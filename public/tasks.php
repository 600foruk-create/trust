<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

require_permission('tasks.view');

$activePage = 'tasks';
$error = '';
$success = '';

$currentUser = current_user();
$userId = (int)$currentUser['id'];
$isSuperAdmin = function_exists('is_super_admin') ? is_super_admin($currentUser) : in_array($currentUser['role'] ?? '', ['Super Admin', 'Administrator'], true);
$isRegDirector = function_exists('is_regional_director') ? is_regional_director($currentUser) : false;
$canManageTasks = has_permission('tasks.manage') || $isSuperAdmin || $isRegDirector;
$canUpdateProgress = has_permission('tasks.update_progress') || $canManageTasks;

$madrasaName = function_exists('get_setting') ? get_setting('madrasa_name', 'Trust Accounts') : 'Trust Accounts';

// Handle Mark Notifications as Read
if (isset($_GET['mark_notifs_read'])) {
    if (function_exists('mark_all_notifications_read')) {
        mark_all_notifications_read($userId);
    }
    header('Location: tasks.php');
    exit;
}

// Handle Task Creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_task') {
    verify_csrf();
    if (!$canManageTasks) {
        $error = 'You do not have permission to create or assign tasks.';
    } else {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $projectLocation = trim($_POST['project_location'] ?? '');
        $targetLat = !empty($_POST['target_lat']) ? (float)$_POST['target_lat'] : null;
        $targetLng = !empty($_POST['target_lng']) ? (float)$_POST['target_lng'] : null;
        $radiusMeters = !empty($_POST['radius_meters']) ? max(50, (int)$_POST['radius_meters']) : 300;
        if ($projectLocation === '' && $targetLat !== null && $targetLng !== null) {
            $projectLocation = 'GPS (' . round($targetLat, 5) . ', ' . round($targetLng, 5) . ')';
        }
        $assignedTo = (int)($_POST['assigned_to'] ?? 0);
        $priority = in_array($_POST['priority'] ?? '', ['low', 'medium', 'high', 'urgent'], true) ? $_POST['priority'] : 'medium';
        $estimatedBudget = (float)($_POST['estimated_budget'] ?? 0);
        $startDate = !empty($_POST['start_date']) ? trim($_POST['start_date']) : date('Y-m-d');
        $dueDate = !empty($_POST['due_date']) ? trim($_POST['due_date']) : null;
        $fundId = (int)($_POST['fund_id'] ?? 0);

        if (empty($title)) {
            $error = 'Task title cannot be empty.';
        } elseif ($assignedTo <= 0) {
            $error = 'Please assign this task to a user / staff member.';
        } else {
            try {
                $prefix = 'TSK-' . date('Ym') . '-';
                $lastNo = $pdo->query("SELECT task_code FROM tasks WHERE task_code LIKE '{$prefix}%' ORDER BY id DESC LIMIT 1")->fetchColumn();
                $nextSeq = 1;
                if ($lastNo && preg_match('/-(\d+)$/', $lastNo, $m)) {
                    $nextSeq = (int)$m[1] + 1;
                }
                $taskCode = $prefix . str_pad((string)$nextSeq, 4, '0', STR_PAD_LEFT);

                // Handle optional initial Before Photo
                $beforePhoto = null;
                if (isset($_FILES['before_photo']) && $_FILES['before_photo']['error'] === UPLOAD_ERR_OK) {
                    $fileTmp = $_FILES['before_photo']['tmp_name'];
                    $fileName = $_FILES['before_photo']['name'];
                    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
                    if (in_array($ext, $allowedExts, true)) {
                        $uploadDir = __DIR__ . '/uploads/tasks/';
                        if (!is_dir($uploadDir)) {
                            @mkdir($uploadDir, 0755, true);
                        }
                        $storedName = 'before_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                        if (move_uploaded_file($fileTmp, $uploadDir . $storedName)) {
                            $beforePhoto = $storedName;
                        }
                    }
                }

                $stmt = $pdo->prepare("INSERT INTO tasks (task_code, title, description, project_location, target_lat, target_lng, radius_meters, before_photo, assigned_to, created_by, priority, status, progress_percent, estimated_budget, start_date, due_date, fund_id) 
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 0, ?, ?, ?, ?)");
                $stmt->execute([
                    $taskCode, $title, $description, $projectLocation ?: null,
                    $targetLat, $targetLng, $radiusMeters, $beforePhoto, $assignedTo, $userId,
                    $priority, $estimatedBudget, $startDate, $dueDate,
                    $fundId > 0 ? $fundId : null
                ]);
                $newTaskId = (int)$pdo->lastInsertId();

                // Initial creation log
                $stmtLog = $pdo->prepare("INSERT INTO task_logs (task_id, user_id, progress_percent, status, report_text) VALUES (?, ?, 0, 'pending', ?)");
                $stmtLog->execute([$newTaskId, $userId, 'Task created and assigned. (ٹاسک بنایا گیا اور سونپا گیا)']);

                if (function_exists('add_notification') && $assignedTo !== $userId) {
                    $creatorName = $currentUser['full_name'] ?? $currentUser['name'] ?? 'Admin';
                    add_notification(
                        $assignedTo,
                        '📋 New Task Assigned: ' . $title,
                        $creatorName . ' has assigned you a new task: "' . $title . '" (Priority: ' . strtoupper($priority) . ($dueDate ? ', Due: ' . date('d M Y', strtotime($dueDate)) : '') . ').',
                        'tasks.php?view_task=' . $newTaskId,
                        'task'
                    );
                }

                header('Location: tasks.php?success=created&code=' . urlencode($taskCode));
                exit;
            } catch (Exception $e) {
                $error = 'Failed to create task: ' . $e->getMessage();
            }
        }
    }
}

// Handle Task Update / Edit (Admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_task') {
    verify_csrf();
    if (!$canManageTasks) {
        $error = 'You do not have permission to edit tasks.';
    } else {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $projectLocation = trim($_POST['project_location'] ?? '');
        $targetLat = !empty($_POST['target_lat']) ? (float)$_POST['target_lat'] : null;
        $targetLng = !empty($_POST['target_lng']) ? (float)$_POST['target_lng'] : null;
        $radiusMeters = !empty($_POST['radius_meters']) ? max(50, (int)$_POST['radius_meters']) : 300;
        if ($projectLocation === '' && $targetLat !== null && $targetLng !== null) {
            $projectLocation = 'GPS (' . round($targetLat, 5) . ', ' . round($targetLng, 5) . ')';
        }
        $assignedTo = (int)($_POST['assigned_to'] ?? 0);
        $priority = in_array($_POST['priority'] ?? '', ['low', 'medium', 'high', 'urgent'], true) ? $_POST['priority'] : 'medium';
        $status = in_array($_POST['status'] ?? '', ['pending', 'in_progress', 'on_hold', 'completed', 'cancelled'], true) ? $_POST['status'] : 'in_progress';
        $progress = max(0, min(100, (int)($_POST['progress_percent'] ?? 0)));
        $estimatedBudget = (float)($_POST['estimated_budget'] ?? 0);
        $dueDate = !empty($_POST['due_date']) ? trim($_POST['due_date']) : null;
        $fundId = (int)($_POST['fund_id'] ?? 0);

        if ($taskId <= 0 || empty($title)) {
            $error = 'Invalid task information.';
        } else {
            try {
                $completedAt = ($status === 'completed' || $progress === 100) ? date('Y-m-d H:i:s') : null;
                if ($status === 'completed' && $progress < 100) {
                    $progress = 100;
                }

                $stmt = $pdo->prepare("UPDATE tasks SET title=?, description=?, project_location=?, target_lat=?, target_lng=?, radius_meters=?, assigned_to=?, priority=?, status=?, progress_percent=?, estimated_budget=?, due_date=?, fund_id=?, completed_at=? WHERE id=?");
                $stmt->execute([
                    $title, $description, $projectLocation ?: null,
                    $targetLat, $targetLng, $radiusMeters,
                    $assignedTo, $priority, $status,
                    $progress, $estimatedBudget, $dueDate,
                    $fundId > 0 ? $fundId : null, $completedAt, $taskId
                ]);

                header('Location: tasks.php?success=updated');
                exit;
            } catch (Exception $e) {
                $error = 'Failed to update task: ' . $e->getMessage();
            }
        }
    }
}

// Handle Daily Progress Report / Log Submission (Assigned User or Admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'post_progress') {
    verify_csrf();
    if (!$canUpdateProgress) {
        $error = 'You do not have permission to post task updates.';
    } else {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $progress = max(0, min(100, (int)($_POST['progress_percent'] ?? 0)));
        $status = in_array($_POST['status'] ?? '', ['in_progress', 'completed', 'on_hold', 'pending'], true) ? $_POST['status'] : 'in_progress';
        $reportText = trim($_POST['report_text'] ?? '');
        $lat = !empty($_POST['latitude']) ? (float)$_POST['latitude'] : null;
        $lng = !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null;
        $accuracy = !empty($_POST['location_accuracy']) ? (float)$_POST['location_accuracy'] : null;
        $locAddress = trim($_POST['location_address'] ?? '');

        // Fetch task to verify access
        $task = $pdo->query("SELECT * FROM tasks WHERE id = {$taskId}")->fetch();
        if (!$task) {
            $error = 'Task not found.';
        } elseif (!$canManageTasks && (int)$task['assigned_to'] !== $userId) {
            $error = 'You can only update tasks assigned to you.';
        } elseif (empty($reportText)) {
            $error = 'Please enter progress details or notes.';
        } else {
            try {
                $pdo->beginTransaction();

                // Distance & Geofence Calculation if project has target coordinates
                $distMeters = null;
                $isOnsite = null;
                if ($lat !== null && $lng !== null && !empty($task['target_lat']) && !empty($task['target_lng'])) {
                    $distMeters = calculate_distance_meters((float)$task['target_lat'], (float)$task['target_lng'], $lat, $lng);
                    $allowedRadius = !empty($task['radius_meters']) ? (int)$task['radius_meters'] : 300;
                    $isOnsite = ($distMeters !== null && $distMeters <= $allowedRadius) ? 1 : 0;
                }

                // Handle optional photo attachment
                $photoPath = null;
                if (isset($_FILES['photo_file']) && $_FILES['photo_file']['error'] === UPLOAD_ERR_OK) {
                    $fileTmp = $_FILES['photo_file']['tmp_name'];
                    $fileName = $_FILES['photo_file']['name'];
                    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

                    if (in_array($ext, $allowedExts, true)) {
                        $uploadDir = __DIR__ . '/uploads/tasks/';
                        if (!is_dir($uploadDir)) {
                            @mkdir($uploadDir, 0755, true);
                        }
                        $storedName = 'task_' . $taskId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                        if (move_uploaded_file($fileTmp, $uploadDir . $storedName)) {
                            $photoPath = $storedName;
                        }
                    }
                }

                if ($progress === 100 || $status === 'completed') {
                    $status = 'completed';
                    $progress = 100;
                    $completedAt = date('Y-m-d H:i:s');
                } else {
                    $completedAt = null;
                }

                // 1. Insert progress log with GPS location & onsite verification
                $stmtLog = $pdo->prepare("INSERT INTO task_logs (task_id, user_id, progress_percent, status, report_text, photo_attachment, latitude, longitude, location_accuracy, location_address, is_onsite, distance_meters) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmtLog->execute([$taskId, $userId, $progress, $status, $reportText, $photoPath, $lat, $lng, $accuracy, $locAddress ?: null, $isOnsite, $distMeters]);

                // 2. Update task record
                $stmtTask = $pdo->prepare("UPDATE tasks SET progress_percent = ?, status = ?, completed_at = COALESCE(?, completed_at) WHERE id = ?");
                $stmtTask->execute([$progress, $status, $completedAt, $taskId]);

                // 3. Save as official After Photo if 100% or explicitly marked
                if (!empty($photoPath) && ($progress === 100 || !empty($_POST['is_after_photo']))) {
                    $pdo->prepare("UPDATE tasks SET after_photo = ? WHERE id = ?")->execute([$photoPath, $taskId]);
                }

                // Notify Task Creator / Admin
                if (function_exists('add_notification') && (int)$task['created_by'] !== $userId) {
                    add_notification(
                        (int)$task['created_by'],
                        '📝 Progress Report: ' . $task['title'],
                        (current_user()['full_name'] ?? 'Worker') . ' submitted update (' . $progress . '%): ' . mb_strimwidth($reportText, 0, 70, '...'),
                        'tasks.php?view_task=' . $taskId,
                        'info'
                    );
                }

                $pdo->commit();
                header('Location: tasks.php?success=progress_posted&view_task=' . $taskId);
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Failed to record progress: ' . $e->getMessage();
            }
        }
    }
}

// Handle Supervisor Review / Approval / Revision Request (Admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'admin_review_task') {
    verify_csrf();
    if (!$canManageTasks) {
        $error = 'You do not have permission to review or approve tasks.';
    } else {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $reviewAction = trim($_POST['review_action'] ?? '');
        $adminRemark = trim($_POST['admin_remark'] ?? '');

        $task = $pdo->query("SELECT * FROM tasks WHERE id = {$taskId}")->fetch();
        if (!$task) {
            $error = 'Task not found.';
        } else {
            try {
                $pdo->beginTransaction();
                $curProgress = (int)$task['progress_percent'];
                $curStatus = $task['status'];
                $adminName = current_user()['full_name'] ?? 'Super Admin';

                if ($reviewAction === 'approve') {
                    $newStatus = 'completed';
                    $newProgress = 100;
                    $completedAt = date('Y-m-d H:i:s');
                    $logText = '✅ Approved by ' . $adminName . ($adminRemark ? ': ' . $adminRemark : ': Task verified and approved. (کام کا معائنہ کر کے مکمل و منظور کر لیا گیا)');

                    $stmtT = $pdo->prepare("UPDATE tasks SET status = ?, progress_percent = ?, completed_at = ? WHERE id = ?");
                    $stmtT->execute([$newStatus, $newProgress, $completedAt, $taskId]);

                    $stmtL = $pdo->prepare("INSERT INTO task_logs (task_id, user_id, progress_percent, status, report_text) VALUES (?, ?, ?, ?, ?)");
                    $stmtL->execute([$taskId, $userId, $newProgress, $newStatus, $logText]);

                    if (function_exists('add_notification') && (int)$task['assigned_to'] !== $userId) {
                        add_notification(
                            (int)$task['assigned_to'],
                            '✅ Task Approved: ' . $task['title'],
                            'Doctor Shireen has reviewed and approved your task as 100% completed.',
                            'tasks.php?view_task=' . $taskId,
                            'success'
                        );
                    }

                } elseif ($reviewAction === 'reject') {
                    $newStatus = 'in_progress';
                    $newProgress = max(0, min(95, $curProgress));
                    $logText = '⚠️ Revision Requested by ' . $adminName . ($adminRemark ? ': ' . $adminRemark : ': کام میں خامیاں ہیں یا تصدیق درکار ہے، دوبارہ رپورٹ بھیجیں۔');

                    $stmtT = $pdo->prepare("UPDATE tasks SET status = ?, progress_percent = ?, completed_at = NULL WHERE id = ?");
                    $stmtT->execute([$newStatus, $newProgress, $taskId]);

                    $stmtL = $pdo->prepare("INSERT INTO task_logs (task_id, user_id, progress_percent, status, report_text) VALUES (?, ?, ?, ?, ?)");
                    $stmtL->execute([$taskId, $userId, $newProgress, $newStatus, $logText]);

                    if (function_exists('add_notification') && (int)$task['assigned_to'] !== $userId) {
                        add_notification(
                            (int)$task['assigned_to'],
                            '⚠️ Task Revision Requested: ' . $task['title'],
                            'Doctor Shireen: ' . ($adminRemark ?: 'کام دوبارہ چیک کر کے پیشرفت رپورٹ بھیجیں۔'),
                            'tasks.php?view_task=' . $taskId,
                            'warning'
                        );
                    }

                } elseif ($reviewAction === 'note') {
                    if (empty($adminRemark)) {
                        throw new Exception('Please enter supervisor instruction or remark.');
                    }
                    $logText = '💬 Supervisor Note from ' . $adminName . ': ' . $adminRemark;

                    $stmtL = $pdo->prepare("INSERT INTO task_logs (task_id, user_id, progress_percent, status, report_text) VALUES (?, ?, ?, ?, ?)");
                    $stmtL->execute([$taskId, $userId, $curProgress, $curStatus, $logText]);

                    if (function_exists('add_notification') && (int)$task['assigned_to'] !== $userId) {
                        add_notification(
                            (int)$task['assigned_to'],
                            '💬 Supervisor Note on ' . $task['title'],
                            'Doctor Shireen: ' . $adminRemark,
                            'tasks.php?view_task=' . $taskId,
                            'warning'
                        );
                    }
                }

                $pdo->commit();
                header('Location: tasks.php?success=review_saved&view_task=' . $taskId);
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Failed to record supervisor review: ' . $e->getMessage();
            }
        }
    }
}

// Handle Task Deletion (Super Admin only)
if (isset($_GET['delete_id']) && $isSuperAdmin) {
    $delId = (int)$_GET['delete_id'];
    $token = $_GET['token'] ?? '';
    if (hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        try {
            $pdo->prepare("DELETE FROM tasks WHERE id = ?")->execute([$delId]);
            header('Location: tasks.php?success=deleted');
            exit;
        } catch (Exception $e) {
            $error = 'Failed to delete task: ' . $e->getMessage();
        }
    }
}

