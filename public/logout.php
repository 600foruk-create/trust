<?php
require __DIR__ . '/../app/bootstrap.php';
if (current_user()) {
    $uid = (int)current_user()['id'];
    $stmt = $pdo->prepare('INSERT INTO audit_logs (user_id,action,entity_type,entity_id,ip_address) VALUES (?,?,?,?,?)');
    $stmt->execute([$uid,'logout','user',$uid,$_SERVER['REMOTE_ADDR'] ?? null]);
    try {
        $pdo->prepare('UPDATE users SET last_seen_at = NULL WHERE id = ?')->execute([$uid]);
    } catch (Throwable $e) {}
}
$_SESSION = [];
if (ini_get('session.use_cookies')) { $p=session_get_cookie_params(); setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'] ?? '',$p['secure'],$p['httponly']); }
session_destroy();
header('Location: login.php'); exit;
