<?php
$old_db_host = 'localhost';
$old_db_user = 'root';
$old_db_pass = '';
$old_db_name = 'udtc_all_app';
try {
    $pdo_old = new PDO("mysql:host=$old_db_host;dbname=$old_db_name;charset=utf8", $old_db_user, $old_db_pass);
    $stmt = $pdo_old->query("SELECT * FROM withdraw LIMIT 1");
    print_r($stmt->fetch(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo $e->getMessage();
}
?>