<?php
require_once 'config.php';
$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'udtc_all_app';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);

    $stmt = $pdo->query("SELECT COUNT(*) FROM product WHERE product_volume > 0");
    echo "Product with volume > 0: " . $stmt->fetchColumn() . "<br>";

    $stmt = $pdo->query("SELECT COUNT(*) FROM product WHERE product_volume = 0");
    echo "Product with volume = 0: " . $stmt->fetchColumn() . "<br>";

    // Check product_insert summation
    echo "<h3>Sum of product_insert by code (Top 5)</h3>";
    $sql = "SELECT product_code, SUM(product_volume) as total_inserted FROM product_insert GROUP BY product_code LIMIT 5";
    $stmt = $pdo->query($sql);
    echo "<pre>";
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
    echo "</pre>";

} catch (Exception $e) {
    echo $e->getMessage();
}
?>