<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

if (!has_permission('campaigns.view') && !can_approve() && !has_permission('donors.view')) {
    redirect_with_alert('dashboard.php', 'error', 'آپ کے پاس مہمات دیکھنے کا اختیار نہیں ہے۔');
}

$activePage = 'campaigns';
$error = '';
$success = '';

$userId = (int)current_user()['id'];
$canManageCampaigns = has_permission('campaigns.manage') || can_approve();
$madrasaName = function_exists('get_setting') ? get_setting('madrasa_name', 'Trust Accounts') : 'Trust Accounts';
$portalUrl = rtrim(function_exists('get_setting') ? get_setting('base_url', '') : '', '/') . '/donate.php';
if (empty($portalUrl) || $portalUrl === '/donate.php') {
    $portalUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/donate.php';
}

// Bank details for message
$bankTitle = get_setting('donation_bank_title', $madrasaName);
$bankName = get_setting('donation_bank_name', 'Allied Bank Limited (ABL)');
$bankAccount = get_setting('donation_bank_account', '');
$bankIban = get_setting('donation_bank_iban', '');
$bankSummary = "بینک: {$bankName} | اکاؤنٹ: {$bankAccount}" . (!empty($bankIban) ? " | IBAN: {$bankIban}" : "");

// Handle Create / Update Campaign
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_campaign') {
    verify_csrf();
    if (!$canManageCampaigns) {
        $error = 'آپ کے پاس مہم بنانے یا تبدیل کرنے کا اختیار نہیں ہے۔';
    } else {
        $campId = (int)($_POST['campaign_id'] ?? 0);
        $code = trim($_POST['campaign_code'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $category = trim($_POST['category'] ?? 'ramadan');
        $targetAmount = max(0, (float)($_POST['target_amount'] ?? 0));
        $startDate = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
        $endDate = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        $appealMsg = trim($_POST['appeal_message'] ?? '');
        $status = in_array($_POST['status'] ?? '', ['active', 'completed', 'draft'], true) ? $_POST['status'] : 'active';

        if ($code === '' || $title === '') {
            $error = 'براہ کرم مہم کا کوڈ اور عنوان درج فرمائیں۔';
        } else {
            try {
                if ($campId > 0) {
                    $stmt = $pdo->prepare("UPDATE campaigns SET campaign_code = ?, title = ?, category = ?, target_amount = ?, start_date = ?, end_date = ?, appeal_message = ?, status = ? WHERE id = ?");
                    $stmt->execute([$code, $title, $category, $targetAmount, $startDate, $endDate, $appealMsg, $status, $campId]);
                    $success = "مہم '{$title}' کامیابی سے اپڈیٹ ہو گئی۔";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO campaigns (campaign_code, title, category, target_amount, start_date, end_date, appeal_message, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$code, $title, $category, $targetAmount, $startDate, $endDate, $appealMsg, $status]);
                    $campId = (int)$pdo->lastInsertId();
                    $success = "نئی مہم '{$title}' کامیابی سے شروع کر دی گئی۔";
                }

                if (function_exists('log_audit_trail')) {
                    log_audit_trail('save_campaign', 'campaigns', $campId, ['code' => $code, 'title' => $title]);
                }
            } catch (Exception $e) {
                $error = 'مہم محفوظ نہیں ہو سکی: ' . $e->getMessage();
            }
        }
    }
}

