<?php
require_once 'config2.php';

echo "<h2>Checking tables in udtc_all_app database...</h2>";

// List all tables
$tables = $pdo_old->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

echo "<h3>Available Tables:</h3>";
echo "<ul>";
foreach ($tables as $table) {
    echo "<li>$table</li>";
}
echo "</ul>";

// Check authen_name structure
echo "<hr><h3>Structure of authen_name table:</h3>";
$columns = $pdo_old->query("DESCRIBE authen_name")->fetchAll(PDO::FETCH_ASSOC);
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr>";
foreach ($columns as $col) {
    echo "<tr>";
    echo "<td>{$col['Field']}</td>";
    echo "<td>{$col['Type']}</td>";
    echo "<td>{$col['Null']}</td>";
    echo "<td>{$col['Key']}</td>";
    echo "</tr>";
}
echo "</table>";

// Check authen structure
echo "<hr><h3>Structure of authen table:</h3>";
$columns = $pdo_old->query("DESCRIBE authen")->fetchAll(PDO::FETCH_ASSOC);
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr>";
foreach ($columns as $col) {
    echo "<tr>";
    echo "<td>{$col['Field']}</td>";
    echo "<td>{$col['Type']}</td>";
    echo "<td>{$col['Null']}</td>";
    echo "<td>{$col['Key']}</td>";
    echo "</tr>";
}
echo "</table>";

// Sample data from authen_name
echo "<hr><h3>Sample data from authen_name (first 10 rows):</h3>";
$sample = $pdo_old->query("SELECT * FROM authen_name LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
echo "<table border='1' cellpadding='5'>";
if (!empty($sample)) {
    echo "<tr>";
    foreach (array_keys($sample[0]) as $key) {
        echo "<th>$key</th>";
    }
    echo "</tr>";

    foreach ($sample as $row) {
        echo "<tr>";
        foreach ($row as $value) {
            echo "<td>" . htmlspecialchars($value) . "</td>";
        }
        echo "</tr>";
    }
}
echo "</table>";

// Sample data from authen
echo "<hr><h3>Sample data from authen (first 10 rows):</h3>";
$sample = $pdo_old->query("SELECT * FROM authen LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
echo "<table border='1' cellpadding='5'>";
if (!empty($sample)) {
    echo "<tr>";
    foreach (array_keys($sample[0]) as $key) {
        echo "<th>$key</th>";
    }
    echo "</tr>";

    foreach ($sample as $row) {
        echo "<tr>";
        foreach ($row as $value) {
            echo "<td>" . htmlspecialchars($value) . "</td>";
        }
        echo "</tr>";
    }
}
echo "</table>";
?>