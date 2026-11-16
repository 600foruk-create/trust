<?php
/**
 * Printable Annual Giving Certificate / Appreciation Certificate (سندِ اعتراف و تشکر)
 * Enhanced Gold & Emerald edition with WhatsApp sharing, public token verification, and Lifetime Excellence mode.
 */
require __DIR__ . '/../app/bootstrap.php';

$donorId = (int)($_GET['id'] ?? 0);
if ($donorId <= 0) {
    die('Invalid donor ID');
}

$stmt = $pdo->prepare('SELECT * FROM donors WHERE id = ?');
$stmt->execute([$donorId]);
$donor = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$donor) {
    die('Donor record not found.');
}

$token = $_GET['token'] ?? '';
$expectedToken = function_exists('get_donor_certificate_public_token') ? get_donor_certificate_public_token($donorId) : '';
$isPublicView = (!empty($token) && hash_equals($expectedToken, $token));

if (!$isPublicView) {
    require_login();
    require_permission('donors.view');
}

$certType = in_array($_GET['type'] ?? '', ['appreciation', 'excellence'], true) ? $_GET['type'] : 'appreciation';
$selectedYear = (int)($_GET['year'] ?? date('Y'));
if ($selectedYear < 2020 || $selectedYear > 2035) {
    $selectedYear = (int)date('Y');
}

// Total Giving in selected Year
$stmtYear = $pdo->prepare("SELECT 
    COALESCE(SUM(total_amount), 0) AS total_year,
    COUNT(id) AS donations_count,
    MIN(voucher_date) AS first_date,
    MAX(voucher_date) AS last_date
    FROM vouchers 
    WHERE donor_id = ? 
      AND voucher_type = 'income' 
      AND status = 'posted' 
      AND voucher_date LIKE ?");
$stmtYear->execute([$donorId, $selectedYear . '%']);
$yearStats = $stmtYear->fetch(PDO::FETCH_ASSOC);
$totalDonatedYear = (float)($yearStats['total_year'] ?? 0);
$yearDonationsCount = (int)($yearStats['donations_count'] ?? 0);

// Lifetime Giving
$stmtLife = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) AS lifetime_total, COUNT(id) AS lifetime_count FROM vouchers WHERE donor_id = ? AND voucher_type = 'income' AND status = 'posted'");
$stmtLife->execute([$donorId]);
$lifeStats = $stmtLife->fetch(PDO::FETCH_ASSOC);
$lifetimeTotal = (float)($lifeStats['lifetime_total'] ?? 0);
$lifetimeCount = (int)($lifeStats['lifetime_count'] ?? 0);

$orgName = function_exists('get_setting') ? get_setting('madrasa_name', 'Trust Accounts') : 'Trust Accounts';
$orgSubtitle = function_exists('get_setting') ? get_setting('org_subtitle', 'Financial Accounting & Institutional ERP') : 'Financial Accounting & Institutional ERP';
$orgPhone = function_exists('get_setting') ? get_setting('madrasa_phone', '+92 331 7240256') : '+92 331 7240256';
$orgAddress = function_exists('get_setting') ? get_setting('madrasa_address', '') : '';
$orgLogo = function_exists('get_org_logo_url') ? get_org_logo_url() : null;

$signTitle1 = get_setting('print_sign_title_1', 'تیار کنندہ / خزانچی (Prepared By)');
$signTitle3 = get_setting('print_sign_title_3', 'مہتمم / صدر / مجاز اتھارٹی (Director)');

$certNo = ($certType === 'excellence' ? 'SFX-GOLD-' : 'SFX-CERT-') . $selectedYear . '-' . str_pad((string)$donor['id'], 4, '0', STR_PAD_LEFT);

$baseUrl = function_exists('get_app_base_url') ? get_app_base_url() : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
$certPublicUrl = "{$baseUrl}/print_donor_certificate.php?id={$donorId}&year={$selectedYear}&type={$certType}" . (!empty($expectedToken) ? "&token={$expectedToken}" : '');

