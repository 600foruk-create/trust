<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();

require_permission('settings.manage'); if (false) {
    http_response_code(403);
    exit('Only Administrators and Approvers have permission to access Settings.');
}

$activePage = 'settings';
$error = '';
$success = '';

// Check & run daily automated backup in background if due
if (function_exists('auto_run_daily_backup')) {
    try {
        auto_run_daily_backup($pdo);
    } catch (Exception $e) {}
}

// Handle Full Database Backup Export (Direct Live Download)
if (isset($_GET['action']) && $_GET['action'] === 'export_db_backup') {
    $token = $_GET['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        exit('Invalid security token.');
    }
    $currUser = current_user();
    $userRole = $currUser['role'] ?? '';
    $isSuperAdmin = in_array($userRole, ['Super Admin', 'Administrator'], true);

    if (!$isSuperAdmin && !has_permission('settings.manage')) {
        http_response_code(403);
        exit('Access denied. Only Super Admin can export database backups.');
    }

    try {
        $backupDir = get_backup_dir();
        $filename = 'database_backup_' . date('Y-m-d_His') . '.sql';
        $filePath = $backupDir . '/' . $filename;

        // Generate clean SQL file on server disk first
        $ok = generate_sql_database_backup($pdo, $filePath);
        if (!$ok || !file_exists($filePath)) {
            throw new Exception('Could not write backup archive on disk.');
        }

        // Clean any active output buffers to avoid corrupting HTTP binary stream
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));

        readfile($filePath);
        exit;
    } catch (Throwable $e) {
        error_log('Database backup failed: ' . $e->getMessage());
        header('Location: settings.php?tab=backups&error=' . urlencode('بیک اپ تیار کرنے میں خرابی: ' . $e->getMessage()) . '#backupSection');
        exit;
    }
}

// Handle Instant Manual Snapshot to Archive
if (isset($_GET['action']) && $_GET['action'] === 'run_backup_now') {
    $token = $_GET['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        exit('Invalid security token.');
    }
    $currUser = current_user();
    $userRole = $currUser['role'] ?? '';
    $isSuperAdmin = in_array($userRole, ['Super Admin', 'Administrator'], true);

    if (!$isSuperAdmin && !has_permission('settings.manage')) {
        http_response_code(403);
        exit('Access denied. Only Super Admin can generate database backups.');
    }

    try {
        $path = auto_run_daily_backup($pdo, true);
        if ($path) {
            header('Location: settings.php?tab=backups&success=backup_created#backupSection');
            exit;
        } else {
            $error = 'بیک اپ فائل تیار کرنے میں ناکامی ہوئی! (Failed to generate backup).';
        }
    } catch (Exception $e) {
        $error = 'خرابی: ' . $e->getMessage();
    }
}

// Handle Download of an Archived Backup File
if (isset($_GET['action']) && $_GET['action'] === 'download_saved_backup') {
    $token = $_GET['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        exit('Invalid security token.');
    }
    $currUser = current_user();
    $userRole = $currUser['role'] ?? '';
    $isSuperAdmin = in_array($userRole, ['Super Admin', 'Administrator'], true);

    if (!$isSuperAdmin && !has_permission('settings.manage')) {
        http_response_code(403);
        exit('Access denied.');
    }

    $fileName = basename($_GET['file'] ?? '');
    if (!preg_match('/^database_backup_[0-9_\-]+\.sql$/', $fileName)) {
        http_response_code(400);
        exit('Invalid backup file requested.');
    }

    $backupDir = get_backup_dir();
    $filePath = $backupDir . '/' . $fileName;

    if (!file_exists($filePath) || strpos(realpath($filePath), realpath($backupDir)) !== 0) {
        http_response_code(404);
        exit('Backup file not found.');
    }

    // Clean any active output buffers to avoid corrupting HTTP binary stream
    while (ob_get_level()) {
        @ob_end_clean();
    }

    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;
}

// Handle Delete of an Archived Backup File
if (isset($_GET['action']) && $_GET['action'] === 'delete_saved_backup') {
    $token = $_GET['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        exit('Invalid security token.');
    }
    $currUser = current_user();
    $userRole = $currUser['role'] ?? '';
    $isSuperAdmin = in_array($userRole, ['Super Admin', 'Administrator'], true);

    if (!$isSuperAdmin && !has_permission('settings.manage')) {
        http_response_code(403);
        exit('Access denied.');
    }

    $fileName = basename($_GET['file'] ?? '');
    if (!preg_match('/^database_backup_[0-9_\-]+\.sql$/', $fileName)) {
        http_response_code(400);
        exit('Invalid backup file request.');
    }

    $backupDir = get_backup_dir();
    $filePath = $backupDir . '/' . $fileName;

    if (file_exists($filePath) && strpos(realpath($filePath), realpath($backupDir)) === 0) {
        @unlink($filePath);
    }

    header('Location: settings.php?tab=backups&success=backup_deleted#backupSection');
    exit;
}

// Handle Test Backup Email Send
if (isset($_GET['action']) && $_GET['action'] === 'test_backup_email') {
    $token = $_GET['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        exit('Invalid security token.');
    }
    $currUser = current_user();
    $userRole = $currUser['role'] ?? '';
    $isSuperAdmin = in_array($userRole, ['Super Admin', 'Administrator'], true);

    if (!$isSuperAdmin && !has_permission('settings.manage')) {
        http_response_code(403);
        exit('Access denied.');
    }

    $emailAddress = get_setting('backup_email_address', '');
    if (empty($emailAddress) || !filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
        header('Location: settings.php?tab=backups&error=' . urlencode('براہ کرم پہلے نیچے دیے گئے باکس میں درست ای میل درج کر کے محفوظ کریں۔') . '#emailBackupSection');
        exit;
    }

    $backupDir = get_backup_dir();
    $latestFiles = glob($backupDir . '/database_backup_*.sql');
    $backupFile = null;
    if (!empty($latestFiles)) {
        rsort($latestFiles);
        $backupFile = $latestFiles[0];
    }
    if (!$backupFile || !file_exists($backupFile)) {
        $tempPath = $backupDir . '/database_backup_' . date('Y-m-d_His') . '.sql';
        if (generate_sql_database_backup($pdo, $tempPath)) {
            $backupFile = $tempPath;
        }
    }

    if ($backupFile && file_exists($backupFile)) {
        $sent = send_backup_email($backupFile, $emailAddress);
        if ($sent) {
            header('Location: settings.php?tab=backups&success=email_backup_sent&target_email=' . urlencode($emailAddress) . '#emailBackupSection');
            exit;
        } else {
            header('Location: settings.php?tab=backups&error=' . urlencode('ای میل بھیجنے میں ناکامی ہوئی! براہ کرم سرور پر PHP Mail سروس یا ای میل ایڈریس چیک کریں۔') . '#emailBackupSection');
            exit;
        }
    } else {
        header('Location: settings.php?tab=backups&error=' . urlencode('بیک اپ فائل تیار نہیں ہو سکی۔') . '#emailBackupSection');
        exit;
    }
}

// Handle Database Optimization & Analysis
if (isset($_GET['action']) && $_GET['action'] === 'optimize_database') {
    $token = $_GET['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        exit('Invalid security token.');
    }
    $currUser = current_user();
    $userRole = $currUser['role'] ?? '';
    $isSuperAdmin = in_array($userRole, ['Super Admin', 'Administrator'], true);

    if (!$isSuperAdmin && !has_permission('settings.manage')) {
        http_response_code(403);
        exit('Access denied.');
    }

    try {
        $res = optimize_database_tables($pdo);
        $count = count($res);
        header('Location: settings.php?tab=backups&success=db_optimized&count=' . $count . '#dbOptimizeSection');
        exit;
    } catch (Throwable $e) {
        header('Location: settings.php?tab=backups&error=' . urlencode('ڈیٹا بیس آپٹیمائزیشن میں خرابی: ' . $e->getMessage()) . '#dbOptimizeSection');
        exit;
    }
}

// Handle Save Email Backup Preferences
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_email_backup_settings') {
    verify_csrf();
    $currUser = current_user();
    $userRole = $currUser['role'] ?? '';
    $isSuperAdmin = in_array($userRole, ['Super Admin', 'Administrator'], true);

    if (!$isSuperAdmin && !has_permission('settings.manage')) {
        http_response_code(403);
        exit('Access denied.');
    }

    $backupEmailEnabled = isset($_POST['backup_email_enabled']) ? '1' : '0';
    $backupEmailAddress = trim((string)($_POST['backup_email_address'] ?? ''));

    if ($backupEmailEnabled === '1' && (empty($backupEmailAddress) || !filter_var($backupEmailAddress, FILTER_VALIDATE_EMAIL))) {
        header('Location: settings.php?tab=backups&error=' . urlencode('براہ کرم ایک درست ای میل ایڈریس درج کریں۔ (Please enter a valid recipient email address).') . '#emailBackupSection');
        exit;
    } else {
        set_setting('backup_email_enabled', $backupEmailEnabled);
        set_setting('backup_email_address', $backupEmailAddress);
        header('Location: settings.php?tab=backups&success=email_settings_saved#emailBackupSection');
        exit;
    }
}

// Handle Restore from an Archived Backup File
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'restore_saved_backup') {
    verify_csrf();
    $currUser = current_user();
    $userRole = $currUser['role'] ?? '';
    $isSuperAdmin = in_array($userRole, ['Super Admin', 'Administrator'], true);

    if (!$isSuperAdmin && !has_permission('settings.manage')) {
        $error = 'Access denied. Only Super Admin can restore database backups.';
    } else {
        $adminPassword = $_POST['admin_password'] ?? '';
        $adminUserId = (int)($currUser['id'] ?? 0);
        $realHash = $pdo->query("SELECT password_hash FROM users WHERE id = {$adminUserId}")->fetchColumn();

        if (!$realHash || !password_verify($adminPassword, $realHash)) {
            $error = 'غلط پاس ورڈ! بیک اپ بحال کرنے کے لیے ایڈمن کا درست پاس ورڈ درج کرنا لازمی ہے۔ (Incorrect Admin Password).';
        } else {
            $fileName = basename($_POST['backup_filename'] ?? '');
            if (!preg_match('/^database_backup_[0-9_\-]+\.sql$/', $fileName)) {
                $error = 'نامعلوم یا غیر معتبر بیک اپ فائل۔ (Invalid backup file name).';
            } else {
                $backupDir = get_backup_dir();
                $filePath = $backupDir . '/' . $fileName;

                if (!file_exists($filePath) || strpos(realpath($filePath), realpath($backupDir)) !== 0) {
                    $error = 'مطلوبہ بیک اپ فائل سرور پر موجود نہیں ہے۔ (Backup file not found).';
                } else {
                    $sql = file_get_contents($filePath);
                    if (empty($sql) || strlen(trim($sql)) < 30) {
                        $error = 'منتخب کردہ فائل خالی ہے یا درست SQL فارمیٹ میں نہیں ہے۔';
                    } else {
                        try {
                            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
                            $pdo->exec("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';");
                            $pdo->exec($sql);
                            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

                            try {
                                $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, entity_type, details, ip_address) VALUES (?, ?, ?, ?, ?)');
                                $log->execute([$adminUserId, 'restore_saved_backup', 'system', json_encode(['file' => $fileName, 'restored_at' => date('Y-m-d H:i:s')]), $_SERVER['REMOTE_ADDR'] ?? null]);
                            } catch (Exception $ex) {}

                            header('Location: settings.php?tab=backups&success=backup_restored#backupSection');
                            exit;
                        } catch (Exception $e) {
                            try { $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;"); } catch (Exception $ex) {}
                            $error = 'بیک اپ بحال کرنے میں خرابی: ' . $e->getMessage();
                        }
                    }
                }
            }
        }
    }
}

