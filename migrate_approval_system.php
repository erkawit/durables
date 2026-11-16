<?php
require_once 'config.php';

try {
    // 1. Create Notifications Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        message TEXT NOT NULL,
        link VARCHAR(255) DEFAULT '',
        is_read TINYINT(1) DEFAULT 0,
        type VARCHAR(50) DEFAULT 'info',
        related_id INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    echo "Created table 'notifications'.<br>";

    // 2. Add columns to request_items for tracking edits/deletions
    // Only add if not exists
    $cols = $pdo->query("DESCRIBE request_items")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('edit_reason', $cols)) {
        $pdo->exec("ALTER TABLE request_items ADD COLUMN edit_reason TEXT DEFAULT NULL AFTER approved_qty");
        echo "Added 'edit_reason' to request_items.<br>";
    }

    if (!in_array('is_deleted', $cols)) {
        $pdo->exec("ALTER TABLE request_items ADD COLUMN is_deleted TINYINT(1) DEFAULT 0 AFTER edit_reason");
        echo "Added 'is_deleted' to request_items.<br>";
    }

    if (!in_array('original_qty', $cols)) {
        $pdo->exec("ALTER TABLE request_items ADD COLUMN original_qty INT DEFAULT NULL AFTER qty");
        // Update original_qty = qty for existing records
        $pdo->exec("UPDATE request_items SET original_qty = qty WHERE original_qty IS NULL");
        echo "Added 'original_qty' to request_items.<br>";
    }

    echo "<h3>Migration Completed!</h3>";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>