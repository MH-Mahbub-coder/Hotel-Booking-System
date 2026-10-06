<?php
// register.php
require_once __DIR__ . '/includes/database.php';
api_require_method('POST');
api_require_csrf();

$first_name = trim($_POST['first_name'] ?? '');
$last_name  = trim($_POST['last_name']  ?? '');
$email      = strtolower(trim($_POST['email'] ?? ''));
$password   = $_POST['password']        ?? '';
$role       = $_POST['role']            ?? 'guest';
$phone      = trim($_POST['phone']      ?? '');

$errors = [];
if ($first_name === '' || strlen($first_name) > 60) $errors[] = 'A first name of at most 60 characters is required.';
if ($last_name === '' || strlen($last_name) > 60) $errors[] = 'A last name of at most 60 characters is required.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) $errors[] = 'Valid email is required.';
if (!api_valid_new_password($password)) $errors[] = 'Password must be at least 12 characters and at most 72 bytes.';
if (strlen($phone) > 30) $errors[] = 'Phone number must be at most 30 characters.';
$role = 'guest'; // Public registration is strictly for Guests; Staff accounts are created by Admin in Staff Management

if (!empty($errors)) { api_json(false, 'Please correct the submitted fields.', null, 400, ['errors' => $errors]); exit(); }

$stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute(); $stmt->store_result();
if ($stmt->num_rows > 0) {
    api_json(false, 'Email already registered.', null, 409); exit();
}
$stmt->close();

$hash = password_hash($password, PASSWORD_BCRYPT);
$stmt = $conn->prepare("INSERT INTO users (first_name, last_name, email, password, role, phone, is_active) VALUES (?, ?, ?, ?, 'guest', ?, 1)");
$stmt->bind_param("sssss", $first_name, $last_name, $email, $hash, $phone);

if ($stmt->execute()) {
    $user_id = $stmt->insert_id;
    session_regenerate_id(true);
    $_SESSION['logged_in'] = true;
    $_SESSION['user_id']   = $user_id;
    $_SESSION['user_name'] = $first_name . ' ' . $last_name;
    $_SESSION['email']     = $email;
    $_SESSION['role']      = 'guest';
    $_SESSION['password_fingerprint'] = hash('sha256', $hash);
    echo json_encode(['success' => true, 'message' => 'Account created!', 'csrf_token' => $_SESSION['csrf_token'],
        'user' => ['id' => $user_id, 'name' => $first_name.' '.$last_name,
                   'email' => $email, 'role' => 'guest']]);
} else {
    api_json(false, 'Registration failed.', null, 500);
}
$stmt->close(); $conn->close();
?>
