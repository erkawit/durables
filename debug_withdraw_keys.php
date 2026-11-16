<?php
$old_db_host = 'localhost';
$old_db_user = 'root';
$old_db_pass = '';
$old_db_name = 'udtc_all_app';
$pdo = new PDO("mysql:host=$old_db_host;dbname=$old_db_name;charset=utf8", $old_db_user, $old_db_pass);
$row = $pdo->query("SELECT * FROM withdraw LIMIT 1")->fetch(PDO::FETCH_ASSOC);
foreach ($row as $k => $v) {
    echo "$k\n";
}
?>