<?php
require 'config.php';
$stmt = $pdo->query("SHOW COLUMNS FROM product_type");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
