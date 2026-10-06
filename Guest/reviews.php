<?php
require_once __DIR__ . '/includes/database.php';
$action = $_POST['action'] ?? $_GET['action'] ?? 'get';

if ($action === 'get') {
    api_require_method('GET');
    $result = $conn->query("SELECT rv.*, u.first_name, u.last_name FROM reviews rv
        JOIN users u ON u.id=rv.user_id WHERE rv.is_approved=1 ORDER BY rv.created_at DESC LIMIT 50");
    $reviews = [];
    while ($row = $result->fetch_assoc()) $reviews[] = $row;
    echo json_encode(['success' => true, 'data' => $reviews], JSON_INVALID_UTF8_SUBSTITUTE);
} elseif ($action === 'submit') {
    api_require_method('POST');
    $user = api_require_login($conn);
    api_require_role($user, 'guest');
    $rating = filter_var($_POST['rating'] ?? null, FILTER_VALIDATE_INT);
    $comment = trim($_POST['comment'] ?? '');
    $bookingId = filter_var($_POST['booking_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$rating || $rating > 5 || $comment === '' || strlen($comment) > 5000) {
        api_json(false, 'Rating must be 1-5 and comment must be at most 5000 characters.', null, 400);
        exit;
    }
    $userId = (int)$user['id'];
    $bookingValue = null;
    if ($bookingId) {
        $bookingCheck = $conn->prepare("SELECT id FROM bookings WHERE id=? AND user_id=? AND status='checked_out'");
        $bookingCheck->bind_param('ii', $bookingId, $userId);
        $bookingCheck->execute();
        if (!$bookingCheck->get_result()->fetch_assoc()) {
            api_json(false, 'A completed booking belonging to your account is required.', null, 403);
            exit;
        }
        $bookingValue = $bookingId;
    } elseif (isset($_POST['booking_id']) && $_POST['booking_id'] !== '') {
        api_json(false, 'Invalid booking ID.', null, 400);
        exit;
    }
    $stmt = $conn->prepare('INSERT INTO reviews (user_id, booking_id, rating, comment) VALUES (?,?,?,?)');
    $stmt->bind_param('iiis', $userId, $bookingValue, $rating, $comment);
    $stmt->execute();
    api_json(true, 'Review submitted.');
} else {
    api_json(false, 'Unknown review action.', null, 400);
}
$conn->close();
?>
