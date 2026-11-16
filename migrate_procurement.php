<?php
require_once 'config.php';

try {
    // 1. Procurements Header
    $pdo->exec("CREATE TABLE IF NOT EXISTS procurements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        receipt_no VARCHAR(50),
        supplier VARCHAR(100),
        procurement_date DATETIME DEFAULT CURRENT_TIMESTAMP,
        user_id INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    // 2. Procurement Items
    $pdo->exec("CREATE TABLE IF NOT EXISTS procurement_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        procurement_id INT,
        supply_id INT,
        qty INT,
        cost_per_unit DECIMAL(10,2) DEFAULT 0.00,
        FOREIGN KEY (procurement_id) REFERENCES procurements(id) ON DELETE CASCADE
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    echo "Tables created successfully.";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>