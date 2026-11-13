<?php
declare(strict_types=1);

if (defined('BOOTSTRAP_LOADED')) {
    return;
}
define('BOOTSTRAP_LOADED', true);

$configFile = __DIR__ . '/../config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Configuration missing. Copy config/config.example.php to config/config.php and set database credentials.');
}
$config = require $configFile;
date_default_timezone_set('Asia/Karachi');

session_name($config['app']['session_name'] ?? 'madrasa_accounts_session');
$secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secureCookie, 'httponly' => true, 'samesite' => 'Lax']);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'Cashier') {
    $_SESSION['user']['role'] = 'User / Cashier';
}

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['db']['host'], $config['db']['name'], $config['db']['charset'] ?? 'utf8mb4');
try {
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    exit('<div style="font-family:sans-serif;max-width:620px;margin:50px auto;padding:24px;border:1px solid #fca5a5;border-radius:10px;background:#fef2f2;color:#991b1b;line-height:1.6;">'
        . '<h3 style="margin-top:0;color:#b91c1c;">Database Connection Failed</h3>'
        . '<p>Could not connect to database <code>' . htmlspecialchars($config['db']['name']) . '</code> on <code>' . htmlspecialchars($config['db']['host']) . '</code>.</p>'
        . '<p><strong>Hostinger MySQL Error:</strong><br><code style="background:#fee2e2;padding:4px 8px;border-radius:4px;display:inline-block;margin-top:4px;">' . htmlspecialchars($e->getMessage()) . '</code></p>'
        . '<p style="font-size:14px;color:#4b5563;margin-top:16px;"><strong>Troubleshooting:</strong><br>'
        . '1. Ensure database <code>' . htmlspecialchars($config['db']['name']) . '</code> is created in Hostinger hPanel &rarr; Databases.<br>'
        . '2. Ensure database user <code>' . htmlspecialchars($config['db']['user']) . '</code> has full permissions.<br>'
        . '3. Verify credentials in <code>config/config.php</code>.</p>'
        . '</div>');
}

