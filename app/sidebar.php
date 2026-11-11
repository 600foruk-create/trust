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
          <span style="background:#ef4444;color:#fff;font-size:11px;font-weight:700;padding:1px 6px;border-radius:10px;line-height:1.2;">
            <?=$unreadNotifCount?>
          </span>
        <?php endif; ?>
      </button>

      <!-- Dropdown Panel -->
      <div id="headerNotifDropdown" style="display:none;position:absolute;right:0;top:100%;margin-top:8px;width:320px;background:#fff;border-radius:10px;box-shadow:0 10px 25px rgba(0,0,0,0.18);border:1px solid #e2e8f0;z-index:99999;overflow:hidden;">
        <div style="padding:10px 14px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
          <b style="font-size:13px;color:#0f172a;">🔔 Notifications <span class="ur">(اطلاعات)</span></b>
          <?php if ($unreadNotifCount > 0): ?>
            <a href="tasks.php?mark_notifs_read=1&token=<?=csrf_token()?>" style="font-size:11px;color:#2563eb;text-decoration:none;font-weight:600;">Mark all read</a>
          <?php endif; ?>
        </div>
        <div style="max-height:280px;overflow-y:auto;">
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
                    @<?=e($au['username'])?> • <span class="badge badge-gray" style="font-size:10.5px;"><?=e($au['role_name'] ?: 'User')?></span>
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
                        <span style="font-size:11px;color:#64748b;">@<?=e($act['username'])?> • <?=e($act['role_name'] ?: 'Staff')?></span>
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

    <?php if (!function_exists('has_permission') || has_permission('donors.view') || has_permission('donors.manage')): ?>
      <a class="<?= $currentPage === 'donors' ? 'active' : '' ?>" href="donors.php">◎ &nbsp; <?=is_madrasa_mode() ? 'Donors & Patrons <span class="ur">(معاونین و سرپرست)</span>' : 'Donors <span class="ur">(معاونین و ڈونرز)</span>'?></a>
    <?php endif; ?>

    <?php if (!function_exists('has_permission') || has_permission('beneficiaries.view') || has_permission('beneficiaries.manage')): ?>
      <a class="<?= $currentPage === 'beneficiaries' ? 'active' : '' ?>" href="beneficiaries.php">🎓 &nbsp; <?=is_madrasa_mode() ? 'Students & Beneficiaries <span class="ur">(طلبہ و مستحقین)</span>' : 'Beneficiaries & Welfare <span class="ur">(مستحقین و راشن)</span>'?></a>
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
        <a class="<?= $currentPage === 'users' ? 'active' : '' ?>" href="users.php">♙ &nbsp; <?=(function_exists('is_regional_director') && is_regional_director()) ? 'My Regional Team <span class="ur">(صوبائی ٹیم)</span>' : 'Users & Provinces <span class="ur">(صارفین و برانچز)</span>'?></a>
      <?php endif; ?>
      <?php if (!function_exists('has_permission') || has_permission('settings.manage')): ?>
        <a class="<?= $currentPage === 'settings' ? 'active' : '' ?>" href="settings.php">⚙ &nbsp; <?=is_madrasa_mode() ? 'Madrasa Settings <span class="ur">(مدرسہ سیٹنگز)</span>' : 'Settings <span class="ur">(سیٹنگز)</span>'?></a>
      <?php endif; ?>
    <?php endif; ?>
  </nav>

  <div class="sidebar-bottom">
    <button type="button" class="pwa-install-btn" id="sidebarPwaInstallBtn" onclick="triggerPwaInstall()" style="display:none;width:100%;margin-bottom:8px;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.15);color:#93c5fd;padding:6px 10px;border-radius:6px;font-size:11px;font-weight:600;align-items:center;justify-content:center;gap:6px;cursor:pointer;">
      📲 Install App <span class="ur">(ایپ انسٹال کریں)</span>
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

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
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
