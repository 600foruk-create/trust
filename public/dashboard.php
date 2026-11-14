<?php
require __DIR__ . '/../app/bootstrap.php';

// Seamlessly redirect if an old/mistaken link like /dashboard.php/print_voucher.php or /dashboard.php/donate.php is accessed
$dashReqUri = $_SERVER['REQUEST_URI'] ?? '';
$dashPathInfo = $_SERVER['PATH_INFO'] ?? '';
$dashPhpSelf = $_SERVER['PHP_SELF'] ?? '';

if (
    stripos($dashReqUri, 'print_voucher.php') !== false ||
    stripos($dashPathInfo, 'print_voucher') !== false ||
    stripos($dashPhpSelf, 'print_voucher.php') !== false
) {
    $queryString = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
    if (empty($queryString) && strpos($dashReqUri, '?') !== false) {
        $queryString = '?' . substr($dashReqUri, strpos($dashReqUri, '?') + 1);
    }
    header('Location: ' . get_app_base_url() . '/print_voucher.php' . $queryString, true, 301);
    exit;
}

if (
    stripos($dashReqUri, 'donate.php') !== false ||
    stripos($dashPathInfo, 'donate') !== false ||
    stripos($dashPhpSelf, 'donate.php') !== false
) {
    $queryString = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
    if (empty($queryString) && strpos($dashReqUri, '?') !== false) {
        $queryString = '?' . substr($dashReqUri, strpos($dashReqUri, '?') + 1);
    }
    header('Location: ' . get_app_base_url() . '/donate.php' . $queryString, true, 301);
    exit;
}

require_login();

$activePage = 'dashboard';
$currentUser = current_user();
$canApprove = can_approve();
$userId = (int)$currentUser['id'];

// Check & run daily automated database backup in background if due
if (function_exists('auto_run_daily_backup') && $canApprove) {
    try {
        auto_run_daily_backup($pdo);
    } catch (Exception $e) {}
}

$madrasaName = get_setting('madrasa_name', 'Trust Accounts');
$madrasaAddress = get_setting('madrasa_address', '');
$madrasaPhone = get_setting('madrasa_phone', '');
$orgLogo = function_exists('get_org_logo_url') ? get_org_logo_url() : null;

// Today's Scheduled Monthly Pledges
$todayDay = (int)date('j');
$currentMonthStr = date('Y-m');
$todayDuePledges = [];
if (has_permission('donors.view')) {
    try {
        $assignedFundIds = get_user_assigned_fund_ids($userId);
        $tpSql = "SELECT d.id, d.donor_name, d.phone, d.pledge_amount, d.pledge_day, d.notes,
                         d.pledge_fund_id, d.fund_id,
                         f.name AS fund_name, pf.name AS pledge_fund_name,
                         sb.full_name AS sponsored_student_name,
                         COALESCE((
                             SELECT SUM(v.total_amount) 
                             FROM vouchers v 
                             WHERE v.donor_id = d.id 
                               AND v.voucher_type = 'income' 
                               AND v.status = 'posted' 
                               AND v.voucher_date LIKE '{$currentMonthStr}%'
                         ), 0) AS paid_this_month
                  FROM donors d
                  LEFT JOIN funds f ON f.id = d.fund_id
                  LEFT JOIN funds pf ON pf.id = d.pledge_fund_id
                  LEFT JOIN beneficiaries sb ON sb.id = d.sponsored_beneficiary_id
                  WHERE d.is_pledge_active = 1 
                    AND d.pledge_amount > 0 
                    AND d.pledge_day = {$todayDay}";
        if (!$canApprove && !empty($assignedFundIds)) {
            $inF = implode(',', array_map('intval', $assignedFundIds));
            $tpSql .= " AND (d.fund_id IN ($inF) OR d.pledge_fund_id IN ($inF))";
        }
        $tpSql .= " HAVING paid_this_month < pledge_amount ORDER BY d.donor_name ASC";
        $todayDuePledges = $pdo->query($tpSql)->fetchAll() ?: [];
    } catch (Throwable $e) {
        $todayDuePledges = [];
    }
}

