<?php
require_once 'config.php';

try {
    // Add category column
    $pdo->exec("ALTER TABLE supplies ADD COLUMN category VARCHAR(100) DEFAULT 'ทั่วไป' AFTER name");
    echo "Added category column.<br>";

    // Update some dummy data if exists (or strictly, just ensuring the column exists is enough for now)
    // Let's rely on the user to manage data, but for the filters to work, we need data with categories.
    // If table is empty, this does nothing.

} catch (PDOException $e) {
    if (strpos($e->getMessage(), "Duplicate column name") !== false) {
        echo "Column 'category' already exists.<br>";
    } else {
        echo "Error: " . $e->getMessage();
    }
}
?>