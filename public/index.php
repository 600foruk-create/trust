<?php
require __DIR__ . '/../app/bootstrap.php';

// Auto-check if setup is needed
try {
    $hasUsersTable = !empty($pdo->query("SHOW TABLES LIKE 'users'")->fetch());
    if (!$hasUsersTable || (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
        header('Location: setup_admin.php');
        exit;
    }
} catch (Exception $e) {
    header('Location: setup_admin.php');
    exit;
}

header('Location: ' . (current_user() ? 'dashboard.php' : 'login.php'));
exit;