// Handle Direct Showcase Photo Upload (Before / After Photos)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_showcase_photos') {
    verify_csrf();
    $taskId = (int)($_POST['task_id'] ?? 0);
    $photoType = $_POST['photo_type'] ?? ''; // 'before' or 'after'

    if ($taskId > 0 && in_array($photoType, ['before', 'after'], true)) {
        if (isset($_FILES['showcase_photo']) && $_FILES['showcase_photo']['error'] === UPLOAD_ERR_OK) {
            $fileTmp = $_FILES['showcase_photo']['tmp_name'];
            $fileName = $_FILES['showcase_photo']['name'];
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];

            if (in_array($ext, $allowedExts, true)) {
                $uploadDir = __DIR__ . '/uploads/tasks/';
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0755, true);
                }
                $storedName = $photoType . '_' . $taskId . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                if (move_uploaded_file($fileTmp, $uploadDir . $storedName)) {
                    $col = ($photoType === 'before') ? 'before_photo' : 'after_photo';
                    $pdo->prepare("UPDATE tasks SET {$col} = ? WHERE id = ?")->execute([$storedName, $taskId]);

                    $label = ($photoType === 'before') ? 'Before Work Photo (کام سے پہلے کی تصویر)' : 'After Work Photo (کام مکمل ہونے کے بعد کی تصویر)';
                    $currentProgress = (int)$pdo->query("SELECT progress_percent FROM tasks WHERE id = {$taskId}")->fetchColumn();
                    $pdo->prepare("INSERT INTO task_logs (task_id, user_id, progress_percent, report_text, photo_attachment) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$taskId, $userId, $currentProgress, "Uploaded official {$label} for audit showcase.", $storedName]);
                }
            }
        }
    }
    header('Location: tasks.php?view_task=' . $taskId);
    exit;
}

