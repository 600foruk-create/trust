<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();
require_permission('donors.view');

$activePage = 'donors';
$error = '';
$success = '';

$currentUser = current_user();
$userId = (int)($currentUser['id'] ?? 0);
$isSuperAdmin = in_array($currentUser['role'] ?? '', ['Super Admin', 'Administrator'], true);
$userFundIds = get_user_assigned_fund_ids($userId);
$hasFundRestriction = (!$isSuperAdmin && !empty($userFundIds));

// Handle Delete Donor (Only authorized users with donors.manage)
if (isset($_GET['delete_id'])) {
    if (!has_permission('donors.manage')) {
        $error = 'صرف مجاز صارفین کو معاونین ڈیلیٹ کرنے کی اجازت ہے۔';
    } else {
        $delId = (int)$_GET['delete_id'];
        $token = $_GET['token'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            $error = 'Invalid security token for deletion.';
        } else {
            try {
                if ($hasFundRestriction) {
                    $donorFund = $pdo->query("SELECT fund_id FROM donors WHERE id = {$delId}")->fetchColumn();
                    if ($donorFund === false || !in_array((int)$donorFund, $userFundIds, true)) {
                        $error = 'آپ کے پاس اس فنڈ کے معاون کو ڈیلیٹ کرنے کا اختیار نہیں ہے۔';
                    }
                }
                if (empty($error)) {
                    // Check if donor has vouchers
                    $voucherCount = (int)$pdo->query("SELECT COUNT(*) FROM vouchers WHERE donor_id = {$delId}")->fetchColumn();
                    if ($voucherCount > 0) {
                        // Safely unlink donor from vouchers so accounting journal entries and balances remain 100% intact
                        $pdo->prepare('UPDATE vouchers SET donor_id = NULL WHERE donor_id = ?')->execute([$delId]);
                    }
                    $stmtDel = $pdo->prepare('DELETE FROM donors WHERE id = ?');
                    $stmtDel->execute([$delId]);
                    
                    $redirectUrl = 'donors.php?success=deleted';
                    if (isset($_GET['tab']) && $_GET['tab'] === 'pledges') {
                        $redirectUrl .= '&tab=pledges';
                        if (!empty($_GET['month'])) {
                            $redirectUrl .= '&month=' . urlencode($_GET['month']);
                        }
                        if (isset($_GET['pledge_fund_id'])) {
                            $redirectUrl .= '&pledge_fund_id=' . (int)$_GET['pledge_fund_id'];
                        }
                        if (!empty($_GET['pledge_status'])) {
                            $redirectUrl .= '&pledge_status=' . urlencode($_GET['pledge_status']);
                        }
                    }
                    header('Location: ' . $redirectUrl);
                    exit;
                }
            } catch (Exception $e) {
                $error = 'Delete failed: ' . $e->getMessage();
            }
        }
    }
}

