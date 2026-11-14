<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../app/bootstrap.php';

$user = $pdo->query("SELECT u.*, r.name AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'Super Admin' LIMIT 1")->fetch();
$_SESSION['user'] = $user;
$sessionId = session_id();
session_write_close();

$cookies = "madrasa_accounts_session=" . $sessionId;

$files = glob(__DIR__ . '/*.php');
$results = [];

foreach ($files as $file) {
    $basename = basename($file);
    if ($basename === 'diag.php' || $basename === 'logout.php') continue;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://palegoldenrod-dotterel-292319.hostingersite.com/" . $basename);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIE, $cookies);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $results[$basename] = $code;
}

foreach ($results as $page => $code) {
    $color = ($code === 200 || $code === 302) ? 'green' : 'red';
    echo "<span style='color:{$color};font-weight:bold;'>{$page}</span>: {$code}<br>";
}
