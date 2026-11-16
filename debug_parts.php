<?php
require_once 'config.php';
try {
    echo "authen_part structure:\n";
    $stmt = $pdo->query("DESCRIBE authen_part");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

    echo "\n\nauthen_part sample data:\n";
    $stmt = $pdo->query("SELECT * FROM authen_part LIMIT 5");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

    echo "\n\nauthen_front_name structure:\n";
    $stmt = $pdo->query("DESCRIBE authen_front_name");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

    echo "\n\nauthen_front_name sample data:\n";
    $stmt = $pdo->query("SELECT * FROM authen_front_name LIMIT 10");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>