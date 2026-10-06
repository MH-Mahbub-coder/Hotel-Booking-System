<?php
require_once __DIR__ . '/includes/database.php';
api_require_method('POST');

$token = strtolower(trim($_POST['token'] ?? ''));
$password = $_POST['password'] ?? '';
if (!preg_match('/^[a-f0-9]{64}$/', $token) || !api_valid_new_password($password)) {
    api_json(false, 'The reset link is invalid or expired, or the new password does not meet the requirements.', null, 400);
    exit;
}

$tokenHash = hash('sha256', $token);
$conn->begin_transaction();
try {
    $lookup = $conn->prepare('SELECT id, user_id FROM password_reset_tokens WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW() FOR UPDATE');
    $lookup->bind_param('s', $tokenHash);
    $lookup->execute();
    $reset = $lookup->get_result()->fetch_assoc();
    if (!$reset) {
        throw new DomainException('The reset link is invalid or expired.');
    }

    $userId = (int)$reset['user_id'];
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $updateUser = $conn->prepare('UPDATE users SET password=? WHERE id=? AND is_active=1');
    $updateUser->bind_param('si', $passwordHash, $userId);
    $updateUser->execute();
    if ($updateUser->affected_rows !== 1) {
        throw new DomainException('The reset link is invalid or expired.');
    }
    $consume = $conn->prepare('UPDATE password_reset_tokens SET used_at=NOW() WHERE id=? AND used_at IS NULL');
    $consume->bind_param('i', $reset['id']);
    $consume->execute();
    $removeOther = $conn->prepare('DELETE FROM password_reset_tokens WHERE user_id=? AND id<>?');
    $removeOther->bind_param('ii', $userId, $reset['id']);
    $removeOther->execute();
    $conn->commit();
    api_json(true, 'Password updated. Please sign in with your new password.');
} catch (DomainException $error) {
    $conn->rollback();
    api_json(false, $error->getMessage(), null, 400);
} catch (Throwable $error) {
    $conn->rollback();
    throw $error;
}
$conn->close();
?>