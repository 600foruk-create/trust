<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

if (!has_permission('users.manage') && !has_permission('team.manage')) {
    http_response_code(403);
    exit('آپ کے پاس صارفین یا ٹیم مینج کرنے کا اختیار نہیں ہے۔');
}

$currentUser = current_user();
$currentUserId = (int)($currentUser['id'] ?? 0);
$isSuperAdmin = function_exists('is_super_admin') ? is_super_admin($currentUser) : in_array($currentUser['role'] ?? '', ['Super Admin', 'Administrator'], true);
$isRegDirector = function_exists('is_regional_director') ? is_regional_director($currentUser) : false;
$userRegionId = function_exists('get_user_region_id') ? get_user_region_id($currentUserId) : 0;
$userRegionInfo = $userRegionId && function_exists('get_region_by_id') ? get_region_by_id($userRegionId) : null;
$allRegions = function_exists('get_all_active_regions') ? get_all_active_regions() : [];
$regionsList = function_exists('get_all_regions') ? get_all_regions(false) : $allRegions;

$activePage = 'users';
$error = '';
$success = '';
$madrasaName = function_exists('get_setting') ? get_setting('madrasa_name', 'Trust Accounts') : 'Trust Accounts';

$activeTab = trim($_GET['tab'] ?? 'users');
if (!$isSuperAdmin && $activeTab === 'regions') {
    $activeTab = 'users';
}

// Check for Edit Region mode
$editRegion = null;
if ($isSuperAdmin && isset($_GET['edit_region_id'])) {
    $editRegId = (int)$_GET['edit_region_id'];
    $stmtER = $pdo->prepare("SELECT * FROM regions WHERE id = ?");
    $stmtER->execute([$editRegId]);
    $editRegion = $stmtER->fetch() ?: null;
    if ($editRegion) {
        $activeTab = 'regions';
    }
}

// Handle Quick Toggle Region Status (Activate / Deactivate)
if ($isSuperAdmin && isset($_GET['toggle_region_id'])) {
    $tId = (int)$_GET['toggle_region_id'];
    $token = $_GET['token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $error = 'Invalid security token.';
    } else {
        try {
            $stmtT = $pdo->prepare("SELECT name, is_active FROM regions WHERE id = ?");
            $stmtT->execute([$tId]);
            $curR = $stmtT->fetch();
            if ($curR) {
                $newStatus = ((int)$curR['is_active'] === 1) ? 0 : 1;
                $pdo->prepare("UPDATE regions SET is_active = ? WHERE id = ?")->execute([$newStatus, $tId]);
                $msg = $newStatus === 1 ? 'region_activated' : 'region_deactivated';
                header('Location: users.php?tab=regions&success=' . $msg);
                exit;
            }
        } catch (Exception $e) {
            $error = 'اسٹیٹس تبدیل نہیں ہو سکا: ' . $e->getMessage();
        }
    }
}

// Handle Delete Region
if ($isSuperAdmin && isset($_GET['delete_region_id'])) {
    $delRegId = (int)$_GET['delete_region_id'];
    $token = $_GET['token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $error = 'Invalid security token for deletion.';
    } else {
        try {
            $stmtD = $pdo->prepare("SELECT name FROM regions WHERE id = ?");
            $stmtD->execute([$delRegId]);
            $targetReg = $stmtD->fetch();
            if (!$targetReg) {
                throw new RuntimeException("صوبہ / ریجن ریکارڈ نہیں ملا۔");
            }
            // Safely unassign any users and vouchers linked to this region
            $pdo->prepare("UPDATE users SET region_id = NULL WHERE region_id = ?")->execute([$delRegId]);
            $pdo->prepare("UPDATE vouchers SET region_id = NULL WHERE region_id = ?")->execute([$delRegId]);
            $pdo->prepare("DELETE FROM regions WHERE id = ?")->execute([$delRegId]);
            header('Location: users.php?tab=regions&success=region_deleted');
            exit;
        } catch (Exception $e) {
            $error = 'صوبہ ڈیلیٹ نہیں ہو سکا: ' . $e->getMessage();
        }
    }
}

// ==========================================
// 1. HANDLE PROVINCE / REGION ACTIONS (SUPER ADMIN ONLY)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['save_region', 'update_region'], true)) {
    verify_csrf();
    if (!$isSuperAdmin) {
        $error = 'صرف مرکزی ایڈمن (Super Admin) کو نئے صوبے یا ریجنز بنانے کا اختیار ہے۔';
    } else {
        $regName = trim($_POST['region_name'] ?? '');
        $regCode = strtoupper(trim($_POST['region_code'] ?? ''));
        $regDirectorId = !empty($_POST['director_id']) ? (int)$_POST['director_id'] : null;
        $regPhone = trim($_POST['region_phone'] ?? '');
        $regAddress = trim($_POST['region_address'] ?? '');
        $regIsActive = isset($_POST['is_active']) ? 1 : 0;

        if (empty($regName) || empty($regCode)) {
            $error = 'براہ کرم صوبے / ریجن کا نام اور کوڈ درج کریں۔';
        } else {
            try {
                if ($_POST['action'] === 'save_region') {
                    $chkCode = $pdo->prepare("SELECT COUNT(*) FROM regions WHERE code = ?");
                    $chkCode->execute([$regCode]);
                    if ($chkCode->fetchColumn() > 0) {
                        throw new RuntimeException("ریجن کوڈ '{$regCode}' پہلے سے موجود ہے۔ براہ کرم منفرد کوڈ منتخب کریں۔");
                    }
                    $stmtReg = $pdo->prepare("INSERT INTO regions (name, code, director_id, phone, address, is_active) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmtReg->execute([$regName, $regCode, $regDirectorId, $regPhone ?: null, $regAddress ?: null, $regIsActive]);
                    $newRegId = (int)$pdo->lastInsertId();

                    if ($regDirectorId > 0) {
                        $dirRoleId = (int)$pdo->query("SELECT id FROM roles WHERE name = 'Regional Director'")->fetchColumn();
                        if ($dirRoleId > 0) {
                            $pdo->prepare("UPDATE users SET region_id = ?, role_id = ? WHERE id = ?")->execute([$newRegId, $dirRoleId, $regDirectorId]);
                            sync_user_role_permissions($pdo, $regDirectorId, 'Regional Director');
                        }
                    }
                    header('Location: users.php?tab=regions&success=region_created');
                    exit;
                } elseif ($_POST['action'] === 'update_region') {
                    $targetRegId = (int)($_POST['region_id'] ?? 0);
                    if ($targetRegId <= 0) {
                        throw new RuntimeException("نامعلوم ریجن ریکارڈ۔");
                    }
                    $chkCode = $pdo->prepare("SELECT COUNT(*) FROM regions WHERE code = ? AND id != ?");
                    $chkCode->execute([$regCode, $targetRegId]);
                    if ($chkCode->fetchColumn() > 0) {
                        throw new RuntimeException("ریجن کوڈ '{$regCode}' پہلے سے کسی دوسرے صوبے کے پاس ہے۔");
                    }
                    $stmtUpR = $pdo->prepare("UPDATE regions SET name = ?, code = ?, director_id = ?, phone = ?, address = ?, is_active = ? WHERE id = ?");
                    $stmtUpR->execute([$regName, $regCode, $regDirectorId, $regPhone ?: null, $regAddress ?: null, $regIsActive, $targetRegId]);

                    if ($regDirectorId > 0) {
                        $dirRoleId = (int)$pdo->query("SELECT id FROM roles WHERE name = 'Regional Director'")->fetchColumn();
                        if ($dirRoleId > 0) {
                            $pdo->prepare("UPDATE users SET region_id = ?, role_id = ? WHERE id = ?")->execute([$targetRegId, $dirRoleId, $regDirectorId]);
                            sync_user_role_permissions($pdo, $regDirectorId, 'Regional Director');
                        }
                    }
                    header('Location: users.php?tab=regions&success=region_updated');
                    exit;
                }
            } catch (Exception $e) {
                $error = 'ریجن محفوظ نہیں ہو سکا: ' . $e->getMessage();
            }
        }
    }
}

