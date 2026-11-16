<?php
require_once 'config.php';
try {
    $stmt = $pdo->query("DESCRIBE system_logs");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>