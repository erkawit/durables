<?php
require_once 'config.php';

$old_db_host = 'localhost';
$old_db_user = 'root';
$old_db_pass = '';
$old_db_name = 'udtc_all_app';

try {
    // Connect to old DB
    $pdo_old = new PDO("mysql:host=$old_db_host;dbname=$old_db_name;charset=utf8", $old_db_user, $old_db_pass);
    $pdo_old->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Connected to old database.<br>";

    // Clear existing data in supplies (Disable FK Check)
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $pdo->exec("TRUNCATE TABLE supplies");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "Cleared old data in 'supplies' table.<br>";

    // Fetch data from old DB
    $sql = "
        SELECT 
            p.product_code,
            p.product_name,
            p.product_img,
            p.type_name as category,
            COALESCE((SELECT SUM(product_volume) FROM product_insert WHERE product_code = p.product_code), 0) as qty,
            p.min_withdraw,
            p.max_withdraw,
            (
                SELECT tv.type_volume 
                FROM product_insert pi 
                LEFT JOIN type_volume tv ON pi.type_volume = tv.id 
                WHERE pi.product_code = p.product_code 
                ORDER BY pi.id DESC LIMIT 1
            ) as unit
        FROM product p
        WHERE p.status_delete = '0' OR p.status_delete IS NULL OR p.status_delete = ''
    ";

    $stmt = $pdo_old->query($sql);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Found " . count($products) . " products in old DB.<br>";

    // Insert into New DB
    $inserted = 0;
    $insert = $pdo->prepare("INSERT INTO supplies (code, name, image, category, qty, unit, min_stock, max_request, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')");

    foreach ($products as $prod) {
        $code = $prod['product_code'];
        $name = $prod['product_name'];
        $image = $prod['product_img'];

        // Adjust Image URL
        if (!empty($image) && strpos($image, 'http') === false) {
            // Correct path based on directory check: data/img/product_img/
            $image = 'http://localhost/all-app/data/img/product_img/' . $image;
        }

        $category = !empty($prod['category']) ? $prod['category'] : 'ทั่วไป';
        $qty = (int) $prod['qty'];
        $unit = !empty($prod['unit']) ? $prod['unit'] : 'ชิ้น';
        $min = (int) $prod['min_withdraw'] > 0 ? (int) $prod['min_withdraw'] : 1;
        $max = (int) $prod['max_withdraw'] > 0 ? (int) $prod['max_withdraw'] : 5;

        // Skip invalid rows
        if (empty($code))
            continue;

        try {
            $insert->execute([$code, $name, $image, $category, $qty, $unit, $min, $max]);
            $inserted++;
        } catch (PDOException $e) {
            // echo "Skipped duplicate/error: $code <br>";
        }
    }

    echo "Migration completed. Successfully imported $inserted items.<br>";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>