// ==========================================
// 2. HANDLE DELETE USER
// ==========================================
if (isset($_GET['delete_id'])) {
    $delId = (int)$_GET['delete_id'];
    $token = $_GET['token'] ?? '';
    
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $error = 'Invalid security token for deletion.';
    } elseif ($delId === $currentUserId) {
        $error = 'You cannot delete your own account.';
    } else {
        try {
            $stmtTarget = $pdo->prepare("SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?");
            $stmtTarget->execute([$delId]);
            $targetUser = $stmtTarget->fetch();
            if (!$targetUser) {
                throw new RuntimeException("صارف موجود نہیں ہے۔");
            }

            if ($targetUser['role_name'] === 'Super Admin') {
                throw new RuntimeException("مرکزی Super Admin اکاؤنٹ کو ڈیلیٹ نہیں کیا جا سکتا۔");
            }

            // Scoping check for Regional Director
            if ($isRegDirector && !$isSuperAdmin) {
                if ((int)$targetUser['region_id'] !== (int)$userRegionId || $targetUser['role_name'] === 'Regional Director') {
                    throw new RuntimeException("صوبائی ڈائریکٹر صرف اپنی صوبائی ٹیم کے کیشیئرز کو ڈیلیٹ کر سکتا ہے۔");
                }
            }

            // Check if user created any financial records
            $voucherCount = (int)$pdo->query("SELECT COUNT(*) FROM vouchers WHERE created_by = {$delId}")->fetchColumn();
            if ($voucherCount > 0) {
                // Safely deactivate instead of breaking foreign keys
                $pdo->query("UPDATE users SET is_active = 0 WHERE id = {$delId}");
                $pdo->query("UPDATE accounts SET is_active = 0 WHERE user_id = {$delId}");
                $success = "صارف کے نام پر {$voucherCount} مالیاتی واؤچرز درج ہیں، لہٰذا تاریخی ریکارڈ کے تحفظ کے لیے اکاؤنٹ کو غیر فعال (Deactivated) کر دیا گیا ہے۔";
            } else {
                $userAcc = $pdo->query("SELECT id FROM accounts WHERE user_id = {$delId}")->fetch();
                if ($userAcc) {
                    $accId = (int)$userAcc['id'];
                    $jlCount = (int)$pdo->query("SELECT COUNT(*) FROM journal_lines WHERE account_id = {$accId}")->fetchColumn();
                    if ($jlCount > 0) {
                        $pdo->query("UPDATE accounts SET user_id = NULL, is_active = 0 WHERE id = {$accId}");
                    } else {
                        $pdo->query("DELETE FROM accounts WHERE id = {$accId}");
                    }
                }
                $pdo->query("DELETE FROM user_permissions WHERE user_id = {$delId}");
                $pdo->query("DELETE FROM user_funds WHERE user_id = {$delId}");
                $pdo->query("DELETE FROM users WHERE id = {$delId}");
                $success = "User account permanently deleted.";
            }
        } catch (Exception $e) {
            $error = 'Delete failed: ' . $e->getMessage();
        }
    }
}

// ==========================================
// 3. HANDLE ADD / EDIT USER SUBMISSION
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['save_user', 'update_user'], true)) {
    verify_csrf();
    $action = $_POST['action'];

    $name = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone_number'] ?? '');
    $roleId = (int)($_POST['role_id'] ?? 0);
    $password = $_POST['password'] ?? '';
    $selectedPermissions = isset($_POST['permissions']) && is_array($_POST['permissions']) ? $_POST['permissions'] : [];
    $assignedFunds = isset($_POST['assigned_funds']) && is_array($_POST['assigned_funds']) ? array_map('intval', $_POST['assigned_funds']) : [];

    // Region & Supervisor scoping
    if ($isRegDirector && !$isSuperAdmin) {
        $assignedRegionId = $userRegionId;
        $assignedSupervisorId = $currentUserId;
    } else {
        $assignedRegionId = !empty($_POST['region_id']) ? (int)$_POST['region_id'] : null;
        $assignedSupervisorId = !empty($_POST['supervisor_id']) ? (int)$_POST['supervisor_id'] : null;
    }

    if ($action === 'save_user') {
        if (empty($name) || strlen($username) < 4 || strlen($password) < 8 || $roleId <= 0) {
            $error = 'Please enter name, username (at least 4 chars), role, and password (at least 8 chars).';
        } else {
            try {
                $exists = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
                $exists->execute([$username]);
                if ($exists->fetchColumn() > 0) {
                    $error = "Username '{$username}' is already taken. Please choose another.";
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare('INSERT INTO users (role_id, region_id, supervisor_id, full_name, username, email, phone_number, password_hash, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)');
                    $stmt->execute([$roleId, $assignedRegionId, $assignedSupervisorId, $name, $username, $email ?: null, $phone ?: null, $hash]);
                    $newUserId = (int)$pdo->lastInsertId();
                    
                    if (function_exists('get_or_create_user_cash_account')) {
                        get_or_create_user_cash_account($pdo, $newUserId);
                    }

                    // Save custom user permissions
                    if (!empty($selectedPermissions)) {
                        $stmtPerm = $pdo->prepare('INSERT IGNORE INTO user_permissions (user_id, permission_code) VALUES (?, ?)');
                        foreach ($selectedPermissions as $pCode) {
                            $pCode = trim($pCode);
                            if (!empty($pCode)) {
                                $stmtPerm->execute([$newUserId, $pCode]);
                            }
                        }
                    } else {
                        $roleName = $pdo->query("SELECT name FROM roles WHERE id = {$roleId}")->fetchColumn() ?: '';
                        sync_user_role_permissions($pdo, $newUserId, $roleName);
                    }

                    // Save assigned funds
                    if (!empty($assignedFunds)) {
                        $stmtUf = $pdo->prepare('INSERT IGNORE INTO user_funds (user_id, fund_id) VALUES (?, ?)');
                        foreach ($assignedFunds as $fId) {
                            if ($fId > 0) $stmtUf->execute([$newUserId, $fId]);
                        }
                    }

                    header('Location: users.php?success=created');
                    exit;
                }
            } catch (Exception $e) {
                $error = 'Error creating user: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'update_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId <= 0 || empty($name) || strlen($username) < 4 || $roleId <= 0) {
            $error = 'Please enter valid user details.';
        } else {
            try {
                // Scoping check for Regional Director
                if ($isRegDirector && !$isSuperAdmin) {
                    $targetReg = (int)$pdo->query("SELECT region_id FROM users WHERE id = {$userId}")->fetchColumn();
                    if ($targetReg !== (int)$userRegionId) {
                        throw new RuntimeException("آپ صرف اپنے ریجن کے صارف میں ترمیم کر سکتے ہیں۔");
                    }
                }

                // Check username duplicate for other users
                $exists = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ? AND id != ?');
                $exists->execute([$username, $userId]);
                if ($exists->fetchColumn() > 0) {
                    $error = "Username '{$username}' is already in use by another account.";
                } else {
                    if (!empty($password)) {
                        if (strlen($password) < 8) {
                            throw new RuntimeException("New password must be at least 8 characters.");
                        }
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare('UPDATE users SET role_id=?, region_id=?, supervisor_id=?, full_name=?, username=?, email=?, phone_number=?, password_hash=? WHERE id=?');
                        $stmt->execute([$roleId, $assignedRegionId, $assignedSupervisorId, $name, $username, $email ?: null, $phone ?: null, $hash, $userId]);
                    } else {
                        $stmt = $pdo->prepare('UPDATE users SET role_id=?, region_id=?, supervisor_id=?, full_name=?, username=?, email=?, phone_number=? WHERE id=?');
                        $stmt->execute([$roleId, $assignedRegionId, $assignedSupervisorId, $name, $username, $email ?: null, $phone ?: null, $userId]);
                    }
                    try {
                        $accName = 'نقدی کیش - ' . $name;
                        $pdo->prepare("UPDATE accounts SET account_name = ? WHERE user_id = ?")->execute([$accName, $userId]);
                    } catch (Exception $ex) {}

                    // Update custom user permissions
                    $pdo->prepare('DELETE FROM user_permissions WHERE user_id = ?')->execute([$userId]);
                    if (!empty($selectedPermissions)) {
                        $stmtPerm = $pdo->prepare('INSERT IGNORE INTO user_permissions (user_id, permission_code) VALUES (?, ?)');
                        foreach ($selectedPermissions as $pCode) {
                            $pCode = trim($pCode);
                            if (!empty($pCode)) {
                                $stmtPerm->execute([$userId, $pCode]);
                            }
                        }
                    } else {
                        $roleName = $pdo->query("SELECT name FROM roles WHERE id = {$roleId}")->fetchColumn() ?: '';
                        sync_user_role_permissions($pdo, $userId, $roleName);
                    }

                    // Update assigned funds
                    $pdo->prepare('DELETE FROM user_funds WHERE user_id = ?')->execute([$userId]);
                    if (!empty($assignedFunds)) {
                        $stmtUf = $pdo->prepare('INSERT IGNORE INTO user_funds (user_id, fund_id) VALUES (?, ?)');
                        foreach ($assignedFunds as $fId) {
                            if ($fId > 0) $stmtUf->execute([$userId, $fId]);
                        }
                    }

                    header('Location: users.php?success=updated');
                    exit;
                }
            } catch (Exception $e) {
                $error = 'Update failed: ' . $e->getMessage();
            }
        }
    }
}

// ==========================================
// 4. HANDLE QUICK PASSWORD RESET (ADMIN TOOL)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quick_reset_password') {
    verify_csrf();
    $targetUserId = (int)($_POST['user_id'] ?? 0);
    $resetPass = (string)($_POST['reset_password'] ?? '');

    if ($targetUserId <= 0) {
        $error = 'User not specified.';
    } elseif (strlen($resetPass) < 8) {
        $error = 'پاس ورڈ کم از کم 8 حروف کا ہونا چاہیے۔ (Password must be at least 8 characters).';
    } else {
        try {
            // Scoping check for Regional Director
            if ($isRegDirector && !$isSuperAdmin) {
                $targetReg = (int)$pdo->query("SELECT region_id FROM users WHERE id = {$targetUserId}")->fetchColumn();
                if ($targetReg !== (int)$userRegionId) {
                    throw new RuntimeException("آپ صرف اپنے صوبائی عملے کا پاس ورڈ ری سیٹ کر سکتے ہیں۔");
                }
            }

            $newHash = password_hash($resetPass, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$newHash, $targetUserId]);
            try {
                $stmtLog = $pdo->prepare("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address) VALUES (?, ?, ?, ?, ?)");
                $stmtLog->execute([$currentUserId, 'quick_reset_user_password', 'user', $targetUserId, $_SERVER['REMOTE_ADDR'] ?? null]);
            } catch (Exception $ex) {}
            header('Location: users.php?success=reset_done');
            exit;
        } catch (Exception $e) {
            $error = 'Failed to reset password: ' . $e->getMessage();
        }
    }
}

