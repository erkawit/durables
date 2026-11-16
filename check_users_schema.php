<?php
require_once 'config.php';

echo "<h2>Users Table Schema</h2>";
try {
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<pre>";
    print_r($columns);
    echo "</pre>";

    // Check if 'role' exists
    $hasRole = false;
    foreach ($columns as $col) {
        if ($col['Field'] == 'role') {
            $hasRole = true;
            break;
        }
    }

    if (!$hasRole) {
        echo "<h3 style='color:red'>Column 'role' MISSING! Attempting to add...</h3>";
        $pdo->exec("ALTER TABLE users ADD COLUMN role ENUM('admin','director','supply_head','head','user') DEFAULT 'user' AFTER section");
        echo "<h3 style='color:green'>Added column 'role' successfully.</h3>";
    } else {
        echo "<h3 style='color:green'>Column 'role' exists.</h3>";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>