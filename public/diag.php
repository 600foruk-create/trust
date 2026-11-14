<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Start session directly and set Super Admin user
require_once __DIR__ . '/../app/bootstrap.php';

$user = $pdo->query("SELECT u.*, r.name AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'Super Admin' LIMIT 1")->fetch();
$_SESSION['user'] = $user;
echo "Logged in as: " . htmlspecialchars($user['full_name']) . " (" . htmlspecialchars($user['role']) . ")<br>";

// Let's do a curl locally to beneficiaries.php with session cookie to see its actual HTTP output!
$sessionId = session_id();
$cookies = "madrasa_accounts_session=" . $sessionId;

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://palegoldenrod-dotterel-292319.hostingersite.com/beneficiaries.php?tab=tracker");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIE, $cookies);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code for tab=tracker: " . $httpCode . "<br>";
if ($httpCode !== 200) {
    echo "Response: <pre>" . htmlspecialchars(substr($res, 0, 1000)) . "</pre><br>";
} else {
    echo "tab=tracker returned 200 OK! Length: " . strlen($res) . "<br>";
}

// Test tab=directory
$ch2 = curl_init();
curl_setopt($ch2, CURLOPT_URL, "https://palegoldenrod-dotterel-292319.hostingersite.com/beneficiaries.php?tab=directory");
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_COOKIE, $cookies);
curl_setopt($ch2, CURLOPT_FOLLOWLOCATION, false);
curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
$res2 = curl_exec($ch2);
$httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);

echo "HTTP Code for tab=directory: " . $httpCode2 . "<br>";
if ($httpCode2 !== 200) {
    echo "Response: <pre>" . htmlspecialchars(substr($res2, 0, 1000)) . "</pre><br>";
} else {
    echo "tab=directory returned 200 OK! Length: " . strlen($res2) . "<br>";
}