// Handle Restore Database Backup (Upload .sql file)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'restore_db_backup') {
    verify_csrf();
    $currUser = current_user();
    $userRole = $currUser['role'] ?? '';
    $isSuperAdmin = in_array($userRole, ['Super Admin', 'Administrator'], true);

    if (!$isSuperAdmin && !has_permission('settings.manage')) {
        $error = 'Access denied. Only Super Admin can restore database backups.';
    } else {
        $adminPassword = $_POST['admin_password'] ?? '';
        $adminUserId = (int)($currUser['id'] ?? 0);
        $realHash = $pdo->query("SELECT password_hash FROM users WHERE id = {$adminUserId}")->fetchColumn();

        if (!$realHash || !password_verify($adminPassword, $realHash)) {
            $error = 'غلط پاس ورڈ! بیک اپ بحال کرنے کے لیے ایڈمن کا درست پاس ورڈ درج کرنا لازمی ہے۔ (Incorrect Admin Password).';
        } elseif (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
            $error = 'براہ کرم درست .sql بیک اپ فائل منتخب کریں۔ (Please select a valid SQL backup file).';
        } else {
            $fileName = $_FILES['backup_file']['name'];
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            if ($ext !== 'sql') {
                $error = 'صرف .sql فارمیٹ کی فائل اپلوڈ کی جا سکتی ہے۔ (Only .sql files are allowed).';
            } else {
                $fileTmp = $_FILES['backup_file']['tmp_name'];
                $sql = file_get_contents($fileTmp);
                if (empty($sql) || strlen(trim($sql)) < 30) {
                    $error = 'منتخب کردہ فائل خالی ہے یا درست SQL فارمیٹ میں نہیں ہے۔';
                } else {
                    try {
                        // Temporarily disable foreign keys and execute SQL
                        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
                        $pdo->exec("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';");
                        $pdo->exec($sql);
                        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

                        // Log audit trail
                        try {
                            $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, entity_type, details, ip_address) VALUES (?, ?, ?, ?, ?)');
                            $log->execute([$adminUserId, 'restore_database_backup', 'system', json_encode(['file' => $fileName, 'restored_at' => date('Y-m-d H:i:s')]), $_SERVER['REMOTE_ADDR'] ?? null]);
                        } catch (Exception $ex) {}

                        header('Location: settings.php?tab=backups&success=backup_restored#backupSection');
                        exit;
                    } catch (Exception $e) {
                        try { $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;"); } catch (Exception $ex) {}
                        $error = 'بیک اپ بحال کرنے میں خرابی: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

// Handle Settings Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    verify_csrf();
    $returnTab = in_array($_POST['return_tab'] ?? '', ['org', 'donations', 'accounting', 'print'], true) ? $_POST['return_tab'] : 'org';

    try {
        $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');

        // Save Organization Details (when submitted from Organization Tab)
        if (isset($_POST['madrasa_name'])) {
            $madrasaName = trim($_POST['madrasa_name']);
            if (empty($madrasaName)) {
                throw new RuntimeException('Organization / Institution Name cannot be empty.');
            }
            $stmt->execute(['madrasa_name', $madrasaName]);

            if (isset($_POST['org_subtitle'])) {
                $stmt->execute(['org_subtitle', trim($_POST['org_subtitle'])]);
            }
            if (isset($_POST['madrasa_phone'])) {
                $stmt->execute(['madrasa_phone', trim($_POST['madrasa_phone'])]);
            }
            if (isset($_POST['madrasa_address'])) {
                $stmt->execute(['madrasa_address', trim($_POST['madrasa_address'])]);
            }

            // Handle Logo Removal
            if (!empty($_POST['remove_logo'])) {
                $currLogo = get_setting('org_logo', '');
                if ($currLogo && is_file(__DIR__ . '/' . $currLogo)) {
                    @unlink(__DIR__ . '/' . $currLogo);
                }
                $stmt->execute(['org_logo', '']);
            }

            // Handle New Logo Upload
            if (isset($_FILES['org_logo']) && $_FILES['org_logo']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['org_logo'];
                $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

                if (!in_array($ext, $allowedExtensions, true)) {
                    throw new RuntimeException('ناقص تصویر! لوگو کے لیے صرف PNG, JPG, JPEG یا WebP فائلز اپ لوڈ کریں۔');
                }

                if ($file['size'] > 4 * 1024 * 1024) {
                    throw new RuntimeException('فائل کا سائز زیادہ ہے! لوگو زیادہ سے زیادہ 4MB کا ہو سکتا ہے۔');
                }

                $uploadDir = __DIR__ . '/uploads/logo/';
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0755, true);
                }

                $currLogo = get_setting('org_logo', '');
                if ($currLogo && is_file(__DIR__ . '/' . $currLogo)) {
                    @unlink(__DIR__ . '/' . $currLogo);
                }

                $newFileName = 'logo_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $destination = $uploadDir . $newFileName;

                if (!move_uploaded_file($file['tmp_name'], $destination)) {
                    throw new RuntimeException('لوگو اپ لوڈ نہیں ہو سکا۔ فولڈر پرمیشن چیک کریں۔');
                }

                $stmt->execute(['org_logo', 'uploads/logo/' . $newFileName]);
            }
        }

        // Save Accounting Preferences (when submitted from Accounting Tab)
        if (isset($_POST['currency'])) {
            $currency = trim($_POST['currency']);
            $timezone = trim($_POST['timezone'] ?? 'Asia/Karachi');
            $finYearStart = trim($_POST['financial_year_start'] ?? '07-01');
            $uiLanguage = in_array($_POST['ui_language'] ?? '', ['english', 'bilingual'], true) ? $_POST['ui_language'] : 'bilingual';
            $orgType = in_array($_POST['org_type'] ?? '', ['trust', 'madrasa'], true) ? $_POST['org_type'] : 'trust';

            $stmt->execute(['currency', $currency]);
            $stmt->execute(['timezone', $timezone]);
            $stmt->execute(['financial_year_start', $finYearStart]);
            $stmt->execute(['ui_language', $uiLanguage]);
            $stmt->execute(['org_type', $orgType]);
        }

        // Save Print & Slip Settings (when submitted from Print Tab)
        if (isset($_POST['print_default_format'])) {
            $defaultFormat = in_array($_POST['print_default_format'], ['thermal', 'a4'], true) ? $_POST['print_default_format'] : 'a4';
            $thermalCopies = in_array($_POST['print_thermal_copies'] ?? '', ['1', '2'], true) ? $_POST['print_thermal_copies'] : '2';
            $showLogo = isset($_POST['print_show_logo']) ? '1' : '0';
            $showAddress = isset($_POST['print_show_address']) ? '1' : '0';
            $preprintedLetterhead = isset($_POST['print_preprinted_letterhead']) ? '1' : '0';
            $signTitle1 = trim($_POST['print_sign_title_1'] ?? '');
            $signTitle2 = trim($_POST['print_sign_title_2'] ?? '');
            $signTitle3 = trim($_POST['print_sign_title_3'] ?? '');
            $receiptDua = trim($_POST['print_receipt_dua'] ?? '');
            $footerNote = trim($_POST['print_footer_note'] ?? '');

            $stmt->execute(['print_default_format', $defaultFormat]);
            $stmt->execute(['print_thermal_copies', $thermalCopies]);
            $stmt->execute(['print_show_logo', $showLogo]);
            $stmt->execute(['print_show_address', $showAddress]);
            $stmt->execute(['print_preprinted_letterhead', $preprintedLetterhead]);
            $stmt->execute(['print_sign_title_1', $signTitle1]);
            $stmt->execute(['print_sign_title_2', $signTitle2]);
            $stmt->execute(['print_sign_title_3', $signTitle3]);
            $stmt->execute(['print_receipt_dua', $receiptDua]);
            $stmt->execute(['print_footer_note', $footerNote]);
        }

        // Save Online Donation & Bank Settings (when submitted from Donations Tab)
        if (isset($_POST['donation_bank_title'])) {
            $stmt->execute(['donation_bank_title', trim($_POST['donation_bank_title'] ?? '')]);
            $stmt->execute(['donation_bank_name', trim($_POST['donation_bank_name'] ?? '')]);
            $stmt->execute(['donation_bank_account', trim($_POST['donation_bank_account'] ?? '')]);
            $stmt->execute(['donation_bank_iban', trim($_POST['donation_bank_iban'] ?? '')]);
            $stmt->execute(['donation_easypaisa', trim($_POST['donation_easypaisa'] ?? '')]);
            $stmt->execute(['donation_appeal_title', trim($_POST['donation_appeal_title'] ?? '')]);
            $stmt->execute(['donation_appeal_text', trim($_POST['donation_appeal_text'] ?? '')]);
            $stmt->execute(['donation_whatsapp', trim($_POST['donation_whatsapp'] ?? '')]);
        }

        header('Location: settings.php?tab=' . urlencode($returnTab) . '&success=saved');
        exit;
    } catch (Exception $e) {
        $error = 'Failed to save settings: ' . $e->getMessage();
    }
}

// Handle Admin Change Own Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'admin_change_own_password') {
    verify_csrf();
    $currentPass = (string)($_POST['current_password'] ?? '');
    $newPass = (string)($_POST['new_password'] ?? '');
    $confirmPass = (string)($_POST['confirm_password'] ?? '');
    $adminUserId = (int)current_user()['id'];

    if (empty($currentPass) || empty($newPass) || empty($confirmPass)) {
        $error = 'براہ کرم پاس ورڈ کے تمام خانے پُر کریں۔ (Please fill all password fields).';
    } elseif (strlen($newPass) < 8) {
        $error = 'نیا پاس ورڈ کم از کم 8 حروف کا ہونا چاہیے۔ (New password must be at least 8 characters).';
    } elseif ($newPass !== $confirmPass) {
        $error = 'نیا پاس ورڈ اور تصدیقی پاس ورڈ ایک جیسے نہیں ہیں۔ (New password and confirmation do not match).';
    } else {
        try {
            $stmtU = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmtU->execute([$adminUserId]);
            $currentHash = $stmtU->fetchColumn();

            if (!$currentHash || !password_verify($currentPass, $currentHash)) {
                $error = '❌ موجودہ پاس ورڈ درست نہیں ہے۔ (Current password is incorrect).';
            } else {
                $newHash = password_hash($newPass, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$newHash, $adminUserId]);
                $stmtLog = $pdo->prepare("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address) VALUES (?, ?, ?, ?, ?)");
                $stmtLog->execute([$adminUserId, 'admin_change_own_password', 'user', $adminUserId, $_SERVER['REMOTE_ADDR'] ?? null]);
                header('Location: settings.php?tab=security&success=own_password_changed#passwordSection');
                exit;
            }
        } catch (Exception $e) {
            $error = 'پاس ورڈ تبدیل نہیں ہو سکا: ' . $e->getMessage();
        }
    }
}

// Handle Admin Reset Any User's Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'admin_reset_user_password') {
    verify_csrf();
    $targetUserId = (int)($_POST['target_user_id'] ?? 0);
    $resetPass = (string)($_POST['reset_password'] ?? '');
    $adminUserId = (int)current_user()['id'];

    if ($targetUserId <= 0) {
        $error = 'براہ کرم وہ یوزر منتخب کریں جس کا پاس ورڈ ری سیٹ کرنا ہے۔ (Please select a user to reset).';
    } elseif (strlen($resetPass) < 8) {
        $error = 'نیا پاس ورڈ کم از کم 8 حروف کا ہونا چاہیے۔ (Password must be at least 8 characters).';
    } else {
        try {
            $stmtTarget = $pdo->prepare("SELECT id, full_name, username FROM users WHERE id = ?");
            $stmtTarget->execute([$targetUserId]);
            $targetUser = $stmtTarget->fetch();

            if (!$targetUser) {
                $error = 'منتخب کردہ یوزر نہیں ملا۔ (User not found).';
            } else {
                $newHash = password_hash($resetPass, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$newHash, $targetUserId]);
                $stmtLog = $pdo->prepare("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address) VALUES (?, ?, ?, ?, ?)");
                $stmtLog->execute([$adminUserId, 'admin_reset_user_password', 'user', $targetUserId, $_SERVER['REMOTE_ADDR'] ?? null]);
                header('Location: settings.php?tab=security&success=user_password_reset&target_name=' . urlencode($targetUser['full_name']) . '#passwordSection');
                exit;
            }
        } catch (Exception $e) {
            $error = 'پاس ورڈ ری سیٹ نہیں ہو سکا: ' . $e->getMessage();
        }
    }
}

// Handle Danger Zone: Clear Transactions & Apply Mode-Aware Defaults (Live Password Protected)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['clear_transactions', 'danger_reset_defaults'], true)) {
    verify_csrf();
    $adminPassword = $_POST['admin_password'] ?? '';
    $confirmReset = isset($_POST['confirm_reset']) || isset($_POST['confirm_danger']);
    $adminUserId = (int)current_user()['id'];

    $targetMode = in_array($_POST['target_mode'] ?? '', ['madrasa', 'trust'], true) ? $_POST['target_mode'] : (is_madrasa_mode() ? 'madrasa' : 'trust');
    $doResetTx = isset($_POST['reset_transactions']) || ($_POST['action'] === 'clear_transactions' && $confirmReset);
    $doResetIdentity = isset($_POST['reset_identity']);
    $doResetFunds = isset($_POST['reset_funds_accounts']);
    $doResetSignatures = isset($_POST['reset_print_signatures']);
    $doResetAppeal = isset($_POST['reset_donation_appeal']);
    $doResetDonors = isset($_POST['reset_donors_students']);

    if (empty($adminPassword)) {
        $error = 'براہ کرم اپنا لائیو ایڈمن پاس ورڈ درج کریں۔ (Please enter your admin password).';
    } elseif (!$confirmReset) {
        $error = 'براہ کرم تصدیقی چیک باکس پر نشان لگائیں۔ (Please confirm by checking the verification box).';
    } elseif (!$doResetTx && !$doResetIdentity && !$doResetFunds && !$doResetSignatures && !$doResetAppeal && !$doResetDonors) {
        $error = 'براہ کرم کم از کم ایک آپشن منتخب کریں جسے ری سیٹ یا ڈیفالٹ کرنا ہو۔ (Please select at least one reset option).';
    } else {
        try {
            // Check live password of the logged in user
            $stmtU = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmtU->execute([$adminUserId]);
            $currentHash = $stmtU->fetchColumn();

            if (!$currentHash || !password_verify($adminPassword, $currentHash)) {
                $error = '❌ غلط پاس ورڈ! تصدیق ناکام ہو گئی۔ کوئی تبدیلی نہیں کی گئی اور آپ کا ڈیٹا محفوظ ہے۔ (Incorrect password. Authentication failed).';
            } else {
                $pdo->beginTransaction();
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

                $appliedActions = [];
                $tableSalary = false;

                // 1. Wipe Financial Transactions if selected
                if ($doResetTx) {
                    $tableSalary = $pdo->query("SHOW TABLES LIKE 'salary_payments'")->fetch();
                    if ($tableSalary) {
                        $pdo->exec("DELETE FROM salary_payments");
                    }

                    try {
                        $receiptFiles = $pdo->query("SELECT stored_name FROM attachments")->fetchAll(PDO::FETCH_COLUMN) ?: [];
                        foreach ($receiptFiles as $fName) {
                            $fPath = __DIR__ . '/uploads/receipts/' . $fName;
                            if (is_file($fPath)) {
                                @unlink($fPath);
                            }
                        }
                    } catch (Exception $ex) {}

                    $pdo->exec("DELETE FROM attachments");
                    $pdo->exec("DELETE FROM journal_lines");
                    $pdo->exec("DELETE FROM journal_entries");
                    $pdo->exec("DELETE FROM vouchers");
                    $appliedActions[] = 'transactions_cleared';
                }

                // 2. Reset Organization Name & Subtitle to Target Mode
                if ($doResetIdentity) {
                    $stmtSet = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                    $defaultName = ($targetMode === 'madrasa') ? 'جامعہ دارالعلوم / مدرسہ اسلامیہ' : 'الحبیب ویلفیئر ٹرسٹ';
                    $customName = trim($_POST['custom_org_name'] ?? '');
                    $finalOrgName = $customName !== '' ? $customName : $defaultName;
                    $finalOrgSubtitle = ($targetMode === 'madrasa') ? 'تعلیم، تربیت اور دینی خدمات' : 'فلاح و بہبود اور انسانی خدمت';

                    $stmtSet->execute(['org_type', $targetMode, $targetMode]);
                    $stmtSet->execute(['madrasa_name', $finalOrgName, $finalOrgName]);
                    $stmtSet->execute(['org_subtitle', $finalOrgSubtitle, $finalOrgSubtitle]);
                    $appliedActions[] = 'identity_reset';
                }

                // 3. Reset Funds & Default Accounts
                if ($doResetFunds) {
                    $stmtSet = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                    if ($targetMode === 'madrasa') {
                        $pdo->exec("UPDATE funds SET is_active = 1 WHERE fund_code = 'MGF' OR scope = 'madrasa'");
                        $defFundId = $pdo->query("SELECT id FROM funds WHERE fund_code = 'MGF' LIMIT 1")->fetchColumn();
                    } else {
                        $pdo->exec("UPDATE funds SET is_active = 1 WHERE fund_code = 'TGF' OR scope = 'trust'");
                        $defFundId = $pdo->query("SELECT id FROM funds WHERE fund_code = 'TGF' LIMIT 1")->fetchColumn();
                    }
                    if ($defFundId) {
                        $stmtSet->execute(['default_donation_fund_id', (string)$defFundId, (string)$defFundId]);
                    }
                    $appliedActions[] = 'funds_activated';
                }

                // 4. Reset Print & Receipt Signatures
                if ($doResetSignatures) {
                    $stmtSet = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                    if ($targetMode === 'madrasa') {
                        $s1 = 'تیار کنندہ / کیشیئر (Prepared By)';
                        $s2 = 'اکاؤنٹنٹ / ناظمِ مالیات (Accountant)';
                        $s3 = 'مہتمم / صدر / مجاز دستخط (Director / Mohtamim)';
                    } else {
                        $s1 = 'Prepared By (تیار کنندہ)';
                        $s2 = 'Accountant / Cashier (اکاؤنٹنٹ)';
                        $s3 = 'Director / Chairman (دستخط مجاز اتھارٹی)';
                    }
                    $stmtSet->execute(['print_sign_title_1', $s1, $s1]);
                    $stmtSet->execute(['print_sign_title_2', $s2, $s2]);
                    $stmtSet->execute(['print_sign_title_3', $s3, $s3]);
                    $appliedActions[] = 'signatures_reset';
                }

                // 5. Reset Online Donation Appeal
                if ($doResetAppeal) {
                    $stmtSet = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                    if ($targetMode === 'madrasa') {
                        $appTitle = 'دینی و تعلیمی خدمات میں ہمارا ساتھ دیں';
                        $appText = 'آپ کا دیا ہوا صدقہ، زکوٰۃ اور عطیات یتامیٰ و مستحق طلباء کی مفت رہائش، خوراک، کتب و تعلیم پر دیانت داری سے خرچ کیے جاتے ہیں۔';
                    } else {
                        $appTitle = 'فلاحی و انسانی خدمات میں ہمارا ساتھ دیں';
                        $appText = 'آپ کا دیا ہوا صدقہ، زکوٰۃ اور عطیات راشن تقسیم، مفت علاج، واٹر پروجیکٹس اور بیواؤں و یتیموں کی کفالت پر دیانت داری سے خرچ کیے جاتے ہیں۔';
                    }
                    $stmtSet->execute(['donation_appeal_title', $appTitle, $appTitle]);
                    $stmtSet->execute(['donation_appeal_text', $appText, $appText]);
                    $appliedActions[] = 'appeal_reset';
                }

                // 6. Clear Test Donors & Beneficiaries
                if ($doResetDonors) {
                    $pdo->exec("UPDATE vouchers SET donor_id = NULL");
                    $pdo->exec("DELETE FROM donors");
                    $pdo->exec("DELETE FROM beneficiaries");
                    $appliedActions[] = 'donors_students_cleared';
                }

                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

                // Audit Log
                $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, entity_type, details, ip_address) VALUES (?, ?, ?, ?, ?)');
                $log->execute([
                    $adminUserId, 
                    'danger_defaults_applied', 
                    'system', 
                    json_encode(['target_mode' => $targetMode, 'actions' => $appliedActions, 'time' => date('Y-m-d H:i:s')]), 
                    $_SERVER['REMOTE_ADDR'] ?? null
                ]);

                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }

                // Reset auto-increment counters AFTER commit
                try {
                    if (in_array('transactions_cleared', $appliedActions, true)) {
                        $pdo->exec("ALTER TABLE journal_lines AUTO_INCREMENT = 1");
                        $pdo->exec("ALTER TABLE journal_entries AUTO_INCREMENT = 1");
                        $pdo->exec("ALTER TABLE vouchers AUTO_INCREMENT = 1");
                        $pdo->exec("ALTER TABLE attachments AUTO_INCREMENT = 1");
                        if ($tableSalary) {
                            $pdo->exec("ALTER TABLE salary_payments AUTO_INCREMENT = 1");
                        }
                    }
                    if (in_array('donors_students_cleared', $appliedActions, true)) {
                        $pdo->exec("ALTER TABLE donors AUTO_INCREMENT = 1");
                        $pdo->exec("ALTER TABLE beneficiaries AUTO_INCREMENT = 1");
                    }
                } catch (Exception $ex) {}

                $successCode = in_array('transactions_cleared', $appliedActions, true) ? 'transactions_cleared' : 'danger_defaults_applied';
                header('Location: settings.php?tab=danger&success=' . urlencode($successCode));
                exit;
            }
        } catch (Exception $e) {
            try {
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            } catch (Exception $ex) {}
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'ڈیفالٹس یا ری سیٹ ایکشن لاگو نہیں ہو سکے: ' . $e->getMessage();
        }
    }
}

