<?php
$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'udtc_all_app';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);

    $stmt = $pdo->query("SELECT * FROM product LIMIT 1");
    echo "PRODUCT COLUMNS:\n";
    print_r(array_keys($stmt->fetch(PDO::FETCH_ASSOC)));

    $stmt = $pdo->query("SELECT * FROM product_insert LIMIT 1");
    echo "PRODUCT_INSERT COLUMNS:\n";
    print_r(array_keys($stmt->fetch(PDO::FETCH_ASSOC)));

} catch (Exception $e) {
    echo $e->getMessage();
}
?>