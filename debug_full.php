<?php
$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'udtc_all_app';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);

    // Check tables
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables: " . implode(", ", $tables) . "\n\n";

    // Check 1 row of product
    $stmt = $pdo->query("SELECT * FROM product WHERE status_delete = '0' LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "PRODUCT ROW:\n";
    print_r($row);

    // Check 1 row of product_insert
    $stmt = $pdo->query("SELECT * FROM product_insert LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "PRODUCT_INSERT ROW:\n";
    print_r($row);

} catch (Exception $e) {
    echo $e->getMessage();
}
?>