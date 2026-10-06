<?php
require_once __DIR__ . '/includes/database.php';
api_require_method('POST');
$action = $_POST['action'] ?? '';

if (!empty($_SESSION['logged_in'])) {
    $user = api_require_login($conn);
    api_require_role($user, 'guest');
    $currentEmail = $user['email'];
    $currentName = trim($user['first_name'] . ' ' . $user['last_name']);
} else {
    if (!isset($_SESSION['guest_chat_email'])) {
        $_SESSION['guest_chat_email'] = 'guest_' . bin2hex(random_bytes(12)) . '@guest.local';
        $_SESSION['guest_chat_name'] = 'Guest User';
    }
    $currentEmail = $_SESSION['guest_chat_email'];
    $currentName = $_SESSION['guest_chat_name'];
}

if ($action === 'send_message') {
    $message = trim($_POST['message'] ?? '');
    if ($message === '' || strlen($message) > 5000) {
        api_json(false, 'Message must contain 1 to 5000 characters.', null, 400);
        exit;
    }
    if (empty($_SESSION['logged_in'])) {
        $contactName = trim($_POST['name'] ?? '');
        $contactEmail = strtolower(trim($_POST['email'] ?? ''));
        if ($contactName !== '' || $contactEmail !== '') {
            if ($contactName === '' || strlen($contactName) > 120 || !filter_var($contactEmail, FILTER_VALIDATE_EMAIL) || strlen($contactEmail) > 120) {
                api_json(false, 'Enter a valid name and email address.', null, 400);
                exit;
            }
            $currentName = $contactName;
            $currentEmail = $contactEmail;
        }
    }

    $stmt = $conn->prepare("INSERT INTO chat_messages (message, message_type, user_name, user_email, is_read, created_at) VALUES (?, 'customer', ?, ?, 0, NOW())");
    $stmt->bind_param('sss', $message, $currentName, $currentEmail);
    $stmt->execute();
    api_json(true, 'Message sent.');
} elseif ($action === 'fetch_messages') {
    $stmt = $conn->prepare('SELECT message_type, message, created_at FROM chat_messages WHERE user_email=? ORDER BY created_at ASC LIMIT 500');
    $stmt->bind_param('s', $currentEmail);
    $stmt->execute();
    $result = $stmt->get_result();
    $messages = [];
    while ($row = $result->fetch_assoc()) {
        $messages[] = ['type' => $row['message_type'], 'message' => $row['message'], 'time' => date('H:i', strtotime($row['created_at']))];
    }
    echo json_encode(['success' => true, 'messages' => $messages], JSON_INVALID_UTF8_SUBSTITUTE);
} else {
    api_json(false, 'Unknown chat action.', null, 400);
}
$conn->close();
?>