// Fetch all campaigns
$campaigns = $pdo->query("SELECT * FROM campaigns ORDER BY (status = 'active') DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Calculate Raised Amount for each campaign based on donations (or target funds)
foreach ($campaigns as &$c) {
    // If target has specific fund or matched keywords in vouchers
    $cat = $c['category'];
    $fundKeyword = ($cat === 'ramadan') ? '%راشن%' : (($cat === 'zakat') ? '%زکو%' : (($cat === 'qurbani') ? '%قربانی%' : '%امدادی%'));
    $raised = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM vouchers WHERE voucher_type = 'income' AND status = 'posted' AND (description LIKE '{$fundKeyword}' OR voucher_no LIKE '%{$c['campaign_code']}%')")->fetchColumn();
    $c['calculated_raised'] = max((float)$c['raised_amount'], $raised);
    $c['progress_pct'] = $c['target_amount'] > 0 ? round(($c['calculated_raised'] / $c['target_amount']) * 100, 1) : 0;
}
unset($c);

// Selected campaign for Dispatcher
$selectedCampId = (int)($_GET['camp_id'] ?? ($campaigns[0]['id'] ?? 0));
$currentCamp = null;
foreach ($campaigns as $cp) {
    if ((int)$cp['id'] === $selectedCampId) {
        $currentCamp = $cp;
        break;
    }
}
if (!$currentCamp && !empty($campaigns)) {
    $currentCamp = $campaigns[0];
}

// Donors list for Dispatcher
$donorFilter = trim($_GET['donor_filter'] ?? 'all');
$sqlDonors = "SELECT d.id, d.donor_name, d.phone, d.city, d.is_pledge_active, d.pledge_amount, 
    (SELECT COALESCE(SUM(total_amount), 0) FROM vouchers WHERE voucher_type = 'income' AND status = 'posted' AND (donor_id = d.id OR payee_name = d.donor_name)) AS lifetime_donated 
    FROM donors d WHERE d.phone IS NOT NULL AND d.phone != ''";
if ($donorFilter === 'pledgers') {
    $sqlDonors .= " AND d.is_pledge_active = 1";
} elseif ($donorFilter === 'major') {
    $sqlDonors .= " HAVING lifetime_donated >= 25000";
}
$sqlDonors .= " ORDER BY lifetime_donated DESC LIMIT 60";
$donors = $pdo->query($sqlDonors)->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../app/sidebar.php';
?>

<div class="main-content">
    <div style="max-width:1200px;margin:0 auto;padding:16px 20px;">

        <!-- Header -->
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;margin-bottom:20px;">
            <div>
                <h1 style="font-size:22px;color:#0f172a;margin:0;display:flex;align-items:center;gap:10px;">
                    <span>📢</span>
                    <span>رمضان، زکوٰۃ و موسمی مہمات (Seasonal Campaigns &amp; Appeals)</span>
                </h1>
                <p style="color:#64748b;font-size:13px;margin:4px 0 0;">
                    سالانہ فنڈ ریزنگ مہمات، اہداف (Targets) کی نگرانی اور معاونین کو 1-کلک واٹس ایپ اپیلز کا اجرا
                </p>
            </div>

            <?php if ($canManageCampaigns): ?>
                <button type="button" onclick="openCampaignModal()" class="btn" style="background:#0f172a;color:#ffffff;border:none;font-weight:700;padding:8px 16px;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
                    ✨ + نئی مہم بنائیں (Create Campaign)
                </button>
            <?php endif; ?>
        </div>

        <?php if ($error): ?>
            <div class="alert error" style="margin-bottom:16px;"><?=e($error)?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert success" style="margin-bottom:16px;"><?=e($success)?></div>
        <?php endif; ?>

        <!-- Campaigns Cards Grid -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:16px;margin-bottom:28px;">
            <?php foreach ($campaigns as $camp): 
                $pct = $camp['progress_pct'];
                $isSel = ($currentCamp && (int)$currentCamp['id'] === (int)$camp['id']);
                $catColor = '#0284c7';
                $catEmoji = '🌙';
                if ($camp['category'] === 'zakat') { $catColor = '#15803d'; $catEmoji = '💰'; }
                elseif ($camp['category'] === 'qurbani') { $catColor = '#b45309'; $catEmoji = '🐑'; }
                elseif ($camp['category'] === 'winter') { $catColor = '#4338ca'; $catEmoji = '❄️'; }
            ?>
            <div style="background:#ffffff;border:2px solid <?=$isSel ? $catColor : '#e2e8f0'?>;border-radius:12px;padding:18px;box-shadow:<?=$isSel ? '0 4px 12px rgba(0,0,0,0.08)' : '0 1px 3px rgba(0,0,0,0.04)'?>;position:relative;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;">
                    <div>
                        <span class="badge" style="background:<?=$catColor?>15;color:<?=$catColor?>;font-size:11px;font-weight:700;">
                            <?=$catEmoji?> <?=e(ucfirst($camp['category']))?>
                        </span>
                        <h3 style="margin:8px 0 0;font-size:15px;color:#0f172a;line-height:1.4;"><?=e($camp['title'])?></h3>
                        <span style="font-size:11px;color:#64748b;display:block;margin-top:2px;">Code: <strong><?=e($camp['campaign_code'])?></strong></span>
                    </div>
                    <?php if ($camp['status'] === 'active'): ?>
                        <span class="badge badge-green" style="font-size:10px;">فعال (Active)</span>
                    <?php else: ?>
                        <span class="badge secondary" style="font-size:10px;">غیر فعال</span>
                    <?php endif; ?>
                </div>

                <div style="background:#f8fafc;padding:10px 12px;border-radius:8px;margin:12px 0;">
                    <div style="display:flex;justify-content:space-between;font-size:12px;color:#64748b;margin-bottom:4px;">
                        <span>جمع شدہ: <b>Rs. <?=number_format($camp['calculated_raised'], 0)?></b></span>
                        <span>ہدف: <b>Rs. <?=number_format($camp['target_amount'], 0)?></b></span>
                    </div>
                    <div style="background:#e2e8f0;height:9px;border-radius:5px;overflow:hidden;">
                        <div style="background:<?=$catColor?>;height:100%;width:<?=min(100, $pct)?>%;"></div>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px;font-size:11px;">
                        <span style="font-weight:700;color:<?=$catColor?>;"><?=$pct?>% مکمل</span>
                        <span style="color:#64748b;">
                            <?= !empty($camp['end_date']) ? ('آخری تاریخ: ' . date('d M Y', strtotime($camp['end_date']))) : 'جاری' ?>
                        </span>
                    </div>
                </div>

                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;padding-top:10px;border-top:1px solid #f1f5f9;">
                    <a href="campaigns.php?camp_id=<?=$camp['id']?>#dispatcherSection" class="btn sm" style="background:<?=$isSel ? $catColor : '#f1f5f9'?>;color:<?=$isSel ? '#ffffff' : '#334155'?>;border:none;font-size:11.5px;font-weight:700;padding:5px 10px;text-decoration:none;">
                        📲 واٹس ایپ اپیلز کھولیں
                    </a>
                    <?php if ($canManageCampaigns): ?>
                        <button type="button" onclick='openCampaignModal(<?=json_encode($camp)?>)' style="background:none;border:none;color:#64748b;cursor:pointer;font-size:12px;" title="ترمیم کریں">
                            ✏️ Edit
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- WhatsApp Appeal Dispatcher Section -->
        <?php if ($currentCamp): 
            $defaultMsg = $currentCamp['appeal_message'] ?: "السلام علیکم ورحمۃ اللہ وبرکاتہ\nمحترم معاون صاحب! {$madrasaName} کی جانب سے {$currentCamp['title']} میں آپ کے تعاون و عطیات کی درخواست کی جاتی ہے۔\nآن لائن عطیہ یا سلپ جمع کروائیں:\n{$portalUrl}\n{$bankSummary}\nجزاکم اللہ خیراً۔";
        ?>
        <div id="dispatcherSection" style="background:#ffffff;border:1.5px solid #cbd5e1;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.05);margin-bottom:30px;">
            <div style="padding:16px 20px;background:#f0fdf4;border-bottom:1.5px solid #86efac;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <span style="font-size:24px;">📲</span>
                    <div>
                        <h3 style="margin:0;font-size:15px;color:#166534;">
                            واٹس ایپ مہم ڈسپیچر برائے: <strong><?=e($currentCamp['title'])?></strong>
                        </h3>
                        <p style="margin:2px 0 0;font-size:12px;color:#15803d;">
                            معاونین کو انفرادی نام اور آن لائن ڈونیشن لنک کے ساتھ دعائیہ اپیل 1-کلک میں ارسال فرمائیں
                        </p>
                    </div>
                </div>

                <!-- Donors Audience Filter -->
                <div style="display:flex;gap:6px;">
                    <a href="campaigns.php?camp_id=<?=$currentCamp['id']?>&donor_filter=all#dispatcherSection" class="btn sm" style="<?=$donorFilter==='all' ? 'background:#166534;color:#fff;' : 'background:#fff;color:#166534;border:1px solid #86efac;'?>font-size:11.5px;font-weight:700;">تمام معاونین (<?=count($donors)?>)</a>
                    <a href="campaigns.php?camp_id=<?=$currentCamp['id']?>&donor_filter=pledgers#dispatcherSection" class="btn sm" style="<?=$donorFilter==='pledgers' ? 'background:#166534;color:#fff;' : 'background:#fff;color:#166534;border:1px solid #86efac;'?>font-size:11.5px;font-weight:700;">ماہانہ کفیلین</a>
                    <a href="campaigns.php?camp_id=<?=$currentCamp['id']?>&donor_filter=major#dispatcherSection" class="btn sm" style="<?=$donorFilter==='major' ? 'background:#166534;color:#fff;' : 'background:#fff;color:#166534;border:1px solid #86efac;'?>font-size:11.5px;font-weight:700;">بڑے ڈونرز (25k+)</a>
                </div>
            </div>

            <div style="padding:16px 20px;background:#fcfdfe;border-bottom:1px solid #e2e8f0;">
                <label style="display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:6px;">
                    📝 مہم پیغام کا مسودہ (Message Template - آپ اسے اپنی مرضی سے تبدیل کر سکتے ہیں):
                </label>
                <textarea id="liveAppealTemplate" rows="4" style="width:100%;font-size:12.5px;padding:10px 12px;border:1.5px solid #cbd5e1;border-radius:6px;box-sizing:border-box;font-family:inherit;line-height:1.6;direction:rtl;text-align:right;"><?=e($defaultMsg)?></textarea>
                <div style="display:flex;justify-content:space-between;font-size:11.5px;color:#64748b;margin-top:4px;">
                    <span>ٹپ: میسج میں ڈونر کا نام اور پورٹل لنک خودکار شامل ہو جائیں گے</span>
                    <span>آن لائن پورٹل: <code><?=e($portalUrl)?></code></span>
                </div>
            </div>

            <!-- Donors Target List Table -->
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:12.5px;text-align:right;">
                    <thead>
                        <tr style="background:#f8fafc;color:#334155;border-bottom:1px solid #cbd5e1;">
                            <th style="padding:10px 16px;text-align:left;">معاون کا نام (Donor)</th>
                            <th style="padding:10px 16px;">موبائل نمبر (WhatsApp)</th>
                            <th style="padding:10px 16px;">شہر / علاقہ</th>
                            <th style="padding:10px 16px;">سابقہ تعاون</th>
                            <th style="padding:10px 16px;text-align:center;">کارروائی (Action)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($donors)): ?>
                            <tr><td colspan="5" style="text-align:center;padding:24px;color:#94a3b8;">کوئی معاون نہیں ملا جس کے پاس موبائل نمبر موجود ہو۔</td></tr>
                        <?php else: foreach ($donors as $dn): 
                            $rawPhone = normalize_pakistan_phone($dn['phone'] ?? '');
                        ?>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:10px 16px;text-align:left;">
                                <strong style="color:#0f172a;"><?=e($dn['donor_name'])?></strong>
                                <?php if (!empty($dn['is_pledge_active'])): ?>
                                    <span class="badge badge-green" style="font-size:9.5px;margin-right:4px;">ماہانہ کفیل</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:10px 16px;font-family:monospace;font-weight:600;color:#0f172a;" dir="ltr">
                                <?=e($dn['phone'])?>
                            </td>
                            <td style="padding:10px 16px;color:#64748b;"><?=e($dn['city'] ?: '—')?></td>
                            <td style="padding:10px 16px;font-weight:700;color:#15803d;">
                                Rs. <?=number_format((float)$dn['lifetime_donated'], 0)?>
                            </td>
                            <td style="padding:10px 16px;text-align:center;">
                                <?php if (!empty($rawPhone)): ?>
                                    <button type="button" onclick="dispatchWhatsAppAppeal('<?=e(addslashes($rawPhone))?>', '<?=e(addslashes($dn['donor_name']))?>')" class="btn sm" style="background:#25D366;color:#ffffff;border:none;font-weight:700;padding:5px 12px;border-radius:5px;cursor:pointer;display:inline-flex;align-items:center;gap:5px;font-size:12px;">
                                        💬 WhatsApp Appeal
                                    </button>
                                <?php else: ?>
                                    <span style="color:#94a3b8;font-size:11px;">فون موجود نہیں</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- Modal: Create / Edit Campaign -->
