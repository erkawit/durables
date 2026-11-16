<?php
$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'udtc_all_app';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);

    echo "<h3>type_volume Table</h3>";
    $stmt = $pdo->query("SELECT * FROM type_volume");
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
    print_r($units);

} catch (Exception $e) {
    echo $e->getMessage();
}
?>