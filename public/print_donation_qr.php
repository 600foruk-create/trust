<?php
/**
 * Printable Donation & Appeal QR Code Poster
 * Ready-to-print high-resolution A4 / Flex poster with Trust branding,
 * official bank accounts, EasyPaisa, and a live QR Code linking to donate.php
 */
require __DIR__ . '/../app/bootstrap.php';

$orgName = function_exists('get_setting') ? get_setting('madrasa_name', 'Mangalchai Welfare Organization') : 'Mangalchai Welfare Organization';
$orgSubtitle = function_exists('get_setting') ? get_setting('org_subtitle', 'Financial Accounting & Welfare Trust') : 'Financial Accounting & Welfare Trust';
$orgPhone = function_exists('get_setting') ? get_setting('madrasa_phone', '+92 331 7240256') : '+92 331 7240256';
$orgAddress = function_exists('get_setting') ? get_setting('madrasa_address', '') : '';
$orgLogo = function_exists('get_org_logo_url') ? get_org_logo_url() : null;
$baseUrl = function_exists('get_app_base_url') ? get_app_base_url() : '';
$portalUrl = $baseUrl . '/donate.php';

// Bank & Donation Settings
$bankTitle = get_setting('donation_bank_title', $orgName);
$bankName = get_setting('donation_bank_name', 'Meezan Bank Limited');
$bankAccount = get_setting('donation_bank_account', '01020304050607');
$bankIban = get_setting('donation_bank_iban', 'PK00MEZN0001020304050607');
$easypaisaDetails = get_setting('donation_easypaisa', $orgPhone . ' (' . $orgName . ')');
$appealTitle = get_setting('donation_appeal_title', 'تعلیمی و فلاحی خدمات میں ہمارا ساتھ دیں');
$appealText = get_setting('donation_appeal_text', 'آپ کا دیا ہوا صدقہ، زکوٰۃ اور عطیات یتامیٰ و مستحق طلباء کی مفت رہائش، خوراک، تعلیم اور غریب گھرانوں کی کفالت پر دیانت داری سے خرچ کیے جاتے ہیں۔');

