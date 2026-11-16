<?php
require_once 'config.php';

$old_db_host = 'localhost';
$old_db_user = 'root';
$old_db_pass = '';
$old_db_name = 'udtc_all_app';

try {
    $pdo_old = new PDO("mysql:host=$old_db_host;dbname=$old_db_name;charset=utf8", $old_db_user, $old_db_pass);

    echo "<h3>Raw Product Dump (First 5)</h3>";
    $stmt = $pdo_old->query("SELECT * FROM product LIMIT 5");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>";
    print_r($rows);
    echo "</pre>";

    echo "<h3>Count All</h3>";
    $stmt = $pdo_old->query("SELECT COUNT(*) FROM product");
    echo "Total: " . $stmt->fetchColumn();

} catch (Exception $e) {
    echo $e->getMessage();
}
?>