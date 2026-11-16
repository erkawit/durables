<?php
require_once 'config.php';
try {
    $stmt = $pdo->query("SELECT * FROM system_logs LIMIT 1");
    print_r($stmt->fetch(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>