if (!$canApprove) {
    // ==========================================
    // 1. DATA FOR REGULAR USER / CASHIER ONLY
    // ==========================================
    $userMetrics = get_user_cash_metrics($pdo, $userId);

    // User's Collections by Fund (Filtered to assigned funds if restricted)
    $assignedFundIds = get_user_assigned_fund_ids($userId);
    if (!empty($assignedFundIds)) {
        $inClause = implode(',', array_fill(0, count($assignedFundIds), '?'));
        $userFundsStmt = $pdo->prepare("SELECT f.id, f.name, f.fund_code, f.is_restricted,
            COALESCE(SUM(CASE WHEN v.voucher_type = 'income' THEN v.total_amount ELSE 0 END), 0) AS total_income,
            COALESCE(SUM(CASE WHEN v.voucher_type = 'expense' THEN v.total_amount ELSE 0 END), 0) AS total_expense
            FROM funds f
            LEFT JOIN vouchers v ON v.fund_id = f.id AND v.created_by = ?
            WHERE f.is_active = 1 AND f.id IN ($inClause)
            GROUP BY f.id
            ORDER BY f.id ASC");
        $userFundsStmt->execute(array_merge([$userId], $assignedFundIds));
    } else {
        $userFundsStmt = $pdo->prepare("SELECT f.id, f.name, f.fund_code, f.is_restricted,
            COALESCE(SUM(CASE WHEN v.voucher_type = 'income' THEN v.total_amount ELSE 0 END), 0) AS total_income,
            COALESCE(SUM(CASE WHEN v.voucher_type = 'expense' THEN v.total_amount ELSE 0 END), 0) AS total_expense
            FROM funds f
            LEFT JOIN vouchers v ON v.fund_id = f.id AND v.created_by = ?
            WHERE f.is_active = 1
            GROUP BY f.id
            ORDER BY f.id ASC");
        $userFundsStmt->execute([$userId]);
    }
    $userFundCards = $userFundsStmt->fetchAll();

    // User's Recent Vouchers only
    $recentStmt = $pdo->prepare("SELECT v.*, f.name AS fund_name, d.donor_name 
        FROM vouchers v 
        LEFT JOIN funds f ON f.id = v.fund_id 
        LEFT JOIN donors d ON d.id = v.donor_id 
        WHERE v.created_by = ? 
        ORDER BY v.voucher_date DESC, v.id DESC LIMIT 8");
    $recentStmt->execute([$userId]);
    $userRecent = $recentStmt->fetchAll();

} else {
    // ==========================================
    // 2. DATA FOR APPROVER / CHECKER / ADMIN
    // ==========================================
    // Main institutional financial totals (only for main cash & bank accounts where user_id IS NULL)
    $totals = ['income' => 0.0, 'expense' => 0.0, 'cash' => 0.0, 'bank' => 0.0];
    $sql = "SELECT a.account_type, a.is_cash_or_bank, a.account_name, a.opening_balance, a.user_id,
            SUM(jl.debit) AS debit_total, SUM(jl.credit) AS credit_total
            FROM journal_lines jl
            JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.status='posted'
            JOIN accounts a ON a.id=jl.account_id
            GROUP BY a.id, a.account_type, a.is_cash_or_bank, a.account_name, a.user_id";
    foreach ($pdo->query($sql) as $row) {
        $debit = (float)$row['debit_total'];
        $credit = (float)$row['credit_total'];
        if ($row['account_type'] === 'income') {
            $totals['income'] += ($credit - $debit);
        }
        if ($row['account_type'] === 'expense') {
            $totals['expense'] += ($debit - $credit);
        }
        // Institutional Main Cash & Bank only (exclude user sub-accounts)
        if ((int)$row['is_cash_or_bank'] === 1 && empty($row['user_id'])) {
            $balance = (float)$row['opening_balance'] + $debit - $credit;
            if (stripos($row['account_name'], 'bank') !== false) {
                $totals['bank'] += $balance;
            } else {
                $totals['cash'] += $balance;
            }
        }
    }
    $balance = $totals['cash'] + $totals['bank'];

    // All Funds institutional overview (Filtered to assigned funds if restricted)
    $assignedFundIds = get_user_assigned_fund_ids($userId);
    $userRegionId = function_exists('get_user_region_id') ? get_user_region_id($userId) : 0;
    $isRegDirector = function_exists('is_regional_director') ? is_regional_director() : false;
    $isSuper = in_array($currentUser['role'] ?? '', ['Super Admin', 'Administrator'], true);
    $myRegion = $userRegionId > 0 && function_exists('get_region_by_id') ? get_region_by_id($userRegionId) : null;

    if (!$isSuper && !empty($assignedFundIds)) {
        $inClause = implode(',', array_fill(0, count($assignedFundIds), '?'));
        $fundsQuery = "SELECT f.*,
                       COALESCE(SUM(CASE WHEN a.account_type = 'income' THEN (jl.credit - jl.debit) ELSE 0 END), 0) AS total_income,
                       COALESCE(SUM(CASE WHEN a.account_type = 'expense' THEN (jl.debit - jl.credit) ELSE 0 END), 0) AS total_expense
                       FROM funds f
                       LEFT JOIN journal_lines jl ON jl.fund_id = f.id
                       LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'posted'
                       LEFT JOIN accounts a ON a.id = jl.account_id
                       WHERE f.is_active = 1 AND f.id IN ($inClause)
                       GROUP BY f.id
                       ORDER BY f.id ASC";
        $fundsStmt = $pdo->prepare($fundsQuery);
        $fundsStmt->execute($assignedFundIds);
        $fundCards = $fundsStmt->fetchAll();
    } else {
        $currOrgMode = function_exists('get_org_mode') ? get_org_mode() : 'trust';
        $fundsQuery = "SELECT f.*,
                       COALESCE(SUM(CASE WHEN a.account_type = 'income' THEN (jl.credit - jl.debit) ELSE 0 END), 0) AS total_income,
                       COALESCE(SUM(CASE WHEN a.account_type = 'expense' THEN (jl.debit - jl.credit) ELSE 0 END), 0) AS total_expense
                       FROM funds f
                       LEFT JOIN journal_lines jl ON jl.fund_id = f.id
                       LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'posted'
                       LEFT JOIN accounts a ON a.id = jl.account_id
                       WHERE f.is_active = 1 AND f.scope IN ('all', '{$currOrgMode}')
                       GROUP BY f.id
                       ORDER BY f.id ASC";
        $fundCards = $pdo->query($fundsQuery)->fetchAll();
    }

    // Pending Vouchers Statistics (Scoped by Region for Regional Director)
    if ($isRegDirector && $userRegionId > 0) {
        $pendingStats = $pdo->query("SELECT COUNT(*) AS total_pending, COALESCE(SUM(total_amount), 0) AS total_amount FROM vouchers WHERE status = 'draft' AND region_id = {$userRegionId}")->fetch();
    } else {
        $pendingStats = $pdo->query("SELECT COUNT(*) AS total_pending, COALESCE(SUM(total_amount), 0) AS total_amount FROM vouchers WHERE status = 'draft'")->fetch();
    }
    $pendingCount = (int)($pendingStats['total_pending'] ?? 0);
    $pendingAmount = (float)($pendingStats['total_amount'] ?? 0);

    // Cashiers / Users In-Hand Cash Monitor (Scoped to province for Regional Director)
    $cashierWhere = "WHERE u.is_active = 1";
    if ($isRegDirector && $userRegionId > 0) {
        $cashierWhere .= " AND u.region_id = {$userRegionId}";
    }
    $cashiersSql = "SELECT u.id, u.full_name, u.username, r.name AS role_name,
                    COALESCE(SUM(CASE WHEN v.voucher_type = 'income' AND v.status = 'draft' THEN v.total_amount ELSE 0 END), 0) AS pending_income,
                    COALESCE(SUM(CASE WHEN v.voucher_type = 'expense' AND v.status = 'draft' THEN v.total_amount ELSE 0 END), 0) AS pending_expense,
                    COUNT(CASE WHEN v.status = 'draft' THEN 1 ELSE NULL END) AS pending_count
                    FROM users u
                    JOIN roles r ON r.id = u.role_id
                    LEFT JOIN vouchers v ON v.created_by = u.id
                    {$cashierWhere}
                    GROUP BY u.id
                    HAVING pending_income > 0 OR pending_expense > 0 OR pending_count > 0
                    ORDER BY pending_count DESC, pending_income DESC";
    $cashiersInHand = $pdo->query($cashiersSql)->fetchAll();

    // Recent Vouchers
    if ($isRegDirector && $userRegionId > 0) {
        $recent = $pdo->query("SELECT v.*, u.full_name AS submitter_name, f.name AS fund_name 
                               FROM vouchers v 
                               LEFT JOIN users u ON u.id = v.created_by 
                               LEFT JOIN funds f ON f.id = v.fund_id 
                               WHERE v.region_id = {$userRegionId}
                               ORDER BY v.voucher_date DESC, v.id DESC LIMIT 6")->fetchAll();
    } else {
        $recent = $pdo->query("SELECT v.*, u.full_name AS submitter_name, f.name AS fund_name 
                               FROM vouchers v 
                               LEFT JOIN users u ON u.id = v.created_by 
                               LEFT JOIN funds f ON f.id = v.fund_id 
                               ORDER BY v.voucher_date DESC, v.id DESC LIMIT 6")->fetchAll();
    }

    // Super Admin: Provincial Directorates Overview
    $provincialCards = [];
    if ($isSuper) {
        try {
            $provincialCards = $pdo->query("
                SELECT reg.id, reg.name, reg.code,
                       u.full_name AS director_name,
                       COUNT(DISTINCT team.id) AS team_count,
                       COUNT(DISTINCT CASE WHEN v.status = 'draft' THEN v.id ELSE NULL END) AS pending_vouchers,
                       COALESCE(SUM(CASE WHEN v.status = 'posted' AND v.voucher_type = 'income' THEN v.total_amount ELSE 0 END), 0) AS posted_income,
                       COALESCE(SUM(CASE WHEN v.status = 'posted' AND v.voucher_type = 'expense' THEN v.total_amount ELSE 0 END), 0) AS posted_expense
                FROM regions reg
                LEFT JOIN users u ON u.id = reg.director_id
                LEFT JOIN users team ON team.region_id = reg.id AND team.is_active = 1
                LEFT JOIN vouchers v ON v.region_id = reg.id
                WHERE reg.is_active = 1
                GROUP BY reg.id
                ORDER BY reg.name ASC
            ")->fetchAll() ?: [];
        } catch (Throwable $e) {}
    }

    // Feature #2: Visual Analytics & Past 12 Months Comparison
    $monthlyTrends = [];
    try {
        $trendStmt = $pdo->query("
            SELECT DATE_FORMAT(je.entry_date, '%Y-%m') AS ym,
                   SUM(CASE WHEN a.account_type = 'income' THEN (jl.credit - jl.debit) ELSE 0 END) AS inc,
                   SUM(CASE WHEN a.account_type = 'expense' THEN (jl.debit - jl.credit) ELSE 0 END) AS exp
            FROM journal_lines jl
            JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'posted'
            JOIN accounts a ON a.id = jl.account_id
            WHERE je.entry_date >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
            GROUP BY ym
            ORDER BY ym ASC
        ");
        $rawTrends = $trendStmt->fetchAll() ?: [];
        $trendMap = [];
        foreach ($rawTrends as $rt) {
            $trendMap[$rt['ym']] = [
                'income' => max(0, (float)$rt['inc']),
                'expense' => max(0, (float)$rt['exp'])
            ];
        }
        for ($i = 11; $i >= 0; $i--) {
            $ym = date('Y-m', strtotime("-{$i} month"));
            $lbl = date('M y', strtotime("-{$i} month"));
            $monthlyTrends[] = [
                'ym' => $ym,
                'label' => $lbl,
                'income' => $trendMap[$ym]['income'] ?? 0.0,
                'expense' => $trendMap[$ym]['expense'] ?? 0.0,
            ];
        }
    } catch (Throwable $e) {}

    // Top Expense Categories
    $topExpenses = [];
    try {
        $topExpenses = $pdo->query("
            SELECT a.account_name, COALESCE(SUM(jl.debit - jl.credit), 0) AS total_spent
            FROM journal_lines jl
            JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'posted'
            JOIN accounts a ON a.id = jl.account_id
            WHERE a.account_type = 'expense'
            GROUP BY a.id
            HAVING total_spent > 0
            ORDER BY total_spent DESC LIMIT 5
        ")->fetchAll() ?: [];
    } catch (Throwable $e) {}
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?=e($madrasaName)?> | Dashboard</title>
    <link rel="stylesheet" href="assets/style.css?v=<?=css_ver()?>">
</head>
<body>
<div class="app-shell">
    <?php require __DIR__ . '/../app/sidebar.php'; ?>

    <main class="main">
        <header class="topbar madrasa-header">
            <div class="header-madrasa-info">
                <div class="header-title-wrap" style="display:flex;align-items:center;gap:12px;">
                    <?php if ($orgLogo): ?>
                        <img src="<?=e($orgLogo)?>?v=<?=filemtime(__DIR__ . '/' . $orgLogo)?>" alt="Logo" class="dashboard-logo-img">
                    <?php endif; ?>
                    <div>
                        <h1 class="header-madrasa-name" style="margin:0;"><?=e($madrasaName)?></h1>
                        <span class="badge <?=is_madrasa_mode() ? 'badge-green' : 'badge-blue'?>">
                            <?=function_exists('get_org_subtitle') ? get_org_subtitle() : (is_madrasa_mode() ? 'Islamic Seminary <span class="ur">(دینی درسگاہ و تعلیمی ادارہ)</span>' : 'Welfare Organization <span class="ur">(فلاحی و رفاہی تنظیم)</span>')?>
                        </span>
                    </div>
                </div>
                <div class="header-madrasa-contacts">
                    <?php if (!empty($madrasaAddress)): ?>
                        <span class="contact-chip" title="Address / پتہ">
                            <span class="chip-icon">📍</span>
                            <span class="chip-text"><?=e($madrasaAddress)?></span>
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($madrasaPhone)): ?>
                        <span class="contact-chip" title="Phone / فون">
                            <span class="chip-icon">📞</span>
                            <span class="chip-text" dir="ltr"><?=e($madrasaPhone)?></span>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="header-user-side">
                <span class="eyebrow" style="font-size:12px;color:#64748b;letter-spacing:1px;"><?=date('l, d M Y')?></span>
            </div>
        </header>

        <section class="welcome">
            <div>
                <h2>Assalamu Alaikum, <?=e(explode(' ', $currentUser['name'])[0])?></h2>
                <p>Welcome to <strong><?=e($madrasaName)?></strong> Financial Management Workspace.</p>
            </div>
            <div class="btn-row">
                <a href="income.php?action=new" class="btn blue sm">＋ Add Income <span class="ur">(آمدنی)</span></a>
                <a href="expenses.php?action=new" class="btn secondary sm">－ Add Expense <span class="ur">(خرچ)</span></a>
                <span class="date-pill"><?=date('d M Y')?></span>
            </div>
        </section>

        <?php if (!empty($todayDuePledges)): 
            $todayTotalDue = 0;
            foreach ($todayDuePledges as $tp) {
                $todayTotalDue += max(0, (float)$tp['pledge_amount'] - (float)$tp['paid_this_month']);
            }
        ?>
            <!-- Today's Scheduled Pledges Alert Banner -->
            <div style="background:#fffbeb;border:1.5px solid #fde68a;border-radius:12px;padding:16px 20px;margin-bottom:22px;box-shadow:0 4px 6px -1px rgba(245,158,11,0.1);">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;border-bottom:1px solid #fef3c7;padding-bottom:12px;margin-bottom:12px;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <span style="font-size:24px;">🔔</span>
                        <div>
                            <strong style="color:#92400e;font-size:15px;">آج کے متوقع عطیات و اقساط (Today's Scheduled Pledges - <?=date('d F Y')?>)</strong>
                            <div style="font-size:12px;color:#b45309;margin-top:2px;">
                                آج <b><?=count($todayDuePledges)?></b> معاونین کے مقررہ عطیات کی تاریخ ہے &bull; کل متوقع رقم: <b><?=money($todayTotalDue)?></b>
                            </div>
                        </div>
                    </div>
                    <a href="donors.php?tab=pledges&month=<?=date('Y-m')?>&pledge_status=pending" class="btn sm" style="background:#d97706;color:#ffffff;border:none;font-weight:700;padding:6px 14px;border-radius:6px;text-decoration:none;">
                        📋 ٹریکر پر جائیں (Open Tracker)
                    </a>
                </div>
                <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(260px, 1fr));gap:10px;">
                    <?php foreach ($todayDuePledges as $tp): 
                        $tpDue = max(0, (float)$tp['pledge_amount'] - (float)$tp['paid_this_month']);
                        $tpFund = $tp['pledge_fund_name'] ?: ($tp['fund_name'] ?: 'General');
                        $tpPhone = normalize_pakistan_phone($tp['phone'] ?? '');
                        $tpWaUrl = "";
                        if (!empty($tpPhone)) {
                            $tpMsg = "السلام علیکم ورحمۃ اللہ وبرکاتہ\nمحترم " . $tp['donor_name'] . " صاحب!\n" . $madrasaName . " کی جانب سے آپ کے مقررہ ماہانہ تعاون برائے (" . $tpFund . ") رقم: " . money($tpDue) . " کی آج تاریخ ادائیگی کے حوالے سے شائستہ یاد دہانی ارسال خدمت ہے۔ جزاکم اللہ خیراً۔";
                            $tpWaUrl = "https://api.whatsapp.com/send?phone=" . urlencode($tpPhone) . "&text=" . urlencode($tpMsg);
                        }
                    ?>
                        <div style="background:#ffffff;border:1px solid #fed7aa;border-radius:8px;padding:10px 14px;display:flex;justify-content:space-between;align-items:center;">
                            <div>
                                <b style="font-size:13px;color:#0f172a;display:block;"><?=e($tp['donor_name'])?></b>
                                <div style="font-size:11.5px;color:#64748b;margin-top:2px;">
                                    فنڈ: <span style="color:#0284c7;font-weight:600;"><?=e($tpFund)?></span> &bull; متوقع: <b style="color:#b91c1c;"><?=money($tpDue)?></b>
                                </div>
                                <?php if (!empty($tp['sponsored_student_name'])): ?>
                                    <div style="font-size:10.5px;color:#047857;margin-top:2px;">🎓 طالب علم: <?=e($tp['sponsored_student_name'])?></div>
                                <?php endif; ?>
                            </div>
                            <div style="display:flex;gap:4px;">
                                <?php if (!empty($tpWaUrl)): ?>
                                    <a href="<?=$tpWaUrl?>" target="_blank" class="btn sm" style="background:#25D366;color:#ffffff;border:none;padding:4px 8px;font-size:11px;border-radius:4px;text-decoration:none;" title="Send WhatsApp">
                                        📲
                                    </a>
                                <?php endif; ?>
                                <a href="donors.php?tab=pledges&month=<?=date('Y-m')?>" class="btn sm" style="background:#0284c7;color:#ffffff;border:none;padding:4px 8px;font-size:11px;border-radius:4px;text-decoration:none;" title="Receive">
                                    💰
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$canApprove): ?>
            <!-- ============================================================== -->
            <!-- USER / CASHIER PERSONAL DASHBOARD (ONLY PERSONAL FINANCIALS)   -->
            <!-- ============================================================== -->
            
            <!-- User Dedicated Cash Account Header Pill -->
            <?php $userAcc = $userMetrics['cash_account']; ?>
            <div style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:12px;padding:14px 20px;margin-bottom:22px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <span style="font-size:24px;">💼</span>
                    <div>
                        <div style="font-size:12px;color:#1e40af;font-weight:600;">My Assigned Cash Account <span class="ur">(میرا تفویض کردہ نقدی کھاتہ)</span></div>
                        <b style="font-size:15px;color:#1e3a8a;"><?=e(format_ui_text($userAcc['account_name'] ?? 'Cash in Hand'))?></b>
                        <span style="font-family:monospace;font-size:12px;background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:4px;margin-left:6px;"><?=e($userAcc['account_code'] ?? '1000-'.$userId)?></span>
                    </div>
                </div>
                <div style="text-align:right;">
                    <span style="font-size:11.5px;color:#64748b;display:block;">Status:</span>
                    <span class="badge badge-green" style="font-size:11.5px;">Active & Dedicated <span class="ur">(فعال)</span></span>
                </div>
            </div>

            <!-- User 4 Key Metrics -->
            <section class="stats-grid">
                <!-- 1. My Collections -->
                <article class="stat-card">
                    <div class="stat-top">
                        <span>My Collections <span class="ur">(میری کل وصولیاں)</span></span>
                        <span class="stat-icon green">↗</span>
                    </div>
                    <strong style="color:#15803d;"><?=money($userMetrics['total_income'])?></strong>
                    <small><span class="ur">منظور شدہ:</span> Posted: <?=money($userMetrics['posted_income'])?> | <span class="ur">التواء:</span> Pending: <?=money($userMetrics['pending_income'])?></small>
                </article>

                <!-- 2. My Expenses -->
                <article class="stat-card">
                    <div class="stat-top">
                        <span>My Expenses <span class="ur">(میرے کل اخراجات)</span></span>
                        <span class="stat-icon red">↘</span>
                    </div>
                    <strong style="color:#b91c1c;"><?=money($userMetrics['total_expense'])?></strong>
                    <small><span class="ur">منظور شدہ:</span> Posted: <?=money($userMetrics['posted_expense'])?> | <span class="ur">التواء:</span> Pending: <?=money($userMetrics['pending_expense'])?></small>
                </article>

                <!-- 3. Current Cash in Hand -->
                <?php 
                    $netHand = $userMetrics['current_cash_in_hand'];
                    $cardBorder = '#bfdbfe';
                    $textColor = '#1d4ed8';
                    if ($netHand > 0) {
                        $cardBorder = '#86efac';
                        $textColor = '#15803d';
                    } elseif ($netHand < 0) {
                        $cardBorder = '#fca5a5';
                        $textColor = '#b91c1c';
                    }
                ?>
                <article class="stat-card" style="border:2px solid <?=$cardBorder?>;background:#faf5ff;">
                    <div class="stat-top">
                        <span>Cash in Hand <span class="ur">(میرے پاس موجود نقدی)</span></span>
                        <span class="stat-icon purple">💼</span>
                    </div>
                    <strong style="color:<?=$textColor?>;"><?=money($netHand)?></strong>
                    <small>
                        <?php if ($netHand > 0): ?>
                            Cash held to submit to checker <span class="ur">(چیکر کے پاس جمع کروانے کے لیے واجب الادا رقم)</span>
                        <?php elseif ($netHand < 0): ?>
                            Pending reimbursement from checker <span class="ur">(چیکر سے وصول طلب اخراجات)</span>
                        <?php else: ?>
                            Settled <span class="ur">(حساب بے باق - تمام کیش تسویہ شدہ ہے)</span>
                        <?php endif; ?>
                    </small>
                </article>

                <!-- 4. Pending Vouchers Count -->
                <article class="stat-card">
                    <div class="stat-top">
                        <span>Pending Approval <span class="ur">(زیرِ التواء واؤچرز)</span></span>
                        <span class="stat-icon amber">⏳</span>
                    </div>
                    <strong style="color:#b45309;"><?=$userMetrics['pending_count']?></strong>
                    <small>Vouchers waiting for review <span class="ur">(چیکر کی پڑتال کے منتظر واؤچرز)</span></small>
                </article>
            </section>

            <!-- User Collections by Fund -->
            <section style="margin: 26px 0 20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                    <div>
                        <h3 style="font-size:16px; margin:0 0 2px; font-weight:700; color:#0f172a;">
                            My Collections by Fund <span class="ur">(میری فنڈ وار وصولیاں)</span>
                        </h3>
                        <p style="margin:0; font-size:12px; color:var(--muted);">
                            <span class="ur">آپ کے ذریعے مختلف فنڈز میں جمع کی گئی رقوم کی تفصیل</span>
                            <span class="en-only" style="display:none;">Collections recorded by fund under your account</span>
                        </p>
                    </div>
                </div>

                <div class="funds-grid">
                    <?php foreach ($userFundCards as $fc): 
                        $inflow = (float)$fc['total_income'];
                        $outflow = (float)$fc['total_expense'];
                        $net = $inflow - $outflow;
                        $class = '';
                        if (stripos($fc['name'], 'zakat') !== false) {
                            $class = 'zakat';
                        } elseif ((int)$fc['is_restricted'] === 1) {
                            $class = 'restricted';
                        }
                    ?>
                        <article class="fund-card <?=$class?>">
                            <div class="fund-card-top">
                                <div>
                                    <h4 class="fund-card-title"><?=e($fc['name'])?></h4>
                                    <small style="color:var(--muted);font-size:11px;"><?=e($fc['fund_code'])?></small>
                                </div>
                                <span class="fund-card-code"><?=money($inflow)?></span>
                            </div>
                            
                            <div class="fund-card-balance" style="font-size:14px;color:#1e3a8a;margin-top:8px;">
                                Inflow <span class="ur">(وصول کردہ)</span>: <b style="color:#15803d;"><?=money($inflow)?></b>
                            </div>

                            <div class="fund-card-details">
                                <span>Outflow <span class="ur">(خرچ)</span>: <b style="color:#b91c1c;"><?=money($outflow)?></b></span>
                                <span>Net <span class="ur">(نیٹ)</span>: <b style="color:#0f172a;"><?=money($net)?></b></span>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- User Recent Transactions -->
            <section class="content-grid">
                <article class="panel">
                    <div class="panel-heading">
                        <div>
                            <h3>My Recent Vouchers <span class="ur">(میرے حالیہ واؤچرز)</span></h3>
                            <p><span class="ur">آپ کے درج کردہ تازہ ترین واؤچرز کی فہرست</span><span class="en-only" style="display:none;">List of recent vouchers recorded by your account</span> <span class="mobile-table-hint" style="display:none;font-size:11px;background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:10px;font-weight:600;margin-right:6px;">⟷ Scroll horizontally</span></p>
                        </div>
                        <div>
                            <a href="reports.php" style="font-size:12px;color:#2563eb;text-decoration:none;">View Full Report <span class="ur">(تمام رپورٹ دیکھیں)</span> &rarr;</a>
                        </div>
                    </div>

                    <?php if (!$userRecent): ?>
                        <div class="empty-state">
                            <div class="empty-icon">▤</div>
                            <b><span class="ur">ابھی تک کوئی واؤچر درج نہیں ہوا</span><span class="en-only" style="display:none;">No vouchers recorded yet</span></b>
                            <p><span class="ur">آمدنی یا خرچ درج کرنے کے لیے اوپر دیے گئے بٹن پر کلک کریں۔</span><span class="en-only" style="display:none;">Click buttons above to record income or expenses.</span></p>
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Voucher # <span class="ur">(واؤچر نمبر)</span></th>
                                        <th>Type <span class="ur">(قسم)</span></th>
                                        <th>Fund / Donor <span class="ur">(فنڈ / معاون)</span></th>
                                        <th>Status <span class="ur">(حالت)</span></th>
                                        <th>Date <span class="ur">(تاریخ)</span></th>
                                        <th class="right">Amount <span class="ur">(رقم)</span></th>
                                        <th class="right">Print <span class="ur">(پرنٹ)</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($userRecent as $r): ?>
                                        <tr>
                                            <td><b><?=e($r['voucher_no'])?></b></td>
                                            <td>
                                                <?php if ($r['voucher_type'] === 'income'): ?>
                                                    <span class="badge badge-green">Income <span class="ur">(آمدنی)</span></span>
                                                <?php elseif ($r['voucher_type'] === 'expense'): ?>
                                                    <span class="badge badge-red">Expense <span class="ur">(خرچ)</span></span>
                                                <?php else: ?>
                                                    <span class="badge badge-blue"><?=e(ucfirst($r['voucher_type']))?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <b><?=e($r['fund_name'] ?? 'General')?></b>
                                                <?php if (!empty($r['donor_name'])): ?>
                                                    <div style="font-size:11px;color:#64748b;"><?=e($r['donor_name'])?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (($r['status'] ?? 'draft') === 'posted'): ?>
                                                    <span class="badge badge-green">Posted <span class="ur">(منظور شدہ)</span></span>
                                                <?php else: ?>
                                                    <span class="badge badge-amber" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;">Pending <span class="ur">(زیرِ التواء)</span></span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="white-space:nowrap;"><?=e($r['voucher_date'])?></td>
                                            <td class="right font-bold" style="white-space:nowrap;"><?=money($r['total_amount'])?></td>
                                            <td class="right" style="white-space:nowrap;">
                                                <a href="print_voucher.php?id=<?=$r['id']?>&format=thermal&autoprint=1" target="_blank" class="btn secondary sm" style="padding:3px 7px;font-size:11px;font-weight:600;" title="Print 80mm POS Thermal Slip">
                                                    🧾 80mm
                                                </a>
                                                <a href="print_voucher.php?id=<?=$r['id']?>&format=a4" target="_blank" class="btn secondary sm" style="padding:3px 7px;font-size:11px;" title="Print A4 Full Voucher">
                                                    📄 A4
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </article>

                <article class="panel quick-panel">
                    <div class="panel-heading">
                        <div>
                            <h3>Workflow & Guidance <span class="ur">(طریقہ کار و رہنمائی)</span></h3>
                            <p><span class="ur">صارفین و کیشیرز کے لیے ضروری معلومات</span><span class="en-only" style="display:none;">Guidelines for cashiers and staff</span></p>
                        </div>
                    </div>

                    <div style="font-size:13px;line-height:1.7;color:#334155;padding:12px 6px;">
                        <div style="margin-bottom:14px;display:flex;gap:10px;">
                            <span style="font-size:18px;">1️⃣</span>
                            <div>
                                <b>Income Collection <span class="ur">(آمدنی کی وصولی)</span>:</b> <span class="ur">رقم وصول کرنے پر فنڈ اور معاون منتخب کریں۔ رقم آپ کے ذاتی نقدی کیش (Cash in Hand) میں محفوظ ہوگی۔</span><span class="en-only" style="display:none;">Select fund and donor when receiving cash. Funds are held in your personal Cash in Hand custody.</span>
                            </div>
                        </div>
                        <div style="margin-bottom:14px;display:flex;gap:10px;">
                            <span style="font-size:18px;">2️⃣</span>
                            <div>
                                <b>Expense Payment <span class="ur">(اخراجات کا اندراج)</span>:</b> <span class="ur">خرچ درج کرتے وقت بل یا رسید کی تصویر لازمی منسلک کریں تاکہ چیکر آسانی سے پڑتال کر سکے۔</span><span class="en-only" style="display:none;">Attach receipt/bill photo when recording expenses so the checker can audit.</span>
                            </div>
                        </div>
                        <div style="margin-bottom:14px;display:flex;gap:10px;">
                            <span style="font-size:18px;">3️⃣</span>
                            <div>
                                <b>Approval & Settlement <span class="ur">(منظوری و تسویہ)</span>:</b> <span class="ur">جیسے ہی چیکر رقم وصول کر کے واؤچر منظور کرے گا، رقم آپ کے کیش سے ادارے / ٹرسٹ کے مرکزی کیش میں چلی جائے گی۔</span><span class="en-only" style="display:none;">When the checker receives physical cash and approves, funds transfer into the main institutional accounts.</span>
                            </div>
                        </div>
                    </div>
                </article>
            </section>

        <?php else: ?>
            <!-- ============================================================== -->
            <!-- APPROVER / CHECKER / ADMIN MAIN INSTITUTIONAL DASHBOARD        -->
            <!-- ============================================================== -->

            <!-- Pending Approvals Alert Banner -->
            <?php if ($pendingCount > 0): ?>
                <div style="background:#fffbeb;border:1.5px solid #fde68a;border-radius:14px;padding:16px 20px;margin-bottom:22px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;box-shadow:0 2px 6px rgba(245,158,11,0.06);">
                    <div style="display:flex;align-items:center;gap:14px;">
                        <span style="font-size:26px;">⏳</span>
                        <div>
                            <b style="color:#92400e;font-size:15px;"><?= $pendingCount ?> Voucher(s) Waiting For Verification & Approval <span class="ur">(منظوری کے لیے زیرِ التواء)</span></b>
                            <p style="margin:2px 0 0;font-size:13px;color:#78350f;">Total value: <strong><?= money($pendingAmount) ?></strong>. Check cashier cash/bills and approve to settle into Main Cash & Bank.</p>
                        </div>
                    </div>
                    <a href="approvals.php" class="btn blue sm" style="text-decoration:none;padding:8px 16px;">
                        ✓ Review & Approve <span class="ur">(پڑتال و منظوری)</span> &rarr;
                    </a>
                </div>
            <?php endif; ?>

            <!-- Regional Director Welcome Banner -->
            <?php if ($isRegDirector && $myRegion): ?>
                <div style="background:#f0fdf4;border:1.5px solid #bbf7d0;border-left:5px solid #16a34a;border-radius:12px;padding:16px 20px;margin-bottom:22px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;box-shadow:0 2px 8px rgba(22,163,74,0.06);">
                    <div style="display:flex;align-items:center;gap:14px;">
                        <span style="font-size:28px;">🏛️</span>
                        <div>
                            <b style="color:#166534;font-size:16px;"><?=e($myRegion['name'])?> Directorate <span class="ur">(صوبائی ڈائریکٹوریٹ)</span></b>
                            <p style="margin:2px 0 0;font-size:13px;color:#15803d;">Provincial Directorate Operations · Autonomous Team & Voucher Management for <?=e($myRegion['name'])?>.</p>
                        </div>
                    </div>
                    <div style="display:flex;gap:8px;">
                        <a href="users.php" class="btn secondary sm" style="text-decoration:none;padding:7px 14px;">👥 My Team</a>
                        <a href="approvals.php" class="btn blue sm" style="text-decoration:none;padding:7px 14px;">✓ Approvals Queue</a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Super Admin: Provincial Directorates Cards -->
            <?php if ($isSuper && !empty($provincialCards)): ?>
                <section style="margin: 0 0 24px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span style="font-size:20px;">🏛️</span>
                            <h3 style="margin:0;font-size:16px;color:#0f172a;">Provincial Directorates & Regional Branches <span class="ur">(صوبائی ڈائریکٹوریٹس)</span></h3>
                        </div>
                        <a href="users.php?tab=regions" class="btn secondary sm" style="font-size:12px;text-decoration:none;">＋ Manage Provinces & Directors</a>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(290px, 1fr));gap:14px;">
                        <?php foreach ($provincialCards as $pc): ?>
                            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,0.04);position:relative;border-top:3px solid #2563eb;">
                                <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;">
                                    <div>
                                        <b style="font-size:15px;color:#0f172a;"><?=e($pc['name'])?></b>
                                        <div style="font-size:12px;color:#64748b;">Director: <strong><?=e($pc['director_name'] ?: 'Not Assigned')?></strong></div>
                                    </div>
                                    <span class="badge" style="background:#eff6ff;color:#1d4ed8;font-weight:700;"><?=e($pc['code'])?></span>
                                </div>
                                <div style="display:flex;gap:12px;background:#f8fafc;padding:8px 12px;border-radius:6px;font-size:12px;color:#475569;margin-bottom:10px;">
                                    <div>👥 Staff: <strong><?=(int)$pc['team_count']?></strong></div>
                                    <div>⏳ Pending: <strong style="color:<?=(int)$pc['pending_vouchers']>0?'#d97706':'#16a34a'?>;"><?=(int)$pc['pending_vouchers']?></strong></div>
                                    <div>↗ In: <strong><?=money($pc['posted_income'])?></strong></div>
                                </div>
                                <div style="display:flex;gap:6px;justify-content:flex-end;">
                                    <a href="users.php?region_id=<?=$pc['id']?>" class="btn secondary sm" style="font-size:11px;padding:3px 8px;text-decoration:none;">Team</a>
                                    <a href="approvals.php?region_id=<?=$pc['id']?>" class="btn blue sm" style="font-size:11px;padding:3px 8px;text-decoration:none;">Queue (<?=(int)$pc['pending_vouchers']?>)</a>
                                    <a href="reports.php?region_id=<?=$pc['id']?>" class="btn sm" style="background:#f1f5f9;color:#0f172a;font-size:11px;padding:3px 8px;text-decoration:none;">Report</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <!-- Top Totals Stats Grid (Central Treasury) -->
            <section class="stats-grid">
                <article class="stat-card">
                    <div class="stat-top">
                        <span>Main Liquid Income <span class="ur">(آمدنی)</span></span>
                        <span class="stat-icon green">↗</span>
                    </div>
                    <strong><?=money($totals['income'])?></strong>
                    <small>Approved & posted entries</small>
                </article>

                <article class="stat-card">
                    <div class="stat-top">
                        <span>Main Expenses <span class="ur">(اخراجات)</span></span>
                        <span class="stat-icon red">↘</span>
                    </div>
                    <strong><?=money($totals['expense'])?></strong>
                    <small>Approved & deducted entries</small>
                </article>

                <article class="stat-card">
                    <div class="stat-top">
                        <span>Main Central Cash & Bank Balance</span>
                        <span class="stat-icon blue">▣</span>
                    </div>
                    <strong><?=money($balance)?></strong>
                    <small>Cash: <?=money($totals['cash'])?> | Bank: <?=money($totals['bank'])?></small>
                </article>

                <article class="stat-card">
                    <div class="stat-top">
                        <span>Active Funds <span class="ur">(فعال فنڈز)</span></span>
                        <span class="stat-icon purple">◎</span>
                    </div>
                    <strong><?=count($fundCards)?></strong>
                    <small>Fund categories configured</small>
                </article>
            </section>

            <!-- Feature #2: Visual Analytics & 12-Month Financial Performance -->
            <section style="margin: 24px 0;">
                <div class="panel" style="border:1.5px solid #cbd5e1;background:#ffffff;border-radius:12px;overflow:hidden;">
                    <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;background:#f8fafc;padding:14px 20px;border-bottom:1px solid #e2e8f0;">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <span style="font-size:22px;">📊</span>
                            <div>
                                <h3 style="margin:0;font-size:15px;color:#0f172a;">Financial Trends &amp; Monthly Comparison <span class="ur">(12 ماہ کا آمدن و خرچ کا موازنہ)</span></h3>
                                <p style="margin:2px 0 0;font-size:12px;color:#64748b;">Past 12 months cashflow trajectory and top expenditure heads</p>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:16px;font-size:12px;font-weight:600;">
                            <span style="display:inline-flex;align-items:center;gap:5px;color:#15803d;">
                                <span style="width:12px;height:12px;border-radius:3px;background:#16a34a;display:inline-block;"></span> Income (آمدنی)
                            </span>
                            <span style="display:inline-flex;align-items:center;gap:5px;color:#b91c1c;">
                                <span style="width:12px;height:12px;border-radius:3px;background:#dc2626;display:inline-block;"></span> Expenses (اخراجات)
                            </span>
                        </div>
                    </div>

                    <div style="padding:20px;display:grid;grid-template-columns: 2fr 1.2fr;gap:24px;">
                        <!-- Left: Visual Bar Chart -->
                        <div>
                            <div style="height:240px;position:relative;">
                                <canvas id="monthlyTrendChart"></canvas>
                            </div>
                        </div>

                        <!-- Right: Top 5 Expense Categories Breakdown -->
                        <div style="border-left:1px solid #e2e8f0;padding-left:20px;">
                            <h4 style="margin:0 0 14px;font-size:13.5px;color:#334155;">🔥 Top Expense Categories <span class="ur">(سب سے زیادہ اخراجات کی مدات)</span></h4>
                            <?php if (empty($topExpenses)): ?>
                                <p style="font-size:12.5px;color:#94a3b8;margin:20px 0;">No expense records found yet.</p>
                            <?php else: ?>
                                <?php 
                                    $maxExp = max(array_column($topExpenses, 'total_spent')) ?: 1;
                                ?>
                                <div style="display:flex;flex-direction:column;gap:12px;">
                                    <?php foreach ($topExpenses as $te): 
                                        $pct = min(100, round(($te['total_spent'] / $maxExp) * 100));
                                    ?>
                                        <div>
                                            <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px;">
                                                <span style="font-weight:600;color:#1e293b;"><?=e(format_ui_text($te['account_name']))?></span>
                                                <span style="font-weight:700;color:#b91c1c;"><?=money($te['total_spent'])?></span>
                                            </div>
                                            <div style="height:6px;width:100%;background:#fee2e2;border-radius:4px;overflow:hidden;">
                                                <div style="width:<?=$pct?>%;height:100%;background:#ef4444;border-radius:4px;"></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Cashiers & Users Cash in Hand Monitor (Pending Handover) -->
            <?php if (!empty($cashiersInHand)): ?>
                <section style="margin: 24px 0;">
                    <div class="panel" style="border:1.5px solid #fed7aa;background:#fffaf5;">
                        <div class="panel-heading" style="border-bottom:1px solid #ffedd5;">
                            <div>
                                <h3 style="color:#9a3412;margin:0;">💼 Cash Held by Cashiers <span class="ur">(کیشیرز کی تحویل میں موجود رقوم)</span></h3>
                                <p style="margin:2px 0 0;font-size:12px;color:#7c2d12;">
                                    <span class="ur">یہ رقوم کیشیرز نے وصول کی ہیں لیکن ابھی چیکر کو جمع کروا کر منظور نہیں ہوئیں۔</span>
                                    <span class="en-only" style="display:none;">These funds were collected by cashiers and are awaiting checker audit & approval.</span>
                                </p>
                            </div>
                            <div>
                                <a href="approvals.php" class="btn secondary sm" style="border-color:#fdba74;color:#9a3412;">
                                    <span class="ur">منظوری پینل</span>
                                    <span class="en-only" style="display:none;">Approvals Panel</span> &rarr;
                                </a>
                            </div>
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Cashier / User</th>
                                        <th>Role</th>
                                        <th class="right">Pending Income <span class="ur">(وصول شدہ)</span></th>
                                        <th class="right">Pending Expenses <span class="ur">(خرچ)</span></th>
                                        <th class="right">Net Cash in Custody <span class="ur">(پاس موجود رقم)</span></th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cashiersInHand as $ch): 
                                        $netCustody = (float)$ch['pending_income'] - (float)$ch['pending_expense'];
                                    ?>
                                        <tr>
                                            <td>
                                                <b><?=e($ch['full_name'])?></b>
                                                <div style="font-size:11px;color:#64748b;">@<?=e($ch['username'])?></div>
                                            </td>
                                            <td><span class="badge badge-purple"><?=e(function_exists('get_role_display_name') ? get_role_display_name($ch['role_name'], false) : $ch['role_name'])?></span></td>
                                            <td class="right font-bold" style="color:#15803d;"><?=money($ch['pending_income'])?></td>
                                            <td class="right font-bold" style="color:#b91c1c;"><?=money($ch['pending_expense'])?></td>
                                            <td class="right font-bold" style="color:#1e3a8a;font-size:14px;">
                                                <?=money($netCustody)?>
                                            </td>
                                            <td>
                                                <a href="approvals.php" class="btn blue sm" style="padding:3px 9px;font-size:11.5px;">
                                                    <span class="ur">پڑتال و منظوری</span>
                                                    <span class="en-only" style="display:none;">Audit & Approve</span> (<?=$ch['pending_count']?>)
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <!-- Individual Funds Cards Section -->
            <section style="margin: 26px 0 20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                    <div>
                        <h3 style="font-size:16px; margin:0 0 2px; font-weight:700; color:#0f172a;">
                            <?=is_madrasa_mode() ? 'Seminary Funds <span class="ur">(شعبہ جات و فنڈز کا بیلنس)</span>' : 'Funds Accounts <span class="ur">(مرکزی فنڈز کا بیلنس)</span>'?>
                        </h3>
                        <p style="margin:0; font-size:12px; color:var(--muted);">
                            Live balance for each distinct fund category
                        </p>
                    </div>
                    <a href="funds.php" style="font-size:12.5px; color:#2563eb; text-decoration:none; font-weight:600;">
                        <?=is_madrasa_mode() ? 'Manage Funds & Depts' : 'Manage Funds'?> &rarr;
                    </a>
                </div>

                <div class="funds-grid">
                    <?php foreach ($fundCards as $fc): 
                        $inflow = (float)$fc['total_income'];
                        $outflow = (float)$fc['total_expense'];
                        $net = $inflow - $outflow;
                        $class = '';
                        if (stripos($fc['name'], 'zakat') !== false) {
                            $class = 'zakat';
                        } elseif ((int)$fc['is_restricted'] === 1) {
                            $class = 'restricted';
                        }
                    ?>
                        <article class="fund-card <?=$class?>">
                            <div class="fund-card-top">
                                <div>
                                    <h4 class="fund-card-title"><?=e($fc['name'])?></h4>
                                    <small style="color:var(--muted);font-size:11px;">
                                        <?=e($fc['description'] ? mb_strimwidth($fc['description'], 0, 35, '...') : 'Trust Fund')?>
                                    </small>
                                </div>
                                <span class="fund-card-code"><?=e($fc['fund_code'])?></span>
                            </div>
                            
                            <div class="fund-card-balance" style="color: <?=$net >= 0 ? '#0f172a' : '#b91c1c'?>;">
                                <?=money($net)?>
                            </div>

                            <div class="fund-card-details">
                                <span>In: <b style="color:#15803d;"><?=money($inflow)?></b></span>
                                <span>Out: <b style="color:#b91c1c;"><?=money($outflow)?></b></span>
                                <?php if ((int)$fc['is_restricted'] === 1): ?>
                                    <span class="badge badge-amber" style="padding:1px 6px;font-size:10px;">Restricted</span>
                                <?php else: ?>
                                    <span class="badge badge-blue" style="padding:1px 6px;font-size:10px;">General</span>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- Content Grid: Recent Transactions & Status -->
            <section class="content-grid">
                <article class="panel">
                    <div class="panel-heading">
                        <div>
                            <h3>Recent Vouchers <span class="ur">(حالیہ واؤچرز)</span></h3>
                            <p>Latest recorded financial entries across the institute</p>
                        </div>
                        <div>
                            <a href="reports.php" style="font-size:12px;color:#2563eb;text-decoration:none;">View all &rarr;</a>
                        </div>
                    </div>

                    <?php if (!$recent): ?>
                        <div class="empty-state">
                            <div class="empty-icon">▤</div>
                            <b>No transactions yet</b>
                            <p>Click <strong>Add Income</strong> or <strong>Add Expense</strong> to record transactions.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Voucher</th>
                                        <th>Type</th>
                                        <th>Submitter</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th class="right">Amount</th>
                                        <th class="right">Print</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent as $r): ?>
                                        <tr>
                                            <td><b><?=e($r['voucher_no'])?></b></td>
                                            <td>
                                                <?php if ($r['voucher_type'] === 'income'): ?>
                                                    <span class="badge badge-green">Income</span>
                                                <?php elseif ($r['voucher_type'] === 'expense'): ?>
                                                    <span class="badge badge-red">Expense</span>
                                                <?php else: ?>
                                                    <span class="badge badge-blue"><?=e(ucfirst($r['voucher_type']))?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><small><?=e($r['submitter_name'] ?? '—')?></small></td>
                                            <td>
                                                <?php if (($r['status'] ?? 'posted') === 'posted'): ?>
                                                    <span class="badge badge-green">Posted</span>
                                                <?php else: ?>
                                                    <span class="badge badge-amber" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;">Pending</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="white-space:nowrap;"><?=e($r['voucher_date'])?></td>
                                            <td class="right font-bold" style="white-space:nowrap;"><?=money($r['total_amount'])?></td>
                                            <td class="right" style="white-space:nowrap;">
                                                <a href="print_voucher.php?id=<?=$r['id']?>&format=thermal&autoprint=1" target="_blank" class="btn secondary sm" style="padding:3px 7px;font-size:11px;font-weight:600;" title="Print 80mm POS Thermal Slip">
                                                    🧾 80mm
                                                </a>
                                                <a href="print_voucher.php?id=<?=$r['id']?>&format=a4" target="_blank" class="btn secondary sm" style="padding:3px 7px;font-size:11px;" title="Print A4 Full Voucher">
                                                    📄 A4
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </article>

                <article class="panel quick-panel">
                    <div class="panel-heading">
                        <div>
                            <h3>Accounting System Status</h3>
                            <p>System configurations</p>
                        </div>
                    </div>

                    <div class="setup-list">
                        <div><span class="check">✓</span><span>Database connected</span><b>Ready</b></div>
                        <div><span class="check">✓</span><span>Secure login & session</span><b>Ready</b></div>
                        <div><span class="check">✓</span><span>Multi-user cash accounts</span><b style="color:#15803d;">Active</b></div>
                        <div><span class="check">✓</span><span>Approval & Settlement flow</span><b style="color:#15803d;">Active</b></div>
                        <div><span class="check">✓</span><span>Bill Photo Uploads</span><b style="color:#15803d;">Enabled</b></div>
                        <div><span class="check">✓</span><span>Individual fund tracking</span><b>Ready</b></div>
                    </div>

                    <div class="notice">
                        <b>Dual-Layer Double Entry</b>
                        <p>User cash accounts hold pending cash safely. Upon checker approval, cash is automatically settled into Main Cash in Hand and reflected in institutional balances.</p>
                    </div>
                </article>
            </section>
        <?php endif; ?>

        <?=render_app_footer($madrasaName)?>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<?php if ($canApprove && !empty($monthlyTrends)): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var ctx = document.getElementById('monthlyTrendChart');
    if (!ctx || typeof Chart === 'undefined') return;

    var trendLabels = <?=json_encode(array_column($monthlyTrends, 'label'))?>;
    var incomeData = <?=json_encode(array_column($monthlyTrends, 'income'))?>;
    var expenseData = <?=json_encode(array_column($monthlyTrends, 'expense'))?>;

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: trendLabels,
            datasets: [
                {
                    label: 'Income (آمدنی)',
                    data: incomeData,
                    backgroundColor: 'rgba(22, 163, 74, 0.85)',
                    borderRadius: 4,
                    barPercentage: 0.65,
                },
                {
                    label: 'Expense (اخراجات)',
                    data: expenseData,
                    backgroundColor: 'rgba(220, 38, 38, 0.85)',
                    borderRadius: 4,
                    barPercentage: 0.65,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(c) {
                            return c.dataset.label + ': Rs. ' + Number(c.raw).toLocaleString();
                        }
                    }
                }
            },
            scales: {
                x: { grid: { display: false } },
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(val) {
                            if (val >= 1000000) return (val/1000000).toFixed(1) + 'M';
                            if (val >= 1000) return (val/1000).toFixed(0) + 'k';
                            return val;
                        }
                    }
                }
            }
        }
    });
});
</script>
<?php endif; ?>
</body>
</html>
