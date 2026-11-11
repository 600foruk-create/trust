<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();

require_permission('voucher.approve');

$activePage = 'approvals';
$error = '';
$success = '';

$currentUser = current_user();
$currentUserId = (int)$currentUser['id'];
$userRegionId = function_exists('get_user_region_id') ? get_user_region_id($currentUserId) : 0;
$isSuperAdmin = function_exists('is_super_admin') ? is_super_admin() : false;
$isRegDirector = function_exists('is_regional_director') ? is_regional_director() : false;
$filterRegionId = (int)($_GET['region_id'] ?? 0);
$allRegions = function_exists('get_all_active_regions') ? get_all_active_regions() : [];
$myRegion = $userRegionId > 0 && function_exists('get_region_by_id') ? get_region_by_id($userRegionId) : null;

// Handle Voucher Approval
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf();
    $action = $_POST['action'];

    if ($action === 'approve_voucher') {
        $voucherId = (int)($_POST['voucher_id'] ?? 0);
        if ($voucherId > 0) {
            // Security check: Regional Director can only approve their own region's vouchers
            if (!$isSuperAdmin && $userRegionId > 0) {
                $vReg = (int)$pdo->query("SELECT region_id FROM vouchers WHERE id = {$voucherId}")->fetchColumn();
                if ($vReg !== $userRegionId) {
                    $error = 'You are only authorized to approve vouchers from your own province / region.';
                }
            }
            if (!$error) {
                $res = approve_voucher_with_settlement($pdo, $voucherId, $currentUserId);
                if ($res['success']) {
                    header('Location: approvals.php?success=approved');
                    exit;
                } else {
                    $error = $res['message'] ?? 'Approval failed.';
                }
            }
        }
    } elseif ($action === 'reject_voucher') {
        $voucherId = (int)($_POST['voucher_id'] ?? 0);
        $reason = trim($_POST['reject_reason'] ?? '');
        if ($voucherId > 0) {
            // Security check: Regional Director can only reject their own region's vouchers
            if (!$isSuperAdmin && $userRegionId > 0) {
                $vReg = (int)$pdo->query("SELECT region_id FROM vouchers WHERE id = {$voucherId}")->fetchColumn();
                if ($vReg !== $userRegionId) {
                    $error = 'You are only authorized to review vouchers from your own province / region.';
                }
            }
            if (!$error) {
                try {
                    $pdo->beginTransaction();

                    $stmtV = $pdo->prepare("UPDATE vouchers SET status = 'reversed', description = CONCAT(COALESCE(description,''), ' [REJECTED: ', ?, ']') WHERE id = ? AND status = 'draft'");
                    $stmtV->execute([$reason ?: 'Rejected by auditor/checker', $voucherId]);

                    $stmtJe = $pdo->prepare("UPDATE journal_entries SET status = 'reversed' WHERE voucher_id = ?");
                    $stmtJe->execute([$voucherId]);

                    $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address) VALUES (?, ?, ?, ?, ?)');
                    $log->execute([$currentUserId, 'reject_voucher', 'voucher', $voucherId, $_SERVER['REMOTE_ADDR'] ?? null]);

                    $pdo->commit();
                    header('Location: approvals.php?success=rejected');
                    exit;
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $error = 'Rejection failed: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'bulk_approve') {
        $ids = array_filter(array_map('intval', (array)($_POST['voucher_ids'] ?? [])));
        if (!empty($ids)) {
            $count = 0;
            foreach ($ids as $vId) {
                // If not Super Admin, ensure voucher belongs to their region
                if (!$isSuperAdmin && $userRegionId > 0) {
                    $vReg = (int)$pdo->query("SELECT region_id FROM vouchers WHERE id = {$vId}")->fetchColumn();
                    if ($vReg !== $userRegionId) {
                        continue; // Skip vouchers outside their region
                    }
                }
                $res = approve_voucher_with_settlement($pdo, $vId, $currentUserId);
                if ($res['success']) {
                    $count++;
                }
            }
            header('Location: approvals.php?success=bulk_approved&count=' . $count);
            exit;
        } else {
            $error = 'No vouchers selected for bulk approval.';
        }
    }
}

// Filter Tab
$filterType = trim($_GET['type'] ?? 'all');
$filterQuery = "WHERE v.status = 'draft'";
if ($filterType === 'expense') {
    $filterQuery .= " AND v.voucher_type = 'expense'";
} elseif ($filterType === 'income') {
    $filterQuery .= " AND v.voucher_type = 'income'";
}

// Regional scoping
if (!$isSuperAdmin && $userRegionId > 0) {
    $filterQuery .= " AND v.region_id = {$userRegionId}";
} elseif ($isSuperAdmin && $filterRegionId > 0) {
    $filterQuery .= " AND v.region_id = {$filterRegionId}";
}

// Fetch Pending Vouchers with Submitter, Region & Attachment details
$sql = "SELECT v.*, u.full_name AS submitter_name, u.username AS submitter_user,
        f.name AS fund_name, f.fund_code,
        d.donor_name,
        reg.name AS region_name, reg.code AS region_code,
        att.id AS attachment_id, att.stored_name AS receipt_file, att.original_name AS receipt_original_name
        FROM vouchers v
        LEFT JOIN users u ON u.id = v.created_by
        LEFT JOIN funds f ON f.id = v.fund_id
        LEFT JOIN donors d ON d.id = v.donor_id
        LEFT JOIN regions reg ON reg.id = v.region_id
        LEFT JOIN attachments att ON att.voucher_id = v.id
        {$filterQuery}
        ORDER BY v.voucher_date DESC, v.id DESC";
$pendingVouchers = $pdo->query($sql)->fetchAll();

// Overall Stats for Header Cards (Scoped by region)
$statsQuery = "SELECT 
    COUNT(*) AS total_count,
    COALESCE(SUM(total_amount), 0) AS total_amount,
    COALESCE(SUM(CASE WHEN voucher_type = 'income' THEN total_amount ELSE 0 END), 0) AS income_amount,
    COUNT(CASE WHEN voucher_type = 'income' THEN 1 ELSE NULL END) AS income_count,
    COALESCE(SUM(CASE WHEN voucher_type = 'expense' THEN total_amount ELSE 0 END), 0) AS expense_amount,
    COUNT(CASE WHEN voucher_type = 'expense' THEN 1 ELSE NULL END) AS expense_count
    FROM vouchers WHERE status = 'draft'";
if (!$isSuperAdmin && $userRegionId > 0) {
    $statsQuery .= " AND region_id = {$userRegionId}";
} elseif ($isSuperAdmin && $filterRegionId > 0) {
    $statsQuery .= " AND region_id = {$filterRegionId}";
}
$stats = $pdo->query($statsQuery)->fetch();

$madrasaName = get_setting('madrasa_name', 'Trust Accounts');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Pending Approvals | <?=e($madrasaName)?></title>
    <link rel="stylesheet" href="assets/style.css?v=<?=css_ver()?>">
    <style>
        .filter-tabs { display: flex; gap: 8px; margin-bottom: 16px; border-bottom: 1px solid var(--line); padding-bottom: 12px; }
        .filter-tab { padding: 8px 16px; border-radius: 8px; font-size: 13.5px; font-weight: 600; text-decoration: none; color: #475569; background: #ffffff; border: 1px solid var(--line); transition: all .15s ease; }
        .filter-tab.active { background: #2563eb; color: #ffffff; border-color: #2563eb; }
        .filter-tab:hover:not(.active) { background: #f1f5f9; }
        
        .thumb-btn { display: inline-flex; align-items: center; gap: 5px; background: #eff6ff; border: 1px solid #bfdbfe; color: #1d4ed8; font-size: 11.5px; font-weight: 600; padding: 4px 8px; border-radius: 6px; cursor: pointer; text-decoration: none; }
        .thumb-btn:hover { background: #dbeafe; }
        
        /* Modal for previewing receipt images */
        .img-modal { display: none; position: fixed; z-index: 9999; inset: 0; background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(4px); place-items: center; padding: 20px; }
        .img-modal.open { display: grid; }
        .img-modal-content { background: #fff; border-radius: 12px; max-width: 680px; width: 100%; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 20px 40px rgba(0,0,0,0.3); }
        .img-modal-header { display: flex; justify-content: space-between; align-items: center; padding: 14px 20px; border-bottom: 1px solid #e2e8f0; background: #f8fafc; }
        .img-modal-body { padding: 16px; overflow: auto; text-align: center; }
        .img-modal-body img { max-width: 100%; max-height: 70vh; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .close-btn { background: none; border: none; font-size: 22px; cursor: pointer; color: #64748b; line-height: 1; }
        .close-btn:hover { color: #0f172a; }
    </style>
</head>
<body>
<div class="app-shell">
    <?php require __DIR__ . '/../app/sidebar.php'; ?>

    <main class="main">
        <header class="topbar">
            <div>
                <span class="eyebrow">AUDIT & VERIFICATION</span>
                <h1>Voucher Approvals <span class="ur">(واؤچرز پڑتال و فائنل منظوری)</span></h1>
            </div>
        </header>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert success">
                <?php
                    if ($_GET['success'] === 'approved') echo '✓ Voucher approved successfully and posted to the Main Ledger & Cash/Bank account!';
                    elseif ($_GET['success'] === 'bulk_approved') echo '✓ ' . (int)($_GET['count'] ?? 0) . ' Vouchers approved and posted successfully!';
                    elseif ($_GET['success'] === 'rejected') echo '✕ Voucher rejected and cancelled.';
                    else echo 'Operation completed successfully!';
                ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert error"><?=e($error)?></div>
        <?php endif; ?>

        <!-- Stats Overview Cards -->
        <?php if ($isRegDirector && $myRegion): ?>
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-left:4px solid #16a34a;padding:12px 16px;border-radius:8px;margin:18px 0 10px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <span style="font-size:24px;">🏛️</span>
                    <div>
                        <b style="color:#166534;font-size:14.5px;"><?=e($myRegion['name'])?> Directorate <span class="ur">(صوبائی ڈائریکٹوریٹ)</span></b>
                        <div style="font-size:12px;color:#15803d;">You are auditing and approving vouchers submitted by cashiers and field staff within your province.</div>
                    </div>
                </div>
                <span class="badge" style="background:#dcfce7;color:#15803d;border:1px solid #86efac;font-weight:700;">Code: <?=e($myRegion['code'])?></span>
            </div>
        <?php endif; ?>

        <section class="stats-grid" style="margin:22px 0 24px;">
            <article class="stat-card">
                <div class="stat-top">
                    <span>Total Pending Vouchers <span class="ur">(کل زیرِ التواء)</span></span>
                    <span class="stat-icon yellow">⏳</span>
                </div>
                <strong><?= (int)$stats['total_count'] ?></strong>
                <small>Value: <?= money($stats['total_amount']) ?></small>
            </article>

            <article class="stat-card">
                <div class="stat-top">
                    <span>Pending Income <span class="ur">(آمدنی وصولی)</span></span>
                    <span class="stat-icon green">↗</span>
                </div>
                <strong><?= money($stats['income_amount']) ?></strong>
                <small><?= (int)$stats['income_count'] ?> pending receipts</small>
            </article>

            <article class="stat-card">
                <div class="stat-top">
                    <span>Pending Expenses <span class="ur">(اخراجات)</span></span>
                    <span class="stat-icon red">↘</span>
                </div>
                <strong><?= money($stats['expense_amount']) ?></strong>
                <small><?= (int)$stats['expense_count'] ?> bills to audit & approve</small>
            </article>

            <article class="stat-card">
                <div class="stat-top">
                    <span>Approval Role</span>
                    <span class="stat-icon purple">✓</span>
                </div>
                <strong style="font-size:16px;"><?=e(current_user()['role'] ?? 'Approver')?></strong>
                <small><?= $isRegDirector && $myRegion ? e($myRegion['name']) : 'Authorized Checker' ?></small>
            </article>
        </section>

        <!-- Filter Tabs & Province Filter -->
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px;border-bottom:1px solid var(--line);padding-bottom:12px;">
            <div class="filter-tabs" style="margin-bottom:0;border-bottom:none;padding-bottom:0;">
                <a href="approvals.php?type=all<?= $filterRegionId > 0 ? '&region_id=' . $filterRegionId : '' ?>" class="filter-tab <?=$filterType==='all'?'active':''?>">
                    All Pending <span class="ur">(تمام زیرِ التواء - <?=(int)$stats['total_count']?>)</span>
                </a>
                <a href="approvals.php?type=expense<?= $filterRegionId > 0 ? '&region_id=' . $filterRegionId : '' ?>" class="filter-tab <?=$filterType==='expense'?'active':''?>">
                    Expenses & Bills <span class="ur">(اخراجات بمع رسیدات - <?=(int)$stats['expense_count']?>)</span>
                </a>
                <a href="approvals.php?type=income<?= $filterRegionId > 0 ? '&region_id=' . $filterRegionId : '' ?>" class="filter-tab <?=$filterType==='income'?'active':''?>">
                    Income Receipts <span class="ur">(آمدنی وصولیاں - <?=(int)$stats['income_count']?>)</span>
                </a>
            </div>

            <?php if ($isSuperAdmin && !empty($allRegions)): ?>
                <form method="get" action="approvals.php" style="display:flex;align-items:center;gap:8px;margin:0;">
                    <input type="hidden" name="type" value="<?=e($filterType)?>">
                    <span style="font-size:12.5px;color:#475569;font-weight:600;">🏛️ Province / Region:</span>
                    <select name="region_id" onchange="this.form.submit()" style="padding:6px 12px;border:1px solid var(--line);border-radius:6px;font-size:13px;background:#fff;font-weight:600;color:#0f172a;">
                        <option value="0">🌐 All Pakistan (پورے پاکستان کے واؤچرز)</option>
                        <?php foreach ($allRegions as $reg): ?>
                            <option value="<?=$reg['id']?>" <?=$filterRegionId === (int)$reg['id'] ? 'selected' : ''?>>
                                🏛️ <?=e($reg['name'])?> (<?=e($reg['code'])?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
        </div>

        <!-- Approvals Panel -->
        <article class="panel">
            <div class="panel-heading">
                <div>
                    <h3>Pending Vouchers Queue <span class="ur">(زیرِ التواء واؤچرز کی لسٹ)</span></h3>
                    <p>Review details, audit attached receipt photos, and approve to post into Main Cash in Hand or Bank</p>
                </div>
                <?php if ($pendingVouchers): ?>
                    <div>
                        <button type="button" class="btn blue sm" onclick="document.getElementById('bulkForm').submit();">
                            ✓ Approve Selected <span class="ur">(منتخب منظور کریں)</span>
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!$pendingVouchers): ?>
                <div class="empty-state">
                    <div class="empty-icon">✓</div>
                    <b>All clear! No pending vouchers waiting for approval.</b>
                    <p>When users or cashiers record income or expenses, they will appear here for verification and approval.</p>
                </div>
            <?php else: ?>
                <form id="bulkForm" method="post">
                    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                    <input type="hidden" name="action" value="bulk_approve">

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width:36px;">
                                        <input type="checkbox" id="selectAllCheckbox" onclick="toggleSelectAll(this)">
                                    </th>
                                    <th>Voucher # / Date</th>
                                    <th>Type</th>
                                    <th>Province / Region <span class="ur">(صوبہ)</span></th>
                                    <th>Submitted By <span class="ur">(صارف)</span></th>
                                    <th>Party / Particulars</th>
                                    <th>Fund Account</th>
                                    <th>Amount</th>
                                    <th>Receipt / Bill <span class="ur">(رسید)</span></th>
                                    <th class="right">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingVouchers as $pv): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="voucher_ids[]" value="<?=$pv['id']?>" class="voucher-chk">
                                        </td>
                                        <td>
                                            <b><?=e($pv['voucher_no'])?></b>
                                            <div style="font-size:11.5px;color:#64748b;"><?=e(date('d M Y', strtotime($pv['voucher_date'])))?></div>
                                        </td>
                                        <td>
                                            <?php if ($pv['voucher_type'] === 'income'): ?>
                                                <span class="badge badge-green">Income <span class="ur">(آمدنی)</span></span>
                                            <?php else: ?>
                                                <span class="badge badge-red">Expense <span class="ur">(خرچ)</span></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($pv['region_name'])): ?>
                                                <span class="badge" style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;font-size:11px;font-weight:600;">
                                                    🏛️ <?=e($pv['region_name'])?>
                                                </span>
                                            <?php else: ?>
                                                <span class="muted" style="font-size:11px;">— HQ / Central</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <b><?=e($pv['submitter_name'] ?: ($pv['submitter_user'] ?: 'User'))?></b>
                                            <div style="font-size:11px;color:#94a3b8;">@<?=e($pv['submitter_user'] ?: 'staff')?></div>
                                        </td>
                                        <td>
                                            <?php 
                                                $party = $pv['voucher_type'] === 'income' 
                                                    ? ($pv['donor_name'] ?: ($pv['payee_name'] ?: 'General Donor')) 
                                                    : ($pv['payee_name'] ?: 'Vendor / Payee');
                                            ?>
                                            <b><?=e($party)?></b>
                                            <?php if ($pv['description']): ?>
                                                <div style="font-size:11.5px;color:#64748b;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?=e($pv['description'])?>">
                                                    <?=e($pv['description'])?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-blue"><?=e($pv['fund_name'] ?? 'General')?></span>
                                        </td>
                                        <td class="font-bold" style="font-size:14px;color:<?=$pv['voucher_type'] === 'income' ? '#15803d' : '#b91c1c'?>;">
                                            <?=money($pv['total_amount'])?>
                                        </td>
                                        <td>
                                            <?php if (!empty($pv['receipt_file'])): 
                                                $filePath = 'uploads/receipts/' . $pv['receipt_file'];
                                                $isPdf = (strtolower(pathinfo($pv['receipt_file'], PATHINFO_EXTENSION)) === 'pdf');
                                            ?>
                                                <?php if ($isPdf): ?>
                                                    <a href="<?=e($filePath)?>" target="_blank" class="thumb-btn">
                                                        📄 View PDF
                                                    </a>
                                                <?php else: ?>
                                                    <button type="button" class="thumb-btn" onclick="openReceiptModal('<?=e($filePath)?>', '<?=e(addslashes($pv['voucher_no']))?>')">
                                                        📷 View Bill <span class="ur">(بل دیکھیں)</span>
                                                    </button>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="font-size:11.5px;color:#94a3b8;">— No Receipt</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="right" style="white-space:nowrap;">
                                            <!-- Approve Button Form -->
                                            <form method="post" style="display:inline-block;" onsubmit="return confirm('Approve voucher <?=e($pv['voucher_no'])?> for <?=money($pv['total_amount'])?>? It will immediately post to Main Cash/Bank.');">
                                                <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                                                <input type="hidden" name="action" value="approve_voucher">
                                                <input type="hidden" name="voucher_id" value="<?=$pv['id']?>">
                                                <button type="submit" class="btn success sm" style="padding:4px 9px;font-size:11.5px;" title="Approve & Post to Ledger">
                                                    ✓ Approve
                                                </button>
                                            </form>

                                            <!-- Reject Button -->
                                            <button type="button" class="btn danger sm" style="padding:4px 9px;font-size:11.5px;" onclick="openRejectModal(<?=$pv['id']?>, '<?=e(addslashes($pv['voucher_no']))?>')">
                                                ✕ Reject
                                            </button>

                                            <!-- Print Slip -->
                                            <a href="print_voucher.php?id=<?=$pv['id']?>" target="_blank" class="btn secondary sm" style="padding:4px 8px;font-size:11.5px;" title="Preview Voucher">
                                                🖨️
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            <?php endif; ?>
        </article>

        <?=render_app_footer($madrasaName)?>
    </main>
</div>

<!-- Receipt Image Preview Lightbox Modal -->
<div id="receiptModal" class="img-modal" onclick="if(event.target===this) closeReceiptModal();">
    <div class="img-modal-content">
        <div class="img-modal-header">
            <b id="modalTitle" style="font-size:14px;color:#0f172a;">Receipt / Bill Attachment</b>
            <button type="button" class="close-btn" onclick="closeReceiptModal()">&times;</button>
        </div>
        <div class="img-modal-body">
            <img id="modalImg" src="" alt="Voucher Bill Attachment">
        </div>
        <div style="padding:10px 16px;background:#f8fafc;border-top:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
            <a id="modalDownload" href="" target="_blank" class="btn secondary sm" download>⬇ Download Image</a>
            <button type="button" class="btn primary sm" onclick="closeReceiptModal()">Close</button>
        </div>
    </div>
</div>

<!-- Reject Reason Modal -->
<div id="rejectModal" class="img-modal" onclick="if(event.target===this) closeRejectModal();">
    <div class="img-modal-content" style="max-width:440px;">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
            <input type="hidden" name="action" value="reject_voucher">
            <input type="hidden" name="voucher_id" id="rejectVoucherId" value="">

            <div class="img-modal-header">
                <b id="rejectModalTitle" style="font-size:14px;color:#991b1b;">Reject Voucher</b>
                <button type="button" class="close-btn" onclick="closeRejectModal()">&times;</button>
            </div>
            <div style="padding:18px 20px;">
                <p style="margin:0 0 12px;font-size:13px;color:#475569;">
                    Are you sure you want to reject this voucher? Enter the reason for rejection (e.g. Invalid bill, wrong fund, incorrect amount):
                </p>
                <textarea name="reject_reason" required rows="3" style="width:100%;padding:10px;border:1px solid var(--line);border-radius:8px;font-family:inherit;font-size:13px;" placeholder="Reason for rejection..."></textarea>
            </div>
            <div style="padding:12px 20px;background:#f8fafc;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;gap:10px;">
                <button type="button" class="btn secondary sm" onclick="closeRejectModal()">Cancel</button>
                <button type="submit" class="btn danger sm">✕ Confirm Rejection</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleSelectAll(master) {
    const checkboxes = document.querySelectorAll('.voucher-chk');
    checkboxes.forEach(cb => cb.checked = master.checked);
}

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

function openRejectModal(voucherId, voucherNo) {
    document.getElementById('rejectVoucherId').value = voucherId;
    document.getElementById('rejectModalTitle').textContent = 'Reject Voucher: ' + voucherNo;
    document.getElementById('rejectModal').classList.add('open');
}

function closeRejectModal() {
    document.getElementById('rejectModal').classList.remove('open');
}
</script>
</body>
</html>

