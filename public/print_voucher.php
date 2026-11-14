<?php
require __DIR__ . '/../app/bootstrap.php';

$voucherId = (int)($_GET['id'] ?? 0);
$defaultFormatSetting = get_setting('print_default_format', 'a4');
$format = trim($_GET['format'] ?? $defaultFormatSetting); // 'a4' or 'thermal'
if (!in_array($format, ['a4', 'thermal'], true)) {
    $format = 'a4';
}
$defaultCopiesSetting = (int)get_setting('print_thermal_copies', '2');
$copies = isset($_GET['copies']) ? (int)$_GET['copies'] : $defaultCopiesSetting; // 1 or 2
if ($copies !== 2) $copies = 1;
$autoprint = !empty($_GET['autoprint']);

if ($voucherId <= 0) {
    http_response_code(400);
    exit('Invalid voucher ID.');
}

// Fetch voucher with fund, donor and user details
$stmt = $pdo->prepare("SELECT v.*, f.name AS fund_name, f.fund_code, d.donor_name, d.phone AS donor_phone, d.address AS donor_address, u.full_name AS created_by_name
                       FROM vouchers v
                       LEFT JOIN funds f ON f.id = v.fund_id
                       LEFT JOIN donors d ON d.id = v.donor_id
                       LEFT JOIN users u ON u.id = v.created_by
                       WHERE v.id = ?");
$stmt->execute([$voucherId]);
$voucher = $stmt->fetch();

if (!$voucher) {
    http_response_code(404);
    exit('Voucher not found.');
}

// Check for public verification token (allows donors/payees to securely view receipt from WhatsApp without ERP login)
$publicToken = trim($_GET['token'] ?? '');
$isPublicVerified = false;
if (!empty($publicToken) && function_exists('get_voucher_public_token')) {
    $expectedToken = get_voucher_public_token($voucherId);
    if (!empty($expectedToken) && hash_equals($expectedToken, $publicToken)) {
        $isPublicVerified = true;
    }
}

// Access Control & IDOR Protection for internal users:
if (!$isPublicVerified) {
    require_login();
    $currUser = current_user();
    $currUserId = (int)($currUser['id'] ?? 0);
    $isSuperAdmin = function_exists('is_super_admin') ? is_super_admin($currUser) : in_array($currUser['role'] ?? '', ['Super Admin', 'Administrator'], true);

    if (!$isSuperAdmin && $currUserId !== (int)$voucher['created_by']) {
        $userRegionId = function_exists('get_user_region_id') ? get_user_region_id($currUserId) : 0;
        if ($userRegionId > 0 && !empty($voucher['region_id']) && (int)$voucher['region_id'] !== $userRegionId) {
            http_response_code(403);
            exit('Access Denied: You do not have permission to view or print vouchers from another province/region.');
        }
        if (!empty($voucher['fund_id']) && function_exists('is_fund_allowed_for_user') && !is_fund_allowed_for_user((int)$voucher['fund_id'], $currUser)) {
            http_response_code(403);
            exit('Access Denied: You do not have permission to view or print vouchers for this fund.');
        }
    }
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
$printReceiptDua = get_setting('print_receipt_dua', 'اللہ تعالیٰ آپ کے اس صدقہ و عطیہ کو شرف قبولیت بخشے، اور آپ کے جان و مال اور رزق میں بے شمار برکتیں عطا فرمائے۔ آمین۔');
$printFooterNote = get_setting('print_footer_note', 'یہ ایک کمپیوٹرائزڈ آفیشل فنانشل رسید ہے۔ کسی دستی مہر کی ضرورت نہیں۔');
$currency = get_setting('currency', 'PKR');
$amountInWords = function_exists('number_to_words') ? number_to_words((float)$voucher['total_amount']) : money($voucher['total_amount']);

$isIncome = ($voucher['voucher_type'] === 'income');
$isTransfer = ($voucher['voucher_type'] === 'transfer');
$isUrdu = function_exists('is_urdu_enabled') ? is_urdu_enabled() : true;
$isMadrasa = function_exists('is_madrasa_mode') ? is_madrasa_mode() : false;
if ($isTransfer) {
    $title = 'CONTRA / TRANSFER VOUCHER <span class="ur">(کیش ٹرانسفر واؤچر)</span>';
    $partyLabel = 'Transferred To <span class="ur">(وصول کنندہ کھاتہ)</span>';
    $partyName = $voucher['payee_name'] ?: 'Transferred Account';
} elseif ($isMadrasa) {
    $title = $isIncome ? 'RECEIPT VOUCHER <span class="ur">(رسید چندہ و فیس)</span>' : 'PAYMENT VOUCHER <span class="ur">(واؤچر خرچ مدرسہ)</span>';
    $partyLabel = $isIncome ? 'Received From <span class="ur">(معاون / طالب علم)</span>' : 'Paid To <span class="ur">(وصول کنندہ / مد)</span>';
    $partyName = $isIncome ? ($voucher['donor_name'] ?: ($voucher['payee_name'] ?: 'General Donor')) : ($voucher['payee_name'] ?: 'Vendor / Payee');
} else {
    $title = $isIncome ? 'DONATION RECEIPT <span class="ur">(ڈونیشن رسید)</span>' : 'PAYMENT VOUCHER <span class="ur">(واؤچر ادائیگی)</span>';
    $partyLabel = $isIncome ? 'Received From <span class="ur">(ڈونر / معاون)</span>' : 'Paid To <span class="ur">(وصول کنندہ / مد)</span>';
    $partyName = $isIncome ? ($voucher['donor_name'] ?: ($voucher['payee_name'] ?: 'General Donor')) : ($voucher['payee_name'] ?: 'Vendor / Payee');
}

// Construct QR Verification Data for Thermal Slip
$qrData = $madrasaName . "\nVoucher: " . $voucher['voucher_no'] . "\nDate: " . date('d-m-Y', strtotime($voucher['voucher_date'])) . "\nAmount: " . $currency . ' ' . number_format((float)$voucher['total_amount'], 2) . "\nParty: " . $partyName . "\nStatus: " . ucfirst($voucher['status'] ?? 'posted');
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&margin=2&data=' . urlencode($qrData);

$attachment = null;
try {
    $attStmt = $pdo->prepare("SELECT stored_name, original_name FROM attachments WHERE voucher_id = ? LIMIT 1");
    $attStmt->execute([$voucherId]);
    $attachment = $attStmt->fetch() ?: null;
} catch (Exception $e) {
    $attachment = null;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?=e($voucher['voucher_no'])?> - Print Voucher | <?=e($madrasaName)?></title>
    <style>
        * { box-sizing: border-box; }
        <?php if (!$isUrdu): ?>
        .ur { display: none !important; }
        .en-only { display: inline !important; }
        <?php endif; ?>
        body {
            margin: 0;
            padding: 20px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            background: #f1f5f9;
            color: #0f172a;
        }

        /* Top Toolbar */
        .toolbar {
            max-width: 820px;
            margin: 0 auto 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #ffffff;
            padding: 12px 20px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .toolbar-left { display: flex; gap: 8px; align-items: center; }
        .toolbar-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 7px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #334155;
            cursor: pointer;
        }
        .toolbar-btn.active { background: #2563eb; color: #ffffff; border-color: #2563eb; }
        .toolbar-btn.primary { background: #0f172a; color: #ffffff; border-color: #0f172a; }
        .toolbar-btn:hover { opacity: 0.9; }

        /* A4 Document Format */
        .page-a4 {
            width: 800px;
            margin: 0 auto;
            background: #ffffff;
            padding: 40px 48px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border: 1.5px solid #0f172a;
            position: relative;
        }
        .a4-header {
            text-align: center;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 16px;
            margin-bottom: 22px;
        }
        .a4-header h1 {
            margin: 0 0 6px;
            font-size: 26px;
            letter-spacing: .5px;
            color: #0f172a;
        }
        .a4-header .voucher-tag {
            display: inline-block;
            background: #0f172a;
            color: #ffffff;
            padding: 4px 14px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 1px;
            margin-top: 6px;
        }
        .a4-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 22px;
            font-size: 14px;
            background: #f8fafc;
            padding: 12px 16px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
        }
        .a4-table {
            width: 100%;
            border-collapse: collapse;
            margin: 18px 0;
            font-size: 13.5px;
        }
        .a4-table th, .a4-table td {
            border: 1px solid #cbd5e1;
            padding: 10px 14px;
            text-align: left;
        }
        .a4-table th {
            background: #f1f5f9;
            color: #334155;
            font-weight: 700;
        }
        .amount-box {
            background: #f0fdf4;
            border: 2px solid #22c55e;
            border-radius: 8px;
            padding: 16px 20px;
            margin: 24px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .amount-box strong { font-size: 26px; color: #15803d; }
        .amount-words { font-size: 13px; color: #166534; font-weight: 600; margin-top: 4px; }
        .signatures {
            margin-top: 55px;
            display: flex;
            justify-content: space-between;
            text-align: center;
        }
        .sig-block {
            width: 200px;
            border-top: 1.5px dashed #64748b;
            padding-top: 8px;
            font-size: 12.5px;
            font-weight: 600;
            color: #475569;
        }

        /* Thermal 80mm Slip Format */
        .slip-thermal-container {
            width: 330px;
            margin: 0 auto;
        }
        .slip-thermal {
            width: 320px;
            margin: 0 auto 20px;
            background: #ffffff;
            padding: 16px 14px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border: 1px dashed #94a3b8;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Nastaliq Urdu", "Jameel Noori Nastaleeq", Tahoma, Arial, sans-serif;
            font-size: 13px;
            line-height: 1.45;
            color: #000000;
        }
        .thermal-header { text-align: center; margin-bottom: 10px; }
        .thermal-header h2 { margin: 0 0 4px; font-size: 17px; font-weight: 800; color: #000; line-height: 1.25; }
        .thermal-copy-badge {
            display: inline-block;
            border: 1px solid #000;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 700;
            margin-top: 5px;
            text-transform: uppercase;
        }
        .thermal-divider { border-top: 1px dashed #000; margin: 8px 0; }
        .thermal-row { display: flex; justify-content: space-between; margin: 4px 0; font-size: 12.5px; }
        .thermal-bold { font-weight: 700; }
        .thermal-amount-box {
            text-align: center;
            border: 2px solid #000;
            border-radius: 4px;
            margin: 10px 0 6px;
            padding: 8px 6px;
            background: #fafafa;
        }
        .thermal-amount-val {
            font-size: 22px;
            font-weight: 900;
            letter-spacing: .5px;
        }
        .thermal-amount-words {
            font-size: 11px;
            font-weight: 600;
            margin-top: 3px;
            line-height: 1.3;
        }
        .thermal-footer { text-align: center; margin-top: 12px; font-size: 11.5px; }
        .thermal-qr {
            text-align: center;
            margin: 10px 0;
        }
        .thermal-qr img {
            width: 95px;
            height: 95px;
            object-fit: contain;
            display: inline-block;
        }
        .thermal-cut-line {
            text-align: center;
            margin: 18px 0;
            font-size: 11px;
            color: #475569;
            border-top: 1px dashed #64748b;
            padding-top: 8px;
        }

        @media screen and (max-width: 840px) {
            body { padding: 8px 6px !important; }
            .toolbar {
                flex-direction: column !important;
                align-items: stretch !important;
                gap: 10px !important;
                padding: 10px 12px !important;
                width: 100% !important;
                max-width: 100% !important;
            }
            .toolbar-left {
                display: flex !important;
                flex-wrap: wrap !important;
                gap: 6px !important;
                justify-content: center !important;
                width: 100% !important;
            }
            .toolbar-btn {
                flex: 1 1 auto !important;
                justify-content: center !important;
                padding: 7px 10px !important;
                font-size: 12px !important;
                text-align: center !important;
            }
            .page-a4 {
                width: 100% !important;
                max-width: 100% !important;
                padding: 20px 14px !important;
                box-sizing: border-box !important;
                border-radius: 6px !important;
            }
            .a4-header h1 {
                font-size: 20px !important;
            }
            .a4-meta {
                flex-direction: column !important;
                align-items: flex-start !important;
                gap: 6px !important;
                padding: 10px 12px !important;
            }
            .a4-table {
                font-size: 12px !important;
            }
            .a4-table th, .a4-table td {
                padding: 8px 10px !important;
            }
            .amount-box {
                padding: 12px 14px !important;
                margin: 16px 0 !important;
            }
            .amount-box .amount-val {
                font-size: 22px !important;
            }
            .a4-signatures {
                flex-direction: column !important;
                align-items: center !important;
                gap: 18px !important;
                margin-top: 26px !important;
            }
            .sig-block {
                width: 180px !important;
            }
            .slip-thermal-container {
                width: 100% !important;
                max-width: 100% !important;
            }
            .slip-thermal {
                width: 100% !important;
                max-width: 330px !important;
                box-sizing: border-box !important;
            }
        }

        @media print {
            body { padding: 0 !important; margin: 0 !important; background: #fff !important; }
            .toolbar { display: none !important; }
            .page-a4 { box-shadow: none !important; border: 1.5px solid #000 !important; margin: 0 !important; width: 100% !important; max-width: 100% !important; padding: 12mm 10mm !important; box-sizing: border-box !important; }
            .slip-thermal-container { width: 100% !important; margin: 0 !important; }
            .slip-thermal { box-shadow: none !important; border: none !important; width: 76mm !important; max-width: 76mm !important; margin: 0 auto !important; padding: 2mm 1mm 10mm !important; }
            .thermal-cut-line { color: #000 !important; border-color: #000 !important; }
            @page {
                margin: <?= $format === 'thermal' ? '0mm' : '8mm' ?>;
                size: <?= $format === 'thermal' ? '80mm auto' : 'A4 portrait' ?>;
            }
        }
    </style>
</head>
<body>

<!-- Interactive Toolbar (Hidden during print) -->
<div class="toolbar">
    <div class="toolbar-left">
        <?php if (!$isPublicVerified): ?>
            <a href="<?= $isIncome ? 'income.php' : 'expenses.php' ?>" class="toolbar-btn">
                &larr; Back
            </a>
        <?php else: ?>
            <span style="font-size:12px;font-weight:700;color:#15803d;padding:6px 10px;background:#dcfce7;border:1px solid #86efac;border-radius:6px;display:inline-flex;align-items:center;gap:5px;">
                ✓ Official Verified Voucher <span class="ur">(تصدیق شدہ آفیشل کمپیوٹرائزڈ واؤچر)</span>
            </span>
        <?php endif; ?>
        <?php $tokenParam = $isPublicVerified ? '&token=' . urlencode($publicToken) : ''; ?>
        <a href="print_voucher.php?id=<?=$voucherId?>&format=a4<?=$tokenParam?>" class="toolbar-btn <?=$format==='a4'?'active':''?>">
            📄 A4 Full Voucher
        </a>
        <a href="print_voucher.php?id=<?=$voucherId?>&format=thermal&copies=1<?=$tokenParam?>" class="toolbar-btn <?=$format==='thermal'&&$copies===1?'active':''?>">
            🧾 Thermal Slip (1 Copy)
        </a>
        <a href="print_voucher.php?id=<?=$voucherId?>&format=thermal&copies=2<?=$tokenParam?>" class="toolbar-btn <?=$format==='thermal'&&$copies===2?'active':''?>" title="معاون اور دفتر کے لیے دو کاپیاں ایک ساتھ">
            🧾 Thermal (2 Copies - معاون + دفتر)
        </a>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:center;">
        <?php 
        $baseUrl = function_exists('get_app_base_url') ? get_app_base_url() : '';
        $baseUrl = preg_replace('/\/[a-zA-Z0-9_\-]+\.php$/i', '', $baseUrl);
        $slipPubToken = function_exists('get_voucher_public_token') ? get_voucher_public_token($voucherId) : '';
        $slipShareLink = !empty($baseUrl) ? "{$baseUrl}/print_voucher.php?id={$voucherId}&token={$slipPubToken}" : '';

        if ($isIncome): 
            $waUrl = function_exists('build_whatsapp_receipt_url') ? build_whatsapp_receipt_url($voucher, $madrasaName, $madrasaPhone) : '';
        ?>
            <a href="<?=$waUrl?>" target="_blank" class="toolbar-btn" style="background:#25D366;color:#ffffff;border-color:#22c55e;" title="ڈونر کو واٹس ایپ پر رسید بھیجیں">
                📲 Send WhatsApp Receipt <span class="ur">(واٹس ایپ رسید)</span>
            </a>
        <?php else: 
            $payeePhone = normalize_pakistan_phone($voucher['donor_phone'] ?? '');
            $expMsg = "السلام علیکم و رحمۃ اللہ و برکاتہ\n"
                    . "محترم جناب *" . ($voucher['payee_name'] ?: 'محترم') . "* صاحب!\n"
                    . "*" . $madrasaName . "* کی جانب سے ادائیگی واؤچر کی تفصیل:\n\n"
                    . "📋 *واؤچر نمبر:* " . $voucher['voucher_no'] . "\n"
                    . "📅 *تاریخ:* " . date('d-m-Y', strtotime($voucher['voucher_date'])) . "\n"
                    . "💰 *رقم:* *PKR " . number_format((float)$voucher['total_amount'], 2) . "*\n"
                    . "🏷️ *فنڈ و مد:* " . ($voucher['fund_name'] ?? 'General') . "\n\n";
            if (!empty($slipShareLink)) {
                $expMsg .= "📄 *کمپیوٹرائزڈ ادائیگی واؤچر سلپ دیکھنے یا پرنٹ کرنے کے لیے لنک:* \n"
                         . $slipShareLink . "\n\n";
            }
            $expMsg .= "جزاکم اللہ خیراً\n*" . $madrasaName . "*";
            $expWaUrl = "https://api.whatsapp.com/send?" . (!empty($payeePhone) ? "phone=" . urlencode($payeePhone) . "&" : "") . "text=" . urlencode($expMsg);
        ?>
            <a href="<?=$expWaUrl?>" target="_blank" class="toolbar-btn" style="background:#25D366;color:#ffffff;border-color:#22c55e;" title="واٹس ایپ پر واؤچر سلپ بھیجیں">
                📲 Send WhatsApp Voucher <span class="ur">(واٹس ایپ واؤچر)</span>
            </a>
        <?php endif; ?>
        <button onclick="window.print()" class="toolbar-btn primary" style="background:#16a34a;border-color:#16a34a;">
            🖨️ Print Now (پرنٹ کریں)
        </button>
    </div>
</div>

<?php if ($format === 'a4'): ?>
    <!-- A4 Full Page Receipt -->
    <div class="page-a4">
        <?php if ($printPreprinted): ?>
            <!-- Pre-printed Stationery Letterhead Mode: Headspace reserved for physical printed pad -->
            <div class="a4-header" style="padding-top: 110px; border-bottom: 2px solid #0f172a; padding-bottom: 10px; margin-bottom: 16px;">
                <div class="voucher-tag"><?=$title?></div>
            </div>
        <?php else: ?>
            <div class="a4-header">
                <?php if ($printShowLogo && $orgLogo): ?>
                    <div><img src="<?=e($orgLogo)?>" alt="Logo" style="max-height:70px;max-width:140px;object-fit:contain;margin-bottom:6px;"></div>
                <?php endif; ?>
                <h1><?=e($madrasaName)?></h1>
                <?php if ($printShowAddress && (!empty($madrasaAddress) || !empty($madrasaPhone))): ?>
                    <div style="font-size:12.5px;color:#475569;margin-top:2px;">
                        <?php if (!empty($madrasaAddress)): ?>📍 <?=e($madrasaAddress)?><?php endif; ?>
                        <?php if (!empty($madrasaAddress) && !empty($madrasaPhone)): ?> &nbsp;·&nbsp; <?php endif; ?>
                        <?php if (!empty($madrasaPhone)): ?>📞 <?=e($madrasaPhone)?><?php endif; ?>
                    </div>
                <?php endif; ?>
                <div style="font-size:12.5px;color:#64748b;">Financial Accounts &amp; Audit System</div>
                <div class="voucher-tag"><?=$title?></div>
            </div>
        <?php endif; ?>

        <div class="a4-meta">
            <div>
                <b>Voucher #:</b> <span style="font-family:monospace;font-size:15px;"><?=e($voucher['voucher_no'])?></span>
            </div>
            <div>
                <b>Date <span class="ur">(تاریخ)</span>:</b> <?=e(date('d F Y', strtotime($voucher['voucher_date'])))?>
            </div>
            <div>
                <b>Status:</b> 
                <?php if ($voucher['status'] === 'posted'): ?>
                    <span style="color:#15803d;font-weight:bold;">POSTED & APPROVED</span>
                <?php elseif ($voucher['status'] === 'draft'): ?>
                    <span style="color:#d97706;font-weight:bold;">PENDING APPROVAL <span class="ur">(زیرِ التواء)</span></span>
                <?php else: ?>
                    <span style="color:#b91c1c;font-weight:bold;">REVERSED / REJECTED</span>
                <?php endif; ?>
            </div>
            <?php if (!empty($attachment)): ?>
                <div>
                    <b><?= $isIncome ? 'Attached Slip / Cheque Photo:' : 'Bill / Receipt Photo:' ?></b> <a href="uploads/receipts/<?=e($attachment['stored_name'])?>" target="_blank" style="color:#2563eb;text-decoration:underline;">📷 View Attachment</a>
                </div>
            <?php endif; ?>
        </div>

        <table class="a4-table">
            <tr>
                <th style="width:30%;"><?=$partyLabel?></th>
                <td style="font-size:15px;font-weight:700;">
                    <?=e($partyName)?>
                    <?php if (!empty($voucher['donor_phone'])): ?>
                        <span style="font-size:12px;color:#64748b;font-weight:normal;">(Phone: <?=e($voucher['donor_phone'])?>)</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>Fund Account <span class="ur">(فنڈ)</span></th>
                <td><b><?=e($voucher['fund_name'] ?? (is_madrasa_mode() ? 'Madrasa General Fund' : 'Trust General Fund'))?></b> (<?=e($voucher['fund_code'] ?? (is_madrasa_mode() ? 'MGF' : 'TGF'))?>)</td>
            </tr>
            <tr>
                <th>Payment Mode <span class="ur">(طریقہ ادائیگی)</span></th>
                <td><?=e(ucfirst($voucher['payment_method'] ?? 'Cash'))?> <?= $voucher['reference_no'] ? '— Ref / Cheque #: '.e($voucher['reference_no']) : '' ?></td>
            </tr>
            <?php if (!empty($voucher['foreign_currency']) && $voucher['foreign_currency'] !== 'PKR'): ?>
            <tr>
                <th>Foreign Currency <span class="ur">(غیر ملکی کرنسی و ریٹ)</span></th>
                <td>
                    <b><?=e($voucher['foreign_currency'])?> <?=number_format((float)($voucher['foreign_amount'] ?? 0), 2)?></b> 
                    <span style="color:#64748b;">(Exchange Rate: <?=e($voucher['exchange_rate'] ?? '1.00')?> PKR)</span>
                </td>
            </tr>
            <?php endif; ?>
            <?php if (!empty($voucher['description'])): ?>
            <tr>
                <th>Description / Purpose <span class="ur">(تفصیل)</span></th>
                <td><?=e($voucher['description'])?></td>
            </tr>
            <?php endif; ?>
        </table>

        <!-- Amount Highlight Box -->
        <div class="amount-box">
            <div>
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#166534;font-weight:700;">Total Amount <span class="ur">(رقم)</span></div>
                <strong><?=money($voucher['total_amount'])?></strong>
                <div class="amount-words">In Words: <?=e($amountInWords)?></div>
            </div>
            <div style="text-align:right;">
                <span style="display:inline-block;border:2px solid #16a34a;color:#16a34a;padding:6px 16px;border-radius:6px;font-weight:800;font-size:15px;text-transform:uppercase;">
                    PAID / RECEIVED
                </span>
            </div>
        </div>

        <!-- Dua and Note Section -->
        <div style="margin-top:14px;padding:10px 14px;background:#f8fafc;border-radius:6px;border-left:3px solid #16a34a;">
            <?php if (!empty($printReceiptDua)): ?>
                <div style="font-size:12.5px;color:#166534;font-weight:700;margin-bottom:4px;direction:rtl;text-align:right;">
                    <?=e($printReceiptDua)?>
                </div>
            <?php endif; ?>
            <div style="font-size:11px;color:#64748b;">
                <?=e($printFooterNote)?>
            </div>
        </div>

        <!-- Signatures -->
        <div class="signatures">
            <div class="sig-block">
                <?=e($printSignTitle1)?><br>
                <small style="color:#94a3b8;"><?=e($voucher['created_by_name'] ?? 'Accounts')?></small>
            </div>
            <div class="sig-block">
                <?=e($printSignTitle2)?><br>
                <small style="color:#94a3b8;">Accounts Dept</small>
            </div>
            <div class="sig-block">
                <?=e($printSignTitle3)?><br>
                <small style="color:#94a3b8;">Official Seal &amp; Signature</small>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- 80mm Thermal Receipt Slip Container -->
    <div class="slip-thermal-container">
        <?php for ($c = 1; $c <= $copies; $c++): ?>
            <div class="slip-thermal">
                <div class="thermal-header">
                    <?php if ($printShowLogo && $orgLogo): ?>
                        <div><img src="<?=e($orgLogo)?>" alt="Logo" style="max-height:46px;max-width:110px;object-fit:contain;margin-bottom:4px;"></div>
                    <?php endif; ?>
                    <h2><?=e($madrasaName)?></h2>
                    <?php if ($printShowAddress && !empty($madrasaAddress)): ?>
                        <div style="font-size:11px;color:#222;"><?=e($madrasaAddress)?></div>
                    <?php endif; ?>
                    <?php if ($printShowAddress && !empty($madrasaPhone)): ?>
                        <div style="font-size:11px;color:#222;">Tel: <?=e($madrasaPhone)?></div>
                    <?php endif; ?>
                    <div style="margin-top:4px;font-size:12px;font-weight:700;"><?=strip_tags($title)?></div>
                    <?php if ($copies === 2): ?>
                        <div>
                            <span class="thermal-copy-badge">
                                <?= $c === 1 ? 'معاون کی کاپی (Donor Copy)' : 'دفتری ریکارڈ (Office Copy)' ?>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="thermal-divider"></div>

                <div class="thermal-row">
                    <span>رسید نمبر (Voucher):</span>
                    <span class="thermal-bold" style="font-family:monospace;font-size:13.5px;"><?=e($voucher['voucher_no'])?></span>
                </div>
                <div class="thermal-row">
                    <span>تاریخ (Date &amp; Time):</span>
                    <span><?=date('d-m-Y', strtotime($voucher['voucher_date']))?> <?=date('h:i A')?></span>
                </div>
                <div class="thermal-row">
                    <span>مد / فنڈ (Fund):</span>
                    <span class="thermal-bold"><?=e($voucher['fund_name'] ?? 'General')?></span>
                </div>
                <div class="thermal-row">
                    <span><?=$isIncome ? 'معاون (Donor):' : 'ادائیگی بنام (Payee):'?></span>
                    <span class="thermal-bold"><?=e($partyName)?></span>
                </div>
                <?php if (!empty($voucher['donor_phone'])): ?>
                <div class="thermal-row">
                    <span>موبائل (Phone):</span>
                    <span><?=e($voucher['donor_phone'])?></span>
                </div>
                <?php endif; ?>
                <div class="thermal-row">
                    <span>طریقہ کار (Method):</span>
                    <span><?=e(ucfirst($voucher['payment_method'] ?? 'Cash'))?> <?= $voucher['reference_no'] ? '('.e($voucher['reference_no']).')' : '' ?></span>
                </div>
                <?php if (!empty($voucher['foreign_currency']) && $voucher['foreign_currency'] !== 'PKR'): ?>
                <div class="thermal-row">
                    <span>کرنسی (Currency):</span>
                    <span class="thermal-bold"><?=e($voucher['foreign_currency'])?> <?=number_format((float)($voucher['foreign_amount'] ?? 0), 2)?> (@ <?=e($voucher['exchange_rate'] ?? '1.00')?>)</span>
                </div>
                <?php endif; ?>

                <div class="thermal-divider"></div>

                <div class="thermal-amount-box">
                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#000;">
                        کل وصول شدہ رقم (Total Amount)
                    </div>
                    <div class="thermal-amount-val">
                        <?=money($voucher['total_amount'])?>
                    </div>
                    <div class="thermal-amount-words">
                        <?=e($amountInWords)?>
                    </div>
                </div>

                <?php if (!empty($voucher['description'])): ?>
                <div style="font-size:11.5px;margin:6px 0;border:1px dashed #000;padding:5px 8px;border-radius:4px;line-height:1.4;">
                    <b>تفصیل (Desc):</b> <?=e($voucher['description'])?>
                </div>
                <?php endif; ?>

                <!-- QR Code Verification Badge -->
                <div class="thermal-qr">
                    <img src="<?=e($qrUrl)?>" alt="QR Verification">
                    <div style="font-size:10px;font-weight:600;margin-top:2px;">✓ کمپیوٹرائزڈ تصدیقی رسید (Verified)</div>
                </div>

                <div class="thermal-divider"></div>

                <div class="thermal-footer">
                    <?php if (!empty($printReceiptDua)): ?>
                        <div style="font-size:12px;font-weight:bold;margin-bottom:4px;line-height:1.4;direction:rtl;text-align:center;">
                            <?=e($printReceiptDua)?>
                        </div>
                    <?php else: ?>
                        <div style="font-size:13px;font-weight:bold;margin-bottom:2px;">جزاكم الله خيراً و احسن الجزاء</div>
                    <?php endif; ?>
                    <?php if (!empty($printFooterNote)): ?>
                        <div style="font-size:10.5px;color:#475569;margin-bottom:8px;line-height:1.3;text-align:center;">
                            <?=e($printFooterNote)?>
                        </div>
                    <?php endif; ?>
                    <div style="display:flex;justify-content:space-between;font-size:11px;margin-top:10px;border-top:1px dotted #000;padding-top:6px;">
                        <span><?=e($printSignTitle1)?>: <?=e($voucher['created_by_name'] ?? 'Admin')?></span>
                        <span>دستخط: ................</span>
                    </div>
                </div>
            </div>

            <?php if ($copies === 2 && $c === 1): ?>
                <div class="thermal-cut-line">
                    ✂️ ------------------ یہاں سے کاٹیں (Cut Here) ------------------ ✂️
                </div>
            <?php endif; ?>
        <?php endfor; ?>
    </div>
<?php endif; ?>

<?php if (!empty($autoprint)): ?>
<script>
window.addEventListener('load', function() {
    setTimeout(function() { window.print(); }, 400);
});
</script>
<?php endif; ?>

</body>
</html>
