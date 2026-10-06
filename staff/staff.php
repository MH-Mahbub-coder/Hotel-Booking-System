<?php
require_once __DIR__ . '/../includes/database.php';
$currentUser = api_require_login($conn);
api_require_role($currentUser, 'admin', 'staff');

$action = $_POST['action'] ?? $_GET['action'] ?? 'get';
$role = $currentUser['role'];

if ($action === 'get') {
    $result = $conn->query("SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.is_active, sp.department, sp.zone, sp.shift_start, sp.shift_end, sp.on_duty 
        FROM users u 
        LEFT JOIN staff_profiles sp ON sp.user_id=u.id 
        WHERE u.role='staff' AND u.is_active=1 
        ORDER BY u.first_name ASC");
    $staff = []; 
    while ($row = $result->fetch_assoc()) $staff[] = $row;
    echo json_encode(['success' => true, 'data' => $staff]);

} elseif ($action === 'add' && $role === 'admin') {
    $fn = trim($_POST['first_name'] ?? ''); 
    $ln = trim($_POST['last_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? '')); 
    $pass = $_POST['password'] ?? '';
    $dept = $_POST['department'] ?? 'Housekeeper'; 
    $zone = $_POST['zone'] ?? 'All Floors';
    $ss = $_POST['shift_start'] ?? '08:00'; 
    $se = $_POST['shift_end'] ?? '16:00';
    $phone = trim($_POST['phone'] ?? '');

    $departments = ['Housekeeper', 'Room Service', 'Receptionist', 'Maintenance', 'Chef', 'Concierge'];
    if ($fn === '' || strlen($fn) > 60 || $ln === '' || strlen($ln) > 60 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120 || !in_array($dept, $departments, true) || strlen($phone) > 30) {
        api_json(false, 'Enter valid staff details.', null, 400); exit();
    }
    if (!api_valid_new_password($pass)) {
        api_json(false, 'Password must be at least 12 characters and at most 72 bytes.', null, 400); exit();
    }

    $hash = password_hash($pass, PASSWORD_BCRYPT);

    // Check if user already exists
    $chk = $conn->prepare("SELECT id, role, is_active FROM users WHERE email = ?");
    $chk->bind_param("s", $email);
    $chk->execute();
    $existing = $chk->get_result()->fetch_assoc();
    $chk->close();

    if ($existing && $existing['role'] === 'admin') {
        api_json(false, 'An administrator account cannot be converted to staff.', null, 409); exit();
    }
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $ss) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $se) || $zone === '' || strlen($zone) > 60) {
        api_json(false, 'Enter valid staff shift and zone details.', null, 400); exit();
    }

    $conn->begin_transaction();
    try {
        if ($existing) {
            $uid = (int)$existing['id'];
            $up = $conn->prepare("UPDATE users SET first_name=?, last_name=?, password=?, role='staff', is_active=1, phone=? WHERE id=?");
            $up->bind_param('ssssi', $fn, $ln, $hash, $phone, $uid);
            $up->execute();
        } else {
            $ins = $conn->prepare("INSERT INTO users (first_name, last_name, email, password, role, is_active, phone) VALUES (?, ?, ?, ?, 'staff', 1, ?)");
            $ins->bind_param('sssss', $fn, $ln, $email, $hash, $phone);
            $ins->execute();
            $uid = $ins->insert_id;
        }
        $profile = $conn->prepare("INSERT INTO staff_profiles (user_id, department, zone, shift_start, shift_end, on_duty) VALUES (?, ?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE department=VALUES(department), zone=VALUES(zone), shift_start=VALUES(shift_start), shift_end=VALUES(shift_end)");
        $profile->bind_param('issss', $uid, $dept, $zone, $ss, $se);
        $profile->execute();
        $conn->commit();
        api_json(true, $existing ? 'Staff profile updated and activated.' : 'Staff member created.');
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

} elseif ($action === 'update' && $role === 'admin') {
    $uid = (int)($_POST['user_id'] ?? 0);
    $fn = trim($_POST['first_name'] ?? '');
    $ln = trim($_POST['last_name'] ?? '');
    $dept = $_POST['department'] ?? '';
    $zone = $_POST['zone'] ?? '';
    $ss = $_POST['shift_start'] ?? '08:00';
    $se = $_POST['shift_end'] ?? '16:00';
    $newPass = $_POST['password'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $onDuty = filter_var($_POST['on_duty'] ?? null, FILTER_VALIDATE_INT);

    $departments = ['Housekeeper', 'Room Service', 'Receptionist', 'Maintenance', 'Chef', 'Concierge'];
    if (!$uid || !in_array($dept, $departments, true) || !in_array($onDuty, [0, 1], true) || $zone === '' || strlen($zone) > 60 || strlen($fn) > 60 || strlen($ln) > 60 || strlen($phone) > 30 || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $ss) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $se) || ($newPass !== '' && !api_valid_new_password($newPass))) {
        api_json(false, 'Invalid staff details.', null, 400); exit();
    }

    $staffCheck = $conn->prepare("SELECT id FROM users WHERE id=? AND role='staff'");
    $staffCheck->bind_param('i', $uid);
    $staffCheck->execute();
    if (!$staffCheck->get_result()->fetch_assoc()) {
        api_json(false, 'Staff member not found.', null, 404); exit();
    }
    
    $conn->begin_transaction();
    try {
        if ($newPass !== '') {
            $hash = password_hash($newPass, PASSWORD_BCRYPT);
            if ($fn !== '' && $ln !== '') {
                $up = $conn->prepare('UPDATE users SET first_name=?, last_name=?, password=?, is_active=1, phone=? WHERE id=?');
                $up->bind_param('ssssi', $fn, $ln, $hash, $phone, $uid);
            } else {
                $up = $conn->prepare('UPDATE users SET password=?, is_active=1 WHERE id=?');
                $up->bind_param('si', $hash, $uid);
            }
            $up->execute();
        } elseif ($fn !== '' && $ln !== '') {
            $up = $conn->prepare('UPDATE users SET first_name=?, last_name=?, is_active=1, phone=? WHERE id=?');
            $up->bind_param('sssi', $fn, $ln, $phone, $uid);
            $up->execute();
        }
        $profile = $conn->prepare('INSERT INTO staff_profiles (user_id, department, zone, shift_start, shift_end, on_duty) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE department=VALUES(department), zone=VALUES(zone), shift_start=VALUES(shift_start), shift_end=VALUES(shift_end), on_duty=VALUES(on_duty)');
        $profile->bind_param('issssi', $uid, $dept, $zone, $ss, $se, $onDuty);
        $profile->execute();
        $conn->commit();
        api_json(true, 'Staff profile updated successfully.');
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

} elseif ($action === 'toggle_duty') {
    api_require_role($currentUser, 'admin');
    $uid = (int)($_POST['user_id'] ?? 0); 
    $on = (int)($_POST['on_duty'] ?? 0);
    if ($uid <= 0 || !in_array($on, [0, 1], true)) {
        api_json(false, 'Invalid staff duty update.', null, 400); exit();
    }
    $staffCheck = $conn->prepare("SELECT id FROM users WHERE id=? AND role='staff' AND is_active=1");
    $staffCheck->bind_param('i', $uid);
    $staffCheck->execute();
    if (!$staffCheck->get_result()->fetch_assoc()) {
        api_json(false, 'Staff member not found.', null, 404); exit();
    }
    
    $check = $conn->prepare("SELECT id FROM staff_profiles WHERE user_id = ?");
    $check->bind_param("i", $uid);
    $check->execute();
    $check->store_result();
    $exists = ($check->num_rows > 0);
    $check->close();
    
    if (!$exists) {
        $stmt = $conn->prepare("INSERT INTO staff_profiles (user_id, department, zone, shift_start, shift_end, on_duty) VALUES (?, 'Housekeeper', 'All Floors', '08:00:00', '16:00:00', ?)");
        $stmt->bind_param("ii", $uid, $on);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare('UPDATE staff_profiles SET on_duty=? WHERE user_id=?');
        $stmt->bind_param('ii', $on, $uid);
        $stmt->execute();
        $stmt->close();
    }
    echo json_encode(['success' => true]);

} elseif ($action === 'deactivate' && $role === 'admin') {
    $uid = (int)($_POST['user_id'] ?? 0);
    $stmt = $conn->prepare("UPDATE users SET is_active=0 WHERE id=? AND role='staff'");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    echo json_encode(['success' => $stmt->affected_rows === 1, 'message' => 'Staff member deactivated.']);
} else {
    api_json(false, 'Unknown or unauthorized staff action.', null, 403);
}
$conn->close();
?>
