<?php
/**
 * Printable Annual Giving Ledger & Contribution Statement (سالانہ کھاتہ و تصدیق عطیات)
 * Professional statement for donors detailing all contributions, receipts, and totals for tax/audit & transparency.
 */
require __DIR__ . '/../app/bootstrap.php';
require_login();
require_permission('donors.view');

$donorId = (int)($_GET['id'] ?? 0);
if ($donorId <= 0) {
    die('Invalid donor ID');
}

$stmt = $pdo->prepare('SELECT d.*, b.full_name AS sponsored_student_name, b.category AS sponsored_student_cat, b.department_class AS sponsored_student_class 
    FROM donors d 
    LEFT JOIN beneficiaries b ON b.id = d.sponsored_beneficiary_id 
    WHERE d.id = ?');
$stmt->execute([$donorId]);
$donor = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$donor) {
    die('Donor record not found.');
}

$selectedYear = isset($_GET['year']) ? trim($_GET['year']) : date('Y');
if ($selectedYear !== 'all' && (!preg_match('/^\d{4}$/', $selectedYear) || (int)$selectedYear < 2018 || (int)$selectedYear > 2035)) {
    $selectedYear = date('Y');
}

// Available years for dropdown
$yearsList = [];
try {
    $yStmt = $pdo->prepare("SELECT DISTINCT YEAR(voucher_date) AS yr FROM vouchers WHERE donor_id = ? AND voucher_type = 'income' AND status = 'posted' ORDER BY yr DESC");
    $yStmt->execute([$donorId]);
    $yearsList = $yStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}
if (!in_array((int)date('Y'), array_map('intval', $yearsList), true)) {
    array_unshift($yearsList, (int)date('Y'));
}

// Query Vouchers for this Statement
$sql = "SELECT v.*, f.name AS fund_name, f.fund_code 
        FROM vouchers v 
        LEFT JOIN funds f ON f.id = v.fund_id 
        WHERE v.donor_id = ? AND v.voucher_type = 'income' AND v.status = 'posted'";
$params = [$donorId];

if ($selectedYear !== 'all') {
    $sql .= " AND v.voucher_date LIKE ?";
    $params[] = $selectedYear . '-%';
}
$sql .= " ORDER BY v.voucher_date ASC, v.id ASC";

$stmtVouchers = $pdo->prepare($sql);
$stmtVouchers->execute($params);
$vouchers = $stmtVouchers->fetchAll(PDO::FETCH_ASSOC);

// Totals for this period
$totalAmountPeriod = 0.0;
$countVouchers = count($vouchers);
foreach ($vouchers as $v) {
    $totalAmountPeriod += (float)$v['total_amount'];
}

// Lifetime Statistics
$stmtLife = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) AS lifetime_total, COUNT(id) AS lifetime_count FROM vouchers WHERE donor_id = ? AND voucher_type = 'income' AND status = 'posted'");
$stmtLife->execute([$donorId]);
$lifeStats = $stmtLife->fetch(PDO::FETCH_ASSOC);
$lifetimeTotal = (float)($lifeStats['lifetime_total'] ?? 0);
$lifetimeCount = (int)($lifeStats['lifetime_count'] ?? 0);

$orgName = function_exists('get_setting') ? get_setting('madrasa_name', 'Mangalchai Welfare Organization') : 'Mangalchai Welfare Organization';
$orgSubtitle = function_exists('get_setting') ? get_setting('org_subtitle', 'Financial Accounting & Welfare Trust') : 'Financial Accounting & Welfare Trust';
$orgPhone = function_exists('get_setting') ? get_setting('madrasa_phone', '+92 331 7240256') : '+92 331 7240256';
$orgAddress = function_exists('get_setting') ? get_setting('madrasa_address', '') : '';
$orgLogo = function_exists('get_org_logo_url') ? get_org_logo_url() : null;

$signTitle1 = get_setting('print_sign_title_1', 'تیار کنندہ / خزانچی (Prepared By)');
$signTitle2 = get_setting('print_sign_title_2', 'نگرانِ پڑتال / آڈیٹر (Auditor / Checker)');
$signTitle3 = get_setting('print_sign_title_3', 'مہتمم / صدر / مجاز اتھارٹی (Director)');

