<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

// Permissions
if (!has_permission('budgets.view') && !can_approve() && !has_permission('expense.view')) {
    redirect_with_alert('dashboard.php', 'error', 'آپ کے پاس بجٹ دیکھنے کا اختیار نہیں ہے۔');
}

$activePage = 'budgets';
$error = '';
$success = '';

// Ensure budgets table exists (Auto-create if not yet created)
try {
    $pdo->query("SELECT 1 FROM budgets LIMIT 1");
} catch (Throwable $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS budgets (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      account_id BIGINT UNSIGNED NOT NULL,
      fiscal_month VARCHAR(7) NOT NULL,
      budget_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
      alert_threshold_percent INT UNSIGNED NOT NULL DEFAULT 80,
      created_by BIGINT UNSIGNED NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_acc_month (account_id, fiscal_month),
      INDEX idx_bgt_month (fiscal_month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

$userId = (int)current_user()['id'];
$canManageBudgets = has_permission('budgets.manage') || can_approve();
$madrasaName = function_exists('get_setting') ? get_setting('madrasa_name', 'Trust Accounts') : 'Trust Accounts';

// Selected Month (Format: YYYY-MM)
$selectedMonth = trim($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}
$monthLabel = date('F Y', strtotime($selectedMonth . '-01'));
$prevMonth = date('Y-m', strtotime($selectedMonth . '-01 -1 month'));
$nextMonth = date('Y-m', strtotime($selectedMonth . '-01 +1 month'));

// Handle Save / Update Budget
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_budget') {
    verify_csrf();
    if (!$canManageBudgets) {
        $error = 'آپ کے پاس بجٹ مقرر یا تبدیل کرنے کا اختیار نہیں ہے۔';
    } else {
        $accountId = (int)($_POST['account_id'] ?? 0);
        $budgetAmount = max(0, (float)($_POST['budget_amount'] ?? 0));
        $alertPercent = max(50, min(100, (int)($_POST['alert_threshold_percent'] ?? 80)));
        $targetMonth = trim($_POST['fiscal_month'] ?? $selectedMonth);

        if ($accountId <= 0) {
            $error = 'براہ کرم درست خرچ کھاتہ منتخب کریں۔';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO budgets (account_id, fiscal_month, budget_amount, alert_threshold_percent, created_by) 
                    VALUES (?, ?, ?, ?, ?) 
                    ON DUPLICATE KEY UPDATE budget_amount = VALUES(budget_amount), alert_threshold_percent = VALUES(alert_threshold_percent)");
                $stmt->execute([$accountId, $targetMonth, $budgetAmount, $alertPercent, $userId]);

                if (function_exists('log_audit_trail')) {
                    log_audit_trail('save_budget', 'budget', $accountId, [
                        'month' => $targetMonth,
                        'amount' => $budgetAmount,
                        'alert_percent' => $alertPercent
                    ]);
                }
                $success = "ماہانہ بجٹ کامیابی سے محفوظ کر لیا گیا۔ ({$monthLabel})";
            } catch (Exception $e) {
                $error = 'بجٹ محفوظ نہیں ہو سکا: ' . $e->getMessage();
            }
        }
    }
}

// Handle Copy Budget From Previous Month
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'copy_from_previous') {
    verify_csrf();
    if (!$canManageBudgets) {
        $error = 'آپ کے پاس بجٹ تبدیل کرنے کا اختیار نہیں ہے۔';
    } else {
        try {
            $prevBudgets = $pdo->prepare("SELECT account_id, budget_amount, alert_threshold_percent FROM budgets WHERE fiscal_month = ? AND budget_amount > 0");
            $prevBudgets->execute([$prevMonth]);
            $rows = $prevBudgets->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                $error = "سابقہ مہینے ({$prevMonth}) کا کوئی فعال بجٹ نہیں ملا۔";
            } else {
                $stmtIns = $pdo->prepare("INSERT INTO budgets (account_id, fiscal_month, budget_amount, alert_threshold_percent, created_by) 
                    VALUES (?, ?, ?, ?, ?) 
                    ON DUPLICATE KEY UPDATE budget_amount = VALUES(budget_amount), alert_threshold_percent = VALUES(alert_threshold_percent)");
                $copiedCount = 0;
                foreach ($rows as $r) {
                    $stmtIns->execute([(int)$r['account_id'], $selectedMonth, (float)$r['budget_amount'], (int)$r['alert_threshold_percent'], $userId]);
                    $copiedCount++;
                }
                $success = "سابقہ مہینے کے {$copiedCount} کھاتوں کا بجٹ رواں مہینے ({$monthLabel}) میں منتقل کر دیا گیا۔";
            }
        } catch (Exception $e) {
            $error = 'بجٹ کاپی نہیں ہو سکا: ' . $e->getMessage();
        }
    }
}

