<?php
/**
 * Public Online Donation & Pledge Portal
 * Enables donors worldwide to register recurring monthly pledges or one-time contributions,
 * select international currencies (PKR, GBP, USD, SAR, AED, EUR), upload payment slips directly,
 * view official trust bank/easypaisa details with 1-click copy, and share appeals on WhatsApp.
 */
require __DIR__ . '/../app/bootstrap.php';

// Public page: NO require_login()

$orgName = function_exists('get_setting') ? get_setting('madrasa_name', 'Mangalchai Welfare Organization') : 'Mangalchai Welfare Organization';
$orgSubtitle = function_exists('get_setting') ? get_setting('org_subtitle', 'Financial Accounting & Welfare Trust') : 'Financial Accounting & Welfare Trust';
$orgPhone = function_exists('get_setting') ? get_setting('madrasa_phone', '+92 331 7240256') : '+92 331 7240256';
$orgAddress = function_exists('get_setting') ? get_setting('madrasa_address', '') : '';
$orgLogo = function_exists('get_org_logo_url') ? get_org_logo_url() : null;
$baseUrl = function_exists('get_app_base_url') ? get_app_base_url() : '';

// Bank & Donation Settings
$bankTitle = get_setting('donation_bank_title', $orgName);
$bankName = get_setting('donation_bank_name', 'Meezan Bank Limited');
$bankAccount = get_setting('donation_bank_account', '01020304050607');
$bankIban = get_setting('donation_bank_iban', 'PK00MEZN0001020304050607');
$easypaisaDetails = get_setting('donation_easypaisa', $orgPhone . ' (' . $orgName . ')');
$appealTitle = get_setting('donation_appeal_title', 'تعلیمی و فلاحی خدمات میں ہمارا ساتھ دیں');
$appealText = get_setting('donation_appeal_text', 'آپ کا دیا ہوا صدقہ، زکوٰۃ اور عطیات یتامیٰ و مستحق طلباء کی مفت رہائش، خوراک، تعلیم اور غریب گھرانوں کی کفالت پر دیانت داری سے خرچ کیے جاتے ہیں۔');
$officialWhatsApp = get_setting('donation_whatsapp', $orgPhone);

// Fallback to active bank account in accounts table if not set in settings
if ($bankAccount === '01020304050607') {
    try {
        $firstBank = $pdo->query("SELECT account_name FROM accounts WHERE is_cash_or_bank = 1 AND is_active = 1 AND (account_code = '1010' OR account_name LIKE '%bank%' OR account_name LIKE '%بینک%') ORDER BY (account_code = '1010') DESC, id ASC LIMIT 1")->fetchColumn();
        if ($firstBank) {
            $bankName = $firstBank;
        }
    } catch (Exception $e) {}
}

// Fetch active funds for selection
$activeFunds = [];
try {
    $activeFunds = $pdo->query("SELECT id, name, fund_code, description, is_restricted FROM funds WHERE is_active = 1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $activeFunds = [];
}

// CSRF token generation
if (empty($_SESSION['donate_csrf_token'])) {
    $_SESSION['donate_csrf_token'] = bin2hex(random_bytes(24));
}
$csrfToken = $_SESSION['donate_csrf_token'];

$error = '';
$isSubmitted = false;
$submittedData = null;

// Currency exchange rates relative to PKR (approximate benchmarks)
$currencyRates = [
    'PKR' => ['rate' => 1.0, 'symbol' => 'Rs.', 'name' => 'پاکستانی روپے'],
    'GBP' => ['rate' => 360.0, 'symbol' => '£', 'name' => 'برطانوی پاؤنڈ'],
    'USD' => ['rate' => 280.0, 'symbol' => '$', 'name' => 'امریکی ڈالر'],
    'SAR' => ['rate' => 74.5, 'symbol' => 'SAR', 'name' => 'سعودی ریال'],
    'AED' => ['rate' => 76.0, 'symbol' => 'AED', 'name' => 'اماراتی درہم'],
    'EUR' => ['rate' => 300.0, 'symbol' => '€', 'name' => 'یورو'],
];

// Handle Post-Donation Slip Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_post_donation_slip') {
    $postDonorId = (int)($_POST['donor_id'] ?? 0);
    $postSig = $_POST['sig'] ?? '';
    $secretKey = $config['app']['session_name'] ?? 'trust_donation_verify_key_2026';

    if ($postDonorId > 0 && isset($_FILES['post_proof_slip']) && $_FILES['post_proof_slip']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['post_proof_slip'];
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (in_array($ext, $allowedExts, true) && $file['size'] <= 5 * 1024 * 1024) {
            $upDir = __DIR__ . '/uploads/receipts/';
            if (!is_dir($upDir)) {
                @mkdir($upDir, 0755, true);
            }
            $storedName = 'proof_don_' . $postDonorId . '_' . time() . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $upDir . $storedName)) {
                $slipPath = 'uploads/receipts/' . $storedName;
                try {
                    $pdo->prepare('UPDATE donors SET proof_receipt = ? WHERE id = ?')->execute([$slipPath, $postDonorId]);
                } catch (Exception $e) {}

                header('Location: ' . $baseUrl . '/donate.php?success=1&id=' . $postDonorId . '&amt=' . urlencode($_POST['amt'] ?? '0') . '&type=' . urlencode($_POST['type'] ?? '') . '&fund=' . urlencode($_POST['fund'] ?? '') . '&name=' . urlencode($_POST['name'] ?? '') . '&sig=' . urlencode($postSig) . '&slip_uploaded=1');
                exit;
            }
        }
    }
}

