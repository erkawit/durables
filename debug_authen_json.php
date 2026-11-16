<?php
require_once 'config.php';
try {
    $stmt = $pdo->query("SELECT * FROM authen LIMIT 1");
    // Use json_encode for clear output
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>