// Fetch users for assignment dropdown
$users = $pdo->query("SELECT u.id, u.full_name, u.username, u.role_id, r.name AS role_name, reg.name AS region_name 
                      FROM users u 
                      LEFT JOIN roles r ON r.id = u.role_id 
                      LEFT JOIN regions reg ON reg.id = u.region_id 
                      WHERE u.is_active = 1 
                      ORDER BY u.full_name ASC")->fetchAll();
$funds = function_exists('get_user_allowed_funds') ? get_user_allowed_funds() : [];
if (empty($funds)) {
    $funds = $pdo->query("SELECT id, name, fund_code FROM funds WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
}

// Filters
$statusFilter = trim($_GET['status'] ?? 'all');
$userFilter = (int)($_GET['user_id'] ?? 0);
$priorityFilter = trim($_GET['priority'] ?? 'all');
$search = trim($_GET['search'] ?? '');

// Base query for tasks
$params = [];
$whereClauses = ["1=1"];

if (!$canManageTasks) {
    $whereClauses[] = "t.assigned_to = ?";
    $params[] = $userId;
} elseif ($userFilter > 0) {
    $whereClauses[] = "t.assigned_to = ?";
    $params[] = $userFilter;
}

if ($statusFilter !== 'all') {
    $whereClauses[] = "t.status = ?";
    $params[] = $statusFilter;
}

if ($priorityFilter !== 'all') {
    $whereClauses[] = "t.priority = ?";
    $params[] = $priorityFilter;
}

if (!empty($search)) {
    $whereClauses[] = "(t.task_code LIKE ? OR t.title LIKE ? OR t.description LIKE ? OR u.full_name LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$whereSql = implode(' AND ', $whereClauses);
$tasksQuery = "SELECT t.*, u.full_name AS assignee_name, u.username AS assignee_username, u.phone_number AS assignee_phone,
                      c.full_name AS creator_name, c.phone_number AS creator_phone, f.name AS fund_name, f.fund_code,
                      COALESCE((SELECT SUM(v.total_amount) FROM vouchers v WHERE v.task_id = t.id AND v.voucher_type = 'expense' AND v.status = 'posted'), 0) AS total_expense,
                      (SELECT COUNT(*) FROM task_logs tl WHERE tl.task_id = t.id) AS logs_count,
                      (SELECT MAX(tl.created_at) FROM task_logs tl WHERE tl.task_id = t.id) AS last_log_at
               FROM tasks t
               JOIN users u ON u.id = t.assigned_to
               JOIN users c ON c.id = t.created_by
               LEFT JOIN funds f ON f.id = t.fund_id
               WHERE {$whereSql}
               ORDER BY CASE t.priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END, t.id DESC";

$stmt = $pdo->prepare($tasksQuery);
$stmt->execute($params);
$taskList = $stmt->fetchAll();

// Excel / CSV Export Handler
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $timestamp = date('Y-m-d_His');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="tasks_report_' . $timestamp . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // Output UTF-8 BOM so Urdu/Arabic characters display properly in Excel!
    fputs($out, "\xEF\xBB\xBF");

    // CSV Column Headers
    fputcsv($out, [
        'Task Code', 'Task Title', 'Priority', 'Status', 'Progress %',
        'Assigned Worker', 'Worker Phone', 'Assigned By', 'Project / Fund',
        'Project Location', 'Estimated Budget (PKR)', 'Actual Expense (PKR)',
        'Start Date', 'Due Date', 'Created At', 'Completed At'
    ]);

    foreach ($taskList as $r) {
        fputcsv($out, [
            $r['task_code'],
            $r['title'],
            strtoupper($r['priority']),
            ucwords(str_replace('_', ' ', $r['status'])),
            $r['progress_percent'] . '%',
            $r['assignee_name'],
            $r['assignee_phone'] ?? '',
            $r['creator_name'],
            $r['fund_name'] ?? 'General',
            $r['project_location'] ?? '',
            $r['estimated_budget'],
            $r['total_expense'],
            $r['start_date'] ?? '',
            $r['due_date'] ?? '',
            $r['created_at'],
            $r['completed_at'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}

// Statistics (KPIs)
$statsFilterUser = $canManageTasks ? "" : "WHERE assigned_to = {$userId}";
$totalTasksCount = (int)$pdo->query("SELECT COUNT(*) FROM tasks {$statsFilterUser}")->fetchColumn();
$inProgressCount = (int)$pdo->query("SELECT COUNT(*) FROM tasks " . ($statsFilterUser ? "{$statsFilterUser} AND" : "WHERE") . " status = 'in_progress'")->fetchColumn();
$pendingTasksCount = (int)$pdo->query("SELECT COUNT(*) FROM tasks " . ($statsFilterUser ? "{$statsFilterUser} AND" : "WHERE") . " status = 'pending'")->fetchColumn();
$completedTasksCount = (int)$pdo->query("SELECT COUNT(*) FROM tasks " . ($statsFilterUser ? "{$statsFilterUser} AND" : "WHERE") . " status = 'completed'")->fetchColumn();

$totalBudgetSpent = (float)$pdo->query("SELECT COALESCE(SUM(v.total_amount), 0) FROM vouchers v WHERE v.task_id IS NOT NULL AND v.voucher_type = 'expense' AND v.status = 'posted'")->fetchColumn();
$totalEstimatedBudget = (float)$pdo->query("SELECT COALESCE(SUM(estimated_budget), 0) FROM tasks")->fetchColumn();

// Fetch timeline logs if viewing a specific task
$viewTaskId = (int)($_GET['view_task'] ?? 0);
$viewTask = null;
$viewTaskLogs = [];
$viewTaskVouchers = [];

if ($viewTaskId > 0) {
    $stmtVT = $pdo->prepare("SELECT t.*, u.full_name AS assignee_name, u.username AS assignee_username, u.phone_number AS assignee_phone,
                             c.full_name AS creator_name, c.phone_number AS creator_phone, f.name AS fund_name, f.fund_code,
                             COALESCE((SELECT SUM(v.total_amount) FROM vouchers v WHERE v.task_id = t.id AND v.voucher_type = 'expense' AND v.status = 'posted'), 0) AS total_expense
                             FROM tasks t
                             JOIN users u ON u.id = t.assigned_to
                             JOIN users c ON c.id = t.created_by
                             LEFT JOIN funds f ON f.id = t.fund_id
                             WHERE t.id = ?");
    $stmtVT->execute([$viewTaskId]);
    $viewTask = $stmtVT->fetch();

    if ($viewTask) {
        $stmtLogs = $pdo->prepare("SELECT tl.*, u.full_name AS user_name, u.username FROM task_logs tl JOIN users u ON u.id = tl.user_id WHERE tl.task_id = ? ORDER BY tl.id DESC");
        $stmtLogs->execute([$viewTaskId]);
        $viewTaskLogs = $stmtLogs->fetchAll();

        $stmtV = $pdo->prepare("SELECT v.*, u.full_name AS submitter_name FROM vouchers v LEFT JOIN users u ON u.id = v.created_by WHERE v.task_id = ? ORDER BY v.voucher_date DESC, v.id DESC");
        $stmtV->execute([$viewTaskId]);
        $viewTaskVouchers = $stmtV->fetchAll();

        // One-Click Official Print Dossier / PDF View
        if (isset($_GET['print']) && (int)$_GET['print'] === 1) {
            $orgLogo = function_exists('get_setting') ? get_setting('org_logo', '') : '';
            $orgSubtitle = function_exists('get_setting') ? get_setting('org_subtitle', '') : '';
            $madrasaPhone = function_exists('get_setting') ? get_setting('madrasa_phone', '') : '';
            $madrasaAddress = function_exists('get_setting') ? get_setting('madrasa_address', '') : '';
            $printedBy = current_user()['full_name'] ?? 'Super Admin';
            $printDate = date('d M Y, h:i A');
            ?>
            <!doctype html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <title>Dossier - <?=e($viewTask['task_code'])?> - <?=e($viewTask['title'])?></title>
                <meta name="viewport" content="width=device-width,initial-scale=1">
                <link rel="stylesheet" href="assets/style.css?v=<?=css_ver()?>">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
                <style>
                    body { background: #f1f5f9; color: #0f172a; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 20px; }
                    .dossier-card { max-width: 960px; margin: 0 auto; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); padding: 32px 36px; }
                    .print-bar { max-width: 960px; margin: 0 auto 16px; display: flex; justify-content: space-between; align-items: center; background: #1e293b; color: #fff; padding: 12px 20px; border-radius: 8px; }
                    .dossier-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px; }
                    .dossier-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 18px; }
                    .dossier-box table { width: 100%; border-collapse: collapse; font-size: 13px; }
                    .dossier-box td { padding: 5px 4px; vertical-align: top; }
                    .dossier-box td.lbl { color: #64748b; font-weight: 600; width: 38%; }
                    .dossier-box td.val { color: #0f172a; font-weight: 700; }
                    .showcase-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-bottom: 24px; }
                    .showcase-frame { border: 1.5px solid #cbd5e1; border-radius: 10px; overflow: hidden; background: #fafafa; text-align: center; }
                    .showcase-header { background: #f1f5f9; padding: 8px 12px; font-weight: 700; font-size: 12.5px; border-bottom: 1px solid #cbd5e1; }
                    .showcase-img-wrap { padding: 12px; min-height: 220px; display: flex; align-items: center; justify-content: center; }
                    .showcase-img { max-width: 100%; max-height: 240px; border-radius: 6px; object-fit: cover; }
                    .audit-table { width: 100%; border-collapse: collapse; font-size: 12.5px; margin-bottom: 24px; }
                    .audit-table th { background: #f1f5f9; border: 1px solid #cbd5e1; padding: 8px 10px; text-align: left; font-weight: 700; color: #334155; }
                    .audit-table td { border: 1px solid #e2e8f0; padding: 8px 10px; vertical-align: top; color: #334155; }
                    .signatures-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-top: 40px; padding-top: 20px; border-top: 1px dashed #cbd5e1; text-align: center; }
                    .sig-line { border-top: 1.5px solid #0f172a; margin-top: 45px; padding-top: 6px; font-weight: 700; font-size: 12px; color: #0f172a; }
                    @media print {
                        body { background: #fff; padding: 0; color: #000; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                        .no-print { display: none !important; }
                        .dossier-card { border: none; box-shadow: none; padding: 0; max-width: 100%; }
                        .showcase-frame { border: 1px solid #000 !important; }
                        .audit-table th, .audit-table td { border: 1px solid #666 !important; }
                    }
                </style>
            </head>
            <body>
                <div class="print-bar no-print">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <span style="font-size:18px;">📄</span>
                        <b>Official Print Dossier: <?=e($viewTask['task_code'])?></b>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <button type="button" class="btn success" style="background:#16a34a;border-color:#16a34a;color:#fff;font-weight:700;padding:7px 16px;cursor:pointer;" onclick="window.print()">
                            🖨️ Print / Save as PDF
                        </button>
                        <a href="tasks.php?view_task=<?=$viewTask['id']?>" style="background:#475569;color:#fff;text-decoration:none;padding:7px 14px;border-radius:6px;font-size:12px;font-weight:600;">
                            &larr; Return to Tasks
                        </a>
                    </div>
                </div>

                <div class="dossier-card">
                    <!-- Institution Header -->
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;padding-bottom:18px;border-bottom:2px solid #0f172a;margin-bottom:20px;">
                        <div style="display:flex;align-items:center;gap:16px;">
                            <?php if ($orgLogo): ?>
                                <img src="<?=e($orgLogo)?>" alt="Logo" style="height:64px;max-width:90px;object-fit:contain;">
                            <?php endif; ?>
                            <div>
                                <h1 style="margin:0;font-size:20px;color:#0f172a;font-weight:800;"><?=e($madrasaName)?></h1>
                                <?php if ($orgSubtitle): ?>
                                    <div style="font-size:12px;color:#475569;margin-top:2px;"><?=e($orgSubtitle)?></div>
                                <?php endif; ?>
                                <div style="font-size:11.5px;color:#64748b;margin-top:4px;">
                                    <?php if ($madrasaPhone): ?><span>📞 <?=e($madrasaPhone)?></span><?php endif; ?>
                                    <?php if ($madrasaAddress): ?> &bull; <span>📍 <?=e($madrasaAddress)?></span><?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div style="text-align:right;">
                            <div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:700;letter-spacing:0.5px;">Document Ref:</div>
                            <div style="font-size:16px;font-family:monospace;font-weight:800;color:#0369a1;"><?=e($viewTask['task_code'])?></div>
                            <div style="font-size:11px;color:#64748b;margin-top:4px;">Date: <?=e($printDate)?></div>
                            <div style="font-size:11px;color:#64748b;">Printed By: <b><?=e($printedBy)?></b></div>
                        </div>
                    </div>

                    <!-- Report Title Banner -->
                    <div style="background:#0f172a;color:#ffffff;padding:10px 16px;border-radius:6px;text-align:center;margin-bottom:22px;">
                        <div style="font-size:14px;font-weight:800;letter-spacing:1px;text-transform:uppercase;">FIELD PROJECT & TASK VERIFICATION DOSSIER</div>
                        <div style="font-size:12px;color:#94a3b8;font-weight:600;margin-top:2px;" class="ur">باضابطہ ٹاسک و فیلڈ پراجیکٹ آڈٹ رپورٹ</div>
                    </div>

                    <!-- Overview Data Grid -->
                    <div class="dossier-grid">
                        <div class="dossier-box">
                            <div style="font-size:12px;font-weight:800;color:#0f172a;border-bottom:1px solid #e2e8f0;padding-bottom:6px;margin-bottom:8px;text-transform:uppercase;">
                                📋 Task Details
                            </div>
                            <table>
                                <tr><td class="lbl">Task Title:</td><td class="val"><?=e($viewTask['title'])?></td></tr>
                                <tr><td class="lbl">Category / Fund:</td><td class="val"><?=e($viewTask['fund_name'] ?? 'General / Not Specified')?></td></tr>
                                <tr><td class="lbl">Priority:</td><td class="val"><span style="text-transform:uppercase;"><?=e($viewTask['priority'])?></span></td></tr>
                                <tr><td class="lbl">Current Status:</td><td class="val"><b><?=ucwords(str_replace('_', ' ', $viewTask['status']))?></b> (<?=(int)$viewTask['progress_percent']?>%)</td></tr>
                                <tr><td class="lbl">Site Location:</td><td class="val"><?=e($viewTask['project_location'] ?: 'General Site')?></td></tr>
                                <?php if (!empty($viewTask['target_lat']) && !empty($viewTask['target_lng'])): ?>
                                    <tr><td class="lbl">Target GPS:</td><td class="val"><?=round((float)$viewTask['target_lat'], 5)?>, <?=round((float)$viewTask['target_lng'], 5)?> (Radius: <?=$viewTask['radius_meters']?>m)</td></tr>
                                <?php endif; ?>
                            </table>
                        </div>

                        <div class="dossier-box">
                            <div style="font-size:12px;font-weight:800;color:#0f172a;border-bottom:1px solid #e2e8f0;padding-bottom:6px;margin-bottom:8px;text-transform:uppercase;">
                                👤 Execution & Financials
                            </div>
                            <table>
                                <tr><td class="lbl">Assigned Worker:</td><td class="val"><?=e($viewTask['assignee_name'])?> <?=!empty($viewTask['assignee_phone']) ? '('.e($viewTask['assignee_phone']).')' : ''?></td></tr>
                                <tr><td class="lbl">Assigned By:</td><td class="val"><?=e($viewTask['creator_name'])?></td></tr>
                                <tr><td class="lbl">Start Date:</td><td class="val"><?=$viewTask['start_date'] ? date('d M Y', strtotime($viewTask['start_date'])) : '—'?></td></tr>
                                <tr><td class="lbl">Due Date:</td><td class="val"><?=$viewTask['due_date'] ? date('d M Y', strtotime($viewTask['due_date'])) : '—'?></td></tr>
                                <tr><td class="lbl">Completed Date:</td><td class="val"><?=$viewTask['completed_at'] ? date('d M Y, h:i A', strtotime($viewTask['completed_at'])) : ($viewTask['status'] === 'completed' ? 'Completed' : 'In Progress')?></td></tr>
                                <tr><td class="lbl">Estimated Budget:</td><td class="val" style="color:#0f172a;"><?=money($viewTask['estimated_budget'])?></td></tr>
                                <tr><td class="lbl">Expenses Recorded:</td><td class="val" style="color:#b91c1c;"><?=money($viewTask['total_expense'])?></td></tr>
                            </table>
                        </div>
                    </div>

                    <?php if (!empty($viewTask['description'])): ?>
                        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 16px;margin-bottom:24px;font-size:12.5px;color:#334155;line-height:1.6;">
                            <b>Scope & Instructions:</b><br>
                            <?=nl2br(e($viewTask['description']))?>
                        </div>
                    <?php endif; ?>

                    <!-- Before & After Photo Comparison Showcase -->
                    <div style="margin-bottom:24px;">
                        <div style="font-size:13px;font-weight:800;color:#0f172a;margin-bottom:10px;text-transform:uppercase;display:flex;align-items:center;gap:6px;">
                            <span>📸 Visual Quality Audit (Before & After Comparison)</span>
                            <span class="ur" style="font-weight:normal;color:#64748b;font-size:12px;">(تصدیقی تصویری ثبوت)</span>
                        </div>
                        <div class="showcase-grid">
                            <div class="showcase-frame">
                                <div class="showcase-header" style="color:#475569;">
                                    📷 1. Before Work <span class="ur">(کام شروع کرنے سے پہلے)</span>
                                </div>
                                <div class="showcase-img-wrap">
                                    <?php if (!empty($viewTask['before_photo']) && is_file(__DIR__ . '/uploads/tasks/' . $viewTask['before_photo'])): ?>
                                        <img src="uploads/tasks/<?=e($viewTask['before_photo'])?>" class="showcase-img" alt="Before Work">
                                    <?php else: ?>
                                        <div style="color:#94a3b8;font-size:12px;">
                                            <span style="font-size:24px;display:block;margin-bottom:4px;">📷</span>
                                            No Initial Photo Recorded
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="showcase-frame" style="border-color:#86efac;background:#f0fdf4;">
                                <div class="showcase-header" style="color:#166534;background:#dcfce7;border-color:#bbf7d0;">
                                    📸 2. After Work <span class="ur">(کام مکمل ہونے کے بعد)</span>
                                </div>
                                <div class="showcase-img-wrap">
                                    <?php if (!empty($viewTask['after_photo']) && is_file(__DIR__ . '/uploads/tasks/' . $viewTask['after_photo'])): ?>
                                        <img src="uploads/tasks/<?=e($viewTask['after_photo'])?>" class="showcase-img" alt="After Work">
                                    <?php else: ?>
                                        <div style="color:#166534;font-size:12px;">
                                            <span style="font-size:24px;display:block;margin-bottom:4px;">📸</span>
                                            Awaiting Completion Photo
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Milestone & Activity Verification Timeline -->
                    <div style="margin-bottom:24px;">
                        <div style="font-size:13px;font-weight:800;color:#0f172a;margin-bottom:10px;text-transform:uppercase;">
                            ⏱️ Activity Timeline & Milestone Verification
                        </div>
                        <?php if ($viewTaskLogs): ?>
                            <table class="audit-table">
                                <thead>
                                    <tr>
                                        <th style="width:18%;">Date & Time</th>
                                        <th style="width:20%;">User / Officer</th>
                                        <th style="width:12%;">Progress</th>
                                        <th style="width:20%;">Field Verification</th>
                                        <th style="width:30%;">Log Remarks / Supervisor Note</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($viewTaskLogs as $log): ?>
                                        <tr>
                                            <td><?=date('d M Y, h:i A', strtotime($log['created_at']))?></td>
                                            <td><b><?=e($log['user_name'])?></b></td>
                                            <td>
                                                <span style="font-weight:700;color:#2563eb;"><?=(int)$log['progress_percent']?>%</span>
                                            </td>
                                            <td>
                                                <?php if ($log['is_onsite'] === 1 || $log['is_onsite'] === '1'): ?>
                                                    <span style="color:#15803d;font-weight:700;">✓ On-Site</span>
                                                    <?=($log['distance_meters'] !== null ? '('.$log['distance_meters'].'m)' : '')?>
                                                <?php elseif ($log['is_onsite'] === 0 || $log['is_onsite'] === '0'): ?>
                                                    <span style="color:#b91c1c;font-weight:700;">⚠️ Off-Site</span>
                                                    <?=($log['distance_meters'] !== null ? '('.round($log['distance_meters']/1000, 1).'km away)' : '')?>
                                                <?php else: ?>
                                                    <span style="color:#64748b;">— General Log</span>
                                                <?php endif; ?>
                                                <?php if (!empty($log['latitude']) && !empty($log['longitude'])): ?>
                                                    <div style="font-size:10px;color:#64748b;font-family:monospace;">(<?=round((float)$log['latitude'], 4)?>, <?=round((float)$log['longitude'], 4)?>)</div>
                                                <?php endif; ?>
                                            </td>
                                            <td style="line-height:1.4;">
                                                <?=nl2br(e($log['report_text']))?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div style="font-size:12px;color:#64748b;padding:12px;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:6px;text-align:center;">
                                No activity logs recorded for this task.
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Linked Financial Expenses -->
                    <?php if ($viewTaskVouchers): ?>
                        <div style="margin-bottom:24px;">
                            <div style="font-size:13px;font-weight:800;color:#0f172a;margin-bottom:10px;text-transform:uppercase;">
                                💰 Financial Expenditures Linked to Task
                            </div>
                            <table class="audit-table">
                                <thead>
                                    <tr>
                                        <th style="width:20%;">Voucher Code</th>
                                        <th style="width:20%;">Date</th>
                                        <th style="width:40%;">Description / Payee</th>
                                        <th style="width:20%;text-align:right;">Amount (PKR)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($viewTaskVouchers as $voc): ?>
                                        <tr>
                                            <td><b><?=e($voc['voucher_number'])?></b></td>
                                            <td><?=date('d M Y', strtotime($voc['voucher_date']))?></td>
                                            <td><?=e($voc['description'] ?: 'Expense linked to task')?></td>
                                            <td style="text-align:right;font-weight:700;color:#b91c1c;"><?=money($voc['total_amount'])?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <!-- Signatures Block -->
                    <div class="signatures-grid">
                        <div>
                            <div class="sig-line">
                                Field Worker / In-Charge<br>
                                <span style="font-weight:normal;color:#64748b;font-size:11px;"><?=e($viewTask['assignee_name'])?></span>
                            </div>
                        </div>
                        <div>
                            <div class="sig-line">
                                Field Supervisor / Inspector<br>
                                <span style="font-weight:normal;color:#64748b;font-size:11px;"><?=e($viewTask['creator_name'])?></span>
                            </div>
                        </div>
                        <div>
                            <div class="sig-line">
                                Super Admin / Trustee Approval<br>
                                <span style="font-weight:normal;color:#64748b;font-size:11px;">Verified & Approved</span>
                            </div>
                        </div>
                    </div>
                </div>
            </body>
            </html>
            <?php
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Tasks & Activities | <?=e($madrasaName)?></title>
    <link rel="stylesheet" href="assets/style.css?v=<?=css_ver()?>">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
    <style>
        .progress-bar-wrap { background: #e2e8f0; border-radius: 999px; height: 8px; overflow: hidden; width: 100%; position: relative; }
        .progress-bar-fill { height: 100%; border-radius: 999px; transition: width .3s ease; }
        .timeline-item { position: relative; padding-left: 28px; padding-bottom: 22px; border-left: 2px solid #e2e8f0; }
        .timeline-item:last-child { border-left-color: transparent; padding-bottom: 0; }
        .timeline-dot { position: absolute; left: -7px; top: 0; width: 12px; height: 12px; border-radius: 50%; background: #2563eb; border: 2px solid #fff; }
        .priority-badge { font-size: 11px; padding: 2px 7px; border-radius: 4px; font-weight: 700; text-transform: uppercase; }
        .priority-urgent { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .priority-high { background: #ffedd5; color: #9a3412; border: 1px solid #fed7aa; }
        .priority-medium { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
        .priority-low { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
        .task-card { background: #ffffff; border: 1px solid var(--line); border-radius: 12px; padding: 18px; margin-bottom: 14px; transition: all .15s ease; box-shadow: 0 1px 3px rgba(0,0,0,0.03); }
        .task-card:hover { border-color: #cbd5e1; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .modal-overlay { 
            display: none; 
            position: fixed; 
            inset: 0; 
            z-index: 10000; 
            background: rgba(15, 23, 42, 0.75); 
            backdrop-filter: blur(4px); 
            place-items: center; 
            padding: 16px; 
            overflow-y: auto; 
            box-sizing: border-box; 
        }
        .modal-overlay.open { 
            display: grid; 
        }
        #progressModal, #editTaskModal, #createTaskModal {
            z-index: 10050;
        }
        #viewDetailModal {
            z-index: 10000;
        }
        .modal-card { 
            background: #ffffff; 
            border-radius: 14px; 
            width: 100%; 
            max-width: 760px; 
            max-height: 90vh; 
            display: flex; 
            flex-direction: column; 
            overflow: hidden; 
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.35); 
            margin: auto; 
            position: relative; 
        }
        .modal-card form {
            display: flex;
            flex-direction: column;
            overflow: hidden;
            flex: 1;
            min-height: 0;
            margin: 0;
        }
        .modal-header { padding: 16px 22px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #f8fafc; flex-shrink: 0; }
        .modal-body { padding: 20px 22px; overflow-y: auto; flex: 1; }
    </style>
</head>
<body>
<div class="app-shell">
    <?php require __DIR__ . '/../app/sidebar.php'; ?>

    <main class="main">
        <header class="topbar">
            <div>
                <span class="eyebrow">PROJECT MANAGEMENT</span>
                <h1>Tasks & Activities <span class="ur">(سرگرمیاں و ٹاسکس)</span></h1>
            </div>
            <div class="btn-row">
                <?php if ($canManageTasks): ?>
                    <button class="btn blue" onclick="openCreateTaskModal()">
                        ＋ Assign New Task <span class="ur">(نیا ٹاسک بنائیں)</span>
                    </button>
                <?php endif; ?>
            </div>
        </header>

        <?php if (!empty($error)): ?>
            <div class="alert danger"><?=e($error)?></div>
        <?php endif; ?>
        <?php if (!empty($_GET['success'])): ?>
            <div class="alert success">
                <?php if ($_GET['success'] === 'created'): ?>
                    Task <b><?=e($_GET['code'] ?? '')?></b> successfully assigned to user!
                <?php elseif ($_GET['success'] === 'updated'): ?>
                    Task updated successfully!
                <?php elseif ($_GET['success'] === 'progress_posted'): ?>
                    Progress report and update logged successfully!
                <?php elseif ($_GET['success'] === 'deleted'): ?>
                    Task removed successfully.
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php 
            $myUnreadNotifs = function_exists('get_user_unread_notifications') ? get_user_unread_notifications($userId, 4) : [];
        ?>
        <?php if (!empty($myUnreadNotifs)): ?>
            <div style="background:#fffbeb;border:1.5px solid #fde68a;border-radius:10px;padding:12px 18px;margin-bottom:20px;box-shadow:0 2px 6px rgba(245,158,11,0.08);display:flex;justify-content:space-between;align-items:flex-start;gap:12px;">
                <div style="display:flex;align-items:flex-start;gap:12px;">
                    <span style="font-size:24px;line-height:1;">🔔</span>
                    <div>
                        <b style="font-size:13.5px;color:#92400e;display:block;">Supervisor Instructions & Alerts <span class="ur">(نگران کی ضروری ہدایات و نوٹس)</span></b>
                        <?php foreach ($myUnreadNotifs as $un): ?>
                            <div style="margin-top:6px;font-size:13px;color:#78350f;">
                                <strong><?=e($un['title'])?>:</strong> <?=e($un['message'])?>
                                <?php if (!empty($un['link_url'])): ?>
                                    <a href="<?=e($un['link_url'])?>" style="color:#2563eb;font-weight:700;margin-left:6px;text-decoration:underline;">
                                        Open Task &rarr;
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <a href="tasks.php?mark_notifs_read=1" class="btn secondary sm" style="font-size:11px;padding:3px 9px;white-space:nowrap;">
                    ✓ Mark Read
                </a>
            </div>
        <?php endif; ?>

        <!-- Top KPI Overview Metrics -->
        <section class="stats-grid">
            <article class="stat-card">
                <div class="stat-top">
                    <span>Total Tasks <span class="ur">(کل ٹاسکس)</span></span>
                    <span class="stat-icon purple">📋</span>
                </div>
                <strong><?=$totalTasksCount?></strong>
                <small>Assigned projects & tasks</small>
            </article>

            <article class="stat-card">
                <div class="stat-top">
                    <span>In Progress <span class="ur">(جاری کام)</span></span>
                    <span class="stat-icon blue">⚡</span>
                </div>
                <strong style="color:#2563eb;"><?=$inProgressCount?></strong>
                <small>Active work in execution</small>
            </article>

            <article class="stat-card">
                <div class="stat-top">
                    <span>Pending / Not Started <span class="ur">(زیرِ التواء)</span></span>
                    <span class="stat-icon amber">⏳</span>
                </div>
                <strong style="color:#d97706;"><?=$pendingTasksCount?></strong>
                <small>Waiting for staff start</small>
            </article>

            <article class="stat-card">
                <div class="stat-top">
                    <span>Completed <span class="ur">(مکمل شدہ)</span></span>
                    <span class="stat-icon green">✓</span>
                </div>
                <strong style="color:#15803d;"><?=$completedTasksCount?></strong>
                <small>Successfully achieved</small>
            </article>

            <article class="stat-card">
                <div class="stat-top">
                    <span>Expenses on Tasks <span class="ur">(کل خرچ)</span></span>
                    <span class="stat-icon red">💰</span>
                </div>
                <strong style="color:#b91c1c;"><?=money($totalBudgetSpent)?></strong>
                <small>Est. Budget: <?=money($totalEstimatedBudget)?></small>
            </article>
        </section>

        <!-- Tasks Filter & Toolbar -->
        <article class="panel" style="margin-top:20px;">
            <div class="panel-heading">
                <div>
                    <h3>Task Tracker & Activity Log</h3>
                    <p>Track assignments, daily milestone updates, and financial expenditures</p>
                </div>
            </div>

            <!-- Filter Bar -->
            <form method="get" action="tasks.php" class="filter-bar">
                <input name="search" placeholder="Search by Code, Title, Assignee..." value="<?=e($search)?>" style="flex:2;min-width:180px;">
                
                <select name="status" onchange="this.form.submit()" style="flex:1;min-width:140px;">
                    <option value="all" <?=$statusFilter==='all'?'selected':''?>>All Statuses <span class="ur">(تمام کیفیات)</span></option>
                    <option value="in_progress" <?=$statusFilter==='in_progress'?'selected':''?>>⚡ In Progress (جاری)</option>
                    <option value="pending" <?=$statusFilter==='pending'?'selected':''?>>⏳ Pending (زیرِ التواء)</option>
                    <option value="completed" <?=$statusFilter==='completed'?'selected':''?>>✓ Completed (مکمل)</option>
                    <option value="on_hold" <?=$statusFilter==='on_hold'?'selected':''?>>⏸ On Hold (روکا گیا)</option>
                </select>

                <?php if ($canManageTasks): ?>
                    <select name="user_id" onchange="this.form.submit()" style="flex:1;min-width:140px;">
                        <option value="0">All Staff / Assignees <span class="ur">(تمام ملازمین)</span></option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?=$u['id']?>" <?=$userFilter === (int)$u['id'] ? 'selected' : ''?>>
                                <?=e($u['full_name'])?> (@<?=e($u['username'])?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <select name="priority" onchange="this.form.submit()" style="flex:1;min-width:120px;">
                    <option value="all" <?=$priorityFilter==='all'?'selected':''?>>All Priorities <span class="ur">(تمام ترجیحات)</span></option>
                    <option value="urgent" <?=$priorityFilter==='urgent'?'selected':''?>>🚨 Urgent</option>
                    <option value="high" <?=$priorityFilter==='high'?'selected':''?>>🔺 High</option>
                    <option value="medium" <?=$priorityFilter==='medium'?'selected':''?>>🔷 Medium</option>
                    <option value="low" <?=$priorityFilter==='low'?'selected':''?>>🟢 Low</option>
                </select>

                <button class="btn secondary sm" type="submit">Filter</button>
                <?php if ($statusFilter !== 'all' || $userFilter > 0 || $priorityFilter !== 'all' || !empty($search)): ?>
                    <a href="tasks.php" class="btn secondary sm" style="text-decoration:none;">Reset</a>
                <?php endif; ?>
                <a href="tasks.php?export=csv<?=!empty($_SERVER['QUERY_STRING']) ? '&' . http_build_query(array_diff_key($_GET, ['export' => 1])) : ''?>" class="btn secondary sm" style="text-decoration:none;display:inline-flex;align-items:center;gap:4px;background:#f0fdf4;border-color:#bbf7d0;color:#166534;font-weight:700;" title="Export filtered tasks to Excel / CSV">
                    📥 Export Excel <span class="ur">(ایکسل رپورٹ)</span>
                </a>
            </form>

            <?php if (!$taskList): ?>
                <div class="empty-state">
                    <div class="empty-icon">📋</div>
                    <b>No tasks found matching criteria</b>
                    <p><?= $canManageTasks ? 'Click "Assign New Task" button above to delegate work to your team.' : 'You have no assigned tasks in this view.' ?></p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Task Code & Title</th>
                                <th>Assigned To</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th style="min-width:140px;">Progress</th>
                                <th class="right">Budget vs Spent</th>
                                <th>Due Date</th>
                                <th class="right" style="min-width:180px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($taskList as $t): 
                                $pct = (int)$t['progress_percent'];
                                $spent = (float)$t['total_expense'];
                                $budget = (float)$t['estimated_budget'];
                                $isOverBudget = ($budget > 0 && $spent > $budget);
                                $isMine = ((int)$t['assigned_to'] === $userId);

                                $fillColor = '#2563eb';
                                if ($t['status'] === 'completed') $fillColor = '#16a34a';
                                elseif ($t['status'] === 'on_hold') $fillColor = '#94a3b8';
                                elseif ($t['priority'] === 'urgent') $fillColor = '#dc2626';
                            ?>
                                <tr>
                                    <td>
                                        <div style="display:flex;align-items:center;gap:6px;">
                                            <span style="font-family:monospace;font-size:11px;background:#e0f2fe;color:#0369a1;padding:2px 6px;border-radius:4px;font-weight:700;">
                                                <?=e($t['task_code'])?>
                                            </span>
                                            <?php if ($t['fund_name']): ?>
                                                <span class="badge badge-purple" style="font-size:10.5px;"><?=e($t['fund_name'])?></span>
                                            <?php endif; ?>
                                        </div>
                                        <b style="font-size:13.5px;color:#0f172a;margin-top:3px;display:block;"><?=e($t['title'])?></b>
                                        <?php 
                                        $hasRowLoc = !empty($t['project_location']);
                                        $hasRowGPS = !empty($t['target_lat']) && !empty($t['target_lng']);
                                        ?>
                                        <?php if ($hasRowLoc || $hasRowGPS): ?>
                                            <div style="font-size:11px;color:#0284c7;display:inline-flex;align-items:center;gap:3px;margin-top:2px;" title="Project Site Location">
                                                📍 <b><?=e($hasRowLoc ? $t['project_location'] : 'GPS Pin (' . round((float)$t['target_lat'], 4) . ', ' . round((float)$t['target_lng'], 4) . ')')?></b>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($t['description'])): ?>
                                            <div style="font-size:11.5px;color:#64748b;max-width:280px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                                <?=e($t['description'])?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <b><?=e($t['assignee_name'])?></b>
                                        <div style="font-size:11px;color:#64748b;">@<?=e($t['assignee_username'])?></div>
                                    </td>
                                    <td>
                                        <span class="priority-badge priority-<?=e($t['priority'])?>">
                                            <?=e(strtoupper($t['priority']))?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($t['status'] === 'completed'): ?>
                                            <span class="badge badge-green">✓ Completed</span>
                                        <?php elseif ($t['status'] === 'in_progress'): ?>
                                            <span class="badge badge-blue">⚡ In Progress</span>
                                        <?php elseif ($t['status'] === 'on_hold'): ?>
                                            <span class="badge badge-gray">⏸ On Hold</span>
                                        <?php elseif ($t['status'] === 'cancelled'): ?>
                                            <span class="badge badge-red">✕ Cancelled</span>
                                        <?php else: ?>
                                            <span class="badge badge-amber">⏳ Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display:flex;justify-content:space-between;font-size:11px;font-weight:700;margin-bottom:3px;color:#334155;">
                                            <span><?=$pct?>%</span>
                                            <span style="font-size:10px;color:#64748b;"><?=$t['logs_count']?> updates</span>
                                        </div>
                                        <div class="progress-bar-wrap">
                                            <div class="progress-bar-fill" style="width:<?=$pct?>%;background:<?=$fillColor?>;"></div>
                                        </div>
                                    </td>
                                    <td class="right">
                                        <div style="font-weight:700;color:<?=$isOverBudget ? '#b91c1c' : '#15803d'?>;font-size:13px;">
                                            <?=money($spent)?>
                                        </div>
                                        <?php if ($budget > 0): ?>
                                            <small style="font-size:10.5px;color:#64748b;display:block;">Budget: <?=money($budget)?></small>
                                        <?php else: ?>
                                            <small style="font-size:10px;color:#94a3b8;display:block;">No budget set</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($t['due_date']): 
                                            $isOverdue = ($t['status'] !== 'completed' && strtotime($t['due_date']) < strtotime(date('Y-m-d')));
                                        ?>
                                            <span style="font-size:12px;font-weight:600;color:<?=$isOverdue ? '#dc2626' : '#334155'?>;">
                                                <?=e(date('d M Y', strtotime($t['due_date'])))?>
                                                <?=$isOverdue ? '⚠️' : ''?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color:#94a3b8;font-size:11.5px;">— None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="right">
                                        <div class="btn-row" style="justify-content:flex-end;gap:5px;">
                                            <a href="tasks.php?view_task=<?=$t['id']?>" class="btn blue sm" style="padding:4px 8px;font-size:11.5px;" title="View timeline & reports">
                                                👁️ View (<?=$t['logs_count']?>)
                                            </a>
                                            <a href="tasks.php?view_task=<?=$t['id']?>&print=1" target="_blank" class="btn secondary sm" style="padding:4px 7px;font-size:11.5px;" title="Official Print Dossier / PDF">
                                                🖨️
                                            </a>
                                            <?php if ($isMine): ?>
                                                <button type="button" class="btn success sm" style="padding:4px 8px;font-size:11.5px;"
                                                    data-id="<?=$t['id']?>"
                                                    data-code="<?=e($t['task_code'])?>"
                                                    data-title="<?=e($t['title'])?>"
                                                    data-percent="<?=(int)$t['progress_percent']?>"
                                                    data-status="<?=e($t['status'])?>"
                                                    onclick="openProgressModal(this)"
                                                    title="Post progress update">
                                                    📝 Update
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($canManageTasks): ?>
                                                <button type="button" class="btn secondary sm" style="padding:4px 7px;font-size:11.5px;"
                                                    data-id="<?=$t['id']?>"
                                                    data-code="<?=e($t['task_code'])?>"
                                                    data-title="<?=e($t['title'])?>"
                                                    data-description="<?=e($t['description'] ?? '')?>"
                                                    data-project-location="<?=e($t['project_location'] ?? '')?>"
                                                    data-target-lat="<?=e($t['target_lat'] ?? '')?>"
                                                    data-target-lng="<?=e($t['target_lng'] ?? '')?>"
                                                    data-radius-meters="<?=(int)($t['radius_meters'] ?? 300)?>"
                                                    data-assigned-to="<?=$t['assigned_to']?>"
                                                    data-priority="<?=e($t['priority'])?>"
                                                    data-status="<?=e($t['status'])?>"
                                                    data-percent="<?=(int)$t['progress_percent']?>"
                                                    data-budget="<?=e($t['estimated_budget'])?>"
                                                    data-due-date="<?=e($t['due_date'] ?? '')?>"
                                                    onclick="openEditModal(this)"
                                                    title="Edit task parameters">
                                                    ✏️
                                                </button>
                                                <?php if ($isSuperAdmin): ?>
                                                    <a href="tasks.php?delete_id=<?=$t['id']?>&token=<?=csrf_token()?>" class="btn danger sm" style="padding:4px 7px;font-size:11.5px;" onclick="return confirm('Delete task <?=e(addslashes($t['task_code']))?> and all its logs?');" title="Delete">
                                                        🗑️
                                                    </a>
                                                <?php endif; ?>
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

        <!-- Modal 1: Create New Task (Admin) -->
        <div class="modal-overlay" id="createTaskModal">
            <div class="modal-card">
                <div class="modal-header">
                    <b style="font-size:16px;color:#0f172a;">＋ Assign New Task <span class="ur">(نیا ٹاسک تفویض کریں)</span></b>
                    <button type="button" style="background:none;border:none;font-size:22px;cursor:pointer;color:#64748b;" onclick="closeCreateTaskModal()">&times;</button>
                </div>
                <form method="post" action="tasks.php" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                    <input type="hidden" name="action" value="create_task">
                    <div class="modal-body">
                        <div class="form-group" style="margin-bottom:14px;">
                            <label>Task Title <span class="ur">(کام کا عنوان)</span> *</label>
                            <input name="title" required placeholder="e.g. راشن پیکجز تقسیم مہم یا واٹر پمپ تنصیب" style="width:100%;font-weight:600;">
                        </div>

                        <div class="form-group" style="margin-bottom:14px;">
                            <label>Task Description & Instructions <span class="ur">(مکمل ہدایات و تفصیل)</span></label>
                            <textarea name="description" rows="3" style="width:100%;padding:9px;border:1px solid var(--line);border-radius:8px;font-family:inherit;font-size:13.5px;" placeholder="Describe scope, locations, target families, beneficiaries..."></textarea>
                        </div>

                        <!-- Project Site Location & Geofence GPS -->
                        <div style="background:#f8fafc;border:1px solid #cbd5e1;border-radius:10px;padding:14px;margin-bottom:16px;">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;flex-wrap:wrap;gap:6px;">
                                <label style="font-weight:700;font-size:13px;color:#0f172a;margin:0;">
                                    📍 Project Site / Location Name <span class="ur">(پراجیکٹ یا کام کی جگہ کا پتہ / محلہ)</span>
                                </label>
                                <span style="font-size:11px;color:#64748b;">پتہ لکھیں، نقشے سے چنیں یا GPS پن کریں</span>
                            </div>

                            <div style="display:flex;gap:6px;margin-bottom:8px;">
                                <input name="project_location" id="createProjectLoc" placeholder="مثلاً: رحیم آباد محلہ، منگل چائے، صوابی" style="flex:1;font-weight:600;padding:8px 10px;font-size:13px;">
                                <button type="button" class="btn secondary sm" onclick="searchLocationByName('create')" style="font-size:11.5px;padding:6px 12px;white-space:nowrap;font-weight:600;" title="Search this address on map">
                                    🔍 Search <span class="ur">(تلاش)</span>
                                </button>
                            </div>

                            <!-- Quick Preset Location Chips -->
                            <div style="margin-bottom:10px;">
                                <div style="font-size:11px;color:#64748b;margin-bottom:4px;font-weight:600;">⚡ Quick Organization Locations (فوری منتخب کریں):</div>
                                <div style="display:flex;flex-wrap:wrap;gap:5px;">
                                    <button type="button" class="btn sm" onclick="setPresetLocation('create', 'منگل چائے، صوابی (Mangalchai, Swabi)', 34.2541, 72.5186)" style="font-size:11px;padding:2px 8px;background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;">
                                        📍 منگل چائے (Swabi)
                                    </button>
                                    <button type="button" class="btn sm" onclick="setPresetLocation('create', 'صوابی سٹی (Swabi City)', 34.1202, 72.4704)" style="font-size:11px;padding:2px 8px;background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;">
                                        📍 صوابی شہر
                                    </button>
                                    <button type="button" class="btn sm" onclick="setPresetLocation('create', 'پشاور آفس (Peshawar Office)', 34.0151, 71.5249)" style="font-size:11px;padding:2px 8px;background:#f3e8ff;color:#7e22ce;border:1px solid #e9d5ff;">
                                        🏛️ پشاور آفس
                                    </button>
                                    <button type="button" class="btn sm" onclick="setPresetLocation('create', 'لاہور آفس (Lahore Office)', 31.5204, 74.3587)" style="font-size:11px;padding:2px 8px;background:#f3e8ff;color:#7e22ce;border:1px solid #e9d5ff;">
                                        🏛️ لاہور آفس
                                    </button>
                                    <button type="button" class="btn sm" onclick="setPresetLocation('create', 'اسلام آباد آفس (Islamabad Office)', 33.7233, 73.0435)" style="font-size:11px;padding:2px 8px;background:#f3e8ff;color:#7e22ce;border:1px solid #e9d5ff;">
                                        🏛️ اسلام آباد آفس
                                    </button>
                                    <button type="button" class="btn sm" onclick="clearLocationGPS('create')" style="font-size:11px;padding:2px 8px;background:#fee2e2;color:#b91c1c;border:1px solid #fecaca;" title="Remove GPS coordinates so no geofencing is enforced">
                                        ❌ Clear GPS (پابندی ہٹائیں)
                                    </button>
                                </div>
                            </div>

                            <!-- GPS Tools Toolbar -->
                            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px;padding-top:8px;border-top:1px dashed #cbd5e1;flex-wrap:wrap;">
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <button type="button" class="btn secondary sm" style="font-size:11.5px;padding:4px 10px;font-weight:600;" onclick="detectTargetGPS('create')">
                                        📍 Pin My Current GPS
                                    </button>
                                    <button type="button" class="btn secondary sm" style="font-size:11.5px;padding:4px 10px;font-weight:600;" onclick="toggleMapPicker('create')">
                                        🗺️ <span id="createMapToggleText">Show Map (نقشہ کھولیں)</span>
                                    </button>
                                </div>
                                <span id="createGpsStatus" style="font-size:11px;color:#64748b;font-weight:600;"></span>
                            </div>

                            <!-- Interactive Leaflet Map Container -->
                            <div id="createMapWrap" style="display:none;margin-bottom:12px;border:1px solid #cbd5e1;border-radius:8px;overflow:hidden;">
                                <div style="padding:6px 10px;background:#f1f5f9;font-size:11.5px;color:#475569;display:flex;justify-content:space-between;align-items:center;">
                                    <span>👉 نقشے پر مطلوبہ جگہ کلک کریں یا پن کو گھسیٹ کر سیٹ کریں:</span>
                                    <span style="font-size:11px;color:#0284c7;font-weight:600;">OpenStreetMap Live</span>
                                </div>
                                <div id="createMap" style="height:220px;width:100%;background:#e2e8f0;"></div>
                            </div>

                            <!-- Latitude, Longitude & Geofence Radius Inputs -->
                            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
                                <div>
                                    <label style="font-size:11px;color:#64748b;font-weight:600;display:block;margin-bottom:3px;">Target Latitude:</label>
                                    <input type="number" step="any" name="target_lat" id="createTargetLat" placeholder="e.g. 34.254100" oninput="onCoordInputChange('create')" style="width:100%;font-size:12px;padding:6px;font-weight:600;">
                                </div>
                                <div>
                                    <label style="font-size:11px;color:#64748b;font-weight:600;display:block;margin-bottom:3px;">Target Longitude:</label>
                                    <input type="number" step="any" name="target_lng" id="createTargetLng" placeholder="e.g. 72.518600" oninput="onCoordInputChange('create')" style="width:100%;font-size:12px;padding:6px;font-weight:600;">
                                </div>
                                <div>
                                    <label style="font-size:11px;color:#64748b;font-weight:600;display:block;margin-bottom:3px;">Allowed Radius (m):</label>
                                    <input type="number" name="radius_meters" id="createRadiusMeters" value="300" min="50" step="50" oninput="onCoordInputChange('create')" style="width:100%;font-size:12px;padding:6px;font-weight:600;" title="Geofence radius in meters">
                                </div>
                            </div>
                            <div id="createExternalMapLink" style="margin-top:6px;font-size:11px;"></div>
                        </div>

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                            <div class="form-group">
                                <label>Assign To User / Staff <span class="ur">(کس ملازم کے ذمے ہے)</span> *</label>
                                <select name="assigned_to" required style="width:100%;">
                                    <option value="">-- Select Staff Member --</option>
                                    <?php foreach ($users as $u): ?>
                                        <option value="<?=$u['id']?>"><?=e($u['full_name'])?> (@<?=e($u['username'])?>)<?php if (!empty($u['region_name'])): ?> [🏛️ <?=e($u['region_name'])?>]<?php endif; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Priority Level <span class="ur">(ترجیح)</span></label>
                                <select name="priority" style="width:100%;">
                                    <option value="medium" selected>🔷 Medium (درمیانی)</option>
                                    <option value="high">🔺 High (اہم)</option>
                                    <option value="urgent">🚨 Urgent (انتہائی فوری)</option>
                                    <option value="low">🟢 Low (عام)</option>
                                </select>
                            </div>
                        </div>

                        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px;">
                            <div class="form-group">
                                <label>Estimated Budget PKR <span class="ur">(تخمینی بجٹ)</span></label>
                                <input type="number" step="0.01" min="0" name="estimated_budget" placeholder="0.00" style="width:100%;">
                            </div>
                            <div class="form-group">
                                <label>Start Date <span class="ur">(آغاز تاریخ)</span></label>
                                <input type="date" name="start_date" value="<?=date('Y-m-d')?>" style="width:100%;">
                            </div>
                            <div class="form-group">
                                <label>Due Date <span class="ur">(آخری تاریخ)</span></label>
                                <input type="date" name="due_date" style="width:100%;">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Linked Fund / Project <span class="ur">(متعلقہ فنڈ - اختیاری)</span></label>
                            <select name="fund_id" style="width:100%;">
                                <option value="0">-- General / Not Fund Specific --</option>
                                <?php foreach ($funds as $f): ?>
                                    <option value="<?=$f['id']?>"><?=e($f['name'])?> (<?=e($f['fund_code'])?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group" style="background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;padding:12px 14px;margin-top:14px;">
                            <label style="font-weight:700;display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                                <span>📷 Initial Site Photo / Before Work Photo <span class="ur">(کام شروع کرنے سے پہلے کی تصویر)</span></span>
                                <span style="font-size:11px;color:#64748b;font-weight:normal;">Optional</span>
                            </label>
                            <input type="file" name="before_photo" accept="image/jpeg,image/png,image/webp" style="width:100%;font-size:13px;">
                            <small class="muted" style="display:block;margin-top:4px;">یہ تصویر ٹاسک کے "Before & After" آڈٹ موازنے میں استعمال ہوگی۔</small>
                        </div>
                    </div>
                    <div style="padding:14px 22px;border-top:1px solid #e2e8f0;background:#f8fafc;display:flex;justify-content:flex-end;gap:10px;">
                        <button type="button" class="btn secondary" onclick="closeCreateTaskModal()">Cancel</button>
                        <button type="submit" class="btn blue">🚀 Assign Task <span class="ur">(ٹاسک تفویض کریں)</span></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal 2: Post Daily Progress Update (User & Admin) -->
        <div class="modal-overlay" id="progressModal">
            <div class="modal-card">
                <div class="modal-header">
                    <div>
                        <b id="progressModalTitle" style="font-size:15px;color:#0f172a;">Post Daily Progress Update</b>
                        <span id="progressModalCode" style="font-family:monospace;font-size:11px;background:#e0f2fe;color:#0369a1;padding:2px 6px;border-radius:4px;margin-left:6px;font-weight:700;"></span>
                    </div>
                    <button type="button" style="background:none;border:none;font-size:22px;cursor:pointer;color:#64748b;" onclick="closeProgressModal()">&times;</button>
                </div>
                <form method="post" action="tasks.php" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                    <input type="hidden" name="action" value="post_progress">
                    <input type="hidden" name="task_id" id="progTaskId" value="">
                    <div class="modal-body">
                        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 16px;margin-bottom:16px;">
                            <div style="font-size:11.5px;color:#1e40af;font-weight:700;">CURRENT PROGRESS & STATUS:</div>
                            <div style="display:flex;align-items:center;gap:14px;margin-top:6px;">
                                <div style="flex:1;">
                                    <label style="font-size:12px;font-weight:600;display:flex;justify-content:space-between;margin-bottom:4px;">
                                        <span>Progress Percentage:</span>
                                        <b id="progSliderVal" style="color:#2563eb;font-size:14px;">50%</b>
                                    </label>
                                    <input type="range" name="progress_percent" id="progSlider" min="0" max="100" step="5" value="50" style="width:100%;accent-color:#2563eb;" oninput="document.getElementById('progSliderVal').textContent = this.value + '%'; var c = document.getElementById('chkAfterPhoto'); var s = document.getElementById('progStatus'); if (parseInt(this.value, 10) === 100) { if (c) c.checked = true; if (s) s.value = 'completed'; } else { if (c) c.checked = false; if (s && s.value === 'completed') s.value = 'in_progress'; }">
                                </div>
                                <div style="min-width:140px;">
                                    <label style="font-size:12px;font-weight:600;margin-bottom:4px;display:block;">Status:</label>
                                    <select name="status" id="progStatus" style="width:100%;font-size:13px;padding:6px 10px;" onchange="if (this.value === 'completed') { document.getElementById('progSlider').value = 100; document.getElementById('progSliderVal').textContent = '100%'; var c = document.getElementById('chkAfterPhoto'); if (c) c.checked = true; }">
                                        <option value="in_progress">⚡ In Progress</option>
                                        <option value="completed">✓ Completed (100%)</option>
                                        <option value="on_hold">⏸ On Hold</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom:14px;">
                            <label>Progress Report / What was done today? <span class="ur">(آج کیا پیشرفت ہوئی؟ تفصیلی رپورٹ)</span> *</label>
                            <textarea name="report_text" rows="4" required style="width:100%;padding:10px;border:1px solid var(--line);border-radius:8px;font-family:inherit;font-size:13.5px;" placeholder="e.g. آج 50 راشن بیگز تیار کیے گئے اور محلہ رحیم آباد میں مستحقین کو تقسیم کیے۔ مزید 20 بیگز کل تقسیم ہوں گے۔"></textarea>
                        </div>

                        <div class="form-group" style="margin-bottom:14px;">
                            <label>📷 Attach Field Photo / Receipt Picture <span class="ur">(موقع کی تصویر یا بل اپلوڈ کریں)</span></label>
                            <input type="file" name="photo_file" accept="image/jpeg,image/png,image/webp,application/pdf" capture="environment" style="width:100%;">
                            <label style="display:flex;align-items:center;gap:6px;font-size:12px;margin-top:6px;cursor:pointer;color:#166534;">
                                <input type="checkbox" name="is_after_photo" id="chkAfterPhoto" value="1">
                                <span><b>📸 Set as Work Completed Photo (حتمی تصویر برائے After Photo)</b></span>
                            </label>
                            <small class="muted" style="display:block;margin-top:2px;">Take picture directly from mobile camera or select file (JPG, PNG, PDF).</small>
                        </div>

                        <!-- Hidden GPS Coordinates (Captured silently in background for admin verification) -->
                        <input type="hidden" name="latitude" id="progLat" value="">
                        <input type="hidden" name="longitude" id="progLng" value="">
                        <input type="hidden" name="location_accuracy" id="progAccuracy" value="">
                        <input type="hidden" name="location_address" id="progLocAddress" value="">
                    </div>
                    <div style="padding:14px 22px;border-top:1px solid #e2e8f0;background:#f8fafc;display:flex;justify-content:flex-end;gap:10px;">
                        <button type="button" class="btn secondary" onclick="closeProgressModal()">Cancel</button>
                        <button type="submit" class="btn success">💾 Submit Report <span class="ur">(پیشرفت محفوظ کریں)</span></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal 3: Edit Task (Admin) -->
        <div class="modal-overlay" id="editTaskModal">
            <div class="modal-card">
                <div class="modal-header">
                    <b style="font-size:15px;color:#0f172a;">Edit Task Parameters <span class="ur">(ٹاسک میں ترمیم کریں)</span></b>
                    <button type="button" style="background:none;border:none;font-size:22px;cursor:pointer;color:#64748b;" onclick="closeEditModal()">&times;</button>
                </div>
                <form method="post" action="tasks.php" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                    <input type="hidden" name="action" value="update_task">
                    <input type="hidden" name="task_id" id="editTaskId" value="">
                    <div class="modal-body">
                        <div class="form-group" style="margin-bottom:14px;">
                            <label>Task Title *</label>
                            <input name="title" id="editTaskTitle" required style="width:100%;font-weight:600;">
                        </div>
                        <div class="form-group" style="margin-bottom:14px;">
                            <label>Description & Scope</label>
                            <textarea name="description" id="editTaskDesc" rows="3" style="width:100%;padding:9px;border:1px solid var(--line);border-radius:8px;font-family:inherit;font-size:13.5px;"></textarea>
                        </div>
                        <!-- Project Location & Geofencing GPS Coordinates -->
                        <div style="background:#f8fafc;border:1px solid #cbd5e1;border-radius:10px;padding:14px;margin-bottom:16px;">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;flex-wrap:wrap;gap:6px;">
                                <label style="font-weight:700;font-size:13px;color:#0f172a;margin:0;">
                                    📍 Project / Site Location <span class="ur">(پروجیکٹ کا مقام / ایڈریس)</span>
                                </label>
                                <span style="font-size:11px;color:#64748b;">پتہ لکھیں، نقشے سے چنیں یا GPS پن کریں</span>
                            </div>

                            <div style="display:flex;gap:6px;margin-bottom:8px;">
                                <input type="text" name="project_location" id="editProjectLoc" placeholder="e.g. رحیم آباد محلہ، منگل چائے، صوابی" style="flex:1;font-weight:600;padding:8px 10px;font-size:13px;">
                                <button type="button" class="btn secondary sm" onclick="searchLocationByName('edit')" style="font-size:11.5px;padding:6px 12px;white-space:nowrap;font-weight:600;" title="Search this address on map">
                                    🔍 Search <span class="ur">(تلاش)</span>
                                </button>
                            </div>

                            <!-- Quick Preset Location Chips -->
                            <div style="margin-bottom:10px;">
                                <div style="font-size:11px;color:#64748b;margin-bottom:4px;font-weight:600;">⚡ Quick Organization Locations (فوری منتخب کریں):</div>
                                <div style="display:flex;flex-wrap:wrap;gap:5px;">
                                    <button type="button" class="btn sm" onclick="setPresetLocation('edit', 'منگل چائے، صوابی (Mangalchai, Swabi)', 34.2541, 72.5186)" style="font-size:11px;padding:2px 8px;background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;">
                                        📍 منگل چائے (Swabi)
                                    </button>
                                    <button type="button" class="btn sm" onclick="setPresetLocation('edit', 'صوابی سٹی (Swabi City)', 34.1202, 72.4704)" style="font-size:11px;padding:2px 8px;background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;">
                                        📍 صوابی شہر
                                    </button>
                                    <button type="button" class="btn sm" onclick="setPresetLocation('edit', 'پشاور آفس (Peshawar Office)', 34.0151, 71.5249)" style="font-size:11px;padding:2px 8px;background:#f3e8ff;color:#7e22ce;border:1px solid #e9d5ff;">
                                        🏛️ پشاور آفس
                                    </button>
                                    <button type="button" class="btn sm" onclick="setPresetLocation('edit', 'لاہور آفس (Lahore Office)', 31.5204, 74.3587)" style="font-size:11px;padding:2px 8px;background:#f3e8ff;color:#7e22ce;border:1px solid #e9d5ff;">
                                        🏛️ لاہور آفس
                                    </button>
                                    <button type="button" class="btn sm" onclick="setPresetLocation('edit', 'اسلام آباد آفس (Islamabad Office)', 33.7233, 73.0435)" style="font-size:11px;padding:2px 8px;background:#f3e8ff;color:#7e22ce;border:1px solid #e9d5ff;">
                                        🏛️ اسلام آباد آفس
                                    </button>
                                    <button type="button" class="btn sm" onclick="clearLocationGPS('edit')" style="font-size:11px;padding:2px 8px;background:#fee2e2;color:#b91c1c;border:1px solid #fecaca;" title="Remove GPS coordinates so no geofencing is enforced">
                                        ❌ Clear GPS (پابندی ہٹائیں)
                                    </button>
                                </div>
                            </div>

                            <!-- GPS Tools Toolbar -->
                            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px;padding-top:8px;border-top:1px dashed #cbd5e1;flex-wrap:wrap;">
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <button type="button" class="btn secondary sm" style="font-size:11.5px;padding:4px 10px;font-weight:600;" onclick="detectTargetGPS('edit')">
                                        📍 Pin My Current GPS
                                    </button>
                                    <button type="button" class="btn secondary sm" style="font-size:11.5px;padding:4px 10px;font-weight:600;" onclick="toggleMapPicker('edit')">
                                        🗺️ <span id="editMapToggleText">Show Map (نقشہ کھولیں)</span>
                                    </button>
                                </div>
                                <span id="editGpsStatus" style="font-size:11px;color:#64748b;font-weight:600;"></span>
                            </div>

                            <!-- Interactive Leaflet Map Container -->
                            <div id="editMapWrap" style="display:none;margin-bottom:12px;border:1px solid #cbd5e1;border-radius:8px;overflow:hidden;">
                                <div style="padding:6px 10px;background:#f1f5f9;font-size:11.5px;color:#475569;display:flex;justify-content:space-between;align-items:center;">
                                    <span>👉 نقشے پر مطلوبہ جگہ کلک کریں یا پن کو گھسیٹ کر سیٹ کریں:</span>
                                    <span style="font-size:11px;color:#0284c7;font-weight:600;">OpenStreetMap Live</span>
                                </div>
                                <div id="editMap" style="height:220px;width:100%;background:#e2e8f0;"></div>
                            </div>

                            <!-- Latitude, Longitude & Geofence Radius Inputs -->
                            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
                                <div>
                                    <label style="font-size:11px;color:#64748b;font-weight:600;display:block;margin-bottom:3px;">Target Latitude:</label>
                                    <input type="number" step="any" name="target_lat" id="editTargetLat" placeholder="e.g. 34.254100" oninput="onCoordInputChange('edit')" style="width:100%;font-size:12px;padding:6px;font-weight:600;">
                                </div>
                                <div>
                                    <label style="font-size:11px;color:#64748b;font-weight:600;display:block;margin-bottom:3px;">Target Longitude:</label>
                                    <input type="number" step="any" name="target_lng" id="editTargetLng" placeholder="e.g. 72.518600" oninput="onCoordInputChange('edit')" style="width:100%;font-size:12px;padding:6px;font-weight:600;">
                                </div>
                                <div>
                                    <label style="font-size:11px;color:#64748b;font-weight:600;display:block;margin-bottom:3px;">Allowed Radius (m):</label>
                                    <input type="number" name="radius_meters" id="editRadiusMeters" value="300" min="50" step="50" oninput="onCoordInputChange('edit')" style="width:100%;font-size:12px;padding:6px;font-weight:600;" title="Geofence radius in meters">
                                </div>
                            </div>
                            <div id="editExternalMapLink" style="margin-top:6px;font-size:11px;"></div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                            <div class="form-group">
                                <label>Assigned To *</label>
                                <select name="assigned_to" id="editTaskAssignee" required style="width:100%;">
                                    <?php foreach ($users as $u): ?>
                                        <option value="<?=$u['id']?>"><?=e($u['full_name'])?> (@<?=e($u['username'])?>)<?php if (!empty($u['region_name'])): ?> [🏛️ <?=e($u['region_name'])?>]<?php endif; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Priority</label>
                                <select name="priority" id="editTaskPriority" style="width:100%;">
                                    <option value="urgent">🚨 Urgent</option>
                                    <option value="high">🔺 High</option>
                                    <option value="medium">🔷 Medium</option>
                                    <option value="low">🟢 Low</option>
                                </select>
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status" id="editTaskStatus" style="width:100%;">
                                    <option value="pending">⏳ Pending</option>
                                    <option value="in_progress">⚡ In Progress</option>
                                    <option value="completed">✓ Completed</option>
                                    <option value="on_hold">⏸ On Hold</option>
                                    <option value="cancelled">✕ Cancelled</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Progress Percentage (0-100%)</label>
                                <input type="number" min="0" max="100" name="progress_percent" id="editTaskProgress" style="width:100%;">
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                            <div class="form-group">
                                <label>Estimated Budget PKR</label>
                                <input type="number" step="0.01" min="0" name="estimated_budget" id="editTaskBudget" style="width:100%;">
                            </div>
                            <div class="form-group">
                                <label>Due Date</label>
                                <input type="date" name="due_date" id="editTaskDue" style="width:100%;">
                            </div>
                        </div>
                    </div>
                    <div style="padding:14px 22px;border-top:1px solid #e2e8f0;background:#f8fafc;display:flex;justify-content:flex-end;gap:10px;">
                        <button type="button" class="btn secondary" onclick="closeEditModal()">Cancel</button>
                        <button type="submit" class="btn blue">💾 Save Changes</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal 4: Supervisor Review / Approval / Revision -->
        <div class="modal-overlay" id="supervisorReviewModal" style="z-index:10060;">
            <div class="modal-card" style="max-width:540px;">
                <div class="modal-header">
                    <b style="font-size:15px;color:#0f172a;" id="supervisorModalTitle">Supervisor Review & Action</b>
                    <button type="button" style="background:none;border:none;font-size:22px;cursor:pointer;color:#64748b;" onclick="closeSupervisorReviewModal()">&times;</button>
                </div>
                <form method="post" action="tasks.php" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                    <input type="hidden" name="action" value="admin_review_task">
                    <input type="hidden" name="task_id" id="reviewTaskId" value="<?=$viewTask ? $viewTask['id'] : ''?>">
                    <input type="hidden" name="review_action" id="reviewActionInput" value="approve">
                    <div class="modal-body">
                        <!-- Action Selector Tabs/Radios -->
                        <div style="margin-bottom:14px;">
                            <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:6px;">Select Action / مطلوبہ ایکشن منتخب کریں:</label>
                            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;">
                                <label id="lblActionApprove" style="display:flex;align-items:center;gap:6px;background:#f0fdf4;border:1.5px solid #bbf7d0;padding:8px 10px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:700;color:#166534;">
                                    <input type="radio" name="review_action_radio" id="radioApprove" value="approve" onchange="switchSupervisorAction('approve')">
                                    <span>✅ Approve</span>
                                </label>
                                <label id="lblActionReject" style="display:flex;align-items:center;gap:6px;background:#fef2f2;border:1.5px solid #fecaca;padding:8px 10px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:700;color:#991b1b;">
                                    <input type="radio" name="review_action_radio" id="radioReject" value="reject" onchange="switchSupervisorAction('reject')">
                                    <span>⚠️ Reject</span>
                                </label>
                                <label id="lblActionNote" style="display:flex;align-items:center;gap:6px;background:#f5f3ff;border:1.5px solid #ddd6fe;padding:8px 10px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:700;color:#5b21b6;">
                                    <input type="radio" name="review_action_radio" id="radioNote" value="note" onchange="switchSupervisorAction('note')">
                                    <span>💬 Note</span>
                                </label>
                            </div>
                        </div>

                        <div id="reviewPromptText" style="margin-bottom:14px;font-size:13px;color:#334155;line-height:1.5;background:#f8fafc;padding:12px;border-radius:8px;border:1px solid #e2e8f0;"></div>
                        <div class="form-group">
                            <label id="reviewRemarkLabel" style="font-weight:600;font-size:12.5px;">Supervisor Remarks / Instructions <span class="ur">(ہدایات یا تبصرہ)</span>:</label>
                            <textarea name="admin_remark" id="reviewRemarkText" rows="3" style="width:100%;padding:9px;border:1px solid var(--line);border-radius:8px;font-family:inherit;font-size:13.5px;" placeholder="یہاں اپنا نوٹ یا ہدایات درج کریں..."></textarea>
                        </div>
                    </div>
                    <div style="padding:14px 22px;border-top:1px solid #e2e8f0;background:#f8fafc;display:flex;justify-content:flex-end;gap:10px;">
                        <button type="button" class="btn secondary" onclick="closeSupervisorReviewModal()">Cancel</button>
                        <button type="submit" class="btn success" id="supervisorSubmitBtn">Confirm Action</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Section: View Task Detail, Timeline & Expenses Modal -->
        <?php if ($viewTask): ?>
            <div class="modal-overlay open" id="viewDetailModal">
                <div class="modal-card" style="max-width:840px;">
                    <div class="modal-header">
                        <div>
                            <span style="font-family:monospace;font-size:12px;background:#e0f2fe;color:#0369a1;padding:3px 7px;border-radius:4px;font-weight:700;">
                                <?=e($viewTask['task_code'])?>
                            </span>
                            <b style="font-size:16px;color:#0f172a;margin-left:8px;"><?=e($viewTask['title'])?></b>
                        </div>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <a href="tasks.php?view_task=<?=$viewTask['id']?>&print=1" target="_blank" class="btn secondary sm" style="display:inline-flex;align-items:center;gap:5px;padding:5px 11px;font-size:12px;font-weight:700;text-decoration:none;background:#f8fafc;border:1px solid #cbd5e1;color:#1e293b;" title="Official Print Dossier / PDF">
                                🖨️ Print Dossier / PDF
                            </a>
                            <a href="tasks.php" style="background:none;border:none;font-size:22px;cursor:pointer;color:#64748b;text-decoration:none;">&times;</a>
                        </div>
                    </div>
                    <div class="modal-body">
                        <!-- Task Summary Strip -->
                        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(135px, 1fr));gap:10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px 16px;margin-bottom:20px;">
                            <div>
                                <span style="font-size:11px;color:#64748b;display:block;">Assignee:</span>
                                <b style="font-size:13px;color:#0f172a;"><?=e($viewTask['assignee_name'])?></b>
                                <div style="margin-top:4px;display:flex;gap:4px;flex-wrap:wrap;">
                                    <?php if ($canManageTasks): ?>
                                        <button type="button" class="btn success sm" style="padding:2px 7px;font-size:10.5px;background:#16a34a;border-color:#16a34a;color:#fff;display:inline-flex;align-items:center;gap:3px;" onclick="sendTaskWhatsApp('worker')" title="Send WhatsApp alert to worker">
                                            📱 WhatsApp
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn success sm" style="padding:2px 7px;font-size:10.5px;background:#16a34a;border-color:#16a34a;color:#fff;display:inline-flex;align-items:center;gap:3px;" onclick="sendTaskWhatsApp('admin')" title="Send WhatsApp update to supervisor">
                                            📱 WhatsApp Admin
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div>
                                <span style="font-size:11px;color:#64748b;display:block;">Status & Priority:</span>
                                <b><?=ucfirst($viewTask['status'])?></b> <span class="priority-badge priority-<?=e($viewTask['priority'])?>"><?=strtoupper($viewTask['priority'])?></span>
                            </div>
                            <div>
                                <span style="font-size:11px;color:#64748b;display:block;">Budget vs Spent:</span>
                                <b style="color:#b91c1c;"><?=money($viewTask['total_expense'])?></b> <small style="color:#64748b;">/ <?=money($viewTask['estimated_budget'])?></small>
                            </div>
                            <div>
                                <span style="font-size:11px;color:#64748b;display:block;">Due Date:</span>
                                <b><?= $viewTask['due_date'] ? date('d M Y', strtotime($viewTask['due_date'])) : '— None' ?></b>
                            </div>
                            <div>
                                <span style="font-size:11px;color:#64748b;display:block;">Progress:</span>
                                <b style="color:#2563eb;"><?=(int)$viewTask['progress_percent']?>%</b>
                            </div>
                            <div>
                                <span style="font-size:11px;color:#64748b;display:block;">Site Location:</span>
                                <?php 
                                $hasLocName = !empty($viewTask['project_location']);
                                $hasGPS = !empty($viewTask['target_lat']) && !empty($viewTask['target_lng']);
                                ?>
                                <?php if ($hasLocName || $hasGPS): ?>
                                    <b style="font-size:12px;color:#0f172a;display:block;">
                                        📍 <?=e($hasLocName ? $viewTask['project_location'] : 'GPS Pin (' . round((float)$viewTask['target_lat'], 4) . ', ' . round((float)$viewTask['target_lng'], 4) . ')')?>
                                    </b>
                                    <?php if ($hasGPS): ?>
                                        <a href="https://www.google.com/maps?q=<?=e($viewTask['target_lat'])?>,<?=e($viewTask['target_lng'])?>" target="_blank" style="font-size:11px;color:#2563eb;text-decoration:none;font-weight:600;display:inline-block;margin-top:2px;">
                                            🗺 View Target Map &rarr;
                                        </a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:#94a3b8;font-size:12px;">— General / Office</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!empty($viewTask['target_lat']) && !empty($viewTask['target_lng'])): ?>
                            <div style="margin-bottom:18px;background:#ffffff;border:1px solid #cbd5e1;border-radius:10px;overflow:hidden;">
                                <div style="background:#f8fafc;padding:8px 14px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;font-size:12px;">
                                    <b style="color:#0f172a;">🗺️ Target Site & Geofence Verification Map <span class="ur">(مطلوبہ مقام اور رینج)</span></b>
                                    <span style="font-size:11px;color:#0284c7;font-weight:600;">Radius: <?=(int)($viewTask['radius_meters'] ?? 300)?> meters</span>
                                </div>
                                <div id="viewTaskMap" style="height:210px;width:100%;background:#e2e8f0;"></div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($viewTask['description'])): ?>
                            <div style="background:#fff;border:1px solid #f1f5f9;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:13.5px;color:#334155;line-height:1.6;">
                                <strong>Instructions / تفصیل:</strong><br>
                                <?=nl2br(e($viewTask['description']))?>
                            </div>
                        <?php endif; ?>

                        <!-- Before & After Photo Showcase Comparison -->
                        <div style="margin-bottom:24px;background:#ffffff;border:1.5px solid #e2e8f0;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                            <div style="background:#f8fafc;padding:10px 16px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                                <b style="font-size:13px;color:#0f172a;display:flex;align-items:center;gap:6px;">
                                    <span>📸 Before & After Photo Showcase</span>
                                    <span class="ur">(کام شروع کرنے سے پہلے اور مکمل ہونے کے بعد کا تصویری ثبوت)</span>
                                </b>
                                <span style="font-size:11px;color:#64748b;">Visual Field Proof</span>
                            </div>
                            <div style="padding:14px;display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                                <!-- 1. Before Photo -->
                                <div style="border:1px solid #e2e8f0;border-radius:8px;padding:10px;background:#fafafa;text-align:center;">
                                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                                        <span style="font-size:12px;font-weight:700;color:#475569;">📷 1. Before Work <span class="ur">(کام سے پہلے)</span></span>
                                        <?php if (!empty($viewTask['before_photo']) && is_file(__DIR__ . '/uploads/tasks/' . $viewTask['before_photo'])): ?>
                                            <span style="font-size:10px;background:#e2e8f0;color:#334155;padding:1px 6px;border-radius:4px;font-weight:700;">Uploaded</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($viewTask['before_photo']) && is_file(__DIR__ . '/uploads/tasks/' . $viewTask['before_photo'])): ?>
                                        <a href="uploads/tasks/<?=e($viewTask['before_photo'])?>" target="_blank" title="Click to view full high-resolution image">
                                            <img src="uploads/tasks/<?=e($viewTask['before_photo'])?>" alt="Before Work" style="width:100%;max-height:190px;object-fit:cover;border-radius:6px;border:1px solid #cbd5e1;">
                                        </a>
                                        <div style="margin-top:6px;font-size:11px;color:#64748b;">🔍 Click to enlarge</div>
                                    <?php else: ?>
                                        <div style="height:140px;display:flex;flex-direction:column;align-items:center;justify-content:center;background:#f1f5f9;border-radius:6px;border:1px dashed #cbd5e1;color:#94a3b8;font-size:12px;padding:10px;">
                                            <span style="font-size:24px;margin-bottom:4px;">📷</span>
                                            <span>No initial photo recorded</span>
                                            <span class="ur" style="font-size:10.5px;color:#94a3b8;margin-top:2px;">کام شروع کرنے سے پہلے کی تصویر</span>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Quick Upload Form for Before Photo -->
                                    <form method="post" action="tasks.php" enctype="multipart/form-data" style="margin-top:8px;">
                                        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                                        <input type="hidden" name="action" value="update_showcase_photos">
                                        <input type="hidden" name="task_id" value="<?=$viewTask['id']?>">
                                        <input type="hidden" name="photo_type" value="before">
                                        <label style="font-size:10.5px;color:#2563eb;cursor:pointer;display:inline-flex;align-items:center;gap:4px;text-decoration:underline;">
                                            <span><?=!empty($viewTask['before_photo']) ? '🔄 Replace Before Photo' : '＋ Upload Before Photo'?></span>
                                            <input type="file" name="showcase_photo" accept="image/*" style="display:none;" onchange="this.form.submit()">
                                        </label>
                                    </form>
                                </div>

                                <!-- 2. After Photo -->
                                <div style="border:1px solid #bbf7d0;border-radius:8px;padding:10px;background:#f0fdf4;text-align:center;">
                                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                                        <span style="font-size:12px;font-weight:700;color:#166534;">📸 2. After Work <span class="ur">(مکمل ہونے کے بعد)</span></span>
                                        <?php if (!empty($viewTask['after_photo']) && is_file(__DIR__ . '/uploads/tasks/' . $viewTask['after_photo'])): ?>
                                            <span style="font-size:10px;background:#dcfce7;color:#15803d;padding:1px 6px;border-radius:4px;font-weight:700;">Verified 100%</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($viewTask['after_photo']) && is_file(__DIR__ . '/uploads/tasks/' . $viewTask['after_photo'])): ?>
                                        <a href="uploads/tasks/<?=e($viewTask['after_photo'])?>" target="_blank" title="Click to view full high-resolution image">
                                            <img src="uploads/tasks/<?=e($viewTask['after_photo'])?>" alt="After Work" style="width:100%;max-height:190px;object-fit:cover;border-radius:6px;border:1px solid #86efac;">
                                        </a>
                                        <div style="margin-top:6px;font-size:11px;color:#166534;">🔍 Click to enlarge</div>
                                    <?php else: ?>
                                        <div style="height:140px;display:flex;flex-direction:column;align-items:center;justify-content:center;background:#ffffff;border-radius:6px;border:1px dashed #86efac;color:#15803d;font-size:12px;padding:10px;">
                                            <span style="font-size:24px;margin-bottom:4px;">📸</span>
                                            <span>Awaiting 100% completion photo</span>
                                            <span class="ur" style="font-size:10.5px;color:#166534;margin-top:2px;">کام مکمل ہونے کی حتمی تصویر</span>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Quick Upload Form for After Photo -->
                                    <form method="post" action="tasks.php" enctype="multipart/form-data" style="margin-top:8px;">
                                        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                                        <input type="hidden" name="action" value="update_showcase_photos">
                                        <input type="hidden" name="task_id" value="<?=$viewTask['id']?>">
                                        <input type="hidden" name="photo_type" value="after">
                                        <label style="font-size:10.5px;color:#16a34a;cursor:pointer;display:inline-flex;align-items:center;gap:4px;text-decoration:underline;">
                                            <span><?=!empty($viewTask['after_photo']) ? '🔄 Replace After Photo' : '＋ Upload After Photo'?></span>
                                            <input type="file" name="showcase_photo" accept="image/*" style="display:none;" onchange="this.form.submit()">
                                        </label>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Linked Expense Vouchers -->
                        <div style="margin-bottom:24px;">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                                <b style="font-size:13.5px;color:#0f172a;">💰 Linked Expense Vouchers (اس کام پر ہونے والے اخراجات)</b>
                                <span style="font-size:12px;font-weight:700;color:#b91c1c;">Total: <?=money($viewTask['total_expense'])?></span>
                            </div>
                            <?php if (!$viewTaskVouchers): ?>
                                <div style="font-size:12px;color:#94a3b8;background:#f8fafc;padding:10px 14px;border-radius:6px;border:1px dashed #cbd5e1;">
                                    No financial vouchers linked to this task yet. When staff records an expense in <b>Expenses</b>, they can link this task.
                                </div>
                            <?php else: ?>
                                <table style="font-size:12px;margin:0;">
                                    <thead><tr><th>Voucher #</th><th>Date</th><th>Payee</th><th>Description</th><th class="right">Amount</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($viewTaskVouchers as $v): ?>
                                            <tr>
                                                <td><b><?=e($v['voucher_no'])?></b></td>
                                                <td><?=e($v['voucher_date'])?></td>
                                                <td><?=e($v['payee_name'])?></td>
                                                <td><?=e($v['description'])?></td>
                                                <td class="right font-bold" style="color:#b91c1c;"><?=money($v['total_amount'])?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>

                        <!-- Progress Updates Timeline -->
                        <?php 
                            $isAssignee = ((int)$viewTask['assigned_to'] === $userId);
                        ?>
                        <div>
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
                                <div>
                                    <b style="font-size:13.5px;color:#0f172a;">🕒 Activity Timeline & Progress Logs <span class="ur">(پیشرفت رپورٹ و معائنہ لاگز)</span></b>
                                    <span style="font-size:11.5px;color:#64748b;display:block;">
                                        Worker: <b><?=e($viewTask['assignee_name'])?></b> | Assigned by: <b><?=e($viewTask['creator_name'])?></b>
                                    </span>
                                </div>
                                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                    <?php if ($isAssignee): ?>
                                        <button type="button" class="btn success sm"
                                            data-id="<?=$viewTask['id']?>"
                                            data-code="<?=e($viewTask['task_code'])?>"
                                            data-title="<?=e($viewTask['title'])?>"
                                            data-percent="<?=(int)$viewTask['progress_percent']?>"
                                            data-status="<?=e($viewTask['status'])?>"
                                            onclick="openProgressModal(this)">
                                            ＋ Add Progress Report <span class="ur">(پیشرفت رپورٹ درج کریں)</span>
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($canManageTasks): ?>
                                        <button type="button" class="btn success sm" style="background:#15803d;border-color:#15803d;color:#fff;" onclick="openSupervisorReviewModal('approve')" title="Approve & Verify Task">
                                            ✅ Approve <span class="ur">(منظور کریں)</span>
                                        </button>
                                        <button type="button" class="btn danger sm" style="background:#b91c1c;border-color:#b91c1c;color:#fff;" onclick="openSupervisorReviewModal('reject')" title="Reject / Request Revision from Worker">
                                            ⚠️ Reject <span class="ur">(مسترد / واپس بھیجیں)</span>
                                        </button>
                                        <button type="button" class="btn secondary sm" onclick="openSupervisorReviewModal('note')" title="Add Supervisor Note">
                                            💬 Note <span class="ur">(نگرانی نوٹ)</span>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (!$viewTaskLogs): ?>
                                <div style="font-size:12px;color:#94a3b8;background:#f8fafc;padding:14px;border-radius:6px;border:1px dashed #cbd5e1;text-align:center;">
                                    No progress reports posted yet.
                                </div>
                            <?php else: ?>
                                <div style="padding-top:8px;">
                                    <?php foreach ($viewTaskLogs as $log): 
                                        $text = $log['report_text'];
                                        $isCreation = (stripos($text, 'Task created') !== false || ($log['progress_percent'] == 0 && (int)$log['user_id'] === (int)$viewTask['created_by']));
                                        $isApproved = (stripos($text, 'Approved by') !== false);
                                        $isRejected = (stripos($text, 'Revision Requested') !== false);
                                        $isSupervisorNote = (stripos($text, 'Supervisor Note') !== false);
                                    ?>
                                        <?php if ($isCreation): ?>
                                            <!-- Creation / Assignment Milestone -->
                                            <div class="timeline-item">
                                                <div class="timeline-dot" style="background:#64748b;border-color:#cbd5e1;"></div>
                                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;flex-wrap:wrap;gap:6px;">
                                                    <div>
                                                        <b style="font-size:13px;color:#334155;">📌 <?=e($log['user_name'])?></b>
                                                        <span style="font-size:11px;color:#64748b;margin-left:4px;">(Super Admin / Task Creator)</span>
                                                        <span style="font-size:11px;color:#94a3b8;margin-left:6px;"><?=e(date('d M Y, h:i A', strtotime($log['created_at'])))?></span>
                                                    </div>
                                                    <span style="font-size:11px;background:#f1f5f9;color:#475569;padding:2px 8px;border-radius:4px;font-weight:700;border:1px solid #cbd5e1;">
                                                        📋 Task Assigned / تفویض شدہ
                                                    </span>
                                                </div>
                                                <div style="font-size:12.5px;color:#475569;background:#f8fafc;padding:10px 14px;border-radius:8px;border:1px dashed #cbd5e1;">
                                                    <span>🎯 <strong>ٹاسک تفویض کیا گیا برائے:</strong> <b style="color:#0f172a;"><?=e($viewTask['assignee_name'])?></b></span>
                                                    <span style="display:inline-block;margin-left:12px;color:#64748b;">(ترجیح: <b><?=strtoupper($viewTask['priority'])?></b> | تخمینی بجٹ: <b><?=money($viewTask['estimated_budget'])?></b>)</span>
                                                </div>
                                            </div>

                                        <?php elseif ($isApproved): ?>
                                            <!-- Supervisor Approval -->
                                            <div class="timeline-item">
                                                <div class="timeline-dot" style="background:#16a34a;border-color:#bbf7d0;"></div>
                                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;flex-wrap:wrap;gap:6px;">
                                                    <div>
                                                        <b style="font-size:13px;color:#15803d;">🛡️ <?=e($log['user_name'])?></b>
                                                        <span style="font-size:11px;color:#64748b;margin-left:4px;">(Supervisor Review)</span>
                                                        <span style="font-size:11px;color:#94a3b8;margin-left:6px;"><?=e(date('d M Y, h:i A', strtotime($log['created_at'])))?></span>
                                                    </div>
                                                    <span style="font-size:11px;background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:4px;font-weight:700;border:1px solid #bbf7d0;">
                                                        ✅ Approved & Verified
                                                    </span>
                                                </div>
                                                <div style="font-size:13px;color:#166534;background:#f0fdf4;padding:10px 14px;border-radius:8px;border:1px solid #bbf7d0;line-height:1.6;">
                                                    <?=nl2br(e($text))?>
                                                </div>
                                            </div>

                                        <?php elseif ($isRejected): ?>
                                            <!-- Supervisor Revision Request -->
                                            <div class="timeline-item">
                                                <div class="timeline-dot" style="background:#dc2626;border-color:#fecaca;"></div>
                                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;flex-wrap:wrap;gap:6px;">
                                                    <div>
                                                        <b style="font-size:13px;color:#b91c1c;">⚠️ <?=e($log['user_name'])?></b>
                                                        <span style="font-size:11px;color:#64748b;margin-left:4px;">(Supervisor Review)</span>
                                                        <span style="font-size:11px;color:#94a3b8;margin-left:6px;"><?=e(date('d M Y, h:i A', strtotime($log['created_at'])))?></span>
                                                    </div>
                                                    <span style="font-size:11px;background:#fee2e2;color:#b91c1c;padding:2px 8px;border-radius:4px;font-weight:700;border:1px solid #fecaca;">
                                                        ⚠️ Revision Requested
                                                    </span>
                                                </div>
                                                <div style="font-size:13px;color:#991b1b;background:#fef2f2;padding:10px 14px;border-radius:8px;border:1px solid #fecaca;line-height:1.6;">
                                                    <?=nl2br(e($text))?>
                                                </div>
                                            </div>

                                        <?php elseif ($isSupervisorNote): ?>
                                            <!-- Supervisor Note / Instruction -->
                                            <div class="timeline-item">
                                                <div class="timeline-dot" style="background:#7c3aed;border-color:#ddd6fe;"></div>
                                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;flex-wrap:wrap;gap:6px;">
                                                    <div>
                                                        <b style="font-size:13px;color:#6d28d9;">💬 <?=e($log['user_name'])?></b>
                                                        <span style="font-size:11px;color:#64748b;margin-left:4px;">(Supervisor Instruction)</span>
                                                        <span style="font-size:11px;color:#94a3b8;margin-left:6px;"><?=e(date('d M Y, h:i A', strtotime($log['created_at'])))?></span>
                                                    </div>
                                                    <span style="font-size:11px;background:#f5f3ff;color:#6d28d9;padding:2px 8px;border-radius:4px;font-weight:700;border:1px solid #ddd6fe;">
                                                        💬 Supervisor Note
                                                    </span>
                                                </div>
                                                <div style="font-size:13px;color:#5b21b6;background:#f5f3ff;padding:10px 14px;border-radius:8px;border:1px solid #ddd6fe;line-height:1.6;">
                                                    <?=nl2br(e($text))?>
                                                </div>
                                            </div>

                                        <?php else: ?>
                                            <!-- Worker Field Progress Report -->
                                            <div class="timeline-item">
                                                <div class="timeline-dot"></div>
                                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;flex-wrap:wrap;gap:6px;">
                                                    <div>
                                                        <b style="font-size:13px;color:#0f172a;"><?=e($log['user_name'])?></b>
                                                        <span style="font-size:11px;color:#64748b;margin-left:6px;"><?=e(date('d M Y, h:i A', strtotime($log['created_at'])))?></span>
                                                    </div>
                                                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                                        <?php if ($canManageTasks): ?>
                                                            <?php if ($log['is_onsite'] === 1 || $log['is_onsite'] === '1'): ?>
                                                                <span style="font-size:11px;background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:4px;font-weight:700;border:1px solid #bbf7d0;">
                                                                    ✓ On-Site <?=($log['distance_meters'] !== null ? '('.$log['distance_meters'].'m)' : '')?>
                                                                </span>
                                                            <?php elseif ($log['is_onsite'] === 0 || $log['is_onsite'] === '0'): ?>
                                                                <span style="font-size:11px;background:#fee2e2;color:#b91c1c;padding:2px 8px;border-radius:4px;font-weight:700;border:1px solid #fecaca;">
                                                                    ⚠️ Off-Site <?=($log['distance_meters'] !== null ? '('.($log['distance_meters'] >= 1000 ? round($log['distance_meters']/1000, 1).'km' : $log['distance_meters'].'m').' away)' : '')?>
                                                                </span>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                        <span class="badge badge-blue" style="font-size:11px;">
                                                            Progress: <?=(int)$log['progress_percent']?>%
                                                        </span>
                                                    </div>
                                                </div>
                                                <div style="font-size:13px;color:#334155;line-height:1.6;background:#f8fafc;padding:10px 14px;border-radius:8px;border:1px solid #e2e8f0;">
                                                    <?=nl2br(e($log['report_text']))?>

                                                    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-top:8px;padding-top:6px;border-top:1px dashed #e2e8f0;">
                                                        <?php if ($canManageTasks && !empty($log['latitude']) && !empty($log['longitude'])): ?>
                                                            <a href="https://www.google.com/maps?q=<?=e($log['latitude'])?>,<?=e($log['longitude'])?>" target="_blank" style="display:inline-flex;align-items:center;gap:5px;background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;padding:3px 9px;border-radius:6px;font-size:11.5px;text-decoration:none;font-weight:600;">
                                                                📍 Open Live GPS Pin (<?=round((float)$log['latitude'], 5)?>, <?=round((float)$log['longitude'], 5)?>) &rarr;
                                                            </a>
                                                        <?php endif; ?>

                                                        <?php if (!empty($log['photo_attachment'])): 
                                                            $fPath = 'uploads/tasks/' . $log['photo_attachment'];
                                                        ?>
                                                            <a href="<?=e($fPath)?>" target="_blank" style="display:inline-flex;align-items:center;gap:6px;background:#fff;border:1px solid #cbd5e1;padding:3px 9px;border-radius:6px;font-size:11.5px;color:#2563eb;text-decoration:none;font-weight:600;">
                                                                📷 View Attached Photo / Document &rarr;
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="padding:12px 22px;border-top:1px solid #e2e8f0;background:#f8fafc;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                        <a href="tasks.php?view_task=<?=$viewTask['id']?>&print=1" target="_blank" class="btn secondary sm" style="display:inline-flex;align-items:center;gap:6px;background:#fff;border:1px solid #cbd5e1;color:#1e293b;text-decoration:none;font-weight:700;">
                            🖨️ Print Dossier / Save as PDF <span class="ur">(پرنٹ رپورٹ)</span>
                        </a>
                        <a href="tasks.php" class="btn secondary sm">Close Detail</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?=render_app_footer($madrasaName)?>
    </main>
</div>

<script>
function openCreateTaskModal() {
    document.getElementById('createTaskModal').classList.add('open');
}
function closeCreateTaskModal() {
    document.getElementById('createTaskModal').classList.remove('open');
}

function openProgressModal(task) {
    if (task && task.dataset) {
        task = {
            id: task.dataset.id,
            title: task.dataset.title,
            task_code: task.dataset.code,
            progress_percent: parseInt(task.dataset.percent || '0', 10),
            status: task.dataset.status
        };
    }
    if (!task) return;
    document.getElementById('progTaskId').value = task.id || '';
    document.getElementById('progressModalTitle').textContent = 'Post Progress Update: ' + (task.title || '');
    document.getElementById('progressModalCode').textContent = task.task_code || '';
    var pct = task.progress_percent || 0;
    document.getElementById('progSlider').value = pct;
    document.getElementById('progSliderVal').textContent = pct + '%';
    document.getElementById('progStatus').value = (task.status === 'completed' || task.status === 'on_hold') ? task.status : 'in_progress';
    var chkAfter = document.getElementById('chkAfterPhoto');
    if (chkAfter) chkAfter.checked = (pct === 100);
    document.getElementById('progressModal').classList.add('open');
    refreshWorkerGPS();
}
function closeProgressModal() {
    document.getElementById('progressModal').classList.remove('open');
}

function openEditModal(task) {
    if (task && task.dataset) {
        task = {
            id: task.dataset.id,
            title: task.dataset.title,
            description: task.dataset.description || '',
            project_location: task.dataset.projectLocation || '',
            target_lat: task.dataset.targetLat || '',
            target_lng: task.dataset.targetLng || '',
            radius_meters: task.dataset.radiusMeters || '300',
            assigned_to: task.dataset.assignedTo,
            priority: task.dataset.priority || 'medium',
            status: task.dataset.status || 'pending',
            progress_percent: parseInt(task.dataset.percent || '0', 10),
            estimated_budget: task.dataset.budget || '',
            due_date: task.dataset.dueDate || ''
        };
    }
    if (!task) return;
    document.getElementById('editTaskId').value = task.id || '';
    document.getElementById('editTaskTitle').value = task.title || '';
    document.getElementById('editTaskDesc').value = task.description || '';
    if (document.getElementById('editProjectLoc')) document.getElementById('editProjectLoc').value = task.project_location || '';
    if (document.getElementById('editTargetLat')) document.getElementById('editTargetLat').value = task.target_lat || '';
    if (document.getElementById('editTargetLng')) document.getElementById('editTargetLng').value = task.target_lng || '';
    if (document.getElementById('editRadiusMeters')) document.getElementById('editRadiusMeters').value = task.radius_meters || '300';
    updateExternalMapLink('edit', task.target_lat, task.target_lng);
    if (task.target_lat && task.target_lng && locationMaps['edit']) {
        updateMapPin('edit', parseFloat(task.target_lat), parseFloat(task.target_lng));
    }
    document.getElementById('editTaskAssignee').value = task.assigned_to || '';
    document.getElementById('editTaskPriority').value = task.priority || 'medium';
    document.getElementById('editTaskStatus').value = task.status || 'pending';
    document.getElementById('editTaskProgress').value = task.progress_percent || 0;
    document.getElementById('editTaskBudget').value = task.estimated_budget || '';
    document.getElementById('editTaskDue').value = task.due_date || '';
    document.getElementById('editTaskModal').classList.add('open');
}
function closeEditModal() {
    document.getElementById('editTaskModal').classList.remove('open');
}

var locationMaps = { create: null, edit: null };
var locationMarkers = { create: null, edit: null };
var locationCircles = { create: null, edit: null };

function detectTargetGPS(prefix) {
    if (!navigator.geolocation) {
        alert("Geolocation is not supported by your browser.");
        return;
    }
    var latInput = document.getElementById(prefix === 'create' ? 'createTargetLat' : 'editTargetLat');
    var lngInput = document.getElementById(prefix === 'create' ? 'createTargetLng' : 'editTargetLng');
    var locInput = document.getElementById(prefix === 'create' ? 'createProjectLoc' : 'editProjectLoc');
    var statusEl = document.getElementById(prefix + 'GpsStatus');

    if (statusEl) {
        statusEl.innerHTML = '<span style="color:#0284c7;">⏳ Detecting current GPS...</span>';
    }

    navigator.geolocation.getCurrentPosition(
        function(pos) {
            var lat = pos.coords.latitude;
            var lng = pos.coords.longitude;
            var acc = Math.round(pos.coords.accuracy);

            if (latInput) latInput.value = lat.toFixed(7);
            if (lngInput) lngInput.value = lng.toFixed(7);

            if (statusEl) {
                statusEl.innerHTML = '<span style="color:#15803d;font-weight:700;">✓ GPS Detected (±' + acc + 'm)</span>';
            }

            updateExternalMapLink(prefix, lat, lng);
            if (locationMaps[prefix]) {
                updateMapPin(prefix, lat, lng);
            }

            // Reverse Geocode with Nominatim to populate location name if empty or generic
            if (locInput && (!locInput.value.trim() || locInput.value.indexOf('GPS') !== -1)) {
                locInput.value = 'Detecting address...';
                fetch('https://nominatim.openstreetmap.org/reverse?format=json&lat=' + lat + '&lon=' + lng + '&accept-language=ur,en')
                    .then(function(res) { return res.json(); })
                    .then(function(data) {
                        if (data && data.display_name) {
                            var shortName = formatGeocodedAddress(data);
                            locInput.value = shortName || data.display_name;
                        } else {
                            locInput.value = 'Site GPS (' + lat.toFixed(5) + ', ' + lng.toFixed(5) + ')';
                        }
                    })
                    .catch(function() {
                        locInput.value = 'Site GPS (' + lat.toFixed(5) + ', ' + lng.toFixed(5) + ')';
                    });
            }
        },
        function(err) {
            if (statusEl) {
                statusEl.innerHTML = '<span style="color:#b91c1c;">⚠️ ' + err.message + '</span>';
            }
            alert("Could not detect GPS: " + err.message + ".\n\nTip: You can search location by name or pick it on the map below.");
        },
        { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
    );
}

function formatGeocodedAddress(data) {
    if (!data) return '';
    var addr = data.address || {};
    var parts = [];
    var road = addr.road || addr.neighbourhood || addr.suburb || addr.village || addr.hamlet;
    if (road) parts.push(road);
    var city = addr.city || addr.town || addr.county || addr.district;
    if (city && parts.indexOf(city) === -1) parts.push(city);
    var state = addr.state;
    if (state && parts.indexOf(state) === -1) parts.push(state);
    return parts.length ? parts.join(', ') : data.display_name;
}

function setPresetLocation(prefix, name, lat, lng) {
    var locInput = document.getElementById(prefix === 'create' ? 'createProjectLoc' : 'editProjectLoc');
    var latInput = document.getElementById(prefix === 'create' ? 'createTargetLat' : 'editTargetLat');
    var lngInput = document.getElementById(prefix === 'create' ? 'createTargetLng' : 'editTargetLng');
    var statusEl = document.getElementById(prefix + 'GpsStatus');

    if (locInput) locInput.value = name;
    if (latInput) latInput.value = lat.toFixed(7);
    if (lngInput) lngInput.value = lng.toFixed(7);

    if (statusEl) {
        statusEl.innerHTML = '<span style="color:#15803d;font-weight:700;">✓ ' + name.split('(')[0].trim() + ' سیٹ ہو گیا۔</span>';
    }

    updateExternalMapLink(prefix, lat, lng);
    if (locationMaps[prefix]) {
        updateMapPin(prefix, lat, lng);
    }
}

function clearLocationGPS(prefix) {
    var latInput = document.getElementById(prefix === 'create' ? 'createTargetLat' : 'editTargetLat');
    var lngInput = document.getElementById(prefix === 'create' ? 'createTargetLng' : 'editTargetLng');
    var statusEl = document.getElementById(prefix + 'GpsStatus');
    var extLink = document.getElementById(prefix + 'ExternalMapLink');

    if (latInput) latInput.value = '';
    if (lngInput) lngInput.value = '';

    if (statusEl) {
        statusEl.innerHTML = '<span style="color:#64748b;font-weight:600;">GPS کوآرڈینیٹس ختم۔ (کوئی جغرافیائی پابندی نہیں)</span>';
    }
    if (extLink) extLink.innerHTML = '';

    if (locationMarkers[prefix]) {
        locationMarkers[prefix].remove();
        locationMarkers[prefix] = null;
    }
    if (locationCircles[prefix]) {
        locationCircles[prefix].remove();
        locationCircles[prefix] = null;
    }
}

function searchLocationByName(prefix) {
    var locInput = document.getElementById(prefix === 'create' ? 'createProjectLoc' : 'editProjectLoc');
    var statusEl = document.getElementById(prefix + 'GpsStatus');
    var query = locInput ? locInput.value.trim() : '';
    if (!query) {
        alert("براہ کرم تلاش کے لیے مقام یا شہر کا نام درج کریں۔ (Please enter place/city name to search).");
        if (locInput) locInput.focus();
        return;
    }

    if (statusEl) {
        statusEl.innerHTML = '<span style="color:#0284c7;">🔍 نقشے پر تلاش جاری ہے...</span>';
    }

    var url = 'https://nominatim.openstreetmap.org/search?format=json&q=' + encodeURIComponent(query) + '&countrycodes=pk&limit=1';
    fetch(url)
        .then(function(res) { return res.json(); })
        .then(function(results) {
            if (results && results.length > 0) {
                var item = results[0];
                var lat = parseFloat(item.lat);
                var lng = parseFloat(item.lon);
                var latInput = document.getElementById(prefix === 'create' ? 'createTargetLat' : 'editTargetLat');
                var lngInput = document.getElementById(prefix === 'create' ? 'createTargetLng' : 'editTargetLng');
                if (latInput) latInput.value = lat.toFixed(7);
                if (lngInput) lngInput.value = lng.toFixed(7);

                if (statusEl) {
                    statusEl.innerHTML = '<span style="color:#15803d;font-weight:700;">✓ مقام مل گیا: ' + (item.name || query) + '</span>';
                }

                ensureMapOpen(prefix);
                updateExternalMapLink(prefix, lat, lng);
                updateMapPin(prefix, lat, lng);
            } else {
                if (statusEl) {
                    statusEl.innerHTML = '<span style="color:#b91c1c;">⚠️ مقام نہیں مل سکا۔ دستی طور پر نقشے پر کلک کریں۔</span>';
                }
                alert("مقام نہیں مل سکا۔ آپ 'Show Map' پر کلک کر کے نقشے سے خود جگہ چن سکتے ہیں۔");
            }
        })
        .catch(function(e) {
            if (statusEl) statusEl.innerHTML = '<span style="color:#b91c1c;">⚠️ تلاش میں غلطی ہوئی۔</span>';
        });
}

function toggleMapPicker(prefix) {
    var wrap = document.getElementById(prefix + 'MapWrap');
    var btnText = document.getElementById(prefix + 'MapToggleText');
    if (!wrap) return;
    if (wrap.style.display === 'none' || !wrap.style.display) {
        wrap.style.display = 'block';
        if (btnText) btnText.textContent = 'Hide Map (نقشہ چھپائیں)';
        initMap(prefix);
    } else {
        wrap.style.display = 'none';
        if (btnText) btnText.textContent = 'Show Map (نقشہ کھولیں)';
    }
}

function ensureMapOpen(prefix) {
    var wrap = document.getElementById(prefix + 'MapWrap');
    var btnText = document.getElementById(prefix + 'MapToggleText');
    if (wrap && (wrap.style.display === 'none' || !wrap.style.display)) {
        wrap.style.display = 'block';
        if (btnText) btnText.textContent = 'Hide Map (نقشہ چھپائیں)';
    }
    initMap(prefix);
}

function initMap(prefix) {
    if (typeof L === 'undefined') return;
    var containerId = prefix + 'Map';
    var container = document.getElementById(containerId);
    if (!container) return;

    var latInput = document.getElementById(prefix === 'create' ? 'createTargetLat' : 'editTargetLat');
    var lngInput = document.getElementById(prefix === 'create' ? 'createTargetLng' : 'editTargetLng');
    var radInput = document.getElementById(prefix === 'create' ? 'createRadiusMeters' : 'editRadiusMeters');

    var lat = latInput && latInput.value ? parseFloat(latInput.value) : 34.2541; // Default Mangalchai Swabi
    var lng = lngInput && lngInput.value ? parseFloat(lngInput.value) : 72.5186;
    var radius = radInput && radInput.value ? parseInt(radInput.value, 10) : 300;

    if (!locationMaps[prefix]) {
        var map = L.map(containerId).setView([lat, lng], 14);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap'
        }).addTo(map);

        map.on('click', function(e) {
            var clickedLat = e.latlng.lat;
            var clickedLng = e.latlng.lng;
            if (latInput) latInput.value = clickedLat.toFixed(7);
            if (lngInput) lngInput.value = clickedLng.toFixed(7);
            updateMapPin(prefix, clickedLat, clickedLng);
            updateExternalMapLink(prefix, clickedLat, clickedLng);

            // Reverse geocode clicked point
            var locInput = document.getElementById(prefix === 'create' ? 'createProjectLoc' : 'editProjectLoc');
            fetch('https://nominatim.openstreetmap.org/reverse?format=json&lat=' + clickedLat + '&lon=' + clickedLng + '&accept-language=ur,en')
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data && locInput && (!locInput.value.trim() || locInput.value.indexOf('GPS') !== -1)) {
                        var sn = formatGeocodedAddress(data);
                        locInput.value = sn || data.display_name;
                    }
                })
                .catch(function(){});
        });

        locationMaps[prefix] = map;
    }

    setTimeout(function() {
        if (locationMaps[prefix]) {
            locationMaps[prefix].invalidateSize();
            if (latInput && latInput.value && lngInput && lngInput.value) {
                updateMapPin(prefix, parseFloat(latInput.value), parseFloat(lngInput.value));
            }
        }
    }, 200);
}

function updateMapPin(prefix, lat, lng) {
    if (!locationMaps[prefix] || typeof L === 'undefined') return;
    var map = locationMaps[prefix];
    var radInput = document.getElementById(prefix === 'create' ? 'createRadiusMeters' : 'editRadiusMeters');
    var radius = radInput && radInput.value ? parseInt(radInput.value, 10) : 300;

    if (locationMarkers[prefix]) {
        locationMarkers[prefix].setLatLng([lat, lng]);
    } else {
        var marker = L.marker([lat, lng], { draggable: true }).addTo(map);
        marker.on('dragend', function(e) {
            var pos = marker.getLatLng();
            var latInput = document.getElementById(prefix === 'create' ? 'createTargetLat' : 'editTargetLat');
            var lngInput = document.getElementById(prefix === 'create' ? 'createTargetLng' : 'editTargetLng');
            if (latInput) latInput.value = pos.lat.toFixed(7);
            if (lngInput) lngInput.value = pos.lng.toFixed(7);
            updateMapPin(prefix, pos.lat, pos.lng);
            updateExternalMapLink(prefix, pos.lat, pos.lng);
        });
        locationMarkers[prefix] = marker;
    }

    if (locationCircles[prefix]) {
        locationCircles[prefix].setLatLng([lat, lng]);
        locationCircles[prefix].setRadius(radius);
    } else {
        var circle = L.circle([lat, lng], {
            radius: radius,
            color: '#2563eb',
            fillColor: '#3b82f6',
            fillOpacity: 0.18
        }).addTo(map);
        locationCircles[prefix] = circle;
    }

    map.panTo([lat, lng]);
}

function updateExternalMapLink(prefix, lat, lng) {
    var el = document.getElementById(prefix + 'ExternalMapLink');
    if (!el) return;
    if (lat && lng) {
        el.innerHTML = '<a href="https://www.google.com/maps?q=' + lat + ',' + lng + '" target="_blank" style="color:#2563eb;text-decoration:none;font-weight:600;">🗺️ View on Google Maps (Google Maps پر دیکھیں) &rarr;</a>';
    } else {
        el.innerHTML = '';
    }
}

function onCoordInputChange(prefix) {
    var latInput = document.getElementById(prefix === 'create' ? 'createTargetLat' : 'editTargetLat');
    var lngInput = document.getElementById(prefix === 'create' ? 'createTargetLng' : 'editTargetLng');
    if (latInput && lngInput && latInput.value && lngInput.value) {
        var lat = parseFloat(latInput.value);
        var lng = parseFloat(lngInput.value);
        if (!isNaN(lat) && !isNaN(lng)) {
            updateMapPin(prefix, lat, lng);
            updateExternalMapLink(prefix, lat, lng);
        }
    }
}

function refreshWorkerGPS() {
    var latEl = document.getElementById('progLat');
    var lngEl = document.getElementById('progLng');
    var accEl = document.getElementById('progAccuracy');

    if (!navigator.geolocation) return;

    navigator.geolocation.getCurrentPosition(
        function(pos) {
            if (latEl) latEl.value = pos.coords.latitude.toFixed(6);
            if (lngEl) lngEl.value = pos.coords.longitude.toFixed(6);
            if (accEl) accEl.value = Math.round(pos.coords.accuracy);
        },
        function(err) {
            // Silently ignore, no warning shown to worker
        },
        { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
    );
}

function switchSupervisorAction(action) {
    var title = document.getElementById('supervisorModalTitle');
    var prompt = document.getElementById('reviewPromptText');
    var input = document.getElementById('reviewActionInput');
    var btn = document.getElementById('supervisorSubmitBtn');
    var rApp = document.getElementById('radioApprove');
    var rRej = document.getElementById('radioReject');
    var rNot = document.getElementById('radioNote');

    if (!input) return;
    input.value = action;

    if (action === 'approve') {
        if (rApp) rApp.checked = true;
        title.innerHTML = '✅ Approve Task & Verify Work <span class="ur">(کام کی باضابطہ منظوری)</span>';
        prompt.innerHTML = 'کیا آپ اس ٹاسک کی پیشرفت اور سائٹ کوالٹی کی تصدیق کر کے اسے <b>مکمل و منظور شدہ (Approved)</b> قرار دینا چاہتے ہیں؟';
        btn.textContent = '✅ Confirm Approval (منظور کریں)';
        btn.className = 'btn success';
        btn.style.background = '#15803d';
        btn.style.borderColor = '#15803d';
    } else if (action === 'reject') {
        if (rRej) rRej.checked = true;
        title.innerHTML = '⚠️ Reject / Request Revision <span class="ur">(مسترد / واپس بھیجیں)</span>';
        prompt.innerHTML = 'ورکر کا کام مسترد کرنے یا دوبارہ کام کروانے کی وجہ لکھیں (اس سے ٹاسک کا سٹیٹس دوبارہ <b>In Progress</b> ہو جائے گا اور ورکر کو الرٹ موصول ہوگا):';
        btn.textContent = '⚠️ Send Rejection (مسترد کریں)';
        btn.className = 'btn danger';
        btn.style.background = '#b91c1c';
        btn.style.borderColor = '#b91c1c';
    } else {
        if (rNot) rNot.checked = true;
        title.innerHTML = '💬 Add Supervisor Note <span class="ur">(نگرانی نوٹ شامل کریں)</span>';
        prompt.innerHTML = 'ورکر کے لیے اپنی ضروری ہدایات یا رہنمائی درج کریں (اس سے کام کا سٹیٹس تبدیل نہیں ہوگا):';
        btn.textContent = '💬 Post Supervisor Note (نوٹ محفوظ کریں)';
        btn.className = 'btn blue';
        btn.style.background = '#2563eb';
        btn.style.borderColor = '#2563eb';
    }
}

function openSupervisorReviewModal(action) {
    var modal = document.getElementById('supervisorReviewModal');
    var txt = document.getElementById('reviewRemarkText');

    if (!modal) return;
    if (txt) txt.value = '';
    switchSupervisorAction(action || 'approve');
    modal.classList.add('open');
}

function closeSupervisorReviewModal() {
    var modal = document.getElementById('supervisorReviewModal');
    if (modal) modal.classList.remove('open');
}

// Close modals when clicking outside the card on the backdrop
window.addEventListener('click', function(e) {
    if (e.target && e.target.classList.contains('modal-overlay')) {
        if (e.target.id === 'progressModal') {
            closeProgressModal();
        } else if (e.target.id === 'editTaskModal') {
            closeEditModal();
        } else if (e.target.id === 'createTaskModal') {
            closeCreateTaskModal();
        } else if (e.target.id === 'supervisorReviewModal') {
            closeSupervisorReviewModal();
        } else if (e.target.id === 'viewDetailModal') {
            window.location.href = 'tasks.php';
        }
    }
});

// Close top open modal on Escape key
window.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        var prog = document.getElementById('progressModal');
        var edit = document.getElementById('editTaskModal');
        var create = document.getElementById('createTaskModal');
        var rev = document.getElementById('supervisorReviewModal');
        var view = document.getElementById('viewDetailModal');
        if (rev && rev.classList.contains('open')) {
            closeSupervisorReviewModal();
        } else if (prog && prog.classList.contains('open')) {
            closeProgressModal();
        } else if (edit && edit.classList.contains('open')) {
            closeEditModal();
        } else if (create && create.classList.contains('open')) {
            closeCreateTaskModal();
        } else if (view && view.classList.contains('open')) {
            window.location.href = 'tasks.php';
        }
    }
});

// 1-Click WhatsApp Quick Notification Helper
function sendTaskWhatsApp(target) {
    <?php if ($viewTask): ?>
    var code = <?=json_encode($viewTask['task_code'] ?? '')?>;
    var title = <?=json_encode($viewTask['title'] ?? '')?>;
    var assignee = <?=json_encode($viewTask['assignee_name'] ?? '')?>;
    var assigneePhone = <?=json_encode($viewTask['assignee_phone'] ?? '')?>;
    var creatorPhone = <?=json_encode($viewTask['creator_phone'] ?? '')?>;
    var priority = <?=json_encode(strtoupper($viewTask['priority'] ?? 'MEDIUM'))?>;
    var progress = <?=json_encode((int)($viewTask['progress_percent'] ?? 0))?>;
    var location = <?=json_encode($viewTask['project_location'] ?? '')?>;
    var budget = <?=json_encode(number_format((float)($viewTask['estimated_budget'] ?? 0)))?>;

    function cleanPhone(p) {
        if (!p) return '';
        p = p.replace(/[^0-9]/g, '');
        if (p.startsWith('0')) p = '92' + p.substring(1);
        return p;
    }

    var phone = '';
    var text = '';

    if (target === 'worker') {
        phone = cleanPhone(assigneePhone);
        if (!phone) {
            alert('ورکر کا واٹس ایپ نمبر سسٹم میں درج نہیں ہے۔ براہ کرم Users سیکشن میں جا کر ورکر کا موبائل نمبر شامل فرمائیں۔');
            return;
        }
        text = 'السلام علیکم ' + assignee + '!\n' +
               'آپ کو پورٹل پر نیا ٹاسک تفویض کیا گیا ہے:\n' +
               '📌 کوڈ: ' + code + '\n' +
               '📋 عنوان: ' + title + '\n' +
               '⚡ ترجیح: ' + priority + '\n' +
               (location ? ('📍 مقام: ' + location + '\n') : '') +
               '💰 تخمینی بجٹ: Rs. ' + budget + '\n' +
               'براہ کرم پورٹل پر لاگ ان کر کے کام کی پیشرفت رپورٹ جمع کروائیں۔ شکریہ!';
    } else {
        phone = cleanPhone(creatorPhone);
        if (!phone) {
            alert('ایڈمن کا موبائل نمبر سسٹم میں درج نہیں ہے۔');
            return;
        }
        text = 'محترم ایڈمن / سپروائزر!\n' +
               'ٹاسک کی پیشرفت رپورٹ درج کی گئی ہے:\n' +
               '📌 کوڈ: ' + code + ' (' + title + ')\n' +
               '👤 ورکر: ' + assignee + '\n' +
               '📊 پروگریس: ' + progress + '%\n' +
               'براہ کرم پورٹل پر تصاویر اور سائٹ لوکیشن کی تصدیق فرمائیں۔ شکریہ!';
    }

    var waUrl = 'https://api.whatsapp.com/send?phone=' + encodeURIComponent(phone) + '&text=' + encodeURIComponent(text);
    window.open(waUrl, '_blank');
    <?php else: ?>
    alert('ٹاسک منتخب نہیں ہے۔');
    <?php endif; ?>
}

<?php if ($viewTask && !empty($viewTask['target_lat']) && !empty($viewTask['target_lng'])): ?>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof L !== 'undefined' && document.getElementById('viewTaskMap')) {
        var tLat = <?= (float)$viewTask['target_lat'] ?>;
        var tLng = <?= (float)$viewTask['target_lng'] ?>;
        var tRadius = <?= (int)($viewTask['radius_meters'] ?? 300) ?>;

        var vMap = L.map('viewTaskMap').setView([tLat, tLng], 15);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap'
        }).addTo(vMap);

        // Target marker
        L.marker([tLat, tLng]).addTo(vMap)
            .bindPopup('<b>📍 Target Site (مطلوبہ مقام)</b><br><?=e(addslashes($viewTask['project_location'] ?? 'Target Site'))?>')
            .openPopup();

        // Geofence circle
        L.circle([tLat, tLng], {
            radius: tRadius,
            color: '#2563eb',
            fillColor: '#3b82f6',
            fillOpacity: 0.15
        }).addTo(vMap);

        <?php if (!empty($viewTaskLogs)): ?>
            <?php foreach ($viewTaskLogs as $l): ?>
                <?php if (!empty($l['latitude']) && !empty($l['longitude'])): ?>
                    var isOs = <?= ($l['is_onsite'] === 1 || $l['is_onsite'] === '1') ? 'true' : 'false' ?>;
                    var lPin = L.circleMarker([<?= (float)$l['latitude'] ?>, <?= (float)$l['longitude'] ?>], {
                        radius: 7,
                        color: isOs ? '#15803d' : '#b91c1c',
                        fillColor: isOs ? '#22c55e' : '#ef4444',
                        fillOpacity: 0.85,
                        weight: 2
                    }).addTo(vMap);
                    lPin.bindPopup('<b><?=e(addslashes($l['reporter_name'] ?? 'Staff'))?></b><br><span style="font-size:11px;"><?=e(date('d M, h:i A', strtotime($l['created_at'])))?></span><br>' + (isOs ? '✓ On-Site' : '⚠️ Off-Site'));
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>

        setTimeout(function() { vMap.invalidateSize(); }, 350);
    }
});
<?php endif; ?>
</script>
</body>
</html>

