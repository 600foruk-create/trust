<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();
require_permission('reports.view');

$activePage = 'reports';

$fromDate = trim($_GET['from_date'] ?? date('Y-01-01'));
$toDate = trim($_GET['to_date'] ?? date('Y-m-d'));
$reportType = trim($_GET['tab'] ?? 'income_expense');

// User restriction: regular users can only view their own records
$currentUser = current_user();
$canApprove = can_approve();

if (!$canApprove) {
    $selectedUserId = (int)$currentUser['id'];
} else {
    $selectedUserId = (int)($_GET['user_id'] ?? 0);
}

$isSuperAdmin = in_array($currentUser['role'] ?? '', ['Super Admin', 'Administrator'], true);
$userFundIds = get_user_assigned_fund_ids((int)$currentUser['id']);
$hasFundRestriction = (!$isSuperAdmin && !empty($userFundIds));

$userRegionId = function_exists('get_user_region_id') ? get_user_region_id((int)$currentUser['id']) : 0;
$isRegDirector = function_exists('is_regional_director') ? is_regional_director() : false;
$allRegions = function_exists('get_all_active_regions') ? get_all_active_regions() : [];

if (!$isSuperAdmin && $userRegionId > 0) {
    // Locked to provincial directorate
    $selectedRegionId = $userRegionId;
} else {
    // Super Admin can choose
    $selectedRegionId = (int)($_GET['region_id'] ?? 0);
}
$currentRegionInfo = $selectedRegionId > 0 && function_exists('get_region_by_id') ? get_region_by_id($selectedRegionId) : null;

// Fetch users list for filter (scoped to region for regional directors)
$usersList = [];
if ($canApprove) {
    try {
        if ($isRegDirector && $userRegionId > 0) {
            $usersList = $pdo->query("SELECT id, full_name, username FROM users WHERE is_active = 1 AND region_id = {$userRegionId} ORDER BY full_name ASC")->fetchAll();
        } else {
            $usersList = $pdo->query("SELECT id, full_name, username FROM users WHERE is_active = 1 ORDER BY full_name ASC")->fetchAll();
        }
    } catch (Exception $e) {
        $usersList = [];
    }
}

