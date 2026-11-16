<?php
session_start();
require_once 'config.php';

// Access Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("Access Denied: Admin only");
}

echo '<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Update User Roles</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: "Sarabun", sans-serif;
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background: #f8fafc;
        }
        h2 {
            color: #1e293b;
            border-bottom: 3px solid #0ea5e9;
            padding-bottom: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        th {
            background: #0ea5e9;
            color: white;
        }
        .warning {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 15px;
            margin: 20px 0;
        }
        .success {
            background: #d1fae5;
            border-left: 4px solid #10b981;
            padding: 15px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <h1 style="color: #0ea5e9;">🔐 Update User Roles to "user" (ผู้ใช้งานทั่วไป)</h1>
    <div class="warning">
        <strong>⚠️ Warning:</strong> This will change ALL user roles to "user" except admin accounts.
    </div>';

// Fetch users that will be updated
$sql = "SELECT id, username, role FROM users WHERE username != 'admin' AND role != 'user'";
$usersToUpdate = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

echo "<h2>Users to be updated (" . count($usersToUpdate) . "):</h2>";

if (count($usersToUpdate) > 0) {
    echo "<table>";
    echo "<tr><th>ID</th><th>Username</th><th>Current Role</th><th>New Role</th></tr>";

    foreach ($usersToUpdate as $user) {
        $roleNames = [
            'director' => 'ผู้อำนวยการฯ',
            'supply_head' => 'หัวหน้าส่วนคลัง',
            'head' => 'หัวหน้าส่วนงาน',
        ];

        $currentRoleName = $roleNames[$user['role']] ?? $user['role'];

        echo "<tr>";
        echo "<td>{$user['id']}</td>";
        echo "<td style='font-family: monospace; color: #0ea5e9;'><strong>{$user['username']}</strong></td>";
        echo "<td style='background: #fee2e2;'>{$currentRoleName} ({$user['role']})</td>";
        echo "<td style='background: #d1fae5; font-weight: bold;'>ผู้ใช้งานทั่วไป (user)</td>";
        echo "</tr>";
    }

    echo "</table>";

    echo "<br>";
    echo "<form method='POST' onsubmit='return confirm(\"Are you sure you want to update " . count($usersToUpdate) . " users to role = user?\");'>";
    echo "<input type='hidden' name='execute_update' value='1'>";
    echo "<button type='submit' style='background: #ef4444; color: white; padding: 15px 30px; font-size: 18px; font-weight: bold; border: none; border-radius: 8px; cursor: pointer; box-shadow: 0 4px 6px rgba(0,0,0,0.1);'>";
    echo "⚠️ UPDATE " . count($usersToUpdate) . " USERS TO \"user\" ROLE";
    echo "</button>";
    echo "</form>";

} else {
    echo "<div class='success'>";
    echo "<p>✅ All users already have 'user' role (except admin)!</p>";
    echo "</div>";
}

echo "<br><p><a href='users.php' style='color: #0ea5e9; font-weight: bold;'>← Back to Users Page</a></p>";

// Handle UPDATE execution
if (isset($_POST['execute_update']) && $_POST['execute_update'] == '1') {
    echo "<hr><h2 style='color: #10b981;'>🔄 Executing Role Updates...</h2>";

    try {
        $stmt = $pdo->prepare("UPDATE users SET role = 'user' WHERE username != 'admin' AND role != 'user'");
        $stmt->execute();
        $count = $stmt->rowCount();

        echo "<div class='success'>";
        echo "<h3 style='color: #059669; margin: 0;'>✅ Successfully updated $count users to role = 'user'</h3>";
        echo "</div>";

        echo "<p style='margin-top: 20px;'><a href='users.php' style='background: #0ea5e9; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; font-weight: bold;'>→ Go to Users Page</a></p>";

    } catch (PDOException $e) {
        echo "<div class='warning'>";
        echo "<p style='color: #dc2626;'>❌ Error: " . $e->getMessage() . "</p>";
        echo "</div>";
    }
}

echo '</body></html>';
?>