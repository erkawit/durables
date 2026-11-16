<?php
session_start();
require_once 'config.php';
require_once 'config2.php'; // Old database connection

// Access Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("Access Denied: Admin only");
}

echo '<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Users Section - Admin Tool</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: "Sarabun", sans-serif;
            max-width: 1600px;
            margin: 20px auto;
            padding: 20px;
            background: #f8fafc;
        }
        h2 {
            color: #1e293b;
            border-bottom: 3px solid #0ea5e9;
            padding-bottom: 10px;
            margin-top: 30px;
        }
        table {
            width: 100%;
            margin: 20px 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-collapse: collapse;
        }
        th {
            padding: 12px;
            font-weight: 600;
        }
        td {
            padding: 8px;
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
        .info {
            background: #dbeafe;
            border-left: 4px solid #0ea5e9;
            padding: 15px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <h1 style="color: #0ea5e9;">🏢 Update Users Section from Old Database (authen_part)</h1>
    <div class="warning">
        <strong>⚠️ Warning:</strong> This tool will update the "section" field in the users table. Please review all changes carefully before executing.
    </div>';

// Step 1: Fetch section data from old database
echo "<h2>Step 1: Fetching section data from authen database...</h2>";

$sql_fetch = "
    SELECT 
        a.authen_u,
        a.authen_id,
        an.authen_name,
        an.authen_part,
        ap.part_name
    FROM authen a
    LEFT JOIN authen_name an ON a.authen_name_id = an.id
    LEFT JOIN authen_part ap ON an.authen_part = ap.id
    WHERE a.authen_u IS NOT NULL 
      AND ap.part_name IS NOT NULL
    ORDER BY a.authen_id
";

try {
    $stmt = $pdo_old->query($sql_fetch);
    $oldData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<p class='success'>✅ Found " . count($oldData) . " records with section data</p>";

    // Show sample data
    echo "<div class='info'>";
    echo "<h3>Sample Data (First 10 records):</h3>";
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse; background: white;'>";
    echo "<tr style='background: #0ea5e9; color: white;'>
            <th>authen_u (Username)</th>
            <th>authen_name</th>
            <th>authen_part (ID)</th>
            <th>part_name (Section)</th>
          </tr>";

    foreach (array_slice($oldData, 0, 10) as $row) {
        echo "<tr>";
        echo "<td style='font-family: monospace; color: #0ea5e9;'><strong>" . htmlspecialchars($row['authen_u']) . "</strong></td>";
        echo "<td>" . htmlspecialchars($row['authen_name']) . "</td>";
        echo "<td style='text-align: center;'>" . htmlspecialchars($row['authen_part']) . "</td>";
        echo "<td><strong>" . htmlspecialchars($row['part_name']) . "</strong></td>";
        echo "</tr>";
    }
    echo "</table></div><br>";

} catch (PDOException $e) {
    die("<p style='color: red;'>❌ Error fetching old data: " . $e->getMessage() . "</p>");
}

// Step 2: Process and match with users table
echo "<hr><h2>Step 2: Matching with users table...</h2>";

$updates = [];
$notFound = [];
$noChange = [];

foreach ($oldData as $oldUser) {
    $username = $oldUser['authen_u'];
    $newSection = $oldUser['part_name'];

    // Check if user exists in new database
    $stmt = $pdo->prepare("SELECT id, username, section FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($currentUser) {
        // Check if there's actually a change
        $hasChange = ($currentUser['section'] !== $newSection);

        if ($hasChange) {
            // Store update info
            $updates[] = [
                'user_id' => $currentUser['id'],
                'username' => $username,
                'old_section' => $currentUser['section'],
                'new_section' => $newSection,
                'source' => [
                    'authen_part' => $oldUser['authen_part'],
                    'part_name' => $oldUser['part_name']
                ]
            ];
        } else {
            $noChange[] = $username;
        }
    } else {
        $notFound[] = $username;
    }
}

echo "<div class='success'>";
echo "<p>✅ Found " . count($updates) . " users that need section updating</p>";
echo "<p>ℹ️ No changes needed: " . count($noChange) . " users</p>";
echo "<p>⚠️ Not found in users table: " . count($notFound) . " users</p>";
echo "</div>";

if (!empty($notFound)) {
    echo "<details style='margin: 20px 0; padding: 10px; background: #fff3cd; border-radius: 5px;'>";
    echo "<summary style='cursor: pointer; font-weight: bold;'>⚠️ Show usernames not found (" . count($notFound) . ")</summary>";
    echo "<ul style='margin-top: 10px;'>";
    foreach ($notFound as $un) {
        echo "<li>" . htmlspecialchars($un) . "</li>";
    }
    echo "</ul></details>";
}

// Step 3: Show comparison table
if (count($updates) > 0) {
    echo "<hr><h2>Step 3: Comparison Table (Before → After)</h2>";
    echo "<p><strong>⚠️ Please review carefully before updating!</strong></p>";

    echo "<table border='1' cellpadding='5' style='border-collapse: collapse; font-size: 13px; background: white;'>";
    echo "<tr style='background: #0ea5e9; color: white;'>
            <th rowspan='2'>Username</th>
            <th colspan='1' style='background: #ef4444;'>Current Section</th>
            <th colspan='1' style='background: #10b981;'>New Section</th>
            <th rowspan='2'>Source (Old DB)</th>
          </tr>";
    echo "<tr style='background: #0ea5e9; color: white;'>
            <th style='background: #f87171;'>Before</th>
            <th style='background: #4ade80;'>After</th>
          </tr>";

    foreach ($updates as $update) {
        echo "<tr style='background: #fef3c7;'>";
        echo "<td><strong style='font-family: monospace; color: #0ea5e9;'>" . htmlspecialchars($update['username']) . "</strong></td>";

        // Old data
        echo "<td style='background: #fee2e2;'>" . htmlspecialchars($update['old_section'] ?: '(ว่าง)') . "</td>";

        // New data
        echo "<td style='background: #d1fae5; font-weight: bold;'>" . htmlspecialchars($update['new_section']) . "</td>";

        // Source
        echo "<td style='font-size: 11px;'>authen_part: " . htmlspecialchars($update['source']['authen_part']) . " => " . htmlspecialchars($update['source']['part_name']) . "</td>";
        echo "</tr>";
    }

    echo "</table><br>";

    // Step 4: Provide UPDATE options
    echo "<hr><h2>Step 4: Execute Update</h2>";
    echo "<div class='warning'>";
    echo "<p><strong>⚠️ IMPORTANT:</strong> This will update <strong>" . count($updates) . " user records</strong> in the database.</p>";
    echo "<p>Make sure you have reviewed the comparison table above carefully!</p>";
    echo "</div>";

    echo "<form method='POST' onsubmit='return confirm(\"Are you ABSOLUTELY SURE you want to update section for " . count($updates) . " users?\\n\\nThis action CANNOT be undone easily!\\n\\nClick OK to proceed or Cancel to abort.\");'>";
    echo "<input type='hidden' name='execute_update' value='1'>";
    echo "<input type='hidden' name='update_data' value='" . htmlspecialchars(json_encode($updates)) . "'>";
    echo "<button type='submit' style='background: #ef4444; color: white; padding: 15px 30px; font-size: 18px; font-weight: bold; border: none; border-radius: 8px; cursor: pointer; box-shadow: 0 4px 6px rgba(0,0,0,0.1);'>
            ⚠️ EXECUTE UPDATE FOR " . count($updates) . " USERS
          </button>";
    echo "</form>";

} else {
    echo "<hr><h2>✅ No Updates Needed!</h2>";
    echo "<p>All user section data is already synchronized with the old database.</p>";
}

echo "<br><p><a href='users.php' style='color: #0ea5e9; font-weight: bold;'>← Back to Users Page</a></p>";

// Handle UPDATE execution
if (isset($_POST['execute_update']) && $_POST['execute_update'] == '1') {
    echo "<hr><h2 style='color: #10b981;'>🔄 Executing Section Updates...</h2>";

    $updateData = json_decode($_POST['update_data'], true);
    $successCount = 0;
    $errorCount = 0;
    $errors = [];

    foreach ($updateData as $update) {
        try {
            $stmt = $pdo->prepare("
                UPDATE users 
                SET section = ?
                WHERE id = ? AND username = ?
            ");

            $result = $stmt->execute([
                $update['new_section'],
                $update['user_id'],
                $update['username']
            ]);

            if ($result && $stmt->rowCount() > 0) {
                $successCount++;
            } else {
                $errorCount++;
                $errors[] = htmlspecialchars($update['username']) . " (no rows affected)";
            }

        } catch (PDOException $e) {
            $errorCount++;
            $errors[] = htmlspecialchars($update['username']) . ": " . $e->getMessage();
        }
    }

    echo "<hr>";
    echo "<div style='background: " . ($errorCount > 0 ? '#fef3c7' : '#d1fae5') . "; padding: 20px; border-radius: 10px; border-left: 5px solid " . ($errorCount > 0 ? '#f59e0b' : '#10b981') . ";'>";
    echo "<h3 style='color: " . ($errorCount > 0 ? '#d97706' : '#059669') . "; margin: 0 0 10px 0;'>";
    echo "✅ Successfully updated: <strong>$successCount users</strong><br>";
    if ($errorCount > 0) {
        echo "❌ Failed: <strong>$errorCount users</strong>";
    }
    echo "</h3>";

    if (!empty($errors)) {
        echo "<details style='margin-top: 15px;'>";
        echo "<summary style='cursor: pointer; color: #d97706; font-weight: bold;'>Show errors</summary>";
        echo "<ul style='margin-top: 10px;'>";
        foreach ($errors as $error) {
            echo "<li style='color: #dc2626;'>$error</li>";
        }
        echo "</ul></details>";
    }
    echo "</div>";

    echo "<p style='margin-top: 20px;'><a href='users.php' style='background: #0ea5e9; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; font-weight: bold;'>→ Go to Users Page</a></p>";
}

echo '</body></html>';
?>