// 1. Income heads total in date range
if ($selectedUserId > 0) {
    $incomeStmt = $pdo->prepare("SELECT a.account_name, a.account_code, 
                                 COALESCE(SUM(jl.credit - jl.debit), 0) AS total_amount
                                 FROM accounts a
                                 JOIN journal_lines jl ON jl.account_id = a.id
                                 JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'posted'
                                 JOIN vouchers v ON v.id = je.voucher_id
                                 WHERE a.account_type = 'income' AND je.entry_date BETWEEN ? AND ? AND v.created_by = ?
                                 GROUP BY a.id
                                 HAVING total_amount > 0
                                 ORDER BY total_amount DESC");
    $incomeStmt->execute([$fromDate, $toDate, $selectedUserId]);
} elseif ($selectedRegionId > 0) {
    $incomeStmt = $pdo->prepare("SELECT a.account_name, a.account_code, 
                                 COALESCE(SUM(jl.credit - jl.debit), 0) AS total_amount
                                 FROM accounts a
                                 JOIN journal_lines jl ON jl.account_id = a.id
                                 JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'posted'
                                 JOIN vouchers v ON v.id = je.voucher_id
                                 WHERE a.account_type = 'income' AND je.entry_date BETWEEN ? AND ? AND (v.region_id = ? OR je.region_id = ?)
                                 GROUP BY a.id
                                 HAVING total_amount > 0
                                 ORDER BY total_amount DESC");
    $incomeStmt->execute([$fromDate, $toDate, $selectedRegionId, $selectedRegionId]);
} else {
    $incomeStmt = $pdo->prepare("SELECT a.account_name, a.account_code, 
                                 COALESCE(SUM(jl.credit - jl.debit), 0) AS total_amount
                                 FROM accounts a
                                 JOIN journal_lines jl ON jl.account_id = a.id
                                 JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'posted'
                                 WHERE a.account_type = 'income' AND je.entry_date BETWEEN ? AND ?
                                 GROUP BY a.id
                                 HAVING total_amount > 0
                                 ORDER BY total_amount DESC");
    $incomeStmt->execute([$fromDate, $toDate]);
}
$incomeHeads = $incomeStmt->fetchAll();

// 2. Expense heads total in date range
if ($selectedUserId > 0) {
    $expenseStmt = $pdo->prepare("SELECT a.account_name, a.account_code, 
                                  COALESCE(SUM(jl.debit - jl.credit), 0) AS total_amount
                                  FROM accounts a
                                  JOIN journal_lines jl ON jl.account_id = a.id
                                  JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'posted'
                                  JOIN vouchers v ON v.id = je.voucher_id
                                  WHERE a.account_type = 'expense' AND je.entry_date BETWEEN ? AND ? AND v.created_by = ?
                                  GROUP BY a.id
                                  HAVING total_amount > 0
                                  ORDER BY total_amount DESC");
    $expenseStmt->execute([$fromDate, $toDate, $selectedUserId]);
} elseif ($selectedRegionId > 0) {
    $expenseStmt = $pdo->prepare("SELECT a.account_name, a.account_code, 
                                  COALESCE(SUM(jl.debit - jl.credit), 0) AS total_amount
                                  FROM accounts a
                                  JOIN journal_lines jl ON jl.account_id = a.id
                                  JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'posted'
                                  JOIN vouchers v ON v.id = je.voucher_id
                                  WHERE a.account_type = 'expense' AND je.entry_date BETWEEN ? AND ? AND (v.region_id = ? OR je.region_id = ?)
                                  GROUP BY a.id
                                  HAVING total_amount > 0
                                  ORDER BY total_amount DESC");
    $expenseStmt->execute([$fromDate, $toDate, $selectedRegionId, $selectedRegionId]);
} else {
    $expenseStmt = $pdo->prepare("SELECT a.account_name, a.account_code, 
                                  COALESCE(SUM(jl.debit - jl.credit), 0) AS total_amount
                                  FROM accounts a
                                  JOIN journal_lines jl ON jl.account_id = a.id
                                  JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'posted'
                                  WHERE a.account_type = 'expense' AND je.entry_date BETWEEN ? AND ?
                                  GROUP BY a.id
                                  HAVING total_amount > 0
                                  ORDER BY total_amount DESC");
    $expenseStmt->execute([$fromDate, $toDate]);
}
$expenseHeads = $expenseStmt->fetchAll();

$totalPeriodIncome = array_sum(array_column($incomeHeads, 'total_amount'));
$totalPeriodExpense = array_sum(array_column($expenseHeads, 'total_amount'));
$netPeriodSurplus = $totalPeriodIncome - $totalPeriodExpense;

// 3. Fund-wise report
$fundWhereClause = $hasFundRestriction ? " WHERE f.id IN (" . implode(',', array_map('intval', $userFundIds)) . ")" : "";
if ($selectedUserId > 0) {
    $fundStmt = $pdo->prepare("SELECT f.name, f.fund_code, f.is_restricted,
                               COALESCE(SUM(CASE WHEN v.voucher_type = 'income' THEN v.total_amount ELSE 0 END), 0) AS period_inflow,
                               COALESCE(SUM(CASE WHEN v.voucher_type = 'expense' THEN v.total_amount ELSE 0 END), 0) AS period_outflow
                               FROM funds f
                               LEFT JOIN vouchers v ON v.fund_id = f.id AND v.created_by = ? AND v.status = 'posted' AND v.voucher_date BETWEEN ? AND ?
                               {$fundWhereClause}
                               GROUP BY f.id
                               ORDER BY f.id ASC");
    $fundStmt->execute([$selectedUserId, $fromDate, $toDate]);
} elseif ($selectedRegionId > 0) {
    $fundStmt = $pdo->prepare("SELECT f.name, f.fund_code, f.is_restricted,
                               COALESCE(SUM(CASE WHEN a.account_type = 'income' THEN (jl.credit - jl.debit) ELSE 0 END), 0) AS period_inflow,
                               COALESCE(SUM(CASE WHEN a.account_type = 'expense' THEN (jl.debit - jl.credit) ELSE 0 END), 0) AS period_outflow
                               FROM funds f
                               LEFT JOIN journal_lines jl ON jl.fund_id = f.id
                               LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'posted' AND je.entry_date BETWEEN ? AND ? AND je.region_id = ?
                               LEFT JOIN accounts a ON a.id = jl.account_id
                               {$fundWhereClause}
                               GROUP BY f.id
                               ORDER BY f.id ASC");
    $fundStmt->execute([$fromDate, $toDate, $selectedRegionId]);
} else {
    $fundStmt = $pdo->prepare("SELECT f.name, f.fund_code, f.is_restricted,
                               COALESCE(SUM(CASE WHEN a.account_type = 'income' THEN (jl.credit - jl.debit) ELSE 0 END), 0) AS period_inflow,
                               COALESCE(SUM(CASE WHEN a.account_type = 'expense' THEN (jl.debit - jl.credit) ELSE 0 END), 0) AS period_outflow
                               FROM funds f
                               LEFT JOIN journal_lines jl ON jl.fund_id = f.id
                               LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'posted' AND je.entry_date BETWEEN ? AND ?
                               LEFT JOIN accounts a ON a.id = jl.account_id
                               {$fundWhereClause}
                               GROUP BY f.id
                               ORDER BY f.id ASC");
    $fundStmt->execute([$fromDate, $toDate]);
}
$fundReports = $fundStmt->fetchAll();

// 4. Voucher Register
$voucherFundClause = $hasFundRestriction ? " AND v.fund_id IN (" . implode(',', array_map('intval', $userFundIds)) . ")" : "";
if ($selectedUserId > 0) {
    $voucherStmt = $pdo->prepare("SELECT v.*, f.name AS fund_name, d.donor_name, reg.name AS region_name 
                                  FROM vouchers v
                                  LEFT JOIN funds f ON f.id = v.fund_id
                                  LEFT JOIN donors d ON d.id = v.donor_id
                                  LEFT JOIN regions reg ON reg.id = v.region_id
                                  WHERE v.status = 'posted' AND v.voucher_date BETWEEN ? AND ? AND v.created_by = ? {$voucherFundClause}
                                  ORDER BY v.voucher_date DESC, v.id DESC");
    $voucherStmt->execute([$fromDate, $toDate, $selectedUserId]);
} elseif ($selectedRegionId > 0) {
    $voucherStmt = $pdo->prepare("SELECT v.*, f.name AS fund_name, d.donor_name, reg.name AS region_name 
                                  FROM vouchers v
                                  LEFT JOIN funds f ON f.id = v.fund_id
                                  LEFT JOIN donors d ON d.id = v.donor_id
                                  LEFT JOIN regions reg ON reg.id = v.region_id
                                  WHERE v.status = 'posted' AND v.voucher_date BETWEEN ? AND ? AND v.region_id = ? {$voucherFundClause}
                                  ORDER BY v.voucher_date DESC, v.id DESC");
    $voucherStmt->execute([$fromDate, $toDate, $selectedRegionId]);
} else {
    $voucherStmt = $pdo->prepare("SELECT v.*, f.name AS fund_name, d.donor_name, reg.name AS region_name 
                                  FROM vouchers v
                                  LEFT JOIN funds f ON f.id = v.fund_id
                                  LEFT JOIN donors d ON d.id = v.donor_id
                                  LEFT JOIN regions reg ON reg.id = v.region_id
                                  WHERE v.status = 'posted' AND v.voucher_date BETWEEN ? AND ? {$voucherFundClause}
                                  ORDER BY v.voucher_date DESC, v.id DESC");
    $voucherStmt->execute([$fromDate, $toDate]);
}
$allVouchers = $voucherStmt->fetchAll();

// 5. Cash & Bank Accounts Schedule (for Dossier)
$treasuryAccounts = $pdo->query("SELECT a.id, a.account_code, a.account_name, a.opening_balance,
    COALESCE(SUM(jl.debit), 0) AS total_debit,
    COALESCE(SUM(jl.credit), 0) AS total_credit
    FROM accounts a
    LEFT JOIN journal_lines jl ON jl.account_id = a.id
    LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'posted'
    WHERE a.is_cash_or_bank = 1 AND a.is_active = 1
    GROUP BY a.id
    ORDER BY (a.account_code = '1000') DESC, (a.account_code = '1010') DESC, a.account_code ASC")->fetchAll();

$totalTreasuryBalance = 0.0;
foreach ($treasuryAccounts as $ta) {
    $totalTreasuryBalance += ((float)$ta['opening_balance'] + (float)$ta['total_debit'] - (float)$ta['total_credit']);
}

$madrasaName = get_setting('madrasa_name', 'Trust Accounts');
$madrasaAddress = get_setting('madrasa_address', '');
$madrasaPhone = get_setting('madrasa_phone', '');
$orgLogo = function_exists('get_org_logo_url') ? get_org_logo_url() : null;

// Print & Slip Customization Settings
$printShowLogo = get_setting('print_show_logo', '1') === '1';
$printShowAddress = get_setting('print_show_address', '1') === '1';
$printPreprinted = get_setting('print_preprinted_letterhead', '0') === '1';
$printSignTitle1 = get_setting('print_sign_title_1', is_madrasa_mode() ? 'تیار کنندہ / کیشیئر (Prepared By)' : 'Prepared By (تیار کنندہ)');
$printSignTitle2 = get_setting('print_sign_title_2', is_madrasa_mode() ? 'اکاؤنٹنٹ / ناظمِ مالیات (Accountant)' : 'Accountant / Cashier (اکاؤنٹنٹ)');
$printSignTitle3 = get_setting('print_sign_title_3', is_madrasa_mode() ? 'مہتمم / صدر / مجاز دستخط (Director / Mohtamim)' : 'Director / Mohtamim (دستخط مجاز اتھارٹی)');
$printFooterNote = get_setting('print_footer_note', 'یہ ایک کمپیوٹرائزڈ آفیشل فنانشل رسید ہے۔ کسی دستی مہر کی ضرورت نہیں۔');

// Determine Report Titles for Print Header
if ($reportType === 'vouchers') {
    $reportTitleEn = 'Vouchers Transaction Register';
    $reportTitleUr = 'تمام واؤچرز و مالیاتی لین دین کا رجسٹر';
} elseif ($reportType === 'funds') {
    $reportTitleEn = 'Funds & Projects Summary Statement';
    $reportTitleUr = 'فنڈ و پروجیکٹ وائز مالیاتی گوشوارہ';
} elseif ($reportType === 'audit_dossier') {
    $reportTitleEn = 'Annual Financial Audit Dossier';
    $reportTitleUr = 'سالانہ مالیاتی گوشوارہ و آڈٹ رپورٹ';
} else {
    $reportTitleEn = 'Income vs Expenses Statement';
    $reportTitleUr = 'آمدنی و اخراجات مالیاتی گوشوارہ';
}

$totalVouchersAmount = !empty($allVouchers) ? array_sum(array_column($allVouchers, 'total_amount')) : 0.0;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Financial Reports | <?=e($madrasaName)?></title>
    <link rel="stylesheet" href="assets/style.css?v=<?=css_ver()?>">
    <style>
        @media print {
            @page {
                size: A4 portrait;
                margin: 8mm 8mm 12mm 8mm;
            }
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .app-top-header, #appTopHeader, .sidebar, .app-sidebar, nav, .topbar, .filter-bar, .tab-nav, .btn-row, button, .no-print, footer, .app-footer, .header-mobile-toggle, .panel-heading {
                display: none !important;
            }
            .print-only {
                display: block !important;
            }
            body, .app-shell, .main {
                background: #ffffff !important;
                color: #000000 !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Nastaliq Urdu", "Jameel Noori Nastaleeq", Tahoma, Arial, sans-serif !important;
            }
            .panel {
                border: none !important;
                box-shadow: none !important;
                padding: 0 !important;
                margin: 0 !important;
                background: transparent !important;
            }
            .dossier-box {
                border: 2px solid #0f172a !important;
                padding: 16px !important;
            }
            .stats-grid {
                display: flex !important;
                gap: 10px !important;
                margin-bottom: 14px !important;
            }
            .stat-card {
                flex: 1 !important;
                border: 1px solid #cbd5e1 !important;
                box-shadow: none !important;
                padding: 10px 14px !important;
                background: #f8fafc !important;
            }
            .content-grid {
                display: block !important;
            }
            .table-wrap {
                overflow: visible !important;
                max-width: 100% !important;
            }
            table {
                width: 100% !important;
                border-collapse: collapse !important;
                margin-top: 6px;
                font-size: 11px !important;
            }
            table thead {
                display: table-header-group !important;
            }
            table tfoot {
                display: table-footer-group !important;
            }
            table tr {
                page-break-inside: avoid !important;
            }
            table th, table td {
                border: 1px solid #334155 !important;
                padding: 6px 8px !important;
                color: #000000 !important;
            }
            table th {
                background: #0f172a !important;
                color: #ffffff !important;
                font-weight: 700 !important;
                font-size: 11px !important;
            }
            table tbody tr:nth-child(even) td {
                background: #f8fafc !important;
            }
            table tfoot tr {
                background: #f1f5f9 !important;
                font-weight: 800 !important;
                border-top: 2px solid #0f172a !important;
            }
            .badge {
                border: 1px solid #64748b !important;
                padding: 2px 6px !important;
                color: #000000 !important;
                background: transparent !important;
                font-size: 9.5px !important;
                font-weight: 600 !important;
            }
            .dossier-sec, .sign-block {
                page-break-inside: avoid !important;
            }
        }
        .print-only {
            display: none;
        }
        .dossier-header { text-align: center; border-bottom: 2px double #0f172a; padding-bottom: 16px; margin-bottom: 22px; }
        .dossier-header h2 { font-size: 24px; margin: 0 0 6px; color: #0f172a; }
        .dossier-header p { margin: 2px 0; color: #475569; font-size: 13px; }
        .dossier-table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        .dossier-table th, .dossier-table td { border: 1px solid #cbd5e1; padding: 7px 10px; font-size: 12.5px; }
        .dossier-table th { background: #f8fafc; color: #0f172a; font-weight: 700; text-align: left; }
        .dossier-table .right { text-align: right; }
        .dossier-title-bar { background: #0f172a; color: #fff; padding: 6px 12px; font-size: 13.5px; font-weight: 700; margin: 18px 0 10px; border-radius: 4px; display: flex; justify-content: space-between; }

        @media screen and (max-width: 768px) {
            .topbar {
                flex-direction: column !important;
                align-items: stretch !important;
                gap: 12px !important;
            }
            .topbar .btn-row {
                display: flex !important;
                flex-wrap: wrap !important;
                gap: 8px !important;
                width: 100% !important;
            }
            .topbar .btn-row a, .topbar .btn-row button {
                flex: 1 1 auto !important;
                justify-content: center !important;
                text-align: center !important;
                padding: 9px 12px !important;
                font-size: 13px !important;
            }
            .filter-bar {
                flex-direction: column !important;
                align-items: stretch !important;
                gap: 10px !important;
            }
            .filter-bar > div {
                width: 100% !important;
                display: flex !important;
                justify-content: space-between !important;
                align-items: center !important;
            }
            .filter-bar input, .filter-bar select {
                max-width: 60% !important;
                width: 60% !important;
            }
            .filter-bar button {
                width: 100% !important;
                padding: 9px !important;
            }
            .stats-grid {
                grid-template-columns: 1fr !important;
                gap: 10px !important;
            }
            .tab-nav {
                overflow-x: auto !important;
                -webkit-overflow-scrolling: touch !important;
                white-space: nowrap !important;
                display: flex !important;
                padding-bottom: 4px !important;
            }
            .table-wrap {
                overflow-x: auto !important;
                -webkit-overflow-scrolling: touch !important;
                width: 100% !important;
                margin-bottom: 16px !important;
            }
            table {
                min-width: 580px !important;
            }
        }
    </style>
</head>
<body>
<div class="app-shell">
    <?php require __DIR__ . '/../app/sidebar.php'; ?>

    <main class="main">
        <?php if ($reportType !== 'audit_dossier'): ?>
        <!-- Official Print / PDF Header (Visible only when printed or exported to PDF) -->
        <div class="print-only print-header-box" style="border:1.5px solid #0f172a;border-radius:6px;padding:16px 20px;margin-bottom:16px;background:#ffffff; <?=$printPreprinted ? 'padding-top:100px;' : ''?>">
            <?php if (!$printPreprinted): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #0f172a;padding-bottom:12px;margin-bottom:12px;">
                <div style="display:flex;align-items:center;gap:14px;">
                    <?php if ($printShowLogo && $orgLogo): ?>
                        <img src="<?=e($orgLogo)?>" alt="Logo" style="max-height:60px;max-width:120px;object-fit:contain;">
                    <?php endif; ?>
                    <div>
                        <h1 style="margin:0;font-size:22px;font-weight:800;color:#0f172a;letter-spacing:-0.3px;line-height:1.2;"><?=e($madrasaName)?></h1>
                        <?php if ($printShowAddress && (!empty($madrasaAddress) || !empty($madrasaPhone))): ?>
                        <div style="font-size:11.5px;color:#475569;margin-top:3px;">
                            <?php if (!empty($madrasaAddress)): ?>
                                <span>📍 <?=e($madrasaAddress)?></span>
                            <?php endif; ?>
                            <?php if (!empty($madrasaPhone)): ?>
                                <span> &bull; 📞 <?=e($madrasaPhone)?></span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="text-align:right;">
                    <div style="display:inline-block;background:#0f172a;color:#ffffff;padding:5px 14px;font-size:12.5px;font-weight:700;border-radius:4px;text-transform:uppercase;letter-spacing:0.4px;">
                        <?=e($reportTitleEn)?>
                    </div>
                    <div style="font-size:12px;color:#0f172a;font-weight:700;margin-top:4px;" class="ur">
                        <?=e($reportTitleUr)?>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div style="display:flex;justify-content:flex-end;border-bottom:2px solid #0f172a;padding-bottom:8px;margin-bottom:12px;">
                <div style="text-align:right;">
                    <div style="display:inline-block;background:#0f172a;color:#ffffff;padding:5px 14px;font-size:12.5px;font-weight:700;border-radius:4px;text-transform:uppercase;letter-spacing:0.4px;">
                        <?=e($reportTitleEn)?>
                    </div>
                    <div style="font-size:12px;color:#0f172a;font-weight:700;margin-top:4px;" class="ur">
                        <?=e($reportTitleUr)?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Print Metadata Row -->
            <div style="display:flex;justify-content:space-between;align-items:center;font-size:11px;color:#334155;background:#f8fafc;padding:7px 12px;border-radius:4px;border:1px solid #e2e8f0;">
                <div>
                    <strong>Period:</strong> <span style="color:#0f172a;font-weight:700;"><?=e(date('d M Y', strtotime($fromDate)))?></span> to <span style="color:#0f172a;font-weight:700;"><?=e(date('d M Y', strtotime($toDate)))?></span>
                    <?php if ($selectedRegionId > 0 && !empty($allRegions)): ?>
                        <?php 
                        $regName = '';
                        foreach ($allRegions as $rg) { if ((int)$rg['id'] === $selectedRegionId) { $regName = $rg['name']; break; } }
                        ?>
                        &nbsp;|&nbsp; <strong>Province:</strong> <span style="color:#0284c7;font-weight:700;"><?=e($regName)?></span>
                    <?php elseif ($selectedRegionId === 0): ?>
                        &nbsp;|&nbsp; <strong>Scope:</strong> <span style="color:#0284c7;font-weight:700;">All Pakistan (ملک بھر کی مجموعی رپورٹ)</span>
                    <?php endif; ?>
                    <?php if ($selectedUserId > 0 && !empty($usersList)): ?>
                        <?php 
                        $uName = '';
                        foreach ($usersList as $usr) { if ((int)$usr['id'] === $selectedUserId) { $uName = $usr['full_name']; break; } }
                        ?>
                        &nbsp;|&nbsp; <strong>User / Cashier:</strong> <span style="font-weight:700;"><?=e($uName)?></span>
                    <?php elseif (!$canApprove): ?>
                        &nbsp;|&nbsp; <strong>Cashier:</strong> <span style="font-weight:700;"><?=e($currentUser['name'])?></span>
                    <?php endif; ?>
                </div>
                <div style="text-align:right;">
                    <strong>Generated By:</strong> <?=e($currentUser['name'] ?? 'Admin')?> &nbsp;|&nbsp; <strong>Printed:</strong> <?=date('d M Y, h:i A')?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php
        $reportShareUrl = (function_exists('get_app_base_url') ? get_app_base_url() : '') . '/reports.php?' . http_build_query($_GET);
        $reportPeriodText = date('d M Y', strtotime($fromDate)) . ' تا ' . date('d M Y', strtotime($toDate));

        $waReportMsg = "السلام علیکم و رحمۃ اللہ و برکاتہ\n"
                     . "🏢 *" . $madrasaName . "*\n"
                     . "📊 *" . $reportTitleUr . " (" . $reportTitleEn . ")*\n"
                     . "📅 *مدت (Period):* " . $reportPeriodText . "\n";

        if ($selectedRegionId > 0 && !empty($allRegions)) {
            $rName = '';
            foreach ($allRegions as $rg) { if ((int)$rg['id'] === $selectedRegionId) { $rName = $rg['name']; break; } }
            $waReportMsg .= "🏛️ *صوبہ / ریجن:* " . $rName . "\n";
        } else {
            $waReportMsg .= "🏛️ *دائرہ کار (Scope):* ملک بھر کی مجموعی رپورٹ\n";
        }

        if ($reportType === 'vouchers') {
            $waReportMsg .= "----------------------------------------\n"
                          . "📝 *کل واؤچرز:* " . count($allVouchers) . "\n"
                          . "💰 *مجموعی مالیت:* PKR " . number_format($totalVouchersAmount, 2) . "\n";
        } else {
            $waReportMsg .= "----------------------------------------\n"
                          . "💰 *کل موصولی / آمدنی (Income):* PKR " . number_format($totalIncome, 2) . "\n"
                          . "💸 *کل ادائیگیاں / اخراجات (Expense):* PKR " . number_format($totalExpense, 2) . "\n"
                          . "⚖️ *خالص بچت / سرپلس (Net Balance):* PKR " . number_format($netSurplus, 2) . "\n";
        }

        $waReportMsg .= "----------------------------------------\n"
                      . "📄 *مکمل کمپیوٹرائزڈ مالیاتی رپورٹ اور پی ڈی ایف دیکھنے / پرنٹ کرنے کے لیے نیچے لنک پر کلک کریں:* \n"
                      . $reportShareUrl . "\n\n"
                      . "جزاکم اللہ خیراً\n"
                      . "*" . $madrasaName . "*";

        $waReportShareLink = "https://api.whatsapp.com/send?text=" . urlencode($waReportMsg);
        ?>

        <header class="topbar no-print">
            <div>
                <span class="eyebrow">REPORTS</span>
                <?php if ($reportType === 'audit_dossier'): ?>
                    <h1>Annual Financial Audit Dossier <span class="ur">(سالانہ آڈٹ رپورٹ بک)</span></h1>
                    <small style="color:var(--muted);"><span class="ur">مجلس شوریٰ و باضابطہ آڈٹ کے لیے دستخط شدہ جامع سالانہ مالیاتی رپورٹ</span><span class="en-only" style="display:none;">Official Shura & Auditor dossier</span></small>
                <?php elseif (!$canApprove): ?>
                    <h1>My Financial Report <span class="ur">(میری انفرادی مالیاتی رپورٹ)</span></h1>
                    <small style="color:var(--muted);"><span class="ur">صارف: <strong><?=e($currentUser['name'])?></strong> — صرف آپ کے درج کردہ منظور شدہ مالیاتی ریکارڈز کا گوشوارہ</span><span class="en-only" style="display:none;">User: <strong><?=e($currentUser['name'])?></strong> — approved personal financial records summary</span></small>
                <?php else: ?>
                    <h1>Financial Reports <span class="ur">(مالیاتی گوشوارے)</span></h1>
                    <small style="color:var(--muted);"><span class="ur">ٹرسٹ / ادارے کا مجموعی مالیاتی گوشوارہ و فنڈ و پروجیکٹ وائز سرگرمی</span><span class="en-only" style="display:none;">Consolidated institution financial statements and fund activity</span></small>
                <?php endif; ?>
            </div>
            <div class="btn-row" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <a href="<?=$waReportShareLink?>" target="_blank" class="btn sm" style="background:#25D366;color:#ffffff;border:1px solid #16a34a;font-weight:700;padding:7px 14px;border-radius:6px;text-decoration:none;display:inline-flex;align-items:center;gap:6px;box-shadow:0 1px 2px rgba(0,0,0,0.06);" title="Share summary & PDF link on WhatsApp (واٹس ایپ پر رپورٹ شیئر کریں)">
                    💬 WhatsApp Share <span class="ur">(واٹس ایپ پر بھیجیں)</span>
                </a>
                <button class="btn secondary sm" onclick="window.print()" style="font-weight:700;padding:7px 14px;display:inline-flex;align-items:center;gap:6px;">
                    🖨️ Print Report <span class="ur">(پرنٹ / پی ڈی ایف)</span>
                </button>
            </div>
        </header>

        <!-- Date & User Filter Bar -->
        <form method="get" class="filter-bar no-print" style="margin-top:20px;flex-wrap:wrap;gap:12px;">
            <input type="hidden" name="tab" value="<?=e($reportType)?>">
            <div>
                <label style="font-size:12px;font-weight:600;margin-right:6px;">From Date:</label>
                <input type="date" name="from_date" value="<?=e($fromDate)?>">
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;margin-right:6px;">To Date:</label>
                <input type="date" name="to_date" value="<?=e($toDate)?>">
            </div>
            <?php if ($isSuperAdmin && !empty($allRegions)): ?>
                <div>
                    <label style="font-size:12px;font-weight:600;margin-right:6px;">🏛️ Province / Region:</label>
                    <select name="region_id" style="padding:6px 10px;border-radius:6px;border:1px solid var(--line);font-size:13px;font-weight:600;color:#0f172a;">
                        <option value="0">🌐 All Pakistan (پورے پاکستان کی رپورٹ)</option>
                        <?php foreach ($allRegions as $reg): ?>
                            <option value="<?=$reg['id']?>" <?=$selectedRegionId === (int)$reg['id'] ? 'selected' : ''?>>
                                🏛️ <?=e($reg['name'])?> (<?=e($reg['code'])?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php elseif ($isRegDirector && $currentRegionInfo): ?>
                <div style="background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;padding:6px 12px;border-radius:6px;font-size:12.5px;font-weight:600;display:flex;align-items:center;gap:6px;">
                    <span>🏛️</span>
                    <span>Province: <?=e($currentRegionInfo['name'])?></span>
                </div>
            <?php endif; ?>
            <?php if ($canApprove && !empty($usersList) && $reportType !== 'audit_dossier'): ?>
                <div>
                    <label style="font-size:12px;font-weight:600;margin-right:6px;">User / Cashier:</label>
                    <select name="user_id" style="padding:6px 10px;border-radius:6px;border:1px solid var(--line);font-size:13px;">
                        <option value="0">-- All Users <span class="ur">(پورے ادارے کی مجموعی رپورٹ)</span> --</option>
                        <?php foreach ($usersList as $u): ?>
                            <option value="<?=$u['id']?>" <?=$selectedUserId === (int)$u['id'] ? 'selected' : ''?>>
                                <?=e($u['full_name'])?> (<?=e($u['username'])?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <button class="btn primary sm" type="submit">Generate Report</button>
        </form>

        <!-- Tabs Nav -->
        <div class="tab-nav no-print" style="margin-bottom:20px;">
            <a href="reports.php?tab=income_expense&from_date=<?=urlencode($fromDate)?>&to_date=<?=urlencode($toDate)?><?=$selectedUserId>0&&$canApprove?'&user_id='.$selectedUserId:''?><?=$selectedRegionId>0?'&region_id='.$selectedRegionId:''?>" class="tab-link <?=$reportType==='income_expense'?'active':''?>">
                Income vs Expenses
            </a>
            <a href="reports.php?tab=funds&from_date=<?=urlencode($fromDate)?>&to_date=<?=urlencode($toDate)?><?=$selectedUserId>0&&$canApprove?'&user_id='.$selectedUserId:''?><?=$selectedRegionId>0?'&region_id='.$selectedRegionId:''?>" class="tab-link <?=$reportType==='funds'?'active':''?>">
                Funds Summary
            </a>
            <a href="reports.php?tab=vouchers&from_date=<?=urlencode($fromDate)?>&to_date=<?=urlencode($toDate)?><?=$selectedUserId>0&&$canApprove?'&user_id='.$selectedUserId:''?><?=$selectedRegionId>0?'&region_id='.$selectedRegionId:''?>" class="tab-link <?=$reportType==='vouchers'?'active':''?>">
                Vouchers Register
            </a>
            <a href="reports.php?tab=audit_dossier&from_date=<?=urlencode($fromDate)?>&to_date=<?=urlencode($toDate)?><?=$selectedRegionId>0?'&region_id='.$selectedRegionId:''?>" class="tab-link <?=$reportType==='audit_dossier'?'active':''?>">
                📑 Annual Audit Dossier <span class="ur">(سالانہ آڈٹ رپورٹ)</span>
            </a>
        </div>

        <?php if ($reportType === 'audit_dossier'): ?>
            <!-- ==================================================== -->
            <!-- TAB 4: OFFICIAL ANNUAL AUDIT DOSSIER & SHURA BOOK     -->
            <!-- ==================================================== -->
            <article class="panel dossier-box" style="padding:26px 30px;background:#fff;border-radius:12px;border:1px solid var(--line);">
                <!-- Header -->
                <div class="dossier-header">
                    <?php if ($printShowLogo && $orgLogo && !$printPreprinted): ?>
                        <div style="margin-bottom:8px;"><img src="<?=e($orgLogo)?>" alt="Logo" style="max-height:65px;max-width:130px;object-fit:contain;"></div>
                    <?php endif; ?>
                    <h2><?=e($madrasaName)?></h2>
                    <p style="font-weight:700;font-size:16px;color:#1e3a8a;">
                        سالانہ مالیاتی گوشوارہ و آڈٹ رپورٹ (Annual Financial Audit Dossier)
                    </p>
                    <?php if ($printShowAddress && (!empty($madrasaAddress) || !empty($madrasaPhone))): ?>
                    <p>
                        <?=!empty($madrasaAddress)?e($madrasaAddress).' | ':''?><?=!empty($madrasaPhone)?'فون رابطہ: '.e($madrasaPhone):''?>
                    </p>
                    <?php endif; ?>
                    <p style="margin-top:6px;font-size:13.5px;color:#0f172a;background:#f1f5f9;display:inline-block;padding:4px 14px;border-radius:20px;font-weight:600;">
                        مالیاتی آڈٹ مدت: <b><?=e(date('d M Y', strtotime($fromDate)))?></b> تا <b><?=e(date('d M Y', strtotime($toDate)))?></b>
                    </p>
                </div>

                <!-- Part 1: Executive Summary -->
                <div class="dossier-sec">
                    <div class="dossier-title-bar">
                        <span>1. خلاصہ سالانہ مالیاتی کیفیت (Executive Financial Summary)</span>
                        <span>Part I</span>
                    </div>
                    <table class="dossier-table">
                        <thead>
                            <tr>
                                <th>تفصیل (Particulars)</th>
                                <th class="right">رقم (PKR)</th>
                                <th>کیفیت (Financial Remarks)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><b>کل وصول شدہ آمدنی (Total Period Receipts / Inflow)</b></td>
                                <td class="right font-bold" style="color:#15803d;"><?=money($totalPeriodIncome)?></td>
                                <td>تمام منظور شدہ فنڈز، عطیات و بیرونی امداد</td>
                            </tr>
                            <tr>
                                <td><b>کل مجاز اخراجات (Total Period Payments / Outflow)</b></td>
                                <td class="right font-bold" style="color:#b91c1c;"><?=money($totalPeriodExpense)?></td>
                                <td>تنخواہیں، مطبخ، تعمیرات و دیگر مصارف</td>
                            </tr>
                            <tr style="background:#f8fafc;font-size:13.5px;">
                                <td><b>خالص سالانہ بچت / خسارہ (Net Surplus / Operational Deficit)</b></td>
                                <td class="right font-bold" style="color:<?=$netPeriodSurplus>=0?'#15803d':'#b91c1c'?>;">
                                    <?=money($netPeriodSurplus)?>
                                </td>
                                <td><b><?=$netPeriodSurplus>=0?'خالص فاضل بچت (Surplus)':'خسارہ (Operational Deficit)'?></b></td>
                            </tr>
                            <tr>
                                <td><b>کل دستیاب سیال اثاثہ جات (Current Cash & Bank Balances)</b></td>
                                <td class="right font-bold" style="color:#1e3a8a;"><?=money($totalTreasuryBalance)?></td>
                                <td>خزانہ و رجسٹرڈ بینک کھاتوں میں کل رقم</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Part 2: Fund-Wise Consolidated Position -->
                <div class="dossier-sec" style="margin-top:20px;">
                    <div class="dossier-title-bar">
                        <span>2. فنڈ وائز آمد و خرچ گوشوارہ (Fund-Wise Movement Schedule)</span>
                        <span>Part II</span>
                    </div>
                    <table class="dossier-table">
                        <thead>
                            <tr>
                                <th>کوڈ</th>
                                <th>فنڈ / شعبہ کا نام</th>
                                <th>نوعیت</th>
                                <th class="right">وصولیاں (Inflow)</th>
                                <th class="right">مصارف (Outflow)</th>
                                <th class="right">خالص تبدیلی (Net Surplus)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($fundReports as $fr): 
                                $netCh = (float)$fr['period_inflow'] - (float)$fr['period_outflow'];
                            ?>
                                <tr>
                                    <td><b><?=e($fr['fund_code'])?></b></td>
                                    <td><b><?=e($fr['name'])?></b></td>
                                    <td><?= (int)$fr['is_restricted'] === 1 ? 'مخصوص فنڈ (Restricted)' : 'عام فنڈ (General)' ?></td>
                                    <td class="right font-bold" style="color:#15803d;"><?=money($fr['period_inflow'])?></td>
                                    <td class="right font-bold" style="color:#b91c1c;"><?=money($fr['period_outflow'])?></td>
                                    <td class="right font-bold" style="color:<?=$netCh>=0?'#15803d':'#b91c1c'?>;"><?=money($netCh)?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Part 3: Schedule of Income Heads -->
                <div class="dossier-sec" style="margin-top:20px;">
                    <div class="dossier-title-bar">
                        <span>3. تفصیل مداتِ آمدنی (Detailed Schedule of Revenue)</span>
                        <span>Part III</span>
                    </div>
                    <table class="dossier-table">
                        <thead>
                            <tr>
                                <th>کوڈ</th>
                                <th>مدِ آمدنی (Income Head)</th>
                                <th class="right">رقم (PKR)</th>
                                <th class="right">فیصد تناسب (% Share)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($incomeHeads as $ih): 
                                $share = $totalPeriodIncome > 0 ? round(($ih['total_amount'] / $totalPeriodIncome) * 100, 1) : 0;
                            ?>
                                <tr>
                                    <td><?=e($ih['account_code'])?></td>
                                    <td><b><?=e(format_ui_text($ih['account_name']))?></b></td>
                                    <td class="right font-bold" style="color:#15803d;"><?=money($ih['total_amount'])?></td>
                                    <td class="right"><?=$share?>%</td>
                                </tr>
                            <?php endforeach; ?>
                            <tr style="background:#f8fafc;font-weight:bold;">
                                <td colspan="2">میزان کل آمدنی (Total Revenue)</td>
                                <td class="right font-bold" style="color:#15803d;"><?=money($totalPeriodIncome)?></td>
                                <td class="right">100%</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Part 4: Schedule of Expense Heads -->
                <div class="dossier-sec" style="margin-top:20px;">
                    <div class="dossier-title-bar">
                        <span>4. تفصیل مداتِ اخراجات (Detailed Schedule of Expenditures)</span>
                        <span>Part IV</span>
                    </div>
                    <table class="dossier-table">
                        <thead>
                            <tr>
                                <th>کوڈ</th>
                                <th>مدِ خرچ (Expense Head)</th>
                                <th class="right">رقم (PKR)</th>
                                <th class="right">فیصد تناسب (% Share)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expenseHeads as $eh): 
                                $share = $totalPeriodExpense > 0 ? round(($eh['total_amount'] / $totalPeriodExpense) * 100, 1) : 0;
                            ?>
                                <tr>
                                    <td><?=e($eh['account_code'])?></td>
                                    <td><b><?=e(format_ui_text($eh['account_name']))?></b></td>
                                    <td class="right font-bold" style="color:#b91c1c;"><?=money($eh['total_amount'])?></td>
                                    <td class="right"><?=$share?>%</td>
                                </tr>
                            <?php endforeach; ?>
                            <tr style="background:#f8fafc;font-weight:bold;">
                                <td colspan="2">میزان کل اخراجات (Total Expenditures)</td>
                                <td class="right font-bold" style="color:#b91c1c;"><?=money($totalPeriodExpense)?></td>
                                <td class="right">100%</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Part 5: Bank & Cash Treasury Schedule -->
                <div class="dossier-sec" style="margin-top:20px;">
                    <div class="dossier-title-bar">
                        <span>5. کیفیت نقدی و بینک بیلنس (Cash & Bank Position Schedule)</span>
                        <span>Part V</span>
                    </div>
                    <table class="dossier-table">
                        <thead>
                            <tr>
                                <th>کوڈ</th>
                                <th>اکاؤنٹ کا عنوان (Bank / Cash Account)</th>
                                <th class="right">افتتاحی رقم</th>
                                <th class="right">آمد (Inflow)</th>
                                <th class="right">خرچ (Outflow)</th>
                                <th class="right">موجودہ اختتامی بیلنس</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($treasuryAccounts as $ta): 
                                $bal = (float)$ta['opening_balance'] + (float)$ta['total_debit'] - (float)$ta['total_credit'];
                            ?>
                                <tr>
                                    <td><?=e($ta['account_code'])?></td>
                                    <td><b><?=e(format_ui_text($ta['account_name']))?></b></td>
                                    <td class="right"><?=money($ta['opening_balance'])?></td>
                                    <td class="right" style="color:#15803d;"><?=money($ta['total_debit'])?></td>
                                    <td class="right" style="color:#b91c1c;"><?=money($ta['total_credit'])?></td>
                                    <td class="right font-bold" style="color:#1e3a8a;"><?=money($bal)?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr style="background:#f8fafc;font-weight:bold;">
                                <td colspan="5">کل سیال خزانہ (Total Liquid Treasury)</td>
                                <td class="right font-bold" style="color:#1e3a8a;"><?=money($totalTreasuryBalance)?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Part 6: Shura & Audit Sign-off Certificate -->
                <div class="sign-block" style="margin-top:35px;padding-top:20px;border-top:2px solid #0f172a;">
                    <div style="background:#f8fafc;padding:12px 16px;border-radius:8px;border:1px solid #e2e8f0;margin-bottom:30px;font-size:12.5px;color:#334155;line-height:1.7;">
                        <b style="color:#0f172a;">تصدیقی بیان برائے مجلس شوریٰ و آڈٹ:</b><br>
                        ہم بذریعہ ہذا تصدیق کرتے ہیں کہ مذکورہ بالا سالانہ مالیاتی آڈٹ رپورٹ ادارے کے باضابطہ ڈبل انٹری کھاتہ جات، آمد و خرچ کے تصدیق شدہ واؤچرز، بینک اسٹیٹمنٹس اور معاونین کے ریکارڈز کی تفصیلی جانچ پڑتال کے بعد تیار کی گئی ہے۔ ادارے کے تمام مالی تصرفات متعلقہ شرعی اصولوں اور شوریٰ کی منظور کردہ پالیسی کے مطابق انجام پائے ہیں۔
                    </div>

                    <div style="display:flex;justify-content:space-between;text-align:center;gap:20px;flex-wrap:wrap;">
                        <div style="flex:1;min-width:200px;">
                            <div style="border-bottom:1px solid #000;margin-bottom:8px;height:55px;"></div>
                            <b style="display:block;font-size:13.5px;color:#0f172a;">خازن / ناظم مالیات</b>
                            <span style="font-size:11.5px;color:#64748b;">(Treasurer / Finance Director)</span>
                            <div style="margin-top:4px;font-size:11px;color:#94a3b8;">دستخط و مہر / Date: ____________</div>
                        </div>

                        <div style="flex:1;min-width:200px;">
                            <div style="border-bottom:1px solid #000;margin-bottom:8px;height:55px;"></div>
                            <b style="display:block;font-size:13.5px;color:#0f172a;">داخلی / باقاعدہ آڈیٹر</b>
                            <span style="font-size:11.5px;color:#64748b;">(Internal / Chartered Auditor)</span>
                            <div style="margin-top:4px;font-size:11px;color:#94a3b8;">دستخط و مہر / Date: ____________</div>
                        </div>

                        <div style="flex:1;min-width:200px;">
                            <div style="border-bottom:1px solid #000;margin-bottom:8px;height:55px;"></div>
                            <b style="display:block;font-size:13.5px;color:#0f172a;">صدر مجلس شوریٰ / مہتمم صاحب</b>
                            <span style="font-size:11.5px;color:#64748b;">(President / Principal Rector)</span>
                            <div style="margin-top:4px;font-size:11px;color:#94a3b8;">دستخط و مہر / Date: ____________</div>
                        </div>
                    </div>
                </div>
            </article>

        <?php elseif ($reportType === 'income_expense'): ?>
            <!-- Income vs Expense Summary Cards -->
            <section class="stats-grid" style="margin-bottom:20px;">
                <article class="stat-card">
                    <div class="stat-top"><span>Period Income <span class="ur">(کل آمدنی)</span></span><span class="stat-icon green">↗</span></div>
                    <strong style="color:#15803d;"><?=money($totalPeriodIncome)?></strong>
                    <small><?=e($fromDate)?> to <?=e($toDate)?></small>
                </article>
                <article class="stat-card">
                    <div class="stat-top"><span>Period Expenses <span class="ur">(کل اخراجات)</span></span><span class="stat-icon red">↘</span></div>
                    <strong style="color:#b91c1c;"><?=money($totalPeriodExpense)?></strong>
                    <small><?=e($fromDate)?> to <?=e($toDate)?></small>
                </article>
                <article class="stat-card">
                    <div class="stat-top"><span>Net Surplus / Deficit <span class="ur">(بچت / خسارہ)</span></span><span class="stat-icon blue">⚖️</span></div>
                    <strong style="color:<?=$netPeriodSurplus>=0?'#15803d':'#b91c1c'?>;"><?=money($netPeriodSurplus)?></strong>
                    <small><?=$netPeriodSurplus>=0?'Net Surplus <span class="ur">(بچت)</span>':'Net Deficit <span class="ur">(خسارہ)</span>'?></small>
                </article>
            </section>

            <div class="content-grid">
                <!-- Income Breakdown -->
                <article class="panel">
                    <div class="panel-heading">
                        <div>
                            <h3 style="color:#15803d;">Income Heads <span class="ur">(آمدنی کی مدات)</span></h3>
                            <p>Receipts recorded in selected period</p>
                        </div>
                    </div>
                    <?php if (!$incomeHeads): ?>
                        <div class="empty-state"><b>No income recorded in this period.</b></div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Account Head</th><th class="right">Amount</th></tr></thead>
                                <tbody>
                                    <?php foreach ($incomeHeads as $h): ?>
                                        <tr>
                                            <td><?=e($h['account_code'])?> - <?=e(format_ui_text($h['account_name']))?></td>
                                            <td class="right font-bold" style="color:#15803d;"><?=money($h['total_amount'])?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr style="background:#f8fafc;font-weight:bold;">
                                        <td>Total Income <span class="ur">(میزان آمدنی)</span></td>
                                        <td class="right font-bold" style="color:#15803d;"><?=money($totalPeriodIncome)?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </article>

                <!-- Expense Breakdown -->
                <article class="panel">
                    <div class="panel-heading">
                        <div>
                            <h3 style="color:#b91c1c;">Expense Heads <span class="ur">(اخراجات کی مدات)</span></h3>
                            <p>Payments recorded in selected period</p>
                        </div>
                    </div>
                    <?php if (!$expenseHeads): ?>
                        <div class="empty-state"><b>No expenses recorded in this period.</b></div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Account Head</th><th class="right">Amount</th></tr></thead>
                                <tbody>
                                    <?php foreach ($expenseHeads as $h): ?>
                                        <tr>
                                            <td><?=e($h['account_code'])?> - <?=e(format_ui_text($h['account_name']))?></td>
                                            <td class="right font-bold" style="color:#b91c1c;"><?=money($h['total_amount'])?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr style="background:#f8fafc;font-weight:bold;">
                                        <td>Total Expenses <span class="ur">(میزان اخراجات)</span></td>
                                        <td class="right font-bold" style="color:#b91c1c;"><?=money($totalPeriodExpense)?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </article>
            </div>

        <?php elseif ($reportType === 'funds'): ?>
            <!-- Fund Summary Table -->
            <article class="panel">
                <div class="panel-heading">
                    <div>
                        <h3>Fund-Wise Activity Report</h3>
                        <p>Inflows and Outflows per fund category between <?=e($fromDate)?> and <?=e($toDate)?></p>
                    </div>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Fund Name</th>
                                <th>Type</th>
                                <th class="right">Inflow <span class="ur">(آمدنی)</span></th>
                                <th class="right">Outflow <span class="ur">(اخراجات)</span></th>
                                <th class="right">Net Period Change</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($fundReports as $fr): 
                                $netChange = (float)$fr['period_inflow'] - (float)$fr['period_outflow'];
                            ?>
                                <tr>
                                    <td><b><?=e($fr['fund_code'])?></b></td>
                                    <td><?=e($fr['name'])?></td>
                                    <td>
                                        <?php if ((int)$fr['is_restricted'] === 1): ?>
                                            <span class="badge badge-amber">Restricted</span>
                                        <?php else: ?>
                                            <span class="badge badge-blue">General</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="right font-bold" style="color:#15803d;"><?=money($fr['period_inflow'])?></td>
                                    <td class="right font-bold" style="color:#b91c1c;"><?=money($fr['period_outflow'])?></td>
                                    <td class="right font-bold" style="color:<?=$netChange>=0?'#15803d':'#b91c1c'?>;">
                                        <?=money($netChange)?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background:#f1f5f9;font-weight:800;border-top:2px solid #0f172a;">
                                <td colspan="3">کل میزان (Total Funds Activity):</td>
                                <td class="right font-bold" style="color:#15803d;"><?=money($totalPeriodIncome)?></td>
                                <td class="right font-bold" style="color:#b91c1c;"><?=money($totalPeriodExpense)?></td>
                                <td class="right font-bold" style="color:<?=$netPeriodSurplus>=0?'#15803d':'#b91c1c'?>;"><?=money($netPeriodSurplus)?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </article>

        <?php elseif ($reportType === 'vouchers'): ?>
            <!-- Vouchers Register -->
            <article class="panel">
                <div class="panel-heading">
                    <div>
                        <h3>Vouchers Register <span class="ur">(تمام واؤچرز کا رجسٹر)</span></h3>
                        <p>Complete transaction register between <?=e($fromDate)?> and <?=e($toDate)?></p>
                    </div>
                </div>

                <?php if (!$allVouchers): ?>
                    <div class="empty-state"><b>No vouchers found in selected date range.</b></div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Voucher No</th>
                                    <th>Type</th>
                                    <th>Date</th>
                                    <th>Province <span class="ur">(صوبہ)</span></th>
                                    <th>Fund</th>
                                    <th>Party / Payee / Donor</th>
                                    <th>Narration</th>
                                    <th class="right">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allVouchers as $v): ?>
                                    <tr>
                                        <td><b><?=e($v['voucher_no'])?></b></td>
                                        <td>
                                            <?php if ($v['voucher_type'] === 'income'): ?>
                                                <span class="badge badge-green">Income</span>
                                            <?php elseif ($v['voucher_type'] === 'expense'): ?>
                                                <span class="badge badge-red">Expense</span>
                                            <?php else: ?>
                                                <span class="badge badge-blue"><?=e(ucfirst($v['voucher_type']))?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?=e($v['voucher_date'])?></td>
                                        <td>
                                            <?php if (!empty($v['region_name'])): ?>
                                                <span class="badge" style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;font-size:11px;font-weight:600;">
                                                    🏛️ <?=e($v['region_name'])?>
                                                </span>
                                            <?php else: ?>
                                                <span class="muted" style="font-size:11px;">— HQ</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?=e($v['fund_name'] ?? '—')?></td>
                                        <td><?=e($v['donor_name'] ?: ($v['payee_name'] ?: '—'))?></td>
                                        <td><?=e($v['description'] ?: '—')?></td>
                                        <td class="right font-bold"><?=money($v['total_amount'])?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="background:#f1f5f9;font-weight:800;border-top:2px solid #0f172a;">
                                    <td colspan="7" style="text-align:right;">
                                        Total Transactions (کل میزان - <?=count($allVouchers)?> واؤچرز):
                                    </td>
                                    <td class="right font-bold"><?=money($totalVouchersAmount)?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </article>
        <?php endif; ?>

        <!-- Official Signature Block for Reports (Visible only when printed or exported to PDF) -->
        <?php if ($reportType !== 'audit_dossier'): ?>
        <div class="print-only sign-block" style="margin-top:35px;page-break-inside:avoid;">
            <div style="display:flex;justify-content:space-between;padding:0 30px;">
                <div style="text-align:center;min-width:160px;">
                    <div style="border-top:1.5px solid #0f172a;padding-top:6px;font-size:11.5px;font-weight:700;color:#0f172a;">
                        <?=e($printSignTitle1)?>
                    </div>
                    <div style="font-size:10px;color:#64748b;margin-top:2px;"><?=e($currentUser['name'] ?? 'Admin')?></div>
                </div>
                <div style="text-align:center;min-width:160px;">
                    <div style="border-top:1.5px solid #0f172a;padding-top:6px;font-size:11.5px;font-weight:700;color:#0f172a;">
                        <?=e($printSignTitle2)?>
                    </div>
                    <div style="font-size:10px;color:#64748b;margin-top:2px;">Accounts Dept</div>
                </div>
                <div style="text-align:center;min-width:160px;">
                    <div style="border-top:1.5px solid #0f172a;padding-top:6px;font-size:11.5px;font-weight:700;color:#0f172a;">
                        <?=e($printSignTitle3)?>
                    </div>
                    <div style="font-size:10px;color:#64748b;margin-top:2px;">Official Seal &amp; Signature</div>
                </div>
            </div>
            <div style="text-align:center;margin-top:24px;font-size:10px;color:#94a3b8;border-top:1px solid #e2e8f0;padding-top:6px;">
                <?=e($printFooterNote)?> &bull; <?=e($madrasaName)?> &bull; Printed: <?=date('d-m-Y H:i:s')?>
            </div>
        </div>
        <?php endif; ?>

        <?=render_app_footer($madrasaName)?>
    </main>
</div>
</body>
</html>