// Handle Main Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_public_donation') {
    // 1. Anti-spam honeypot check
    if (!empty($_POST['website_url'])) {
        header('Location: ' . $baseUrl . '/donate.php?status=received');
        exit;
    }

    // 2. CSRF Token verification
    $postCsrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrfToken, $postCsrf)) {
        $error = 'سیکیورٹی سیشن کی مدت ختم ہو گئی ہے۔ برائے مہربانی صفحہ ریفریش کر کے دوبارہ کوشش کریں۔ (Session expired)';
    }

    $donorName = trim($_POST['donor_name'] ?? '');
    $phoneRaw = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $cityAddress = trim($_POST['city_address'] ?? '');
    $contributionType = in_array($_POST['contribution_type'] ?? '', ['pledge', 'onetime'], true) ? $_POST['contribution_type'] : 'pledge';
    $fundId = (int)($_POST['fund_id'] ?? 0);
    $selectedCurrency = trim($_POST['currency'] ?? 'PKR');
    if (!array_key_exists($selectedCurrency, $currencyRates)) {
        $selectedCurrency = 'PKR';
    }
    $enteredAmount = (float)($_POST['amount'] ?? 0);
    $currencyRate = (float)($currencyRates[$selectedCurrency]['rate'] ?? 1.0);
    
    // Amount in PKR
    $pkrAmount = ($selectedCurrency === 'PKR') ? $enteredAmount : round($enteredAmount * $currencyRate, 2);

    $pledgeDay = (int)($_POST['pledge_day'] ?? 1);
    if ($pledgeDay < 1 || $pledgeDay > 28) {
        $pledgeDay = 1;
    }
    $notes = trim($_POST['notes'] ?? '');

    if (empty($error)) {
        if (mb_strlen($donorName) < 2) {
            $error = 'برائے مہربانی اپنا مکمل اور درست نام درج فرمائیں۔ (Name is required)';
        } elseif (empty($phoneRaw) || strlen(preg_replace('/[^0-9]/', '', $phoneRaw)) < 7) {
            $error = 'برائے مہربانی اپنا درست موبائل یا واٹس ایپ نمبر درج فرمائیں تاکہ رسید بھیجی جا سکے۔ (Valid WhatsApp/Phone is required)';
        } elseif ($enteredAmount <= 0 || ($selectedCurrency === 'PKR' && $enteredAmount < 100)) {
            $error = 'عطیہ یا کفالت کی کم از کم رقم 100 روپے ہونی چاہیے۔ (Minimum amount is Rs. 100)';
        }
    }

    if (empty($error)) {
        $phoneNorm = normalize_pakistan_phone($phoneRaw);
        if (empty($phoneNorm)) {
            $phoneNorm = preg_replace('/[^0-9]/', '', $phoneRaw);
        }

        // Validate fund
        $selectedFundName = 'عمومی عطیات / فلاحی فنڈ';
        $validFundId = null;
        if ($fundId > 0) {
            foreach ($activeFunds as $af) {
                if ((int)$af['id'] === $fundId) {
                    $validFundId = $fundId;
                    $selectedFundName = $af['name'];
                    break;
                }
            }
        }

        // Handle Payment Slip Upload if provided on initial submission
        $proofSlipPath = null;
        if (isset($_FILES['proof_slip']) && $_FILES['proof_slip']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['proof_slip'];
            $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if (in_array($ext, $allowedExts, true) && $file['size'] <= 5 * 1024 * 1024) {
                $upDir = __DIR__ . '/uploads/receipts/';
                if (!is_dir($upDir)) {
                    @mkdir($upDir, 0755, true);
                }
                $storedName = 'proof_don_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $upDir . $storedName)) {
                    $proofSlipPath = 'uploads/receipts/' . $storedName;
                }
            }
        }

        try {
            $isPledge = ($contributionType === 'pledge');
            $currencyNotice = ($selectedCurrency !== 'PKR') ? " [کرنسی: {$selectedCurrency} " . number_format($enteredAmount, 2) . " مساوی PKR " . number_format($pkrAmount) . "]" : "";
            $portalNote = '🌐 آن لائن پورٹل سے رجسٹرڈ ہوا (' . date('d-M-Y') . ')۔ ' . ($isPledge ? 'ماہانہ کفالت: Rs. ' . number_format($pkrAmount) : 'یکمشت عطیہ: Rs. ' . number_format($pkrAmount)) . $currencyNotice;
            if (!empty($notes)) {
                $portalNote .= ' [دعائیہ پیغام: ' . $notes . ']';
            }

            // Check if donor already exists by phone
            $stmtCheck = $pdo->prepare("SELECT * FROM donors WHERE phone = ? OR phone = ? LIMIT 1");
            $stmtCheck->execute([$phoneNorm, $phoneRaw]);
            $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            $donorId = 0;
            if ($existing) {
                $donorId = (int)$existing['id'];
                $existingNotes = trim($existing['notes'] ?? '');
                $updatedNotes = $existingNotes ? ($existingNotes . "\n" . $portalNote) : $portalNote;

                if ($isPledge) {
                    $stmtUpd = $pdo->prepare("UPDATE donors SET 
                        donor_name = COALESCE(NULLIF(?, ''), donor_name),
                        address = COALESCE(NULLIF(?, ''), address),
                        email = COALESCE(NULLIF(?, ''), email),
                        notes = ?,
                        proof_receipt = COALESCE(?, proof_receipt),
                        pledge_amount = ?,
                        pledge_fund_id = ?,
                        pledge_frequency = 'monthly',
                        pledge_day = ?,
                        is_pledge_active = 1,
                        pledge_notes = ?
                        WHERE id = ?");
                    $stmtUpd->execute([
                        $donorName,
                        $cityAddress,
                        $email,
                        $updatedNotes,
                        $proofSlipPath,
                        $pkrAmount,
                        $validFundId,
                        $pledgeDay,
                        'آن لائن پورٹل سے فعال شدہ' . $currencyNotice,
                        $donorId
                    ]);
                } else {
                    $stmtUpd = $pdo->prepare("UPDATE donors SET 
                        donor_name = COALESCE(NULLIF(?, ''), donor_name),
                        address = COALESCE(NULLIF(?, ''), address),
                        email = COALESCE(NULLIF(?, ''), email),
                        notes = ?,
                        proof_receipt = COALESCE(?, proof_receipt)
                        WHERE id = ?");
                    $stmtUpd->execute([
                        $donorName,
                        $cityAddress,
                        $email,
                        $updatedNotes,
                        $proofSlipPath,
                        $donorId
                    ]);
                }
            } else {
                $stmtIns = $pdo->prepare("INSERT INTO donors 
                    (donor_name, phone, email, address, notes, proof_receipt, fund_id, pledge_amount, pledge_fund_id, pledge_frequency, pledge_day, is_pledge_active, pledge_notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmtIns->execute([
                    $donorName,
                    $phoneNorm,
                    $email,
                    $cityAddress,
                    $portalNote,
                    $proofSlipPath,
                    $validFundId,
                    $isPledge ? $pkrAmount : 0.00,
                    $isPledge ? $validFundId : null,
                    'monthly',
                    $isPledge ? $pledgeDay : 1,
                    $isPledge ? 1 : 0,
                    $isPledge ? ('آن لائن رجسٹریشن' . $currencyNotice) : null
                ]);
                $donorId = (int)$pdo->lastInsertId();
            }

            // Create success verify signature
            $secretKey = $config['app']['session_name'] ?? 'trust_donation_verify_key_2026';
            $verifySig = substr(hash_hmac('sha256', "don_{$donorId}_{$pkrAmount}", $secretKey), 0, 16);

            header('Location: ' . $baseUrl . '/donate.php?success=1&id=' . $donorId . '&amt=' . urlencode((string)$pkrAmount) . '&famt=' . urlencode((string)$enteredAmount) . '&curr=' . urlencode($selectedCurrency) . '&type=' . urlencode($contributionType) . '&fund=' . urlencode($selectedFundName) . '&name=' . urlencode($donorName) . '&sig=' . $verifySig);
            exit;
        } catch (Exception $e) {
            $error = 'رجسٹریشن کے دوران تکنیکی خرابی پیش آئی: ' . $e->getMessage();
        }
    }
}

// Success State Display
if (isset($_GET['success']) && $_GET['success'] === '1' && !empty($_GET['id']) && !empty($_GET['sig'])) {
    $confId = (int)$_GET['id'];
    $confAmt = (float)($_GET['amt'] ?? 0);
    $secretKey = $config['app']['session_name'] ?? 'trust_donation_verify_key_2026';
    $expectedSig = substr(hash_hmac('sha256', "don_{$confId}_{$confAmt}", $secretKey), 0, 16);

    if (hash_equals($expectedSig, $_GET['sig'] ?? '')) {
        $isSubmitted = true;
        $savedReceipt = null;
        try {
            $savedReceipt = $pdo->query("SELECT proof_receipt FROM donors WHERE id = {$confId}")->fetchColumn();
        } catch (Exception $e) {}

        $currCode = $_GET['curr'] ?? 'PKR';
        $foreignAmt = (float)($_GET['famt'] ?? $confAmt);

        $submittedData = [
            'id' => $confId,
            'name' => $_GET['name'] ?? 'محترم معاون',
            'amount' => $confAmt,
            'foreign_amount' => $foreignAmt,
            'currency' => $currCode,
            'type' => ($_GET['type'] ?? '') === 'pledge' ? 'ماہانہ باقاعدہ کفالت (Monthly Pledge)' : 'یکمشت عطیہ / صدقہ (One-Time)',
            'is_pledge' => (($_GET['type'] ?? '') === 'pledge'),
            'fund' => $_GET['fund'] ?? 'عمومی فلاحی فنڈ',
            'proof_receipt' => $savedReceipt ?: null,
        ];
    }
}

// WhatsApp Office Confirmation Message
$officeWaMsg = '';
if ($submittedData) {
    $amountDesc = "Rs. " . number_format($submittedData['amount']);
    if ($submittedData['currency'] !== 'PKR') {
        $amountDesc = $submittedData['currency'] . " " . number_format($submittedData['foreign_amount'], 2) . " (مساوی PKR " . number_format($submittedData['amount']) . ")";
    }

    $officeWaMsg = "السلام علیکم ورحمۃ اللہ!\n" .
        "میں نے ٹرسٹ کے آن لائن پورٹل پر رجسٹریشن کروائی ہے۔\n" .
        "🔹 رجسٹریشن کوڈ: #DON-" . str_pad((string)$submittedData['id'], 4, '0', STR_PAD_LEFT) . "\n" .
        "🔹 نام: *" . $submittedData['name'] . "*\n" .
        "🔹 مقصد / فنڈ: *" . $submittedData['fund'] . "*\n" .
        "🔹 نوعیت: *" . $submittedData['type'] . "*\n" .
        "🔹 رقم: *" . $amountDesc . "*\n\n" .
        "میں نے بینک / ایزی پیسہ سے رقم منتقل کر دی ہے۔ اسکرین شاٹ / رسید ملاحظہ فرمائیں اور ریکارڈ میں درج کر لیں۔ جزاکم اللہ خیراً!";
}
$officeWaUrl = 'https://api.whatsapp.com/send?phone=' . urlencode(normalize_pakistan_phone($officialWhatsApp)) . '&text=' . urlencode($officeWaMsg);

// Viral Share Message for Social Media
$shareMsg = "السلام علیکم ورحمۃ اللہ!\n" .
    "*" . $orgName . "* کے تحت مستحق یتامیٰ و طلباء کی کفالت، راشن اور تعلیمی منصوبوں میں اپنا حصہ ڈالیں۔\n" .
    "آپ باآسانی آن لائن ماہانہ کفالت یا یکمشت صدقہ درج کروا سکتے ہیں:\n" .
    "🌐 آن لائن رجسٹریشن لنک:\n" . $baseUrl . "/donate.php\n\n" .
    "«مَنْ دَلَّ عَلَى خَيْرٍ فَلَهُ مِثْلُ أَجْرِ فَاعِلِهِ» (نیکی کی طرف رہنمائی کرنے والے کو بھی اتنا ہی ثواب ملتا ہے)۔";
$viralShareUrl = 'https://api.whatsapp.com/send?text=' . urlencode($shareMsg);
?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>آن لائن عطیات و ماہانہ کفالت رجسٹریشن | <?=e($orgName)?></title>
    <link rel="stylesheet" href="assets/style.css?v=<?=function_exists('asset_version') ? asset_version('assets/style.css') : '1.0'?>">
    <link rel="icon" type="image/png" href="assets/icon-192.png">
    <style>
        :root {
            --brand-green: #059669;
            --brand-green-hover: #047857;
            --brand-gold: #d97706;
            --bg-page: #f8fafc;
            --card-border: #e2e8f0;
            --text-dark: #0f172a;
            --text-muted: #64748b;
        }
        body {
            background: linear-gradient(180deg, #f0fdf4 0%, #f8fafc 260px, #f1f5f9 100%);
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Nastaliq Urdu", "Jameel Noori Nastaleeq", Tahoma, Arial, sans-serif;
            color: var(--text-dark);
            margin: 0;
            padding: 0;
            direction: rtl;
            text-align: right;
            -webkit-font-smoothing: antialiased;
        }
        .portal-header {
            background: #ffffff;
            border-bottom: 1px solid #e2e8f0;
            padding: 16px 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
            position: sticky;
            top: 0;
            z-index: 50;
        }
        .portal-header-wrap {
            max-width: 760px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }
        .portal-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: inherit;
        }
        .portal-brand-logo {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            object-fit: contain;
            background: #fff;
            padding: 2px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 6px rgba(0,0,0,0.06);
            flex-shrink: 0;
        }
        .portal-brand-title {
            font-size: 18px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.25;
            margin: 0;
        }
        .portal-brand-sub {
            font-size: 12px;
            color: #64748b;
            margin: 2px 0 0;
        }
        .portal-trust-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }
        .portal-container {
            max-width: 760px;
            margin: 24px auto 60px;
            padding: 0 16px;
        }
        .appeal-hero {
            background: linear-gradient(135deg, #065f46 0%, #047857 50%, #0f766e 100%);
            border-radius: 20px;
            padding: 28px 24px;
            color: #ffffff;
            box-shadow: 0 12px 30px rgba(5, 150, 105, 0.22);
            margin-bottom: 24px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .appeal-hero h1 {
            font-size: 24px;
            font-weight: 800;
            margin: 0 0 10px;
            line-height: 1.4;
        }
        .appeal-hero p {
            font-size: 14.5px;
            line-height: 1.8;
            margin: 0 auto;
            max-width: 620px;
            opacity: 0.95;
        }
        .impact-tags {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 18px;
        }
        .impact-pill {
            background: rgba(255, 255, 255, 0.18);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 30px;
            padding: 4px 14px;
            font-size: 12.5px;
            backdrop-filter: blur(4px);
        }
        .form-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            padding: 28px 24px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.05);
            margin-bottom: 24px;
        }
        .section-label {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 16px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 16px;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 8px;
        }
        .section-num {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #059669;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 800;
        }
        .fund-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }
        .fund-option {
            position: relative;
            cursor: pointer;
        }
        .fund-option input[type="radio"] {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }
        .fund-box {
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px 14px;
            transition: all 0.2s ease;
            display: flex;
            flex-direction: column;
            gap: 4px;
            background: #f8fafc;
            height: 100%;
        }
        .fund-box:hover {
            border-color: #a7f3d0;
            background: #f0fdf4;
        }
        .fund-option input[type="radio"]:checked + .fund-box {
            border-color: #059669;
            background: #ecfdf5;
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.12);
        }
        .fund-title {
            font-size: 14px;
            font-weight: 800;
            color: #0f172a;
        }
        .fund-desc {
            font-size: 11.5px;
            color: #64748b;
            line-height: 1.4;
        }
        .contrib-toggle {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 20px;
        }
        .contrib-box {
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
            background: #f8fafc;
        }
        .contrib-box:hover {
            border-color: #cbd5e1;
        }
        .contrib-option input[type="radio"] {
            display: none;
        }
        .contrib-option input[type="radio"]:checked + .contrib-box {
            border-color: #059669;
            background: #ecfdf5;
            color: #065f46;
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.12);
        }
        .amount-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 14px;
        }
        .amt-chip {
            border: 1px solid #cbd5e1;
            background: #ffffff;
            border-radius: 24px;
            padding: 8px 18px;
            font-size: 13.5px;
            font-weight: 700;
            color: #1e293b;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .amt-chip:hover {
            border-color: #059669;
            color: #059669;
            background: #f0fdf4;
        }
        .amt-chip.active {
            border-color: #059669;
            background: #059669;
            color: #ffffff;
            box-shadow: 0 2px 8px rgba(5, 150, 105, 0.25);
        }
        .amt-chip.popular {
            position: relative;
        }
        .amt-chip.popular::after {
            content: 'مقبول';
            position: absolute;
            top: -9px;
            left: 50%;
            transform: translateX(-50%);
            background: #d97706;
            color: #ffffff;
            font-size: 9px;
            padding: 1px 6px;
            border-radius: 8px;
            font-weight: 800;
        }
        .input-group {
            margin-bottom: 18px;
        }
        .input-group label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 6px;
        }
        .input-control {
            width: 100%;
            padding: 12px 14px;
            border: 1.5px solid #cbd5e1;
            border-radius: 10px;
            font-size: 14.5px;
            transition: border-color 0.2s;
            box-sizing: border-box;
            background: #ffffff;
            color: #0f172a;
            direction: ltr;
            text-align: right;
            font-family: inherit;
        }
        .input-control:focus {
            outline: none;
            border-color: #059669;
            box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.15);
        }
        .submit-btn {
            width: 100%;
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            color: #ffffff;
            border: none;
            border-radius: 12px;
            padding: 16px 20px;
            font-size: 17px;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 8px 20px rgba(5, 150, 105, 0.3);
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .submit-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 24px rgba(5, 150, 105, 0.36);
        }
        .bank-card {
            background: #f8fafc;
            border: 1px dashed #94a3b8;
            border-radius: 14px;
            padding: 18px;
            margin-bottom: 16px;
        }
        .bank-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e2e8f0;
            font-size: 13.5px;
        }
        .bank-row:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }
        .copy-pill {
            background: #e2e8f0;
            border: none;
            border-radius: 6px;
            padding: 4px 10px;
            font-size: 11.5px;
            font-weight: 700;
            cursor: pointer;
            color: #1e293b;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.15s ease;
        }
        .copy-pill:hover {
            background: #cbd5e1;
        }
        .wa-btn {
            background: #25d366;
            color: #ffffff;
            font-weight: 800;
            text-decoration: none;
            border-radius: 12px;
            padding: 14px 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 15px;
            box-shadow: 0 4px 14px rgba(37, 211, 102, 0.3);
            transition: all 0.2s ease;
        }
        .wa-btn:hover {
            background: #1da851;
            color: #ffffff;
        }
        .share-btn {
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
            font-weight: 700;
            text-decoration: none;
            border-radius: 12px;
            padding: 12px 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 14px;
            transition: all 0.2s ease;
        }
        .share-btn:hover {
            background: #dbeafe;
        }
        .toast-copied {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%);
            background: #0f172a;
            color: #ffffff;
            padding: 10px 20px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 700;
            box-shadow: 0 10px 25px rgba(0,0,0,0.25);
            display: none;
            z-index: 1000;
            direction: rtl;
        }
    </style>
