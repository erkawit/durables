<?php
require_once 'config.php';

try {
    $stmt = $pdo->query("DESCRIBE supplies");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Columns in supplies table: <br>";
    echo "<pre>";
    print_r($columns);
    echo "</pre>";

    if (!in_array('category', $columns)) {
        echo "Column 'category' is MISSING. Attempting to add...<br>";
        try {
            $pdo->exec("ALTER TABLE supplies ADD COLUMN category VARCHAR(100) DEFAULT 'ทั่วไป' AFTER name");
            echo "Successfully added 'category' column.<br>";
        } catch (PDOException $e) {
            echo "Failed to add column: " . $e->getMessage();
        }
    } else {
        echo "Column 'category' ALREADY EXISTS.<br>";
    }

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>