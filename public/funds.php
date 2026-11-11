<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();
require_permission('funds.view');

$activePage = 'funds';
$error = '';
$success = '';

$userId = (int)current_user()['id'];
$isSuperAdmin = function_exists('is_super_admin') ? is_super_admin() : in_array(current_user()['role'] ?? '', ['Super Admin', 'Administrator'], true);
$userFundIds = get_user_assigned_fund_ids($userId);
$hasFundRestriction = (!$isSuperAdmin && !empty($userFundIds));
$madrasaName = function_exists('get_setting') ? get_setting('madrasa_name', 'Trust Accounts') : 'Trust Accounts';

// Handle Delete Fund
if (isset($_GET['delete_id']) && !has_permission('funds.manage')) {
    $error = 'آپ کے پاس فنڈز ڈیلیٹ کرنے کا اختیار نہیں ہے۔';
} elseif (isset($_GET['delete_id'])) {
    $delId = (int)$_GET['delete_id'];
    $token = $_GET['token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $error = 'Invalid security token for deletion.';
    } else {
        try {
            $stmtFund = $pdo->prepare("SELECT * FROM funds WHERE id = ?");
            $stmtFund->execute([$delId]);
            $fund = $stmtFund->fetch();
            if (!$fund) {
                throw new RuntimeException("Fund not found.");
            }
            if ($hasFundRestriction && !in_array($delId, $userFundIds, true)) {
                throw new RuntimeException("آپ کے پاس اس فنڈ کو ڈیلیٹ کرنے کا اختیار نہیں ہے۔");
            }

            // Ensure at least one active fund remains
            $activeFundsCount = (int)$pdo->query("SELECT COUNT(*) FROM funds WHERE is_active = 1")->fetchColumn();
            if ($activeFundsCount <= 1 && (int)$fund['is_active'] === 1) {
                throw new RuntimeException("سسٹم میں کم از کم ایک فعال فنڈ ہونا ضروری ہے، تمام فنڈز ڈیلیٹ نہیں کیے جا سکتے۔");
            }

            // Check if fund is used in any vouchers or journal lines
            $voucherCount = (int)$pdo->query("SELECT COUNT(*) FROM vouchers WHERE fund_id = {$delId}")->fetchColumn();
            $journalCount = (int)$pdo->query("SELECT COUNT(*) FROM journal_lines WHERE fund_id = {$delId}")->fetchColumn();
            $totalUsage = $voucherCount + $journalCount;

            if ($totalUsage > 0) {
                // Safely deactivate so ledger and historical vouchers remain intact
                $pdo->prepare("UPDATE funds SET is_active = 0 WHERE id = ?")->execute([$delId]);
                $success = "فنڈ '" . e($fund['name']) . "' میں {$totalUsage} واؤچرز/ٹرانزیکشنز ریکارڈ ہیں۔ مالیاتی ریکارڈ کے تحفظ کے لیے اسے غیر فعال (Deactivate) کر دیا گیا ہے۔";
            } else {
                // Permanently delete
                // Clean up user_funds and donors links before permanent delete
                $pdo->prepare("DELETE FROM user_funds WHERE fund_id = ?")->execute([$delId]);
                $pdo->prepare("UPDATE donors SET fund_id = NULL WHERE fund_id = ?")->execute([$delId]);
                $pdo->prepare("DELETE FROM funds WHERE id = ?")->execute([$delId]);
                $success = "فنڈ '" . e($fund['name']) . "' کامیابی سے مستقل ڈیلیٹ کر دیا گیا ہے۔";
            }
        } catch (Exception $e) {
            $error = 'ڈیلیٹ نہیں ہو سکا: ' . $e->getMessage();
        }
    }
}

