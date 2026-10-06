<?php
require_once __DIR__ . '/../includes/database.php';
$user = api_require_login($conn);
api_require_role($user, 'admin');
$room_stats = $conn->query("SELECT COUNT(*) AS total, SUM(status='available') AS available, SUM(status='occupied') AS occupied, SUM(status='cleaning') AS cleaning, SUM(status='maintenance') AS maintenance FROM rooms")->fetch_assoc();
$booking_stats = $conn->query("SELECT COUNT(*) AS total, SUM(status='confirmed') AS confirmed, SUM(status='approved') AS approved, SUM(status='pending') AS pending, SUM(total_amount) AS total_revenue FROM bookings WHERE status != 'cancelled'")->fetch_assoc();
$staff_stats = $conn->query("SELECT COUNT(*) AS total, SUM(sp.on_duty) AS on_duty FROM users u LEFT JOIN staff_profiles sp ON sp.user_id=u.id WHERE u.role='staff' AND u.is_active=1")->fetch_assoc();
$recent = []; $r = $conn->query("SELECT b.booking_ref, b.check_in, b.check_out, b.total_amount, b.status, u.first_name, u.last_name, r.room_number FROM bookings b JOIN users u ON u.id=b.user_id JOIN rooms r ON r.id=b.room_id ORDER BY b.created_at DESC LIMIT 5");
while ($row = $r->fetch_assoc()) $recent[] = $row;
$orders = []; $o = $conn->query("SELECT o.order_ref, o.total, o.status, u.first_name, u.last_name, r.room_number FROM service_orders o JOIN users u ON u.id=o.user_id JOIN rooms r ON r.id=o.room_id WHERE o.status IN ('pending','preparing') LIMIT 5");
while ($row = $o->fetch_assoc()) $orders[] = $row;
echo json_encode(['success' => true, 'data' => ['room_stats' => $room_stats, 'booking_stats' => $booking_stats, 'staff_stats' => $staff_stats, 'recent_bookings' => $recent, 'pending_orders' => $orders]]);
$conn->close();
?>
