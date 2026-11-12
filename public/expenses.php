<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();
require_permission('expense.view');

$activePage = 'expenses';
$error = '';
$success = '';

// Handle Direct Approve of an Expense Voucher (for Approvers / Super Admins)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve_expense') {
    verify_csrf();
    if (!can_approve()) {
        http_response_code(403);
        exit('You do not have permission to approve vouchers.');
    }
    $vId = (int)($_POST['voucher_id'] ?? 0);
    if ($vId > 0) {
        $res = approve_voucher_with_settlement($pdo, $vId, (int)current_user()['id']);
        if ($res['success']) {
            header('Location: expenses.php?success=approved');
            exit;
        } else {
            $error = $res['message'] ?? 'Approval failed.';
        }
    }
}

// Handle Delete Expense Voucher (Only Approvers / Super Admins)
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

            $stmtV = $pdo->prepare("SELECT voucher_no FROM vouchers WHERE id = ? AND voucher_type = 'expense'");
            $stmtV->execute([$delId]);
            $vNo = $stmtV->fetchColumn();

            if (!$vNo) {
                throw new RuntimeException("Expense voucher not found.");
            }

            // Find all journal entries linked to this voucher (original + settlement entries)
            $stmtJe = $pdo->prepare("SELECT id FROM journal_entries WHERE voucher_id = ?");
            $stmtJe->execute([$delId]);
            $jeIds = $stmtJe->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($jeIds)) {
                $inClause = implode(',', array_fill(0, count($jeIds), '?'));
                $pdo->prepare("DELETE FROM journal_lines WHERE journal_entry_id IN ($inClause)")->execute($jeIds);
                $pdo->prepare("DELETE FROM journal_entries WHERE id IN ($inClause)")->execute($jeIds);
            }

            // Delete attachments record and files
            try {
                $attStmt = $pdo->prepare("SELECT stored_name FROM attachments WHERE voucher_id = ?");
                $attStmt->execute([$delId]);
                foreach ($attStmt->fetchAll(PDO::FETCH_COLUMN) as $fName) {
                    $fPath = __DIR__ . '/uploads/receipts/' . $fName;
                    if (is_file($fPath)) @unlink($fPath);
                }
            } catch (Exception $e) {}
            $pdo->prepare("DELETE FROM attachments WHERE voucher_id = ?")->execute([$delId]);

            // Delete voucher
            $pdo->prepare("DELETE FROM vouchers WHERE id = ?")->execute([$delId]);

            // Audit log
            $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)');
            $log->execute([current_user()['id'], 'delete_expense_voucher', 'voucher', $delId, json_encode(['voucher_no' => $vNo]), $_SERVER['REMOTE_ADDR'] ?? null]);

            $pdo->commit();
            header('Location: expenses.php?success=deleted&v=' . urlencode((string)$vNo));
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Failed to delete voucher: ' . $e->getMessage();
        }
    }
}

