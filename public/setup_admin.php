<?php
require __DIR__ . '/../app/bootstrap.php';

// Helper to check if tables exist
function tables_exist(PDO $pdo): bool {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
        return (bool)$stmt->fetch();
    } catch (Exception $e) {
        return false;
    }
}

// Robust SQL schema runner
function run_schema(PDO $pdo, string $filePath): void {
    if (!is_file($filePath)) {
        throw new RuntimeException("Schema file not found at: {$filePath}");
    }
    $sql = file_get_contents($filePath);
    
    // Clean CREATE DATABASE and USE statements to avoid Hostinger privilege restrictions
    $sql = preg_replace('/CREATE\s+DATABASE\s+IF\s+NOT\s+EXISTS\s+`?[a-zA-Z0-9_]+`?.*?;/i', '', $sql);
    $sql = preg_replace('/CREATE\s+DATABASE\s+`?[a-zA-Z0-9_]+`?.*?;/i', '', $sql);
    $sql = preg_replace('/USE\s+`?[a-zA-Z0-9_]+`?;/i', '', $sql);

    // Try multi-statement exec first
    try {
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, 1);
        $pdo->exec($sql);
        return;
    } catch (Exception $e) {
        // Fallback to statement-by-statement parsing
    }

    $statements = [];
    $current = '';
    $inString = false;
    $stringChar = '';
    $len = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $char = $sql[$i];
        $prev = $i > 0 ? $sql[$i - 1] : '';

        if ($inString) {
            if ($char === $stringChar && $prev !== '\\') {
                $inString = false;
            }
            $current .= $char;
        } else {
            if ($char === "'" || $char === '"') {
                $inString = true;
                $stringChar = $char;
                $current .= $char;
            } elseif ($char === ';') {
                $trimmed = trim($current);
                if (!empty($trimmed)) {
                    $statements[] = $trimmed;
                }
                $current = '';
            } else {
                $current .= $char;
            }
        }
    }
    $trimmed = trim($current);
    if (!empty($trimmed)) {
        $statements[] = $trimmed;
    }

    foreach ($statements as $stmt) {
        $pdo->exec($stmt);
    }
}

$tablesExist = tables_exist($pdo);
$adminCount = 0;

if ($tablesExist) {
    try {
        $adminCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    } catch (Exception $e) {
        $tablesExist = false;
    }
}

if ($tablesExist && $adminCount > 0) {
    http_response_code(403);
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Setup Complete | Madrasa Accounts</title>
        <link rel="stylesheet" href="assets/style.css?v=<?=css_ver()?>">
    </head>
    <body class="auth-bg">
        <main class="auth-card" style="text-align:center;">
            <div class="brand-mark" style="margin:0 auto 16px;">MA</div>
            <h2>Setup Already Completed</h2>
            <p class="muted">The Super Administrator account has already been created.</p>
            <div style="margin:24px 0;">
                <a href="login.php" class="btn primary full">Go to Login</a>
            </div>
            <p class="warning-text">For security, please delete <code>public/setup_admin.php</code> from your Hostinger server.</p>
        </main>
    </body>
    </html>
    <?php
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($name === '' || !preg_match('/^[a-zA-Z0-9_.-]{4,60}$/', $username) || strlen($password) < 12) {
        $error = 'Enter your full name, a username (at least 4 characters: letters, numbers, dot, dash, underscore), and a password of at least 12 characters.';
    } else {
        try {
            // Step 1: Auto-initialize tables if not already created
            if (!$tablesExist) {
                run_schema($pdo, __DIR__ . '/../database/schema.sql');
                $tablesExist = true;
            }

            // Step 2: Fetch Super Admin role
            $roleId = (int)$pdo->query("SELECT id FROM roles WHERE name='Super Admin'")->fetchColumn();
            if (!$roleId) {
                // If roles table empty, seed schema again
                run_schema($pdo, __DIR__ . '/../database/schema.sql');
                $roleId = (int)$pdo->query("SELECT id FROM roles WHERE name='Super Admin'")->fetchColumn();
            }

            if (!$roleId) {
                throw new RuntimeException("Could not find Super Admin role in database.");
            }

            // Step 3: Create Super Admin user
            $stmt = $pdo->prepare('INSERT INTO users (role_id, full_name, username, password_hash) VALUES (?, ?, ?, ?)');
            $stmt->execute([$roleId, $name, $username, password_hash($password, PASSWORD_DEFAULT)]);

            header('Location: login.php?setup=success');
            exit;
        } catch (Exception $e) {
            $error = 'Database Setup Error: ' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Setup Database & Super Admin | Madrasa Accounts</title>
    <link rel="stylesheet" href="assets/style.css?v=<?=css_ver()?>">
    <style>
        .db-status {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #166534;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 20px;
            text-align: left;
        }
        .db-status b { display: block; font-size: 14px; margin-bottom: 3px; }
        .tag-pill {
            display: inline-block;
            background: #e0f2fe;
            color: #0369a1;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
    </style>
</head>
<body class="auth-bg">
    <main class="auth-card">
        <div class="brand-mark">MA</div>
        <h1>System Setup</h1>
        <p class="muted">Initialize Madrasa Accounts database & create Super Admin</p>

        <div class="db-status">
            <b>Database Connection: Active</b>
            <div>Database: <code><?=e($config['db']['name'])?></code></div>
            <div>Status: <?php if ($tablesExist): ?>
                <span class="tag-pill" style="background:#dcfce7;color:#15803d;">Tables Ready</span>
            <?php else: ?>
                <span class="tag-pill" style="background:#fef3c7;color:#b45309;">Tables will be created on submit</span>
            <?php endif; ?></div>
        </div>

        <?php if ($error): ?>
            <div class="alert error"><?=e($error)?></div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
            
            <label>Full Name</label>
            <input name="full_name" required maxlength="120" placeholder="e.g. Suhail Ahmad" value="<?=e($_POST['full_name'] ?? '')?>">
            
            <label>Username</label>
            <input name="username" required minlength="4" maxlength="60" autocomplete="username" placeholder="e.g. admin" value="<?=e($_POST['username'] ?? '')?>">
            
            <label>Password</label>
            <input type="password" name="password" required minlength="12" autocomplete="new-password" placeholder="At least 12 characters">
            <small class="muted" style="display:block;margin-top:4px;margin-bottom:16px;">Minimum 12 characters for strong security.</small>
            
            <button class="btn primary full" type="submit">
                <?= $tablesExist ? 'Create Super Admin' : 'Initialize Database & Create Admin' ?>
            </button>
        </form>

        <p class="warning-text" style="margin-top:20px;">
            After completing setup, remember to delete <code>public/setup_admin.php</code> from your server for security.
        </p>
    </main>
</body>
</html>
