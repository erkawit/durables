<?php
require_once 'config.php';

echo "<h2>Checking Database Structure</h2>";

// Check 'requests' table for reason column
echo "<h3>Table: requests</h3>";
$stmt = $pdo->query("DESCRIBE requests");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<table border='1'><tr><th>Field</th><th>Type</th></tr>";
$hasReason = false;
foreach ($columns as $col) {
    echo "<tr><td>{$col['Field']}</td><td>{$col['Type']}</td></tr>";
    if ($col['Field'] == 'reason' || $col['Field'] == 'note')
        $hasReason = true;
}
echo "</table>";

if (!$hasReason) {
    echo "<p style='color:red'>- Missing 'reason' column in requests table.</p>";
} else {
    echo "<p style='color:green'>- 'reason' column exists.</p>";
}

// Check 'supplies' table for limit columns
echo "<h3>Table: supplies</h3>";
$stmt = $pdo->query("DESCRIBE supplies");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<table border='1'><tr><th>Field</th><th>Type</th></tr>";
$hasLimits = false;
foreach ($columns as $col) {
    echo "<tr><td>{$col['Field']}</td><td>{$col['Type']}</td></tr>";
    if ($col['Field'] == 'min_qty' || $col['Field'] == 'max_qty')
        $hasLimits = true;
}
echo "</table>";

if (!$hasLimits) {
    echo "<p style='color:red'>- Missing limit columns (min_qty, max_qty) in supplies table.</p>";
} else {
    echo "<p style='color:green'>- Limit columns exist.</p>";
}
?>