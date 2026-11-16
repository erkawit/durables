<?php
$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'udtc_all_app';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);

    echo "<h3>Product Table (First 5)</h3>";
    $sql = "SELECT product_code, product_name, product_volume, type_name FROM product WHERE status_delete = '0' LIMIT 5";
    $stmt = $pdo->query($sql);
    echo "<pre>";
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
    echo "</pre>";

    echo "<h3>Product Insert Table (First 5)</h3>";
    $sql = "SELECT product_code, product_volume, type_volume, type_name FROM product_insert LIMIT 5";
    $stmt = $pdo->query($sql);
    echo "<pre>";
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
    echo "</pre>";

} catch (Exception $e) {
    echo $e->getMessage();
}
?>