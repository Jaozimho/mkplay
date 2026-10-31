<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../api/includes/db.php';
require_once __DIR__ . '/../api/includes/api_log.php';

$adminId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
if (empty($_SESSION['logged_in']) || $adminId === false || $adminId === null) {
    header('Location: login.php');
    exit;
}

$adminCheck = getDB()->prepare('SELECT admin FROM users WHERE id = ?');
$adminCheck->execute([$adminId]);
if ((int) $adminCheck->fetchColumn() !== 1) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    register_shutdown_function(static function () use ($adminId): void {
        $action = $_POST['action'] ?? $_POST['device_action'] ?? '';
        if (!is_string($action) || $action === '') {
            $action = 'unknown';
        }
        apiLog('admin_action_processed', [
            'admin_id' => (int) $adminId,
            'action' => substr($action, 0, 80),
            'field_names' => implode(',', array_slice(array_keys($_POST), 0, 40)),
            'http_status' => http_response_code() ?: 200,
        ]);
    });
}
apiStartRequestLogging();
