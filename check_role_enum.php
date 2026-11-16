<?php
require_once 'config.php';
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Current Type for 'role': " . $result['Type'] . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>