// Fetch current transaction and entity counts
$vouchersCount = 0;
$journalsCount = 0;
$donorsCount = 0;
$beneficiariesCount = 0;
try {
    $vouchersCount = (int)$pdo->query("SELECT COUNT(*) FROM vouchers")->fetchColumn();
    $journalsCount = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
    $donorsCount = (int)$pdo->query("SELECT COUNT(*) FROM donors")->fetchColumn();
    $beneficiariesCount = (int)$pdo->query("SELECT COUNT(*) FROM beneficiaries")->fetchColumn();
} catch (Exception $e) {}

// Fetch current settings
$settingsRows = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
$madrasaName = $settingsRows['madrasa_name'] ?? 'Trust Accounts';
$madrasaPhone = $settingsRows['madrasa_phone'] ?? '';
$madrasaAddress = $settingsRows['madrasa_address'] ?? '';
$currency = $settingsRows['currency'] ?? 'PKR';
$timezone = $settingsRows['timezone'] ?? 'Asia/Karachi';
$finYearStart = $settingsRows['financial_year_start'] ?? '07-01';
$uiLanguage = $settingsRows['ui_language'] ?? 'bilingual';
$orgType = $settingsRows['org_type'] ?? 'trust';
$orgSubtitle = $settingsRows['org_subtitle'] ?? '';
$orgLogo = function_exists('get_org_logo_url') ? get_org_logo_url() : null;
$shortBrand = mb_strtoupper(mb_substr($madrasaName, 0, 2));

// Print & Slip Settings
$printDefaultFormat = $settingsRows['print_default_format'] ?? 'a4';
$printThermalCopies = $settingsRows['print_thermal_copies'] ?? '2';
$printShowLogo = $settingsRows['print_show_logo'] ?? '1';
$printShowAddress = $settingsRows['print_show_address'] ?? '1';
$printPreprintedLetterhead = $settingsRows['print_preprinted_letterhead'] ?? '0';
$printSignTitle1 = $settingsRows['print_sign_title_1'] ?? (is_madrasa_mode() ? 'تیار کنندہ / کیشیئر (Prepared By)' : 'Prepared By (تیار کنندہ)');
$printSignTitle2 = $settingsRows['print_sign_title_2'] ?? (is_madrasa_mode() ? 'اکاؤنٹنٹ / ناظمِ مالیات (Accountant)' : 'Accountant / Cashier (اکاؤنٹنٹ)');
$printSignTitle3 = $settingsRows['print_sign_title_3'] ?? (is_madrasa_mode() ? 'مہتمم / صدر / مجاز دستخط (Director / Mohtamim)' : 'Director / Mohtamim (دستخط مجاز اتھارٹی)');
$printReceiptDua = $settingsRows['print_receipt_dua'] ?? 'اللہ تعالیٰ آپ کے اس صدقہ و عطیہ کو شرف قبولیت بخشے، اور آپ کے جان و مال اور رزق میں بے شمار برکتیں عطا فرمائے۔ آمین۔';
$printFooterNote = $settingsRows['print_footer_note'] ?? 'یہ ایک کمپیوٹرائزڈ آفیشل فنانشل رسید ہے۔ کسی دستی مہر کی ضرورت نہیں۔';

// Online Donation Portal Settings
$donationBankTitle = $settingsRows['donation_bank_title'] ?? $madrasaName;
$donationBankName = $settingsRows['donation_bank_name'] ?? 'Meezan Bank Limited';
$donationBankAccount = $settingsRows['donation_bank_account'] ?? '';
$donationBankIban = $settingsRows['donation_bank_iban'] ?? '';
$donationEasypaisa = $settingsRows['donation_easypaisa'] ?? $madrasaPhone;
$donationAppealTitle = $settingsRows['donation_appeal_title'] ?? 'تعلیمی و فلاحی خدمات میں ہمارا ساتھ دیں';
$donationAppealText = $settingsRows['donation_appeal_text'] ?? 'آپ کا دیا ہوا صدقہ، زکوٰۃ اور عطیات یتامیٰ و مستحق طلباء کی مفت رہائش، خوراک، تعلیم اور غریب گھرانوں کی کفالت پر دیانت داری سے خرچ کیے جاتے ہیں۔';
$donationWhatsapp = $settingsRows['donation_whatsapp'] ?? $madrasaPhone;

// Fetch active users for admin password reset dropdown
$allUsersList = $pdo->query("SELECT u.id, u.full_name, u.username, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_active = 1 ORDER BY u.full_name ASC")->fetchAll();

// Auto-Backup & Archive Info
$cronToken = get_or_create_cron_token();
$savedBackups = get_saved_backups_list();
$lastBackupAt = get_setting('last_auto_backup_at', '');

// Email Backup Settings
$backupEmailEnabled = $settingsRows['backup_email_enabled'] ?? '0';
$backupEmailAddress = $settingsRows['backup_email_address'] ?? '';
$lastBackupEmailAt = $settingsRows['last_backup_email_at'] ?? '';

// Security & Login Activity (Feature #4)
$recentLoginActivities = function_exists('get_recent_login_activities') ? get_recent_login_activities(15) : [];

// Database Optimization Stats (Feature #5)
$dbTablesCount = 0;
try {
    $dbTablesCount = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")->fetchColumn();
} catch (Exception $e) {}

// Construct Dynamic Live Cron URL & Curl Command
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$cronUrl = $protocol . '://' . $host . ($baseDir ? $baseDir : '') . '/cron_backup.php?token=' . $cronToken;
$cronCurlCmd = 'curl -s "' . $cronUrl . '" >/dev/null 2>&1';

$currUser = current_user();
$userRole = $currUser['role'] ?? '';
$isSuperAdmin = in_array($userRole, ['Super Admin', 'Administrator'], true);

