<?php
require_once __DIR__ . '/includes/database.php';
$user = api_require_login($conn);
$userId = (int)$user['id'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'get';

if ($action === 'get') {
    api_require_method('GET');
    if (in_array($user['role'], ['admin', 'staff'], true)) {
        $result = $conn->query("SELECT inv.*, u.first_name, u.last_name, u.email AS user_email,
            r.room_number, b.check_in, b.check_out, b.nights, b.booking_ref
            FROM invoices inv JOIN bookings b ON b.id=inv.booking_id
            JOIN users u ON u.id=inv.user_id JOIN rooms r ON r.id=b.room_id
            ORDER BY inv.issued_at DESC");
    } else {
        $stmt = $conn->prepare("SELECT inv.*, r.room_number, r.type AS room_type,
            b.check_in, b.check_out, b.nights, b.booking_ref
            FROM invoices inv JOIN bookings b ON b.id=inv.booking_id JOIN rooms r ON r.id=b.room_id
            WHERE inv.user_id=? ORDER BY inv.issued_at DESC");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
    }
    $list = [];
    while ($row = $result->fetch_assoc()) $list[] = $row;
    echo json_encode(['success' => true, 'data' => $list], JSON_INVALID_UTF8_SUBSTITUTE);
} elseif ($action === 'mark_paid') {
    api_require_method('POST');
    api_require_role($user, 'admin');
    $invoiceId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$invoiceId) {
        api_json(false, 'Invalid invoice ID.', null, 400);
        exit;
    }
    $stmt = $conn->prepare("UPDATE invoices inv JOIN bookings b ON b.id=inv.booking_id SET inv.status='paid', inv.paid_at=NOW() WHERE inv.id=? AND inv.status IN ('issued','overdue') AND b.status='checked_out'");
    $stmt->bind_param('i', $invoiceId);
    $stmt->execute();
    if ($stmt->affected_rows !== 1) {
        api_json(false, 'Invoice not found or already settled.', null, 404);
    } else {
        api_json(true, 'Invoice marked paid by an administrator.');
    }
} else {
    api_json(false, 'Unknown invoice action.', null, 400);
}
$conn->close();
?>
