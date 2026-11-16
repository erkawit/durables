<?php
$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'udtc_all_app';
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    echo "<h3>stationary</h3>";
    $stmt = $pdo->query("DESCRIBE stationary");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo $e->getMessage();
}
?>