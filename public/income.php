<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();
require_permission('income.view');

$activePage = 'income';
$error = '';
$success = '';

// Handle Direct Approve of an Income Voucher (for Approvers / Super Admins)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve_income') {
    verify_csrf();
    if (!can_approve()) {
        http_response_code(403);
        exit('You do not have permission to approve vouchers.');
    }
    $vId = (int)($_POST['voucher_id'] ?? 0);
    if ($vId > 0) {
        $res = approve_voucher_with_settlement($pdo, $vId, (int)current_user()['id']);
        if ($res['success']) {
            header('Location: income.php?success=approved');
            exit;
        } else {
            $error = $res['message'] ?? 'Approval failed.';
        }
    }
}

// Handle Delete Income Voucher (Only Approvers / Super Admins)
if (isset($_GET['delete_id'])) {
    $delId = (int)$_GET['delete_id'];
    $token = $_GET['token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $error = 'Invalid security token for deletion.';
    } elseif (!can_approve()) {
        $error = 'Only Approvers and Super Admins have permission to delete vouchers.';
    } else {
        try {
            $pdo->beginTransaction();

            $chk = $pdo->prepare('SELECT voucher_no FROM vouchers WHERE id = ? AND voucher_type = "income"');
            $chk->execute([$delId]);
            $delVoucherNo = $chk->fetchColumn();

            if (!$delVoucherNo) {
                throw new RuntimeException("Voucher not found or cannot be deleted.");
            }

            // Find all journal entries linked to this voucher (including auto-settlement transfer entries)
            $stmtJe = $pdo->prepare('SELECT id FROM journal_entries WHERE voucher_id = ?');
            $stmtJe->execute([$delId]);
            $jeIds = $stmtJe->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($jeIds)) {
                $inClause = implode(',', array_map('intval', $jeIds));
                // Delete journal lines for all linked entries
                $pdo->exec("DELETE FROM journal_lines WHERE journal_entry_id IN ({$inClause})");
                // Delete the journal entries themselves
                $pdo->exec("DELETE FROM journal_entries WHERE id IN ({$inClause})");
            }

            // Delete physical attachment files and records
            try {
                $attStmt = $pdo->prepare("SELECT stored_name FROM attachments WHERE voucher_id = ?");
                $attStmt->execute([$delId]);
                while ($stored = $attStmt->fetchColumn()) {
                    $fPath = __DIR__ . '/uploads/receipts/' . $stored;
                    if (file_exists($fPath)) @unlink($fPath);
                }
            } catch (Exception $e) {}
            $pdo->prepare('DELETE FROM attachments WHERE voucher_id = ?')->execute([$delId]);

            // Delete the voucher record
            $stmtDelV = $pdo->prepare('DELETE FROM vouchers WHERE id = ?');
            $stmtDelV->execute([$delId]);

            $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address) VALUES (?, ?, ?, ?, ?)');
            $log->execute([current_user()['id'], 'delete_income_voucher', 'voucher', $delId, $_SERVER['REMOTE_ADDR'] ?? null]);

            $pdo->commit();
            header('Location: income.php?success=deleted&v=' . urlencode((string)$delVoucherNo));
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Failed to delete voucher: ' . $e->getMessage();
        }
    }
}

