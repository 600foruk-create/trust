<?php
/**
 * Printable Annual Giving Certificate / Appreciation Certificate (سندِ اعتراف و تشکر)
 * Beautiful certificate of recognition for donors with yearly giving totals, Hadith, and official seal lines.
 */
require __DIR__ . '/../app/bootstrap.php';
require_login();
require_permission('donors.view');

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

$selectedYear = (int)($_GET['year'] ?? date('Y'));
if ($selectedYear < 2020 || $selectedYear > 2035) {
    $selectedYear = (int)date('Y');
}

// Total Giving in this Year
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

// Lifetime Giving
$stmtLife = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) AS lifetime_total, COUNT(id) AS lifetime_count FROM vouchers WHERE donor_id = ? AND voucher_type = 'income' AND status = 'posted'");
$stmtLife->execute([$donorId]);
$lifeStats = $stmtLife->fetch(PDO::FETCH_ASSOC);
$lifetimeTotal = (float)($lifeStats['lifetime_total'] ?? 0);

$orgName = function_exists('get_setting') ? get_setting('madrasa_name', 'Mangalchai Welfare Organization') : 'Mangalchai Welfare Organization';
$orgSubtitle = function_exists('get_setting') ? get_setting('org_subtitle', 'Financial Accounting & Welfare Trust') : 'Financial Accounting & Welfare Trust';
$orgPhone = function_exists('get_setting') ? get_setting('madrasa_phone', '+92 331 7240256') : '+92 331 7240256';
$orgAddress = function_exists('get_setting') ? get_setting('madrasa_address', '') : '';
$orgLogo = function_exists('get_org_logo_url') ? get_org_logo_url() : null;

$signTitle1 = get_setting('print_sign_title_1', 'تیار کنندہ / کیشیئر (Prepared By)');
$signTitle3 = get_setting('print_sign_title_3', 'مہتمم / صدر / مجاز اتھارٹی (Director)');

$certNo = 'CERT-' . $selectedYear . '-' . str_pad((string)$donor['id'], 4, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate of Appreciation - <?=e($donor['donor_name'])?></title>
    <style>
        @page {
            size: A4 landscape;
            margin: 8mm;
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
            text-align: center;
        }
        .cert-container {
            max-width: 1040px;
            margin: 0 auto;
            background: #ffffff;
            border: 10px solid #065f46;
            border-radius: 16px;
            padding: 30px 45px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.12);
            position: relative;
        }
        .cert-inner-frame {
            border: 2px solid #d97706;
            padding: 24px 30px;
            border-radius: 10px;
            position: relative;
            background: radial-gradient(circle at center, #ffffff 0%, #fafafa 100%);
        }
        .cert-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 14px;
            margin-bottom: 20px;
        }
        .cert-logo {
            max-height: 70px;
            max-width: 120px;
            object-fit: contain;
        }
        .cert-title-badge {
            background: #047857;
            color: #ffffff;
            padding: 6px 28px;
            border-radius: 30px;
            font-size: 22px;
            font-weight: 800;
            letter-spacing: 0.5px;
            display: inline-block;
            margin: 10px 0 6px;
            box-shadow: 0 4px 12px rgba(4, 120, 87, 0.2);
        }
        .cert-hadith {
            font-size: 14px;
            color: #065f46;
            font-weight: 700;
            margin: 8px 0;
        }
        .cert-body {
            margin: 22px 0;
            font-size: 16px;
            line-height: 2.2;
            color: #1e293b;
            text-align: justify;
            text-align-last: center;
        }
        .donor-highlight {
            font-size: 26px;
            font-weight: 900;
            color: #065f46;
            border-bottom: 2px dashed #059669;
            padding: 0 14px;
            display: inline-block;
        }
        .amount-highlight {
            font-size: 20px;
            font-weight: 800;
            color: #b45309;
            background: #fffbeb;
            padding: 2px 10px;
            border-radius: 6px;
            border: 1px solid #fde68a;
            direction: ltr;
            display: inline-block;
        }
        .stats-summary-box {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin: 20px 0;
        }
        .stat-pill {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 8px 18px;
            font-size: 13.5px;
        }
        .cert-footer-signs {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-top: 40px;
            padding: 0 30px;
        }
        .sign-col {
            text-align: center;
            width: 200px;
        }
        .sign-line {
            border-top: 1.5px dashed #64748b;
            margin-bottom: 6px;
        }
        .sign-title {
            font-size: 12.5px;
            font-weight: 700;
            color: #334155;
        }
        .seal-circle {
            width: 90px;
            height: 90px;
            border: 3px double #065f46;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #065f46;
            font-weight: 800;
            font-size: 11px;
            margin: 0 auto;
            text-transform: uppercase;
            transform: rotate(-10deg);
        }
        .no-print-bar {
            max-width: 1040px;
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
            padding: 8px 18px;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 700;
            cursor: pointer;
        }
        @media print {
            body {
                background: #ffffff;
                padding: 0;
            }
            .no-print-bar {
                display: none !important;
            }
            .cert-container {
                border-width: 8px;
                box-shadow: none;
                margin: 0;
                padding: 20px;
                width: 100%;
                max-width: 100%;
            }
        }
    </style>