// QR Code URL (High-res 400x400 API)
$qrApiUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&margin=10&data=' . urlencode($portalUrl);
?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Donation QR Poster - <?=e($orgName)?></title>
    <style>
        @page {
            size: A4 portrait;
            margin: 10mm;
        }
        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Nastaliq Urdu", "Jameel Noori Nastaleeq", Tahoma, Arial, sans-serif;
            background: #f1f5f9;
            margin: 0;
            padding: 20px;
            color: #0f172a;
            direction: rtl;
            text-align: right;
        }
        .poster-canvas {
            max-width: 800px;
            margin: 0 auto;
            background: #ffffff;
            border: 8px solid #047857;
            border-radius: 18px;
            padding: 30px 36px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            position: relative;
        }
        .inner-border {
            border: 2px dashed #10b981;
            border-radius: 12px;
            padding: 26px 28px;
        }
        .header-section {
            text-align: center;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 20px;
            margin-bottom: 22px;
        }
        .logo-wrap {
            display: inline-block;
            margin-bottom: 12px;
        }
        .org-logo {
            max-height: 80px;
            max-width: 140px;
            object-fit: contain;
        }
        .org-title {
            font-size: 28px;
            font-weight: 900;
            color: #065f46;
            margin: 0 0 6px;
            line-height: 1.25;
        }
        .org-sub {
            font-size: 15px;
            color: #64748b;
            margin: 0;
            font-weight: 600;
        }
        .appeal-banner {
            background: linear-gradient(135deg, #065f46 0%, #047857 100%);
            color: #ffffff;
            border-radius: 14px;
            padding: 16px 20px;
            text-align: center;
            margin-bottom: 24px;
        }
        .appeal-banner h2 {
            margin: 0 0 8px;
            font-size: 22px;
            font-weight: 800;
        }
        .appeal-banner p {
            margin: 0;
            font-size: 14px;
            line-height: 1.7;
            opacity: 0.95;
        }
        .qr-center-box {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 28px;
            background: #f0fdf4;
            border: 2px solid #a7f3d0;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 24px;
        }
        .qr-img-wrap {
            background: #ffffff;
            padding: 12px;
            border-radius: 14px;
            border: 2px solid #059669;
            box-shadow: 0 6px 18px rgba(5, 150, 105, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .qr-img {
            width: 200px;
            height: 200px;
            display: block;
        }
        .qr-instructions {
            flex: 1;
        }
        .qr-instructions h3 {
            font-size: 20px;
            color: #065f46;
            margin: 0 0 8px;
            font-weight: 800;
        }
        .qr-instructions ol {
            margin: 0;
            padding-right: 20px;
            font-size: 14px;
            color: #334155;
            line-height: 1.9;
        }
        .qr-url-pill {
            display: inline-block;
            background: #ffffff;
            border: 1px solid #10b981;
            padding: 4px 12px;
            border-radius: 20px;
            font-family: monospace;
            font-size: 13px;
            direction: ltr;
            color: #047857;
            margin-top: 10px;
            font-weight: 700;
        }
        .accounts-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 20px;
        }
        .account-card {
            border: 1.5px solid #cbd5e1;
            border-radius: 12px;
            padding: 14px 16px;
            background: #f8fafc;
        }
        .account-card h4 {
            margin: 0 0 8px;
            font-size: 15px;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 6px;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 6px;
        }
        .acc-row {
            font-size: 13.5px;
            margin-bottom: 5px;
            color: #475569;
        }
        .acc-row strong {
            color: #0f172a;
        }
        .acc-num {
            font-family: monospace;
            font-size: 15px;
            color: #065f46;
            font-weight: 800;
            direction: ltr;
            display: inline-block;
        }
        .footer-banner {
            border-top: 2px solid #e2e8f0;
            padding-top: 14px;
            text-align: center;
            font-size: 13px;
            color: #64748b;
            line-height: 1.6;
        }
        .no-print-bar {
            max-width: 800px;
            margin: 0 auto 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #ffffff;
            padding: 12px 20px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .print-btn {
            background: #059669;
            color: #ffffff;
            border: none;
            padding: 9px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .print-btn:hover {
            background: #047857;
        }
        @media print {
            body {
                background: #ffffff;
                padding: 0;
            }
            .no-print-bar {
                display: none !important;
            }
            .poster-canvas {
                border-width: 6px;
                box-shadow: none;
                margin: 0;
                padding: 20px 24px;
                width: 100%;
                max-width: 100%;
            }
        }
    </style>
</head>
<body>

<div class="no-print-bar">
    <div>
        <strong style="color:#0f172a;font-size:15px;">🖨️ پرنٹ ایبل ڈونیشن کیو آر پوسٹر (Donation QR Poster)</strong>
        <div style="font-size:12px;color:#64748b;">اس پوسٹر کو A4 کاغذ یا فلیکس پر پرنٹ کر کے مسجد، دفتر یا اشتہار پر لگائیں۔</div>
    </div>
    <div style="display:flex;gap:10px;">
        <button type="button" class="print-btn" onclick="window.print()">
            <span>🖨️ ابھی پرنٹ کریں (Print / Save PDF)</span>
        </button>
        <a href="donors.php" style="background:#e2e8f0;color:#1e293b;padding:9px 16px;border-radius:8px;font-size:13.5px;text-decoration:none;font-weight:700;">
            ✕ واپس جائیں
        </a>
    </div>
</div>

<div class="poster-canvas">
    <div class="inner-border">
        <!-- Header -->
        <div class="header-section">
            <?php if ($orgLogo): ?>
                <div class="logo-wrap">
                    <img src="<?=e($orgLogo)?>" alt="Logo" class="org-logo">
                </div>
            <?php endif; ?>
            <h1 class="org-title"><?=e($orgName)?></h1>
            <p class="org-sub"><?=e($orgSubtitle)?></p>
        </div>

        <!-- Appeal Banner -->
        <div class="appeal-banner">
            <h2><?=e($appealTitle)?></h2>
            <p><?=e($appealText)?></p>
        </div>

        <!-- QR Code Center Box -->
        <div class="qr-center-box">
            <div class="qr-img-wrap">
                <img src="<?=e($qrApiUrl)?>" alt="Donation QR Code" class="qr-img">
            </div>
            <div class="qr-instructions">
                <h3>📲 موبائل سے اسکین کریں اور عطیہ درج کریں</h3>
                <ol>
                    <li>اپنے موبائل فون کا کیمرہ یا واٹس ایپ / گوگل لینس اوپن کریں۔</li>
                    <li>کیمرے کا رخ اس QR Code کے سامنے لائیں اور لنک پر کلک کریں۔</li>
                    <li>اپنی پسندیدہ مہم (کفالتِ یتیم، راشن، زکوٰۃ، تعلیم) منتخب کر کے باآسانی رجسٹریشن مکمل فرمائیں۔</li>
                </ol>
                <div>
                    <span class="qr-url-pill"><?=e($portalUrl)?></span>
                </div>
            </div>
        </div>

        <!-- Accounts Grid -->
        <div class="accounts-grid">
            <!-- Bank Account -->
            <div class="account-card">
                <h4>
                    <span>🏛️</span>
                    <span>بینک اکاؤنٹ تفصیلات (Bank Account)</span>
                </h4>
                <div class="acc-row">بینک کا نام: <strong><?=e($bankName)?></strong></div>
                <div class="acc-row">اکاؤنٹ ٹائٹل: <strong><?=e($bankTitle)?></strong></div>
                <div class="acc-row">اکاؤنٹ نمبر: <span class="acc-num"><?=e($bankAccount)?></span></div>
                <?php if (!empty($bankIban)): ?>
                    <div class="acc-row" style="font-size:12.5px;">IBAN: <span class="acc-num" style="font-size:13px;"><?=e($bankIban)?></span></div>
                <?php endif; ?>
            </div>

            <!-- Mobile Wallets -->
            <div class="account-card" style="background:#f0fdf4;border-color:#86efac;">
                <h4 style="color:#065f46;">
                    <span>📱</span>
                    <span>ایزی پیسہ / جاز کیش (Mobile Wallets)</span>
                </h4>
                <div class="acc-row" style="margin-top:10px;">
                    نمبر و ٹائٹل:
                    <div style="font-size:16px;font-weight:900;color:#047857;direction:ltr;text-align:right;margin-top:4px;">
                        <?=e($easypaisaDetails)?>
                    </div>
                </div>
                <div style="font-size:12px;color:#047857;margin-top:10px;line-height:1.5;">
                    💡 رقم منتقل کرنے کے بعد اپنے موبائل سے رسید کا اسکرین شاٹ واٹس ایپ پر ارسال فرما دیں۔
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer-banner">
            <div>
                <strong><?=e($orgName)?></strong> &bull; رابطہ و واٹس ایپ: <span style="direction:ltr;display:inline-block;font-weight:700;"><?=e($orgPhone)?></span>
            </div>
            <?php if (!empty($orgAddress)): ?>
                <div style="margin-top:3px;"><?=e($orgAddress)?></div>
            <?php endif; ?>
            <div style="margin-top:8px;font-size:11px;color:#94a3b8;">
                «مَا نَقَصَتْ صَدَقَةٌ مِنْ مَالٍ» (صدقہ دینے سے مال میں کبھی کمی نہیں آتی - صحیح مسلم)
            </div>
        </div>
    </div>
</div>

<script>
// Auto print if ?autoprint=1
if (window.location.search.indexOf('autoprint=1') !== -1) {
    window.onload = function() {
        setTimeout(function() { window.print(); }, 400);
    };
}
</script>
</body>
</html>

