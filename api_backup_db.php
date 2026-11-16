<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

// Only Admin can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
    exit;
}

try {
    $backupDir = __DIR__ . '/backups';
    
    // Check if folder exists, if not create it
    if (!is_dir($backupDir)) {
        if (!mkdir($backupDir, 0777, true)) {
            throw new Exception("Failed to create backup directory.");
        }
    } else {
        // If folder exists, delete old files
        $files = glob($backupDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    $dateStr = date('Y-m-d');
    $fileName = "durables_db_" . $dateStr . ".sql";
    $filePath = $backupDir . '/' . $fileName;

    // Database credentials from config.php or hardcoded for local XAMPP
    // We can get them from PDO connection if possible, but PDO doesn't expose raw credentials easily.
    // However, we know local settings from api_check_data.php
    $host = 'localhost';
    $user = 'root';
    $pass = '';
    $dbname = 'durables_db';
    
    $mysqldumpPath = '/Applications/XAMPP/bin/mysqldump'; // Mac XAMPP path

    // Check if mysqldump exists
    if (!file_exists($mysqldumpPath)) {
        // Fallback for Windows or other environments
        $mysqldumpPath = 'mysqldump'; 
    }

    $command = "$mysqldumpPath -u $user -h $host $dbname > \"$filePath\" 2>&1";
    exec($command, $output, $returnVar);

    if ($returnVar !== 0) {
        // If mysqldump fails, let's try a pure PHP approach
        $pdo->setAttribute(PDO::ATTR_ORACLE_NULLS, PDO::NULL_NATURAL);
        
        $sqlScript = "";
        $tables = [];
        $stmt = $pdo->query("SHOW TABLES");
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }

        foreach ($tables as $table) {
            // Get create table
            $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
            $row = $stmt->fetch(PDO::FETCH_NUM);
            $sqlScript .= "\n\n" . $row[1] . ";\n\n";
            
            // Get data
            $stmt = $pdo->query("SELECT * FROM `$table`");
            $columnCount = $stmt->columnCount();

            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                $sqlScript .= "INSERT INTO `$table` VALUES(";
                for ($j = 0; $j < $columnCount; $j++) {
                    $row[$j] = $row[$j];
                    if (isset($row[$j])) {
                        $sqlScript .= "'" . addslashes($row[$j]) . "'";
                    } else {
                        $sqlScript .= "''";
                    }
                    if ($j < ($columnCount - 1)) {
                        $sqlScript .= ',';
                    }
                }
                $sqlScript .= ");\n";
            }
        }

        if (file_put_contents($filePath, $sqlScript) === false) {
             throw new Exception("Failed to write backup file (pure PHP fallback).");
        }
    }

    echo json_encode([
        'status' => 'success', 
        'message' => 'Database backed up successfully.', 
        'file' => $fileName,
        'path' => '/backups/' . $fileName
    ]);

} catch (Exception $e) {
    echo json_encode([
        'status' => 'error', 
        'message' => 'Backup failed: ' . $e->getMessage()
    ]);
}
?>
