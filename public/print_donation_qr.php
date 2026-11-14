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
$projectLocation = get_setting('donation_project_location', '');
$projectImage = get_setting('donation_project_image', '');
$hasProjectImage = !empty($projectImage) && file_exists(__DIR__ . '/' . $projectImage);

// QR Code URL (High-res 400x400 API)
$qrApiUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&margin=10&data=' . urlencode($portalUrl);
?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Donation QR Poster - <?=e($orgName)?></title>
    <script src="assets/html2canvas.min.js"></script>
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
            --theme-main: #047857;
            --theme-dark: #065f46;
            --theme-accent: #10b981;
            --theme-light: #f0fdf4;
            --theme-border: #a7f3d0;
            max-width: 800px;
            margin: 0 auto;
            background: #ffffff;
            border: 8px solid var(--theme-main);
            border-radius: 18px;
            padding: 30px 36px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            position: relative;
            transition: border-color 0.2s ease, max-width 0.25s ease;
        }
        .poster-canvas.theme-blue {
            --theme-main: #1d4ed8;
            --theme-dark: #1e3a8a;
            --theme-accent: #3b82f6;
            --theme-light: #eff6ff;
            --theme-border: #bfdbfe;
        }
        .poster-canvas.theme-maroon {
            --theme-main: #881337;
            --theme-dark: #4c0519;
            --theme-accent: #be123c;
            --theme-light: #fff1f2;
            --theme-border: #fecdd3;
        }
        .poster-canvas.theme-slate {
            --theme-main: #334155;
            --theme-dark: #0f172a;
            --theme-accent: #b45309;
            --theme-light: #fefce8;
            --theme-border: #fef08a;
        }

        /* Format Classes */
        .poster-canvas.format-status {
            max-width: 500px;
            padding: 22px 20px;
        }
        .poster-canvas.format-status .qr-center-box {
            flex-direction: column;
            text-align: center;
            gap: 14px;
        }
        .poster-canvas.format-status .qr-instructions ol {
            padding-right: 0;
            list-style-position: inside;
        }
        .poster-canvas.format-status .accounts-grid {
            grid-template-columns: 1fr;
            gap: 10px;
        }
        .poster-canvas.format-square {
            max-width: 720px;
            padding: 24px 26px;
        }

        .inner-border {
            border: 2px dashed var(--theme-accent);
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
            color: var(--theme-dark);
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
            background: linear-gradient(135deg, var(--theme-dark) 0%, var(--theme-main) 100%);
            color: #ffffff;
            border-radius: 14px;
            padding: 16px 22px;
            text-align: center;
            margin-bottom: 22px;
        }
        .appeal-banner.has-photo {
            text-align: right;
            padding: 16px 20px;
        }
        .appeal-flex {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }
        .appeal-text-side {
            flex: 1;
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
        .appeal-photo-side {
            flex-shrink: 0;
            width: 210px;
        }
        .appeal-photo-card {
            position: relative;
            border-radius: 10px;
            overflow: hidden;
            border: 3px solid rgba(255, 255, 255, 0.9);
            box-shadow: 0 6px 14px rgba(0, 0, 0, 0.25);
            background: #064e3b;
        }
        .appeal-photo-img {
            width: 100%;
            height: 130px;
            object-fit: cover;
            display: block;
        }
        .appeal-photo-badge {
            position: absolute;
            bottom: 4px;
            right: 4px;
            background: rgba(15, 23, 42, 0.82);
            color: #ffffff;
            font-size: 10.5px;
            padding: 2px 7px;
            border-radius: 4px;
            font-weight: 700;
            backdrop-filter: blur(2px);
        }
        .appeal-location-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 10px;
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.38);
            padding: 5px 12px;
            border-radius: 8px;
            font-size: 13px;
            color: #ffffff;
            font-weight: 700;
        }
        .qr-center-box {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 28px;
            background: var(--theme-light);
            border: 2px solid var(--theme-border);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 24px;
        }
        .qr-img-wrap {
            background: #ffffff;
            padding: 12px;
            border-radius: 14px;
            border: 2px solid var(--theme-main);
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.08);
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
            color: var(--theme-dark);
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
            border: 1px solid var(--theme-accent);
            padding: 4px 12px;
            border-radius: 20px;
            font-family: monospace;
            font-size: 13px;
            direction: ltr;
            color: var(--theme-main);
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
            color: var(--theme-dark);
            font-weight: 800;
            direction: ltr;
            display: inline-block;
        }
        .footer-banner {
            border-top: 2px solid var(--theme-border);
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
            flex-direction: column;
            gap: 12px;
            background: #ffffff;
            padding: 14px 20px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            border: 1px solid #e2e8f0;
        }
        .theme-pill, .format-pill {
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            color: #334155;
            padding: 4px 11px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.15s ease;
        }
        .theme-pill:hover, .format-pill:hover {
            border-color: #94a3b8;
            background: #f8fafc;
        }
        .theme-pill.active {
            background: #0f172a;
            color: #ffffff;
            border-color: #0f172a;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.2);
        }
        .format-pill.active {
            background: #0284c7;
            color: #ffffff;
            border-color: #0284c7;
            box-shadow: 0 2px 6px rgba(2, 132, 199, 0.25);
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
        .wa-btn {
            background: #25D366;
            color: #ffffff;
            border: 1px solid #20ba5a;
            padding: 9px 18px;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 2px 6px rgba(37, 211, 102, 0.35);
            transition: all 0.15s ease;
        }
        .wa-btn:hover {
            background: #20ba5a;
        }
        .dl-btn {
            background: #0284c7;
            color: #ffffff;
            border: 1px solid #0369a1;
            padding: 8px 13px;
            border-radius: 8px;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 2px 6px rgba(2, 132, 199, 0.25);
            transition: all 0.15s ease;
        }
        .dl-btn:hover {
            background: #0369a1;
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
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
        <div>
            <strong style="color:#0f172a;font-size:15px;">🖨️ پرنٹ ایبل ڈونیشن کیو آر پوسٹر (Donation QR Poster)</strong>
            <div style="font-size:12px;color:#64748b;">رنگ و سائز منتخب کریں، واٹس ایپ پر شیئر کریں یا تصویر محفوظ کریں۔</div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <button type="button" class="wa-btn" id="btnShareWhatsApp" onclick="sharePosterWhatsApp()" title="پوسٹر کی تصویر اور ڈونیشن لنک واٹس ایپ پر شیئر کریں">
                <span>💬 واٹس ایپ پر شیئر کریں (Share on WhatsApp)</span>
            </button>
            <button type="button" class="dl-btn" id="btnDownloadImage" onclick="downloadPosterImage()" title="پوسٹر کی تصویر محفوظ کریں">
                <span>💾 تصویر محفوظ کریں (Save Image)</span>
            </button>
            <button type="button" class="print-btn" onclick="window.print()">
                <span>🖨️ پرنٹ / PDF</span>
            </button>
            <a href="settings.php?tab=donations#appealSection" target="_blank" style="background:#fef3c7;border:1.5px solid #fde68a;color:#92400e;padding:8px 12px;border-radius:8px;font-size:13px;text-decoration:none;font-weight:700;display:inline-flex;align-items:center;gap:6px;">
                <span>✏️ اپیل تبدیل کریں</span>
            </a>
            <a href="donors.php" style="background:#e2e8f0;color:#1e293b;padding:8px 14px;border-radius:8px;font-size:13px;text-decoration:none;font-weight:700;">
                ✕ واپس جائیں
            </a>
        </div>
    </div>
    <!-- Quick Theme & Format Selector -->
    <div style="display:flex;align-items:center;justify-content:space-between;gap:14px;border-top:1px solid #f1f5f9;padding-top:10px;flex-wrap:wrap;">
        <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
            <span style="font-size:12px;font-weight:700;color:#64748b;">🎨 رنگ / تھیم:</span>
            <button type="button" class="theme-pill active" onclick="setPosterTheme('', this)">🟢 اسلامی سبز</button>
            <button type="button" class="theme-pill" onclick="setPosterTheme('blue', this)">🔵 رائل بلیو</button>
            <button type="button" class="theme-pill" onclick="setPosterTheme('maroon', this)">🔴 میرون</button>
            <button type="button" class="theme-pill" onclick="setPosterTheme('slate', this)">⚫ سلیٹ و گولڈ</button>
        </div>
        <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
            <span style="font-size:12px;font-weight:700;color:#64748b;">📐 سائز / فارمیٹ:</span>
            <button type="button" class="format-pill active" onclick="setPosterFormat('a4', this)">📄 A4 فل پرنٹ</button>
            <button type="button" class="format-pill" onclick="setPosterFormat('status', this)">📱 واٹس ایپ اسٹیٹس (9:16)</button>
            <button type="button" class="format-pill" onclick="setPosterFormat('square', this)">🖼️ سوشل میڈیا (1:1)</button>
        </div>
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
        <div class="appeal-banner <?= $hasProjectImage ? 'has-photo' : '' ?>">
            <div class="<?= $hasProjectImage ? 'appeal-flex' : '' ?>">
                <div class="<?= $hasProjectImage ? 'appeal-text-side' : '' ?>">
                    <h2><?=e($appealTitle)?></h2>
                    <p><?=e($appealText)?></p>
                    <?php if (!empty($projectLocation)): ?>
                        <div class="appeal-location-pill">
                            <span>📍 پروجیکٹ کا مقام / لوکیشن:</span>
                            <span><?=e($projectLocation)?></span>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($hasProjectImage): ?>
                    <div class="appeal-photo-side">
                        <div class="appeal-photo-card">
                            <img src="<?=e($projectImage)?>?v=<?=filemtime(__DIR__ . '/' . $projectImage)?>" alt="Project Site Photo" class="appeal-photo-img">
                            <span class="appeal-photo-badge">📸 سائٹ / پیش رفت</span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
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

const posterShareData = {
    orgName: <?= json_encode($orgName, JSON_UNESCAPED_UNICODE) ?>,
    appealTitle: <?= json_encode($appealTitle, JSON_UNESCAPED_UNICODE) ?>,
    appealText: <?= json_encode($appealText, JSON_UNESCAPED_UNICODE) ?>,
    projectLocation: <?= json_encode($projectLocation, JSON_UNESCAPED_UNICODE) ?>,
    bankName: <?= json_encode($bankName, JSON_UNESCAPED_UNICODE) ?>,
    bankTitle: <?= json_encode($bankTitle, JSON_UNESCAPED_UNICODE) ?>,
    bankAccount: <?= json_encode($bankAccount, JSON_UNESCAPED_UNICODE) ?>,
    bankIban: <?= json_encode($bankIban, JSON_UNESCAPED_UNICODE) ?>,
    easypaisa: <?= json_encode($easypaisaDetails, JSON_UNESCAPED_UNICODE) ?>,
    phone: <?= json_encode($orgPhone, JSON_UNESCAPED_UNICODE) ?>,
    portalUrl: <?= json_encode($portalUrl, JSON_UNESCAPED_UNICODE) ?>
};

function setPosterTheme(themeName, btn) {
    const poster = document.querySelector('.poster-canvas');
    if (!poster) return;
    poster.classList.remove('theme-blue', 'theme-maroon', 'theme-slate');
    if (themeName) {
        poster.classList.add('theme-' + themeName);
    }
    document.querySelectorAll('.theme-pill').forEach(el => el.classList.remove('active'));
    if (btn) btn.classList.add('active');
}

function setPosterFormat(formatName, btn) {
    const poster = document.querySelector('.poster-canvas');
    if (!poster) return;
    poster.classList.remove('format-status', 'format-square');
    if (formatName !== 'a4') {
        poster.classList.add('format-' + formatName);
    }
    document.querySelectorAll('.format-pill').forEach(el => el.classList.remove('active'));
    if (btn) btn.classList.add('active');
}

function copyTextToClipboard(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        return navigator.clipboard.writeText(text).catch(function() {
            return fallbackCopyText(text);
        });
    }
    return fallbackCopyText(text);
}

function fallbackCopyText(text) {
    const textArea = document.createElement("textarea");
    textArea.value = text;
    textArea.style.position = "fixed";
    textArea.style.left = "-9999px";
    textArea.style.top = "0";
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    let successful = false;
    try {
        successful = document.execCommand('copy');
    } catch (err) {
        successful = false;
    }
    document.body.removeChild(textArea);
    return Promise.resolve(successful);
}

function openWhatsAppUrl(msg) {
    const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
    const waUrl = 'https://api.whatsapp.com/send?text=' + encodeURIComponent(msg);
    if (isMobile) {
        window.location.href = waUrl;
    } else {
        window.open(waUrl, '_blank');
    }
}

async function sharePosterWhatsApp() {
    const btn = document.getElementById('btnShareWhatsApp');
    const donateUrl = posterShareData.portalUrl;

    // 1. Automatically copy ONLY the clean donation link to user's clipboard
    copyTextToClipboard(donateUrl);

    showPosterToast('📋 ڈونیشن لنک کاپی ہو گیا! واٹس ایپ میں تصویر کے نیچے کیپشن میں صرف Paste کر دیں۔', true);
    if (btn) {
        btn.disabled = true;
        btn.style.opacity = '0.7';
    }

    const poster = document.querySelector('.poster-canvas');
    if (!poster) {
        openWhatsAppUrl(donateUrl);
        if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
        return;
    }

    try {
        if (typeof html2canvas === 'function') {
            const canvas = await html2canvas(poster, {
                scale: 2,
                useCORS: true,
                allowTaint: true,
                backgroundColor: '#ffffff'
            });

            const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
            if (blob) {
                const file = new File([blob], 'donation-poster.png', { type: 'image/png' });

                // Try native file sharing (Android Chrome, iOS Safari)
                if (navigator.canShare && navigator.canShare({ files: [file] })) {
                    try {
                        await navigator.share({
                            files: [file],
                            title: posterShareData.appealTitle,
                            text: donateUrl
                        });
                        showPosterToast('✓ تصویر واٹس ایپ کے لیے کھل گئی! کیپشن میں لنک پیسٹ کر کے سینڈ کریں۔', true);
                        if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
                        return;
                    } catch (shareErr) {
                        if (shareErr.name === 'AbortError') {
                            hidePosterToast();
                            if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
                            return;
                        }
                    }
                }

                // Desktop fallback: download the crisp image file automatically & open WhatsApp with link
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = 'donation-poster-' + Date.now() + '.png';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
            }
        }
    } catch (err) {
        console.warn('Canvas render error:', err);
    }

    showPosterToast('پوسٹر تصویر محفوظ ہو گئی۔ واٹس ایپ اوپن ہو رہا ہے...', true);
    openWhatsAppUrl(donateUrl);
    if (btn) {
        btn.disabled = false;
        btn.style.opacity = '1';
    }
}

async function downloadPosterImage() {
    const poster = document.querySelector('.poster-canvas');
    const btn = document.getElementById('btnDownloadImage');
    if (!poster) return;

    showPosterToast('ہائی ریزولیوشن تصویر بن رہی ہے...', false);
    if (btn) {
        btn.disabled = true;
        btn.style.opacity = '0.7';
    }

    try {
        if (typeof html2canvas === 'function') {
            const canvas = await html2canvas(poster, {
                scale: 2,
                useCORS: true,
                allowTaint: true,
                backgroundColor: '#ffffff'
            });

            const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
            if (blob) {
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = 'donation-poster-' + Date.now() + '.png';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                showPosterToast('✓ پوسٹر تصویر کامیابی سے محفوظ ہو گئی!', true);
            }
        } else {
            alert('تصویر بنانے والی لائبریری لوڈ نہیں ہو سکی۔');
        }
    } catch (err) {
        alert('تصویر بنانے میں خرابی آئی: ' + err.message);
    }

    if (btn) {
        btn.disabled = false;
        btn.style.opacity = '1';
    }
}

function showPosterToast(text, isSuccess) {
    let toast = document.getElementById('posterToast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'posterToast';
        toast.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#0f172a;color:#ffffff;padding:12px 24px;border-radius:30px;font-size:14px;font-weight:700;z-index:99999;box-shadow:0 10px 30px rgba(0,0,0,0.35);direction:rtl;display:flex;align-items:center;gap:10px;border:1px solid rgba(255,255,255,0.2);';
        document.body.appendChild(toast);
    }
    toast.innerHTML = (isSuccess ? '✅ ' : '⏳ ') + text;
    toast.style.display = 'flex';
    if (isSuccess) {
        setTimeout(function() { toast.style.display = 'none'; }, 4000);
    }
}

function hidePosterToast() {
    const toast = document.getElementById('posterToast');
    if (toast) toast.style.display = 'none';
}
</script>
</body>
</html>