// Handle Add or Edit Fund Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && !has_permission('funds.manage')) {
    $error = 'آپ کے پاس فنڈز بنانے یا تبدیل کرنے کا اختیار نہیں ہے۔';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf();
    $action = $_POST['action'];

    if ($action === 'save_fund') {
        $name = trim($_POST['name'] ?? '');
        $fundCode = strtoupper(trim($_POST['fund_code'] ?? ''));
        $description = trim($_POST['description'] ?? '');
        $isRestricted = isset($_POST['is_restricted']) ? 1 : 0;

        if (empty($name) || empty($fundCode)) {
            $error = 'Please enter both Fund Name and Fund Code.';
        } else {
            try {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM funds WHERE fund_code = ? OR name = ?");
                $chk->execute([$fundCode, $name]);
                if ($chk->fetchColumn() > 0) {
                    throw new RuntimeException("Fund Code '{$fundCode}' or Name '{$name}' already exists. Please choose a unique name and code.");
                }

                $stmt = $pdo->prepare('INSERT INTO funds (name, fund_code, description, is_restricted, is_active) VALUES (?, ?, ?, ?, 1)');
                $stmt->execute([$name, $fundCode, $description ?: null, $isRestricted]);
                header('Location: funds.php?success=created');
                exit;
            } catch (Exception $e) {
                $error = 'Failed to create fund: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'update_fund') {
        $fundId = (int)($_POST['fund_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $fundCode = strtoupper(trim($_POST['fund_code'] ?? ''));
        $description = trim($_POST['description'] ?? '');
        $isRestricted = isset($_POST['is_restricted']) ? 1 : 0;
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($fundId <= 0 || empty($name) || empty($fundCode)) {
            $error = 'Please enter both Fund Name and Fund Code.';
        } else {
            try {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM funds WHERE (fund_code = ? OR name = ?) AND id != ?");
                $chk->execute([$fundCode, $name, $fundId]);
                if ($chk->fetchColumn() > 0) {
                    throw new RuntimeException("Fund Code '{$fundCode}' or Name '{$name}' is already used by another fund.");
                }

                $stmt = $pdo->prepare('UPDATE funds SET name = ?, fund_code = ?, description = ?, is_restricted = ?, is_active = ? WHERE id = ?');
                $stmt->execute([$name, $fundCode, $description ?: null, $isRestricted, $isActive, $fundId]);
                header('Location: funds.php?success=updated');
                exit;
            } catch (Exception $e) {
                $error = 'Failed to update fund: ' . $e->getMessage();
            }
        }
    }
}

// Edit mode data
$editFund = null;
if (isset($_GET['edit_id'])) {
    $editId = (int)$_GET['edit_id'];
    $stmtEdit = $pdo->prepare('SELECT * FROM funds WHERE id = ?');
    $stmtEdit->execute([$editId]);
    $editFund = $stmtEdit->fetch();
    if ($editFund && $hasFundRestriction && !in_array($editId, $userFundIds, true)) {
        $error = 'آپ کے پاس اس فنڈ کو تبدیل کرنے کا اختیار نہیں ہے۔';
        $editFund = null;
    }
}
$showForm = !empty($editFund) || (!empty($error) && $_SERVER['REQUEST_METHOD'] === 'POST');

// Fetch Funds with calculated balances from posted double-entry journal lines
$currOrgMode = function_exists('get_org_mode') ? get_org_mode() : 'trust';
$whereParts = ["f.scope IN ('all', " . $pdo->quote($currOrgMode) . ")"];
if ($hasFundRestriction && !empty($userFundIds)) {
    $inFunds = implode(',', array_map('intval', $userFundIds));
    $whereParts[] = "f.id IN ($inFunds)";
}
$whereSql = implode(' AND ', $whereParts);

$fundsQuery = "SELECT f.*,
               COALESCE(SUM(CASE WHEN a.account_type = 'income' THEN (jl.credit - jl.debit) ELSE 0 END), 0) AS total_income,
               COALESCE(SUM(CASE WHEN a.account_type = 'expense' THEN (jl.debit - jl.credit) ELSE 0 END), 0) AS total_expense
               FROM funds f
               LEFT JOIN journal_lines jl ON jl.fund_id = f.id
               LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'posted'
               LEFT JOIN accounts a ON a.id = jl.account_id
               WHERE {$whereSql}
               GROUP BY f.id
               ORDER BY f.is_active DESC, f.id ASC";
$funds = $pdo->query($fundsQuery)->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?=is_madrasa_mode() ? 'Funds & Depts' : 'Funds & Projects'?> | <?=e($madrasaName)?></title>
    <link rel="stylesheet" href="assets/style.css?v=<?=css_ver()?>">
</head>
<body>
<div class="app-shell">
    <?php require __DIR__ . '/../app/sidebar.php'; ?>

    <main class="main">
        <header class="topbar">
            <div>
                <span class="eyebrow">ACCOUNTING</span>
                <h1><?=is_madrasa_mode() ? 'Funds & Depts <span class="ur">(فنڈز و شعبہ جات)</span>' : 'Funds & Projects <span class="ur">(فنڈز و پروجیکٹس)</span>'?></h1>
            </div>
            <div class="btn-row"><?php if (has_permission('funds.manage')): ?>
                <?php if ($editFund): ?>
                    <a href="funds.php" class="btn secondary sm">✕ Cancel Editing <span class="ur">(منسوخ کریں)</span></a>
                <?php else: ?>
                    <button type="button" class="btn blue sm" id="toggleFundBtn" onclick="toggleFundForm()">
                        ＋ Create New Fund
                    </button>
                <?php endif; ?><?php endif; ?>
            </div>
        </header>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert success">
                <?php if ($_GET['success'] === 'created' || $_GET['success'] === '1') echo 'New Fund category created successfully!'; ?>
                <?php if ($_GET['success'] === 'updated') echo 'Fund category details updated successfully!'; ?>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert success"><?=e($success)?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert error"><?=e($error)?></div>
        <?php endif; ?>

        <!-- Create / Edit Fund Form Card (Hidden by default, shown on "+ Create New Fund" click) -->
        <article class="form-card" id="fundFormCard" style="<?= $showForm ? 'margin:20px 0 24px;' : 'display:none; margin:20px 0 24px;' ?>">
            <div class="form-card-header" style="display:flex;justify-content:space-between;align-items:flex-start;">
                <div>
                    <h3><?= $editFund ? 'Edit Fund / Project <span class="ur">(فنڈ یا پروجیکٹ میں ترمیم کریں)</span>' : 'Create New Fund / Project <span class="ur">(نیا فنڈ یا پروجیکٹ بنائیں)</span>' ?></h3>
                    <p><?= $editFund ? 'Update fund name, code, restriction type, and active status' : 'Segregate funds for Zakat, Ration, Medical, Water Projects, Welfare, etc.' ?></p>
                </div>
                <?php if (!$editFund): ?>
                    <button type="button" class="btn sm secondary" onclick="toggleFundForm(false)" style="padding:4px 10px;" title="فارم بند کریں">✕</button>
                <?php endif; ?>
            </div>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                <input type="hidden" name="action" value="<?= $editFund ? 'update_fund' : 'save_fund' ?>">
                <?php if ($editFund): ?>
                    <input type="hidden" name="fund_id" value="<?=$editFund['id']?>">
                <?php endif; ?>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Fund Name <span class="ur">نام فنڈ</span></label>
                        <input name="name" required placeholder="e.g. Ration Aid Fund / Medical Fund / Zakat" value="<?=e($editFund['name'] ?? '')?>">
                    </div>

                    <div class="form-group">
                        <label>Fund Code <span class="ur">مختصر کوڈ</span></label>
                        <input name="fund_code" required placeholder="e.g. RAF, MAF, CWP" maxlength="10" style="text-transform:uppercase;font-weight:700;" value="<?=e($editFund['fund_code'] ?? '')?>">
                    </div>

                    <div class="form-group">
                        <label>Islamic Restriction <span class="ur">شرعی حیثیت</span></label>
                        <div style="padding-top:10px;">
                            <label style="font-weight:normal;cursor:pointer;display:flex;align-items:center;gap:8px;">
                                <input type="checkbox" name="is_restricted" value="1" <?= (!empty($editFund['is_restricted']) && (int)$editFund['is_restricted'] === 1) ? 'checked' : '' ?> style="width:18px;height:18px;">
                                <span><b>Restricted Fund <span class="ur">(مخصوص فنڈ)</span></b> — Can only be used for specified Islamic purposes (e.g. Zakat).</span>
                            </label>
                        </div>
                    </div>

                    <?php if ($editFund): ?>
                    <div class="form-group">
                        <label>Fund Status <span class="ur">حیثیت فنڈ</span></label>
                        <div style="padding-top:10px;">
                            <label style="font-weight:normal;cursor:pointer;display:flex;align-items:center;gap:8px;">
                                <input type="checkbox" name="is_active" value="1" <?= ((int)($editFund['is_active'] ?? 1) === 1) ? 'checked' : '' ?> style="width:18px;height:18px;">
                                <span><b>Active Status <span class="ur">(فعال فنڈ)</span></b> — Uncheck to deactivate/hide from income & expense forms.</span>
                            </label>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Description / Purpose <span class="ur">مقصد و تفصیل</span></label>
                        <input name="description" placeholder="Specify who or what this fund can be spent on..." value="<?=e($editFund['description'] ?? '')?>">
                    </div>
                </div>

                <div class="btn-row" style="margin-top:10px;">
                    <button class="btn blue" type="submit">
                        <?= $editFund ? '💾 Update Fund Details' : '💾 Create Fund' ?>
                    </button>
                    <?php if ($editFund): ?>
                        <a href="funds.php" class="btn secondary">Cancel Editing</a>
                    <?php else: ?>
                        <button class="btn secondary" type="button" onclick="toggleFundForm(false)">✕ Cancel <span class="ur">(منسوخ کریں)</span></button>
                    <?php endif; ?>
                </div>
            </form>
        </article>

        <!-- Funds Balances Table (Directly shown at the top) -->
        <article class="panel" style="margin-top:20px;">
            <div class="panel-heading">
                <div>
                    <h3>Active Funds & Real-Time Balances</h3>
                    <p>Automatic fund tracking from double-entry posted vouchers</p>
                </div>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Fund Name</th>
                            <th>Restriction</th>
                            <th class="right">Total Inflow <span class="ur">(آمدنی)</span></th>
                            <th class="right">Total Outflow <span class="ur">(اخراجات)</span></th>
                            <th class="right">Current Balance <span class="ur">(موجودہ بیلنس)</span></th>
                            <th style="text-align:center;">Actions <span class="ur">(کارروائی)</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $grandInflow = 0; 
                        $grandOutflow = 0; 
                        $grandBalance = 0;
                        foreach ($funds as $f): 
                            $inflow = (float)$f['total_income'];
                            $outflow = (float)$f['total_expense'];
                            $net = $inflow - $outflow;
                            $grandInflow += $inflow;
                            $grandOutflow += $outflow;
                            $grandBalance += $net;
                            $isInactive = ((int)$f['is_active'] === 0);
                        ?>
                            <tr style="<?=$isInactive ? 'opacity:0.65;background:#f8fafc;' : ''?>">
                                <td><b><?=e($f['fund_code'])?></b></td>
                                <td>
                                    <b><?=e($f['name'])?></b>
                                    <?php if ($isInactive): ?>
                                        <span class="badge badge-gray" style="margin-left:6px;font-size:10px;">Inactive <span class="ur">(غیر فعال)</span></span>
                                    <?php endif; ?>
                                    <?php if ($f['description']): ?>
                                        <div style="font-size:11px;color:#64748b;"><?=e($f['description'])?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ((int)$f['is_restricted'] === 1): ?>
                                        <span class="badge badge-amber">Restricted <span class="ur">(مخصوص)</span></span>
                                    <?php else: ?>
                                        <span class="badge badge-blue">General <span class="ur">(عام)</span></span>
                                    <?php endif; ?>
                                </td>
                                <td class="right font-bold" style="color:#15803d;"><?=money($inflow)?></td>
                                <td class="right font-bold" style="color:#b91c1c;"><?=money($outflow)?></td>
                                <td class="right font-bold" style="font-size:14px;color:<?=$net >= 0 ? '#15803d' : '#b91c1c'?>;">
                                    <?=money($net)?>
                                </td>
                                <td style="text-align:center;white-space:nowrap;">
                                    <div class="btn-row" style="justify-content:center;gap:6px;">
                                    <?php if (has_permission('funds.manage')): ?><a href="funds.php?edit_id=<?=$f['id']?>" class="btn sm secondary" title="ترمیم کریں">✏ Edit</a>
                                        <a href="funds.php?delete_id=<?=$f['id']?>&token=<?=csrf_token()?>" class="btn sm danger" title="ڈیلیٹ کریں" onclick="return confirm('کیا آپ واقعی فنڈ \'<?=e(addslashes($f['name']))?>\' ڈیلیٹ یا غیر فعال کرنا چاہتے ہیں؟');">🗑 Delete</a><?php else: ?><span class="muted" style="font-size:11px;">View Only</span><?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr style="background:#f8fafc;font-weight:bold;border-top:2px solid #cbd5e1;">
                            <td colspan="3"><b>Total All Funds <span class="ur">(مجموعی میزان)</span></b></td>
                            <td class="right" style="color:#15803d;"><?=money($grandInflow)?></td>
                            <td class="right" style="color:#b91c1c;"><?=money($grandOutflow)?></td>
                            <td class="right" style="font-size:15px;color:<?=$grandBalance >= 0 ? '#15803d' : '#b91c1c'?>;"><?=money($grandBalance)?></td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </article>

        <?=render_app_footer($madrasaName)?>
    </main>
</div>

<script>
function toggleFundForm(forceOpen) {
    var card = document.getElementById('fundFormCard');
    var btn = document.getElementById('toggleFundBtn');
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
        var nameInput = card.querySelector('input[name="name"]');
        if (nameInput) setTimeout(function() { nameInput.focus(); }, 250);
    } else {
        card.style.display = 'none';
        if (btn) {
            btn.innerHTML = '＋ Create New Fund';
            btn.classList.remove('secondary');
            btn.classList.add('blue');
        }
    }
}
</script>
</body>
</html>