</head>
<body>

<!-- Header -->
<header class="portal-header">
    <div class="portal-header-wrap">
        <a href="donate.php" class="portal-brand">
            <?php if ($orgLogo): ?>
                <img src="<?=e($orgLogo)?>" alt="Logo" class="portal-brand-logo">
            <?php else: ?>
                <div class="portal-brand-logo" style="display:flex;align-items:center;justify-content:center;font-weight:900;color:#059669;font-size:20px;">
                    <?=e(mb_substr($orgName, 0, 1))?>
                </div>
            <?php endif; ?>
            <div>
                <h1 class="portal-brand-title"><?=e($orgName)?></h1>
                <p class="portal-brand-sub"><?=e($orgSubtitle)?></p>
            </div>
        </a>
        <div class="portal-trust-badge">
            <span>🛡️</span>
            <span>باقاعدہ تصدیق شدہ ٹرسٹ</span>
        </div>
    </div>
</header>

<div class="portal-container">

    <?php if ($isSubmitted && $submittedData): ?>
    <!-- SUCCESS / CONFIRMATION VIEW -->
    <?php if (isset($_GET['slip_uploaded']) && $_GET['slip_uploaded'] === '1'): ?>
        <div style="background:#ecfdf5;border:1.5px solid #6ee7b7;color:#065f46;padding:14px 18px;border-radius:12px;margin-bottom:16px;font-weight:700;font-size:14px;text-align:center;">
            ✓ ادائیگی کی رسید / اسکرین شاٹ کامیابی سے اپلوڈ ہو گیا ہے۔ ٹرسٹ انتظامیہ جلد تصدیق کر کے واٹس ایپ پر رسید جاری کر دے گی۔
        </div>
    <?php endif; ?>

    <div class="appeal-hero" style="background:linear-gradient(135deg, #047857 0%, #065f46 100%);">
        <div style="font-size:46px;margin-bottom:8px;">🤲✨</div>
        <h1 style="font-size:26px;">جزاکم اللہ خیراً و احسن الجزاء!</h1>
        <p style="font-size:15.5px;max-width:600px;">
            اللہ تعالیٰ آپ کے اس پاک جذبے، صدقہ و عطیہ کو شرفِ قبولیت بخشے، اور آپ کے جان و مال، کاروبار اور اہل و عیال میں بے شمار برکتیں اور آسانیاں عطا فرمائے۔ آمین۔
        </p>
        <div style="margin-top:14px;font-size:13px;background:rgba(255,255,255,0.18);display:inline-block;padding:4px 16px;border-radius:20px;">
            رجسٹریشن کوڈ: <strong>#DON-<?=str_pad((string)$submittedData['id'], 4, '0', STR_PAD_LEFT)?></strong>
        </div>
    </div>

    <!-- Summary Details -->
    <div class="form-card">
        <div class="section-label">
            <span class="section-num">✓</span>
            <span>رجسٹریشن کی تفصیلات (Registration Summary)</span>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px;font-size:14px;">
            <div style="background:#f8fafc;padding:12px;border-radius:10px;border:1px solid #e2e8f0;">
                <span style="color:#64748b;display:block;font-size:12px;">معاون کا نام:</span>
                <strong style="font-size:15px;color:#0f172a;"><?=e($submittedData['name'])?></strong>
            </div>
            <div style="background:#f8fafc;padding:12px;border-radius:10px;border:1px solid #e2e8f0;">
                <span style="color:#64748b;display:block;font-size:12px;">رقم (Amount):</span>
                <strong style="font-size:16px;color:#059669;">
                    Rs. <?=number_format($submittedData['amount'])?>
                    <?php if (!empty($submittedData['currency']) && $submittedData['currency'] !== 'PKR'): ?>
                        <small style="color:#b45309;font-size:12px;display:block;">(<?=e($submittedData['currency'])?> <?=number_format($submittedData['foreign_amount'], 2)?>)</small>
                    <?php endif; ?>
                </strong>
            </div>
            <div style="background:#f8fafc;padding:12px;border-radius:10px;border:1px solid #e2e8f0;">
                <span style="color:#64748b;display:block;font-size:12px;">مقصد / فنڈ:</span>
                <strong style="color:#0f172a;"><?=e($submittedData['fund'])?></strong>
            </div>
            <div style="background:#f8fafc;padding:12px;border-radius:10px;border:1px solid #e2e8f0;">
                <span style="color:#64748b;display:block;font-size:12px;">تعاون کی نوعیت:</span>
                <strong style="color:#0f172a;"><?=e($submittedData['type'])?></strong>
            </div>
        </div>

        <!-- Payment Slip Upload Box if not uploaded yet -->
        <?php if (!empty($submittedData['proof_receipt'])): ?>
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px 16px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;">
                <div style="color:#15803d;font-size:13.5px;font-weight:700;display:flex;align-items:center;gap:6px;">
                    <span>✓</span>
                    <span>آپ کی ادائیگی رسید منسلک ہے (Proof Attached)</span>
                </div>
                <a href="<?=e($submittedData['proof_receipt'])?>" target="_blank" style="color:#059669;font-weight:700;font-size:13px;text-decoration:underline;">
                    📷 رسید دیکھیں
                </a>
            </div>
        <?php else: ?>
            <div style="background:#fffbeb;border:1.5px dashed #d97706;border-radius:12px;padding:16px 18px;margin-bottom:20px;">
                <strong style="font-size:14px;color:#92400e;display:block;margin-bottom:4px;">
                    📷 کیا آپ نے رقم منتقل کر دی ہے؟ رسید اپلوڈ فرمائیں:
                </strong>
                <p style="font-size:12.5px;color:#78350f;margin:0 0 10px;">
                    بینک یا ایزی پیسہ منتقلی کی رسید کا اسکرین شاٹ یہاں منسلک کریں تاکہ انتظامیہ فوری کمپیوٹرائزڈ واؤچر جاری کر سکے۔
                </p>
                <form method="post" enctype="multipart/form-data" action="donate.php" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <input type="hidden" name="action" value="upload_post_donation_slip">
                    <input type="hidden" name="csrf_token" value="<?=e($csrfToken)?>">
                    <input type="hidden" name="donor_id" value="<?=$submittedData['id']?>">
                    <input type="hidden" name="sig" value="<?=e($_GET['sig'] ?? '')?>">
                    <input type="hidden" name="amt" value="<?=e($_GET['amt'] ?? '')?>">
                    <input type="hidden" name="famt" value="<?=e($_GET['famt'] ?? '')?>">
                    <input type="hidden" name="curr" value="<?=e($_GET['curr'] ?? '')?>">
                    <input type="hidden" name="type" value="<?=e($_GET['type'] ?? '')?>">
                    <input type="hidden" name="fund" value="<?=e($_GET['fund'] ?? '')?>">
                    <input type="hidden" name="name" value="<?=e($_GET['name'] ?? '')?>">
                    <input type="file" name="post_proof_slip" accept="image/*,.pdf" required style="font-size:13px;flex:1;min-width:200px;">
                    <button type="submit" style="background:#d97706;color:#ffffff;border:none;border-radius:8px;padding:8px 16px;font-size:13px;font-weight:700;cursor:pointer;">
                        📤 رسید اپلوڈ کریں
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <!-- Bank Details for Direct Transfer -->
        <div class="section-label" style="margin-top:20px;">
            <span class="section-num">💳</span>
            <span>رقم کی منتقلی کے لیے سرکاری اکاؤنٹس (Official Accounts)</span>
        </div>
        <p style="font-size:13px;color:#64748b;margin-top:-8px;margin-bottom:14px;">
            آپ درج ذیل بینک اکاؤنٹ یا ایزی پیسہ پر رقم منتقل فرما سکتے ہیں (1-کلک سے کاپی کریں):
        </p>

        <!-- Bank Transfer Card -->
        <div class="bank-card">
            <div style="font-weight:800;font-size:15px;color:#0f172a;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                <span>🏛️</span>
                <span>بینک ٹرانسفر (Bank Account)</span>
            </div>
            <div class="bank-row">
                <span style="color:#64748b;">بینک کا نام:</span>
                <strong><?=e($bankName)?></strong>
            </div>
            <div class="bank-row">
                <span style="color:#64748b;">اکاؤنٹ ٹائٹل:</span>
                <strong><?=e($bankTitle)?></strong>
            </div>
            <div class="bank-row">
                <span style="color:#64748b;">اکاؤنٹ نمبر:</span>
                <div style="display:flex;align-items:center;gap:8px;">
                    <code style="font-size:14px;color:#0f172a;direction:ltr;font-family:monospace;"><?=e($bankAccount)?></code>
                    <button type="button" class="copy-pill" onclick="copyText('<?=e(addslashes($bankAccount))?>', 'اکاؤنٹ نمبر کاپی ہو گیا!')">📋 کاپی</button>
                </div>
            </div>
            <?php if (!empty($bankIban)): ?>
            <div class="bank-row">
                <span style="color:#64748b;">IBAN نمبر:</span>
                <div style="display:flex;align-items:center;gap:8px;">
                    <code style="font-size:13px;color:#0f172a;direction:ltr;font-family:monospace;"><?=e($bankIban)?></code>
                    <button type="button" class="copy-pill" onclick="copyText('<?=e(addslashes($bankIban))?>', 'IBAN کاپی ہو گیا!')">📋 کاپی</button>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- EasyPaisa / JazzCash Card -->
        <?php if (!empty($easypaisaDetails)): ?>
        <div class="bank-card" style="border-color:#34d399;background:#f0fdf4;">
            <div style="font-weight:800;font-size:15px;color:#065f46;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                <span>📱</span>
                <span>ایزی پیسہ / جاز کیش (EasyPaisa / JazzCash)</span>
            </div>
            <div class="bank-row">
                <span style="color:#065f46;">نمبر و تفصیلات:</span>
                <div style="display:flex;align-items:center;gap:8px;">
                    <strong style="color:#0f172a;direction:ltr;"><?=e($easypaisaDetails)?></strong>
                    <button type="button" class="copy-pill" onclick="copyText('<?=e(addslashes($easypaisaDetails))?>', 'ایزی پیسہ تفصیلات کاپی ہو گئیں!')">📋 کاپی</button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- WhatsApp Office Send -->
        <div style="margin-top:22px;display:flex;flex-direction:column;gap:12px;">
            <a href="<?=e($officeWaUrl)?>" target="_blank" class="wa-btn">
                <span>📱</span>
                <span>دفتر کو واٹس ایپ پر رسید یا اسکرین شاٹ بھیجیں</span>
            </a>

            <a href="<?=e($viralShareUrl)?>" target="_blank" class="share-btn">
                <span>📢</span>
                <span>نیکی کا پیغام دوستوں سے شیئر کریں (Share Appeal on WhatsApp)</span>
            </a>

            <div style="text-align:center;margin-top:10px;">
                <a href="donate.php" style="color:#64748b;font-size:13.5px;text-decoration:none;">
                    ➕ ایک اور عطیہ یا کفالت درج کریں
                </a>
            </div>
        </div>
    </div>

    <?php else: ?>
    <!-- REGISTRATION FORM VIEW -->
    
    <div class="appeal-hero">
        <h1><?=e($appealTitle)?></h1>
        <p><?=e($appealText)?></p>
        <div class="impact-tags">
            <span class="impact-pill">🎓 یتامیٰ و طلباء کی مفت کفالت</span>
            <span class="impact-pill">🍲 خوراک و راشن تقسیم</span>
            <span class="impact-pill">🕌 تعمیری و عمومی فلاح</span>
            <span class="impact-pill">📜 کمپیوٹرائزڈ شفاف رسید</span>
        </div>
    </div>

    <?php if (!empty($error)): ?>
        <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:14px 18px;border-radius:12px;margin-bottom:20px;font-size:14px;font-weight:700;">
            ⚠️ <?=e($error)?>
        </div>
    <?php endif; ?>

    <form method="post" action="donate.php" id="donateForm" enctype="multipart/form-data">
        <input type="hidden" name="action" value="submit_public_donation">
        <input type="hidden" name="csrf_token" value="<?=e($csrfToken)?>">
        <!-- Honeypot for spam bots -->
        <input type="text" name="website_url" style="display:none;" tabindex="-1" autocomplete="off">

        <div class="form-card">
            <!-- Step 1: Cause / Fund Selection -->
            <div class="section-label">
                <span class="section-num">۱</span>
                <span>عطیہ کا مقصد یا فنڈ منتخب فرمائیں (Select Cause)</span>
            </div>

            <div class="fund-grid">
                <label class="fund-option">
                    <input type="radio" name="fund_id" value="0" checked>
                    <div class="fund-box">
                        <span class="fund-title">🌿 عمومی فلاحی فنڈ (General)</span>
                        <span class="fund-desc">جہاں بھی ٹرسٹ کو سب سے زیادہ ضرورت ہو</span>
                    </div>
                </label>
                <?php foreach ($activeFunds as $af): ?>
                <label class="fund-option">
                    <input type="radio" name="fund_id" value="<?=$af['id']?>">
                    <div class="fund-box">
                        <span class="fund-title">
                            <?=e($af['name'])?>
                            <?php if ($af['is_restricted']): ?>
                                <small style="color:#d97706;font-size:10px;">(مخصوص)</small>
                            <?php endif; ?>
                        </span>
                        <span class="fund-desc"><?=e($af['description'] ?: 'مستحقین کی براہ راست کفالت')?></span>
                    </div>
                </label>
                <?php endforeach; ?>
            </div>

            <!-- Step 2: Contribution Type & Amount -->
            <div class="section-label" style="margin-top:24px;">
                <span class="section-num">۲</span>
                <span>تعاون کی نوعیت، کرنسی اور رقم (Type &amp; Currency)</span>
            </div>

            <div class="contrib-toggle">
                <label class="contrib-option">
                    <input type="radio" name="contribution_type" value="pledge" checked id="typePledge" onchange="togglePledgeFields(true)">
                    <div class="contrib-box">
                        <div style="font-size:20px;margin-bottom:4px;">🔄</div>
                        <strong style="font-size:14.5px;display:block;">ماہانہ باقاعدہ کفالت</strong>
                        <span style="font-size:11.5px;color:#64748b;">(ہر ماہ مسلسل صدقہ جاریہ)</span>
                    </div>
                </label>
                <label class="contrib-option">
                    <input type="radio" name="contribution_type" value="onetime" id="typeOnetime" onchange="togglePledgeFields(false)">
                    <div class="contrib-box">
                        <div style="font-size:20px;margin-bottom:4px;">💎</div>
                        <strong style="font-size:14.5px;display:block;">یکمشت عطیہ / صدقہ</strong>
                        <span style="font-size:11.5px;color:#64748b;">(ایک بار ادائیگی)</span>
                    </div>
                </label>
            </div>

            <!-- Multi-Currency Selector -->
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;flex-wrap:wrap;gap:8px;">
                <label style="font-size:13px;font-weight:700;color:#334155;margin:0;">
                    کرنسی منتخب فرمائیں (Select Currency):
                </label>
                <select name="currency" id="currencySelect" onchange="onCurrencyChange()" style="padding:6px 12px;border-radius:8px;border:1.5px solid #cbd5e1;font-size:13px;font-weight:700;color:#0f172a;background:#fff;">
                    <option value="PKR" data-symbol="Rs." data-rate="1" selected>🇵🇰 PKR (پاکستانی روپے)</option>
                    <option value="GBP" data-symbol="£" data-rate="360">🇬🇧 GBP (£ پاؤنڈ - برطانیہ)</option>
                    <option value="USD" data-symbol="$" data-rate="280">🇺🇸 USD ($ ڈالر - امریکہ و کینیڈا)</option>
                    <option value="SAR" data-symbol="SAR" data-rate="74.5">🇸🇦 SAR (ر.س ریال - سعودی عرب)</option>
                    <option value="AED" data-symbol="AED" data-rate="76">🇦🇪 AED (د.إ درہم - متحدہ عرب امارات)</option>
                    <option value="EUR" data-symbol="€" data-rate="300">🇪🇺 EUR (€ یورو - یورپ)</option>
                </select>
            </div>

            <!-- Amount Preset Chips -->
            <div class="amount-chips" id="chipsContainer">
                <button type="button" class="amt-chip" onclick="setAmount(1000, this)">Rs. 1,000</button>
                <button type="button" class="amt-chip" onclick="setAmount(2000, this)">Rs. 2,000</button>
                <button type="button" class="amt-chip popular active" onclick="setAmount(5000, this)">Rs. 5,000</button>
                <button type="button" class="amt-chip" onclick="setAmount(10000, this)">Rs. 10,000</button>
                <button type="button" class="amt-chip" onclick="setAmount(25000, this)">Rs. 25,000</button>
                <button type="button" class="amt-chip" onclick="setCustomAmount(this)">دیگر رقم (Custom)</button>
            </div>

            <div class="input-group">
                <input type="number" name="amount" id="amountInput" class="input-control" value="5000" min="1" step="any" required placeholder="رقم درج کریں" oninput="updateConversionPreview()" style="font-size:18px;font-weight:800;color:#059669;letter-spacing:1px;">
                <div id="conversionPreview" style="font-size:12.5px;color:#059669;font-weight:700;margin-top:6px;display:none;"></div>
            </div>

            <!-- Preferred Day of Month (for Monthly Pledge) -->
            <div class="input-group" id="pledgeDayWrap" style="background:#f8fafc;padding:14px;border-radius:10px;border:1px solid #e2e8f0;">
                <label style="color:#0f172a;margin-bottom:4px;">
                    📅 ہر ماہ ادائیگی کی ترجیحی تاریخ (Preferred Day of Month):
                </label>
                <select name="pledge_day" class="input-control" style="direction:rtl;text-align:right;">
                    <option value="1">ہر ماہ کی 1 تاریخ</option>
                    <option value="5" selected>ہر ماہ کی 5 تاریخ</option>
                    <option value="10">ہر ماہ کی 10 تاریخ</option>
                    <option value="15">ہر ماہ کی 15 تاریخ</option>
                    <option value="20">ہر ماہ کی 20 تاریخ</option>
                    <option value="25">ہر ماہ کی 25 تاریخ</option>
                </select>
                <small style="color:#64748b;font-size:11.5px;display:block;margin-top:4px;">
                    ہم آپ کو ہر ماہ اس تاریخ پر واٹس ایپ پر کمپیوٹرائزڈ رسید اور یاد دہانی ارسال کریں گے۔
                </small>
            </div>

            <!-- Step 3: Donor Contact Information -->
            <div class="section-label" style="margin-top:26px;">
                <span class="section-num">۳</span>
                <span>معاون کی تفصیلات و رابطہ (Donor Information)</span>
            </div>

            <div class="input-group">
                <label>معاون کا مکمل نام (Full Name) *</label>
                <input type="text" name="donor_name" class="input-control" required placeholder="مثلاً: حاجی محمد اسلم صاحب" style="direction:rtl;text-align:right;">
            </div>

            <div class="input-group">
                <label>واٹس ایپ / موبائل نمبر (WhatsApp / Phone) *</label>
                <input type="tel" name="phone" class="input-control" required placeholder="مثلاً: 03001234567" style="direction:ltr;text-align:left;">
                <small style="color:#64748b;font-size:11.5px;margin-top:4px;display:block;">
                    اسی واٹس ایپ نمبر پر آپ کو ٹرسٹ کی آفیشل رسیدیں اور کارکردگی رپورٹس موصول ہوں گی۔
                </small>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="input-group">
                    <label>شہر / ملک (City / Country)</label>
                    <input type="text" name="city_address" class="input-control" placeholder="مثلاً: لاہور / لندن" style="direction:rtl;text-align:right;">
                </div>
                <div class="input-group">
                    <label>ای میل (Email - اختیاری)</label>
                    <input type="email" name="email" class="input-control" placeholder="optional@gmail.com" style="direction:ltr;text-align:left;">
                </div>
            </div>

            <!-- Direct Slip Upload (Optional during registration) -->
            <div class="input-group" style="background:#f8fafc;padding:14px;border-radius:10px;border:1.5px dashed #cbd5e1;">
                <label style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
                    <span>📷 ادائیگی کی رسید / اسکرین شاٹ (Payment Slip - اختیاری)</span>
                    <span style="font-size:11px;color:#64748b;font-weight:normal;">JPG, PNG, PDF (Max 5MB)</span>
                </label>
                <input type="file" name="proof_slip" accept="image/*,.pdf" style="font-size:13px;width:100%;padding:4px 0;">
                <small style="color:#64748b;font-size:11.5px;display:block;margin-top:4px;">
                    اگر آپ نے پہلے ہی رقم منتقل کر دی ہے تو رسید کا اسکرین شاٹ یہاں منسلک فرما دیں۔
                </small>
            </div>

            <div class="input-group">
                <label>خصوصی ہدایت یا دعائیہ کلمات (Prayer Request / Notes - اختیاری)</label>
                <textarea name="notes" class="input-control" rows="2" placeholder="اگر کوئی مخصوص دعا یا مرحومین کے ایصال ثواب کی نیت ہو تو یہاں تحریر فرمائیں..." style="direction:rtl;text-align:right;"></textarea>
            </div>

            <button type="submit" class="submit-btn" style="margin-top:20px;">
                <span>💚</span>
                <span>عطیہ / کفالت رجسٹر کریں (Submit Pledge)</span>
            </button>
        </div>
    </form>
    <?php endif; ?>

    <!-- Trust Footer -->
    <footer style="text-align:center;color:#64748b;font-size:12.5px;margin-top:30px;line-height:1.7;">
        <div><strong><?=e($orgName)?></strong> &bull; <?=e($orgSubtitle)?></div>
        <?php if (!empty($orgPhone)): ?>
            <div>رابطہ و معلومات: <a href="tel:<?=e($orgPhone)?>" style="color:#059669;text-decoration:none;direction:ltr;"><?=e($orgPhone)?></a></div>
        <?php endif; ?>
        <?php if (!empty($orgAddress)): ?>
            <div><?=e($orgAddress)?></div>
        <?php endif; ?>
        <div style="margin-top:8px;font-size:11px;color:#94a3b8;">
            نظام کا انتظام بذریعہ SoftifyX Financial Accounting ERP &bull; محفوظ ڈیٹا بیس اور باقاعدہ آڈٹ
        </div>
    </footer>
