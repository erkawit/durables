<?php
require_once 'config.php';

try {
    // 1. Check if 'reason' exists in 'requests'
    $stmt = $pdo->query("SHOW COLUMNS FROM requests LIKE 'reason'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE requests ADD COLUMN reason TEXT DEFAULT NULL AFTER status");
        echo "✅ Added 'reason' column to requests table.<br>";
    } else {
        echo "ℹ️ 'reason' column already exists in requests table.<br>";
    }

    // 2. Check if 'min_order' exists (just to be sure)
    $stmt = $pdo->query("SHOW COLUMNS FROM supplies LIKE 'min_order'");
    if ($stmt->rowCount() == 0) {
        // If not exists, add it (default 1)
        $pdo->exec("ALTER TABLE supplies ADD COLUMN min_order INT DEFAULT 1");
        echo "✅ Added 'min_order' column to supplies table.<br>";
    } else {
        echo "ℹ️ 'min_order' column already exists.<br>";
    }

    // 3. Check 'max_order'
    $stmt = $pdo->query("SHOW COLUMNS FROM supplies LIKE 'max_order'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE supplies ADD COLUMN max_order INT DEFAULT 100"); // Default max
        echo "✅ Added 'max_order' column to supplies table.<br>";
    } else {
        echo "ℹ️ 'max_order' column already exists.<br>";
    }

    // 4. Check 'inspection_threshold'
    $stmt = $pdo->query("SHOW COLUMNS FROM supplies LIKE 'inspection_threshold'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE supplies ADD COLUMN inspection_threshold INT DEFAULT 100 AFTER price");
        echo "✅ Added 'inspection_threshold' column to supplies table.<br>";
    } else {
        echo "ℹ️ 'inspection_threshold' column already exists.<br>";
    }

} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>