// Available Navigation Tabs (Option A: Clean Top Tabs)
$validTabs = ['org', 'donations', 'accounting', 'print', 'security', 'backups'];
if ($isSuperAdmin) {
    $validTabs[] = 'danger';
}
$activeTab = $_GET['tab'] ?? 'org';
if (!in_array($activeTab, $validTabs, true)) {
    $activeTab = 'org';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Settings | <?=e($madrasaName)?></title>
    <link rel="stylesheet" href="assets/style.css?v=<?=css_ver()?>">
</head>
<body>
<div class="app-shell">
    <?php require __DIR__ . '/../app/sidebar.php'; ?>

    <main class="main">
        <header class="topbar">
            <div>
                <span class="eyebrow">PREFERENCES</span>
                <h1>Settings <span class="ur">(سسٹم ترتیبات)</span></h1>
            </div>
        </header>

        <!-- Top Navigation Tabs (Option A) -->
        <div class="tab-nav no-print" style="margin-top:16px;margin-bottom:20px;">
            <a href="settings.php?tab=org" class="tab-link <?=$activeTab === 'org' ? 'active' : ''?>">
                🏛️ Organization &amp; Identity <span class="ur">(ادارہ و ٹرسٹ تفصیلات)</span>
            </a>
            <a href="settings.php?tab=donations" class="tab-link <?=$activeTab === 'donations' ? 'active' : ''?>">
                💳 Online Donations &amp; Bank Accounts <span class="ur">(آن لائن اپیل و بینک اکاؤنٹس)</span>
            </a>
            <a href="settings.php?tab=accounting" class="tab-link <?=$activeTab === 'accounting' ? 'active' : ''?>">
                💼 Financial &amp; Accounting <span class="ur">(مالیاتی ترتیبات)</span>
            </a>
            <a href="settings.php?tab=print" class="tab-link <?=$activeTab === 'print' ? 'active' : ''?>">
                🖨️ Print &amp; Slips <span class="ur">(پرنٹ و رسید ترتیبات)</span>
            </a>
            <a href="settings.php?tab=security" class="tab-link <?=$activeTab === 'security' ? 'active' : ''?>">
                🔒 Security &amp; Passwords <span class="ur">(سیکیورٹی و پاس ورڈز)</span>
            </a>
            <a href="settings.php?tab=backups" class="tab-link <?=$activeTab === 'backups' ? 'active' : ''?>">
                💾 Database &amp; Backups <span class="ur">(ڈیٹا بیس و بیک اپ)</span>
            </a>
            <?php if ($isSuperAdmin): ?>
                <a href="settings.php?tab=danger" class="tab-link <?=$activeTab === 'danger' ? 'active' : ''?>" style="<?=$activeTab === 'danger' ? 'background:#fee2e2;color:#991b1b;border-color:#fca5a5;' : 'color:#dc2626;'?>">
                    ⚠️ Danger Zone <span class="ur">(خطرناک زون)</span>
                </a>
            <?php endif; ?>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert success" style="margin-bottom:18px;">
                <?php if ($_GET['success'] === 'transactions_cleared'): ?>
                    ✓ تمام مالیاتی واؤچرز، رسیدات اور ٹرانزیکشنز کا صفایا کامیابی سے کر دیا گیا ہے۔ تمام کھاتے نئے سرے سے تیار ہیں۔ (All transactions cleared successfully).
                <?php elseif ($_GET['success'] === 'danger_defaults_applied'): ?>
                    ✓ منتخب کردہ موڈ کی ترتیبات، ڈیفالٹ نام اور ری سیٹ ایکشنز کامیابی سے لاگو ہو چکے ہیں! سسٹم نئے موڈ کے ساتھ مکمل طور پر تیار ہے۔ (Mode defaults and resets applied successfully).
                <?php elseif ($_GET['success'] === 'backup_restored'): ?>
                    ✓ ڈیٹا بیس بیک اپ کامیابی کے ساتھ بحال کر دیا گیا ہے! تمام کھاتے، واؤچرز، اور ریکارڈز کامیابی سے اپ ڈیٹ ہو چکے ہیں۔ (Database backup restored successfully).
                <?php elseif ($_GET['success'] === 'backup_created'): ?>
                    ✓ نیا ڈیٹا بیس بیک اپ کامیابی سے تیار کر کے آرکائیو میں محفوظ کر لیا گیا ہے! (New database backup created &amp; archived successfully).
                <?php elseif ($_GET['success'] === 'backup_deleted'): ?>
                    ✓ منتخب کردہ بیک اپ فائل کامیابی سے ڈیلیٹ کر دی گئی ہے۔ (Backup file deleted successfully).
                <?php elseif ($_GET['success'] === 'own_password_changed'): ?>
                    ✓ آپ کا پاس ورڈ کامیابی سے تبدیل ہو گیا ہے۔ آئندہ لاگ ان کے لیے نیا پاس ورڈ استعمال کریں۔ (Your password has been changed successfully!)
                <?php elseif ($_GET['success'] === 'user_password_reset'): ?>
                    ✓ صارف <b><?=e($_GET['target_name'] ?? 'User')?></b> کا پاس ورڈ کامیابی سے ری سیٹ کر دیا گیا ہے! (Password successfully reset!)
                <?php elseif ($_GET['success'] === 'email_backup_sent'): ?>
                    ✓ ٹیسٹ بیک اپ ای میل کامیابی کے ساتھ <b><?=e($_GET['target_email'] ?? '')?></b> پر بھیج دی گئی ہے! برائے مہربانی اپنا ان باکس اور اسپام/جنک فولڈر چیک کریں۔ (Test backup email sent successfully!)
                <?php elseif ($_GET['success'] === 'email_settings_saved'): ?>
                    ✓ بیک اپ ای میل کی ترتیبات کامیابی کے ساتھ محفوظ ہو گئی ہیں! (Email backup settings saved successfully!)
                <?php elseif ($_GET['success'] === 'db_optimized'): ?>
                    ✓ ڈیٹا بیس کے تمام <b><?=e($_GET['count'] ?? '0')?></b> ٹیبلز کی کارکردگی اور انڈیکسز کو کامیابی سے 100% بہترین اور تیز (Optimized &amp; Analyzed) کر دیا گیا ہے۔ (Database optimized successfully!)
                <?php else: ?>
                    Settings updated successfully! ترتیبات کامیابی سے محفوظ ہو گئیں۔
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php $displayError = $error ?: ($_GET['error'] ?? ''); ?>
        <?php if ($displayError): ?>
            <div class="alert error" style="margin-bottom:18px;"><?=e($displayError)?></div>
        <?php endif; ?>

        <?php if ($activeTab === 'org'): ?>
        <!-- Tab 1: Organization & Identity Details -->
        <form method="post" enctype="multipart/form-data" style="margin-top:14px;">
            <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
            <input type="hidden" name="action" value="save_settings">
            <input type="hidden" name="return_tab" value="org">

            <div class="content-grid" style="grid-template-columns: 1fr; max-width: 900px;">
                <!-- Organization & Contact Details -->
                <article class="panel">
                    <div class="panel-heading">
                        <div>
                            <h3>Organization / Trust Details <span class="ur">(ادارے / ٹرسٹ کی تفصیلات)</span></h3>
                            <p>Configure institution name, phone number, and official address</p>
                        </div>
                    </div>

                    <div style="padding: 22px 26px;">
                        <!-- Official Logo / Monogram Upload -->
                        <div class="form-group" style="margin-bottom:20px;padding:16px;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:12px;">
                            <label style="font-weight:700;display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                                <span>Official Logo / Monogram <span class="ur">(ادارے کا آفیشل لوگو)</span></span>
                                <span style="font-size:11px;font-weight:normal;color:#64748b;">PNG, JPG, SVG, WebP (Max: 4MB)</span>
                            </label>
                            <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;">
                                <!-- Logo Preview Box -->
                                <div id="logoPreviewContainer" style="width:84px;height:84px;border-radius:12px;border:2px solid #e2e8f0;background:#ffffff;display:flex;align-items:center;justify-content:center;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.06);flex-shrink:0;">
                                    <?php if ($orgLogo): ?>
                                        <img id="logoPreviewImg" src="<?=e($orgLogo)?>?v=<?=filemtime(__DIR__ . '/' . $orgLogo)?>" alt="Logo" style="max-width:100%;max-height:100%;object-fit:contain;">
                                    <?php else: ?>
                                        <div id="logoPreviewPlaceholder" style="font-size:26px;color:#94a3b8;font-weight:700;text-align:center;">
                                            <?=e($shortBrand ?: 'TA')?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div style="flex:1;min-width:220px;">
                                    <input type="file" name="org_logo" id="orgLogoInput" accept="image/png,image/jpeg,image/jpg,image/webp,image/svg+xml" style="font-size:13px;padding:8px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;width:100%;">
                                    <div style="display:flex;align-items:center;gap:14px;margin-top:8px;flex-wrap:wrap;">
                                        <small class="muted" style="font-size:11.5px;"><span class="ur">لوگو منتخب کریں؛ یہ سائیڈ بار، ڈیش بورڈ، رسیدات اور واؤچرز پر نظر آئے گا۔</span><span class="en-only" style="display:none;">Choose a logo; appears on sidebar, dashboard, receipts, and vouchers.</span></small>
                                        <?php if ($orgLogo): ?>
                                            <label style="font-size:11.5px;color:#dc2626;cursor:pointer;display:flex;align-items:center;gap:4px;white-space:nowrap;margin:0;font-weight:600;">
                                                <input type="checkbox" name="remove_logo" value="1" style="width:14px;height:14px;">
                                                <span>🗑 Remove Logo <span class="ur">(لوگو ختم کریں)</span></span>
                                            </label>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom:18px;">
                            <label><?=is_madrasa_mode() ? 'Madrasa / Jamia Name <span class="ur">(مدرسہ / دار العلوم کا نام)</span>' : 'Organization / Trust Name <span class="ur">(ادارے / ٹرسٹ کا نام)</span>'?> *</label>
                            <input name="madrasa_name" value="<?=e($madrasaName)?>" required placeholder="<?=is_madrasa_mode() ? 'e.g. Jamia Dar-ul-Uloom' : 'e.g. Mangalchai Welfare Organization'?>">
                            <small class="muted">This name appears on the dashboard header, vouchers, and reports.</small>
                        </div>

                        <div class="form-group" style="margin-bottom:18px;">
                            <label><?=is_madrasa_mode() ? 'Institution Subtitle / Category <span class="ur">(ادارے کی نوعیت یا ذیلی عنوان)</span>' : 'Organization Subtitle / Category <span class="ur">(ادارے کی نوعیت یا ذیلی عنوان)</span>'?></label>
                            <input name="org_subtitle" value="<?=e($orgSubtitle)?>" placeholder="<?=is_madrasa_mode() ? 'Default: Islamic Seminary (دینی درسگاہ و تعلیمی ادارہ)' : 'Default: Welfare Organization (فلاحی و رفاہی تنظیم)'?>">
                            <small class="muted">Appears as the badge under your institution name on the dashboard header. Leave blank to use the default.</small>
                        </div>

                        <div class="form-group" style="margin-bottom:18px;">
                            <label>Phone / WhatsApp Number <span class="ur">(رابطہ نمبر / فون)</span></label>
                            <input name="madrasa_phone" value="<?=e($madrasaPhone)?>" placeholder="e.g. 0300-1234567 / 0312-3456789">
                            <small class="muted">Displayed on topbar header and printable donation vouchers.</small>
                        </div>

                        <div class="form-group" style="margin-bottom:22px;">
                            <label>Official Address / Location <span class="ur">(مکمل پتہ / ایڈریس)</span></label>
                            <textarea name="madrasa_address" rows="3" style="width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:8px;font-family:inherit;font-size:14px;resize:vertical;" placeholder="e.g. Main Office, Swabi, KPK"><?=e($madrasaAddress)?></textarea>
                            <small class="muted">Shown across the dashboard banner and on all printed receipts.</small>
                        </div>

                        <div style="margin-top:20px;padding-top:16px;border-top:1px solid #e2e8f0;">
                            <button class="btn blue" type="submit" style="padding:11px 24px;font-weight:700;">💾 Save Organization Details <span class="ur">(ادارے کی تفصیلات محفوظ کریں)</span></button>
                        </div>
                    </div>
                </article>
            </div>
        </form>

        <?php elseif ($activeTab === 'donations'): ?>
        <!-- Tab: Online Donations & Official Bank Accounts -->
        <form method="post" style="margin-top:14px;">
            <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
            <input type="hidden" name="action" value="save_settings">
            <input type="hidden" name="return_tab" value="donations">

            <div class="content-grid" style="grid-template-columns: 1fr; max-width: 900px;">
                <article class="panel">
                    <div class="panel-heading">
                        <div>
                            <h3>Online Donation Page &amp; Bank Accounts <span class="ur">(آن لائن اپیل و بینک اکاؤنٹس برائے عطیات)</span></h3>
                            <p>Configure official bank details, EasyPaisa/JazzCash, and donation appeal text displayed on the public donation page</p>
                        </div>
                    </div>

                    <div style="padding: 22px 26px;">
                        <!-- Live Public Link Quick Banner -->
                        <div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:12px;padding:16px 20px;margin-bottom:24px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;">
                            <div>
                                <div style="font-weight:800;color:#065f46;font-size:14px;display:flex;align-items:center;gap:6px;">
                                    <span>🌐</span>
                                    <span>Public Donation &amp; Pledge Page URL:</span>
                                </div>
                                <div style="margin-top:4px;">
                                    <code style="font-size:13.5px;color:#047857;background:#fff;padding:3px 8px;border-radius:6px;border:1px solid #6ee7b7;font-family:monospace;"><?=e(get_app_base_url())?>/donate.php</code>
                                </div>
                                <div style="font-size:12px;color:#047857;margin-top:4px;">
                                    اس لنک کو فیس بک، واٹس ایپ اسٹیٹس یا اشتہارات پر شیئر کریں تاکہ ڈونرز باآسانی عطیات اور ماہانہ کفالت آن لائن درج کر سکیں۔
                                </div>
                            </div>
                            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                <a href="<?=e(get_app_base_url())?>/donate.php" target="_blank" class="btn sm" style="background:#059669;color:#fff;border-radius:8px;text-decoration:none;font-weight:700;">
                                    👁️ View Live Page
                                </a>
                                <button type="button" class="btn secondary sm" style="border-radius:8px;" onclick="copyPortalLink()">
                                    📋 Copy Link
                                </button>
                            </div>
                        </div>

                        <!-- 1. Official Bank Account -->
                        <div class="setting-section-title">
                            <span>1. Official Bank Account <span class="ur">(آفیشل بینک اکاؤنٹ برائے رقوم منتقلی)</span></span>
                        </div>

                        <div class="form-grid-2">
                            <div class="form-group" style="margin-bottom:16px;">
                                <label>Account Title <span class="ur">(اکاؤنٹ ٹائٹل)</span> *</label>
                                <input name="donation_bank_title" value="<?=e($donationBankTitle)?>" placeholder="e.g. Mangalchai Welfare Organization" required>
                                <small class="muted">Name of trust / entity on bank record</small>
                            </div>

                            <div class="form-group" style="margin-bottom:16px;">
                                <label>Bank Name <span class="ur">(بینک کا نام)</span> *</label>
                                <input name="donation_bank_name" value="<?=e($donationBankName)?>" placeholder="e.g. Meezan Bank Limited / HBL / Bank Alfalah" required>
                                <small class="muted">Bank where trust holds its official account</small>
                            </div>
                        </div>

                        <div class="form-grid-2">
                            <div class="form-group" style="margin-bottom:16px;">
                                <label>Account Number <span class="ur">(اکاؤنٹ نمبر)</span> *</label>
                                <input name="donation_bank_account" value="<?=e($donationBankAccount)?>" placeholder="e.g. 01020304050607" required style="font-family:monospace;letter-spacing:1px;">
                                <small class="muted">Standard account number (1-click copy enabled for donors)</small>
                            </div>

                            <div class="form-group" style="margin-bottom:16px;">
                                <label>IBAN Number <span class="ur">(آئی بی اے این نمبر - برائے بیرون ملک و آن لائن منتقلی)</span></label>
                                <input name="donation_bank_iban" value="<?=e($donationBankIban)?>" placeholder="e.g. PK00MEZN0001020304050607" style="font-family:monospace;letter-spacing:1px;">
                                <small class="muted">24-character International Bank Account Number</small>
                            </div>
                        </div>

                        <!-- 2. Mobile Accounts / Wallets -->
                        <div class="setting-section-title" style="margin-top:24px;">
                            <span>2. Mobile Accounts &amp; Wallets <span class="ur">(ایزی پیسہ / جاز کیش اکاؤنٹ)</span></span>
                        </div>

                        <div class="form-group" style="margin-bottom:18px;">
                            <label>EasyPaisa / JazzCash Details <span class="ur">(ایزی پیسہ و جاز کیش نمبر و ٹائٹل)</span></label>
                            <input name="donation_easypaisa" value="<?=e($donationEasypaisa)?>" placeholder="e.g. 0331-7240256 (Title: Admin / Welfare Trust)">
                            <small class="muted">Displayed on the public portal for quick mobile transfers.</small>
                        </div>

                        <!-- 3. Appeal Message & WhatsApp Office -->
                        <div class="setting-section-title" style="margin-top:24px;">
                            <span>3. Donation Appeal &amp; WhatsApp Office <span class="ur">(عوامی اپیل و واٹس ایپ تصدیق)</span></span>
                        </div>

                        <div class="form-group" style="margin-bottom:16px;">
                            <label>Appeal Headline <span class="ur">(عوامی اپیل کی سرخی)</span></label>
                            <input name="donation_appeal_title" value="<?=e($donationAppealTitle)?>" placeholder="e.g. تعلیمی و فلاحی خدمات میں ہمارا ساتھ دیں">
                            <small class="muted">Main bold title shown at the top banner of the donation page.</small>
                        </div>

                        <div class="form-group" style="margin-bottom:16px;">
                            <label>Appeal Description / Hadith <span class="ur">(تفصیلی پیغام یا ترغیب)</span></label>
                            <textarea name="donation_appeal_text" rows="3" style="width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:8px;font-family:inherit;font-size:14px;resize:vertical;" placeholder="تفصیلی پیغام تحریر فرمائیں..."><?=e($donationAppealText)?></textarea>
                            <small class="muted">Impact statement shown to donors before registration.</small>
                        </div>

                        <div class="form-group" style="margin-bottom:22px;">
                            <label>Official WhatsApp Number for Receipt Confirmation <span class="ur">(رسیدیں وصول کرنے کا واٹس ایپ نمبر)</span></label>
                            <input name="donation_whatsapp" value="<?=e($donationWhatsapp)?>" placeholder="e.g. +92 331 7240256">
                            <small class="muted">When donor submits their donation/transfer, clicking "Send to WhatsApp" opens a chat with this number.</small>
                        </div>

                        <div style="margin-top:20px;padding-top:16px;border-top:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                            <button class="btn blue" type="submit" style="padding:11px 24px;font-weight:700;">💾 Save Donation &amp; Bank Settings <span class="ur">(ترتیبات محفوظ کریں)</span></button>
                            <a href="<?=e(get_app_base_url())?>/donate.php" target="_blank" style="color:#059669;font-weight:700;font-size:13.5px;text-decoration:none;">
                                🌐 Open Public Page in New Tab &rarr;
                            </a>
                        </div>
                    </div>
                </article>
            </div>
        </form>

        <?php elseif ($activeTab === 'accounting'): ?>
        <!-- Tab 2: Financial & Accounting Preferences -->
        <form method="post" style="margin-top:14px;">
            <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
            <input type="hidden" name="action" value="save_settings">
            <input type="hidden" name="return_tab" value="accounting">

            <div class="content-grid" style="grid-template-columns: 1fr; max-width: 900px;">
                <!-- Accounting & Regional Preferences -->
                <article class="panel">
                    <div class="panel-heading">
                        <div>
                            <h3>Accounting &amp; Regional Preferences <span class="ur">(مالیاتی و علاقائی ترتیبات)</span></h3>
                            <p>Currency, timezone, fiscal year start, and organization operational workflow mode</p>
                        </div>
                    </div>

                    <div style="padding: 22px 26px;">
                        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:16px;margin-bottom:18px;">
                            <div class="form-group">
                                <label>Currency Code <span class="ur">(کرنسی)</span> *</label>
                                <input name="currency" value="<?=e($currency)?>" required placeholder="PKR">
                            </div>

                            <div class="form-group">
                                <label>Timezone <span class="ur">(ٹائم زون)</span> *</label>
                                <input name="timezone" value="<?=e($timezone)?>" required placeholder="Asia/Karachi">
                            </div>

                            <div class="form-group">
                                <label>Financial Year Start Date <span class="ur">(مالی سال کا آغاز MM-DD)</span> *</label>
                                <input name="financial_year_start" value="<?=e($finYearStart)?>" required placeholder="07-01">
                                <small class="muted">Default: 07-01 (1st July for Pakistan fiscal cycle)</small>
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom:20px;padding:16px;background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px;">
                            <label style="font-weight:700;display:block;margin-bottom:6px;color:#166534;">
                                🏢 Organization Workflow Mode <span class="ur">(ادارے کی نوعیت اور سسٹم ورک فلو)</span>
                            </label>
                            <select name="org_type" style="width:100%;font-size:13.5px;padding:9px 12px;border:1.5px solid #16a34a;border-radius:7px;background:#ffffff;font-weight:600;color:#0f172a;">
                                <option value="trust" <?=$orgType === 'trust' ? 'selected' : ''?>>🏢 Trust / Welfare NGO (فلاحی ٹرسٹ / این جی او ورک فلو)</option>
                                <option value="madrasa" <?=$orgType === 'madrasa' ? 'selected' : ''?>>🕌 Madrasa / Islamic Seminary (مدرسہ / دار العلوم و جامعہ ورک فلو)</option>
                            </select>
                            <small class="muted" style="display:block;margin-top:6px;font-size:11.5px;line-height:1.4;color:#15803d;">
                                <span class="ur"><b>Trust Mode:</b> راشن، صاف پانی، یتیم کفالت، ایمرجنسی ریلیف فنڈز، اور ڈونیشن واؤچرز فعال ہوں گے۔<br><b>Madrasa Mode:</b> طلبہ کفالت، تعلیمی فیس، کتب و مکتبہ، مطبخ طعام، اور اساتذہ تنخواہیں فعال ہوں گی۔</span>
                                <span class="en-only" style="display:none;">Switching modes dynamically adjusts categories, terminology, and funds while preserving all transaction records.</span>
                            </small>
                        </div>

                        <div class="form-group" style="margin-bottom:20px;padding:14px;background:#f8fafc;border:1.5px solid #cbd5e1;border-radius:10px;">
                            <label style="font-weight:700;display:block;margin-bottom:6px;">
                                Urdu Text & Language Mode <span class="ur">(اردو ٹیکسٹ اور زبان کا انتخاب)</span>
                            </label>
                            <select name="ui_language" style="width:100%;font-size:13.5px;padding:9px 12px;border:1.5px solid #94a3b8;border-radius:7px;background:#ffffff;font-weight:600;color:#0f172a;">
                                <option value="bilingual" <?=$uiLanguage === 'bilingual' ? 'selected' : ''?>>🌐 Bilingual (English + اردو ترجمہ)</option>
                                <option value="english" <?=$uiLanguage === 'english' ? 'selected' : ''?>>🔤 English Only (صرف انگریزی - بغیر اردو ٹیکسٹ)</option>
                            </select>
                            <small class="muted" style="display:block;margin-top:6px;font-size:11.5px;line-height:1.4;">
                                <span class="ur">اگر آپ سافٹ ویئر کے مینیو، کارڈز اور ہیڈنگز پر اردو ترجمہ دیکھنا چاہتے ہیں تو <b>Bilingual</b> منتخب کریں۔ اگر آپ مکمل سافٹ ویئر صرف انگریزی میں دیکھنا چاہتے ہیں تو <b>English Only</b> منتخب کریں۔</span>
                                <span class="en-only" style="display:none;">Select <b>Bilingual</b> to display Urdu subtitles across menus and cards, or <b>English Only</b> for an English-only interface.</span>
                            </small>
                        </div>

                        <div style="margin-top:20px;padding-top:16px;border-top:1px solid #e2e8f0;">
                            <button class="btn blue" type="submit" style="padding:11px 24px;font-weight:700;">💾 Save Accounting Preferences <span class="ur">(مالیاتی ترتیبات محفوظ کریں)</span></button>
                        </div>
                    </div>
                </article>
            </div>
        </form>

        <?php elseif ($activeTab === 'print'): ?>
        <!-- Tab 3: Print & Slips Settings -->
        <form method="post" style="margin-top:14px;">
            <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
            <input type="hidden" name="action" value="save_settings">
            <input type="hidden" name="return_tab" value="print">

            <div class="content-grid" style="grid-template-columns: 1fr; max-width: 960px;">
                <!-- Panel 1: Printer & Default Format -->
                <article class="panel" style="margin-bottom:20px;">
                    <div class="panel-heading">
                        <div>
                            <h3>Printer &amp; Default Slip Format <span class="ur">(پرنٹر اور ڈیفالٹ رسید سائز)</span></h3>
                            <p>Configure default voucher layout, paper size, and thermal receipt copies</p>
                        </div>
                    </div>
                    <div style="padding:22px 26px;">
                        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:20px;margin-bottom:10px;">
                            <div class="form-group">
                                <label style="font-weight:700;display:block;margin-bottom:6px;">
                                    Default Print Format <span class="ur">(ڈیفالٹ پرنٹ فارمیٹ)</span>
                                </label>
                                <select name="print_default_format" style="width:100%;font-size:13.5px;padding:9px 12px;border:1.5px solid #94a3b8;border-radius:7px;background:#ffffff;font-weight:600;color:#0f172a;">
                                    <option value="a4" <?=$printDefaultFormat === 'a4' ? 'selected' : ''?>>📄 A4 Full Sheet Voucher (بڑا واؤچر - لیزر پرنٹر)</option>
                                    <option value="thermal" <?=$printDefaultFormat === 'thermal' ? 'selected' : ''?>>🧾 80mm POS Thermal Slip (چھوٹی تھرمل رسید - پوائنٹ آف سیل)</option>
                                </select>
                                <small class="muted" style="display:block;margin-top:6px;font-size:11.5px;line-height:1.4;">
                                    <span class="ur">جب آپ رسید یا واؤچر پرنٹ کا بٹن دبائیں تو خودکار طور پر کون سا فارمیٹ کھلے۔</span>
                                </small>
                            </div>

                            <div class="form-group">
                                <label style="font-weight:700;display:block;margin-bottom:6px;">
                                    Thermal Slip Copies <span class="ur">(تھرمل رسید کی کاپیاں)</span>
                                </label>
                                <select name="print_thermal_copies" style="width:100%;font-size:13.5px;padding:9px 12px;border:1.5px solid #94a3b8;border-radius:7px;background:#ffffff;font-weight:600;color:#0f172a;">
                                    <option value="2" <?=$printThermalCopies === '2' ? 'selected' : ''?>>2 Copies (معاون رسید + دفتری کاپی ایک ساتھ - Cut Line کے ساتھ)</option>
                                    <option value="1" <?=$printThermalCopies === '1' ? 'selected' : ''?>>1 Copy (صرف ایک رسید)</option>
                                </select>
                                <small class="muted" style="display:block;margin-top:6px;font-size:11.5px;line-height:1.4;">
                                    <span class="ur">دو کاپیاں منتخب کرنے پر ایک ڈونر کے لیے اور ایک دفتری ریکارڈ کے لیے کٹ لائن کے ساتھ ایک ساتھ پرنٹ ہوں گی۔</span>
                                </small>
                            </div>
                        </div>
                    </div>
                </article>

                <!-- Panel 2: Letterhead & Branding -->
                <article class="panel" style="margin-bottom:20px;">
                    <div class="panel-heading">
                        <div>
                            <h3>Letterhead &amp; Branding <span class="ur">(لیٹر پیڈ اور لوگو ڈسپلے)</span></h3>
                            <p>Control logo visibility, address details, or enable pre-printed stationery letterhead</p>
                        </div>
                    </div>
                    <div style="padding:22px 26px;">
                        <div style="display:flex;flex-direction:column;gap:14px;">
                            <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;background:#f8fafc;padding:12px 14px;border-radius:8px;border:1px solid #e2e8f0;">
                                <input type="checkbox" name="print_show_logo" value="1" <?=$printShowLogo === '1' ? 'checked' : ''?> style="margin-top:3px;width:18px;height:18px;">
                                <div>
                                    <div style="font-weight:700;color:#0f172a;font-size:13.5px;">
                                        Show Official Logo / Monogram on Prints <span class="ur">(پرنٹ پر ادارے کا مونوگرام / لوگو دکھائیں)</span>
                                    </div>
                                    <div class="muted" style="font-size:11.5px;margin-top:2px;">
                                        اگر غیر فعال کریں گے تو رسید اور واؤچرز کے پرنٹ پر اوپر کا لوگو غائب رہے گا۔
                                    </div>
                                </div>
                            </label>

                            <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;background:#f8fafc;padding:12px 14px;border-radius:8px;border:1px solid #e2e8f0;">
                                <input type="checkbox" name="print_show_address" value="1" <?=$printShowAddress === '1' ? 'checked' : ''?> style="margin-top:3px;width:18px;height:18px;">
                                <div>
                                    <div style="font-weight:700;color:#0f172a;font-size:13.5px;">
                                        Show Address &amp; Contact Info <span class="ur">(مکمل پتہ اور رابطہ نمبر پرنٹ پر دکھائیں)</span>
                                    </div>
                                    <div class="muted" style="font-size:11.5px;margin-top:2px;">
                                        ادارے کا رجسٹرڈ پتہ اور فون نمبر رسید اور واؤچرز کے ہیڈر میں دکھایا جائے گا۔
                                    </div>
                                </div>
                            </label>

                            <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;background:#eff6ff;padding:12px 14px;border-radius:8px;border:1.5px solid #bfdbfe;">
                                <input type="checkbox" name="print_preprinted_letterhead" value="1" <?=$printPreprintedLetterhead === '1' ? 'checked' : ''?> style="margin-top:3px;width:18px;height:18px;">
                                <div>
                                    <div style="font-weight:700;color:#1e40af;font-size:13.5px;">
                                        📄 Use Pre-printed Official Letterhead <span class="ur">(پہلے سے چھپا ہوا آفیشل لیٹر پیڈ)</span>
                                    </div>
                                    <div class="muted" style="font-size:11.5px;margin-top:2px;color:#1e3a8a;">
                                        اگر آپ کا ادارہ پہلے سے چھپا ہوا پرنٹڈ پیڈ استعمال کرتا ہے جس پر مونوگرام اور نام پہلے سے چھپا ہوتا ہے، تو اسے چیک کریں۔ سسٹم اوپر کا ہیڈر خالی چھوڑ دے گا اور واؤچر کا مواد تقریباً 2.5 انچ نیچے سے پرنٹ ہوگا۔
                                    </div>
                                </div>
                            </label>
                        </div>
                    </div>
                </article>

                <!-- Panel 3: Signature Block Authority Titles -->
                <article class="panel" style="margin-bottom:20px;">
                    <div class="panel-heading">
                        <div>
                            <h3>Signatures &amp; Authority Titles <span class="ur">(دستخطی بلاک کے عنوانات)</span></h3>
                            <p>Customize the 3 authorization columns printed at the bottom of vouchers and statements</p>
                        </div>
                    </div>
                    <div style="padding:22px 26px;">
                        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));gap:16px;">
                            <div class="form-group">
                                <label style="font-weight:700;display:block;margin-bottom:6px;">
                                    Signature 1 (Left / تیار کنندہ)
                                </label>
                                <input type="text" name="print_sign_title_1" value="<?=e($printSignTitle1)?>" style="width:100%;font-size:13px;padding:9px 12px;border:1px solid #cbd5e1;border-radius:6px;font-weight:600;" placeholder="Prepared By (تیار کنندہ)">
                                <small class="muted" style="display:block;margin-top:4px;font-size:11px;">بائیں طرف کا پہلا دستخطی عنوان (کیشیئر / کلرک)</small>
                            </div>

                            <div class="form-group">
                                <label style="font-weight:700;display:block;margin-bottom:6px;">
                                    Signature 2 (Center / پڑتال و اکاؤنٹنٹ)
                                </label>
                                <input type="text" name="print_sign_title_2" value="<?=e($printSignTitle2)?>" style="width:100%;font-size:13px;padding:9px 12px;border:1px solid #cbd5e1;border-radius:6px;font-weight:600;" placeholder="Accountant (اکاؤنٹنٹ)">
                                <small class="muted" style="display:block;margin-top:4px;font-size:11px;">درمیان کا دستخطی عنوان (اکاؤنٹنٹ / ناظمِ مالیات)</small>
                            </div>

                            <div class="form-group">
                                <label style="font-weight:700;display:block;margin-bottom:6px;">
                                    Signature 3 (Right / مجاز اتھارٹی)
                                </label>
                                <input type="text" name="print_sign_title_3" value="<?=e($printSignTitle3)?>" style="width:100%;font-size:13px;padding:9px 12px;border:1px solid #cbd5e1;border-radius:6px;font-weight:600;" placeholder="Director / Mohtamim (مہتمم / صدر)">
                                <small class="muted" style="display:block;margin-top:4px;font-size:11px;">دائیں طرف کی مجاز اتھارٹی (مہتمم / ڈائریکٹر / صدر)</small>
                            </div>
                        </div>
                    </div>
                </article>

                <!-- Panel 4: Receipt Dua & Verification Note -->
                <article class="panel" style="margin-bottom:20px;">
                    <div class="panel-heading">
                        <div>
                            <h3>Receipt Dua &amp; Verification Note <span class="ur">(رسید دعائیہ کلمات و تصدیقی نوٹس)</span></h3>
                            <p>Customize the donor blessing dua and computerized verification notice</p>
                        </div>
                    </div>
                    <div style="padding:22px 26px;">
                        <div class="form-group" style="margin-bottom:16px;">
                            <label style="font-weight:700;display:block;margin-bottom:6px;">
                                Donor Receipt Dua / Blessings <span class="ur">(ڈونر رسید پر دعائیہ کلمات)</span>
                            </label>
                            <textarea name="print_receipt_dua" rows="2" style="width:100%;font-size:13.5px;padding:10px 12px;border:1px solid #cbd5e1;border-radius:6px;line-height:1.5;direction:rtl;text-align:right;font-family:inherit;"><?=e($printReceiptDua)?></textarea>
                            <small class="muted" style="display:block;margin-top:4px;font-size:11px;">یہ دعائیہ کلمات معاون کی رسید (A4 اور تھرمل سلپ) کے نیچے نمایاں پرنٹ ہوتے ہیں۔</small>
                        </div>

                        <div class="form-group">
                            <label style="font-weight:700;display:block;margin-bottom:6px;">
                                Computerized System Note <span class="ur">(کمپیوٹرائزڈ رسید کی تصدیقی عبارت)</span>
                            </label>
                            <input type="text" name="print_footer_note" value="<?=e($printFooterNote)?>" style="width:100%;font-size:13px;padding:9px 12px;border:1px solid #cbd5e1;border-radius:6px;">
                            <small class="muted" style="display:block;margin-top:4px;font-size:11px;">پرنٹ کے بالکل نیچے قانونی / تصدیقی نوٹ۔</small>
                        </div>

                        <div style="margin-top:22px;padding-top:16px;border-top:1px solid #e2e8f0;">
                            <button class="btn blue" type="submit" style="padding:11px 26px;font-weight:700;font-size:14px;">
                                💾 Save Print &amp; Slip Settings <span class="ur">(پرنٹ ترتیبات محفوظ کریں)</span>
                            </button>
                        </div>
                    </div>
                </article>
            </div>
        </form>

        <?php elseif ($activeTab === 'security'): ?>
        <!-- Tab 3: Security, Passwords & Login Activity -->
        <div id="passwordSection" style="margin-top: 14px;">
            <div class="content-grid">
                <!-- Panel 1: Admin Change Own Password -->
                <article class="panel">
                    <div class="panel-heading">
                        <div>
                            <h3>Change My Password <span class="ur">(اپنا پاس ورڈ تبدیل کریں)</span></h3>
                            <p>Update your personal login password with verification</p>
                        </div>
                    </div>
                    <form method="post" action="settings.php?tab=security#passwordSection" style="padding: 20px 24px;">
                        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                        <input type="hidden" name="action" value="admin_change_own_password">

                        <div class="form-group" style="margin-bottom:14px;">
                            <label>Current Password <span class="ur">(موجودہ پاس ورڈ)</span> *</label>
                            <input type="password" name="current_password" required placeholder="Enter current password" style="width:100%;">
                        </div>

                        <div class="form-group" style="margin-bottom:14px;">
                            <label>New Password <span class="ur">(نیا پاس ورڈ)</span> *</label>
                            <input type="password" name="new_password" required minlength="8" placeholder="At least 8 characters" style="width:100%;">
                        </div>

                        <div class="form-group" style="margin-bottom:20px;">
                            <label>Confirm New Password <span class="ur">(نئے پاس ورڈ کی تصدیق)</span> *</label>
                            <input type="password" name="confirm_password" required minlength="8" placeholder="Re-type new password" style="width:100%;">
                        </div>

                        <button class="btn blue" type="submit" style="width:100%;justify-content:center;">
                            💾 Update My Password <span class="ur">(میرا پاس ورڈ تبدیل کریں)</span>
                        </button>
                    </form>
                </article>

                <!-- Panel 2: Reset Any User Password (Admin Power Tool) -->
                <article class="panel" style="border: 1.5px solid #bfdbfe; background: #f8fafc;">
                    <div class="panel-heading" style="background:#eff6ff;border-bottom:1px solid #bfdbfe;">
                        <div>
                            <h3 style="color:#1d4ed8;">🔑 Reset User Password <span class="ur">(کسی بھی یوزر کا پاس ورڈ ری سیٹ کریں)</span></h3>
                            <p style="color:#2563eb;">Assign a new password to any staff member without needing their old password</p>
                        </div>
                    </div>
                    <form method="post" action="settings.php?tab=security#passwordSection" style="padding: 20px 24px;" onsubmit="return confirm('Are you sure you want to reset password for this user?');">
                        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                        <input type="hidden" name="action" value="admin_reset_user_password">

                        <div class="form-group" style="margin-bottom:14px;">
                            <label>Select User / Staff Member <span class="ur">(صارف / ملازم منتخب کریں)</span> *</label>
                            <select name="target_user_id" required style="width:100%;font-weight:600;">
                                <option value="">-- Choose User to Reset Password --</option>
                                <?php foreach ($allUsersList as $usr): ?>
                                    <option value="<?=$usr['id']?>">
                                        <?=e($usr['full_name'])?> (@<?=e($usr['username'])?>) — [<?=e(function_exists('get_role_display_name') ? get_role_display_name($usr['role_name'], false) : $usr['role_name'])?>]
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group" style="margin-bottom:14px;">
                            <label>New Password for User <span class="ur">(یوزر کا نیا پاس ورڈ)</span> *</label>
                            <input type="text" name="reset_password" required minlength="8" placeholder="Enter new password (min 8 characters)" style="width:100%;font-family:monospace;font-size:14px;">
                            <small class="muted" style="display:block;margin-top:4px;">
                                <span class="ur">ایڈمن کو یوزر کا پرانا پاس ورڈ دینے کی ضرورت نہیں ہے۔ یہ پاس ورڈ فوری لاگو ہو جائے گا۔</span>
                                <span class="en-only" style="display:none;">Old password is not required. This takes effect immediately.</span>
                            </small>
                        </div>

                        <div style="margin-top:24px;">
                            <button class="btn success" type="submit" style="width:100%;justify-content:center;padding:11px;">
                                🔑 Reset User's Password <span class="ur">(پاس ورڈ ری سیٹ کریں)</span>
                            </button>
                        </div>
                    </form>
                </article>
            </div>

            <!-- Feature #4: Recent Login Activity & Security Audit Log -->
            <article id="loginActivitySection" class="panel" style="margin-top: 28px; border: 1.5px solid #cbd5e1; background: #ffffff; border-radius: 12px; overflow:hidden;">
                <div class="panel-heading" style="border-bottom: 1px solid #e2e8f0; background: #f8fafc; padding: 16px 24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <span style="font-size:24px;">🔒</span>
                        <div>
                            <h3 style="color:#0f172a;margin:0;">Login Activity &amp; Device Tracker <span class="ur">(حالیہ لاگ ان اور سیکیورٹی لاگ)</span></h3>
                            <p style="color:#64748b;margin:2px 0 0;font-size:12.5px;">
                                Audit log of recent user logins, devices used (Desktop/Mobile), IP addresses, and authentication status.
                            </p>
                        </div>
                    </div>
                    <div>
                        <span class="badge" style="font-size:11.5px;padding:4px 10px;background:#e2e8f0;color:#334155;font-weight:600;">
                            Showing Last <?=count($recentLoginActivities)?> Events
                        </span>
                    </div>
                </div>

                <div style="padding: 20px 24px;">
                    <?php if (empty($recentLoginActivities)): ?>
                        <div style="text-align:center;padding:30px 20px;border:1.5px dashed #cbd5e1;border-radius:8px;color:#64748b;font-size:13px;">
                            <span style="font-size:28px;display:block;margin-bottom:6px;">🛡️</span>
                            کوئی لاگ ان ریکارڈ ابھی موجود نہیں ہے۔ تمام نئے لاگ ان یہاں خود بخود ظاہر ہوں گے۔
                        </div>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                            <table class="table" style="width:100%;border-collapse:collapse;font-size:13px;">
                                <thead>
                                    <tr style="background:#f1f5f9;text-align:left;">
                                        <th style="padding:10px 14px;border-bottom:2px solid #cbd5e1;width:40px;">#</th>
                                        <th style="padding:10px 14px;border-bottom:2px solid #cbd5e1;">User / Staff <span class="ur">(صارف)</span></th>
                                        <th style="padding:10px 14px;border-bottom:2px solid #cbd5e1;width:120px;">Status <span class="ur">(حیثیت)</span></th>
                                        <th style="padding:10px 14px;border-bottom:2px solid #cbd5e1;width:120px;">Device <span class="ur">(ڈیوائس)</span></th>
                                        <th style="padding:10px 14px;border-bottom:2px solid #cbd5e1;width:150px;">IP Address <span class="ur">(آئی پی)</span></th>
                                        <th style="padding:10px 14px;border-bottom:2px solid #cbd5e1;text-align:right;width:180px;">Date &amp; Time <span class="ur">(وقت)</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentLoginActivities as $idx => $act): ?>
                                        <tr style="border-bottom:1px solid #e2e8f0;">
                                            <td style="padding:10px 14px;color:#64748b;font-weight:600;"><?=$idx + 1?></td>
                                            <td style="padding:10px 14px;">
                                                <div style="display:flex;align-items:center;gap:8px;">
                                                    <div style="width:28px;height:28px;border-radius:50%;background:#e0e7ff;color:#4338ca;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11px;">
                                                        <?=strtoupper(substr($act['username'] ?? 'U', 0, 2))?>
                                                    </div>
                                                    <div>
                                                        <strong style="color:#0f172a;display:block;font-size:13px;"><?=e($act['full_name'] ?: $act['username'])?></strong>
                                                        <span style="font-size:11px;color:#64748b;">@<?=e($act['username'])?><?=!empty($act['role_name']) ? ' &bull; ' . e($act['role_name']) : ''?></span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td style="padding:10px 14px;">
                                                <?php if ($act['status'] === 'success'): ?>
                                                    <span class="badge" style="background:#dcfce7;color:#15803d;font-weight:700;font-size:11px;padding:3px 8px;border-radius:12px;display:inline-flex;align-items:center;gap:4px;">
                                                        <span style="font-size:9px;">●</span> Success (کامیاب)
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge" style="background:#fee2e2;color:#b91c1c;font-weight:700;font-size:11px;padding:3px 8px;border-radius:12px;display:inline-flex;align-items:center;gap:4px;">
                                                        <span style="font-size:9px;">●</span> Failed (ناکام)
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding:10px 14px;color:#334155;font-weight:500;">
                                                <?php
                                                    $dIcon = '💻';
                                                    if ($act['device_type'] === 'Mobile') $dIcon = '📱';
                                                    elseif ($act['device_type'] === 'Tablet') $dIcon = '📟';
                                                ?>
                                                <?=$dIcon?> <?=e($act['device_type'])?>
                                            </td>
                                            <td style="padding:10px 14px;">
                                                <code style="background:#f1f5f9;border:1px solid #cbd5e1;padding:2px 7px;border-radius:4px;font-size:11.5px;color:#0f172a;font-family:monospace;">
                                                    <?=e($act['ip_address'])?>
                                                </code>
                                            </td>
                                            <td style="padding:10px 14px;text-align:right;color:#475569;font-size:12px;">
                                                <?=date('d M Y, h:i A', strtotime($act['created_at']))?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </article>
        </div>

        <?php elseif ($activeTab === 'backups'): ?>
        <!-- Tab 4: Database & Backups -->
        <div style="margin-top: 14px;">
            <!-- One-Click Database Backup & Disaster Recovery -->
            <article id="backupSection" class="panel" style="border: 2px solid #16a34a; background: #ffffff; border-radius: 12px; overflow:hidden;">
            <div class="panel-heading" style="border-bottom: 1.5px solid #bbf7d0; background: #dcfce7; padding: 16px 24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <span style="font-size:26px;">💾</span>
                    <div>
                        <h3 style="color:#15803d;margin:0;">Database Backup &amp; Disaster Recovery <span class="ur">(ڈیٹا بیس بیک اپ اور ڈیٹا کی حفاظت)</span></h3>
                        <p style="color:#166534;margin:2px 0 0;font-size:12.5px;">
                            Download instant SQL backup, schedule automated daily backups, or restore previous system checkpoints.
                        </p>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:10px;">
                    <span class="badge badge-green" style="font-size:12px;padding:5px 12px;background:#bbf7d0;color:#166534;font-weight:700;border-radius:20px;">
                        🟢 Auto-Backup: Active (فعال ہے)
                    </span>
                </div>
            </div>

            <!-- Quick Action Bar -->
            <div style="padding: 20px 24px; background:#f0fdf4; border-bottom:1px solid #dcfce7; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:16px;">
                <div style="max-width:550px;font-size:13px;line-height:1.7;color:#166534;">
                    <div class="ur">
                        <strong>💡 خودکار بیک اپ کا طریقہ کار:</strong><br>
                        جیسے ہی کوئی ایڈمن روزانہ سسٹم کھولے گا، ایک تازہ بیک اپ خودکار طریقے سے سرور میں محفوظ ہو جاتا ہے۔ سرور پر خود بخود آخری 15 دن کے بیک اپ محفوظ رکھے جاتے ہیں تاکہ ڈسک پر بوجھ بھی نہ بنے اور ریکارڈ بھی محفوظ رہے۔
                    </div>
                    <div class="en-only" style="display:none;">
                        <strong>💡 Automatic Daily Backup:</strong><br>
                        A fresh snapshot is automatically archived on server once daily when an admin logs in. The last 15 daily snapshots are maintained automatically.
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <a href="settings.php?action=export_db_backup&csrf=<?=e(csrf_token())?>" class="btn success" style="background:#15803d;border-color:#15803d;padding:11px 16px;font-size:13px;font-weight:700;display:inline-flex;align-items:center;gap:7px;text-decoration:none;color:#fff;border-radius:6px;box-shadow:0 2px 4px rgba(0,0,0,0.08);">
                        💾 Download Live Backup <span class="ur">(فوری ڈاؤن لوڈ)</span>
                    </a>
                    <a href="settings.php?action=run_backup_now&csrf=<?=e(csrf_token())?>" class="btn" style="background:#0284c7;border-color:#0284c7;padding:11px 16px;font-size:13px;font-weight:700;display:inline-flex;align-items:center;gap:7px;text-decoration:none;color:#fff;border-radius:6px;box-shadow:0 2px 4px rgba(0,0,0,0.08);" onclick="return confirm('ابھی ایک نیا آرکائیو بیک اپ تیار کر کے محفوظ کریں؟');">
                        🚀 Run Backup Now <span class="ur">(ابھی بیک اپ بنائیں)</span>
                    </a>
                    <button type="button" class="btn blue" onclick="openRestoreModal()" style="background:#4f46e5;border-color:#4f46e5;padding:11px 16px;font-size:13px;font-weight:700;display:inline-flex;align-items:center;gap:7px;color:#fff;border-radius:6px;box-shadow:0 2px 4px rgba(0,0,0,0.08);cursor:pointer;">
                        📤 Upload &amp; Restore File <span class="ur">(فائل اپلوڈ کریں)</span>
                    </button>
                </div>
            </div>

            <!-- Hostinger / Server Automated Cron Job Instruction Box -->
            <div style="padding: 18px 24px; border-bottom: 1px solid #e2e8f0; background:#f8fafc;">
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:8px;">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span style="font-size:18px;">⏰</span>
                        <strong style="color:#0f172a;font-size:13.5px;">Hostinger &amp; Server Scheduled Cron Job <span class="ur">(ہوسٹنگر سرور پر روزانہ خودکار بیک اپ)</span></strong>
                    </div>
                    <span style="font-size:12px;color:#64748b;">
                        آخری خودکار بیک اپ: <b><?=!empty($lastBackupAt) ? date('d M Y, h:i A', strtotime($lastBackupAt)) : 'آج کامیابی سے تیار ہوا'?></b>
                    </span>
                </div>
                <p style="margin:0 0 10px;font-size:12.5px;color:#475569;line-height:1.6;">
                    اگر آپ چاہتے ہیں کہ بغیر لاگ ان ہوئے بھی سرور خود ہی روزانہ رات کے وقت بیک اپ لے، تو Hostinger hPanel میں <b>Advanced &gt; Cron Jobs</b> میں جا کر روزانہ 02:00 AM پر درج ذیل کمانڈ لگا دیں:
                </p>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <input type="text" id="cronCmdInput" readonly value="<?=e($cronCurlCmd)?>" style="flex:1;min-width:280px;background:#ffffff;border:1px solid #cbd5e1;padding:8px 12px;border-radius:6px;font-family:monospace;font-size:12.5px;color:#0f172a;">
                    <button type="button" class="btn secondary" onclick="copyCronCmd()" style="padding:8px 14px;font-size:12.5px;display:inline-flex;align-items:center;gap:6px;cursor:pointer;">
                        📋 <span id="copyBtnText">Copy Command (کمانڈ کاپی کریں)</span>
                    </button>
                </div>
            </div>

            <!-- Automated Daily Backup to Gmail / Email (Feature #1) -->
            <div id="emailBackupSection" style="padding: 18px 24px; border-bottom: 1px solid #e2e8f0; background:#f0fdf4;">
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:10px;">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span style="font-size:20px;">📧</span>
                        <strong style="color:#166534;font-size:14px;">Daily Automated Backup to Gmail / Email <span class="ur">(روزانہ ڈیٹا بیس بیک اپ بذریعہ ای میل وصول کریں)</span></strong>
                    </div>
                    <?php if (!empty($lastBackupEmailAt)): ?>
                        <span style="font-size:12px;color:#15803d;background:#dcfce7;padding:3px 10px;border-radius:20px;font-weight:600;">
                            آخری ای میل روانہ ہوئی: <?=date('d M Y, h:i A', strtotime($lastBackupEmailAt))?>
                        </span>
                    <?php endif; ?>
                </div>
                <p style="margin:0 0 12px;font-size:12.5px;color:#166534;line-height:1.6;">
                    جب بھی روزانہ نیا بیک اپ تیار ہوگا، سسٹم وہ مکمل بیک اپ فائل خود بخود آپ کے درج کردہ ای میل یا جی میل (Gmail) پر بطور اٹیچمنٹ روانہ کر دے گا۔ سرور خراب ہونے کی صورت میں بھی آپ کا ڈیٹا جی میل پر ہمیشہ محفوظ رہے گا۔
                </p>

                <form method="post" action="settings.php#emailBackupSection" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                    <input type="hidden" name="action" value="save_email_backup_settings">

                    <label style="display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:700;color:#14532d;cursor:pointer;background:#fff;padding:8px 12px;border:1.5px solid #86efac;border-radius:6px;">
                        <input type="checkbox" name="backup_email_enabled" value="1" <?=$backupEmailEnabled === '1' ? 'checked' : ''?> style="width:16px;height:16px;accent-color:#16a34a;">
                        <span>Enable Email Backup <span class="ur">(ای میل بیک اپ فعال کریں)</span></span>
                    </label>

                    <input type="email" name="backup_email_address" value="<?=e($backupEmailAddress)?>" placeholder="e.g. admin@gmail.com / yourtrust@gmail.com" style="flex:1;min-width:240px;background:#ffffff;border:1.5px solid #86efac;padding:8px 12px;border-radius:6px;font-size:13px;color:#0f172a;">

                    <button type="submit" class="btn success" style="background:#16a34a;border-color:#16a34a;padding:8px 14px;font-size:12.5px;display:inline-flex;align-items:center;gap:6px;cursor:pointer;">
                        💾 Save Email Settings <span class="ur">(ای میل محفوظ کریں)</span>
                    </button>

                    <?php if (!empty($backupEmailAddress)): ?>
                        <a href="settings.php?action=test_backup_email&csrf=<?=e(csrf_token())?>" class="btn" style="background:#0284c7;border-color:#0284c7;color:#fff;padding:8px 14px;font-size:12.5px;display:inline-flex;align-items:center;gap:6px;text-decoration:none;border-radius:6px;cursor:pointer;" onclick="return confirm('ابھی ٹیسٹ بیک اپ ای میل ارسال کریں؟\nوصول کنندہ: <?=e($backupEmailAddress)?>');">
                            📧 Send Test Email Now <span class="ur">(ٹیسٹ ای میل بھیجیں)</span>
                        </a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Saved Backups Archive Table -->
            <div style="padding: 22px 24px;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
                    <h4 style="margin:0;color:#1e293b;font-size:15px;display:flex;align-items:center;gap:8px;">
                        📁 Saved Backups Archive <span class="ur">(سرور پر محفوظ شدہ بیک اپ فائلز کا ریکارڈ)</span>
                        <span style="font-size:12px;font-weight:normal;color:#64748b;">(<?=count($savedBackups)?> Backups stored)</span>
                    </h4>
                    <span style="font-size:12px;color:#16a34a;font-weight:600;">
                        ✓ Auto-Retention: Keeping latest 15 daily snapshots
                    </span>
                </div>

                <?php if (empty($savedBackups)): ?>
                    <div style="text-align:center;padding:35px 20px;border:1.5px dashed #cbd5e1;border-radius:8px;color:#64748b;font-size:13px;">
                        <span style="font-size:32px;display:block;margin-bottom:8px;">📦</span>
                        کوئی سابقہ بیک اپ فائل ابھی موجود نہیں ہے۔ نیا بیک اپ بنانے کے لیے اوپر <b>Run Backup Now</b> پر کلک کریں۔
                    </div>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table class="table" style="width:100%;border-collapse:collapse;font-size:13px;">
                            <thead>
                                <tr style="background:#f1f5f9;text-align:left;">
                                    <th style="padding:10px 14px;border-bottom:2px solid #cbd5e1;width:40px;">#</th>
                                    <th style="padding:10px 14px;border-bottom:2px solid #cbd5e1;">File Name <span class="ur">(فائل کا نام)</span></th>
                                    <th style="padding:10px 14px;border-bottom:2px solid #cbd5e1;width:180px;">Created Date <span class="ur">(تاریخ و وقت)</span></th>
                                    <th style="padding:10px 14px;border-bottom:2px solid #cbd5e1;width:100px;">Size <span class="ur">(سائز)</span></th>
                                    <th style="padding:10px 14px;border-bottom:2px solid #cbd5e1;text-align:right;width:240px;">Actions <span class="ur">(کارروائی)</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($savedBackups as $idx => $bk): ?>
                                    <tr style="border-bottom:1px solid #e2e8f0;">
                                        <td style="padding:10px 14px;color:#64748b;font-weight:600;"><?=$idx + 1?></td>
                                        <td style="padding:10px 14px;">
                                            <span style="font-family:monospace;font-weight:600;color:#0f172a;display:inline-flex;align-items:center;gap:6px;">
                                                💾 <?=e($bk['filename'])?>
                                            </span>
                                        </td>
                                        <td style="padding:10px 14px;color:#475569;">
                                            <?=e($bk['date_formatted'])?>
                                        </td>
                                        <td style="padding:10px 14px;">
                                            <span class="badge" style="background:#e0f2fe;color:#0369a1;font-weight:600;font-size:11.5px;padding:3px 8px;border-radius:4px;">
                                                <?=e($bk['filesize_formatted'])?>
                                            </span>
                                        </td>
                                        <td style="padding:10px 14px;text-align:right;">
                                            <div style="display:inline-flex;align-items:center;gap:6px;">
                                                <a href="settings.php?action=download_saved_backup&file=<?=urlencode($bk['filename'])?>&csrf=<?=e(csrf_token())?>" class="btn success" style="padding:5px 10px;font-size:12px;background:#15803d;color:#fff;text-decoration:none;border-radius:4px;" title="Download this file to your computer">
                                                    📥 Download
                                                </a>
                                                <button type="button" class="btn blue" style="padding:5px 10px;font-size:12px;background:#2563eb;color:#fff;border-radius:4px;cursor:pointer;" onclick="openRestoreSavedModal('<?=e($bk['filename'])?>')" title="Restore this checkpoint">
                                                    🔄 Restore
                                                </button>
                                                <a href="settings.php?action=delete_saved_backup&file=<?=urlencode($bk['filename'])?>&csrf=<?=e(csrf_token())?>" class="btn danger" style="padding:5px 9px;font-size:12px;background:#dc2626;color:#fff;text-decoration:none;border-radius:4px;" onclick="return confirm('کیا آپ واقعی یہ بیک اپ فائل ڈیلیٹ کرنا چاہتے ہیں؟\n<?=e($bk['filename'])?>');" title="Delete backup">
                                                    🗑️
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </article>

        <!-- Feature #5: Database Performance & 1-Click Speed Optimization -->
        <article id="dbOptimizeSection" class="panel" style="margin-top: 32px; border: 2px solid #8b5cf6; background: #ffffff; border-radius: 12px; overflow:hidden;">
            <div class="panel-heading" style="border-bottom: 1.5px solid #ddd6fe; background: #f5f3ff; padding: 16px 24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <span style="font-size:26px;">⚡</span>
                    <div>
                        <h3 style="color:#6d28d9;margin:0;">Database Performance &amp; Optimization <span class="ur">(ڈیٹا بیس اسپیڈ تیز اور آپٹیمائز کریں)</span></h3>
                        <p style="color:#7c3aed;margin:2px 0 0;font-size:12.5px;">
                            Defragment database tables, re-index records, and maximize software responsiveness with 1-click.
                        </p>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:10px;">
                    <span class="badge" style="font-size:12px;padding:5px 12px;background:#ede9fe;color:#6d28d9;font-weight:700;border-radius:20px;">
                        📊 Total Tables: <?=$dbTablesCount?> Active
                    </span>
                </div>
            </div>

            <div style="padding: 20px 24px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:16px;">
                <div style="max-width:620px;font-size:13px;line-height:1.7;color:#475569;">
                    <div class="ur">
                        <strong>⚡ آپٹیمائزیشن کی اہمیت:</strong><br>
                        سسٹم میں روزمرہ واؤچرز درج کرنے، سرچ کرنے یا پرانے ریکارڈز حذف کرنے سے ڈیٹا بیس میں اندرونی جگہ (Overhead) غیر استعمال شدہ رہ جاتی ہے۔ یہ ٹول ایک ہی کلک پر MySQL کے تمام <b><?=$dbTablesCount?></b> ٹیبلز کو <code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;color:#6d28d9;">OPTIMIZE TABLE</code> اور <code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;color:#6d28d9;">ANALYZE TABLE</code> چلا کر غیر ضروری اسپیس فری کرتا ہے اور رپورٹنگ کی رفتار کو تیز ترین بناتا ہے۔
                    </div>
                    <div class="en-only" style="display:none;">
                        <strong>⚡ Why Optimize:</strong><br>
                        Continuous voucher creation and searches create fragmented overhead in database tables. This tool defragments all <b><?=$dbTablesCount?></b> tables, rebuilds index trees, and frees unused disk space for maximum speed.
                    </div>
                </div>
                <div>
                    <a href="settings.php?action=optimize_database&csrf=<?=e(csrf_token())?>" class="btn" style="background:#7c3aed;border-color:#7c3aed;color:#fff;padding:12px 20px;font-size:13.5px;font-weight:700;display:inline-flex;align-items:center;gap:8px;text-decoration:none;border-radius:7px;box-shadow:0 3px 6px rgba(124,58,237,0.25);" onclick="return confirm('کیا آپ ڈیٹا بیس کے تمام ٹیبلز کو ابھی آپٹیمائز اور تیز کرنا چاہتے ہیں؟');">
                        ⚡ Run 1-Click Database Optimization <span class="ur">(ابھی اسپیڈ تیز کریں)</span>
                    </a>
                </div>
            </div>
        </article>
        </div>

        <?php elseif ($activeTab === 'danger' && $isSuperAdmin): ?>
        <!-- Tab 7: Danger Zone & Mode-Aware Defaults Manager -->
        <div style="margin-top: 14px;">
            <article class="panel" style="border: 2px solid #ef4444; background: #fff5f5; border-radius: 12px; overflow:hidden;">
                <div class="panel-heading" style="border-bottom: 1.5px solid #fecaca; background: #fee2e2; padding: 18px 24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <span style="font-size:28px;">⚠️</span>
                        <div>
                            <h3 style="color:#991b1b;margin:0;font-size:17px;">Mode-Aware Defaults &amp; Danger Zone <span class="ur">(موڈ ترتیبات و خطرناک زون)</span></h3>
                            <p style="color:#b91c1c;margin:3px 0 0;font-size:12.5px;">
                                <span class="ur">سسٹم کو ٹیسٹ ڈیٹا سے صاف کرنے، نیا مالی سال شروع کرنے یا مدرسہ/ٹرسٹ موڈ کی ڈیفالٹ ترتیبات لاگو کرنے کے لیے استعمال کریں۔</span>
                            </p>
                        </div>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <span class="badge <?=is_madrasa_mode() ? 'badge-green' : 'badge-blue'?>" style="font-size:12px;padding:5px 11px;">
                            🏛️ <?=is_madrasa_mode() ? 'Madrasa Mode (دینی درسگاہ)' : 'Trust Mode (فلاحی ٹرسٹ)'?>
                        </span>
                        <span class="badge badge-red" style="font-size:12px;padding:5px 11px;">
                            🧾 <?=$vouchersCount?> واؤچرز (<?=$journalsCount?> انٹریز)
                        </span>
                        <span class="badge secondary" style="font-size:12px;padding:5px 11px;">
                            👥 <?=$donorsCount?> معاونین | 🎓 <?=$beneficiariesCount?> مستحقین
                        </span>
                    </div>
                </div>

                <div style="padding: 24px;">
                    <form method="post" action="settings.php?tab=danger" id="dangerZoneForm" onsubmit="return validateDangerSubmit();">
                        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                        <input type="hidden" name="action" value="danger_reset_defaults">

                        <!-- Step 1: Select Target Mode -->
                        <div style="background:#ffffff;border:1.5px solid #fca5a5;border-radius:10px;padding:16px 20px;margin-bottom:20px;">
                            <label style="display:block;font-size:14px;font-weight:700;color:#991b1b;margin-bottom:12px;">
                                1. منتخب کردہ موڈ (Select Target Mode) *
                            </label>
                            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:14px;">
                                <label style="display:flex;align-items:flex-start;gap:12px;padding:12px 14px;border:2px solid <?=is_madrasa_mode() ? '#16a34a' : '#e2e8f0'?>;border-radius:8px;background:<?=is_madrasa_mode() ? '#f0fdf4' : '#f8fafc'?>;cursor:pointer;" id="labelModeMadrasa">
                                    <input type="radio" name="target_mode" value="madrasa" id="target_mode_madrasa" <?=is_madrasa_mode() ? 'checked' : ''?> onchange="onDangerModeChange('madrasa')" style="margin-top:3px;accent-color:#16a34a;width:18px;height:18px;">
                                    <div>
                                        <b style="font-size:14px;color:#15803d;">🕌 دینی درسگاہ موڈ (Islamic Seminary Mode)</b>
                                        <p style="margin:3px 0 0;font-size:12px;color:#475569;">
                                            دینی و تعلیمی شعبہ جات، ناظم و مہتمم دستخط، طلبہ کفالت و مطبخ فنڈز، اور مدرسہ عمومی فنڈ (MGF)۔
                                        </p>
                                    </div>
                                </label>

                                <label style="display:flex;align-items:flex-start;gap:12px;padding:12px 14px;border:2px solid <?=!is_madrasa_mode() ? '#2563eb' : '#e2e8f0'?>;border-radius:8px;background:<?=!is_madrasa_mode() ? '#eff6ff' : '#f8fafc'?>;cursor:pointer;" id="labelModeTrust">
                                    <input type="radio" name="target_mode" value="trust" id="target_mode_trust" <?=!is_madrasa_mode() ? 'checked' : ''?> onchange="onDangerModeChange('trust')" style="margin-top:3px;accent-color:#2563eb;width:18px;height:18px;">
                                    <div>
                                        <b style="font-size:14px;color:#1d4ed8;">🏢 فلاحی ٹرسٹ موڈ (Welfare Trust Mode)</b>
                                        <p style="margin:3px 0 0;font-size:12px;color:#475569;">
                                            فلاحی پروجیکٹس، چیئرمین و فنانس ڈائریکٹر دستخط، راشن، میڈیکل، واٹر اور ٹرسٹ جنرل فنڈ (TGF)۔
                                        </p>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- Step 2: Live Dynamic Defaults Preview -->
                        <div style="background:#ffffff;border:1.5px solid #cbd5e1;border-radius:10px;padding:16px 20px;margin-bottom:20px;">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;border-bottom:1px solid #f1f5f9;padding-bottom:8px;">
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <span style="font-size:18px;">👁️</span>
                                    <b style="font-size:13.5px;color:#1e293b;">منتخب موڈ کے متعلقہ نام اور ڈیفالٹ ترتیبات (Mode Defaults Preview)</b>
                                </div>
                                <span id="dangerModeBadge" class="badge <?=is_madrasa_mode() ? 'badge-green' : 'badge-blue'?>">
                                    <?=is_madrasa_mode() ? '🕌 دینی مدرسہ موڈ' : '🏢 فلاحی ٹرسٹ موڈ'?>
                                </span>
                            </div>

                            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:12px;font-size:13px;line-height:1.6;">
                                <div style="background:#f8fafc;padding:10px 14px;border-radius:7px;border:1px solid #e2e8f0;">
                                    <span style="color:#64748b;display:block;font-size:11.5px;">🏛️ ڈیفالٹ ادارے کا نام (Organization Name):</span>
                                    <b id="previewOrgName" style="color:#0f172a;font-size:13.5px;"><?=is_madrasa_mode() ? 'جامعہ دارالعلوم / مدرسہ اسلامیہ' : 'الحبیب ویلفیئر ٹرسٹ'?></b>
                                </div>
                                <div style="background:#f8fafc;padding:10px 14px;border-radius:7px;border:1px solid #e2e8f0;">
                                    <span style="color:#64748b;display:block;font-size:11.5px;">🏷️ ذیلی تعارف (Org Subtitle):</span>
                                    <span id="previewOrgSubtitle" style="color:#334155;font-weight:600;"><?=is_madrasa_mode() ? 'تعلیم، تربیت اور دینی خدمات' : 'فلاح و بہبود اور انسانی خدمت'?></span>
                                </div>
                                <div style="background:#f8fafc;padding:10px 14px;border-radius:7px;border:1px solid #e2e8f0;">
                                    <span style="color:#64748b;display:block;font-size:11.5px;">🌿 مرکزی بنیادی فنڈ (Primary Default Fund):</span>
                                    <span id="previewFund" style="color:#16a34a;font-weight:700;"><?=is_madrasa_mode() ? 'Madrasa General Fund (مدرسہ عمومی فنڈ - MGF)' : 'Trust General Fund (ٹرسٹ جنرل فنڈ - TGF)'?></span>
                                </div>
                                <div style="background:#f8fafc;padding:10px 14px;border-radius:7px;border:1px solid #e2e8f0;">
                                    <span style="color:#64748b;display:block;font-size:11.5px;">✍️ رسیدات و واؤچرز کے دستخط (Print Signatures):</span>
                                    <span id="previewSignatures" style="color:#475569;font-size:12px;"><?=is_madrasa_mode() ? 'تیار کنندہ / کیشیئر | اکاؤنٹنٹ / ناظمِ مالیات | مہتمم / صدر / مجاز دستخط' : 'Prepared By (تیار کنندہ) | Accountant / Cashier (اکاؤنٹنٹ) | Director / Chairman (دستخط مجاز اتھارٹی)'?></span>
                                </div>
                            </div>

                            <div style="margin-top:12px;background:#f8fafc;padding:10px 14px;border-radius:7px;border:1px solid #e2e8f0;">
                                <span style="color:#64748b;display:block;font-size:11.5px;">📢 آن لائن ڈونیشن اپیل (Public Appeal Message):</span>
                                <span id="previewAppeal" style="color:#475569;font-size:12.5px;"><?=is_madrasa_mode() ? 'دینی و تعلیمی خدمات میں ہمارا ساتھ دیں (یتامیٰ و مستحق طلباء کی کفالت)' : 'فلاحی و انسانی خدمات میں ہمارا ساتھ دیں (راشن، میڈیکل اور بیوہ کفالت)'?></span>
                            </div>

                            <div style="margin-top:12px;">
                                <label style="display:block;font-size:12.5px;font-weight:600;color:#475569;margin-bottom:4px;">
                                    اختیاری: اگر آپ ڈیفالٹ نام کے بجائے ادارے کا کوئی مخصوص نام رکھنا چاہتے ہیں تو یہاں درج کریں (Optional Custom Name):
                                </label>
                                <input type="text" name="custom_org_name" id="customOrgNameInput" placeholder="<?=is_madrasa_mode() ? 'جامعہ دارالعلوم / مدرسہ اسلامیہ' : 'الحبیب ویلفیئر ٹرسٹ'?>" style="width:100%;font-size:13px;padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;">
                            </div>
                        </div>

                        <!-- Step 3: Granular Action Checkboxes -->
                        <div style="background:#ffffff;border:1.5px solid #fca5a5;border-radius:10px;padding:16px 20px;margin-bottom:20px;">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
                                <label style="font-size:14px;font-weight:700;color:#991b1b;margin:0;">
                                    2. کون کون سی سیٹنگز کو ری سیٹ / ڈیفالٹ کرنا ہے؟ (Select Action Items) *
                                </label>
                                <div style="display:flex;gap:6px;">
                                    <button type="button" onclick="toggleAllDangerCheckboxes(true)" class="btn secondary" style="font-size:11.5px;padding:4px 9px;">☑️ تمام منتخب کریں (Select All)</button>
                                    <button type="button" onclick="toggleAllDangerCheckboxes(false)" class="btn secondary" style="font-size:11.5px;padding:4px 9px;">◻️ تمام غیر منتخب کریں (Uncheck All)</button>
                                </div>
                            </div>

                            <div style="display:flex;flex-direction:column;gap:10px;">
                                <!-- Item 1: Clear All Transactions -->
                                <label style="display:flex;align-items:flex-start;gap:12px;background:#fef2f2;border:1px solid #fecaca;padding:12px 14px;border-radius:8px;cursor:pointer;">
                                    <input type="checkbox" name="reset_transactions" value="1" id="cb_reset_tx" style="margin-top:3px;width:18px;height:18px;accent-color:#dc2626;">
                                    <div style="flex:1;">
                                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                            <b style="color:#991b1b;font-size:13.5px;">تمام مالیاتی ٹرانزیکشنز و واؤچرز کا صفایا (Clear All Transactions &amp; Vouchers)</b>
                                            <span class="badge badge-red" style="font-size:11px;">خطرناک / Data Wipe</span>
                                        </div>
                                        <p style="margin:2px 0 0;font-size:12px;color:#7f1d1d;">
                                            تمام آمدنی و اخراجات کے واؤچرز، رسیدات، جرنل انٹریز، اور منسلک تصاویر مکمل حذف ہو جائیں گی۔ واؤچر نمبر دوبارہ 1 سے شروع ہوں گے۔
                                        </p>
                                    </div>
                                </label>

                                <!-- Item 2: Reset Identity -->
                                <label style="display:flex;align-items:flex-start;gap:12px;background:#f8fafc;border:1px solid #e2e8f0;padding:12px 14px;border-radius:8px;cursor:pointer;">
                                    <input type="checkbox" name="reset_identity" value="1" id="cb_reset_id" style="margin-top:3px;width:18px;height:18px;accent-color:#2563eb;">
                                    <div style="flex:1;">
                                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                            <b style="color:#1e293b;font-size:13.5px;">ادارے کا نام اور موڈ ری سیٹ کریں (Reset Org Name &amp; Mode)</b>
                                            <span class="badge badge-blue" style="font-size:11px;">Identity Setup</span>
                                        </div>
                                        <p style="margin:2px 0 0;font-size:12px;color:#475569;">
                                            ادارے کی شناخت، سب ٹائٹل اور سسٹم ورک فلو کو منتخب کردہ موڈ (مدرسہ یا ٹرسٹ) کے ڈیفالٹ پر منتقل کر دیا جائے گا۔
                                        </p>
                                    </div>
                                </label>

                                <!-- Item 3: Reset Funds & Accounts -->
                                <label style="display:flex;align-items:flex-start;gap:12px;background:#f8fafc;border:1px solid #e2e8f0;padding:12px 14px;border-radius:8px;cursor:pointer;">
                                    <input type="checkbox" name="reset_funds_accounts" value="1" id="cb_reset_funds" style="margin-top:3px;width:18px;height:18px;accent-color:#16a34a;">
                                    <div style="flex:1;">
                                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                            <b style="color:#1e293b;font-size:13.5px;">بنیادی فنڈز اور ڈیفالٹ فنڈ فعال کریں (Reset Mode Funds &amp; Default Fund)</b>
                                            <span class="badge badge-green" style="font-size:11px;">Funds Setup</span>
                                        </div>
                                        <p style="margin:2px 0 0;font-size:12px;color:#475569;">
                                            منتخب موڈ کے بنیادی فنڈ (Madrasa General Fund یا Trust General Fund) کو سسٹم کا مرکزی ڈیفالٹ فنڈ مقرر کر دیا جائے گا۔
                                        </p>
                                    </div>
                                </label>

                                <!-- Item 4: Reset Print Signatures -->
                                <label style="display:flex;align-items:flex-start;gap:12px;background:#f8fafc;border:1px solid #e2e8f0;padding:12px 14px;border-radius:8px;cursor:pointer;">
                                    <input type="checkbox" name="reset_print_signatures" value="1" id="cb_reset_signs" style="margin-top:3px;width:18px;height:18px;accent-color:#7c3aed;">
                                    <div style="flex:1;">
                                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                            <b style="color:#1e293b;font-size:13.5px;">رسیدات و واؤچرز کے دستخطی عہدے ری سیٹ کریں (Reset Print Signatures)</b>
                                            <span class="badge secondary" style="font-size:11px;background:#f3e8ff;color:#6b21a8;border-color:#d8b4fe;">Signatures</span>
                                        </div>
                                        <p style="margin:2px 0 0;font-size:12px;color:#475569;">
                                            پرنٹ رسیدات اور واؤچرز کے تینوں دستخطی خانے منتخب موڈ کے متعلقہ عہدوں (مثلاً: مہتمم / صدر یا چیئرمین / ڈائریکٹر) پر سیٹ ہو جائیں گے۔
                                        </p>
                                    </div>
                                </label>

                                <!-- Item 5: Reset Donation Appeal -->
                                <label style="display:flex;align-items:flex-start;gap:12px;background:#f8fafc;border:1px solid #e2e8f0;padding:12px 14px;border-radius:8px;cursor:pointer;">
                                    <input type="checkbox" name="reset_donation_appeal" value="1" id="cb_reset_appeal" style="margin-top:3px;width:18px;height:18px;accent-color:#d97706;">
                                    <div style="flex:1;">
                                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                            <b style="color:#1e293b;font-size:13.5px;">آن لائن ڈونیشن پورٹل کی اپیل ری سیٹ کریں (Reset Public Donation Appeal)</b>
                                            <span class="badge secondary" style="font-size:11px;background:#fef3c7;color:#92400e;border-color:#fde68a;">Donation Portal</span>
                                        </div>
                                        <p style="margin:2px 0 0;font-size:12px;color:#475569;">
                                            آن لائن ڈونیشن پیج (donate.php) پر عوام کے لیے لکھی گئی اپیل اور پیغام کو منتخب موڈ کے مطابق ری سیٹ کر دیا جائے گا۔
                                        </p>
                                    </div>
                                </label>

                                <!-- Item 6: Clear Test Donors & Beneficiaries -->
                                <label style="display:flex;align-items:flex-start;gap:12px;background:#fff1f2;border:1px solid #fecdd3;padding:12px 14px;border-radius:8px;cursor:pointer;">
                                    <input type="checkbox" name="reset_donors_students" value="1" id="cb_reset_donors" style="margin-top:3px;width:18px;height:18px;accent-color:#e11d48;">
                                    <div style="flex:1;">
                                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                            <b style="color:#9f1239;font-size:13.5px;">ٹیسٹ معاونین و طلبہ/مستحقین کی فہرست صاف کریں (Clear Test Donors &amp; Students)</b>
                                            <span class="badge badge-red" style="font-size:11px;">اختیاری / Test Data Wipe</span>
                                        </div>
                                        <p style="margin:2px 0 0;font-size:12px;color:#881337;">
                                            سسٹم میں موجود تمام آزمائشی معاونین (<?=$donorsCount?>) اور طلبہ/مستحقین (<?=$beneficiariesCount?>) کا ڈیٹا ڈیلیٹ ہو جائے گا۔
                                        </p>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- Step 4: Security Authorization & Submit -->
                        <div style="background:#ffffff;border:1.5px solid #ef4444;border-radius:10px;padding:18px 20px;">
                            <div style="margin-bottom:14px;">
                                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-weight:700;color:#991b1b;font-size:13.5px;">
                                    <input type="checkbox" name="confirm_danger" id="confirmDangerCheck" required style="width:19px;height:19px;accent-color:#dc2626;">
                                    <span class="ur">میں تصدیق کرتا ہوں کہ اوپر منتخب کردہ تمام ایکشنز کو لائیو سسٹم پر لاگو کیا جائے۔ (I authorize applying the selected changes).</span>
                                </label>
                            </div>

                            <div style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;">
                                <div class="form-group" style="flex:1;min-width:260px;margin-bottom:0;">
                                    <label style="color:#991b1b;font-weight:700;">Live Admin Password <span class="ur">(ایڈمن لاگ ان پاس ورڈ)</span> *</label>
                                    <input type="password" name="admin_password" required placeholder="Enter live Admin password to authorize" style="border-color:#fca5a5;">
                                </div>

                                <div>
                                    <button class="btn danger" type="submit" style="padding:11px 24px;font-weight:700;font-size:14px;background:#dc2626;border-color:#b91c1c;box-shadow:0 3px 6px rgba(220,38,38,0.25);">
                                        🗑️ Apply Selected Resets &amp; Defaults <span class="ur">(منتخب کردہ ترتیبات لاگو کریں)</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </article>
        </div>

        <script>
        const dangerModeConfig = {
            madrasa: {
                badgeClass: "badge badge-green",
                badgeText: "🕌 دینی مدرسہ موڈ",
                orgName: "جامعہ دارالعلوم / مدرسہ اسلامیہ",
                subtitle: "تعلیم، تربیت اور دینی خدمات",
                fund: "Madrasa General Fund (مدرسہ عمومی فنڈ - MGF)",
                signatures: "تیار کنندہ / کیشیئر | اکاؤنٹنٹ / ناظمِ مالیات | مہتمم / صدر / مجاز دستخط",
                appeal: "دینی و تعلیمی خدمات میں ہمارا ساتھ دیں (یتامیٰ و مستحق طلباء کی کفالت)"
            },
            trust: {
                badgeClass: "badge badge-blue",
                badgeText: "🏢 فلاحی ٹرسٹ موڈ",
                orgName: "الحبیب ویلفیئر ٹرسٹ",
                subtitle: "فلاح و بہبود اور انسانی خدمت",
                fund: "Trust General Fund (ٹرسٹ جنرل فنڈ - TGF)",
                signatures: "Prepared By (تیار کنندہ) | Accountant / Cashier (اکاؤنٹنٹ) | Director / Chairman (دستخط مجاز اتھارٹی)",
                appeal: "فلاحی و انسانی خدمات میں ہمارا ساتھ دیں (راشن، میڈیکل اور بیوہ کفالت)"
            }
        };

        function onDangerModeChange(mode) {
            const cfg = dangerModeConfig[mode] || dangerModeConfig.madrasa;
            
            // Highlight active mode card
            const lblM = document.getElementById('labelModeMadrasa');
            const lblT = document.getElementById('labelModeTrust');
            if (lblM && lblT) {
                if (mode === 'madrasa') {
                    lblM.style.borderColor = '#16a34a';
                    lblM.style.background = '#f0fdf4';
                    lblT.style.borderColor = '#e2e8f0';
                    lblT.style.background = '#f8fafc';
                } else {
                    lblT.style.borderColor = '#2563eb';
                    lblT.style.background = '#eff6ff';
                    lblM.style.borderColor = '#e2e8f0';
                    lblM.style.background = '#f8fafc';
                }
            }

            // Update Preview fields
            const badge = document.getElementById('dangerModeBadge');
            if (badge) {
                badge.className = cfg.badgeClass;
                badge.innerText = cfg.badgeText;
            }
            const elName = document.getElementById('previewOrgName');
            if (elName) elName.innerText = cfg.orgName;
            const elSub = document.getElementById('previewOrgSubtitle');
            if (elSub) elSub.innerText = cfg.subtitle;
            const elFund = document.getElementById('previewFund');
            if (elFund) elFund.innerText = cfg.fund;
            const elSigns = document.getElementById('previewSignatures');
            if (elSigns) elSigns.innerText = cfg.signatures;
            const elApp = document.getElementById('previewAppeal');
            if (elApp) elApp.innerText = cfg.appeal;

            const nameInput = document.getElementById('customOrgNameInput');
            if (nameInput) {
                nameInput.placeholder = cfg.orgName;
            }
        }

        function toggleAllDangerCheckboxes(check) {
            const ids = ['cb_reset_tx', 'cb_reset_id', 'cb_reset_funds', 'cb_reset_signs', 'cb_reset_appeal', 'cb_reset_donors'];
            ids.forEach(function(id) {
                const el = document.getElementById(id);
                if (el) el.checked = check;
            });
        }

        function validateDangerSubmit() {
            const ids = ['cb_reset_tx', 'cb_reset_id', 'cb_reset_funds', 'cb_reset_signs', 'cb_reset_appeal', 'cb_reset_donors'];
            let anyChecked = false;
            ids.forEach(function(id) {
                const el = document.getElementById(id);
                if (el && el.checked) anyChecked = true;
            });

            if (!anyChecked) {
                alert('براہ کرم کم از کم ایک آپشن منتخب کریں جسے ری سیٹ یا ڈیفالٹ کرنا ہو۔\n(Please check at least one reset option).');
                return false;
            }

            const isTx = document.getElementById('cb_reset_tx')?.checked;
            const msg = isTx
                ? '⚠️ تنبیہ: آپ نے تمام مالیاتی ٹرانزیکشنز و واؤچرز کا صفایا منتخب کیا ہے۔ یہ عمل ناقابل واپسی ہوگا!\n\nکیا آپ منتخب کردہ سیٹنگز کو لاگو کرنے کی تصدیق کرتے ہیں؟'
                : 'کیا آپ منتخب کردہ موڈ کی ترتیبات اور ڈیفالٹس لاگو کرنے کی تصدیق کرتے ہیں؟';
            return confirm(msg);
        }
        </script>
        <?php endif; ?>

        <!-- Modal: Restore Database Backup -->
        <div class="modal-overlay" id="restoreBackupModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(15,23,42,0.65);z-index:99999;align-items:center;justify-content:center;padding:16px;">
            <div class="modal-card" style="background:#ffffff;border-radius:12px;max-width:540px;width:100%;box-shadow:0 20px 25px -5px rgba(0,0,0,0.25);overflow:hidden;border:1px solid #cbd5e1;">
                <div style="background:#eff6ff;border-bottom:1px solid #bfdbfe;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span style="font-size:22px;">📤</span>
                        <b style="font-size:15px;color:#1e40af;">Upload & Restore Backup <span class="ur">(ڈیٹا بیس بیک اپ بحال کریں)</span></b>
                    </div>
                    <button type="button" onclick="closeRestoreModal()" style="background:none;border:none;font-size:24px;cursor:pointer;color:#64748b;line-height:1;">&times;</button>
                </div>
                <form method="post" action="settings.php?tab=backups" enctype="multipart/form-data" style="padding:22px;" onsubmit="return confirm('⚠️ WARNING!\nAre you sure you want to restore this database backup?\nAll current database records will be replaced with the data from the selected backup file.');">
                    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                    <input type="hidden" name="action" value="restore_db_backup">

                    <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px 14px;margin-bottom:18px;font-size:12.5px;color:#991b1b;line-height:1.6;">
                        <strong>⚠️ انتباہ / Caution:</strong><br>
                        بیک اپ فائل اپلوڈ کرنے سے موجودہ ڈیٹا بیس اسی فائل کے ڈیٹا کے مطابق تبدیل ہو جائے گا۔ احتیاطاً اس عمل سے قبل موجودہ سسٹم کا بیک اپ (Download Backup) ضرور محفوظ فرما لیں۔
                    </div>

                    <div class="form-group" style="margin-bottom:16px;">
                        <label style="font-weight:700;display:block;margin-bottom:6px;font-size:13px;color:#1e293b;">
                            Select .sql Backup File <span class="ur">(بیک اپ فائل منتخب کریں)</span> *
                        </label>
                        <input type="file" name="backup_file" accept=".sql" required style="width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;background:#f8fafc;">
                        <small style="color:#64748b;font-size:11px;display:block;margin-top:4px;">Only official .sql files generated by this system are supported.</small>
                    </div>

                    <div class="form-group" style="margin-bottom:20px;">
                        <label style="font-weight:700;display:block;margin-bottom:6px;font-size:13px;color:#1e293b;">
                            Super Admin Live Password <span class="ur">(ایڈمن پاس ورڈ درج کریں)</span> *
                        </label>
                        <input type="password" name="admin_password" required placeholder="Enter your live password to authorize restore" style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13.5px;">
                        <small style="color:#64748b;font-size:11px;display:block;margin-top:4px;">سیکیورٹی کی خاطر بیک اپ بحال کرنے کے لیے ایڈمن کا لائیو پاس ورڈ لازمی ہے۔</small>
                    </div>

                    <div style="display:flex;justify-content:flex-end;gap:10px;padding-top:14px;border-top:1px solid #e2e8f0;">
                        <button type="button" class="btn secondary" onclick="closeRestoreModal()">Cancel (منسوخ)</button>
                        <button type="submit" class="btn success" style="background:#16a34a;border-color:#16a34a;font-weight:700;color:#fff;">
                            🚀 Upload & Restore Backup <span class="ur">(بیک اپ بحال کریں)</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal: Restore Saved Backup from Archive -->
        <div class="modal-overlay" id="restoreSavedBackupModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(15,23,42,0.65);z-index:99999;align-items:center;justify-content:center;padding:16px;">
            <div class="modal-card" style="background:#ffffff;border-radius:12px;max-width:520px;width:100%;box-shadow:0 20px 25px -5px rgba(0,0,0,0.25);overflow:hidden;border:1px solid #cbd5e1;">
                <div style="background:#eff6ff;border-bottom:1px solid #bfdbfe;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span style="font-size:22px;">🔄</span>
                        <b style="font-size:15px;color:#1e40af;">Restore Archived Backup <span class="ur">(محفوظ شدہ بیک اپ بحال کریں)</span></b>
                    </div>
                    <button type="button" onclick="closeRestoreSavedModal()" style="background:none;border:none;font-size:24px;cursor:pointer;color:#64748b;line-height:1;">&times;</button>
                </div>
                <form method="post" action="settings.php?tab=backups" style="padding:22px;" onsubmit="return confirm('⚠️ WARNING!\nAre you sure you want to restore this archive backup?\nCurrent database records will be rolled back to this point.');">
                    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                    <input type="hidden" name="action" value="restore_saved_backup">
                    <input type="hidden" name="backup_filename" id="restoreSavedFileNameInput" value="">

                    <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px 14px;margin-bottom:18px;font-size:12.5px;color:#991b1b;line-height:1.6;">
                        <strong>⚠️ انتباہ / Caution:</strong><br>
                        آپ درج ذیل محفوظ شدہ بیک اپ فائل کو سسٹم میں بحال کرنے لگے ہیں:<br>
                        <b id="restoreSavedFileNameDisplay" style="font-family:monospace;display:inline-block;margin-top:4px;color:#0f172a;background:#fee2e2;padding:3px 8px;border-radius:4px;"></b><br>
                        اس عمل سے موجودہ تمام ریکارڈز اس بیک اپ فائل کے ڈیٹا کے مطابق بدل جائیں گے۔
                    </div>

                    <div class="form-group" style="margin-bottom:20px;">
                        <label style="font-weight:700;display:block;margin-bottom:6px;font-size:13px;color:#1e293b;">
                            Super Admin Live Password <span class="ur">(ایڈمن پاس ورڈ درج کریں)</span> *
                        </label>
                        <input type="password" name="admin_password" required placeholder="Enter your live password to authorize restore" style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13.5px;">
                        <small style="color:#64748b;font-size:11px;display:block;margin-top:4px;">سیکیورٹی کی خاطر بیک اپ بحال کرنے کے لیے ایڈمن کا لائیو پاس ورڈ لازمی ہے۔</small>
                    </div>

                    <div style="display:flex;justify-content:flex-end;gap:10px;padding-top:14px;border-top:1px solid #e2e8f0;">
                        <button type="button" class="btn secondary" onclick="closeRestoreSavedModal()">Cancel (منسوخ)</button>
                        <button type="submit" class="btn success" style="background:#2563eb;border-color:#2563eb;font-weight:700;color:#fff;">
                            🚀 Confirm &amp; Restore Backup <span class="ur">(بیک اپ بحال کریں)</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?=render_app_footer($madrasaName)?>
    </main>
</div>
<script>
function openRestoreModal() {
    var m = document.getElementById('restoreBackupModal');
    if (m) m.style.display = 'flex';
}
function closeRestoreModal() {
    var m = document.getElementById('restoreBackupModal');
    if (m) m.style.display = 'none';
}
function openRestoreSavedModal(filename) {
    document.getElementById('restoreSavedFileNameInput').value = filename;
    document.getElementById('restoreSavedFileNameDisplay').textContent = filename;
    var m = document.getElementById('restoreSavedBackupModal');
    if (m) m.style.display = 'flex';
}
function closeRestoreSavedModal() {
    var m = document.getElementById('restoreSavedBackupModal');
    if (m) m.style.display = 'none';
}
function copyCronCmd() {
    var inp = document.getElementById('cronCmdInput');
    if (inp) {
        inp.select();
        inp.setSelectionRange(0, 99999);
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(inp.value).then(function() {
                var btn = document.getElementById('copyBtnText');
                if (btn) {
                    var old = btn.textContent;
                    btn.textContent = 'Copied! (کاپی ہو گیا)';
                    setTimeout(function() { btn.textContent = old; }, 2500);
                }
            });
        } else {
            document.execCommand('copy');
            var btn = document.getElementById('copyBtnText');
            if (btn) {
                var old = btn.textContent;
                btn.textContent = 'Copied! (کاپی ہو گیا)';
                setTimeout(function() { btn.textContent = old; }, 2500);
            }
        }
    }
}
window.addEventListener('click', function(e) {
    var m1 = document.getElementById('restoreBackupModal');
    var m2 = document.getElementById('restoreSavedBackupModal');
    if (m1 && e.target === m1) closeRestoreModal();
    if (m2 && e.target === m2) closeRestoreSavedModal();
});
window.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeRestoreModal();
        closeRestoreSavedModal();
    }
});

document.getElementById('orgLogoInput')?.addEventListener('change', function(e) {
    var file = e.target.files[0];
    if (file) {
        var reader = new FileReader();
        reader.onload = function(evt) {
            var container = document.getElementById('logoPreviewContainer');
            if (container) {
                container.innerHTML = '<img id="logoPreviewImg" src="' + evt.target.result + '" alt="Preview" style="max-width:100%;max-height:100%;object-fit:contain;">';
            }
        };
        reader.readAsDataURL(file);
    }
});

function copyPortalLink() {
    var url = '<?=e(get_app_base_url())?>/donate.php';
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(url).then(function() {
            alert('✓ آن لائن ڈونیشن پورٹل کا لنک کاپی ہو گیا ہے!\n\n' + url + '\n\nاسے واٹس ایپ یا فیس بک پر شیئر کریں۔');
        });
    } else {
        prompt('آن لائن پورٹل کا لنک کاپی کریں:', url);
    }
}
</script>
</body>
</html>
