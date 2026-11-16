<?php
require_once 'config.php';
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $currentType = $row['Type'];
    echo "Current Type: $currentType<br>";

    if (strpos($currentType, 'supply_officer') === false) {
        // Need to update
        // We include 'admin', 'director', 'supply_head', 'head', 'user' and the new 'supply_officer'
        // Also keeping 'staff' just in case it was there, although code suggests 'user' is the main one.
        // Code in users.php had: $valid_roles = ['admin', 'director', 'supply_head', 'head', 'user', 'supply_officer'];

        $newDefinition = "ENUM('admin', 'director', 'supply_head', 'head', 'user', 'supply_officer')";

        // IMPORTANT: Ensure we don't lose data. ENUM changes are generally safe if we are just adding.
        $sql = "ALTER TABLE users MODIFY COLUMN role $newDefinition DEFAULT 'user'";
        $pdo->exec($sql);
        echo "<b>Success!</b> Updated role column to: $newDefinition";
    } else {
        echo "<b>OK!</b> 'supply_officer' is already in the ENUM.";
    }

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>