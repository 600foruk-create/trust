<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

if (!has_permission('inventory.view') && !can_approve() && !has_permission('donors.view')) {
    redirect_with_alert('dashboard.php', 'error', 'آپ کے پاس انوینٹری اور راشن اسٹاک دیکھنے کا اختیار نہیں ہے۔');
}

$activePage = 'inventory';
$error = '';
$success = '';

// Ensure inventory tables exist (Auto-create if not yet created)
try {
    $pdo->query("SELECT 1 FROM inventory_items LIMIT 1");
} catch (Throwable $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_items (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          item_code VARCHAR(30) NOT NULL UNIQUE,
          item_name VARCHAR(150) NOT NULL,
          category ENUM('ration', 'construction', 'books', 'medical', 'general') NOT NULL DEFAULT 'ration',
          unit VARCHAR(30) NOT NULL DEFAULT 'KG',
          current_stock DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          min_alert_qty DECIMAL(12,2) NOT NULL DEFAULT 5.00,
          estimated_unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          notes TEXT NULL,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_inv_cat (category),
          INDEX idx_inv_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_transactions (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          transaction_type ENUM('in_donation', 'out_consumption', 'adjustment') NOT NULL,
          item_id INT UNSIGNED NOT NULL,
          donor_id BIGINT UNSIGNED NULL,
          quantity DECIMAL(12,2) NOT NULL,
          unit VARCHAR(30) NOT NULL,
          unit_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          total_estimated_value DECIMAL(14,2) NOT NULL DEFAULT 0.00,
          recipient_department VARCHAR(150) NULL,
          reference_no VARCHAR(50) NULL,
          notes TEXT NULL,
          created_by BIGINT UNSIGNED NOT NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_inv_tx_item (item_id),
          INDEX idx_inv_tx_donor (donor_id),
          INDEX idx_inv_tx_type (transaction_type),
          INDEX idx_inv_tx_date (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $ex) {}
}

$userId = (int)current_user()['id'];
$canManageInventory = has_permission('inventory.manage') || can_approve();
$madrasaName = function_exists('get_setting') ? get_setting('madrasa_name', 'Trust Accounts') : 'Trust Accounts';

// Handle Add New Inventory Item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_item') {
    verify_csrf();
    if (!$canManageInventory) {
        $error = 'آپ کے پاس نیا آئٹم شامل کرنے کا اختیار نہیں ہے۔';
    } else {
        $code = trim($_POST['item_code'] ?? '');
        $name = trim($_POST['item_name'] ?? '');
        $category = trim($_POST['category'] ?? 'ration');
        $unit = trim($_POST['unit'] ?? 'KG');
        $minQty = max(0, (float)($_POST['min_alert_qty'] ?? 5));
        $estPrice = max(0, (float)($_POST['estimated_unit_price'] ?? 0));
        $openingStock = max(0, (float)($_POST['opening_stock'] ?? 0));
        $notes = trim($_POST['notes'] ?? '');

        if ($code === '' || $name === '') {
            $error = 'براہ کرم آئٹم کوڈ اور نام درج فرمائیں۔';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO inventory_items (item_code, item_name, category, unit, current_stock, min_alert_qty, estimated_unit_price, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$code, $name, $category, $unit, $openingStock, $minQty, $estPrice, $notes]);
                $itemId = (int)$pdo->lastInsertId();

                if ($openingStock > 0) {
                    $stmtTx = $pdo->prepare("INSERT INTO inventory_transactions (transaction_type, item_id, quantity, unit, unit_value, total_estimated_value, recipient_department, notes, created_by) VALUES ('adjustment', ?, ?, ?, ?, ?, 'ابتدائی اسٹاک (Opening Stock)', 'افتتاحی بیلنس اندراج', ?)");
                    $stmtTx->execute([$itemId, $openingStock, $unit, $estPrice, ($openingStock * $estPrice), $userId]);
                }

                if (function_exists('log_audit_trail')) {
                    log_audit_trail('create_inventory_item', 'inventory_items', $itemId, ['code' => $code, 'name' => $name]);
                }
                $success = "نیا سامان/آئٹم '{$name}' کامیابی سے رجسٹر ہو گیا۔";
            } catch (Exception $e) {
                $error = 'آئٹم شامل نہیں ہو سکا (شاید کوڈ پہلے سے موجود ہو): ' . $e->getMessage();
            }
        }
    }
}

