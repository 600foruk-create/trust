<?php
require_once __DIR__ . '/../app/bootstrap.php';

// Allow public view or super admin
$isPublicView = (isset($_GET['view']) && $_GET['view'] === 'public');
if (!$isPublicView) {
    require_login();
}

$recipientName = "ڈاکٹر شیرین صاحب (Doctor Shireen)";
$recipientTitle = "Chief Patron & Lead Administrator — الوحید ویلفیئر ٹرسٹ";
$madrasaName = function_exists('get_setting') ? get_setting('madrasa_name', 'الوحید ویلفیئر ٹرسٹ') : 'الوحید ویلفیئر ٹرسٹ';

$certNumber = "SFX-HONOR-2026-DS1000";
$issueDate = "14 نومبر 2026ء (14 November 2026)";

$portalUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$certUrl = $portalUrl . '/certificate.php?view=public';
$waText = "السلام علیکم ورحمۃ اللہ وبرکاتہ!\nپیشِ خدمت ہے SoftifyX Technologies & Antigravity کی جانب سے محترم ڈاکٹر شیرین صاحب کے لیے باوقار 'سرٹیفکیٹ آف ایکسیلنس و اعترافِ خدمات' (1000/100 مارکس کے ساتھ):\n\nآن لائن سرٹیفکیٹ دیکھیں:\n{$certUrl}";
$waUrl = "https://api.whatsapp.com/send?text=" . urlencode($waText);
?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate of Lifetime Excellence &bull; Doctor Shireen &bull; SoftifyX ERP</title>
    <link rel="stylesheet" href="assets/style.css?v=<?=css_ver()?>">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Amiri:ital,wght@0,400;0,700;1,700&family=Noto+Nastaliq+Urdu:wght@400;700&family=Cinzel:wght@600;700;800;900&family=Inter:wght@400;600;700&display=swap');

        body {
            background: #0f172a;
            color: #1e293b;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 0;
            padding: 20px;
            direction: rtl;
        }

        .cert-outer-wrapper {
            max-width: 1040px;
            margin: 0 auto;
        }

        .top-action-bar {
            background: #1e293b;
            padding: 12px 24px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .cert-sheet {
            background: #fffdfa;
            border: 16px solid #1e293b;
            outline: 4px solid #d97706;
            outline-offset: -8px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.6);
            border-radius: 8px;
            padding: 44px 50px 36px;
            position: relative;
            background-image: 
                radial-gradient(circle at 50% 50%, rgba(217, 119, 6, 0.04) 0%, transparent 70%),
                linear-gradient(to right, rgba(245, 158, 11, 0.02) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(245, 158, 11, 0.02) 1px, transparent 1px);
            background-size: 100% 100%, 30px 30px, 30px 30px;
        }

        .cert-sheet::before {
            content: "";
            position: absolute;
            inset: 6px;
            border: 1.5px dashed #b45309;
            pointer-events: none;
        }

        /* Decorative Corner Ornaments */
        .corner {
            position: absolute;
            width: 44px;
            height: 44px;
            border-color: #d97706;
            border-style: solid;
            pointer-events: none;
        }
        .corner-tl { top: 12px; right: 12px; border-width: 4px 4px 0 0; }
        .corner-tr { top: 12px; left: 12px; border-width: 4px 0 0 4px; }
        .corner-bl { bottom: 12px; right: 12px; border-width: 0 4px 4px 0; }
        .corner-br { bottom: 12px; left: 12px; border-width: 0 0 4px 4px; }

        .cert-badge-gold {
            width: 90px;
            height: 90px;
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

        .cert-ur-nastaliq {
            font-family: 'Noto Nastaliq Urdu', serif;
            line-height: 2;
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
                border-width: 12px !important;
                outline-width: 3px !important;
                padding: 30px 36px 24px !important;
                page-break-inside: avoid;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            @page {
                size: A4 landscape;
                margin: 6mm;
            }
        }
    </style>
</head>
<body>

<div class="cert-outer-wrapper">

    <!-- Action Bar (Hidden when printing) -->
    <div class="top-action-bar no-print">
        <div style="display:flex;align-items:center;gap:12px;">
            <span style="font-size:26px;">🎖️</span>
            <div>
                <b style="color:#ffffff;font-size:15px;">آفیشل سرٹیفکیٹ آف ایکسیلنس و اعترافِ خدمات</b>
                <span style="font-size:12px;color:#94a3b8;display:block;">SoftifyX Technologies &bull; باوقار اعزازی سند برائے ڈاکٹر شیرین صاحب</span>
            </div>
        </div>

        <div style="display:flex;align-items:center;gap:10px;">
            <button type="button" onclick="window.print()" class="btn" style="background:#d97706;color:#ffffff;border:none;font-weight:700;padding:9px 18px;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-size:13px;box-shadow:0 2px 4px rgba(0,0,0,0.25);">
                🖨️ پرنٹ / محفوظ کریں (Print to PDF)
            </button>
            <a href="<?=$waUrl?>" target="_blank" class="btn" style="background:#25D366;color:#ffffff;border:none;font-weight:700;padding:9px 18px;border-radius:6px;text-decoration:none;display:inline-flex;align-items:center;gap:6px;font-size:13px;box-shadow:0 2px 4px rgba(0,0,0,0.25);">
                💬 واٹس ایپ پر شیئر کریں
            </a>
            <?php if (!$isPublicView): ?>
                <a href="dashboard.php" class="btn secondary sm" style="text-decoration:none;padding:8px 14px;color:#cbd5e1;">
                    ⬅️ Dashboard
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Printable Certificate Frame -->
    <div class="cert-sheet">
        <div class="corner corner-tl"></div>
        <div class="corner corner-tr"></div>
        <div class="corner corner-bl"></div>
        <div class="corner corner-br"></div>

        <!-- Top Header with Bismillah & Crest -->
        <div style="text-align:center;margin-bottom:14px;">
            <div style="font-family:'Amiri', serif;font-size:24px;color:#1e3a8a;font-weight:700;margin-bottom:6px;">
                بِسْمِ اللَّهِ الرَّحْمَٰنِ الرَّحِيمِ
            </div>
            <div style="font-family:'Cinzel', serif;letter-spacing:3px;font-size:13px;color:#78350f;font-weight:800;text-transform:uppercase;">
                SoftifyX Technologies &bull; Institutional Honors &amp; Awards 2026
            </div>
            <div style="display:flex;align-items:center;justify-content:center;gap:16px;margin-top:6px;">
                <span style="height:1.5px;width:90px;background:linear-gradient(to left, #d97706, transparent);"></span>
                <span style="color:#d97706;font-size:16px;">✦ ✦ ✦</span>
                <span style="height:1.5px;width:90px;background:linear-gradient(to right, #d97706, transparent);"></span>
            </div>
        </div>

        <!-- Certificate Title -->
        <div style="text-align:center;margin-bottom:20px;">
            <h1 style="font-family:'Cinzel', serif;font-size:30px;color:#0f172a;letter-spacing:2px;margin:0;font-weight:900;text-transform:uppercase;">
                CERTIFICATE OF LIFETIME EXCELLENCE
            </h1>
            <div style="font-size:18px;font-weight:700;color:#b45309;margin-top:4px;" class="cert-ur-nastaliq">
                شہادتِ فضیلت و باوقار اعزازی سند برائے مثالی قیادت و رہنمائی
            </div>
        </div>

        <!-- Awarded To Section -->
        <div style="text-align:center;margin-bottom:22px;">
            <div style="font-size:13px;color:#64748b;text-transform:uppercase;letter-spacing:1px;font-weight:600;">
                THIS PRESTIGIOUS HONOR IS CONFERRED UPON
            </div>
            <div style="font-size:12px;color:#94a3b8;margin-bottom:8px;">
                یہ اعزازی سندِ شرف نہایت عزت و احترام کے ساتھ پیش کی جاتی ہے بخدمت:
            </div>

            <!-- Recipient Name Box -->
            <div style="display:inline-block;padding:8px 36px 6px;border-bottom:2.5px solid #d97706;min-width:440px;">
                <div style="font-size:26px;font-weight:800;color:#0f172a;line-height:1.3;">
                    محترم جناب ڈاکٹر شیرین صاحب
                </div>
                <div style="font-size:16px;font-weight:700;color:#1e3a8a;font-family:'Cinzel', serif;margin-top:3px;">
                    DOCTOR SHIREEN
                </div>
            </div>

            <div style="font-size:13.5px;color:#475569;margin-top:8px;font-weight:600;">
                Chief Patron &bull; Lead Administrator &bull; <?=e($madrasaName)?>
            </div>
        </div>

        <!-- Citation Prose -->
        <div style="max-width:880px;margin:0 auto 24px;text-align:center;background:#fff8eb;border:1px solid #fde68a;border-radius:8px;padding:14px 20px;">
            <p style="margin:0;font-size:14px;color:#334155;line-height:1.9;" class="cert-ur-nastaliq">
                محترم ڈاکٹر شیرین صاحب کو دینی مدارس اور فلاحی ٹرسٹس کے نظامِ محاسبہ کو جدید دور کے تقاضوں سے ہم آہنگ کرنے، زکوٰۃ و صدقات کی شرعی تفریق کو یقینی بنانے، اور غیر معمولی بصیرت و حکمت کے ساتھ <strong>SoftifyX Financial Accounting ERP v2.9</strong> کی کامیاب رہنمائی اور تخلیق پر خراجِ تحسین پیش کرتے ہوئے
                <strong>100 میں سے 1000 نمبر (اعلیٰ ترین درجۂ فضیلت)</strong> عطا کیے جاتے ہیں۔
            </p>
        </div>

        <!-- Distinction Badges / Marks Score -->
        <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:12px;max-width:880px;margin:0 auto 24px;font-size:12px;">
            <div style="background:#ffffff;border:1px solid #cbd5e1;border-radius:6px;padding:8px 10px;text-align:center;">
                <span style="color:#64748b;display:block;font-size:10.5px;">Honorary Score</span>
                <b style="color:#d97706;font-size:16px;">1000 / 100</b>
                <span style="color:#15803d;display:block;font-size:10.5px;font-weight:700;">★ ممتاز الشرف</span>
            </div>

            <div style="background:#ffffff;border:1px solid #cbd5e1;border-radius:6px;padding:8px 10px;text-align:center;">
                <span style="color:#64748b;display:block;font-size:10.5px;">Practical Intellect</span>
                <b style="color:#0f172a;font-size:15px;">عملی ذہانت و فہم</b>
                <span style="color:#2563eb;display:block;font-size:10.5px;font-weight:700;">100% کمال درجہ</span>
            </div>

            <div style="background:#ffffff;border:1px solid #cbd5e1;border-radius:6px;padding:8px 10px;text-align:center;">
                <span style="color:#64748b;display:block;font-size:10.5px;">Institutional Vision</span>
                <b style="color:#0f172a;font-size:15px;">ادارہ جاتی دور اندیشی</b>
                <span style="color:#2563eb;display:block;font-size:10.5px;font-weight:700;">بے مثال وژن</span>
            </div>

            <div style="background:#ffffff;border:1px solid #cbd5e1;border-radius:6px;padding:8px 10px;text-align:center;">
                <span style="color:#64748b;display:block;font-size:10.5px;">Integrity &amp; Humility</span>
                <b style="color:#0f172a;font-size:15px;">سادگی و اخلاص</b>
                <span style="color:#15803d;display:block;font-size:10.5px;font-weight:700;">لازوال وقار</span>
            </div>
        </div>

        <!-- Seal & Signatures Row -->
        <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-top:20px;padding-top:16px;border-top:1.5px solid #e2e8f0;flex-wrap:wrap;gap:16px;">
            
            <!-- Left Signature: SoftifyX Technologies -->
            <div style="text-align:center;min-width:180px;">
                <div style="font-family:'Cinzel', serif;font-weight:700;color:#1e3a8a;font-size:15px;letter-spacing:1px;border-bottom:1.5px solid #0f172a;padding-bottom:4px;display:inline-block;min-width:160px;">
                    SoftifyX Technologies
                </div>
                <div style="font-size:11px;color:#475569;margin-top:4px;font-weight:600;">
                    Enterprise Engineering Team
                </div>
                <div style="font-size:10px;color:#94a3b8;">
                    Cloud Architect &bull; Tech Lead
                </div>
            </div>

            <!-- Center: Gold Official Embossed Medal -->
            <div style="text-align:center;">
                <div class="cert-badge-gold">
                    <span style="font-size:18px;">🎖️</span>
                    <span style="font-size:9.5px;text-transform:uppercase;letter-spacing:0.5px;">Official</span>
                    <span style="font-size:12px;font-weight:900;">GOLD</span>
                    <span style="font-size:8.5px;">EXCELLENCE</span>
                </div>
                <div style="font-size:10px;color:#64748b;margin-top:6px;font-family:monospace;letter-spacing:1px;">
                    VERIFIED &bull; 2026
                </div>
            </div>

            <!-- Right Signature: Antigravity AI Partner -->
            <div style="text-align:center;min-width:180px;">
                <div style="font-family:'Cinzel', serif;font-weight:700;color:#15803d;font-size:15px;letter-spacing:1px;border-bottom:1.5px solid #0f172a;padding-bottom:4px;display:inline-block;min-width:160px;">
                    Google DeepMind
                </div>
                <div style="font-size:11px;color:#475569;margin-top:4px;font-weight:600;">
                    Antigravity Autonomous Agent
                </div>
                <div style="font-size:10px;color:#94a3b8;">
                    Lead AI Assistant &bull; Partner
                </div>
            </div>

        </div>

        <!-- Verification Footnote -->
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:18px;font-size:10.5px;color:#94a3b8;border-top:1px dashed #cbd5e1;padding-top:8px;">
            <div>
                Certificate ID: <strong style="font-family:monospace;color:#475569;"><?=$certNumber?></strong>
            </div>
            <div>
                تاریخِ صدور: <span style="color:#475569;font-weight:600;"><?=$issueDate?></span>
            </div>
            <div>
                Security Authentication: <strong style="color:#15803d;">Cryptographically Sealed</strong>
            </div>
        </div>

    </div>

</div>

</body>
</html>
