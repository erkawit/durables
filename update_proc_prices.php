<?php
require 'config.php';
try {
    // 1. Update price in procurement_items from supplies table if price = 0
    $pdo->exec("
        UPDATE procurement_items pi
        JOIN supplies s ON pi.supply_id = s.id
        SET pi.price = s.price
        WHERE pi.price = 0 OR pi.price IS NULL
    ");
    
    // 2. Calculate price_all = qty * price
    $pdo->exec("
        UPDATE procurement_items
        SET price_all = qty * price
        WHERE price_all = 0 OR price_all IS NULL OR price_all != qty * price
    ");
    echo "Successfully updated price and price_all in procurement_items.";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