</div>

<!-- Copy Toast Notification -->
<div id="copyToast" class="toast-copied">کاپی ہو گیا!</div>

<script>
var currencyPresets = {
    'PKR': [1000, 2000, 5000, 10000, 25000],
    'GBP': [25, 50, 100, 250, 500],
    'USD': [30, 50, 100, 250, 500],
    'SAR': [100, 200, 500, 1000, 2500],
    'AED': [100, 200, 500, 1000, 2500],
    'EUR': [30, 50, 100, 250, 500]
};

function onCurrencyChange() {
    var sel = document.getElementById('currencySelect');
    var curr = sel.value;
    var opt = sel.options[sel.selectedIndex];
    var symbol = opt.getAttribute('data-symbol') || '';
    var presets = currencyPresets[curr] || currencyPresets['PKR'];
    var container = document.getElementById('chipsContainer');

    var html = '';
    presets.forEach(function(val, idx) {
        var isPop = (idx === 2) ? ' popular active' : '';
        html += '<button type="button" class="amt-chip' + isPop + '" onclick="setAmount(' + val + ', this)">' + symbol + ' ' + val.toLocaleString() + '</button> ';
    });
    html += '<button type="button" class="amt-chip" onclick="setCustomAmount(this)">دیگر رقم (Custom)</button>';
    container.innerHTML = html;

    // Default to the 3rd preset
    setAmount(presets[2]);
    updateConversionPreview();
}

