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

        <?php if (isset($_GET['success'])): ?>
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
                                            <a href="expenses.php?beneficiary_id=<?=$b['id']?>&payee_name=<?=urlencode($b['full_name'])?>&amount=<?=$b['monthly_stipend_amount']?>&fund_id=<?=$b['assigned_fund_id']?>&desc=<?=urlencode('ماہانہ وظیفہ / کفالت برائے ' . $b['full_name'])?>" class="btn success sm" style="background:#15803d;color:#fff;padding:3px 8px;font-size:11px;font-weight:600;text-decoration:none;border-radius:4px;" title="Issue Stipend or Expense Voucher">
                                                💰 Stipend
                                            </a>
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

        <?=render_app_footer($madrasaName)?>
    </main>
</div>

<script>
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
