<?php
require_once __DIR__ . '/../includes/database.php';
$user = api_require_login($conn);
api_require_role($user, 'admin', 'staff');
$action = $_POST['action'] ?? $_GET['action'] ?? 'get';
api_require_method($action === 'get' ? 'GET' : 'POST');

if ($action === 'add') {
    api_require_role($user, 'admin');
    $room_number = trim($_POST['room_number'] ?? '');
    $type = $_POST['type'] ?? 'Deluxe';
    $floor = (int)($_POST['floor'] ?? 1);
    $price = (float)($_POST['price'] ?? 150);
    $capacity = (int)($_POST['capacity'] ?? 2);
    $amenities = trim($_POST['amenities'] ?? '');
    
    $roomTypes = ['Deluxe', 'Superior', 'Junior Suite', 'Grand Suite', 'Presidential'];
    if (!preg_match('/^[A-Za-z0-9-]{1,10}$/', $room_number) || !in_array($type, $roomTypes, true) || $floor < 1 || $floor > 99 || !is_numeric($_POST['price'] ?? null) || $price < 0 || $price > 1000000 || $capacity < 1 || $capacity > 10 || strlen($amenities) > 500) {
        api_json(false, 'Enter valid room details.', null, 400); exit();
    }
    
    // Check if room number already exists
    $stmt = $conn->prepare("SELECT id FROM rooms WHERE room_number = ?");
    $stmt->bind_param("s", $room_number);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Room number already exists.']); exit();
    }
    $stmt->close();
    
    $stmt = $conn->prepare("INSERT INTO rooms (room_number, type, floor, price, capacity, amenities, status) VALUES (?, ?, ?, ?, ?, ?, 'available')");
    $stmt->bind_param("ssidis", $room_number, $type, $floor, $price, $capacity, $amenities);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Room added successfully!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to add room.']);
    }
    $stmt->close();
}
elseif ($action === 'delete') {
    api_require_role($user, 'admin');
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['success' => false, 'message' => 'Room ID is required.']); exit(); }
    
    $stmt = $conn->prepare("DELETE FROM rooms WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Room removed.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to remove room. Maybe it has active bookings.']);
    }
    $stmt->close();
}
elseif ($action === 'update') {
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    $status = $_POST['status'] ?? '';
    $allowed = ['available','occupied','cleaning','maintenance'];
    if (!$id || !in_array($status, $allowed, true)) {
        api_json(false, 'Invalid room update.', null, 400); exit();
    }

    $conn->begin_transaction();
    try {
        $roomLock = $conn->prepare('SELECT status FROM rooms WHERE id=? FOR UPDATE');
        $roomLock->bind_param('i', $id);
        $roomLock->execute();
        $room = $roomLock->get_result()->fetch_assoc();
        if (!$room) {
            throw new DomainException('Room not found.');
        }
        if ($user['role'] === 'staff') {
            if ($status === 'maintenance' || ($room['status'] === 'maintenance' && $status !== 'maintenance')) {
                throw new DomainException('Only an administrator can change maintenance status.');
            }
            $activeStay = $conn->prepare("SELECT id FROM bookings WHERE room_id=? AND status='checked_in' AND check_in<=CURDATE() AND check_out>CURDATE() LIMIT 1");
            $activeStay->bind_param('i', $id);
            $activeStay->execute();
            $isOccupied = (bool)$activeStay->get_result()->fetch_assoc();
            $openCleaning = $conn->prepare("SELECT id FROM cleaning_requests WHERE room_id=? AND status IN ('pending','in-progress') LIMIT 1");
            $openCleaning->bind_param('i', $id);
            $openCleaning->execute();
            $hasCleaning = (bool)$openCleaning->get_result()->fetch_assoc();
            if (($status === 'available' && ($isOccupied || $hasCleaning)) || ($status === 'occupied' && !$isOccupied)) {
                throw new DomainException('Room status conflicts with its active booking or cleaning request.');
            }
        }
        $stmt = $conn->prepare('UPDATE rooms SET status=? WHERE id=?');
        $stmt->bind_param('si', $status, $id);
        $stmt->execute();
        $conn->commit();
        api_json(true, 'Room updated.');
    } catch (DomainException $error) {
        $conn->rollback();
        api_json(false, $error->getMessage(), null, 409);
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}
else {
    api_json(false, 'Unknown room action.', null, 400);
}
$conn->close();
?>
