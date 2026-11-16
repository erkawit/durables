<?php
session_start();
require_once 'config.php';

// Only authenticated admin can run emergency restore
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("Access denied: Admin authentication required.");
}

try {
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    $pdo->exec("TRUNCATE TABLE users");
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");

    $hash = password_hash('admin', PASSWORD_DEFAULT);
    $sql = "INSERT INTO users (username, password, prefix, firstname, lastname, position, level, section, role, status) VALUES 
            ('admin', ?, 'นาย', 'Administrator', 'System', 1, 1, 'IT', 'admin', 'active')";
    $pdo->prepare($sql)->execute([$hash]);
    echo "Emergency Admin Restored.\nUsername: admin\nPassword: admin";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
