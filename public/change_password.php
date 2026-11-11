<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();

$activePage = 'profile';
$error = '';
$success = '';
$userId = (int)current_user()['id'];

// Fetch current user details
$stmtUser = $pdo->prepare("SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?");
$stmtUser->execute([$userId]);
$currentUserData = $stmtUser->fetch();

if (!$currentUserData) {
    http_response_code(403);
    exit('User record not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_my_password') {
    verify_csrf();
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        $error = 'براہ کرم تمام فیلڈز مکمل پُر کریں۔ (Please fill all fields).';
    } elseif (strlen($newPassword) < 8) {
        $error = 'نیا پاس ورڈ کم از کم 8 حروف کا ہونا چاہیے۔ (New password must be at least 8 characters long).';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'نیا پاس ورڈ اور تصدیقی پاس ورڈ ایک جیسے نہیں ہیں۔ (New password and confirmation do not match).';
    } elseif (!password_verify($currentPassword, (string)$currentUserData['password_hash'])) {
        $error = '❌ موجودہ پاس ورڈ درست نہیں ہے۔ (Current password is incorrect).';
    } else {
        try {
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmtUp = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmtUp->execute([$newHash, $userId]);

            // Audit log
            try {
                $stmtLog = $pdo->prepare("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address) VALUES (?, ?, ?, ?, ?)");
                $stmtLog->execute([$userId, 'change_own_password', 'user', $userId, $_SERVER['REMOTE_ADDR'] ?? null]);
            } catch (Exception $ex) {}

            $success = '✓ پاس ورڈ کامیابی سے تبدیل ہو گیا ہے۔ آئندہ لاگ اِن کے لیے نیا پاس ورڈ استعمال کریں۔ (Password changed successfully!)';
            // Refresh currentUserData hash
            $currentUserData['password_hash'] = $newHash;
        } catch (Exception $e) {
            $error = 'پاس ورڈ تبدیل کرنے میں خرابی: ' . $e->getMessage();
        }
    }
}

$madrasaName = get_setting('madrasa_name', 'Trust Accounts');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Change Password | <?=e($madrasaName)?></title>
    <link rel="stylesheet" href="assets/style.css?v=<?=css_ver()?>">
</head>
<body>
<div class="app-shell">
    <?php require __DIR__ . '/../app/sidebar.php'; ?>

    <main class="main">
        <header class="topbar">
            <div>
                <span class="eyebrow">USER SECURITY</span>
                <h1>Change Password <span class="ur">(پاس ورڈ کی تبدیلی)</span></h1>
            </div>
            <div class="btn-row">
                <a href="dashboard.php" class="btn secondary sm">&larr; Back to Dashboard</a>
            </div>
        </header>

        <?php if (!empty($error)): ?>
            <div class="alert danger" style="margin-bottom:18px;"><?=e($error)?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert success" style="margin-bottom:18px;"><?=e($success)?></div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:minmax(0, 560px) minmax(0, 340px);gap:24px;align-items:start;">
            <!-- Change Password Form Card -->
            <article class="panel">
                <div class="panel-heading">
                    <div>
                        <h3>Update Your Password <span class="ur">(اپنا پاس ورڈ تبدیل کریں)</span></h3>
                        <p>Keep your account secure by using a strong, unique password</p>
                    </div>
                </div>

                <form method="post" action="change_password.php" style="padding:24px;">
                    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                    <input type="hidden" name="action" value="change_my_password">

                    <div class="form-group" style="margin-bottom:18px;">
                        <label>Current Password <span class="ur">(موجودہ پاس ورڈ)</span> *</label>
                        <input type="password" name="current_password" required placeholder="Enter current login password" autocomplete="current-password" style="width:100%;">
                    </div>

                    <div class="form-group" style="margin-bottom:18px;">
                        <label>New Password <span class="ur">(نیا پاس ورڈ)</span> *</label>
                        <input type="password" name="new_password" required minlength="8" placeholder="At least 8 characters" autocomplete="new-password" style="width:100%;">
                        <small class="muted">Minimum 8 characters (use letters, numbers, and symbols for best security).</small>
                    </div>

                    <div class="form-group" style="margin-bottom:24px;">
                        <label>Confirm New Password <span class="ur">(نئے پاس ورڈ کی تصدیق)</span> *</label>
                        <input type="password" name="confirm_password" required minlength="8" placeholder="Re-type new password" autocomplete="new-password" style="width:100%;">
                    </div>

                    <div class="btn-row">
                        <button type="submit" class="btn blue">
                            💾 Update Password <span class="ur">(پاس ورڈ محفوظ کریں)</span>
                        </button>
                        <a href="dashboard.php" class="btn secondary">Cancel</a>
                    </div>
                </form>
            </article>

            <!-- User Info & Security Card -->
            <article class="panel">
                <div class="panel-heading">
                    <div>
                        <h3>Account Profile <span class="ur">(اکاؤنٹ معلومات)</span></h3>
                    </div>
                </div>
                <div style="padding:22px;">
                    <div style="display:flex;align-items:center;gap:14px;margin-bottom:18px;padding-bottom:16px;border-bottom:1px solid var(--line);">
                        <div style="width:48px;height:48px;border-radius:50%;background:#2563eb;color:#fff;display:grid;place-items:center;font-weight:700;font-size:18px;">
                            <?=e(mb_strtoupper(mb_substr($currentUserData['full_name'], 0, 2)))?>
                        </div>
                        <div>
                            <b style="font-size:15px;color:#0f172a;display:block;"><?=e($currentUserData['full_name'])?></b>
                            <span style="font-size:12px;color:#64748b;">@<?=e($currentUserData['username'])?></span>
                        </div>
                    </div>

                    <div style="font-size:13px;line-height:1.7;color:#334155;">
                        <div style="display:flex;justify-content:space-between;margin-bottom:8px;">
                            <span style="color:#64748b;">Role / عہدہ:</span>
                            <span class="badge badge-purple"><?=e($currentUserData['role_name'])?></span>
                        </div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:8px;">
                            <span style="color:#64748b;">Status / حیثیت:</span>
                            <span class="badge badge-green">Active (فعال)</span>
                        </div>
                    </div>

                    <div style="margin-top:20px;padding:12px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;font-size:12px;color:#475569;line-height:1.5;">
                        <b style="color:#0f172a;display:block;margin-bottom:4px;">🛡️ Security Tips:</b>
                        <span class="ur">پاس ورڈ کسی کے ساتھ شیئر نہ کریں۔ اگر آپ کو لگے کہ پاس ورڈ کسی اور کو معلوم ہو گیا ہے تو اسے فوری تبدیل کریں۔</span>
                        <span class="en-only" style="display:none;">Never share your credentials with anyone. Change password immediately if you suspect unauthorized access.</span>
                    </div>
                </div>
            </article>
        </div>

        <?=render_app_footer($madrasaName)?>
    </main>
</div>
</body>
</html>