$dPhone = function_exists('normalize_pakistan_phone') ? normalize_pakistan_phone($donor['phone'] ?? '') : preg_replace('/\D+/', '', $donor['phone'] ?? '');
$donorTitle = $certType === 'excellence' ? "لائف ٹائم ایکسیلنس سند و اعزاز" : "سندِ اعتراف و تشکر (سال {$selectedYear})";

$waMsg = "السلام علیکم و رحمۃ اللہ و برکاتہ!\n"
       . "محترم جناب *" . $donor['donor_name'] . "* صاحب!\n"
       . "ادارہ *" . $orgName . "* کی جانب سے آپ کے مخلصانہ مالی تعاون اور دینی و فلاحی خدمات کے اعتراف میں باوقار *" . $donorTitle . "* پیشِ خدمت ہے:\n\n"
       . "📜 *آن لائن سند دیکھنے و پی ڈی ایف محفوظ کرنے کے لیے لنک کھولیں:*\n"
       . $certPublicUrl . "\n\n"
       . "اللہ تعالیٰ آپ کے اس صدقہ و خیرات کو شرفِ قبولیت بخشے اور آپ کے رزق، جان، مال اور اہل و عیال میں بے شمار برکتیں و عافیت عطا فرمائے۔ آمین۔\n\n"
       . "جزاکم اللہ خیراً و احسن الجزاء\n"
       . "*" . $orgName . "*";
if ($orgPhone) {
    $waMsg .= "\nرابطہ: " . $orgPhone;
}

$waShareUrl = !empty($dPhone)
    ? 'https://api.whatsapp.com/send?phone=' . urlencode($dPhone) . '&text=' . urlencode($waMsg)
    : 'https://api.whatsapp.com/send?text=' . urlencode($waMsg);
