<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();
require_permission('donors.view');

$activePage = 'donors';
$error = '';
$success = '';

$currentUser = current_user();
$userId = (int)($currentUser['id'] ?? 0);
$isSuperAdmin = in_array($currentUser['role'] ?? '', ['Super Admin', 'Administrator'], true);
$userFundIds = get_user_assigned_fund_ids($userId);
$hasFundRestriction = (!$isSuperAdmin && !empty($userFundIds));

// Handle Delete Donor (Only authorized users with donors.manage)
if (isset($_GET['delete_id'])) {
    if (!has_permission('donors.manage')) {
        $error = 'صرف مجاز صارفین کو معاونین ڈیلیٹ کرنے کی اجازت ہے۔';
    } else {
        $delId = (int)$_GET['delete_id'];
        $token = $_GET['token'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            $error = 'Invalid security token for deletion.';
        } else {
            try {
                if ($hasFundRestriction) {
                    $donorFund = $pdo->query("SELECT fund_id FROM donors WHERE id = {$delId}")->fetchColumn();
                    if ($donorFund === false || !in_array((int)$donorFund, $userFundIds, true)) {
                        $error = 'آپ کے پاس اس فنڈ کے معاون کو ڈیلیٹ کرنے کا اختیار نہیں ہے۔';
                    }
                }
                if (empty($error)) {
                    // Check if donor has vouchers
                    $voucherCount = (int)$pdo->query("SELECT COUNT(*) FROM vouchers WHERE donor_id = {$delId}")->fetchColumn();
                    if ($voucherCount > 0) {
                        // Safely unlink donor from vouchers so accounting journal entries and balances remain 100% intact
                        $pdo->prepare('UPDATE vouchers SET donor_id = NULL WHERE donor_id = ?')->execute([$delId]);
                    }
                    $stmtDel = $pdo->prepare('DELETE FROM donors WHERE id = ?');
                    $stmtDel->execute([$delId]);
                    
                    $redirectUrl = 'donors.php?success=deleted';
                    if (isset($_GET['tab']) && $_GET['tab'] === 'pledges') {
                        $redirectUrl .= '&tab=pledges';
                        if (!empty($_GET['month'])) {
                            $redirectUrl .= '&month=' . urlencode($_GET['month']);
                        }
                        if (isset($_GET['pledge_fund_id'])) {
                            $redirectUrl .= '&pledge_fund_id=' . (int)$_GET['pledge_fund_id'];
                        }
                        if (!empty($_GET['pledge_status'])) {
                            $redirectUrl .= '&pledge_status=' . urlencode($_GET['pledge_status']);
                        }
                    }
                    header('Location: ' . $redirectUrl);
                    exit;
                }
            } catch (Exception $e) {
                $error = 'Delete failed: ' . $e->getMessage();
            }
        }
    }
}

