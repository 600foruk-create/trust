<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/../app/bootstrap.php';

// Log in as Super Admin
$user = $pdo->query("SELECT u.*, r.name AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'Super Admin' LIMIT 1")->fetch();
$_SESSION['user'] = $user;
echo "Logged in as: " . htmlspecialchars($user['full_name']) . " (" . htmlspecialchars($user['role']) . ")<br>";

// Test including beneficiaries.php in output buffer
$_GET['tab'] = 'tracker';
$_GET['month'] = date('Y-m');

ob_start();
try {
    include __DIR__ . '/beneficiaries.php';
    $out = ob_get_clean();
    echo "beneficiaries.php included successfully! Output length: " . strlen($out) . " bytes.<br>";
} catch (Throwable $e) {
    ob_end_clean();
    echo "<b style='color:red;'>ERROR including beneficiaries.php:</b> " . htmlspecialchars($e->getMessage()) . "<br>";
    echo "In " . $e->getFile() . " on line " . $e->getLine() . "<br>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

// Test with tab=directory
$_GET['tab'] = 'directory';
ob_start();
try {
    include __DIR__ . '/beneficiaries.php';
    $out2 = ob_get_clean();
    echo "beneficiaries.php (directory) included successfully! Output length: " . strlen($out2) . " bytes.<br>";
} catch (Throwable $e) {
    ob_end_clean();
    echo "<b style='color:red;'>ERROR including beneficiaries.php (directory):</b> " . htmlspecialchars($e->getMessage()) . "<br>";
    echo "In " . $e->getFile() . " on line " . $e->getLine() . "<br>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