// ==========================================
// 5. HANDLE TOGGLE STATUS
// ==========================================
if (isset($_GET['toggle_id'])) {
    $token = $_GET['token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        header('Location: users.php?error=invalid_token');
        exit;
    }
    $toggleId = (int)$_GET['toggle_id'];
    if ($toggleId !== $currentUserId) {
        if ($isRegDirector && !$isSuperAdmin) {
            $targetReg = (int)$pdo->query("SELECT region_id FROM users WHERE id = {$toggleId}")->fetchColumn();
            if ($targetReg !== (int)$userRegionId) {
                header('Location: users.php');
                exit;
            }
        }
        $pdo->query("UPDATE users SET is_active = IF(is_active=1, 0, 1) WHERE id = {$toggleId}");
        $newAct = (int)$pdo->query("SELECT is_active FROM users WHERE id = {$toggleId}")->fetchColumn();
        $pdo->query("UPDATE accounts SET is_active = {$newAct} WHERE user_id = {$toggleId}");
    }
    header('Location: users.php');
    exit;
}

// Edit mode data
$editUser = null;
if (isset($_GET['edit_id'])) {
    $editId = (int)$_GET['edit_id'];
    $stmtEdit = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmtEdit->execute([$editId]);
    $editUser = $stmtEdit->fetch();
    if ($editUser && $isRegDirector && !$isSuperAdmin && (int)$editUser['region_id'] !== (int)$userRegionId) {
        $editUser = null;
    }
}

// Filtering by province for Super Admin
$filterRegionId = (int)($_GET['region_id'] ?? 0);

// Fetch all users with province and cash account
$userWhere = "WHERE 1=1";
if ($isRegDirector && !$isSuperAdmin) {
    $userWhere .= " AND u.region_id = {$userRegionId}";
} elseif ($isSuperAdmin && $filterRegionId > 0) {
    $userWhere .= " AND u.region_id = {$filterRegionId}";
}

