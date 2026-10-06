<?php
// login.php
require_once __DIR__ . '/includes/database.php';
api_require_method('POST');
api_require_csrf();

$email    = strtolower(trim($_POST['email'] ?? ''));
$password = $_POST['password']      ?? '';

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120 || $password === '' || strlen($password) > 72) {
    api_json(false, 'Email and password are required.', null, 400);
    exit;
}
api_rate_limit($conn, 'login-ip', 30, 900);
api_rate_limit($conn, 'login-account:' . hash('sha256', $email), 8, 900);

$stmt = $conn->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    api_json(false, 'Invalid email or password.', null, 401);
    exit;
}

$user = $result->fetch_assoc();

if (!password_verify($password, $user['password'])) {
    api_json(false, 'Invalid email or password.', null, 401);
    exit;
}

// Set session
session_regenerate_id(true);
$_SESSION['logged_in'] = true;
$_SESSION['user_id']   = $user['id'];
$_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
$_SESSION['email']     = $user['email'];
$_SESSION['role']      = $user['role'];
$_SESSION['password_fingerprint'] = hash('sha256', $user['password']);

echo json_encode(['success' => true, 'message' => 'Login successful.', 'csrf_token' => $_SESSION['csrf_token'],
    'user' => ['id' => $user['id'], 'name' => $user['first_name'].' '.$user['last_name'],
               'email' => $user['email'], 'role' => $user['role']]]);

$stmt->close(); $conn->close();
?>
