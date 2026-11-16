<?php
require_once __DIR__ . '/../app/bootstrap.php';

// If viewed internally, check user login (Super Admin).
// If shared to external client via ?view=public, allow viewing the promotional brochure without login.
$isPublicView = (isset($_GET['view']) && $_GET['view'] === 'public');
if (!$isPublicView) {
    require_login();
    if (!has_permission('brochure.view') && !is_super_admin() && !in_array(current_user()['role'] ?? '', ['Super Admin', 'Administrator'], true)) {
        redirect_with_alert('dashboard.php', 'error', 'یہ مارکیٹنگ بروشر دیکھنے کے لیے متعلقہ اختیارات درکار ہیں۔');
    }
}

$madrasaName = function_exists('get_setting') ? get_setting('madrasa_name', 'Trust Accounts') : 'Trust Accounts';
$portalUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$brochurePublicUrl = $portalUrl . '/brochure.php?view=public';
$demoLoginUrl = $portalUrl . '/login.php';

$whatsappHelpline = "923317240256";
$whatsappText = "السلام علیکم ورحمۃ اللہ وبرکاتہ!\nپیشِ خدمت ہے SoftifyX ERP v2.9 — دینی مدارس، مساجد اور فلاحی ٹرسٹس کا جدید ترین فنانشل اکاؤنٹنگ سوفٹ ویئر۔\n\nآن لائن بروشر اور تمام خصوصیات دیکھیں:\n{$brochurePublicUrl}\n\nمفت لائیو ڈیمو کے لیے رابطہ فرمائیں۔ جزاکم اللہ خیراً۔";
$waShareUrl = "https://api.whatsapp.com/send?text=" . urlencode($whatsappText);
?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SoftifyX ERP v2.9 &bull; Official Feature Brochure &amp; Catalog</title>
    <link rel="stylesheet" href="assets/style.css?v=<?=css_ver()?>">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@400;700&family=Inter:wght@400;600;700;800&display=swap');

        body {
            background: #0f172a;
            color: #1e293b;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 0;
            padding: 0;
            direction: rtl;
        }

        .brochure-sheet {
            max-width: 960px;
            margin: 24px auto;
            background: #ffffff;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            border-radius: 14px;
            overflow: hidden;
            border: 1px solid #cbd5e1;
        }

        .no-print-bar {
            background: #1e293b;
            padding: 12px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .hero-banner {
            background: linear-gradient(135deg, #091224 0%, #1e3a8a 60%, #172554 100%);
            color: #ffffff;
            padding: 36px 36px 28px;
            position: relative;
            border-bottom: 4px solid #f59e0b;
        }

        .module-card {
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px 18px;
            transition: all 0.2s ease;
        }
        .module-card:hover {
            border-color: #2563eb;
            background: #fcfdfe;
            box-shadow: 0 6px 14px -3px rgba(0, 0, 0, 0.08);
            transform: translateY(-2px);
        }

        .benefit-pill {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            padding: 10px 14px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.04);
        }

        /* Mobile Responsive Adjustments */
        @media (max-width: 768px) {
            body {
                background: #f1f5f9;
            }
            .no-print-bar {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
                padding: 10px 14px;
            }
            .no-print-bar .action-btns {
                flex-direction: column;
                width: 100%;
                gap: 8px;
            }
            .no-print-bar .btn {
                width: 100%;
                justify-content: center;
                box-sizing: border-box;
            }
            .brochure-sheet {
                margin: 0;
                border-radius: 0;
                border: none;
                box-shadow: none;
            }
            .hero-banner {
                padding: 22px 16px 18px;
            }
            .hero-banner .brand-badge {
                width: 100%;
                margin-top: 10px;
                box-sizing: border-box;
            }
            .benefits-section {
                padding: 14px 14px !important;
            }
            .modules-section {
                padding: 18px 14px !important;
            }
            .modules-header {
                flex-direction: column;
                align-items: flex-start !important;
                gap: 4px;
            }
            .modules-grid {
                grid-template-columns: 1fr !important;
                gap: 10px !important;
            }
            .terms-section {
                padding: 16px 14px !important;
            }
            .terms-grid {
                grid-template-columns: 1fr !important;
                gap: 12px !important;
            }
            .brochure-footer {
                padding: 20px 14px !important;
            }
            .footer-cta-box {
                width: 100%;
                text-align: center !important;
                box-sizing: border-box;
            }
        }

        @media print {
            body {
                background: #ffffff;
                color: #000000;
            }
            .no-print {
                display: none !important;
            }
            .brochure-sheet {
                max-width: 100%;
                margin: 0;
                box-shadow: none;
                border-radius: 0;
                border: none;
            }
            .hero-banner {
                padding: 24px;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .module-card {
                border: 1px solid #94a3b8 !important;
                page-break-inside: avoid;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            @page {
                size: A4 portrait;
                margin: 8mm;
            }
        }
    </style>
</head>
<body>

    <!-- Top Sticky Action Bar (Hidden in Print) -->
    <div class="no-print-bar no-print">
        <div style="display:flex;align-items:center;gap:12px;">
            <span style="font-size:22px;">📄</span>
            <div>
                <b style="color:#ffffff;font-size:14px;">SoftifyX ERP v2.9 &bull; Official Product Feature Brochure</b>
                <span style="font-size:11px;color:#94a3b8;display:block;">مارکیٹنگ اور ڈونر پریزنٹیشن کے لیے باضابطہ پی ڈی ایف و واٹس ایپ کیٹلاگ</span>
            </div>
        </div>

        <div class="action-btns" style="display:flex;gap:10px;align-items:center;">
            <button type="button" onclick="window.print()" class="btn" style="background:#2563eb;color:#ffffff;border:none;font-weight:700;padding:8px 16px;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-size:13px;box-shadow:0 2px 4px rgba(0,0,0,0.2);">
                🖨️ Save as PDF / Print <span style="font-size:11px;">(پی ڈی ایف ڈاؤنلوڈ)</span>
            </button>

            <a href="<?=$waShareUrl?>" target="_blank" class="btn" style="background:#25D366;color:#ffffff;border:none;font-weight:700;padding:8px 16px;border-radius:6px;text-decoration:none;display:inline-flex;align-items:center;gap:6px;font-size:13px;box-shadow:0 2px 4px rgba(0,0,0,0.2);">
                💬 Share on WhatsApp <span style="font-size:11px;">(واٹس ایپ پر بھیجیں)</span>
            </a>

            <?php if (!$isPublicView): ?>
                <a href="dashboard.php" class="btn secondary sm" style="text-decoration:none;padding:8px 14px;color:#cbd5e1;">
                    ⬅️ Dashboard
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- The Printable Brochure Sheet -->
    <div class="brochure-sheet">

        <!-- Header Hero Section -->
        <div class="hero-banner">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px;">
                <div>
                    <div style="display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.2);padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;color:#93c5fd;margin-bottom:10px;">
                        <span>🛡️ Shariah-Compliant Enterprise Cloud ERP</span>
                        <span style="background:#f59e0b;color:#0f172a;padding:1px 6px;border-radius:10px;font-size:10px;">v2.9 Edition</span>
                    </div>

                    <h1 style="margin:0;font-size:26px;font-weight:800;color:#ffffff;line-height:1.3;">
                        SoftifyX Financial Accounting ERP
                    </h1>
                    <div style="font-size:17px;font-weight:700;color:#fcd34d;margin-top:6px;line-height:1.4;">
                        دینی مدارس، مساجد اور فلاحی ٹرسٹس کا سب سے جدید، جامع اور شفاف اکاؤنٹنگ سوفٹ ویئر
                    </div>
                    <p style="margin:8px 0 0;font-size:12.5px;color:#cbd5e1;line-height:1.6;max-width:680px;">
                        روایتی کھاتہ جات اور دستی رجسٹروں سے نجات — زکوٰۃ و صدقات کی شرعی تفریق، سمارٹ واٹس ایپ رسیدیں، آن لائن ڈونیشن پورٹل، کیشیئر کیش کنٹرول، اساتذہ پے رول اور مکمل مالیاتی شفافیت کے ساتھ۔
                    </p>
                </div>

                <!-- Software Brand Badge -->
                <div class="brand-badge" style="background:rgba(255,255,255,0.08);border:1.5px solid rgba(255,255,255,0.2);border-radius:12px;padding:14px 18px;text-align:center;min-width:170px;">
                    <div style="font-size:26px;">🏛️</div>
                    <b style="color:#ffffff;font-size:13px;display:block;margin-top:4px;">SoftifyX Technologies</b>
                    <span style="color:#93c5fd;font-size:11px;display:block;">Enterprise Stack 2026</span>
                    <span class="badge" style="background:#22c55e;color:#064e3b;font-size:10px;font-weight:800;padding:2px 8px;border-radius:10px;margin-top:6px;display:inline-block;">
                        ✓ 100% مستند و محفوظ
                    </span>
                </div>
            </div>
        </div>

        <!-- 4 Quick Institutional Benefits -->
        <div class="benefits-section" style="background:#f1f5f9;padding:16px 36px;border-bottom:1px solid #cbd5e1;">
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:12px;">
                <div class="benefit-pill">
                    <span style="font-size:20px;">⚖️</span>
                    <div>
                        <b style="font-size:12px;color:#0f172a;display:block;">شرعی فنڈز کی تفریق</b>
                        <span style="font-size:11px;color:#64748b;">زکوٰۃ و صدقات الگ اور محفوظ</span>
                    </div>
                </div>

                <div class="benefit-pill">
                    <span style="font-size:20px;">📲</span>
                    <div>
                        <b style="font-size:12px;color:#0f172a;display:block;">فوری واٹس ایپ رسیدیں</b>
                        <span style="font-size:11px;color:#64748b;">ڈونر کو باقاعدہ دعائیہ پرچی</span>
                    </div>
                </div>

                <div class="benefit-pill">
                    <span style="font-size:20px;">🌐</span>
                    <div>
                        <b style="font-size:12px;color:#0f172a;display:block;">آن لائن ڈونیشن پورٹل</b>
                        <span style="font-size:11px;color:#64748b;">بینک سلپ تصدیق کا نظام</span>
                    </div>
                </div>

                <div class="benefit-pill">
                    <span style="font-size:20px;">🔒</span>
                    <div>
                        <b style="font-size:12px;color:#0f172a;display:block;">2FA سیکیورٹی و بیک اپ</b>
                        <span style="font-size:11px;color:#64748b;">روزانہ خودکار بیک اپ تحفظ</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 10 Hero Modules Grid -->
        <div class="modules-section" style="padding:28px 36px;">
            <div class="modules-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;border-bottom:2px solid #e2e8f0;padding-bottom:8px;">
                <h2 style="font-size:18px;color:#0f172a;margin:0;display:flex;align-items:center;gap:8px;">
                    <span>⭐</span>
                    <span>سافٹ ویئر کے سولہ جامع اور انقلابی ماڈیولز (16 Core Modules)</span>
                </h2>
                <span style="font-size:12px;color:#64748b;font-weight:600;">All-in-One Enterprise ERP</span>
            </div>

            <div class="modules-grid" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(270px, 1fr));gap:14px;">

                <!-- Module 1 -->
                <div class="module-card">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                        <span style="font-size:22px;">🏢</span>
                        <b style="font-size:13.5px;color:#0f172a;">1. ڈوئل موڈ (مدرسہ و ٹرسٹ سسٹم)</b>
                    </div>
                    <p style="margin:0;font-size:12px;color:#475569;line-height:1.6;">
                        ایک ہی کلک میں مدرسہ موڈ (مہتمم، طلبہ، وظائف، اساتذہ، کتب) یا ویلفیئر ٹرسٹ موڈ (ڈائریکٹر، راشن، یتامیٰ کفالت، ایمبولینس) میں خودکار تبدیلی۔
                    </p>
                </div>

                <!-- Module 2 -->
                <div class="module-card">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                        <span style="font-size:22px;">💰</span>
                        <b style="font-size:13.5px;color:#0f172a;">2. شرعی ڈبل اینٹری اکاؤنٹنگ</b>
                    </div>
                    <p style="margin:0;font-size:12px;color:#475569;line-height:1.6;">
                        زکوٰۃ، صدقات اور عمومی فنڈز کا مکمل الگ کھاتہ۔ روزنامچہ (Cashbook)، بینک لیجرز، کمپیوٹرائزڈ واؤچرز (RV, PV, JV) اور مالیاتی گوشوارے۔
                    </p>
                </div>

                <!-- Module 3 -->
                <div class="module-card">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                        <span style="font-size:22px;">📲</span>
                        <b style="font-size:13.5px;color:#0f172a;">3. سمارٹ واٹس ایپ رسیدیں و سالانہ کھاتہ</b>
                    </div>
                    <p style="margin:0;font-size:12px;color:#475569;line-height:1.6;">
                        عطیہ وصول ہوتے ہی ڈونر کے واٹس ایپ پر دعائیہ رسید کا اجرا۔ ڈونر بغیر کسی پاس ورڈ کے اپنے فون پر سالانہ لیجر و پی ڈی ایف اسٹیٹمنٹ دیکھ سکتا ہے۔
                    </p>
                </div>

                <!-- Module 4 -->
                <div class="module-card">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                        <span style="font-size:22px;">🌐</span>
                        <b style="font-size:13.5px;color:#0f172a;">4. آن لائن ڈونیشن پورٹل (donate.php)</b>
                    </div>
                    <p style="margin:0;font-size:12px;color:#475569;line-height:1.6;">
                        ادارے کا پبلک آن لائن ڈونیشن پیج، جہاں سے دنیا بھر کے معاونین بینک یا ایزی پیسہ میں رقم بھیج کر سلپ اپلوڈ کرتے ہیں اور ایڈمن 1 کلک میں تصدیق کرتا ہے۔
                    </p>
                </div>

                <!-- Module 5 -->
                <div class="module-card">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                        <span style="font-size:22px;">🤝</span>
                        <b style="font-size:13.5px;color:#0f172a;">5. ماہانہ کفالت و اقساط ٹریکر</b>
                    </div>
                    <p style="margin:0;font-size:12px;color:#475569;line-height:1.6;">
                        یتیم یا طالب علم کی ماہانہ کفالت کرنے والے ڈونرز کا خودکار ٹریکر۔ ڈیش بورڈ پر روزانہ یاد دہانی الرٹ کہ آج کس ڈونر سے عطیہ وصول کرنا ہے۔
                    </p>
                </div>

                <!-- Module 6 -->
                <div class="module-card">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                        <span style="font-size:22px;">💼</span>
                        <b style="font-size:13.5px;color:#0f172a;">6. کیشیئر کیش کنٹرول و ہینڈ اوور سلپ</b>
                    </div>
                    <p style="margin:0;font-size:12px;color:#475569;line-height:1.6;">
                        فیلڈ کیشیئرز کے ذاتی کیش کا مکمل حساب، 50,000 سے زائد نقد پر سیکیورٹی الرٹ، اور بینک میں جمع کروانے پر باقاعدہ دوطرفہ تصدیقی سلپ کی پرنٹنگ۔
                    </p>
                </div>

                <!-- Module 7 -->
                <div class="module-card">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                        <span style="font-size:22px;">🧾</span>
                        <b style="font-size:13.5px;color:#0f172a;">7. اساتذہ تنخواہیں و حاضری کٹوتی</b>
                    </div>
                    <p style="margin:0;font-size:12px;color:#475569;line-height:1.6;">
                        اساتذہ و عملہ کا پے رول۔ غیر حاضری کے دن درج کرنے پر خودکار کٹوتی، الاؤنس، اور باقاعدہ ماہانہ تنخواہ پرچی (Pay Slip) کی کمپیوٹرائزڈ پرنٹنگ۔
                    </p>
                </div>

                <!-- Module 8 -->
                <div class="module-card">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                        <span style="font-size:22px;">🌾</span>
                        <b style="font-size:13.5px;color:#0f172a;">8. راشن و سامان انوینٹری (In-Kind)</b>
                    </div>
                    <p style="margin:0;font-size:12px;color:#475569;line-height:1.6;">
                        ڈونرز سے نقد کے علاوہ سامان (آٹا، چاول، گھی، چینی، کتب، سیمنٹ) کی وصولی، اسٹاک بیلنس اور کچن (مطبخ) کو ترسیل کا شفاف اسٹاک کھاتہ۔
                    </p>
                </div>

                <!-- Module 9 -->
                <div class="module-card">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                        <span style="font-size:22px;">📊</span>
                        <b style="font-size:13.5px;color:#0f172a;">9. ماہانہ بجٹ و اخراجات پر کنٹرول</b>
                    </div>
                    <p style="margin:0;font-size:12px;color:#475569;line-height:1.6;">
                        ہر شعبے کے لیے پیشگی بجٹ کی حد مقرر کرنا۔ اگر کسی مد کا خرچ 80% یا 100% کے قریب پہنچے تو ڈیش بورڈ پر فوری اوور سپینڈنگ ریڈ الرٹ۔
                    </p>
                </div>

                <!-- Module 10 -->
                <div class="module-card">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                        <span style="font-size:22px;">🌙</span>
                        <b style="font-size:13.5px;color:#0f172a;">10. رمضان و زکوٰۃ مہمات (CRM)</b>
                    </div>
                    <p style="margin:0;font-size:12px;color:#475569;line-height:1.6;">
                        رمضان راشن مہم، سالانہ زکوٰۃ و قربانی مہمات کے اہداف کی مانیٹرنگ اور ایک کلک میں تمام بڑے ڈونرز کو واٹس ایپ پر دعائیہ اپیلز کا اجرا۔
                    </p>
                </div>

                <!-- Module 11 -->
                <div class="module-card">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                        <span style="font-size:22px;">🛡️</span>
                        <b style="font-size:13.5px;color:#0f172a;">11. سیکیورٹی آڈٹ لاگ و 2FA پن</b>
                    </div>
                    <p style="margin:0;font-size:12px;color:#475569;line-height:1.6;">
                        ہر صارف کی مالیاتی کارروائی کا مستقل ناقابلِ ترمیم لاگ، ایڈمن لاگ ان پر 4 ہندسوں کا ماسٹر سیکیورٹی پن، اور حساس تبدیلیوں کا مکمل سراغ۔
                    </p>
                </div>

                <!-- Module 12 -->
                <div class="module-card">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                        <span style="font-size:22px;">🖨️</span>
                        <b style="font-size:13.5px;color:#0f172a;">12. ڈوئل پرنٹنگ (A4 اور 80mm تھرمل)</b>
                    </div>
                    <p style="margin:0;font-size:12px;color:#475569;line-height:1.6;">
                        دکانوں والے چھوٹے پرنٹر کی تیز رفتار تھرمل پرچی (بشمول تصدیقی QR Code) اور باقاعدہ لیٹر ہیڈ پرنٹنگ دونوں کی بیک وقت سہولت۔
                    </p>
                </div>

                <!-- Module 13 -->
                <div class="module-card" style="border-color:#fcd34d;background:#fffdfa;">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                        <span style="font-size:22px;">📜</span>
                        <b style="font-size:13.5px;color:#92400e;">13. لائف ٹائم ایکسیلنس سرٹیفکیٹ و اعزازات</b>
                    </div>
                    <p style="margin:0;font-size:12px;color:#475569;line-height:1.6;">
                        صدر، چیف پیٹرن اور نمایاں ڈونرز کے لیے باوقار توصیفی سند (Certificate of Excellence) کا خودکار کمپیوٹرائزڈ اجرا مع گولڈن اسٹیمپ، تصویر اور لائیو تصدیقی کوڈ۔
                    </p>
                </div>

                <!-- Module 14 -->
                <div class="module-card" style="border-color:#86efac;background:#f0fdf4;">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                        <span style="font-size:22px;">🧮</span>
                        <b style="font-size:13.5px;color:#166534;">14. زکوٰۃ کیلکولیٹر و شرعی نصاب مینیجر</b>
                    </div>
                    <p style="margin:0;font-size:12px;color:#475569;line-height:1.6;">
                        سونے چاندی کے مارکیٹ ریٹس، نقدی، مالِ تجارت اور قرضوں کی کٹوتی کے بعد زکوٰۃ کا 1-کلک شرعی حساب اور براہِ راست زکوٰۃ وصولی واؤچر کا اجرا۔
                    </p>
                </div>

                <!-- Module 15 -->
                <div class="module-card" style="border-color:#93c5fd;background:#eff6ff;">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                        <span style="font-size:22px;">👥</span>
                        <b style="font-size:13.5px;color:#1e40af;">15. جدید یوزر رائٹس و 18 شعبہ جات پرمیشنز</b>
                    </div>
                    <p style="margin:0;font-size:12px;color:#475569;line-height:1.6;">
                        سیٹنگز میں تمام 18 شعبوں کے انفرادی اختیارات۔ کیشیئرز، نگرانوں اور صوبائی ڈائریکٹرز کے لیے 1-کلک پری سیٹس، برانچ مینجمنٹ اور سیکیورٹی کنٹرول۔
                    </p>
                </div>

                <!-- Module 16 -->
                <div class="module-card" style="border-color:#cbd5e1;background:#f8fafc;">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                        <span style="font-size:22px;">💾</span>
                        <b style="font-size:13.5px;color:#0f172a;">16. کلاؤڈ آرکائیو، بیک اپس و ڈیزاسٹر ریکوری</b>
                    </div>
                    <p style="margin:0;font-size:12px;color:#475569;line-height:1.6;">
                        روزانہ خودکار کرون ڈیٹا بیس بیک اپ، کلاؤڈ محفوظ اسٹوریج، اور ہنگامی خرابی پر سابقہ ڈیٹا کی 1-سیکنڈ فوری مکمل بحالی (Disaster Recovery)۔
                    </p>
                </div>

            </div>
        </div>

        <!-- Institutional Terms & Guarantee -->
        <div class="terms-section" style="background:#f8fafc;padding:24px 36px;border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;">
            <div class="terms-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:20px;font-size:12.5px;">
                <div style="background:#ffffff;border:1px solid #cbd5e1;border-radius:8px;padding:14px;">
                    <b style="color:#1e3a8a;font-size:13px;display:block;margin-bottom:6px;">🔒 100% ڈیٹا ملکیت اور رازداری:</b>
                    <span style="color:#475569;line-height:1.6;display:block;">
                        آپ کے ادارے کے تمام مالیاتی، اکاؤنٹنگ، عطیات، طلباء اور اساتذہ کے کوائف 100% آپ کے ادارے کی ذاتی ملکیت ہیں۔ کوئی بیرونی تھرڈ پارٹی آپ کے ڈیٹا تک رسائی نہیں کر سکتی۔
                    </span>
                </div>

                <div style="background:#ffffff;border:1px solid #cbd5e1;border-radius:8px;padding:14px;">
                    <b style="color:#15803d;font-size:13px;display:block;margin-bottom:6px;">📜 لائف ٹائم انٹرپرائز لائسنس:</b>
                    <span style="color:#475569;line-height:1.6;display:block;">
                        ماہانہ یا سالانہ سافٹ ویئر رینٹل کی پریشانی سے آزاد۔ لامحدود برانچز اور کیشیئرز کے لیے تاحیات پرپیچوئل لائسنس اور خودکار کلاؤڈ سپورٹ۔
                    </span>
                </div>
            </div>
        </div>

        <!-- Call to Action & Contact Details Footer -->
        <div class="brochure-footer" style="background:#0f172a;color:#ffffff;padding:28px 36px;">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:18px;">
                <div>
                    <span style="font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;font-weight:700;">
                        Developed &amp; Powered By
                    </span>
                    <h3 style="margin:3px 0 0;font-size:18px;color:#ffffff;">
                        SoftifyX Technologies &bull; Enterprise Cloud Stack
                    </h3>
                    <div style="font-size:12.5px;color:#cbd5e1;margin-top:4px;">
                        دینی و فلاحی اداروں کے لیے اسپیشلائزڈ سوفٹ ویئر ڈویلپمنٹ و سرور ہوسٹنگ سروسز
                    </div>
                </div>

                <!-- Contact & WhatsApp CTA Box -->
                <div class="footer-cta-box" style="background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.2);padding:12px 18px;border-radius:10px;text-align:right;">
                    <div style="font-size:11px;color:#cbd5e1;">بالمشافہ ڈیمو یا آن لائن ٹیسٹنگ کے لیے رابطہ فرمائیں:</div>
                    <div style="font-size:18px;font-weight:800;color:#22c55e;margin:3px 0;" dir="ltr">
                        +92 331 7240256
                    </div>
                    <div style="font-size:11px;color:#93c5fd;">
                        WhatsApp Helpline &bull; info@softifyx.com
                    </div>
                </div>
            </div>

            <div style="text-align:center;margin-top:20px;padding-top:14px;border-top:1px solid rgba(255,255,255,0.1);font-size:11px;color:#94a3b8;">
                &copy; <?=date('Y')?> SoftifyX Technologies. All Rights Reserved. Enterprise Madrasa &amp; Welfare Trust Financial ERP.
            </div>
        </div>

    </div>

</body>
</html>

