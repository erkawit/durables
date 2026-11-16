<?php
$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'udtc_all_app';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);

    // Check withdraw table structure again specifically for date_accept_step2 and section logic
    echo "<h3>Withdraw Table (Sample)</h3>";
    $stmt = $pdo->query("SELECT * FROM withdraw LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<pre>";
    print_r($row);
    echo "</pre>";

    // Check how to link user section. Maybe 'id_line' matches user? Or is there a section column?
    // Let's check columns again
    echo "<h3>Withdraw Columns</h3>";
    $stmt = $pdo->query("DESCRIBE withdraw");
    print_r($stmt->fetchAll(PDO::FETCH_COLUMN));

} catch (Exception $e) {
    echo $e->getMessage();
}
?>