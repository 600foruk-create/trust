<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$activeUsers = function_exists('get_active_online_users') ? get_active_online_users(25) : [];
$recentActivities = function_exists('get_recent_login_activities') ? get_recent_login_activities(15) : [];
$currUid = (int)($_SESSION['user']['id'] ?? 0);

$desktopCount = 0;
$mobileCount = 0;
foreach ($activeUsers as $u) {
    if (($u['device_type'] ?? '') === 'Mobile') {
        $mobileCount++;
    } else {
        $desktopCount++;
    }
}

echo json_encode([
    'status' => 'ok',
    'online_count' => count($activeUsers),
    'desktop_count' => $desktopCount,
    'mobile_count' => $mobileCount,
    'current_user_id' => $currUid,
    'active_users' => array_map(function($u) use ($currUid) {
        $mins = (int)($u['minutes_ago'] ?? 0);
        $statusText = ($mins <= 1) ? 'Online Now' : "Active {$mins}m ago";
        $dev = $u['device_type'] ?: 'Desktop';
        $ip = $u['ip_address'] ?: '127.0.0.1';
        $loginTimeFormatted = !empty($u['login_time']) ? date('d M Y, h:i A', strtotime($u['login_time'])) : (!empty($u['last_login_at']) ? date('d M Y, h:i A', strtotime($u['last_login_at'])) : 'Recent');
        $initials = mb_strtoupper(mb_substr($u['full_name'] ?: $u['username'], 0, 2));
        return [
            'id' => (int)$u['id'],
            'full_name' => $u['full_name'] ?: $u['username'],
            'username' => $u['username'],
            'role_name' => $u['role_name'] ?: 'User',
            'initials' => $initials,
            'is_me' => ((int)$u['id'] === $currUid),
            'status_text' => $statusText,
            'device_type' => $dev,
            'ip_address' => $ip,
            'login_time' => $loginTimeFormatted,
        ];
    }, $activeUsers),
    'recent_activities' => array_map(function($a) {
        return [
            'id' => (int)$a['id'],
            'user_id' => (int)($a['user_id'] ?? 0),
            'full_name' => $a['full_name'] ?: $a['username'],
            'username' => $a['username'],
            'role_name' => $a['role_name'] ?: 'Staff',
            'initials' => mb_strtoupper(mb_substr($a['full_name'] ?: $a['username'], 0, 2)),
            'status' => $a['status'] ?: 'success',
            'device_type' => $a['device_type'] ?: 'Desktop',
            'ip_address' => $a['ip_address'] ?: '—',
            'date_time' => date('d M Y, h:i A', strtotime($a['created_at'])),
        ];
    }, $recentActivities),
], JSON_UNESCAPED_UNICODE);
exit;
