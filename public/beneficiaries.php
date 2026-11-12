<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();
require_permission('beneficiaries.view');

$activePage = 'beneficiaries';
$error = '';
$success = '';

$currentUser = current_user();
$userId = (int)($currentUser['id'] ?? 0);
$isSuperAdmin = in_array($currentUser['role'] ?? '', ['Super Admin', 'Administrator'], true);
$madrasaName = get_setting('madrasa_name', 'Trust Accounts');
$currency = get_setting('currency', 'PKR');
$isMadrasa = is_madrasa_mode();

// Handle Delete Beneficiary
if (isset($_GET['delete_id'])) {
    if (!has_permission('beneficiaries.manage')) {
        $error = 'صرف مجاز صارفین کو طلبہ و مستحقین ڈیلیٹ کرنے کی اجازت ہے۔';
    } else {
        $delId = (int)$_GET['delete_id'];
        $token = $_GET['token'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            $error = 'Invalid security token for deletion.';
        } else {
            try {
                // Unlink from vouchers first
                $pdo->prepare('UPDATE vouchers SET beneficiary_id = NULL WHERE beneficiary_id = ?')->execute([$delId]);
                $stmtDel = $pdo->prepare('DELETE FROM beneficiaries WHERE id = ?');
                $stmtDel->execute([$delId]);
                header('Location: beneficiaries.php?success=deleted');
                exit;
            } catch (Exception $e) {
                $error = 'Delete failed: ' . $e->getMessage();
            }
        }
    }
}

