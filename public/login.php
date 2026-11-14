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

$is2faEnabled = (get_setting('admin_2fa_enabled', '0') === '1');
$adminPin = get_setting('admin_security_pin', '7860');

if (isset($_GET['cancel_2fa'])) {
    unset($_SESSION['pending_2fa_user']);
    header('Location: login.php');
    exit;
}

$error = '';
$show2faForm = false;

// If user is currently in 2FA step
if (!empty($_SESSION['pending_2fa_user'])) {
    $show2faForm = true;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['security_pin'])) {
        verify_csrf();
        $enteredPin = trim($_POST['security_pin'] ?? '');
        $pendingUser = $_SESSION['pending_2fa_user'];
        if ($enteredPin !== '' && ($enteredPin === $adminPin || hash_equals($adminPin, $enteredPin))) {
            // PIN verified successfully
            unset($_SESSION['pending_2fa_user']);
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id' => (int)$pendingUser['id'],
                'name' => $pendingUser['full_name'],
                'username' => $pendingUser['username'],
                'role' => $pendingUser['role']
            ];
            $pdo->prepare('UPDATE users SET last_login_at=NOW(), last_seen_at=NOW() WHERE id=?')->execute([$pendingUser['id']]);
            $_SESSION['last_seen_ts'] = time();
            if (function_exists('log_audit_trail')) {
                log_audit_trail('login_2fa_verified', 'user', (int)$pendingUser['id'], ['username' => $pendingUser['username']], (int)$pendingUser['id']);
            }
            if (function_exists('record_login_activity')) {
                record_login_activity((int)$pendingUser['id'], $pendingUser['username'], 'success');
            }
            header('Location: dashboard.php');
            exit;
        } else {
            $error = 'غلط سیکیورٹی پن کوڈ! براہ کرم درست پن درج فرمائیں یا ایڈمن سے رابطہ کریں۔ (Invalid Security PIN)';
            if (function_exists('record_login_activity')) {
                record_login_activity((int)$pendingUser['id'], $pendingUser['username'], 'failed');
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $stmt = $pdo->prepare('SELECT u.id,u.full_name,u.username,u.password_hash,u.is_active,r.name AS role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.username=? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if ($user && (int)$user['is_active'] === 1 && password_verify($password, $user['password_hash'])) {
        // If 2FA is active and user is an Admin
        $isAdminRole = in_array($user['role'], ['Super Admin', 'Admin', 'Administrator', 'Regional Director'], true);
        if ($is2faEnabled && $isAdminRole) {
            $_SESSION['pending_2fa_user'] = $user;
            header('Location: login.php');
            exit;
        }

        // Standard Login
        session_regenerate_id(true);
        $_SESSION['user'] = ['id'=>(int)$user['id'],'name'=>$user['full_name'],'username'=>$user['username'],'role'=>$user['role']];
        $pdo->prepare('UPDATE users SET last_login_at=NOW(), last_seen_at=NOW() WHERE id=?')->execute([$user['id']]);
        $_SESSION['last_seen_ts'] = time();
        if (function_exists('log_audit_trail')) {
            log_audit_trail('login', 'user', (int)$user['id'], ['username' => $user['username']], (int)$user['id']);
        }
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

        <?php if ($show2faForm): ?>
            <!-- 2FA Security PIN Verification Step -->
            <div style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:10px;padding:14px;margin-bottom:16px;text-align:center;">
                <div style="font-size:32px;margin-bottom:4px;">🔐</div>
                <b style="color:#1e40af;font-size:14.5px;display:block;">دو فیکٹر سیکیورٹی تصدیق (Two-Factor PIN)</b>
                <p style="font-size:12px;color:#3b82f6;margin:4px 0 0;">
                    خوش آمدید <strong><?=e($_SESSION['pending_2fa_user']['full_name'])?></strong>!<br>
                    ایڈمن سیکیورٹی کوڈ / ماسٹر پن درج فرمائیں:
                </p>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                <label style="text-align:center;display:block;font-weight:700;margin-bottom:6px;">4-Digit Security PIN <span class="ur">(سیکیورٹی پن)</span></label>
                <input type="password" name="security_pin" maxlength="10" required autofocus autocomplete="one-time-code" placeholder="••••" style="text-align:center;font-size:24px;letter-spacing:8px;font-weight:800;padding:10px;border:2px solid #3b82f6;border-radius:8px;width:100%;box-sizing:border-box;">
                <button class="btn primary full" style="margin-top:14px;padding:12px;font-size:14px;font-weight:700;background:#1e40af;">
                    ✅ تصدیق کریں اور لاگ ان ہوں (Verify &amp; Enter)
                </button>
                <div style="text-align:center;margin-top:12px;">
                    <a href="login.php?cancel_2fa=1" style="font-size:12px;color:#64748b;text-decoration:none;">
                        ⬅️ کسی دوسرے صارف سے لاگ ان کریں (Cancel)
                    </a>
                </div>
            </form>
        <?php else: ?>
            <!-- Standard Login Form -->
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                <label>Username</label>
                <input name="username" required autocomplete="username">
                <label>Password</label>
                <input type="password" name="password" required autocomplete="current-password">
                <button class="btn primary full">Sign in</button>
            </form>
        <?php endif; ?>
        <p class="muted center">Authorized users only</p>
    </main>
</body>
</html>
