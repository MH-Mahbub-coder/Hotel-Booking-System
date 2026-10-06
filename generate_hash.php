<?php
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__ . '/includes/database.php';

$read = static function (string $prompt): string {
	fwrite(STDOUT, $prompt);
	return trim((string)fgets(STDIN));
};

$firstName = $read('Admin first name: ');
$lastName = $read('Admin last name: ');
$email = strtolower($read('Admin email: '));
$password = $read('Admin password (minimum 12 characters): ');
if ($firstName === '' || strlen($firstName) > 60 || $lastName === '' || strlen($lastName) > 60 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120 || !api_valid_new_password($password)) {
	fwrite(STDERR, "Invalid account details. No account was created.\n");
	exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$check = $conn->prepare('SELECT id, role FROM users WHERE email=? LIMIT 1');
$check->bind_param('s', $email);
$check->execute();
$existing = $check->get_result()->fetch_assoc();
if ($existing) {
	if ($existing['role'] !== 'admin') {
		fwrite(STDERR, "That email belongs to a non-admin account. No changes were made.\n");
		exit(1);
	}
	$stmt = $conn->prepare('UPDATE users SET first_name=?, last_name=?, password=?, is_active=1 WHERE id=? AND role=\'admin\'');
	$stmt->bind_param('sssi', $firstName, $lastName, $hash, $existing['id']);
	$stmt->execute();
	fwrite(STDOUT, "Administrator account updated. Store its password securely and remove this utility from the web root after setup.\n");
} else {
	$stmt = $conn->prepare("INSERT INTO users (first_name,last_name,email,password,role,is_active) VALUES (?,?, ?, ?, 'admin', 1)");
	$stmt->bind_param('ssss', $firstName, $lastName, $email, $hash);
	$stmt->execute();
	fwrite(STDOUT, "Administrator account created. Store its password securely and remove this utility from the web root after setup.\n");
}
$conn->close();
?>
