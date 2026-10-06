<?php
// get_rooms.php — Returns rooms as JSON (public)
require_once('includes/database.php');
api_require_method('GET');

$where = "1=1";
$params = []; $types = "";

if (!empty($_GET['status'])) {
    $allowed = ['available','occupied','cleaning','maintenance'];
    if (!in_array($_GET['status'], $allowed, true)) {
        api_json(false, 'Invalid room status filter.', null, 400); exit();
    }
    $where .= " AND status = ?"; $params[] = $_GET['status']; $types .= "s";
}
if (!empty($_GET['type'])) {
    $allowedTypes = ['Deluxe','Superior','Junior Suite','Grand Suite','Presidential'];
    if (!in_array($_GET['type'], $allowedTypes, true)) {
        api_json(false, 'Invalid room type filter.', null, 400); exit();
    }
    $where .= " AND type = ?"; $params[] = $_GET['type']; $types .= "s";
}
foreach (['min_price', 'max_price'] as $priceKey) {
    if (!isset($_GET[$priceKey]) || $_GET[$priceKey] === '') continue;
    $price = filter_var($_GET[$priceKey], FILTER_VALIDATE_FLOAT);
    if ($price === false || $price < 0 || $price > 1000000) {
        api_json(false, 'Invalid price filter.', null, 400); exit();
    }
    $operator = $priceKey === 'min_price' ? '>=' : '<=';
    $where .= " AND price $operator ?"; $params[] = (float)$price; $types .= "d";
}

$sql  = "SELECT id, room_number, type, floor, status, price, capacity, amenities, image_url FROM rooms WHERE $where ORDER BY floor, room_number";
$stmt = $conn->prepare($sql);
if (!empty($params)) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$result = $stmt->get_result();

$rooms = [];
while ($row = $result->fetch_assoc()) {
    $row['amenities_array'] = array_map('trim', explode(',', $row['amenities'] ?? ''));
    $rooms[] = $row;
}
echo json_encode(['success' => true, 'data' => $rooms]);
$stmt->close(); $conn->close();
?>
