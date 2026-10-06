<?php
require_once __DIR__ . '/includes/api.php';
api_require_method('POST');
api_require_csrf();

$_SESSION = [];
if (ini_get('session.use_cookies')) {
	$params = session_get_cookie_params();
	setcookie(session_name(), '', [
		'expires' => time() - 42000,
		'path' => $params['path'],
		'domain' => $params['domain'],
		'secure' => $params['secure'],
		'httponly' => $params['httponly'],
		'samesite' => $params['samesite'] ?? 'Lax',
	]);
}
session_destroy();
api_json(true, 'Logged out.');
?>
