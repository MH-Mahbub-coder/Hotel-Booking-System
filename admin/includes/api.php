<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', '1800');

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
$isProduction = getenv('APP_ENV') === 'production';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps || $isProduction,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: https:; connect-src 'self'; frame-src https://maps.google.com; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

set_exception_handler(static function (Throwable $error): void {
    error_log((string)$error);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['success' => false, 'message' => 'The request could not be completed.']);
});

function api_json(bool $success, string $message = '', $data = null, int $status = 200, array $extra = []): void
{
    http_response_code($status);
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) {
        $response['data'] = $data;
    }
    echo json_encode(array_merge($response, $extra), JSON_INVALID_UTF8_SUBSTITUTE);
}

function api_require_method(string ...$methods): void
{
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', $methods, true)) {
        api_json(false, 'Method not allowed.', null, 405);
        exit;
    }
}

function api_require_csrf(): void
{
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!is_string($provided) || $expected === '' || !hash_equals($expected, $provided)) {
        api_json(false, 'Your session expired. Refresh the page and try again.', null, 403);
        exit;
    }
}

function api_require_login(mysqli $conn): array
{
    $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$userId || empty($_SESSION['logged_in'])) {
        api_json(false, 'Authentication required.', null, 401);
        exit;
    }

    $stmt = $conn->prepare('SELECT id, first_name, last_name, email, role, is_active, password FROM users WHERE id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $passwordFingerprint = $user ? hash('sha256', $user['password']) : '';
    if (!$user || (int)$user['is_active'] !== 1 || empty($_SESSION['password_fingerprint']) || !hash_equals($_SESSION['password_fingerprint'], $passwordFingerprint)) {
        $csrfToken = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
        $_SESSION = [];
        $_SESSION['csrf_token'] = $csrfToken;
        session_regenerate_id(true);
        api_json(false, 'Authentication required.', null, 401);
        exit;
    }
    $_SESSION['role'] = $user['role'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['user_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
    return $user;
}

function api_require_role(array $user, string ...$roles): void
{
    if (!in_array($user['role'], $roles, true)) {
        api_json(false, 'You are not allowed to perform this action.', null, 403);
        exit;
    }
}

function api_valid_new_password(string $password): bool
{
    $characters = preg_match_all('/./us', $password);
    return $characters !== false && $characters >= 12 && strlen($password) <= 72;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    api_require_csrf();
}

function api_rate_limit(mysqli $conn, string $bucket, int $limit, int $windowSeconds): void
{
    $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rateKey = hash('sha256', $bucket . "\0" . $remoteAddress);
    $conn->begin_transaction();
    try {
        $seed = $conn->prepare('INSERT INTO request_rate_limits (rate_key, window_started_at, attempts) VALUES (?, NOW(), 0) ON DUPLICATE KEY UPDATE rate_key=VALUES(rate_key)');
        $seed->bind_param('s', $rateKey);
        $seed->execute();
        $lookup = $conn->prepare('SELECT attempts, TIMESTAMPDIFF(SECOND, window_started_at, NOW()) AS window_age FROM request_rate_limits WHERE rate_key=? FOR UPDATE');
        $lookup->bind_param('s', $rateKey);
        $lookup->execute();
        $row = $lookup->get_result()->fetch_assoc();
        if ((int)$row['window_age'] < $windowSeconds && (int)$row['attempts'] >= $limit) {
            $conn->commit();
            api_json(false, 'Too many attempts. Please wait and try again.', null, 429);
            exit;
        }
        if ((int)$row['window_age'] >= $windowSeconds) {
            $update = $conn->prepare('UPDATE request_rate_limits SET window_started_at=NOW(), attempts=1 WHERE rate_key=?');
            $update->bind_param('s', $rateKey);
        } else {
            $update = $conn->prepare('UPDATE request_rate_limits SET attempts=attempts+1 WHERE rate_key=?');
            $update->bind_param('s', $rateKey);
        }
        $update->execute();
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}