$cleanPhone = preg_replace('/[^0-9]/', '', $donor['phone'] ?? '');
if (!empty($cleanPhone) && strpos($cleanPhone, '0') === 0) {
    $cleanPhone = '92' . substr($cleanPhone, 1);
}

// WhatsApp Share URL for this Statement
$currentUrl = (function_exists('get_app_base_url') ? get_app_base_url() : '') . "/print_donor_statement.php?id={$donorId}&year=" . urlencode($selectedYear);
$yearLabelUrdu = ($selectedYear === 'all') ? 'مکمل تاریخ / تاحیات' : "سال {$selectedYear}";
$waMsg = "السلام علیکم و رحمۃ اللہ و برکاتہ\n"
       . "محترم *" . $donor['donor_name'] . "* صاحب!\n\n"
       . "🏢 *" . $orgName . "*\n"
       . "📜 *سالانہ کھاتہ و عطیات اسٹیٹمنٹ (" . $yearLabelUrdu . ")*\n\n"
       . "الحمد للہ! ادارے میں آپ کی جانب سے موصولہ عطیات و کفالت کا باقاعدہ آڈٹ شدہ سالانہ ریکارڈ تیار ہے۔\n\n"
       . "📊 *خلاصہ مالی تعاون:*\n"
       . "• دورانیہ: *" . $yearLabelUrdu . "*\n"
       . "• موصول شدہ کل رقم: *PKR " . number_format($totalAmountPeriod, 2) . "*\n"
       . "• جاری شدہ رسیدیں: *" . $countVouchers . " عدد*\n"
       . "• مجموعی تاحیات عطیات: *PKR " . number_format($lifetimeTotal, 2) . "*\n\n"
       . "🔗 *مکمل کمپیوٹرائزڈ اسٹیٹمنٹ دیکھیں / پی ڈی ایف ڈاؤنلوڈ کریں:*\n"
       . $currentUrl . "\n\n"
       . "اللہ تعالیٰ آپ کا دیا ہوا ہر روپیہ اپنی بارگاہ میں قبول فرمائے اور آپ کے رزق و مال میں برکتیں عطا فرمائے۔ آمین۔\n"
       . (!empty($orgPhone) ? "رابطہ: " . $orgPhone : "");

