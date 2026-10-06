<?php
require_once __DIR__ . '/../includes/database.php';
$user = api_require_login($conn);
api_require_role($user, 'admin', 'staff');
$userId = (int)$user['id'];
$action = $_POST['action'] ?? $_GET['action'] ?? 'get';

if ($action === 'get') {
    if ($user['role'] === 'admin') {
        $result = $conn->query("SELECT t.*, u.first_name, u.last_name FROM admin_tasks t LEFT JOIN users u ON u.id=t.assigned_to ORDER BY t.created_at DESC");
    } else {
        $stmt = $conn->prepare("SELECT t.*, u.first_name, u.last_name FROM admin_tasks t LEFT JOIN users u ON u.id=t.assigned_to WHERE t.assigned_to=? ORDER BY t.created_at DESC");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
    }
    $tasks = [];
    while ($row = $result->fetch_assoc()) $tasks[] = $row;
    echo json_encode(['success' => true, 'data' => $tasks], JSON_INVALID_UTF8_SUBSTITUTE);
} elseif ($action === 'add') {
    api_require_role($user, 'admin');
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $assignedTo = filter_var($_POST['assigned_to'] ?? null, FILTER_VALIDATE_INT);
    $priority = $_POST['priority'] ?? 'medium';
    $dueDate = trim($_POST['due_date'] ?? '');
    $allowedPriorities = ['low', 'medium', 'high', 'urgent'];
    $parsedDate = $dueDate === '' ? null : DateTime::createFromFormat('!Y-m-d', $dueDate);
    $dateErrors = DateTime::getLastErrors();
    $validDate = $dueDate === '' || ($parsedDate && $parsedDate->format('Y-m-d') === $dueDate && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0)));

    if ($title === '' || strlen($title) > 200 || strlen($description) > 5000 || !in_array($priority, $allowedPriorities, true) || !$validDate) {
        api_json(false, 'Invalid task details.', null, 400);
        exit;
    }
    if ($assignedTo) {
        $check = $conn->prepare("SELECT id FROM users WHERE id=? AND role='staff' AND is_active=1");
        $check->bind_param('i', $assignedTo);
        $check->execute();
        if (!$check->get_result()->fetch_assoc()) {
            api_json(false, 'Assigned staff member was not found.', null, 400);
            exit;
        }
    } else {
        $assignedTo = null;
    }
    $dueDate = $dueDate === '' ? null : $dueDate;
    $stmt = $conn->prepare('INSERT INTO admin_tasks (title,description,assigned_to,created_by,priority,due_date) VALUES (?,?,?,?,?,?)');
    $stmt->bind_param('ssiiss', $title, $description, $assignedTo, $userId, $priority, $dueDate);
    echo json_encode(['success' => $stmt->execute()]);
} elseif ($action === 'update_status') {
    $taskId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    $status = $_POST['status'] ?? '';
    if (!$taskId || !in_array($status, ['todo', 'in-progress', 'done', 'cancelled'], true)) {
        api_json(false, 'Invalid task update.', null, 400);
        exit;
    }
    if ($user['role'] === 'admin') {
        $check = $conn->prepare('SELECT id FROM admin_tasks WHERE id=?');
        $check->bind_param('i', $taskId);
    } else {
        $check = $conn->prepare('SELECT id FROM admin_tasks WHERE id=? AND assigned_to=?');
        $check->bind_param('ii', $taskId, $userId);
    }
    $check->execute();
    if (!$check->get_result()->fetch_assoc()) {
        api_json(false, 'Task not found or not assigned to you.', null, 404);
        exit;
    }
    $stmt = $conn->prepare('UPDATE admin_tasks SET status=? WHERE id=?');
    $stmt->bind_param('si', $status, $taskId);
    echo json_encode(['success' => $stmt->execute()]);
} elseif ($action === 'delete') {
    api_require_role($user, 'admin');
    $taskId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$taskId) {
        api_json(false, 'Invalid task ID.', null, 400);
        exit;
    }
    $stmt = $conn->prepare('DELETE FROM admin_tasks WHERE id=?');
    $stmt->bind_param('i', $taskId);
    echo json_encode(['success' => $stmt->execute()]);
} else {
    api_json(false, 'Unknown action.', null, 400);
}
$conn->close();
?>
