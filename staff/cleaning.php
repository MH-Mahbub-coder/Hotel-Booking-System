<?php
require_once __DIR__ . '/includes/database.php';
$user = api_require_login($conn);
$userId = (int)$user['id'];
$role = $user['role'];
$action = $_POST['action'] ?? $_GET['action'] ?? 'get';

if ($action === 'request') {
    api_require_method('POST');
    api_require_role($user, 'guest');
    $roomKey = preg_replace('/^ROOM_/', '', trim($_POST['room_id'] ?? ''));
    $priority = $_POST['priority'] ?? 'normal';
    $notes = trim($_POST['notes'] ?? '');
    if (!is_string($roomKey) || !ctype_digit($roomKey) || strlen($roomKey) > 10 || !in_array($priority, ['low', 'normal', 'high', 'urgent'], true) || strlen($notes) > 2000) {
        api_json(false, 'Invalid cleaning request.', null, 400);
        exit;
    }

    $roomNumber = $roomKey;
    $roomCandidate = (int)$roomKey;
    $stmt = $conn->prepare('SELECT id, status FROM rooms WHERE room_number=? OR id=? ORDER BY (room_number=?) DESC LIMIT 1');
    $stmt->bind_param('sis', $roomNumber, $roomCandidate, $roomNumber);
    $stmt->execute();
    $room = $stmt->get_result()->fetch_assoc();
    if (!$room) {
        api_json(false, 'Room not found.', null, 404);
        exit;
    }
    $roomId = (int)$room['id'];

    $bookingCheck = $conn->prepare("SELECT id FROM bookings WHERE user_id=? AND room_id=? AND status='checked_in' AND check_in<=CURDATE() AND check_out>CURDATE() LIMIT 1");
    $bookingCheck->bind_param('ii', $userId, $roomId);
    $bookingCheck->execute();
    if (!$bookingCheck->get_result()->fetch_assoc()) {
        api_json(false, 'An active checked-in booking for this room is required.', null, 403);
        exit;
    }

    $conn->begin_transaction();
    try {
        $lock = $conn->prepare('SELECT status FROM rooms WHERE id=? FOR UPDATE');
        $lock->bind_param('i', $roomId);
        $lock->execute();
        $lockedRoom = $lock->get_result()->fetch_assoc();
        if (!$lockedRoom || $lockedRoom['status'] === 'maintenance') {
            throw new DomainException('Cleaning cannot be requested for this room.');
        }
        $duplicate = $conn->prepare("SELECT id FROM cleaning_requests WHERE room_id=? AND status IN ('pending','in-progress') LIMIT 1");
        $duplicate->bind_param('i', $roomId);
        $duplicate->execute();
        if ($duplicate->get_result()->fetch_assoc()) {
            throw new DomainException('A cleaning request is already open for this room.');
        }
        $reference = 'CR' . strtoupper(bin2hex(random_bytes(8)));
        $insert = $conn->prepare('INSERT INTO cleaning_requests (request_ref, room_id, requested_by, priority, notes) VALUES (?,?,?,?,?)');
        $insert->bind_param('siiss', $reference, $roomId, $userId, $priority, $notes);
        $insert->execute();
        $updateRoom = $conn->prepare("UPDATE rooms SET status='cleaning' WHERE id=?");
        $updateRoom->bind_param('i', $roomId);
        $updateRoom->execute();
        $conn->commit();
        api_json(true, 'Cleaning request submitted.', null, 200, ['request_ref' => $reference]);
    } catch (DomainException $error) {
        $conn->rollback();
        api_json(false, $error->getMessage(), null, 409);
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
} elseif ($action === 'update') {
    api_require_method('POST');
    api_require_role($user, 'admin', 'staff');
    $requestId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    $newStatus = $_POST['status'] ?? '';
    if (!$requestId || !in_array($newStatus, ['pending', 'in-progress', 'done', 'cancelled'], true)) {
        api_json(false, 'Invalid cleaning update.', null, 400);
        exit;
    }

    $conn->begin_transaction();
    try {
        $find = $conn->prepare('SELECT room_id, status, assigned_to FROM cleaning_requests WHERE id=? FOR UPDATE');
        $find->bind_param('i', $requestId);
        $find->execute();
        $request = $find->get_result()->fetch_assoc();
        if (!$request || in_array($request['status'], ['done', 'cancelled'], true)) {
            throw new DomainException('Cleaning request not found or already closed.');
        }
        if ($role === 'staff') {
            $canClaim = $newStatus === 'in-progress' && $request['status'] === 'pending' && ($request['assigned_to'] === null || (int)$request['assigned_to'] === $userId);
            $canComplete = in_array($newStatus, ['done', 'cancelled'], true) && $request['status'] === 'in-progress' && (int)$request['assigned_to'] === $userId;
            if (!$canClaim && !$canComplete) {
                throw new DomainException('Cleaning request is not assigned to you.');
            }
        }
        $roomLock = $conn->prepare('SELECT status FROM rooms WHERE id=? FOR UPDATE');
        $roomLock->bind_param('i', $request['room_id']);
        $roomLock->execute();
        $room = $roomLock->get_result()->fetch_assoc();
        if ($newStatus === 'in-progress') {
            $update = $conn->prepare('UPDATE cleaning_requests SET status=?, assigned_to=? WHERE id=?');
            $update->bind_param('sii', $newStatus, $userId, $requestId);
        } else {
            $update = $conn->prepare('UPDATE cleaning_requests SET status=?, completed_at=IF(?="done", NOW(), completed_at) WHERE id=?');
            $update->bind_param('ssi', $newStatus, $newStatus, $requestId);
        }
        $update->execute();
        if (in_array($newStatus, ['done', 'cancelled'], true) && $room && $room['status'] !== 'maintenance') {
            $activeStay = $conn->prepare("SELECT id FROM bookings WHERE room_id=? AND status='checked_in' AND check_in<=CURDATE() AND check_out>CURDATE() LIMIT 1");
            $activeStay->bind_param('i', $request['room_id']);
            $activeStay->execute();
            $roomStatus = $activeStay->get_result()->fetch_assoc() ? 'occupied' : 'available';
            $restore = $conn->prepare('UPDATE rooms SET status=? WHERE id=?');
            $restore->bind_param('si', $roomStatus, $request['room_id']);
            $restore->execute();
        }
        $conn->commit();
        api_json(true, 'Cleaning request updated.');
    } catch (DomainException $error) {
        $conn->rollback();
        api_json(false, $error->getMessage(), null, 404);
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
} elseif ($action === 'get') {
    api_require_method('GET', 'POST');
    if (in_array($role, ['admin', 'staff'], true)) {
        $result = $conn->query("SELECT cr.*, r.room_number,
            u1.first_name AS req_first, u1.last_name AS req_last,
            u2.first_name AS asgn_first, u2.last_name AS asgn_last
            FROM cleaning_requests cr
            JOIN rooms r ON r.id=cr.room_id
            JOIN users u1 ON u1.id=cr.requested_by
            LEFT JOIN users u2 ON u2.id=cr.assigned_to
            ORDER BY cr.requested_at DESC");
    } else {
        $stmt = $conn->prepare('SELECT cr.*, r.room_number FROM cleaning_requests cr JOIN rooms r ON r.id=cr.room_id WHERE cr.requested_by=? ORDER BY cr.requested_at DESC');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
    }
    $list = [];
    while ($row = $result->fetch_assoc()) $list[] = $row;
    echo json_encode(['success' => true, 'data' => $list], JSON_INVALID_UTF8_SUBSTITUTE);
} else {
    api_json(false, 'Unknown action.', null, 400);
}
$conn->close();
?>