<div id="campaignModal" class="modal-backdrop" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.65);z-index:99999;align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(3px);">
    <div style="background:#ffffff;border-radius:12px;width:100%;max-width:540px;box-shadow:0 25px 35px -5px rgba(0,0,0,0.3);overflow:hidden;border:1px solid #cbd5e1;">
        <div style="padding:14px 18px;background:#0f172a;color:#ffffff;display:flex;justify-content:space-between;align-items:center;">
            <b id="cm_modal_title" style="font-size:14.5px;">📢 فنڈ ریزنگ مہم ترتیبات (Fundraising Campaign)</b>
            <button type="button" onclick="closeCampaignModal()" style="background:none;border:none;color:#cbd5e1;font-size:20px;cursor:pointer;">&times;</button>
        </div>
        <form method="post" action="campaigns.php" style="padding:18px 20px;">
            <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
            <input type="hidden" name="action" value="save_campaign">
            <input type="hidden" name="campaign_id" id="cm_campaign_id" value="0">

            <div style="display:grid;grid-template-columns:1fr 2fr;gap:12px;margin-bottom:12px;">
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;color:#0f172a;margin-bottom:4px;">Code *</label>
                    <input type="text" name="campaign_code" id="cm_code" required placeholder="e.g. RAM-2027" style="width:100%;font-size:13px;padding:8px;border:1.5px solid #cbd5e1;border-radius:6px;box-sizing:border-box;">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;color:#0f172a;margin-bottom:4px;">Title (مہم کا عنوان) *</label>
                    <input type="text" name="title" id="cm_title" required placeholder="مثلاً رمضان راشن و افطار مہم" style="width:100%;font-size:13px;padding:8px;border:1.5px solid #cbd5e1;border-radius:6px;box-sizing:border-box;">
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;color:#0f172a;margin-bottom:4px;">Category *</label>
                    <select name="category" id="cm_category" style="width:100%;padding:8px;border:1.5px solid #cbd5e1;border-radius:6px;font-size:12.5px;">
                        <option value="ramadan">🌙 رمضان المبارک مہم</option>
                        <option value="zakat">💰 سالانہ زکوٰۃ مہم</option>
                        <option value="qurbani">🐑 عید الاضحیٰ قربانی مہم</option>
                        <option value="winter">❄️ سرمائی گرم بستر مہم</option>
                        <option value="building">🧱 تعمیراتی و مسجد مہم</option>
                        <option value="general">📢 عمومی فنڈ ریزنگ</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;color:#0f172a;margin-bottom:4px;">Target Amount PKR *</label>
                    <input type="number" step="1000" min="0" name="target_amount" id="cm_target_amount" required placeholder="500000" style="width:100%;font-size:13px;font-weight:700;padding:8px;border:1.5px solid #cbd5e1;border-radius:6px;box-sizing:border-box;">
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;color:#0f172a;margin-bottom:4px;">Start Date</label>
                    <input type="date" name="start_date" id="cm_start_date" style="width:100%;padding:8px;border:1.5px solid #cbd5e1;border-radius:6px;font-size:12.5px;box-sizing:border-box;">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;color:#0f172a;margin-bottom:4px;">End Date</label>
                    <input type="date" name="end_date" id="cm_end_date" style="width:100%;padding:8px;border:1.5px solid #cbd5e1;border-radius:6px;font-size:12.5px;box-sizing:border-box;">
                </div>
            </div>

            <div style="margin-bottom:12px;">
                <label style="display:block;font-size:12px;font-weight:700;color:#0f172a;margin-bottom:4px;">Status</label>
                <select name="status" id="cm_status" style="width:100%;padding:8px;border:1.5px solid #cbd5e1;border-radius:6px;font-size:12.5px;">
                    <option value="active">فعال (Active)</option>
                    <option value="completed">مکمل شدہ (Completed)</option>
                    <option value="draft">ڈرافٹ (Draft)</option>
                </select>
            </div>

            <div style="margin-bottom:16px;">
                <label style="display:block;font-size:12px;font-weight:700;color:#0f172a;margin-bottom:4px;">Appeal Message Template <span class="ur">(واٹس ایپ پیغام)</span></label>
                <textarea name="appeal_message" id="cm_appeal_message" rows="3" placeholder="مہم کا تفصیلی تعارف اور دعا..." style="width:100%;font-size:12px;padding:8px;border:1.5px solid #cbd5e1;border-radius:6px;box-sizing:border-box;direction:rtl;text-align:right;"></textarea>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:8px;padding-top:12px;border-top:1px solid #e2e8f0;">
                <button type="button" onclick="closeCampaignModal()" class="btn secondary" style="padding:8px 16px;">منسوخ کریں</button>
                <button type="submit" class="btn" style="background:#0f172a;color:#ffffff;border:none;padding:8px 20px;font-weight:700;border-radius:6px;cursor:pointer;">
                    💾 محفوظ کریں (Save Campaign)
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openCampaignModal(c) {
    if (c) {
        document.getElementById('cm_modal_title').textContent = '✏️ ترمیم مہم: ' + c.title;
        document.getElementById('cm_campaign_id').value = c.id;
        document.getElementById('cm_code').value = c.campaign_code;
        document.getElementById('cm_title').value = c.title;
        document.getElementById('cm_category').value = c.category;
        document.getElementById('cm_target_amount').value = c.target_amount;
        document.getElementById('cm_start_date').value = c.start_date || '';
        document.getElementById('cm_end_date').value = c.end_date || '';
        document.getElementById('cm_status').value = c.status || 'active';
        document.getElementById('cm_appeal_message').value = c.appeal_message || '';
    } else {
        document.getElementById('cm_modal_title').textContent = '✨ نئی مہم بنائیں (Create Campaign)';
        document.getElementById('cm_campaign_id').value = 0;
        document.getElementById('cm_code').value = '';
        document.getElementById('cm_title').value = '';
        document.getElementById('cm_target_amount').value = '';
        document.getElementById('cm_start_date').value = new Date().toISOString().split('T')[0];
        document.getElementById('cm_end_date').value = '';
        document.getElementById('cm_status').value = 'active';
        document.getElementById('cm_appeal_message').value = '';
    }
    var m = document.getElementById('campaignModal');
    if (m) m.style.display = 'flex';
}

function closeCampaignModal() {
    var m = document.getElementById('campaignModal');
    if (m) m.style.display = 'none';
}

function dispatchWhatsAppAppeal(phone, donorName) {
    if (!phone) {
        alert('اس معاون کا موبائل نمبر درج نہیں ہے۔');
        return;
    }
    var template = document.getElementById('liveAppealTemplate').value || '';
    var personalized = "محترم " + donorName + " صاحب!\n\n" + template;
    var url = "https://api.whatsapp.com/send?phone=" + encodeURIComponent(phone) + "&text=" + encodeURIComponent(personalized);
    window.open(url, '_blank');
}

window.addEventListener('click', function(e) {
    var m = document.getElementById('campaignModal');
    if (e.target === m) closeCampaignModal();
});
</script>