// Auto-seed madrasa accounts & Ensure roles & attachments schema (version-cached to avoid 30+ DDL queries on every page request)
function run_schema_and_seed_migrations(PDO $pdo): void {
    try {
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'accounts'")->fetch();
    if ($tableCheck) {
        $incomeCount = (int)$pdo->query("SELECT COUNT(*) FROM accounts WHERE account_type = 'income'")->fetchColumn();
        if ($incomeCount <= 1) {
            $pdo->exec("INSERT IGNORE INTO accounts (account_code, account_name, account_type, is_cash_or_bank) VALUES
                ('4000', 'عام عطیات (General Donations)', 'income', 0),
                ('4010', 'زکوٰۃ فنڈ (Zakat Receipts)', 'income', 0),
                ('4020', 'صدقات و خیرات (Sadqat & Charity)', 'income', 0),
                ('4030', 'فطرانہ و فدیہ (Fitrana & Fidya)', 'income', 0),
                ('4040', 'تعلیمی فیس و داخلہ (Tuition / Admission Fees)', 'income', 0),
                ('4050', 'ماہانہ کفالت طلبہ (Monthly Student Sponsorship)', 'income', 0),
                ('4060', 'کتب و مکتبہ آمدنی (Books & Stationery Sale)', 'income', 0),
                ('4070', 'قربانی کی کھالیں (Qurbani Hides & Skin)', 'income', 0),
                ('4080', 'تعمیراتی چندہ (Construction Donations)', 'income', 0),
                ('4090', 'متفرق آمدنی (Miscellaneous Income)', 'income', 0)");
            
            $pdo->exec("INSERT IGNORE INTO accounts (account_code, account_name, account_type, is_cash_or_bank) VALUES
                ('5000', 'عمومی و متفرق اخراجات (General Expenses)', 'expense', 0),
                ('5010', 'اساتذہ و عملہ کی تنخواہیں (Teachers & Staff Salaries)', 'expense', 0),
                ('5020', 'طلبہ کا کھانا و راشن (Student Food & Mess / Kitchen)', 'expense', 0),
                ('5030', 'بجلی، گیس، پانی کے بلات (Electricity, Gas, Water)', 'expense', 0),
                ('5040', 'کتب، کاپیاں و تعلیمی سامان (Books & Stationery)', 'expense', 0),
                ('5050', 'طلبہ کا علاج و وظائف (Student Medical & Stipends)', 'expense', 0),
                ('5060', 'عمارت کی مرمت و رنگ و روغن (Building Maintenance)', 'expense', 0),
                ('5070', 'سفر و ٹرانسپورٹیشن (Travel & Transportation)', 'expense', 0)");
        }
    }

    // Ensure attachments table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS attachments (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        voucher_id BIGINT UNSIGNED NULL,
        original_name VARCHAR(255) NOT NULL,
        stored_name VARCHAR(255) NOT NULL,
        mime_type VARCHAR(120) NOT NULL,
        file_size BIGINT UNSIGNED NOT NULL,
        uploaded_by BIGINT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_attachment_voucher (voucher_id)
    ) ENGINE=InnoDB;");

    // Ensure uploads directory exists
    $uploadDir = __DIR__ . '/../public/uploads/receipts';
    $logoDir = __DIR__ . '/../public/uploads/logo';
    $tasksDir = __DIR__ . '/../public/uploads/tasks';
    if (!is_dir($logoDir)) {
        @mkdir($logoDir, 0755, true);
    }
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    if (!is_dir($tasksDir)) {
        @mkdir($tasksDir, 0755, true);
    }

    // Update role names & descriptions for Approver/Checker workflow
    $roleCheck = $pdo->query("SHOW TABLES LIKE 'roles'")->fetch();
    if ($roleCheck) {
        $pdo->exec("UPDATE roles SET description = 'Full system access & Final Approvals (مکمل اختیارات)' WHERE name = 'Super Admin'");
        $pdo->exec("UPDATE roles SET name = 'Approver / Checker', description = 'Review and approve pending vouchers, audit receipts (واؤچرز منظوری و پڑتال)' WHERE name = 'Administrator'");
        $pdo->exec("UPDATE roles SET name = 'User / Cashier', description = 'Record income and expenses - Pending approval (وصولی و اخراجات کا اندراج)' WHERE name = 'Accountant'");
        $pdo->exec("UPDATE roles SET description = 'Read-only access (صرف دیکھنے کی اجازت)' WHERE name = 'Viewer'");

        // Ensure User / Cashier exists
        $userCashierRoleId = (int)$pdo->query("SELECT id FROM roles WHERE name = 'User / Cashier'")->fetchColumn();
        if ($userCashierRoleId <= 0) {
            $pdo->exec("INSERT IGNORE INTO roles (name, description) VALUES ('User / Cashier', 'Record income and expenses - Pending approval (وصولی و اخراجات کا اندراج)')");
            $userCashierRoleId = (int)$pdo->query("SELECT id FROM roles WHERE name = 'User / Cashier'")->fetchColumn();
        }

        // Ensure Regional Director role exists for Provincial Directorate (KPK, Punjab, etc.)
        $regDirRoleId = (int)$pdo->query("SELECT id FROM roles WHERE name = 'Regional Director'")->fetchColumn();
        if ($regDirRoleId <= 0) {
            $pdo->exec("INSERT IGNORE INTO roles (name, description) VALUES ('Regional Director', 'صوبائی ڈائریکٹر — ریجنل ٹیم مینجمنٹ، واؤچر منظوری و فنڈ نگرانی')");
        }

        // Migrate and remove redundant duplicate 'Cashier' role
        $oldCashierRoleId = (int)$pdo->query("SELECT id FROM roles WHERE name = 'Cashier'")->fetchColumn();
        if ($oldCashierRoleId > 0) {
            if ($userCashierRoleId > 0) {
                $pdo->exec("UPDATE users SET role_id = {$userCashierRoleId} WHERE role_id = {$oldCashierRoleId}");
            }
            $pdo->exec("DELETE FROM role_permissions WHERE role_id = {$oldCashierRoleId}");
            $pdo->exec("DELETE FROM roles WHERE id = {$oldCashierRoleId}");
        }
    }

    // Ensure accounts table has user_id column
    $accCheck = $pdo->query("SHOW TABLES LIKE 'accounts'")->fetch();
    if ($accCheck) {
        $colCheck = $pdo->query("SHOW COLUMNS FROM accounts LIKE 'user_id'")->fetch();
        if (!$colCheck) {
            $pdo->exec("ALTER TABLE accounts ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER parent_id, ADD INDEX idx_account_user (user_id)");
        }
    }

    // Ensure donors table has fund_id column for category linking
    $donorsCheck = $pdo->query("SHOW TABLES LIKE 'donors'")->fetch();
    if ($donorsCheck) {
        $colCheck = $pdo->query("SHOW COLUMNS FROM donors LIKE 'fund_id'")->fetch();
        if (!$colCheck) {
            $pdo->exec("ALTER TABLE donors ADD COLUMN fund_id BIGINT UNSIGNED NULL AFTER notes, ADD INDEX idx_donor_fund (fund_id)");
        }
    }

    // Ensure tasks and task_logs tables exist for project/task tracking
    $pdo->exec("CREATE TABLE IF NOT EXISTS tasks (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        task_code VARCHAR(30) NOT NULL UNIQUE,
        title VARCHAR(255) NOT NULL,
        description TEXT NULL,
        assigned_to BIGINT UNSIGNED NOT NULL,
        created_by BIGINT UNSIGNED NOT NULL,
        priority ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
        status ENUM('pending','in_progress','on_hold','completed','cancelled') NOT NULL DEFAULT 'pending',
        progress_percent INT UNSIGNED NOT NULL DEFAULT 0,
        estimated_budget DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        start_date DATE NULL,
        due_date DATE NULL,
        fund_id BIGINT UNSIGNED NULL,
        completed_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_task_assigned (assigned_to),
        INDEX idx_task_status (status),
        INDEX idx_task_priority (priority),
        INDEX idx_task_fund (fund_id)
    ) ENGINE=InnoDB;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS task_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        task_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        progress_percent INT UNSIGNED NOT NULL DEFAULT 0,
        status ENUM('pending','in_progress','on_hold','completed','cancelled') NULL,
        report_text TEXT NOT NULL,
        photo_attachment VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_tlog_task (task_id),
        INDEX idx_tlog_user (user_id),
        CONSTRAINT fk_tlog_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;");

    // Ensure vouchers table has task_id column
    $vouchCheck = $pdo->query("SHOW TABLES LIKE 'vouchers'")->fetch();
    if ($vouchCheck) {
        $colCheckTask = $pdo->query("SHOW COLUMNS FROM vouchers LIKE 'task_id'")->fetch();
        if (!$colCheckTask) {
            $pdo->exec("ALTER TABLE vouchers ADD COLUMN task_id BIGINT UNSIGNED NULL AFTER fund_id, ADD INDEX idx_voucher_task (task_id)");
        }
    }

    // Ensure tasks table has location/GPS columns
    $taskCheck = $pdo->query("SHOW TABLES LIKE 'tasks'")->fetch();
    if ($taskCheck) {
        $colLoc = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'project_location'")->fetch();
        if (!$colLoc) {
            $pdo->exec("ALTER TABLE tasks 
                ADD COLUMN project_location VARCHAR(255) NULL AFTER description,
                ADD COLUMN target_lat DECIMAL(10, 8) NULL AFTER project_location,
                ADD COLUMN target_lng DECIMAL(11, 8) NULL AFTER target_lat,
                ADD COLUMN radius_meters INT UNSIGNED NOT NULL DEFAULT 300 AFTER target_lng");
        }

        $colPhoto = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'before_photo'")->fetch();
        if (!$colPhoto) {
            $pdo->exec("ALTER TABLE tasks 
                ADD COLUMN before_photo VARCHAR(255) NULL AFTER radius_meters,
                ADD COLUMN after_photo VARCHAR(255) NULL AFTER before_photo");
        }
    }

    // Ensure users table has phone_number column
    $userCheck = $pdo->query("SHOW TABLES LIKE 'users'")->fetch();
    if ($userCheck) {
        $colPhone = $pdo->query("SHOW COLUMNS FROM users LIKE 'phone_number'")->fetch();
        if (!$colPhone) {
            $pdo->exec("ALTER TABLE users ADD COLUMN phone_number VARCHAR(30) NULL AFTER email");
        }
    }

    // Ensure task_logs table has GPS tracking columns
    $tlogCheck = $pdo->query("SHOW TABLES LIKE 'task_logs'")->fetch();
    if ($tlogCheck) {
        $colLat = $pdo->query("SHOW COLUMNS FROM task_logs LIKE 'latitude'")->fetch();
        if (!$colLat) {
            $pdo->exec("ALTER TABLE task_logs 
                ADD COLUMN latitude DECIMAL(10, 8) NULL AFTER photo_attachment,
                ADD COLUMN longitude DECIMAL(11, 8) NULL AFTER latitude,
                ADD COLUMN location_accuracy DECIMAL(8, 2) NULL AFTER longitude,
                ADD COLUMN location_address VARCHAR(255) NULL AFTER location_accuracy,
                ADD COLUMN is_onsite TINYINT(1) NULL AFTER location_address,
                ADD COLUMN distance_meters INT UNSIGNED NULL AFTER is_onsite,
                ADD INDEX idx_tlog_onsite (is_onsite)");
        }
    }

    // Ensure user_permissions table exists for granular permissions
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_permissions (
      user_id BIGINT UNSIGNED NOT NULL,
      permission_code VARCHAR(80) NOT NULL,
      PRIMARY KEY (user_id, permission_code),
      INDEX idx_up_user (user_id),
      CONSTRAINT fk_up_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    // Ensure notifications table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id BIGINT UNSIGNED NOT NULL,
      title VARCHAR(255) NOT NULL,
      message TEXT NOT NULL,
      link_url VARCHAR(255) NULL,
      type ENUM('info', 'warning', 'success', 'task') NOT NULL DEFAULT 'task',
      is_read TINYINT(1) NOT NULL DEFAULT 0,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_notif_user (user_id, is_read)
    ) ENGINE=InnoDB");

    // Ensure login_activities table exists for security audit
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_activities (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id BIGINT UNSIGNED NULL,
      username VARCHAR(100) NOT NULL,
      ip_address VARCHAR(45) NULL,
      user_agent VARCHAR(255) NULL,
      device_type VARCHAR(20) NOT NULL DEFAULT 'Desktop',
      status ENUM('success','failed') NOT NULL DEFAULT 'success',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_la_user (user_id),
      INDEX idx_la_created (created_at)
    ) ENGINE=InnoDB");

    // Ensure beneficiaries table exists for students / welfare records (Feature #3)
    $pdo->exec("CREATE TABLE IF NOT EXISTS beneficiaries (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      category ENUM('student', 'beneficiary', 'orphan', 'staff') NOT NULL DEFAULT 'student',
      reg_no VARCHAR(50) NULL,
      full_name VARCHAR(150) NOT NULL,
      father_name VARCHAR(150) NULL,
      department_class VARCHAR(100) NULL,
      gender ENUM('male', 'female') NOT NULL DEFAULT 'male',
      phone VARCHAR(50) NULL,
      address TEXT NULL,
      assigned_fund_id INT UNSIGNED NULL,
      assigned_donor_id BIGINT UNSIGNED NULL,
      monthly_stipend_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      status ENUM('active', 'graduated', 'inactive') NOT NULL DEFAULT 'active',
      admission_date DATE NULL,
      guardian_cnic VARCHAR(30) NULL,
      notes TEXT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_ben_cat (category),
      INDEX idx_ben_status (status),
      INDEX idx_ben_fund (assigned_fund_id)
    ) ENGINE=InnoDB");

    // Ensure donors table has pledge / recurring kafalat columns (Feature #1)
    try {
        $chkDonPledge = $pdo->query("SHOW COLUMNS FROM donors LIKE 'pledge_amount'")->fetch();
        if (!$chkDonPledge) {
            $pdo->exec("ALTER TABLE donors 
                ADD COLUMN pledge_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER notes,
                ADD COLUMN pledge_fund_id INT UNSIGNED NULL AFTER pledge_amount,
                ADD COLUMN pledge_frequency ENUM('monthly','annual','quarterly') NOT NULL DEFAULT 'monthly' AFTER pledge_fund_id,
                ADD COLUMN pledge_day TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER pledge_frequency,
                ADD COLUMN is_pledge_active TINYINT(1) NOT NULL DEFAULT 0 AFTER pledge_day,
                ADD COLUMN pledge_notes VARCHAR(255) NULL AFTER is_pledge_active,
                ADD INDEX idx_donor_pledge (is_pledge_active, pledge_day)");
        }
        $chkDonProof = $pdo->query("SHOW COLUMNS FROM donors LIKE 'proof_receipt'")->fetch();
        if (!$chkDonProof) {
            $pdo->exec("ALTER TABLE donors ADD COLUMN proof_receipt VARCHAR(255) NULL AFTER notes");
        }
    } catch (Throwable $e) {}

    // Ensure vouchers table has multi-currency, beneficiary and reconciliation columns (Features #3, #4, #5)
    try {
        $chkVouchCurr = $pdo->query("SHOW COLUMNS FROM vouchers LIKE 'foreign_currency'")->fetch();
        if (!$chkVouchCurr) {
            $pdo->exec("ALTER TABLE vouchers 
                ADD COLUMN foreign_currency VARCHAR(10) NULL DEFAULT 'PKR' AFTER total_amount,
                ADD COLUMN foreign_amount DECIMAL(12,2) NULL AFTER foreign_currency,
                ADD COLUMN exchange_rate DECIMAL(12,4) NULL AFTER foreign_amount,
                ADD COLUMN beneficiary_id BIGINT UNSIGNED NULL AFTER task_id,
                ADD COLUMN is_reconciled TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
                ADD COLUMN reconciled_at DATETIME NULL AFTER is_reconciled,
                ADD COLUMN reconciled_by BIGINT UNSIGNED NULL AFTER reconciled_at,
                ADD COLUMN bank_ref VARCHAR(100) NULL AFTER reconciled_by,
                ADD INDEX idx_vouch_recon (is_reconciled),
                ADD INDEX idx_vouch_ben (beneficiary_id)");
        }
    } catch (Throwable $e) {}

    // Ensure journal_entries table has reconciliation columns (Feature #5)
    try {
        $chkJeRecon = $pdo->query("SHOW COLUMNS FROM journal_entries LIKE 'is_reconciled'")->fetch();
        if (!$chkJeRecon) {
            $pdo->exec("ALTER TABLE journal_entries 
                ADD COLUMN is_reconciled TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
                ADD COLUMN reconciled_at DATETIME NULL AFTER is_reconciled,
                ADD COLUMN bank_ref VARCHAR(100) NULL AFTER reconciled_at,
                ADD INDEX idx_je_recon (is_reconciled)");
        }
    } catch (Throwable $e) {}

    // Ensure users table has last_seen_at column for Live Login Activity & Device Tracker
    try {
        $chkLastSeen = $pdo->query("SHOW COLUMNS FROM users LIKE 'last_seen_at'")->fetch();
        if (!$chkLastSeen) {
            $pdo->exec("ALTER TABLE users ADD COLUMN last_seen_at DATETIME NULL AFTER last_login_at, ADD INDEX idx_user_last_seen (last_seen_at)");
        }
    } catch (Throwable $e) {}

    // Ensure regions table exists for Provincial & Regional Directorate
    $pdo->exec("CREATE TABLE IF NOT EXISTS regions (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(100) NOT NULL,
      code VARCHAR(20) NOT NULL UNIQUE,
      director_id BIGINT UNSIGNED NULL,
      phone VARCHAR(50) NULL,
      address TEXT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_reg_active (is_active)
    ) ENGINE=InnoDB");

    // Ensure users table has region_id and supervisor_id
    try {
        $chkUserReg = $pdo->query("SHOW COLUMNS FROM users LIKE 'region_id'")->fetch();
        if (!$chkUserReg) {
            $pdo->exec("ALTER TABLE users 
                ADD COLUMN region_id INT UNSIGNED NULL AFTER role_id,
                ADD COLUMN supervisor_id BIGINT UNSIGNED NULL AFTER region_id,
                ADD INDEX idx_user_region (region_id),
                ADD INDEX idx_user_supervisor (supervisor_id)");
        }
    } catch (Throwable $e) {}

    // Ensure vouchers table has region_id
    try {
        $chkVouchReg = $pdo->query("SHOW COLUMNS FROM vouchers LIKE 'region_id'")->fetch();
        if (!$chkVouchReg) {
            $pdo->exec("ALTER TABLE vouchers 
                ADD COLUMN region_id INT UNSIGNED NULL AFTER fund_id,
                ADD INDEX idx_vouch_region (region_id)");
        }
    } catch (Throwable $e) {}

    // Ensure journal_entries table has region_id
    try {
        $chkJeReg = $pdo->query("SHOW COLUMNS FROM journal_entries LIKE 'region_id'")->fetch();
        if (!$chkJeReg) {
            $pdo->exec("ALTER TABLE journal_entries 
                ADD COLUMN region_id INT UNSIGNED NULL AFTER voucher_id,
                ADD INDEX idx_je_region (region_id)");
        }
    } catch (Throwable $e) {}

    // Ensure daily_cash_closings table exists
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS daily_cash_closings (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          account_id BIGINT UNSIGNED NOT NULL,
          user_id BIGINT UNSIGNED NOT NULL,
          region_id INT UNSIGNED NULL,
          closing_date DATE NOT NULL,
          system_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
          physical_cash DECIMAL(14,2) NOT NULL DEFAULT 0.00,
          difference DECIMAL(14,2) NOT NULL DEFAULT 0.00,
          status ENUM('balanced', 'short', 'excess') NOT NULL DEFAULT 'balanced',
          denominations JSON NULL,
          notes TEXT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_dcc_date (closing_date),
          INDEX idx_dcc_acc (account_id),
          INDEX idx_dcc_user (user_id)
        ) ENGINE=InnoDB");
    } catch (Throwable $e) {}

    // Ensure vouchers table has multi-currency columns
    try {
        $chkCurr = $pdo->query("SHOW COLUMNS FROM vouchers LIKE 'foreign_currency'")->fetch();
        if (!$chkCurr) {
            $pdo->exec("ALTER TABLE vouchers 
                ADD COLUMN foreign_currency VARCHAR(10) NULL AFTER total_amount,
                ADD COLUMN foreign_amount DECIMAL(14,2) NULL AFTER foreign_currency,
                ADD COLUMN exchange_rate DECIMAL(14,4) NULL AFTER foreign_amount");
        }
    } catch (Throwable $e) {}

    // One-time execution: Seed 2 sample provinces (KPK, Punjab) & replace test users with provincial director/cashier accounts
    $provSeeded = get_setting('provincial_hierarchy_seeded', '0');
    if ($provSeeded !== '1') {
        try {
            // 1. Seed KPK & Punjab regions if not exist
            $kpkCount = (int)$pdo->query("SELECT COUNT(*) FROM regions WHERE code = 'KPK'")->fetchColumn();
            if ($kpkCount === 0) {
                $pdo->exec("INSERT INTO regions (name, code, phone, address, is_active) VALUES ('خیبر پختونخوا (KPK)', 'KPK', '091-1111111', 'پشاور / کے پی کے ریجنل دفتر', 1)");
            }
            $pbCount = (int)$pdo->query("SELECT COUNT(*) FROM regions WHERE code = 'PUNJAB'")->fetchColumn();
            if ($pbCount === 0) {
                $pdo->exec("INSERT INTO regions (name, code, phone, address, is_active) VALUES ('پنجاب (Punjab)', 'PUNJAB', '042-2222222', 'لاہور / پنجاب ریجنل دفتر', 1)");
            }

            $kpkId = (int)$pdo->query("SELECT id FROM regions WHERE code = 'KPK'")->fetchColumn();
            $pbId = (int)$pdo->query("SELECT id FROM regions WHERE code = 'PUNJAB'")->fetchColumn();

            // 2. Identify Super Admin (Doctor Shireen) to protect
            $adminId = (int)$pdo->query("SELECT id FROM users WHERE username = 'admin' OR role_id IN (SELECT id FROM roles WHERE name='Super Admin') ORDER BY id ASC LIMIT 1")->fetchColumn();
            if ($adminId > 0) {
                // Reassign vouchers created by test users to admin so foreign keys are protected
                $pdo->exec("UPDATE vouchers SET created_by = {$adminId} WHERE created_by != {$adminId}");
                try { $pdo->exec("UPDATE tasks SET assigned_to = {$adminId} WHERE assigned_to IS NOT NULL AND assigned_to != {$adminId}"); } catch (Throwable $e) {}
                try { $pdo->exec("UPDATE tasks SET created_by = {$adminId} WHERE created_by != {$adminId}"); } catch (Throwable $e) {}
                try { $pdo->exec("UPDATE audit_logs SET user_id = {$adminId} WHERE user_id IS NOT NULL AND user_id != {$adminId}"); } catch (Throwable $e) {}
                try { $pdo->exec("UPDATE login_activities SET user_id = {$adminId} WHERE user_id IS NOT NULL AND user_id != {$adminId}"); } catch (Throwable $e) {}
                try { $pdo->exec("DELETE FROM push_subscriptions WHERE user_id != {$adminId}"); } catch (Throwable $e) {}
                $pdo->exec("DELETE FROM user_permissions WHERE user_id != {$adminId}");
                $pdo->exec("DELETE FROM user_funds WHERE user_id != {$adminId}");
                $pdo->exec("DELETE FROM accounts WHERE user_id IS NOT NULL AND user_id != {$adminId}");
                $pdo->exec("DELETE FROM users WHERE id != {$adminId}");
            }

            // 3. Create dummy users for KPK and Punjab
            $dirRoleId = (int)$pdo->query("SELECT id FROM roles WHERE name = 'Regional Director'")->fetchColumn();
            $cashierRoleId = (int)$pdo->query("SELECT id FROM roles WHERE name = 'User / Cashier'")->fetchColumn();

            $passDir = password_hash('Admin@1234', PASSWORD_DEFAULT);
            $passCashier = password_hash('Cashier@1234', PASSWORD_DEFAULT);

            // A. KPK Director (محمد عامر) & KPK Cashier
            if ($kpkId > 0 && $dirRoleId > 0) {
                $stmtIns = $pdo->prepare("INSERT INTO users (role_id, region_id, full_name, username, email, phone_number, password_hash, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
                $stmtIns->execute([$dirRoleId, $kpkId, 'محمد عامر (KPK ڈائریکٹر)', 'dir_kpk', 'dir.kpk@madrasa.org', '0300-1111111', $passDir]);
                $kpkDirId = (int)$pdo->lastInsertId();
                $pdo->exec("UPDATE regions SET director_id = {$kpkDirId} WHERE id = {$kpkId}");
                if (function_exists('get_or_create_user_cash_account')) get_or_create_user_cash_account($pdo, $kpkDirId);
                if (function_exists('sync_user_role_permissions')) sync_user_role_permissions($pdo, $kpkDirId, 'Regional Director');

                if ($cashierRoleId > 0) {
                    $stmtIns->execute([$cashierRoleId, $kpkId, 'کیشیئر (KPK فیلڈ)', 'cashier_kpk', 'cashier.kpk@madrasa.org', '0300-2222222', $passCashier]);
                    $kpkCashierId = (int)$pdo->lastInsertId();
                    $pdo->exec("UPDATE users SET supervisor_id = {$kpkDirId} WHERE id = {$kpkCashierId}");
                    if (function_exists('get_or_create_user_cash_account')) get_or_create_user_cash_account($pdo, $kpkCashierId);
                    if (function_exists('sync_user_role_permissions')) sync_user_role_permissions($pdo, $kpkCashierId, 'User / Cashier');
                }
            }

            // B. Punjab Director & Punjab Cashier
            if ($pbId > 0 && $dirRoleId > 0) {
                $stmtIns = $pdo->prepare("INSERT INTO users (role_id, region_id, full_name, username, email, phone_number, password_hash, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
                $stmtIns->execute([$dirRoleId, $pbId, 'پنجاب ڈائریکٹر (Punjab Director)', 'dir_punjab', 'dir.pb@madrasa.org', '0300-3333333', $passDir]);
                $pbDirId = (int)$pdo->lastInsertId();
                $pdo->exec("UPDATE regions SET director_id = {$pbDirId} WHERE id = {$pbId}");
                if (function_exists('get_or_create_user_cash_account')) get_or_create_user_cash_account($pdo, $pbDirId);
                if (function_exists('sync_user_role_permissions')) sync_user_role_permissions($pdo, $pbDirId, 'Regional Director');

                if ($cashierRoleId > 0) {
                    $stmtIns->execute([$cashierRoleId, $pbId, 'کیشیئر (پنجاب فیلڈ)', 'cashier_punjab', 'cashier.pb@madrasa.org', '0300-4444444', $passCashier]);
                    $pbCashierId = (int)$pdo->lastInsertId();
                    $pdo->exec("UPDATE users SET supervisor_id = {$pbDirId} WHERE id = {$pbCashierId}");
                    if (function_exists('get_or_create_user_cash_account')) get_or_create_user_cash_account($pdo, $pbCashierId);
                    if (function_exists('sync_user_role_permissions')) sync_user_role_permissions($pdo, $pbCashierId, 'User / Cashier');
                }
            }

            set_setting('provincial_hierarchy_seeded', '1');
        } catch (Throwable $e) {}
    }

    // Ensure any user without permissions in user_permissions gets their default role permissions synced
    try {
        $usersWithoutPerms = $pdo->query("SELECT u.id, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id NOT IN (SELECT DISTINCT user_id FROM user_permissions)")->fetchAll();
        foreach ($usersWithoutPerms as $uwp) {
            sync_user_role_permissions($pdo, (int)$uwp['id'], (string)$uwp['role_name']);
        }
    } catch (Throwable $e) {}

    // Ensure user_funds table exists for per-user assigned funds
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_funds (
      user_id BIGINT UNSIGNED NOT NULL,
      fund_id BIGINT UNSIGNED NOT NULL,
      PRIMARY KEY (user_id, fund_id),
      INDEX idx_uf_user (user_id),
      INDEX idx_uf_fund (fund_id),
      CONSTRAINT fk_uf_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_uf_fund FOREIGN KEY (fund_id) REFERENCES funds(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    // Ensure accounts and funds tables have 'scope' column (ENUM: all, madrasa, trust)
    try {
        $colCheckAcc = $pdo->query("SHOW COLUMNS FROM accounts LIKE 'scope'")->fetch();
        if (!$colCheckAcc) {
            $pdo->exec("ALTER TABLE accounts ADD COLUMN scope ENUM('all','madrasa','trust') NOT NULL DEFAULT 'all' AFTER is_active, ADD INDEX idx_account_scope (scope)");
        }
    } catch (Exception $e) {}

    try {
        $colCheckFund = $pdo->query("SHOW COLUMNS FROM funds LIKE 'scope'")->fetch();
        if (!$colCheckFund) {
            $pdo->exec("ALTER TABLE funds ADD COLUMN scope ENUM('all','madrasa','trust') NOT NULL DEFAULT 'all' AFTER is_restricted, ADD INDEX idx_fund_scope (scope)");
        }
    } catch (Exception $e) {}

    // Seed standard Trust funds with scope='trust' (and Zakat as 'all')
    $trustFunds = [
        ['Trust General Fund', 'TGF', 'General trust administrative and operational expenses (عمومی فنڈ برائے دفتری و انتظامی اخراجات)', 0, 'trust'],
        ['Zakat & Sadaqah Fund', 'ZAK', 'Zakat & Sadaqah contributions; strictly for eligible beneficiaries (زکوٰۃ و صدقات فنڈ)', 1, 'all'],
        ['Ration & Food Aid Fund', 'RAF', 'Monthly ration packages and food support for deserving families (راشن و خوراک فنڈ)', 1, 'trust'],
        ['Medical & Ambulance Fund', 'MAF', 'Medical treatments, medicines and ambulance services (طبی امداد و ایمبولینس فنڈ)', 1, 'trust'],
        ['Clean Water Project Fund', 'CWP', 'Water handpumps, wells and filtration plant projects (صاف پانی و واٹر پروجیکٹ)', 1, 'trust'],
        ['Orphan & Widow Support Fund', 'OWF', 'Monthly financial stipend and educational care for orphans and widows (یتیم و بیوہ کفالت)', 1, 'trust'],
        ['Emergency & Disaster Relief', 'EDF', 'Emergency relief fund for floods, earthquakes and crisis (ہنگامی آفات و ریلیف فنڈ)', 1, 'trust']
    ];
    $chkFund = $pdo->prepare("SELECT id, name, is_active FROM funds WHERE fund_code = ? LIMIT 1");
    $actFund = $pdo->prepare("UPDATE funds SET name = ?, description = ?, is_restricted = ?, scope = ?, is_active = 1 WHERE id = ?");
    $insFund = $pdo->prepare("INSERT INTO funds (name, fund_code, description, is_restricted, scope, is_active) VALUES (?, ?, ?, ?, ?, 1)");
    foreach ($trustFunds as $tf) {
        $chkFund->execute([$tf[1]]);
        $foundFund = $chkFund->fetch(PDO::FETCH_ASSOC);
        if (!$foundFund) {
            $byName = $pdo->prepare("SELECT id FROM funds WHERE name = ? OR name LIKE ? LIMIT 1");
            $byName->execute([$tf[0], '%' . explode(' ', $tf[0])[0] . '%']);
            $foundByName = $byName->fetch(PDO::FETCH_ASSOC);
            if ($foundByName) {
                $actFund->execute([$tf[0], $tf[2], $tf[3], $tf[4], $foundByName['id']]);
                $pdo->prepare("UPDATE funds SET fund_code = ? WHERE id = ?")->execute([$tf[1], $foundByName['id']]);
            } else {
                $insFund->execute([$tf[0], $tf[1], $tf[2], $tf[3], $tf[4]]);
            }
        } else {
            $actFund->execute([$tf[0], $tf[2], $tf[3], $tf[4], $foundFund['id']]);
        }
    }

    // Seed standard Madrasa funds with scope='madrasa'
    $madrasaFunds = [
        ['Madrasa General Fund', 'MGF', 'General administrative, books, and operational expenses of the seminary (مدرسہ عمومی فنڈ)', 0, 'madrasa'],
        ['Student Mess & Food Fund', 'MSF', 'Kitchen, ration, and daily meals for madrasa students (مطبخ و طعام طلبہ فنڈ)', 1, 'madrasa'],
        ['Student Sponsorship & Boarding', 'STU', 'Education expenses, stipends, and boarding for deserving students (کفالت طلبہ فنڈ)', 1, 'madrasa'],
        ['Construction & Masjid Fund', 'BLD', 'Building extensions, classroom construction, and masjid maintenance (تعمیرات و مسجد فنڈ)', 1, 'madrasa']
    ];
    foreach ($madrasaFunds as $mf) {
        $chkFund->execute([$mf[1]]);
        $foundFund = $chkFund->fetch(PDO::FETCH_ASSOC);
        if (!$foundFund) {
            $insFund->execute([$mf[0], $mf[1], $mf[2], $mf[3], $mf[4]]);
        } else {
            $actFund->execute([$mf[0], $mf[2], $mf[3], $mf[4], $foundFund['id']]);
        }
    }

    // Seed standard Trust chart of accounts with scope='trust'
    $trustAccounts = [
        ['4100', 'ماہانہ کفالت عطیات (Monthly Sponsorship)', 'income', 0, 'trust'],
        ['4110', 'راشن و فوڈ ایڈ عطیات (Ration & Food Aid)', 'income', 0, 'trust'],
        ['4120', 'میڈیکل و ایمبولینس عطیات (Medical & Ambulance)', 'income', 0, 'trust'],
        ['4130', 'واٹر پروجیکٹ عطیات (Clean Water Donations)', 'income', 0, 'trust'],
        ['4140', 'یتیم و بیوہ کفالت عطیات (Orphan & Widow Aid)', 'income', 0, 'trust'],
        ['4150', 'ہنگامی ریلیف عطیات (Disaster Relief Donations)', 'income', 0, 'trust'],
        ['5100', 'راشن خریداری و تقسیم (Ration Purchases & Distribution)', 'expense', 0, 'trust'],
        ['5110', 'مریضوں کا علاج و ادویات (Patient Medical Aid & Medicine)', 'expense', 0, 'trust'],
        ['5120', 'ایمبولینس ایندھن و مرمت (Ambulance Fuel & Maintenance)', 'expense', 0, 'trust'],
        ['5130', 'بیوہ و یتیم ماہانہ وظائف (Widow & Orphan Monthly Stipends)', 'expense', 0, 'trust'],
        ['5140', 'واٹر پروجیکٹ و ہینڈ پمپ اخراجات (Water Project & Handpump Costs)', 'expense', 0, 'trust'],
        ['5150', 'ہنگامی ریلیف و امدادی سامان (Emergency Relief & Supplies)', 'expense', 0, 'trust']
    ];
    $chkAcc = $pdo->prepare("SELECT id FROM accounts WHERE account_code = ? LIMIT 1");
    $insAcc = $pdo->prepare("INSERT INTO accounts (account_code, account_name, account_type, is_cash_or_bank, scope, is_active) VALUES (?, ?, ?, ?, ?, 1)");
    $upAccScope = $pdo->prepare("UPDATE accounts SET scope = ? WHERE id = ?");
    foreach ($trustAccounts as $ta) {
        $chkAcc->execute([$ta[0]]);
        $foundAcc = $chkAcc->fetch(PDO::FETCH_ASSOC);
        if (!$foundAcc) {
            $insAcc->execute([$ta[0], $ta[1], $ta[2], $ta[3], $ta[4]]);
        } else {
            $upAccScope->execute([$ta[4], $foundAcc['id']]);
        }
    }

    // Ensure Madrasa-specific accounts have scope='madrasa'
    $madrasaCodes = ['4040','4050','4060','4070','4080','4090','5010','5020','5040','5050'];
    $inCodes = "'" . implode("','", $madrasaCodes) . "'";
    $pdo->exec("UPDATE accounts SET scope = 'madrasa' WHERE account_code IN ({$inCodes})");

    // Ensure Common accounts have scope='all'
    $commonCodes = ['1000','1010','3000','4000','4010','4020','4030','5000','5030','5060','5070'];
    $inCommon = "'" . implode("','", $commonCodes) . "'";
    $pdo->exec("UPDATE accounts SET scope = 'all' WHERE account_code IN ({$inCommon})");
        $pdo->exec("UPDATE accounts SET scope = 'all' WHERE is_cash_or_bank = 1 OR user_id IS NOT NULL");
    } catch (Exception $e) {
        // Non-blocking
    }
}

// Auto-seed and schema migrations: version-cached to prevent heavy DDL on every page request
try {
    // Quick failsafe for new columns in donors
    $chkProofCol = $pdo->query("SHOW COLUMNS FROM donors LIKE 'proof_receipt'")->fetch();
    if (!$chkProofCol) {
        $pdo->exec("ALTER TABLE donors ADD COLUMN proof_receipt VARCHAR(255) NULL AFTER notes");
    }
    $chkProofVer = $pdo->query("SHOW COLUMNS FROM donors LIKE 'proof_receipt_verified'")->fetch();
    if (!$chkProofVer) {
        $pdo->exec("ALTER TABLE donors ADD COLUMN proof_receipt_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER proof_receipt");
    }
    $chkSponBen = $pdo->query("SHOW COLUMNS FROM donors LIKE 'sponsored_beneficiary_id'")->fetch();
    if (!$chkSponBen) {
        $pdo->exec("ALTER TABLE donors ADD COLUMN sponsored_beneficiary_id BIGINT UNSIGNED NULL AFTER pledge_notes, ADD INDEX idx_donor_spon_ben (sponsored_beneficiary_id)");
    }
} catch (Throwable $e) {}

try {
    // Quick failsafe: Auto-heal any donors with missing/zero fund_id or pledge_fund_id (e.g. online registered leads)
    $orgMode = function_exists('get_org_mode') ? get_org_mode() : 'trust';
    $defFundId = (int)$pdo->query("SELECT id FROM funds WHERE is_active=1 AND scope IN ('all', '{$orgMode}') ORDER BY is_restricted ASC, (name LIKE '%general%' OR name LIKE '%عمومی%') DESC, id ASC LIMIT 1")->fetchColumn();
    if ($defFundId > 0) {
        $pdo->exec("UPDATE donors SET fund_id = {$defFundId} WHERE fund_id IS NULL OR fund_id <= 0");
        $pdo->exec("UPDATE donors SET pledge_fund_id = fund_id WHERE (pledge_fund_id IS NULL OR pledge_fund_id <= 0) AND is_pledge_active = 1");
    }
} catch (Throwable $e) {}

try {
    $schemaVer = get_setting('schema_version', '');
    if ($schemaVer !== '2.7') {
        run_schema_and_seed_migrations($pdo);
        set_setting('schema_version', '2.7');
    }
} catch (Throwable $e) {
    try {
        run_schema_and_seed_migrations($pdo);
        set_setting('schema_version', '2.7');
    } catch (Throwable $ex) {}
}

if (!function_exists('get_user_assigned_fund_ids')) {
function get_user_assigned_fund_ids(int $userId): array {
    global $pdo;
    static $fundCache = [];
    if (isset($fundCache[$userId])) {
        return $fundCache[$userId];
    }
    if (!$pdo || $userId <= 0) return [];
    try {
        $stmt = $pdo->prepare('SELECT fund_id FROM user_funds WHERE user_id = ?');
        $stmt->execute([$userId]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $fundCache[$userId] = array_map('intval', $ids ?: []);
        return $fundCache[$userId];
    } catch (Exception $e) {
        return [];
    }
}
}

if (!function_exists('get_user_allowed_funds')) {
function get_user_allowed_funds(?array $user = null): array {
    global $pdo;
    if (!$pdo) return [];
    if ($user === null) {
        $user = current_user();
    }
    if (!$user) return [];

    $mode = function_exists('get_org_mode') ? get_org_mode() : 'trust';
    $role = $user['role'] ?? '';
    // Super Admin / Administrator gets active funds matching current mode
    if (in_array($role, ['Super Admin', 'Administrator'], true)) {
        return $pdo->query("SELECT id, name, fund_code, is_restricted, scope FROM funds WHERE is_active=1 AND scope IN ('all', '{$mode}') ORDER BY name ASC")->fetchAll();
    }

    $userId = (int)($user['id'] ?? 0);
    $assignedFundIds = get_user_assigned_fund_ids($userId);

    // If specific funds are assigned, restrict strictly to those funds!
    if (!empty($assignedFundIds)) {
        $placeholders = implode(',', array_fill(0, count($assignedFundIds), '?'));
        $stmt = $pdo->prepare("SELECT id, name, fund_code, is_restricted, scope FROM funds WHERE is_active=1 AND id IN ($placeholders) AND scope IN ('all', '{$mode}') ORDER BY name ASC");
        $stmt->execute($assignedFundIds);
        return $stmt->fetchAll();
    }

    // Default: if no specific funds assigned, allow active funds matching current mode
    return $pdo->query("SELECT id, name, fund_code, is_restricted, scope FROM funds WHERE is_active=1 AND scope IN ('all', '{$mode}') ORDER BY name ASC")->fetchAll();
}
}

if (!function_exists('is_fund_allowed_for_user')) {
function is_fund_allowed_for_user(int $fundId, ?array $user = null): bool {
    if ($fundId <= 0) return false;
    $allowed = get_user_allowed_funds($user);
    foreach ($allowed as $f) {
        if ((int)$f['id'] === $fundId) {
            return true;
        }
    }
    return false;
}
}

if (!function_exists('get_all_permissions_definition')) {
function get_all_permissions_definition(): array {
    return [
        'Income (آمدنی)' => [
            'income.view' => ['label' => 'View Income Receipts', 'ur' => 'آمدنی کی رسیدیں دیکھنا'],
            'income.create' => ['label' => 'Create Income Receipts', 'ur' => 'نئی وصولی رسید بنانا'],
            'income.slip' => ['label' => 'Print & WhatsApp Slips', 'ur' => 'رسید پرنٹ و واٹس ایپ شیئر'],
        ],
        'Expenses (اخراجات)' => [
            'expense.view' => ['label' => 'View Expenses', 'ur' => 'اخراجات کی لسٹ دیکھنا'],
            'expense.create' => ['label' => 'Create Expenses', 'ur' => 'خرچ کا واؤچر بنانا'],
        ],
        'Approvals & Direct Post (منظوری و پوسٹنگ)' => [
            'voucher.approve' => ['label' => 'Approve & Reject Vouchers', 'ur' => 'واؤچرز کی پڑتال و منظوری'],
            'voucher.direct_post' => ['label' => 'Direct Post (No Approval Needed)', 'ur' => 'بغیر منظوری کے ڈائریکٹ پوسٹ'],
        ],
        'Donors (معاونین)' => [
            'donors.view' => ['label' => 'View Donors Directory', 'ur' => 'معاونین کی فہرست دیکھنا'],
            'donors.manage' => ['label' => 'Add, Edit & Delete Donors', 'ur' => 'نیا معاون شامل / تبدیل کرنا'],
        ],
        'Beneficiaries & Students (طلبہ و مستحقین)' => [
            'beneficiaries.view' => ['label' => 'View Beneficiaries & Students', 'ur' => 'طلبہ و مستحقین کا ریکارڈ دیکھنا'],
            'beneficiaries.manage' => ['label' => 'Add, Edit & Delete Beneficiaries', 'ur' => 'طالب علم / مستحق شامل و تبدیل کرنا'],
        ],
        'Funds (فنڈز)' => [
            'funds.view' => ['label' => 'View Funds & Balances', 'ur' => 'فنڈز اور بیلنس دیکھنا'],
            'funds.manage' => ['label' => 'Create & Edit Funds', 'ur' => 'فنڈز بنانا یا تبدیل کرنا'],
        ],
        'Cash & Bank (کیش و بینک)' => [
            'accounts.view' => ['label' => 'View Accounts & Ledgers', 'ur' => 'کھاتہ جات اور لیجر دیکھنا'],
            'accounts.manage' => ['label' => 'Manage Chart of Accounts', 'ur' => 'نیا اکاؤنٹ بنانا یا ترمیم کرنا'],
        ],
        'Reports (رپورٹس)' => [
            'reports.view' => ['label' => 'View Financial Reports', 'ur' => 'مالیاتی رپورٹس و کیش بک دیکھنا'],
        ],
        'Tasks & Activities (سرگرمیاں و ٹاسکس)' => [
            'tasks.view' => ['label' => 'View Tasks & Projects', 'ur' => 'ٹاسکس اور سرگرمیاں دیکھنا'],
            'tasks.manage' => ['label' => 'Create & Assign Tasks', 'ur' => 'نئے ٹاسکس بنانا اور تفویض کرنا'],
            'tasks.update_progress' => ['label' => 'Post Daily Progress Reports', 'ur' => 'روزانہ پیشرفت رپورٹ درج کرنا'],
        ],
        'Administration (انتظامیہ)' => [
            'users.manage' => ['label' => 'Manage Users & Permissions', 'ur' => 'صارفین اور اختیارات مینیج کرنا'],
            'settings.manage' => ['label' => 'Manage Trust Settings', 'ur' => 'ٹرسٹ کی بنیادی سیٹنگز'],
        ],
        'Provinces & Regional Hierarchy (صوبائی و علاقائی برانچز)' => [
            'regions.view' => ['label' => 'View Regions & Provinces', 'ur' => 'صوبائی برانچز اور ریجنز دیکھنا'],
            'regions.manage' => ['label' => 'Manage Regions & Provinces', 'ur' => 'صوبے بنانا اور ڈائریکٹر مقرر کرنا'],
            'team.manage' => ['label' => 'Manage Regional Team & Cashiers', 'ur' => 'اپنی صوبائی ٹیم کے کیشیئرز و فیلڈ اسٹاف بنانا'],
        ],
    ];
}
}

if (!function_exists('sync_user_role_permissions')) {
function sync_user_role_permissions(PDO $pdo, int $userId, string $roleName): void {
    $codes = [];
    if ($roleName === 'Super Admin' || $roleName === 'Administrator') {
        foreach (get_all_permissions_definition() as $group => $items) {
            foreach ($items as $c => $meta) {
                $codes[] = $c;
            }
        }
    } elseif ($roleName === 'Regional Director') {
        $codes = [
            'income.view', 'income.create', 'income.slip',
            'expense.view', 'expense.create',
            'voucher.approve', 'voucher.direct_post',
            'donors.view', 'donors.manage',
            'beneficiaries.view', 'beneficiaries.manage',
            'funds.view', 'accounts.view',
            'reports.view',
            'tasks.view', 'tasks.manage', 'tasks.update_progress',
            'regions.view', 'team.manage'
        ];
    } elseif ($roleName === 'Approver / Checker' || $roleName === 'Checker') {
        foreach (get_all_permissions_definition() as $group => $items) {
            foreach ($items as $c => $meta) {
                if (!in_array($c, ['users.manage', 'settings.manage'], true)) {
                    $codes[] = $c;
                }
            }
        }
    } elseif ($roleName === 'User / Cashier' || $roleName === 'Cashier' || $roleName === 'Accountant') {
        $codes = ['income.view', 'income.create', 'income.slip', 'expense.view', 'expense.create', 'donors.view', 'donors.manage', 'beneficiaries.view', 'beneficiaries.manage', 'tasks.view', 'tasks.update_progress'];
    } elseif ($roleName === 'Viewer') {
        $codes = ['income.view', 'expense.view', 'donors.view', 'beneficiaries.view', 'funds.view', 'accounts.view', 'reports.view', 'tasks.view'];
    }

    if (!empty($codes)) {
        $stmtIns = $pdo->prepare("INSERT IGNORE INTO user_permissions (user_id, permission_code) VALUES (?, ?)");
        foreach ($codes as $c) {
            $stmtIns->execute([$userId, $c]);
        }
    }
}
}

if (!function_exists('get_user_permissions')) {
function get_user_permissions(int $userId): array {
    global $pdo;
    static $cache = [];
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }
    if (!$pdo || $userId <= 0) return [];
    try {
        $stmt = $pdo->prepare('SELECT permission_code FROM user_permissions WHERE user_id = ?');
        $stmt->execute([$userId]);
        $perms = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $cache[$userId] = $perms ?: [];
        return $cache[$userId];
    } catch (Exception $e) {
        return [];
    }
}
}

if (!function_exists('has_permission')) {
function has_permission(string $code, ?array $user = null): bool {
    if ($user === null) {
        $user = current_user();
    }
    if (!$user) return false;

    $role = $user['role'] ?? '';
    // Super Admin and Administrator always have all permissions!
    if (in_array($role, ['Super Admin', 'Administrator'], true)) {
        return true;
    }

    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) return false;

    $perms = get_user_permissions($userId);
    if (!empty($perms)) {
        return in_array($code, $perms, true);
    }

    // Role-based fallback if no user_permissions rows exist yet:
    if ($role === 'Approver / Checker' || $role === 'Checker') {
        return !in_array($code, ['users.manage', 'settings.manage'], true);
    }
    if ($role === 'User / Cashier' || $role === 'Cashier' || $role === 'Accountant') {
        return in_array($code, ['income.view', 'income.create', 'income.slip', 'expense.view', 'expense.create', 'donors.view', 'donors.manage'], true);
    }
    if ($role === 'Viewer') {
        return in_array($code, ['income.view', 'expense.view', 'donors.view', 'funds.view', 'accounts.view', 'reports.view'], true);
    }
    return false;
}
}

if (!function_exists('require_permission')) {
function require_permission(string $code): void {
    require_login();
    if (!has_permission($code)) {
        http_response_code(403);
        $currUser = current_user();
        $userName = htmlspecialchars($currUser['name'] ?? 'User');
        echo '<!doctype html><html lang="ur" dir="rtl"><head><meta charset="utf-8"><title>اختیار موجود نہیں | Access Denied</title>';
        echo '<link rel="stylesheet" href="assets/style.css?v=' . css_ver() . '">';
        echo '<body style="background:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px;font-family:system-ui,-apple-system,sans-serif;">';
        echo '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;max-width:480px;width:100%;padding:32px 24px;text-align:center;box-shadow:0 10px 25px rgba(0,0,0,0.06);">';
        echo '<div style="font-size:48px;margin-bottom:12px;">🚫</div>';
        echo '<h2 style="color:#0f172a;margin-bottom:8px;font-size:20px;">اس صفحے تک رسائی کا اختیار نہیں ہے</h2>';
        echo '<p style="color:#64748b;font-size:14px;margin-bottom:20px;">معزز صارف <b>' . $userName . '</b>! آپ کے اکاؤنٹ کے پاس اس کام کا اختیار (<code>' . htmlspecialchars($code) . '</code>) فعال نہیں ہے۔</p>';
        echo '<div style="display:flex;gap:10px;justify-content:center;">';
        echo '<a href="dashboard.php" class="btn blue sm" style="text-decoration:none;padding:8px 18px;">ڈیش بورڈ پر واپس جائیں</a>';
        echo '<a href="javascript:history.back()" class="btn secondary sm" style="text-decoration:none;padding:8px 18px;">پیچھے جائیں</a>';
        echo '</div>';
        echo '</div></body></html>';
        exit;
    }
}
}

function e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void {
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf_token'] ?? '', $sent)) {
        http_response_code(419);
        exit('Invalid or expired form token. Please go back and try again.');
    }
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function require_login(): void {
    if (!current_user()) {
        header('Location: login.php');
        exit;
    }
    // Update live heartbeat for Login Activity & Device Tracker
    global $pdo;
    $now = time();
    if (!empty($_SESSION['user']['id']) && $pdo) {
        if (empty($_SESSION['last_seen_ts']) || ($now - (int)$_SESSION['last_seen_ts']) >= 45) {
            $_SESSION['last_seen_ts'] = $now;
            try {
                $pdo->prepare("UPDATE users SET last_seen_at = NOW() WHERE id = ?")->execute([(int)$_SESSION['user']['id']]);
            } catch (Throwable $e) {}
        }
    }
}

function can_approve(): bool {
    $user = current_user();
    if (!$user) return false;
    $role = $user['role'] ?? '';
    if (in_array($role, ['Super Admin', 'Administrator'], true)) return true;
    return has_permission('voucher.approve', $user) || has_permission('voucher.direct_post', $user);
}

function can_record(): bool {
    $user = current_user();
    if (!$user) return false;
    $role = $user['role'] ?? '';
    if (in_array($role, ['Super Admin', 'Administrator'], true)) return true;
    return has_permission('income.create', $user) || has_permission('expense.create', $user);
}

function require_role(array $allowed): void {
    require_login();
    $role = $_SESSION['user']['role'] ?? '';
    
    // Allow Approver / Checker whenever Administrator is allowed
    if (in_array('Administrator', $allowed, true) && $role === 'Approver / Checker') {
        return;
    }
    // Allow User / Cashier whenever Accountant is allowed
    if (in_array('Accountant', $allowed, true) && $role === 'User / Cashier') {
        return;
    }

    if (!in_array($role, $allowed, true)) {
        http_response_code(403);
        exit('You do not have permission to access this page.');
    }
}

function has_role(string|array $role, ?array $user = null): bool {
    if ($user === null) {
        $user = current_user();
    }
    if (!$user) return false;
    $userRole = $user['role'] ?? '';
    
    if (is_array($role)) {
        foreach ($role as $r) {
            if (has_role($r, $user)) return true;
        }
        return false;
    }
    
    $cleanCheck = strtolower(str_replace([' ', '_', '-'], '', (string)$role));
    $cleanUser = strtolower(str_replace([' ', '_', '-'], '', (string)$userRole));
    
    return $cleanCheck === $cleanUser;
}

function money(float|int|string $amount): string {
    return 'Rs. ' . number_format((float)$amount, 2);
}

function css_ver(): string {
    $cssPath = __DIR__ . '/../public/assets/style.css';
    return is_file($cssPath) ? (string)filemtime($cssPath) : '1.0';
}

function get_setting(string $key, string $default = ''): string {
    global $pdo;
    static $settings = null;
    if ($settings === null) {
        try {
            $settings = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        } catch (Exception $e) {
            $settings = [];
        }
    }
    return (string)($settings[$key] ?? $default);
}

function set_setting(string $key, string $value): bool {
    global $pdo;
    if (!$pdo) return false;
    try {
        $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        return $stmt->execute([$key, $value]);
    } catch (Exception $e) {
        return false;
    }
}

function get_app_base_url(): string {
    global $config;
    $configured = trim($config['app']['base_url'] ?? '');
    if (!empty($configured)) {
        return rtrim($configured, '/');
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return $scheme . '://' . $host . ($scriptDir && $scriptDir !== '/' ? $scriptDir : '');
}

function get_voucher_public_token(int $voucherId): string {
    global $config;
    $secret = $config['app']['session_name'] ?? 'madrasa_trust_public_key_2026';
    return substr(hash_hmac('sha256', "voucher_public_verify_{$voucherId}", $secret), 0, 16);
}

function get_backup_dir(): string {
    $dir = dirname(__DIR__) . '/storage/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $storageHt = dirname(__DIR__) . '/storage/.htaccess';
    if (!file_exists($storageHt)) {
        @file_put_contents($storageHt, "Deny from all\n");
    }
    return $dir;
}

function get_or_create_cron_token(): string {
    $token = get_setting('cron_backup_token', '');
    if (empty($token) || strlen($token) < 20) {
        try {
            $token = bin2hex(random_bytes(16));
        } catch (Exception $e) {
            $token = md5(uniqid('cron_', true));
        }
        set_setting('cron_backup_token', $token);
    }
    return $token;
}

function generate_sql_database_backup(PDO $pdo, ?string $destPath = null): bool {
    $isFileOutput = ($destPath !== null);
    try {
        $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn() ?: 'database';
        $orgName = function_exists('get_setting') ? get_setting('madrasa_name', '') : '';

        if ($isFileOutput) {
            $dir = dirname($destPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $out = fopen($destPath, 'w');
            if (!$out) {
                return false;
            }
        } else {
            $out = fopen('php://output', 'w');
        }

        fwrite($out, "-- ==========================================================\n");
        fwrite($out, "-- Database Backup & Disaster Recovery Archive\n");
        fwrite($out, "-- Institution: " . ($orgName ?: 'Trust & Accounts ERP') . "\n");
        fwrite($out, "-- Database: {$dbName}\n");
        fwrite($out, "-- Generated At: " . date('Y-m-d H:i:s') . "\n");
        fwrite($out, "-- Exported By: " . (function_exists('current_user') && current_user() ? (current_user()['full_name'] ?? 'System') : 'System/Cron') . "\n");
        fwrite($out, "-- ==========================================================\n\n");
        fwrite($out, "SET FOREIGN_KEY_CHECKS = 0;\n");
        fwrite($out, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n");
        fwrite($out, "SET time_zone = '+00:00';\n\n");

        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $tbl) {
            fwrite($out, "-- ----------------------------------------------------------\n");
            fwrite($out, "-- Table structure for table `{$tbl}`\n");
            fwrite($out, "-- ----------------------------------------------------------\n");
            fwrite($out, "DROP TABLE IF EXISTS `{$tbl}`;\n");

            $createRow = $pdo->query("SHOW CREATE TABLE `{$tbl}`")->fetch(PDO::FETCH_NUM);
            if (!empty($createRow[1])) {
                fwrite($out, $createRow[1] . ";\n\n");
            }

            // Dump data
            $rows = $pdo->query("SELECT * FROM `{$tbl}`")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                fwrite($out, "-- Dumping data for table `{$tbl}` (" . count($rows) . " rows)\n");
                $cols = array_keys($rows[0]);
                $colNames = implode('`, `', array_map('addslashes', $cols));

                $chunks = array_chunk($rows, 50);
                foreach ($chunks as $chunk) {
                    $valList = [];
                    foreach ($chunk as $row) {
                        $escapedVals = [];
                        foreach ($row as $val) {
                            if ($val === null) {
                                $escapedVals[] = 'NULL';
                            } elseif (is_int($val) || is_float($val)) {
                                $escapedVals[] = (string)$val;
                            } else {
                                $escapedVals[] = $pdo->quote((string)$val);
                            }
                        }
                        $valList[] = "(" . implode(', ', $escapedVals) . ")";
                    }
                    fwrite($out, "INSERT INTO `{$tbl}` (`{$colNames}`) VALUES\n" . implode(",\n", $valList) . ";\n");
                }
                fwrite($out, "\n");
            }
        }

        fwrite($out, "SET FOREIGN_KEY_CHECKS = 1;\n");
        fwrite($out, "-- Dump completed at " . date('Y-m-d H:i:s') . "\n");

        if ($isFileOutput && is_resource($out)) {
            fclose($out);
        } elseif (is_resource($out)) {
            fflush($out);
        }
        return true;
    } catch (Throwable $e) {
        if ($isFileOutput && isset($out) && is_resource($out)) {
            fclose($out);
            if (file_exists($destPath)) {
                @unlink($destPath);
            }
        }
        error_log('Database backup error: ' . $e->getMessage());
        return false;
    }
}

function auto_run_daily_backup(PDO $pdo, bool $force = false): ?string {
    $backupDir = get_backup_dir();
    $today = date('Y-m-d');

    $enabled = get_setting('auto_backup_enabled', '1');
    if ($enabled !== '1' && !$force) {
        return null;
    }

    if (!$force) {
        $todayBackups = glob($backupDir . '/database_backup_' . $today . '_*.sql');
        if (!empty($todayBackups)) {
            return end($todayBackups);
        }
    }

    $filename = 'database_backup_' . date('Y-m-d_His') . '.sql';
    $filePath = $backupDir . '/' . $filename;

    $ok = generate_sql_database_backup($pdo, $filePath);
    if (!$ok) {
        return null;
    }

    set_setting('last_auto_backup_at', date('Y-m-d H:i:s'));
    cleanup_old_backups(15);

    // Automated Backup to Email (Feature #1)
    $emailEnabled = get_setting('backup_email_enabled', '0');
    $emailAddress = get_setting('backup_email_address', '');
    if ($emailEnabled === '1' && !empty($emailAddress)) {
        try {
            send_backup_email($filePath, $emailAddress);
        } catch (Throwable $ex) {}
    }

    return $filePath;
}

function cleanup_old_backups(int $keep = 15): void {
    $backupDir = get_backup_dir();
    $files = glob($backupDir . '/database_backup_*.sql');
    if (!$files || count($files) <= $keep) {
        return;
    }
    rsort($files);
    $filesToDelete = array_slice($files, $keep);
    foreach ($filesToDelete as $oldFile) {
        if (is_file($oldFile)) {
            @unlink($oldFile);
        }
    }
}

function get_saved_backups_list(): array {
    $backupDir = get_backup_dir();
    $files = glob($backupDir . '/database_backup_*.sql');
    if (!$files) {
        return [];
    }
    rsort($files);
    $list = [];
    foreach ($files as $file) {
        $basename = basename($file);
        $size = @filesize($file) ?: 0;
        $mtime = @filemtime($file) ?: time();
        $list[] = [
            'filename' => $basename,
            'filepath' => $file,
            'filesize' => $size,
            'filesize_formatted' => format_bytes_size($size),
            'mtime' => $mtime,
            'date_formatted' => date('d M Y, h:i A', $mtime),
        ];
    }
    return $list;
}

function format_bytes_size(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 2) . ' MB';
}

function send_backup_email(string $filePath, string $recipientEmail): bool {
    if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    if (!is_file($filePath) || !is_readable($filePath)) {
        return false;
    }

    $fileName = basename($filePath);
    $fileSize = filesize($filePath);
    $fileContent = chunk_split(base64_encode(file_get_contents($filePath)));
    $madrasaName = function_exists('get_setting') ? get_setting('madrasa_name', 'Trust Accounts') : 'Trust Accounts';

    $boundary = md5((string)time());
    $subject = "=?UTF-8?B?" . base64_encode("[Backup] {$madrasaName} - " . date('d M Y')) . "?=";

    $host = $_SERVER['HTTP_HOST'] ?? 'trust-accounts.org';
    $headers = [];
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "From: {$madrasaName} System <no-reply@{$host}>";
    $headers[] = "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";

    $sizeFormatted = format_bytes_size($fileSize);
    $nowFormatted = date('d F Y, h:i A');

    $body = "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $body .= "<div style='font-family:sans-serif;line-height:1.6;color:#1e293b;max-width:600px;margin:auto;border:1px solid #e2e8f0;border-radius:10px;padding:24px;background:#ffffff;'>"
        . "<h2 style='color:#15803d;margin-top:0;'>💾 Automated Database Backup Archive</h2>"
        . "<p>Assalam-o-Alaikum,</p>"
        . "<p>A fresh database backup archive has been safely created for <b>" . htmlspecialchars($madrasaName) . "</b>.</p>"
        . "<table style='width:100%;border-collapse:collapse;margin:15px 0;font-size:13.5px;'>"
        . "<tr><td style='padding:8px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:bold;width:35%;'>File Name:</td><td style='padding:8px;border:1px solid #cbd5e1;font-family:monospace;'>" . htmlspecialchars($fileName) . "</td></tr>"
        . "<tr><td style='padding:8px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:bold;'>Backup Date:</td><td style='padding:8px;border:1px solid #cbd5e1;'>" . $nowFormatted . "</td></tr>"
        . "<tr><td style='padding:8px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:bold;'>File Size:</td><td style='padding:8px;border:1px solid #cbd5e1;'>" . $sizeFormatted . "</td></tr>"
        . "</table>"
        . "<p style='color:#475569;font-size:12.5px;'>The complete SQL dump file is attached to this email. In case of server or hardware failure, this file can restore your entire accounts system in 2 minutes.</p>"
        . "<hr style='border:none;border-top:1px solid #e2e8f0;margin:20px 0;'>"
        . "<p style='font-size:11px;color:#94a3b8;margin:0;'>SoftifyX Technologies &middot; Automated Disaster Recovery Protection</p>"
        . "</div>\r\n\r\n";

    // Attachment
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: application/sql; name=\"{$fileName}\"\r\n";
    $body .= "Content-Disposition: attachment; filename=\"{$fileName}\"\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= $fileContent . "\r\n\r\n";
    $body .= "--{$boundary}--";

    $sent = @mail($recipientEmail, $subject, $body, implode("\r\n", $headers));
    if ($sent) {
        set_setting('last_backup_email_at', date('Y-m-d H:i:s'));
    }
    return (bool)$sent;
}

function record_login_activity(?int $userId, string $username, string $status = 'success'): void {
    global $pdo;
    if (!$pdo) return;
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $device = 'Desktop';
        if (preg_match('/(android|iphone|ipad|ipod|mobile|phone)/i', $ua)) {
            $device = 'Mobile';
        } elseif (preg_match('/(tablet|kindle|playbook)/i', $ua)) {
            $device = 'Tablet';
        }

        $stmt = $pdo->prepare("INSERT INTO login_activities (user_id, username, ip_address, user_agent, device_type, status) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $username, $ip, $ua, $device, $status]);

        // Keep last 500 records to prevent bloating
        if (mt_rand(1, 25) === 1) {
            $pdo->exec("DELETE FROM login_activities WHERE id NOT IN (SELECT id FROM (SELECT id FROM login_activities ORDER BY id DESC LIMIT 500) AS tmp)");
        }
    } catch (Throwable $e) {}
}

function get_recent_login_activities(int $limit = 15): array {
    global $pdo;
    if (!$pdo) return [];
    try {
        $stmt = $pdo->prepare("SELECT la.*, u.full_name, r.name AS role_name 
            FROM login_activities la 
            LEFT JOIN users u ON u.id = la.user_id 
            LEFT JOIN roles r ON r.id = u.role_id 
            ORDER BY la.id DESC LIMIT ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function get_active_online_users(int $windowMinutes = 25): array {
    global $pdo;
    if (!$pdo) return [];
    try {
        $currUid = !empty($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : 0;
        if ($currUid > 0) {
            $pdo->prepare("UPDATE users SET last_seen_at = NOW() WHERE id = ? AND (last_seen_at IS NULL OR last_seen_at < DATE_SUB(NOW(), INTERVAL 1 MINUTE))")->execute([$currUid]);
        }

        $sql = "SELECT u.id, u.username, u.full_name, u.last_seen_at, u.last_login_at,
                       COALESCE(r.name, 'User') AS role_name,
                       la.ip_address, la.device_type, la.user_agent, la.created_at AS login_time,
                       TIMESTAMPDIFF(MINUTE, COALESCE(u.last_seen_at, u.last_login_at, NOW()), NOW()) AS minutes_ago
                FROM users u
                LEFT JOIN roles r ON r.id = u.role_id
                LEFT JOIN (
                    SELECT la1.*
                    FROM login_activities la1
                    INNER JOIN (
                        SELECT user_id, MAX(id) AS max_id
                        FROM login_activities
                        WHERE status = 'success' AND user_id IS NOT NULL
                        GROUP BY user_id
                    ) latest ON la1.id = latest.max_id
                ) la ON la.user_id = u.id
                WHERE u.is_active = 1
                  AND (
                      u.id = {$currUid}
                      OR u.last_seen_at >= DATE_SUB(NOW(), INTERVAL {$windowMinutes} MINUTE)
                      OR (u.last_seen_at IS NULL AND u.last_login_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR))
                  )
                ORDER BY (u.id = {$currUid}) DESC, COALESCE(u.last_seen_at, u.last_login_at) DESC";
        $rows = $pdo->query($sql)->fetchAll() ?: [];
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

function get_user_region_id(?int $userId = null): ?int {
    global $pdo;
    if (!$pdo) return null;
    if ($userId === null) {
        $user = current_user();
        $userId = $user ? (int)$user['id'] : 0;
    }
    if ($userId <= 0) return null;
    try {
        $regId = $pdo->query("SELECT region_id FROM users WHERE id = {$userId}")->fetchColumn();
        return $regId ? (int)$regId : null;
    } catch (Throwable $e) {
        return null;
    }
}

function is_super_admin(?array $user = null): bool {
    if ($user === null) $user = current_user();
    if (!$user) return false;
    $role = $user['role'] ?? '';
    return in_array($role, ['Super Admin', 'Administrator'], true);
}

function is_regional_director(?array $user = null): bool {
    if ($user === null) $user = current_user();
    if (!$user) return false;
    $role = $user['role'] ?? '';
    return ($role === 'Regional Director');
}

function get_all_regions(bool $activeOnly = false): array {
    global $pdo;
    if (!$pdo) return [];
    try {
        $where = $activeOnly ? "WHERE r.is_active = 1" : "";
        return $pdo->query("SELECT r.*, u.full_name AS director_name, u.phone_number AS director_phone,
            (SELECT COUNT(*) FROM users WHERE region_id = r.id AND is_active = 1) AS team_count
            FROM regions r
            LEFT JOIN users u ON u.id = r.director_id
            {$where}
            ORDER BY r.is_active DESC, r.name ASC")->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function get_all_active_regions(): array {
    return get_all_regions(true);
}

function get_region_by_id(int $regionId): ?array {
    global $pdo;
    if (!$pdo || $regionId <= 0) return null;
    try {
        $stmt = $pdo->prepare("SELECT r.*, u.full_name AS director_name, u.phone_number AS director_phone FROM regions r LEFT JOIN users u ON u.id = r.director_id WHERE r.id = ?");
        $stmt->execute([$regionId]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function normalize_pakistan_phone(?string $phone): string {
    if (!$phone) return '';
    $digits = preg_replace('/[^0-9]/', '', $phone);
    if (str_starts_with($digits, '0')) {
        $digits = '92' . substr($digits, 1);
    } elseif (str_starts_with($digits, '3') && strlen($digits) === 10) {
        $digits = '92' . $digits;
    }
    return $digits;
}

function build_whatsapp_receipt_url(array $voucher, string $madrasaName, string $madrasaPhone): string {
    $donorName = $voucher['donor_name'] ?? $voucher['payee_name'] ?? 'محترم ڈونر';
    $vno = $voucher['voucher_no'] ?? '';
    $vdate = $voucher['voucher_date'] ?? date('Y-m-d');
    $pkrAmount = number_format((float)($voucher['total_amount'] ?? 0), 2);
    $fundName = $voucher['fund_name'] ?? 'عام عطیات فنڈ';
    $method = ucfirst($voucher['payment_method'] ?? 'کیش / آن لائن');
    
    $amountText = "PKR " . $pkrAmount;
    if (!empty($voucher['foreign_currency']) && !empty($voucher['foreign_amount'])) {
        $amountText = $voucher['foreign_currency'] . " " . number_format((float)$voucher['foreign_amount'], 2) . " (مساوی PKR " . $pkrAmount . ")";
    }

    $baseUrl = function_exists('get_app_base_url') ? get_app_base_url() : '';
    $voucherId = (int)($voucher['id'] ?? 0);
    $slipToken = ($voucherId > 0 && function_exists('get_voucher_public_token')) ? get_voucher_public_token($voucherId) : '';
    $slipLink = ($voucherId > 0 && !empty($baseUrl)) ? "{$baseUrl}/print_voucher.php?id={$voucherId}" . (!empty($slipToken) ? "&token={$slipToken}" : '') : '';

    $receiptDua = get_setting('print_receipt_dua', 'اللہ تعالیٰ آپ کے اس صدقہ و عطیہ کو شرف قبولیت بخشے، اور آپ کے جان و مال اور رزق میں بے شمار برکتیں عطا فرمائے۔ آمین۔');

    $msg = "السلام علیکم و رحمۃ اللہ و برکاتہ\n"
         . "محترم جناب *" . $donorName . "* صاحب!\n"
         . "*" . $madrasaName . "* کے لیے آپ کا عطیہ موصول ہو چکا ہے۔\n\n"
         . "📋 *رسید نمبر:* " . $vno . "\n"
         . "📅 *تاریخ:* " . $vdate . "\n"
         . "💰 *رقم:* *" . $amountText . "*\n"
         . "🏷️ *فنڈ و مد:* " . $fundName . "\n"
         . "💳 *طریقہ ادائیگی:* " . $method . "\n\n";

    if (!empty($slipLink)) {
        $msg .= "📄 *کمپیوٹرائزڈ رسید (PDF / Print Slip) دیکھنے یا ڈاؤن لوڈ کرنے کے لیے نیچے لنک پر کلک کریں:* \n"
              . $slipLink . "\n\n";
    }

    $msg .= $receiptDua . "\n\n"
         . "جزاکم اللہ خیراً و احسن الجزاء\n"
         . "*" . $madrasaName . "*";
    if ($madrasaPhone) {
        $msg .= "\nرابطہ دفتر: " . $madrasaPhone;
    }

    $donorPhone = normalize_pakistan_phone($voucher['donor_phone'] ?? $voucher['phone'] ?? '');
    return "https://api.whatsapp.com/send?phone=" . urlencode($donorPhone) . "&text=" . urlencode($msg);
}

function build_whatsapp_reminder_url(array $pledger, string $madrasaName, string $madrasaPhone, string $month): string {
    $donorName = $pledger['donor_name'] ?? 'محترم ڈونر';
    $fundName = $pledger['pledge_fund_name'] ?? $pledger['fund_name'] ?? 'کفالت فنڈ';
    $pledgeAmt = (float)($pledger['pledge_amount'] ?? 0);
    $paidAmt = (float)($pledger['paid_this_month'] ?? 0);
    $pendingAmt = max(0, $pledgeAmt - $paidAmt);

    $msg = "السلام علیکم و رحمۃ اللہ و برکاتہ\n"
         . "محترم جناب *" . $donorName . "* صاحب!\n"
         . "امید ہے آپ بخیر و عافیت ہوں گے۔\n\n"
         . "*" . $madrasaName . "* کی جانب سے آپ کے زیرِ کفالت نیک مقصد (*" . $fundName . "*) کے لیے ماہانہ تعاون برائے ماہ *" . $month . "* کی شائستہ یاد دہانی۔\n\n"
         . "📋 *آپ کا مقرر کردہ ماہانہ تعاون:* PKR " . number_format($pledgeAmt, 2) . "\n"
         . "⚠️ *بقایا برائے رواں ماہ:* *PKR " . number_format($pendingAmt, 2) . "*\n\n"
         . "آپ کا یہ تعاون کسی یتیم، بیوہ یا ضرورت مند کی کفالت کا ذریعہ ہے۔ برائے مہربانی اپنا تعاون ارسال فرما کر رسید شیئر فرما دیں۔\n\n"
         . "جزاکم اللہ خیراً و احسن الجزاء\n"
         . "*" . $madrasaName . "*";
    if ($madrasaPhone) {
        $msg .= "\nرابطہ دفتر: " . $madrasaPhone;
    }

    $donorPhone = normalize_pakistan_phone($pledger['phone'] ?? '');
    return "https://api.whatsapp.com/send?phone=" . urlencode($donorPhone) . "&text=" . urlencode($msg);
}

function optimize_database_tables(PDO $pdo): array {
    $results = [];
    try {
        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $tbl) {
            $opt = $pdo->query("OPTIMIZE TABLE `{$tbl}`")->fetch();
            $pdo->query("ANALYZE TABLE `{$tbl}`");
            $results[] = [
                'table' => $tbl,
                'status' => $opt['Msg_text'] ?? 'OK'
            ];
        }
    } catch (Throwable $e) {}
    return $results;
}

function get_org_logo_url(): ?string {
    $logo = get_setting('org_logo', '');
    if (!empty($logo) && file_exists(__DIR__ . '/../public/' . $logo)) {
        return $logo;
    }
    return null;
}

function number_to_words(float $number): string {
    $hyphen = ' ';
    $conjunction = ' and ';
    $separator = ', ';
    $dictionary = [
        0 => 'Zero', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four',
        5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
        10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen',
        15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen', 19 => 'Nineteen',
        20 => 'Twenty', 30 => 'Thirty', 40 => 'Forty', 50 => 'Fifty', 60 => 'Sixty',
        70 => 'Seventy', 80 => 'Eighty', 90 => 'Ninety', 100 => 'Hundred',
        1000 => 'Thousand', 100000 => 'Lakh', 10000000 => 'Crore'
    ];

    $intNumber = (int)floor($number);
    if ($intNumber === 0) return 'Zero Rupees Only';

    $string = '';
    switch (true) {
        case $intNumber < 21:
            $string = $dictionary[$intNumber];
            break;
        case $intNumber < 100:
            $tens = ((int) ($intNumber / 10)) * 10;
            $units = $intNumber % 10;
            $string = $dictionary[$tens];
            if ($units) $string .= $hyphen . $dictionary[$units];
            break;
        case $intNumber < 1000:
            $hundreds = (int) ($intNumber / 100);
            $remainder = $intNumber % 100;
            $string = $dictionary[$hundreds] . ' ' . $dictionary[100];
            if ($remainder) $string .= $conjunction . number_to_words($remainder);
            break;
        case $intNumber < 100000:
            $thousands = (int) ($intNumber / 1000);
            $remainder = $intNumber % 1000;
            $string = number_to_words($thousands) . ' ' . $dictionary[1000];
            if ($remainder) $string .= $separator . number_to_words($remainder);
            break;
        case $intNumber < 10000000:
            $lakhs = (int) ($intNumber / 100000);
            $remainder = $intNumber % 100000;
            $string = number_to_words($lakhs) . ' ' . $dictionary[100000];
            if ($remainder) $string .= $separator . number_to_words($remainder);
            break;
        default:
            $crores = (int) ($intNumber / 10000000);
            $remainder = $intNumber % 10000000;
            $string = number_to_words($crores) . ' ' . $dictionary[10000000];
            if ($remainder) $string .= $separator . number_to_words($remainder);
            break;
    }

    // Clean up recursive suffix if added
    $clean = trim(str_ireplace('Rupees Only', '', $string));
    return $clean . ' Rupees Only';
}

function get_main_cash_account(PDO $pdo): ?array {
    $stmt = $pdo->query("SELECT * FROM accounts WHERE (account_code = '1000' OR (is_cash_or_bank = 1 AND user_id IS NULL AND account_type = 'asset' AND account_name NOT LIKE '%bank%')) ORDER BY (account_code = '1000') DESC, id ASC LIMIT 1");
    $acc = $stmt->fetch();
    return $acc ?: null;
}

function get_or_create_bank_accounts(PDO $pdo): array {
    // 1. Search for existing active bank accounts
    $stmt = $pdo->query("SELECT id, account_code, account_name, user_id FROM accounts 
                         WHERE is_active = 1 
                         AND (
                             account_code = '1010' 
                             OR account_name LIKE '%bank%' 
                             OR account_name LIKE '%بینک%'
                             OR (is_cash_or_bank = 1 AND user_id IS NULL AND account_code != '1000' AND account_name NOT LIKE '%cash%' AND account_name NOT LIKE '%نقدی%')
                         )
                         ORDER BY (account_code = '1010') DESC, account_code ASC");
    $banks = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    // 2. If no bank account found, check if 1010 exists but is inactive or is_cash_or_bank=0
    if (empty($banks)) {
        $chk = $pdo->query("SELECT id, account_code, account_name FROM accounts WHERE account_code = '1010' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($chk) {
            $pdo->exec("UPDATE accounts SET is_active = 1, is_cash_or_bank = 1, scope = 'all' WHERE id = " . (int)$chk['id']);
            $stmt = $pdo->query("SELECT id, account_code, account_name, user_id FROM accounts WHERE id = " . (int)$chk['id']);
            $banks = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        }
    }

    // 3. If still no bank account exists at all, auto-create the core institutional bank account 1010
    if (empty($banks)) {
        try {
            $pdo->exec("INSERT INTO accounts (account_code, account_name, account_type, is_cash_or_bank, opening_balance, scope, is_active)
                        VALUES ('1010', 'مرکزی بینک اکاؤنٹ (Main Bank Account)', 'asset', 1, 0.00, 'all', 1)
                        ON DUPLICATE KEY UPDATE is_active = 1, is_cash_or_bank = 1");
            $stmt = $pdo->query("SELECT id, account_code, account_name, user_id FROM accounts WHERE account_code = '1010' LIMIT 1");
            $banks = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) {}
    }

    return $banks;
}

function get_or_create_user_cash_account(PDO $pdo, int $userId): ?array {
    if ($userId <= 0) return null;
    
    // Check if account already exists for this user
    $stmt = $pdo->prepare("SELECT * FROM accounts WHERE user_id = ? AND account_type = 'asset' LIMIT 1");
    $stmt->execute([$userId]);
    $acc = $stmt->fetch();
    if ($acc) {
        return $acc;
    }

    // Fetch user details
    $uStmt = $pdo->prepare("SELECT id, full_name, username FROM users WHERE id = ?");
    $uStmt->execute([$userId]);
    $user = $uStmt->fetch();
    if (!$user) {
        return null;
    }

    $accCode = '1000-' . $userId;
    $accName = 'نقدی کیش - ' . $user['full_name'];

    // Insert new dedicated cash-in-hand sub-account
    $ins = $pdo->prepare("INSERT INTO accounts (account_code, account_name, account_type, user_id, is_cash_or_bank, opening_balance, is_active)
                          VALUES (?, ?, 'asset', ?, 1, 0.00, 1)
                          ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)");
    $ins->execute([$accCode, $accName, $userId]);

    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

function get_user_cash_metrics(PDO $pdo, int $userId): array {
    $cashAcc = get_or_create_user_cash_account($pdo, $userId);
    $accId = (int)($cashAcc['id'] ?? 0);

    // Pending Vouchers (Draft)
    $stmtPending = $pdo->prepare("SELECT 
        COALESCE(SUM(CASE WHEN voucher_type = 'income' THEN total_amount ELSE 0 END), 0) AS pending_income,
        COALESCE(SUM(CASE WHEN voucher_type = 'expense' THEN total_amount ELSE 0 END), 0) AS pending_expense,
        COUNT(*) AS pending_count
        FROM vouchers WHERE created_by = ? AND status = 'draft'");
    $stmtPending->execute([$userId]);
    $p = $stmtPending->fetch() ?: ['pending_income' => 0, 'pending_expense' => 0, 'pending_count' => 0];

    // Approved & Posted Vouchers
    $stmtPosted = $pdo->prepare("SELECT 
        COALESCE(SUM(CASE WHEN voucher_type = 'income' THEN total_amount ELSE 0 END), 0) AS posted_income,
        COALESCE(SUM(CASE WHEN voucher_type = 'expense' THEN total_amount ELSE 0 END), 0) AS posted_expense,
        COUNT(*) AS posted_count
        FROM vouchers WHERE created_by = ? AND status = 'posted'");
    $stmtPosted->execute([$userId]);
    $pos = $stmtPosted->fetch() ?: ['posted_income' => 0, 'posted_expense' => 0, 'posted_count' => 0];

    $pendingIncome = (float)$p['pending_income'];
    $pendingExpense = (float)$p['pending_expense'];
    $postedIncome = (float)$pos['posted_income'];
    $postedExpense = (float)$pos['posted_expense'];

    // Posted Ledger Balance on user's sub-account (normally settles to 0 upon approval)
    $postedAccBalance = 0.0;
    if ($accId > 0) {
        $balStmt = $pdo->prepare("SELECT COALESCE(SUM(jl.debit - jl.credit), 0) 
                                   FROM journal_lines jl 
                                   JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'posted' 
                                   WHERE jl.account_id = ?");
        $balStmt->execute([$accId]);
        $postedAccBalance = (float)$balStmt->fetchColumn();
    }

    // Net pending cash in user's custody awaiting checker approval
    $netPendingCash = $pendingIncome - $pendingExpense;
    $totalCashInHand = $postedAccBalance + $netPendingCash;

    return [
        'cash_account' => $cashAcc,
        'pending_income' => $pendingIncome,
        'pending_expense' => $pendingExpense,
        'pending_count' => (int)$p['pending_count'],
        'posted_income' => $postedIncome,
        'posted_expense' => $postedExpense,
        'posted_count' => (int)$pos['posted_count'],
        'total_income' => $postedIncome + $pendingIncome,
        'total_expense' => $postedExpense + $pendingExpense,
        'posted_account_balance' => $postedAccBalance,
        'net_pending_cash' => $netPendingCash,
        'current_cash_in_hand' => $totalCashInHand,
    ];
}

function approve_voucher_with_settlement(PDO $pdo, int $voucherId, int $approverUserId): array {
    if ($voucherId <= 0) {
        return ['success' => false, 'message' => 'Invalid voucher ID.'];
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT v.*, u.full_name AS submitter_name, u.username AS submitter_user 
                               FROM vouchers v 
                               LEFT JOIN users u ON u.id = v.created_by 
                               WHERE v.id = ? AND v.status = 'draft'");
        $stmt->execute([$voucherId]);
        $v = $stmt->fetch();
        if (!$v) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'واؤچر نہیں ملا یا پہلے سے منظور شدہ ہے۔'];
        }

        // Fetch original journal entry ID
        $jeStmt = $pdo->prepare("SELECT id FROM journal_entries WHERE voucher_id = ? AND status = 'draft'");
        $jeStmt->execute([$voucherId]);
        $jeId = (int)$jeStmt->fetchColumn();

        // Identify the asset account (cash/bank) used in this voucher
        $assetAccountId = 0;
        $isUserAccount = false;
        if ($jeId > 0) {
            $linesStmt = $pdo->prepare("SELECT jl.*, a.user_id, a.account_name, a.account_type, a.is_cash_or_bank 
                                        FROM journal_lines jl 
                                        JOIN accounts a ON a.id = jl.account_id 
                                        WHERE jl.journal_entry_id = ?");
            $linesStmt->execute([$jeId]);
            $lines = $linesStmt->fetchAll();

            foreach ($lines as $line) {
                if ((int)$line['is_cash_or_bank'] === 1 || $line['account_type'] === 'asset') {
                    $assetAccountId = (int)$line['account_id'];
                    if (!empty($line['user_id'])) {
                        $isUserAccount = true;
                    }
                    break;
                }
            }
        }

        // If asset account didn't explicitly have user_id, check if user has a dedicated cash account matching this account
        if (!$isUserAccount && $assetAccountId > 0) {
            $userAcc = get_or_create_user_cash_account($pdo, (int)$v['created_by']);
            if ($userAcc && (int)$userAcc['id'] === $assetAccountId) {
                $isUserAccount = true;
            }
        }

        // 1. Mark voucher and original journal entry as posted
        $pdo->prepare("UPDATE vouchers SET status = 'posted', posted_at = NOW() WHERE id = ?")->execute([$voucherId]);
        if ($jeId > 0) {
            $pdo->prepare("UPDATE journal_entries SET status = 'posted' WHERE id = ?")->execute([$jeId]);
        }

        // 2. Perform Automatic Settlement to Main Cash in Hand if voucher was recorded against a User Cash Account
        $mainCashAcc = get_main_cash_account($pdo);
        if ($isUserAccount && $mainCashAcc && $assetAccountId !== (int)$mainCashAcc['id']) {
            $mainCashId = (int)$mainCashAcc['id'];
            $amount = (float)$v['total_amount'];
            $fundId = !empty($v['fund_id']) ? (int)$v['fund_id'] : null;
            $submitter = $v['submitter_name'] ?: 'Staff';

            if ($v['voucher_type'] === 'income') {
                // Income collected by user:
                // Transfer from User Cash Account to Main Cash Account
                // Debit: Main Cash (increases main cash)
                // Credit: User Cash (resets user custody to 0)
                $settleDesc = "تسویہ وصولی کیش (Settlement to Main Cash) - Voucher #{$v['voucher_no']} ({$submitter})";
                $stmtSettleJe = $pdo->prepare("INSERT INTO journal_entries (voucher_id, entry_date, narration, status, created_by) VALUES (?, ?, ?, 'posted', ?)");
                $stmtSettleJe->execute([$voucherId, $v['voucher_date'], $settleDesc, $approverUserId]);
                $settleJeId = (int)$pdo->lastInsertId();

                $stmtLine = $pdo->prepare("INSERT INTO journal_lines (journal_entry_id, account_id, fund_id, description, debit, credit) VALUES (?, ?, ?, ?, ?, ?)");
                $stmtLine->execute([$settleJeId, $mainCashId, $fundId, "وصولی کیش جمع در مرکزی اکاؤنٹ از {$submitter}", $amount, 0.00]);
                $stmtLine->execute([$settleJeId, $assetAccountId, $fundId, "منتقلی کیش بہ مرکزی اکاؤنٹ (واؤچر {$v['voucher_no']})", 0.00, $amount]);

            } elseif ($v['voucher_type'] === 'expense') {
                // Expense paid by user:
                // Reimbursement from Main Cash Account to User Cash Account
                // Debit: User Cash (reimburses user's pocket back to 0)
                // Credit: Main Cash (deducts from main cash)
                $settleDesc = "تسویہ اخراجات (Reimbursement from Main Cash) - Voucher #{$v['voucher_no']} ({$submitter})";
                $stmtSettleJe = $pdo->prepare("INSERT INTO journal_entries (voucher_id, entry_date, narration, status, created_by) VALUES (?, ?, ?, 'posted', ?)");
                $stmtSettleJe->execute([$voucherId, $v['voucher_date'], $settleDesc, $approverUserId]);
                $settleJeId = (int)$pdo->lastInsertId();

                $stmtLine = $pdo->prepare("INSERT INTO journal_lines (journal_entry_id, account_id, fund_id, description, debit, credit) VALUES (?, ?, ?, ?, ?, ?)");
                $stmtLine->execute([$settleJeId, $assetAccountId, $fundId, "واپسی رقم خرچ بہ {$submitter} (واؤچر {$v['voucher_no']})", $amount, 0.00]);
                $stmtLine->execute([$settleJeId, $mainCashId, $fundId, "ادائیگی خرچ از مرکزی کیش (واؤچر {$v['voucher_no']})", 0.00, $amount]);
            }
        }

        // 3. Audit log
        $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)');
        $log->execute([$approverUserId, 'approve_voucher', 'voucher', $voucherId, json_encode(['voucher_no' => $v['voucher_no'], 'amount' => $v['total_amount']]), $_SERVER['REMOTE_ADDR'] ?? null]);

        $pdo->commit();
        return ['success' => true, 'voucher_no' => $v['voucher_no']];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => $e->getMessage()];
    }
}


function get_org_mode(): string {
    return get_setting('org_type', 'trust');
}

function is_madrasa_mode(): bool {
    return get_org_mode() === 'madrasa';
}

function org_label(string $madrasaText, string $trustText): string {
    return is_madrasa_mode() ? $madrasaText : $trustText;
}

function get_role_display_name(string $roleName, bool $withUrdu = false): string {
    $isMadrasa = is_madrasa_mode();
    $roleClean = trim($roleName);

    switch ($roleClean) {
        case 'Regional Director':
        case 'Director':
            if ($isMadrasa) {
                return $withUrdu ? 'مہتمم / ناظمِ شاخ (Mohtamim / Nazim)' : 'مہتمم / ناظم';
            } else {
                return $withUrdu ? 'صوبائی ڈائریکٹر (Director)' : 'Director';
            }
        case 'User / Cashier':
        case 'Cashier':
            if ($isMadrasa) {
                return $withUrdu ? 'خزانچی / ناظمِ مال (Khazanchi / Cashier)' : 'خزانچی / ناظمِ مال';
            } else {
                return $withUrdu ? 'کیشیئر / فیلڈ اسٹاف (Cashier)' : 'User / Cashier';
            }
        case 'Approver / Checker':
        case 'Approver':
        case 'Checker':
            if ($isMadrasa) {
                return $withUrdu ? 'نائب مہتمم / نگرانِ پڑتال (Naib Mohtamim)' : 'نائب مہتمم / نگران';
            } else {
                return $withUrdu ? 'منظور کنندہ / چیکر (Approver / Checker)' : 'Checker';
            }
        case 'Super Admin':
            if ($isMadrasa) {
                return $withUrdu ? 'صدر / مہتممِ اعلیٰ (Super Admin)' : 'صدر / مہتممِ اعلیٰ';
            } else {
                return $withUrdu ? 'مرکزی ایڈمن (Super Admin)' : 'Super Admin';
            }
        case 'Viewer':
            if ($isMadrasa) {
                return $withUrdu ? 'ناظر / مبصر (Viewer)' : 'ناظر (Viewer)';
            } else {
                return $withUrdu ? 'ویور / مبصر (Viewer)' : 'Viewer';
            }
        default:
            return $roleName;
    }
}

function get_org_subtitle(): string {
    $custom = trim(get_setting('org_subtitle', ''));
    if (!empty($custom)) {
        return $custom;
    }
    if (is_madrasa_mode()) {
        return 'Islamic Seminary <span class="ur">(دینی درسگاہ و تعلیمی ادارہ)</span>';
    } else {
        return 'Welfare Organization <span class="ur">(فلاحی و رفاہی تنظیم)</span>';
    }
}

function calculate_distance_meters(?float $lat1, ?float $lon1, ?float $lat2, ?float $lon2): ?float {
    if ($lat1 === null || $lon1 === null || $lat2 === null || $lon2 === null) {
        return null;
    }
    if ($lat1 == 0 && $lon1 == 0) return null;
    if ($lat2 == 0 && $lon2 == 0) return null;
    $earthRadius = 6371000; // in meters
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return (float)round($earthRadius * $c);
}

function get_user_pending_tasks_count(int $userId): int {
    global $pdo;
    if (!$pdo || $userId <= 0) return 0;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND status IN ('pending', 'in_progress')");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

function get_all_active_tasks_count(): int {
    global $pdo;
    if (!$pdo) return 0;
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status IN ('pending', 'in_progress')")->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

function add_notification(int $userId, string $title, string $message, ?string $linkUrl = null, string $type = 'task'): bool {
    global $pdo;
    if (!$pdo || $userId <= 0 || empty($title)) return false;
    try {
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, link_url, type, is_read) VALUES (?, ?, ?, ?, ?, 0)");
        return $stmt->execute([$userId, $title, $message, $linkUrl, $type]);
    } catch (Exception $e) {
        return false;
    }
}

function get_user_unread_notifications(int $userId, int $limit = 5): array {
    global $pdo;
    if (!$pdo || $userId <= 0) return [];
    try {
        $limit = max(1, min(20, (int)$limit));
        $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY id DESC LIMIT {$limit}");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

function get_user_unread_notifications_count(int $userId): int {
    global $pdo;
    if (!$pdo || $userId <= 0) return 0;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

function mark_all_notifications_read(int $userId): bool {
    global $pdo;
    if (!$pdo || $userId <= 0) return false;
    try {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        return $stmt->execute([$userId]);
    } catch (Exception $e) {
        return false;
    }
}

function is_urdu_enabled(): bool {
    return get_setting('ui_language', 'bilingual') !== 'english';
}

function format_ui_text(?string $text): string {
    if ($text === null || $text === '') {
        return '';
    }
    if (is_urdu_enabled()) {
        return $text;
    }

    $text = trim($text);

    // 1. Convert user cash account prefix "نقدی کیش - " to "Cash in Hand - "
    if (preg_match('/^[\x{0600}-\x{06FF}\s]+\s*-\s*(.+)$/u', $text, $m)) {
        return 'Cash in Hand - ' . trim($m[1]);
    }

    // Check for optional leading code like "4130 - " or "1000: "
    $prefix = '';
    if (preg_match('/^([A-Z0-9\-]+(?:\s*[-:]\s*))(.+)$/u', $text, $pm)) {
        $prefix = $pm[1];
        $text = trim($pm[2]);
    }

    // 2. Pattern: "Urdu (English)" -> "English"
    if (preg_match('/^[\x{0600}-\x{06FF}\s\d\/\-،؛_&]+\s*\(([^)]*[a-zA-Z][^)]*)\)$/u', $text, $m)) {
        return $prefix . trim($m[1]);
    }

    // 3. Pattern: "English (Urdu)" -> "English"
    if (preg_match('/^([a-zA-Z\d\s\/\-_&.,\'"]+)\s*\([\x{0600}-\x{06FF}\s\d\/\-،؛_]+\)$/u', $text, $m)) {
        return $prefix . trim($m[1]);
    }

    // 4. Any remaining parenthetical Urdu: "Text (Urdu)" -> "Text"
    $text = preg_replace('/\s*\([\x{0600}-\x{06FF}\s\d\/\-،؛_–—:]+\)/u', '', $text);

    // 5. Replace any standalone "نقدی کیش" with "Cash in Hand"
    $text = str_replace('نقدی کیش', 'Cash in Hand', $text);

    return $prefix . trim($text);
}

function render_app_footer(string $madrasaName = ''): string {
    $name = $madrasaName !== '' ? $madrasaName : (function_exists('get_setting') ? get_setting('madrasa_name', 'Trust Accounts') : 'Trust Accounts');
    $year = date('Y');
    return '
    <footer class="app-footer no-print">
        <div class="footer-container">
            <div class="footer-org-info">
                &copy; ' . htmlspecialchars($year) . ' <b>' . htmlspecialchars($name) . '</b> &middot; All Rights Reserved
            </div>
            <div class="footer-vendor-info">
                <span>Developed &amp; Supported by</span>
                <strong class="vendor-highlight">SoftifyX Technologies</strong>
                <span class="footer-sep">&middot;</span>
                <a href="tel:+923317240256" class="footer-vendor-link" title="Call / WhatsApp Support">📞 +92 331 7240256</a>
                <span class="footer-sep">&middot;</span>
                <a href="mailto:support@softifyx.com" class="footer-vendor-link" title="Email Support">✉️ support@softifyx.com</a>
            </div>
        </div>
    </footer>';
}