$users = $pdo->query("SELECT u.*, r.name AS role_name, reg.name AS region_name, reg.code AS region_code, 
                             a.account_code, a.account_name AS cash_account_name 
                      FROM users u 
                      JOIN roles r ON r.id = u.role_id 
                      LEFT JOIN regions reg ON reg.id = u.region_id
                      LEFT JOIN accounts a ON a.user_id = u.id AND a.account_type = 'asset' 
                      {$userWhere}
                      ORDER BY (u.id = {$currentUserId}) DESC, u.id ASC")->fetchAll();

foreach ($users as $idx => $u) {
    if (empty($u['account_code']) && function_exists('get_or_create_user_cash_account')) {
        $acc = get_or_create_user_cash_account($pdo, (int)$u['id']);
        if ($acc) {
            $users[$idx]['account_code'] = $acc['account_code'];
            $users[$idx]['cash_account_name'] = $acc['account_name'];
        }
    }
}

// Roles available for selection
if ($isRegDirector && !$isSuperAdmin) {
    $roles = $pdo->query("SELECT id, name, description FROM roles WHERE name IN ('User / Cashier', 'Viewer') ORDER BY id ASC")->fetchAll();
} else {
    $roles = $pdo->query("SELECT id, name, description FROM roles WHERE name != 'Cashier' ORDER BY id ASC")->fetchAll();
}

// All active funds
$allSystemFunds = $pdo->query('SELECT id, name, fund_code, is_restricted FROM funds WHERE is_active=1 ORDER BY name ASC')->fetchAll();

// Permissions definition
$allPermissionsDef = get_all_permissions_definition();
$activeUserPermissions = [];
$activeUserFundIds = $editUser ? get_user_assigned_fund_ids((int)$editUser['id']) : [];

if ($editUser) {
    $activeUserPermissions = get_user_permissions((int)$editUser['id']);
    if (empty($activeUserPermissions)) {
        $rName = $pdo->query("SELECT name FROM roles WHERE id = " . (int)$editUser['role_id'])->fetchColumn() ?: '';
        if ($rName === 'Super Admin' || $rName === 'Administrator') {
            foreach ($allPermissionsDef as $grp => $items) {
                foreach ($items as $k => $v) $activeUserPermissions[] = $k;
            }
        } elseif ($rName === 'Regional Director') {
            $activeUserPermissions = ['income.view', 'income.create', 'income.slip', 'expense.view', 'expense.create', 'voucher.approve', 'voucher.direct_post', 'donors.view', 'donors.manage', 'beneficiaries.view', 'beneficiaries.manage', 'funds.view', 'accounts.view', 'reports.view', 'tasks.view', 'tasks.manage', 'tasks.update_progress', 'regions.view', 'team.manage'];
        } elseif ($rName === 'Approver / Checker' || $rName === 'Checker') {
            foreach ($allPermissionsDef as $grp => $items) {
                foreach ($items as $k => $v) {
                    if (!in_array($k, ['users.manage', 'settings.manage'], true)) $activeUserPermissions[] = $k;
                }
            }
        } elseif ($rName === 'User / Cashier' || $rName === 'Cashier') {
            $activeUserPermissions = ['income.view', 'income.create', 'income.slip', 'expense.view', 'expense.create', 'donors.view', 'donors.manage', 'beneficiaries.view', 'beneficiaries.manage', 'tasks.view', 'tasks.update_progress'];
        } elseif ($rName === 'Viewer') {
            $activeUserPermissions = ['income.view', 'expense.view', 'donors.view', 'beneficiaries.view', 'funds.view', 'accounts.view', 'reports.view', 'tasks.view'];
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>User Management & Permissions | <?=e($madrasaName)?></title>
    <link rel="stylesheet" href="assets/style.css?v=<?=css_ver()?>">
</head>
<body>
<div class="app-shell">
    <?php require __DIR__ . '/../app/sidebar.php'; ?>

    <main class="main">
        <header class="topbar">
            <div>
                <span class="eyebrow">ADMINISTRATION</span>
                <h1><?= $isRegDirector && !$isSuperAdmin ? 'My Regional Team <span class="ur">(میری صوبائی ٹیم)</span>' : 'Users & Provinces <span class="ur">(صارفین و برانچز)</span>' ?></h1>
            </div>
            <div class="btn-row">
                <?php if ($activeTab === 'users'): ?>
                    <?php if ($editUser): ?>
                        <a href="users.php" class="btn secondary sm">✕ Cancel Edit Mode</a>
                    <?php else: ?>
                        <button type="button" class="btn blue sm" id="toggleUserBtn" onclick="toggleUserForm()">
                            ＋ Create New User <span class="ur">(نیا صارف)</span>
                        </button>
                    <?php endif; ?>
                <?php elseif ($activeTab === 'regions' && $isSuperAdmin): ?>
                    <?php if ($editRegion): ?>
                        <a href="users.php?tab=regions" class="btn secondary sm">✕ Cancel Editing <span class="ur">(منسوخ کریں)</span></a>
                    <?php else: ?>
                        <button type="button" class="btn blue sm" id="toggleRegionBtn" onclick="toggleRegionForm()">
                            ＋ Add New Province / Region <span class="ur">(نیا صوبہ بنائیں)</span>
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </header>

        <!-- Top Navigation Tabs (Super Admin sees Provinces tab) -->
        <?php if ($isSuperAdmin): ?>
            <div class="tab-nav" style="margin-top:16px;margin-bottom:20px;">
                <a href="users.php?tab=users" class="tab-link <?=$activeTab==='users'?'active':''?>">
                    👥 Users & Staff Directory <span class="ur">(تمام عملہ و صارفین)</span>
                </a>
                <a href="users.php?tab=regions" class="tab-link <?=$activeTab==='regions'?'active':''?>">
                    🏛️ Provinces & Regional Directorates <span class="ur">(صوبائی و علاقائی برانچز)</span>
                </a>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert success">
                <?php if ($_GET['success'] === 'created') echo '✓ New user account created successfully with assigned rights and funds!'; ?>
                <?php if ($_GET['success'] === 'updated') echo '✓ User account details, permissions, and assigned funds updated successfully!'; ?>
                <?php if ($_GET['success'] === 'deleted') echo '✓ User account deleted successfully!'; ?>
                <?php if ($_GET['success'] === 'reset_done') echo '✓ صارف کا نیا پاس ورڈ کامیابی سے لاگو ہو گیا ہے۔ (User password reset successfully!)'; ?>
                <?php if ($_GET['success'] === 'region_created') echo '✓ نیا صوبہ / ڈائریکٹوریٹ کامیابی سے شامل کر دیا گیا ہے!'; ?>
                <?php if ($_GET['success'] === 'region_updated') echo '✓ صوبائی ڈائریکٹوریٹ کی معلومات اپ ڈیٹ ہو گئیں!'; ?>
                <?php if ($_GET['success'] === 'region_deleted') echo '✓ منتخب صوبہ کامیابی سے ڈیلیٹ کر دیا گیا ہے!'; ?>
                <?php if ($_GET['success'] === 'region_activated') echo '✓ صوبہ کامیابی سے فعال (Active) کر دیا گیا ہے!'; ?>
                <?php if ($_GET['success'] === 'region_deactivated') echo '✓ صوبہ کامیابی سے غیر فعال (Deactivated) کر دیا گیا ہے۔ اب یہ غیر فعال فہرست میں ہے۔'; ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert error"><?=e($error)?></div>
        <?php endif; ?>

        <?php if ($activeTab === 'regions' && $isSuperAdmin): ?>
            <!-- ============================================================== -->
            <!-- TAB 2: PROVINCES & REGIONAL DIRECTORATES (SUPER ADMIN ONLY)    -->
            <!-- ============================================================== -->
            <!-- Add / Edit Region Form -->
            <article class="form-card" id="regionFormCard" style="display:<?= $editRegion ? 'block' : 'none' ?>;margin-bottom:24px;">
                <div class="form-card-header" style="display:flex;justify-content:space-between;align-items:flex-start;">
                    <div>
                        <h3><?= $editRegion ? '🏛️ Edit Province / Directorate <span class="ur">(صوبائی ڈائریکٹوریٹ میں ترمیم کریں)</span>' : '🏛️ Add New Province / Directorate <span class="ur">(نیا صوبہ شامل کریں)</span>' ?></h3>
                        <p><?= $editRegion ? 'Update provincial details, appointed director, contact info, and active status' : 'Configure provincial directorate (e.g. Sindh, Balochistan, Islamabad) and appoint its Regional Director' ?></p>
                    </div>
                    <?php if (!$editRegion): ?>
                        <button type="button" class="btn sm secondary" onclick="toggleRegionForm(false)" style="padding:4px 10px;" title="فارم بند کریں">✕</button>
                    <?php endif; ?>
                </div>
                <form method="post" action="users.php?tab=regions">
                    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                    <input type="hidden" name="action" value="<?= $editRegion ? 'update_region' : 'save_region' ?>">
                    <?php if ($editRegion): ?>
                        <input type="hidden" name="region_id" value="<?=$editRegion['id']?>">
                    <?php endif; ?>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Province / Region Name <span class="ur">(صوبے کا نام)</span> *</label>
                            <input name="region_name" required placeholder="e.g. پنجاب (Punjab) / سندھ (Sindh) / بلوچستان (Balochistan)" value="<?=e($editRegion['name'] ?? '')?>">
                        </div>
                        <div class="form-group">
                            <label>Region Code <span class="ur">(منفرد کوڈ)</span> *</label>
                            <input name="region_code" required placeholder="e.g. PUNJAB, KPK, SINDH, BAL, ISB" maxlength="10" style="text-transform:uppercase;font-family:monospace;font-weight:700;" value="<?=e($editRegion['code'] ?? '')?>">
                            <small class="muted">2 to 10 capital letters.</small>
                        </div>
                        <div class="form-group">
                            <label>Appoint Director <span class="ur">(صوبائی ڈائریکٹر نامزد کریں)</span></label>
                            <select name="director_id">
                                <option value="">-- Appoint Later / کوئی ڈائریکٹر نہیں --</option>
                                <?php foreach ($users as $usr): ?>
                                    <option value="<?=$usr['id']?>" <?= (isset($editRegion['director_id']) && (int)$editRegion['director_id'] === (int)$usr['id']) ? 'selected' : '' ?>>
                                        <?=e($usr['full_name'])?> (@<?=e($usr['username'])?>) — <?=e($usr['role_name'])?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Regional Office Phone <span class="ur">(دفتری رابطہ نمبر)</span></label>
                            <input name="region_phone" placeholder="e.g. 042-33333333" value="<?=e($editRegion['phone'] ?? '')?>">
                        </div>

                        <div class="form-group">
                            <label>Active Status <span class="ur">(فعال حیثیت / اسٹیٹس)</span></label>
                            <div style="padding-top:10px;">
                                <label style="font-weight:normal;cursor:pointer;display:flex;align-items:center;gap:8px;">
                                    <input type="checkbox" name="is_active" value="1" <?= ((int)($editRegion['is_active'] ?? 1) === 1) ? 'checked' : '' ?> style="width:18px;height:18px;">
                                    <span><b>Active Province / Directorate <span class="ur">(فعال صوبہ)</span></b> — Uncheck to deactivate.</span>
                                </label>
                            </div>
                        </div>

                        <div class="form-group" style="grid-column:1/-1;">
                            <label>Office Address <span class="ur">(صوبائی دفتری پتہ)</span></label>
                            <textarea name="region_address" rows="2" placeholder="صوبائی مرکزی دفتر کا پتہ..."><?=e($editRegion['address'] ?? '')?></textarea>
                        </div>
                    </div>
                    <div class="btn-row" style="margin-top:16px;">
                        <button class="btn blue" type="submit">
                            <?= $editRegion ? '💾 Update Province / Directorate' : '💾 Save Province / Directorate' ?>
                        </button>
                        <?php if ($editRegion): ?>
                            <a href="users.php?tab=regions" class="btn secondary">Cancel Editing</a>
                        <?php else: ?>
                            <button class="btn secondary" type="button" onclick="toggleRegionForm(false)">Cancel</button>
                        <?php endif; ?>
                    </div>
                </form>
            </article>

            <!-- Provinces Directory Table -->
            <article class="panel">
                <div class="panel-heading">
                    <div>
                        <h3>Provincial Directorates Directory <span class="ur">(صوبائی ڈائریکٹوریٹس کی فہرست)</span></h3>
                        <p>All provincial directorates across Pakistan with team counts, direct activate/deactivate, and management</p>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Province / Directorate</th>
                                <th>Code</th>
                                <th>Regional Director <span class="ur">(صوبائی ڈائریکٹر)</span></th>
                                <th>Contact & Office</th>
                                <th>Team Size</th>
                                <th>Status</th>
                                <th style="text-align:center;">Actions <span class="ur">(کارروائی)</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($regionsList)): ?>
                                <tr><td colspan="7" class="center muted" style="padding:24px;">کوئی صوبہ موجود نہیں ہے۔</td></tr>
                            <?php else: ?>
                                <?php foreach ($regionsList as $reg): 
                                    $isRegActive = ((int)$reg['is_active'] === 1);
                                ?>
                                    <tr style="<?=$isRegActive ? '' : 'opacity:0.75;background:#fef2f2;'?>">
                                        <td>
                                            <b style="font-size:14.5px;color:#0f172a;">🏛️ <?=e($reg['name'])?></b>
                                        </td>
                                        <td>
                                            <span class="badge" style="background:#eff6ff;color:#1d4ed8;font-weight:700;font-family:monospace;"><?=e($reg['code'])?></span>
                                        </td>
                                        <td>
                                            <?php if (!empty($reg['director_name'])): ?>
                                                <b style="color:#0f172a;"><?=e($reg['director_name'])?></b>
                                                <?php if (!empty($reg['director_phone'])): ?>
                                                    <div style="font-size:11px;color:#16a34a;">📱 <?=e($reg['director_phone'])?></div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="badge badge-amber">Not Assigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div><?=e($reg['phone'] ?: '—')?></div>
                                            <?php if (!empty($reg['address'])): ?>
                                                <small class="muted" style="font-size:11px;"><?=e($reg['address'])?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="users.php?tab=users&region_id=<?=$reg['id']?>" class="badge badge-blue" style="text-decoration:none;" title="View Team">
                                                👥 <?=(int)($reg['team_count'] ?? 0)?> Staff Members
                                            </a>
                                        </td>
                                        <td>
                                            <?php if ($isRegActive): ?>
                                                <span class="badge badge-green">Active <span class="ur">(فعال)</span></span>
                                            <?php else: ?>
                                                <span class="badge badge-gray" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;">Inactive <span class="ur">(غیر فعال)</span></span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:center;white-space:nowrap;">
                                            <div class="btn-row" style="justify-content:center;gap:6px;">
                                                <?php if ($isRegActive): ?>
                                                    <a href="users.php?tab=regions&toggle_region_id=<?=$reg['id']?>&token=<?=csrf_token()?>" class="btn sm secondary" style="color:#b91c1c;border-color:#fca5a5;background:#fff5f5;font-weight:600;" title="صوبہ غیر فعال کریں" onclick="return confirm('کیا آپ واقعی صوبہ \'<?=e(addslashes($reg['name']))?>\' کو غیر فعال (Deactivate) کرنا چاہتے ہیں؟');">⏸ Deactivate</a>
                                                <?php else: ?>
                                                    <a href="users.php?tab=regions&toggle_region_id=<?=$reg['id']?>&token=<?=csrf_token()?>" class="btn sm secondary" style="color:#15803d;border-color:#86efac;background:#f0fdf4;font-weight:600;" title="صوبہ دوبارہ فعال کریں">▶ Activate</a>
                                                <?php endif; ?>
                                                <a href="users.php?tab=regions&edit_region_id=<?=$reg['id']?>" class="btn sm secondary" title="ترمیم کریں">✏ Edit</a>
                                                <a href="users.php?tab=regions&delete_region_id=<?=$reg['id']?>&token=<?=csrf_token()?>" class="btn sm danger" title="ڈیلیٹ کریں" onclick="return confirm('کیا آپ واقعی صوبہ \'<?=e(addslashes($reg['name']))?>\' کو مستقل ڈیلیٹ کرنا چاہتے ہیں؟ اس سے وابستہ عملہ غیر متعین ہو جائے گا۔');">🗑 Delete</a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </article>

        <?php else: ?>
            <!-- ============================================================== -->
            <!-- TAB 1: USERS & STAFF DIRECTORY                                  -->
            <!-- ============================================================== -->

            <?php if ($isRegDirector && $userRegionInfo): ?>
                <div style="background:#f0fdf4;border:1.5px solid #bbf7d0;border-left:5px solid #16a34a;border-radius:10px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <span style="font-size:24px;">🏛️</span>
                        <div>
                            <b style="color:#166534;font-size:14.5px;"><?=e($userRegionInfo['name'])?> Directorate <span class="ur">(صوبائی ڈائریکٹوریٹ)</span></b>
                            <div style="font-size:12px;color:#15803d;">You have administrative control over all field cashiers and staff assigned to your province.</div>
                        </div>
                    </div>
                    <span class="badge" style="background:#dcfce7;color:#15803d;border:1px solid #86efac;font-weight:700;">Code: <?=e($userRegionInfo['code'])?></span>
                </div>
            <?php endif; ?>

            <!-- Add / Edit User Form Card -->
            <article class="form-card" id="userFormCard" style="display:<?= $editUser ? 'block' : 'none' ?>; margin-bottom:24px;">
                <div class="form-card-header">
                    <h3><?= $editUser ? 'Edit User Details & Rights <span class="ur">(صارف و اختیارات میں ترمیم کریں)</span>' : 'Create New System User <span class="ur">(نیا صارف شامل کریں)</span>' ?></h3>
                    <p><?= $editUser ? 'Update account info, assign roles, or customize granular permissions and funds' : 'Set credentials and select specific task permissions and funds for this user' ?></p>
                </div>

                <form method="post" action="users.php">
                    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                    <input type="hidden" name="action" value="<?= $editUser ? 'update_user' : 'save_user' ?>">
                    <?php if ($editUser): ?>
                        <input type="hidden" name="user_id" value="<?=$editUser['id']?>">
                    <?php endif; ?>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Full Name <span class="ur">(مکمل نام)</span> *</label>
                            <input name="full_name" required placeholder="e.g. Muhammad Tariq" value="<?=e($editUser['full_name'] ?? '')?>">
                        </div>

                        <div class="form-group">
                            <label>Username <span class="ur">(یوزر نیم لاگ ان کے لیے)</span> *</label>
                            <input name="username" required minlength="4" placeholder="e.g. mtariq" value="<?=e($editUser['username'] ?? '')?>">
                        </div>

                        <div class="form-group">
                            <label>Email Address <span class="ur">(ای میل)</span></label>
                            <input type="email" name="email" placeholder="user@example.com" value="<?=e($editUser['email'] ?? '')?>">
                        </div>

                        <div class="form-group">
                            <label>WhatsApp / Mobile Phone <span class="ur">(موبائل یا واٹس ایپ نمبر)</span></label>
                            <input type="text" name="phone_number" placeholder="0300-1234567" value="<?=e($editUser['phone_number'] ?? '')?>">
                            <small style="color:var(--muted);font-size:11px;">ٹاسک اور الرٹس واٹس ایپ پر بھیجنے کے لیے استعمال ہوگا</small>
                        </div>

                        <?php if ($isSuperAdmin): ?>
                            <div class="form-group">
                                <label>Province / Region <span class="ur">(متعلقہ صوبہ یا برانچ)</span></label>
                                <select name="region_id">
                                    <option value="">-- Central / Head Office (مرکزی ہیڈ کوارٹر) --</option>
                                    <?php foreach ($allRegions as $reg): ?>
                                        <option value="<?=$reg['id']?>" <?=($editUser && (int)($editUser['region_id'] ?? 0) === (int)$reg['id']) ? 'selected' : ''?>>
                                            🏛️ <?=e($reg['name'])?> (<?=e($reg['code'])?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php else: ?>
                            <div class="form-group">
                                <label>Province / Region <span class="ur">(صوبہ)</span></label>
                                <input type="text" value="<?=e($userRegionInfo['name'] ?? 'Provincial Team')?>" readonly style="background:#f1f5f9;cursor:not-allowed;">
                            </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label>Assigned Role Template <span class="ur">(بنیادی کردار / رول)</span> *</label>
                            <select name="role_id" id="roleSelect" onchange="onRoleChange(this.value)" required>
                                <?php foreach ($roles as $r): ?>
                                    <option value="<?=$r['id']?>" data-name="<?=e($r['name'])?>" <?=($editUser && (int)$editUser['role_id'] === (int)$r['id']) ? 'selected' : ''?>>
                                        <?=e($r['name'])?> (<?=e($r['description'])?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small style="color:var(--muted);font-size:11px;">
                                <span class="ur">یہ بنیادی ٹیمپلیٹ ہے۔ نیچے موجود چیک باکسز سے آپ اپنی مرضی کا کوئی بھی اختیار شامل یا خارج کر سکتے ہیں۔</span>
                                <span class="en-only" style="display:none;">Base role template. You can customize individual rights using the checkboxes below.</span>
                            </small>
                        </div>

                        <div class="form-group">
                            <label>Password <span class="ur"><?= $editUser ? '(تبدیل کرنے کے لیے نیا پاس ورڈ لکھیں)' : '(کم از کم 8 حروف)' ?></span></label>
                            <input type="password" name="password" minlength="8" placeholder="<?= $editUser ? 'Leave blank to keep unchanged' : 'At least 8 characters' ?>" <?= $editUser ? '' : 'required' ?>>
                            <?php if ($editUser): ?>
                                <small class="muted" style="font-size:11px;">
                                    <span class="ur">اگر پاس ورڈ تبدیل نہیں کرنا تو خالی چھوڑ دیں۔</span>
                                    <span class="en-only" style="display:none;">Leave blank if not changing password.</span>
                                </small>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Custom User Permissions Section -->
                    <div class="user-permissions-section" style="margin-top:24px;padding-top:20px;border-top:1.5px solid #e2e8f0;">
                        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
                            <div>
                                <h4 style="margin:0;font-size:15px;color:var(--text);display:flex;align-items:center;gap:8px;">
                                    <span>🛡️</span>
                                    <span>User Access Rights & Permissions <span class="ur">(صارف کے مخصوص اختیارات)</span></span>
                                </h4>
                                <p style="margin:4px 0 0;font-size:12px;color:var(--muted);">
                                    <span class="ur">اپنی مرضی کے مطابق جو اختیارات اس صارف کو دینا چاہتے ہیں ان کے ڈبے ٹک (Check) کریں۔</span>
                                    <span class="en-only" style="display:none;">Check the individual permissions you wish to grant this user.</span>
                                </p>
                            </div>
                            <!-- Quick Presets -->
                            <div class="preset-btn-row" style="display:flex;flex-wrap:wrap;gap:6px;">
                                <button type="button" class="btn secondary sm" onclick="applyPreset('cashier')" style="font-size:11.5px;padding:4px 10px;">
                                    🔘 <span class="en-only" style="display:none;">Cashier / Collector</span><span class="ur">صرف کیشئر / تحصیل دار</span>
                                </button>
                                <?php if ($isSuperAdmin): ?>
                                    <button type="button" class="btn secondary sm" onclick="applyPreset('approver')" style="font-size:11.5px;padding:4px 10px;">
                                        🔘 <span class="en-only" style="display:none;">Accountant / Checker</span><span class="ur">اکاؤنٹنٹ و چیکر</span>
                                    </button>
                                    <button type="button" class="btn secondary sm" onclick="applyPreset('director')" style="font-size:11.5px;padding:4px 10px;">
                                        🔘 <span class="ur">صوبائی ڈائریکٹر</span>
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="btn secondary sm" onclick="applyPreset('viewer')" style="font-size:11.5px;padding:4px 10px;">
                                    🔘 <span class="en-only" style="display:none;">Viewer Only</span><span class="ur">صرف ناظر (Viewer)</span>
                                </button>
                                <button type="button" class="btn secondary sm" onclick="applyPreset('all')" style="font-size:11.5px;padding:4px 10px;background:#eff6ff;color:#1e40af;border-color:#bfdbfe;">
                                    🔘 <span class="en-only" style="display:none;">Select All</span><span class="ur">تمام منتخب</span>
                                </button>
                                <button type="button" class="btn secondary sm" onclick="applyPreset('none')" style="font-size:11.5px;padding:4px 10px;">
                                    ✕ <span class="en-only" style="display:none;">Clear All</span><span class="ur">سب ختم</span>
                                </button>
                            </div>
                        </div>

                        <!-- Permissions Grid -->
                        <div class="permissions-card-grid" style="display:grid;grid-template-columns:repeat(auto-fill, minmax(280px, 1fr));gap:14px;margin-bottom:16px;">
                            <?php foreach ($allPermissionsDef as $groupTitle => $groupPerms): 
                                // Restrict Regional Directors from granting global administration permissions
                                if ($isRegDirector && !$isSuperAdmin && strpos($groupTitle, 'Administration') !== false) {
                                    continue;
                                }
                                $grpParts = explode('(', $groupTitle, 2);
                                $grpEn = trim($grpParts[0]);
                                $grpUr = isset($grpParts[1]) ? '(' . trim($grpParts[1]) : '';
                            ?>
                                <div class="permission-group-card" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px 14px;">
                                    <div style="font-weight:700;font-size:13px;color:#0f172a;margin-bottom:8px;padding-bottom:5px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
                                        <span><?=e($grpEn)?> <?php if ($grpUr): ?><span class="ur"><?=e($grpUr)?></span><?php endif; ?></span>
                                        <label style="font-size:10.5px;color:var(--muted);cursor:pointer;font-weight:normal;user-select:none;">
                                            <input type="checkbox" onchange="toggleGroupCheckboxes(this)" style="vertical-align:middle;margin-right:2px;"> <span class="en-only" style="display:none;">All</span><span class="ur">سب</span>
                                        </label>
                                    </div>
                                    <div style="display:flex;flex-direction:column;gap:7px;">
                                        <?php foreach ($groupPerms as $code => $meta): ?>
                                            <?php $isChecked = in_array($code, $activeUserPermissions, true); ?>
                                            <label class="perm-checkbox-item" style="display:flex;align-items:flex-start;gap:8px;font-size:12px;cursor:pointer;line-height:1.35;user-select:none;">
                                                <input type="checkbox" name="permissions[]" value="<?=e($code)?>" class="perm-checkbox" data-code="<?=e($code)?>" <?= $isChecked ? 'checked' : '' ?> style="margin-top:2px;">
                                                <div>
                                                    <div style="font-weight:600;color:#1e293b;"><?=e($meta['label'])?></div>
                                                    <div class="ur" style="font-size:11px;color:#64748b;"><?=e($meta['ur'])?></div>
                                                </div>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Allowed Funds Access Section -->
                    <div class="user-funds-section" style="margin-top:20px;padding-top:18px;border-top:1.5px solid #e2e8f0;">
                        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
                            <div>
                                <h4 style="margin:0;font-size:15px;color:var(--text);display:flex;align-items:center;gap:8px;">
                                    <span>🏦</span>
                                    <span>Assigned Funds Access <span class="ur">(مخصوص فنڈز جن میں یہ صارف وصولی و خرچ کر سکے گا)</span></span>
                                </h4>
                                <p style="margin:4px 0 0;font-size:12px;color:var(--muted);">
                                    <span class="ur">اگر تمام فنڈز پر کام کرنے دینا چاہتے ہیں تو کوئی فنڈ منتخب نہ کریں (یا تمام منتخب کریں)۔ مخصوص فنڈز (مثلاً راشن فنڈ، زکوٰۃ یا جنرل فنڈ) دینے کے لیے متعلقہ فنڈز پر ٹک لگائیں۔</span>
                                    <span class="en-only" style="display:none;">Leave blank or select all to allow all funds. Check specific funds to restrict this user.</span>
                                </p>
                            </div>
                            <div class="preset-btn-row" style="display:flex;gap:6px;">
                                <button type="button" class="btn secondary sm" onclick="toggleAllFunds(true)" style="font-size:11.5px;padding:4px 10px;">
                                    🔘 <span class="en-only" style="display:none;">Select All Funds</span><span class="ur">تمام فنڈز منتخب</span>
                                </button>
                                <button type="button" class="btn secondary sm" onclick="toggleAllFunds(false)" style="font-size:11.5px;padding:4px 10px;">
                                    ✕ <span class="en-only" style="display:none;">Clear All</span><span class="ur">سب ختم</span>
                                </button>
                            </div>
                        </div>

                        <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(240px, 1fr));gap:10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px;">
                            <?php foreach ($allSystemFunds as $sf): ?>
                                <?php $isFundChecked = in_array((int)$sf['id'], $activeUserFundIds, true); ?>
                                <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;background:#fff;padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px;">
                                    <input type="checkbox" name="assigned_funds[]" value="<?=$sf['id']?>" class="user-fund-checkbox" <?= $isFundChecked ? 'checked' : '' ?>>
                                    <div>
                                        <b><?=e($sf['name'])?></b>
                                        <small style="color:var(--muted);font-size:11px;">(<?=e($sf['fund_code'])?>)</small>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="btn-row" style="margin-top:16px;">
                        <button class="btn blue" type="submit">
                            <?= $editUser ? '💾 Update User & Rights' : '💾 Save User & Rights' ?>
                        </button>
                        <?php if ($editUser): ?>
                            <a href="users.php" class="btn secondary">Cancel Editing</a>
                        <?php else: ?>
                            <button class="btn secondary" type="button" onclick="toggleUserForm(false)">Cancel</button>
                        <?php endif; ?>
                    </div>
                </form>
            </article>

            <!-- Users List Table Panel -->
            <article class="panel" style="margin-top:20px;">
                <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
                    <div>
                        <h3>System Users List & Rights Overview <span class="ur">(صارفین و اختیارات فہرست)</span></h3>
                        <p>Accounts with permission to access this accounting software</p>
                    </div>

                    <?php if ($isSuperAdmin && !empty($allRegions)): ?>
                        <form method="get" action="users.php" style="display:flex;align-items:center;gap:8px;margin:0;">
                            <input type="hidden" name="tab" value="users">
                            <span style="font-size:12.5px;color:#64748b;font-weight:600;">🏛️ Province Filter:</span>
                            <select name="region_id" onchange="this.form.submit()" style="padding:6px 10px;border:1px solid var(--line);border-radius:6px;font-size:13px;font-weight:600;">
                                <option value="0">🌐 All Pakistan (پورے پاکستان کے صارفین)</option>
                                <?php foreach ($allRegions as $r): ?>
                                    <option value="<?=$r['id']?>" <?=$filterRegionId===(int)$r['id']?'selected':''?>>🏛️ <?=e($r['name'])?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>User Name</th>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Province / Region <span class="ur">(صوبہ)</span></th>
                                <th>Assigned Rights <span class="ur">(اختیارات)</span></th>
                                <th>Allowed Funds <span class="ur">(مجاز فنڈز)</span></th>
                                <th>Assigned Cash Account <span class="ur">(کیش اکاؤنٹ)</span></th>
                                <th>Status</th>
                                <th>Last Login</th>
                                <th class="right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                                <?php 
                                    $uPerms = get_user_permissions((int)$u['id']);
                                    $uPermCount = count($uPerms);
                                    $uFundIds = get_user_assigned_fund_ids((int)$u['id']);
                                ?>
                                <tr>
                                    <td>
                                        <b><?=e($u['full_name'])?></b>
                                        <?php if ($u['email']): ?>
                                            <div style="font-size:11px;color:#64748b;"><?=e($u['email'])?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($u['phone_number'])): ?>
                                            <div style="font-size:11px;color:#16a34a;display:flex;align-items:center;gap:3px;margin-top:2px;">
                                                <span>📱 <?=e($u['phone_number'])?></span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><code><?=e($u['username'])?></code></td>
                                    <td>
                                        <?php if ($u['role_name'] === 'Super Admin'): ?>
                                            <span class="badge badge-purple"><?=e($u['role_name'])?></span>
                                        <?php elseif ($u['role_name'] === 'Regional Director'): ?>
                                            <span class="badge" style="background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;font-weight:700;">Director</span>
                                        <?php elseif ($u['role_name'] === 'Approver / Checker'): ?>
                                            <span class="badge" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;">Checker</span>
                                        <?php else: ?>
                                            <span class="badge badge-gray"><?=e($u['role_name'])?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($u['region_name'])): ?>
                                            <span class="badge" style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;font-size:11px;font-weight:600;">
                                                🏛️ <?=e($u['region_name'])?>
                                            </span>
                                        <?php else: ?>
                                            <span class="muted" style="font-size:11px;">— HQ / Central</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($u['role_name'] === 'Super Admin'): ?>
                                            <span class="badge badge-purple" style="font-size:11px;">Full Access <span class="ur">(مکمل اختیارات)</span></span>
                                        <?php elseif ($uPermCount > 0): ?>
                                            <span class="badge badge-blue" style="font-size:11px;" title="<?=e(implode(', ', $uPerms))?>">
                                                🛡️ <?=$uPermCount?> Rights <span class="ur">(اختیارات)</span>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-gray" style="font-size:11px;">Default <span class="ur">(رول کے مطابق)</span></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($u['role_name'] === 'Super Admin' || empty($uFundIds)): ?>
                                            <span class="badge badge-green" style="font-size:11px;">All Funds <span class="ur">(تمام فنڈز)</span></span>
                                        <?php else: ?>
                                            <?php
                                                $fNames = [];
                                                foreach ($allSystemFunds as $asf) {
                                                    if (in_array((int)$asf['id'], $uFundIds, true)) $fNames[] = $asf['name'];
                                                }
                                            ?>
                                            <span class="badge badge-amber" style="font-size:11px;" title="<?=e(implode(', ', $fNames))?>">
                                                🏦 <?=count($uFundIds)?> Funds <span class="ur">(فنڈز)</span>: <?=e(implode(', ', array_slice($fNames, 0, 2)))?><?=count($fNames) > 2 ? '...' : ''?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-blue" style="font-family:monospace;font-size:11.5px;">
                                            💼 <?=e(format_ui_text($u['cash_account_name'] ?: 'Cash in Hand'))?> (<?=e($u['account_code'] ?: '—')?>)
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ((int)$u['is_active'] === 1): ?>
                                            <span class="badge badge-green">Active</span>
                                        <?php else: ?>
                                            <span class="badge badge-red">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?=e($u['last_login_at'] ?: 'Never')?></td>
                                    <td class="right">
                                        <div class="btn-row" style="justify-content:flex-end;">
                                            <button type="button" class="btn secondary sm" style="padding:4px 8px;font-size:11.5px;" onclick="openQuickReset(<?=$u['id']?>, '<?=e(addslashes($u['full_name']))?>', '<?=e(addslashes($u['username']))?>')" title="Reset User Password">
                                                🔑 Reset
                                            </button>
                                            <a href="users.php?edit_id=<?=$u['id']?>#userFormCard" class="btn secondary sm" style="padding:4px 9px;font-size:11.5px;">
                                                ✏️ Edit
                                            </a>

                                            <?php if ((int)$u['id'] !== $currentUserId): ?>
                                                <a href="users.php?toggle_id=<?=$u['id']?>&token=<?=csrf_token()?>" class="btn secondary sm" style="padding:4px 9px;font-size:11.5px;">
                                                    <?=(int)$u['is_active'] === 1 ? 'Deactivate' : 'Activate'?>
                                                </a>
                                                <a href="users.php?delete_id=<?=$u['id']?>&token=<?=csrf_token()?>" class="btn danger sm" style="padding:4px 9px;font-size:11.5px;" onclick="return confirm('Are you sure you want to delete user \'<?=e($u['username'])?>\'?');">
                                                    🗑️ Delete
                                                </a>
                                            <?php else: ?>
                                                <span style="font-size:11px;color:#94a3b8;padding:4px 6px;">(You)</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </article>

        <?php endif; ?>

        <?=render_app_footer($madrasaName)?>
    </main>
</div>

<script>
var PRESETS = {
    'cashier': ['income.view', 'income.create', 'income.slip', 'expense.view', 'expense.create', 'donors.view', 'donors.manage', 'beneficiaries.view', 'beneficiaries.manage', 'tasks.view', 'tasks.update_progress'],
    'approver': ['income.view', 'income.create', 'income.slip', 'expense.view', 'expense.create', 'voucher.approve', 'voucher.direct_post', 'donors.view', 'donors.manage', 'beneficiaries.view', 'beneficiaries.manage', 'funds.view', 'funds.manage', 'accounts.view', 'accounts.manage', 'reports.view', 'reports.export', 'tasks.view', 'tasks.manage', 'tasks.update_progress'],
    'director': ['income.view', 'income.create', 'income.slip', 'expense.view', 'expense.create', 'voucher.approve', 'voucher.direct_post', 'donors.view', 'donors.manage', 'beneficiaries.view', 'beneficiaries.manage', 'funds.view', 'accounts.view', 'reports.view', 'tasks.view', 'tasks.manage', 'tasks.update_progress', 'regions.view', 'team.manage'],
    'viewer': ['income.view', 'expense.view', 'donors.view', 'beneficiaries.view', 'funds.view', 'accounts.view', 'reports.view', 'tasks.view'],
    'all': ['income.view', 'income.create', 'income.slip', 'expense.view', 'expense.create', 'voucher.approve', 'voucher.direct_post', 'donors.view', 'donors.manage', 'beneficiaries.view', 'beneficiaries.manage', 'funds.view', 'funds.manage', 'accounts.view', 'accounts.manage', 'reports.view', 'reports.export', 'tasks.view', 'tasks.manage', 'tasks.update_progress', 'users.manage', 'settings.manage', 'regions.view', 'regions.manage', 'team.manage']
};

function applyPreset(presetKey) {
    var boxes = document.querySelectorAll('.perm-checkbox');
    if (presetKey === 'none') {
        boxes.forEach(function(b) { b.checked = false; });
        return;
    }
    var targetList = PRESETS[presetKey] || [];
    boxes.forEach(function(b) {
        var code = b.getAttribute('data-code');
        b.checked = (presetKey === 'all') || (targetList.indexOf(code) !== -1);
    });
}

function toggleGroupCheckboxes(headerCheckbox) {
    var card = headerCheckbox.closest('.permission-group-card');
    if (!card) return;
    var groupBoxes = card.querySelectorAll('.perm-checkbox');
    groupBoxes.forEach(function(b) {
        b.checked = headerCheckbox.checked;
    });
}

function toggleAllFunds(check) {
    var boxes = document.querySelectorAll('.user-fund-checkbox');
    boxes.forEach(function(b) { b.checked = check; });
}

function onRoleChange(roleId) {
    var sel = document.getElementById('roleSelect');
    if (!sel) return;
    var opt = sel.options[sel.selectedIndex];
    var rName = opt ? opt.getAttribute('data-name') : '';
    if (rName === 'User / Cashier' || rName === 'Cashier') {
        applyPreset('cashier');
    } else if (rName === 'Approver / Checker' || rName === 'Checker') {
        applyPreset('approver');
    } else if (rName === 'Regional Director') {
        applyPreset('director');
    } else if (rName === 'Viewer') {
        applyPreset('viewer');
    }
}

function toggleUserForm(forceOpen) {
    var card = document.getElementById('userFormCard');
    var btn = document.getElementById('toggleUserBtn');
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
        var nameInput = card.querySelector('input[name="full_name"]');
        if (nameInput) setTimeout(function() { nameInput.focus(); }, 250);
    } else {
        card.style.display = 'none';
        if (btn) {
            btn.innerHTML = '＋ Create New User <span class="ur">(نیا صارف)</span>';
            btn.classList.remove('secondary');
            btn.classList.add('blue');
        }
    }
}

function toggleRegionForm(forceOpen) {
    var card = document.getElementById('regionFormCard');
    var btn = document.getElementById('toggleRegionBtn');
    if (!card) return;
    var isHidden = (card.style.display === 'none' || getComputedStyle(card).display === 'none');
    var open = (forceOpen !== undefined) ? forceOpen : isHidden;
    card.style.display = open ? 'block' : 'none';
    if (btn) {
        if (open) {
            btn.innerHTML = '✕ Close Form <span class="ur">(بند کریں)</span>';
            btn.classList.remove('blue');
            btn.classList.add('secondary');
        } else {
            btn.innerHTML = '＋ Add New Province / Region <span class="ur">(نیا صوبہ بنائیں)</span>';
            btn.classList.remove('secondary');
            btn.classList.add('blue');
        }
    }
    if (open) {
        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
        var nameInp = card.querySelector('input[name="region_name"]');
        if (nameInp) setTimeout(function() { nameInp.focus(); }, 200);
    }
}

function openQuickReset(userId, fullName, username) {
    document.getElementById('resetUserId').value = userId;
    document.getElementById('resetUserBadge').textContent = fullName + ' (@' + username + ')';
    document.getElementById('resetPassInput').value = '';
    document.getElementById('quickResetModal').classList.add('open');
    setTimeout(function() {
        document.getElementById('resetPassInput').focus();
    }, 200);
}

function closeQuickReset() {
    document.getElementById('quickResetModal').classList.remove('open');
}
</script>

<!-- Quick Password Reset Modal -->
<div id="quickResetModal" class="img-modal" onclick="if(event.target===this) closeQuickReset();">
    <div class="img-modal-content" style="max-width:440px;">
        <div class="img-modal-header">
            <div>
                <b style="font-size:15px;color:#0f172a;">🔑 Reset User Password</b>
                <span id="resetUserBadge" style="display:block;font-size:12px;color:#2563eb;font-weight:600;margin-top:2px;"></span>
            </div>
            <button type="button" class="close-btn" onclick="closeQuickReset()">&times;</button>
        </div>
        <form method="post" action="users.php" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
            <input type="hidden" name="action" value="quick_reset_password">
            <input type="hidden" name="user_id" id="resetUserId" value="">
            <div style="padding:20px;">
                <div class="form-group" style="margin-bottom:16px;">
                    <label>New Password <span class="ur">(نیا پاس ورڈ)</span> *</label>
                    <input type="text" name="reset_password" id="resetPassInput" required minlength="8" placeholder="Enter new password (min 8 chars)" style="width:100%;font-family:monospace;font-size:14px;">
                    <small class="muted" style="display:block;margin-top:4px;">Minimum 8 characters. Old password is not required.</small>
                </div>
                <div class="btn-row" style="justify-content:flex-end;margin-top:10px;">
                    <button type="button" class="btn secondary sm" onclick="closeQuickReset()">Cancel</button>
                    <button type="submit" class="btn success sm">💾 Set New Password</button>
                </div>
            </div>
        </form>
    </div>
</div>
</body>
</html>