?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?=e($donorTitle)?> &bull; <?=e($donor['donor_name'])?> &bull; <?=e($orgName)?></title>
    <link rel="stylesheet" href="assets/style.css?v=<?=css_ver()?>">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Amiri:ital,wght@0,400;0,700;1,700&family=Noto+Nastaliq+Urdu:wght@400;700&family=Cinzel:wght@600;700;800;900&family=Inter:wght@400;600;700;800&display=swap">
    <link rel="stylesheet" href="https://fonts.cdnfonts.com/css/jameel-noori-nastaleeq">
    <style>
        @page {
            size: A4 landscape;
            margin: 6mm;
        }
        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        body {
            font-family: 'Noto Nastaliq Urdu', 'Jameel Noori Nastaleeq', 'Amiri', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #0f172a;
            margin: 0;
            padding: 16px;
            color: #0f172a;
            direction: rtl;
        }

        .cert-outer-wrapper {
            max-width: 1060px;
            margin: 0 auto;
        }

        /* Top Action Bar (Hidden when printing) */
        .no-print-bar {
            background: #1e293b;
            padding: 12px 20px;
            border-radius: 12px;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }

        /* Certificate Main Sheet */
        .cert-sheet {
            background: #fffdfa;
            border-radius: 8px;
            position: relative;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.6);
            padding: 34px 44px 26px;
            background-image: 
                radial-gradient(circle at 50% 50%, rgba(217, 119, 6, 0.03) 0%, transparent 70%),
                linear-gradient(to right, rgba(245, 158, 11, 0.02) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(245, 158, 11, 0.02) 1px, transparent 1px);
            background-size: 100% 100%, 30px 30px, 30px 30px;
        }

        /* Border Styles depending on theme */
        .cert-sheet.theme-appreciation {
            border: 14px solid #064e3b;
            outline: 4px solid #d97706;
            outline-offset: -7px;
        }
        .cert-sheet.theme-excellence {
            border: 14px solid #0f172a;
            outline: 4px solid #f59e0b;
            outline-offset: -7px;
        }

        .cert-sheet::before {
            content: "";
            position: absolute;
            inset: 5px;
            border: 1.5px dashed #d97706;
            pointer-events: none;
        }

        /* Ornate Decorative Corners */
        .corner {
            position: absolute;
            width: 36px;
            height: 36px;
            border-color: #d97706;
            border-style: solid;
            pointer-events: none;
        }
        .corner-tl { top: 10px; right: 10px; border-width: 4px 4px 0 0; }
        .corner-tr { top: 10px; left: 10px; border-width: 4px 0 0 4px; }
        .corner-bl { bottom: 10px; right: 10px; border-width: 0 4px 4px 0; }
        .corner-br { bottom: 10px; left: 10px; border-width: 0 0 4px 4px; }

        .cert-logo {
            max-height: 64px;
            max-width: 110px;
            object-fit: contain;
        }

        /* Medallion Golden Badge */
        .cert-badge-gold {
            width: 82px;
            height: 82px;
            background: radial-gradient(circle, #fef08a 0%, #d97706 70%, #92400e 100%);
            border-radius: 50%;
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            box-shadow: 0 6px 14px rgba(217, 119, 6, 0.4), inset 0 2px 4px rgba(255, 255, 255, 0.6);
            border: 3px double #ffffff;
            color: #78350f;
            font-weight: 800;
            text-align: center;
            line-height: 1.1;
        }

        .donor-name-box {
            display: inline-block;
            padding: 6px 34px 4px;
            border-bottom: 2.5px solid #d97706;
            min-width: 360px;
        }

        .cert-prose {
            font-size: 14.5px;
            line-height: 2.1;
            color: #1e293b;
            text-align: center;
            margin: 16px auto;
            max-width: 900px;
        }

        .amount-badge {
            font-family: 'Inter', sans-serif;
            font-size: 18px;
            font-weight: 800;
            color: #065f46;
            background: #ecfdf5;
            padding: 2px 10px;
            border-radius: 6px;
            border: 1px solid #a7f3d0;
            direction: ltr;
            display: inline-block;
        }

        /* Mobile Responsive Adjustments */
        @media (max-width: 768px) {
            body {
                padding: 8px 6px;
                background: #f1f5f9;
            }
            .no-print-bar {
                flex-direction: column;
                align-items: stretch;
                padding: 12px 14px;
            }
            .no-print-bar .action-group {
                flex-direction: column;
                width: 100%;
                gap: 8px !important;
            }
            .no-print-bar .btn, .no-print-bar select {
                width: 100%;
                justify-content: center;
                box-sizing: border-box;
            }
            .cert-sheet {
                padding: 24px 14px 20px;
                border-width: 8px !important;
                outline-width: 2px !important;
                outline-offset: -4px !important;
            }
            .corner {
                width: 18px;
                height: 18px;
            }
            .corner-tl { top: 6px; right: 6px; }
            .corner-tr { top: 6px; left: 6px; }
            .corner-bl { bottom: 6px; right: 6px; }
            .corner-br { bottom: 6px; left: 6px; }
            .donor-name-box {
                min-width: unset;
                width: 100%;
                padding: 4px 8px;
            }
            .donor-name-text {
                font-size: 21px !important;
            }
            .cert-stats-grid {
                grid-template-columns: 1fr 1fr !important;
                gap: 8px !important;
            }
            .cert-signs-row {
                flex-direction: column !important;
                align-items: center !important;
                gap: 16px !important;
            }
            .cert-footer-meta {
                flex-direction: column !important;
                text-align: center !important;
                gap: 4px !important;
            }
        }

        @media print {
            body {
                background: #ffffff !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .no-print {
                display: none !important;
            }
            .cert-outer-wrapper {
                max-width: 100% !important;
                width: 100% !important;
                margin: 0 !important;
            }
            .cert-sheet {
                box-shadow: none !important;
                margin: 0 !important;
                padding: 24px 34px 18px !important;
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>

<div class="cert-outer-wrapper">

    <!-- Top Action Bar (Hidden when printing) -->
    <div class="no-print-bar no-print">
        <div style="display:flex;align-items:center;gap:10px;">
            <span style="font-size:24px;"><?=$certType === 'excellence' ? '🎖️' : '📜'?></span>
            <div>
                <b style="color:#ffffff;font-size:14px;"><?=e($donor['donor_name'])?> &bull; <?=e($donorTitle)?></b>
                <span style="font-size:11px;color:#94a3b8;display:block;">
                    رقم: <strong><?=e($certNo)?></strong> &bull; حیات بھر کا کل تعاون: <strong>Rs. <?=number_format($lifetimeTotal)?></strong>
                </span>
            </div>
        </div>

        <div class="action-group" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            
            <!-- Type Switcher -->
            <form method="get" style="display:inline-flex;align-items:center;gap:6px;margin:0;">
                <input type="hidden" name="id" value="<?=$donorId?>">
                <?php if (!empty($token)): ?>
                    <input type="hidden" name="token" value="<?=e($token)?>">
                <?php endif; ?>
                <select name="type" onchange="this.form.submit()" style="padding:6px 10px;border-radius:6px;border:1px solid #3b82f6;background:#ffffff;font-weight:700;font-size:12px;color:#0f172a;cursor:pointer;">
                    <option value="appreciation" <?=$certType === 'appreciation' ? 'selected' : ''?>>📜 سندِ تشکر (Annual)</option>
                    <option value="excellence" <?=$certType === 'excellence' ? 'selected' : ''?>>🎖️ لائف ٹائم ایکسیلنس (Gold)</option>
                </select>

                <!-- Year Selector -->
                <select name="year" onchange="this.form.submit()" style="padding:6px 8px;border-radius:6px;border:1px solid #94a3b8;background:#ffffff;font-size:12px;font-weight:600;color:#0f172a;cursor:pointer;">
                    <?php for ($y = (int)date('Y'); $y >= 2022; $y--): ?>
                        <option value="<?=$y?>" <?=$y === $selectedYear ? 'selected' : ''?>><?=$y?></option>
                    <?php endfor; ?>
                </select>
            </form>

            <!-- WhatsApp Share Button -->
            <a href="<?=e($waShareUrl)?>" target="_blank" class="btn" style="background:#25D366;color:#ffffff;border:none;font-weight:700;padding:8px 14px;border-radius:6px;text-decoration:none;display:inline-flex;align-items:center;gap:6px;font-size:12.5px;box-shadow:0 2px 4px rgba(0,0,0,0.25);">
                💬 واٹس ایپ پر بھیجیں
            </a>

            <!-- Print / PDF Button -->
            <button type="button" onclick="window.print()" class="btn" style="background:<?=$certType === 'excellence' ? '#d97706' : '#059669'?>;color:#ffffff;border:none;font-weight:700;padding:8px 14px;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-size:12.5px;box-shadow:0 2px 4px rgba(0,0,0,0.25);">
                🖨️ پرنٹ / PDF محفوظ کریں
            </button>

            <?php if (!$isPublicView): ?>
                <a href="donors.php" class="btn secondary sm" style="text-decoration:none;padding:8px 12px;color:#cbd5e1;font-size:12px;">
                    ⬅️ Donors List
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- The Printable Certificate Sheet -->
    <div class="cert-sheet theme-<?=$certType?>">
        <div class="corner corner-tl"></div>
        <div class="corner corner-tr"></div>
        <div class="corner corner-bl"></div>
        <div class="corner corner-br"></div>

        <!-- Bismillah & Institution Crest Header -->
        <div style="text-align:center;margin-bottom:8px;">
            <div style="font-family:'Amiri', serif;font-size:23px;color:<?=$certType === 'excellence' ? '#1e3a8a' : '#065f46'?>;font-weight:700;margin-bottom:2px;">
                بِسْمِ اللَّهِ الرَّحْمَٰنِ الرَّحِيمِ
            </div>
            
            <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1.5px solid #e2e8f0;padding-bottom:10px;margin-bottom:12px;">
                <!-- Certificate Meta Left -->
                <div style="text-align:right;min-width:130px;">
                    <div style="font-size:11px;color:#64748b;font-family:monospace;letter-spacing:0.5px;font-weight:700;"><?=e($certNo)?></div>
                    <div style="font-size:11.5px;color:#0f172a;margin-top:2px;">تاریخِ اجراء: <strong><?=date('d-M-Y')?></strong></div>
                </div>

                <!-- Center: Organization Title -->
                <div style="text-align:center;">
                    <h2 style="margin:0;font-size:22px;color:<?=$certType === 'excellence' ? '#0f172a' : '#065f46'?>;font-weight:900;">
                        <?=e($orgName)?>
                    </h2>
                    <div style="font-size:12px;color:#64748b;margin-top:2px;font-weight:600;">
                        <?=e($orgSubtitle)?>
                    </div>
                </div>

                <!-- Logo / Monogram Right -->
                <div style="text-align:left;min-width:130px;">
                    <?php if ($orgLogo): ?>
                        <img src="<?=e($orgLogo)?>" alt="Logo" class="cert-logo">
                    <?php else: ?>
                        <div style="display:inline-flex;width:48px;height:48px;border-radius:8px;background:<?=$certType === 'excellence' ? '#0f172a' : '#065f46'?>;color:#ffffff;align-items:center;justify-content:center;font-weight:900;font-size:18px;">
                            <?=e(mb_substr($orgName, 0, 1))?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Certificate Title Banner -->
        <div style="text-align:center;margin-bottom:14px;">
            <?php if ($certType === 'excellence'): ?>
                <div style="font-family:'Cinzel', serif;letter-spacing:2px;font-size:11.5px;color:#78350f;font-weight:800;text-transform:uppercase;">
                    SoftifyX ERP &bull; Lifetime Excellence &amp; Patron Honors
                </div>
                <h1 style="font-family:'Cinzel', serif;font-size:25px;color:#0f172a;letter-spacing:1.5px;margin:2px 0 0;font-weight:900;text-transform:uppercase;">
                    CERTIFICATE OF LIFETIME EXCELLENCE
                </h1>
                <div style="font-size:16.5px;font-weight:700;color:#b45309;margin-top:2px;">
                    شہادتِ فضیلت و باوقار توصیفی سند برائے لائف ٹائم سرپرست و ڈونر
                </div>
            <?php else: ?>
                <div style="background:#047857;color:#ffffff;padding:5px 28px;border-radius:24px;font-size:20px;font-weight:800;display:inline-block;box-shadow:0 3px 10px rgba(4,120,87,0.25);">
                    سَنَدِ اعْتِرَافِ خِدْمَات وَ تَشَکُّر
                </div>
                <div style="font-size:12.5px;color:#065f46;font-weight:700;margin-top:5px;">
                    «مَنْ لَا يَشْكُرُ النَّاسَ لَا يَشْكُرُ اللَّهَ» (صحیح ترمذی)
                </div>
            <?php endif; ?>
        </div>

        <!-- Conferred Upon / Donor Section -->
        <div style="text-align:center;margin-bottom:12px;">
            <div style="font-size:11.5px;color:#64748b;text-transform:uppercase;letter-spacing:1px;font-weight:600;">
                THIS PRESTIGIOUS HONOR IS CONFERRED WITH GRATITUDE UPON
            </div>
            <div style="font-size:11.5px;color:#94a3b8;margin-bottom:4px;">
                یہ اعزازی سندِ شرف و تشکر نہایت احترام اور دعاؤں کے ساتھ پیش کی جاتی ہے بخدمت:
            </div>

            <div class="donor-name-box">
                <div class="donor-name-text" style="font-size:26px;font-weight:900;color:#0f172a;line-height:1.4;">
                    محترم جناب <?=e($donor['donor_name'])?> صاحب
                </div>
                <?php if (!empty($donor['phone']) || !empty($donor['address'])): ?>
                    <div style="font-size:12px;color:#64748b;margin-top:2px;">
                        <?=e(implode(' &bull; ', array_filter([$donor['phone'] ?? '', $donor['address'] ?? ''])))?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Certificate Citation Text -->
        <div class="cert-prose">
            <?php if ($certType === 'excellence'): ?>
                انتظامیہ <strong><?=e($orgName)?></strong> کی جانب سے آپ کے دائمی اخلاص، دینی و فلاحی خدمات، یتامیٰ و مستحقین کی مسلسل سرپرستی اور بے لوث قربانیوں کو خراجِ تحسین پیش کرتے ہوئے
                <strong>لائف ٹائم ایکسیلنس و سرپرستِ اعلیٰ</strong> کا باوقار اعزاز تفویض کیا جاتا ہے۔ آپ نے ادارہ ہذا کے قیام و استحکام میں اب تک کل
                <span class="amount-badge">Rs. <?=number_format($lifetimeTotal)?></span>
                کا زریں مالی تعاون فرما کر صدقۂ جاریہ کی ابدی سعادت حاصل فرمائی۔ ادارہ آپ کے رزق، جان، مال اور نسلوں میں برکت کے لیے دعا گو ہے۔
            <?php else: ?>
                انتظامیہ <strong><?=e($orgName)?></strong> آپ کے شاندار مالی تعاون اور ہمدردی پر تہہ دل سے شکر گزار ہے۔ آپ نے سال <strong><?=$selectedYear?></strong> کے دوران دینی و فلاحی منصوبوں اور زیرِ کفالت طلباء کے لیے کل
                <span class="amount-badge">Rs. <?=number_format($totalDonatedYear)?></span>
                کا پرخلوص عطیہ فرما کر نیکی اور ایثار کی اعلیٰ مثال قائم کی۔ ادارہ آپ کے کاروبار و اہل خانہ میں دائمی عافیت و برکت کا متمنی ہے۔
            <?php endif; ?>
        </div>

        <!-- Giving Stats 4 Badges Grid -->
        <div class="cert-stats-grid" style="display:grid;grid-template-columns:repeat(4, 1fr);gap:10px;max-width:880px;margin:14px auto 18px;font-size:11.5px;">
            <div style="background:#ffffff;border:1px solid #cbd5e1;border-radius:6px;padding:7px 10px;text-align:center;">
                <span style="color:#64748b;display:block;font-size:10px;">سال <?=$selectedYear?> کا تعاون</span>
                <b style="color:#065f46;font-size:15px;direction:ltr;display:inline-block;">Rs. <?=number_format($totalDonatedYear)?></b>
                <span style="color:#15803d;display:block;font-size:10px;font-weight:700;">★ <?=$yearDonationsCount?> رسیدیں</span>
            </div>

            <div style="background:#ffffff;border:1px solid #cbd5e1;border-radius:6px;padding:7px 10px;text-align:center;">
                <span style="color:#64748b;display:block;font-size:10px;">حیات بھر کا کل تعاون</span>
                <b style="color:#1e40af;font-size:15px;direction:ltr;display:inline-block;">Rs. <?=number_format($lifetimeTotal)?></b>
                <span style="color:#2563eb;display:block;font-size:10px;font-weight:700;"><?=$lifetimeCount?> کل رسیدات</span>
            </div>

            <div style="background:#ffffff;border:1px solid #cbd5e1;border-radius:6px;padding:7px 10px;text-align:center;">
                <span style="color:#64748b;display:block;font-size:10px;">تعاون کی نوعیت</span>
                <b style="color:#0f172a;font-size:13.5px;"><?=e($donor['fund_name'] ?? 'عمومی و زکوٰۃ فنڈ')?></b>
                <span style="color:#15803d;display:block;font-size:10px;font-weight:700;">100% شفاف ریکارڈ</span>
            </div>

            <div style="background:#ffffff;border:1px solid #cbd5e1;border-radius:6px;padding:7px 10px;text-align:center;">
                <span style="color:#64748b;display:block;font-size:10px;">اعزاز کا درجہ</span>
                <b style="color:#d97706;font-size:14px;"><?=$certType === 'excellence' ? 'لائف ٹائم سرپرست' : 'معزز ڈونر و محسن'?></b>
                <span style="color:#d97706;display:block;font-size:10px;font-weight:700;">★ شرفِ قبولیت</span>
            </div>
        </div>

        <!-- Seal & Signatures Row -->
        <div class="cert-signs-row" style="display:flex;justify-content:space-between;align-items:flex-end;margin-top:16px;padding-top:12px;border-top:1.5px solid #e2e8f0;flex-wrap:wrap;gap:14px;">
            
            <!-- Left Signature: Cashier / Prepared By -->
            <div style="text-align:center;min-width:180px;">
                <div style="font-weight:700;color:#0f172a;font-size:13px;border-bottom:1.5px solid #0f172a;padding-bottom:3px;display:inline-block;min-width:160px;">
                    <?=e($signTitle1)?>
                </div>
                <div style="font-size:10.5px;color:#64748b;margin-top:3px;">
                    محاسب / تصدیق کنندہ
                </div>
            </div>

            <!-- Center: Gold Official Embossed Medal -->
            <div style="text-align:center;">
                <div class="cert-badge-gold">
                    <span style="font-size:16px;">🎖️</span>
                    <span style="font-size:8px;text-transform:uppercase;letter-spacing:0.5px;">Official</span>
                    <span style="font-size:11px;font-weight:900;"><?=$certType === 'excellence' ? 'GOLD' : 'SEAL'?></span>
                    <span style="font-size:7.5px;">EXCELLENCE</span>
                </div>
                <div style="font-size:9.5px;color:#64748b;margin-top:3px;font-family:monospace;letter-spacing:1px;">
                    AUTHENTICATED &bull; <?=date('Y')?>
                </div>
            </div>

            <!-- Right Signature: Mohtamim / Director -->
            <div style="text-align:center;min-width:180px;">
                <div style="font-weight:700;color:<?=$certType === 'excellence' ? '#1e3a8a' : '#065f46'?>;font-size:13px;border-bottom:1.5px solid #0f172a;padding-bottom:3px;display:inline-block;min-width:160px;">
                    <?=e($signTitle3)?>
                </div>
                <div style="font-size:10.5px;color:#64748b;margin-top:3px;">
                    صدر / مہتمم ادارہ
                </div>
            </div>

        </div>

        <!-- Verification Footnote -->
        <div class="cert-footer-meta" style="display:flex;justify-content:space-between;align-items:center;margin-top:14px;font-size:10px;color:#94a3b8;border-top:1px dashed #cbd5e1;padding-top:6px;">
            <div>
                Certificate ID: <strong style="font-family:monospace;color:#475569;"><?=$certNo?></strong>
            </div>
            <div>
                ادارۂ ہذا: <span style="color:#475569;font-weight:600;"><?=e($orgName)?></span>
                <?php if ($orgPhone): ?> (رابطہ: <?=e($orgPhone)?>)<?php endif; ?>
            </div>
            <div>
                Verification: <strong style="color:#15803d;">Cryptographically Sealed &bull; SoftifyX ERP</strong>
            </div>
        </div>

    </div>

</div>

</body>
</html>
