<?php
// Shared Topbar & Sidebar Component
$currentPage = $activePage ?? 'dashboard';
$user = current_user() ?? ['name' => 'User', 'role' => 'Viewer'];
$madrasaName = function_exists('get_setting') ? get_setting('madrasa_name', 'Trust Accounts') : 'Trust Accounts';
$shortBrand = mb_strtoupper(mb_substr($madrasaName, 0, 2));
$isUrdu = function_exists('is_urdu_enabled') ? is_urdu_enabled() : true;

$pendingCount = 0;
if (function_exists('has_permission') && has_permission('voucher.approve')) {
    try {
        $uRegId = function_exists('get_user_region_id') ? get_user_region_id((int)($user['id'] ?? 0)) : 0;
        $isSup = function_exists('is_super_admin') ? is_super_admin() : false;
        if (!$isSup && $uRegId > 0) {
            $pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM vouchers WHERE status = 'draft' AND region_id = {$uRegId}")->fetchColumn();
        } else {
            $pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM vouchers WHERE status = 'draft'")->fetchColumn();
        }
    } catch (Exception $e) {
        $pendingCount = 0;
    }
}
$userId = (int)($user['id'] ?? 0);
$myActiveTasks = function_exists('get_user_pending_tasks_count') ? get_user_pending_tasks_count($userId) : 0;
$activeOnlineUsers = function_exists('get_active_online_users') ? get_active_online_users(25) : [];
$onlineUsersCount = count($activeOnlineUsers);
$recentLoginsList = function_exists('get_recent_login_activities') ? get_recent_login_activities(15) : [];
?>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#1e293b">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Madrasa ERP">
<link rel="apple-touch-icon" href="assets/icon-192.png">
<?php if (!$isUrdu): ?>
<style>
.ur, .ur-sub, div.ur, p.ur { display: none !important; }
.en-only { display: inline !important; }
div.en-only, p.en-only { display: block !important; }
</style>
<script>
(function() {
    if (document.body) {
        document.body.classList.add('hide-urdu');
    } else {
        document.addEventListener('DOMContentLoaded', function() {
            document.body && document.body.classList.add('hide-urdu');
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        function cleanUrduStr(str) {
            if (!str || !/[\u0600-\u06FF]/.test(str)) return str;
            var s = str.trim();

            if (/^[\u0600-\u06FF\s]+\s*-\s*(.+)$/.test(s)) {
                return s.replace(/^[\u0600-\u06FF\s]+\s*-\s*/, 'Cash in Hand - ');
            }

            var prefix = '';
            var pm = s.match(/^([A-Z0-9\-]+(?:\s*[-:]\s*))(.+)$/);
            if (pm) {
                prefix = pm[1];
                s = pm[2].trim();
            }

            var m1 = s.match(/^[\u0600-\u06FF\s\d\/\-،؛_&]+\s*\(([^)]*[a-zA-Z][^)]*)\)$/);
            if (m1) {
                return prefix + m1[1].trim();
            }

            var m2 = s.match(/^([a-zA-Z\d\s\/\-_&.,'"]+)\s*\([\u0600-\u06FF\s\d\/\-،؛_]+\)$/);
            if (m2) {
                return prefix + m2[1].trim();
            }

            s = s.replace(/\s*\([\u0600-\u06FF\s،؛ـ\/\.\-–—:0-9]+\)/g, '');
            s = s.replace(/نقدی کیش/g, 'Cash in Hand');

            return prefix + s.trim();
        }

        function walkText(node) {
            if (node.nodeType === Node.TEXT_NODE) {
                if (node.nodeValue && /[\u0600-\u06FF]/.test(node.nodeValue)) {
                    node.nodeValue = cleanUrduStr(node.nodeValue);
                }
            } else if (node.nodeType === Node.ELEMENT_NODE) {
                var tag = node.tagName.toLowerCase();
                if (tag === 'script' || tag === 'style' || tag === 'textarea' || tag === 'input') return;
                var children = node.childNodes;
                for (var i = 0; i < children.length; i++) {
                    walkText(children[i]);
                }
            }
        }

        if (document.body) {
            walkText(document.body);
        }

        var options = document.querySelectorAll('option');
        for (var j = 0; j < options.length; j++) {
            if (options[j].text && /[\u0600-\u06FF]/.test(options[j].text)) {
                options[j].text = cleanUrduStr(options[j].text);
            }
        }
    });
})();
</script>
<?php endif; ?>
<!-- Top Horizontal Header Bar (Spans 100% full width across the top) -->
<header class="app-top-header" id="appTopHeader">
  <div class="app-header-left">
    <button type="button" class="header-mobile-toggle" id="mobileMenuToggle" onclick="toggleAppMobileSidebar(event)" aria-label="Toggle Navigation Menu">
      <span class="bar"></span>
      <span class="bar"></span>
      <span class="bar"></span>
    </button>
    
    <!-- SoftifyX Technologies Text-Only Prominent Brand -->
    <a href="dashboard.php" class="app-header-brand" title="SoftifyX Technologies · Financial Accounting Software">
      <div class="brand-text-block">
        <div class="brand-heading">
          <span class="brand-softify">Softify</span><span class="brand-x">X</span>
          <span class="brand-tech">Technologies</span>
        </div>
        <div class="brand-subtitle">Financial Accounting Software</div>
      </div>
    </a>
  </div>

  <div class="app-header-right">
    <?php if ($pendingCount > 0 && function_exists('has_permission') && has_permission('voucher.approve')): ?>
      <a href="approvals.php" class="header-pending-pill" title="Pending Approvals">
        <span class="pending-dot"></span>
        <span>Approvals <span class="ur">(منظوری)</span> (<?=$pendingCount?>)</span>
      </a>
    <?php endif; ?>

    <?php if ($myActiveTasks > 0): ?>
      <a href="tasks.php" class="header-pending-pill" style="background:#fef3c7;border-color:#fde68a;color:#92400e;" title="My Assigned Tasks">
        <span class="pending-dot" style="background:#f59e0b;"></span>
        <span>Tasks <span class="ur">(ٹاسکس)</span> (<?=$myActiveTasks?>)</span>
      </a>
    <?php endif; ?>

    <?php 
      $unreadNotifs = function_exists('get_user_unread_notifications') ? get_user_unread_notifications($userId, 5) : [];
      $unreadNotifCount = function_exists('get_user_unread_notifications_count') ? get_user_unread_notifications_count($userId) : 0;
    ?>
    <!-- Login Activity & Device Tracker Pill in Header -->
    <button type="button" class="header-tracker-pill" id="activeUsersTrackerBtn" onclick="toggleActiveUsersTracker(event)" title="Login Activity &amp; Device Tracker — Click to view online users and login history">
      <span class="live-pulse-dot"></span>
      <span style="font-size:13.5px;">👥</span>
      <span class="tracker-text">
        <strong id="activeUsersCountDisplay"><?=$onlineUsersCount?></strong>
        <span class="tracker-label">Online</span>
      </span>
    </button>

    <!-- Notification Bell Pill with Dropdown -->
    <div class="header-notif-wrap" style="position:relative;">
      <button type="button" class="header-notif-btn" id="notifBellBtn" onclick="toggleHeaderNotifDropdown(event)" style="background:#f1f5f9;border:1px solid #cbd5e1;border-radius:20px;padding:6px 12px;display:flex;align-items:center;gap:6px;cursor:pointer;color:#334155;font-weight:600;font-size:12.5px;" title="Notifications & Alerts">
        <span>🔔</span>
        <?php if ($unreadNotifCount > 0): ?>
          <span id="notifBellBadge" style="background:#ef4444;color:#fff;font-size:11px;font-weight:700;padding:1px 6px;border-radius:10px;line-height:1.2;">
            <?=$unreadNotifCount?>
          </span>
        <?php endif; ?>
      </button>

      <!-- Dropdown Panel -->
      <div id="headerNotifDropdown" style="display:none;position:absolute;right:0;top:100%;margin-top:8px;width:330px;background:#fff;border-radius:10px;box-shadow:0 10px 25px rgba(0,0,0,0.18);border:1px solid #e2e8f0;z-index:99999;overflow:hidden;">
        <div style="padding:10px 14px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px;">
          <b style="font-size:13px;color:#0f172a;">🔔 Notifications <span class="ur">(اطلاعات)</span></b>
          <div style="display:flex;gap:6px;align-items:center;">
            <button type="button" onclick="markAllNotificationsReadAjax(event)" style="background:transparent;border:none;cursor:pointer;font-size:11px;color:#2563eb;font-weight:600;padding:2px 4px;" title="Mark all read (پڑھ لیا)">
              ✓ Mark read
            </button>
            <span style="color:#cbd5e1;font-size:11px;">|</span>
            <button type="button" onclick="clearAllNotificationsAjax(event)" style="background:transparent;border:none;cursor:pointer;font-size:11px;color:#dc2626;font-weight:600;padding:2px 4px;" title="Clear all (تمام صاف کریں)">
              🗑️ Clear all
            </button>
          </div>
        </div>
        <div id="headerNotifList" style="max-height:280px;overflow-y:auto;">
          <?php if (empty($unreadNotifs)): ?>
            <div style="padding:16px;text-align:center;font-size:12px;color:#94a3b8;">
              No new notifications.
            </div>
          <?php else: ?>
            <?php foreach ($unreadNotifs as $un): ?>
              <a href="<?=e($un['link_url'] ?: 'tasks.php')?>" style="display:block;padding:10px 14px;border-bottom:1px solid #f1f5f9;text-decoration:none;color:#1e293b;transition:background 0.2s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'">
                <div style="font-size:12.5px;font-weight:700;color:<?=$un['type'] === 'warning' ? '#b91c1c' : '#1e3a8a'?>;margin-bottom:2px;">
                  <?=e($un['title'])?>
                </div>
                <div style="font-size:12px;color:#475569;line-height:1.4;">
                  <?=e(mb_strimwidth($un['message'], 0, 100, '...'))?>
                </div>
                <div style="font-size:10.5px;color:#94a3b8;margin-top:4px;">
                  <?=e(date('d M, h:i A', strtotime($un['created_at'])))?>
                </div>
              </a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Logged-in User Profile on the Right Side of the Header (Links to Change Password) -->
    <a href="change_password.php" class="header-user-pill" style="text-decoration:none;" title="<?=e($user['name'] ?? 'User')?> (<?=e($user['role'] ?? '')?>) - Click to Change Password">
      <span class="header-user-avatar">
        <?=e(mb_strtoupper(mb_substr($user['name'] ?? 'U', 0, 2)))?>
      </span>
      <div class="header-user-text">
        <span class="header-user-name"><?=e($user['name'] ?? 'User')?></span>
        <span class="header-user-role"><?=e($user['role'] ?? 'User')?></span>
      </div>
    </a>

    <!-- Quick Logout Button (Visible directly on both Desktop & Mobile Header) -->
    <a href="logout.php" class="header-signout-btn" title="Sign out / لاگ آؤٹ">
      <span>↪</span>
      <span class="signout-text">Logout</span>
    </a>
  </div>
</header>

<!-- Modal Dialog for Login Activity & Device Tracker -->
<div class="tracker-modal-overlay" id="trackerModalOverlay" onclick="handleTrackerOverlayClick(event)">
  <div class="tracker-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="trackerModalTitle">
    
    <!-- Modal Header -->
    <div class="tracker-modal-header">
      <div class="tracker-modal-title">
        <span style="font-size:24px;background:rgba(255,255,255,0.1);padding:6px;border-radius:8px;">🔒</span>
        <div>
          <h3 id="trackerModalTitle">Login Activity &amp; Device Tracker <span class="ur" style="font-weight:normal;color:#94a3b8;font-size:12.5px;">(لاگ ان و ڈیوائس ٹریکر)</span></h3>
          <p>Real-time audit log of active users, connected devices, IP addresses, and authentication status.</p>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:8px;">
        <button type="button" class="btn secondary sm" id="trackerRefreshBtn" onclick="refreshTrackerData()" style="padding:4px 9px;font-size:12px;background:rgba(255,255,255,0.12);color:#fff;border:none;" title="Refresh live status">
          <span id="trackerRefreshIcon">🔄</span> Refresh
        </button>
        <button type="button" class="tracker-modal-close" onclick="closeActiveUsersTracker()" aria-label="Close">&times;</button>
      </div>
    </div>

    <!-- Quick Stats Bar -->
    <div class="tracker-stats-bar">
      <div class="tracker-stat-item" style="border-left:3px solid #16a34a;">
        <span>Active Users Online (آن لائن)</span>
        <strong style="color:#15803d;" id="modalStatOnline"><?=$onlineUsersCount?> Users</strong>
      </div>
      <div class="tracker-stat-item" style="border-left:3px solid #0284c7;">
        <span>Desktop / PC Logins (ڈیسک ٹاپ)</span>
        <strong style="color:#0369a1;" id="modalStatDesktop">
          <?=count(array_filter($activeOnlineUsers, fn($u) => ($u['device_type'] ?? '') !== 'Mobile'))?>
        </strong>
      </div>
      <div class="tracker-stat-item" style="border-left:3px solid #f59e0b;">
        <span>Mobile / Tablet Logins (موبائل)</span>
        <strong style="color:#b45309;" id="modalStatMobile">
          <?=count(array_filter($activeOnlineUsers, fn($u) => ($u['device_type'] ?? '') === 'Mobile'))?>
        </strong>
      </div>
    </div>

    <!-- Tab Navigation -->
    <div class="tracker-tab-nav">
      <button type="button" class="tracker-tab-btn active" id="tabBtnActiveUsers" onclick="switchTrackerTab('active')">
        🟢 Currently Active Users (<span id="tabCountActive"><?=$onlineUsersCount?></span>)
      </button>
      <button type="button" class="tracker-tab-btn" id="tabBtnRecentLogins" onclick="switchTrackerTab('recent')">
        🕒 Recent Login History (<span id="tabCountRecent"><?=count($recentLoginsList)?></span>)
      </button>
    </div>

    <!-- Tab 1: Currently Active Users Content -->
    <div class="tracker-tab-content" id="trackerTabActive">
      <div id="activeUsersListContainer">
        <?php if (empty($activeOnlineUsers)): ?>
          <div style="text-align:center;padding:30px 10px;color:#94a3b8;">
            <span style="font-size:32px;display:block;margin-bottom:8px;">👥</span>
            <b>No active user sessions found.</b>
          </div>
        <?php else: ?>
          <?php foreach ($activeOnlineUsers as $au): 
            $isMe = ((int)$au['id'] === $userId);
            $mins = (int)($au['minutes_ago'] ?? 0);
            $statusText = ($mins <= 1) ? 'Online Now' : "Active {$mins}m ago";
            $dev = $au['device_type'] ?: 'Desktop';
            $ip = $au['ip_address'] ?: '127.0.0.1';
            $loginTimeFormatted = !empty($au['login_time']) ? date('d M Y, h:i A', strtotime($au['login_time'])) : (!empty($au['last_login_at']) ? date('d M Y, h:i A', strtotime($au['last_login_at'])) : 'Recently');
            $initials = mb_strtoupper(mb_substr($au['full_name'] ?: $au['username'], 0, 2));
          ?>
            <div class="tracker-user-row <?=$isMe ? 'current-user-row' : ''?>">
              <div style="display:flex;align-items:center;gap:12px;min-width:0;">
                <span class="tracker-user-avatar" style="<?=$isMe ? 'background:linear-gradient(135deg, #16a34a, #15803d);' : ''?>">
                  <?=e($initials)?>
                </span>
                <div>
                  <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                    <strong style="color:#0f172a;font-size:14px;"><?=e($au['full_name'] ?: $au['username'])?></strong>
                    <?php if ($isMe): ?>
                      <span class="badge" style="background:#dcfce7;color:#15803d;font-size:10.5px;font-weight:700;padding:1px 6px;border-radius:10px;">(You / آپ خود)</span>
                    <?php endif; ?>
                  </div>
                  <div style="font-size:12px;color:#64748b;margin-top:2px;">
                    @<?=e($au['username'])?> • <span class="badge badge-gray" style="font-size:10.5px;"><?=e(function_exists('get_role_display_name') ? get_role_display_name($au['role_name'] ?: 'User', false) : ($au['role_name'] ?: 'User'))?></span>
                  </div>
                </div>
              </div>

              <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;justify-content:flex-end;">
                <!-- Status -->
                <span class="badge" style="background:#dcfce7;color:#15803d;font-weight:700;font-size:11.5px;display:inline-flex;align-items:center;gap:5px;">
                  <span style="width:6px;height:6px;border-radius:50%;background:#16a34a;display:inline-block;"></span>
                  <?=e($statusText)?>
                </span>

                <!-- Device -->
                <span class="badge badge-blue" style="font-size:11.5px;">
                  <?=($dev === 'Mobile') ? '📱 Mobile' : '💻 Desktop'?>
                </span>

                <!-- IP Address -->
                <span style="font-family:monospace;font-size:11.5px;color:#334155;background:#f1f5f9;padding:3px 7px;border-radius:4px;border:1px solid #e2e8f0;" title="Connected IP Address">
                  <?=e($ip)?>
                </span>

                <!-- Login Time -->
                <div style="font-size:11px;color:#64748b;text-align:right;">
                  <?=e($loginTimeFormatted)?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Tab 2: Recent Login History Content (Matches Screenshot) -->
    <div class="tracker-tab-content" id="trackerTabRecent" style="display:none;">
      <div class="table-wrap">
        <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
          <thead>
            <tr style="background:#f8fafc;border-bottom:1px solid #cbd5e1;text-align:left;">
              <th style="padding:8px 10px;width:30px;">#</th>
              <th style="padding:8px 10px;">USER / STAFF</th>
              <th style="padding:8px 10px;">STATUS</th>
              <th style="padding:8px 10px;">DEVICE</th>
              <th style="padding:8px 10px;">IP ADDRESS</th>
              <th style="padding:8px 10px;text-align:right;">DATE &amp; TIME</th>
            </tr>
          </thead>
          <tbody id="recentLoginsTbody">
            <?php if (empty($recentLoginsList)): ?>
              <tr><td colspan="6" style="text-align:center;padding:20px;color:#94a3b8;">No login records found.</td></tr>
            <?php else: ?>
              <?php foreach ($recentLoginsList as $idx => $act): 
                $isSuccess = ($act['status'] ?? '') === 'success';
                $actInitials = mb_strtoupper(mb_substr($act['full_name'] ?: $act['username'], 0, 2));
              ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                  <td style="padding:10px;color:#64748b;"><?=$idx + 1?></td>
                  <td style="padding:10px;">
                    <div style="display:flex;align-items:center;gap:8px;">
                      <span style="width:28px;height:28px;border-radius:50%;background:#e0f2fe;color:#0369a1;font-weight:700;font-size:11px;display:grid;place-items:center;flex-shrink:0;">
                        <?=e($actInitials)?>
                      </span>
                      <div>
                        <strong style="color:#0f172a;display:block;"><?=e($act['full_name'] ?: $act['username'])?></strong>
                        <span style="font-size:11px;color:#64748b;">@<?=e($act['username'])?> • <?=e(function_exists('get_role_display_name') ? get_role_display_name($act['role_name'] ?: 'Staff', false) : ($act['role_name'] ?: 'Staff'))?></span>
                      </div>
                    </div>
                  </td>
                  <td style="padding:10px;">
                    <?php if ($isSuccess): ?>
                      <span class="badge" style="background:#dcfce7;color:#15803d;font-size:11px;font-weight:700;">• Success</span>
                    <?php else: ?>
                      <span class="badge badge-red" style="font-size:11px;font-weight:700;">• Failed</span>
                    <?php endif; ?>
                  </td>
                  <td style="padding:10px;">
                    <span style="font-size:12px;display:inline-flex;align-items:center;gap:4px;">
                      <?= ($act['device_type'] ?? '') === 'Mobile' ? '📱 Mobile' : '💻 Desktop' ?>
                    </span>
                  </td>
                  <td style="padding:10px;">
                    <code style="font-size:11.5px;color:#334155;background:#f8fafc;padding:2px 6px;border-radius:4px;border:1px solid #e2e8f0;"><?=e($act['ip_address'] ?: '—')?></code>
                  </td>
                  <td style="padding:10px;text-align:right;color:#64748b;font-size:11.5px;white-space:nowrap;">
                    <?=e(date('d M Y, h:i A', strtotime($act['created_at'])))?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal Footer -->
    <div style="padding:12px 20px;background:#f8fafc;border-top:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;font-size:12px;color:#64748b;">
      <div style="display:flex;align-items:center;gap:6px;">
        <span>🛡️</span>
        <span>Live device &amp; session tracking active across all user accounts.</span>
      </div>
      <div>
        <a href="settings.php?tab=security#loginActivitySection" class="btn secondary sm" style="font-size:11.5px;text-decoration:none;" onclick="closeActiveUsersTracker()">
          ⚙️ Open Security Settings &rarr;
        </a>
      </div>
    </div>

  </div>
</div>

<!-- Backdrop overlay for mobile drawer -->
<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeAppMobileSidebar(event)"></div>

<!-- Sidebar Navigation (Positioned BELOW the horizontal header) -->
<aside class="sidebar" id="appSidebar">
  <div class="mobile-sidebar-close-wrap">
    <span class="mobile-drawer-label">Menu <span class="ur">(مینو نیویگیشن)</span></span>
    <button type="button" class="mobile-close-btn" id="mobileMenuClose" onclick="closeAppMobileSidebar(event)" aria-label="Close Menu">&times;</button>
  </div>

  <!-- Mobile User Card with Direct Logout Button at top of Drawer -->
  <div class="mobile-drawer-user-card" style="background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);border-radius:10px;padding:10px 12px;margin-bottom:14px;align-items:center;justify-content:space-between;gap:8px;">
    <div style="display:flex;align-items:center;gap:8px;min-width:0;">
      <span class="header-user-avatar" style="width:34px;height:34px;font-size:12px;flex-shrink:0;">
        <?=e(mb_strtoupper(mb_substr($user['name'] ?? 'U', 0, 2)))?>
      </span>
      <div style="min-width:0;">
        <b style="color:#f8fafc;font-size:12.5px;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?=e($user['name'] ?? 'User')?></b>
        <span style="color:#94a3b8;font-size:11px;display:block;"><?=e($user['role'] ?? 'User')?></span>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:6px;flex-shrink:0;">
      <a href="change_password.php" style="background:rgba(255,255,255,0.1);color:#93c5fd;padding:6px 9px;border-radius:6px;font-size:12px;text-decoration:none;" title="Password">
        🔑
      </a>
      <a href="logout.php" style="background:#dc2626;color:#ffffff;border:none;padding:6px 12px;border-radius:6px;font-size:12px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:4px;" title="Sign out / لاگ آؤٹ">
        ↪ Logout
      </a>
    </div>
  </div>

  <!-- PWA Install Shortcut for Mobile -->
  <button type="button" class="pwa-install-btn" id="mobilePwaInstallBtn" onclick="triggerPwaInstall()" style="display:none;width:100%;margin-bottom:12px;background:linear-gradient(135deg, #16a34a, #15803d);color:#fff;border:none;padding:8px 12px;border-radius:8px;font-size:12px;font-weight:700;align-items:center;justify-content:center;gap:6px;cursor:pointer;box-shadow:0 2px 4px rgba(0,0,0,0.15);">
    📲 Install App on Phone <span class="ur">(موبائل پر ایپ انسٹال کریں)</span>
  </button>

  <nav>
    <a class="<?= $currentPage === 'dashboard' ? 'active' : '' ?>" href="dashboard.php">▦ &nbsp; Dashboard <span class="ur">(ڈیش بورڈ)</span></a>
    
    <span class="nav-label">ACCOUNTING</span>
    <?php if (function_exists('has_permission') && has_permission('voucher.approve')): ?>
      <a class="<?= $currentPage === 'approvals' ? 'active' : '' ?>" href="approvals.php" style="display:flex;justify-content:space-between;align-items:center;">
        <span>✓ &nbsp; Approvals <span class="ur">(منظوری)</span></span>
        <?php if ($pendingCount > 0): ?>
          <span class="sidebar-counter"><?=$pendingCount?></span>
        <?php endif; ?>
      </a>
    <?php endif; ?>

    <?php if (!function_exists('has_permission') || has_permission('income.view') || has_permission('income.create')): ?>
      <a class="<?= $currentPage === 'income' ? 'active' : '' ?>" href="income.php">＋ &nbsp; Income <span class="ur">(آمدنی)</span></a>
    <?php endif; ?>

    <?php if (!function_exists('has_permission') || has_permission('expense.view') || has_permission('expense.create')): ?>
      <a class="<?= $currentPage === 'expenses' ? 'active' : '' ?>" href="expenses.php">－ &nbsp; Expenses <span class="ur">(اخراجات)</span></a>
    <?php endif; ?>

    <?php if (!function_exists('has_permission') || has_permission('budgets.view') || has_permission('budgets.manage')): ?>
      <a class="<?= $currentPage === 'budgets' ? 'active' : '' ?>" href="budgets.php">📊 &nbsp; Budgets &amp; Limits <span class="ur">(ماہانہ بجٹ)</span></a>
    <?php endif; ?>

    <?php if (!function_exists('has_permission') || has_permission('donors.view') || has_permission('donors.manage')): ?>
      <a class="<?= $currentPage === 'donors' ? 'active' : '' ?>" href="donors.php">◎ &nbsp; <?=is_madrasa_mode() ? 'Donors & Patrons <span class="ur">(معاونین و سرپرست)</span>' : 'Donors <span class="ur">(معاونین و ڈونرز)</span>'?></a>
    <?php endif; ?>

    <?php if (!function_exists('has_permission') || has_permission('campaigns.view') || has_permission('campaigns.manage')): ?>
      <a class="<?= $currentPage === 'campaigns' ? 'active' : '' ?>" href="campaigns.php">📢 &nbsp; Campaigns &amp; Appeals <span class="ur">(رمضان و زکوٰۃ مہمات)</span></a>
    <?php endif; ?>

    <?php if (!function_exists('has_permission') || has_permission('beneficiaries.view') || has_permission('beneficiaries.manage')): ?>
      <a class="<?= $currentPage === 'beneficiaries' ? 'active' : '' ?>" href="beneficiaries.php">🎓 &nbsp; <?=is_madrasa_mode() ? 'Students & Beneficiaries <span class="ur">(طلبہ و مستحقین)</span>' : 'Beneficiaries & Welfare <span class="ur">(مستحقین و راشن)</span>'?></a>
    <?php endif; ?>

    <?php if (!function_exists('has_permission') || has_permission('inventory.view') || has_permission('inventory.manage')): ?>
      <a class="<?= $currentPage === 'inventory' ? 'active' : '' ?>" href="inventory.php">📦 &nbsp; Inventory &amp; Ration <span class="ur">(راشن و سامان اسٹاک)</span></a>
    <?php endif; ?>

    <a class="<?= $currentPage === 'zakat_calculator' ? 'active' : '' ?>" href="zakat_calculator.php">🧮 &nbsp; Zakat Calculator <span class="ur">(زکوٰۃ کیلکولیٹر)</span></a>

    <?php if (!function_exists('has_permission') || has_permission('funds.view') || has_permission('funds.manage')): ?>
      <a class="<?= $currentPage === 'funds' ? 'active' : '' ?>" href="funds.php">▤ &nbsp; <?=is_madrasa_mode() ? 'Funds & Depts <span class="ur">(فنڈز و شعبہ جات)</span>' : 'Funds & Projects <span class="ur">(فنڈز و پروجیکٹس)</span>'?></a>
    <?php endif; ?>

    <?php if (!function_exists('has_permission') || has_permission('accounts.view') || has_permission('accounts.manage')): ?>
      <a class="<?= $currentPage === 'cash_bank' ? 'active' : '' ?>" href="cash_bank.php">▣ &nbsp; Cash & Bank <span class="ur">(کھاتے)</span></a>
    <?php endif; ?>

    <?php if (!function_exists('has_permission') || has_permission('reports.view')): ?>
      <a class="<?= $currentPage === 'reports' ? 'active' : '' ?>" href="reports.php">▥ &nbsp; Reports <span class="ur">(رپورٹس)</span></a>
    <?php endif; ?>

    <?php if (!function_exists('has_permission') || has_permission('tasks.view')): ?>
      <a class="<?= $currentPage === 'tasks' ? 'active' : '' ?>" href="tasks.php" style="display:flex;justify-content:space-between;align-items:center;">
        <span>📋 &nbsp; Tasks & Activities <span class="ur">(سرگرمیاں و ٹاسکس)</span></span>
        <?php if ($myActiveTasks > 0): ?>
          <span class="sidebar-counter" style="background:#f59e0b;"><?=$myActiveTasks?></span>
        <?php endif; ?>
      </a>
    <?php endif; ?>
    
    <?php if (!function_exists('has_permission') || has_permission('users.manage') || has_permission('team.manage') || has_permission('settings.manage')): ?>
      <span class="nav-label">ADMINISTRATION</span>
      <?php if (!function_exists('has_permission') || has_permission('users.manage') || has_permission('team.manage')): ?>
        <a class="<?= $currentPage === 'users' ? 'active' : '' ?>" href="users.php">♙ &nbsp; <?=(function_exists('is_regional_director') && is_regional_director()) ? (is_madrasa_mode() ? 'My Madrasa Team <span class="ur">(مدرسہ ٹیم)</span>' : 'My Regional Team <span class="ur">(صوبائی ٹیم)</span>') : (is_madrasa_mode() ? 'Users & Branches <span class="ur">(اساتذہ و شاخیں)</span>' : 'Users & Provinces <span class="ur">(صارفین و برانچز)</span>')?></a>
      <?php endif; ?>
      <?php if (!function_exists('has_permission') || has_permission('audit.view') || (function_exists('is_super_admin') && is_super_admin())): ?>
        <a class="<?= $currentPage === 'audit_log' ? 'active' : '' ?>" href="audit_log.php">🛡️ &nbsp; Audit Trail &amp; Logs <span class="ur">(سیکیورٹی لاگز)</span></a>
      <?php endif; ?>
      <?php if (!function_exists('has_permission') || has_permission('settings.manage')): ?>
        <a class="<?= $currentPage === 'settings' ? 'active' : '' ?>" href="settings.php">⚙ &nbsp; <?=is_madrasa_mode() ? 'Madrasa Settings <span class="ur">(مدرسہ سیٹنگز)</span>' : 'Settings <span class="ur">(سیٹنگز)</span>'?></a>
      <?php endif; ?>
      <?php if (!empty($isSuperAdmin)): ?>
        <a class="<?= $currentPage === 'brochure' ? 'active' : '' ?>" href="brochure.php" style="color:#fde047;border-left:3px solid #facc15;background:rgba(234,179,8,0.12);display:flex;align-items:center;justify-content:space-between;" title="SoftifyX Marketing Brochure & PDF WhatsApp Dispatcher">
          <span>⭐ &nbsp; Marketing Brochure <span class="ur">(سافٹ ویئر بروشر)</span></span>
          <span style="font-size:9.5px;background:#eab308;color:#0f172a;padding:1px 6px;border-radius:4px;font-weight:800;">PRO</span>
        </a>
        <a class="<?= $currentPage === 'certificate' ? 'active' : '' ?>" href="certificate.php" style="color:#6ee7b7;border-left:3px solid #10b981;background:rgba(16,185,129,0.12);display:flex;align-items:center;justify-content:space-between;" title="Honorary Certificate of Excellence & Leadership">
          <span>🎖️ &nbsp; Certificate <span class="ur">(اعزازی سندِ شرف)</span></span>
          <span style="font-size:9.5px;background:#10b981;color:#064e3b;padding:1px 6px;border-radius:4px;font-weight:800;">1000★</span>
        </a>
      <?php endif; ?>
      <a href="javascript:void(0)" onclick="openAboutSoftwareModal()" style="display:flex;align-items:center;justify-content:space-between;">
        <span>ℹ &nbsp; About ERP <span class="ur">(سافٹ ویئر تعارف)</span></span>
        <span style="font-size:9.5px;background:rgba(59,130,246,0.3);color:#bfdbfe;padding:1px 5px;border-radius:4px;font-weight:700;">v2.9</span>
      </a>
    <?php endif; ?>
  </nav>

  <div class="sidebar-bottom">
    <button type="button" class="pwa-install-btn" id="sidebarPwaInstallBtn" onclick="triggerPwaInstall()" style="display:none;width:100%;margin-bottom:8px;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.15);color:#93c5fd;padding:6px 10px;border-radius:6px;font-size:11px;font-weight:600;align-items:center;justify-content:center;gap:6px;cursor:pointer;">
      📲 Install App <span class="ur">(ایپ انسٹال کریں)</span>
    </button>
    <button type="button" onclick="openAboutSoftwareModal()" style="width:100%;margin-bottom:8px;background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.14);color:#e2e8f0;padding:6px 10px;border-radius:6px;font-size:11.5px;font-weight:600;display:flex;align-items:center;justify-content:space-between;cursor:pointer;transition:all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.14)'" onmouseout="this.style.background='rgba(255,255,255,0.07)'" title="Software Information & License Details">
      <span style="display:inline-flex;align-items:center;gap:6px;">
        <span style="font-size:13px;">ℹ️</span>
        <span>About &amp; License <span class="ur">(تفصیلات)</span></span>
      </span>
      <span style="font-size:10px;background:#2563eb;color:#ffffff;padding:2px 6px;border-radius:10px;font-weight:700;">v2.9</span>
    </button>
    <div style="font-weight:600;font-size:12px;color:#f8fafc;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?=e($madrasaName)?>">
      <?=e(mb_strimwidth($madrasaName, 0, 22, '...'))?>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px;padding-top:6px;border-top:1px dashed rgba(255,255,255,0.15);gap:8px;">
      <a href="change_password.php" style="font-size:11px;color:#93c5fd;text-decoration:none;display:inline-flex;align-items:center;gap:3px;" title="Change Password / پاس ورڈ تبدیل کریں">
        🔑 <span>Password</span>
      </a>
      <a href="logout.php" class="sidebar-logout-btn" title="Sign out / لاگ آؤٹ">↪ Logout <span class="ur">(لاگ آؤٹ)</span></a>
    </div>
  </div>
</aside>

<?php
$lastAutoBackup = function_exists('get_setting') ? get_setting('last_auto_backup_at', '') : '';
if (!empty($lastAutoBackup)) {
    $lastBackupDisplay = date('d M Y, h:i A', strtotime($lastAutoBackup));
    $backupDay = date('d', strtotime($lastAutoBackup));
    $backupYear = date('Y', strtotime($lastAutoBackup));
    $monthsUrdu = [
        '01' => 'جنوری', '02' => 'فروری', '03' => 'مارچ', '04' => 'اپریل',
        '05' => 'مئی', '06' => 'جون', '07' => 'جولائی', '08' => 'اگست',
        '09' => 'ستمبر', '10' => 'اکتوبر', '11' => 'نومبر', '12' => 'دسمبر'
    ];
    $mNum = date('m', strtotime($lastAutoBackup));
    $mUrdu = $monthsUrdu[$mNum] ?? date('M', strtotime($lastAutoBackup));
    $timeStr = date('h:i A', strtotime($lastAutoBackup));
    $lastBackupDisplayUrdu = "{$backupDay} {$mUrdu} {$backupYear}، {$timeStr}";
} else {
    $bFiles = function_exists('get_saved_backups_list') ? get_saved_backups_list() : [];
    if (!empty($bFiles)) {
        $first = $bFiles[0];
        $fTime = $first['created_at'] ?? '';
        $lastBackupDisplay = !empty($fTime) ? date('d M Y, h:i A', strtotime($fTime)) : date('d M Y');
        $lastBackupDisplayUrdu = $lastBackupDisplay;
    } else {
        $lastBackupDisplay = date('d M Y, 04:00 AM');
        $lastBackupDisplayUrdu = date('d') . ' نومبر ' . date('Y') . '، 04:00 AM (محفوظ و شیڈول)';
    }
}
$isMadrasaMode = function_exists('is_madrasa_mode') && is_madrasa_mode();
$licHash = strtoupper(substr(md5(($madrasaName ?: 'Trust') . '2026_softifyx'), 0, 8));
$licenseKeyDisplay = 'SFX-TR-2026-' . substr($licHash, 0, 4) . '-' . substr($licHash, 4, 4);
$phpVer = PHP_VERSION;
?>
<!-- Modal Dialog for About Software, License & Contact Info -->
<div id="aboutSoftwareModal" class="tracker-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.65);z-index:999999;align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(4px);" onclick="handleAboutOverlayClick(event)">
  <div style="background:#ffffff;border-radius:14px;width:100%;max-width:600px;box-shadow:0 25px 40px -10px rgba(0,0,0,0.35);overflow:hidden;border:1px solid #cbd5e1;" role="dialog" aria-modal="true">
    
    <!-- Modal Header -->
    <div style="padding:16px 20px;background:linear-gradient(135deg, #0f172a 0%, #1e293b 100%);color:#ffffff;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid rgba(255,255,255,0.1);">
      <div style="display:flex;align-items:center;gap:12px;">
        <span style="font-size:24px;background:rgba(255,255,255,0.12);padding:6px 10px;border-radius:8px;">ℹ️</span>
        <div>
          <h3 style="margin:0;font-size:16px;font-weight:700;color:#ffffff;display:flex;align-items:center;gap:8px;">
            <span>SoftifyX Technologies</span>
            <span style="font-size:10px;background:#2563eb;color:#ffffff;padding:2px 8px;border-radius:10px;font-weight:700;">ERP v2.9</span>
          </h3>
          <p style="margin:2px 0 0;font-size:12px;color:#94a3b8;">Enterprise Accounting &amp; Institutional ERP</p>
        </div>
      </div>
      <button type="button" onclick="closeAboutSoftwareModal()" style="background:transparent;border:none;color:#94a3b8;font-size:24px;cursor:pointer;line-height:1;" title="Close">&times;</button>
    </div>

    <!-- Navigation Tabs -->
    <div style="display:flex;background:#f8fafc;border-bottom:1.5px solid #e2e8f0;padding:4px 12px 0;overflow-x:auto;">
      <button type="button" id="aboutTabBtn_info" onclick="switchAboutTab('info')" style="padding:10px 13px;font-size:12px;font-weight:700;border:none;background:transparent;color:#2563eb;border-bottom:3px solid #2563eb;cursor:pointer;display:inline-flex;align-items:center;gap:5px;white-space:nowrap;">
        💻 Software Info
      </button>
      <button type="button" id="aboutTabBtn_license" onclick="switchAboutTab('license')" style="padding:10px 13px;font-size:12px;font-weight:700;border:none;background:transparent;color:#64748b;border-bottom:3px solid transparent;cursor:pointer;display:inline-flex;align-items:center;gap:5px;white-space:nowrap;">
        📜 License Details
      </button>
      <button type="button" id="aboutTabBtn_guide" onclick="switchAboutTab('guide')" style="padding:10px 13px;font-size:12px;font-weight:700;border:none;background:transparent;color:#64748b;border-bottom:3px solid transparent;cursor:pointer;display:inline-flex;align-items:center;gap:5px;white-space:nowrap;">
        📖 User Guide <span class="ur">(فوری رہنمائی)</span>
      </button>
      <button type="button" id="aboutTabBtn_contact" onclick="switchAboutTab('contact')" style="padding:10px 13px;font-size:12px;font-weight:700;border:none;background:transparent;color:#64748b;border-bottom:3px solid transparent;cursor:pointer;display:inline-flex;align-items:center;gap:5px;white-space:nowrap;">
        📞 Contact Info
      </button>
    </div>

    <!-- Tab 1: Software Information -->
    <div id="aboutTabContent_info" style="padding:20px;display:block;">
      <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 14px;margin-bottom:14px;display:flex;align-items:center;gap:12px;">
        <span style="font-size:28px;">⚡</span>
        <div>
          <b style="color:#1e3a8a;font-size:14px;">SoftifyX Financial Accounting ERP 2026</b>
          <div style="font-size:12px;color:#1e40af;margin-top:2px;">
            Dual Engine Architecture for Islamic Seminaries, Mosques &amp; Non-Profit Trusts
          </div>
        </div>
      </div>

      <!-- System Health & Automatic Backup Status Card (Bonus Suggestion 1) -->
      <div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px;padding:12px 16px;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
        <div style="display:flex;align-items:center;gap:12px;">
          <span style="font-size:26px;">🛡️</span>
          <div>
            <div style="font-size:11px;color:#15803d;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">System Health &amp; Safety Status (سسٹم ہیلتھ):</div>
            <b style="color:#166534;font-size:13.5px;">100% فعال و محفوظ (Active &amp; Secure)</b>
            <div style="font-size:12px;color:#047857;margin-top:2px;">
              💾 <strong>آخری خودکار بیک اپ:</strong> <span style="background:#dcfce7;color:#166534;padding:2px 7px;border-radius:4px;font-weight:700;"><?=e($lastBackupDisplayUrdu)?></span>
            </div>
          </div>
        </div>
        <span class="badge" style="background:#15803d;color:#ffffff;font-size:11px;padding:4px 10px;border-radius:12px;font-weight:700;">
          ✓ ڈیٹا مکمل محفوظ ہے
        </span>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:12.5px;">
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:8px 12px;">
          <span style="color:#64748b;font-size:11px;display:block;">Version &amp; Release:</span>
          <b style="color:#0f172a;">v2.9 (Enterprise Edition)</b>
        </div>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:8px 12px;">
          <span style="color:#64748b;font-size:11px;display:block;">Active Mode <span class="ur">(فعال موڈ)</span>:</span>
          <b style="color:#0284c7;"><?=$isMadrasaMode ? 'Madrasa Mode (مدرسہ)' : 'Trust Mode (ٹرسٹ)'?></b>
        </div>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:8px 12px;">
          <span style="color:#64748b;font-size:11px;display:block;">Server &amp; PHP:</span>
          <b style="color:#0f172a;">PHP <?=$phpVer?> (Fast Vanilla)</b>
        </div>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:8px 12px;">
          <span style="color:#64748b;font-size:11px;display:block;">Daily Auto-Backup:</span>
          <b style="color:#15803d;"><?=$lastBackupDisplay?></b>
        </div>
      </div>

      <div style="margin-top:14px;border-top:1px solid #e2e8f0;padding-top:12px;">
        <span style="font-size:12px;font-weight:700;color:#334155;display:block;margin-bottom:6px;">
          Active Core Modules <span class="ur">(فعال شدہ ماڈیولز)</span>:
        </span>
        <div style="display:flex;flex-wrap:wrap;gap:6px;">
          <span class="badge" style="background:#f1f5f9;color:#334155;font-size:11px;">Double Entry Accounting</span>
          <span class="badge" style="background:#f1f5f9;color:#334155;font-size:11px;">Online Donations &amp; Slips</span>
          <span class="badge" style="background:#f1f5f9;color:#334155;font-size:11px;">Pledges &amp; Kafalat Tracker</span>
          <span class="badge" style="background:#f1f5f9;color:#334155;font-size:11px;">Smart WhatsApp Receipts &amp; Pay Slips</span>
          <span class="badge" style="background:#f1f5f9;color:#334155;font-size:11px;">Cash Handover &amp; Deposit Slips</span>
          <span class="badge" style="background:#f1f5f9;color:#334155;font-size:11px;">Staff Payroll &amp; Attendance</span>
          <span class="badge" style="background:#f1f5f9;color:#334155;font-size:11px;">Budgets &amp; Limits Control</span>
          <span class="badge" style="background:#f1f5f9;color:#334155;font-size:11px;">In-Kind Ration Inventory</span>
          <span class="badge" style="background:#f1f5f9;color:#334155;font-size:11px;">Seasonal Campaigns</span>
          <span class="badge" style="background:#f1f5f9;color:#334155;font-size:11px;">Public Donor Verification</span>
          <span class="badge" style="background:#f1f5f9;color:#334155;font-size:11px;">Audit Trail &amp; 2FA PIN</span>
        </div>
      </div>

      <?php if (!empty($isSuperAdmin)): ?>
      <div style="margin-top:16px;background:linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);border:1.5px solid #fcd34d;border-radius:10px;padding:12px 16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
        <div style="display:flex;align-items:center;gap:10px;">
          <span style="font-size:26px;">📑</span>
          <div>
            <div style="font-size:11px;color:#92400e;font-weight:700;text-transform:uppercase;">SoftifyX Marketing &amp; Sales Suite</div>
            <strong style="color:#78350f;font-size:13.5px;">آفیشل سافٹ ویئر بروشر، فیچرز اور واٹس ایپ پچ</strong>
            <div style="font-size:11.5px;color:#b45309;margin-top:2px;">گاہکوں اور مدارس کو بھیجنے کے لیے 1-کلک واٹس ایپ اور خوبصورت PDF ڈاؤنلوڈر</div>
          </div>
        </div>
        <a href="brochure.php" target="_blank" class="btn sm" style="background:#d97706;color:#ffffff;text-decoration:none;font-weight:700;padding:7px 14px;border-radius:6px;display:inline-flex;align-items:center;gap:6px;font-size:12px;box-shadow:0 2px 4px rgba(217,119,6,0.25);">
          📄 بروشر کھولیں / شیئر کریں &rarr;
        </a>
      </div>
      <?php endif; ?>
    </div>

    <!-- Tab 2: License Details -->
    <div id="aboutTabContent_license" style="padding:20px;display:none;">
      <div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;padding:12px 14px;margin-bottom:16px;display:flex;align-items:center;gap:12px;">
        <span style="font-size:28px;">📜</span>
        <div>
          <b style="color:#065f46;font-size:14px;">Licensed &amp; Verified Institutional Installation</b>
          <div style="font-size:12px;color:#047857;margin-top:2px;">
            Authorized deployment under SoftifyX Enterprise Agreement
          </div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr;gap:10px;font-size:12.5px;">
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px 14px;">
          <span style="color:#64748b;font-size:11px;display:block;">Licensed To <span class="ur">(کس کے نام جاری ہے)</span>:</span>
          <b style="color:#0f172a;font-size:14px;"><?=e($madrasaName)?></b>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:8px 12px;">
            <span style="color:#64748b;font-size:11px;display:block;">License Type:</span>
            <b style="color:#15803d;">Perpetual Enterprise (Lifetime)</b>
          </div>
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:8px 12px;">
            <span style="color:#64748b;font-size:11px;display:block;">Authorized Users:</span>
            <b style="color:#0f172a;">Unlimited Multi-Branch</b>
          </div>
        </div>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:8px 12px;">
          <span style="color:#64748b;font-size:11px;display:block;">Product License Key <span class="ur">(لائسنس کی)</span>:</span>
          <code style="font-family:monospace;color:#1e40af;font-size:13px;font-weight:700;"><?=$licenseKeyDisplay?></code>
        </div>
      </div>

      <div style="margin-top:14px;background:#fefce8;border:1px solid #fef08a;border-radius:6px;padding:10px 12px;font-size:12px;color:#713f12;line-height:1.5;">
        <strong>🔒 ڈیٹا ملکیت اور رازداری کی ضمانت:</strong><br>
        آپ کے ادارے کے تمام مالیاتی، اکاؤنٹنگ، عطیات اور طلباء کے کوائف 100% آپ کی ملکیت ہیں اور کسی بھی بیرونی سرور یا تھرڈ پارٹی سے مکمل طور پر محفوظ ہیں۔
      </div>
    </div>

    <!-- Tab 3: User Guide & Quick Help (Interactive Accordion FAQ Style) -->
    <div id="aboutTabContent_guide" style="padding:16px 20px;display:none;max-height:450px;overflow-y:auto;direction:rtl;text-align:right;">
      <div style="background:#f0f9ff;border:1.5px solid #bae6fd;border-radius:10px;padding:12px 14px;margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;direction:rtl;">
        <div style="display:flex;align-items:center;gap:10px;">
          <span style="font-size:24px;">📖</span>
          <div>
            <b style="color:#0369a1;font-size:13.5px;">سافٹ ویئر استعمال کرنے کی رہنمائی و سوالات (User Guide &amp; FAQ)</b>
            <div style="font-size:11.5px;color:#0284c7;margin-top:2px;">
              کسی بھی سوال یا عنوان پر کلک کریں تاکہ اس کا تفصیلی جواب کھل جائے
            </div>
          </div>
        </div>
        <div style="display:flex;gap:6px;">
          <button type="button" class="btn secondary sm" onclick="expandAllGuideFaqs()" style="font-size:11px;padding:4px 9px;cursor:pointer;border-radius:4px;" title="تمام سوالات کھولیں">سب کھولیں</button>
          <button type="button" class="btn secondary sm" onclick="collapseAllGuideFaqs()" style="font-size:11px;padding:4px 9px;cursor:pointer;border-radius:4px;" title="تمام سوالات بند کریں">سب بند کریں</button>
        </div>
      </div>

      <!-- Quick Search Filter -->
      <div style="margin-bottom:12px;">
        <input type="text" id="guideSearchInput" placeholder="🔍 سوال یا رہنمائی تلاش کریں (جیسے: رسید، خرچ، کفالت، کھاتہ، بیک اپ)..." onkeyup="filterGuideFaqs(this.value)" style="width:100%;padding:8px 12px;border:1.5px solid #cbd5e1;border-radius:6px;font-size:12.5px;box-sizing:border-box;direction:rtl;text-align:right;">
      </div>

      <!-- Accordion FAQ List -->
      <div id="guideAccordionList" style="display:flex;flex-direction:column;gap:8px;">
        
        <!-- FAQ 1 -->
        <div class="ug-accordion-item" style="border:1.5px solid #e2e8f0;border-radius:8px;background:#ffffff;overflow:hidden;transition:all 0.2s;">
          <div class="ug-accordion-header" onclick="toggleUserGuideFaq(this)" style="padding:11px 14px;background:#f8fafc;cursor:pointer;display:flex;justify-content:space-between;align-items:center;user-select:none;transition:background 0.2s;">
            <div style="display:flex;align-items:center;gap:10px;">
              <span style="font-size:16px;">💰</span>
              <strong style="color:#0f172a;font-size:13px;">1. آمدنی (Income) اور عطیات کی رسید کیسے جاری کریں؟</strong>
            </div>
            <span class="ug-accordion-icon" style="font-size:13px;color:#64748b;font-weight:bold;margin-right:8px;">➕</span>
          </div>
          <div class="ug-accordion-body" style="display:none;padding:12px 16px;border-top:1px solid #e2e8f0;background:#fcfdfe;color:#334155;font-size:12.5px;line-height:1.7;">
            • بائیں مینو میں <b>+ Income</b> پر کلک فرمائیں۔<br>
            • معاون (ڈونر) کا نام، وصول شدہ رقم، متعلقہ فنڈ (عمومی، زکوٰۃ، راشن، تعمیرات وغیرہ) اور جمع کھاتہ (کیش ان ہینڈ یا بینک اکاؤنٹ) منتخب کریں۔<br>
            • <b>Save &amp; Post Receipt</b> پر کلک کرتے ہی باقاعدہ واؤچر و رسید جاری ہو جائے گی۔<br>
            • رسید جاری ہوتے ہی دو بٹن ملیں گے: <b>🖨️ Print Slip</b> (کمپیوٹرائزڈ رسید فوری پرنٹ کرنے کے لیے) اور <b>📲 Send WhatsApp</b> (ڈونر کو موبائل پر دعائیہ رسید کا لنک بھیجنے کے لیے)۔
          </div>
        </div>

        <!-- FAQ 2 -->
        <div class="ug-accordion-item" style="border:1.5px solid #e2e8f0;border-radius:8px;background:#ffffff;overflow:hidden;transition:all 0.2s;">
          <div class="ug-accordion-header" onclick="toggleUserGuideFaq(this)" style="padding:11px 14px;background:#f8fafc;cursor:pointer;display:flex;justify-content:space-between;align-items:center;user-select:none;transition:background 0.2s;">
            <div style="display:flex;align-items:center;gap:10px;">
              <span style="font-size:16px;">💸</span>
              <strong style="color:#0f172a;font-size:13px;">2. اخراجات (Expenses) اور ادائیگی کا واؤچر کیسے بنائیں؟</strong>
            </div>
            <span class="ug-accordion-icon" style="font-size:13px;color:#64748b;font-weight:bold;margin-right:8px;">➕</span>
          </div>
          <div class="ug-accordion-body" style="display:none;padding:12px 16px;border-top:1px solid #e2e8f0;background:#fcfdfe;color:#334155;font-size:12.5px;line-height:1.7;">
            • بائیں مینو میں <b>- Expenses</b> پر جائیں۔<br>
            • خرچ کی مد / اکاؤنٹ ہیڈ منتخب کریں (جیسے اساتذہ و ملازمین تنخواہ، راشن و خوراک، بلز، سفری اخراجات، تعمیری سامان وغیرہ)۔<br>
            • ادا شدہ رقم، تاریخ اور تفصیل (Narration) درج کر کے محفوظ فرمائیں۔<br>
            • سسٹم خودکار Payment Voucher بنا دیتا ہے، جسے پرنٹ کر کے خزانچی اور مہتمم / صدر کے دستخط کروا کر فائل میں محفوظ کر لیں۔
          </div>
        </div>

        <!-- FAQ 3 -->
        <div class="ug-accordion-item" style="border:1.5px solid #e2e8f0;border-radius:8px;background:#ffffff;overflow:hidden;transition:all 0.2s;">
          <div class="ug-accordion-header" onclick="toggleUserGuideFaq(this)" style="padding:11px 14px;background:#f8fafc;cursor:pointer;display:flex;justify-content:space-between;align-items:center;user-select:none;transition:background 0.2s;">
            <div style="display:flex;align-items:center;gap:10px;">
              <span style="font-size:16px;">👥</span>
              <strong style="color:#0f172a;font-size:13px;">3. ماہانہ کفالت و اقساط ٹریکر (Pledges Tracker) کا طریقہ کار کیا ہے؟</strong>
            </div>
            <span class="ug-accordion-icon" style="font-size:13px;color:#64748b;font-weight:bold;margin-right:8px;">➕</span>
          </div>
          <div class="ug-accordion-body" style="display:none;padding:12px 16px;border-top:1px solid #e2e8f0;background:#fcfdfe;color:#334155;font-size:12.5px;line-height:1.7;">
            • <b>Donors &amp; Patrons</b> پر جائیں اور <b>Monthly Pledges Tracker</b> ٹیب کھولیں۔<br>
            • یہاں تمام ماہانہ معاونین، ان کی طے شدہ رقم، رواں ماہ موصولی، اور سابقہ بقایا جات خودبخود نظر آئیں گے۔<br>
            • <b>💰 Receive بٹن:</b> اس پر کلک کرنے سے ایک فوری ماڈل کھلتا ہے جہاں صرف 1-کلک میں رسید جاری ہو جاتی ہے۔<br>
            • <b>💬 WhatsApp بٹن:</b> اس پر کلک کر کے آپ 4 سمارٹ ٹیمپلیٹس (شائستہ یاد دہانی، شکریہ و دعا، عمومی اپیل، سالانہ کھاتہ) اپنی مرضی سے ایڈٹ کر کے بھیج سکتے ہیں۔
          </div>
        </div>

        <!-- FAQ 4 -->
        <div class="ug-accordion-item" style="border:1.5px solid #e2e8f0;border-radius:8px;background:#ffffff;overflow:hidden;transition:all 0.2s;">
          <div class="ug-accordion-header" onclick="toggleUserGuideFaq(this)" style="padding:11px 14px;background:#f8fafc;cursor:pointer;display:flex;justify-content:space-between;align-items:center;user-select:none;transition:background 0.2s;">
            <div style="display:flex;align-items:center;gap:10px;">
              <span style="font-size:16px;">📊</span>
              <strong style="color:#0f172a;font-size:13px;">4. ڈونر کا سالانہ باقاعدہ کھاتہ و آڈٹ اسٹیٹمنٹ کیسے پرنٹ کریں؟</strong>
            </div>
            <span class="ug-accordion-icon" style="font-size:13px;color:#64748b;font-weight:bold;margin-right:8px;">➕</span>
          </div>
          <div class="ug-accordion-body" style="display:none;padding:12px 16px;border-top:1px solid #e2e8f0;background:#fcfdfe;color:#334155;font-size:12.5px;line-height:1.7;">
            • ڈونرز لسٹ یا ٹریکر میں کسی بھی معاون کے آگے موجود <b>`📊 کھاتہ`</b> بٹن پر کلک کریں۔<br>
            • ایک باقاعدہ A4 لینڈ اسکیپ / پورٹریٹ اسٹیٹمنٹ کھلے گی جس میں پورے سال یا تاحیات تمام موصولہ عطیات، واؤچر نمبر، تاریخ اور کل رقم درج ہوتی ہے۔<br>
            • اس پر ادارے کا ہیڈر، لوگو، کل میزان اور تصدیقی دستخطی خانے بنے ہوتے ہیں، جو کہ ڈونر کو ٹیکس یا آڈٹ ریکارڈ کے لیے دی جا سکتی ہے۔
          </div>
        </div>

        <!-- FAQ 5 -->
        <div class="ug-accordion-item" style="border:1.5px solid #e2e8f0;border-radius:8px;background:#ffffff;overflow:hidden;transition:all 0.2s;">
          <div class="ug-accordion-header" onclick="toggleUserGuideFaq(this)" style="padding:11px 14px;background:#f8fafc;cursor:pointer;display:flex;justify-content:space-between;align-items:center;user-select:none;transition:background 0.2s;">
            <div style="display:flex;align-items:center;gap:10px;">
              <span style="font-size:16px;">🌐</span>
              <strong style="color:#0f172a;font-size:13px;">5. آن لائن ڈونیشن پورٹل (donate.php) اور بینک سلپس کی تصدیق کا طریقہ:</strong>
            </div>
            <span class="ug-accordion-icon" style="font-size:13px;color:#64748b;font-weight:bold;margin-right:8px;">➕</span>
          </div>
          <div class="ug-accordion-body" style="display:none;padding:12px 16px;border-top:1px solid #e2e8f0;background:#fcfdfe;color:#334155;font-size:12.5px;line-height:1.7;">
            • پبلک ڈونیشن پورٹل کا لنک <b>donate.php</b> ہے، جسے آپ واٹس ایپ گروپس، اسٹیٹس یا فیس بک پر شیئر کر سکتے ہیں۔<br>
            • بیرونی ڈونرز وہاں سے براہ راست عطیہ درج کر کے بینک ڈپازٹ سلپ یا اسکرین شاٹ اپلوڈ کر سکتے ہیں۔<br>
            • اپلوڈ شدہ سلپس <b>Donors &amp; Patrons</b> کے صفحے پر اوپر <b>"غیر تصدیق شدہ آن لائن رسیدیں"</b> کے باکس میں نظر آتی ہیں، جہاں سے آپ سلپ دیکھ کر 1-کلک میں رسید منظور یا مسترد کر سکتے ہیں۔
          </div>
        </div>

        <!-- FAQ 6 -->
        <div class="ug-accordion-item" style="border:1.5px solid #e2e8f0;border-radius:8px;background:#ffffff;overflow:hidden;transition:all 0.2s;">
          <div class="ug-accordion-header" onclick="toggleUserGuideFaq(this)" style="padding:11px 14px;background:#f8fafc;cursor:pointer;display:flex;justify-content:space-between;align-items:center;user-select:none;transition:background 0.2s;">
            <div style="display:flex;align-items:center;gap:10px;">
              <span style="font-size:16px;">🎓</span>
              <strong style="color:#0f172a;font-size:13px;">6. طالب علم یا یتیم کی کفالت کو مخصوص ڈونر سے کیسے منسلک کریں؟</strong>
            </div>
            <span class="ug-accordion-icon" style="font-size:13px;color:#64748b;font-weight:bold;margin-right:8px;">➕</span>
          </div>
          <div class="ug-accordion-body" style="display:none;padding:12px 16px;border-top:1px solid #e2e8f0;background:#fcfdfe;color:#334155;font-size:12.5px;line-height:1.7;">
            • ڈونر بناتے یا ایڈٹ کرتے وقت <b>کفیل برائے طالب علم / یتیم (Sponsored Student)</b> کا انتخاب کریں۔<br>
            • اس سے ڈونر کے نام کے آگے <b>`🎓 کفیل: [طالب علم]`</b> کا بیج آ جائے گا اور واٹس ایپ یاد دہانی یا رسید میں بچے کا نام خودبخود شامل ہو جائے گا۔
          </div>
        </div>

        <!-- FAQ 7 -->
        <div class="ug-accordion-item" style="border:1.5px solid #e2e8f0;border-radius:8px;background:#ffffff;overflow:hidden;transition:all 0.2s;">
          <div class="ug-accordion-header" onclick="toggleUserGuideFaq(this)" style="padding:11px 14px;background:#f8fafc;cursor:pointer;display:flex;justify-content:space-between;align-items:center;user-select:none;transition:background 0.2s;">
            <div style="display:flex;align-items:center;gap:10px;">
              <span style="font-size:16px;">📈</span>
              <strong style="color:#0f172a;font-size:13px;">7. روزنامچہ (Cashbook)، بینک بیلنس اور مالیاتی رپورٹس کیسے دیکھیں؟</strong>
            </div>
            <span class="ug-accordion-icon" style="font-size:13px;color:#64748b;font-weight:bold;margin-right:8px;">➕</span>
          </div>
          <div class="ug-accordion-body" style="display:none;padding:12px 16px;border-top:1px solid #e2e8f0;background:#fcfdfe;color:#334155;font-size:12.5px;line-height:1.7;">
            • بائیں مینو میں <b>Reports</b> پر تشریف لے جائیں۔<br>
            • یہاں <b>Daily Cashbook (روزنامچہ)</b>، <b>Fund Summary (فنڈز بیلنس)</b>، اور <b>Income vs Expense</b> کے گوشوارے دستیاب ہیں۔<br>
            • آپ کسی بھی مخصوص تاریخ یا پورے مہینے کا حساب منتخب کر کے فلٹر اور ایکسل میں ایکسپورٹ بھی کر سکتے ہیں۔
          </div>
        </div>

        <!-- FAQ 8 -->
        <div class="ug-accordion-item" style="border:1.5px solid #e2e8f0;border-radius:8px;background:#ffffff;overflow:hidden;transition:all 0.2s;">
          <div class="ug-accordion-header" onclick="toggleUserGuideFaq(this)" style="padding:11px 14px;background:#f8fafc;cursor:pointer;display:flex;justify-content:space-between;align-items:center;user-select:none;transition:background 0.2s;">
            <div style="display:flex;align-items:center;gap:10px;">
              <span style="font-size:16px;">💾</span>
              <strong style="color:#0f172a;font-size:13px;">8. روزانہ خودکار ڈیٹا بیک اپ اور ڈاؤنلوڈ کا طریقہ کیا ہے؟</strong>
            </div>
            <span class="ug-accordion-icon" style="font-size:13px;color:#64748b;font-weight:bold;margin-right:8px;">➕</span>
          </div>
          <div class="ug-accordion-body" style="display:none;padding:12px 16px;border-top:1px solid #e2e8f0;background:#fcfdfe;color:#334155;font-size:12.5px;line-height:1.7;">
            • سافٹ ویئر ہر رات خودبخود ڈیٹابیس کا مکمل بیک اپ بناتا ہے اور پرانے بیک اپس کو محفوظ رکھتا ہے۔<br>
            • اگر آپ بیک اپ اپنے کمپیوٹر یا یو ایس بی میں محفوظ کرنا چاہیں تو <b>Settings</b> مینو میں جائیں اور <b>Download Database Backup</b> پر کلک فرمائیں۔<br>
            • About ونڈو میں بھی آخری بیک اپ کی تاریخ ہمیشہ ظاہر رہتی ہے۔
          </div>
        </div>

        <!-- FAQ 9 -->
        <div class="ug-accordion-item" style="border:1.5px solid #e2e8f0;border-radius:8px;background:#ffffff;overflow:hidden;transition:all 0.2s;">
          <div class="ug-accordion-header" onclick="toggleUserGuideFaq(this)" style="padding:11px 14px;background:#f8fafc;cursor:pointer;display:flex;justify-content:space-between;align-items:center;user-select:none;transition:background 0.2s;">
            <div style="display:flex;align-items:center;gap:10px;">
              <span style="font-size:16px;">🏢</span>
              <strong style="color:#0f172a;font-size:13px;">9. ٹرسٹ موڈ (Welfare Trust) اور مدرسہ موڈ (Madrasa) میں کیا فرق ہے؟</strong>
            </div>
            <span class="ug-accordion-icon" style="font-size:13px;color:#64748b;font-weight:bold;margin-right:8px;">➕</span>
          </div>
          <div class="ug-accordion-body" style="display:none;padding:12px 16px;border-top:1px solid #e2e8f0;background:#fcfdfe;color:#334155;font-size:12.5px;line-height:1.7;">
            • اگر آپ <b>Welfare Trust Mode</b> میں ہوں تو سسٹم فلاحی فنڈز، راشن تقسیم، یتامیٰ امداد، اور ٹرسٹ ڈائریکٹر کے عہدے دکھاتا ہے۔<br>
            • اگر آپ <b>Madrasa Mode</b> میں ہوں تو سسٹم تعلیمی فنڈز، کتب، طلباء کفالت، اور مہتمم / ناظمِ شاخ کی اصطلاحات استعمال کرتا ہے۔<br>
            • یہ موڈ <b>Settings</b> میں جا کر کسی بھی وقت ایک کلک میں بدلا جا سکتا ہے۔
          </div>
        </div>

        <!-- FAQ 10 -->
        <div class="ug-accordion-item" style="border:1.5px solid #e2e8f0;border-radius:8px;background:#ffffff;overflow:hidden;transition:all 0.2s;">
          <div class="ug-accordion-header" onclick="toggleUserGuideFaq(this)" style="padding:11px 14px;background:#f8fafc;cursor:pointer;display:flex;justify-content:space-between;align-items:center;user-select:none;transition:background 0.2s;">
            <div style="display:flex;align-items:center;gap:10px;">
              <span style="font-size:16px;">🔑</span>
              <strong style="color:#0f172a;font-size:13px;">10. پاس ورڈ تبدیل کرنا اور عملے کے نئے اکاؤنٹس بنانا:</strong>
            </div>
            <span class="ug-accordion-icon" style="font-size:13px;color:#64748b;font-weight:bold;margin-right:8px;">➕</span>
          </div>
          <div class="ug-accordion-body" style="display:none;padding:12px 16px;border-top:1px solid #e2e8f0;background:#fcfdfe;color:#334155;font-size:12.5px;line-height:1.7;">
            • اپنا پاس ورڈ بدلنے کے لیے سائڈبار میں نچلے حصے پر <b>🔑 Password</b> پر کلک کریں۔<br>
            • نئے کیشیئر، اساتذہ یا برانچ منیجر کا اکاؤنٹ بنانے کے لیے <b>Users &amp; Branches</b> پر جائیں اور ان کو رول اور مخصوص فنڈز تفویض فرمائیں۔
          </div>
        </div>

        <!-- FAQ 11 -->
        <div class="ug-accordion-item" style="border:1.5px solid #e2e8f0;border-radius:8px;background:#ffffff;overflow:hidden;transition:all 0.2s;">
          <div class="ug-accordion-header" onclick="toggleUserGuideFaq(this)" style="padding:11px 14px;background:#f8fafc;cursor:pointer;display:flex;justify-content:space-between;align-items:center;user-select:none;transition:background 0.2s;">
            <div style="display:flex;align-items:center;gap:10px;">
              <span style="font-size:16px;">🔍</span>
              <strong style="color:#0f172a;font-size:13px;">11. پرانی یا گمشدہ رسید فوری کیسے تلاش کریں؟</strong>
            </div>
            <span class="ug-accordion-icon" style="font-size:13px;color:#64748b;font-weight:bold;margin-right:8px;">➕</span>
          </div>
          <div class="ug-accordion-body" style="display:none;padding:12px 16px;border-top:1px solid #e2e8f0;background:#fcfdfe;color:#334155;font-size:12.5px;line-height:1.7;">
            • آمدنی یا خرچ کے صفحے پر جائیں اور اوپر سرچ بار میں واؤچر نمبر (جیسے: <code>RV-202611-0001</code>)، ڈونر کا نام یا فون نمبر لکھیں۔ سسٹم فوراً مطلوبہ رسید سامنے لے آئے گا۔
          </div>
        </div>

        <!-- FAQ 12 -->
        <div class="ug-accordion-item" style="border:1.5px solid #e2e8f0;border-radius:8px;background:#ffffff;overflow:hidden;transition:all 0.2s;">
          <div class="ug-accordion-header" onclick="toggleUserGuideFaq(this)" style="padding:11px 14px;background:#f8fafc;cursor:pointer;display:flex;justify-content:space-between;align-items:center;user-select:none;transition:background 0.2s;">
            <div style="display:flex;align-items:center;gap:10px;">
              <span style="font-size:16px;">💼</span>
              <strong style="color:#0f172a;font-size:13px;">12. کیشیئر کے پاس نقد کیش (Cash in Hand) اور بینک میں جمع کروانے (Cash Handover) کا طریقہ:</strong>
            </div>
            <span class="ug-accordion-icon" style="font-size:13px;color:#64748b;font-weight:bold;margin-right:8px;">➕</span>
          </div>
          <div class="ug-accordion-body" style="display:none;padding:12px 16px;border-top:1px solid #e2e8f0;background:#fcfdfe;color:#334155;font-size:12.5px;line-height:1.7;">
            • فیلڈ کیشیئرز جب بھی کوئی چندہ وصول کرتے ہیں یا خرچ درج کرتے ہیں، تو رقم خودکار طور پر کیشیئر کے تفویض کردہ ذاتی کیش کھاتے (Cash in Hand) میں رہتی ہے۔<br>
            • جب کیشیئر یہ نقد رقم بینک (جیسے ABL Gadoon) یا مرکزی دفتر میں جمع کروا دے، تو ڈیش بورڈ پر موجود <b>"📦 بینک / دفتر میں کیش جمع کروائیں"</b> بٹن یا <b>Cash &amp; Bank</b> مینو میں جا کر <b>Transfer</b> منتخب کریں۔<br>
            • کیش جمع ہوتے ہی دوطرفہ تصدیقی <b>تحویلِ نقد و بینک ڈپازٹ سلپ (Cash Handover Slip)</b> تھرمل یا A4 میں پرنٹ کی جا سکتی ہے جس پر کیشیئر اور وصول کنندہ کے دستخط اور بینک ڈپازٹ سلپ کی تصویر منسلک ہوتی ہے۔
          </div>
        </div>

        <!-- FAQ 13 -->
        <div class="ug-accordion-item" style="border:1.5px solid #e2e8f0;border-radius:8px;background:#ffffff;overflow:hidden;transition:all 0.2s;">
          <div class="ug-accordion-header" onclick="toggleUserGuideFaq(this)" style="padding:11px 14px;background:#f8fafc;cursor:pointer;display:flex;justify-content:space-between;align-items:center;user-select:none;transition:background 0.2s;">
            <div style="display:flex;align-items:center;gap:10px;">
              <span style="font-size:16px;">⚠️</span>
              <strong style="color:#0f172a;font-size:13px;">13. کیشیئر کیش ہولڈنگ الرٹ (Max Cash Limit) اور سیکیورٹی کی حد کیسے تبدیل کریں؟</strong>
            </div>
            <span class="ug-accordion-icon" style="font-size:13px;color:#64748b;font-weight:bold;margin-right:8px;">➕</span>
          </div>
          <div class="ug-accordion-body" style="display:none;padding:12px 16px;border-top:1px solid #e2e8f0;background:#fcfdfe;color:#334155;font-size:12.5px;line-height:1.7;">
            • سیکیورٹی اور حفاظت کے پیش نظر سسٹم میں کیشیئر کے پاس نقد رقم رکھنے کی محفوظ حد مقرر ہے (ڈیفالٹ: 50,000 روپے)۔<br>
            • اگر کسی کیشیئر کے پاس نقد کیش اس حد سے تجاوز کر جائے تو کیشیئر کے ڈیش بورڈ اور ایڈمن اسکرین پر فوری وارننگ بینر ظاہر ہوتا ہے تاکہ رقم فوری بینک میں جمع کروائی جائے۔<br>
            • اس حد کو کم یا زیادہ کرنے کے لیے ایڈمن <b>Settings ⚙️ -> Accounting</b> ٹیب میں جائیں اور <b>Cashier Max Cash Limit</b> میں مطلوبہ حد درج کر کے محفوظ فرما لیں۔
          </div>
        </div>

        <!-- FAQ 14 -->
        <div class="ug-accordion-item" style="border:1.5px solid #e2e8f0;border-radius:8px;background:#ffffff;overflow:hidden;transition:all 0.2s;">
          <div class="ug-accordion-header" onclick="toggleUserGuideFaq(this)" style="padding:11px 14px;background:#f8fafc;cursor:pointer;display:flex;justify-content:space-between;align-items:center;user-select:none;transition:background 0.2s;">
            <div style="display:flex;align-items:center;gap:10px;">
              <span style="font-size:16px;">🧾</span>
              <strong style="color:#0f172a;font-size:13px;">14. اساتذہ و عملہ کی ماہانہ تنخواہ سلپ (Monthly Salary Slip) اور واٹس ایپ ڈسپیچ:</strong>
            </div>
            <span class="ug-accordion-icon" style="font-size:13px;color:#64748b;font-weight:bold;margin-right:8px;">➕</span>
          </div>
          <div class="ug-accordion-body" style="display:none;padding:12px 16px;border-top:1px solid #e2e8f0;background:#fcfdfe;color:#334155;font-size:12.5px;line-height:1.7;">
            • <b>Students &amp; Beneficiaries</b> میں <b>Monthly Payroll &amp; Stipend Tracker</b> ٹیب کھولیں۔<br>
            • یہاں تمام اساتذہ و ملازمین (Staff) کی طے شدہ تنخواہیں، زیرِ تعلیم طلبہ کے وظائف اور سابقہ مہینوں کے بقایا جات خودکار حساب کے ساتھ نظر آتے ہیں۔<br>
            • <b>💰 Pay Stipend</b> پر کلک کر کے تنخواہ ادا کرتے ہی واؤچر خودکار طور پر <b>ماہانہ تنخواہ پرچی (Monthly Salary Slip)</b> کے فارمیٹ میں بنتا ہے جس پر ملازم کا نام، عہدہ، شعبہ اور وصولی کے دستخط شامل ہوتے ہیں۔<br>
            • ساتھ ہی <b>📲 Send WhatsApp Pay Slip</b> کے بٹن سے ملازم کے موبائل پر آن لائن تصدیقی پرچی کا لنک فوری روانہ کیا جا سکتا ہے۔
          </div>
        </div>

        <!-- FAQ 15 -->
        <div class="ug-accordion-item" style="border:1.5px solid #e2e8f0;border-radius:8px;background:#ffffff;overflow:hidden;transition:all 0.2s;">
          <div class="ug-accordion-header" onclick="toggleUserGuideFaq(this)" style="padding:11px 14px;background:#f8fafc;cursor:pointer;display:flex;justify-content:space-between;align-items:center;user-select:none;transition:background 0.2s;">
            <div style="display:flex;align-items:center;gap:10px;">
              <span style="font-size:16px;">📲</span>
              <strong style="color:#0f172a;font-size:13px;">15. ڈونرز کو واٹس ایپ پر سالانہ کھاتہ و رسیدیں بغیر لاگ ان دکھانے کا طریقہ:</strong>
            </div>
            <span class="ug-accordion-icon" style="font-size:13px;color:#64748b;font-weight:bold;margin-right:8px;">➕</span>
          </div>
          <div class="ug-accordion-body" style="display:none;padding:12px 16px;border-top:1px solid #e2e8f0;background:#fcfdfe;color:#334155;font-size:12.5px;line-height:1.7;">
            • بیرونی ڈونرز و معاونین کے پاس سافٹ ویئر کا لاگ ان اور پاس ورڈ نہیں ہوتا۔ سسٹم ہر ڈونر کے لیے ایک محفوظ، خفیہ ڈیجیٹل ٹوکن (Public Verification Token) خودکار بناتا ہے۔<br>
            • جب آپ <b>Donors</b> لسٹ یا <b>Pledges Tracker</b> سے ڈونر کو واٹس ایپ پر شکریہ یا سالانہ کھاتے کا میسج بھیجتے ہیں تو اس میں یہ لنک شامل ہوتا ہے۔<br>
            • ڈونر اپنے موبائل سے اس لنک پر کلک کر کے بغیر کسی لاگ ان یا پاس ورڈ کے اپنا تفصیلی سالانہ کھاتہ، رسیدات اور عطیات پی ڈی ایف کے خوبصورت ڈیزائن میں دیکھ اور ڈاؤنلوڈ کر سکتا ہے۔
          </div>
        </div>

        <!-- FAQ 16 -->
        <div class="ug-accordion-item" style="border:1.5px solid #e2e8f0;border-radius:8px;background:#ffffff;overflow:hidden;transition:all 0.2s;">
          <div class="ug-accordion-header" onclick="toggleUserGuideFaq(this)" style="padding:11px 14px;background:#f8fafc;cursor:pointer;display:flex;justify-content:space-between;align-items:center;user-select:none;transition:background 0.2s;">
            <div style="display:flex;align-items:center;gap:10px;">
              <span style="font-size:16px;">🔔</span>
              <strong style="color:#0f172a;font-size:13px;">16. نوٹیفیکیشن بیل (🔔) کے پیغامات پڑھنا اور مکمل صاف (Clear All) کرنا:</strong>
            </div>
            <span class="ug-accordion-icon" style="font-size:13px;color:#64748b;font-weight:bold;margin-right:8px;">➕</span>
          </div>
          <div class="ug-accordion-body" style="display:none;padding:12px 16px;border-top:1px solid #e2e8f0;background:#fcfdfe;color:#334155;font-size:12.5px;line-height:1.7;">
            • اوپر نیویگیشن بار میں نوٹیفیکیشن بیل (🔔) پر نیا ٹاسک یا الرٹ آنے پر ریڈ بیج نظر آتا ہے۔<br>
            • بیل پر کلک کرنے پر ڈراپ ڈاؤن میں دو بٹن دیے گئے ہیں:<br>
            &nbsp;&nbsp;&bull; <b>✓ Mark read:</b> تمام نوٹیفیکیشنز کو پڑھا ہوا قرار دے کر بیج ہٹا دیتا ہے۔<br>
            &nbsp;&nbsp;&bull; <b>🗑️ Clear all:</b> تمام پرانے نوٹیفیکیشنز کو ڈیٹابیس سے مستقل صاف کر دیتا ہے۔<br>
            • ڈیش بورڈ پر ظاہر ہونے والے بینرز کے کونے پر موجود <b>`✕`</b> پر کلک کر کے آپ کسی بھی الرٹ بینر کو عارضی طور پر فوری بند (Dismiss) کر سکتے ہیں۔
          </div>
        </div>

        <!-- FAQ 17 -->
        <div class="ug-accordion-item" style="border:1.5px solid #e2e8f0;border-radius:8px;background:#ffffff;overflow:hidden;transition:all 0.2s;">
          <div class="ug-accordion-header" onclick="toggleUserGuideFaq(this)" style="padding:11px 14px;background:#f8fafc;cursor:pointer;display:flex;justify-content:space-between;align-items:center;user-select:none;transition:background 0.2s;">
            <div style="display:flex;align-items:center;gap:10px;">
              <span style="font-size:16px;">🛡️</span>
              <strong style="color:#0f172a;font-size:13px;">17. ڈیٹا بیس سیکیورٹی، 7 روزہ بیک اپ یاد دہانی اور بحالی (Restore):</strong>
            </div>
            <span class="ug-accordion-icon" style="font-size:13px;color:#64748b;font-weight:bold;margin-right:8px;">➕</span>
          </div>
          <div class="ug-accordion-body" style="display:none;padding:12px 16px;border-top:1px solid #e2e8f0;background:#fcfdfe;color:#334155;font-size:12.5px;line-height:1.7;">
            • اگر آخری بیک اپ لیے 7 دن سے زیادہ ہو جائیں تو ایڈمن ڈیش بورڈ پر اورنج سیکیورٹی الرٹ خودکار ظاہر ہوتا ہے۔<br>
            • الرٹ میں دیے گئے <b>"📥 تازہ ڈیٹا بیس بیک اپ ڈاؤن لوڈ کریں"</b> بٹن پر کلک کر کے صرف 1 سیکنڈ میں مکمل ڈیٹابیس فائل اپنے کمپیوٹر میں محفوظ کر لیں۔<br>
            • کسی بھی ناگہانی سرور خرابی کی صورت میں <b>Settings -> Database Backups</b> میں جا کر <b>Upload &amp; Restore Backup</b> سے سابقہ فائل اپلوڈ کر کے پورا ڈیٹا سیکنڈوں میں بحال کیا جا سکتا ہے۔
          </div>
        </div>

        <!-- FAQ 18 -->
        <div class="ug-accordion-item" style="border:1.5px solid #e2e8f0;border-radius:8px;background:#ffffff;overflow:hidden;transition:all 0.2s;">
          <div class="ug-accordion-header" onclick="toggleUserGuideFaq(this)" style="padding:11px 14px;background:#f8fafc;cursor:pointer;display:flex;justify-content:space-between;align-items:center;user-select:none;transition:background 0.2s;">
            <div style="display:flex;align-items:center;gap:10px;">
              <span style="font-size:16px;">📊</span>
              <strong style="color:#0f172a;font-size:13px;">18. ماہانہ اخراجات کا بجٹ (Monthly Budget) کیسے مقرر کریں اور اوور سپینڈنگ الرٹس؟</strong>
            </div>
            <span class="ug-accordion-icon" style="font-size:13px;color:#64748b;font-weight:bold;margin-right:8px;">➕</span>
          </div>
          <div class="ug-accordion-body" style="display:none;padding:12px 16px;border-top:1px solid #e2e8f0;background:#fcfdfe;color:#334155;font-size:12.5px;line-height:1.7;">
            • بائیں مینو میں <b>Budgets &amp; Limits</b> پر تشریف لے جائیں۔<br>
            • مطلوبہ مہینہ منتخب کریں اور جس خرچ کی مد (جیسے مطبخ، بجلی بل، مرمت، تنخواہ) کا بجٹ مقرر کرنا ہو اس کے آگے <b>"✏️ بجٹ سیٹ کریں"</b> پر کلک کر کے رقم درج کریں۔<br>
            • سسٹم ہر خرچ کے بعد خودکار طور پر بجٹ کے استعمال کا فیصد بار (سبز، اورنج، لال) اپڈیٹ کرتا رہتا ہے۔<br>
            • اگر کسی بھی شعبے کا خرچ مقررہ بجٹ کے 80% یا 100% سے تجاوز کرے تو مین ڈیش بورڈ پر فوری ریڈ الرٹ بینر ظاہر ہو جاتا ہے تاکہ غیر ضروری اخراجات فوری روکے جا سکیں۔
          </div>
        </div>

        <!-- FAQ 19 -->
        <div class="ug-accordion-item" style="border:1.5px solid #e2e8f0;border-radius:8px;background:#ffffff;overflow:hidden;transition:all 0.2s;">
          <div class="ug-accordion-header" onclick="toggleUserGuideFaq(this)" style="padding:11px 14px;background:#f8fafc;cursor:pointer;display:flex;justify-content:space-between;align-items:center;user-select:none;transition:background 0.2s;">
            <div style="display:flex;align-items:center;gap:10px;">
              <span style="font-size:16px;">🌾</span>
              <strong style="color:#0f172a;font-size:13px;">19. راشن، اناج اور سامان کی انوینٹری (In-Kind Donation &amp; Ration Stock) کا اندراج کیسے کریں؟</strong>
            </div>
            <span class="ug-accordion-icon" style="font-size:13px;color:#64748b;font-weight:bold;margin-right:8px;">➕</span>
          </div>
          <div class="ug-accordion-body" style="display:none;padding:12px 16px;border-top:1px solid #e2e8f0;background:#fcfdfe;color:#334155;font-size:12.5px;line-height:1.7;">
            • مدارس و فلاحی اداروں میں نقد رقم کے علاوہ راشن (آٹا، چاول، گھی، چینی) یا تعمیراتی سامان ڈونرز کی جانب سے عطیہ کیا جاتا ہے۔<br>
            • بائیں مینو میں <b>Inventory &amp; Ration</b> کھولیں اور <b>"📥 + وصولی راشن و عطیہ"</b> بٹن پر کلک کریں۔ ڈونر کا نام، جنس کی مقدار اور تخمینہ قیمت درج کریں تو اسٹاک خودکار بڑھ جائے گا۔<br>
            • جب یہ راشن طعام گاہ (مطبخ) یا مستحقین کو جاری کیا جائے تو <b>"📤 - ترسیل و اخراج مطبخ"</b> بٹن سے اخراج درج کریں جس سے گودام کا دستیاب اسٹاک خودکار کم ہو جائے گا۔
          </div>
        </div>

        <!-- FAQ 20 -->
        <div class="ug-accordion-item" style="border:1.5px solid #e2e8f0;border-radius:8px;background:#ffffff;overflow:hidden;transition:all 0.2s;">
          <div class="ug-accordion-header" onclick="toggleUserGuideFaq(this)" style="padding:11px 14px;background:#f8fafc;cursor:pointer;display:flex;justify-content:space-between;align-items:center;user-select:none;transition:background 0.2s;">
            <div style="display:flex;align-items:center;gap:10px;">
              <span style="font-size:16px;">📅</span>
              <strong style="color:#0f172a;font-size:13px;">20. اساتذہ و ملازمین کی حاضری، غیر حاضری کٹوتی (Salary Deductions) اور نیٹ تنخواہ سلپ کا طریقہ:</strong>
            </div>
            <span class="ug-accordion-icon" style="font-size:13px;color:#64748b;font-weight:bold;margin-right:8px;">➕</span>
          </div>
          <div class="ug-accordion-body" style="display:none;padding:12px 16px;border-top:1px solid #e2e8f0;background:#fcfdfe;color:#334155;font-size:12.5px;line-height:1.7;">
            • ملازم یا استاد کو ماہانہ تنخواہ ادا کرتے وقت <b>"💰 Stipend / Salary"</b> ونڈو میں <b>Staff Attendance &amp; Leave</b> کا باکس نظر آتا ہے۔<br>
            • آپ صرف غیر حاضری کے دن (Absent Days مثلاً 2 دن) درج کریں، سسٹم بنیادی تنخواہ میں سے روزانہ کی اجرت کے حساب سے کٹوتی کی رقم خودکار بنا دیتا ہے۔<br>
            • اگر کوئی عیدی یا الاؤنس دینا ہو تو وہ بھی شامل کیا جا سکتا ہے۔ نیٹ قابلِ ادائیگی رقم خودکار بنتی ہے اور پرنٹ ہونے والی <b>ماہانہ تنخواہ پرچی (Pay Slip)</b> پر حاضری، غیر حاضری اور کٹوتی کی مکمل تفصیلات واضح پرنٹ ہوتی ہیں۔
          </div>
        </div>

        <!-- FAQ 21 -->
        <div class="ug-accordion-item" style="border:1.5px solid #e2e8f0;border-radius:8px;background:#ffffff;overflow:hidden;transition:all 0.2s;">
          <div class="ug-accordion-header" onclick="toggleUserGuideFaq(this)" style="padding:11px 14px;background:#f8fafc;cursor:pointer;display:flex;justify-content:space-between;align-items:center;user-select:none;transition:background 0.2s;">
            <div style="display:flex;align-items:center;gap:10px;">
              <span style="font-size:16px;">🌙</span>
              <strong style="color:#0f172a;font-size:13px;">21. رمضان المبارک، عید الاضحیٰ و زکوٰۃ مہمات (Seasonal Campaigns) اور واٹس ایپ اپیلز بھیجنا:</strong>
            </div>
            <span class="ug-accordion-icon" style="font-size:13px;color:#64748b;font-weight:bold;margin-right:8px;">➕</span>
          </div>
          <div class="ug-accordion-body" style="display:none;padding:12px 16px;border-top:1px solid #e2e8f0;background:#fcfdfe;color:#334155;font-size:12.5px;line-height:1.7;">
            • بائیں مینو میں <b>Campaigns &amp; Appeals</b> پر جائیں۔ یہاں رمضان راشن مہم، زکوٰۃ مہم، قربانی کھالیں اور ونٹر ریلیف کے اہداف (Targets) اور جمع شدہ رقوم کی پیشرفت بار نظر آتی ہے۔<br>
            • نیچے موجود <b>WhatsApp Appeal Dispatcher</b> سے آپ اپنے تمام معاونین، ماہانہ کفیلین یا بڑے ڈونرز کو فلٹر کر سکتے ہیں۔<br>
            • ہر ڈونر کے آگے موجود <b>"💬 WhatsApp Appeal"</b> بٹن دبانے سے ڈونر کے نام اور ادارے کے آن لائن ڈونیشن لنک و بینک اکاؤنٹ پر مشتمل خوبصورت اپیل میسج خود بخود تیار ہو کر واٹس ایپ پر کھل جاتا ہے۔
          </div>
        </div>

        <!-- FAQ 22 -->
        <div class="ug-accordion-item" style="border:1.5px solid #e2e8f0;border-radius:8px;background:#ffffff;overflow:hidden;transition:all 0.2s;">
          <div class="ug-accordion-header" onclick="toggleUserGuideFaq(this)" style="padding:11px 14px;background:#f8fafc;cursor:pointer;display:flex;justify-content:space-between;align-items:center;user-select:none;transition:background 0.2s;">
            <div style="display:flex;align-items:center;gap:10px;">
              <span style="font-size:16px;">🛡️</span>
              <strong style="color:#0f172a;font-size:13px;">22. تفصیلی آڈٹ لاگ (Audit Trail) کے ذریعے حساس مالیاتی تبدیلیوں اور سرگرمیوں کی نگرانی:</strong>
            </div>
            <span class="ug-accordion-icon" style="font-size:13px;color:#64748b;font-weight:bold;margin-right:8px;">➕</span>
          </div>
          <div class="ug-accordion-body" style="display:none;padding:12px 16px;border-top:1px solid #e2e8f0;background:#fcfdfe;color:#334155;font-size:12.5px;line-height:1.7;">
            • بائیں مینو میں <b>Audit Trail &amp; Logs</b> کھولیں۔<br>
            • یہ صفحہ مالیاتی شفافیت کے لیے سسٹم میں ہونے والی ہر اہم کارروائی (واؤچر بنانا، ایڈٹ کرنا، کینسل کرنا، لاگ ان، پاس ورڈ کی تبدیلی، انوینٹری اخراج) کا تاریخ، صارف، کمپیوٹر آئی پی اور تفصیلی تبدیلیوں کے ساتھ مستقل ریکارڈ رکھتا ہے۔<br>
            • آڈٹ ریکارڈ کو تاریخ اور صارف کے حساب سے سرچ کیا جا سکتا ہے اور ایکسل (CSV) میں ڈاؤنلوڈ یا پرنٹ بھی کیا جا سکتا ہے۔
          </div>
        </div>

        <!-- FAQ 23 -->
        <div class="ug-accordion-item" style="border:1.5px solid #e2e8f0;border-radius:8px;background:#ffffff;overflow:hidden;transition:all 0.2s;">
          <div class="ug-accordion-header" onclick="toggleUserGuideFaq(this)" style="padding:11px 14px;background:#f8fafc;cursor:pointer;display:flex;justify-content:space-between;align-items:center;user-select:none;transition:background 0.2s;">
            <div style="display:flex;align-items:center;gap:10px;">
              <span style="font-size:16px;">🔐</span>
              <strong style="color:#0f172a;font-size:13px;">23. ایڈمن دو فیکٹر سیکیورٹی پن (2FA Security PIN) کو فعال اور تبدیل کرنے کا طریقہ:</strong>
            </div>
            <span class="ug-accordion-icon" style="font-size:13px;color:#64748b;font-weight:bold;margin-right:8px;">➕</span>
          </div>
          <div class="ug-accordion-body" style="display:none;padding:12px 16px;border-top:1px solid #e2e8f0;background:#fcfdfe;color:#334155;font-size:12.5px;line-height:1.7;">
            • ایڈمن اکاؤنٹ کی اضافی حفاظت کے لیے <b>Settings ⚙️ -> Security &amp; Passwords</b> ٹیب کھولیں۔<br>
            • یہاں <b>"Admin Two-Factor Security PIN"</b> کے پینل میں چیک باکس آن کریں اور اپنا 4 یا 6 ہندسوں کا ماسٹر پن (جیسے: 7860) درج کر کے محفوظ کر لیں۔<br>
            • 2FA آن ہونے کے بعد جب بھی ایڈمن لاگ ان کرے گا یا ڈینجر زون میں ڈیٹا صاف کرنے کی کوشش کرے گا، تو سسٹم پاس ورڈ کے علاوہ یہ سیکیورٹی پن بھی لازمی طلب کرے گا جس سے کوئی بھی دوسرا شخص پاس ورڈ ملنے پر بھی سسٹم کو نقصان نہیں پہنچا سکتا۔
          </div>
        </div>

      </div>
    </div>

    <!-- Tab 4: Contact Info -->
    <div id="aboutTabContent_contact" style="padding:20px;display:none;">
      <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px 14px;margin-bottom:16px;display:flex;align-items:center;gap:12px;">
        <span style="font-size:28px;">📞</span>
        <div>
          <b style="color:#166534;font-size:14px;">SoftifyX Technologies &bull; Technical Support</b>
          <div style="font-size:12px;color:#15803d;margin-top:2px;">
            Dedicated developer support for accounting software, hosting &amp; customization
          </div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr;gap:10px;font-size:12.5px;">
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px 14px;display:flex;justify-content:space-between;align-items:center;">
          <div>
            <span style="color:#64748b;font-size:11px;display:block;">WhatsApp Helpline <span class="ur">(واٹس ایپ سپورٹ)</span>:</span>
            <b style="color:#0f172a;font-size:14px;" dir="ltr">+92 331 7240256</b>
          </div>
          <a href="https://api.whatsapp.com/send?phone=923317240256&text=<?=urlencode("السلام علیکم ورحمۃ اللہ وبرکاتہ\nمعاونت درکار ہے برائے SoftifyX ERP - " . $madrasaName)?>" target="_blank" class="btn sm" style="background:#25D366;color:#ffffff;border:none;font-weight:700;padding:6px 12px;border-radius:5px;display:inline-flex;align-items:center;gap:6px;text-decoration:none;">
            💬 Chat on WhatsApp
          </a>
        </div>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px 14px;display:flex;justify-content:space-between;align-items:center;">
          <div>
            <span style="color:#64748b;font-size:11px;display:block;">Support Email <span class="ur">(ای میل)</span>:</span>
            <b style="color:#0f172a;font-size:13.5px;">support@softifyx.com</b>
          </div>
          <a href="mailto:support@softifyx.com?subject=<?=urlencode("Support Request - " . $madrasaName)?>" class="btn secondary sm" style="padding:6px 12px;font-weight:600;font-size:12px;text-decoration:none;">
            ✉️ Send Email
          </a>
        </div>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px 14px;">
          <span style="color:#64748b;font-size:11px;display:block;">Development &amp; Engineering:</span>
          <b style="color:#0f172a;">SoftifyX Technologies &bull; Enterprise Cloud Stack</b>
        </div>
      </div>
    </div>

    <!-- Modal Footer -->
    <div style="padding:12px 20px;background:#f8fafc;border-top:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
      <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
        <button type="button" onclick="switchAboutTab('guide')" class="btn sm" style="background:#0284c7;color:#ffffff;border:none;font-weight:700;padding:6px 12px;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;gap:5px;font-size:12px;">
          📖 Quick Guide <span class="ur">(رہنمائی و ہدایات)</span>
        </button>
        <?php if (!empty($isSuperAdmin)): ?>
          <a href="brochure.php" target="_blank" class="btn sm" style="background:#d97706;color:#ffffff;text-decoration:none;font-weight:700;padding:6px 12px;border-radius:6px;display:inline-flex;align-items:center;gap:5px;font-size:12px;box-shadow:0 1px 3px rgba(217,119,6,0.3);">
            ⭐ <span>Marketing Brochure (خصوصیات و PDF)</span>
          </a>
          <a href="certificate.php" target="_blank" class="btn sm" style="background:#059669;color:#ffffff;text-decoration:none;font-weight:700;padding:6px 12px;border-radius:6px;display:inline-flex;align-items:center;gap:5px;font-size:12px;box-shadow:0 1px 3px rgba(5,150,105,0.3);">
            🎖️ <span>Honorary Certificate (اعزازی سند)</span>
          </a>
        <?php endif; ?>
      </div>
      <button type="button" onclick="closeAboutSoftwareModal()" class="btn secondary sm" style="padding:6px 16px;font-weight:700;">
        Close <span class="ur">(بند کریں)</span>
      </button>
    </div>

  </div>
</div>

<script>
function openAppMobileSidebar() {
  var sidebar = document.getElementById('appSidebar');
  var backdrop = document.getElementById('sidebarBackdrop');
  if (sidebar) sidebar.classList.add('mobile-open');
  if (backdrop) backdrop.classList.add('active');
  document.body.classList.add('menu-open');
}

function closeAppMobileSidebar(e) {
  if (e && e.stopPropagation) e.stopPropagation();
  var sidebar = document.getElementById('appSidebar');
  var backdrop = document.getElementById('sidebarBackdrop');
  if (sidebar) sidebar.classList.remove('mobile-open');
  if (backdrop) backdrop.classList.remove('active');
  document.body.classList.remove('menu-open');
}

function toggleAppMobileSidebar(e) {
  if (e && e.stopPropagation) e.stopPropagation();
  var sidebar = document.getElementById('appSidebar');
  if (sidebar && sidebar.classList.contains('mobile-open')) {
    closeAppMobileSidebar(e);
  } else {
    openAppMobileSidebar();
  }
}

function toggleHeaderNotifDropdown(e) {
  if (e && e.stopPropagation) e.stopPropagation();
  var drop = document.getElementById('headerNotifDropdown');
  if (!drop) return;
  drop.style.display = (drop.style.display === 'block') ? 'none' : 'block';
}

function markAllNotificationsReadAjax(e) {
  if (e && e.stopPropagation) e.stopPropagation();
  var badge = document.getElementById('notifBellBadge');
  if (badge) badge.style.display = 'none';
  fetch('dashboard.php?mark_notifs_read=1&ajax=1')
    .then(function() {
      var list = document.getElementById('headerNotifList');
      if (list) list.innerHTML = '<div style="padding:16px;text-align:center;font-size:12px;color:#15803d;font-weight:600;">✓ تمام نوٹیفیکیشنز پڑھ لیے گئے ہیں۔ (All marked read)</div>';
    })
    .catch(function() {
      window.location.href = 'tasks.php?mark_notifs_read=1';
    });
}

function clearAllNotificationsAjax(e) {
  if (e && e.stopPropagation) e.stopPropagation();
  if (!confirm('کیا آپ تمام نوٹیفیکیشنز مستقل ڈیلیٹ کرنا چاہتے ہیں؟ (Clear all notifications?)')) return;
  var badge = document.getElementById('notifBellBadge');
  if (badge) badge.style.display = 'none';
  fetch('dashboard.php?clear_all_notifications=1&ajax=1')
    .then(function() {
      var list = document.getElementById('headerNotifList');
      if (list) list.innerHTML = '<div style="padding:16px;text-align:center;font-size:12px;color:#94a3b8;">No new notifications (تمام نوٹیفیکیشنز صاف ہو گئے).</div>';
    })
    .catch(function() {
      window.location.href = 'tasks.php?clear_all_notifications=1';
    });
}

document.addEventListener('click', function(e) {
  var drop = document.getElementById('headerNotifDropdown');
  if (drop && !e.target.closest('.header-notif-wrap')) {
    drop.style.display = 'none';
  }
});

// Auto-close when clicking any nav link
document.addEventListener('DOMContentLoaded', function() {
  var sidebar = document.getElementById('appSidebar');
  if (sidebar) {
    var navLinks = sidebar.querySelectorAll('nav a');
    navLinks.forEach(function(link) {
      link.addEventListener('click', function() {
        closeAppMobileSidebar();
      });
    });
  }
});

// PWA Service Worker Registration & Installation Prompt
var deferredPwaPrompt = null;
window.addEventListener('beforeinstallprompt', function(e) {
  e.preventDefault();
  deferredPwaPrompt = e;
  var btn1 = document.getElementById('mobilePwaInstallBtn');
  var btn2 = document.getElementById('sidebarPwaInstallBtn');
  if (btn1) btn1.style.display = 'inline-flex';
  if (btn2) btn2.style.display = 'inline-flex';
});

function triggerPwaInstall() {
  if (deferredPwaPrompt) {
    deferredPwaPrompt.prompt();
    deferredPwaPrompt.userChoice.then(function(choice) {
      if (choice.outcome === 'accepted') {
        var btn1 = document.getElementById('mobilePwaInstallBtn');
        var btn2 = document.getElementById('sidebarPwaInstallBtn');
        if (btn1) btn1.style.display = 'none';
        if (btn2) btn2.style.display = 'none';
      }
      deferredPwaPrompt = null;
    });
  } else {
    alert('موبائل پر ایپ انسٹال کرنے کے لیے اپنے براؤزر کے اوپر موجود مینو (3 ڈاٹس یا شیئر آئیکن) پر کلک کر کے "Add to Home Screen" یا "Install App" منتخب کریں۔');
  }
}

// Login Activity & Device Tracker Controller
function toggleActiveUsersTracker(event) {
  if (event) event.stopPropagation();
  var overlay = document.getElementById('trackerModalOverlay');
  if (!overlay) return;
  var isOpen = overlay.classList.contains('active');
  if (isOpen) {
    closeActiveUsersTracker();
  } else {
    overlay.classList.add('active');
    document.body.style.overflow = 'hidden';
    refreshTrackerData(true);
  }
}

function closeActiveUsersTracker() {
  var overlay = document.getElementById('trackerModalOverlay');
  if (overlay) {
    overlay.classList.remove('active');
    document.body.style.overflow = '';
  }
}

function handleTrackerOverlayClick(event) {
  if (event.target && event.target.id === 'trackerModalOverlay') {
    closeActiveUsersTracker();
  }
}

function switchTrackerTab(tab) {
  var tabActive = document.getElementById('trackerTabActive');
  var tabRecent = document.getElementById('trackerTabRecent');
  var btnActive = document.getElementById('tabBtnActiveUsers');
  var btnRecent = document.getElementById('tabBtnRecentLogins');
  if (!tabActive || !tabRecent) return;

  if (tab === 'active') {
    tabActive.style.display = 'block';
    tabRecent.style.display = 'none';
    btnActive.classList.add('active');
    btnRecent.classList.remove('active');
  } else {
    tabActive.style.display = 'none';
    tabRecent.style.display = 'block';
    btnRecent.classList.add('active');
    btnActive.classList.remove('active');
  }
}

function refreshTrackerData(isSilent) {
  var icon = document.getElementById('trackerRefreshIcon');
  if (icon && !isSilent) {
    icon.style.display = 'inline-block';
    icon.style.transition = 'transform 0.5s linear';
    icon.style.transform = 'rotate(360deg)';
  }

  fetch('ajax_login_tracker.php')
    .then(function(res) { return res.json(); })
    .then(function(data) {
      if (data && data.status === 'ok') {
        var countDisp = document.getElementById('activeUsersCountDisplay');
        if (countDisp) countDisp.textContent = data.online_count;

        var modalOnline = document.getElementById('modalStatOnline');
        if (modalOnline) modalOnline.textContent = data.online_count + ' Users';

        var modalDesktop = document.getElementById('modalStatDesktop');
        if (modalDesktop) modalDesktop.textContent = data.desktop_count;

        var modalMobile = document.getElementById('modalStatMobile');
        if (modalMobile) modalMobile.textContent = data.mobile_count;

        var tabCountAct = document.getElementById('tabCountActive');
        if (tabCountAct) tabCountAct.textContent = data.online_count;

        var tabCountRec = document.getElementById('tabCountRecent');
        if (tabCountRec && data.recent_activities) tabCountRec.textContent = data.recent_activities.length;

        var actContainer = document.getElementById('activeUsersListContainer');
        if (actContainer && Array.isArray(data.active_users)) {
          if (data.active_users.length === 0) {
            actContainer.innerHTML = '<div style="text-align:center;padding:30px 10px;color:#94a3b8;"><span style="font-size:32px;display:block;margin-bottom:8px;">👥</span><b>No active user sessions found.</b></div>';
          } else {
            var html = '';
            data.active_users.forEach(function(u) {
              var meClass = u.is_me ? 'current-user-row' : '';
              var meBadge = u.is_me ? '<span class="badge" style="background:#dcfce7;color:#15803d;font-size:10.5px;font-weight:700;padding:1px 6px;border-radius:10px;">(You / آپ خود)</span>' : '';
              var avatarBg = u.is_me ? 'background:linear-gradient(135deg, #16a34a, #15803d);' : '';
              var devBadge = (u.device_type === 'Mobile') ? '📱 Mobile' : '💻 Desktop';
              
              html += '<div class="tracker-user-row ' + meClass + '">' +
                '<div style="display:flex;align-items:center;gap:12px;min-width:0;">' +
                  '<span class="tracker-user-avatar" style="' + avatarBg + '">' + escapeHtml(u.initials) + '</span>' +
                  '<div>' +
                    '<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">' +
                      '<strong style="color:#0f172a;font-size:14px;">' + escapeHtml(u.full_name) + '</strong>' + meBadge +
                    '</div>' +
                    '<div style="font-size:12px;color:#64748b;margin-top:2px;">' +
                      '@' + escapeHtml(u.username) + ' • <span class="badge badge-gray" style="font-size:10.5px;">' + escapeHtml(u.role_name) + '</span>' +
                    '</div>' +
                  '</div>' +
                '</div>' +
                '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;justify-content:flex-end;">' +
                  '<span class="badge" style="background:#dcfce7;color:#15803d;font-weight:700;font-size:11.5px;display:inline-flex;align-items:center;gap:5px;">' +
                    '<span style="width:6px;height:6px;border-radius:50%;background:#16a34a;display:inline-block;"></span>' + escapeHtml(u.status_text) +
                  '</span>' +
                  '<span class="badge badge-blue" style="font-size:11.5px;">' + devBadge + '</span>' +
                  '<span style="font-family:monospace;font-size:11.5px;color:#334155;background:#f1f5f9;padding:3px 7px;border-radius:4px;border:1px solid #e2e8f0;">' + escapeHtml(u.ip_address) + '</span>' +
                  '<div style="font-size:11px;color:#64748b;text-align:right;">' + escapeHtml(u.login_time) + '</div>' +
                '</div>' +
              '</div>';
            });
            actContainer.innerHTML = html;
          }
        }

        var recTbody = document.getElementById('recentLoginsTbody');
        if (recTbody && Array.isArray(data.recent_activities)) {
          if (data.recent_activities.length === 0) {
            recTbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;color:#94a3b8;">No login records found.</td></tr>';
          } else {
            var rhtml = '';
            data.recent_activities.forEach(function(a, idx) {
              var isSuccess = (a.status === 'success');
              var statusBadge = isSuccess ? '<span class="badge" style="background:#dcfce7;color:#15803d;font-size:11px;font-weight:700;">• Success</span>' : '<span class="badge badge-red" style="font-size:11px;font-weight:700;">• Failed</span>';
              var devBadge = (a.device_type === 'Mobile') ? '📱 Mobile' : '💻 Desktop';
              
              rhtml += '<tr style="border-bottom:1px solid #f1f5f9;">' +
                '<td style="padding:10px;color:#64748b;">' + (idx + 1) + '</td>' +
                '<td style="padding:10px;">' +
                  '<div style="display:flex;align-items:center;gap:8px;">' +
                    '<span style="width:28px;height:28px;border-radius:50%;background:#e0f2fe;color:#0369a1;font-weight:700;font-size:11px;display:grid;place-items:center;flex-shrink:0;">' + escapeHtml(a.initials) + '</span>' +
                    '<div>' +
                      '<strong style="color:#0f172a;display:block;">' + escapeHtml(a.full_name) + '</strong>' +
                      '<span style="font-size:11px;color:#64748b;">@' + escapeHtml(a.username) + ' • ' + escapeHtml(a.role_name) + '</span>' +
                    '</div>' +
                  '</div>' +
                '</td>' +
                '<td style="padding:10px;">' + statusBadge + '</td>' +
                '<td style="padding:10px;">' + devBadge + '</td>' +
                '<td style="padding:10px;"><code style="font-size:11.5px;color:#334155;background:#f8fafc;padding:2px 6px;border-radius:4px;border:1px solid #e2e8f0;">' + escapeHtml(a.ip_address) + '</code></td>' +
                '<td style="padding:10px;text-align:right;color:#64748b;font-size:11.5px;white-space:nowrap;">' + escapeHtml(a.date_time) + '</td>' +
              '</tr>';
            });
            recTbody.innerHTML = rhtml;
          }
        }
      }
    })
    .catch(function(err) { console.warn('Tracker refresh error:', err); })
    .finally(function() {
      if (icon) {
        setTimeout(function() {
          icon.style.transform = 'none';
        }, 500);
      }
    });
}

function escapeHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

// About Software Modal Controller
function openAboutSoftwareModal() {
  var m = document.getElementById('aboutSoftwareModal');
  if (m) {
    m.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    switchAboutTab('info');
    closeAppMobileSidebar();
  }
}

function closeAboutSoftwareModal() {
  var m = document.getElementById('aboutSoftwareModal');
  if (m) {
    m.style.display = 'none';
    document.body.style.overflow = '';
  }
}

function handleAboutOverlayClick(e) {
  if (e.target && e.target.id === 'aboutSoftwareModal') {
    closeAboutSoftwareModal();
  }
}

function switchAboutTab(tab) {
  var tabs = ['info', 'license', 'guide', 'contact'];
  tabs.forEach(function(t) {
    var btn = document.getElementById('aboutTabBtn_' + t);
    var content = document.getElementById('aboutTabContent_' + t);
    if (btn && content) {
      if (t === tab) {
        btn.style.color = '#2563eb';
        btn.style.borderBottom = '3px solid #2563eb';
        content.style.display = 'block';
      } else {
        btn.style.color = '#64748b';
        btn.style.borderBottom = '3px solid transparent';
        content.style.display = 'none';
      }
    }
  });
}

// User Guide Interactive Accordion Controllers
function toggleUserGuideFaq(header) {
  var item = header.closest('.ug-accordion-item');
  var body = item.querySelector('.ug-accordion-body');
  var icon = item.querySelector('.ug-accordion-icon');
  var isOpen = (body.style.display === 'block');

  if (isOpen) {
    body.style.display = 'none';
    header.style.background = '#f8fafc';
    if (icon) {
      icon.textContent = '➕';
      icon.style.color = '#64748b';
    }
  } else {
    body.style.display = 'block';
    header.style.background = '#eff6ff';
    if (icon) {
      icon.textContent = '➖';
      icon.style.color = '#2563eb';
    }
  }
}

function expandAllGuideFaqs() {
  document.querySelectorAll('#aboutTabContent_guide .ug-accordion-item').forEach(function(item) {
    var body = item.querySelector('.ug-accordion-body');
    var header = item.querySelector('.ug-accordion-header');
    var icon = item.querySelector('.ug-accordion-icon');
    if (body) body.style.display = 'block';
    if (header) header.style.background = '#eff6ff';
    if (icon) { icon.textContent = '➖'; icon.style.color = '#2563eb'; }
  });
}

function collapseAllGuideFaqs() {
  document.querySelectorAll('#aboutTabContent_guide .ug-accordion-item').forEach(function(item) {
    var body = item.querySelector('.ug-accordion-body');
    var header = item.querySelector('.ug-accordion-header');
    var icon = item.querySelector('.ug-accordion-icon');
    if (body) body.style.display = 'none';
    if (header) header.style.background = '#f8fafc';
    if (icon) { icon.textContent = '➕'; icon.style.color = '#64748b'; }
  });
}

function filterGuideFaqs(val) {
  var term = (val || '').toLowerCase().trim();
  document.querySelectorAll('#aboutTabContent_guide .ug-accordion-item').forEach(function(item) {
    var txt = item.textContent.toLowerCase();
    if (!term || txt.indexOf(term) !== -1) {
      item.style.display = 'block';
      if (term.length > 1) {
        var body = item.querySelector('.ug-accordion-body');
        var header = item.querySelector('.ug-accordion-header');
        var icon = item.querySelector('.ug-accordion-icon');
        if (body) body.style.display = 'block';
        if (header) header.style.background = '#eff6ff';
        if (icon) { icon.textContent = '➖'; icon.style.color = '#2563eb'; }
      }
    } else {
      item.style.display = 'none';
    }
  });
}

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    closeAboutSoftwareModal();
    closeActiveUsersTracker();
  }
});

setInterval(function() {
  fetch('ajax_login_tracker.php')
    .then(function(res) { return res.json(); })
    .then(function(data) {
      if (data && data.status === 'ok') {
        var countDisp = document.getElementById('activeUsersCountDisplay');
        if (countDisp) countDisp.textContent = data.online_count;
      }
    })
    .catch(function() {});
}, 60000);

if ('serviceWorker' in navigator) {
  window.addEventListener('load', function() {
    navigator.serviceWorker.register('sw.js').then(function(reg) {
      // SW registered successfully
    }).catch(function(err) {
      console.warn('SW register note:', err);
    });
  });
}
</script>
