<?php
require_once 'config.php';

try {
    echo "<h2>Fixing Role Enum in Users Table...</h2>";

    // 1. Update existing 'staff' to 'user' (if any text data allows, but with strict enum it might be tricky)
    // Sometimes strict SQL mode blocks this. Best to modify column to VARCHAR first, then Update, then back to ENUM.

    // Step A: Convert to VARCHAR to allow flexibility
    $pdo->exec("ALTER TABLE users MODIFY COLUMN role VARCHAR(50)");
    echo "Converted role to VARCHAR.<br>";

    // Step B: Update 'staff' -> 'user'
    $stmt = $pdo->prepare("UPDATE users SET role = 'user' WHERE role = 'staff'");
    $stmt->execute();
    echo "Updated 'staff' records to 'user'. Rows affected: " . $stmt->rowCount() . "<br>";

    // Step C: Convert back to ENUM with 'user'
    $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin','director','supply_head','head','user') DEFAULT 'user'");
    echo "Converted role column to ENUM('admin','director','supply_head','head','user').<br>";

    echo "<h3 style='color:green'>Done! Database Schema Fixed.</h3>";

} catch (Exception $e) {
    echo "<h3 style='color:red'>Error: " . $e->getMessage() . "</h3>";
}
?>