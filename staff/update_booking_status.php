<?php
require_once __DIR__ . '/includes/database.php';
$user = api_require_login($conn);
api_require_method('POST');

$bookingId = filter_var($_POST['booking_id'] ?? null, FILTER_VALIDATE_INT);
$newStatus = $_POST['status'] ?? '';
if (!$bookingId || !in_array($newStatus, ['approved', 'confirmed', 'checked_in', 'checked_out', 'cancelled'], true)) {
    api_json(false, 'Invalid booking update.', null, 400);
    exit;
}

$conn->begin_transaction();
try {
    $find = $conn->prepare('SELECT id, user_id, room_id, status FROM bookings WHERE id=? FOR UPDATE');
    $find->bind_param('i', $bookingId);
    $find->execute();
    $booking = $find->get_result()->fetch_assoc();
    if (!$booking) {
        throw new DomainException('Booking not found.');
    }

    $allowedTransitions = [];
    if ($user['role'] === 'guest' && (int)$booking['user_id'] === (int)$user['id']) {
        $allowedTransitions = in_array($booking['status'], ['pending', 'approved'], true) ? ['cancelled'] : [];
    } elseif ($user['role'] === 'staff') {
        $allowedTransitions = $booking['status'] === 'pending' ? ['approved', 'cancelled'] : [];
    } elseif ($user['role'] === 'admin') {
        $allowedTransitions = [
            'pending' => ['approved', 'cancelled'],
            'approved' => ['confirmed', 'cancelled'],
            'confirmed' => ['checked_in', 'cancelled'],
            'checked_in' => ['checked_out'],
        ][$booking['status']] ?? [];
    }
    if (!in_array($newStatus, $allowedTransitions, true)) {
        throw new DomainException('This booking cannot be changed to the requested status.');
    }

    if ($newStatus === 'cancelled') {
        $invoiceCheck = $conn->prepare('SELECT id, status FROM invoices WHERE booking_id=? FOR UPDATE');
        $invoiceCheck->bind_param('i', $bookingId);
        $invoiceCheck->execute();
        $invoice = $invoiceCheck->get_result()->fetch_assoc();
        if ($invoice && $invoice['status'] === 'paid') {
            throw new DomainException('This booking has a paid invoice and requires manual refund review.');
        }
        if ($invoice) {
            $removeInvoice = $conn->prepare("DELETE FROM invoices WHERE id=? AND status IN ('issued','overdue')");
            $removeInvoice->bind_param('i', $invoice['id']);
            $removeInvoice->execute();
        }
    }

    $roomId = (int)$booking['room_id'];
    $roomLock = $conn->prepare('SELECT status FROM rooms WHERE id=? FOR UPDATE');
    $roomLock->bind_param('i', $roomId);
    $roomLock->execute();
    $room = $roomLock->get_result()->fetch_assoc();
    if (!$room) {
        throw new DomainException('Room not found.');
    }

    $update = $conn->prepare('UPDATE bookings SET status=? WHERE id=?');
    $update->bind_param('si', $newStatus, $bookingId);
    $update->execute();
    if ($newStatus === 'checked_in') {
        $roomStatus = 'occupied';
    } elseif (in_array($newStatus, ['checked_out', 'cancelled'], true) && !in_array($room['status'], ['maintenance', 'cleaning'], true)) {
        $otherStay = $conn->prepare("SELECT id FROM bookings WHERE room_id=? AND id<>? AND status='checked_in' AND check_in<=CURDATE() AND check_out>CURDATE() LIMIT 1");
        $otherStay->bind_param('ii', $roomId, $bookingId);
        $otherStay->execute();
        $roomStatus = $otherStay->get_result()->fetch_assoc() ? 'occupied' : 'available';
    } else {
        $roomStatus = $room['status'];
    }
    if ($roomStatus !== $room['status']) {
        $setRoom = $conn->prepare('UPDATE rooms SET status=? WHERE id=?');
        $setRoom->bind_param('si', $roomStatus, $roomId);
        $setRoom->execute();
    }
    $conn->commit();
    api_json(true, 'Booking status updated.');
} catch (DomainException $error) {
    $conn->rollback();
    $status = $error->getMessage() === 'Booking not found.' || $error->getMessage() === 'Room not found.' ? 404 : 403;
    api_json(false, $error->getMessage(), null, $status);
} catch (Throwable $error) {
    $conn->rollback();
    throw $error;
}
$conn->close();
?>
