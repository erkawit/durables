<?php
require_once 'config.php';
try {
    echo "AUTHEN TABLE STRUCTURE:\n";
    $stmt = $pdo->query("DESCRIBE authen");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

    echo "\nAUTHEN SAMPLE DATA:\n";
    $stmt = $pdo->query("SELECT * FROM authen LIMIT 5");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>