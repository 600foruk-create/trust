<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

require_permission('accounts.view');
$currentUser = current_user();

$activePage = 'cash_bank';
$error = '';
$success = '';

// Handle Delete Bank/Cash Account
if (isset($_GET['delete_id']) && !has_permission('accounts.manage')) {
    $error = 'آپ کے پاس اکاؤنٹس ڈیلیٹ کرنے کا اختیار نہیں ہے۔';
} elseif (isset($_GET['delete_id'])) {
    $delId = (int)$_GET['delete_id'];
    $token = $_GET['token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $error = 'Invalid security token for deletion.';
    } else {
        try {
            $stmtAcc = $pdo->prepare("SELECT * FROM accounts WHERE id = ?");
            $stmtAcc->execute([$delId]);
            $acc = $stmtAcc->fetch();
            if (!$acc) {
                throw new RuntimeException("Account not found.");
            }

            // Protect core system accounts (1000 Main Cash, 1010 Main Bank)
            if (in_array((string)$acc['account_code'], ['1000', '1010'], true)) {
                throw new RuntimeException("بنیادی مرکزی اکاؤنٹ (" . e($acc['account_code']) . ") کو ڈیلیٹ نہیں کیا جا سکتا۔ آپ اس کا نام ترمیم کر سکتے ہیں۔");
            }

            // Check if any financial transactions / journal lines exist for this account
            $transCount = (int)$pdo->query("SELECT COUNT(*) FROM journal_lines WHERE account_id = {$delId}")->fetchColumn();
            if ($transCount > 0) {
                // Safely deactivate so double-entry ledger history is 100% protected
                $pdo->prepare("UPDATE accounts SET is_active = 0 WHERE id = ?")->execute([$delId]);
                $success = "اکاؤنٹ '" . e($acc['account_name']) . "' میں {$transCount} مالیاتی ٹرانزیکشنز موجود ہیں، لہٰذا تاریخی ریکارڈ کے تحفظ کے لیے اسے غیر فعال (Deactivate) کر دیا گیا ہے۔";
            } else {
                // Permanently delete account
                $pdo->prepare("DELETE FROM accounts WHERE id = ?")->execute([$delId]);
                $success = "اکاؤنٹ '" . e($acc['account_name']) . "' کامیابی سے مستقل ڈیلیٹ کر دیا گیا ہے۔";
            }
        } catch (Exception $e) {
            $error = 'ڈیلیٹ نہیں ہو سکا: ' . $e->getMessage();
        }
    }
}