// Fetch Expense Accounts
$mode = function_exists('get_org_mode') ? get_org_mode() : 'trust';
$stmtExp = $pdo->prepare("SELECT a.id, a.account_code, a.account_name, a.scope 
    FROM accounts a 
    WHERE a.account_type = 'expense' AND a.is_active = 1 AND a.scope IN ('all', ?) 
    ORDER BY a.account_code ASC");
$stmtExp->execute([$mode]);
$expenseAccounts = $stmtExp->fetchAll(PDO::FETCH_ASSOC);

// Fetch Set Budgets for this month
$stmtBgt = $pdo->prepare("SELECT account_id, budget_amount, alert_threshold_percent FROM budgets WHERE fiscal_month = ?");
$stmtBgt->execute([$selectedMonth]);
$budgetsMap = [];
foreach ($stmtBgt->fetchAll(PDO::FETCH_ASSOC) as $b) {
    $budgetsMap[(int)$b['account_id']] = [
        'amount' => (float)$b['budget_amount'],
        'alert_percent' => (int)$b['alert_threshold_percent']
    ];
}

// Fetch Actual Spent for each account in this month
$stmtSpent = $pdo->prepare("SELECT jl.account_id, SUM(jl.debit - jl.credit) AS actual_spent 
    FROM journal_lines jl 
    JOIN journal_entries je ON je.id = jl.journal_entry_id 
    WHERE je.status = 'posted' AND DATE_FORMAT(je.entry_date, '%Y-%m') = ? 
    GROUP BY jl.account_id");
$stmtSpent->execute([$selectedMonth]);
$spentMap = [];
foreach ($stmtSpent->fetchAll(PDO::FETCH_ASSOC) as $s) {
    $spentMap[(int)$s['account_id']] = max(0, (float)$s['actual_spent']);
}

// Calculate Aggregate KPI Stats
$totalBudget = 0.00;
$totalSpent = 0.00;
$overBudgetCount = 0;
$nearLimitCount = 0;

$items = [];
foreach ($expenseAccounts as $acc) {
    $accId = (int)$acc['id'];
    $bData = $budgetsMap[$accId] ?? ['amount' => 0.00, 'alert_percent' => 80];
    $budget = $bData['amount'];
    $spent = $spentMap[$accId] ?? 0.00;
    $remaining = $budget > 0 ? ($budget - $spent) : 0.00;
    $percent = $budget > 0 ? round(($spent / $budget) * 100, 1) : 0;
    $alertThresh = $bData['alert_percent'];

    if ($budget > 0) {
        $totalBudget += $budget;
        $totalSpent += $spent;
        if ($percent >= 100) {
            $overBudgetCount++;
        } elseif ($percent >= $alertThresh) {
            $nearLimitCount++;
        }
    }

    $items[] = [
        'id' => $accId,
        'code' => $acc['account_code'],
        'name' => $acc['account_name'],
        'budget' => $budget,
        'spent' => $spent,
        'remaining' => $remaining,
        'percent' => $percent,
        'alert_percent' => $alertThresh
    ];
}

$overallRemaining = $totalBudget - $totalSpent;
$overallPercent = $totalBudget > 0 ? round(($totalSpent / $totalBudget) * 100, 1) : 0;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Budgets &amp; Expense Limits | <?=e($madrasaName)?></title>
    <link rel="stylesheet" href="assets/style.css?v=<?=css_ver()?>">
</head>
<body>
<div class="app-shell">
    <?php require __DIR__ . '/../app/sidebar.php'; ?>

    <main class="main">
        <div style="max-width:1200px;margin:0 auto;padding:16px 20px;">
        
        <!-- Header & Month Filter Navigation -->
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;margin-bottom:20px;">
            <div>
                <h1 style="font-size:22px;color:#0f172a;margin:0;display:flex;align-items:center;gap:10px;">
                    <span>📊</span>
                    <span>ماہانہ بجٹ اور اخراجات کنٹرول (Budgets &amp; Limits)</span>
                </h1>
                <p style="color:#64748b;font-size:13px;margin:4px 0 0;">
                    ہر شعبے و خرچ کی مد کے لیے ماہانہ بجٹ مقرر کریں اور اوور اسپینڈنگ سے محفوظ رہیں
                </p>
            </div>

            <!-- Month Switcher & Actions -->
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <div style="display:inline-flex;align-items:center;background:#ffffff;border:1.5px solid #cbd5e1;border-radius:8px;padding:3px;box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                    <a href="budgets.php?month=<?=$prevMonth?>" class="btn secondary sm" style="padding:4px 10px;text-decoration:none;" title="Previous Month">◀</a>
                    <form method="get" action="budgets.php" style="margin:0;display:inline;">
                        <input type="month" name="month" value="<?=$selectedMonth?>" onchange="this.form.submit()" style="border:none;font-weight:700;color:#0f172a;font-size:13px;padding:4px 8px;cursor:pointer;">
                    </form>
                    <a href="budgets.php?month=<?=$nextMonth?>" class="btn secondary sm" style="padding:4px 10px;text-decoration:none;" title="Next Month">▶</a>
                </div>

                <?php if ($canManageBudgets): ?>
                    <form method="post" action="budgets.php?month=<?=$selectedMonth?>" style="display:inline;" onsubmit="return confirm('کیا آپ واقعی پچھلے مہینے کا بجٹ اس مہینے میں منتقل کرنا چاہتے ہیں؟');">
                        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                        <input type="hidden" name="action" value="copy_from_previous">
                        <button type="submit" class="btn secondary sm" style="padding:7px 12px;font-size:12px;font-weight:700;" title="سابقہ مہینے سے بجٹ منتقل کریں">
                            📋 Copy from Prev Month
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert error" style="margin-bottom:16px;"><?=e($error)?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert success" style="margin-bottom:16px;"><?=e($success)?></div>
        <?php endif; ?>

        <!-- Warning Banner for Over-Budget Items -->
        <?php if ($overBudgetCount > 0): ?>
            <div style="background:#fef2f2;border:1.5px solid #f87171;border-radius:10px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:12px;">
                <span style="font-size:26px;">🚨</span>
                <div>
                    <b style="color:#991b1b;font-size:14px;">بجٹ الرٹ: <?=$overBudgetCount?> اخراجات کھاتے اپنے ماہانہ بجٹ کی حد سے تجاوز کر چکے ہیں!</b>
                    <div style="font-size:12px;color:#b91c1c;margin-top:2px;">
                        برائے مہربانی فوری طور پر غیر ضروری اخراجات روکیں تاکہ مالیاتی خسارے سے بچا جا سکے۔
                    </div>
                </div>
            </div>
        <?php elseif ($nearLimitCount > 0): ?>
            <div style="background:#fffbeb;border:1.5px solid #fcd34d;border-radius:10px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:12px;">
                <span style="font-size:26px;">⚠️</span>
                <div>
                    <b style="color:#92400e;font-size:14px;">انتباہ: <?=$nearLimitCount?> اخراجات کھاتے بجٹ حد کے قریب (80% سے زائد) پہنچ چکے ہیں!</b>
                </div>
            </div>
        <?php endif; ?>

        <!-- Top KPI Cards Grid -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));gap:14px;margin-bottom:20px;">
            <!-- Card 1: Total Budget -->
            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                <span style="font-size:12px;color:#64748b;font-weight:700;display:block;">مجموعی ماہانہ بجٹ (Total Budget)</span>
                <b style="font-size:22px;color:#0f172a;display:block;margin:6px 0;">Rs. <?=number_format($totalBudget, 2)?></b>
                <span style="font-size:11.5px;color:#0284c7;">برائے <?=e($monthLabel)?></span>
            </div>

            <!-- Card 2: Total Spent -->
            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                <span style="font-size:12px;color:#64748b;font-weight:700;display:block;">کل خرچ شدہ رقم (Actual Spent)</span>
                <b style="font-size:22px;color:<?=$overallPercent >= 100 ? '#dc2626' : ($overallPercent >= 80 ? '#d97706' : '#15803d')?>;display:block;margin:6px 0;">
                    Rs. <?=number_format($totalSpent, 2)?>
                </b>
                <span style="font-size:11.5px;color:#64748b;">
                    استعمال: <strong><?=$overallPercent?>%</strong>
                </span>
            </div>

            <!-- Card 3: Remaining Balance -->
            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                <span style="font-size:12px;color:#64748b;font-weight:700;display:block;">باقی بچ جانے والا بجٹ (Remaining)</span>
                <b style="font-size:22px;color:<?=$overallRemaining < 0 ? '#dc2626' : '#15803d'?>;display:block;margin:6px 0;">
                    Rs. <?=number_format($overallRemaining, 2)?>
                </b>
                <span style="font-size:11.5px;color:<?=$overallRemaining < 0 ? '#dc2626' : '#15803d'?>;">
                    <?=$overallRemaining < 0 ? 'تجاوز (Over Budget)' : 'محفوظ حد (Under Budget)'?>
                </span>
            </div>

            <!-- Card 4: Over Budget Count -->
            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                <span style="font-size:12px;color:#64748b;font-weight:700;display:block;">حد سے متجاوز کھاتے (Over-Limit Heads)</span>
                <b style="font-size:22px;color:<?=$overBudgetCount > 0 ? '#dc2626' : '#15803d'?>;display:block;margin:6px 0;">
                    <?=$overBudgetCount?> کھاتے
                </b>
                <span style="font-size:11.5px;color:#64748b;">قریب البلوغ: <?=$nearLimitCount?> کھاتے</span>
            </div>
        </div>

        <!-- Expense Budgets Table Panel -->
        <div style="background:#ffffff;border:1px solid #cbd5e1;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
            <div style="padding:14px 20px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
                <h3 style="margin:0;font-size:15px;color:#0f172a;">تفصیل بجٹ بمقابلہ اصل اخراجات (Budget vs Actual)</h3>
                <span style="font-size:12px;color:#64748b;">ماہ: <strong><?=e($monthLabel)?></strong></span>
            </div>

            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:13px;text-align:right;">
                    <thead>
                        <tr style="background:#f1f5f9;color:#334155;border-bottom:1.5px solid #cbd5e1;">
                            <th style="padding:12px 16px;text-align:left;">Account Code &amp; Name (مد خرچ)</th>
                            <th style="padding:12px 16px;">مقرر بجٹ (Budget)</th>
                            <th style="padding:12px 16px;">اصل خرچ (Actual Spent)</th>
                            <th style="padding:12px 16px;">باقی رقم (Remaining)</th>
                            <th style="padding:12px 16px;min-width:180px;">پیشرفت (Progress)</th>
                            <th style="padding:12px 16px;text-align:center;">حیثیت (Status)</th>
                            <?php if ($canManageBudgets): ?>
                                <th style="padding:12px 16px;text-align:center;">عمل (Action)</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): 
                            $pct = $item['percent'];
                            $barColor = '#15803d'; // Green
                            $badgeClass = 'badge-green';
                            $statusText = 'نارمل';
                            if ($item['budget'] <= 0) {
                                $statusText = 'بجٹ مقرر نہیں';
                                $badgeClass = 'secondary';
                            } elseif ($pct >= 100) {
                                $barColor = '#dc2626'; // Red
                                $badgeClass = 'badge-red';
                                $statusText = 'تجاوز 🚨';
                            } elseif ($pct >= $item['alert_percent']) {
                                $barColor = '#d97706'; // Orange
                                $badgeClass = 'badge-yellow';
                                $statusText = 'قریب البلوغ ⚠️';
                            }
                        ?>
                        <tr style="border-bottom:1px solid #f1f5f9;transition:background 0.2s;">
                            <td style="padding:12px 16px;text-align:left;">
                                <strong style="color:#0f172a;"><?=e($item['code'])?></strong> &bull; <?=e(format_ui_text($item['name']))?>
                            </td>
                            <td style="padding:12px 16px;font-weight:700;color:#0f172a;">
                                <?= $item['budget'] > 0 ? ('Rs. ' . number_format($item['budget'], 2)) : '<span style="color:#94a3b8;">—</span>' ?>
                            </td>
                            <td style="padding:12px 16px;font-weight:700;color:<?=$pct >= 100 ? '#dc2626' : '#334155'?>;">
                                Rs. <?=number_format($item['spent'], 2)?>
                            </td>
                            <td style="padding:12px 16px;font-weight:700;color:<?=$item['remaining'] < 0 ? '#dc2626' : '#15803d'?>;">
                                <?= $item['budget'] > 0 ? ('Rs. ' . number_format($item['remaining'], 2)) : '<span style="color:#94a3b8;">—</span>' ?>
                            </td>
                            <td style="padding:12px 16px;">
                                <?php if ($item['budget'] > 0): ?>
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <div style="flex:1;background:#e2e8f0;height:8px;border-radius:4px;overflow:hidden;">
                                            <div style="background:<?=$barColor?>;height:100%;width:<?=min(100, $pct)?>%;"></div>
                                        </div>
                                        <span style="font-size:11.5px;font-weight:700;color:<?=$barColor?>;min-width:42px;"><?=round($pct)?>%</span>
                                    </div>
                                <?php else: ?>
                                    <span style="color:#94a3b8;font-size:11.5px;">کوئی حد نہیں</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:12px 16px;text-align:center;">
                                <span class="badge <?=$badgeClass?>" style="font-size:11px;font-weight:700;">
                                    <?=$statusText?>
                                </span>
                            </td>
                            <?php if ($canManageBudgets): ?>
                                <td style="padding:12px 16px;text-align:center;">
                                    <button type="button" class="btn secondary sm" style="padding:4px 10px;font-size:11.5px;font-weight:700;" onclick='openBudgetModal(<?=json_encode($item)?>)'>
                                        ✏️ بجٹ سیٹ کریں
                                    </button>
                                </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<!-- Modal: Set / Update Account Monthly Budget -->