</head>
<body>

<div class="no-print-bar">
    <div style="text-align:right;">
        <strong style="font-size:15px;color:#0f172a;">📜 سندِ اعتراف و تشکر (Certificate of Appreciation)</strong>
        <div style="font-size:12px;color:#64748b;">
            معاون: <b><?=e($donor['donor_name'])?></b> &bull; سال: <b><?=$selectedYear?></b>
        </div>
    </div>
    <div style="display:flex;gap:10px;align-items:center;">
        <form method="get" style="display:inline-flex;gap:6px;align-items:center;">
            <input type="hidden" name="id" value="<?=$donorId?>">
            <label style="font-size:12px;color:#64748b;">سال منتخب کریں:</label>
            <select name="year" onchange="this.form.submit()" style="padding:5px 8px;border-radius:6px;border:1px solid #cbd5e1;font-size:13px;">
                <?php for ($y = (int)date('Y'); $y >= 2022; $y--): ?>
                    <option value="<?=$y?>" <?=$y === $selectedYear ? 'selected' : ''?>><?=$y?></option>
                <?php endfor; ?>
            </select>
        </form>
        <button type="button" class="print-btn" onclick="window.print()">
            🖨️ پرنٹ / PDF ڈاؤنلوڈ
        </button>
        <a href="donors.php" style="background:#e2e8f0;color:#1e293b;padding:8px 14px;border-radius:8px;font-size:13px;text-decoration:none;font-weight:700;">
            ✕ بند کریں
        </a>
    </div>
</div>

<div class="cert-container">
    <div class="cert-inner-frame">
        <!-- Certificate Header -->
        <div class="cert-header">
            <div style="text-align:right;">
                <div style="font-size:11px;color:#64748b;font-family:monospace;"><?=e($certNo)?></div>
                <div style="font-size:12px;color:#0f172a;margin-top:2px;">تاریخ اجراء: <strong><?=date('d-M-Y')?></strong></div>
            </div>
            <div>
                <h2 style="margin:0;font-size:24px;color:#065f46;font-weight:900;"><?=e($orgName)?></h2>
                <div style="font-size:13px;color:#64748b;margin-top:2px;"><?=e($orgSubtitle)?></div>
            </div>
            <div style="width:100px;text-align:left;">
                <?php if ($orgLogo): ?>
                    <img src="<?=e($orgLogo)?>" alt="Logo" class="cert-logo">
                <?php else: ?>
                    <div style="width:50px;height:50px;border-radius:8px;background:#065f46;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:20px;">
                        <?=e(mb_substr($orgName, 0, 1))?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="cert-title-badge">سَنَدِ اعْتِرَافِ خِدْمَات وَ تَشَکُّر</div>
        <div class="cert-hadith">«مَنْ لَا يَشْكُرُ النَّاسَ لَا يَشْكُرُ اللَّهَ» (صحیح ترمذی)</div>

        <!-- Certificate Prose -->
        <div class="cert-body">
            انتظامیہ <strong><?=e($orgName)?></strong> کی جانب سے یہ اعزازی سند نہایت مسرت و تشکر کے ساتھ محترم و مکرم
            <br>
            <span class="donor-highlight"><?=e($donor['donor_name'])?></span> صاحب
            <br>
            کی خدمتِ بابرکت میں پیش کی جاتی ہے۔ آپ نے سال <strong><?=$selectedYear?></strong> کے دوران ادارے کے فلاحی و تعلیمی منصوبوں، یتامیٰ و مستحق طلباء کی کفالت میں خلوصِ دل سے حصہ ڈالا اور کل
            <span class="amount-highlight">Rs. <?=number_format($totalDonatedYear)?></span>
            کا زریں مالی تعاون فرما کر صدقہ جاریہ کی سعادت حاصل فرمائی۔
            <br>
            ادارہ اور زیرِ کفالت طلباء آپ کے رزق، جان، مال، اور کاروبار میں ترقی و برکت اور عافیت کے لیے دعا گو ہیں۔
        </div>

        <!-- Giving Stats Summary -->
        <div class="stats-summary-box">
            <div class="stat-pill">
                کل عطیات برائے سال <?=$selectedYear?>: <strong style="color:#065f46;">Rs. <?=number_format($totalDonatedYear)?></strong> (<?=$yearStats['donations_count']?> رسیدیں)
            </div>
            <?php if ($lifetimeTotal > $totalDonatedYear): ?>
            <div class="stat-pill">
                حیات بھر کا کل ریکارڈ شدہ تعاون: <strong style="color:#1e40af;">Rs. <?=number_format($lifetimeTotal)?></strong>
            </div>
            <?php endif; ?>
        </div>

        <!-- Signatures & Seal -->
        <div class="cert-footer-signs">
            <div class="sign-col">
                <div class="sign-line"></div>
                <div class="sign-title"><?=e($signTitle1)?></div>
            </div>

            <div class="seal-circle">
                آفیشل مہر<br>VERIFIED
            </div>

            <div class="sign-col">
                <div class="sign-line"></div>
                <div class="sign-title"><?=e($signTitle3)?></div>
            </div>
        </div>
    </div>
</div>

</body>
</html>

