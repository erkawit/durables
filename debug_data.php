<?php
require_once 'config.php';

try {
    // Check local supplies
    $stmt = $pdo->query("SELECT COUNT(*) FROM supplies");
    echo "Current rows in 'supplies' table: " . $stmt->fetchColumn() . "<br>";

    // Check old db again to debug why import might have failed
    $old_db_host = 'localhost';
    $old_db_user = 'root';
    $old_db_pass = '';
    $old_db_name = 'udtc_all_app';

    $pdo_old = new PDO("mysql:host=$old_db_host;dbname=$old_db_name;charset=utf8", $old_db_user, $old_db_pass);
    $pdo_old->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Count products in old db
    $stmtOld = $pdo_old->query("SELECT COUNT(*) FROM product WHERE status_delete != 'Y'");
    echo "Rows in old 'product' table (active): " . $stmtOld->fetchColumn() . "<br>";

    // Test the specific query used in migration
    $sql = "
        SELECT 
            p.product_code,
            p.product_name
        FROM product p
        WHERE p.status_delete != 'Y'
        LIMIT 5
    ";
    echo "Sample data from old DB:<br><pre>";
    print_r($pdo_old->query($sql)->fetchAll(PDO::FETCH_ASSOC));
    echo "</pre>";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>