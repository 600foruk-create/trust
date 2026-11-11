<?php
require __DIR__ . '/../app/bootstrap.php';

// If setup is not complete, redirect to setup
try {
    $hasUsers = !empty($pdo->query("SHOW TABLES LIKE 'users'")->fetch());
    if (!$hasUsers || (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
        header('Location: setup_admin.php');
        exit;
    }
} catch (Exception $e) {
    header('Location: setup_admin.php');
    exit;
}

if (current_user()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $stmt = $pdo->prepare('SELECT u.id,u.full_name,u.username,u.password_hash,u.is_active,r.name AS role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.username=? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if ($user && (int)$user['is_active'] === 1 && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = ['id'=>(int)$user['id'],'name'=>$user['full_name'],'username'=>$user['username'],'role'=>$user['role']];
        $pdo->prepare('UPDATE users SET last_login_at=NOW(), last_seen_at=NOW() WHERE id=?')->execute([$user['id']]);
        $_SESSION['last_seen_ts'] = time();
        $log = $pdo->prepare('INSERT INTO audit_logs (user_id,action,entity_type,entity_id,ip_address) VALUES (?,?,?,?,?)');
        $log->execute([$user['id'],'login','user',$user['id'],$_SERVER['REMOTE_ADDR'] ?? null]);
        if (function_exists('record_login_activity')) {
            record_login_activity((int)$user['id'], $username, 'success');
        }
        header('Location: dashboard.php'); exit;
    }
    if (function_exists('record_login_activity')) {
        record_login_activity($user ? (int)$user['id'] : null, $username, 'failed');
    }
    $error = 'Username or password is incorrect, or this account is inactive.';
}
$madrasaName = function_exists('get_setting') ? get_setting('madrasa_name', 'Trust Accounts') : 'Trust Accounts';
$shortBrand = mb_strtoupper(mb_substr($madrasaName, 0, 2));
$orgLogo = function_exists('get_org_logo_url') ? get_org_logo_url() : null;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Login | <?=e($madrasaName)?></title>
    <link rel="stylesheet" href="assets/style.css?v=<?=css_ver()?>">
</head>
<body class="auth-bg">
    <main class="auth-card">
        <?php if ($orgLogo): ?>
            <div style="margin-bottom:16px;">
                <img src="<?=e($orgLogo)?>" alt="Logo" style="max-height:70px;max-width:140px;object-fit:contain;margin:0 auto;display:block;">
            </div>
        <?php else: ?>
            <div class="brand-mark"><?=e($shortBrand ?: 'TA')?></div>
        <?php endif; ?>
        <h1><?=e($madrasaName)?></h1>
        <p class="muted">Sign in to your accounting workspace</p>
        <?php if(isset($_GET['setup'])): ?>
            <div class="alert success">Administrator account created successfully. Please sign in.</div>
        <?php endif; ?>
        <?php if($error): ?>
            <div class="alert error"><?=e($error)?></div>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
            <label>Username</label>
            <input name="username" required autocomplete="username">
            <label>Password</label>
            <input type="password" name="password" required autocomplete="current-password">
            <button class="btn primary full">Sign in</button>
        </form>
        <p class="muted center">Authorized users only</p>
    </main>
</body>
</html>