$waShareUrl = "https://api.whatsapp.com/send?phone={$cleanPhone}&text=" . urlencode($waMsg);
$autoPrint = isset($_GET['autoprint']) && $_GET['autoprint'] === '1';
?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Donor Annual Statement - <?=e($donor['donor_name'])?> (<?=$selectedYear?>)</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 10mm 10mm 12mm 10mm;
        }
        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Nastaliq Urdu", "Jameel Noori Nastaleeq", Tahoma, Arial, sans-serif;
            background: #f8fafc;
            margin: 0;
            padding: 20px;
            color: #0f172a;
            direction: rtl;
        }
        .statement-card {
            max-width: 860px;
            margin: 0 auto;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            padding: 32px 36px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.06);
            position: relative;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            border-bottom: 2.5px solid #0f172a;
            padding-bottom: 16px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 16px;
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 22px;
        }
        .info-row {
            margin-bottom: 6px;
            font-size: 13.5px;
        }
        .info-row strong {
            color: #475569;
            display: inline-block;
            min-width: 110px;
        }
        .metrics-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 22px;
        }
        .metric-box {
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 12px 14px;
            text-align: center;
        }
        .metric-box.highlight {
            background: #ecfdf5;
            border-color: #86efac;
        }
        .metric-val {
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            margin-top: 4px;
        }
        .metric-val.green {
            color: #15803d;
        }
        .metric-lbl {
            font-size: 11.5px;
            color: #64748b;
            font-weight: 700;
            text-transform: uppercase;
        }
        .ledger-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
            font-size: 13px;
        }
        .ledger-table th {
            background: #0f172a;
            color: #ffffff;
            font-weight: 700;
            padding: 9px 12px;
            border: 1px solid #0f172a;
            text-align: right;
            font-size: 12.5px;
        }
        .ledger-table td {
            padding: 8px 12px;
            border: 1px solid #cbd5e1;
            text-align: right;
        }
        .ledger-table tr:nth-child(even) td {
            background: #f8fafc;
        }
        .ledger-table tfoot td {
            background: #f1f5f9;
            font-weight: 800;
            border-top: 2px solid #0f172a;
            font-size: 14px;
        }
        .sign-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-top: 36px;
            padding-top: 24px;
            border-top: 1px dashed #cbd5e1;
            text-align: center;
        }
        .sign-line {
            border-top: 1.5px solid #64748b;
            margin-top: 45px;
            padding-top: 6px;
            font-size: 12px;
            color: #334155;
            font-weight: 700;
        }
        .no-print-bar {
            max-width: 860px;
            margin: 0 auto 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            background: #ffffff;
            padding: 12px 18px;
            border-radius: 10px;
            border: 1px solid #cbd5e1;
            box-shadow: 0 2px 6px rgba(0,0,0,0.04);
        }
        .btn {
            padding: 7px 15px;
            font-size: 13px;
            font-weight: 700;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: none;
        }
        .btn-green { background: #15803d; color: #ffffff; }
        .btn-wa { background: #25D366; color: #ffffff; }
        .btn-secondary { background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; }
        @media print {
            body { background: #ffffff; padding: 0; }
            .statement-card { border: none; box-shadow: none; padding: 0; width: 100%; max-width: 100%; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

    <!-- Top Action & Filter Toolbar (Excluded from Print) -->
    <div class="no-print no-print-bar">
        <div style="display:flex;align-items:center;gap:10px;">
            <label style="font-size:13px;font-weight:700;color:#334155;">سال (Financial Year):</label>
            <select onchange="window.location.href='print_donor_statement.php?id=<?=$donorId?>&year=' + this.value" style="font-size:13px;font-weight:700;padding:6px 12px;border:1px solid #94a3b8;border-radius:6px;background:#ffffff;">
                <option value="all" <?=$selectedYear==='all'?'selected':''?>>تمام سال (All Time Record)</option>
                <?php foreach ($yearsList as $yr): ?>
                    <option value="<?=$yr?>" <?=(string)$yr===$selectedYear?'selected':''?>><?=$yr?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display:flex;align-items:center;gap:8px;">
            <button onclick="window.print()" class="btn btn-green">🖨️ Print / Save PDF</button>
            <?php if (!empty($cleanPhone)): ?>
                <a href="<?=$waShareUrl?>" target="_blank" class="btn btn-wa">📲 WhatsApp Statement</a>
            <?php endif; ?>
            <a href="donors.php" class="btn btn-secondary">✕ Back to Directory</a>
        </div>
    </div>

    <!-- Official Printable Statement Container -->
    <div class="statement-card">
        <!-- Header -->
        <table class="header-table">
            <tr>
                <td style="width:75%;vertical-align:middle;text-align:right;">
                    <h1 style="margin:0 0 4px;font-size:22px;color:#0f172a;font-weight:800;"><?=e($orgName)?></h1>
                    <div style="font-size:13px;color:#475569;margin-bottom:3px;"><?=e($orgSubtitle)?></div>
                    <div style="font-size:12px;color:#64748b;">
                        <?php if ($orgAddress): ?><?=e($orgAddress)?> &bull; <?php endif; ?>
                        <?php if ($orgPhone): ?>رابطہ / واٹس ایپ: <b><?=e($orgPhone)?></b><?php endif; ?>
                    </div>
                </td>
                <td style="width:25%;text-align:left;vertical-align:middle;">
                    <?php if ($orgLogo): ?>
                        <img src="<?=e($orgLogo)?>" alt="Logo" style="max-height:75px;max-width:140px;object-fit:contain;">
                    <?php else: ?>
                        <div style="width:75px;height:75px;border-radius:50%;background:#065f46;color:#ffffff;display:flex;align-items:center;justify-content:center;font-size:32px;font-weight:800;float:left;">
                            <?=mb_substr($orgName, 0, 1)?>
                        </div>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <!-- Document Title Banner -->
        <div style="background:#0f172a;color:#ffffff;padding:8px 16px;border-radius:6px;display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;">
            <div style="font-weight:800;font-size:15px;letter-spacing:0.5px;">
                ANNUAL GIVING &amp; CONTRIBUTION STATEMENT <span class="ur">(سالانہ کھاتہ و تصدیقِ عطیات)</span>
            </div>
            <div style="font-size:12.5px;font-weight:700;">
                دورانیہ: <span style="background:rgba(255,255,255,0.18);padding:2px 8px;border-radius:4px;"><?=$yearLabelUrdu?></span>
            </div>
        </div>

        <!-- Donor Information Grid -->
        <div class="info-grid">
            <div>
                <div class="info-row"><strong>معاون کا نام:</strong> <b style="font-size:15px;color:#0f172a;"><?=e($donor['donor_name'])?></b></div>
                <div class="info-row"><strong>موبائل / واٹس ایپ:</strong> <span><?=e($donor['phone'] ?: '—')?></span></div>
                <div class="info-row"><strong>پتہ / شہر:</strong> <span><?=e($donor['address'] ?: '—')?></span></div>
            </div>
            <div>
                <div class="info-row"><strong>رجسٹریشن نمبر:</strong> <span style="font-family:monospace;font-weight:700;">#DON-<?=str_pad((string)$donor['id'], 4, '0', STR_PAD_LEFT)?></span></div>
                <div class="info-row"><strong>ماہانہ کفالت کمٹمنٹ:</strong> <b><?= (float)$donor['pledge_amount'] > 0 ? ('Rs. ' . number_format($donor['pledge_amount'], 2) . ' ماہانہ') : 'عام معاون / غیر متعین' ?></b></div>
                <?php if (!empty($donor['sponsored_student_name'])): ?>
                    <div class="info-row" style="color:#15803d;"><strong>کفیل برائے طالب علم:</strong> <b>🎓 <?=e($donor['sponsored_student_name'])?> (<?=e($donor['sponsored_student_class'] ?: 'طالب علم')?>)</b></div>
                <?php endif; ?>
                <div class="info-row"><strong>تاریخِ رپورٹ:</strong> <span><?=date('d-M-Y H:i')?></span></div>
            </div>
        </div>

        <!-- Summary Metrics Box -->
        <div class="metrics-row">
            <div class="metric-box highlight">
                <div class="metric-lbl">موصولہ رقم بابت <?=e($selectedYear)?></div>
                <div class="metric-val green">Rs. <?=number_format($totalAmountPeriod, 2)?></div>
                <div style="font-size:11px;color:#059669;margin-top:2px;">کل جاری شدہ رسیدیں: <?=$countVouchers?> عدد</div>
            </div>
            <div class="metric-box">
                <div class="metric-lbl">مجموعی تاحیات عطیات (Lifetime Giving)</div>
                <div class="metric-val">Rs. <?=number_format($lifetimeTotal, 2)?></div>
                <div style="font-size:11px;color:#64748b;margin-top:2px;">کل تاحیات رسیدیں: <?=$lifetimeCount?> عدد</div>
            </div>
            <div class="metric-box">
                <div class="metric-lbl">ماہانہ اوسط تعاون (Monthly Average)</div>
                <div class="metric-val">Rs. <?=number_format($countVouchers > 0 ? ($totalAmountPeriod / max(1, min(12, $countVouchers))) : 0, 2)?></div>
                <div style="font-size:11px;color:#64748b;margin-top:2px;">باقاعدگی: <?=$countVouchers >= 10 ? '⭐⭐⭐ بہترین' : 'باقاعدہ معاون'?></div>
            </div>
        </div>

        <!-- Detailed Transactions Ledger Table -->
        <table class="ledger-table">
            <thead>
                <tr>
                    <th style="width:40px;text-align:center;">#</th>
                    <th style="width:95px;">تاریخ (Date)</th>
                    <th style="width:115px;">واؤچر نمبر</th>
                    <th>فنڈ / مدات (Purpose / Fund)</th>
                    <th style="width:110px;">طریقہ ادائیگی</th>
                    <th style="width:125px;text-align:left;">رقم (Amount PKR)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($vouchers)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center;padding:26px;color:#94a3b8;">
                            اس دورانیہ میں کوئی مالی وصولی ریکارڈ پر موجود نہیں ہے۔
                        </td>
                    </tr>
                <?php else: ?>
                    <?php $sr = 1; foreach ($vouchers as $v): ?>
                        <tr>
                            <td style="text-align:center;color:#64748b;font-size:11px;"><?=$sr++?></td>
                            <td><?=date('d-m-Y', strtotime($v['voucher_date']))?></td>
                            <td style="font-family:monospace;font-weight:700;color:#0369a1;"><?=e($v['voucher_no'])?></td>
                            <td>
                                <b><?=e($v['fund_name'] ?: 'General Fund')?></b>
                                <?php if (!empty($v['description'])): ?>
                                    <div style="font-size:11px;color:#64748b;"><?=e($v['description'])?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?=ucfirst(e($v['payment_method'] ?? 'cash'))?>
                                <?php if (!empty($v['reference_no'])): ?>
                                    <div style="font-size:10.5px;color:#059669;">Ref: <?=e($v['reference_no'])?></div>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:left;font-weight:700;color:#0f172a;">
                                <?=number_format((float)$v['total_amount'], 2)?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5" style="text-align:right;">
                        مجموعی میزان بابت <?=$yearLabelUrdu?> (TOTAL RECEIVED):
                    </td>
                    <td style="text-align:left;color:#15803d;">
                        PKR <?=number_format($totalAmountPeriod, 2)?>
                    </td>
                </tr>
            </tfoot>
        </table>

        <!-- Amount in Words & Official Verification Note -->
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:12px;line-height:1.7;">
            <div>
                <strong>الفاظ میں رقم:</strong> 
                <span style="font-weight:700;color:#065f46;">
                    مبلغ <?=function_exists('number_to_words') ? number_to_words($totalAmountPeriod) : number_format($totalAmountPeriod)?> روپے فقط۔
                </span>
            </div>
            <div style="color:#64748b;margin-top:4px;">
                <strong>تصدیقی نوٹ:</strong> یہ کمپیوٹرائزڈ اسٹیٹمنٹ ادارے کے باقاعدہ ڈبل انٹری فنانشل اکاؤنٹنگ لیجر سے جاری کی گئی ہے اور مکمل طور پر تصدیق شدہ ہے۔ ادارے کے تمام اخراجات و مدات کا باقاعدہ شرعی و قانونی آڈٹ کیا جاتا ہے۔
            </div>
        </div>

        <!-- Quranic / Hadith Blessing Footer -->
        <div style="text-align:center;font-size:12px;color:#065f46;background:#ecfdf5;border:1px dashed #a7f3d0;padding:10px 14px;border-radius:6px;margin-bottom:15px;">
            ﴿ مَّثَلُ الَّذِينَ يُنفِقُونَ أَمْوَالَهُمْ فِي سَبِيلِ اللَّهِ كَمَثَلِ حَبَّةٍ أَنبَتَتْ سَبْعَ سَنَابِلَ فِي كُلِّ سُنبُلَةٍ مِّائَةُ حَبَّةٍ ۗ وَاللَّهُ يُضَاعِفُ لِمَن يَشَاءُ ﴾
            <div style="font-size:11px;color:#047857;margin-top:2px;">(سورة البقرة: 261) &bull; اللہ تعالیٰ آپ کے عطیات و تعاون کو دارین کی فلاح اور صدقہ جاریہ کا ذریعہ بنائے۔</div>
        </div>

        <!-- Official Signatures & Seal Section -->
        <div class="sign-grid">
            <div>
                <div class="sign-line"><?=e($signTitle1)?></div>
            </div>
            <div>
                <div class="sign-line"><?=e($signTitle2)?></div>
            </div>
            <div>
                <div class="sign-line"><?=e($signTitle3)?></div>
            </div>
        </div>

        <div style="text-align:center;margin-top:20px;font-size:10px;color:#94a3b8;border-top:1px solid #f1f5f9;padding-top:8px;">
            Software Generated Official Statement &bull; <?=e($orgName)?> &bull; Printed: <?=date('d-m-Y H:i:s')?>
        </div>
    </div>

    <?php if ($autoPrint): ?>
        <script>
            window.addEventListener('load', function() {
                setTimeout(function() { window.print(); }, 400);
            });
        </script>
    <?php endif; ?>
</body>
</html>