// Quick Pay Stipend (One-Click Modal Disbursement directly from Beneficiaries List)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quick_pay_stipend') {
    verify_csrf();
    if (!has_permission('vouchers.create') && !has_permission('expenses.create') && !has_permission('expense.create') && !has_permission('beneficiaries.manage') && !can_approve()) {
        $error = 'آپ کے پاس ادائیگی واؤچر جاری کرنے کا اختیار نہیں ہے۔';
    } else {
        $benId = (int)($_POST['beneficiary_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $fundId = (int)($_POST['fund_id'] ?? 0);
        $expenseAccountId = (int)($_POST['expense_account_id'] ?? 0);
        $date = trim($_POST['voucher_date'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        $paymentMethod = trim($_POST['payment_method'] ?? 'cash');
        $referenceNo = trim($_POST['reference_no'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $assetAccountId = (int)($_POST['asset_account_id'] ?? 0);

        $userCashAcc = get_or_create_user_cash_account($pdo, $userId);
        $userCashAccId = (int)($userCashAcc['id'] ?? 0);

        if ($paymentMethod === 'cash') {
            if (!can_approve() || $assetAccountId <= 0) {
                $assetAccountId = $userCashAccId;
            } else {
                $accCheck = $pdo->prepare("SELECT id FROM accounts WHERE id = ? AND is_cash_or_bank = 1 AND is_active = 1");
                $accCheck->execute([$assetAccountId]);
                if (!$accCheck->fetchColumn()) {
                    $assetAccountId = $userCashAccId;
                }
            }
        } else {
            // Bank / Online / Cheque
            $bankCheck = $pdo->prepare("SELECT id FROM accounts WHERE id = ? AND is_cash_or_bank = 1 AND is_active = 1 AND (user_id IS NULL OR account_code = '1010' OR account_name LIKE '%bank%' OR account_name LIKE '%بینک%')");
            $bankCheck->execute([$assetAccountId]);
            if (!$bankCheck->fetchColumn()) {
                $bankDef = $pdo->query("SELECT id FROM accounts WHERE is_cash_or_bank = 1 AND is_active = 1 AND (account_code = '1010' OR account_name LIKE '%bank%' OR account_name LIKE '%بینک%') ORDER BY (account_code = '1010') DESC, id ASC LIMIT 1")->fetchColumn();
                $assetAccountId = (int)($bankDef ?: $userCashAccId);
            }
        }
        if ($assetAccountId <= 0) {
            $assetAccountId = $userCashAccId;
        }

        if ($expenseAccountId <= 0) {
            $defExp = $pdo->query("SELECT id FROM accounts WHERE account_type = 'expense' AND is_active = 1 ORDER BY (account_code = '5010') DESC, (account_code = '5050') DESC, id ASC LIMIT 1")->fetchColumn();
            $expenseAccountId = (int)($defExp ?: 0);
        }

        if ($amount <= 0 || $benId <= 0) {
            $error = 'براہ کرم درست رقم درج کریں۔';
        } elseif ($fundId <= 0) {
            $error = 'براہ کرم متعلقہ فنڈ منتخب کریں۔';
        } elseif ($expenseAccountId <= 0) {
            $error = 'براہ کرم خرچ کی مد منتخب کریں۔';
        } else {
            try {
                $pdo->beginTransaction();

                $benRow = $pdo->query("SELECT full_name, phone, category, department_class FROM beneficiaries WHERE id = {$benId}")->fetch();
                $payeeName = $benRow['full_name'] ?? 'مستحق';

                $prefix = 'PV-' . date('Ym', strtotime($date)) . '-';
                $lastNo = $pdo->query("SELECT voucher_no FROM vouchers WHERE voucher_no LIKE '{$prefix}%' ORDER BY id DESC LIMIT 1")->fetchColumn();
                $nextSeq = 1;
                if ($lastNo && preg_match('/-(\d+)$/', $lastNo, $m)) {
                    $nextSeq = (int)$m[1] + 1;
                }
                $voucherNo = $prefix . str_pad((string)$nextSeq, 4, '0', STR_PAD_LEFT);

                $isDirectPost = has_permission('voucher.direct_post') || can_approve();
                $voucherStatus = $isDirectPost ? 'posted' : 'draft';
                $postedAt = $isDirectPost ? date('Y-m-d H:i:s') : null;

                $userRegId = function_exists('get_user_region_id') ? get_user_region_id($userId) : 0;
                $voucherRegionId = $userRegId > 0 ? $userRegId : null;

                $stmtVoucher = $pdo->prepare('INSERT INTO vouchers (voucher_no, voucher_type, voucher_date, fund_id, region_id, beneficiary_id, payee_name, payment_method, reference_no, description, total_amount, status, created_by, posted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmtVoucher->execute([
                    $voucherNo, 'expense', $date, $fundId, $voucherRegionId, $benId,
                    $payeeName, $paymentMethod, $referenceNo, $description, $amount,
                    $voucherStatus, $userId, $postedAt
                ]);
                $voucherId = (int)$pdo->lastInsertId();

                $narration = $description ?: ("Stipend/Salary disbursement to " . $payeeName);
                $stmtJe = $pdo->prepare('INSERT INTO journal_entries (voucher_id, region_id, entry_date, narration, status, created_by) VALUES (?, ?, ?, ?, ?, ?)');
                $stmtJe->execute([$voucherId, $voucherRegionId, $date, $narration, $voucherStatus, $userId]);
                $jeId = (int)$pdo->lastInsertId();

                // Double-Entry Journal Lines
                // Debit: Expense Account
                $stmtLine = $pdo->prepare('INSERT INTO journal_lines (journal_entry_id, account_id, fund_id, description, debit, credit) VALUES (?, ?, ?, ?, ?, ?)');
                $stmtLine->execute([$jeId, $expenseAccountId, $fundId, 'Disbursement: ' . $narration, $amount, 0.00]);
                // Credit: Cash / Bank Asset Account
                $stmtLine->execute([$jeId, $assetAccountId, $fundId, 'Paid via: ' . $narration, 0.00, $amount]);

                $pdo->commit();

                header('Location: beneficiaries.php?success=stipend_paid&paid_voucher_id=' . $voucherId);
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'ادائیگی واؤچر بنانے میں خرابی: ' . $e->getMessage();
            }
        }
    }
}

// Handle Add or Edit Beneficiary
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!has_permission('beneficiaries.manage')) {
        $error = 'آپ کے پاس نئے ریکارڈز شامل کرنے یا تبدیل کرنے کا اختیار نہیں ہے۔';
    } else {
        verify_csrf();
        $action = $_POST['action'];
        $fullName = trim($_POST['full_name'] ?? '');
        $fatherName = trim($_POST['father_name'] ?? '');
        $category = in_array($_POST['category'] ?? '', ['student', 'beneficiary', 'orphan', 'staff'], true) ? $_POST['category'] : 'student';
        $regNo = trim($_POST['reg_no'] ?? '');
        $deptClass = trim($_POST['department_class'] ?? '');
        $gender = in_array($_POST['gender'] ?? '', ['male', 'female'], true) ? $_POST['gender'] : 'male';
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $guardianCnic = trim($_POST['guardian_cnic'] ?? '');
        $fundId = !empty($_POST['assigned_fund_id']) ? (int)$_POST['assigned_fund_id'] : null;
        $donorId = !empty($_POST['assigned_donor_id']) ? (int)$_POST['assigned_donor_id'] : null;
        $stipendAmount = (float)($_POST['monthly_stipend_amount'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['active', 'graduated', 'inactive'], true) ? $_POST['status'] : 'active';
        $admissionDate = !empty($_POST['admission_date']) ? $_POST['admission_date'] : null;
        $notes = trim($_POST['notes'] ?? '');

        if (empty($fullName)) {
            $error = 'براہ کرم مکمل نام درج کریں۔ (Please enter full name).';
        } else {
            try {
                if ($action === 'save_beneficiary') {
                    $stmt = $pdo->prepare('INSERT INTO beneficiaries (category, reg_no, full_name, father_name, department_class, gender, phone, address, guardian_cnic, assigned_fund_id, assigned_donor_id, monthly_stipend_amount, status, admission_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$category, $regNo ?: null, $fullName, $fatherName ?: null, $deptClass ?: null, $gender, $phone ?: null, $address ?: null, $guardianCnic ?: null, $fundId, $donorId, $stipendAmount, $status, $admissionDate, $notes ?: null]);
                    header('Location: beneficiaries.php?success=created');
                    exit;
                } elseif ($action === 'update_beneficiary') {
                    $benId = (int)($_POST['beneficiary_id'] ?? 0);
                    if ($benId <= 0) {
                        $error = 'Invalid beneficiary record.';
                    } else {
                        $stmt = $pdo->prepare('UPDATE beneficiaries SET category = ?, reg_no = ?, full_name = ?, father_name = ?, department_class = ?, gender = ?, phone = ?, address = ?, guardian_cnic = ?, assigned_fund_id = ?, assigned_donor_id = ?, monthly_stipend_amount = ?, status = ?, admission_date = ?, notes = ? WHERE id = ?');
                        $stmt->execute([$category, $regNo ?: null, $fullName, $fatherName ?: null, $deptClass ?: null, $gender, $phone ?: null, $address ?: null, $guardianCnic ?: null, $fundId, $donorId, $stipendAmount, $status, $admissionDate, $notes ?: null, $benId]);
                        header('Location: beneficiaries.php?success=updated');
                        exit;
                    }
                }
            } catch (Exception $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Edit Mode Data
$editBen = null;
if (isset($_GET['edit_id']) && has_permission('beneficiaries.manage')) {
    $stmtEdit = $pdo->prepare('SELECT * FROM beneficiaries WHERE id = ?');
    $stmtEdit->execute([(int)$_GET['edit_id']]);
    $editBen = $stmtEdit->fetch();
}
$showForm = !empty($editBen) || (!empty($error) && $_SERVER['REQUEST_METHOD'] === 'POST');

// Filter & Search
$filterCat = trim($_GET['category'] ?? 'all');
$filterStatus = trim($_GET['status'] ?? 'all');
$search = trim($_GET['search'] ?? '');

$query = "SELECT b.*, f.name AS fund_name, f.fund_code, d.donor_name,
          COALESCE((SELECT SUM(v.total_amount) FROM vouchers v WHERE v.beneficiary_id = b.id AND v.status = 'posted'), 0) AS total_disbursed,
          COALESCE((SELECT COUNT(v.id) FROM vouchers v WHERE v.beneficiary_id = b.id AND v.status = 'posted'), 0) AS vouchers_count
          FROM beneficiaries b
          LEFT JOIN funds f ON f.id = b.assigned_fund_id
          LEFT JOIN donors d ON d.id = b.assigned_donor_id
          WHERE 1=1";
$params = [];

if ($filterCat !== 'all') {
    $query .= " AND b.category = ?";
    $params[] = $filterCat;
}
if ($filterStatus !== 'all') {
    $query .= " AND b.status = ?";
    $params[] = $filterStatus;
}
if (!empty($search)) {
    $query .= " AND (b.full_name LIKE ? OR b.father_name LIKE ? OR b.reg_no LIKE ? OR b.phone LIKE ? OR b.department_class LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
$query .= " ORDER BY b.id DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$beneficiaries = $stmt->fetchAll();

// KPI Stats
$totalActiveCount = (int)$pdo->query("SELECT COUNT(*) FROM beneficiaries WHERE status = 'active'")->fetchColumn();
$totalMonthlyBudget = (float)$pdo->query("SELECT COALESCE(SUM(monthly_stipend_amount), 0) FROM beneficiaries WHERE status = 'active'")->fetchColumn();
$totalOrphansCount = (int)$pdo->query("SELECT COUNT(*) FROM beneficiaries WHERE category = 'orphan' AND status = 'active'")->fetchColumn();
$totalDisbursedOverall = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM vouchers WHERE beneficiary_id IS NOT NULL AND status = 'posted'")->fetchColumn();

// Fetch active funds and donors for dropdowns
$funds = get_user_allowed_funds();
$donors = $pdo->query("SELECT id, donor_name, phone FROM donors ORDER BY donor_name ASC")->fetchAll();

// Fetch accounts for Quick Pay Stipend Modal
$userCashAcc = get_or_create_user_cash_account($pdo, (int)$userId);
$bankAccounts = function_exists('get_or_create_bank_accounts') ? get_or_create_bank_accounts($pdo) : [];
$allAssetAccounts = $pdo->query("SELECT id, account_code, account_name, user_id FROM accounts WHERE is_cash_or_bank=1 AND is_active=1 ORDER BY (account_code = '1000') DESC, (account_code = '1010') DESC, account_code ASC")->fetchAll() ?: [];

$bankIds = array_map(fn($b) => (int)$b['id'], $bankAccounts);
$otherCashAccounts = [];
foreach ($allAssetAccounts as $acc) {
    if (in_array((int)$acc['id'], $bankIds, true)) continue;
    if (!empty($acc['user_id']) && (int)$acc['user_id'] === (int)$userId) continue;
    $otherCashAccounts[] = $acc;
}

$currOrgMode = function_exists('get_org_mode') ? get_org_mode() : 'trust';
$expenseAccounts = $pdo->query("SELECT id, account_code, account_name FROM accounts WHERE account_type='expense' AND is_active=1 AND scope IN ('all', '{$currOrgMode}') ORDER BY (account_code='5010') DESC, (account_code='5050') DESC, account_code ASC")->fetchAll() ?: [];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= $isMadrasa ? 'Students & Beneficiaries' : 'Beneficiaries & Welfare' ?> | <?=e($madrasaName)?></title>
    <link rel="stylesheet" href="assets/style.css?v=<?=css_ver()?>">
</head>
<body>
<div class="app-shell">
    <?php require __DIR__ . '/../app/sidebar.php'; ?>

    <main class="main">
        <header class="topbar">
            <div>
                <span class="eyebrow"><?= $isMadrasa ? 'SEMINARY & STUDENTS' : 'WELFARE & BENEFICIARIES' ?></span>
                <h1>
                    <?= $isMadrasa ? 'Students & Beneficiaries <span class="ur">(طلبہ و مستحقین رجسٹری)</span>' : 'Beneficiaries & Welfare <span class="ur">(مستحقین و فلاحی خاندان)</span>' ?>
                </h1>
            </div>
            <div class="btn-row">
                <?php if (has_permission('beneficiaries.manage')): ?>
                    <?php if ($editBen): ?>
                        <a href="beneficiaries.php" class="btn secondary sm">✕ Cancel Editing <span class="ur">(منسوخ کریں)</span></a>
                    <?php else: ?>
                        <button type="button" class="btn blue sm" id="toggleBenBtn" onclick="toggleBenForm()">
                            ＋ <?= $isMadrasa ? 'Register Student / Beneficiary' : 'Register New Beneficiary' ?>
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </header>

        <?php if (!empty($_GET['paid_voucher_id']) && ($_GET['success'] ?? '') === 'stipend_paid'): 
            $paidVid = (int)$_GET['paid_voucher_id'];
            $paidVoucher = $pdo->query("SELECT v.*, f.name AS fund_name, b.full_name AS ben_name FROM vouchers v LEFT JOIN funds f ON f.id = v.fund_id LEFT JOIN beneficiaries b ON b.id = v.beneficiary_id WHERE v.id = {$paidVid}")->fetch();
        ?>
            <div class="no-print" style="background:#ecfdf5;border:1.5px solid #10b981;border-radius:10px;padding:14px 18px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;box-shadow:0 2px 6px rgba(16,185,129,0.15);">
                <div style="display:flex;align-items:center;gap:12px;">
                    <span style="font-size:28px;">🎉</span>
                    <div>
                        <strong style="color:#065f46;font-size:15px;">وظیفہ / تنخواہ ادائیگی واؤچر کامیابی سے جاری ہو گیا! (Stipend Disbursed)</strong>
                        <div style="font-size:12.5px;color:#047857;margin-top:2px;">
                            واؤچر نمبر: <b style="font-family:monospace;font-size:13.5px;"><?=e($paidVoucher['voucher_no'] ?? '')?></b> &bull; وصول کنندہ: <b><?=e($paidVoucher['payee_name'] ?? ($paidVoucher['ben_name'] ?? ''))?></b> &bull; رقم: <b><?=money($paidVoucher['total_amount'] ?? 0)?></b>
                        </div>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <a href="print_voucher.php?id=<?=$paidVid?>&autoprint=1" target="_blank" class="btn sm" style="background:#059669;color:#ffffff;border:none;font-weight:700;padding:8px 16px;border-radius:6px;display:inline-flex;align-items:center;gap:6px;box-shadow:0 1px 2px rgba(0,0,0,0.12);text-decoration:none;">
                        🖨️ Print Payment Slip (رسید پرنٹ کریں)
                    </a>
                </div>
            </div>
        <?php elseif (isset($_GET['success'])): ?>
            <div class="alert success">
                <?php
                    if ($_GET['success'] === 'created') echo '✓ Record successfully registered!';
                    elseif ($_GET['success'] === 'updated') echo '✓ Details updated successfully!';
                    elseif ($_GET['success'] === 'deleted') echo '✓ Record removed successfully!';
                    else echo 'Operation completed successfully!';
                ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert error"><?=e($error)?></div>
        <?php endif; ?>

        <!-- Stats KPI Overview -->
        <section class="stats-grid" style="margin:22px 0 24px;">
            <article class="stat-card">
                <div class="stat-top"><span>Active Enrolled / Registered</span><span class="stat-icon blue">🎓</span></div>
                <strong><?=$totalActiveCount?></strong>
                <small><?= $isMadrasa ? 'Active students & recipients' : 'Registered beneficiaries' ?></small>
            </article>
            <article class="stat-card">
                <div class="stat-top"><span>Monthly Stipend / Budget</span><span class="stat-icon green">💰</span></div>
                <strong><?=money($totalMonthlyBudget)?></strong>
                <small>Monthly recurring sponsorship</small>
            </article>
            <article class="stat-card">
                <div class="stat-top"><span>Orphans &amp; Deserving</span><span class="stat-icon purple">🤝</span></div>
                <strong><?=$totalOrphansCount?></strong>
                <small>Special kafalat beneficiaries</small>
            </article>
            <article class="stat-card">
                <div class="stat-top"><span>Total Disbursed to Date</span><span class="stat-icon red">↘</span></div>
                <strong style="color:#b91c1c;"><?=money($totalDisbursedOverall)?></strong>
                <small>Recorded in vouchers</small>
            </article>
        </section>

        <!-- Beneficiary Form (Add / Edit) -->
        <?php if (has_permission('beneficiaries.manage')): ?>
        <article class="form-card" id="benFormCard" style="<?= $showForm ? 'margin:20px 0 24px;' : 'display:none; margin:20px 0 24px;' ?>">
            <div class="form-card-header" style="display:flex;justify-content:space-between;align-items:flex-start;">
                <div>
                    <h3><?= $editBen ? '✏️ Edit Record <span class="ur">(معلومات میں ترمیم)</span> — ' . e($editBen['full_name']) : '＋ Register New ' . ($isMadrasa ? 'Student / Beneficiary' : 'Beneficiary') . ' <span class="ur">(نیا اندراج)</span>' ?></h3>
                    <p>Keep track of enrolled students, orphans, stipend recipients, and welfare families</p>
                </div>
                <?php if (!$editBen): ?>
                    <button type="button" class="btn sm secondary" onclick="toggleBenForm(false)" style="padding:4px 10px;" title="Close">✕</button>
                <?php endif; ?>
            </div>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                <input type="hidden" name="action" value="<?= $editBen ? 'update_beneficiary' : 'save_beneficiary' ?>">
                <?php if ($editBen): ?>
                    <input type="hidden" name="beneficiary_id" value="<?=$editBen['id']?>">
                <?php endif; ?>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Category <span class="ur">(نوعیت / زمرہ)</span> *</label>
                        <select name="category" required>
                            <option value="student" <?=($editBen['category'] ?? '') === 'student' ? 'selected' : ''?>>🎓 Regular Student (طالب علم)</option>
                            <option value="orphan" <?=($editBen['category'] ?? '') === 'orphan' ? 'selected' : ''?>>🤝 Orphan / Yateem (یتیم طالب علم / بچہ)</option>
                            <option value="beneficiary" <?=($editBen['category'] ?? '') === 'beneficiary' ? 'selected' : ''?>>🏠 Deserving Family / Widow (مستحق خاندان / بیوہ)</option>
                            <option value="staff" <?=($editBen['category'] ?? '') === 'staff' ? 'selected' : ''?>>👨‍🏫 Staff / Teacher (استاذ / ملازم)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Full Name <span class="ur">(مکمل نام)</span> *</label>
                        <input name="full_name" required placeholder="e.g. Muhammad Anas" value="<?=e($editBen['full_name'] ?? '')?>">
                    </div>

                    <div class="form-group">
                        <label>Father / Guardian Name <span class="ur">(والد / سرپرست کا نام)</span></label>
                        <input name="father_name" placeholder="e.g. Abdul Rahman" value="<?=e($editBen['father_name'] ?? '')?>">
                    </div>

                    <div class="form-group">
                        <label>Roll / Registration No <span class="ur">(رول نمبر / رجسٹریشن)</span></label>
                        <input name="reg_no" placeholder="e.g. REG-2026-042" value="<?=e($editBen['reg_no'] ?? '')?>">
                    </div>

                    <div class="form-group">
                        <label><?= $isMadrasa ? 'Department / Class <span class="ur">(شعبہ / کلاس)</span>' : 'Assistance Category <span class="ur">(شعبہ امداد)</span>' ?></label>
                        <input name="department_class" placeholder="<?= $isMadrasa ? 'e.g. Hifz Class A / Darse Nizami Year 2' : 'e.g. Monthly Ration Card / Medical Support' ?>" value="<?=e($editBen['department_class'] ?? '')?>">
                    </div>

                    <div class="form-group">
                        <label>Gender <span class="ur">(جنس)</span></label>
                        <select name="gender">
                            <option value="male" <?=($editBen['gender'] ?? 'male') === 'male' ? 'selected' : ''?>>Male (مرد / لڑکا)</option>
                            <option value="female" <?=($editBen['gender'] ?? '') === 'female' ? 'selected' : ''?>>Female (عورت / لڑکی)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Contact Phone <span class="ur">(رابطہ نمبر)</span></label>
                        <input name="phone" placeholder="0300-1234567" value="<?=e($editBen['phone'] ?? '')?>">
                    </div>

                    <div class="form-group">
                        <label>Guardian CNIC <span class="ur">(سرپرست کا شناختی کارڈ)</span></label>
                        <input name="guardian_cnic" placeholder="12345-1234567-1" value="<?=e($editBen['guardian_cnic'] ?? '')?>">
                    </div>

                    <div class="form-group">
                        <label>Assigned Fund <span class="ur">(متعلقہ فنڈ)</span></label>
                        <select name="assigned_fund_id">
                            <option value="">-- No Specific Fund --</option>
                            <?php foreach ($funds as $f): ?>
                                <option value="<?=$f['id']?>" <?=($editBen && (int)($editBen['assigned_fund_id'] ?? 0) === (int)$f['id']) ? 'selected' : ''?>>
                                    <?=e($f['name'])?> (<?=e($f['fund_code'])?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Sponsoring Donor <span class="ur">(کفیل معاون)</span></label>
                        <select name="assigned_donor_id">
                            <option value="">-- Unassigned / General --</option>
                            <?php foreach ($donors as $d): ?>
                                <option value="<?=$d['id']?>" <?=($editBen && (int)($editBen['assigned_donor_id'] ?? 0) === (int)$d['id']) ? 'selected' : ''?>>
                                    <?=e($d['donor_name'])?> <?= $d['phone'] ? '('.e($d['phone']).')' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Monthly Stipend / Sponsorship <span class="ur">(ماہانہ وظیفہ / کفالت رقم PKR)</span></label>
                        <input type="number" step="0.01" min="0" name="monthly_stipend_amount" placeholder="0.00" value="<?=e($editBen['monthly_stipend_amount'] ?? '')?>" style="font-weight:700; color:#15803d;">
                    </div>

                    <div class="form-group">
                        <label>Status <span class="ur">(حیثیت)</span></label>
                        <select name="status">
                            <option value="active" <?=($editBen['status'] ?? 'active') === 'active' ? 'selected' : ''?>>🟢 Active (فعال / زیرِ تعلیم)</option>
                            <option value="graduated" <?=($editBen['status'] ?? '') === 'graduated' ? 'selected' : ''?>>🎓 Graduated / Completed (فارغ التحصیل)</option>
                            <option value="inactive" <?=($editBen['status'] ?? '') === 'inactive' ? 'selected' : ''?>>⚪ Inactive (غیر فعال)</option>
                        </select>
                    </div>

                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Address &amp; Location <span class="ur">(مکمل پتہ)</span></label>
                        <input name="address" placeholder="Village / Tehsil / City..." value="<?=e($editBen['address'] ?? '')?>">
                    </div>

                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Notes / Details <span class="ur">(اضافی نوٹس و تفصیلات)</span></label>
                        <input name="notes" placeholder="e.g. Free boarding, Books provided, Special ration case..." value="<?=e($editBen['notes'] ?? '')?>">
                    </div>
                </div>

                <div class="btn-row" style="margin-top:14px;">
                    <button class="btn blue" type="submit"><?= $editBen ? '💾 Update Record <span class="ur">(محفوظ کریں)</span>' : '💾 Save Record <span class="ur">(محفوظ کریں)</span>' ?></button>
                    <?php if ($editBen): ?>
                        <a href="beneficiaries.php" class="btn secondary">✕ Cancel Editing</a>
                    <?php else: ?>
                        <button class="btn secondary" type="button" onclick="toggleBenForm(false)">✕ Cancel</button>
                    <?php endif; ?>
                </div>
            </form>
        </article>
        <?php endif; ?>

        <!-- Beneficiaries Directory Panel -->
        <article class="panel">
            <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
                <div>
                    <h3><?= $isMadrasa ? 'Enrolled Students & Beneficiaries List' : 'Beneficiaries & Welfare Directory' ?></h3>
                    <p>Track student sponsorships, stipends, and welfare disbursements</p>
                </div>
            </div>

            <!-- Filter & Search Bar -->
            <form method="get" class="filter-bar" style="margin-bottom:18px;">
                <input name="search" placeholder="Search by name, roll no, father name, class..." value="<?=e($search)?>" style="flex:2;min-width:180px;">
                <select name="category" onchange="this.form.submit()" style="flex:1;min-width:160px;">
                    <option value="all">All Categories (تمام زمرے)</option>
                    <option value="student" <?=$filterCat==='student'?'selected':''?>>🎓 Regular Students</option>
                    <option value="orphan" <?=$filterCat==='orphan'?'selected':''?>>🤝 Orphans / Yateem</option>
                    <option value="beneficiary" <?=$filterCat==='beneficiary'?'selected':''?>>🏠 Deserving Families</option>
                    <option value="staff" <?=$filterCat==='staff'?'selected':''?>>👨‍🏫 Staff / Teachers</option>
                </select>
                <select name="status" onchange="this.form.submit()" style="flex:1;min-width:140px;">
                    <option value="all">All Statuses (تمام)</option>
                    <option value="active" <?=$filterStatus==='active'?'selected':''?>>Active Only</option>
                    <option value="graduated" <?=$filterStatus==='graduated'?'selected':''?>>Graduated</option>
                    <option value="inactive" <?=$filterStatus==='inactive'?'selected':''?>>Inactive</option>
                </select>
                <button class="btn secondary sm" type="submit">Filter</button>
                <?php if ($search || $filterCat !== 'all' || $filterStatus !== 'all'): ?>
                    <a href="beneficiaries.php" class="btn secondary sm" style="text-decoration:none;">Reset</a>
                <?php endif; ?>
            </form>

            <?php if (empty($beneficiaries)): ?>
                <div class="empty-state" style="padding:40px 20px;">
                    <div class="empty-icon">🎓</div>
                    <b>No records found</b>
                    <p>Register students, orphans or welfare recipients using the button above.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Reg / Roll #</th>
                                <th>Name &amp; Guardian</th>
                                <th>Category</th>
                                <th>Dept / Class</th>
                                <th>Assigned Fund</th>
                                <th>Monthly Stipend</th>
                                <th>Total Disbursed</th>
                                <th>Status</th>
                                <th class="right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($beneficiaries as $b): 
                                $catBadges = [
                                    'student' => ['bg' => '#e0e7ff', 'col' => '#4338ca', 'label' => '🎓 Student'],
                                    'orphan' => ['bg' => '#fef3c7', 'col' => '#b45309', 'label' => '🤝 Orphan'],
                                    'beneficiary' => ['bg' => '#dcfce7', 'col' => '#15803d', 'label' => '🏠 Welfare'],
                                    'staff' => ['bg' => '#f3e8ff', 'col' => '#7e22ce', 'label' => '👨‍🏫 Staff'],
                                ];
                                $catInfo = $catBadges[$b['category']] ?? ['bg' => '#f1f5f9', 'col' => '#475569', 'label' => $b['category']];
                            ?>
                                <tr>
                                    <td>
                                        <span style="font-family:monospace;font-weight:700;color:#0f172a;">
                                            <?=e($b['reg_no'] ?: ('#' . $b['id']))?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong style="display:block;color:#0f172a;font-size:13.5px;"><?=e($b['full_name'])?></strong>
                                        <?php if ($b['father_name']): ?>
                                            <span style="font-size:11.5px;color:#64748b;">S/O: <?=e($b['father_name'])?></span>
                                        <?php endif; ?>
                                        <?php if ($b['phone']): ?>
                                            <span style="font-size:11px;color:#0284c7;display:block;">📞 <?=e($b['phone'])?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge" style="background:<?=$catInfo['bg']?>;color:<?=$catInfo['col']?>;font-weight:700;font-size:11px;padding:3px 8px;border-radius:12px;">
                                            <?=$catInfo['label']?>
                                        </span>
                                    </td>
                                    <td><?=e($b['department_class'] ?: '—')?></td>
                                    <td>
                                        <span class="badge badge-blue" style="font-size:11px;">
                                            <?=e($b['fund_name'] ?: 'General')?>
                                        </span>
                                    </td>
                                    <td class="font-bold" style="color:#15803d;">
                                        <?= (float)$b['monthly_stipend_amount'] > 0 ? money($b['monthly_stipend_amount']) : '—' ?>
                                    </td>
                                    <td class="font-bold" style="color:#b91c1c;">
                                        <?=money($b['total_disbursed'])?>
                                        <small style="display:block;font-size:10px;font-weight:normal;color:#64748b;"><?=$b['vouchers_count']?> vouchers</small>
                                    </td>
                                    <td>
                                        <?php if ($b['status'] === 'active'): ?>
                                            <span class="badge" style="background:#dcfce7;color:#15803d;font-weight:700;font-size:11px;">Active</span>
                                        <?php elseif ($b['status'] === 'graduated'): ?>
                                            <span class="badge" style="background:#e0f2fe;color:#0369a1;font-weight:700;font-size:11px;">Graduated</span>
                                        <?php else: ?>
                                            <span class="badge" style="background:#fee2e2;color:#b91c1c;font-weight:700;font-size:11px;">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="right" style="white-space:nowrap;">
                                        <div style="display:inline-flex;align-items:center;gap:6px;">
                                            <?php 
                                                $quickStipendData = [
                                                    'beneficiary_id' => (int)$b['id'],
                                                    'full_name' => $b['full_name'],
                                                    'category' => $b['category'],
                                                    'department_class' => $b['department_class'] ?? '',
                                                    'fund_id' => (int)($b['assigned_fund_id'] ?: ($funds[0]['id'] ?? 0)),
                                                    'fund_name' => (string)($b['fund_name'] ?: 'General'),
                                                    'amount' => (float)$b['monthly_stipend_amount'],
                                                    'desc' => 'ماہانہ وظیفہ / تنخواہ برائے ' . $b['full_name'] . ' بابت ماہ ' . date('F Y')
                                                ];
                                            ?>
                                            <button type="button" 
                                                    class="btn success sm" 
                                                    style="background:#15803d;color:#fff;padding:3px 9px;font-size:11.5px;font-weight:700;border:none;border-radius:4px;cursor:pointer;display:inline-flex;align-items:center;gap:4px;" 
                                                    title="Quick Pay Stipend / Salary (فوری ادائیگی)"
                                                    onclick='openQuickStipendModal(<?=json_encode($quickStipendData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)?>)'>
                                                💰 Stipend
                                            </button>
                                            <?php if (has_permission('beneficiaries.manage')): ?>
                                                <a href="beneficiaries.php?edit_id=<?=$b['id']?>#benFormCard" class="btn secondary sm" style="padding:3px 7px;font-size:11px;" title="Edit Details">
                                                    ✏️
                                                </a>
                                                <a href="beneficiaries.php?delete_id=<?=$b['id']?>&token=<?=csrf_token()?>" class="btn danger sm" style="padding:3px 7px;font-size:11px;" onclick="return confirm('Are you sure you want to delete \'<?=e(addslashes($b['full_name']))?>\'?');" title="Delete">
                                                    🗑️
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </article>

        <!-- Quick Pay Stipend / Salary Modal (1-Click Instant Disbursement) -->
        <div id="quickStipendModal" class="modal-backdrop no-print" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.65);z-index:99999;align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(3px);">
            <div style="background:#ffffff;border-radius:14px;width:100%;max-width:500px;box-shadow:0 25px 35px -5px rgba(0,0,0,0.3);overflow:hidden;border:1px solid #cbd5e1;">
                <div style="padding:16px 20px;background:#0f172a;color:#ffffff;display:flex;justify-content:space-between;align-items:center;">
                    <div style="font-weight:700;font-size:15px;display:flex;align-items:center;gap:8px;">
                        <span>💰</span>
                        <span>Quick Pay Stipend / Salary <span class="ur">(فوری ادائیگی وظیفہ / تنخواہ)</span></span>
                    </div>
                    <button type="button" onclick="closeQuickStipendModal()" style="background:transparent;border:none;color:#94a3b8;font-size:24px;cursor:pointer;line-height:1;" title="Close (بند کریں)">&times;</button>
                </div>

                <form method="post" action="beneficiaries.php" style="padding:20px;">
                    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                    <input type="hidden" name="action" value="quick_pay_stipend">
                    <input type="hidden" name="beneficiary_id" id="qsm_beneficiary_id" value="">

                    <!-- Beneficiary Info Card -->
                    <div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:10px;padding:12px 16px;margin-bottom:16px;">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                            <div>
                                <div style="font-size:11px;text-transform:uppercase;color:#64748b;font-weight:700;letter-spacing:0.5px;">امیدوار / وصول کنندہ:</div>
                                <div style="font-weight:800;font-size:16px;color:#0f172a;margin-top:2px;" id="qsm_full_name">Beneficiary Name</div>
                                <div style="font-size:12px;color:#475569;margin-top:3px;" id="qsm_subtext">Class / Dept</div>
                            </div>
                            <div style="text-align:right;">
                                <span class="badge" style="background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;font-weight:700;font-size:11.5px;padding:3px 8px;" id="qsm_category_badge">Category</span>
                                <div style="font-size:11.5px;color:#0284c7;font-weight:700;margin-top:5px;" id="qsm_fund_name">Fund</div>
                            </div>
                        </div>
                    </div>

                    <!-- Amount & Payment Date Grid -->
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
                        <div>
                            <label style="display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:5px;">
                                Disbursement Amount <span class="ur">(ادائیگی رقم PKR)</span> *
                            </label>
                            <input type="number" step="0.01" min="1" name="amount" id="qsm_amount" required style="width:100%;font-size:17px;font-weight:800;color:#b91c1c;padding:8px 12px;border:1.5px solid #94a3b8;border-radius:6px;box-sizing:border-box;">
                        </div>
                        <div>
                            <label style="display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:5px;">
                                Payment Date <span class="ur">(تاریخ ادائیگی)</span> *
                            </label>
                            <input type="date" name="voucher_date" id="qsm_date" required style="width:100%;font-size:13.5px;font-weight:600;padding:9px 12px;border:1.5px solid #94a3b8;border-radius:6px;box-sizing:border-box;">
                        </div>
                    </div>

                    <!-- Fund & Expense Head Grid -->
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
                        <div>
                            <label style="display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:5px;">
                                From Fund <span class="ur">(فنڈ کھاتہ)</span> *
                            </label>
                            <select name="fund_id" id="qsm_fund_id" required style="width:100%;font-size:12.5px;font-weight:600;padding:9px 10px;border:1.5px solid #cbd5e1;border-radius:6px;box-sizing:border-box;">
                                <?php foreach ($funds as $f): ?>
                                    <option value="<?=$f['id']?>"><?=e($f['name'])?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:5px;">
                                Expense Head <span class="ur">(خرچ کی مد)</span> *
                            </label>
                            <select name="expense_account_id" id="qsm_expense_account_id" required style="width:100%;font-size:12.5px;font-weight:600;padding:9px 10px;border:1.5px solid #cbd5e1;border-radius:6px;box-sizing:border-box;">
                                <?php foreach ($expenseAccounts as $acc): ?>
                                    <option value="<?=$acc['id']?>" data-code="<?=$acc['account_code']?>">
                                        <?=e($acc['account_code'])?> - <?=e(format_ui_text($acc['account_name']))?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Payment Method & Paid From Grid -->
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
                        <div>
                            <label style="display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:5px;">
                                Payment Method <span class="ur">(طریقہ کار)</span> *
                            </label>
                            <select name="payment_method" id="qsm_payment_method" onchange="onStipendPaymentMethodChange()" style="width:100%;font-size:12.5px;font-weight:700;padding:9px 10px;border:1.5px solid #94a3b8;border-radius:6px;box-sizing:border-box;background:#f8fafc;">
                                <option value="cash">💵 Cash (نقد ادائیگی)</option>
                                <option value="bank_transfer">🏛️ Bank / Online Transfer (بینک منتقلی)</option>
                                <option value="cheque">📝 Cheque (بینک چیک)</option>
                            </select>
                        </div>
                        <div>
                            <label style="display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:5px;">
                                Paid From <span class="ur">(ادائیگی کھاتہ)</span> *
                            </label>
                            <select name="asset_account_id" id="qsm_asset_account_id" onchange="onStipendAccountChange()" required style="width:100%;font-size:12.5px;font-weight:700;padding:9px 10px;border:1.5px solid #94a3b8;border-radius:6px;box-sizing:border-box;">
                                <?php if (!empty($userCashAcc)): ?>
                                    <optgroup label="💼 آپ کا ذاتی نقد کھاتہ (Your Cash Custody)">
                                        <option value="<?=$userCashAcc['id']?>" data-type="cash" selected>
                                            <?=e($userCashAcc['account_code'])?> - <?=e(format_ui_text($userCashAcc['account_name']))?> (آپ کا کیش)
                                        </option>
                                    </optgroup>
                                <?php endif; ?>

                                <?php if (!empty($bankAccounts)): ?>
                                    <optgroup label="🏛️ مدرسہ / ٹرسٹ بینک اکاؤنٹس (Bank Accounts)">
                                        <?php foreach ($bankAccounts as $bAcc): ?>
                                            <option value="<?=$bAcc['id']?>" data-type="bank">
                                                <?=e($bAcc['account_code'])?> - <?=e(format_ui_text($bAcc['account_name']))?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>

                                <?php if (can_approve() && !empty($otherCashAccounts)): ?>
                                    <optgroup label="👥 دیگر عملہ کیش اکاؤنٹس (Other Staff Cash)">
                                        <?php foreach ($otherCashAccounts as $oAcc): ?>
                                            <option value="<?=$oAcc['id']?>" data-type="other_cash">
                                                <?=e($oAcc['account_code'])?> - <?=e(format_ui_text($oAcc['account_name']))?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Bank Ref / Cheque No Box (Shows when Bank or Cheque is selected) -->
                    <div id="qsm_ref_container" style="display:none;margin-bottom:14px;background:#f0fdf4;border:1.5px solid #86efac;border-radius:8px;padding:10px 14px;">
                        <label style="display:block;font-size:12px;font-weight:700;color:#166534;margin-bottom:5px;">
                            Bank Transfer Ref / Cheque No <span class="ur">(بینک رسید / آن لائن ٹرانزیکشن یا چیک نمبر)</span>
                        </label>
                        <input type="text" name="reference_no" id="qsm_reference_no" style="width:100%;font-size:12.5px;padding:8px 12px;border:1px solid #86efac;border-radius:6px;box-sizing:border-box;" placeholder="e.g. TR-98214 یا Cheque # 0019284">
                    </div>

                    <!-- Description / Narration -->
                    <div style="margin-bottom:18px;">
                        <label style="display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:5px;">
                            Description / Note <span class="ur">(تفصیل و مد)</span>
                        </label>
                        <input type="text" name="description" id="qsm_description" style="width:100%;font-size:12.5px;padding:9px 12px;border:1px solid #cbd5e1;border-radius:6px;box-sizing:border-box;" placeholder="ماہانہ وظیفہ / تنخواہ ادائیگی">
                    </div>

                    <!-- Modal Actions -->
                    <div style="display:flex;justify-content:space-between;align-items:center;padding-top:14px;border-top:1px solid #e2e8f0;flex-wrap:wrap;gap:10px;">
                        <a href="expenses.php" id="qsm_adv_link" target="_blank" style="font-size:11.5px;color:#2563eb;text-decoration:none;" title="Open full Expense form for bill attachments or foreign currency">
                            Advanced Form ↗ <span class="ur">(تفصیلی فارم)</span>
                        </a>
                        <div style="display:flex;gap:8px;">
                            <button type="button" onclick="closeQuickStipendModal()" class="btn secondary" style="padding:9px 16px;font-weight:600;">
                                Cancel
                            </button>
                            <button type="submit" class="btn" style="background:#15803d;color:#ffffff;border:none;padding:9px 20px;font-weight:700;font-size:13.5px;border-radius:6px;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,0.15);">
                                ✅ Confirm &amp; Disburse Payment <span class="ur">(رقم ادا کریں)</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <?=render_app_footer($madrasaName)?>
    </main>
</div>

<script>
var defaultStipendUserCashId = "<?=!empty($userCashAcc) ? (int)$userCashAcc['id'] : 0?>";
var defaultStipendBankId = "<?=!empty($bankAccounts) ? (int)$bankAccounts[0]['id'] : 0?>";

function onStipendPaymentMethodChange() {
    var pm = document.getElementById('qsm_payment_method');
    var accSelect = document.getElementById('qsm_asset_account_id');
    var refBox = document.getElementById('qsm_ref_container');
    if (!pm || !accSelect) return;

    if (pm.value === 'cash') {
        var cashOpt = accSelect.querySelector('option[data-type="cash"]');
        if (cashOpt) {
            accSelect.value = cashOpt.value;
        } else if (defaultStipendUserCashId && defaultStipendUserCashId !== '0') {
            accSelect.value = defaultStipendUserCashId;
        }
        if (refBox) refBox.style.display = 'none';
    } else {
        // Bank or Cheque
        var bankOpt = accSelect.querySelector('option[data-type="bank"]');
        if (bankOpt) {
            accSelect.value = bankOpt.value;
        } else if (defaultStipendBankId && defaultStipendBankId !== '0') {
            accSelect.value = defaultStipendBankId;
        }
        if (refBox) {
            refBox.style.display = 'block';
            var refInput = document.getElementById('qsm_reference_no');
            if (refInput) refInput.focus();
        }
    }
}

function onStipendAccountChange() {
    var accSelect = document.getElementById('qsm_asset_account_id');
    var pm = document.getElementById('qsm_payment_method');
    var refBox = document.getElementById('qsm_ref_container');
    if (!accSelect || !pm) return;

    var selectedOpt = accSelect.options[accSelect.selectedIndex];
    var accType = selectedOpt ? selectedOpt.getAttribute('data-type') : '';

    if (accType === 'bank') {
        if (pm.value === 'cash') {
            pm.value = 'bank_transfer';
        }
        if (refBox) refBox.style.display = 'block';
    } else if (accType === 'cash') {
        pm.value = 'cash';
        if (refBox) refBox.style.display = 'none';
    }
}

function openQuickStipendModal(data) {
    if (!data) return;
    document.getElementById('qsm_beneficiary_id').value = data.beneficiary_id || '';
    document.getElementById('qsm_full_name').textContent = data.full_name || 'امیدوار';
    document.getElementById('qsm_subtext').textContent = data.department_class ? ('شعبہ / درجہ: ' + data.department_class) : 'امیدوار وظیفہ';
    document.getElementById('qsm_category_badge').textContent = data.category ? (data.category.toUpperCase()) : 'STUDENT';
    document.getElementById('qsm_fund_name').textContent = '🏷️ ' + (data.fund_name || 'General');
    document.getElementById('qsm_amount').value = data.amount || 0;
    document.getElementById('qsm_date').value = new Date().toISOString().split('T')[0];
    document.getElementById('qsm_description').value = data.desc || '';

    // Set fund dropdown
    var fundSel = document.getElementById('qsm_fund_id');
    if (fundSel && data.fund_id) {
        fundSel.value = data.fund_id;
    }

    // Auto-select Expense Head based on category (5010 for staff salaries, 5050 for students/orphans)
    var expSelect = document.getElementById('qsm_expense_account_id');
    if (expSelect) {
        var targetCode = (data.category === 'staff') ? '5010' : '5050';
        for (var i = 0; i < expSelect.options.length; i++) {
            var opt = expSelect.options[i];
            if (opt.getAttribute('data-code') === targetCode) {
                expSelect.selectedIndex = i;
                break;
            }
        }
    }

    // Reset payment method to cash & user's own cash account by default
    var pm = document.getElementById('qsm_payment_method');
    if (pm) pm.value = 'cash';
    var accSelect = document.getElementById('qsm_asset_account_id');
    if (accSelect && defaultStipendUserCashId && defaultStipendUserCashId !== '0') {
        accSelect.value = defaultStipendUserCashId;
    }
    var refBox = document.getElementById('qsm_ref_container');
    if (refBox) refBox.style.display = 'none';
    var refInput = document.getElementById('qsm_reference_no');
    if (refInput) refInput.value = '';

    // Advanced full form fallback link
    var advLink = document.getElementById('qsm_adv_link');
    if (advLink) {
        advLink.href = 'expenses.php?beneficiary_id=' + encodeURIComponent(data.beneficiary_id) +
            '&payee_name=' + encodeURIComponent(data.full_name) +
            '&amount=' + encodeURIComponent(data.amount) +
            '&fund_id=' + encodeURIComponent(data.fund_id) +
            '&desc=' + encodeURIComponent(data.desc);
    }

    var m = document.getElementById('quickStipendModal');
    if (m) {
        m.style.display = 'flex';
        setTimeout(function() {
            var amt = document.getElementById('qsm_amount');
            if (amt) { amt.focus(); amt.select(); }
        }, 120);
    }
}

function closeQuickStipendModal() {
    var m = document.getElementById('quickStipendModal');
    if (m) m.style.display = 'none';
}

// Close modal if user clicks outside of modal dialog
window.addEventListener('click', function(e) {
    var m = document.getElementById('quickStipendModal');
    if (e.target === m) {
        closeQuickStipendModal();
    }
});
function toggleBenForm(forceOpen) {
    var card = document.getElementById('benFormCard');
    var btn = document.getElementById('toggleBenBtn');
    if (!card) return;

    var isHidden = (card.style.display === 'none' || getComputedStyle(card).display === 'none');
    var open = (forceOpen !== undefined) ? forceOpen : isHidden;

    if (open) {
        card.style.display = 'block';
        if (btn) {
            btn.innerHTML = '✕ Close Form <span class="ur">(بند کریں)</span>';
            btn.classList.remove('blue');
            btn.classList.add('secondary');
        }
        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
        var nameInput = card.querySelector('input[name="full_name"]');
        if (nameInput) setTimeout(function() { nameInput.focus(); }, 250);
    } else {
        card.style.display = 'none';
        if (btn) {
            btn.innerHTML = '＋ Register Student / Beneficiary';
            btn.classList.remove('secondary');
            btn.classList.add('blue');
        }
    }
}
</script>
</body>
</html>
