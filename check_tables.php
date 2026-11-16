<?php
require_once 'config.php';

// ตรวจสอบโครงสร้างตาราง authen
echo "=== โครงสร้างตาราง authen ===\n";
$stmt = $pdo->query("DESCRIBE authen");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $row['Field'] . " (" . $row['Type'] . ")\n";
}

echo "\n=== โครงสร้างตาราง authen_name ===\n";
$stmt = $pdo->query("DESCRIBE authen_name");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $row['Field'] . " (" . $row['Type'] . ")\n";
}

echo "\n=== โครงสร้างตาราง users ===\n";
$stmt = $pdo->query("DESCRIBE users");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $row['Field'] . " (" . $row['Type'] . ")\n";
}

echo "\n=== ตัวอย่างข้อมูล authen (5 rows) ===\n";
$stmt = $pdo->query("SELECT * FROM authen LIMIT 5");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
    echo "\n";
}

echo "\n=== ตัวอย่างข้อมูล authen_name (all rows) ===\n";
$stmt = $pdo->query("SELECT * FROM authen_name");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
    echo "\n";
}

echo "\n=== ตัวอย่างข้อมูล users (5 rows) ===\n";
$stmt = $pdo->query("SELECT id, username, prefix, firstname, lastname FROM users LIMIT 5");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
    echo "\n";
}

echo "\n=== ตรวจสอบการ JOIN ===\n";
echo "Checking authen LEFT JOIN authen_name...\n";
$stmt = $pdo->query("
    SELECT 
        a.authen_u,
        a.authen_name,
        a.authen_l_name,
        n.authen_name as prefix_name
    FROM authen a
    LEFT JOIN authen_name n ON a.authen_name = n.id
    LIMIT 5
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
    echo "\n";
}
?>