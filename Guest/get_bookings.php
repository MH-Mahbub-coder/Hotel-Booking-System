<?php
// get_bookings.php
require_once __DIR__ . '/includes/database.php';
api_require_method('GET');
$user = api_require_login($conn);
$role = $user['role'];
$user_id = (int)$user['id'];

if ($role === 'admin' || $role === 'staff') {
    $result = $conn->query("SELECT b.*, u.first_name, u.last_name, u.email AS user_email,
        r.room_number, r.type AS room_type, r.floor
        FROM bookings b
        JOIN users u ON u.id = b.user_id
        JOIN rooms  r ON r.id = b.room_id
        ORDER BY b.created_at DESC");
} else {
    $stmt = $conn->prepare("SELECT b.*, u.first_name, u.last_name, u.email AS user_email,
        r.room_number, r.type AS room_type, r.floor, r.price
        FROM bookings b 
        JOIN users u ON u.id = b.user_id
        JOIN rooms r ON r.id = b.room_id
        WHERE b.user_id = ? ORDER BY b.created_at DESC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
}

$bookings = [];
while ($row = $result->fetch_assoc()) $bookings[] = $row;
echo json_encode(['success' => true, 'data' => $bookings]);
$conn->close();
?>
