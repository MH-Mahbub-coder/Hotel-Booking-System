<?php
require_once __DIR__ . '/includes/database.php';
api_require_method('POST');

$mailFrom = getenv('MAIL_FROM') ?: '';
$resetBaseUrl = getenv('PASSWORD_RESET_BASE_URL') ?: '';
$baseParts = parse_url($resetBaseUrl);
$isProduction = getenv('APP_ENV') === 'production';
$allowedScheme = is_array($baseParts) && (($baseParts['scheme'] ?? '') === 'https' || (!$isProduction && ($baseParts['scheme'] ?? '') === 'http' && in_array($baseParts['host'] ?? '', ['localhost', '127.0.0.1'], true)));
if (!filter_var($mailFrom, FILTER_VALIDATE_EMAIL) || !$allowedScheme || !function_exists('mail')) {
    api_json(false, 'Password reset email is not configured. Please contact support.', null, 503);
    exit;
}

$email = strtolower(trim($_POST['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) {
    api_json(false, 'Enter a valid email address.', null, 400);
    exit;
}
api_rate_limit($conn, 'password-reset-ip', 10, 900);
api_rate_limit($conn, 'password-reset-account:' . hash('sha256', $email), 3, 900);

$lookup = $conn->prepare('SELECT id FROM users WHERE email=? AND is_active=1 LIMIT 1');
$lookup->bind_param('s', $email);
$lookup->execute();
$user = $lookup->get_result()->fetch_assoc();
if ($user) {
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $userId = (int)$user['id'];
    $conn->begin_transaction();
    try {
        $cleanup = $conn->prepare('DELETE FROM password_reset_tokens WHERE user_id=? OR expires_at<NOW() OR used_at IS NOT NULL');
        $cleanup->bind_param('i', $userId);
        $cleanup->execute();
        $insert = $conn->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))');
        $insert->bind_param('is', $userId, $tokenHash);
        $insert->execute();
        $tokenId = $insert->insert_id;
        $conn->commit();

        $resetUrl = $resetBaseUrl . (strpos($resetBaseUrl, '?') !== false ? '&' : '?') . http_build_query(['reset_token' => $token]);
        $subject = 'Aurelia Grand password reset';
        $body = "A password reset was requested for your Aurelia Grand account.\n\nUse this one-time link within one hour:\n" . $resetUrl . "\n\nIf you did not request this, you can ignore this message.";
        $headers = "From: Aurelia Grand <{$mailFrom}>\r\nContent-Type: text/plain; charset=UTF-8";
        if (!mail($email, $subject, $body, $headers)) {
            $remove = $conn->prepare('DELETE FROM password_reset_tokens WHERE id=?');
            $remove->bind_param('i', $tokenId);
            $remove->execute();
            error_log('Password reset email delivery failed for user id ' . $userId);
        }
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}

api_json(true, 'If an active account matches and email delivery succeeds, reset instructions will arrive shortly.');
$conn->close();
?>