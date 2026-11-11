<?php
// cron_backup.php - Automated Scheduled Database Backup Endpoint
// Compatible with Hostinger hPanel Cron Jobs, cPanel Cron Jobs, Wget, Curl & CLI
require __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$isCli = (php_sapi_name() === 'cli' || empty($_SERVER['REMOTE_ADDR']));
$validToken = get_or_create_cron_token();
$providedToken = (string)($_GET['token'] ?? '');
$force = isset($_GET['force']) && $_GET['force'] === '1';

if (!$isCli && (!hash_equals($validToken, $providedToken))) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Forbidden: Invalid or missing cron backup security token.'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $filePath = auto_run_daily_backup($pdo, $force);
    $savedFiles = get_saved_backups_list();
    $latest = !empty($savedFiles) ? $savedFiles[0] : null;

    echo json_encode([
        'success' => true,
        'message' => 'Daily automated database backup executed successfully.',
        'file' => $latest ? $latest['filename'] : null,
        'size' => $latest ? $latest['filesize_formatted'] : null,
        'timestamp' => date('Y-m-d H:i:s'),
        'total_backups_archived' => count($savedFiles)
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Backup failed: ' . $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