<div id="budgetModal" class="modal-backdrop" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.65);z-index:99999;align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(3px);">
    <div style="background:#ffffff;border-radius:12px;width:100%;max-width:480px;box-shadow:0 25px 35px -5px rgba(0,0,0,0.3);overflow:hidden;border:1px solid #cbd5e1;">
        <div style="padding:14px 18px;background:#0f172a;color:#ffffff;display:flex;justify-content:space-between;align-items:center;">
            <b style="font-size:14.5px;">📊 ماہانہ بجٹ مقرر کریں (Set Budget)</b>
            <button type="button" onclick="closeBudgetModal()" style="background:none;border:none;color:#cbd5e1;font-size:20px;cursor:pointer;">&times;</button>
        </div>
        <form method="post" action="budgets.php?month=<?=$selectedMonth?>" style="padding:18px 20px;">
            <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
            <input type="hidden" name="action" value="save_budget">
            <input type="hidden" name="account_id" id="bm_account_id">
            <input type="hidden" name="fiscal_month" value="<?=$selectedMonth?>">

            <div style="background:#f8fafc;padding:10px 12px;border-radius:6px;border:1px solid #e2e8f0;margin-bottom:14px;">
                <span style="font-size:11px;color:#64748b;display:block;">کھاتہ (Account):</span>
                <strong id="bm_account_name" style="color:#0f172a;font-size:13.5px;">—</strong>
                <div style="font-size:11.5px;color:#0284c7;margin-top:2px;">برائے مہینہ: <?=e($monthLabel)?></div>
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block;font-size:12.5px;font-weight:700;color:#0f172a;margin-bottom:5px;">
                    Monthly Budget Amount <span class="ur">(ماہانہ بجٹ رقم PKR)</span> *
                </label>
                <input type="number" step="100" min="0" name="budget_amount" id="bm_budget_amount" required style="width:100%;font-size:16px;font-weight:700;padding:9px 12px;border:1.5px solid #cbd5e1;border-radius:6px;box-sizing:border-box;" placeholder="e.g. 50000">
            </div>

            <div style="margin-bottom:18px;">
                <label style="display:block;font-size:12.5px;font-weight:700;color:#0f172a;margin-bottom:5px;">
                    Alert Threshold <span class="ur">(کتنے فیصد پر وارننگ الرٹ دکھائیں)</span> *
                </label>
                <select name="alert_threshold_percent" id="bm_alert_percent" style="width:100%;font-size:13px;padding:8px 10px;border:1.5px solid #cbd5e1;border-radius:6px;box-sizing:border-box;">
                    <option value="75">75% خرچ ہونے پر</option>
                    <option value="80" selected>80% خرچ ہونے پر (Recommended)</option>
                    <option value="90">90% خرچ ہونے پر</option>
                    <option value="100">100% مکمل حد پر پہنچنے پر</option>
                </select>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:8px;padding-top:12px;border-top:1px solid #e2e8f0;">
                <button type="button" onclick="closeBudgetModal()" class="btn secondary" style="padding:8px 16px;">منسوخ کریں</button>
                <button type="submit" class="btn" style="background:#0f172a;color:#ffffff;border:none;padding:8px 20px;font-weight:700;border-radius:6px;cursor:pointer;">
                    💾 محفوظ کریں (Save Budget)
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openBudgetModal(item) {
    if (!item) return;
    document.getElementById('bm_account_id').value = item.id;
    document.getElementById('bm_account_name').textContent = item.code + ' - ' + item.name;
    document.getElementById('bm_budget_amount').value = item.budget > 0 ? item.budget : '';
    document.getElementById('bm_alert_percent').value = item.alert_percent || 80;
    
    var m = document.getElementById('budgetModal');
    if (m) {
        m.style.display = 'flex';
        setTimeout(function() {
            var input = document.getElementById('bm_budget_amount');
            if (input) { input.focus(); input.select(); }
        }, 100);
    }
}

function closeBudgetModal() {
    var m = document.getElementById('budgetModal');
    if (m) m.style.display = 'none';
}

window.addEventListener('click', function(e) {
    var m = document.getElementById('budgetModal');
    if (e.target === m) closeBudgetModal();
});
</script>
</body>
</html>

