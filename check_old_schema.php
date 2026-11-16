<?php
$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'udtc_all_app';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "<h3>Table: product</h3>";
    $stmt = $pdo->query("DESCRIBE product");
    echo "<pre>";
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
    echo "</pre>";

    echo "<h3>Table: product_insert</h3>";
    $stmt = $pdo->query("DESCRIBE product_insert");
    echo "<pre>";
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
    echo "</pre>";

    // Also fetch a few rows to see data
    echo "<h3>Sample Data (Joined)</h3>";
    $sql = "SELECT p.*, pi.* FROM product p LEFT JOIN product_insert pi ON p.product_id = pi.product_id_ref LIMIT 5";
    $stmt = $pdo->query($sql);
    echo "<pre>";
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
    echo "</pre>";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>