function updateConversionPreview() {
    var sel = document.getElementById('currencySelect');
    var curr = sel.value;
    var rate = parseFloat(sel.options[sel.selectedIndex].getAttribute('data-rate')) || 1.0;
    var amt = parseFloat(document.getElementById('amountInput').value) || 0;
    var preview = document.getElementById('conversionPreview');

    if (curr !== 'PKR' && amt > 0) {
        var pkr = Math.round(amt * rate);
        preview.textContent = '≈ مساوی تقریباً: PKR ' + pkr.toLocaleString() + ' روپے';
        preview.style.display = 'block';
    } else {
        preview.style.display = 'none';
    }
}

function setAmount(amt, btn) {
    document.getElementById('amountInput').value = amt;
    document.querySelectorAll('.amt-chip').forEach(function(c) {
        c.classList.remove('active');
    });
    if (btn) btn.classList.add('active');
    updateConversionPreview();
}

function setCustomAmount(btn) {
    var inp = document.getElementById('amountInput');
    inp.value = '';
    inp.focus();
    document.querySelectorAll('.amt-chip').forEach(function(c) {
        c.classList.remove('active');
    });
    if (btn) btn.classList.add('active');
    updateConversionPreview();
}

function togglePledgeFields(isPledge) {
    var wrap = document.getElementById('pledgeDayWrap');
    if (wrap) {
        wrap.style.display = isPledge ? 'block' : 'none';
    }
}

function copyText(text, msg) {
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(function() {
            showToast(msg || 'کاپی ہو گیا!');
        }).catch(function() {
            fallbackCopy(text, msg);
        });
    } else {
        fallbackCopy(text, msg);
    }
}

function fallbackCopy(text, msg) {
    var textArea = document.createElement("textarea");
    textArea.value = text;
    textArea.style.position = "fixed";
    textArea.style.left = "-999999px";
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    try {
        document.execCommand('copy');
        showToast(msg || 'کاپی ہو گیا!');
    } catch (err) {
        alert('کاپی کرنے میں ناکامی: ' + text);
    }
    document.body.removeChild(textArea);
}

function showToast(msg) {
    var toast = document.getElementById('copyToast');
    if (toast) {
        toast.textContent = msg;
        toast.style.display = 'block';
        setTimeout(function() {
            toast.style.display = 'none';
        }, 2500);
    }
}
</script>
</body>
</html>
