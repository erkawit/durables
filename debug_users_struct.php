<?php
require_once 'config.php';
try {
    echo "USERS Table:\n";
    $stmt = $pdo->query("DESCRIBE users");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

    echo "\nPositions Table (authen_position):\n";
    try {
        $stmt = $pdo->query("SELECT * FROM authen_position");
        print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (PDOException $e) {
        echo "Error: " . $e->getMessage();
    }

    echo "\nLevel Positions Table (authen_l_position):\n";
    try {
        $stmt = $pdo->query("SELECT * FROM authen_l_position");
        print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (PDOException $e) {
        echo "Error: " . $e->getMessage();
    }

    echo "\nExisting Roles:\n";
    $stmt = $pdo->query("SELECT DISTINCT role FROM users");
    print_r($stmt->fetchAll(PDO::FETCH_COLUMN));

} catch (Exception $e) {
    echo $e->getMessage();
}
?>