// Handle In-Kind Donation Receipt (ان کائنڈ راشن و سامان وصولی)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'receive_donation') {
    verify_csrf();
    if (!$canManageInventory) {
        $error = 'آپ کے پاس سامان وصولی کا اندراج کرنے کا اختیار نہیں ہے۔';
    } else {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $donorId = (int)($_POST['donor_id'] ?? 0);
        $qty = max(0.01, (float)($_POST['quantity'] ?? 0));
        $unitVal = max(0, (float)($_POST['unit_value'] ?? 0));
        $refNo = trim($_POST['reference_no'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($itemId <= 0 || $qty <= 0) {
            $error = 'براہ کرم سامان اور درست مقدار منتخب کریں۔';
        } else {
            try {
                $pdo->beginTransaction();

                $stmtItm = $pdo->prepare("SELECT item_name, unit, estimated_unit_price FROM inventory_items WHERE id = ? FOR UPDATE");
                $stmtItm->execute([$itemId]);
                $itm = $stmtItm->fetch(PDO::FETCH_ASSOC);
                if (!$itm) throw new RuntimeException("سامان ریکارڈ میں نہیں ملا۔");

                if ($unitVal <= 0) $unitVal = (float)$itm['estimated_unit_price'];
                $totalVal = $qty * $unitVal;
                $unit = $itm['unit'];

                // 1. Update Current Stock
                $pdo->prepare("UPDATE inventory_items SET current_stock = current_stock + ? WHERE id = ?")->execute([$qty, $itemId]);

                // 2. Insert Transaction
                $stmtTx = $pdo->prepare("INSERT INTO inventory_transactions (transaction_type, item_id, donor_id, quantity, unit, unit_value, total_estimated_value, reference_no, notes, created_by) VALUES ('in_donation', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmtTx->execute([$itemId, ($donorId > 0 ? $donorId : null), $qty, $unit, $unitVal, $totalVal, $refNo, $notes, $userId]);
                $txId = (int)$pdo->lastInsertId();

                $pdo->commit();

                if (function_exists('log_audit_trail')) {
                    log_audit_trail('inventory_in_donation', 'inventory_transactions', $txId, [
                        'item' => $itm['item_name'],
                        'quantity' => $qty,
                        'total_val' => $totalVal
                    ]);
                }
                $success = "عطیہ وصولی کامیابی سے درج کر لی گئی: {$qty} {$unit} {$itm['item_name']} (تخمینہ قیمت: Rs. " . number_format($totalVal, 2) . ")۔";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'عطیہ درج نہیں ہو سکا: ' . $e->getMessage();
            }
        }
    }
}

// Handle Issue / Consumption (اخراج و ترسیلِ راشن و سامان)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'issue_stock') {
    verify_csrf();
    if (!$canManageInventory) {
        $error = 'آپ کے پاس سامان کے اخراج کا اختیار نہیں ہے۔';
    } else {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $qty = max(0.01, (float)($_POST['quantity'] ?? 0));
        $dept = trim($_POST['recipient_department'] ?? 'طعام گاہ / مطبخ (Student Mess)');
        $refNo = trim($_POST['reference_no'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($itemId <= 0 || $qty <= 0) {
            $error = 'براہ کرم آئٹم اور مطلوبہ مقدار درج فرمائیں۔';
        } else {
            try {
                $pdo->beginTransaction();

                $stmtItm = $pdo->prepare("SELECT item_name, unit, current_stock, estimated_unit_price FROM inventory_items WHERE id = ? FOR UPDATE");
                $stmtItm->execute([$itemId]);
                $itm = $stmtItm->fetch(PDO::FETCH_ASSOC);
                if (!$itm) throw new RuntimeException("سامان ریکارڈ میں نہیں ملا۔");

                if ((float)$itm['current_stock'] < $qty) {
                    throw new RuntimeException("اسٹاک میں مطلوبہ مقدار موجود نہیں ہے! موجودہ دستیاب اسٹاک صرف {$itm['current_stock']} {$itm['unit']} ہے۔");
                }

                $unitVal = (float)$itm['estimated_unit_price'];
                $totalVal = $qty * $unitVal;
                $unit = $itm['unit'];

                // 1. Deduct Stock
                $pdo->prepare("UPDATE inventory_items SET current_stock = current_stock - ? WHERE id = ?")->execute([$qty, $itemId]);

                // 2. Insert Outward Transaction
                $stmtTx = $pdo->prepare("INSERT INTO inventory_transactions (transaction_type, item_id, quantity, unit, unit_value, total_estimated_value, recipient_department, reference_no, notes, created_by) VALUES ('out_consumption', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmtTx->execute([$itemId, $qty, $unit, $unitVal, $totalVal, $dept, $refNo, $notes, $userId]);
                $txId = (int)$pdo->lastInsertId();

                $pdo->commit();

                if (function_exists('log_audit_trail')) {
                    log_audit_trail('inventory_issue', 'inventory_transactions', $txId, [
                        'item' => $itm['item_name'],
                        'quantity' => $qty,
                        'dept' => $dept
                    ]);
                }
                $success = "اسٹاک اخراج درج ہو گیا: {$qty} {$unit} {$itm['item_name']} برائے {$dept}۔";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'اخراج درج نہیں ہو سکا: ' . $e->getMessage();
            }
        }
    }
}

// Fetch all active items
$items = $pdo->query("SELECT * FROM inventory_items WHERE is_active = 1 ORDER BY category ASC, item_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch recent 30 transactions
$stmtRecent = $pdo->query("SELECT tx.*, itm.item_name, itm.item_code, d.donor_name, u.full_name AS user_name 
    FROM inventory_transactions tx 
    JOIN inventory_items itm ON itm.id = tx.item_id 
    LEFT JOIN donors d ON d.id = tx.donor_id 
    LEFT JOIN users u ON u.id = tx.created_by 
    ORDER BY tx.id DESC LIMIT 40");
$transactions = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);

// Fetch Donors for dropdown
$donorsList = $pdo->query("SELECT id, donor_name, phone FROM donors ORDER BY donor_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// KPI aggregates
$totalStockValue = 0.00;
$lowStockCount = 0;
foreach ($items as $it) {
    $totalStockValue += ((float)$it['current_stock'] * (float)$it['estimated_unit_price']);
    if ((float)$it['current_stock'] <= (float)$it['min_alert_qty']) {
        $lowStockCount++;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>In-Kind Donations &amp; Ration Inventory | <?=e($madrasaName)?></title>
    <link rel="stylesheet" href="assets/style.css?v=<?=css_ver()?>">
</head>
<body>
<div class="app-shell">
    <?php require __DIR__ . '/../app/sidebar.php'; ?>

    <main class="main">
        <div style="max-width:1200px;margin:0 auto;padding:16px 20px;">

        <!-- Header & Action Buttons -->
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;margin-bottom:20px;">
            <div>
                <h1 style="font-size:22px;color:#0f172a;margin:0;display:flex;align-items:center;gap:10px;">
                    <span>📦</span>
                    <span>راشن و سامان انوینٹری (In-Kind Donations &amp; Ration Stock)</span>
                </h1>
                <p style="color:#64748b;font-size:13px;margin:4px 0 0;">
                    غیر نقدی عطیات (آٹا، چاول، گھی، درسی کتب، تعمیراتی سامان) کی وصولی، اسٹاک اور کچن و مستحقین کو ترسیل کا ریکارڈ
                </p>
            </div>

            <?php if ($canManageInventory): ?>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button type="button" onclick="openReceiveModal()" class="btn" style="background:#15803d;color:#ffffff;border:none;font-weight:700;padding:8px 14px;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-size:12.5px;">
                    📥 + وصولی راشن و عطیہ (Receive Donation)
                </button>
                <button type="button" onclick="openIssueModal()" class="btn" style="background:#d97706;color:#ffffff;border:none;font-weight:700;padding:8px 14px;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-size:12.5px;">
                    📤 - ترسیل و اخراج مطبخ (Issue to Mess)
                </button>
                <button type="button" onclick="openAddItemModal()" class="btn secondary" style="padding:8px 14px;font-weight:700;font-size:12.5px;">
                    ✨ نیا آئٹم بنائیں
                </button>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($error): ?>
            <div class="alert error" style="margin-bottom:16px;"><?=e($error)?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert success" style="margin-bottom:16px;"><?=e($success)?></div>
        <?php endif; ?>

        <!-- KPI Cards Grid -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));gap:14px;margin-bottom:20px;">
            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                <span style="font-size:12px;color:#64748b;font-weight:700;display:block;">کل رجسٹرڈ اشیاء (Total Items)</span>
                <b style="font-size:24px;color:#0f172a;display:block;margin:6px 0;"><?=count($items)?> اقسام</b>
                <span style="font-size:11.5px;color:#0284c7;">راشن، کتب، ادویات، تعمیراتی سامان</span>
            </div>

            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                <span style="font-size:12px;color:#64748b;font-weight:700;display:block;">موجودہ اسٹاک تخمینہ مالیت (Total Valuation)</span>
                <b style="font-size:24px;color:#15803d;display:block;margin:6px 0;">Rs. <?=number_format($totalStockValue, 2)?></b>
                <span style="font-size:11.5px;color:#166534;">گودام میں محفوظ نان کیش اثاثہ</span>
            </div>

            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                <span style="font-size:12px;color:#64748b;font-weight:700;display:block;">کم اسٹاک الرٹس (Low Stock Warning)</span>
                <b style="font-size:24px;color:<?=$lowStockCount > 0 ? '#dc2626' : '#15803d'?>;display:block;margin:6px 0;">
                    <?=$lowStockCount?> آئٹمز
                </b>
                <span style="font-size:11.5px;color:<?=$lowStockCount > 0 ? '#b91c1c' : '#64748b'?>;">
                    <?=$lowStockCount > 0 ? '⚠️ ان اشیاء کا اسٹاک فوری منگوائیں' : '✓ تمام اشیاء تسلی بخش ہیں'?>
                </span>
            </div>
        </div>

        <!-- Inventory Stock Directory Table -->
        <div style="background:#ffffff;border:1px solid #cbd5e1;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.05);margin-bottom:24px;">
            <div style="padding:14px 20px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
                <h3 style="margin:0;font-size:15px;color:#0f172a;">موجودہ اسٹاک رجسٹر (Current Stock Directory)</h3>
                <span style="font-size:12px;color:#64748b;">کل اقسام: <?=count($items)?></span>
            </div>

            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:13px;text-align:right;">
                    <thead>
                        <tr style="background:#f1f5f9;color:#334155;border-bottom:1.5px solid #cbd5e1;">
                            <th style="padding:12px 16px;text-align:left;">کوڈ و نامِ جنس (Item Code &amp; Name)</th>
                            <th style="padding:12px 16px;">کیٹیگری (Category)</th>
                            <th style="padding:12px 16px;">پیمائش اکائی (Unit)</th>
                            <th style="padding:12px 16px;">موجودہ اسٹاک (Balance)</th>
                            <th style="padding:12px 16px;">تخمینہ فی یونٹ قیمت</th>
                            <th style="padding:12px 16px;">کل مالیت (Value)</th>
                            <th style="padding:12px 16px;text-align:center;">حیثیت (Status)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                            <tr><td colspan="7" style="text-align:center;padding:24px;color:#94a3b8;">کوئی آئٹم درج نہیں ہے۔</td></tr>
                        <?php else: foreach ($items as $it): 
                            $currStk = (float)$it['current_stock'];
                            $minQty = (float)$it['min_alert_qty'];
                            $isLow = ($currStk <= $minQty);
                            $val = $currStk * (float)$it['estimated_unit_price'];
                            $catBadge = 'secondary';
                            $catUrdu = 'عمومی';
                            if ($it['category'] === 'ration') { $catBadge = 'badge-green'; $catUrdu = '🌾 راشن و خوراک'; }
                            elseif ($it['category'] === 'books') { $catBadge = 'badge-blue'; $catUrdu = '📚 کتب و درسیات'; }
                            elseif ($it['category'] === 'construction') { $catBadge = 'badge-yellow'; $catUrdu = '🧱 تعمیرات'; }
                            elseif ($it['category'] === 'medical') { $catBadge = 'badge-red'; $catUrdu = '💊 ادویات'; }
                        ?>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:12px 16px;text-align:left;">
                                <strong style="color:#0f172a;"><?=e($it['item_name'])?></strong>
                                <span style="font-size:11px;color:#64748b;display:block;">Code: <?=e($it['item_code'])?></span>
                            </td>
                            <td style="padding:12px 16px;">
                                <span class="badge <?=$catBadge?>" style="font-size:11px;font-weight:700;"><?=$catUrdu?></span>
                            </td>
                            <td style="padding:12px 16px;font-weight:600;color:#334155;"><?=e($it['unit'])?></td>
                            <td style="padding:12px 16px;font-weight:800;font-size:14px;color:<?=$isLow ? '#dc2626' : '#15803d'?>;">
                                <?=number_format($currStk, 2)?> <?=e($it['unit'])?>
                            </td>
                            <td style="padding:12px 16px;color:#64748b;">Rs. <?=number_format((float)$it['estimated_unit_price'], 2)?></td>
                            <td style="padding:12px 16px;font-weight:700;color:#0f172a;">Rs. <?=number_format($val, 2)?></td>
                            <td style="padding:12px 16px;text-align:center;">
                                <?php if ($isLow): ?>
                                    <span class="badge badge-red" style="font-size:10.5px;font-weight:700;">⚠️ کم اسٹاک (Low)</span>
                                <?php else: ?>
                                    <span class="badge badge-green" style="font-size:10.5px;font-weight:700;">✓ دستیاب</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Recent Transactions Log Table -->
        <div style="background:#ffffff;border:1px solid #cbd5e1;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
            <div style="padding:14px 20px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
                <h3 style="margin:0;font-size:15px;color:#0f172a;">حالیہ آمد و اخراج لاگ (Stock Movement Ledger)</h3>
                <span style="font-size:12px;color:#64748b;">حالیہ <?=count($transactions)?> ٹرانزیکشنز</span>
            </div>

            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:12.5px;text-align:right;">
                    <thead>
                        <tr style="background:#f1f5f9;color:#334155;border-bottom:1px solid #cbd5e1;">
                            <th style="padding:10px 14px;text-align:left;">تاریخ و وقت</th>
                            <th style="padding:10px 14px;text-align:center;">نوعیت (Type)</th>
                            <th style="padding:10px 14px;text-align:left;">سامان (Item)</th>
                            <th style="padding:10px 14px;">مقدار (Qty)</th>
                            <th style="padding:10px 14px;">ڈونر / شعبہ وصول کنندہ</th>
                            <th style="padding:10px 14px;">تخمینہ مالیت</th>
                            <th style="padding:10px 14px;">درج کنندہ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                            <tr><td colspan="7" style="text-align:center;padding:20px;color:#94a3b8;">ابھی تک سامان کی کوئی ٹرانزیکشن ریکارڈ نہیں ہے۔</td></tr>
                        <?php else: foreach ($transactions as $tx): 
                            $isIn = ($tx['transaction_type'] === 'in_donation');
                            $isOut = ($tx['transaction_type'] === 'out_consumption');
                        ?>
                        <tr style="border-bottom:1px solid #f8fafc;">
                            <td style="padding:10px 14px;text-align:left;color:#64748b;">
                                <?=date('d M Y, h:i A', strtotime($tx['created_at']))?>
                            </td>
                            <td style="padding:10px 14px;text-align:center;">
                                <?php if ($isIn): ?>
                                    <span class="badge badge-green" style="font-size:10.5px;">📥 عطیہ وصولی (In)</span>
                                <?php elseif ($isOut): ?>
                                    <span class="badge badge-yellow" style="font-size:10.5px;">📤 اخراج / ترسیل (Out)</span>
                                <?php else: ?>
                                    <span class="badge secondary" style="font-size:10.5px;">⚖️ ایڈجسٹمنٹ</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:10px 14px;text-align:left;">
                                <b><?=e($tx['item_name'])?></b>
                            </td>
                            <td style="padding:10px 14px;font-weight:700;color:<?=$isIn ? '#15803d' : '#b91c1c'?>;">
                                <?=$isIn ? '+' : ($isOut ? '-' : '')?><?=number_format((float)$tx['quantity'], 2)?> <?=e($tx['unit'])?>
                            </td>
                            <td style="padding:10px 14px;">
                                <?php if ($isIn): ?>
                                    <?=e($tx['donor_name'] ?: 'ڈونر (نامعلوم / گمنام)')?>
                                <?php else: ?>
                                    <?=e($tx['recipient_department'] ?: 'مطبخ و طعام گاہ')?>
                                <?php endif; ?>
                                <?php if (!empty($tx['reference_no'])): ?>
                                    <span style="font-size:10.5px;color:#64748b;display:block;">Ref: <?=e($tx['reference_no'])?></span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:10px 14px;font-weight:600;">
                                Rs. <?=number_format((float)$tx['total_estimated_value'], 2)?>
                            </td>
                            <td style="padding:10px 14px;color:#64748b;">
                                <?=e($tx['user_name'] ?: 'ایڈمن')?>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        </div>
    </main>
</div>

<!-- Modal 1: Receive In-Kind Donation -->
<div id="receiveModal" class="modal-backdrop" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.65);z-index:99999;align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(3px);">
    <div style="background:#ffffff;border-radius:12px;width:100%;max-width:520px;box-shadow:0 25px 35px -5px rgba(0,0,0,0.3);overflow:hidden;border:1px solid #cbd5e1;">
        <div style="padding:14px 18px;background:#15803d;color:#ffffff;display:flex;justify-content:space-between;align-items:center;">
            <b style="font-size:14.5px;">📥 راشن و سامان عطیہ وصولی (Receive In-Kind Donation)</b>
            <button type="button" onclick="closeReceiveModal()" style="background:none;border:none;color:#ffffff;font-size:20px;cursor:pointer;">&times;</button>
        </div>
        <form method="post" action="inventory.php" style="padding:18px 20px;">
            <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
            <input type="hidden" name="action" value="receive_donation">

            <div style="margin-bottom:12px;">
                <label style="display:block;font-size:12px;font-weight:700;color:#0f172a;margin-bottom:4px;">
                    Donor / Benefactor <span class="ur">(معاون / عطیہ دہندہ)</span>
                </label>
                <select name="donor_id" style="width:100%;padding:8px 10px;border:1.5px solid #cbd5e1;border-radius:6px;font-size:13px;">
                    <option value="0">-- گمنام / عمومی معاون (Anonymous) --</option>
                    <?php foreach ($donorsList as $d): ?>
                        <option value="<?=$d['id']?>"><?=e($d['donor_name'])?> (<?=e($d['phone'] ?: 'No Phone')?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom:12px;">
                <label style="display:block;font-size:12px;font-weight:700;color:#0f172a;margin-bottom:4px;">
                    Item Received <span class="ur">(موصولہ سامان / جنس)</span> *
                </label>
                <select name="item_id" required style="width:100%;padding:8px 10px;border:1.5px solid #cbd5e1;border-radius:6px;font-size:13px;font-weight:700;">
                    <?php foreach ($items as $it): ?>
                        <option value="<?=$it['id']?>"><?=e($it['item_name'])?> [موجودہ: <?=number_format((float)$it['current_stock'], 2)?> <?=e($it['unit'])?>]</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;color:#0f172a;margin-bottom:4px;">
                        Quantity <span class="ur">(وصول شدہ مقدار)</span> *
                    </label>
                    <input type="number" step="0.01" min="0.01" name="quantity" required placeholder="مثلاً 10" style="width:100%;font-size:14px;font-weight:700;padding:8px;border:1.5px solid #cbd5e1;border-radius:6px;box-sizing:border-box;">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;color:#0f172a;margin-bottom:4px;">
                        Estimated Price PKR <span class="ur">(تخمینہ قیمت فی یونٹ)</span>
                    </label>
                    <input type="number" step="1" min="0" name="unit_value" placeholder="اختیاری" style="width:100%;font-size:13px;padding:8px;border:1.5px solid #cbd5e1;border-radius:6px;box-sizing:border-box;">
                </div>
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block;font-size:12px;font-weight:700;color:#0f172a;margin-bottom:4px;">
                    Bilty / Receipt / Ref # <span class="ur">(بلٹی نمبر یا حوالہ)</span>
                </label>
                <input type="text" name="reference_no" placeholder="e.g. Bilty # 89201" style="width:100%;font-size:12.5px;padding:8px;border:1.5px solid #cbd5e1;border-radius:6px;box-sizing:border-box;">
            </div>

            <div style="margin-bottom:16px;">
                <label style="display:block;font-size:12px;font-weight:700;color:#0f172a;margin-bottom:4px;">
                    Notes / Description <span class="ur">(تفصیل و کیفیت)</span>
                </label>
                <input type="text" name="notes" placeholder="e.g. رمضان المبارک راشن پیکیجز کے لیے 10 بوریاں گندم" style="width:100%;font-size:12.5px;padding:8px;border:1.5px solid #cbd5e1;border-radius:6px;box-sizing:border-box;">
            </div>

            <div style="display:flex;justify-content:flex-end;gap:8px;padding-top:12px;border-top:1px solid #e2e8f0;">
                <button type="button" onclick="closeReceiveModal()" class="btn secondary" style="padding:8px 16px;">منسوخ کریں</button>
                <button type="submit" class="btn" style="background:#15803d;color:#ffffff;border:none;padding:8px 20px;font-weight:700;border-radius:6px;cursor:pointer;">
                    ✅ وصولی درج کریں (Record Inward)
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 2: Issue Stock (Outward) -->
<div id="issueModal" class="modal-backdrop" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.65);z-index:99999;align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(3px);">
    <div style="background:#ffffff;border-radius:12px;width:100%;max-width:520px;box-shadow:0 25px 35px -5px rgba(0,0,0,0.3);overflow:hidden;border:1px solid #cbd5e1;">
        <div style="padding:14px 18px;background:#d97706;color:#ffffff;display:flex;justify-content:space-between;align-items:center;">
            <b style="font-size:14.5px;">📤 سامان اخراج و ترسیلِ مطبخ (Issue to Mess / Dept)</b>
            <button type="button" onclick="closeIssueModal()" style="background:none;border:none;color:#ffffff;font-size:20px;cursor:pointer;">&times;</button>
        </div>
        <form method="post" action="inventory.php" style="padding:18px 20px;">
            <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
            <input type="hidden" name="action" value="issue_stock">

            <div style="margin-bottom:12px;">
                <label style="display:block;font-size:12px;font-weight:700;color:#0f172a;margin-bottom:4px;">
                    Select Item <span class="ur">(سامان منتخب کریں)</span> *
                </label>
                <select name="item_id" required style="width:100%;padding:8px 10px;border:1.5px solid #cbd5e1;border-radius:6px;font-size:13px;font-weight:700;">
                    <?php foreach ($items as $it): ?>
                        <option value="<?=$it['id']?>"><?=e($it['item_name'])?> [دستیاب: <?=number_format((float)$it['current_stock'], 2)?> <?=e($it['unit'])?>]</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;color:#0f172a;margin-bottom:4px;">
                        Issue Quantity <span class="ur">(اخراج مقدار)</span> *
                    </label>
                    <input type="number" step="0.01" min="0.01" name="quantity" required placeholder="مقدار" style="width:100%;font-size:14px;font-weight:700;padding:8px;border:1.5px solid #cbd5e1;border-radius:6px;box-sizing:border-box;">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;color:#0f172a;margin-bottom:4px;">
                        Recipient Dept <span class="ur">(وصول کنندہ شعبہ)</span> *
                    </label>
                    <input type="text" name="recipient_department" value="طعام گاہ / مطبخ (Student Mess)" required style="width:100%;font-size:12px;padding:8px;border:1.5px solid #cbd5e1;border-radius:6px;box-sizing:border-box;">
                </div>
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block;font-size:12px;font-weight:700;color:#0f172a;margin-bottom:4px;">
                    Notes / Requisition Ref <span class="ur">(طلب پرچی نمبر یا تفصیل)</span>
                </label>
                <input type="text" name="notes" placeholder="e.g. مطبخ ہفتہ وار راشن طلب پرچی نمبر 14" style="width:100%;font-size:12.5px;padding:8px;border:1.5px solid #cbd5e1;border-radius:6px;box-sizing:border-box;">
            </div>

            <div style="display:flex;justify-content:flex-end;gap:8px;padding-top:12px;border-top:1px solid #e2e8f0;">
                <button type="button" onclick="closeIssueModal()" class="btn secondary" style="padding:8px 16px;">منسوخ کریں</button>
                <button type="submit" class="btn" style="background:#d97706;color:#ffffff;border:none;padding:8px 20px;font-weight:700;border-radius:6px;cursor:pointer;">
                    📤 اخراج درج کریں (Confirm Issue)
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 3: Add New Item -->
<div id="addItemModal" class="modal-backdrop" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.65);z-index:99999;align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(3px);">
    <div style="background:#ffffff;border-radius:12px;width:100%;max-width:500px;box-shadow:0 25px 35px -5px rgba(0,0,0,0.3);overflow:hidden;border:1px solid #cbd5e1;">
        <div style="padding:14px 18px;background:#0f172a;color:#ffffff;display:flex;justify-content:space-between;align-items:center;">
            <b style="font-size:14.5px;">✨ نیا انوینٹری آئٹم رجسٹر کریں (Add Inventory Item)</b>
            <button type="button" onclick="closeAddItemModal()" style="background:none;border:none;color:#cbd5e1;font-size:20px;cursor:pointer;">&times;</button>
        </div>
        <form method="post" action="inventory.php" style="padding:18px 20px;">
            <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
            <input type="hidden" name="action" value="add_item">

            <div style="display:grid;grid-template-columns:1fr 2fr;gap:12px;margin-bottom:12px;">
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;color:#0f172a;margin-bottom:4px;">Item Code *</label>
                    <input type="text" name="item_code" required placeholder="ITM-008" style="width:100%;font-size:12.5px;padding:8px;border:1.5px solid #cbd5e1;border-radius:6px;box-sizing:border-box;">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;color:#0f172a;margin-bottom:4px;">Item Name (نامِ جنس) *</label>
                    <input type="text" name="item_name" required placeholder="مثلاً دال مسور / گھی" style="width:100%;font-size:13px;padding:8px;border:1.5px solid #cbd5e1;border-radius:6px;box-sizing:border-box;">
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;color:#0f172a;margin-bottom:4px;">Category *</label>
                    <select name="category" style="width:100%;padding:8px;border:1.5px solid #cbd5e1;border-radius:6px;font-size:12.5px;">
                        <option value="ration">🌾 راشن و خوراک</option>
                        <option value="books">📚 کتب و درسیات</option>
                        <option value="construction">🧱 تعمیراتی سامان</option>
                        <option value="medical">💊 طبی امداد / ادویات</option>
                        <option value="general">📦 عمومی سامان</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;color:#0f172a;margin-bottom:4px;">Unit of Measure *</label>
                    <input type="text" name="unit" value="KG" required placeholder="KG, بوری, لیٹر, کارٹن" style="width:100%;font-size:12.5px;padding:8px;border:1.5px solid #cbd5e1;border-radius:6px;box-sizing:border-box;">
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;color:#0f172a;margin-bottom:4px;">Opening Stock</label>
                    <input type="number" step="0.01" min="0" name="opening_stock" value="0" style="width:100%;font-size:13px;padding:8px;border:1.5px solid #cbd5e1;border-radius:6px;box-sizing:border-box;">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;color:#0f172a;margin-bottom:4px;">Min Alert Qty</label>
                    <input type="number" step="0.01" min="0" name="min_alert_qty" value="5" style="width:100%;font-size:13px;padding:8px;border:1.5px solid #cbd5e1;border-radius:6px;box-sizing:border-box;">
                </div>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:8px;padding-top:12px;border-top:1px solid #e2e8f0;">
                <button type="button" onclick="closeAddItemModal()" class="btn secondary" style="padding:8px 16px;">منسوخ کریں</button>
                <button type="submit" class="btn" style="background:#0f172a;color:#ffffff;border:none;padding:8px 20px;font-weight:700;border-radius:6px;cursor:pointer;">
                    💾 رجسٹر کریں (Register Item)
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openReceiveModal() {
    var m = document.getElementById('receiveModal');
    if (m) m.style.display = 'flex';
}
function closeReceiveModal() {
    var m = document.getElementById('receiveModal');
    if (m) m.style.display = 'none';
}
function openIssueModal() {
    var m = document.getElementById('issueModal');
    if (m) m.style.display = 'flex';
}
function closeIssueModal() {
    var m = document.getElementById('issueModal');
    if (m) m.style.display = 'none';
}
function openAddItemModal() {
    var m = document.getElementById('addItemModal');
    if (m) m.style.display = 'flex';
}
function closeAddItemModal() {
    var m = document.getElementById('addItemModal');
    if (m) m.style.display = 'none';
}
window.addEventListener('click', function(e) {
    if (e.target && e.target.classList.contains('modal-backdrop')) {
        e.target.style.display = 'none';
    }
});
</script>
</body>
</html>