// Handle Add / Edit / Reconciliation / Transfer Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf();
    $action = $_POST['action'];

    if ($action === 'transfer_funds') {
        $fromAccId = (int)($_POST['from_account_id'] ?? 0);
        $toAccId = (int)($_POST['to_account_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0.0);
        $transferDate = !empty($_POST['transfer_date']) ? trim($_POST['transfer_date']) : date('Y-m-d');
        $fundId = (int)($_POST['fund_id'] ?? 0);
        $refNo = trim($_POST['reference_no'] ?? '');
        $narration = trim($_POST['narration'] ?? '');

        if ($fromAccId <= 0 || $toAccId <= 0) {
            $error = 'براہ کرم رقم بھیجنے والا کھاتہ اور وصول کرنے والا کھاتہ دونوں منتخب کریں۔';
        } elseif ($fromAccId === $toAccId) {
            $error = 'رقم بھیجنے اور وصول کرنے والا کھاتہ ایک جیسا نہیں ہو سکتا۔ دونوں مختلف ہونے چاہئیں۔';
        } elseif ($amount <= 0.01) {
            $error = 'براہ کرم درست رقم درج کریں (رقم صفر سے زیادہ ہونی چاہیے)۔';
        } else {
            try {
                // Fetch both accounts
                $stmtAcc = $pdo->prepare("SELECT a.*, 
                                          COALESCE(SUM(jl.debit), 0) AS total_debit,
                                          COALESCE(SUM(jl.credit), 0) AS total_credit
                                          FROM accounts a
                                          LEFT JOIN journal_lines jl ON jl.account_id = a.id
                                          LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'posted'
                                          WHERE a.id = ? AND a.is_cash_or_bank = 1 AND a.is_active = 1
                                          GROUP BY a.id");
                
                $stmtAcc->execute([$fromAccId]);
                $fromAcc = $stmtAcc->fetch(PDO::FETCH_ASSOC);

                $stmtAcc->execute([$toAccId]);
                $toAcc = $stmtAcc->fetch(PDO::FETCH_ASSOC);

                if (!$fromAcc) {
                    throw new RuntimeException("رقم بھیجنے والا اکاؤنٹ موجود نہیں ہے یا غیر فعال ہے۔");
                }
                if (!$toAcc) {
                    throw new RuntimeException("رقم وصول کرنے والا اکاؤنٹ موجود نہیں ہے یا غیر فعال ہے۔");
                }

                $fromBalance = (float)$fromAcc['opening_balance'] + (float)$fromAcc['total_debit'] - (float)$fromAcc['total_credit'];
                
                $isSuperAdmin = function_exists('is_super_admin') ? is_super_admin($currentUser) : false;
                $isRegDirector = function_exists('is_regional_director') ? is_regional_director($currentUser) : false;
                $currentUserId = (int)($currentUser['id'] ?? 0);

                // Scoping: if regular user, ensure they own the source cash account
                if (!$isSuperAdmin && !$isRegDirector && !has_permission('accounts.manage')) {
                    if ((int)($fromAcc['user_id'] ?? 0) !== $currentUserId) {
                        throw new RuntimeException("آپ صرف اپنے تفویض کردہ کیش کھاتے سے ہی رقم منتقل کر سکتے ہیں۔");
                    }
                }

                // Balance check
                if (!$isSuperAdmin && $fromBalance < $amount) {
                    throw new RuntimeException("بھیجنے والے کھاتے (" . e($fromAcc['account_name']) . ") میں مطلوبہ بیلنس موجود نہیں ہے۔ دستیاب بیلنس: " . money($fromBalance));
                }

                $pdo->beginTransaction();

                // Generate voucher code TRF-YYYYMM-XXXX
                $prefix = 'TRF-' . date('Ym') . '-';
                $lastVoucher = $pdo->query("SELECT voucher_no FROM vouchers WHERE voucher_no LIKE '{$prefix}%' ORDER BY id DESC LIMIT 1")->fetchColumn();
                $seq = 1;
                if ($lastVoucher && preg_match('/-(\d+)$/', $lastVoucher, $m)) {
                    $seq = (int)$m[1] + 1;
                }
                $voucherNo = $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

                $transferRegionId = !empty($fromAcc['region_id']) ? (int)$fromAcc['region_id'] : (!empty($toAcc['region_id']) ? (int)$toAcc['region_id'] : (function_exists('get_user_region_id') ? get_user_region_id($currentUserId) : null));

                $fromName = format_ui_text($fromAcc['account_name']);
                $toName = format_ui_text($toAcc['account_name']);
                $descText = "اندرونی فنڈ منتقلی (Internal Cash Transfer): " . $fromName . " سے " . $toName . ($narration ? " — " . $narration : '');

                // 1. Insert Voucher
                $stmtV = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, voucher_date, fund_id, region_id, payee_name, payment_method, reference_no, description, total_amount, status, created_by, posted_at)
                                       VALUES (?, 'transfer', ?, ?, ?, ?, 'cash', ?, ?, ?, 'posted', ?, NOW())");
                $stmtV->execute([
                    $voucherNo, $transferDate, ($fundId > 0 ? $fundId : null),
                    ($transferRegionId > 0 ? $transferRegionId : null),
                    $toName, $refNo ?: null, $descText, $amount, $currentUserId
                ]);
                $voucherId = (int)$pdo->lastInsertId();

                // 2. Insert Journal Entry
                $stmtJe = $pdo->prepare("INSERT INTO journal_entries (voucher_id, region_id, entry_date, narration, status, created_by)
                                         VALUES (?, ?, ?, ?, 'posted', ?)");
                $stmtJe->execute([
                    $voucherId, ($transferRegionId > 0 ? $transferRegionId : null),
                    $transferDate, $descText, $currentUserId
                ]);
                $jeId = (int)$pdo->lastInsertId();

                // 3. Double-entry lines
                // Line 1: DEBIT destination account (Inflow / Asset increase)
                $stmtLine = $pdo->prepare("INSERT INTO journal_lines (journal_entry_id, account_id, fund_id, description, debit, credit)
                                           VALUES (?, ?, ?, ?, ?, 0.00)");
                $stmtLine->execute([
                    $jeId, $toAccId, ($fundId > 0 ? $fundId : null),
                    "Transfer Received from {$fromName}" . ($refNo ? " (Ref: {$refNo})" : ''),
                    $amount
                ]);

                // Line 2: CREDIT source account (Outflow / Asset decrease)
                $stmtLine = $pdo->prepare("INSERT INTO journal_lines (journal_entry_id, account_id, fund_id, description, debit, credit)
                                           VALUES (?, ?, ?, ?, 0.00, ?)");
                $stmtLine->execute([
                    $jeId, $fromAccId, ($fundId > 0 ? $fundId : null),
                    "Transfer Sent to {$toName}" . ($refNo ? " (Ref: {$refNo})" : ''),
                    $amount
                ]);

                // 4. Handle optional slip attachment
                if (isset($_FILES['slip_file']) && $_FILES['slip_file']['error'] === UPLOAD_ERR_OK) {
                    $fileTmp = $_FILES['slip_file']['tmp_name'];
                    $fileName = $_FILES['slip_file']['name'];
                    $fileSize = $_FILES['slip_file']['size'];
                    $fileType = mime_content_type($fileTmp) ?: $_FILES['slip_file']['type'];
                    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

                    if (in_array($ext, $allowedExts, true)) {
                        $uploadDir = __DIR__ . '/uploads/receipts/';
                        if (!is_dir($uploadDir)) {
                            @mkdir($uploadDir, 0755, true);
                        }
                        $storedName = 'trf_' . $voucherId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                        if (move_uploaded_file($fileTmp, $uploadDir . $storedName)) {
                            $stmtAtt = $pdo->prepare('INSERT INTO attachments (voucher_id, original_name, stored_name, mime_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)');
                            $stmtAtt->execute([$voucherId, $fileName, $storedName, $fileType, $fileSize, $currentUserId]);
                        }
                    }
                }

                // 5. Audit log
                try {
                    $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address) VALUES (?, ?, ?, ?, ?)');
                    $log->execute([$currentUserId, 'transfer_funds', 'voucher', $voucherId, $_SERVER['REMOTE_ADDR'] ?? null]);
                } catch (Throwable $e) {}

                // 6. Notify receiver user if applicable
                if (function_exists('add_notification') && !empty($toAcc['user_id']) && (int)$toAcc['user_id'] !== $currentUserId) {
                    add_notification(
                        (int)$toAcc['user_id'],
                        '💸 فنڈز موصول ہوئے: ' . money($amount),
                        $fromName . ' سے آپ کے کیش کھاتے میں ' . money($amount) . ' رقم منتقل کی گئی ہے (واؤچر: ' . $voucherNo . ')۔',
                        'cash_bank.php',
                        'voucher'
                    );
                }

                $pdo->commit();
                header('Location: cash_bank.php?tab=overview&success=transferred&vno=' . urlencode($voucherNo));
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'رقم منتقل نہیں ہو سکی: ' . $e->getMessage();
            }
        }
    } elseif (!has_permission('accounts.manage')) {
        $error = 'آپ کے پاس اکاؤنٹس یا ریکونسیلیشن تبدیل کرنے کا اختیار نہیں ہے۔';
    } elseif ($action === 'save_account') {
        $code = trim($_POST['account_code'] ?? '');
        $name = trim($_POST['account_name'] ?? '');
        $openingBalance = (float)($_POST['opening_balance'] ?? 0.0);

        if (empty($name)) {
            $error = 'Please enter Account Name.';
        } else {
            try {
                if (empty($code)) {
                    $maxCode = (int)$pdo->query("SELECT MAX(CAST(account_code AS UNSIGNED)) FROM accounts WHERE account_code REGEXP '^[0-9]+$' AND account_code >= 1000 AND account_code < 2000")->fetchColumn();
                    $code = (string)($maxCode >= 1000 ? $maxCode + 10 : 1020);
                } else {
                    $chk = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE account_code = ?");
                    $chk->execute([$code]);
                    if ($chk->fetchColumn() > 0) {
                        throw new RuntimeException("Account Code '{$code}' is already assigned. Please choose another code.");
                    }
                }
                $stmt = $pdo->prepare("INSERT INTO accounts (account_code, account_name, account_type, is_cash_or_bank, opening_balance, is_active) VALUES (?, ?, 'asset', 1, ?, 1)");
                $stmt->execute([$code, $name, $openingBalance]);
                header('Location: cash_bank.php?tab=overview&success=created');
                exit;
            } catch (Exception $e) {
                $error = 'Failed to create account: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'update_account') {
        $accId = (int)($_POST['account_id'] ?? 0);
        $code = trim($_POST['account_code'] ?? '');
        $name = trim($_POST['account_name'] ?? '');
        $openingBalance = (float)($_POST['opening_balance'] ?? 0.0);

        if ($accId <= 0 || empty($name)) {
            $error = 'Please enter valid Account Name.';
        } else {
            try {
                if (!empty($code)) {
                    $chk = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE account_code = ? AND id != ?");
                    $chk->execute([$code, $accId]);
                    if ($chk->fetchColumn() > 0) {
                        throw new RuntimeException("Account Code '{$code}' is already assigned to another account.");
                    }
                }
                $stmtUp = $pdo->prepare("UPDATE accounts SET account_name = ?, account_code = ?, opening_balance = ? WHERE id = ?");
                $stmtUp->execute([$name, $code, $openingBalance, $accId]);
                header('Location: cash_bank.php?tab=overview&success=updated');
                exit;
            } catch (Exception $e) {
                $error = 'Failed to update account: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'toggle_recon') {
        // Toggle reconciliation for a single transaction
        $jeId = (int)($_POST['je_id'] ?? 0);
        $bankRef = trim($_POST['bank_ref'] ?? '');
        $reconAccId = (int)($_POST['recon_acc_id'] ?? 0);
        $reconStatusFilter = trim($_POST['recon_status'] ?? 'all');
        try {
            $stmtRecon = $pdo->prepare("SELECT id, is_reconciled, voucher_id FROM journal_entries WHERE id = ?");
            $stmtRecon->execute([$jeId]);
            $jeRow = $stmtRecon->fetch();
            if ($jeRow) {
                $newStatus = (int)$jeRow['is_reconciled'] === 1 ? 0 : 1;
                $reconAt = $newStatus === 1 ? date('Y-m-d H:i:s') : null;
                $refVal = $newStatus === 1 ? ($bankRef !== '' ? $bankRef : 'Reconciled via Statement') : null;

                $pdo->prepare("UPDATE journal_entries SET is_reconciled = ?, reconciled_at = ?, bank_ref = ? WHERE id = ?")
                    ->execute([$newStatus, $reconAt, $refVal, $jeId]);

                if (!empty($jeRow['voucher_id'])) {
                    $pdo->prepare("UPDATE vouchers SET is_reconciled = ?, reconciled_at = ?, reconciled_by = ?, bank_ref = ? WHERE id = ?")
                        ->execute([$newStatus, $reconAt, $newStatus === 1 ? (int)$currentUser['id'] : null, $refVal, $jeRow['voucher_id']]);
                }
                $success = ($newStatus === 1) 
                    ? 'ٹرانزیکشن کو بینک اسٹیٹمنٹ کے مطابق کلیئر (Cleared / Reconciled) نشان زد کر دیا گیا ہے۔' 
                    : 'ٹرانزیکشن کا تصفیہ منسوخ کر دیا گیا ہے (Uncleared)۔';
            }
        } catch (Exception $e) {
            $error = 'ریکونسیلیشن میں خرابی: ' . $e->getMessage();
        }
    } elseif ($action === 'bulk_recon') {
        // Bulk mark transactions as cleared or uncleared
        $selectedJeIds = $_POST['je_ids'] ?? [];
        $reconMode = $_POST['recon_mode'] ?? 'clear';
        $reconAccId = (int)($_POST['recon_acc_id'] ?? 0);
        if (is_array($selectedJeIds) && !empty($selectedJeIds)) {
            $newStatus = $reconMode === 'clear' ? 1 : 0;
            $reconAt = $newStatus === 1 ? date('Y-m-d H:i:s') : null;
            $uid = $newStatus === 1 ? (int)$currentUser['id'] : null;
            $inClause = implode(',', array_map('intval', $selectedJeIds));

            $pdo->query("UPDATE journal_entries SET is_reconciled = {$newStatus}, reconciled_at = " . ($reconAt ? "'{$reconAt}'" : "NULL") . " WHERE id IN ({$inClause})");
            $pdo->query("UPDATE vouchers v JOIN journal_entries je ON je.voucher_id = v.id SET v.is_reconciled = {$newStatus}, v.reconciled_at = " . ($reconAt ? "'{$reconAt}'" : "NULL") . ", v.reconciled_by = " . ($uid ? $uid : "NULL") . " WHERE je.id IN ({$inClause})");

            $success = count($selectedJeIds) . ' ٹرانزیکشنز کو کامیابی سے اپڈیٹ کر دیا گیا۔';
        }
    } elseif ($action === 'save_daily_closing') {
        $accId = (int)($_POST['closing_account_id'] ?? 0);
        $closingDate = trim($_POST['closing_date'] ?? date('Y-m-d'));
        $n5000 = max(0, (int)($_POST['n_5000'] ?? 0));
        $n1000 = max(0, (int)($_POST['n_1000'] ?? 0));
        $n500  = max(0, (int)($_POST['n_500'] ?? 0));
        $n100  = max(0, (int)($_POST['n_100'] ?? 0));
        $n50   = max(0, (int)($_POST['n_50'] ?? 0));
        $n20   = max(0, (int)($_POST['n_20'] ?? 0));
        $n10   = max(0, (int)($_POST['n_10'] ?? 0));
        $coins = max(0, (float)($_POST['coins'] ?? 0));
        $notes = trim($_POST['closing_notes'] ?? '');

        $physicalTotal = ($n5000 * 5000) + ($n1000 * 1000) + ($n500 * 500) + ($n100 * 100) + ($n50 * 50) + ($n20 * 20) + ($n10 * 10) + $coins;

        if ($accId <= 0) {
            $error = 'براہ کرم کیش اکاؤنٹ منتخب کریں۔ (Please select a cash account).';
        } else {
            try {
                // Compute current system book balance for this account
                $accBalStmt = $pdo->prepare("SELECT a.*, 
                    COALESCE(SUM(jl.debit), 0) AS total_debit, 
                    COALESCE(SUM(jl.credit), 0) AS total_credit
                    FROM accounts a
                    LEFT JOIN journal_lines jl ON jl.account_id = a.id
                    LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'posted'
                    WHERE a.id = ?
                    GROUP BY a.id");
                $accBalStmt->execute([$accId]);
                $accRow = $accBalStmt->fetch();
                if (!$accRow) {
                    throw new RuntimeException("Account not found.");
                }
                $systemBalance = (float)$accRow['opening_balance'] + (float)$accRow['total_debit'] - (float)$accRow['total_credit'];
                $diff = $physicalTotal - $systemBalance;

                $status = 'balanced';
                if (abs($diff) > 0.01) {
                    $status = $diff < 0 ? 'short' : 'excess';
                }

                $denomsJson = json_encode([
                    '5000' => $n5000,
                    '1000' => $n1000,
                    '500'  => $n500,
                    '100'  => $n100,
                    '50'   => $n50,
                    '20'   => $n20,
                    '10'   => $n10,
                    'coins'=> $coins
                ]);

                $uRegId = function_exists('get_user_region_id') ? get_user_region_id((int)$currentUser['id']) : null;

                $stmtIns = $pdo->prepare("INSERT INTO daily_cash_closings (account_id, user_id, region_id, closing_date, system_balance, physical_cash, difference, status, denominations, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmtIns->execute([$accId, (int)$currentUser['id'], $uRegId, $closingDate, $systemBalance, $physicalTotal, $diff, $status, $denomsJson, $notes ?: null]);

                $closingId = (int)$pdo->lastInsertId();

                // Audit log
                $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)');
                $log->execute([
                    (int)$currentUser['id'], 'daily_cash_closing', 'daily_cash_closings', $closingId,
                    json_encode(['account' => $accRow['account_name'], 'system' => $systemBalance, 'physical' => $physicalTotal, 'diff' => $diff, 'status' => $status]),
                    $_SERVER['REMOTE_ADDR'] ?? null
                ]);

                header('Location: cash_bank.php?tab=closing&success=closed&cid=' . $closingId);
                exit;
            } catch (Exception $e) {
                $error = 'کیش کلوزنگ محفوظ نہیں ہو سکی: ' . $e->getMessage();
            }
        }
    }
}

// Active Tab Handling
$activeTab = trim($_GET['tab'] ?? 'overview');

// Edit Mode Data
$editAcc = null;
if (isset($_GET['edit_id'])) {
    $editId = (int)$_GET['edit_id'];
    $stmtEd = $pdo->prepare("SELECT * FROM accounts WHERE id = ? AND is_cash_or_bank = 1");
    $stmtEd->execute([$editId]);
    $editAcc = $stmtEd->fetch();
}
$showAccForm = !empty($editAcc) || (!empty($error) && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['save_account', 'update_account'], true));

// Calculate next auto-assigned code
$maxCode = (int)$pdo->query("SELECT MAX(CAST(account_code AS UNSIGNED)) FROM accounts WHERE account_code REGEXP '^[0-9]+$' AND account_code >= 1000 AND account_code < 2000")->fetchColumn();
$nextAccountCode = (string)($maxCode >= 1000 ? $maxCode + 10 : 1020);

// Fetch all Cash & Bank Accounts with real ledger balance
$sql = "SELECT a.*,
        COALESCE(SUM(jl.debit), 0) AS total_debit,
        COALESCE(SUM(jl.credit), 0) AS total_credit
        FROM accounts a
        LEFT JOIN journal_lines jl ON jl.account_id = a.id
        LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'posted'
        WHERE a.is_cash_or_bank = 1 AND a.is_active = 1
        GROUP BY a.id
        ORDER BY (a.account_code = '1000') DESC, (a.account_code = '1010') DESC, a.account_code ASC";
$accounts = $pdo->query($sql)->fetchAll();

$totalCash = 0.0;
$totalBank = 0.0;
foreach ($accounts as $acc) {
    $bal = (float)$acc['opening_balance'] + (float)$acc['total_debit'] - (float)$acc['total_credit'];
    if (stripos($acc['account_name'], 'bank') !== false) {
        $totalBank += $bal;
    } else {
        $totalCash += $bal;
    }
}
$combinedTotal = $totalCash + $totalBank;
$allFunds = $pdo->query("SELECT id, name, fund_code FROM funds WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

// Recent ledger transactions for Overview tab
$ledgerSql = "SELECT jl.*, je.entry_date, je.narration, v.voucher_no, v.voucher_type, a.account_name, f.name AS fund_name
              FROM journal_lines jl
              JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'posted'
              JOIN accounts a ON a.id = jl.account_id AND a.is_cash_or_bank = 1
              LEFT JOIN vouchers v ON v.id = je.voucher_id
              LEFT JOIN funds f ON f.id = jl.fund_id
              ORDER BY je.entry_date DESC, jl.id DESC LIMIT 30";
$transactions = $pdo->query($ledgerSql)->fetchAll();

// Bank Reconciliation Data Preparation
$bankAccounts = array_filter($accounts, function($a) {
    return (stripos($a['account_name'], 'bank') !== false) || in_array((string)$a['account_code'], ['1010'], true);
});
if (empty($bankAccounts)) {
    $bankAccounts = $accounts;
}

$reconAccId = (int)($_GET['recon_acc_id'] ?? 0);
if ($reconAccId <= 0 && !empty($bankAccounts)) {
    $firstB = reset($bankAccounts);
    $reconAccId = (int)$firstB['id'];
}

$reconStatusFilter = trim($_GET['recon_status'] ?? 'all');
$statementBalanceRaw = $_GET['statement_balance'] ?? '';
$statementBalance = ($statementBalanceRaw !== '') ? (float)$statementBalanceRaw : null;

$selectedReconAcc = null;
$reconTransactions = [];
$reconSummary = [
    'opening' => 0.0,
    'total_debit' => 0.0,
    'total_credit' => 0.0,
    'book_balance' => 0.0,
    'cleared_in' => 0.0,
    'cleared_out' => 0.0,
    'cleared_balance' => 0.0,
    'uncleared_in' => 0.0,
    'uncleared_out' => 0.0,
    'uncleared_count' => 0,
    'cleared_count' => 0,
    'total_count' => 0,
];

if ($reconAccId > 0) {
    foreach ($accounts as $a) {
        if ((int)$a['id'] === $reconAccId) {
            $selectedReconAcc = $a;
            break;
        }
    }

    $reconSql = "SELECT jl.*, je.id AS je_id, je.entry_date, je.narration, je.is_reconciled, je.reconciled_at, je.bank_ref,
                        v.voucher_no, v.voucher_type, f.name AS fund_name
                 FROM journal_lines jl
                 JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'posted'
                 LEFT JOIN vouchers v ON v.id = je.voucher_id
                 LEFT JOIN funds f ON f.id = jl.fund_id
                 WHERE jl.account_id = ?
                 ORDER BY je.entry_date DESC, jl.id DESC";
    $stmtReconQ = $pdo->prepare($reconSql);
    $stmtReconQ->execute([$reconAccId]);
    $allReconItems = $stmtReconQ->fetchAll();

    if ($selectedReconAcc) {
        $reconSummary['opening'] = (float)$selectedReconAcc['opening_balance'];
    }

    foreach ($allReconItems as $item) {
        $deb = (float)$item['debit'];
        $crd = (float)$item['credit'];
        $isRec = (int)($item['is_reconciled'] ?? 0) === 1;

        $reconSummary['total_debit'] += $deb;
        $reconSummary['total_credit'] += $crd;
        $reconSummary['total_count']++;

        if ($isRec) {
            $reconSummary['cleared_in'] += $deb;
            $reconSummary['cleared_out'] += $crd;
            $reconSummary['cleared_count']++;
        } else {
            $reconSummary['uncleared_in'] += $deb;
            $reconSummary['uncleared_out'] += $crd;
            $reconSummary['uncleared_count']++;
        }
    }

    $reconSummary['book_balance'] = $reconSummary['opening'] + $reconSummary['total_debit'] - $reconSummary['total_credit'];
    $reconSummary['cleared_balance'] = $reconSummary['opening'] + $reconSummary['cleared_in'] - $reconSummary['cleared_out'];

    if ($reconStatusFilter === 'cleared') {
        $reconTransactions = array_filter($allReconItems, fn($i) => (int)($i['is_reconciled'] ?? 0) === 1);
    } elseif ($reconStatusFilter === 'uncleared') {
        $reconTransactions = array_filter($allReconItems, fn($i) => (int)($i['is_reconciled'] ?? 0) === 0);
    } else {
        $reconTransactions = $allReconItems;
    }
}

// Daily Closing Data
$cashAccounts = array_filter($accounts, function($a) {
    return (stripos($a['account_name'], 'bank') === false) && (string)$a['account_code'] !== '1010';
});
if (empty($cashAccounts)) {
    $cashAccounts = $accounts;
}
$selectedClosingAccId = (int)($_GET['closing_acc_id'] ?? 0);
if ($selectedClosingAccId <= 0 && !empty($cashAccounts)) {
    $userCashAcc = array_values(array_filter($cashAccounts, fn($a) => (int)($a['user_id'] ?? 0) === (int)$currentUser['id']));
    $selectedClosingAccId = !empty($userCashAcc) ? (int)$userCashAcc[0]['id'] : (int)array_values($cashAccounts)[0]['id'];
}
$recentClosings = [];
if ($activeTab === 'closing') {
    try {
        $recentClosings = $pdo->query("SELECT dcc.*, a.account_name, a.account_code, u.full_name AS cashier_name, r.name AS region_name
            FROM daily_cash_closings dcc
            JOIN accounts a ON a.id = dcc.account_id
            JOIN users u ON u.id = dcc.user_id
            LEFT JOIN regions r ON r.id = dcc.region_id
            ORDER BY dcc.closing_date DESC, dcc.id DESC
            LIMIT 50")->fetchAll() ?: [];
    } catch (Exception $e) {
        $recentClosings = [];
    }
}

$madrasaName = get_setting('madrasa_name', 'Trust Accounts');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Cash & Bank | <?=e($madrasaName)?></title>
    <link rel="stylesheet" href="assets/style.css?v=<?=css_ver()?>">
    <style>
        .recon-badge-cleared { background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; padding:3px 8px; border-radius:12px; font-weight:600; font-size:11.5px; display:inline-flex; align-items:center; gap:4px; }
        .recon-badge-uncleared { background:#fef3c7; color:#b45309; border:1px solid #fde68a; padding:3px 8px; border-radius:12px; font-weight:600; font-size:11.5px; display:inline-flex; align-items:center; gap:4px; }
        .recon-stat-card { border-radius:10px; padding:14px; background:#fff; border:1px solid var(--line); display:flex; flex-direction:column; justify-content:space-between; }
        .recon-stat-card strong { font-size:1.35rem; margin:6px 0; }
        @media print {
            .no-print { display:none !important; }
            .print-only { display:block !important; }
            body { background:#fff !important; }
        }
        .print-only { display:none; }
    </style>
</head>
<body>
<div class="app-shell">
    <?php require __DIR__ . '/../app/sidebar.php'; ?>

    <main class="main">
        <header class="topbar no-print">
            <div>
                <span class="eyebrow">TREASURY & BANKING</span>
                <h1>Cash & Bank <span class="ur">(نقدی، بینک و اسٹیٹمنٹ ریکونسیلیشن)</span></h1>
            </div>
            <div class="btn-row">
                <?php if ($activeTab === 'reconciliation'): ?>
                    <button class="btn secondary sm" onclick="window.print()">🖨️ Print Reconciliation</button>
                <?php elseif ($activeTab === 'closing'): ?>
                    <button type="button" class="btn secondary sm" onclick="window.print()">🖨️ Print Closing Sheet <span class="ur">(کلوزنگ شیٹ پرنٹ کریں)</span></button>
                <?php else: ?>
                    <button type="button" class="btn blue sm" onclick="openTransferModal()">
                        ⇄ Transfer Funds <span class="ur">(رقم منتقل کریں)</span>
                    </button>
                    <?php if (has_permission('accounts.manage')): ?>
                        <?php if ($editAcc): ?>
                            <a href="cash_bank.php?tab=overview" class="btn secondary sm">✕ Cancel Editing <span class="ur">(منسوخ کریں)</span></a>
                        <?php else: ?>
                            <button type="button" class="btn secondary sm" id="toggleAccBtn" onclick="toggleAccountForm()">
                                ＋ Add Bank / Cash Account <span class="ur">(نیا اکاؤنٹ شامل کریں)</span>
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </header>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert success no-print">
                <?php
                    if ($_GET['success'] === 'transferred') {
                        $vno = htmlspecialchars($_GET['vno'] ?? '');
                        echo '✓ رقم کامیابی سے منتقل ہو گئی ہے! (Funds transferred successfully!) واؤچر نمبر: <b>' . $vno . '</b>';
                        if ($vno) {
                            echo ' &nbsp; <a href="print_voucher.php?voucher_no=' . urlencode($vno) . '" target="_blank" class="btn secondary sm" style="padding:2px 8px;font-size:11px;background:#fff;text-decoration:none;">🖨️ پرنٹ ٹرانسفر واؤچر</a>';
                        }
                    } elseif ($_GET['success'] === 'closed') {
                        echo '✓ یومیہ کیش کلوزنگ اور نوٹوں کی فزیکل گنتی کامیابی سے لاک اور محفوظ ہو گئی ہے! (Daily cash closing locked successfully!)';
                    } elseif ($_GET['success'] === 'created') {
                        echo 'Account created successfully!';
                    } elseif ($_GET['success'] === 'updated') {
                        echo 'Account details updated successfully!';
                    } else {
                        echo 'Operation completed successfully!';
                    }
                ?>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert success no-print"><?=e($success)?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert error no-print"><?=e($error)?></div>
        <?php endif; ?>

        <!-- Tab Navigation -->
        <div class="tab-nav no-print" style="margin:16px 0 20px;">
            <a href="cash_bank.php?tab=overview" class="tab-link <?=$activeTab==='overview'?'active':''?>">
                💳 Accounts & Ledger <span class="ur">(اکاؤنٹس و کیش بیلنس)</span>
            </a>
            <a href="cash_bank.php?tab=closing" class="tab-link <?=$activeTab==='closing'?'active':''?>">
                📝 Daily Cash Closing <span class="ur">(یومیہ کیش گنتی و کلوزنگ)</span>
            </a>
            <a href="cash_bank.php?tab=reconciliation<?=$reconAccId>0?'&recon_acc_id='.$reconAccId:''?>" class="tab-link <?=$activeTab==='reconciliation'?'active':''?>">
                🏦 Bank Reconciliation <span class="ur">(بینک اسٹیٹمنٹ ریکونسیلیشن)</span>
                <?php if (!empty($reconSummary['uncleared_count'])): ?>
                    <span class="badge badge-amber" style="margin-left:6px;font-size:11px;"><?=$reconSummary['uncleared_count']?> Pending</span>
                <?php endif; ?>
            </a>
        </div>

        <?php if ($activeTab === 'reconciliation'): ?>
            <!-- ========================================== -->
            <!-- TAB 2: BANK STATEMENT RECONCILIATION       -->
            <!-- ========================================== -->

            <!-- Print Header -->
            <div class="print-only" style="margin-bottom:20px;">
                <h2 style="margin:0 0 5px;"><?=e($madrasaName)?></h2>
                <h3 style="margin:0 0 5px;color:#1e3a8a;">Bank Reconciliation Statement (بینک مفاہمت نامہ)</h3>
                <p style="margin:0;color:#64748b;">
                    Bank Account: <strong><?=e($selectedReconAcc['account_name'] ?? 'N/A')?> (Code: <?=e($selectedReconAcc['account_code'] ?? 'N/A')?>)</strong> | Date: <?=date('d M Y, h:i A')?>
                </p>
            </div>

            <!-- Filter and Account Bar -->
            <form method="get" class="filter-bar no-print" style="margin-bottom:20px;flex-wrap:wrap;gap:12px;background:#f8fafc;padding:14px;border-radius:10px;border:1px solid var(--line);">
                <input type="hidden" name="tab" value="reconciliation">
                <div style="flex:1;min-width:220px;">
                    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Select Bank Account <span class="ur">(بینک منتخب کریں)</span>:</label>
                    <select name="recon_acc_id" style="width:100%;padding:7px 10px;border-radius:6px;border:1px solid var(--line);font-weight:600;" onchange="this.form.submit()">
                        <?php foreach ($bankAccounts as $ba): ?>
                            <option value="<?=$ba['id']?>" <?=$reconAccId === (int)$ba['id'] ? 'selected' : ''?>>
                                <?=e($ba['account_name'])?> (<?=e($ba['account_code'])?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="width:160px;">
                    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Status Filter <span class="ur">(حالت)</span>:</label>
                    <select name="recon_status" style="width:100%;padding:7px 10px;border-radius:6px;border:1px solid var(--line);" onchange="this.form.submit()">
                        <option value="all" <?=$reconStatusFilter==='all'?'selected':''?>>All Transactions (سبھی)</option>
                        <option value="uncleared" <?=$reconStatusFilter==='uncleared'?'selected':''?>>⏳ Uncleared / Pending (غیر مصدقہ)</option>
                        <option value="cleared" <?=$reconStatusFilter==='cleared'?'selected':''?>>✓ Cleared / Matched (کلیئر شدہ)</option>
                    </select>
                </div>

                <div style="width:190px;">
                    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Bank Statement Balance <span class="ur">(بینک بیلنس)</span>:</label>
                    <input type="number" step="0.01" name="statement_balance" value="<?=e($statementBalanceRaw)?>" placeholder="Enter statement PKR" style="width:100%;padding:6px 10px;border-radius:6px;border:1px solid var(--line);">
                </div>

                <div style="align-self:flex-end;">
                    <button class="btn blue sm" type="submit">Check & Match</button>
                    <a href="cash_bank.php?tab=reconciliation&recon_acc_id=<?=$reconAccId?>" class="btn secondary sm">Reset</a>
                </div>
            </form>

            <!-- Reconciliation Summary Dashboard -->
            <section class="stats-grid" style="margin-bottom:22px;">
                <article class="recon-stat-card" style="border-left:4px solid #3b82f6;">
                    <div style="display:flex;justify-content:space-between;color:#64748b;font-size:12px;font-weight:600;">
                        <span>Book Ledger Balance <span class="ur">(کھاتہ کے مطابق)</span></span>
                        <span>📚</span>
                    </div>
                    <strong style="color:#1e3a8a;"><?=money($reconSummary['book_balance'])?></strong>
                    <small style="color:#64748b;">All posted vouchers in software</small>
                </article>

                <article class="recon-stat-card" style="border-left:4px solid #10b981;">
                    <div style="display:flex;justify-content:space-between;color:#64748b;font-size:12px;font-weight:600;">
                        <span>Cleared / Matched Balance <span class="ur">(بینک تصدیق شدہ)</span></span>
                        <span>✓</span>
                    </div>
                    <strong style="color:#15803d;"><?=money($reconSummary['cleared_balance'])?></strong>
                    <small style="color:#15803d;"><?=$reconSummary['cleared_count']?> transactions verified</small>
                </article>

                <article class="recon-stat-card" style="border-left:4px solid #f59e0b;">
                    <div style="display:flex;justify-content:space-between;color:#64748b;font-size:12px;font-weight:600;">
                        <span>Uncleared Inflows <span class="ur">(جمع طلب چیکس/ڈپازٹ)</span></span>
                        <span>↗</span>
                    </div>
                    <strong style="color:#b45309;"><?=money($reconSummary['uncleared_in'])?></strong>
                    <small style="color:#64748b;">Deposits in transit / clearing</small>
                </article>

                <article class="recon-stat-card" style="border-left:4px solid #ef4444;">
                    <div style="display:flex;justify-content:space-between;color:#64748b;font-size:12px;font-weight:600;">
                        <span>Unpresented Outflows <span class="ur">(غیر ادا شدہ چیکس)</span></span>
                        <span>↘</span>
                    </div>
                    <strong style="color:#b91c1c;"><?=money($reconSummary['uncleared_out'])?></strong>
                    <small style="color:#64748b;">Cheques issued but not debited</small>
                </article>
            </section>

            <!-- Statement Difference Checker Box -->
            <?php if ($statementBalance !== null): 
                $reconDiff = round($statementBalance - $reconSummary['cleared_balance'], 2);
                $isMatched = abs($reconDiff) < 0.01;
            ?>
                <div style="padding:14px 18px;border-radius:8px;margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;background:<?=$isMatched?'#f0fdf4':'#fff1f2'?>;border:1px solid <?=$isMatched?'#86efac':'#fca5a5'?>;">
                    <div>
                        <b style="font-size:15px;color:<?=$isMatched?'#166534':'#991b1b'?>;">
                            <?=$isMatched ? '🎉 ماشاءاللہ! بینک اسٹیٹمنٹ اور سافٹ ویئر ریکارڈز 100% میچ کر رہے ہیں۔' : '⚠️ بینک اسٹیٹمنٹ اور ریکونسیلیشن بیلنس میں فرق موجود ہے۔'?>
                        </b>
                        <p style="margin:4px 0 0;font-size:12.5px;color:<?=$isMatched?'#15803d':'#b91c1c'?>;">
                            Statement: <b><?=money($statementBalance)?></b> | Cleared: <b><?=money($reconSummary['cleared_balance'])?></b> | Difference: <b style="font-size:14px;"><?=money($reconDiff)?></b>
                        </p>
                    </div>
                    <div>
                        <span class="badge <?=$isMatched?'badge-green':'badge-red'?>" style="font-size:13px;padding:6px 12px;">
                            <?=$isMatched ? '100% RECONCILED' : 'DISCREPANCY FOUND'?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Reconciliation Transactions Table -->
            <article class="panel">
                <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <h3>Transactions Reconciliation Ledger — <?=e($selectedReconAcc['account_name'] ?? 'Bank')?></h3>
                        <p>Toggle clearance status against official bank statement lines</p>
                    </div>
                    <div class="no-print">
                        <span style="font-size:12px;color:#64748b;">
                            Showing <strong><?=count($reconTransactions)?></strong> of <strong><?=$reconSummary['total_count']?></strong> entries
                        </span>
                    </div>
                </div>

                <?php if (empty($reconTransactions)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">✓</div>
                        <b>No transactions match the selected filter.</b>
                        <p>Switch filter to "All Transactions" or select another bank account.</p>
                    </div>
                <?php else: ?>
                    <form method="post" id="bulkReconForm">
                        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                        <input type="hidden" name="action" value="bulk_recon">
                        <input type="hidden" name="recon_acc_id" value="<?=$reconAccId?>">

                        <div class="no-print" style="background:#f8fafc;padding:10px 14px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:10px;">
                            <div style="display:flex;align-items:center;gap:8px;">
                                <input type="checkbox" id="selectAllRecon" onclick="toggleSelectAllRecon(this)">
                                <label for="selectAllRecon" style="font-size:12.5px;cursor:pointer;font-weight:600;">Select All Visible</label>
                            </div>
                            <div class="btn-row">
                                <button type="submit" name="recon_mode" value="clear" class="btn success sm" style="font-size:11.5px;padding:4px 10px;">
                                    ✓ Bulk Mark Cleared
                                </button>
                                <button type="submit" name="recon_mode" value="unclear" class="btn secondary sm" style="font-size:11.5px;padding:4px 10px;">
                                    ⏳ Bulk Mark Uncleared
                                </button>
                            </div>
                        </div>

                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th style="width:30px;" class="no-print">#</th>
                                        <th>Date</th>
                                        <th>Voucher #</th>
                                        <th>Fund</th>
                                        <th>Particulars / Narration</th>
                                        <th class="right">Inflow (Deposit)</th>
                                        <th class="right">Outflow (Cheque)</th>
                                        <th class="center">Reconciliation Status</th>
                                        <th class="right no-print">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($reconTransactions as $rt): 
                                        $isCleared = (int)($rt['is_reconciled'] ?? 0) === 1;
                                    ?>
                                        <tr style="<?=$isCleared ? 'background:#fcfdfd;' : 'background:#fffdfa;'?>">
                                            <td class="no-print">
                                                <input type="checkbox" name="je_ids[]" value="<?=$rt['je_id']?>" class="recon-checkbox">
                                            </td>
                                            <td><?=e($rt['entry_date'])?></td>
                                            <td><b><?=e($rt['voucher_no'] ?: 'JV-'.$rt['journal_entry_id'])?></b></td>
                                            <td><span class="badge badge-blue"><?=e($rt['fund_name'] ?? 'General')?></span></td>
                                            <td>
                                                <?=e($rt['description'] ?: $rt['narration'])?>
                                                <?php if (!empty($rt['bank_ref'])): ?>
                                                    <div style="font-size:11px;color:#0284c7;margin-top:2px;">
                                                        Ref: <b><?=e($rt['bank_ref'])?></b>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="right font-bold" style="color:#15803d;">
                                                <?=(float)$rt['debit'] > 0 ? money($rt['debit']) : '—'?>
                                            </td>
                                            <td class="right font-bold" style="color:#b91c1c;">
                                                <?=(float)$rt['credit'] > 0 ? money($rt['credit']) : '—'?>
                                            </td>
                                            <td class="center">
                                                <?php if ($isCleared): ?>
                                                    <span class="recon-badge-cleared" title="Cleared on <?=e($rt['reconciled_at'] ?? '')?>">
                                                        ✓ Cleared
                                                    </span>
                                                <?php else: ?>
                                                    <span class="recon-badge-uncleared" title="Not yet verified on statement">
                                                        ⏳ Uncleared
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="right no-print" style="white-space:nowrap;">
                                                <form method="post" style="display:inline;">
                                                    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                                                    <input type="hidden" name="action" value="toggle_recon">
                                                    <input type="hidden" name="je_id" value="<?=$rt['je_id']?>">
                                                    <input type="hidden" name="recon_acc_id" value="<?=$reconAccId?>">
                                                    <input type="hidden" name="recon_status" value="<?=e($reconStatusFilter)?>">
                                                    <button type="submit" class="btn sm <?=$isCleared ? 'secondary' : 'success'?>" style="padding:3px 8px;font-size:11px;">
                                                        <?=$isCleared ? '↩ Undo Clearance' : '✓ Mark Cleared'?>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </form>
                <?php endif; ?>
            </article>

            <!-- Printable Sign-off Block -->
            <div class="print-only" style="margin-top:40px;padding-top:20px;border-top:1px dashed #cbd5e1;">
                <div style="display:flex;justify-content:space-between;text-align:center;font-size:13px;">
                    <div style="width:200px;">
                        <div style="border-bottom:1px solid #000;margin-bottom:6px;height:40px;"></div>
                        <b>Prepared by (اکاؤنٹنٹ)</b>
                    </div>
                    <div style="width:200px;">
                        <div style="border-bottom:1px solid #000;margin-bottom:6px;height:40px;"></div>
                        <b>Verified by (ناظم مالیات / خازن)</b>
                    </div>
                    <div style="width:200px;">
                        <div style="border-bottom:1px solid #000;margin-bottom:6px;height:40px;"></div>
                        <b>Approved by (مہتمم / صدر)</b>
                    </div>
                </div>
            </div>

        <?php elseif ($activeTab === 'closing'): ?>
            <!-- ========================================== -->
            <!-- TAB 3: DAILY CASH CLOSING & DENOMINATIONS   -->
            <!-- ========================================== -->

            <div class="print-only" style="margin-bottom:20px;">
                <h2 style="margin:0 0 5px;"><?=e($madrasaName)?></h2>
                <h3 style="margin:0 0 5px;color:#1e3a8a;">Daily Cash Closing & Denomination Sheet (یومیہ کیش گنتی و کلوزنگ شیٹ)</h3>
                <p style="margin:0;color:#64748b;">
                    Date: <?=date('d M Y, h:i A')?> | Printed By: <?=e(current_user()['name'])?>
                </p>
            </div>

            <!-- Closing Entry Form Card -->
            <article class="form-card no-print" style="margin-bottom:24px;">
                <div class="form-card-header" style="background:#f8fafc;padding:16px 20px;border-bottom:1px solid #e2e8f0;">
                    <div>
                        <h3 style="margin:0;font-size:16px;color:#1e3a8a;">
                            📝 Record Daily Cash Closing <span class="ur">(آج کے کیش دراز کی گنتی و کلوزنگ)</span>
                        </h3>
                        <p style="margin:4px 0 0;font-size:12.5px;color:#64748b;">
                            Select cash account, enter count of each physical currency note, verify balance, and lock the day's cash.
                        </p>
                    </div>
                </div>

                <form method="post" action="cash_bank.php?tab=closing" style="padding:20px;">
                    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                    <input type="hidden" name="action" value="save_daily_closing">

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
                        <div class="form-group">
                            <label style="font-weight:700;font-size:13px;">Closing Date <span class="ur">(کلوزنگ کی تاریخ)</span> *</label>
                            <input type="date" name="closing_date" value="<?=date('Y-m-d')?>" required style="width:100%;font-size:13.5px;padding:8px 10px;">
                        </div>

                        <div class="form-group">
                            <label style="font-weight:700;font-size:13px;">Cash Account <span class="ur">(کیش اکاؤنٹ منتخب کریں)</span> *</label>
                            <select name="closing_account_id" id="closingAccSelect" onchange="onClosingAccChange(this)" required style="width:100%;font-size:13.5px;padding:8px 10px;font-weight:600;">
                                <?php foreach ($cashAccounts as $ca): 
                                    $b = (float)$ca['opening_balance'] + (float)$ca['total_debit'] - (float)$ca['total_credit'];
                                ?>
                                    <option value="<?=$ca['id']?>" data-bal="<?=$b?>" <?=$selectedClosingAccId === (int)$ca['id'] ? 'selected' : ''?>>
                                        <?=e(format_ui_text($ca['account_name']))?> (<?=e($ca['account_code'])?>) — موجودہ بہی کھاتہ: <?=money($b)?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Denominations Grid Table -->
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px;margin-bottom:20px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                            <b style="font-size:14px;color:#0f172a;">💵 Currency Notes Breakdown <span class="ur">(نوٹوں کی تعداد و تفصیل)</span></b>
                            <span style="font-size:11.5px;color:#64748b;">تعداد درج کریں، رقم خود بخود شمار ہو گی</span>
                        </div>

                        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:12px;">
                            <!-- 5000 -->
                            <div style="background:#fff;border:1px solid #cbd5e1;border-radius:8px;padding:10px;">
                                <div style="display:flex;justify-content:space-between;align-items:center;">
                                    <b style="color:#1e40af;font-size:13.5px;">Rs. 5,000</b>
                                    <span style="font-size:11px;color:#64748b;">نوٹ</span>
                                </div>
                                <div style="display:flex;align-items:center;gap:6px;margin-top:6px;">
                                    <input type="number" min="0" name="n_5000" id="n5000" placeholder="0" class="denom-input" oninput="calcClosingTotals()" style="width:75px;font-weight:700;padding:5px 8px;font-size:13px;">
                                    <span style="font-size:12px;color:#64748b;">=</span>
                                    <b id="sub5000" style="font-size:12.5px;color:#0f172a;">Rs. 0</b>
                                </div>
                            </div>

                            <!-- 1000 -->
                            <div style="background:#fff;border:1px solid #cbd5e1;border-radius:8px;padding:10px;">
                                <div style="display:flex;justify-content:space-between;align-items:center;">
                                    <b style="color:#0369a1;font-size:13.5px;">Rs. 1,000</b>
                                    <span style="font-size:11px;color:#64748b;">نوٹ</span>
                                </div>
                                <div style="display:flex;align-items:center;gap:6px;margin-top:6px;">
                                    <input type="number" min="0" name="n_1000" id="n1000" placeholder="0" class="denom-input" oninput="calcClosingTotals()" style="width:75px;font-weight:700;padding:5px 8px;font-size:13px;">
                                    <span style="font-size:12px;color:#64748b;">=</span>
                                    <b id="sub1000" style="font-size:12.5px;color:#0f172a;">Rs. 0</b>
                                </div>
                            </div>

                            <!-- 500 -->
                            <div style="background:#fff;border:1px solid #cbd5e1;border-radius:8px;padding:10px;">
                                <div style="display:flex;justify-content:space-between;align-items:center;">
                                    <b style="color:#047857;font-size:13.5px;">Rs. 500</b>
                                    <span style="font-size:11px;color:#64748b;">نوٹ</span>
                                </div>
                                <div style="display:flex;align-items:center;gap:6px;margin-top:6px;">
                                    <input type="number" min="0" name="n_500" id="n500" placeholder="0" class="denom-input" oninput="calcClosingTotals()" style="width:75px;font-weight:700;padding:5px 8px;font-size:13px;">
                                    <span style="font-size:12px;color:#64748b;">=</span>
                                    <b id="sub500" style="font-size:12.5px;color:#0f172a;">Rs. 0</b>
                                </div>
                            </div>

                            <!-- 100 -->
                            <div style="background:#fff;border:1px solid #cbd5e1;border-radius:8px;padding:10px;">
                                <div style="display:flex;justify-content:space-between;align-items:center;">
                                    <b style="color:#b45309;font-size:13.5px;">Rs. 100</b>
                                    <span style="font-size:11px;color:#64748b;">نوٹ</span>
                                </div>
                                <div style="display:flex;align-items:center;gap:6px;margin-top:6px;">
                                    <input type="number" min="0" name="n_100" id="n100" placeholder="0" class="denom-input" oninput="calcClosingTotals()" style="width:75px;font-weight:700;padding:5px 8px;font-size:13px;">
                                    <span style="font-size:12px;color:#64748b;">=</span>
                                    <b id="sub100" style="font-size:12.5px;color:#0f172a;">Rs. 0</b>
                                </div>
                            </div>

                            <!-- 50 -->
                            <div style="background:#fff;border:1px solid #cbd5e1;border-radius:8px;padding:10px;">
                                <div style="display:flex;justify-content:space-between;align-items:center;">
                                    <b style="color:#475569;font-size:13.5px;">Rs. 50</b>
                                    <span style="font-size:11px;color:#64748b;">نوٹ</span>
                                </div>
                                <div style="display:flex;align-items:center;gap:6px;margin-top:6px;">
                                    <input type="number" min="0" name="n_50" id="n50" placeholder="0" class="denom-input" oninput="calcClosingTotals()" style="width:75px;font-weight:700;padding:5px 8px;font-size:13px;">
                                    <span style="font-size:12px;color:#64748b;">=</span>
                                    <b id="sub50" style="font-size:12.5px;color:#0f172a;">Rs. 0</b>
                                </div>
                            </div>

                            <!-- 20 -->
                            <div style="background:#fff;border:1px solid #cbd5e1;border-radius:8px;padding:10px;">
                                <div style="display:flex;justify-content:space-between;align-items:center;">
                                    <b style="color:#c2410c;font-size:13.5px;">Rs. 20</b>
                                    <span style="font-size:11px;color:#64748b;">نوٹ</span>
                                </div>
                                <div style="display:flex;align-items:center;gap:6px;margin-top:6px;">
                                    <input type="number" min="0" name="n_20" id="n20" placeholder="0" class="denom-input" oninput="calcClosingTotals()" style="width:75px;font-weight:700;padding:5px 8px;font-size:13px;">
                                    <span style="font-size:12px;color:#64748b;">=</span>
                                    <b id="sub20" style="font-size:12.5px;color:#0f172a;">Rs. 0</b>
                                </div>
                            </div>

                            <!-- 10 -->
                            <div style="background:#fff;border:1px solid #cbd5e1;border-radius:8px;padding:10px;">
                                <div style="display:flex;justify-content:space-between;align-items:center;">
                                    <b style="color:#15803d;font-size:13.5px;">Rs. 10</b>
                                    <span style="font-size:11px;color:#64748b;">نوٹ</span>
                                </div>
                                <div style="display:flex;align-items:center;gap:6px;margin-top:6px;">
                                    <input type="number" min="0" name="n_10" id="n10" placeholder="0" class="denom-input" oninput="calcClosingTotals()" style="width:75px;font-weight:700;padding:5px 8px;font-size:13px;">
                                    <span style="font-size:12px;color:#64748b;">=</span>
                                    <b id="sub10" style="font-size:12.5px;color:#0f172a;">Rs. 0</b>
                                </div>
                            </div>

                            <!-- Coins -->
                            <div style="background:#fff;border:1px solid #cbd5e1;border-radius:8px;padding:10px;">
                                <div style="display:flex;justify-content:space-between;align-items:center;">
                                    <b style="color:#64748b;font-size:13.5px;">🪙 Coins / سکے</b>
                                    <span style="font-size:11px;color:#64748b;">سکے و ریزگاری</span>
                                </div>
                                <div style="display:flex;align-items:center;gap:6px;margin-top:6px;">
                                    <input type="number" step="0.01" min="0" name="coins" id="coinsInput" placeholder="0.00" oninput="calcClosingTotals()" style="width:110px;font-weight:700;padding:5px 8px;font-size:13px;">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Live Summary & Reconciliation Box -->
                    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:14px;background:#ffffff;border:2px solid #cbd5e1;border-radius:10px;padding:16px;margin-bottom:20px;">
                        <div>
                            <span style="font-size:12px;color:#64748b;font-weight:600;">System Book Balance (سسٹم بیلنس)</span>
                            <div id="dispSystemBal" style="font-size:19px;font-weight:800;color:#1e3a8a;margin-top:2px;">Rs. 0.00</div>
                        </div>
                        <div>
                            <span style="font-size:12px;color:#64748b;font-weight:600;">Physical Cash Counted (کل نقد گنتی)</span>
                            <div id="dispPhysicalCash" style="font-size:19px;font-weight:800;color:#047857;margin-top:2px;">Rs. 0.00</div>
                        </div>
                        <div>
                            <span style="font-size:12px;color:#64748b;font-weight:600;">Closing Difference (فرق)</span>
                            <div id="dispDifference" style="font-size:19px;font-weight:800;color:#0f172a;margin-top:2px;">Rs. 0.00</div>
                        </div>
                        <div>
                            <span style="font-size:12px;color:#64748b;font-weight:600;">Status (حیثیت)</span>
                            <div id="dispStatusBadge" style="margin-top:4px;">
                                <span class="badge" style="background:#f1f5f9;color:#64748b;font-weight:700;font-size:12px;padding:4px 10px;">Waiting for count...</span>
                            </div>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom:16px;">
                        <label style="font-weight:600;font-size:12.5px;">Closing Remarks / Notes <span class="ur">(دستخطی ریمارکس یا نوٹ)</span></label>
                        <input name="closing_notes" placeholder="e.g. کیش دراز چیک کر کے سیل کر دی گئی..." style="width:100%;font-size:13px;padding:8px 10px;">
                    </div>

                    <div class="btn-row" style="justify-content:flex-end;">
                        <button type="submit" class="btn blue" style="font-weight:700;padding:9px 24px;font-size:14px;background:#1e3a8a;">
                            🔒 Save & Lock Daily Closing <span class="ur">(یومیہ کلوزنگ محفوظ کریں)</span>
                        </button>
                    </div>
                </form>
            </article>

            <!-- Past Closings History Table -->
            <article class="panel">
                <div class="panel-heading">
                    <div>
                        <h3>Recent Daily Cash Closings Log <span class="ur">(سابقہ یومیہ کیش کلوزنگ ریکارڈز)</span></h3>
                        <p>Complete historical audit log of physical cash drawer counts and reconciliations</p>
                    </div>
                </div>

                <?php if (empty($recentClosings)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">📝</div>
                        <b>No daily cash closings recorded yet.</b>
                        <p>Complete your first cash closing above to audit and reconcile drawer balances.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Closing Date</th>
                                    <th>Cash Account</th>
                                    <th>Cashier / Staff</th>
                                    <th class="right">System Balance</th>
                                    <th class="right">Physical Cash</th>
                                    <th class="right">Difference</th>
                                    <th class="center">Status</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentClosings as $rc): 
                                    $diff = (float)$rc['difference'];
                                ?>
                                    <tr>
                                        <td><b><?=e(date('d M Y', strtotime($rc['closing_date'])))?></b></td>
                                        <td>
                                            <b><?=e($rc['account_name'])?></b>
                                            <span style="font-size:11px;color:#64748b;">(<?=e($rc['account_code'])?>)</span>
                                            <?php if (!empty($rc['region_name'])): ?>
                                                <div style="font-size:10.5px;color:#0284c7;">🏛️ <?=e($rc['region_name'])?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <b><?=e($rc['cashier_name'])?></b>
                                            <div style="font-size:10.5px;color:#64748b;"><?=e(date('h:i A', strtotime($rc['created_at'])))?></div>
                                        </td>
                                        <td class="right font-bold" style="color:#1e3a8a;"><?=money($rc['system_balance'])?></td>
                                        <td class="right font-bold" style="color:#047857;"><?=money($rc['physical_cash'])?></td>
                                        <td class="right font-bold" style="color:<?=abs($diff) < 0.01 ? '#15803d' : ($diff < 0 ? '#b91c1c' : '#b45309')?>;">
                                            <?=$diff > 0 ? '+' : ''?><?=money($diff)?>
                                        </td>
                                        <td class="center">
                                            <?php if ($rc['status'] === 'balanced'): ?>
                                                <span class="badge" style="background:#dcfce7;color:#15803d;font-weight:700;">✓ Balanced</span>
                                            <?php elseif ($rc['status'] === 'short'): ?>
                                                <span class="badge" style="background:#fee2e2;color:#b91c1c;font-weight:700;">⚠️ Short</span>
                                            <?php else: ?>
                                                <span class="badge" style="background:#fef3c7;color:#b45309;font-weight:700;">ℹ️ Excess</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size:12px;color:#475569;">
                                            <?=e($rc['notes'] ?: '—')?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </article>

            <!-- Printable Sign-off Block for Closing Sheet -->
            <div class="print-only" style="margin-top:40px;padding-top:20px;border-top:1px dashed #cbd5e1;">
                <div style="display:flex;justify-content:space-between;text-align:center;font-size:13px;">
                    <div style="width:200px;">
                        <div style="border-bottom:1px solid #000;margin-bottom:6px;height:40px;"></div>
                        <b>Cashier (کیشیئر / کیش انچارج)</b>
                    </div>
                    <div style="width:200px;">
                        <div style="border-bottom:1px solid #000;margin-bottom:6px;height:40px;"></div>
                        <b>Regional Director / Verifier (ڈائریکٹر / نگران)</b>
                    </div>
                    <div style="width:200px;">
                        <div style="border-bottom:1px solid #000;margin-bottom:6px;height:40px;"></div>
                        <b>Finance Officer (صدر / مہتمم)</b>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- ========================================== -->
            <!-- TAB 1: ACCOUNTS OVERVIEW & LEDGER FEED     -->
            <!-- ========================================== -->

            <!-- Balance Summary Cards -->
            <section class="stats-grid" style="margin-bottom:24px;">
                <article class="stat-card">
                    <div class="stat-top"><span>Cash in Hand <span class="ur">(نقدی کیش)</span></span><span class="stat-icon green">💵</span></div>
                    <strong><?=money($totalCash)?></strong>
                    <small>Physical cash across institute & cashiers</small>
                </article>
                <article class="stat-card">
                    <div class="stat-top"><span>Bank Accounts <span class="ur">(بینک بیلنس)</span></span><span class="stat-icon blue">🏦</span></div>
                    <strong><?=money($totalBank)?></strong>
                    <small>Total in registered bank accounts</small>
                </article>
                <article class="stat-card">
                    <div class="stat-top"><span>Combined Liquid Funds</span><span class="stat-icon purple">▣</span></div>
                    <strong style="color:#1d4ed8;"><?=money($combinedTotal)?></strong>
                    <small>Total liquid treasury</small>
                </article>
            </section>

            <!-- Accounts Overview Table with Edit and Delete Actions -->
            <article class="panel" style="margin-bottom:22px;">
                <div class="panel-heading">
                    <div>
                        <h3>Active Accounts Balance Breakdown <span class="ur">(فعال اکاؤنٹس کی فہرست)</span></h3>
                        <p>Calculated from opening balance and posted double-entry journals</p>
                    </div>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Account Name</th>
                                <th class="right">Opening Balance</th>
                                <th class="right">Total Inflow (Receipts)</th>
                                <th class="right">Total Outflow (Payments)</th>
                                <th class="right">Current Balance</th>
                                <th class="right">Actions <span class="ur">(اختیارات)</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($accounts as $a): 
                                $bal = (float)$a['opening_balance'] + (float)$a['total_debit'] - (float)$a['total_credit'];
                                $isCore = in_array((string)$a['account_code'], ['1000', '1010'], true);
                                $isUserAcc = !empty($a['user_id']);
                                $isBank = (stripos($a['account_name'], 'bank') !== false) || in_array((string)$a['account_code'], ['1010'], true);
                            ?>
                                <tr>
                                    <td><b><?=e($a['account_code'])?></b></td>
                                    <td>
                                        <b><?=e(format_ui_text($a['account_name']))?></b>
                                        <?php if ($isBank): ?>
                                            <span class="badge badge-blue">Bank</span>
                                        <?php elseif ($isUserAcc): ?>
                                            <span class="badge badge-purple">User Cashier</span>
                                        <?php else: ?>
                                            <span class="badge badge-green">Main Cash</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="right"><?=money($a['opening_balance'])?></td>
                                    <td class="right font-bold" style="color:#15803d;"><?=money($a['total_debit'])?></td>
                                    <td class="right font-bold" style="color:#b91c1c;"><?=money($a['total_credit'])?></td>
                                    <td class="right font-bold" style="font-size:14px;color:<?=$bal >= 0 ? '#15803d' : '#b91c1c'?>;">
                                        <?=money($bal)?>
                                    </td>
                                    <td class="right" style="white-space:nowrap;">
                                        <div class="btn-row" style="justify-content:flex-end;">
                                            <button type="button" class="btn sm" style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;padding:4px 9px;font-size:11.5px;font-weight:600;" onclick="openTransferModal(<?=$a['id']?>)" title="اس کھاتے سے رقم منتقل کریں">
                                                ⇄ Transfer
                                            </button>
                                            <?php if ($isBank): ?>
                                                <a href="cash_bank.php?tab=reconciliation&recon_acc_id=<?=$a['id']?>" class="btn success sm" style="padding:4px 9px;font-size:11.5px;" title="ریکونسیلیشن کریں">
                                                    🏦 Reconcile
                                                </a>
                                            <?php endif; ?>
                                            <a href="cash_bank.php?tab=overview&edit_id=<?=$a['id']?>#accFormCard" class="btn secondary sm" style="padding:4px 9px;font-size:11.5px;" title="ترمیم کریں">
                                                ✏️ Edit
                                            </a>
                                            <?php if (!$isCore): ?>
                                                <a href="cash_bank.php?delete_id=<?=$a['id']?>&token=<?=csrf_token()?>" class="btn danger sm" style="padding:4px 9px;font-size:11.5px;" onclick="return confirm('کیا آپ واقعی اکاؤنٹ \'<?=e(addslashes($a['account_name']))?>\' کو ڈیلیٹ کرنا چاہتے ہیں؟');" title="حذف کریں">
                                                    🗑️ Delete
                                                </a>
                                            <?php else: ?>
                                                <span style="font-size:11px;color:#94a3b8;padding:4px 6px;" title="بنیادی مرکزی اکاؤنٹ محفوظ ہے">(Core)</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </article>

            <!-- Add or Edit Bank Account Form Card (Hidden by default, shown on "+ Add Bank / Cash Account" click) -->
            <article class="form-card" id="accFormCard" style="<?= $showAccForm ? 'margin:20px 0 24px;' : 'display:none; margin:20px 0 24px;' ?>">
                <div class="form-card-header" style="display:flex;justify-content:space-between;align-items:flex-start;">
                    <div>
                        <h3><?= $editAcc ? '✏️ Edit Bank / Cash Account <span class="ur">(اکاؤنٹ میں ترمیم کریں)</span> — '.e(format_ui_text($editAcc['account_name'])) : 'Add New Bank or Cash Account <span class="ur">(نیا بینک یا کیش اکاؤنٹ شامل کریں)</span>' ?></h3>
                        <p><?= $editAcc ? 'Update account title, code or initial opening balance' : 'e.g. Meezan Bank, HBL, Allied Bank, or Branch Petty Cash Box' ?></p>
                    </div>
                    <?php if (!$editAcc): ?>
                        <button type="button" class="btn sm secondary" onclick="toggleAccountForm(false)" style="padding:4px 10px;" title="فارم بند کریں">✕</button>
                    <?php endif; ?>
                </div>

                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                    <input type="hidden" name="action" value="<?= $editAcc ? 'update_account' : 'save_account' ?>">
                    <?php if ($editAcc): ?>
                        <input type="hidden" name="account_id" value="<?=$editAcc['id']?>">
                    <?php endif; ?>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Account Code <span class="ur">(خودکار کوڈ)</span></label>
                            <input name="account_code" value="<?=e($editAcc['account_code'] ?? $nextAccountCode)?>" placeholder="e.g. <?=e($nextAccountCode)?>">
                            <small class="muted" style="font-size:11px;">
                                <span class="ur">ضرورت پڑنے پر کوڈ تبدیل کیا جا سکتا ہے۔</span>
                                <span class="en-only" style="display:none;">Code can be customized if needed.</span>
                            </small>
                        </div>

                        <div class="form-group">
                            <label>Account Name <span class="ur">(اکاؤنٹ کا نام و تفصیل)</span></label>
                            <input name="account_name" required placeholder="e.g. Meezan Bank Current A/c 0123456789" value="<?=e($editAcc['account_name'] ?? '')?>">
                        </div>

                        <div class="form-group">
                            <label>Opening Balance <span class="ur">(افتتاحی بیلنس PKR)</span></label>
                            <input type="number" step="0.01" name="opening_balance" value="<?=e($editAcc['opening_balance'] ?? '0.00')?>" placeholder="0.00">
                        </div>
                    </div>

                    <div class="btn-row" style="margin-top:10px;">
                        <button class="btn blue" type="submit"><?= $editAcc ? '💾 Update Account <span class="ur">(محفوظ کریں)</span>' : '💾 Create Account' ?></button>
                        <?php if ($editAcc): ?>
                            <a href="cash_bank.php?tab=overview" class="btn secondary">Cancel Editing <span class="ur">(منسوخ کریں)</span></a>
                        <?php else: ?>
                            <button class="btn secondary" type="button" onclick="toggleAccountForm(false)">✕ Cancel <span class="ur">(منسوخ کریں)</span></button>
                        <?php endif; ?>
                    </div>
                </form>
            </article>

            <!-- Cash/Bank Ledger Table -->
            <article class="panel">
                <div class="panel-heading">
                    <div>
                        <h3>Recent Cash & Bank Transactions</h3>
                        <p>Live transaction feed from posted vouchers</p>
                    </div>
                </div>

                <?php if (!$transactions): ?>
                    <div class="empty-state">
                        <div class="empty-icon">▣</div>
                        <b>No transactions recorded yet</b>
                        <p>When you record Income or Expense vouchers, transactions will appear here automatically.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Voucher</th>
                                    <th>Account</th>
                                    <th>Fund</th>
                                    <th>Particulars / Narration</th>
                                    <th class="right">Debit (In)</th>
                                    <th class="right">Credit (Out)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transactions as $t): ?>
                                    <tr>
                                        <td><?=e($t['entry_date'])?></td>
                                        <td><b><?=e($t['voucher_no'] ?: 'JV-'.$t['journal_entry_id'])?></b></td>
                                        <td><span class="badge badge-gray"><?=e(format_ui_text($t['account_name']))?></span></td>
                                        <td><span class="badge badge-blue"><?=e($t['fund_name'] ?? 'General')?></span></td>
                                        <td><?=e($t['description'] ?: $t['narration'])?></td>
                                        <td class="right font-bold" style="color:#15803d;"><?=(float)$t['debit'] > 0 ? money($t['debit']) : '—'?></td>
                                        <td class="right font-bold" style="color:#b91c1c;"><?=(float)$t['credit'] > 0 ? money($t['credit']) : '—'?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </article>
        <?php endif; ?>

        <?=render_app_footer($madrasaName)?>
    </main>
</div>

<script>
function toggleAccountForm(forceOpen) {
    var card = document.getElementById('accFormCard');
    var btn = document.getElementById('toggleAccBtn');
    if (!card) return;

    var isHidden = (card.style.display === 'none' || getComputedStyle(card).display === 'none');
    var open = (forceOpen !== undefined) ? forceOpen : isHidden;

    if (open) {
        card.style.display = 'block';
        if (btn) {
            btn.innerHTML = '✕ Close Form <span class="ur">(بند کریں)</span>';
        }
        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
        var nameInput = card.querySelector('input[name="account_name"]');
        if (nameInput) setTimeout(function() { nameInput.focus(); }, 250);
    } else {
        card.style.display = 'none';
        if (btn) {
            btn.innerHTML = '＋ Add Bank / Cash Account <span class="ur">(نیا اکاؤنٹ شامل کریں)</span>';
        }
    }
}

function toggleSelectAllRecon(master) {
    const boxes = document.querySelectorAll('.recon-checkbox');
    boxes.forEach(cb => cb.checked = master.checked);
}

function openTransferModal(preselectFromId) {
    var modal = document.getElementById('transferModal');
    if (!modal) return;
    if (preselectFromId) {
        var fromSel = document.getElementById('transferFromAcc');
        if (fromSel) {
            fromSel.value = preselectFromId;
            updateTransferFromBal();
        }
    }
    modal.style.display = 'flex';
}

function closeTransferModal() {
    var modal = document.getElementById('transferModal');
    if (modal) modal.style.display = 'none';
}

function updateTransferFromBal() {
    var sel = document.getElementById('transferFromAcc');
    var badge = document.getElementById('fromBalBadge');
    if (!sel || !badge) return;
    var opt = sel.options[sel.selectedIndex];
    if (opt && opt.getAttribute('data-bal')) {
        var b = parseFloat(opt.getAttribute('data-bal')) || 0;
        badge.innerHTML = 'دستیاب کیش بیلنس: <b>Rs. ' + b.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}) + '</b>';
    } else {
        badge.textContent = '';
    }
}

function onClosingAccChange(sel) {
    calcClosingTotals();
}

function calcClosingTotals() {
    var denoms = [
        { id: 'n5000', sub: 'sub5000', mult: 5000 },
        { id: 'n1000', sub: 'sub1000', mult: 1000 },
        { id: 'n500',  sub: 'sub500',  mult: 500 },
        { id: 'n100',  sub: 'sub100',  mult: 100 },
        { id: 'n50',   sub: 'sub50',   mult: 50 },
        { id: 'n20',   sub: 'sub20',   mult: 20 },
        { id: 'n10',   sub: 'sub10',   mult: 10 }
    ];

    var totalCash = 0;
    denoms.forEach(function(d) {
        var inp = document.getElementById(d.id);
        var subSpan = document.getElementById(d.sub);
        var count = inp ? (parseInt(inp.value, 10) || 0) : 0;
        var sub = count * d.mult;
        totalCash += sub;
        if (subSpan) {
            subSpan.textContent = 'Rs. ' + sub.toLocaleString('en-US');
        }
    });

    var coinsInp = document.getElementById('coinsInput');
    var coins = coinsInp ? (parseFloat(coinsInp.value) || 0) : 0;
    totalCash += coins;

    var accSel = document.getElementById('closingAccSelect');
    var sysBal = 0;
    if (accSel && accSel.selectedIndex >= 0) {
        var opt = accSel.options[accSel.selectedIndex];
        if (opt && opt.getAttribute('data-bal')) {
            sysBal = parseFloat(opt.getAttribute('data-bal')) || 0;
        }
    }

    var diff = totalCash - sysBal;

    var dispSys = document.getElementById('dispSystemBal');
    if (dispSys) {
        dispSys.textContent = 'Rs. ' + sysBal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    var dispPhys = document.getElementById('dispPhysicalCash');
    if (dispPhys) {
        dispPhys.textContent = 'Rs. ' + totalCash.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    var dispDiff = document.getElementById('dispDifference');
    var badge = document.getElementById('dispStatusBadge');
    if (dispDiff) {
        var prefix = diff > 0 ? '+' : '';
        dispDiff.textContent = prefix + 'Rs. ' + diff.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        if (Math.abs(diff) < 0.01) {
            dispDiff.style.color = '#15803d';
        } else if (diff < 0) {
            dispDiff.style.color = '#b91c1c';
        } else {
            dispDiff.style.color = '#b45309';
        }
    }

    if (badge) {
        if (totalCash === 0 && sysBal === 0) {
            badge.innerHTML = '<span class="badge" style="background:#f1f5f9;color:#64748b;font-weight:700;font-size:12px;padding:4px 10px;">Waiting for count...</span>';
        } else if (Math.abs(diff) < 0.01) {
            badge.innerHTML = '<span class="badge" style="background:#dcfce7;color:#15803d;font-weight:700;font-size:12px;padding:4px 10px;">✓ Cash Balanced (برابر ہے)</span>';
        } else if (diff < 0) {
            badge.innerHTML = '<span class="badge" style="background:#fee2e2;color:#b91c1c;font-weight:700;font-size:12px;padding:4px 10px;">⚠️ Cash Short (کیش کم ہے)</span>';
        } else {
            badge.innerHTML = '<span class="badge" style="background:#fef3c7;color:#b45309;font-weight:700;font-size:12px;padding:4px 10px;">ℹ️ Cash Excess (اضافی کیش)</span>';
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('closingAccSelect')) {
        calcClosingTotals();
    }
});
</script>

<!-- Funds Transfer Modal -->
<div id="transferModal" class="img-modal" style="display:none;" onclick="if(event.target===this) closeTransferModal();">
    <div class="img-modal-content" style="max-width:560px;width:100%;max-height:92vh;display:flex;flex-direction:column;border-radius:12px;overflow:hidden;box-shadow:0 25px 50px rgba(0,0,0,0.35);">
        <div class="img-modal-header" style="background:#1e3a8a;color:#fff;padding:14px 20px;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;">
            <div>
                <b style="font-size:16px;color:#fff;">⇄ Transfer Funds / Contra Voucher</b>
                <span class="ur" style="display:block;font-size:12.5px;color:#bfdbfe;margin-top:2px;">(اندرونی کیش و بینک فنڈ منتقلی)</span>
            </div>
            <button type="button" class="close-btn" onclick="closeTransferModal()" style="color:#fff;font-size:24px;line-height:1;background:none;border:none;cursor:pointer;">&times;</button>
        </div>
        <form method="post" action="cash_bank.php" enctype="multipart/form-data" style="margin:0;display:flex;flex-direction:column;flex:1;min-height:0;overflow:hidden;">
            <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
            <input type="hidden" name="action" value="transfer_funds">
            
            <div style="padding:20px;overflow-y:auto;flex:1;min-height:0;">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
                    <div class="form-group">
                        <label style="font-weight:600;font-size:12.5px;">Transfer Date <span class="ur">(تاریخ)</span> *</label>
                        <input type="date" name="transfer_date" value="<?=date('Y-m-d')?>" required style="width:100%;font-size:13px;padding:7px 10px;">
                    </div>
                    <div class="form-group">
                        <label style="font-weight:600;font-size:12.5px;">Linked Fund <span class="ur">(متعلقہ فنڈ)</span></label>
                        <select name="fund_id" style="width:100%;font-size:13px;padding:7px 10px;">
                            <option value="0">-- General Treasury / عام خزانہ --</option>
                            <?php foreach ($allFunds as $f): ?>
                                <option value="<?=$f['id']?>"><?=e($f['name'])?> (<?=e($f['fund_code'])?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:14px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 12px;">
                    <label style="font-weight:700;font-size:13px;color:#991b1b;display:flex;align-items:center;justify-content:space-between;">
                        <span>📤 From Account (رقم بھیجنے والا کھاتہ) *</span>
                    </label>
                    <select name="from_account_id" id="transferFromAcc" onchange="updateTransferFromBal()" required style="width:100%;font-size:13px;padding:8px 10px;margin-top:4px;">
                        <option value="">-- رقم کس کھاتے سے بھیجنی ہے منتخب کریں --</option>
                        <?php foreach ($accounts as $a): 
                            $b = (float)$a['opening_balance'] + (float)$a['total_debit'] - (float)$a['total_credit'];
                        ?>
                            <option value="<?=$a['id']?>" data-bal="<?=$b?>">
                                <?=e(format_ui_text($a['account_name']))?> (<?=e($a['account_code'])?>) — دستیاب: <?=money($b)?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="fromBalBadge" style="font-size:11.5px;color:#991b1b;font-weight:700;margin-top:4px;"></div>
                </div>

                <div class="form-group" style="margin-bottom:14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px 12px;">
                    <label style="font-weight:700;font-size:13px;color:#166534;">
                        📥 To Account (رقم وصول کرنے والا کھاتہ) *
                    </label>
                    <select name="to_account_id" id="transferToAcc" required style="width:100%;font-size:13px;padding:8px 10px;margin-top:4px;">
                        <option value="">-- رقم کس کھاتے میں جمع کرنی ہے منتخب کریں --</option>
                        <?php foreach ($accounts as $a): 
                            $b = (float)$a['opening_balance'] + (float)$a['total_debit'] - (float)$a['total_credit'];
                        ?>
                            <option value="<?=$a['id']?>">
                                <?=e(format_ui_text($a['account_name']))?> (<?=e($a['account_code'])?>) — موجودہ: <?=money($b)?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
                    <div class="form-group">
                        <label style="font-weight:600;font-size:12.5px;">Transfer Amount PKR <span class="ur">(رقم)</span> *</label>
                        <input type="number" step="0.01" min="1" name="amount" id="transferAmount" required placeholder="0.00" style="width:100%;font-size:14px;font-weight:700;padding:7px 10px;">
                    </div>
                    <div class="form-group">
                        <label style="font-weight:600;font-size:12.5px;">Slip / Cheque / Ref No <span class="ur">(رسید / چیک نمبر)</span></label>
                        <input type="text" name="reference_no" placeholder="e.g. Deposit Slip # 12345" style="width:100%;font-size:13px;padding:7px 10px;">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:14px;">
                    <label style="font-weight:600;font-size:12.5px;">Purpose & Description <span class="ur">(تفصیل و وجہ منتقلی)</span></label>
                    <textarea name="narration" rows="2" placeholder="e.g. فیلڈ کیش ڈائریکٹر کے حوالے کیا گیا یا میزان بینک میں جمع کروایا گیا..." style="width:100%;font-size:12.5px;padding:7px 10px;"></textarea>
                </div>

                <div class="form-group" style="background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;padding:10px 12px;margin-bottom:6px;">
                    <label style="font-weight:600;font-size:12.5px;display:flex;align-items:center;justify-content:space-between;">
                        <span>📷 Bank Deposit Slip Photo <span class="ur">(بینک سلپ یا دستخط کی تصویر)</span></span>
                        <span style="font-size:11px;color:#64748b;font-weight:normal;">اختیاری (Optional)</span>
                    </label>
                    <input type="file" name="slip_file" accept="image/jpeg,image/png,image/webp,application/pdf" style="font-size:12px;width:100%;margin-top:4px;">
                    <small class="muted" style="font-size:11px;display:block;margin-top:2px;">بینک میں رقم جمع کروانے کی مہر شدہ پرچی یا وصول کنندہ کے دستخط کی رسید اپلوڈ کریں۔</small>
                </div>
            </div>

            <div style="padding:14px 20px;border-top:1px solid #e2e8f0;background:#f8fafc;display:flex;justify-content:flex-end;gap:10px;flex-shrink:0;box-shadow:0 -4px 10px rgba(0,0,0,0.03);z-index:2;">
                <button type="button" class="btn secondary sm" onclick="closeTransferModal()">Cancel <span class="ur">(منسوخ)</span></button>
                <button type="submit" class="btn blue" style="font-weight:700;padding:8px 22px;font-size:13.5px;cursor:pointer;">🚀 Confirm & Transfer Funds <span class="ur">(رقم منتقل کریں)</span></button>
            </div>
        </form>
    </div>
</div>
</body>
</html>