// Handle Add / Edit Income Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf();
    $action = $_POST['action'];

    if ($action === 'save_income') {
        $date = trim($_POST['voucher_date'] ?? date('Y-m-d'));
        $fundId = (int)($_POST['fund_id'] ?? 0);
        $assetAccountId = (int)($_POST['asset_account_id'] ?? 0);
        $paymentMethod = trim($_POST['payment_method'] ?? 'cash');

        $userCashAcc = get_or_create_user_cash_account($pdo, (int)current_user()['id']);
        $userCashAccId = (int)($userCashAcc['id'] ?? 0);

        if ($paymentMethod === 'cash') {
            if (!can_approve() || $assetAccountId <= 0) {
                $assetAccountId = $userCashAccId;
            } else {
                $accCheck = $pdo->prepare("SELECT id FROM accounts WHERE id = ? AND is_cash_or_bank = 1 AND is_active = 1");
                $accCheck->execute([$assetAccountId]);
                if (!$accCheck->fetchColumn()) {
                    $assetAccountId = $userCashAccId;
                }
            }
        } else {
            // Bank / Online / Cheque
            $bankCheck = $pdo->prepare("SELECT id FROM accounts WHERE id = ? AND is_cash_or_bank = 1 AND is_active = 1 AND (user_id IS NULL OR account_code = '1010' OR account_name LIKE '%bank%' OR account_name LIKE '%بینک%')");
            $bankCheck->execute([$assetAccountId]);
            if (!$bankCheck->fetchColumn()) {
                $bankDef = $pdo->query("SELECT id FROM accounts WHERE is_cash_or_bank = 1 AND is_active = 1 AND (account_code = '1010' OR account_name LIKE '%bank%' OR account_name LIKE '%بینک%') ORDER BY (account_code = '1010') DESC, id ASC LIMIT 1")->fetchColumn();
                $assetAccountId = (int)($bankDef ?: $userCashAccId);
            }
        }
        if ($assetAccountId <= 0) {
            $assetAccountId = $userCashAccId;
        }

        if (!can_approve()) {
            $newDonorName = '';
        } else {
            $newDonorName = trim($_POST['new_donor_name'] ?? '');
        }
        $incomeAccountId = (int)($_POST['income_account_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $paymentMethod = trim($_POST['payment_method'] ?? 'cash');
        $referenceNo = trim($_POST['reference_no'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $donorId = (int)($_POST['donor_id'] ?? 0);
        $donorPhoneInput = trim($_POST['donor_phone'] ?? ($_POST['new_donor_phone'] ?? ''));

        // Multi-currency support (Feature #4)
        $foreignCurr = trim($_POST['foreign_currency'] ?? 'PKR');
        $foreignAmt = !empty($_POST['foreign_amount']) ? (float)$_POST['foreign_amount'] : null;
        $exchangeRate = !empty($_POST['exchange_rate']) ? (float)$_POST['exchange_rate'] : null;

        if ($amount <= 0) {
            $error = 'Please enter a valid amount greater than 0.';
        } elseif ($fundId <= 0 || $assetAccountId <= 0 || $incomeAccountId <= 0) {
            $error = 'Please select Fund, Received-in Account, and Income Head.';
        } elseif (!is_fund_allowed_for_user($fundId)) {
            $error = 'آپ کے اکاؤنٹ کے پاس اس فنڈ میں آمدنی درج کرنے کا اختیار نہیں ہے۔';
        } else {
            try {
                $pdo->beginTransaction();

                if (!empty($newDonorName) && can_approve()) {
                    $stmtDonor = $pdo->prepare('INSERT INTO donors (donor_name, phone, fund_id) VALUES (?, ?, ?)');
                    $stmtDonor->execute([$newDonorName, $donorPhoneInput ?: null, $fundId > 0 ? $fundId : null]);
                    $donorId = (int)$pdo->lastInsertId();
                } elseif ($donorId > 0 && !empty($donorPhoneInput)) {
                    // Update donor phone if it was empty
                    $currPhone = $pdo->query("SELECT phone FROM donors WHERE id = {$donorId}")->fetchColumn();
                    if (empty($currPhone)) {
                        $pdo->prepare('UPDATE donors SET phone = ? WHERE id = ?')->execute([$donorPhoneInput, $donorId]);
                    }
                }

                $prefix = 'RV-' . date('Ym', strtotime($date)) . '-';
                $lastNo = $pdo->query("SELECT voucher_no FROM vouchers WHERE voucher_no LIKE '{$prefix}%' ORDER BY id DESC LIMIT 1")->fetchColumn();
                $nextSeq = 1;
                if ($lastNo && preg_match('/-(\d+)$/', $lastNo, $m)) {
                    $nextSeq = (int)$m[1] + 1;
                }
                $voucherNo = $prefix . str_pad((string)$nextSeq, 4, '0', STR_PAD_LEFT);

                $donorName = null;
                if ($donorId > 0) {
                    $donorName = $pdo->query("SELECT donor_name FROM donors WHERE id = {$donorId}")->fetchColumn();
                }

                // Workflow status: Direct post if user has voucher.direct_post or is Approver/Admin; otherwise 'draft'
                $isDirectPost = has_permission('voucher.direct_post') || can_approve();
                $voucherStatus = $isDirectPost ? 'posted' : 'draft';
                $postedAt = $isDirectPost ? date('Y-m-d H:i:s') : null;

                $userRegId = function_exists('get_user_region_id') ? get_user_region_id((int)current_user()['id']) : 0;
                $postRegId = (int)($_POST['region_id'] ?? 0);
                $voucherRegionId = $userRegId > 0 ? $userRegId : ($postRegId > 0 ? $postRegId : null);

                $stmtVoucher = $pdo->prepare('INSERT INTO vouchers (voucher_no, voucher_type, voucher_date, fund_id, region_id, donor_id, payee_name, payment_method, reference_no, description, total_amount, foreign_currency, foreign_amount, exchange_rate, status, created_by, posted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmtVoucher->execute([
                    $voucherNo, 'income', $date, $fundId, $voucherRegionId, $donorId > 0 ? $donorId : null,
                    $donorName, $paymentMethod, $referenceNo, $description, $amount,
                    $foreignCurr, $foreignAmt, $exchangeRate, $voucherStatus,
                    current_user()['id'], $postedAt
                ]);
                $voucherId = (int)$pdo->lastInsertId();

                $narration = $description ?: ("Income receipt from " . ($donorName ?: 'General Donor'));
                $stmtJe = $pdo->prepare('INSERT INTO journal_entries (voucher_id, region_id, entry_date, narration, status, created_by) VALUES (?, ?, ?, ?, ?, ?)');
                $stmtJe->execute([$voucherId, $voucherRegionId, $date, $narration, $voucherStatus, current_user()['id']]);
                $jeId = (int)$pdo->lastInsertId();

                $stmtLine = $pdo->prepare('INSERT INTO journal_lines (journal_entry_id, account_id, fund_id, description, debit, credit) VALUES (?, ?, ?, ?, ?, ?)');
                $stmtLine->execute([$jeId, $assetAccountId, $fundId, 'Received: ' . $narration, $amount, 0.00]);
                $stmtLine->execute([$jeId, $incomeAccountId, $fundId, 'Donation/Income: ' . $narration, 0.00, $amount]);

                // Handle Bank Slip / Cheque / Transfer Photo Attachment
                if (isset($_FILES['receipt_file']) && $_FILES['receipt_file']['error'] === UPLOAD_ERR_OK) {
                    $fileTmp = $_FILES['receipt_file']['tmp_name'];
                    $fileName = $_FILES['receipt_file']['name'];
                    $fileSize = $_FILES['receipt_file']['size'];
                    $fileType = mime_content_type($fileTmp) ?: $_FILES['receipt_file']['type'];
                    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

                    if (in_array($ext, $allowedExts, true)) {
                        $uploadDir = __DIR__ . '/uploads/receipts/';
                        if (!is_dir($uploadDir)) {
                            @mkdir($uploadDir, 0755, true);
                        }
                        $storedName = 'income_' . $voucherId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                        if (move_uploaded_file($fileTmp, $uploadDir . $storedName)) {
                            $pdo->prepare('DELETE FROM attachments WHERE voucher_id = ?')->execute([$voucherId]);
                            $stmtAtt = $pdo->prepare('INSERT INTO attachments (voucher_id, original_name, stored_name, mime_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)');
                            $stmtAtt->execute([$voucherId, $fileName, $storedName, $fileType, $fileSize, current_user()['id']]);
                        }
                    }
                }

                $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address) VALUES (?, ?, ?, ?, ?)');
                $log->execute([current_user()['id'], 'create_income_voucher', 'voucher', $voucherId, $_SERVER['REMOTE_ADDR'] ?? null]);

                $pdo->commit();
                header('Location: income.php?success=created&v=' . urlencode($voucherNo) . '&st=' . $voucherStatus . '&vid=' . $voucherId . '&show_slip=1');
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Failed to record income: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'update_income') {
        if (!can_approve()) {
            http_response_code(403);
            exit('Permission denied: Only Approvers and Super Admins can edit vouchers.');
        }
        $voucherId = (int)($_POST['voucher_id'] ?? 0);
        $date = trim($_POST['voucher_date'] ?? date('Y-m-d'));
        $fundId = (int)($_POST['fund_id'] ?? 0);
        $assetAccountId = (int)($_POST['asset_account_id'] ?? 0);
        $paymentMethod = trim($_POST['payment_method'] ?? 'cash');

        $userCashAcc = get_or_create_user_cash_account($pdo, (int)current_user()['id']);
        $userCashAccId = (int)($userCashAcc['id'] ?? 0);

        if ($paymentMethod === 'cash') {
            if (!can_approve() || $assetAccountId <= 0) {
                $assetAccountId = $userCashAccId;
            } else {
                $accCheck = $pdo->prepare("SELECT id FROM accounts WHERE id = ? AND is_cash_or_bank = 1 AND is_active = 1");
                $accCheck->execute([$assetAccountId]);
                if (!$accCheck->fetchColumn()) {
                    $assetAccountId = $userCashAccId;
                }
            }
        } else {
            // Bank / Online / Cheque
            $bankCheck = $pdo->prepare("SELECT id FROM accounts WHERE id = ? AND is_cash_or_bank = 1 AND is_active = 1 AND (user_id IS NULL OR account_code = '1010' OR account_name LIKE '%bank%' OR account_name LIKE '%بینک%')");
            $bankCheck->execute([$assetAccountId]);
            if (!$bankCheck->fetchColumn()) {
                $bankDef = $pdo->query("SELECT id FROM accounts WHERE is_cash_or_bank = 1 AND is_active = 1 AND (account_code = '1010' OR account_name LIKE '%bank%' OR account_name LIKE '%بینک%') ORDER BY (account_code = '1010') DESC, id ASC LIMIT 1")->fetchColumn();
                $assetAccountId = (int)($bankDef ?: $userCashAccId);
            }
        }
        if ($assetAccountId <= 0) {
            $assetAccountId = $userCashAccId;
        }

        if (!can_approve()) {
            $newDonorName = '';
        } else {
            $newDonorName = trim($_POST['new_donor_name'] ?? '');
        }
        $incomeAccountId = (int)($_POST['income_account_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $paymentMethod = trim($_POST['payment_method'] ?? 'cash');
        $referenceNo = trim($_POST['reference_no'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $donorId = (int)($_POST['donor_id'] ?? 0);
        $donorPhoneInput = trim($_POST['donor_phone'] ?? '');

        // Multi-currency support (Feature #4)
        $foreignCurr = trim($_POST['foreign_currency'] ?? 'PKR');
        $foreignAmt = !empty($_POST['foreign_amount']) ? (float)$_POST['foreign_amount'] : null;
        $exchangeRate = !empty($_POST['exchange_rate']) ? (float)$_POST['exchange_rate'] : null;

        if ($voucherId <= 0 || $amount <= 0 || $fundId <= 0 || $assetAccountId <= 0 || $incomeAccountId <= 0) {
            $error = 'Please fill all required voucher fields.';
        } else {
            try {
                $pdo->beginTransaction();

                if (!empty($donorPhoneInput) && $donorId > 0) {
                    $pdo->prepare('UPDATE donors SET phone = ? WHERE id = ?')->execute([$donorPhoneInput, $donorId]);
                }

                $donorName = null;
                if ($donorId > 0) {
                    $donorName = $pdo->query("SELECT donor_name FROM donors WHERE id = {$donorId}")->fetchColumn();
                }

                $stmtUpV = $pdo->prepare('UPDATE vouchers SET voucher_date=?, fund_id=?, donor_id=?, payee_name=?, payment_method=?, reference_no=?, description=?, total_amount=?, foreign_currency=?, foreign_amount=?, exchange_rate=? WHERE id=?');
                $stmtUpV->execute([$date, $fundId, $donorId > 0 ? $donorId : null, $donorName, $paymentMethod, $referenceNo, $description, $amount, $foreignCurr, $foreignAmt, $exchangeRate, $voucherId]);

                $stmtJeId = $pdo->prepare('SELECT id FROM journal_entries WHERE voucher_id = ?');
                $stmtJeId->execute([$voucherId]);
                $jeId = (int)$stmtJeId->fetchColumn();

                $narration = $description ?: ("Income receipt from " . ($donorName ?: 'General Donor'));
                if ($jeId) {
                    $pdo->prepare('UPDATE journal_entries SET entry_date=?, narration=? WHERE id=?')->execute([$date, $narration, $jeId]);
                    $pdo->prepare('DELETE FROM journal_lines WHERE journal_entry_id = ?')->execute([$jeId]);
                } else {
                    $stmtJeNew = $pdo->prepare('INSERT INTO journal_entries (voucher_id, entry_date, narration, status, created_by) VALUES (?, ?, ?, "posted", ?)');
                    $stmtJeNew->execute([$voucherId, $date, $narration, current_user()['id']]);
                    $jeId = (int)$pdo->lastInsertId();
                }

                $stmtLine = $pdo->prepare('INSERT INTO journal_lines (journal_entry_id, account_id, fund_id, description, debit, credit) VALUES (?, ?, ?, ?, ?, ?)');
                $stmtLine->execute([$jeId, $assetAccountId, $fundId, 'Received: ' . $narration, $amount, 0.00]);
                $stmtLine->execute([$jeId, $incomeAccountId, $fundId, 'Donation/Income: ' . $narration, 0.00, $amount]);

                // Handle Bank Slip / Cheque / Transfer Photo Attachment
                if (isset($_FILES['receipt_file']) && $_FILES['receipt_file']['error'] === UPLOAD_ERR_OK) {
                    $fileTmp = $_FILES['receipt_file']['tmp_name'];
                    $fileName = $_FILES['receipt_file']['name'];
                    $fileSize = $_FILES['receipt_file']['size'];
                    $fileType = mime_content_type($fileTmp) ?: $_FILES['receipt_file']['type'];
                    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

                    if (in_array($ext, $allowedExts, true)) {
                        $uploadDir = __DIR__ . '/uploads/receipts/';
                        if (!is_dir($uploadDir)) {
                            @mkdir($uploadDir, 0755, true);
                        }
                        $storedName = 'income_' . $voucherId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                        if (move_uploaded_file($fileTmp, $uploadDir . $storedName)) {
                            $pdo->prepare('DELETE FROM attachments WHERE voucher_id = ?')->execute([$voucherId]);
                            $stmtAtt = $pdo->prepare('INSERT INTO attachments (voucher_id, original_name, stored_name, mime_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)');
                            $stmtAtt->execute([$voucherId, $fileName, $storedName, $fileType, $fileSize, current_user()['id']]);
                        }
                    }
                }

                $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address) VALUES (?, ?, ?, ?, ?)');
                $log->execute([current_user()['id'], 'edit_income_voucher', 'voucher', $voucherId, $_SERVER['REMOTE_ADDR'] ?? null]);

                $pdo->commit();
                header('Location: income.php?success=updated');
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Failed to update voucher: ' . $e->getMessage();
            }
        }
    }
}

// Edit Mode Data
$editVoucher = null;
$editAssetAcc = 0;
$editIncomeAcc = 0;
if (isset($_GET['edit_id'])) {
    if (!can_approve()) {
        $error = 'Once submitted, vouchers cannot be edited by users. Only Approvers or Super Admins can modify vouchers.';
    } else {
        $editId = (int)$_GET['edit_id'];
        $stmtE = $pdo->prepare("SELECT * FROM vouchers WHERE id = ? AND voucher_type = 'income'");
        $stmtE->execute([$editId]);
        $editVoucher = $stmtE->fetch();

        if ($editVoucher) {
            if (!empty($editVoucher['donor_id'])) {
                $editVoucher['donor_phone'] = $pdo->query("SELECT phone FROM donors WHERE id = " . (int)$editVoucher['donor_id'])->fetchColumn() ?: '';
            }
            $jlRows = $pdo->query("SELECT jl.account_id, jl.debit, jl.credit, a.account_type 
                                   FROM journal_lines jl 
                                   JOIN journal_entries je ON je.id = jl.journal_entry_id 
                                   JOIN accounts a ON a.id = jl.account_id 
                                   WHERE je.voucher_id = {$editId}")->fetchAll();
            foreach ($jlRows as $row) {
                if ($row['account_type'] === 'asset' || (float)$row['debit'] > 0) {
                    $editAssetAcc = (int)$row['account_id'];
                }
                if ($row['account_type'] === 'income' || (float)$row['credit'] > 0) {
                    $editIncomeAcc = (int)$row['account_id'];
                }
            }
        }
    }
}

// Check Auto-Display of Black Thermal Slip
$autoSlipVoucher = null;
if (isset($_GET['vid']) && (int)$_GET['vid'] > 0 && isset($_GET['show_slip'])) {
    $slipVid = (int)$_GET['vid'];
    $stmtSlip = $pdo->prepare("SELECT v.*, f.name AS fund_name, f.fund_code, d.donor_name, d.phone AS donor_phone, d.address AS donor_address, u.full_name AS submitter_name
                               FROM vouchers v
                               LEFT JOIN funds f ON f.id = v.fund_id
                               LEFT JOIN donors d ON d.id = v.donor_id
                               LEFT JOIN users u ON u.id = v.created_by
                               WHERE v.id = ?");
    $stmtSlip->execute([$slipVid]);
    $autoSlipVoucher = $stmtSlip->fetch() ?: null;
}

// Fetch dropdown data
$currentUserId = (int)current_user()['id'];
$userCashAcc = get_or_create_user_cash_account($pdo, $currentUserId);
$funds = get_user_allowed_funds();
$isSuperAdmin = in_array(current_user()['role'] ?? '', ['Super Admin', 'Administrator'], true);
$userFundIds = get_user_assigned_fund_ids($currentUserId);
$hasFundRestriction = (!$isSuperAdmin && !empty($userFundIds));

$currOrgMode = function_exists('get_org_mode') ? get_org_mode() : 'trust';
$bankAccounts = function_exists('get_or_create_bank_accounts') ? get_or_create_bank_accounts($pdo) : [];
$allAssetAccounts = $pdo->query("SELECT id, account_code, account_name, user_id FROM accounts WHERE is_cash_or_bank=1 AND is_active=1 ORDER BY (account_code = '1000') DESC, (account_code = '1010') DESC, account_code ASC")->fetchAll() ?: [];

$bankIds = array_map(fn($b) => (int)$b['id'], $bankAccounts);
$otherCashAccounts = [];
foreach ($allAssetAccounts as $acc) {
    if (in_array((int)$acc['id'], $bankIds, true)) continue;
    if (!empty($acc['user_id']) && (int)$acc['user_id'] === $currentUserId) continue;
    $otherCashAccounts[] = $acc;
}
$assetAccounts = $allAssetAccounts;
$incomeAccounts = $pdo->query("SELECT id, account_code, account_name FROM accounts WHERE account_type='income' AND is_active=1 AND scope IN ('all', '{$currOrgMode}') ORDER BY account_code ASC")->fetchAll();

$userRegId = function_exists('get_user_region_id') ? get_user_region_id($currentUserId) : 0;
$isRegDirector = function_exists('is_regional_director') ? is_regional_director() : false;
$filterRegion = (int)($_GET['region_id'] ?? 0);
$allRegions = function_exists('get_all_active_regions') ? get_all_active_regions() : [];
$myRegion = $userRegId > 0 && function_exists('get_region_by_id') ? get_region_by_id($userRegId) : null;

if ($hasFundRestriction) {
    $inFunds = implode(',', array_map('intval', $userFundIds));
    $donors = $pdo->query("SELECT d.id, d.donor_name, d.phone, d.fund_id, f.name AS fund_name, f.fund_code 
                           FROM donors d 
                           LEFT JOIN funds f ON f.id = d.fund_id 
                           WHERE d.fund_id IN ($inFunds) 
                           ORDER BY d.donor_name ASC")->fetchAll();
} else {
    $donors = $pdo->query('SELECT d.id, d.donor_name, d.phone, d.fund_id, f.name AS fund_name, f.fund_code FROM donors d LEFT JOIN funds f ON f.id = d.fund_id ORDER BY d.donor_name ASC')->fetchAll();
}

// Statistics (Province/Region Aware)
if ($isRegDirector && $userRegId > 0) {
    $totalIncome = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM vouchers WHERE voucher_type='income' AND status='posted' AND region_id={$userRegId}")->fetchColumn();
    $pendingIncome = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM vouchers WHERE voucher_type='income' AND status='draft' AND region_id={$userRegId}")->fetchColumn();
    $thisMonthIncome = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM vouchers WHERE voucher_type='income' AND status='posted' AND voucher_date >= DATE_FORMAT(NOW(), '%Y-%m-01') AND region_id={$userRegId}")->fetchColumn();
    $vouchersCount = (int)$pdo->query("SELECT COUNT(*) FROM vouchers WHERE voucher_type='income' AND status='posted' AND region_id={$userRegId}")->fetchColumn();
    $pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM vouchers WHERE voucher_type='income' AND status='draft' AND region_id={$userRegId}")->fetchColumn();
} elseif (!can_approve()) {
    $totalIncome = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM vouchers WHERE voucher_type='income' AND status='posted' AND created_by={$currentUserId}")->fetchColumn();
    $pendingIncome = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM vouchers WHERE voucher_type='income' AND status='draft' AND created_by={$currentUserId}")->fetchColumn();
    $thisMonthIncome = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM vouchers WHERE voucher_type='income' AND status='posted' AND voucher_date >= DATE_FORMAT(NOW(), '%Y-%m-01') AND created_by={$currentUserId}")->fetchColumn();
    $vouchersCount = (int)$pdo->query("SELECT COUNT(*) FROM vouchers WHERE voucher_type='income' AND status='posted' AND created_by={$currentUserId}")->fetchColumn();
    $pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM vouchers WHERE voucher_type='income' AND status='draft' AND created_by={$currentUserId}")->fetchColumn();
} else {
    $regScope = ($isSuperAdmin && $filterRegion > 0) ? " AND region_id={$filterRegion}" : "";
    $totalIncome = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM vouchers WHERE voucher_type='income' AND status='posted'{$regScope}")->fetchColumn();
    $pendingIncome = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM vouchers WHERE voucher_type='income' AND status='draft'{$regScope}")->fetchColumn();
    $thisMonthIncome = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM vouchers WHERE voucher_type='income' AND status='posted' AND voucher_date >= DATE_FORMAT(NOW(), '%Y-%m-01'){$regScope}")->fetchColumn();
    $vouchersCount = (int)$pdo->query("SELECT COUNT(*) FROM vouchers WHERE voucher_type='income' AND status='posted'{$regScope}")->fetchColumn();
    $pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM vouchers WHERE voucher_type='income' AND status='draft'{$regScope}")->fetchColumn();
}

// Search / Filter
$filterFund = (int)($_GET['fund_id'] ?? 0);
$statusFilter = trim($_GET['status'] ?? 'all');
$search = trim($_GET['search'] ?? '');

// Prefill support from Zakat Calculator, Pledges Tracker, or external links
$prefillFund = $_GET['prefill_fund'] ?? '';
$prefillAmount = $_GET['prefill_amount'] ?? ($_GET['amount'] ?? '');
$prefillDesc = $_GET['prefill_desc'] ?? ($_GET['desc'] ?? '');
$prefillDonorId = (int)($_GET['donor_id'] ?? 0);
$prefillFundId = (int)($_GET['fund_id'] ?? 0);
$prefillDate = trim($_GET['voucher_date'] ?? ($_GET['date'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $prefillDate)) {
    $prefillDate = '';
}
if ($prefillFund === 'zakat') {
    foreach ($funds as $f) {
        if ($f['fund_code'] === 'ZAK' || stripos($f['name'], 'zakat') !== false || strpos($f['name'], 'زکوٰ') !== false) {
            $prefillFundId = (int)$f['id'];
            break;
        }
    }
}
$prefillIncomeAccId = 0;
if ($prefillFund === 'zakat') {
    foreach ($incomeAccounts as $ia) {
        if ($ia['account_code'] === '4010' || stripos($ia['account_name'], 'zakat') !== false || strpos($ia['account_name'], 'زکوٰ') !== false) {
            $prefillIncomeAccId = (int)$ia['id'];
            break;
        }
    }
}

$query = "SELECT v.*, f.name AS fund_name, f.fund_code, d.donor_name, d.phone AS donor_phone, d.address AS donor_address, u.full_name AS submitter_name, reg.name AS region_name, reg.code AS region_code, att.stored_name AS receipt_file 
          FROM vouchers v
          LEFT JOIN funds f ON f.id = v.fund_id
          LEFT JOIN donors d ON d.id = v.donor_id
          LEFT JOIN users u ON u.id = v.created_by
          LEFT JOIN regions reg ON reg.id = v.region_id
          LEFT JOIN attachments att ON att.voucher_id = v.id
          WHERE v.voucher_type='income'";
$params = [];

if ($isRegDirector && $userRegId > 0) {
    $query .= " AND v.region_id = {$userRegId}";
} elseif (!can_approve()) {
    $query .= " AND v.created_by = {$currentUserId}";
} elseif ($isSuperAdmin && $filterRegion > 0) {
    $query .= " AND v.region_id = {$filterRegion}";
}
if ($hasFundRestriction) {
    $inFunds = implode(',', array_map('intval', $userFundIds));
    $query .= " AND v.fund_id IN ($inFunds)";
}

if ($statusFilter === 'posted') {
    $query .= " AND v.status = 'posted'";
} elseif ($statusFilter === 'draft') {
    $query .= " AND v.status = 'draft'";
}

if ($filterFund > 0) {
    $query .= " AND v.fund_id = ?";
    $params[] = $filterFund;
}
if (!empty($search)) {
    $query .= " AND (v.voucher_no LIKE ? OR d.donor_name LIKE ? OR v.payee_name LIKE ? OR v.description LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
$query .= " ORDER BY v.voucher_date DESC, v.id DESC LIMIT 100";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$incomeList = $stmt->fetchAll();

$madrasaName = get_setting('madrasa_name', 'Trust Accounts');
$madrasaAddress = get_setting('madrasa_address', '');
$madrasaPhone = get_setting('madrasa_phone', '');
$orgLogo = function_exists('get_org_logo_url') ? get_org_logo_url() : null;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Income Management | <?=e($madrasaName)?></title>
    <link rel="stylesheet" href="assets/style.css?v=<?=css_ver()?>">
    <script src="assets/html2canvas.min.js"></script>
</head>
<body>
<div class="app-shell">
    <?php require __DIR__ . '/../app/sidebar.php'; ?>

    <main class="main">
        <header class="topbar">
            <div>
                <span class="eyebrow">ACCOUNTING</span>
                <h1>Income <span class="ur">(آمدنی / وصولی)</span></h1>
            </div>
            <div class="btn-row">
                <?php if ($editVoucher): ?>
                    <a href="income.php" class="btn secondary sm">＋ New Income Voucher</a>
                <?php else: ?>
                    <button class="btn blue sm" onclick="document.getElementById('incomeFormCard').scrollIntoView({behavior:'smooth'});">
                        ＋ New Income Voucher
                    </button>
                <?php endif; ?>
            </div>
        </header>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert success">
                <?php if ($_GET['success'] === 'created'): ?>
                    <?php if (($_GET['st'] ?? '') === 'draft'): ?>
                        Receipt Voucher <b><?=e($_GET['v'] ?? '')?></b> saved! It is now <strong>PENDING APPROVAL <span class="ur">(زیرِ التواء)</span></strong>. It will be added to Main Cash/Bank once approved by the checker.
                    <?php else: ?>
                        Receipt Voucher <b><?=e($_GET['v'] ?? '')?></b> successfully recorded and posted to ledger!
                    <?php endif; ?>
                <?php elseif ($_GET['success'] === 'updated'): ?>
                    Voucher successfully updated and ledger rebalanced!
                <?php elseif ($_GET['success'] === 'deleted'): ?>
                    Voucher <b><?=e($_GET['v'] ?? '')?></b> and its journal ledger lines successfully deleted!
                <?php elseif ($_GET['success'] === 'approved'): ?>
                    ✓ Voucher approved successfully and posted to the Main Ledger!
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert error"><?=e($error)?></div>
        <?php endif; ?>

        <?php if ($isRegDirector && $myRegion): ?>
            <div style="background:#eff6ff;border:1px solid #bfdbfe;border-left:4px solid #2563eb;padding:12px 16px;border-radius:8px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <span style="font-size:24px;">🏛️</span>
                    <div>
                        <b style="color:#1e40af;font-size:14.5px;"><?=e($myRegion['name'])?> Directorate <span class="ur">(صوبائی ڈائریکٹوریٹ)</span></b>
                        <div style="font-size:12px;color:#2563eb;">Managing and monitoring income receipts for <?=e($myRegion['name'])?> province.</div>
                    </div>
                </div>
                <span class="badge" style="background:#dbeafe;color:#1e40af;border:1px solid #93c5fd;font-weight:700;">Code: <?=e($myRegion['code'])?></span>
            </div>
        <?php endif; ?>

        <!-- Stats Overview (Only visible to Approvers/Admins on Desktop) -->
        <?php if (can_approve()): ?>
        <section class="stats-grid income-stats-grid voucher-stats-grid" style="margin:22px 0 24px;">
            <article class="stat-card">
                <div class="stat-top"><span>Total Posted Receipts</span><span class="stat-icon green">↗</span></div>
                <strong><?=money($totalIncome)?></strong>
                <small>Approved in Cash/Bank ledger</small>
            </article>
            <article class="stat-card">
                <div class="stat-top"><span>Pending Approval <span class="ur">(زیرِ التواء)</span></span><span class="stat-icon yellow">⏳</span></div>
                <strong style="color:#d97706;"><?=money($pendingIncome)?></strong>
                <small><?=$pendingCount?> vouchers waiting for approval</small>
            </article>
            <article class="stat-card">
                <div class="stat-top"><span>This Month (Posted)</span><span class="stat-icon green">📅</span></div>
                <strong><?=money($thisMonthIncome)?></strong>
                <small><?=date('F Y')?></small>
            </article>
            <article class="stat-card">
                <div class="stat-top"><span>Approved Vouchers</span><span class="stat-icon blue">🧾</span></div>
                <strong><?=$vouchersCount?></strong>
                <small>Active posted receipts</small>
            </article>
        </section>
        <?php endif; ?>

        <!-- Professional Form Card (Add / Edit) -->
        <article class="form-card" id="incomeFormCard">
            <div class="form-card-header">
                <h3><?= $editVoucher ? 'Edit Income Voucher <span class="ur">(واؤچر میں ترمیم کریں)</span> — '.e($editVoucher['voucher_no']) : 'Record New Income Receipt <span class="ur">(رسید وصولی آمدنی)</span>' ?></h3>
                <p><?= $editVoucher ? 'Modify voucher details and update double-entry journals' : 'Enter donation, fees, Zakat, or charity with automatic double-entry journal posting' ?></p>
            </div>

            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                <input type="hidden" name="action" value="<?= $editVoucher ? 'update_income' : 'save_income' ?>">
                <?php if ($editVoucher): ?>
                    <input type="hidden" name="voucher_id" value="<?=$editVoucher['id']?>">
                <?php endif; ?>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Voucher Date <span class="ur">تاریخ</span></label>
                        <input type="date" name="voucher_date" value="<?=e($editVoucher['voucher_date'] ?? ($prefillDate ?: date('Y-m-d')))?>" required>
                    </div>

                    <?php if ($isSuperAdmin && !empty($allRegions)): ?>
                    <div class="form-group">
                        <label>Province / Region <span class="ur">(صوبہ / علاقہ)</span></label>
                        <select name="region_id">
                            <option value="">-- Central / Head Office (مرکزی دفتر) --</option>
                            <?php foreach ($allRegions as $reg): ?>
                                <option value="<?=$reg['id']?>" <?=($editVoucher && (int)($editVoucher['region_id'] ?? 0) === (int)$reg['id']) ? 'selected' : ''?>>
                                    🏛️ <?=e($reg['name'])?> (<?=e($reg['code'])?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label>Fund Account <span class="ur">(کس فنڈ میں جمع ہوا)</span></label>
                        <select name="fund_id" id="fundSelect" required>
                            <option value="">-- Select Fund <span class="ur">(فنڈ منتخب کریں)</span> --</option>
                            <?php foreach ($funds as $f): ?>
                                <?php $isSelected = ($editVoucher && (int)$editVoucher['fund_id'] === (int)$f['id']) || (!$editVoucher && $prefillFundId === (int)$f['id']); ?>
                                <option value="<?=$f['id']?>" <?=$isSelected ? 'selected' : ''?>>
                                    <?=e($f['name'])?> (<?=e($f['fund_code'])?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Received Into <span class="ur">(وصولی کھاتہ کیش / بینک)</span> *</label>
                        <select name="asset_account_id" id="inc_asset_account_id" onchange="onIncomeAccountChange()" required>
                            <?php if (!empty($userCashAcc)): ?>
                                <optgroup label="💼 آپ کا ذاتی نقد کھاتہ (Your Cash Custody)">
                                    <?php $isMyCashSelected = ($editVoucher ? ((int)$editAssetAcc === (int)$userCashAcc['id']) : true); ?>
                                    <option value="<?=$userCashAcc['id']?>" data-type="cash" <?=$isMyCashSelected ? 'selected' : ''?>>
                                        <?=e($userCashAcc['account_code'])?> - <?=e(format_ui_text($userCashAcc['account_name']))?> (آپ کا کیش)
                                    </option>
                                </optgroup>
                            <?php endif; ?>

                            <?php if (!empty($bankAccounts)): ?>
                                <optgroup label="🏛️ مدرسہ / ٹرسٹ بینک اکاؤنٹس (Bank Accounts)">
                                    <?php foreach ($bankAccounts as $bAcc): ?>
                                        <option value="<?=$bAcc['id']?>" data-type="bank" <?=($editVoucher && (int)$editAssetAcc === (int)$bAcc['id']) ? 'selected' : ''?>>
                                            <?=e($bAcc['account_code'])?> - <?=e(format_ui_text($bAcc['account_name']))?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>

                            <?php if (can_approve() && !empty($otherCashAccounts)): ?>
                                <optgroup label="👥 دیگر عملہ کیش اکاؤنٹس (Other Staff Cash)">
                                    <?php foreach ($otherCashAccounts as $oAcc): ?>
                                        <option value="<?=$oAcc['id']?>" data-type="other_cash" <?=($editVoucher && (int)$editAssetAcc === (int)$oAcc['id']) ? 'selected' : ''?>>
                                            <?=e($oAcc['account_code'])?> - <?=e(format_ui_text($oAcc['account_name']))?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                        </select>
                        <?php if (!can_approve()): ?>
                            <small style="color:var(--muted);font-size:11px;margin-top:4px;display:block;">
                                <span class="ur">نقد وصولی آپ کی تحویل میں رہے گی، یا ڈونر کی طرف سے بینک منتقلی کی صورت میں متعلقہ بینک اکاؤنٹ منتخب کریں۔</span>
                            </small>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label>Income Head <span class="ur">(آمدنی کی مد)</span></label>
                        <select name="income_account_id" required>
                            <option value="">-- Select Income Category --</option>
                            <?php foreach ($incomeAccounts as $acc): ?>
                                <?php $isAccSelected = ($editVoucher && (int)$editIncomeAcc === (int)$acc['id']) || (!$editVoucher && $prefillIncomeAccId === (int)$acc['id']); ?>
                                <option value="<?=$acc['id']?>" <?=$isAccSelected ? 'selected' : ''?>>
                                    <?=e($acc['account_code'])?> - <?=e(format_ui_text($acc['account_name']))?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Select Donor / Contributor <span class="ur">(معاون منتخب کریں)</span></label>
                        <select name="donor_id" id="donorSelect" onchange="onDonorSelectChange(this)">
                            <option value="0" data-phone="">-- General / New Contributor <span class="ur">(عام معاون)</span> --</option>
                            <?php foreach ($donors as $d): ?>
                                <?php $isDonorSel = ($editVoucher && (int)$editVoucher['donor_id'] === (int)$d['id']) || (!$editVoucher && $prefillDonorId === (int)$d['id']); ?>
                                <option value="<?=$d['id']?>" data-phone="<?=e($d['phone'] ?? '')?>" data-fund-id="<?=e($d['fund_id'] ?? '')?>" <?=$isDonorSel ? 'selected' : ''?>>
                                    <?=e($d['donor_name'])?><?= !empty($d['fund_name']) ? ' ['.e($d['fund_name']).']' : '' ?><?= $d['phone'] ? ' ('.e($d['phone']).')' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Donor WhatsApp / Mobile <span class="ur">(فون نمبر / واٹس ایپ)</span></label>
                        <input name="donor_phone" id="donorPhoneInput" placeholder="0300-1234567" value="<?=e($editVoucher['donor_phone'] ?? '')?>">
                    </div>

                    <!-- Multi-Currency Selector (Feature #4) -->
                    <div class="form-group" style="grid-column: 1 / -1; background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:14px 16px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:10px;">
                            <label style="font-weight:700; color:#1e293b; margin:0; font-size:13px; display:inline-flex; align-items:center; gap:6px;">
                                <span>💱</span> Multi-Currency &amp; Overseas Donation <span class="ur">(بیرونِ ملک کرنسی و عطیات)</span>
                            </label>
                            <span style="font-size:11.5px; color:#64748b;">اگر عطیہ فارن کرنسی (USD, SAR, AED وغیرہ) میں ہے تو یہاں منتخب کریں</span>
                        </div>
                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px; align-items:flex-end;">
                            <div>
                                <label style="font-size:12px; font-weight:600;">Donation Currency <span class="ur">(عطیہ کرنسی)</span></label>
                                <select name="foreign_currency" id="currencySelect" onchange="onCurrencyChange(this.value)">
                                    <option value="PKR" <?=($editVoucher['foreign_currency'] ?? 'PKR') === 'PKR' ? 'selected' : ''?>>🇵🇰 PKR - Pakistani Rupee (مقامی)</option>
                                    <option value="USD" <?=($editVoucher['foreign_currency'] ?? '') === 'USD' ? 'selected' : ''?>>🇺🇸 USD - US Dollar ($)</option>
                                    <option value="SAR" <?=($editVoucher['foreign_currency'] ?? '') === 'SAR' ? 'selected' : ''?>>🇸🇦 SAR - Saudi Riyal (﷼)</option>
                                    <option value="AED" <?=($editVoucher['foreign_currency'] ?? '') === 'AED' ? 'selected' : ''?>>🇦🇪 AED - UAE Dirham (د.إ)</option>
                                    <option value="GBP" <?=($editVoucher['foreign_currency'] ?? '') === 'GBP' ? 'selected' : ''?>>🇬🇧 GBP - British Pound (£)</option>
                                    <option value="EUR" <?=($editVoucher['foreign_currency'] ?? '') === 'EUR' ? 'selected' : ''?>>🇪🇺 EUR - Euro (€)</option>
                                    <option value="CAD" <?=($editVoucher['foreign_currency'] ?? '') === 'CAD' ? 'selected' : ''?>>🇨🇦 CAD - Canadian Dollar ($)</option>
                                    <option value="AUD" <?=($editVoucher['foreign_currency'] ?? '') === 'AUD' ? 'selected' : ''?>>🇦🇺 AUD - Australian Dollar ($)</option>
                                </select>
                            </div>
                            <div id="foreignAmountWrap" style="<?=!empty($editVoucher['foreign_currency']) && $editVoucher['foreign_currency'] !== 'PKR' ? 'display:block;' : 'display:none;'?>">
                                <label style="font-size:12px; font-weight:600;">Foreign Amount <span class="ur">(غیر ملکی رقم)</span></label>
                                <input type="number" step="0.01" min="0" name="foreign_amount" id="foreignAmountInput" placeholder="e.g. 500" value="<?=e($editVoucher['foreign_amount'] ?? '')?>" oninput="calcForeignToPkr()">
                            </div>
                            <div id="exchangeRateWrap" style="<?=!empty($editVoucher['foreign_currency']) && $editVoucher['foreign_currency'] !== 'PKR' ? 'display:block;' : 'display:none;'?>">
                                <label style="font-size:12px; font-weight:600;">Exchange Rate <span class="ur">(ایکسچینج ریٹ بمقابلہ PKR)</span></label>
                                <input type="number" step="0.0001" min="0" name="exchange_rate" id="exchangeRateInput" placeholder="e.g. 280.00" value="<?=e($editVoucher['exchange_rate'] ?? '')?>" oninput="calcForeignToPkr()">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Total Amount <span class="ur">(کل رقم PKR)</span></label>
                        <input type="number" step="0.01" min="1" name="amount" required placeholder="0.00" value="<?=e($editVoucher['total_amount'] ?? $prefillAmount)?>" style="font-weight:700;font-size:15px;color:#15803d;">
                    </div>

                    <?php if (can_approve()): ?>
                    <div class="form-group" id="newDonorGroup">
                        <label>New Donor Name (If not in list) <span class="ur">(نیا معاون نام)</span></label>
                        <input name="new_donor_name" placeholder="Leave empty if selected above or general">
                    </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label>Payment Method <span class="ur">(طریقہ وصولی)</span></label>
                        <select name="payment_method" id="inc_payment_method" onchange="onIncomePaymentMethodChange()">
                            <?php $currMethod = $editVoucher['payment_method'] ?? 'cash'; ?>
                            <option value="cash" <?=$currMethod==='cash'?'selected':''?>>Cash <span class="ur">(نقد)</span></option>
                            <option value="bank" <?=$currMethod==='bank'?'selected':''?>>Bank Transfer <span class="ur">(بینک)</span></option>
                            <option value="online" <?=$currMethod==='online'?'selected':''?>>Online / EasyPaisa / JazzCash</option>
                            <option value="cheque" <?=$currMethod==='cheque'?'selected':''?>>Cheque <span class="ur">(چیک)</span></option>
                            <option value="other" <?=$currMethod==='other'?'selected':''?>>Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Receipt # / Reference <span class="ur">(رسید یا حوالہ نمبر)</span></label>
                        <input name="reference_no" placeholder="Optional manual book receipt #" value="<?=e($editVoucher['reference_no'] ?? '')?>">
                    </div>

                    <!-- Bank Deposit Slip / Cheque / Online Transfer Photo Attachment -->
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>📷 Attach Deposit Slip / Transfer Screenshot / Cheque Photo <span class="ur">(سلپ یا چیک کی تصویر)</span></label>
                        <input type="file" name="receipt_file" accept="image/jpeg,image/png,image/webp,application/pdf" capture="environment">
                        <small class="muted">Upload bank deposit slip, cheque photo, or online transfer receipt (JPG, PNG, PDF).</small>
                    </div>

                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Description / Notes <span class="ur">(تفصیل و دعا)</span></label>
                        <input name="description" placeholder="e.g. Monthly contribution for Jamia expenses, Dua for Marhoomeen..." value="<?=e($editVoucher['description'] ?? $prefillDesc)?>">
                    </div>
                </div>

                <div class="btn-row" style="margin-top:10px;">
                    <button class="btn blue" type="submit">
                        <?= $editVoucher ? '💾 Update Voucher' : (can_approve() ? '💾 Save & Post Receipt' : '💾 Submit for Approval <span class="ur">(منظوری کے لیے بھیجیں)</span>') ?>
                    </button>
                    <?php if ($editVoucher): ?>
                        <a href="income.php" class="btn secondary">Cancel Editing</a>
                    <?php else: ?>
                        <button class="btn secondary" type="reset">Reset Form</button>
                    <?php endif; ?>
                </div>
            </form>
        </article>

        <!-- Income Vouchers History Table -->
        <article class="panel" id="vouchersLedger" style="margin-top:24px;">
            <div class="panel-heading">
                <div>
                    <h3>Income Vouchers Ledger <span class="ur">(آمدنی واؤچرز فہرست)</span></h3>
                    <p>Recent recorded income transactions with instant thermal slips and WhatsApp sharing</p>
                </div>
            </div>

            <!-- Filter Toolbar -->
            <form method="get" action="income.php#vouchersLedger" class="filter-bar" id="incomeFilterForm">
                <input type="text" id="incomeSearchInput" name="search" placeholder="Search by Voucher #, Donor, or Notes..." value="<?=e($search)?>" style="flex:2;min-width:180px;">
                <select id="incomeFundFilter" name="fund_id" style="flex:1;min-width:140px;">
                    <option value="0">All Funds <span class="ur">(تمام فنڈز)</span></option>
                    <?php foreach ($funds as $f): ?>
                        <option value="<?=$f['id']?>" <?=$filterFund===(int)$f['id']?'selected':''?>><?=e($f['name'])?></option>
                    <?php endforeach; ?>
                </select>
                <select id="incomeStatusFilter" name="status" style="flex:1;min-width:140px;">
                    <option value="all" <?=$statusFilter==='all'?'selected':''?>>All Statuses</option>
                    <option value="posted" <?=$statusFilter==='posted'?'selected':''?>>Posted / Approved</option>
                    <option value="draft" <?=$statusFilter==='draft'?'selected':''?>>Pending Approval</option>
                </select>
                <?php if ($isSuperAdmin && !empty($allRegions)): ?>
                    <select name="region_id" style="flex:1;min-width:140px;">
                        <option value="0">🌐 All Pakistan (پورے پاکستان)</option>
                        <?php foreach ($allRegions as $reg): ?>
                            <option value="<?=$reg['id']?>" <?=$filterRegion===(int)$reg['id']?'selected':''?>>🏛️ <?=e($reg['name'])?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <button type="submit" class="btn secondary sm" style="height:38px;">🔍 Filter</button>
                <?php if ($filterFund > 0 || !empty($search) || $statusFilter !== 'all'): ?>
                    <a href="income.php#vouchersLedger" class="btn sm secondary" style="height:38px;display:flex;align-items:center;">Reset</a>
                <?php endif; ?>
            </form>

            <?php if (!$incomeList): ?>
                <div class="empty-state">
                    <div class="empty-icon">＋</div>
                    <b>No income records found</b>
                    <p>Enter your first income voucher using the form above.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Voucher No</th>
                                <th>Date</th>
                                <th>Province <span class="ur">(صوبہ)</span></th>
                                <th>Fund</th>
                                <th>Donor / Payee</th>
                                <th>Status</th>
                                <th>Slip Photo</th>
                                <th>Method</th>
                                <th>Description</th>
                                <th class="right">Amount</th>
                                <th class="right" style="min-width:210px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($incomeList as $inc): ?>
                                <tr class="voucher-row" data-status="<?=e($inc['status'])?>" data-fund-id="<?=e($inc['fund_id'])?>">
                                    <td>
                                        <b><?=e($inc['voucher_no'])?></b>
                                        <?php if ($inc['submitter_name']): ?>
                                            <div style="font-size:11px;color:#94a3b8;">By: <?=e($inc['submitter_name'])?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?=e($inc['voucher_date'])?></td>
                                    <td>
                                        <?php if (!empty($inc['region_name'])): ?>
                                            <span class="badge" style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;font-size:11px;font-weight:600;">
                                                🏛️ <?=e($inc['region_name'])?>
                                            </span>
                                        <?php else: ?>
                                            <span class="muted" style="font-size:11px;">— HQ</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge badge-blue"><?=e($inc['fund_name'] ?? 'General')?></span></td>
                                    <td>
                                        <b><?=e($inc['donor_name'] ?: ($inc['payee_name'] ?: 'General Donor'))?></b>
                                        <?php if (!empty($inc['donor_phone'])): ?>
                                            <div style="font-size:11px;color:#64748b;">📱 <?=e($inc['donor_phone'])?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($inc['status'] === 'posted'): ?>
                                            <span class="badge badge-green">Posted <span class="ur">(منظور شدہ)</span></span>
                                        <?php elseif ($inc['status'] === 'draft'): ?>
                                            <span class="badge badge-amber" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;">Pending <span class="ur">(زیرِ التواء)</span></span>
                                        <?php else: ?>
                                            <span class="badge badge-red">Rejected <span class="ur">(مسترد)</span></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($inc['receipt_file'])): 
                                            $filePath = 'uploads/receipts/' . $inc['receipt_file'];
                                            $isPdf = (strtolower(pathinfo($inc['receipt_file'], PATHINFO_EXTENSION)) === 'pdf');
                                        ?>
                                            <?php if ($isPdf): ?>
                                                <a href="<?=e($filePath)?>" target="_blank" class="thumb-btn">
                                                    📄 PDF
                                                </a>
                                            <?php else: ?>
                                                <button type="button" class="thumb-btn" onclick="openAttachedFileModal('<?=e($filePath)?>', '<?=e(addslashes($inc['voucher_no']))?>')">
                                                    📷 View Slip
                                                </button>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="font-size:11px;color:#94a3b8;">— None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge badge-gray"><?=e(ucfirst($inc['payment_method'] ?? 'cash'))?></span></td>
                                    <td>
                                        <?php if ($inc['description']): ?>
                                            <div style="font-size:11.5px;color:#64748b;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?=e($inc['description'])?>">
                                                <?=e($inc['description'])?>
                                            </div>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td class="right font-bold" style="color:#15803d;"><?=money($inc['total_amount'])?></td>
                                    <td class="right">
                                        <div class="btn-row" style="justify-content:flex-end;gap:5px;">
                                            <?php if ($inc['status'] === 'draft' && can_approve()): ?>
                                                <form method="post" style="display:inline-block;" onsubmit="return confirm('Approve income receipt <?=e($inc['voucher_no'])?>? It will be added to Main Cash/Bank.');">
                                                    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                                                    <input type="hidden" name="action" value="approve_income">
                                                    <input type="hidden" name="voucher_id" value="<?=$inc['id']?>">
                                                    <button type="submit" class="btn success sm" style="padding:3px 7px;font-size:11px;" title="Approve & Post to Main Cash/Bank">
                                                        ✓ Approve
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <button type="button" class="btn blue sm" style="padding:3px 8px;font-size:11.5px;display:inline-flex;align-items:center;gap:4px;" onclick='openReceiptModal(<?= htmlspecialchars(json_encode([
                                                "id" => $inc["id"],
                                                "voucher_no" => $inc["voucher_no"],
                                                "date" => date("d-m-Y", strtotime($inc["voucher_date"])),
                                                "fund" => $inc["fund_name"] ?? "General",
                                                "donor" => $inc["donor_name"] ?: ($inc["payee_name"] ?: "General Donor"),
                                                "phone" => $inc["donor_phone"] ?? "",
                                                "amount" => (float)$inc["total_amount"],
                                                "amount_formatted" => money($inc["total_amount"]),
                                                "amount_words" => function_exists("number_to_words") ? number_to_words((float)$inc["total_amount"]) : money($inc["total_amount"]),
                                                "method" => ucfirst($inc["payment_method"] ?? "cash"),
                                                "reference_no" => $inc["reference_no"] ?? "",
                                                "description" => $inc["description"] ?? "",
                                                "status" => $inc["status"],
                                                "created_by" => $inc["submitter_name"] ?? current_user()["name"],
                                                "time" => date("d-m-Y h:i A")
                                            ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8") ?>)' title="تھرمل رسید و واٹس ایپ شیئر">
                                                🧾 Receipt <span class="ur">(رسید)</span>
                                            </button>

                                            <a href="print_voucher.php?id=<?=$inc['id']?>&format=thermal&autoprint=1" target="_blank" class="btn secondary sm" style="padding:3px 6px;font-size:11px;font-weight:600;" title="Print 80mm POS Thermal Slip (فوری تھرمل پرنٹ)">
                                                🧾 80mm
                                            </a>
                                            <a href="print_voucher.php?id=<?=$inc['id']?>&format=a4" target="_blank" class="btn secondary sm" style="padding:3px 6px;font-size:11px;" title="Print A4 Full Voucher">
                                                📄 A4
                                            </a>
                                            <?php if (can_approve()): ?>
                                                <a href="income.php?edit_id=<?=$inc['id']?>#incomeFormCard" class="btn secondary sm" style="padding:3px 7px;font-size:11.5px;" title="Edit Voucher">
                                                    ✏️
                                                </a>
                                                <a href="income.php?delete_id=<?=$inc['id']?>&token=<?=csrf_token()?>" class="btn danger sm" style="padding:3px 7px;font-size:11.5px;" onclick="return confirm('Are you sure you want to delete voucher <?=e($inc['voucher_no'])?>?');" title="Delete Voucher">
                                                    🗑️
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </article>

        <?=render_app_footer($madrasaName)?>
    </main>
</div>

<!-- ============================================================ -->
<!-- Black Thermal Print Receipt Modal (Popup On Save & On Click) -->
<!-- ============================================================ -->
<div id="receiptModalOverlay" class="receipt-modal-overlay" style="display:none;" onclick="if(event.target===this) closeReceiptModal();">
    <div class="receipt-modal-container">
        <!-- Header -->
        <div class="receipt-modal-header">
            <div style="display:flex;align-items:center;gap:8px;">
                <span style="font-size:18px;">🧾</span>
                <b style="font-size:15px;">رسید وصولی چندہ (Thermal Receipt)</b>
            </div>
            <button type="button" class="receipt-modal-close" onclick="closeReceiptModal()" aria-label="Close">&times;</button>
        </div>

        <!-- Body with Black Thermal Receipt Slip -->
        <div class="receipt-modal-body">
            <div id="thermalPrintSlip" class="thermal-paper">
                <div class="thermal-header">
                    <?php if ($orgLogo): ?>
                        <div><img src="<?=e($orgLogo)?>" alt="Logo" style="max-height:48px;max-width:110px;object-fit:contain;margin-bottom:4px;"></div>
                    <?php endif; ?>
                    <div class="thermal-title"><?=e($madrasaName)?></div>
                    <?php if (!empty($madrasaAddress)): ?>
                        <div class="thermal-sub"><?=e($madrasaAddress)?></div>
                    <?php endif; ?>
                    <?php if (!empty($madrasaPhone)): ?>
                        <div class="thermal-sub">رابطہ / فون: <?=e($madrasaPhone)?></div>
                    <?php endif; ?>
                    <div style="margin-top:5px;font-size:12px;font-weight:700;">*** رسید وصولی چندہ (RECEIPT) ***</div>
                    <div id="slipStatusBadge" style="margin-top:3px;"></div>
                </div>

                <div class="thermal-hr"></div>

                <div class="thermal-row">
                    <span>رسید نمبر:</span>
                    <span class="thermal-bold" id="slipVoucherNo"></span>
                </div>
                <div class="thermal-row">
                    <span>تاریخ و وقت:</span>
                    <span id="slipDate"></span>
                </div>
                <div class="thermal-row">
                    <span>مد / فنڈ:</span>
                    <span class="thermal-bold" id="slipFund"></span>
                </div>
                <div class="thermal-row">
                    <span>معاون / ڈونر:</span>
                    <span class="thermal-bold" id="slipDonor"></span>
                </div>
                <div class="thermal-row" id="slipPhoneRow" style="display:none;">
                    <span>فون / واٹس ایپ:</span>
                    <span id="slipPhone"></span>
                </div>
                <div class="thermal-row">
                    <span>طریقہ وصولی:</span>
                    <span id="slipMethod"></span>
                </div>
                <div class="thermal-row" id="slipRefRow" style="display:none;">
                    <span>ریفرنس #:</span>
                    <span id="slipRef"></span>
                </div>

                <div class="thermal-hr"></div>

                <div class="thermal-box">
                    <div style="font-size:11px;font-weight:700;">کل وصول شدہ رقم</div>
                    <div class="thermal-amount-text" id="slipAmount"></div>
                </div>
                <div style="text-align:center;font-size:11.5px;font-weight:600;margin-bottom:8px;" id="slipAmountWords"></div>

                <div id="slipDescRow" style="display:none;font-size:11px;margin:6px 0;background:#f8fafc;padding:5px;border:1px dashed #cbd5e1;line-height:1.4;">
                    <b>تفصیل و دعا:</b> <span id="slipDesc"></span>
                </div>

                <div class="thermal-hr"></div>

                <div style="text-align:center;font-size:11px;margin-top:8px;">
                    <div style="font-size:14px;font-weight:800;margin-bottom:2px;">جزاكم الله خيراً و احسن الجزاء</div>
                    <div style="font-size:11px;color:#333;">اللہ تعالیٰ آپ کا صدقہ و عطیہ قبول فرمائے۔</div>
                    <div style="margin-top:8px;font-size:10.5px;color:#555;">تیار کنندہ: <span id="slipCollector"></span></div>
                    <div style="font-size:9.5px;color:#777;margin-top:3px;">کمپیوٹرائزڈ تصدیقی رسید · فلاحی ٹرسٹ اکاؤنٹس</div>
                </div>
            </div>
        </div>

        <!-- Status Toast Notification -->
        <div id="slipStatusToast" style="display:none;text-align:center;padding:7px 12px;font-size:12px;font-weight:600;border-radius:6px;margin:0 14px 10px;background:#e0f2fe;color:#0369a1;transition:all 0.3s ease;"></div>

        <!-- Action Buttons (Icon-only in 1 Line) -->
        <div class="receipt-modal-actions">
            <!-- 1. WhatsApp Share (Sends Receipt as Image) -->
            <button type="button" class="slip-btn btn-wa" id="btnShareWhatsApp" onclick="triggerWhatsAppShare()" title="واٹس ایپ پر رسید کی تصویر بھیجیں (Send Image to WhatsApp)" aria-label="WhatsApp">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/>
                </svg>
            </button>

            <!-- 2. Thermal Print -->
            <button type="button" class="slip-btn btn-print" onclick="printThermalReceipt()" title="تھرمل پرنٹ کریں (Thermal Print)" aria-label="Print">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="6 9 6 2 18 2 18 9"></polyline>
                    <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
                    <rect x="6" y="14" width="12" height="8"></rect>
                </svg>
            </button>

            <!-- 3. Save / Download Image to Device -->
            <button type="button" class="slip-btn btn-share" id="btnSaveImage" onclick="downloadReceiptImage()" title="رسید کی تصویر موبائل میں محفوظ کریں (Save Image to Device)" aria-label="Save Image">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="7 10 12 15 17 10"></polyline>
                    <line x1="12" y1="15" x2="12" y2="3"></line>
                </svg>
            </button>

            <!-- 4. Open Full A4 Page -->
            <a id="slipDirectLink" href="#" target="_blank" class="slip-btn btn-link" title="مکمل A4 رسید دیکھیں (Open Full Voucher)" aria-label="Full Receipt">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                    <polyline points="15 3 21 3 21 9"></polyline>
                    <line x1="10" y1="14" x2="21" y2="3"></line>
                </svg>
            </a>

            <!-- 5. Close Modal -->
            <button type="button" class="slip-btn btn-close" onclick="closeReceiptModal()" title="بند کریں (Close)" aria-label="Close">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        </div>
    </div>
</div>

<!-- Attached File (Deposit Slip / Cheque) Lightbox Modal -->
<div id="attachedFileModal" class="img-modal" onclick="if(event.target===this) closeAttachedFileModal();">
    <div class="img-modal-content">
        <div class="img-modal-header">
            <b id="attachedModalTitle" style="font-size:14px;color:#0f172a;">Attached Slip / Document</b>
            <button type="button" class="close-btn" onclick="closeAttachedFileModal()">&times;</button>
        </div>
        <div class="img-modal-body">
            <img id="attachedModalImg" src="" alt="Voucher Attachment">
        </div>
        <div style="padding:10px 16px;background:#f8fafc;border-top:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
            <a id="attachedModalDownload" href="" target="_blank" class="btn secondary sm" download>⬇ Download</a>
            <button type="button" class="btn primary sm" onclick="closeAttachedFileModal()">Close</button>
        </div>
    </div>
</div>

<script>
var defaultIncUserCashId = "<?=!empty($userCashAcc) ? (int)$userCashAcc['id'] : 0?>";
var defaultIncBankId = "<?=!empty($bankAccounts) ? (int)$bankAccounts[0]['id'] : 0?>";

function onIncomePaymentMethodChange() {
    var pm = document.getElementById('inc_payment_method');
    var accSelect = document.getElementById('inc_asset_account_id');
    if (!pm || !accSelect) return;

    if (pm.value === 'cash') {
        var cashOpt = accSelect.querySelector('option[data-type="cash"]');
        if (cashOpt) {
            accSelect.value = cashOpt.value;
        } else if (defaultIncUserCashId && defaultIncUserCashId !== '0') {
            accSelect.value = defaultIncUserCashId;
        }
    } else {
        // Bank, online, cheque
        var bankOpt = accSelect.querySelector('option[data-type="bank"]');
        if (bankOpt) {
            accSelect.value = bankOpt.value;
        } else if (defaultIncBankId && defaultIncBankId !== '0') {
            accSelect.value = defaultIncBankId;
        }
    }
}

function onIncomeAccountChange() {
    var accSelect = document.getElementById('inc_asset_account_id');
    var pm = document.getElementById('inc_payment_method');
    if (!accSelect || !pm) return;

    var selectedOpt = accSelect.options[accSelect.selectedIndex];
    var accType = selectedOpt ? selectedOpt.getAttribute('data-type') : '';

    if (accType === 'bank') {
        if (pm.value === 'cash') {
            pm.value = 'bank';
        }
    } else if (accType === 'cash') {
        pm.value = 'cash';
    }
}

function openAttachedFileModal(src, voucherNo) {
    document.getElementById('attachedModalTitle').textContent = 'Attached Slip for Voucher ' + voucherNo;
    document.getElementById('attachedModalImg').src = src;
    document.getElementById('attachedModalDownload').href = src;
    document.getElementById('attachedFileModal').classList.add('open');
}
function closeAttachedFileModal() {
    var m = document.getElementById('attachedFileModal');
    if (m) m.classList.remove('open');
    var img = document.getElementById('attachedModalImg');
    if (img) img.src = '';
}
var currentReceiptVoucher = null;

function openReceiptModal(data) {
    if (!data) return;
    currentReceiptVoucher = data;

    document.getElementById('slipVoucherNo').textContent = data.voucher_no || '';
    document.getElementById('slipDate').textContent = (data.date || '') + ' ' + (data.time ? (data.time.split(' ')[1] || '') + ' ' + (data.time.split(' ')[2] || '') : '');
    document.getElementById('slipFund').textContent = data.fund || 'General';
    document.getElementById('slipDonor').textContent = data.donor || 'عام معاون';

    var phoneRow = document.getElementById('slipPhoneRow');
    if (data.phone) {
        document.getElementById('slipPhone').textContent = data.phone;
        phoneRow.style.display = 'flex';
    } else {
        phoneRow.style.display = 'none';
    }

    document.getElementById('slipMethod').textContent = data.method || 'Cash';

    var refRow = document.getElementById('slipRefRow');
    if (data.reference_no) {
        document.getElementById('slipRef').textContent = data.reference_no;
        refRow.style.display = 'flex';
    } else {
        refRow.style.display = 'none';
    }

    document.getElementById('slipAmount').textContent = data.amount_formatted || ('Rs. ' + Number(data.amount).toFixed(2));
    document.getElementById('slipAmountWords').textContent = data.amount_words ? (data.amount_words + ' فقط') : '';

    var descRow = document.getElementById('slipDescRow');
    if (data.description) {
        document.getElementById('slipDesc').textContent = data.description;
        descRow.style.display = 'block';
    } else {
        descRow.style.display = 'none';
    }

    document.getElementById('slipCollector').textContent = data.created_by || 'کیشیر';

    var statusBadge = document.getElementById('slipStatusBadge');
    if (data.status === 'posted') {
        statusBadge.innerHTML = '<span style="color:#15803d;border:1px solid #16a34a;padding:2px 8px;border-radius:4px;display:inline-block;font-size:10.5px;">✓ تصدیق شدہ / منظور (Posted)</span>';
    } else {
        statusBadge.innerHTML = '<span style="color:#b45309;border:1px solid #f59e0b;padding:2px 8px;border-radius:4px;display:inline-block;font-size:10.5px;">⏳ زیرِ التواء پڑتال (Pending Approval)</span>';
    }

    var directLink = document.getElementById('slipDirectLink');
    if (directLink) {
        directLink.href = 'print_voucher.php?id=' + encodeURIComponent(data.id);
    }

    var overlay = document.getElementById('receiptModalOverlay');
    if (overlay) {
        overlay.style.display = 'flex';
    }
}

function closeReceiptModal() {
    var overlay = document.getElementById('receiptModalOverlay');
    if (overlay) {
        overlay.style.display = 'none';
    }
    // Clean URL query parameters so refresh doesn't reopen modal
    if (window.history && window.history.replaceState) {
        var cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
        window.history.replaceState({path: cleanUrl}, '', cleanUrl);
    }
}

function printThermalReceipt() {
    document.body.classList.add('printing-slip');
    window.print();
    setTimeout(function() {
        document.body.classList.remove('printing-slip');
    }, 1200);
}

function showSlipToast(msg, isSuccess, duration) {
    var toast = document.getElementById('slipStatusToast');
    if (!toast) return;
    toast.textContent = msg;
    toast.style.display = 'block';
    toast.style.background = isSuccess ? '#dcfce7' : '#e0f2fe';
    toast.style.color = isSuccess ? '#15803d' : '#0369a1';
    toast.style.border = isSuccess ? '1px solid #bbf7d0' : '1px solid #bae6fd';
    if (duration !== 0) {
        setTimeout(function() {
            toast.style.display = 'none';
        }, duration || 2500);
    }
}

function cleanPhone(raw) {
    var p = (raw || '').trim().replace(/[^0-9]/g, '');
    if (p.length === 11 && p.startsWith('0')) {
        return '92' + p.substring(1);
    } else if (p.length === 10 && p.startsWith('3')) {
        return '92' + p;
    }
    return p;
}

function captureReceiptImageBlob(callback) {
    var slip = document.getElementById('thermalPrintSlip');
    if (!slip) {
        if (callback) callback(null);
        return;
    }

    function doRender() {
        if (typeof html2canvas === 'undefined') {
            console.error('html2canvas library is not loaded');
            if (callback) callback(null);
            return;
        }

        html2canvas(slip, {
            scale: 2.5,
            backgroundColor: '#ffffff',
            useCORS: true,
            logging: false
        }).then(function(canvas) {
            canvas.toBlob(function(blob) {
                if (callback) callback(blob, canvas);
            }, 'image/png', 0.95);
        }).catch(function(err) {
            console.error('Error rendering receipt image:', err);
            if (callback) callback(null);
        });
    }

    if (typeof html2canvas === 'undefined') {
        var s = document.createElement('script');
        s.src = 'assets/html2canvas.min.js';
        s.onload = doRender;
        s.onerror = function() {
            var cdn = document.createElement('script');
            cdn.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
            cdn.onload = doRender;
            cdn.onerror = function() { if (callback) callback(null); };
            document.head.appendChild(cdn);
        };
        document.head.appendChild(s);
    } else {
        doRender();
    }
}

function triggerWhatsAppShare() {
    if (!currentReceiptVoucher) return;
    var v = currentReceiptVoucher;
    var madrasa = <?= json_encode($madrasaName, JSON_UNESCAPED_UNICODE) ?>;
    var btn = document.getElementById('btnShareWhatsApp');

    if (btn) {
        btn.style.opacity = '0.5';
        btn.style.pointerEvents = 'none';
    }
    showSlipToast('رسید کی تصویر تیار ہو رہی ہے...', false, 0);

    captureReceiptImageBlob(async function(blob) {
        if (btn) {
            btn.style.opacity = '1';
            btn.style.pointerEvents = 'auto';
        }

        if (!blob) {
            showSlipToast('تصویر بنانے میں مسئلہ ہوا، متن ارسال کیا جا رہا ہے...', false, 2000);
            fallbackTextShare();
            return;
        }

        var filename = 'receipt-' + (v.voucher_no || 'slip').replace(/[^a-zA-Z0-9_-]/g, '_') + '.png';
        var file = new File([blob], filename, { type: 'image/png' });

        // Check if Web Share API with files is supported (supported on Android Chrome, iOS Safari)
        if (navigator.canShare && navigator.canShare({ files: [file] })) {
            showSlipToast('واٹس ایپ منتخب کریں...', true, 1800);
            try {
                await navigator.share({
                    files: [file],
                    title: 'رسید وصولی چندہ - ' + (v.voucher_no || ''),
                    text: 'رسید وصولی چندہ - ' + (v.voucher_no || '') + ' (' + madrasa + ')'
                });
                showSlipToast('✓ رسید کامیابی سے بھیج دی گئی', true, 2500);
                return;
            } catch (shareErr) {
                if (shareErr.name === 'AbortError') {
                    var toast = document.getElementById('slipStatusToast');
                    if (toast) toast.style.display = 'none';
                    return;
                }
                console.warn('Share error:', shareErr);
            }
        }

        // Fallback for Desktop PC or browsers without Web Share Level 2:
        // 1. Auto-download receipt image to device
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        // 2. Try copying to clipboard
        if (navigator.clipboard && window.ClipboardItem) {
            try {
                await navigator.clipboard.write([
                    new ClipboardItem({ 'image/png': blob })
                ]);
            } catch (clipErr) {}
        }

        // 3. Open WhatsApp Web or App without any number prompt
        var isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
        var waMsg = 'رسید وصولی چندہ: ' + (v.voucher_no || '') + ' (' + madrasa + ')';
        var phone = cleanPhone(v.phone || '');
        var waUrl = phone
            ? ('https://api.whatsapp.com/send?phone=' + encodeURIComponent(phone) + '&text=' + encodeURIComponent(waMsg))
            : ('https://api.whatsapp.com/send?text=' + encodeURIComponent(waMsg));

        showSlipToast('رسید کی تصویر محفوظ ہو گئی۔ واٹس ایپ میں بھیجیں۔', true, 3500);
        if (isMobile) {
            window.location.href = waUrl;
        } else {
            window.open(waUrl, '_blank');
        }
    });
}

function downloadReceiptImage() {
    if (!currentReceiptVoucher) return;
    var v = currentReceiptVoucher;
    var btn = document.getElementById('btnSaveImage');

    if (btn) {
        btn.style.opacity = '0.5';
        btn.style.pointerEvents = 'none';
    }
    showSlipToast('تصویر محفوظ کی جا رہی ہے...', false, 0);

    captureReceiptImageBlob(function(blob) {
        if (btn) {
            btn.style.opacity = '1';
            btn.style.pointerEvents = 'auto';
        }

        if (!blob) {
            showSlipToast('تصویر بنانے میں ناکامی ہوئی۔', false, 2500);
            return;
        }

        var filename = 'receipt-' + (v.voucher_no || 'slip').replace(/[^a-zA-Z0-9_-]/g, '_') + '.png';
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        showSlipToast('✓ رسید کی تصویر موبائل میں محفوظ ہو گئی ہے', true, 3000);
    });
}

function fallbackTextShare() {
    if (!currentReceiptVoucher) return;
    var v = currentReceiptVoucher;
    var madrasa = <?= json_encode($madrasaName, JSON_UNESCAPED_UNICODE) ?>;
    var phone = cleanPhone(v.phone || '');
    var baseUrl = window.location.origin + window.location.pathname.replace('income.php', 'print_voucher.php');
    var receiptUrl = baseUrl + '?id=' + encodeURIComponent(v.id) + '&format=thermal';

    var msg = 'السلام علیکم ورحمۃ اللہ وبرکاتہ\n\n'
            + '🕋 *' + madrasa + '*\n'
            + '📜 *رسید وصولی چندہ (Donation Receipt)*\n'
            + '--------------------------------\n'
            + '🔹 *رسید نمبر:* ' + v.voucher_no + '\n'
            + '🔹 *تاریخ:* ' + v.date + '\n'
            + '🔹 *معاون / ڈونر:* ' + (v.donor || 'عام معاون') + '\n'
            + '🔹 *فنڈ کی مد:* ' + v.fund + '\n'
            + '💰 *کل وصول شدہ رقم:* ' + v.amount_formatted + '\n'
            + '--------------------------------\n'
            + '✨ *جزاكم الله خيراً و احسن الجزاء*\n'
            + '📄 *آن لائن رسید:* \n' + receiptUrl;

    var waUrl = phone
        ? ('https://api.whatsapp.com/send?phone=' + encodeURIComponent(phone) + '&text=' + encodeURIComponent(msg))
        : ('https://api.whatsapp.com/send?text=' + encodeURIComponent(msg));

    var isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
    if (isMobile) {
        window.location.href = waUrl;
    } else {
        window.open(waUrl, '_blank');
    }
}

function onDonorSelectChange(sel) {
    var opt = sel.options[sel.selectedIndex];
    var phone = opt ? (opt.getAttribute('data-phone') || '') : '';
    var fundId = opt ? (opt.getAttribute('data-fund-id') || '') : '';
    var phoneInput = document.getElementById('donorPhoneInput');
    if (phoneInput && phone) {
        phoneInput.value = phone;
    }
    var fundSelect = document.getElementById('fundSelect');
    if (fundSelect && fundId && (!fundSelect.value || fundSelect.value === '')) {
        fundSelect.value = fundId;
    }
    toggleNewDonor(sel.value);
}

function toggleNewDonor(val) {
    const el = document.getElementById('newDonorGroup');
    if (el) {
        if (val !== '0') {
            el.style.opacity = '0.4';
        } else {
            el.style.opacity = '1';
        }
    }
}

function onCurrencyChange(curr) {
    var famt = document.getElementById('foreignAmountWrap');
    var rate = document.getElementById('exchangeRateWrap');
    if (curr === 'PKR') {
        if (famt) famt.style.display = 'none';
        if (rate) rate.style.display = 'none';
    } else {
        if (famt) famt.style.display = 'block';
        if (rate) rate.style.display = 'block';
    }
    calcForeignToPkr();
}

function calcForeignToPkr() {
    var sel = document.getElementById('currencySelect');
    if (!sel) return;
    var curr = sel.value;
    if (curr !== 'PKR') {
        var fVal = parseFloat(document.getElementById('foreignAmountInput')?.value) || 0;
        var rVal = parseFloat(document.getElementById('exchangeRateInput')?.value) || 0;
        if (fVal > 0 && rVal > 0) {
            var pkrVal = (fVal * rVal).toFixed(2);
            var amtInput = document.querySelector('input[name="amount"]');
            if (amtInput) amtInput.value = pkrVal;
        }
    }
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeReceiptModal();
    }
});

// Live real-time filter for Income Vouchers Ledger
(function() {
    var searchInput = document.getElementById('incomeSearchInput');
    var fundSelect = document.getElementById('incomeFundFilter');
    var statusSelect = document.getElementById('incomeStatusFilter');
    var filterForm = document.getElementById('incomeFilterForm');
    var rows = document.querySelectorAll('tr.voucher-row');

    function applyLiveFilter() {
        var query = (searchInput ? searchInput.value : '').trim().toLowerCase();
        var fundId = fundSelect ? fundSelect.value : '0';
        var status = statusSelect ? statusSelect.value : 'all';

        var visibleCount = 0;
        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            var rowStatus = row.getAttribute('data-status') || '';
            var rowFund = row.getAttribute('data-fund-id') || '0';
            var rowText = (row.textContent || row.innerText || '').toLowerCase();

            var matchStatus = (status === 'all' || rowStatus === status);
            var matchFund = (fundId === '0' || rowFund === fundId);
            var matchQuery = (!query || rowText.indexOf(query) !== -1);

            if (matchStatus && matchFund && matchQuery) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        }

        var noMatch = document.getElementById('noIncomeMatchRow');
        if (rows.length > 0) {
            if (visibleCount === 0) {
                if (!noMatch) {
                    var tbody = document.querySelector('#vouchersLedger table tbody');
                    if (tbody) {
                        noMatch = document.createElement('tr');
                        noMatch.id = 'noIncomeMatchRow';
                        noMatch.innerHTML = '<td colspan="10" style="text-align:center;padding:26px;color:#64748b;font-weight:500;">No income records match the selected filter. <a href="javascript:void(0)" onclick="resetIncomeFilters()" style="color:#2563eb;text-decoration:underline;margin-left:8px;">Reset Filter</a></td>';
                        tbody.appendChild(noMatch);
                    }
                } else {
                    noMatch.style.display = '';
                }
            } else if (noMatch) {
                noMatch.style.display = 'none';
            }
        }
    }

    window.resetIncomeFilters = function() {
        if (searchInput) searchInput.value = '';
        if (fundSelect) fundSelect.value = '0';
        if (statusSelect) statusSelect.value = 'all';
        applyLiveFilter();
        if (filterForm) {
            window.location.href = 'income.php#vouchersLedger';
        }
    };

    if (fundSelect) {
        fundSelect.addEventListener('change', function() {
            applyLiveFilter();
            if (filterForm) filterForm.submit();
        });
    }
    if (statusSelect) {
        statusSelect.addEventListener('change', function() {
            applyLiveFilter();
            if (filterForm) filterForm.submit();
        });
    }
    if (searchInput) {
        searchInput.addEventListener('input', applyLiveFilter);
    }
})();
</script>

<?php if ($autoSlipVoucher): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var autoData = <?= json_encode([
        'id' => $autoSlipVoucher['id'],
        'voucher_no' => $autoSlipVoucher['voucher_no'],
        'date' => date('d-m-Y', strtotime($autoSlipVoucher['voucher_date'])),
        'fund' => $autoSlipVoucher['fund_name'] ?? 'General',
        'donor' => $autoSlipVoucher['donor_name'] ?: ($autoSlipVoucher['payee_name'] ?: 'General Donor'),
        'phone' => $autoSlipVoucher['donor_phone'] ?? '',
        'amount' => (float)$autoSlipVoucher['total_amount'],
        'amount_formatted' => money($autoSlipVoucher['total_amount']),
        'amount_words' => function_exists('number_to_words') ? number_to_words((float)$autoSlipVoucher['total_amount']) : money($autoSlipVoucher['total_amount']),
        'method' => ucfirst($autoSlipVoucher['payment_method'] ?? 'cash'),
        'reference_no' => $autoSlipVoucher['reference_no'] ?? '',
        'description' => $autoSlipVoucher['description'] ?? '',
        'status' => $autoSlipVoucher['status'],
        'created_by' => $autoSlipVoucher['submitter_name'] ?? current_user()['name'],
        'time' => date('d-m-Y h:i A')
    ], JSON_UNESCAPED_UNICODE) ?>;
    openReceiptModal(autoData);
});
</script>
<?php endif; ?>

</body>
</html>
