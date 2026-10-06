<?php
// get_session.php — Frontend calls this on load to check login status
require_once __DIR__ . '/includes/database.php';
api_require_method('GET');

$response = ['logged_in' => false, 'csrf_token' => $_SESSION['csrf_token']];
if (!empty($_SESSION['logged_in']) && isset($_SESSION['user_id'])) {
    $user = api_require_login($conn);
    $response['logged_in'] = true;
    $response['user'] = [
        'id' => (int)$user['id'],
        'name' => trim($user['first_name'] . ' ' . $user['last_name']),
        'email' => $user['email'],
        'role' => $user['role'],
    ];
}
echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
?>
