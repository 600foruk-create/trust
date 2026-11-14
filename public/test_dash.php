<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../app/bootstrap.php';
$user = $pdo->query("SELECT u.*, r.name AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'Super Admin' LIMIT 1")->fetch();
$_SESSION['user'] = $user;

ob_start();
include __DIR__ . '/dashboard.php';
$out = ob_get_clean();

echo "DASHBOARD_OK_LENGTH:" . strlen($out);
