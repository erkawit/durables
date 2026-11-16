<?php
require_once 'config.php';

function getColInfo($pdo, $table)
{
    try {
        $stmt = $pdo->query("DESCRIBE $table");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return "Table $table not found";
    }
}

echo "<h3>Table: requests</h3>";
print_r(getColInfo($pdo, 'requests'));

echo "<h3>Table: request_items</h3>";
print_r(getColInfo($pdo, 'request_items'));

echo "<h3>Table: notifications (Check if exists)</h3>";
print_r(getColInfo($pdo, 'notifications'));
?>