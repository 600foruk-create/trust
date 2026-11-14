<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../app/bootstrap.php';

$user = $pdo->query("SELECT u.*, r.name AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'Super Admin' LIMIT 1")->fetch();
$_SESSION['user'] = $user;
$token = csrf_token();
$sessionId = session_id();
session_write_close();

$cookies = "madrasa_accounts_session=" . $sessionId;

// Simulate submitting a new student
$postData = [
    'csrf_token' => $token,
    'action' => 'save_beneficiary',
    'return_tab' => 'tracker',
    'category' => 'student',
    'full_name' => 'Test Student Diagnostic',
    'father_name' => 'Test Father',
    'reg_no' => 'TEST-001',
    'department_class' => 'Hifz',
    'gender' => 'male',
    'phone' => '03001234567',
    'guardian_cnic' => '12345-1234567-1',
    'assigned_fund_id' => '1',
    'assigned_donor_id' => '',
    'monthly_stipend_amount' => '1000',
    'status' => 'active',
    'address' => 'Test Address',
    'notes' => 'Diagnostic test note'
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://palegoldenrod-dotterel-292319.hostingersite.com/beneficiaries.php");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIE, $cookies);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 8);
$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
curl_close($ch);

echo "POST save_beneficiary HTTP Code: " . $httpCode . "<br>";
echo "Redirect URL: " . htmlspecialchars($redirectUrl) . "<br>";
if ($httpCode !== 302 && $httpCode !== 200) {
    echo "Response: <pre>" . htmlspecialchars(substr($res, 0, 1500)) . "</pre><br>";
}

// Clean up test student
$pdo->exec("DELETE FROM beneficiaries WHERE full_name = 'Test Student Diagnostic'");
echo "Diagnostic complete.";
