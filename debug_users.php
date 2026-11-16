<?php
require_once 'config.php';
try {
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo implode(", ", $columns);
} catch (Exception $e) {
    echo $e->getMessage();
}
?>