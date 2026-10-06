<?php
require_once __DIR__ . '/includes/database.php';
$user = api_require_login($conn);
$userId = (int)$user['id'];
$role = $user['role'];
$action = $_POST['action'] ?? $_GET['action'] ?? 'place';

if ($action === 'place') {
    api_require_method('POST');
    api_require_role($user, 'guest');
    $bookingId = filter_var($_POST['booking_id'] ?? null, FILTER_VALIDATE_INT);
    $items = json_decode($_POST['items'] ?? '', true);
    $notes = trim($_POST['notes'] ?? '');
    if (!$bookingId || !is_array($items) || !$items || count($items) > 20 || strlen($notes) > 2000) {
        api_json(false, 'Invalid order details.', null, 400);
        exit;
    }

    $booking = $conn->prepare("SELECT id, room_id, total_amount FROM bookings WHERE id=? AND user_id=? AND status='checked_in' AND check_in<=CURDATE() AND check_out>CURDATE()");
    $booking->bind_param('ii', $bookingId, $userId);
    $booking->execute();
    $bookingRow = $booking->get_result()->fetch_assoc();
    if (!$bookingRow) {
        api_json(false, 'An active checked-in booking is required for room service.', null, 403);
        exit;
    }

    $canonicalItems = [];
    $subtotalCents = 0;
    $menuLookup = $conn->prepare('SELECT name, unit_price FROM service_menu_items WHERE id=? AND is_active=1');
    foreach ($items as $item) {
        $itemId = is_array($item) ? ($item['id'] ?? '') : '';
        $quantity = is_array($item) ? ($item['qty'] ?? null) : null;
        if (!is_string($itemId) || !preg_match('/^[a-z0-9-]{1,60}$/', $itemId) || !is_int($quantity) || $quantity < 1 || $quantity > 20) {
            api_json(false, 'Each item needs a valid menu ID and quantity from 1 to 20.', null, 400);
            exit;
        }
        $menuLookup->bind_param('s', $itemId);
        $menuLookup->execute();
        $menuItem = $menuLookup->get_result()->fetch_assoc();
        if (!$menuItem) {
            api_json(false, 'An order item is no longer available.', null, 400);
            exit;
        }
        $unitCents = (int)round((float)$menuItem['unit_price'] * 100);
        $subtotalCents += $unitCents * $quantity;
        $canonicalItems[] = [
            'id' => $itemId,
            'name' => $menuItem['name'],
            'qty' => $quantity,
            'price' => number_format($unitCents / 100, 2, '.', ''),
        ];
    }
    $serviceCents = (int)round($subtotalCents * 0.10);
    $total = number_format(($subtotalCents + $serviceCents) / 100, 2, '.', '');
    $itemsJson = json_encode($canonicalItems, JSON_INVALID_UTF8_SUBSTITUTE);
    $reference = 'ORD' . strtoupper(bin2hex(random_bytes(8)));
    $bookingId = (int)$bookingRow['id'];
    $roomId = (int)$bookingRow['room_id'];
    $roomCents = (int)round((float)$bookingRow['total_amount'] * 100);
    $tax = number_format((int)round($roomCents * 0.15) / 100, 2, '.', '');
    $roomCharges = number_format($roomCents / 100, 2, '.', '');
    $invoiceTotal = number_format(($roomCents + (int)round($roomCents * 0.15) + (int)round($total * 100)) / 100, 2, '.', '');

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('INSERT INTO service_orders (order_ref, booking_id, user_id, room_id, items, total, notes) VALUES (?,?,?,?,?,?,?)');
        $stmt->bind_param('siiisds', $reference, $bookingId, $userId, $roomId, $itemsJson, $total, $notes);
        $stmt->execute();
        $invoiceLock = $conn->prepare('SELECT id, status FROM invoices WHERE booking_id=? FOR UPDATE');
        $invoiceLock->bind_param('i', $bookingId);
        $invoiceLock->execute();
        $invoice = $invoiceLock->get_result()->fetch_assoc();
        if ($invoice) {
            if ($invoice['status'] === 'paid') {
                throw new DomainException('This booking invoice is already settled. Contact reception to arrange further charges.');
            }
            $updateInvoice = $conn->prepare('UPDATE invoices SET service_charges=service_charges+?, total_amount=total_amount+? WHERE id=?');
            $updateInvoice->bind_param('ddi', $total, $total, $invoice['id']);
            $updateInvoice->execute();
        } else {
            $invoiceReference = 'INV' . strtoupper(bin2hex(random_bytes(8)));
            $serviceCharges = $total;
            $createInvoice = $conn->prepare("INSERT INTO invoices (invoice_ref, booking_id, user_id, room_charges, service_charges, tax_amount, total_amount, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'issued')");
            $createInvoice->bind_param('siidddd', $invoiceReference, $bookingId, $userId, $roomCharges, $serviceCharges, $tax, $invoiceTotal);
            $createInvoice->execute();
        }
        $conn->commit();
        api_json(true, 'Order placed.', null, 200, ['order_ref' => $reference, 'total' => (float)$total]);
    } catch (DomainException $error) {
        $conn->rollback();
        api_json(false, $error->getMessage(), null, 409);
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
} elseif ($action === 'update_status') {
    api_require_method('POST');
    api_require_role($user, 'admin', 'staff');
    $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
    $newStatus = $_POST['status'] ?? '';
    if (!$orderId || !in_array($newStatus, ['pending', 'preparing', 'on-the-way', 'delivered', 'cancelled'], true)) {
        api_json(false, 'Invalid order update.', null, 400);
        exit;
    }
    $conn->begin_transaction();
    try {
        $find = $conn->prepare('SELECT booking_id, total, status, assigned_to FROM service_orders WHERE id=? FOR UPDATE');
        $find->bind_param('i', $orderId);
        $find->execute();
        $order = $find->get_result()->fetch_assoc();
        if (!$order) {
            throw new DomainException('Order not found.');
        }
        $transitions = [
            'pending' => ['preparing', 'cancelled'],
            'preparing' => ['on-the-way', 'delivered', 'cancelled'],
            'on-the-way' => ['delivered', 'cancelled'],
        ];
        $allowedNext = $transitions[$order['status']] ?? [];
        if (!in_array($newStatus, $allowedNext, true)) {
            throw new DomainException('Order cannot transition from its current status.');
        }
        if ($role === 'staff') {
            $isUnassigned = $order['assigned_to'] === null;
            if (!in_array($newStatus, $allowedNext, true) || ($newStatus === 'preparing' && !$isUnassigned && (int)$order['assigned_to'] !== $userId) || ($newStatus !== 'preparing' && (int)$order['assigned_to'] !== $userId)) {
                throw new DomainException('Order is not available for your assignment or status.');
            }
        }
        if ($newStatus === 'cancelled') {
            $invoiceLock = $conn->prepare('SELECT id, status FROM invoices WHERE booking_id=? FOR UPDATE');
            $invoiceLock->bind_param('i', $order['booking_id']);
            $invoiceLock->execute();
            $invoice = $invoiceLock->get_result()->fetch_assoc();
            if ($invoice && $invoice['status'] === 'paid') {
                throw new DomainException('This order belongs to a paid invoice and requires manual refund review.');
            }
            if ($invoice) {
                $orderTotal = (float)$order['total'];
                $updateInvoice = $conn->prepare("UPDATE invoices SET service_charges=GREATEST(0,service_charges-?), total_amount=GREATEST(room_charges+tax_amount,total_amount-?) WHERE id=? AND status IN ('issued','overdue')");
                $updateInvoice->bind_param('ddi', $orderTotal, $orderTotal, $invoice['id']);
                $updateInvoice->execute();
            }
        }
        if ($newStatus === 'preparing') {
            $stmt = $conn->prepare('UPDATE service_orders SET status=?, assigned_to=? WHERE id=?');
            $stmt->bind_param('sii', $newStatus, $userId, $orderId);
        } else {
            $stmt = $conn->prepare('UPDATE service_orders SET status=? WHERE id=?');
            $stmt->bind_param('si', $newStatus, $orderId);
        }
        $stmt->execute();
        $conn->commit();
        api_json(true, 'Order status updated.');
    } catch (DomainException $error) {
        $conn->rollback();
        $status = $error->getMessage() === 'Order not found.' ? 404 : 403;
        api_json(false, $error->getMessage(), null, $status);
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
} elseif ($action === 'get') {
    api_require_method('GET', 'POST');
    if (in_array($role, ['admin', 'staff'], true)) {
        $result = $conn->query("SELECT o.*, u.first_name, u.last_name, r.room_number
            FROM service_orders o JOIN users u ON u.id=o.user_id JOIN rooms r ON r.id=o.room_id
            ORDER BY o.ordered_at DESC");
    } else {
        $stmt = $conn->prepare('SELECT o.*, r.room_number FROM service_orders o JOIN rooms r ON r.id=o.room_id WHERE o.user_id=? ORDER BY o.ordered_at DESC');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
    }
    $orders = [];
    while ($row = $result->fetch_assoc()) {
        $row['items_array'] = json_decode($row['items'], true);
        $orders[] = $row;
    }
    echo json_encode(['success' => true, 'data' => $orders], JSON_INVALID_UTF8_SUBSTITUTE);
} else {
    api_json(false, 'Unknown action.', null, 400);
}
$conn->close();
?>