// Handle Add or Edit Expense Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_expense') {
        $date = trim($_POST['voucher_date'] ?? date('Y-m-d'));
        $fundId = (int)($_POST['fund_id'] ?? 0);
        $taskId = (int)($_POST['task_id'] ?? 0);
        $assetAccountId = (int)($_POST['asset_account_id'] ?? 0);
        if (!can_approve()) {
            $userCashAcc = get_or_create_user_cash_account($pdo, (int)current_user()['id']);
            $assetAccountId = (int)($userCashAcc['id'] ?? 0);
        }
        $expenseAccountId = (int)($_POST['expense_account_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $paymentMethod = trim($_POST['payment_method'] ?? 'cash');
        $payeeName = trim($_POST['payee_name'] ?? '');
        $referenceNo = trim($_POST['reference_no'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $beneficiaryId = !empty($_POST['beneficiary_id']) ? (int)$_POST['beneficiary_id'] : null;

        if ($amount <= 0) {
            $error = 'Please enter a valid amount greater than 0.';
        } elseif ($fundId <= 0 || $assetAccountId <= 0 || $expenseAccountId <= 0) {
            $error = 'Please select Fund, Paid-From Account, and Expense Head.';
        } elseif (!is_fund_allowed_for_user($fundId)) {
            $error = 'آپ کے اکاؤنٹ کے پاس اس فنڈ میں اخراجات درج کرنے کا اختیار نہیں ہے۔';
        } else {
            try {
                $pdo->beginTransaction();

                $prefix = 'PV-' . date('Ym', strtotime($date)) . '-';
                $lastNo = $pdo->query("SELECT voucher_no FROM vouchers WHERE voucher_no LIKE '{$prefix}%' ORDER BY id DESC LIMIT 1")->fetchColumn();
                $nextSeq = 1;
                if ($lastNo && preg_match('/-(\d+)$/', $lastNo, $m)) {
                    $nextSeq = (int)$m[1] + 1;
                }
                $voucherNo = $prefix . str_pad((string)$nextSeq, 4, '0', STR_PAD_LEFT);

                // Workflow status: Direct post if user has voucher.direct_post or is Approver/Admin; otherwise 'draft'
                $isDirectPost = has_permission('voucher.direct_post') || can_approve();
                $voucherStatus = $isDirectPost ? 'posted' : 'draft';
                $postedAt = $isDirectPost ? date('Y-m-d H:i:s') : null;

                $userRegId = function_exists('get_user_region_id') ? get_user_region_id((int)current_user()['id']) : 0;
                $postRegId = (int)($_POST['region_id'] ?? 0);
                $voucherRegionId = $userRegId > 0 ? $userRegId : ($postRegId > 0 ? $postRegId : null);

                // 1. Insert Voucher
                $stmtVoucher = $pdo->prepare('INSERT INTO vouchers (voucher_no, voucher_type, voucher_date, fund_id, region_id, task_id, beneficiary_id, payee_name, payment_method, reference_no, description, total_amount, status, created_by, posted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmtVoucher->execute([
                    $voucherNo, 'expense', $date, $fundId, $voucherRegionId, ($taskId > 0 ? $taskId : null),
                    ($beneficiaryId > 0 ? $beneficiaryId : null),
                    $payeeName ?: null, $paymentMethod, $referenceNo, $description, $amount, $voucherStatus,
                    current_user()['id'], $postedAt
                ]);
                $voucherId = (int)$pdo->lastInsertId();

                // 2. Insert Journal Entry (status matches voucher)
                $narration = $description ?: ("Payment to " . ($payeeName ?: 'Vendor'));
                $stmtJe = $pdo->prepare('INSERT INTO journal_entries (voucher_id, region_id, entry_date, narration, status, created_by) VALUES (?, ?, ?, ?, ?, ?)');
                $stmtJe->execute([$voucherId, $voucherRegionId, $date, $narration, $voucherStatus, current_user()['id']]);
                $jeId = (int)$pdo->lastInsertId();

                // 3. Double-Entry Journal Lines
                // Debit: Expense Account (Expense Increase)
                $stmtLine = $pdo->prepare('INSERT INTO journal_lines (journal_entry_id, account_id, fund_id, description, debit, credit) VALUES (?, ?, ?, ?, ?, ?)');
                $stmtLine->execute([$jeId, $expenseAccountId, $fundId, 'Expense: ' . $narration, $amount, 0.00]);

                // Credit: Cash / Bank Asset Account (Asset Decrease)
                $stmtLine->execute([$jeId, $assetAccountId, $fundId, 'Paid via: ' . $narration, 0.00, $amount]);

                // 4. Handle Receipt/Bill Picture Attachment
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
                        $storedName = 'bill_' . $voucherId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                        if (move_uploaded_file($fileTmp, $uploadDir . $storedName)) {
                            $stmtAtt = $pdo->prepare('INSERT INTO attachments (voucher_id, original_name, stored_name, mime_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)');
                            $stmtAtt->execute([$voucherId, $fileName, $storedName, $fileType, $fileSize, current_user()['id']]);
                        }
                    }
                }

                // 5. Audit Log
                $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address) VALUES (?, ?, ?, ?, ?)');
                $log->execute([current_user()['id'], 'create_expense_voucher', 'voucher', $voucherId, $_SERVER['REMOTE_ADDR'] ?? null]);

                $pdo->commit();
                header('Location: expenses.php?success=created&v=' . urlencode($voucherNo) . '&st=' . $voucherStatus);
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Failed to save expense voucher: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'update_expense') {
        if (!can_approve()) {
            http_response_code(403);
            exit('Permission denied: Only Approvers and Super Admins can edit vouchers.');
        }
        $voucherId = (int)($_POST['voucher_id'] ?? 0);
        $date = trim($_POST['voucher_date'] ?? date('Y-m-d'));
        $fundId = (int)($_POST['fund_id'] ?? 0);
        $taskId = (int)($_POST['task_id'] ?? 0);
        $assetAccountId = (int)($_POST['asset_account_id'] ?? 0);
        if (!can_approve()) {
            $userCashAcc = get_or_create_user_cash_account($pdo, (int)current_user()['id']);
            $assetAccountId = (int)($userCashAcc['id'] ?? 0);
        }
        $expenseAccountId = (int)($_POST['expense_account_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $paymentMethod = trim($_POST['payment_method'] ?? 'cash');
        $payeeName = trim($_POST['payee_name'] ?? '');
        $referenceNo = trim($_POST['reference_no'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $beneficiaryId = !empty($_POST['beneficiary_id']) ? (int)$_POST['beneficiary_id'] : null;

        if ($voucherId <= 0 || $amount <= 0 || $fundId <= 0 || $assetAccountId <= 0 || $expenseAccountId <= 0) {
            $error = 'Please fill all required voucher fields with valid values.';
        } else {
            try {
                $pdo->beginTransaction();

                // 1. Update Voucher
                $stmtUpV = $pdo->prepare('UPDATE vouchers SET voucher_date=?, fund_id=?, task_id=?, beneficiary_id=?, payee_name=?, payment_method=?, reference_no=?, description=?, total_amount=? WHERE id=?');
                $stmtUpV->execute([$date, $fundId, ($taskId > 0 ? $taskId : null), ($beneficiaryId > 0 ? $beneficiaryId : null), $payeeName ?: null, $paymentMethod, $referenceNo, $description, $amount, $voucherId]);

                // 2. Update Journal Entry
                $stmtJeId = $pdo->prepare('SELECT id FROM journal_entries WHERE voucher_id = ?');
                $stmtJeId->execute([$voucherId]);
                $jeId = (int)$stmtJeId->fetchColumn();

                $narration = $description ?: ("Payment to " . ($payeeName ?: 'Vendor'));
                if ($jeId) {
                    $pdo->prepare('UPDATE journal_entries SET entry_date=?, narration=? WHERE id=?')->execute([$date, $narration, $jeId]);
                    $pdo->prepare('DELETE FROM journal_lines WHERE journal_entry_id = ?')->execute([$jeId]);
                } else {
                    $stmtJeNew = $pdo->prepare('INSERT INTO journal_entries (voucher_id, entry_date, narration, status, created_by) VALUES (?, ?, ?, "posted", ?)');
                    $stmtJeNew->execute([$voucherId, $date, $narration, current_user()['id']]);
                    $jeId = (int)$pdo->lastInsertId();
                }

                // 3. Double-entry lines: Debit Expense, Credit Asset
                $stmtLine = $pdo->prepare('INSERT INTO journal_lines (journal_entry_id, account_id, fund_id, description, debit, credit) VALUES (?, ?, ?, ?, ?, ?)');
                $stmtLine->execute([$jeId, $expenseAccountId, $fundId, 'Expense: ' . $narration, $amount, 0.00]);
                $stmtLine->execute([$jeId, $assetAccountId, $fundId, 'Paid via: ' . $narration, 0.00, $amount]);

                // 4. Handle New Receipt Image Upload if provided
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
                        $storedName = 'bill_' . $voucherId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                        if (move_uploaded_file($fileTmp, $uploadDir . $storedName)) {
                            $pdo->prepare('DELETE FROM attachments WHERE voucher_id = ?')->execute([$voucherId]);
                            $stmtAtt = $pdo->prepare('INSERT INTO attachments (voucher_id, original_name, stored_name, mime_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)');
                            $stmtAtt->execute([$voucherId, $fileName, $storedName, $fileType, $fileSize, current_user()['id']]);
                        }
                    }
                }

                $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address) VALUES (?, ?, ?, ?, ?)');
                $log->execute([current_user()['id'], 'edit_expense_voucher', 'voucher', $voucherId, $_SERVER['REMOTE_ADDR'] ?? null]);

                $pdo->commit();
                header('Location: expenses.php?success=updated');
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Failed to update voucher: ' . $e->getMessage();
            }
        }
    }
}

// Check if Edit Mode
$editVoucher = null;
$editAssetAcc = 0;
$editExpenseAcc = 0;
if (isset($_GET['edit_id'])) {
    if (!can_approve()) {
        $error = 'Once submitted, vouchers cannot be edited by users. Only Approvers or Super Admins can modify vouchers.';
    } else {
        $editId = (int)$_GET['edit_id'];
        $stmtE = $pdo->prepare("SELECT * FROM vouchers WHERE id = ? AND voucher_type = 'expense'");
        $stmtE->execute([$editId]);
        $editVoucher = $stmtE->fetch();

        if ($editVoucher) {
            $jlRows = $pdo->query("SELECT jl.account_id, jl.debit, jl.credit, a.account_type
                                   FROM journal_lines jl
                                   JOIN journal_entries je ON je.id = jl.journal_entry_id
                                   JOIN accounts a ON a.id = jl.account_id
                                   WHERE je.voucher_id = {$editId}")->fetchAll();
            foreach ($jlRows as $row) {
                if ($row['account_type'] === 'asset' || (float)$row['credit'] > 0) {
                    $editAssetAcc = (int)$row['account_id'];
                }
                if ($row['account_type'] === 'expense' || (float)$row['debit'] > 0) {
                    $editExpenseAcc = (int)$row['account_id'];
                }
            }
        }
    }
}

// Fetch dropdown data
$currentUserId = (int)current_user()['id'];
$userCashAcc = get_or_create_user_cash_account($pdo, $currentUserId);
$funds = get_user_allowed_funds();
$isSuperAdmin = in_array(current_user()['role'] ?? '', ['Super Admin', 'Administrator'], true);
$userFundIds = get_user_assigned_fund_ids($currentUserId);
$hasFundRestriction = (!$isSuperAdmin && !empty($userFundIds));
$currOrgMode = function_exists('get_org_mode') ? get_org_mode() : 'trust';
$assetAccounts = $pdo->query("SELECT id, account_code, account_name FROM accounts WHERE is_cash_or_bank=1 AND is_active=1 ORDER BY account_code ASC")->fetchAll();
$expenseAccounts = $pdo->query("SELECT id, account_code, account_name FROM accounts WHERE account_type='expense' AND is_active=1 AND scope IN ('all', '{$currOrgMode}') ORDER BY account_code ASC")->fetchAll();
$activeTasks = [];
try {
    $activeTasks = $pdo->query("SELECT id, task_code, title, assigned_to FROM tasks WHERE status IN ('pending', 'in_progress') ORDER BY id DESC")->fetchAll();
} catch (Exception $e) {
    // Tasks table might not exist yet
}

$userRegId = function_exists('get_user_region_id') ? get_user_region_id($currentUserId) : 0;
$isRegDirector = function_exists('is_regional_director') ? is_regional_director() : false;
$filterRegion = (int)($_GET['region_id'] ?? 0);
$allRegions = function_exists('get_all_active_regions') ? get_all_active_regions() : [];
$myRegion = $userRegId > 0 && function_exists('get_region_by_id') ? get_region_by_id($userRegId) : null;

// Statistics (Province/Region Aware)
if ($isRegDirector && $userRegId > 0) {
    $totalExpenses = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM vouchers WHERE voucher_type='expense' AND status='posted' AND region_id={$userRegId}")->fetchColumn();
    $pendingExpenses = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM vouchers WHERE voucher_type='expense' AND status='draft' AND region_id={$userRegId}")->fetchColumn();
    $thisMonthExpenses = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM vouchers WHERE voucher_type='expense' AND status='posted' AND voucher_date >= DATE_FORMAT(NOW(), '%Y-%m-01') AND region_id={$userRegId}")->fetchColumn();
    $vouchersCount = (int)$pdo->query("SELECT COUNT(*) FROM vouchers WHERE voucher_type='expense' AND status='posted' AND region_id={$userRegId}")->fetchColumn();
    $pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM vouchers WHERE voucher_type='expense' AND status='draft' AND region_id={$userRegId}")->fetchColumn();
} elseif (!can_approve()) {
    $totalExpenses = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM vouchers WHERE voucher_type='expense' AND status='posted' AND created_by={$currentUserId}")->fetchColumn();
    $pendingExpenses = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM vouchers WHERE voucher_type='expense' AND status='draft' AND created_by={$currentUserId}")->fetchColumn();
    $thisMonthExpenses = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM vouchers WHERE voucher_type='expense' AND status='posted' AND voucher_date >= DATE_FORMAT(NOW(), '%Y-%m-01') AND created_by={$currentUserId}")->fetchColumn();
    $vouchersCount = (int)$pdo->query("SELECT COUNT(*) FROM vouchers WHERE voucher_type='expense' AND status='posted' AND created_by={$currentUserId}")->fetchColumn();
    $pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM vouchers WHERE voucher_type='expense' AND status='draft' AND created_by={$currentUserId}")->fetchColumn();
} else {
    $regScope = ($isSuperAdmin && $filterRegion > 0) ? " AND region_id={$filterRegion}" : "";
    $totalExpenses = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM vouchers WHERE voucher_type='expense' AND status='posted'{$regScope}")->fetchColumn();
    $pendingExpenses = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM vouchers WHERE voucher_type='expense' AND status='draft'{$regScope}")->fetchColumn();
    $thisMonthExpenses = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM vouchers WHERE voucher_type='expense' AND status='posted' AND voucher_date >= DATE_FORMAT(NOW(), '%Y-%m-01'){$regScope}")->fetchColumn();
    $vouchersCount = (int)$pdo->query("SELECT COUNT(*) FROM vouchers WHERE voucher_type='expense' AND status='posted'{$regScope}")->fetchColumn();
    $pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM vouchers WHERE voucher_type='expense' AND status='draft'{$regScope}")->fetchColumn();
}

// Search / Filter
$filterFund = (int)($_GET['fund_id'] ?? 0);
$statusFilter = trim($_GET['status'] ?? 'all');
$search = trim($_GET['search'] ?? '');

// Prefill support from Beneficiaries or external links
$prefillBenId = (int)($_GET['beneficiary_id'] ?? 0);
$prefillPayee = $_GET['payee_name'] ?? '';
$prefillAmount = $_GET['amount'] ?? '';
$prefillDesc = $_GET['desc'] ?? '';
$prefillFundId = (int)($_GET['fund_id'] ?? 0);

// Fetch active beneficiaries for linking
$allBeneficiaries = $pdo->query("SELECT id, full_name, father_name, department_class, category FROM beneficiaries WHERE status='active' ORDER BY full_name ASC")->fetchAll() ?: [];

$query = "SELECT v.*, f.name AS fund_name, u.full_name AS submitter_name, att.stored_name AS receipt_file,
                 reg.name AS region_name, reg.code AS region_code,
                 tk.task_code, tk.title AS task_title
          FROM vouchers v
          LEFT JOIN funds f ON f.id = v.fund_id
          LEFT JOIN users u ON u.id = v.created_by
          LEFT JOIN regions reg ON reg.id = v.region_id
          LEFT JOIN attachments att ON att.voucher_id = v.id
          LEFT JOIN tasks tk ON tk.id = v.task_id
          WHERE v.voucher_type='expense'";
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
    $query .= " AND (v.voucher_no LIKE ? OR v.payee_name LIKE ? OR v.description LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
$query .= " ORDER BY v.voucher_date DESC, v.id DESC LIMIT 100";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$expenseList = $stmt->fetchAll();
$madrasaName = get_setting('madrasa_name', 'Trust Accounts');
$prefillDate = trim($_GET['voucher_date'] ?? ($_GET['date'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $prefillDate)) {
    $prefillDate = '';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Expenses Management | <?=e($madrasaName)?></title>
    <link rel="stylesheet" href="assets/style.css?v=<?=css_ver()?>">
    <style>
        .thumb-btn { display: inline-flex; align-items: center; gap: 4px; background: #eff6ff; border: 1px solid #bfdbfe; color: #1d4ed8; font-size: 11.5px; font-weight: 600; padding: 3px 8px; border-radius: 6px; cursor: pointer; text-decoration: none; }
        .thumb-btn:hover { background: #dbeafe; }
        .img-modal { display: none; position: fixed; z-index: 9999; inset: 0; background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(4px); place-items: center; padding: 20px; }
        .img-modal.open { display: grid; }
        .img-modal-content { background: #fff; border-radius: 12px; max-width: 680px; width: 100%; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 20px 40px rgba(0,0,0,0.3); }
        .img-modal-header { display: flex; justify-content: space-between; align-items: center; padding: 14px 20px; border-bottom: 1px solid #e2e8f0; background: #f8fafc; }
        .img-modal-body { padding: 16px; overflow: auto; text-align: center; }
        .img-modal-body img { max-width: 100%; max-height: 70vh; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .close-btn { background: none; border: none; font-size: 22px; cursor: pointer; color: #64748b; line-height: 1; }
    </style>
</head>
<body>
<div class="app-shell">
    <?php require __DIR__ . '/../app/sidebar.php'; ?>

    <main class="main">
        <header class="topbar">
            <div>
                <span class="eyebrow">ACCOUNTING</span>
                <h1>Expenses <span class="ur">(اخراجات / ادائیگیاں)</span></h1>
            </div>
            <div class="btn-row">
                <?php if ($editVoucher): ?>
                    <a href="expenses.php" class="btn secondary sm">＋ Add New Expense Mode</a>
                <?php else: ?>
                    <button class="btn danger sm" onclick="document.getElementById('expenseFormCard').scrollIntoView({behavior:'smooth'});">
                        ＋ New Expense Voucher
                    </button>
                <?php endif; ?>
            </div>
        </header>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert success">
                <?php if ($_GET['success'] === 'created'): ?>
                    <?php if (($_GET['st'] ?? '') === 'draft'): ?>
                        Payment Voucher <b><?=e($_GET['v'] ?? '')?></b> saved! It is now <strong>PENDING APPROVAL <span class="ur">(زیرِ التواء)</span></strong>. It will be deducted from Main Cash/Bank once approved by the checker.
                    <?php else: ?>
                        Payment Voucher <b><?=e($_GET['v'] ?? '')?></b> successfully recorded and posted to ledger!
                    <?php endif; ?>
                <?php elseif ($_GET['success'] === 'updated'): ?>
                    Payment Voucher updated successfully!
                <?php elseif ($_GET['success'] === 'deleted'): ?>
                    Payment Voucher <b><?=e($_GET['v'] ?? '')?></b> successfully deleted and ledger reversed!
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
                        <div style="font-size:12px;color:#2563eb;">Managing and monitoring operational & field expenses for <?=e($myRegion['name'])?> province.</div>
                    </div>
                </div>
                <span class="badge" style="background:#dbeafe;color:#1e40af;border:1px solid #93c5fd;font-weight:700;">Code: <?=e($myRegion['code'])?></span>
            </div>
        <?php endif; ?>

        <!-- Stats Grid (Only visible to Approvers/Admins on Desktop) -->
        <?php if (can_approve()): ?>
        <section class="stats-grid voucher-stats-grid" style="margin:22px 0 24px;">
            <article class="stat-card">
                <div class="stat-top"><span>Total Posted Expenses</span><span class="stat-icon red">↘</span></div>
                <strong><?=money($totalExpenses)?></strong>
                <small>Approved & deducted from Cash/Bank</small>
            </article>
            <article class="stat-card">
                <div class="stat-top"><span>Pending Approval <span class="ur">(زیرِ التواء)</span></span><span class="stat-icon yellow">⏳</span></div>
                <strong style="color:#d97706;"><?=money($pendingExpenses)?></strong>
                <small><?=$pendingCount?> vouchers waiting for approval</small>
            </article>
            <article class="stat-card">
                <div class="stat-top"><span>This Month (Posted)</span><span class="stat-icon blue">📅</span></div>
                <strong><?=money($thisMonthExpenses)?></strong>
                <small><?=date('F Y')?></small>
            </article>
            <article class="stat-card">
                <div class="stat-top"><span>Approved Vouchers</span><span class="stat-icon purple">🧾</span></div>
                <strong><?=$vouchersCount?></strong>
                <small>Posted in general ledger</small>
            </article>
        </section>
        <?php endif; ?>

        <!-- Professional Expense Form Card (Add / Edit) -->
        <article class="form-card" id="expenseFormCard">
            <div class="form-card-header">
                <h3><?= $editVoucher ? 'Edit Expense Voucher <span class="ur">(واؤچر میں ترمیم کریں)</span> — '.e($editVoucher['voucher_no']) : 'Record New Expense Payment <span class="ur">(واؤچر ادائیگی اخراجات)</span>' ?></h3>
                <p><?= $editVoucher ? 'Modify voucher details and update double-entry journals' : 'Enter teacher salaries, kitchen ration, electricity bills, books, or maintenance' ?></p>
            </div>

            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                <input type="hidden" name="action" value="<?= $editVoucher ? 'update_expense' : 'save_expense' ?>">
                <?php if ($editVoucher): ?>
                    <input type="hidden" name="voucher_id" value="<?=$editVoucher['id']?>">
                <?php endif; ?>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Voucher Date <span class="ur">(تاریخ)</span></label>
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
                        <label>From Fund <span class="ur">(کس فنڈ سے خرچ ہوا)</span></label>
                        <select name="fund_id" required>
                            <option value="">-- Select Fund <span class="ur">(فنڈ منتخب کریں)</span> --</option>
                            <?php foreach ($funds as $f): ?>
                                <?php $isFundSel = ($editVoucher && (int)$editVoucher['fund_id'] === (int)$f['id']) || (!$editVoucher && $prefillFundId === (int)$f['id']); ?>
                                <option value="<?=$f['id']?>" <?=$isFundSel ? 'selected' : ''?>>
                                    <?=e($f['name'])?> (<?=e($f['fund_code'])?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Paid From <span class="ur">(ادائیگی کا ذریعہ)</span></label>
                        <?php if (!can_approve() && !empty($userCashAcc)): ?>
                            <input type="hidden" name="asset_account_id" value="<?=$userCashAcc['id']?>">
                            <div style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:8px;padding:9px 12px;color:#1e40af;font-weight:600;display:flex;align-items:center;gap:8px;">
                                <span>💼</span>
                                <span><?=e(format_ui_text($userCashAcc['account_name']))?> <span class="ur">(خودکار کیش)</span></span>
                            </div>
                            <small style="color:var(--muted);font-size:11px;margin-top:4px;display:block;">
                                <span class="ur">خرچ آپ کے کیش میں درج ہو گا۔ چیکر کی منظوری اور رقم وصول ہونے پر مرکزی اکاؤنٹ سے تسویہ ہو گا۔</span>
                                <span class="en-only" style="display:none;">Expense is recorded in your cashier custody and settled upon checker approval.</span>
                            </small>
                        <?php else: ?>
                            <select name="asset_account_id" required>
                                <?php foreach ($assetAccounts as $acc): ?>
                                    <option value="<?=$acc['id']?>" <?=($editVoucher && (int)$editAssetAcc === (int)$acc['id']) ? 'selected' : ''?>>
                                        <?=e($acc['account_code'])?> - <?=e(format_ui_text($acc['account_name']))?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label>Expense Head <span class="ur">(اخراجات کی مد)</span></label>
                        <select name="expense_account_id" required>
                            <option value="">-- Select Expense Category --</option>
                            <?php foreach ($expenseAccounts as $acc): ?>
                                <option value="<?=$acc['id']?>" <?=($editVoucher && (int)$editExpenseAcc === (int)$acc['id']) ? 'selected' : ''?>>
                                    <?=e($acc['account_code'])?> - <?=e(format_ui_text($acc['account_name']))?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Student / Beneficiary Link (Feature #3) -->
                    <div class="form-group">
                        <label>Student / Beneficiary <span class="ur">(طالب علم / مستحق - اختیاری)</span></label>
                        <select name="beneficiary_id" onchange="if(this.value!='0'){ var opt=this.options[this.selectedIndex]; var nm=opt.getAttribute('data-name'); if(nm) document.querySelector('input[name=\'payee_name\']').value=nm; }">
                            <option value="0" data-name="">-- None / General Expense --</option>
                            <?php foreach ($allBeneficiaries as $ben): ?>
                                <?php $isBenSel = ($editVoucher && (int)($editVoucher['beneficiary_id'] ?? 0) === (int)$ben['id']) || (!$editVoucher && $prefillBenId === (int)$ben['id']); ?>
                                <option value="<?=$ben['id']?>" data-name="<?=e($ben['full_name'])?>" <?=$isBenSel ? 'selected' : ''?>>
                                    <?=e($ben['full_name'])?> (<?=e($ben['category'])?><?= $ben['department_class'] ? ' - '.e($ben['department_class']) : '' ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Payee / Paid To <span class="ur">(کس کو ادائیگی کی)</span></label>
                        <input name="payee_name" placeholder="Teacher name, student, vendor..." required value="<?=e($editVoucher['payee_name'] ?? $prefillPayee)?>">
                    </div>

                    <div class="form-group">
                        <label>Amount <span class="ur">(رقم PKR)</span></label>
                        <input type="number" step="0.01" min="1" name="amount" required placeholder="0.00" value="<?=e($editVoucher['total_amount'] ?? $prefillAmount)?>" style="font-weight:700;font-size:15px;color:#b91c1c;">
                    </div>

                    <div class="form-group">
                        <label>Payment Method <span class="ur">(طریقہ ادائیگی)</span></label>
                        <select name="payment_method">
                            <?php $currMethod = $editVoucher['payment_method'] ?? 'cash'; ?>
                            <option value="cash" <?=$currMethod==='cash'?'selected':''?>>Cash <span class="ur">(نقد)</span></option>
                            <option value="bank" <?=$currMethod==='bank'?'selected':''?>>Bank Transfer <span class="ur">(بینک)</span></option>
                            <option value="online" <?=$currMethod==='online'?'selected':''?>>Online / EasyPaisa / JazzCash</option>
                            <option value="cheque" <?=$currMethod==='cheque'?'selected':''?>>Cheque <span class="ur">(چیک)</span></option>
                            <option value="other" <?=$currMethod==='other'?'selected':''?>>Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Bill / Cheque # <span class="ur">(بل یا چیک نمبر)</span></label>
                        <input name="reference_no" placeholder="Optional bill or cheque no." value="<?=e($editVoucher['reference_no'] ?? '')?>">
                    </div>

                    <div class="form-group">
                        <label>Link to Task / Activity <span class="ur">(متعلقہ ٹاسک)</span></label>
                        <select name="task_id">
                            <option value="0">-- None / General Expense --</option>
                            <?php foreach ($activeTasks as $taskItem): ?>
                                <option value="<?=$taskItem['id']?>" <?=($editVoucher && (int)($editVoucher['task_id'] ?? 0) === (int)$taskItem['id']) ? 'selected' : ''?>>
                                    [<?=e($taskItem['task_code'])?>] <?=e($taskItem['title'])?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Bill Photo Attachment -->
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>📷 Attach Bill / Receipt Photo <span class="ur">(دکاندار کے بل یا رسید کی تصویر)</span></label>
                        <input type="file" name="receipt_file" accept="image/jpeg,image/png,image/webp,application/pdf" capture="environment">
                        <small class="muted">Upload bill or receipt picture directly from mobile camera or computer (JPG, PNG, PDF).</small>
                    </div>

                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Description / Particulars <span class="ur">(تفصیل و یادداشت)</span></label>
                        <input name="description" placeholder="e.g. Teacher salary for current month, electricity bill payment, ration supply..." value="<?=e($editVoucher['description'] ?? $prefillDesc)?>">
                    </div>
                </div>

                <div class="btn-row" style="margin-top:10px;">
                    <button class="btn danger" type="submit">
                        <?= $editVoucher ? '💾 Update Voucher' : (can_approve() ? '💾 Save & Post Payment' : '💾 Submit for Approval <span class="ur">(منظوری کے لیے بھیجیں)</span>') ?>
                    </button>
                    <?php if ($editVoucher): ?>
                        <a href="expenses.php" class="btn secondary">Cancel Editing</a>
                    <?php else: ?>
                        <button class="btn secondary" type="reset">Reset Form</button>
                    <?php endif; ?>
                </div>
            </form>
        </article>

        <!-- Expenses List Table -->
        <article class="panel" id="expensesLedger">
            <div class="panel-heading">
                <div>
                    <h3>Expense Payments Register</h3>
                    <p>Records with double-entry ledger status and attached receipts</p>
                </div>
            </div>

            <!-- Filter Bar -->
            <form method="get" action="expenses.php#expensesLedger" class="filter-bar" id="expensesFilterForm">
                <input id="expensesSearchInput" name="search" placeholder="Search by Voucher, Payee..." value="<?=e($search)?>">
                <select id="expensesStatusFilter" name="status">
                    <option value="all" <?=$statusFilter==='all'?'selected':''?>>All Statuses <span class="ur">(تمام واؤچرز)</span></option>
                    <option value="posted" <?=$statusFilter==='posted'?'selected':''?>>Posted / Approved <span class="ur">(منظور شدہ)</span></option>
                    <option value="draft" <?=$statusFilter==='draft'?'selected':''?>>Pending Approval <span class="ur">(زیرِ التواء)</span></option>
                </select>
                <select id="expensesFundFilter" name="fund_id">
                    <option value="0">All Funds</option>
                    <?php foreach ($funds as $f): ?>
                        <option value="<?=$f['id']?>" <?=$filterFund === (int)$f['id'] ? 'selected' : ''?>><?=e($f['name'])?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($isSuperAdmin && !empty($allRegions)): ?>
                    <select name="region_id">
                        <option value="0">🌐 All Pakistan (پورے پاکستان)</option>
                        <?php foreach ($allRegions as $reg): ?>
                            <option value="<?=$reg['id']?>" <?=$filterRegion === (int)$reg['id'] ? 'selected' : ''?>>🏛️ <?=e($reg['name'])?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <button class="btn secondary sm" type="submit">Filter</button>
                <?php if ($filterFund || $search || $statusFilter !== 'all'): ?>
                    <a href="expenses.php#expensesLedger" class="btn secondary sm" style="text-decoration:none;">Clear</a>
                <?php endif; ?>
            </form>

            <?php if (!$expenseList): ?>
                <div class="empty-state">
                    <div class="empty-icon">－</div>
                    <b>No expense records found</b>
                    <p>Enter your first expense payment voucher using the form above.</p>
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
                                <th>Paid To</th>
                                <th>Status</th>
                                <th>Receipt / Bill</th>
                                <th class="right">Amount</th>
                                <th class="right" style="min-width:170px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expenseList as $exp): ?>
                                <tr class="voucher-row" data-status="<?=e($exp['status'])?>" data-fund-id="<?=e($exp['fund_id'])?>">
                                    <td>
                                        <b><?=e($exp['voucher_no'])?></b>
                                        <?php if ($exp['submitter_name']): ?>
                                            <div style="font-size:11px;color:#94a3b8;">By: <?=e($exp['submitter_name'])?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?=e($exp['voucher_date'])?></td>
                                    <td>
                                        <?php if (!empty($exp['region_name'])): ?>
                                            <span class="badge" style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;font-size:11px;font-weight:600;">
                                                🏛️ <?=e($exp['region_name'])?>
                                            </span>
                                        <?php else: ?>
                                            <span class="muted" style="font-size:11px;">— HQ</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-purple"><?=e($exp['fund_name'] ?? 'General')?></span>
                                        <?php if (!empty($exp['task_code'])): ?>
                                            <div style="margin-top:4px;">
                                                <a href="tasks.php?view_id=<?=$exp['task_id']?>" style="font-size:10.5px;background:#eff6ff;color:#1d4ed8;padding:2px 6px;border-radius:4px;text-decoration:none;font-weight:600;display:inline-flex;align-items:center;gap:3px;border:1px solid #bfdbfe;" title="<?=e($exp['task_title'] ?? '')?>">
                                                    📋 <?=e($exp['task_code'])?>
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <b><?=e($exp['payee_name'] ?: '—')?></b>
                                        <?php if ($exp['description']): ?>
                                            <div style="font-size:11.5px;color:#64748b;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?=e($exp['description'])?>">
                                                <?=e($exp['description'])?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($exp['status'] === 'posted'): ?>
                                            <span class="badge badge-green">Posted <span class="ur">(منظور شدہ)</span></span>
                                        <?php elseif ($exp['status'] === 'draft'): ?>
                                            <span class="badge badge-amber" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;">Pending <span class="ur">(زیرِ التواء)</span></span>
                                        <?php else: ?>
                                            <span class="badge badge-red">Rejected <span class="ur">(مسترد)</span></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($exp['receipt_file'])): 
                                            $filePath = 'uploads/receipts/' . $exp['receipt_file'];
                                            $isPdf = (strtolower(pathinfo($exp['receipt_file'], PATHINFO_EXTENSION)) === 'pdf');
                                        ?>
                                            <?php if ($isPdf): ?>
                                                <a href="<?=e($filePath)?>" target="_blank" class="thumb-btn">
                                                    📄 PDF
                                                </a>
                                            <?php else: ?>
                                                <button type="button" class="thumb-btn" onclick="openReceiptModal('<?=e($filePath)?>', '<?=e(addslashes($exp['voucher_no']))?>')">
                                                    📷 View Bill
                                                </button>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="font-size:11px;color:#94a3b8;">— None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="right font-bold" style="color:#b91c1c;"><?=money($exp['total_amount'])?></td>
                                    <td class="right">
                                        <div class="btn-row" style="justify-content:flex-end;gap:5px;">
                                            <?php if ($exp['status'] === 'draft' && can_approve()): ?>
                                                <form method="post" style="display:inline-block;" onsubmit="return confirm('Approve expense <?=e($exp['voucher_no'])?>? It will be deducted from Main Cash/Bank.');">
                                                    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                                                    <input type="hidden" name="action" value="approve_expense">
                                                    <input type="hidden" name="voucher_id" value="<?=$exp['id']?>">
                                                    <button type="submit" class="btn success sm" style="padding:3px 7px;font-size:11px;" title="Approve & Deduct from Main Cash/Bank">
                                                        ✓ Approve
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <a href="print_voucher.php?id=<?=$exp['id']?>&format=thermal&autoprint=1" target="_blank" class="btn secondary sm" style="padding:3px 6px;font-size:11px;font-weight:600;" title="Print 80mm POS Thermal Slip (فوری تھرمل پرنٹ)">
                                                🧾 80mm
                                            </a>
                                            <a href="print_voucher.php?id=<?=$exp['id']?>&format=a4" target="_blank" class="btn secondary sm" style="padding:3px 6px;font-size:11px;" title="Print A4 Full Voucher">
                                                📄 A4
                                            </a>
                                            <?php if (can_approve()): ?>
                                                <a href="expenses.php?edit_id=<?=$exp['id']?>#expenseFormCard" class="btn secondary sm" style="padding:3px 7px;font-size:11.5px;" title="Edit Voucher">
                                                    ✏️
                                                </a>
                                                <a href="expenses.php?delete_id=<?=$exp['id']?>&token=<?=csrf_token()?>" class="btn danger sm" style="padding:3px 7px;font-size:11.5px;" onclick="return confirm('Are you sure you want to delete voucher <?=e($exp['voucher_no'])?>?');" title="Delete Voucher">
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

<!-- Receipt Lightbox Modal -->
<div id="receiptModal" class="img-modal" onclick="if(event.target===this) closeReceiptModal();">
    <div class="img-modal-content">
        <div class="img-modal-header">
            <b id="modalTitle" style="font-size:14px;color:#0f172a;">Attached Receipt / Bill</b>
            <button type="button" class="close-btn" onclick="closeReceiptModal()">&times;</button>
        </div>
        <div class="img-modal-body">
            <img id="modalImg" src="" alt="Voucher Bill Attachment">
        </div>
        <div style="padding:10px 16px;background:#f8fafc;border-top:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
            <a id="modalDownload" href="" target="_blank" class="btn secondary sm" download>⬇ Download</a>
            <button type="button" class="btn primary sm" onclick="closeReceiptModal()">Close</button>
        </div>
    </div>
</div>

<script>
function openReceiptModal(src, voucherNo) {
    document.getElementById('modalTitle').textContent = 'Attached Bill for Voucher ' + voucherNo;
    document.getElementById('modalImg').src = src;
    document.getElementById('modalDownload').href = src;
    document.getElementById('receiptModal').classList.add('open');
}
function closeReceiptModal() {
    document.getElementById('receiptModal').classList.remove('open');
    document.getElementById('modalImg').src = '';
}

// Live real-time filter for Expense Payments Register
(function() {
    var searchInput = document.getElementById('expensesSearchInput');
    var fundSelect = document.getElementById('expensesFundFilter');
    var statusSelect = document.getElementById('expensesStatusFilter');
    var filterForm = document.getElementById('expensesFilterForm');
    var rows = document.querySelectorAll('#expensesLedger tr.voucher-row');

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

        var noMatch = document.getElementById('noExpenseMatchRow');
        if (rows.length > 0) {
            if (visibleCount === 0) {
                if (!noMatch) {
                    var tbody = document.querySelector('#expensesLedger table tbody');
                    if (tbody) {
                        noMatch = document.createElement('tr');
                        noMatch.id = 'noExpenseMatchRow';
                        noMatch.innerHTML = '<td colspan="8" style="text-align:center;padding:26px;color:#64748b;font-weight:500;">No expense records match the selected filter. <a href="javascript:void(0)" onclick="resetExpenseFilters()" style="color:#2563eb;text-decoration:underline;margin-left:8px;">Reset Filter</a></td>';
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

    window.resetExpenseFilters = function() {
        if (searchInput) searchInput.value = '';
        if (fundSelect) fundSelect.value = '0';
        if (statusSelect) statusSelect.value = 'all';
        applyLiveFilter();
        if (filterForm) {
            window.location.href = 'expenses.php#expensesLedger';
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
</body>
</html>
