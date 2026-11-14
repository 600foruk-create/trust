<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/../app/bootstrap.php';

echo "Bootstrap OK<br>";
echo "PHP Version: " . PHP_VERSION . "<br>";

try {
    $cols = $pdo->query("SHOW COLUMNS FROM beneficiaries")->fetchAll(PDO::FETCH_COLUMN);
    echo "Beneficiaries columns: " . implode(', ', $cols) . "<br>";
} catch (Throwable $e) {
    echo "Error querying beneficiaries: " . $e->getMessage() . "<br>";
}

echo "Diag finished successfully.";