// Handle Add or Edit Donor Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!has_permission('donors.manage')) {
        $error = 'آپ کے پاس نئے معاونین شامل کرنے یا تبدیل کرنے کا اختیار نہیں ہے۔';
    } else {
        verify_csrf();
        $action = $_POST['action'];
        $name = trim($_POST['donor_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $fundId = !empty($_POST['fund_id']) ? (int)$_POST['fund_id'] : null;

        // Recurring Pledge fields (Feature #1)
        $pledgeActive = isset($_POST['is_pledge_active']) ? 1 : 0;
        $pledgeAmount = (float)($_POST['pledge_amount'] ?? 0);
        $pledgeFundId = !empty($_POST['pledge_fund_id']) ? (int)$_POST['pledge_fund_id'] : null;
        $pledgeFrequency = in_array($_POST['pledge_frequency'] ?? '', ['monthly', 'annual', 'quarterly'], true) ? $_POST['pledge_frequency'] : 'monthly';
        $pledgeDay = max(1, min(28, (int)($_POST['pledge_day'] ?? 1)));
        $pledgeNotes = trim($_POST['pledge_notes'] ?? '');

        // Strict fund validation for restricted users
        if ($hasFundRestriction) {
            if ($fundId === null || !in_array($fundId, $userFundIds, true)) {
                if (count($userFundIds) === 1) {
                    $fundId = $userFundIds[0];
                } else {
                    $error = 'براہ کرم اپنے تفویض کردہ فنڈز میں سے کوئی ایک فنڈ منتخب کریں۔';
                }
            }
        }

        if (empty($error)) {
            if ($action === 'save_donor') {
                if (empty($name)) {
                    $error = 'Please enter donor name.';
                } else {
                    try {
                        $stmt = $pdo->prepare('INSERT INTO donors (donor_name, phone, email, address, notes, fund_id, pledge_amount, pledge_fund_id, pledge_frequency, pledge_day, is_pledge_active, pledge_notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                        $stmt->execute([$name, $phone ?: null, $email ?: null, $address ?: null, $notes ?: null, $fundId, $pledgeAmount, $pledgeFundId, $pledgeFrequency, $pledgeDay, $pledgeActive, $pledgeNotes ?: null]);
                        header('Location: donors.php?success=created');
                        exit;
                    } catch (Exception $e) {
                        $error = 'Error saving donor: ' . $e->getMessage();
                    }
                }
            } elseif ($action === 'update_donor') {
                $donorId = (int)($_POST['donor_id'] ?? 0);
                if ($donorId <= 0 || empty($name)) {
                    $error = 'Please enter valid donor details.';
                } else {
                    if ($hasFundRestriction) {
                        $currDonorFund = $pdo->query("SELECT fund_id FROM donors WHERE id = {$donorId}")->fetchColumn();
                        if ($currDonorFund === false || !in_array((int)$currDonorFund, $userFundIds, true)) {
                            $error = 'آپ کے پاس اس معاون کی معلومات تبدیل کرنے کا اختیار نہیں ہے۔';
                        }
                    }
                    if (empty($error)) {
                        try {
                            $stmt = $pdo->prepare('UPDATE donors SET donor_name = ?, phone = ?, email = ?, address = ?, notes = ?, fund_id = ?, pledge_amount = ?, pledge_fund_id = ?, pledge_frequency = ?, pledge_day = ?, is_pledge_active = ?, pledge_notes = ? WHERE id = ?');
                            $stmt->execute([$name, $phone ?: null, $email ?: null, $address ?: null, $notes ?: null, $fundId, $pledgeAmount, $pledgeFundId, $pledgeFrequency, $pledgeDay, $pledgeActive, $pledgeNotes ?: null, $donorId]);
                            header('Location: donors.php?success=updated');
                            exit;
                        } catch (Exception $e) {
                            $error = 'Error updating donor: ' . $e->getMessage();
                        }
                    }
                }
            }
        }
    }
}

// Edit Mode Data
$editDonor = null;
if (isset($_GET['edit_id']) && has_permission('donors.manage')) {
    $editId = (int)$_GET['edit_id'];
    $stmtEdit = $pdo->prepare('SELECT * FROM donors WHERE id = ?');
    $stmtEdit->execute([$editId]);
    $foundDonor = $stmtEdit->fetch();
    if ($foundDonor) {
        if ($hasFundRestriction && !in_array((int)($foundDonor['fund_id'] ?? 0), $userFundIds, true)) {
            $error = 'آپ کے پاس اس معاون کی تفصیلات ایڈٹ کرنے کا اختیار نہیں ہے۔';
        } else {
            $editDonor = $foundDonor;
        }
    }
}
$showForm = !empty($editDonor) || (!empty($error) && $_SERVER['REQUEST_METHOD'] === 'POST');

// Fetch user allowed active Funds dynamically for categories
$funds = get_user_allowed_funds();

// Search and Fund Category Filter
$search = trim($_GET['search'] ?? '');
$filterFund = isset($_GET['fund_id']) ? (int)$_GET['fund_id'] : 0;

$query = "SELECT d.*, f.name AS fund_name, f.fund_code, f.is_restricted,
          COALESCE(SUM(v.total_amount), 0) AS total_donated,
          COUNT(v.id) AS donations_count
          FROM donors d
          LEFT JOIN funds f ON f.id = d.fund_id
          LEFT JOIN vouchers v ON v.donor_id = d.id AND v.voucher_type='income' AND v.status='posted'
          WHERE 1=1";

$params = [];
if ($hasFundRestriction) {
    $inFunds = implode(',', array_map('intval', $userFundIds));
    $query .= " AND d.fund_id IN ($inFunds)";
}

if (!empty($search)) {
    $query .= " AND (d.donor_name LIKE ? OR d.phone LIKE ? OR d.address LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($filterFund > 0) {
    $query .= " AND d.fund_id = ?";
    $params[] = $filterFund;
} elseif ($filterFund === -1 && !$hasFundRestriction) {
    $query .= " AND d.fund_id IS NULL";
}

$query .= " GROUP BY d.id ORDER BY d.id DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$donors = $stmt->fetchAll();

if ($hasFundRestriction) {
    $inFunds = implode(',', array_map('intval', $userFundIds));
    $totalDonorsCount = (int)$pdo->query("SELECT COUNT(*) FROM donors WHERE fund_id IN ($inFunds)")->fetchColumn();
    $totalLifetimeDonations = (float)$pdo->query("SELECT COALESCE(SUM(v.total_amount), 0) FROM vouchers v JOIN donors d ON d.id = v.donor_id WHERE d.fund_id IN ($inFunds) AND v.status='posted' AND v.voucher_type='income'")->fetchColumn();
    $catCountsRaw = $pdo->query("SELECT COALESCE(fund_id, 0) AS fid, COUNT(*) AS cnt FROM donors WHERE fund_id IN ($inFunds) GROUP BY fid")->fetchAll(PDO::FETCH_KEY_PAIR);
} else {
    $totalDonorsCount = (int)$pdo->query("SELECT COUNT(*) FROM donors")->fetchColumn();
    $totalLifetimeDonations = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM vouchers WHERE donor_id IS NOT NULL AND status='posted' AND voucher_type='income'")->fetchColumn();
    $catCountsRaw = $pdo->query("SELECT COALESCE(fund_id, 0) AS fid, COUNT(*) AS cnt FROM donors GROUP BY fid")->fetchAll(PDO::FETCH_KEY_PAIR);
}
$madrasaName = get_setting('madrasa_name', 'Trust Accounts');
$madrasaPhone = get_setting('madrasa_phone', '');

// Tab Navigation & Monthly Pledges / Kafalat Tracker (Feature #1)
$currentTab = trim($_GET['tab'] ?? 'directory'); // 'directory' or 'pledges'
$selectedMonth = trim($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}
$filterPledgeFund = isset($_GET['pledge_fund_id']) ? (int)$_GET['pledge_fund_id'] : 0;
$filterPledgeStatus = in_array($_GET['pledge_status'] ?? '', ['all', 'pending', 'paid'], true) ? $_GET['pledge_status'] : 'all';

// Fetch active pledgers
$pledgeQuery = "SELECT d.*, f.name AS fund_name, f.fund_code, pf.name AS pledge_fund_name,
                COALESCE((
                    SELECT SUM(v.total_amount) 
                    FROM vouchers v 
                    WHERE v.donor_id = d.id 
                      AND v.voucher_type = 'income' 
                      AND v.status = 'posted' 
                      AND v.voucher_date LIKE '{$selectedMonth}%'
                ), 0) AS paid_this_month
                FROM donors d
                LEFT JOIN funds f ON f.id = d.fund_id
                LEFT JOIN funds pf ON pf.id = d.pledge_fund_id
                WHERE d.is_pledge_active = 1";
if ($hasFundRestriction) {
    $inFunds = implode(',', array_map('intval', $userFundIds));
    $pledgeQuery .= " AND (d.fund_id IN ($inFunds) OR d.pledge_fund_id IN ($inFunds))";
}
if ($filterPledgeFund > 0) {
    $pledgeQuery .= " AND (d.pledge_fund_id = {$filterPledgeFund} OR (d.pledge_fund_id IS NULL AND d.fund_id = {$filterPledgeFund}))";
} elseif ($filterPledgeFund === -1) {
    $pledgeQuery .= " AND (d.pledge_fund_id IS NULL AND d.fund_id IS NULL)";
}
$pledgeQuery .= " ORDER BY d.pledge_day ASC, d.id DESC";
$rawPledgers = $pdo->query($pledgeQuery)->fetchAll() ?: [];

// Status Filtering
$activePledgers = [];
foreach ($rawPledgers as $pl) {
    $isPaid = ((float)$pl['paid_this_month'] >= (float)$pl['pledge_amount']);
    if ($filterPledgeStatus === 'pending' && $isPaid) continue;
    if ($filterPledgeStatus === 'paid' && !$isPaid) continue;
    $activePledgers[] = $pl;
}

$totalMonthlyPledged = 0;
$totalMonthlyReceived = 0;
$totalPendingPledgers = 0;
foreach ($activePledgers as $pl) {
    $totalMonthlyPledged += (float)$pl['pledge_amount'];
    $totalMonthlyReceived += min((float)$pl['paid_this_month'], (float)$pl['pledge_amount']);
    if ((float)$pl['paid_this_month'] < (float)$pl['pledge_amount']) {
        $totalPendingPledgers++;
    }
}
$totalMonthlyPending = max(0, $totalMonthlyPledged - $totalMonthlyReceived);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Donors Management | <?=e($madrasaName)?></title>
    <link rel="stylesheet" href="assets/style.css?v=<?=css_ver()?>">
</head>
<body>
<div class="app-shell">
    <?php require __DIR__ . '/../app/sidebar.php'; ?>

    <main class="main">
        <header class="topbar">
            <div>
                <span class="eyebrow">DIRECTORY</span>
                <h1>Donors Directory <span class="ur">(معاونین و عطیہ دہندگان)</span></h1>
            </div>
            <div class="btn-row">
                <?php if (has_permission('donors.manage')): ?>
                    <?php if ($editDonor): ?>
                        <a href="donors.php" class="btn secondary sm">✕ Cancel Editing <span class="ur">(منسوخ کریں)</span></a>
                    <?php else: ?>
                        <button type="button" class="btn blue sm" id="toggleDonorBtn" onclick="toggleDonorForm()">
                            ＋ Register New Donor
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </header>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert success">
                <?php
                    if ($_GET['success'] === 'created') echo 'Donor details successfully registered!';
                    elseif ($_GET['success'] === 'updated') echo 'Donor information updated successfully!';
                    elseif ($_GET['success'] === 'deleted') echo 'Donor record deleted successfully!';
                    else echo 'Donor details saved successfully!';
                ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert error"><?=e($error)?></div>
        <?php endif; ?>

        <?php if (!has_permission('donors.manage')): ?>
            <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:12px 18px;margin-bottom:20px;display:flex;align-items:center;gap:12px;">
                <span style="font-size:20px;">ℹ️</span>
                <div style="font-size:13px;color:#1e40af;">
                    <div class="ur"><strong>معاونین کی فہرست:</strong> نئے معاونین کا اندراج، تبدیلی یا ڈیلیٹ کرنے کا اختیار مجاز صارفین کے پاس ہے۔ آپ یہاں سے معاونین تلاش کر کے ان کی آمدنی کی رسید (Receipt) جاری کر سکتے ہیں۔</div>
                    <div class="en-only" style="display:none;"><strong>Donors Directory:</strong> Authorized users can register, modify or delete donors. You can search donors here and issue income receipts.</div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Tab Navigation (Feature #1) -->
        <div class="tab-nav" style="margin: 18px 0;">
            <a href="donors.php?tab=directory" class="tab-link <?=$currentTab==='directory'?'active':''?>">
                👥 Donors Directory <span class="ur">(معاونین لسٹ)</span> (<?=$totalDonorsCount?>)
            </a>
            <a href="donors.php?tab=pledges&month=<?=e($selectedMonth)?>" class="tab-link <?=$currentTab==='pledges'?'active':''?>" style="display:inline-flex;align-items:center;gap:6px;">
                📋 Monthly Pledges &amp; Kafalat Tracker <span class="ur">(ماہانہ کفالت و اقساط ٹریکر)</span>
                <?php if ($totalPendingPledgers > 0): ?>
                    <span class="badge" style="background:#fee2e2;color:#b91c1c;font-size:11px;padding:2px 7px;border-radius:10px;"><?=$totalPendingPledgers?> Due</span>
                <?php else: ?>
                    <span class="badge" style="background:#dcfce7;color:#15803d;font-size:11px;padding:2px 7px;border-radius:10px;">✓ All Cleared</span>
                <?php endif; ?>
            </a>
        </div>

        <?php if ($currentTab === 'directory'): ?>
        <!-- Stats Overview -->
        <section class="stats-grid" style="margin:22px 0 24px;">
            <article class="stat-card">
                <div class="stat-top"><span>Total Registered Donors</span><span class="stat-icon blue">👥</span></div>
                <strong><?=$totalDonorsCount?></strong>
                <small>Active contributors</small>
            </article>
            <article class="stat-card">
                <div class="stat-top"><span>Total Collected from Donors</span><span class="stat-icon green">↗</span></div>
                <strong><?=money($totalLifetimeDonations)?></strong>
                <small>Tracked donations</small>
            </article>
        </section>
        <?php endif; ?>

        <!-- Professional Donor Form Card (Add / Edit) - Only for users with donors.manage permission -->
        <?php if (has_permission('donors.manage')): ?>
        <article class="form-card" id="donorFormCard" style="<?= $showForm ? 'margin:20px 0 24px;' : 'display:none; margin:20px 0 24px;' ?>">
            <div class="form-card-header" style="display:flex;justify-content:space-between;align-items:flex-start;">
                <div>
                    <h3><?= $editDonor ? '✏️ Edit Donor Details <span class="ur">(معاون کی تفصیلات تبدیل کریں)</span>' : 'Register New Donor <span class="ur">(نیا معاون درج کریں)</span>' ?></h3>
                    <p><?= $editDonor ? 'Update contact information and preferences for <strong>' . e($editDonor['donor_name']) . '</strong>' : 'Keep donor record for receipts, communications and future appeals' ?></p>
                </div>
                <?php if (!$editDonor): ?>
                    <button type="button" class="btn sm secondary" onclick="toggleDonorForm(false)" style="padding:4px 10px;" title="Close">✕</button>
                <?php endif; ?>
            </div>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                <input type="hidden" name="action" value="<?= $editDonor ? 'update_donor' : 'save_donor' ?>">
                <?php if ($editDonor): ?>
                    <input type="hidden" name="donor_id" value="<?=$editDonor['id']?>">
                <?php endif; ?>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Donor Full Name <span class="ur">(مکمل نام)</span></label>
                        <input name="donor_name" required placeholder="e.g. Haji Muhammad Aslam" value="<?=e($editDonor['donor_name'] ?? '')?>">
                    </div>

                    <div class="form-group">
                        <label>Fund Category <span class="ur">(متعلقہ فنڈ / پروجیکٹ)</span></label>
                        <select name="fund_id" <?= $hasFundRestriction ? 'required' : '' ?>>
                            <?php if (!$hasFundRestriction): ?>
                                <option value="">-- General / All Funds <span class="ur">(عام / تمام فنڈز)</span> --</option>
                            <?php endif; ?>
                            <?php foreach ($funds as $f): 
                                $isSelected = false;
                                if ($editDonor && (int)($editDonor['fund_id'] ?? 0) === (int)$f['id']) {
                                    $isSelected = true;
                                } elseif (!$editDonor && $hasFundRestriction && count($funds) === 1) {
                                    $isSelected = true;
                                }
                            ?>
                                <option value="<?=$f['id']?>" <?=$isSelected ? 'selected' : ''?>>
                                    <?=e($f['name'])?> (<?=e($f['fund_code'])?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color:var(--muted);font-size:11px;">
                            <span class="ur"><?=is_madrasa_mode() ? 'یہ معاون مدرسہ کے کس فنڈ (مثلاً: زکوٰۃ، کفالت طلبہ، مطبخ راشن، تعمیرات وغیرہ) کا ڈونر ہے۔' : 'یہ معاون ٹرسٹ کے کس فنڈ یا پروجیکٹ (مثلاً: زکوٰۃ و صدقات، راشن، یتیم کفالت وغیرہ) کا ڈونر ہے۔'?></span>
                            <span class="en-only" style="display:none;"><?=is_madrasa_mode() ? 'Select which madrasa fund this patron contributes to.' : 'Select which trust fund or project this donor contributes to.'?></span>
                        </small>
                    </div>

                    <div class="form-group">
                        <label>Phone / WhatsApp <span class="ur">(فون نمبر)</span></label>
                        <input name="phone" placeholder="0300-1234567" value="<?=e($editDonor['phone'] ?? '')?>">
                    </div>

                    <div class="form-group">
                        <label>Email Address <span class="ur">(ای میل)</span></label>
                        <input type="email" name="email" placeholder="donor@example.com" value="<?=e($editDonor['email'] ?? '')?>">
                    </div>

                    <div class="form-group">
                        <label>Address / City <span class="ur">(پتہ / شہر)</span></label>
                        <input name="address" placeholder="e.g. Lahore / Karachi" value="<?=e($editDonor['address'] ?? '')?>">
                    </div>

                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Notes / Preferences <span class="ur">(خصوصی یادداشت)</span></label>
                        <input name="notes" placeholder="e.g. Monthly Zakat, Ration donor, Water project..." value="<?=e($editDonor['notes'] ?? '')?>">
                    </div>

                    <!-- Recurring Monthly Pledge / Sponsorship Box (Feature #1) -->
                    <div class="form-group" style="grid-column: 1 / -1; background:#f0fdf4; border:1.5px solid #86efac; border-radius:10px; padding:16px;">
                        <label style="font-weight:700; color:#166534; display:flex; align-items:center; gap:8px; cursor:pointer; margin-bottom:12px;">
                            <input type="checkbox" name="is_pledge_active" id="pledgeCheckbox" value="1" <?=!empty($editDonor['is_pledge_active']) ? 'checked' : ''?> onchange="togglePledgeFields(this.checked)" style="width:18px;height:18px;accent-color:#16a34a;">
                            <span>🤝 Monthly Recurring Pledge / Kafalat <span class="ur">(مستقل معاون / ماہانہ کفالت و عطیہ)</span></span>
                        </label>

                        <div id="pledgeFieldsContainer" style="<?=!empty($editDonor['is_pledge_active']) ? 'display:grid;' : 'display:none;'?> grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:14px;">
                            <div>
                                <label style="font-size:12.5px; font-weight:600;">Pledged Amount <span class="ur">(طے شدہ رقم PKR)</span> *</label>
                                <input type="number" step="0.01" min="1" name="pledge_amount" placeholder="e.g. 5000" value="<?=e($editDonor['pledge_amount'] ?? '')?>" style="font-weight:700; color:#15803d;">
                            </div>
                            <div>
                                <label style="font-size:12.5px; font-weight:600;">Dedicated Fund <span class="ur">(مخصوص فنڈ برائے کفالت)</span></label>
                                <select name="pledge_fund_id">
                                    <option value="">-- Same as Donor Fund --</option>
                                    <?php foreach ($funds as $f): ?>
                                        <option value="<?=$f['id']?>" <?=($editDonor && (int)($editDonor['pledge_fund_id'] ?? 0) === (int)$f['id']) ? 'selected' : ''?>>
                                            <?=e($f['name'])?> (<?=e($f['fund_code'])?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label style="font-size:12.5px; font-weight:600;">Frequency <span class="ur">(تواتر)</span></label>
                                <select name="pledge_frequency">
                                    <option value="monthly" <?=($editDonor['pledge_frequency'] ?? '') === 'monthly' ? 'selected' : ''?>>Monthly (ماہانہ)</option>
                                    <option value="quarterly" <?=($editDonor['pledge_frequency'] ?? '') === 'quarterly' ? 'selected' : ''?>>Quarterly (سہ ماہی)</option>
                                    <option value="annual" <?=($editDonor['pledge_frequency'] ?? '') === 'annual' ? 'selected' : ''?>>Annual (سالانہ)</option>
                                </select>
                            </div>
                            <div>
                                <label style="font-size:12.5px; font-weight:600;">Expected Day <span class="ur">(متوقع تاریخ)</span></label>
                                <input type="number" min="1" max="28" name="pledge_day" placeholder="1 to 28 (e.g. 5)" value="<?=e($editDonor['pledge_day'] ?? '1')?>">
                            </div>
                            <div style="grid-column: 1 / -1;">
                                <label style="font-size:12.5px; font-weight:600;">Pledge Details / Tag <span class="ur">(تفصیل جیسے طالب علم، یتیم یا راشن کا نام)</span></label>
                                <input name="pledge_notes" placeholder="e.g. Sponsorship for Student Ahmad Hifz Class, Ration pack support..." value="<?=e($editDonor['pledge_notes'] ?? '')?>">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="btn-row" style="margin-top:10px;">
                    <button class="btn blue" type="submit"><?= $editDonor ? '💾 Update Donor <span class="ur">(محفوظ کریں)</span>' : '💾 Save Donor <span class="ur">(محفوظ کریں)</span>' ?></button>
                    <?php if ($editDonor): ?>
                        <a href="donors.php" class="btn secondary">✕ Cancel Editing <span class="ur">(منسوخ کریں)</span></a>
                    <?php else: ?>
                        <button class="btn secondary" type="button" onclick="toggleDonorForm(false)">✕ Cancel <span class="ur">(منسوخ کریں)</span></button>
                    <?php endif; ?>
                </div>
            </form>
        </article>
        <?php endif; ?>

        <?php if ($currentTab === 'directory'): ?>
        <!-- Donors List -->
        <article class="panel" id="donorsDirectory">
            <div class="panel-heading">
                <div>
                    <h3>Donors Directory <span class="ur">(معاونین کی فہرست)</span></h3>
                    <p>List of contributors with total giving summary</p>
                </div>
            </div>

            <!-- Fund Category Quick Filter Pills -->
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;">
                <a href="donors.php?fund_id=0<?= $search ? '&search='.urlencode($search) : '' ?>#donorsDirectory" class="btn sm <?= $filterFund === 0 ? 'blue' : 'secondary' ?>" style="font-size:12px;padding:5px 12px;border-radius:20px;text-decoration:none;">
                    All Donors (<?= $totalDonorsCount ?>)
                </a>
                <?php if (!$hasFundRestriction): ?>
                <a href="donors.php?fund_id=-1<?= $search ? '&search='.urlencode($search) : '' ?>#donorsDirectory" class="btn sm <?= $filterFund === -1 ? 'blue' : 'secondary' ?>" style="font-size:12px;padding:5px 12px;border-radius:20px;text-decoration:none;">
                    General <span class="ur">/ عام</span> (<?= (int)($catCountsRaw[0] ?? 0) ?>)
                </a>
                <?php endif; ?>
                <?php foreach ($funds as $f): ?>
                    <?php $cCount = (int)($catCountsRaw[$f['id']] ?? 0); ?>
                    <a href="donors.php?fund_id=<?=$f['id']?><?= $search ? '&search='.urlencode($search) : '' ?>#donorsDirectory" class="btn sm <?= $filterFund === (int)$f['id'] ? 'blue' : 'secondary' ?>" style="font-size:12px;padding:5px 12px;border-radius:20px;text-decoration:none;">
                        <?=e($f['name'])?> (<?=$cCount?>)
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Search & Filter Bar -->
            <form method="get" action="donors.php#donorsDirectory" class="filter-bar">
                <input name="search" placeholder="Search by name, phone, city..." value="<?=e($search)?>" style="flex:2;min-width:180px;">
                <select name="fund_id" onchange="this.form.submit()" style="flex:1;min-width:170px;">
                    <option value="0">All Categories <span class="ur">(تمام فنڈز و پروجیکٹس)</span></option>
                    <?php if (!$hasFundRestriction): ?>
                        <option value="-1" <?=$filterFund===-1?'selected':''?>>General <span class="ur">/ عام معاونین</span></option>
                    <?php endif; ?>
                    <?php foreach ($funds as $f): ?>
                        <option value="<?=$f['id']?>" <?=$filterFund===(int)$f['id']?'selected':''?>>
                            <?=e($f['name'])?> (<?=e($f['fund_code'])?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="btn secondary sm" type="submit">Filter</button>
                <?php if ($search || $filterFund !== 0): ?>
                    <a href="donors.php#donorsDirectory" class="btn secondary sm" style="text-decoration:none;">Reset</a>
                <?php endif; ?>
            </form>

            <?php if (!$donors): ?>
                <div class="empty-state">
                    <div class="empty-icon">◎</div>
                    <b>No donors found</b>
                    <p>Add your first donor using the form above.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Donor Name</th>
                                <th>Category / Fund</th>
                                <th>Phone</th>
                                <th>Address / City</th>
                                <th>Donations Count</th>
                                <th>Total Donated</th>
                                <th class="right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($donors as $d): ?>
                                <tr>
                                    <td>
                                        <b><?=e($d['donor_name'])?></b>
                                        <?php if (!empty($d['is_pledge_active'])): ?>
                                            <span class="badge" style="background:#dcfce7;color:#15803d;font-size:10px;padding:2px 6px;border-radius:4px;margin-left:4px;font-weight:700;">
                                                🤝 Pledge: <?=money($d['pledge_amount'])?>/mo
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($d['notes']): ?>
                                            <div style="font-size:11px;color:#64748b;"><?=e($d['notes'])?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($d['fund_name'])): ?>
                                            <span class="badge badge-blue" style="font-size:11.5px;"><?=e($d['fund_name'])?></span>
                                            <?php if (!empty($d['fund_code'])): ?>
                                                <small style="color:var(--muted);font-size:10px;margin-left:2px;">(<?=e($d['fund_code'])?>)</small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge badge-gray" style="font-size:11px;">General <span class="ur">(عام)</span></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?=e($d['phone'] ?: '—')?></td>
                                    <td><?=e($d['address'] ?: '—')?></td>
                                    <td><span class="badge badge-gray"><?=(int)$d['donations_count']?> receipts</span></td>
                                    <td class="font-bold" style="color:#15803d;"><?=money($d['total_donated'])?></td>
                                    <td class="right" style="white-space:nowrap;">
                                        <a href="income.php?donor_id=<?=$d['id']?>" class="btn blue sm" style="padding:4px 8px;font-size:11.5px;" title="Issue Donation Receipt">
                                            ＋ Receipt
                                        </a>
                                        <?php 
                                        $dPhone = normalize_pakistan_phone($d['phone'] ?? '');
                                        if (!empty($dPhone)): 
                                            $orgName = app_setting('org_name', 'منگل چائے ویلفیئر آرگنائزیشن');
                                            $msg = "السلام علیکم ورحمۃ اللہ وبرکاتہ جناب " . $d['donor_name'] . " صاحب!\nامید ہے آپ بخیر و عافیت ہوں گے۔\n" . $orgName . " کی جانب سے آپ کے تعاون اور دعاؤں کے دلی شکر گزار ہیں۔\nجزاکم اللہ خیرا۔";
                                            $dWaUrl = 'https://api.whatsapp.com/send?phone=' . $dPhone . '&text=' . urlencode($msg);
                                        ?>
                                            <a href="<?=e($dWaUrl)?>" target="_blank" class="btn sm" style="background:#25D366;color:#fff;border-color:#25D366;padding:4px 8px;font-size:11.5px;font-weight:600;text-decoration:none;" title="Chat / Send Greeting on WhatsApp">
                                                💬 WhatsApp
                                            </a>
                                        <?php endif; ?>
                                        <?php if (has_permission('donors.manage')): ?>
                                            <a href="donors.php?edit_id=<?=$d['id']?>#donorFormCard" class="btn secondary sm" style="padding:4px 8px;font-size:11.5px;" title="Edit Donor Details">
                                                ✏️ Edit
                                            </a>
                                            <a href="donors.php?delete_id=<?=$d['id']?>&token=<?=csrf_token()?>" class="btn danger sm" style="padding:4px 8px;font-size:11.5px;" onclick="return confirm('Are you sure you want to delete donor \'<?=e(addslashes($d['donor_name']))?>\'?');" title="Delete Donor">
                                                🗑 Delete
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </article>
        <?php elseif ($currentTab === 'pledges'): ?>
            <!-- Monthly Pledges & Kafalat KPI Cards -->
            <section class="stats-grid" style="margin:22px 0 24px;">
                <article class="stat-card">
                    <div class="stat-top"><span>Monthly Target <span class="ur">(ماہانہ ہدف)</span></span><span class="stat-icon blue">🎯</span></div>
                    <strong><?=money($totalMonthlyPledged)?></strong>
                    <small><?=count($activePledgers)?> active regular pledgers</small>
                </article>
                <article class="stat-card">
                    <div class="stat-top"><span>Received This Month <span class="ur">(موصول شدہ)</span></span><span class="stat-icon green">✓</span></div>
                    <strong style="color:#15803d;"><?=money($totalMonthlyReceived)?></strong>
                    <small>Collected for <?=date('F Y', strtotime($selectedMonth . '-01'))?></small>
                </article>
                <article class="stat-card">
                    <div class="stat-top"><span>Due / Pending <span class="ur">(بقایا اقساط)</span></span><span class="stat-icon red">⏳</span></div>
                    <strong style="color:<?=$totalMonthlyPending > 0 ? '#b91c1c' : '#15803d'?>;"><?=money($totalMonthlyPending)?></strong>
                    <small><?=$totalPendingPledgers?> donors pending</small>
                </article>
            </section>

            <!-- Pledges Month Selector & Table Panel -->
            <article class="panel">
                <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;">
                    <div>
                        <h3 style="margin:0 0 4px;">Monthly Pledges &amp; Kafalat Tracker <span class="ur">(ماہانہ کفالت و اقساط ٹریکر)</span></h3>
                        <p style="margin:0;font-size:12.5px;color:#64748b;">
                            Track regular donor contributions, sponsorship commitments, and follow up
                            <?php if ($filterPledgeFund > 0): ?>
                                <?php 
                                    $currFundName = '';
                                    foreach ($funds as $f) {
                                        if ((int)$f['id'] === $filterPledgeFund) {
                                            $currFundName = $f['name'];
                                            break;
                                        }
                                    }
                                ?>
                                &bull; <span style="color:#0284c7;font-weight:700;">Filtered: <?=e($currFundName)?></span>
                            <?php elseif ($filterPledgeFund === -1): ?>
                                &bull; <span style="color:#0284c7;font-weight:700;">Filtered: Unassigned (عام / غیر متعین)</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <form method="get" style="display:flex;align-items:center;flex-wrap:wrap;gap:10px;">
                        <input type="hidden" name="tab" value="pledges">

                        <!-- Category / Dedicated Fund Filter -->
                        <div style="display:inline-flex;align-items:center;gap:6px;">
                            <label style="font-size:12px;font-weight:700;color:#334155;text-transform:uppercase;letter-spacing:0.4px;">Category <span class="ur">(کیٹیگری / فنڈ)</span>:</label>
                            <select name="pledge_fund_id" onchange="this.form.submit()" style="padding:6px 12px;border-radius:6px;border:1px solid #cbd5e1;font-size:13px;background:#ffffff;font-weight:600;color:#0f172a;cursor:pointer;min-width:180px;">
                                <option value="0">🏷️ All Categories (تمام کیٹیگریز)</option>
                                <?php foreach ($funds as $f): ?>
                                    <option value="<?=$f['id']?>" <?=$filterPledgeFund === (int)$f['id'] ? 'selected' : ''?>>
                                        <?=e($f['name'])?> (<?=e($f['fund_code'])?>)
                                    </option>
                                <?php endforeach; ?>
                                <option value="-1" <?=$filterPledgeFund === -1 ? 'selected' : ''?>>⚪ Unassigned (عام / غیر متعین)</option>
                            </select>
                        </div>

                        <!-- Status Filter -->
                        <div style="display:inline-flex;align-items:center;gap:6px;">
                            <label style="font-size:12px;font-weight:700;color:#334155;text-transform:uppercase;letter-spacing:0.4px;">Status <span class="ur">(حیثیت)</span>:</label>
                            <select name="pledge_status" onchange="this.form.submit()" style="padding:6px 10px;border-radius:6px;border:1px solid #cbd5e1;font-size:13px;background:#ffffff;font-weight:600;color:#0f172a;cursor:pointer;">
                                <option value="all" <?=$filterPledgeStatus === 'all' ? 'selected' : ''?>>All (تمام)</option>
                                <option value="pending" <?=$filterPledgeStatus === 'pending' ? 'selected' : ''?>>⏳ Pending Only (بقایا)</option>
                                <option value="paid" <?=$filterPledgeStatus === 'paid' ? 'selected' : ''?>>✓ Paid Only (ادا شدہ)</option>
                            </select>
                        </div>

                        <!-- Select Month -->
                        <div style="display:inline-flex;align-items:center;gap:6px;">
                            <label style="font-size:12px;font-weight:700;color:#334155;text-transform:uppercase;letter-spacing:0.4px;">Month <span class="ur">(مہینہ)</span>:</label>
                            <input type="month" name="month" value="<?=e($selectedMonth)?>" onchange="this.form.submit()" style="padding:6px 10px;border-radius:6px;border:1px solid #cbd5e1;font-size:13px;background:#ffffff;font-weight:600;color:#0f172a;cursor:pointer;">
                        </div>

                        <?php if ($filterPledgeFund !== 0 || $filterPledgeStatus !== 'all' || $selectedMonth !== date('Y-m')): ?>
                            <a href="donors.php?tab=pledges" class="btn secondary sm" style="padding:6px 10px;font-size:12px;display:inline-flex;align-items:center;gap:4px;" title="Reset All Filters">
                                ✕ Reset
                            </a>
                        <?php endif; ?>
                    </form>
                </div>

                <?php if (empty($activePledgers)): ?>
                    <div class="empty-state" style="padding:40px 20px;">
                        <div class="empty-icon">🤝</div>
                        <b>No active monthly pledgers found</b>
                        <?php if ($filterPledgeFund !== 0 || $filterPledgeStatus !== 'all'): ?>
                            <p style="margin-top:6px;color:#64748b;">No monthly pledges match the selected category or status filter for <?=date('F Y', strtotime($selectedMonth . '-01'))?>.</p>
                            <div style="margin-top:14px;">
                                <a href="donors.php?tab=pledges&month=<?=e($selectedMonth)?>" class="btn secondary sm">Clear Filters</a>
                            </div>
                        <?php else: ?>
                            <p>You can set a donor as a regular monthly contributor by editing their profile above and checking "Monthly Recurring Pledge".</p>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Donor Name</th>
                                    <th>Phone / WhatsApp</th>
                                    <th>Dedicated Fund / Purpose</th>
                                    <th>Expected Day</th>
                                    <th>Monthly Pledge</th>
                                    <th>Paid (<?=date('M Y', strtotime($selectedMonth . '-01'))?>)</th>
                                    <th>Status</th>
                                    <th class="right">Action &amp; Follow-up</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($activePledgers as $pl): 
                                    $isPaid = ((float)$pl['paid_this_month'] >= (float)$pl['pledge_amount']);
                                    $cleanPhone = preg_replace('/[^0-9]/', '', (string)$pl['phone']);
                                    if (strpos($cleanPhone, '0') === 0) {
                                        $cleanPhone = '92' . substr($cleanPhone, 1);
                                    }
                                    $monthNameUrdu = date('F Y', strtotime($selectedMonth . '-01'));
                                    $remText = "السلام علیکم ورحمۃ اللہ وبرکاتہ\n\nمحترم " . $pl['donor_name'] . " صاحب!\n" . $madrasaName . " کی جانب سے دلی دعا اور نیک تمنائیں قبول فرمائیں۔\n\nآپ کی جانب سے مقررہ ماہانہ تعاون / کفالت برائے ماہ (" . $monthNameUrdu . ") رقم: " . money($pl['pledge_amount']) . " کا شائستہ یاد دہانی پیغام ارسال خدمت ہے۔\n\nاگر آپ یہ رقم ادا فرما چکے ہیں تو برائے مہربانی رسید یا اسکرین شاٹ اس نمبر پر ارسال فرما دیں تاکہ ریکارڈ اپ ڈیٹ کیا جا سکے۔\n\nجزاکم اللہ خیراً و احسن الجزاء\n" . $madrasaName . (!empty($madrasaPhone) ? "\nرابطہ: " . $madrasaPhone : "");
                                    $waUrl = "https://wa.me/{$cleanPhone}?text=" . urlencode($remText);
                                    $fundToUse = $pl['pledge_fund_id'] ?: $pl['fund_id'];
                                ?>
                                    <tr>
                                        <td>
                                            <b><?=e($pl['donor_name'])?></b>
                                            <?php if ($pl['pledge_notes']): ?>
                                                <div style="font-size:11px;color:#0284c7;font-weight:600;">🏷️ <?=e($pl['pledge_notes'])?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?=e($pl['phone'] ?: '—')?></td>
                                        <td>
                                            <span class="badge badge-blue" style="font-size:11.5px;">
                                                <?=e($pl['pledge_fund_name'] ?: ($pl['fund_name'] ?: 'General'))?>
                                            </span>
                                        </td>
                                        <td><span class="badge badge-gray"><?= (int)$pl['pledge_day'] ?>th of month</span></td>
                                        <td class="font-bold" style="color:#1e293b;"><?=money($pl['pledge_amount'])?></td>
                                        <td class="font-bold" style="color:<?=$isPaid ? '#15803d' : '#b91c1c'?>;">
                                            <?=money($pl['paid_this_month'])?>
                                        </td>
                                        <td>
                                            <?php if ($isPaid): ?>
                                                <span class="badge" style="background:#dcfce7;color:#15803d;font-weight:700;font-size:11px;padding:3px 8px;border-radius:12px;">
                                                    ✓ Paid (ادا شدہ)
                                                </span>
                                            <?php else: ?>
                                                <span class="badge" style="background:#fee2e2;color:#b91c1c;font-weight:700;font-size:11px;padding:3px 8px;border-radius:12px;">
                                                    ⏳ Pending (بقایا)
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="right" style="white-space:nowrap;">
                                            <div style="display:inline-flex;align-items:center;gap:6px;">
                                                <a href="income.php?donor_id=<?=$pl['id']?>&amount=<?=$pl['pledge_amount']?>&fund_id=<?=$fundToUse?>&desc=<?=urlencode('ماہانہ کفالت برائے ماہ ' . $monthNameUrdu)?>" class="btn success sm" style="background:#15803d;color:#fff;padding:4px 9px;font-size:11.5px;font-weight:700;text-decoration:none;border-radius:4px;" title="Receive this month's pledge">
                                                    💰 Receive
                                                </a>
                                                <?php if (!empty($cleanPhone)): ?>
                                                    <a href="<?=e($waUrl)?>" target="_blank" class="btn sm" style="background:#25D366;color:#fff;border-color:#25D366;padding:4px 9px;font-size:11.5px;font-weight:600;text-decoration:none;border-radius:4px;" title="Send polite WhatsApp Reminder">
                                                        💬 WhatsApp
                                                    </a>
                                                <?php endif; ?>
                                                <a href="donors.php?edit_id=<?=$pl['id']?>#donorFormCard" class="btn secondary sm" style="padding:4px 8px;font-size:11.5px;" title="Edit Donor">
                                                    ✏️
                                                </a>
                                                <?php if (has_permission('donors.manage')): ?>
                                                    <a href="donors.php?tab=pledges&month=<?=urlencode($selectedMonth)?>&pledge_fund_id=<?=$filterPledgeFund?>&pledge_status=<?=urlencode($filterPledgeStatus)?>&delete_id=<?=$pl['id']?>&token=<?=csrf_token()?>" class="btn danger sm" style="padding:4px 8px;font-size:11.5px;" onclick="return confirm('Are you sure you want to delete donor \'<?=e(addslashes($pl['donor_name']))?>\'?');" title="Delete Donor">
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
        <?php endif; ?>

        <?=render_app_footer($madrasaName)?>
    </main>
</div>

<script>
function togglePledgeFields(isChecked) {
    var c = document.getElementById('pledgeFieldsContainer');
    if (c) c.style.display = isChecked ? 'grid' : 'none';
}
function toggleDonorForm(forceOpen) {
    var card = document.getElementById('donorFormCard');
    var btn = document.getElementById('toggleDonorBtn');
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
        var nameInput = card.querySelector('input[name="donor_name"]');
        if (nameInput) setTimeout(function() { nameInput.focus(); }, 250);
    } else {
        card.style.display = 'none';
        if (btn) {
            btn.innerHTML = '＋ Register New Donor';
            btn.classList.remove('secondary');
            btn.classList.add('blue');
        }
    }
}
</script>
</body>
</html>
