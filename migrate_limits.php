<?php
require_once 'config.php';

try {
    // Add columns if not exist
    $pdo->exec("ALTER TABLE supplies ADD COLUMN min_order INT DEFAULT 1");
    $pdo->exec("ALTER TABLE supplies ADD COLUMN max_order INT DEFAULT 0"); // 0 means unlimited? Or maybe just set a high number. Let's say default 10.

    echo "Columns added successfully.";
} catch (PDOException $e) {
    // Ignore duplicate column error
    echo "Info: " . $e->getMessage();
}
?>