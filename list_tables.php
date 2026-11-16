<?php
$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'udtc_all_app';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t)
        echo $t . "\n";
} catch (Exception $e) {
    echo $e->getMessage();
}
?>