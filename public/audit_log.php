<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

if (!has_permission('audit.view') && !can_approve() && !in_array(current_user()['role'] ?? '', ['Super Admin', 'Administrator'], true)) {
    redirect_with_alert('dashboard.php', 'error', 'آپ کے پاس سیکیورٹی آڈٹ ٹریل دیکھنے کا اختیار نہیں ہے۔');
}

$activePage = 'audit_log';
$madrasaName = function_exists('get_setting') ? get_setting('madrasa_name', 'Trust Accounts') : 'Trust Accounts';

// Filters
$actionFilter = trim($_GET['action_type'] ?? 'all');
$userFilter = (int)($_GET['user_id'] ?? 0);
$dateFrom = trim($_GET['date_from'] ?? date('Y-m-01'));
$dateTo = trim($_GET['date_to'] ?? date('Y-m-d'));
$search = trim($_GET['search'] ?? '');

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=audit_trail_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    fputcsv($out, ['ID', 'Date & Time', 'User', 'Role', 'Action', 'Entity', 'Details', 'IP Address']);

    $sqlCsv = "SELECT al.*, u.full_name, u.username, r.name AS role_name 
        FROM audit_logs al 
        LEFT JOIN users u ON u.id = al.user_id 
        LEFT JOIN roles r ON r.id = u.role_id 
        WHERE 1=1";
    $p = [];
    if (!empty($dateFrom)) { $sqlCsv .= " AND DATE(al.created_at) >= ?"; $p[] = $dateFrom; }
    if (!empty($dateTo)) { $sqlCsv .= " AND DATE(al.created_at) <= ?"; $p[] = $dateTo; }
    if ($userFilter > 0) { $sqlCsv .= " AND al.user_id = ?"; $p[] = $userFilter; }
    $sqlCsv .= " ORDER BY al.id DESC LIMIT 2000";
    $stmtC = $pdo->prepare($sqlCsv);
    $stmtC->execute($p);
    while ($row = $stmtC->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $row['id'],
            $row['created_at'],
            $row['full_name'] ?: 'System',
            $row['role_name'] ?: '—',
            $row['action'],
            $row['entity_type'] . ($row['entity_id'] ? " #{$row['entity_id']}" : ''),
            $row['details'],
            $row['ip_address']
        ]);
    }
    fclose($out);
    exit;
}

// Build query for table
$sql = "SELECT al.*, u.full_name, u.username, r.name AS role_name 
    FROM audit_logs al 
    LEFT JOIN users u ON u.id = al.user_id 
    LEFT JOIN roles r ON r.id = u.role_id 
    WHERE 1=1";
$params = [];