// Quick Receive Pledge (One-click Modal Receipt directly from Tracker)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quick_receive_pledge') {
    verify_csrf();
    if (!has_permission('vouchers.create') && !has_permission('income.create') && !has_permission('donors.manage') && !can_approve()) {
        $error = 'آپ کے پاس آمدنی کی رسید یا واؤچر جاری کرنے کا اختیار نہیں ہے۔';
    } else {
        $donorId = (int)($_POST['donor_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $fundId = (int)($_POST['fund_id'] ?? 0);
        $date = trim($_POST['voucher_date'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        $paymentMethod = trim($_POST['payment_method'] ?? 'cash');
        $referenceNo = trim($_POST['reference_no'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $assetAccountId = (int)($_POST['asset_account_id'] ?? 0);
        $incomeAccountId = (int)($_POST['income_account_id'] ?? 0);

        $userCashAcc = get_or_create_user_cash_account($pdo, $userId);
        $userCashAccId = (int)($userCashAcc['id'] ?? 0);

        if ($paymentMethod === 'cash') {
            // Cash receipts: default to the logged-in user's cash account
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
            // Bank transfer / Cheque receipts: must deposit into an active Bank Account
            $bankCheck = $pdo->prepare("SELECT id FROM accounts WHERE id = ? AND is_cash_or_bank = 1 AND is_active = 1 AND (user_id IS NULL OR account_code = '1010' OR account_name LIKE '%bank%' OR account_name LIKE '%بینک%')");
            $bankCheck->execute([$assetAccountId]);
            if (!$bankCheck->fetchColumn()) {
                // Fallback to default active bank account
                $bankDef = $pdo->query("SELECT id FROM accounts WHERE is_cash_or_bank = 1 AND is_active = 1 AND (account_code = '1010' OR account_name LIKE '%bank%' OR account_name LIKE '%بینک%') ORDER BY (account_code = '1010') DESC, id ASC LIMIT 1")->fetchColumn();
                $assetAccountId = (int)($bankDef ?: $userCashAccId);
            }
        }

        if ($assetAccountId <= 0) {
            $assetAccountId = $userCashAccId;
        }

        if ($incomeAccountId <= 0) {
            $incAccDefault = $pdo->query("SELECT id FROM accounts WHERE account_type='income' AND is_active=1 ORDER BY (account_code='4000') DESC, account_code ASC LIMIT 1")->fetchColumn();
            $incomeAccountId = (int)($incAccDefault ?: 0);
        }

        if ($amount <= 0 || $donorId <= 0) {
            $error = 'براہ کرم درست رقم درج کریں۔';
        } elseif ($fundId <= 0) {
            $error = 'براہ کرم متعلقہ فنڈ منتخب کریں۔';
        } elseif ($hasFundRestriction && !in_array($fundId, $userFundIds, true)) {
            $error = 'آپ کے اکاؤنٹ کے پاس اس فنڈ میں رقم وصول کرنے کا اختیار نہیں ہے۔';
        } else {
            try {
                $pdo->beginTransaction();

                $donorRow = $pdo->query("SELECT donor_name, phone FROM donors WHERE id = {$donorId}")->fetch();
                $donorName = $donorRow['donor_name'] ?? 'معاون';

                $prefix = 'RV-' . date('Ym', strtotime($date)) . '-';
                $lastNo = $pdo->query("SELECT voucher_no FROM vouchers WHERE voucher_no LIKE '{$prefix}%' ORDER BY id DESC LIMIT 1")->fetchColumn();
                $nextSeq = 1;
                if ($lastNo && preg_match('/-(\d+)$/', $lastNo, $m)) {
                    $nextSeq = (int)$m[1] + 1;
                }
                $voucherNo = $prefix . str_pad((string)$nextSeq, 4, '0', STR_PAD_LEFT);

                $isDirectPost = has_permission('voucher.direct_post') || can_approve();
                $voucherStatus = $isDirectPost ? 'posted' : 'draft';
                $postedAt = $isDirectPost ? date('Y-m-d H:i:s') : null;

                $userRegId = function_exists('get_user_region_id') ? get_user_region_id($userId) : 0;
                $voucherRegionId = $userRegId > 0 ? $userRegId : null;

                $stmtVoucher = $pdo->prepare('INSERT INTO vouchers (voucher_no, voucher_type, voucher_date, fund_id, region_id, donor_id, payee_name, payment_method, reference_no, description, total_amount, status, created_by, posted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmtVoucher->execute([
                    $voucherNo, 'income', $date, $fundId, $voucherRegionId, $donorId,
                    $donorName, $paymentMethod, $referenceNo, $description, $amount,
                    $voucherStatus, $userId, $postedAt
                ]);
                $voucherId = (int)$pdo->lastInsertId();

                $narration = $description ?: ("Monthly pledge contribution from " . $donorName);
                $stmtJe = $pdo->prepare('INSERT INTO journal_entries (voucher_id, region_id, entry_date, narration, status, created_by) VALUES (?, ?, ?, ?, ?, ?)');
                $stmtJe->execute([$voucherId, $voucherRegionId, $date, $narration, $voucherStatus, $userId]);
                $jeId = (int)$pdo->lastInsertId();

                $stmtLine = $pdo->prepare('INSERT INTO journal_lines (journal_entry_id, account_id, fund_id, description, debit, credit) VALUES (?, ?, ?, ?, ?, ?)');
                $stmtLine->execute([$jeId, $assetAccountId, $fundId, 'Received: ' . $narration, $amount, 0.00]);
                $stmtLine->execute([$jeId, $incomeAccountId, $fundId, 'Donation/Income: ' . $narration, 0.00, $amount]);

                $pdo->commit();

                $retMonth = trim($_POST['return_month'] ?? date('Y-m'));
                $retFund = (int)($_POST['return_fund_id'] ?? 0);
                $retStatus = trim($_POST['return_status'] ?? 'all');

                header('Location: donors.php?tab=pledges&month=' . urlencode($retMonth) . '&pledge_fund_id=' . $retFund . '&pledge_status=' . urlencode($retStatus) . '&received_voucher_id=' . $voucherId . '&success=pledge_received');
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Error receiving pledge: ' . $e->getMessage();
            }
        }
    }
}

// Handle Add or Edit Donor Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!has_permission('donors.manage')) {
        $error = 'آپ کے پاس نئے معاونین شامل کرنے یا تبدیل کرنے کا اختیار نہیں ہے۔';
    } else {
        verify_csrf();
        $action = $_POST['action'];
        $name = trim($_POST['donor_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $fundId = !empty($_POST['fund_id']) ? (int)$_POST['fund_id'] : null;

        // Recurring Pledge fields (Feature #1)
        $pledgeActive = isset($_POST['is_pledge_active']) ? 1 : 0;
        $pledgeAmount = (float)($_POST['pledge_amount'] ?? 0);
        $pledgeFundId = !empty($_POST['pledge_fund_id']) ? (int)$_POST['pledge_fund_id'] : null;
        $pledgeFrequency = in_array($_POST['pledge_frequency'] ?? '', ['monthly', 'annual', 'quarterly'], true) ? $_POST['pledge_frequency'] : 'monthly';
        $pledgeDay = max(1, min(28, (int)($_POST['pledge_day'] ?? 1)));
        $pledgeNotes = trim($_POST['pledge_notes'] ?? '');

        // Strict fund validation for restricted users
        if ($hasFundRestriction) {
            if ($fundId === null || !in_array($fundId, $userFundIds, true)) {
                if (count($userFundIds) === 1) {
                    $fundId = $userFundIds[0];
                } else {
                    $error = 'براہ کرم اپنے تفویض کردہ فنڈز میں سے کوئی ایک فنڈ منتخب کریں۔';
                }
            }
        }

        if (empty($error)) {
            if ($action === 'save_donor') {
                if (empty($name)) {
                    $error = 'Please enter donor name.';
                } else {
                    try {
                        $stmt = $pdo->prepare('INSERT INTO donors (donor_name, phone, email, address, notes, fund_id, pledge_amount, pledge_fund_id, pledge_frequency, pledge_day, is_pledge_active, pledge_notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                        $stmt->execute([$name, $phone ?: null, $email ?: null, $address ?: null, $notes ?: null, $fundId, $pledgeAmount, $pledgeFundId, $pledgeFrequency, $pledgeDay, $pledgeActive, $pledgeNotes ?: null]);
                        header('Location: donors.php?success=created');
                        exit;
                    } catch (Exception $e) {
                        $error = 'Error saving donor: ' . $e->getMessage();
                    }
                }
            } elseif ($action === 'update_donor') {
                $donorId = (int)($_POST['donor_id'] ?? 0);
                if ($donorId <= 0 || empty($name)) {
                    $error = 'Please enter valid donor details.';
                } else {
                    if ($hasFundRestriction) {
                        $currDonorFund = $pdo->query("SELECT fund_id FROM donors WHERE id = {$donorId}")->fetchColumn();
                        if ($currDonorFund === false || !in_array((int)$currDonorFund, $userFundIds, true)) {
                            $error = 'آپ کے پاس اس معاون کی معلومات تبدیل کرنے کا اختیار نہیں ہے۔';
                        }
                    }
                    if (empty($error)) {
                        try {
                            $stmt = $pdo->prepare('UPDATE donors SET donor_name = ?, phone = ?, email = ?, address = ?, notes = ?, fund_id = ?, pledge_amount = ?, pledge_fund_id = ?, pledge_frequency = ?, pledge_day = ?, is_pledge_active = ?, pledge_notes = ? WHERE id = ?');
                            $stmt->execute([$name, $phone ?: null, $email ?: null, $address ?: null, $notes ?: null, $fundId, $pledgeAmount, $pledgeFundId, $pledgeFrequency, $pledgeDay, $pledgeActive, $pledgeNotes ?: null, $donorId]);
                            header('Location: donors.php?success=updated');
                            exit;
                        } catch (Exception $e) {
                            $error = 'Error updating donor: ' . $e->getMessage();
                        }
                    }
                }
            }
        }
    }
}

// Edit Mode Data
$editDonor = null;
if (isset($_GET['edit_id']) && has_permission('donors.manage')) {
    $editId = (int)$_GET['edit_id'];
    $stmtEdit = $pdo->prepare('SELECT * FROM donors WHERE id = ?');
    $stmtEdit->execute([$editId]);
    $foundDonor = $stmtEdit->fetch();
    if ($foundDonor) {
        if ($hasFundRestriction && !in_array((int)($foundDonor['fund_id'] ?? 0), $userFundIds, true)) {
            $error = 'آپ کے پاس اس معاون کی تفصیلات ایڈٹ کرنے کا اختیار نہیں ہے۔';
        } else {
            $editDonor = $foundDonor;
        }
    }
}
$showForm = !empty($editDonor) || (!empty($error) && $_SERVER['REQUEST_METHOD'] === 'POST');

// Fetch user allowed active Funds dynamically for categories
$funds = get_user_allowed_funds();

// Search and Fund Category Filter
$search = trim($_GET['search'] ?? '');
$filterFund = isset($_GET['fund_id']) ? (int)$_GET['fund_id'] : 0;

$filterType = trim($_GET['filter_type'] ?? '');

$query = "SELECT d.*, f.name AS fund_name, f.fund_code, f.is_restricted,
          COALESCE(SUM(v.total_amount), 0) AS total_donated,
          COUNT(v.id) AS donations_count,
          MAX(v.id) AS latest_voucher_id,
          MAX(v.voucher_date) AS latest_voucher_date
          FROM donors d
          LEFT JOIN funds f ON f.id = d.fund_id
          LEFT JOIN vouchers v ON v.donor_id = d.id AND v.voucher_type='income' AND v.status='posted'
          WHERE 1=1";

$params = [];
if ($hasFundRestriction) {
    $inFunds = implode(',', array_map('intval', $userFundIds));
    $query .= " AND d.fund_id IN ($inFunds)";
}

if (!empty($search)) {
    $query .= " AND (d.donor_name LIKE ? OR d.phone LIKE ? OR d.address LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($filterFund > 0) {
    $query .= " AND d.fund_id = ?";
    $params[] = $filterFund;
} elseif ($filterFund === -1 && !$hasFundRestriction) {
    $query .= " AND d.fund_id IS NULL";
}

if ($filterType === 'online') {
    $query .= " AND (d.notes LIKE '%آن لائن%' OR d.notes LIKE '%Online%')";
} elseif ($filterType === 'proof') {
    $query .= " AND d.proof_receipt IS NOT NULL AND d.proof_receipt != ''";
}

if ($filterType === 'lapsed') {
    $query .= " GROUP BY d.id HAVING (latest_voucher_date IS NULL OR latest_voucher_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)) ORDER BY d.id DESC";
} else {
    $query .= " GROUP BY d.id ORDER BY d.id DESC";
}
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$donors = $stmt->fetchAll();

$onlineLeadsCount = (int)$pdo->query("SELECT COUNT(*) FROM donors WHERE notes LIKE '%آن لائن%' OR notes LIKE '%Online%'")->fetchColumn();
$withSlipCount = (int)$pdo->query("SELECT COUNT(*) FROM donors WHERE proof_receipt IS NOT NULL AND proof_receipt != ''")->fetchColumn();

if ($hasFundRestriction) {
    $inFunds = implode(',', array_map('intval', $userFundIds));
    $totalDonorsCount = (int)$pdo->query("SELECT COUNT(*) FROM donors WHERE fund_id IN ($inFunds)")->fetchColumn();
    $totalLifetimeDonations = (float)$pdo->query("SELECT COALESCE(SUM(v.total_amount), 0) FROM vouchers v JOIN donors d ON d.id = v.donor_id WHERE d.fund_id IN ($inFunds) AND v.status='posted' AND v.voucher_type='income'")->fetchColumn();
    $catCountsRaw = $pdo->query("SELECT COALESCE(fund_id, 0) AS fid, COUNT(*) AS cnt FROM donors WHERE fund_id IN ($inFunds) GROUP BY fid")->fetchAll(PDO::FETCH_KEY_PAIR);
} else {
    $totalDonorsCount = (int)$pdo->query("SELECT COUNT(*) FROM donors")->fetchColumn();
    $totalLifetimeDonations = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM vouchers WHERE donor_id IS NOT NULL AND status='posted' AND voucher_type='income'")->fetchColumn();
    $catCountsRaw = $pdo->query("SELECT COALESCE(fund_id, 0) AS fid, COUNT(*) AS cnt FROM donors GROUP BY fid")->fetchAll(PDO::FETCH_KEY_PAIR);
}
$madrasaName = get_setting('madrasa_name', 'Trust Accounts');
$madrasaPhone = get_setting('madrasa_phone', '');
$madrasaAddress = get_setting('madrasa_address', '');
$orgLogo = function_exists('get_org_logo_url') ? get_org_logo_url() : null;

// Print & Slip Customization Settings
$printShowLogo = get_setting('print_show_logo', '1') === '1';
$printShowAddress = get_setting('print_show_address', '1') === '1';
$printPreprinted = get_setting('print_preprinted_letterhead', '0') === '1';
$printSignTitle1 = get_setting('print_sign_title_1', is_madrasa_mode() ? 'تیار کنندہ / کیشیئر (Prepared By)' : 'Prepared By (تیار کنندہ)');
$printSignTitle2 = get_setting('print_sign_title_2', is_madrasa_mode() ? 'اکاؤنٹنٹ / ناظمِ مالیات (Accountant)' : 'Accountant / Cashier (اکاؤنٹنٹ)');
$printSignTitle3 = get_setting('print_sign_title_3', is_madrasa_mode() ? 'مہتمم / صدر / مجاز دستخط (Director / Mohtamim)' : 'Director / Mohtamim (دستخط مجاز اتھارٹی)');
$printFooterNote = get_setting('print_footer_note', 'یہ ایک کمپیوٹرائزڈ آفیشل فنانشل رسید ہے۔ کسی دستی مہر کی ضرورت نہیں۔');

// Cash & Bank Accounts and Income Heads for Quick Receipt
$userCashAcc = get_or_create_user_cash_account($pdo, (int)$userId);
$bankAccounts = function_exists('get_or_create_bank_accounts') ? get_or_create_bank_accounts($pdo) : [];
$allAssetAccounts = $pdo->query("SELECT id, account_code, account_name, user_id FROM accounts WHERE is_cash_or_bank=1 AND is_active=1 ORDER BY (account_code = '1000') DESC, (account_code = '1010') DESC, account_code ASC")->fetchAll() ?: [];

$bankIds = array_map(fn($b) => (int)$b['id'], $bankAccounts);
$otherCashAccounts = [];
foreach ($allAssetAccounts as $acc) {
    if (in_array((int)$acc['id'], $bankIds, true)) continue;
    if (!empty($acc['user_id']) && (int)$acc['user_id'] === (int)$userId) continue;
    $otherCashAccounts[] = $acc;
}
$incomeAccounts = $pdo->query("SELECT id, account_code, account_name FROM accounts WHERE account_type='income' AND is_active=1 ORDER BY (account_code='4000') DESC, account_code ASC")->fetchAll();

// Tab Navigation & Monthly Pledges / Kafalat Tracker (Feature #1)
$currentTab = trim($_GET['tab'] ?? 'directory'); // 'directory' or 'pledges'
$selectedMonth = trim($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}
$filterPledgeFund = isset($_GET['pledge_fund_id']) ? (int)$_GET['pledge_fund_id'] : 0;
$filterPledgeStatus = in_array($_GET['pledge_status'] ?? '', ['all', 'pending', 'paid'], true) ? $_GET['pledge_status'] : 'all';

// Pre-calculate selected fund name for header/prints
$currFundName = '';
if ($filterPledgeFund > 0) {
    foreach ($funds as $f) {
        if ((int)$f['id'] === $filterPledgeFund) {
            $currFundName = $f['name'];
            break;
        }
    }
}

// Fetch active pledgers
$pledgeQuery = "SELECT d.*, f.name AS fund_name, f.fund_code, pf.name AS pledge_fund_name,
                COALESCE((
                    SELECT SUM(v.total_amount) 
                    FROM vouchers v 
                    WHERE v.donor_id = d.id 
                      AND v.voucher_type = 'income' 
                      AND v.status = 'posted' 
                      AND v.voucher_date LIKE '{$selectedMonth}%'
                ), 0) AS paid_this_month
                FROM donors d
                LEFT JOIN funds f ON f.id = d.fund_id
                LEFT JOIN funds pf ON pf.id = d.pledge_fund_id
                WHERE d.is_pledge_active = 1";
if ($hasFundRestriction) {
    $inFunds = implode(',', array_map('intval', $userFundIds));
    $pledgeQuery .= " AND (d.fund_id IN ($inFunds) OR d.pledge_fund_id IN ($inFunds))";
}
if ($filterPledgeFund > 0) {
    $pledgeQuery .= " AND (d.pledge_fund_id = {$filterPledgeFund} OR (d.pledge_fund_id IS NULL AND d.fund_id = {$filterPledgeFund}))";
} elseif ($filterPledgeFund === -1) {
    $pledgeQuery .= " AND (d.pledge_fund_id IS NULL AND d.fund_id IS NULL)";
}
$pledgeQuery .= " ORDER BY d.pledge_day ASC, d.id DESC";
$rawPledgers = $pdo->query($pledgeQuery)->fetchAll() ?: [];

// Fetch prior payments and earliest activity for carry-forward arrears calculation
$priorPaidMap = $pdo->query("SELECT donor_id, SUM(total_amount) AS total_prior 
                             FROM vouchers 
                             WHERE voucher_type='income' AND status='posted' AND voucher_date < '{$selectedMonth}-01' 
                             GROUP BY donor_id")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

$firstVoucherMap = $pdo->query("SELECT donor_id, MIN(voucher_date) AS first_date 
                                FROM vouchers 
                                WHERE voucher_type='income' AND status='posted' 
                                GROUP BY donor_id")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

// Status Filtering & Prior Arrears Computation
$activePledgers = [];
$allPendingPledgers = [];
$totalMonthlyPledged = 0;
$totalMonthlyReceived = 0;
$totalPendingPledgers = 0;
$totalPriorArrearsAll = 0;

$selYear = (int)date('Y', strtotime($selectedMonth . '-01'));
$selMonth = (int)date('m', strtotime($selectedMonth . '-01'));

foreach ($rawPledgers as $pl) {
    $donorId = (int)$pl['id'];
    $monthlyPledge = (float)$pl['pledge_amount'];
    $paidThisMonth = (float)$pl['paid_this_month'];
    $dueThisMonth = max(0, $monthlyPledge - $paidThisMonth);
    $isPaid = ($paidThisMonth >= $monthlyPledge);

    // Determine donor commitment start date
    $regDate = !empty($pl['created_at']) ? substr($pl['created_at'], 0, 10) : date('Y-m-d');
    $earliestDate = $firstVoucherMap[$donorId] ?? $regDate;
    $startDateStr = ($earliestDate && $earliestDate < $regDate) ? $earliestDate : $regDate;

    $startYear = (int)date('Y', strtotime($startDateStr));
    $startMonth = (int)date('m', strtotime($startDateStr));

    // Number of prior billing months strictly before selected month
    $priorMonthsCount = (($selYear - $startYear) * 12) + ($selMonth - $startMonth);
    if ($priorMonthsCount < 0) {
        $priorMonthsCount = 0;
    }

    $totalExpectedPrior = $priorMonthsCount * $monthlyPledge;
    $totalPaidBefore = (float)($priorPaidMap[$donorId] ?? 0);

    // Unpaid prior arrears (and any current month surplus covers prior arrears)
    $thisMonthSurplus = max(0, $paidThisMonth - $monthlyPledge);
    $rawPriorArrears = max(0, $totalExpectedPrior - $totalPaidBefore);
    $priorArrears = max(0, $rawPriorArrears - $thisMonthSurplus);
    $unpaidPriorMonths = ($monthlyPledge > 0 && $priorArrears > 0) ? (int)ceil($priorArrears / $monthlyPledge) : 0;

    $totalDueWithArrears = $dueThisMonth + $priorArrears;

    $pl['prior_arrears'] = $priorArrears;
    $pl['prior_unpaid_months'] = $unpaidPriorMonths;
    $pl['due_this_month'] = $dueThisMonth;
    $pl['total_due_with_arrears'] = $totalDueWithArrears;
    $pl['is_paid'] = $isPaid;

    if ($dueThisMonth > 0 || $priorArrears > 0) {
        $allPendingPledgers[] = $pl;
    }

    if ($filterPledgeStatus === 'pending' && $isPaid && $priorArrears <= 0) continue;
    if ($filterPledgeStatus === 'paid' && (!$isPaid || $priorArrears > 0)) continue;

    $activePledgers[] = $pl;

    $totalMonthlyPledged += $monthlyPledge;
    $totalMonthlyReceived += min($paidThisMonth, $monthlyPledge);
    $totalPriorArrearsAll += $priorArrears;
    if ($dueThisMonth > 0 || $priorArrears > 0) {
        $totalPendingPledgers++;
    }
}
$totalMonthlyPending = max(0, $totalMonthlyPledged - $totalMonthlyReceived);

// ==========================================
// PLEDGES EXCEL / CSV EXPORT HANDLER
// ==========================================
if (isset($_GET['export']) && in_array($_GET['export'], ['excel', 'csv'], true)) {
    $currMonthName = date('F_Y', strtotime($selectedMonth . '-01'));
    $fundSlug = $filterPledgeFund > 0 ? ('_fund' . $filterPledgeFund) : '';
    $filename = "Monthly_Pledges_{$currMonthName}{$fundSlug}.csv";

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // Output UTF-8 BOM so Urdu & English characters display properly in Excel
    fputs($out, "\xEF\xBB\xBF");

    // Organization & Title info
    fputcsv($out, [$madrasaName . ' - Monthly Pledges & Kafalat Tracker']);
    fputcsv($out, ['Month: ' . date('F Y', strtotime($selectedMonth . '-01')), 'Exported On: ' . date('d M Y, h:i A')]);
    
    $filterDesc = 'All Categories (تمام کیٹیگریز)';
    if ($filterPledgeFund > 0) {
        foreach ($funds as $f) {
            if ((int)$f['id'] === $filterPledgeFund) {
                $filterDesc = $f['name'] . ' (' . $f['fund_code'] . ')';
                break;
            }
        }
    } elseif ($filterPledgeFund === -1) {
        $filterDesc = 'Unassigned (غیر متعین)';
    }
    fputcsv($out, ['Category / Fund Filter:', $filterDesc, 'Status Filter:', ucfirst($filterPledgeStatus)]);
    fputcsv($out, []); // Blank line

    // Column Headers
    fputcsv($out, [
        'Sr #',
        'Donor Name',
        'Phone / WhatsApp',
        'Dedicated Fund / Purpose',
        'Expected Day',
        'Monthly Pledge (PKR)',
        'Prior Arrears (سابقہ بقایا)',
        'Paid This Month (PKR)',
        'Month Due (PKR)',
        'Total Due With Arrears (کل بقایا)',
        'Status',
        'Notes / Details'
    ]);

    $sr = 1;
    $sumPledge = 0;
    $sumPrior = 0;
    $sumPaid = 0;
    $sumDue = 0;
    $sumTotalDue = 0;

    foreach ($activePledgers as $pl) {
        $pAmt = (float)$pl['pledge_amount'];
        $paidAmt = (float)$pl['paid_this_month'];
        $priorAmt = (float)($pl['prior_arrears'] ?? 0);
        $dueAmt = (float)($pl['due_this_month'] ?? max(0, $pAmt - $paidAmt));
        $totalDueAmt = (float)($pl['total_due_with_arrears'] ?? ($dueAmt + $priorAmt));
        $isPaid = ($paidAmt >= $pAmt && $priorAmt <= 0);
        $statusText = $isPaid ? 'Paid (ادا شدہ)' : 'Pending (بقایا)';

        $fundName = $pl['pledge_fund_name'] ?: ($pl['fund_name'] ?: 'General');

        $sumPledge += $pAmt;
        $sumPrior += $priorAmt;
        $sumPaid += $paidAmt;
        $sumDue += $dueAmt;
        $sumTotalDue += $totalDueAmt;

        fputcsv($out, [
            $sr++,
            $pl['donor_name'],
            $pl['phone'] ?: '',
            $fundName,
            $pl['pledge_day'] . 'th of month',
            number_format($pAmt, 2, '.', ''),
            number_format($priorAmt, 2, '.', ''),
            number_format($paidAmt, 2, '.', ''),
            number_format($dueAmt, 2, '.', ''),
            number_format($totalDueAmt, 2, '.', ''),
            $statusText,
            $pl['pledge_notes'] ?: ''
        ]);
    }

    // Totals Row
    fputcsv($out, []);
    fputcsv($out, [
        'TOTALS (کل میزان)',
        count($activePledgers) . ' Donors',
        '',
        '',
        '',
        number_format($sumPledge, 2, '.', ''),
        number_format($sumPrior, 2, '.', ''),
        number_format($sumPaid, 2, '.', ''),
        number_format($sumDue, 2, '.', ''),
        number_format($sumTotalDue, 2, '.', ''),
        $totalPendingPledgers . ' Pending',
        ''
    ]);

    fclose($out);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Donors Management | <?=e($madrasaName)?></title>
    <link rel="stylesheet" href="assets/style.css?v=<?=css_ver()?>">
    <style>
        @media print {
            @page {
                size: A4 portrait;
                margin: 10mm 10mm 14mm 10mm;
            }
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .app-top-header, .sidebar, .app-sidebar, nav, .topbar, .tab-nav, .filter-form-wrap, .no-print, .action-col, footer, .app-footer, .header-pending-pill, .panel-heading {
                display: none !important;
            }
            .print-only {
                display: block !important;
            }
            body, .app-shell, .main {
                background: #ffffff !important;
                color: #000000 !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Nastaliq Urdu", "Jameel Noori Nastaleeq", Tahoma, Arial, sans-serif !important;
            }
            .panel {
                border: none !important;
                box-shadow: none !important;
                padding: 0 !important;
                margin: 0 !important;
                background: transparent !important;
            }
            .stats-grid {
                display: none !important;
            }
            .table-wrap {
                overflow: visible !important;
                max-width: 100% !important;
            }
            table {
                width: 100% !important;
                border-collapse: collapse !important;
                margin-top: 10px;
                font-size: 10.5px !important;
            }
            table thead {
                display: table-header-group !important;
            }
            table tfoot {
                display: table-footer-group !important;
            }
            table tr {
                page-break-inside: avoid !important;
            }
            table th, table td {
                border: 1px solid #334155 !important;
                padding: 5px 6px !important;
                color: #000000 !important;
            }
            table th {
                background: #0f172a !important;
                color: #ffffff !important;
                font-weight: 700 !important;
                font-size: 10.5px !important;
            }
            table tbody tr:nth-child(even) td {
                background: #f8fafc !important;
            }
            table tfoot tr {
                background: #f1f5f9 !important;
                font-weight: 800 !important;
                border-top: 2px solid #0f172a !important;
            }
            .badge {
                border: 1px solid #94a3b8 !important;
                padding: 2px 5px !important;
                color: #000000 !important;
                background: transparent !important;
                font-size: 9.5px !important;
            }
            .sign-block {
                page-break-inside: avoid !important;
            }
        }
        .print-only {
            display: none;
        }

        @media screen and (max-width: 768px) {
            .topbar {
                flex-direction: column !important;
                align-items: stretch !important;
                gap: 12px !important;
            }
            .topbar .btn-row {
                display: flex !important;
                flex-wrap: wrap !important;
                gap: 8px !important;
                width: 100% !important;
            }
            .topbar .btn-row button, .topbar .btn-row a {
                flex: 1 1 auto !important;
                justify-content: center !important;
                text-align: center !important;
                padding: 8px 12px !important;
                font-size: 13px !important;
            }
            .panel-heading {
                flex-direction: column !important;
                align-items: stretch !important;
                gap: 12px !important;
            }
            .panel-heading > div:last-child {
                display: flex !important;
                flex-wrap: wrap !important;
                gap: 8px !important;
                width: 100% !important;
            }
            .panel-heading > div:last-child a, .panel-heading > div:last-child button {
                flex: 1 1 auto !important;
                justify-content: center !important;
                text-align: center !important;
                padding: 8px 10px !important;
                font-size: 12.5px !important;
            }
            .stats-grid {
                grid-template-columns: 1fr !important;
                gap: 10px !important;
            }
            .tab-nav {
                overflow-x: auto !important;
                -webkit-overflow-scrolling: touch !important;
                white-space: nowrap !important;
                display: flex !important;
                padding-bottom: 4px !important;
            }
            .table-wrap {
                overflow-x: auto !important;
                -webkit-overflow-scrolling: touch !important;
                width: 100% !important;
                margin-bottom: 16px !important;
            }
            table {
                min-width: 600px !important;
            }
        }
    </style>
</head>
<body>
<div class="app-shell">
    <?php require __DIR__ . '/../app/sidebar.php'; ?>

    <main class="main">
        <header class="topbar">
            <div>
                <span class="eyebrow">DIRECTORY</span>
                <h1>Donors Directory <span class="ur">(معاونین و عطیہ دہندگان)</span></h1>
            </div>
            <div class="btn-row">
                <a href="<?=e(get_app_base_url())?>/donate.php" target="_blank" class="btn secondary sm" style="background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;font-weight:700;display:inline-flex;align-items:center;gap:5px;" title="عوامی ڈونیشن پورٹل اوپن کریں">
                    🌐 Online Donation Page <span class="ur">(آن لائن پورٹل)</span>
                </a>
                <button type="button" class="btn secondary sm" style="display:inline-flex;align-items:center;gap:5px;" onclick="copyPortalLink()" title="آن لائن پورٹل کا لنک کاپی کر کے واٹس ایپ یا فیس بک پر شیئر کریں">
                    🔗 Copy Link <span class="ur">(شیئر لنک)</span>
                </button>
                <a href="print_donation_qr.php" target="_blank" class="btn secondary sm" style="background:#fffbeb;color:#92400e;border:1px solid #fde68a;font-weight:700;display:inline-flex;align-items:center;gap:5px;" title="مسجد یا بینر کے لیے کیو آر پوسٹر پرنٹ کریں">
                    🖨️ QR Poster <span class="ur">(کیو آر بینر)</span>
                </a>
                <?php if (has_permission('donors.manage')): ?>
                    <?php if ($editDonor): ?>
                        <a href="donors.php" class="btn secondary sm">✕ Cancel Editing <span class="ur">(منسوخ کریں)</span></a>
                    <?php else: ?>
                        <button type="button" class="btn blue sm" id="toggleDonorBtn" onclick="toggleDonorForm()">
                            ＋ Register New Donor
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </header>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert success">
                <?php
                    if ($_GET['success'] === 'created') echo 'Donor details successfully registered!';
                    elseif ($_GET['success'] === 'updated') echo 'Donor information updated successfully!';
                    elseif ($_GET['success'] === 'deleted') echo 'Donor record deleted successfully!';
                    else echo 'Donor details saved successfully!';
                ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert error"><?=e($error)?></div>
        <?php endif; ?>

        <?php if (!has_permission('donors.manage')): ?>
            <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:12px 18px;margin-bottom:20px;display:flex;align-items:center;gap:12px;">
                <span style="font-size:20px;">ℹ️</span>
                <div style="font-size:13px;color:#1e40af;">
                    <div class="ur"><strong>معاونین کی فہرست:</strong> نئے معاونین کا اندراج، تبدیلی یا ڈیلیٹ کرنے کا اختیار مجاز صارفین کے پاس ہے۔ آپ یہاں سے معاونین تلاش کر کے ان کی آمدنی کی رسید (Receipt) جاری کر سکتے ہیں۔</div>
                    <div class="en-only" style="display:none;"><strong>Donors Directory:</strong> Authorized users can register, modify or delete donors. You can search donors here and issue income receipts.</div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Tab Navigation (Feature #1) -->
        <div class="tab-nav" style="margin: 18px 0;">
            <a href="donors.php?tab=directory" class="tab-link <?=$currentTab==='directory'?'active':''?>">
                👥 Donors Directory <span class="ur">(معاونین لسٹ)</span> (<?=$totalDonorsCount?>)
            </a>
            <a href="donors.php?tab=pledges&month=<?=e($selectedMonth)?>" class="tab-link <?=$currentTab==='pledges'?'active':''?>" style="display:inline-flex;align-items:center;gap:6px;">
                📋 Monthly Pledges &amp; Kafalat Tracker <span class="ur">(ماہانہ کفالت و اقساط ٹریکر)</span>
                <?php if ($totalPendingPledgers > 0): ?>
                    <span class="badge" style="background:#fee2e2;color:#b91c1c;font-size:11px;padding:2px 7px;border-radius:10px;"><?=$totalPendingPledgers?> Due</span>
                <?php else: ?>
                    <span class="badge" style="background:#dcfce7;color:#15803d;font-size:11px;padding:2px 7px;border-radius:10px;">✓ All Cleared</span>
                <?php endif; ?>
            </a>
        </div>

        <?php if ($currentTab === 'directory'): ?>
        <!-- Stats Overview -->
        <section class="stats-grid" style="margin:22px 0 24px;">
            <article class="stat-card">
                <div class="stat-top"><span>Total Registered Donors</span><span class="stat-icon blue">👥</span></div>
                <strong><?=$totalDonorsCount?></strong>
                <small>Active contributors</small>
            </article>
            <article class="stat-card">
                <div class="stat-top"><span>Total Collected from Donors</span><span class="stat-icon green">↗</span></div>
                <strong><?=money($totalLifetimeDonations)?></strong>
                <small>Tracked donations</small>
            </article>
        </section>
        <?php endif; ?>

        <!-- Professional Donor Form Card (Add / Edit) - Only for users with donors.manage permission -->
        <?php if (has_permission('donors.manage')): ?>
        <article class="form-card" id="donorFormCard" style="<?= $showForm ? 'margin:20px 0 24px;' : 'display:none; margin:20px 0 24px;' ?>">
            <div class="form-card-header" style="display:flex;justify-content:space-between;align-items:flex-start;">
                <div>
                    <h3><?= $editDonor ? '✏️ Edit Donor Details <span class="ur">(معاون کی تفصیلات تبدیل کریں)</span>' : 'Register New Donor <span class="ur">(نیا معاون درج کریں)</span>' ?></h3>
                    <p><?= $editDonor ? 'Update contact information and preferences for <strong>' . e($editDonor['donor_name']) . '</strong>' : 'Keep donor record for receipts, communications and future appeals' ?></p>
                </div>
                <?php if (!$editDonor): ?>
                    <button type="button" class="btn sm secondary" onclick="toggleDonorForm(false)" style="padding:4px 10px;" title="Close">✕</button>
                <?php endif; ?>
            </div>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                <input type="hidden" name="action" value="<?= $editDonor ? 'update_donor' : 'save_donor' ?>">
                <?php if ($editDonor): ?>
                    <input type="hidden" name="donor_id" value="<?=$editDonor['id']?>">
                <?php endif; ?>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Donor Full Name <span class="ur">(مکمل نام)</span></label>
                        <input name="donor_name" required placeholder="e.g. Haji Muhammad Aslam" value="<?=e($editDonor['donor_name'] ?? '')?>">
                    </div>

                    <div class="form-group">
                        <label>Fund Category <span class="ur">(متعلقہ فنڈ / پروجیکٹ)</span></label>
                        <select name="fund_id" <?= $hasFundRestriction ? 'required' : '' ?>>
                            <?php if (!$hasFundRestriction): ?>
                                <option value="">-- General / All Funds <span class="ur">(عام / تمام فنڈز)</span> --</option>
                            <?php endif; ?>
                            <?php foreach ($funds as $f): 
                                $isSelected = false;
                                if ($editDonor && (int)($editDonor['fund_id'] ?? 0) === (int)$f['id']) {
                                    $isSelected = true;
                                } elseif (!$editDonor && $hasFundRestriction && count($funds) === 1) {
                                    $isSelected = true;
                                }
                            ?>
                                <option value="<?=$f['id']?>" <?=$isSelected ? 'selected' : ''?>>
                                    <?=e($f['name'])?> (<?=e($f['fund_code'])?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color:var(--muted);font-size:11px;">
                            <span class="ur"><?=is_madrasa_mode() ? 'یہ معاون مدرسہ کے کس فنڈ (مثلاً: زکوٰۃ، کفالت طلبہ، مطبخ راشن، تعمیرات وغیرہ) کا ڈونر ہے۔' : 'یہ معاون ٹرسٹ کے کس فنڈ یا پروجیکٹ (مثلاً: زکوٰۃ و صدقات، راشن، یتیم کفالت وغیرہ) کا ڈونر ہے۔'?></span>
                            <span class="en-only" style="display:none;"><?=is_madrasa_mode() ? 'Select which madrasa fund this patron contributes to.' : 'Select which trust fund or project this donor contributes to.'?></span>
                        </small>
                    </div>

                    <div class="form-group">
                        <label>Phone / WhatsApp <span class="ur">(فون نمبر)</span></label>
                        <input name="phone" placeholder="0300-1234567" value="<?=e($editDonor['phone'] ?? '')?>">
                    </div>

                    <div class="form-group">
                        <label>Email Address <span class="ur">(ای میل)</span></label>
                        <input type="email" name="email" placeholder="donor@example.com" value="<?=e($editDonor['email'] ?? '')?>">
                    </div>

                    <div class="form-group">
                        <label>Address / City <span class="ur">(پتہ / شہر)</span></label>
                        <input name="address" placeholder="e.g. Lahore / Karachi" value="<?=e($editDonor['address'] ?? '')?>">
                    </div>

                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Notes / Preferences <span class="ur">(خصوصی یادداشت)</span></label>
                        <input name="notes" placeholder="e.g. Monthly Zakat, Ration donor, Water project..." value="<?=e($editDonor['notes'] ?? '')?>">
                    </div>

                    <!-- Recurring Monthly Pledge / Sponsorship Box (Feature #1) -->
                    <div class="form-group" style="grid-column: 1 / -1; background:#f0fdf4; border:1.5px solid #86efac; border-radius:10px; padding:16px;">
                        <label style="font-weight:700; color:#166534; display:flex; align-items:center; gap:8px; cursor:pointer; margin-bottom:12px;">
                            <input type="checkbox" name="is_pledge_active" id="pledgeCheckbox" value="1" <?=!empty($editDonor['is_pledge_active']) ? 'checked' : ''?> onchange="togglePledgeFields(this.checked)" style="width:18px;height:18px;accent-color:#16a34a;">
                            <span>🤝 Monthly Recurring Pledge / Kafalat <span class="ur">(مستقل معاون / ماہانہ کفالت و عطیہ)</span></span>
                        </label>

                        <div id="pledgeFieldsContainer" style="<?=!empty($editDonor['is_pledge_active']) ? 'display:grid;' : 'display:none;'?> grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:14px;">
                            <div>
                                <label style="font-size:12.5px; font-weight:600;">Pledged Amount <span class="ur">(طے شدہ رقم PKR)</span> *</label>
                                <input type="number" step="0.01" min="1" name="pledge_amount" placeholder="e.g. 5000" value="<?=e($editDonor['pledge_amount'] ?? '')?>" style="font-weight:700; color:#15803d;">
                            </div>
                            <div>
                                <label style="font-size:12.5px; font-weight:600;">Dedicated Fund <span class="ur">(مخصوص فنڈ برائے کفالت)</span></label>
                                <select name="pledge_fund_id">
                                    <option value="">-- Same as Donor Fund --</option>
                                    <?php foreach ($funds as $f): ?>
                                        <option value="<?=$f['id']?>" <?=($editDonor && (int)($editDonor['pledge_fund_id'] ?? 0) === (int)$f['id']) ? 'selected' : ''?>>
                                            <?=e($f['name'])?> (<?=e($f['fund_code'])?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label style="font-size:12.5px; font-weight:600;">Frequency <span class="ur">(تواتر)</span></label>
                                <select name="pledge_frequency">
                                    <option value="monthly" <?=($editDonor['pledge_frequency'] ?? '') === 'monthly' ? 'selected' : ''?>>Monthly (ماہانہ)</option>
                                    <option value="quarterly" <?=($editDonor['pledge_frequency'] ?? '') === 'quarterly' ? 'selected' : ''?>>Quarterly (سہ ماہی)</option>
                                    <option value="annual" <?=($editDonor['pledge_frequency'] ?? '') === 'annual' ? 'selected' : ''?>>Annual (سالانہ)</option>
                                </select>
                            </div>
                            <div>
                                <label style="font-size:12.5px; font-weight:600;">Expected Day <span class="ur">(متوقع تاریخ)</span></label>
                                <input type="number" min="1" max="28" name="pledge_day" placeholder="1 to 28 (e.g. 5)" value="<?=e($editDonor['pledge_day'] ?? '1')?>">
                            </div>
                            <div style="grid-column: 1 / -1;">
                                <label style="font-size:12.5px; font-weight:600;">Pledge Details / Tag <span class="ur">(تفصیل جیسے طالب علم، یتیم یا راشن کا نام)</span></label>
                                <input name="pledge_notes" placeholder="e.g. Sponsorship for Student Ahmad Hifz Class, Ration pack support..." value="<?=e($editDonor['pledge_notes'] ?? '')?>">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="btn-row" style="margin-top:10px;">
                    <button class="btn blue" type="submit"><?= $editDonor ? '💾 Update Donor <span class="ur">(محفوظ کریں)</span>' : '💾 Save Donor <span class="ur">(محفوظ کریں)</span>' ?></button>
                    <?php if ($editDonor): ?>
                        <a href="donors.php" class="btn secondary">✕ Cancel Editing <span class="ur">(منسوخ کریں)</span></a>
                    <?php else: ?>
                        <button class="btn secondary" type="button" onclick="toggleDonorForm(false)">✕ Cancel <span class="ur">(منسوخ کریں)</span></button>
                    <?php endif; ?>
                </div>
            </form>
        </article>
        <?php endif; ?>

        <?php if ($currentTab === 'directory'): ?>
        <!-- Donors List -->
        <article class="panel" id="donorsDirectory">
            <div class="panel-heading">
                <div>
                    <h3>Donors Directory <span class="ur">(معاونین کی فہرست)</span></h3>
                    <p>List of contributors with total giving summary</p>
                </div>
            </div>

            <!-- Fund Category Quick Filter Pills -->
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;">
                <a href="donors.php?fund_id=0<?= $search ? '&search='.urlencode($search) : '' ?>#donorsDirectory" class="btn sm <?= $filterFund === 0 ? 'blue' : 'secondary' ?>" style="font-size:12px;padding:5px 12px;border-radius:20px;text-decoration:none;">
                    All Donors (<?= $totalDonorsCount ?>)
                </a>
                <?php if (!$hasFundRestriction): ?>
                <a href="donors.php?fund_id=-1<?= $search ? '&search='.urlencode($search) : '' ?>#donorsDirectory" class="btn sm <?= $filterFund === -1 ? 'blue' : 'secondary' ?>" style="font-size:12px;padding:5px 12px;border-radius:20px;text-decoration:none;">
                    General <span class="ur">/ عام</span> (<?= (int)($catCountsRaw[0] ?? 0) ?>)
                </a>
                <?php endif; ?>
                <?php foreach ($funds as $f): ?>
                    <?php $cCount = (int)($catCountsRaw[$f['id']] ?? 0); ?>
                    <a href="donors.php?fund_id=<?=$f['id']?><?= $search ? '&search='.urlencode($search) : '' ?>#donorsDirectory" class="btn sm <?= $filterFund === (int)$f['id'] ? 'blue' : 'secondary' ?>" style="font-size:12px;padding:5px 12px;border-radius:20px;text-decoration:none;">
                        <?=e($f['name'])?> (<?=$cCount?>)
                    </a>
                <?php endforeach; ?>
                <a href="donors.php?filter_type=online<?= $search ? '&search='.urlencode($search) : '' ?>#donorsDirectory" class="btn sm" style="font-size:12px;padding:5px 12px;border-radius:20px;text-decoration:none;background:<?= $filterType === 'online' ? '#2563eb' : '#eff6ff' ?>;color:<?= $filterType === 'online' ? '#fff' : '#1d4ed8' ?>;border:1px solid #bfdbfe;font-weight:700;">
                    🌐 Online Leads (<?= $onlineLeadsCount ?>)
                </a>
                <a href="donors.php?filter_type=proof<?= $search ? '&search='.urlencode($search) : '' ?>#donorsDirectory" class="btn sm" style="font-size:12px;padding:5px 12px;border-radius:20px;text-decoration:none;background:<?= $filterType === 'proof' ? '#059669' : '#f0fdf4' ?>;color:<?= $filterType === 'proof' ? '#fff' : '#047857' ?>;border:1px solid #a7f3d0;font-weight:700;">
                    📷 With Proof Slip (<?= $withSlipCount ?>)
                </a>
                <a href="donors.php?filter_type=lapsed<?= $search ? '&search='.urlencode($search) : '' ?>#donorsDirectory" class="btn sm" style="font-size:12px;padding:5px 12px;border-radius:20px;text-decoration:none;background:<?= $filterType === 'lapsed' ? '#b91c1c' : '#fef2f2' ?>;color:<?= $filterType === 'lapsed' ? '#fff' : '#b91c1c' ?>;border:1px solid #fecaca;font-weight:700;">
                    ⚠️ Lapsed (غیر فعال - 90+ دن)
                </a>
            </div>

            <!-- Search & Filter Bar -->
            <form method="get" action="donors.php#donorsDirectory" class="filter-bar">
                <input name="search" placeholder="Search by name, phone, city..." value="<?=e($search)?>" style="flex:2;min-width:180px;">
                <select name="fund_id" onchange="this.form.submit()" style="flex:1;min-width:170px;">
                    <option value="0">All Categories <span class="ur">(تمام فنڈز و پروجیکٹس)</span></option>
                    <?php if (!$hasFundRestriction): ?>
                        <option value="-1" <?=$filterFund===-1?'selected':''?>>General <span class="ur">/ عام معاونین</span></option>
                    <?php endif; ?>
                    <?php foreach ($funds as $f): ?>
                        <option value="<?=$f['id']?>" <?=$filterFund===(int)$f['id']?'selected':''?>>
                            <?=e($f['name'])?> (<?=e($f['fund_code'])?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="btn secondary sm" type="submit">Filter</button>
                <?php if ($search || $filterFund !== 0): ?>
                    <a href="donors.php#donorsDirectory" class="btn secondary sm" style="text-decoration:none;">Reset</a>
                <?php endif; ?>
            </form>

            <?php if (!$donors): ?>
                <div class="empty-state">
                    <div class="empty-icon">◎</div>
                    <b>No donors found</b>
                    <p>Add your first donor using the form above.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Donor Name</th>
                                <th>Category / Fund</th>
                                <th>Phone</th>
                                <th>Address / City</th>
                                <th>Donations Count</th>
                                <th>Total Donated</th>
                                <th class="right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($donors as $d): ?>
                                <tr>
                                    <td>
                                        <b><?=e($d['donor_name'])?></b>
                                        <?php if (stripos($d['notes'] ?? '', 'آن لائن') !== false || stripos($d['notes'] ?? '', 'Online') !== false): ?>
                                            <span class="badge" style="background:#dbeafe;color:#1e40af;font-size:10px;padding:2px 6px;border-radius:4px;margin-left:4px;font-weight:700;" title="آن لائن پورٹل سے رجسٹرڈ">
                                                🌐 Online Lead
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($d['proof_receipt'])): ?>
                                            <a href="<?=e($d['proof_receipt'])?>" target="_blank" class="badge" style="background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;font-size:10px;padding:2px 6px;border-radius:4px;margin-left:4px;text-decoration:none;font-weight:700;" title="منتقلی رسید یا اسکرین شاٹ دیکھیں">
                                                📷 Slip
                                            </a>
                                        <?php endif; ?>
                                        <?php 
                                            $isLapsed = (!empty($d['latest_voucher_date']) && strtotime($d['latest_voucher_date']) < strtotime('-90 days')) || (empty($d['latest_voucher_date']) && (int)$d['donations_count'] === 0);
                                            if ($isLapsed): 
                                        ?>
                                            <span class="badge" style="background:#fef2f2;color:#991b1b;font-size:10px;padding:2px 6px;border-radius:4px;margin-left:4px;font-weight:700;" title="پچھلے 90 دن سے کوئی نیا عطیہ موصول نہیں ہوا">
                                                ⚠️ Lapsed
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($d['is_pledge_active'])): ?>
                                            <span class="badge" style="background:#dcfce7;color:#15803d;font-size:10px;padding:2px 6px;border-radius:4px;margin-left:4px;font-weight:700;">
                                                🤝 Pledge: <?=money($d['pledge_amount'])?>/mo
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($d['notes']): ?>
                                            <div style="font-size:11px;color:#64748b;"><?=e($d['notes'])?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($d['fund_name'])): ?>
                                            <span class="badge badge-blue" style="font-size:11.5px;"><?=e($d['fund_name'])?></span>
                                            <?php if (!empty($d['fund_code'])): ?>
                                                <small style="color:var(--muted);font-size:10px;margin-left:2px;">(<?=e($d['fund_code'])?>)</small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge badge-gray" style="font-size:11px;">General <span class="ur">(عام)</span></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?=e($d['phone'] ?: '—')?></td>
                                    <td><?=e($d['address'] ?: '—')?></td>
                                    <td><span class="badge badge-gray"><?=(int)$d['donations_count']?> receipts</span></td>
                                    <td class="font-bold" style="color:#15803d;"><?=money($d['total_donated'])?></td>
                                    <td class="right" style="white-space:nowrap;">
                                        <a href="income.php?donor_id=<?=$d['id']?>" class="btn blue sm" style="padding:4px 8px;font-size:11.5px;" title="Issue Donation Receipt">
                                            ＋ Receipt
                                        </a>
                                        <a href="print_donor_certificate.php?id=<?=$d['id']?>" target="_blank" class="btn secondary sm" style="padding:4px 8px;font-size:11.5px;background:#fffbeb;color:#92400e;border-color:#fde68a;" title="سندِ اعتراف و تشکر پرنٹ کریں">
                                            📜 سند
                                        </a>
                                        <?php 
                                        $dPhone = normalize_pakistan_phone($d['phone'] ?? '');
                                        if (!empty($dPhone)): 
                                            $latestVId = (int)($d['latest_voucher_id'] ?? 0);
                                            $baseUrl = function_exists('get_app_base_url') ? get_app_base_url() : '';
                                            $slipToken = ($latestVId > 0 && function_exists('get_voucher_public_token')) ? get_voucher_public_token($latestVId) : '';
                                            $slipLink = ($latestVId > 0 && !empty($baseUrl)) ? "{$baseUrl}/print_voucher.php?id={$latestVId}" . (!empty($slipToken) ? "&token={$slipToken}" : '') : '';
                                            
                                            if ($isLapsed):
                                                $msg = "السلام علیکم و رحمۃ اللہ و برکاتہ\n"
                                                     . "محترم جناب *" . $d['donor_name'] . "* صاحب!\n"
                                                     . "امید ہے آپ بخیر و عافیت ہوں گے۔\n"
                                                     . "*" . $madrasaName . "* کی جانب سے دعا ہے کہ اللہ تعالیٰ آپ کے رزق، صحت اور اہل و عیال میں بے شمار برکتیں عطا فرمائے۔\n\n"
                                                     . "کافی عرصہ سے آپ کی طرف سے مدرسہ و ٹرسٹ کے زیرِ کفالت یتامیٰ و طلباء کے لیے دعائیہ و مالی تعاون کی تجدید نہیں ہو سکی۔ ادارے کے اساتذہ و طلباء آپ کی سابقہ خدمات کو یاد رکھتے ہوئے ہمیشہ دعا گو ہیں۔\n\n"
                                                     . "نیکی کے اس تسلسل کو دوبارہ جاری رکھنے کے لیے آپ باآسانی آن لائن لنک سے بھی تشریف لا سکتے ہیں:\n"
                                                     . $baseUrl . "/donate.php\n\n"
                                                     . "جزاکم اللہ خیراً و احسن الجزاء\n"
                                                     . "*" . $madrasaName . "*";
                                            else:
                                                $msg = "السلام علیکم و رحمۃ اللہ و برکاتہ\n"
                                                     . "محترم جناب *" . $d['donor_name'] . "* صاحب!\n"
                                                     . "امید ہے آپ بخیر و عافیت ہوں گے۔\n"
                                                     . "*" . $madrasaName . "* کی جانب سے آپ کے مخلصانہ تعاون کا شکریہ اور مالیاتی تفصیل:\n\n"
                                                     . "💰 *کل عطیات و معاونت اب تک:* *" . money($d['total_donated']) . "*\n"
                                                     . "📋 *موصول شدہ رسیدات:* " . (int)$d['donations_count'] . " رسیدات\n";
                                                if (!empty($d['fund_name'])) {
                                                    $msg .= "🏷️ *مخصوص فنڈ:* " . $d['fund_name'] . "\n";
                                                }
                                                if (!empty($slipLink)) {
                                                    $msg .= "\n📄 *حالیہ کمپیوٹرائزڈ موصولی رسید (Slip) دیکھنے یا پرنٹ کرنے کے لیے لنک کھولیں:* \n"
                                                          . $slipLink . "\n";
                                                }
                                                $msg .= "\nاللہ تعالیٰ آپ کے اس صدقہ و خیرات کو قبول فرمائے اور آپ کے رزق، جان اور مال میں برکتیں عطا فرمائے۔ آمین۔\n\n"
                                                     . "جزاکم اللہ خیراً و احسن الجزاء\n"
                                                     . "*" . $madrasaName . "*";
                                            endif;

                                            if ($madrasaPhone) {
                                                $msg .= "\nرابطہ دفتر: " . $madrasaPhone;
                                            }
                                            $dWaUrl = 'https://api.whatsapp.com/send?phone=' . urlencode($dPhone) . '&text=' . urlencode($msg);
                                        ?>
                                            <a href="<?=e($dWaUrl)?>" target="_blank" class="btn sm" style="background:<?=$isLapsed ? '#fff1f2' : '#25D366'?>;color:<?=$isLapsed ? '#be123c' : '#fff'?>;border:1px solid <?=$isLapsed ? '#fecdd3' : '#16a34a'?>;padding:4px 8px;font-size:11.5px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:3px;" title="<?= $isLapsed ? 'Send Re-engagement Message' : 'Send Donation Summary on WhatsApp'?>">
                                                <?= $isLapsed ? '💌 Re-engage' : '💬 WhatsApp' ?>
                                            </a>
                                        <?php endif; ?>
                                        <?php if (has_permission('donors.manage')): ?>
                                            <a href="donors.php?edit_id=<?=$d['id']?>#donorFormCard" class="btn secondary sm" style="padding:4px 8px;font-size:11.5px;" title="Edit Donor Details">
                                                ✏️ Edit
                                            </a>
                                            <a href="donors.php?delete_id=<?=$d['id']?>&token=<?=csrf_token()?>" class="btn danger sm" style="padding:4px 8px;font-size:11.5px;" onclick="return confirm('Are you sure you want to delete donor \'<?=e(addslashes($d['donor_name']))?>\'?');" title="Delete Donor">
                                                🗑 Delete
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </article>
        <?php elseif ($currentTab === 'pledges'): ?>
            <!-- Official Print / PDF Header (Visible only when printed or exported to PDF) -->
            <div class="print-only print-header-box" style="border:1.5px solid #0f172a;border-radius:6px;padding:16px 20px;margin-bottom:16px;background:#ffffff; <?=$printPreprinted ? 'padding-top:100px;' : ''?>">
                <?php if (!$printPreprinted): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #0f172a;padding-bottom:12px;margin-bottom:12px;">
                    <div style="display:flex;align-items:center;gap:14px;">
                        <?php if ($printShowLogo && $orgLogo): ?>
                            <img src="<?=e($orgLogo)?>" alt="Logo" style="max-height:55px;max-width:110px;object-fit:contain;">
                        <?php endif; ?>
                        <div>
                            <h1 style="margin:0;font-size:22px;font-weight:800;color:#0f172a;letter-spacing:-0.3px;line-height:1.2;"><?=e($madrasaName)?></h1>
                            <?php if ($printShowAddress && (!empty($madrasaAddress) || !empty($madrasaPhone))): ?>
                            <div style="font-size:11.5px;color:#475569;margin-top:3px;">
                                <?php if (!empty($madrasaAddress)): ?>
                                    <span><?=e($madrasaAddress)?></span>
                                <?php endif; ?>
                                <?php if (!empty($madrasaPhone)): ?>
                                    <span> &bull; 📞 <?=e($madrasaPhone)?></span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="text-align:right;">
                        <div style="display:inline-block;background:#0f172a;color:#ffffff;padding:5px 14px;font-size:12.5px;font-weight:700;border-radius:4px;text-transform:uppercase;letter-spacing:0.4px;">
                            Monthly Pledges &amp; Kafalat Statement
                        </div>
                        <div style="font-size:12px;color:#0f172a;font-weight:700;margin-top:4px;" class="ur">
                            ماہانہ کفالت و اقساط تفصیلی رپورٹ
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div style="display:flex;justify-content:flex-end;border-bottom:2px solid #0f172a;padding-bottom:8px;margin-bottom:12px;">
                    <div style="text-align:right;">
                        <div style="display:inline-block;background:#0f172a;color:#ffffff;padding:5px 14px;font-size:12.5px;font-weight:700;border-radius:4px;text-transform:uppercase;letter-spacing:0.4px;">
                            Monthly Pledges &amp; Kafalat Statement
                        </div>
                        <div style="font-size:12px;color:#0f172a;font-weight:700;margin-top:4px;" class="ur">
                            ماہانہ کفالت و اقساط تفصیلی رپورٹ
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Print Metadata Row -->
                <div style="display:flex;justify-content:space-between;align-items:center;font-size:11.5px;color:#334155;background:#f8fafc;padding:7px 12px;border-radius:4px;border:1px solid #e2e8f0;">
                    <div>
                        <strong>Billing Month:</strong> <span style="color:#0f172a;font-weight:700;"><?=date('F Y', strtotime($selectedMonth . '-01'))?></span> (<?=date('m/Y', strtotime($selectedMonth . '-01'))?>)
                        <?php if ($filterPledgeFund > 0 && !empty($currFundName)): ?>
                            &nbsp;|&nbsp; <strong>Category / Fund:</strong> <span style="color:#0284c7;font-weight:700;"><?=e($currFundName)?></span>
                        <?php elseif ($filterPledgeFund === -1): ?>
                            &nbsp;|&nbsp; <strong>Category / Fund:</strong> <span style="color:#0284c7;font-weight:700;">Unassigned (عام)</span>
                        <?php endif; ?>
                        <?php if ($filterPledgeStatus !== 'all'): ?>
                            &nbsp;|&nbsp; <strong>Status:</strong> <span style="font-weight:700;"><?=ucfirst($filterPledgeStatus)?></span>
                        <?php endif; ?>
                    </div>
                    <div style="text-align:right;">
                        <strong>Printed:</strong> <?=date('d M Y, h:i A')?> | <strong>By:</strong> <?=e($currentUser['name'] ?? 'Admin')?>
                    </div>
                </div>

                <!-- Print-Only KPI Summary Strip -->
                <div style="display:flex;gap:10px;margin-top:12px;">
                    <div style="flex:1;border:1px solid #cbd5e1;border-radius:4px;padding:6px 10px;text-align:center;background:#ffffff;">
                        <div style="font-size:10px;color:#64748b;font-weight:700;text-transform:uppercase;">Total Pledgers <span class="ur">(کل معاونین)</span></div>
                        <div style="font-size:15px;font-weight:800;color:#0f172a;margin-top:2px;"><?=count($activePledgers)?> Patrons</div>
                    </div>
                    <div style="flex:1;border:1px solid #cbd5e1;border-radius:4px;padding:6px 10px;text-align:center;background:#ffffff;">
                        <div style="font-size:10px;color:#64748b;font-weight:700;text-transform:uppercase;">Target <span class="ur">(ماہانہ ہدف)</span></div>
                        <div style="font-size:15px;font-weight:800;color:#0f172a;margin-top:2px;"><?=money($totalMonthlyPledged)?></div>
                    </div>
                    <div style="flex:1;border:1px solid #86efac;border-radius:4px;padding:6px 10px;text-align:center;background:#f0fdf4;">
                        <div style="font-size:10px;color:#166534;font-weight:700;text-transform:uppercase;">Received <span class="ur">(موصول شدہ)</span></div>
                        <div style="font-size:15px;font-weight:800;color:#15803d;margin-top:2px;"><?=money($totalMonthlyReceived)?></div>
                    </div>
                    <div style="flex:1;border:1px solid #fca5a5;border-radius:4px;padding:6px 10px;text-align:center;background:#fef2f2;">
                        <div style="font-size:10px;color:#991b1b;font-weight:700;text-transform:uppercase;">Due / Pending <span class="ur">(بقایا)</span></div>
                        <div style="font-size:15px;font-weight:800;color:#b91c1c;margin-top:2px;"><?=money($totalMonthlyPending)?></div>
                    </div>
                    <div style="flex:1;border:1px solid #cbd5e1;border-radius:4px;padding:6px 10px;text-align:center;background:#ffffff;">
                        <div style="font-size:10px;color:#64748b;font-weight:700;text-transform:uppercase;">Recovery <span class="ur">(وصولی شرح)</span></div>
                        <div style="font-size:15px;font-weight:800;color:#0f172a;margin-top:2px;">
                            <?=$totalMonthlyPledged > 0 ? round(($totalMonthlyReceived / $totalMonthlyPledged) * 100, 1) : 0?>%
                        </div>
                    </div>
                </div>
            </div>

            <!-- Monthly Pledges & Kafalat KPI Cards -->
            <section class="stats-grid" style="margin:22px 0 24px;">
                <article class="stat-card">
                    <div class="stat-top"><span>Monthly Target <span class="ur">(ماہانہ ہدف)</span></span><span class="stat-icon blue">🎯</span></div>
                    <strong><?=money($totalMonthlyPledged)?></strong>
                    <small><?=count($activePledgers)?> active regular pledgers</small>
                </article>
                <article class="stat-card">
                    <div class="stat-top"><span>Received This Month <span class="ur">(موصول شدہ)</span></span><span class="stat-icon green">✓</span></div>
                    <strong style="color:#15803d;"><?=money($totalMonthlyReceived)?></strong>
                    <small>Collected for <?=date('F Y', strtotime($selectedMonth . '-01'))?></small>
                </article>
                <article class="stat-card">
                    <div class="stat-top"><span>Due / Pending <span class="ur">(بقایا اقساط)</span></span><span class="stat-icon red">⏳</span></div>
                    <strong style="color:<?=$totalMonthlyPending > 0 || $totalPriorArrearsAll > 0 ? '#b91c1c' : '#15803d'?>;"><?=money($totalMonthlyPending)?></strong>
                    <small>
                        <?php if ($totalPriorArrearsAll > 0): ?>
                            <span style="color:#c2410c;font-weight:700;">+ <?=money($totalPriorArrearsAll)?> سابقہ بقایا</span> (کل: <?=money($totalMonthlyPending + $totalPriorArrearsAll)?>)
                        <?php else: ?>
                            <?=$totalPendingPledgers?> donors pending
                        <?php endif; ?>
                    </small>
                </article>
            </section>

            <?php if (!empty($_GET['received_voucher_id']) && ($_GET['success'] ?? '') === 'pledge_received'): 
                $recVoucherId = (int)$_GET['received_voucher_id'];
                $recVoucher = $pdo->query("SELECT v.*, f.name AS fund_name, d.donor_name, d.phone AS donor_phone FROM vouchers v LEFT JOIN funds f ON f.id = v.fund_id LEFT JOIN donors d ON d.id = v.donor_id WHERE v.id = {$recVoucherId}")->fetch();
            ?>
                <div class="no-print" style="background:#ecfdf5;border:1.5px solid #10b981;border-radius:10px;padding:14px 18px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;box-shadow:0 2px 6px rgba(16,185,129,0.15);">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <span style="font-size:28px;">🎉</span>
                        <div>
                            <strong style="color:#065f46;font-size:15px;">ماہانہ کفالت کی وصولی کامیابی سے درج ہو گئی! (Voucher Created)</strong>
                            <div style="font-size:12.5px;color:#047857;margin-top:2px;">
                                رسید / واؤچر نمبر: <b style="font-family:monospace;font-size:13.5px;"><?=e($recVoucher['voucher_no'] ?? '')?></b> &bull; معاون: <b><?=e($recVoucher['donor_name'] ?? '')?></b> &bull; رقم: <b><?=money($recVoucher['total_amount'] ?? 0)?></b>
                            </div>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <a href="print_voucher.php?id=<?=$recVoucherId?>&autoprint=1" target="_blank" class="btn sm" style="background:#059669;color:#ffffff;border:none;font-weight:700;padding:8px 15px;border-radius:6px;display:inline-flex;align-items:center;gap:6px;box-shadow:0 1px 2px rgba(0,0,0,0.12);text-decoration:none;">
                            🖨️ Print Slip (رسید پرنٹ کریں)
                        </a>
                        <?php if (!empty($recVoucher['donor_phone'])): 
                            $recWaUrl = build_whatsapp_receipt_url($recVoucher, $madrasaName, $madrasaPhone);
                        ?>
                            <a href="<?=$recWaUrl?>" target="_blank" class="btn sm" style="background:#25D366;color:#ffffff;border:none;font-weight:700;padding:8px 15px;border-radius:6px;display:inline-flex;align-items:center;gap:6px;box-shadow:0 1px 2px rgba(0,0,0,0.12);text-decoration:none;">
                                📲 Send WhatsApp (واٹس ایپ رسید)
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Pledges Month Selector & Table Panel -->
            <article class="panel">
                <!-- Panel Header with Title on Left and Export/Print Buttons on Right (Exact User Request) -->
                <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;border-bottom:1px solid #f1f5f9;padding-bottom:14px;">
                    <div>
                        <h3 style="margin:0 0 4px;font-size:18px;color:#0f172a;">Monthly Pledges &amp; Kafalat Tracker <span class="ur">(ماہانہ کفالت و اقساط ٹریکر)</span></h3>
                        <p style="margin:0;font-size:12.5px;color:#64748b;">
                            Track regular donor contributions, sponsorship commitments, and follow up
                            <?php if ($filterPledgeFund > 0): ?>
                                <?php 
                                    $currFundName = '';
                                    foreach ($funds as $f) {
                                        if ((int)$f['id'] === $filterPledgeFund) {
                                            $currFundName = $f['name'];
                                            break;
                                        }
                                    }
                                ?>
                                &bull; <span style="color:#0284c7;font-weight:700;">Filtered: <?=e($currFundName)?></span>
                            <?php elseif ($filterPledgeFund === -1): ?>
                                &bull; <span style="color:#0284c7;font-weight:700;">Filtered: Unassigned (عام / غیر متعین)</span>
                            <?php endif; ?>
                        </p>
                    </div>

                    <!-- Excel, PDF & Print Actions Toolbar (Top Right Corner - Exactly parallel with Title) -->
                    <div class="no-print" style="display:inline-flex;align-items:center;gap:8px;flex-shrink:0;">
                        <?php
                        $pledgesShareUrl = (function_exists('get_app_base_url') ? get_app_base_url() : '') . "/donors.php?tab=pledges&month=" . urlencode($selectedMonth);
                        $waPledgesMsg = "السلام علیکم و رحمۃ اللہ و برکاتہ\n"
                                      . "🏢 *" . $madrasaName . "*\n"
                                      . "🎯 *ماہانہ کفالت و اقساط وصولی رپورٹ (Monthly Pledges Tracker)*\n"
                                      . "📅 *ماہ:* " . date('F Y', strtotime($selectedMonth . '-01')) . "\n"
                                      . "----------------------------------------\n"
                                      . "👥 *کل باقاعدہ معاونین:* " . count($activePledgers) . "\n"
                                      . "🎯 *ماہانہ ہدف:* " . money($totalMonthlyPledged) . "\n"
                                      . "✓ *موصول شدہ:* " . money($totalMonthlyReceived) . "\n"
                                      . "⏳ *بقایا اقساط:* " . money($totalMonthlyPending) . "\n"
                                      . "----------------------------------------\n"
                                      . "📄 *مکمل رپورٹ اور پی ڈی ایف دیکھنے / ڈاؤن لوڈ کرنے کے لیے نیچے لنک پر کلک کریں:* \n"
                                      . $pledgesShareUrl . "\n\n"
                                      . "جزاکم اللہ خیراً\n*" . $madrasaName . "*";
                        $waPledgesShareLink = "https://api.whatsapp.com/send?text=" . urlencode($waPledgesMsg);
                        ?>
                        <a href="<?=$waPledgesShareLink?>" target="_blank" class="btn sm" style="background:#25D366;color:#ffffff;border:1px solid #16a34a;font-weight:700;padding:7px 14px;font-size:13px;border-radius:6px;text-decoration:none;display:inline-flex;align-items:center;gap:6px;box-shadow:0 1px 2px rgba(0,0,0,0.06);white-space:nowrap;" title="Share monthly pledges summary & PDF link on WhatsApp">
                            💬 WhatsApp
                        </a>

                        <button type="button" onclick="openBulkRemindersModal()" class="btn sm" style="background:#ea580c;color:#ffffff;border:1px solid #c2410c;font-weight:700;padding:7px 12px;font-size:13px;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;gap:5px;box-shadow:0 1px 2px rgba(0,0,0,0.06);white-space:nowrap;" title="View all pending donors & send WhatsApp reminders">
                            📢 Reminders (<?=count($allPendingPledgers)?>)
                        </button>

                        <!-- Excel Export Button -->
                        <a href="donors.php?tab=pledges&export=excel&month=<?=urlencode($selectedMonth)?>&pledge_fund_id=<?=$filterPledgeFund?>&pledge_status=<?=urlencode($filterPledgeStatus)?>" class="btn sm" style="background:#15803d;color:#ffffff;border:1px solid #166534;font-weight:700;padding:7px 14px;font-size:13px;border-radius:6px;text-decoration:none;display:inline-flex;align-items:center;gap:6px;box-shadow:0 1px 2px rgba(0,0,0,0.06);white-space:nowrap;" title="Export filtered list to Excel (.CSV) - ایکسل ڈاؤنلوڈ">
                            📊 Excel
                        </a>

                        <!-- PDF Button -->
                        <button type="button" onclick="printPledges('pdf')" class="btn sm" style="background:#dc2626;color:#ffffff;border:1px solid #b91c1c;font-weight:700;padding:7px 14px;font-size:13px;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;box-shadow:0 1px 2px rgba(0,0,0,0.06);white-space:nowrap;" title="Save / Download as PDF - پی ڈی ایف محفوظ کریں">
                            📄 PDF
                        </button>

                        <!-- Print Button -->
                        <button type="button" onclick="printPledges('print')" class="btn secondary sm" style="background:#ffffff;color:#1e293b;border:1px solid #cbd5e1;font-weight:700;padding:7px 14px;font-size:13px;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;box-shadow:0 1px 2px rgba(0,0,0,0.06);white-space:nowrap;" title="Print Official Statement - پرنٹ کریں">
                            🖨️ Print
                        </button>
                    </div>
                </div>

                <!-- Clean Filters Row (Category, Status, Month) -->
                <div class="no-print" style="padding:14px 20px;background:#f8fafc;border-bottom:1px solid var(--line,#e2e8f0);">
                    <form method="get" style="display:flex;align-items:center;flex-wrap:wrap;gap:12px;">
                        <input type="hidden" name="tab" value="pledges">

                        <!-- Category / Dedicated Fund Filter -->
                        <div style="display:inline-flex;align-items:center;gap:6px;">
                            <label style="font-size:12px;font-weight:700;color:#334155;text-transform:uppercase;letter-spacing:0.4px;">Category <span class="ur">(کیٹیگری / فنڈ)</span>:</label>
                            <select name="pledge_fund_id" onchange="this.form.submit()" style="padding:6px 12px;border-radius:6px;border:1px solid #cbd5e1;font-size:13px;background:#ffffff;font-weight:600;color:#0f172a;cursor:pointer;min-width:180px;">
                                <option value="0">🏷️ All Categories (تمام کیٹیگریز)</option>
                                <?php foreach ($funds as $f): ?>
                                    <option value="<?=$f['id']?>" <?=$filterPledgeFund === (int)$f['id'] ? 'selected' : ''?>>
                                        <?=e($f['name'])?> (<?=e($f['fund_code'])?>)
                                    </option>
                                <?php endforeach; ?>
                                <option value="-1" <?=$filterPledgeFund === -1 ? 'selected' : ''?>>⚪ Unassigned (عام / غیر متعین)</option>
                            </select>
                        </div>

                        <!-- Status Filter -->
                        <div style="display:inline-flex;align-items:center;gap:6px;">
                            <label style="font-size:12px;font-weight:700;color:#334155;text-transform:uppercase;letter-spacing:0.4px;">Status <span class="ur">(حیثیت)</span>:</label>
                            <select name="pledge_status" onchange="this.form.submit()" style="padding:6px 10px;border-radius:6px;border:1px solid #cbd5e1;font-size:13px;background:#ffffff;font-weight:600;color:#0f172a;cursor:pointer;">
                                <option value="all" <?=$filterPledgeStatus === 'all' ? 'selected' : ''?>>All (تمام)</option>
                                <option value="pending" <?=$filterPledgeStatus === 'pending' ? 'selected' : ''?>>⏳ Pending Only (بقایا)</option>
                                <option value="paid" <?=$filterPledgeStatus === 'paid' ? 'selected' : ''?>>✓ Paid Only (ادا شدہ)</option>
                            </select>
                        </div>

                        <!-- Select Month -->
                        <div style="display:inline-flex;align-items:center;gap:6px;">
                            <label style="font-size:12px;font-weight:700;color:#334155;text-transform:uppercase;letter-spacing:0.4px;">Month <span class="ur">(مہینہ)</span>:</label>
                            <input type="month" name="month" value="<?=e($selectedMonth)?>" onchange="this.form.submit()" style="padding:6px 10px;border-radius:6px;border:1px solid #cbd5e1;font-size:13px;background:#ffffff;font-weight:600;color:#0f172a;cursor:pointer;">
                        </div>

                        <?php if ($filterPledgeFund !== 0 || $filterPledgeStatus !== 'all' || $selectedMonth !== date('Y-m')): ?>
                            <a href="donors.php?tab=pledges" class="btn secondary sm" style="padding:6px 10px;font-size:12px;display:inline-flex;align-items:center;gap:4px;" title="Reset All Filters">
                                ✕ Reset
                            </a>
                        <?php endif; ?>
                    </form>
                </div>

                <?php if (empty($activePledgers)): ?>
                    <div class="empty-state" style="padding:40px 20px;">
                        <div class="empty-icon">🤝</div>
                        <b>No active monthly pledgers found</b>
                        <?php if ($filterPledgeFund !== 0 || $filterPledgeStatus !== 'all'): ?>
                            <p style="margin-top:6px;color:#64748b;">No monthly pledges match the selected category or status filter for <?=date('F Y', strtotime($selectedMonth . '-01'))?>.</p>
                            <div style="margin-top:14px;">
                                <a href="donors.php?tab=pledges&month=<?=e($selectedMonth)?>" class="btn secondary sm">Clear Filters</a>
                            </div>
                        <?php else: ?>
                            <p>You can set a donor as a regular monthly contributor by editing their profile above and checking "Monthly Recurring Pledge".</p>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width:36px;text-align:center;">#</th>
                                    <th>Donor Name</th>
                                    <th>Phone / WhatsApp</th>
                                    <th>Dedicated Fund / Purpose</th>
                                    <th style="text-align:center;">Expected Day</th>
                                    <th class="right">Monthly Pledge</th>
                                    <th class="right">Paid (<?=date('M Y', strtotime($selectedMonth . '-01'))?>)</th>
                                    <th class="right">Due / Pending</th>
                                    <th style="text-align:center;">Status</th>
                                    <th class="right no-print">Action &amp; Follow-up</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $srNum = 1;
                                foreach ($activePledgers as $pl): 
                                    $pAmt = (float)$pl['pledge_amount'];
                                    $paidAmt = (float)$pl['paid_this_month'];
                                    $priorAmt = (float)($pl['prior_arrears'] ?? 0);
                                    $dueAmt = (float)($pl['due_this_month'] ?? max(0, $pAmt - $paidAmt));
                                    $totalDueAmt = (float)($pl['total_due_with_arrears'] ?? ($dueAmt + $priorAmt));
                                    $isPaid = ($paidAmt >= $pAmt);

                                    $cleanPhone = normalize_pakistan_phone($pl['phone'] ?? '');
                                    if (empty($cleanPhone)) {
                                        $cleanPhone = preg_replace('/[^0-9]/', '', (string)$pl['phone']);
                                        if (strpos($cleanPhone, '0') === 0) {
                                            $cleanPhone = '92' . substr($cleanPhone, 1);
                                        }
                                    }

                                    $monthNameUrdu = date('F Y', strtotime($selectedMonth . '-01'));
                                    $fundName = trim($pl['pledge_fund_name'] ?: ($pl['fund_name'] ?: ''));
                                    $fundPart = !empty($fundName) ? " برائے فنڈ (" . $fundName . ")" : "";

                                    $amountUrdu = money($pAmt);
                                    if ($paidAmt > 0 && $dueAmt > 0) {
                                        $amountUrdu = money($pAmt) . " (موصول: " . money($paidAmt) . "، بقیہ واجب الادا: " . money($dueAmt) . ")";
                                    }

                                    if ($isPaid) {
                                        if ($priorAmt > 0) {
                                            $remText = "السلام علیکم ورحمۃ اللہ وبرکاتہ\n\n" .
                                                       "محترم " . $pl['donor_name'] . " صاحب!\n" .
                                                       $madrasaName . " کی جانب سے دلی دعا اور نیک تمنائیں قبول فرمائیں۔\n\n" .
                                                       "آپ کی جانب سے مقررہ ماہانہ تعاون / کفالت" . $fundPart . " برائے ماہ (" . $monthNameUrdu . ") رقم: " . money($pAmt) . " بحمداللہ موصول ہو چکی ہے۔ آپ کے اس پرخلوص تعاون کے لیے ہم تہہ دل سے شکر گزار ہیں۔\n\n" .
                                                       "مطلع فرمایا جاتا ہے کہ آپ کے کھاتے میں سابقہ بقایا اقساط رقم: " . money($priorAmt) . " واجب الادا ہے۔ جب بھی سہولت ہو اس کی ادائیگی فرما کر اجر دارین حاصل فرمائیں۔\n\n" .
                                                       "جزاکم اللہ خیراً و احسن الجزاء\n" .
                                                       $madrasaName . (!empty($madrasaPhone) ? "\nرابطہ: " . $madrasaPhone : "");
                                        } else {
                                            $remText = "السلام علیکم ورحمۃ اللہ وبرکاتہ\n\n" .
                                                       "محترم " . $pl['donor_name'] . " صاحب!\n" .
                                                       $madrasaName . " کی جانب سے دلی دعا اور نیک تمنائیں قبول فرمائیں۔\n\n" .
                                                       "آپ کی جانب سے مقررہ ماہانہ تعاون / کفالت" . $fundPart . " برائے ماہ (" . $monthNameUrdu . ") رقم: " . money($pAmt) . " بحمداللہ موصول ہو چکی ہے۔ آپ کے اس پرخلوص تعاون کے لیے ہم تہہ دل سے شکر گزار ہیں۔\n\n" .
                                                       "اللہ تعالیٰ آپ کے جان و مال میں برکت عطا فرمائے اور اس صدقہ جاریہ کو اپنی بارگاہ میں قبول فرمائے۔ آمین\n\n" .
                                                       "جزاکم اللہ خیراً و احسن الجزاء\n" .
                                                       $madrasaName . (!empty($madrasaPhone) ? "\nرابطہ: " . $madrasaPhone : "");
                                        }
                                    } else {
                                        $arrearsNotice = ($priorAmt > 0) ? "\nاور سابقہ غیر ادا شدہ بقایا جات: " . money($priorAmt) . "\nکل واجب الادا رقم: " . money($totalDueAmt) : "";
                                        $remText = "السلام علیکم ورحمۃ اللہ وبرکاتہ\n\n" .
                                                   "محترم " . $pl['donor_name'] . " صاحب!\n" .
                                                   $madrasaName . " کی جانب سے دلی دعا اور نیک تمنائیں قبول فرمائیں۔\n\n" .
                                                   "آپ کی جانب سے مقررہ ماہانہ تعاون / کفالت" . $fundPart . " برائے ماہ (" . $monthNameUrdu . ") رقم: " . $amountUrdu . $arrearsNotice . " کا شائستہ یاد دہانی پیغام ارسال خدمت ہے۔\n\n" .
                                                   "اگر آپ یہ رقم ادا فرما چکے ہیں تو برائے مہربانی رسید یا اسکرین شاٹ اس نمبر پر ارسال فرما دیں تاکہ ریکارڈ اپ ڈیٹ کیا جا سکے۔\n\n" .
                                                   "جزاکم اللہ خیراً و احسن الجزاء\n" .
                                                   $madrasaName . (!empty($madrasaPhone) ? "\nرابطہ: " . $madrasaPhone : "");
                                    }

                                    $waUrl = "https://api.whatsapp.com/send?phone={$cleanPhone}&text=" . urlencode($remText);
                                    $fundToUse = $pl['pledge_fund_id'] ?: $pl['fund_id'];
                                ?>
                                    <tr>
                                        <td style="text-align:center;color:#64748b;font-size:11px;"><?= $srNum++ ?></td>
                                        <td>
                                            <b><?=e($pl['donor_name'])?></b>
                                            <?php if (stripos($pl['notes'] ?? '', 'آن لائن') !== false || stripos($pl['pledge_notes'] ?? '', 'آن لائن') !== false || stripos($pl['notes'] ?? '', 'Online') !== false): ?>
                                                <span class="badge" style="background:#dbeafe;color:#1e40af;font-size:10px;padding:1px 5px;border-radius:4px;margin-left:4px;font-weight:700;" title="آن لائن پورٹل سے رجسٹرڈ">
                                                    🌐 Online
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($priorAmt > 0): ?>
                                                <div style="margin-top:4px;">
                                                    <span class="badge" style="background:#fff7ed;color:#c2410c;border:1px solid #fed7aa;font-size:10px;font-weight:700;padding:2px 7px;border-radius:10px;" title="Past unpaid arrears (سابقہ غیر ادا شدہ اقساط)">
                                                        ⚠️ سابقہ بقایا: <?=money($priorAmt)?><?=$pl['prior_unpaid_months'] > 0 ? " ({$pl['prior_unpaid_months']} ماہ)" : ''?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($pl['pledge_notes']): ?>
                                                <div style="font-size:11px;color:#0284c7;font-weight:600;margin-top:2px;">🏷️ <?=e($pl['pledge_notes'])?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?=e($pl['phone'] ?: '—')?></td>
                                        <td>
                                            <span class="badge badge-blue" style="font-size:11.5px;">
                                                <?=e($pl['pledge_fund_name'] ?: ($pl['fund_name'] ?: 'General'))?>
                                            </span>
                                        </td>
                                        <td style="text-align:center;"><span class="badge badge-gray"><?= (int)$pl['pledge_day'] ?>th of month</span></td>
                                        <td class="font-bold right" style="color:#1e293b;"><?=money($pAmt)?></td>
                                        <td class="font-bold right" style="color:<?=$isPaid ? '#15803d' : '#b91c1c'?>;">
                                            <?=money($paidAmt)?>
                                        </td>
                                        <td class="font-bold right" style="color:<?=$totalDueAmt > 0 ? '#b91c1c' : '#15803d'?>;">
                                            <?=money($dueAmt)?>
                                            <?php if ($priorAmt > 0): ?>
                                                <div style="font-size:10.5px;color:#c2410c;font-weight:700;margin-top:2px;" title="This month due + previous arrears">
                                                    کل: <?=money($totalDueAmt)?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:center;">
                                            <?php if ($isPaid && $priorAmt <= 0): ?>
                                                <span class="badge" style="background:#dcfce7;color:#15803d;font-weight:700;font-size:11px;padding:3px 8px;border-radius:12px;">
                                                    ✓ Paid (مکمل ادا)
                                                </span>
                                            <?php elseif ($isPaid && $priorAmt > 0): ?>
                                                <span class="badge" style="background:#fef3c7;color:#b45309;font-weight:700;font-size:10.5px;padding:3px 8px;border-radius:12px;" title="Current month cleared but previous arrears remain">
                                                    ✓ یہ ماہ ادا (سابقہ باقی)
                                                </span>
                                            <?php else: ?>
                                                <span class="badge" style="background:#fee2e2;color:#b91c1c;font-weight:700;font-size:11px;padding:3px 8px;border-radius:12px;">
                                                    ⏳ Pending (بقایا)
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="right no-print" style="white-space:nowrap;">
                                            <div style="display:inline-flex;align-items:center;gap:6px;">
                                                <?php 
                                                    $pledgeDayNum = !empty($pl['expected_day']) ? (int)$pl['expected_day'] : 1;
                                                    if ($pledgeDayNum < 1 || $pledgeDayNum > 28) $pledgeDayNum = 1;
                                                    $pledgeVoucherDate = $selectedMonth . '-' . str_pad((string)$pledgeDayNum, 2, '0', STR_PAD_LEFT);
                                                    $quickReceiveData = [
                                                        'donor_id' => (int)$pl['id'],
                                                        'donor_name' => $pl['donor_name'],
                                                        'phone' => $pl['phone'] ?? '',
                                                        'amount' => (float)$pAmt,
                                                        'due_amount' => (float)($dueAmt > 0 ? $dueAmt : $pAmt),
                                                        'prior_arrears' => (float)$priorAmt,
                                                        'total_due_with_arrears' => (float)$totalDueAmt,
                                                        'fund_id' => (int)$fundToUse,
                                                        'fund_name' => (string)($fundName ?: 'Trust General Fund'),
                                                        'month' => date('F Y', strtotime($selectedMonth . '-01')),
                                                        'target_date' => $pledgeVoucherDate,
                                                        'desc' => 'ماہانہ کفالت برائے فنڈ ' . ($fundName ?: 'جنرل') . ' بابت ماہ ' . $monthNameUrdu
                                                    ];
                                                ?>
                                                <button type="button" 
                                                        class="btn success sm" 
                                                        style="background:#15803d;color:#fff;padding:4px 10px;font-size:12px;font-weight:700;border:none;border-radius:4px;cursor:pointer;display:inline-flex;align-items:center;gap:4px;" 
                                                        title="Quick Receive this month's pledge (فوری وصولی)"
                                                        onclick='openQuickReceiveModal(<?=json_encode($quickReceiveData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)?>)'>
                                                    💰 Receive
                                                </button>
                                                <?php if (!empty($cleanPhone)): ?>
                                                    <a href="<?=e($waUrl)?>" target="_blank" class="btn sm" style="background:#25D366;color:#fff;border-color:#25D366;padding:4px 9px;font-size:11.5px;font-weight:600;text-decoration:none;border-radius:4px;" title="Send polite WhatsApp Reminder">
                                                        💬 WhatsApp
                                                    </a>
                                                <?php endif; ?>
                                                <a href="donors.php?edit_id=<?=$pl['id']?>#donorFormCard" class="btn secondary sm" style="padding:4px 8px;font-size:11.5px;" title="Edit Donor">
                                                    ✏️
                                                </a>
                                                <?php if (has_permission('donors.manage')): ?>
                                                    <a href="donors.php?tab=pledges&month=<?=urlencode($selectedMonth)?>&pledge_fund_id=<?=$filterPledgeFund?>&pledge_status=<?=urlencode($filterPledgeStatus)?>&delete_id=<?=$pl['id']?>&token=<?=csrf_token()?>" class="btn danger sm" style="padding:4px 8px;font-size:11.5px;" onclick="return confirm('Are you sure you want to delete donor \'<?=e(addslashes($pl['donor_name']))?>\'?');" title="Delete Donor">
                                                        🗑️
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="background:#f8fafc;font-weight:800;border-top:2px solid #cbd5e1;">
                                    <td colspan="5" style="text-align:right;">
                                        Total (کل خلاصہ و میزان):
                                    </td>
                                    <td class="font-bold right" style="color:#1e293b;"><?=money($totalMonthlyPledged)?></td>
                                    <td class="font-bold right" style="color:#15803d;"><?=money($totalMonthlyReceived)?></td>
                                    <td class="font-bold right" style="color:<?=$totalMonthlyPending > 0 || $totalPriorArrearsAll > 0 ? '#b91c1c' : '#15803d'?>;">
                                        <?=money($totalMonthlyPending)?>
                                        <?php if ($totalPriorArrearsAll > 0): ?>
                                            <div style="font-size:10px;color:#c2410c;font-weight:700;margin-top:2px;">
                                                + <?=money($totalPriorArrearsAll)?> سابقہ (کل: <?=money($totalMonthlyPending + $totalPriorArrearsAll)?>)
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center;font-size:11.5px;color:#0f172a;">
                                        <?=$totalMonthlyPledged > 0 ? round(($totalMonthlyReceived / $totalMonthlyPledged) * 100, 1) : 0?>% Rec.
                                    </td>
                                    <td class="no-print"></td>
                                </tr>
                            </tfoot>
                        </table>

                        <!-- Official Signature Block (Visible only when printed or exported to PDF) -->
                        <div class="print-only sign-block" style="margin-top:40px;page-break-inside:avoid;">
                            <div style="display:flex;justify-content:space-between;padding:0 30px;">
                                <div style="text-align:center;min-width:160px;">
                                    <div style="border-top:1.5px solid #0f172a;padding-top:6px;font-size:11.5px;font-weight:700;color:#0f172a;">
                                        <?=e($printSignTitle1)?>
                                    </div>
                                    <div style="font-size:10px;color:#64748b;margin-top:2px;"><?=e($currentUser['name'] ?? 'Admin')?></div>
                                </div>
                                <div style="text-align:center;min-width:160px;">
                                    <div style="border-top:1.5px solid #0f172a;padding-top:6px;font-size:11.5px;font-weight:700;color:#0f172a;">
                                        <?=e($printSignTitle2)?>
                                    </div>
                                    <div style="font-size:10px;color:#64748b;margin-top:2px;">Accounts Dept</div>
                                </div>
                                <div style="text-align:center;min-width:160px;">
                                    <div style="border-top:1.5px solid #0f172a;padding-top:6px;font-size:11.5px;font-weight:700;color:#0f172a;">
                                        <?=e($printSignTitle3)?>
                                    </div>
                                    <div style="font-size:10px;color:#64748b;margin-top:2px;">Official Seal &amp; Signature</div>
                                </div>
                            </div>
                            <div style="text-align:center;margin-top:24px;font-size:10px;color:#94a3b8;border-top:1px solid #e2e8f0;padding-top:6px;">
                                <?=e($printFooterNote)?> &bull; <?=e($madrasaName)?> &bull; Printed: <?=date('d-m-Y H:i:s')?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </article>
        <?php endif; ?>

        <!-- Quick Receive Pledge Modal (1-Click Instant Receipt) -->
        <div id="quickReceiveModal" class="modal-backdrop no-print" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.65);z-index:99999;align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(3px);">
            <div style="background:#ffffff;border-radius:14px;width:100%;max-width:480px;box-shadow:0 25px 35px -5px rgba(0,0,0,0.3);overflow:hidden;border:1px solid #cbd5e1;">
                <div style="padding:16px 20px;background:#0f172a;color:#ffffff;display:flex;justify-content:space-between;align-items:center;">
                    <div style="font-weight:700;font-size:15px;display:flex;align-items:center;gap:8px;">
                        <span>💰</span>
                        <span>Quick Receive Monthly Pledge <span class="ur">(فوری ماہانہ وصولی)</span></span>
                    </div>
                    <button type="button" onclick="closeQuickReceiveModal()" style="background:transparent;border:none;color:#94a3b8;font-size:24px;cursor:pointer;line-height:1;" title="Close (بند کریں)">&times;</button>
                </div>

                <form method="post" action="donors.php?tab=pledges" style="padding:20px;">
                    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                    <input type="hidden" name="action" value="quick_receive_pledge">
                    <input type="hidden" name="return_month" value="<?=e($selectedMonth)?>">
                    <input type="hidden" name="return_fund_id" value="<?=$filterPledgeFund?>">
                    <input type="hidden" name="return_status" value="<?=e($filterPledgeStatus)?>">
                    <input type="hidden" name="donor_id" id="qrm_donor_id" value="">
                    <input type="hidden" name="fund_id" id="qrm_fund_id" value="">

                    <!-- Donor & Target Month Info Box -->
                    <div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:10px;padding:12px 16px;margin-bottom:16px;">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                            <div>
                                <div style="font-size:11px;text-transform:uppercase;color:#64748b;font-weight:700;letter-spacing:0.5px;">معاون / ڈونر:</div>
                                <div style="font-weight:800;font-size:16px;color:#0f172a;margin-top:2px;" id="qrm_donor_name">Donor Name</div>
                                <div style="font-size:12px;color:#475569;margin-top:3px;" id="qrm_donor_phone">0300-0000000</div>
                            </div>
                            <div style="text-align:right;">
                                <span class="badge" style="background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;font-weight:700;font-size:11.5px;padding:3px 8px;" id="qrm_month">Month</span>
                                <div style="font-size:11.5px;color:#0284c7;font-weight:700;margin-top:5px;" id="qrm_fund_name">Fund</div>
                            </div>
                        </div>
                    </div>

                    <!-- Arrears Notice and Quick Selection -->
                    <div id="qrm_arrears_box" style="display:none;background:#fff7ed;border:1.5px solid #fed7aa;border-radius:8px;padding:10px 14px;margin-bottom:14px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                            <div style="font-size:12px;color:#9a3412;">
                                <strong>⚠️ سابقہ بقایا موجود ہے:</strong>
                                <span id="qrm_prior_amount" style="font-weight:800;color:#c2410c;">Rs. 0.00</span>
                                <div style="font-size:11px;color:#7c2d12;">کل واجب الادا (سابقہ + موجودہ): <strong id="qrm_total_due_text">Rs. 0.00</strong></div>
                            </div>
                            <div style="display:flex;gap:6px;">
                                <button type="button" class="btn sm" onclick="setReceiveAmountType('month_only')" style="background:#ffffff;border:1px solid #cbd5e1;font-size:11px;font-weight:700;padding:4px 8px;border-radius:4px;cursor:pointer;">
                                    صرف یہ ماہ
                                </button>
                                <button type="button" class="btn sm" onclick="setReceiveAmountType('total_due')" style="background:#ea580c;color:#ffffff;border:none;font-size:11px;font-weight:700;padding:4px 10px;border-radius:4px;cursor:pointer;">
                                    سابقہ ملا کر وصول کریں
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Amount & Receipt Date Grid -->
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
                        <div>
                            <label style="display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:5px;">
                                Received Amount <span class="ur">(وصول شدہ رقم PKR)</span> *
                            </label>
                            <input type="number" step="0.01" min="1" name="amount" id="qrm_amount" required style="width:100%;font-size:17px;font-weight:800;color:#15803d;padding:8px 12px;border:1.5px solid #94a3b8;border-radius:6px;box-sizing:border-box;">
                        </div>
                        <div>
                            <label style="display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:5px;">
                                Receipt Date <span class="ur">(تاریخ رسید)</span> *
                            </label>
                            <input type="date" name="voucher_date" id="qrm_date" required style="width:100%;font-size:13.5px;font-weight:600;padding:9px 12px;border:1.5px solid #94a3b8;border-radius:6px;box-sizing:border-box;">
                        </div>
                    </div>

                    <!-- Payment Method & Deposit Into Grid -->
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
                        <div>
                            <label style="display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:5px;">
                                Payment Method <span class="ur">(طریقہ کار)</span> *
                            </label>
                            <select name="payment_method" id="qrm_payment_method" onchange="onPaymentMethodChange()" style="width:100%;font-size:12.5px;font-weight:700;padding:9px 10px;border:1.5px solid #94a3b8;border-radius:6px;box-sizing:border-box;background:#f8fafc;">
                                <option value="cash">💵 Cash (نقد وصولی)</option>
                                <option value="bank_transfer">🏛️ Bank / Online Transfer (بینک منتقلی)</option>
                                <option value="cheque">📝 Cheque (بینک چیک)</option>
                            </select>
                        </div>
                        <div>
                            <label style="display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:5px;">
                                Deposit Into <span class="ur">(جمع کھاتہ)</span> *
                            </label>
                            <select name="asset_account_id" id="qrm_asset_account_id" onchange="onDepositAccountChange()" required style="width:100%;font-size:12.5px;font-weight:700;padding:9px 10px;border:1.5px solid #94a3b8;border-radius:6px;box-sizing:border-box;">
                                <?php if (!empty($userCashAcc)): ?>
                                    <optgroup label="💼 آپ کا ذاتی نقد کھاتہ (Your Cash Custody)">
                                        <option value="<?=$userCashAcc['id']?>" data-type="cash" selected>
                                            <?=e($userCashAcc['account_code'])?> - <?=e(format_ui_text($userCashAcc['account_name']))?> (آپ کا کیش)
                                        </option>
                                    </optgroup>
                                <?php endif; ?>

                                <?php if (!empty($bankAccounts)): ?>
                                    <optgroup label="🏛️ مدرسہ / ٹرسٹ بینک اکاؤنٹس (Bank Accounts)">
                                        <?php foreach ($bankAccounts as $bAcc): ?>
                                            <option value="<?=$bAcc['id']?>" data-type="bank">
                                                <?=e($bAcc['account_code'])?> - <?=e(format_ui_text($bAcc['account_name']))?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>

                                <?php if (can_approve() && !empty($otherCashAccounts)): ?>
                                    <optgroup label="👥 دیگر عملہ کیش اکاؤنٹس (Other Staff Cash)">
                                        <?php foreach ($otherCashAccounts as $oAcc): ?>
                                            <option value="<?=$oAcc['id']?>" data-type="other_cash">
                                                <?=e($oAcc['account_code'])?> - <?=e(format_ui_text($oAcc['account_name']))?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Bank Ref / Cheque No Box (Automatically shown when Bank or Cheque is selected) -->
                    <div id="qrm_ref_container" style="display:none;margin-bottom:14px;background:#f0fdf4;border:1.5px solid #86efac;border-radius:8px;padding:10px 14px;">
                        <label style="display:block;font-size:12px;font-weight:700;color:#166534;margin-bottom:5px;">
                            Bank Transfer Ref / Cheque No <span class="ur">(بینک رسید / آن لائن ٹرانزیکشن یا چیک نمبر)</span>
                        </label>
                        <input type="text" name="reference_no" id="qrm_reference_no" style="width:100%;font-size:12.5px;padding:8px 12px;border:1px solid #86efac;border-radius:6px;box-sizing:border-box;" placeholder="e.g. TR-98214 یا Cheque # 0019284">
                    </div>

                    <!-- Description / Narration -->
                    <div style="margin-bottom:18px;">
                        <label style="display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:5px;">
                            Description / Note <span class="ur">(تفصیل و دعا)</span>
                        </label>
                        <input type="text" name="description" id="qrm_description" style="width:100%;font-size:12.5px;padding:9px 12px;border:1px solid #cbd5e1;border-radius:6px;box-sizing:border-box;" placeholder="ماہانہ کفالت عطیہ">
                    </div>

                    <!-- Modal Actions & Full Form Link -->
                    <div style="display:flex;justify-content:space-between;align-items:center;padding-top:14px;border-top:1px solid #e2e8f0;flex-wrap:wrap;gap:10px;">
                        <a href="income.php" id="qrm_adv_link" target="_blank" style="font-size:11.5px;color:#2563eb;text-decoration:none;" title="Open full Income form for foreign currency or attachments">
                            Advanced Form ↗ <span class="ur">(تفصیلی فارم)</span>
                        </a>
                        <div style="display:flex;gap:8px;">
                            <button type="button" onclick="closeQuickReceiveModal()" class="btn secondary" style="padding:9px 16px;font-weight:600;">
                                Cancel
                            </button>
                            <button type="submit" class="btn" style="background:#15803d;color:#ffffff;border:none;padding:9px 20px;font-weight:700;font-size:13.5px;border-radius:6px;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,0.15);">
                                ✅ Confirm &amp; Issue Receipt <span class="ur">(وصول کریں)</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Bulk WhatsApp Reminders Modal -->
        <div id="bulkRemindersModal" class="modal-backdrop no-print" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.65);z-index:99999;align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(3px);">
            <div style="background:#ffffff;border-radius:14px;width:100%;max-width:640px;box-shadow:0 25px 35px -5px rgba(0,0,0,0.3);overflow:hidden;border:1px solid #cbd5e1;max-height:88vh;display:flex;flex-direction:column;">
                <div style="padding:16px 20px;background:#0f172a;color:#ffffff;display:flex;justify-content:space-between;align-items:center;">
                    <div style="font-weight:700;font-size:15px;display:flex;align-items:center;gap:8px;">
                        <span>📢</span>
                        <span>Pending Reminders List <span class="ur">(بقایا دار معاونین کی فہرست و یاد دہانی)</span></span>
                    </div>
                    <button type="button" onclick="closeBulkRemindersModal()" style="background:transparent;border:none;color:#94a3b8;font-size:24px;cursor:pointer;line-height:1;" title="Close">&times;</button>
                </div>

                <div style="padding:12px 18px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                    <div style="font-size:12.5px;color:#334155;">
                        <strong>ماہ:</strong> <?=date('F Y', strtotime($selectedMonth . '-01'))?> &bull; 
                        <strong>کل بقایا دار معاونین:</strong> <span style="color:#b91c1c;font-weight:800;"><?=count($allPendingPledgers)?></span>
                    </div>
                    <button type="button" onclick="copyPendingPhones()" class="btn secondary sm" style="font-size:11.5px;padding:4px 10px;font-weight:700;display:inline-flex;align-items:center;gap:4px;">
                        📋 Copy All Phone Numbers
                    </button>
                </div>

                <div style="padding:16px 18px;overflow-y:auto;flex:1;">
                    <?php if (empty($allPendingPledgers)): ?>
                        <div style="text-align:center;padding:30px;color:#15803d;font-weight:700;">
                            🎉 تمام معاونین کی اس ماہ کی کفالت موصول ہو چکی ہے، کوئی بقایا دار نہیں!
                        </div>
                    <?php else: ?>
                        <div style="display:flex;flex-direction:column;gap:10px;">
                            <?php 
                            $phoneList = [];
                            foreach ($allPendingPledgers as $pp): 
                                $clPhone = normalize_pakistan_phone($pp['phone'] ?? '');
                                if (!empty($clPhone)) $phoneList[] = $clPhone;
                                $remWaUrl = function_exists('build_whatsapp_reminder_url') ? build_whatsapp_reminder_url($pp, $madrasaName, $madrasaPhone, date('F Y', strtotime($selectedMonth . '-01'))) : '';
                            ?>
                                <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;gap:10px;">
                                    <div style="min-width:0;flex:1;">
                                        <strong style="font-size:13.5px;color:#0f172a;display:block;"><?=e($pp['donor_name'])?></strong>
                                        <div style="font-size:12px;color:#64748b;margin-top:2px;">
                                            📞 <?=e($pp['phone'] ?: 'بغیر فون نمبر')?> &bull; 
                                            واجب الادا: <strong style="color:#b91c1c;"><?=money($pp['total_due_with_arrears'] ?? $pp['pledge_amount'])?></strong>
                                            <?php if (!empty($pp['pledge_fund_name'])): ?>
                                                &bull; <span style="color:#0284c7;"><?=e($pp['pledge_fund_name'])?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div style="flex-shrink:0;">
                                        <?php if (!empty($clPhone)): ?>
                                            <a href="<?=$remWaUrl?>" target="_blank" class="btn sm" style="background:#25D366;color:#ffffff;border:none;font-weight:700;font-size:11.5px;padding:6px 12px;border-radius:5px;display:inline-flex;align-items:center;gap:4px;text-decoration:none;">
                                                📲 WhatsApp
                                            </a>
                                        <?php else: ?>
                                            <span style="font-size:11px;color:#94a3b8;">No Phone</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <textarea id="hiddenPendingPhones" style="display:none;"><?=implode(', ', $phoneList)?></textarea>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?=render_app_footer($madrasaName)?>
    </main>
</div>

<script>
var currentQuickData = null;
var defaultUserCashId = "<?=!empty($userCashAcc) ? (int)$userCashAcc['id'] : 0?>";
var defaultBankId = "<?=!empty($bankAccounts) ? (int)$bankAccounts[0]['id'] : 0?>";

function onPaymentMethodChange() {
    var pm = document.getElementById('qrm_payment_method');
    var accSelect = document.getElementById('qrm_asset_account_id');
    var refBox = document.getElementById('qrm_ref_container');
    if (!pm || !accSelect) return;

    if (pm.value === 'cash') {
        var cashOpt = accSelect.querySelector('option[data-type="cash"]');
        if (cashOpt) {
            accSelect.value = cashOpt.value;
        } else if (defaultUserCashId && defaultUserCashId !== '0') {
            accSelect.value = defaultUserCashId;
        }
        if (refBox) refBox.style.display = 'none';
    } else {
        // Bank or Cheque
        var bankOpt = accSelect.querySelector('option[data-type="bank"]');
        if (bankOpt) {
            accSelect.value = bankOpt.value;
        } else if (defaultBankId && defaultBankId !== '0') {
            accSelect.value = defaultBankId;
        }
        if (refBox) {
            refBox.style.display = 'block';
            var refInput = document.getElementById('qrm_reference_no');
            if (refInput) refInput.focus();
        }
    }
}

function onDepositAccountChange() {
    var accSelect = document.getElementById('qrm_asset_account_id');
    var pm = document.getElementById('qrm_payment_method');
    var refBox = document.getElementById('qrm_ref_container');
    if (!accSelect || !pm) return;

    var selectedOpt = accSelect.options[accSelect.selectedIndex];
    var accType = selectedOpt ? selectedOpt.getAttribute('data-type') : '';

    if (accType === 'bank') {
        if (pm.value === 'cash') {
            pm.value = 'bank_transfer';
        }
        if (refBox) refBox.style.display = 'block';
    } else if (accType === 'cash') {
        pm.value = 'cash';
        if (refBox) refBox.style.display = 'none';
    }
}

function setReceiveAmountType(type) {
    if (!currentQuickData) return;
    var amtInput = document.getElementById('qrm_amount');
    var descInput = document.getElementById('qrm_description');
    if (type === 'total_due') {
        amtInput.value = currentQuickData.total_due_with_arrears || currentQuickData.due_amount;
        if (descInput) {
            descInput.value = currentQuickData.desc + ' (بشمول سابقہ بقایا جات)';
        }
    } else {
        amtInput.value = currentQuickData.due_amount || currentQuickData.amount;
        if (descInput) {
            descInput.value = currentQuickData.desc;
        }
    }
    amtInput.focus();
    amtInput.select();
}

function openQuickReceiveModal(data) {
    if (!data) return;
    currentQuickData = data;
    document.getElementById('qrm_donor_id').value = data.donor_id || '';
    document.getElementById('qrm_fund_id').value = data.fund_id || '';
    document.getElementById('qrm_donor_name').textContent = data.donor_name || 'معاون';
    document.getElementById('qrm_donor_phone').textContent = data.phone ? ('📞 ' + data.phone) : 'بغیر فون نمبر';
    document.getElementById('qrm_month').textContent = data.month || '';
    document.getElementById('qrm_fund_name').textContent = '🏷️ ' + (data.fund_name || 'General');
    document.getElementById('qrm_amount').value = data.due_amount || data.amount || 0;
    document.getElementById('qrm_date').value = data.target_date || new Date().toISOString().split('T')[0];
    document.getElementById('qrm_description').value = data.desc || '';

    // Reset Payment Method & Deposit Account to User's Own Cash Account by Default
    var pm = document.getElementById('qrm_payment_method');
    if (pm) pm.value = 'cash';
    var accSelect = document.getElementById('qrm_asset_account_id');
    if (accSelect && defaultUserCashId && defaultUserCashId !== '0') {
        accSelect.value = defaultUserCashId;
    }
    var refBox = document.getElementById('qrm_ref_container');
    if (refBox) refBox.style.display = 'none';
    var refInput = document.getElementById('qrm_reference_no');
    if (refInput) refInput.value = '';

    // Handle Prior Arrears Notice
    var arrearsBox = document.getElementById('qrm_arrears_box');
    if (arrearsBox) {
        if (data.prior_arrears && data.prior_arrears > 0) {
            arrearsBox.style.display = 'block';
            document.getElementById('qrm_prior_amount').textContent = 'Rs. ' + Number(data.prior_arrears).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('qrm_total_due_text').textContent = 'Rs. ' + Number(data.total_due_with_arrears).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        } else {
            arrearsBox.style.display = 'none';
        }
    }

    // Advanced full form fallback link
    var advLink = document.getElementById('qrm_adv_link');
    if (advLink) {
        advLink.href = 'income.php?donor_id=' + encodeURIComponent(data.donor_id) +
            '&amount=' + encodeURIComponent(data.due_amount || data.amount) +
            '&fund_id=' + encodeURIComponent(data.fund_id) +
            '&date=' + encodeURIComponent(data.target_date) +
            '&desc=' + encodeURIComponent(data.desc);
    }

    var m = document.getElementById('quickReceiveModal');
    if (m) {
        m.style.display = 'flex';
        setTimeout(function() {
            var amt = document.getElementById('qrm_amount');
            if (amt) { amt.focus(); amt.select(); }
        }, 120);
    }
}

function closeQuickReceiveModal() {
    var m = document.getElementById('quickReceiveModal');
    if (m) m.style.display = 'none';
}

// Close modal if user clicks outside of modal dialog
window.addEventListener('click', function(e) {
    var m = document.getElementById('quickReceiveModal');
    if (e.target === m) {
        closeQuickReceiveModal();
    }
});

function togglePledgeFields(isChecked) {
    var c = document.getElementById('pledgeFieldsContainer');
    if (c) c.style.display = isChecked ? 'grid' : 'none';
}
function toggleDonorForm(forceOpen) {
    var card = document.getElementById('donorFormCard');
    var btn = document.getElementById('toggleDonorBtn');
    if (!card) return;

    var isHidden = (card.style.display === 'none' || getComputedStyle(card).display === 'none');
    var open = (forceOpen !== undefined) ? forceOpen : isHidden;

    if (open) {
        card.style.display = 'block';
        if (btn) {
            btn.innerHTML = '✕ Close Form <span class="ur">(بند کریں)</span>';
            btn.classList.remove('blue');
            btn.classList.add('secondary');
        }
        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
        var nameInput = card.querySelector('input[name="donor_name"]');
        if (nameInput) setTimeout(function() { nameInput.focus(); }, 250);
    } else {
        card.style.display = 'none';
        if (btn) {
            btn.innerHTML = '＋ Register New Donor';
            btn.classList.remove('secondary');
            btn.classList.add('blue');
        }
    }
}
function openBulkRemindersModal() {
    var m = document.getElementById('bulkRemindersModal');
    if (m) m.style.display = 'flex';
}
function closeBulkRemindersModal() {
    var m = document.getElementById('bulkRemindersModal');
    if (m) m.style.display = 'none';
}
function copyPendingPhones() {
    var el = document.getElementById('hiddenPendingPhones');
    if (el && el.value) {
        navigator.clipboard.writeText(el.value).then(function() {
            alert('تمام بقایا داروں کے فون نمبرز کاپی ہو گئے ہیں:\n' + el.value);
        });
    } else {
        alert('کوئی فون نمبر موجود نہیں ہے۔');
    }
}

// Close modals if clicked outside
window.addEventListener('click', function(e) {
    var b = document.getElementById('bulkRemindersModal');
    if (e.target === b) closeBulkRemindersModal();
});

function copyPortalLink() {
    var url = '<?=e(get_app_base_url())?>/donate.php';
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(url).then(function() {
            alert('✓ آن لائن ڈونیشن پورٹل کا لنک کاپی ہو گیا ہے!\n\n' + url + '\n\nاسے واٹس ایپ گروپس، اسٹیٹس یا سوشل میڈیا پر شیئر کریں تاکہ لوگ براہِ راست عطیات و کفالت درج کر سکیں۔');
        });
    } else {
        prompt('آن لائن پورٹل کا لنک کاپی کریں:', url);
    }
}

function printPledges(mode) {
    var originalTitle = document.title;
    if (mode === 'pdf') {
        document.title = "Monthly_Pledges_<?=date('F_Y', strtotime($selectedMonth . '-01'))?>";
    }
    window.print();
    setTimeout(function() {
        document.title = originalTitle;
    }, 1500);
}
</script>
</body>
</html>
