<?php
require_once 'config.php';
try {
    echo "Tables in durables_db:\n";
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    print_r($tables);

    // Check if authen_front_name exists
    if (in_array('authen_front_name', $tables)) {
        echo "\n\nauthen_front_name EXISTS\n";
    } else {
        echo "\n\nauthen_front_name DOES NOT EXIST - need to query from old DB\n";

        // Try old DB
        $pdo_old = new PDO("mysql:host=localhost;dbname=udtc_all_app;charset=utf8", 'root', '');
        $stmt = $pdo_old->query("SELECT * FROM authen_front_name LIMIT 10");
        echo "\nData from old DB:\n";
        print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>