if (!empty($dateFrom)) {
    $sql .= " AND DATE(al.created_at) >= ?";
    $params[] = $dateFrom;
}
if (!empty($dateTo)) {
    $sql .= " AND DATE(al.created_at) <= ?";
    $params[] = $dateTo;
}
if ($userFilter > 0) {
    $sql .= " AND al.user_id = ?";
    $params[] = $userFilter;
}
if ($actionFilter !== 'all') {
    if ($actionFilter === 'login') {
        $sql .= " AND al.action LIKE '%login%'";
    } elseif ($actionFilter === 'voucher') {
        $sql .= " AND (al.action LIKE '%voucher%' OR al.entity_type = 'voucher')";
    } elseif ($actionFilter === 'settings') {
        $sql .= " AND (al.action LIKE '%setting%' OR al.action LIKE '%reset%' OR al.action LIKE '%danger%' OR al.action LIKE '%password%')";
    } elseif ($actionFilter === 'inventory') {
        $sql .= " AND (al.action LIKE '%inventory%' OR al.entity_type LIKE '%inventory%')";
    }
}
if ($search !== '') {
    $sql .= " AND (al.action LIKE ? OR al.details LIKE ? OR u.full_name LIKE ? OR al.ip_address LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY al.id DESC LIMIT 150";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch users for filter dropdown
$users = $pdo->query("SELECT id, full_name, username FROM users ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../app/sidebar.php';
?>

<div class="main-content">
    <div style="max-width:1200px;margin:0 auto;padding:16px 20px;">

        <!-- Header -->
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;margin-bottom:20px;">
            <div>
                <h1 style="font-size:22px;color:#0f172a;margin:0;display:flex;align-items:center;gap:10px;">
                    <span>🛡️</span>
                    <span>سیکیورٹی آڈٹ ٹریل اور حساس لاگز (Audit Trail &amp; Logs)</span>
                </h1>
                <p style="color:#64748b;font-size:13px;margin:4px 0 0;">
                    تمام اہم مالیاتی و انتظامی تبدیلیوں، واؤچرز منظوری، لاگ ان اور سیٹنگز کی ناقابلِ ترمیم تاریخ
                </p>
            </div>

            <div style="display:flex;gap:8px;">
                <a href="audit_log.php?export=csv&date_from=<?=urlencode($dateFrom)?>&date_to=<?=urlencode($dateTo)?>&user_id=<?=$userFilter?>&action_type=<?=urlencode($actionFilter)?>" class="btn secondary sm" style="padding:8px 14px;font-weight:700;display:inline-flex;align-items:center;gap:6px;text-decoration:none;">
                    📥 Export Excel / CSV
                </a>
                <button type="button" onclick="window.print()" class="btn secondary sm" style="padding:8px 14px;font-weight:700;">
                    🖨️ Print
                </button>
            </div>
        </div>

        <!-- Filter Box -->
        <div style="background:#ffffff;border:1px solid #cbd5e1;border-radius:10px;padding:14px 18px;margin-bottom:20px;box-shadow:0 1px 2px rgba(0,0,0,0.03);">
            <form method="get" action="audit_log.php" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)) auto;gap:12px;align-items:flex-end;">
                <div>
                    <label style="display:block;font-size:11.5px;font-weight:700;color:#334155;margin-bottom:4px;">From Date</label>
                    <input type="date" name="date_from" value="<?=e($dateFrom)?>" style="width:100%;font-size:12.5px;padding:7px;border:1px solid #cbd5e1;border-radius:5px;box-sizing:border-box;">
                </div>
                <div>
                    <label style="display:block;font-size:11.5px;font-weight:700;color:#334155;margin-bottom:4px;">To Date</label>
                    <input type="date" name="date_to" value="<?=e($dateTo)?>" style="width:100%;font-size:12.5px;padding:7px;border:1px solid #cbd5e1;border-radius:5px;box-sizing:border-box;">
                </div>
                <div>
                    <label style="display:block;font-size:11.5px;font-weight:700;color:#334155;margin-bottom:4px;">Action Type</label>
                    <select name="action_type" style="width:100%;font-size:12.5px;padding:7px;border:1px solid #cbd5e1;border-radius:5px;">
                        <option value="all" <?=$actionFilter==='all'?'selected':''?>>تمام سرگرمیاں (All Actions)</option>
                        <option value="login" <?=$actionFilter==='login'?'selected':''?>>لاگ ان / لاگ آؤٹ (Logins)</option>
                        <option value="voucher" <?=$actionFilter==='voucher'?'selected':''?>>واؤچرز و مالیات (Vouchers)</option>
                        <option value="inventory" <?=$actionFilter==='inventory'?'selected':''?>>راشن و انوینٹری (Inventory)</option>
                        <option value="settings" <?=$actionFilter==='settings'?'selected':''?>>سیٹنگز و سیکیورٹی (Security/Danger)</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:11.5px;font-weight:700;color:#334155;margin-bottom:4px;">User / Staff</label>
                    <select name="user_id" style="width:100%;font-size:12.5px;padding:7px;border:1px solid #cbd5e1;border-radius:5px;">
                        <option value="0">-- تمام صارفین (All Users) --</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?=$u['id']?>" <?=$userFilter===(int)$u['id']?'selected':''?>><?=e($u['full_name'])?> (@<?=e($u['username'])?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:11.5px;font-weight:700;color:#334155;margin-bottom:4px;">Search Keyword</label>
                    <input type="text" name="search" value="<?=e($search)?>" placeholder="Search IP, details..." style="width:100%;font-size:12.5px;padding:7px;border:1px solid #cbd5e1;border-radius:5px;box-sizing:border-box;">
                </div>
                <div>
                    <button type="submit" class="btn primary sm" style="padding:8px 18px;font-weight:700;">
                        🔍 فلٹر کریں
                    </button>
                </div>
            </form>
        </div>

        <!-- Audit Trail Table -->
        <div style="background:#ffffff;border:1px solid #cbd5e1;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
            <div style="padding:14px 20px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
                <h3 style="margin:0;font-size:15px;color:#0f172a;">تفصیلی سیکیورٹی لاگ (Showing <?=count($logs)?> events)</h3>
                <span style="font-size:12px;color:#64748b;">آڈٹ لاگ محفوظ و ناقابل ترمیم ہے</span>
            </div>

            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:12.5px;text-align:right;">
                    <thead>
                        <tr style="background:#f1f5f9;color:#334155;border-bottom:1.5px solid #cbd5e1;">
                            <th style="padding:10px 14px;text-align:left;">Date &amp; Time (تاریخ و وقت)</th>
                            <th style="padding:10px 14px;text-align:left;">صارف (User)</th>
                            <th style="padding:10px 14px;text-align:center;">کارروائی (Action)</th>
                            <th style="padding:10px 14px;text-align:left;">Entity</th>
                            <th style="padding:10px 14px;text-align:left;">تفصیلات و تبدیلیاں (Details)</th>
                            <th style="padding:10px 14px;text-align:center;">IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr><td colspan="6" style="text-align:center;padding:24px;color:#94a3b8;">کوئی ریکارڈ نہیں ملا۔</td></tr>
                        <?php else: foreach ($logs as $l): 
                            $act = $l['action'];
                            $badgeClass = 'secondary';
                            if (strpos($act, 'login') !== false) $badgeClass = 'badge-blue';
                            elseif (strpos($act, 'create') !== false || strpos($act, 'approve') !== false || strpos($act, 'in_') !== false) $badgeClass = 'badge-green';
                            elseif (strpos($act, 'delete') !== false || strpos($act, 'reject') !== false || strpos($act, 'reset') !== false) $badgeClass = 'badge-red';
                            elseif (strpos($act, 'edit') !== false || strpos($act, 'update') !== false || strpos($act, 'transfer') !== false) $badgeClass = 'badge-yellow';

                            // Format details
                            $detStr = $l['details'] ?: '—';
                            $detJson = json_decode($detStr, true);
                            if (is_array($detJson)) {
                                $detStr = '';
                                foreach ($detJson as $k => $v) {
                                    $detStr .= "<b>{$k}:</b> " . (is_array($v) ? json_encode($v) : $v) . " &bull; ";
                                }
                                $detStr = rtrim($detStr, " &bull; ");
                            }
                        ?>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:10px 14px;text-align:left;color:#64748b;white-space:nowrap;">
                                <?=date('d M Y, h:i:s A', strtotime($l['created_at']))?>
                            </td>
                            <td style="padding:10px 14px;text-align:left;white-space:nowrap;">
                                <strong style="color:#0f172a;"><?=e($l['full_name'] ?: 'System / Guest')?></strong>
                                <?php if (!empty($l['username'])): ?>
                                    <span style="font-size:11px;color:#64748b;display:block;">@<?=e($l['username'])?> (<?=e($l['role_name'] ?: 'Staff')?>)</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:10px 14px;text-align:center;">
                                <span class="badge <?=$badgeClass?>" style="font-size:10.5px;font-weight:700;">
                                    <?=e($l['action'])?>
                                </span>
                            </td>
                            <td style="padding:10px 14px;text-align:left;color:#334155;white-space:nowrap;">
                                <?=e($l['entity_type'] ?: '—')?> <?= $l['entity_id'] ? ('#'.e($l['entity_id'])) : '' ?>
                            </td>
                            <td style="padding:10px 14px;text-align:left;color:#334155;font-size:12px;line-height:1.5;">
                                <?=$detStr?>
                            </td>
                            <td style="padding:10px 14px;text-align:center;font-family:monospace;font-size:11px;color:#64748b;white-space:nowrap;" dir="ltr">
                                <?=e($l['ip_address'] ?: '—')?>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

