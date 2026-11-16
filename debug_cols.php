<?php
require_once 'config.php';
function desc($pdo, $table)
{
    echo "TABLE: $table\n";
    try {
        $stmt = $pdo->query("DESCRIBE $table");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            echo $row['Field'] . "\n";
        }
    } catch (Exception $e) {
        echo $e->getMessage() . "\n";
    }
    echo "----------------\n";
}

desc($pdo, 'users');
desc($pdo, 'authen_position');
desc($pdo, 'authen_l_position');
?>