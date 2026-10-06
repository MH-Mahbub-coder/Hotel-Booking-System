<?php
require_once __DIR__ . '/includes/database.php';
$user = api_require_login($conn);
api_require_role($user, 'admin');
api_require_method('POST');
$action = $_POST['action'] ?? '';

if ($action === 'get_inbox') {
    $result = $conn->query("SELECT user_email, MAX(user_name) AS user_name, MAX(created_at) AS last_message,
        SUM(CASE WHEN message_type='customer' AND is_read=0 THEN 1 ELSE 0 END) AS unread
        FROM chat_messages GROUP BY user_email ORDER BY last_message DESC LIMIT 200");
    $users = [];
    while ($row = $result->fetch_assoc()) $users[] = $row;
    echo json_encode(['success' => true, 'users' => $users], JSON_INVALID_UTF8_SUBSTITUTE);
} elseif ($action === 'fetch_conversation') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) {
        api_json(false, 'Invalid conversation.', null, 400);
        exit;
    }
    $markRead = $conn->prepare("UPDATE chat_messages SET is_read=1 WHERE user_email=? AND message_type='customer'");
    $markRead->bind_param('s', $email);
    $markRead->execute();
    $stmt = $conn->prepare('SELECT id, user_email, user_name, message, message_type, is_read, created_at FROM chat_messages WHERE user_email=? ORDER BY created_at ASC LIMIT 1000');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $messages = [];
    while ($row = $result->fetch_assoc()) $messages[] = $row;
    echo json_encode(['success' => true, 'messages' => $messages], JSON_INVALID_UTF8_SUBSTITUTE);
} elseif ($action === 'send_reply') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $message = trim($_POST['message'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120 || $message === '' || strlen($message) > 5000) {
        api_json(false, 'Enter a valid conversation and a message of at most 5000 characters.', null, 400);
        exit;
    }
    $nameLookup = $conn->prepare('SELECT user_name FROM chat_messages WHERE user_email=? ORDER BY created_at DESC LIMIT 1');
    $nameLookup->bind_param('s', $email);
    $nameLookup->execute();
    $recipient = $nameLookup->get_result()->fetch_assoc();
    if (!$recipient) {
        api_json(false, 'Conversation not found.', null, 404);
        exit;
    }
    $recipientName = $recipient['user_name'];
    $stmt = $conn->prepare("INSERT INTO chat_messages (message, message_type, user_name, user_email, is_read, created_at) VALUES (?, 'admin', ?, ?, 1, NOW())");
    $stmt->bind_param('sss', $message, $recipientName, $email);
    api_json($stmt->execute(), 'Reply sent.');
} else {
    api_json(false, 'Unknown chat action.', null, 400);
}
$conn->close();
?>
