<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

// Only Admin can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access', 'details' => 'Role is not admin.']);
    exit;
}

$step = $_GET['step'] ?? '';

// We need a connection to the external database `udtc_all_app` to check things.
try {
    $host = 'localhost';
    $db_user = 'root';
    $db_pass = ''; // Based on common local setup, modify if different
    $db_name_ext = 'udtc_all_app';
    
    // We try to connect to the external DB
    $ext_pdo = new PDO("mysql:host=$host;dbname=$db_name_ext;charset=utf8", $db_user, $db_pass);
    $ext_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
} catch (PDOException $e) {
    if ($step === 'db_conn') {
        echo json_encode(['status' => 'error', 'message' => "Cannot connect to database '$db_name_ext'", 'details' => $e->getMessage()]);
        exit;
    }
    // If it fails on other steps, we return generic error
    echo json_encode(['status' => 'error', 'message' => "Database connection to '$db_name_ext' failed.", 'details' => $e->getMessage()]);
    exit;
}

try {
    if ($step === 'db_conn') {
        // Since PDO instantiated successfully, connection is OK
        echo json_encode(['status' => 'success', 'message' => "Connected to database '$db_name_ext' successfully.", 'details' => "Host: $host, Database: $db_name_ext"]);
        exit;
    }
    
    if ($step === 'tbl_product') {
        // Check if table `product` exists in `udtc_all_app`
        $stmt = $ext_pdo->query("SHOW TABLES LIKE 'product'");
        if ($stmt->rowCount() > 0) {
            // Count rows
            $countStmt = $ext_pdo->query("SELECT COUNT(*) FROM product");
            $countExt = $countStmt->fetchColumn();
            
            // Check local count
            $countLocalStmt = $pdo->query("SELECT COUNT(*) FROM supplies"); // Assuming local `supplies` maps to `product` or we have `product`
            // Check if local has `product` table
            $localProductCheck = $pdo->query("SHOW TABLES LIKE 'product'");
            if ($localProductCheck->rowCount() > 0) {
                 $countLocalStmt = $pdo->query("SELECT COUNT(*) FROM product");
                 $countLocal = $countLocalStmt->fetchColumn();
                 echo json_encode([
                     'status' => 'success', 
                     'message' => "Table 'product' exists in both databases.", 
                     'details' => "External rows: $countExt, Local rows: $countLocal."
                 ]);
            } else {
                 echo json_encode([
                     'status' => 'error', 
                     'message' => "Local table 'product' does not exist.", 
                     'details' => "External rows: $countExt. Structure mismatch."
                 ]);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => "Table 'product' does not exist in external database.", 'details' => "Schema might be outdated."]);
        }
        exit;
    }
    
    if ($step === 'tbl_type_volume') {
        // Check if table `type_volume` exists
        $stmt = $ext_pdo->query("SHOW TABLES LIKE 'type_volume'");
        if ($stmt->rowCount() > 0) {
            $countExt = $ext_pdo->query("SELECT COUNT(*) FROM type_volume")->fetchColumn();
            
            $localCheck = $pdo->query("SHOW TABLES LIKE 'type_volume'");
            if ($localCheck->rowCount() > 0) {
                 $countLocal = $pdo->query("SELECT COUNT(*) FROM type_volume")->fetchColumn();
                 echo json_encode([
                     'status' => 'success', 
                     'message' => "Table 'type_volume' exists and verified.", 
                     'details' => "Found $countExt rows externally, $countLocal rows locally."
                 ]);
            } else {
                 echo json_encode([
                     'status' => 'error', 
                     'message' => "Table 'type_volume' missing locally.", 
                     'details' => "Cannot map volume types."
                 ]);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => "Table 'type_volume' missing externally.", 'details' => "Source data is incomplete."]);
        }
        exit;
    }
    
    if ($step === 'data_product') {
        // Balance Data Verification
        // Example logic: checking if any products have negative balance, or comparing sums
        $stmt = $ext_pdo->query("SHOW TABLES LIKE 'product'");
        if ($stmt->rowCount() > 0) {
            $sumExt = $ext_pdo->query("SELECT SUM(product_num) FROM product")->fetchColumn();
            
            $localCheck = $pdo->query("SHOW TABLES LIKE 'product'");
            if ($localCheck->rowCount() > 0) {
                $sumLocal = $pdo->query("SELECT SUM(product_num) FROM product")->fetchColumn();
                
                if ($sumExt == $sumLocal) {
                    echo json_encode([
                        'status' => 'success', 
                        'message' => "Balance data is synchronized.", 
                        'details' => "Total items external: $sumExt, Total items local: $sumLocal."
                    ]);
                } else {
                    echo json_encode([
                        'status' => 'error', 
                        'message' => "Balance data mismatch detected.", 
                        'details' => "External total: $sumExt, Local total: $sumLocal. Sync required."
                    ]);
                }
            } else {
                echo json_encode([
                    'status' => 'error', 
                    'message' => "Cannot verify balances.", 
                    'details' => "Local 'product' table missing."
                ]);
            }
        } else {
             echo json_encode(['status' => 'error', 'message' => "Cannot verify balances.", 'details' => "External 'product' table missing."]);
        }
        exit;
    }
    
    if ($step === 'auth_users') {
        // User Authentication Match
        $stmt = $ext_pdo->query("SHOW TABLES LIKE 'authen'");
        if ($stmt->rowCount() > 0) {
            $countExt = $ext_pdo->query("SELECT COUNT(*) FROM authen")->fetchColumn();
            $countLocal = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            
            echo json_encode([
                'status' => 'success', 
                'message' => "User data checked.", 
                'details' => "External 'authen' has $countExt users, Local 'users' has $countLocal users. Differences may exist due to role mappings."
            ]);
        } else {
             echo json_encode(['status' => 'error', 'message' => "Cannot verify user data.", 'details' => "External 'authen' table missing."]);
        }
        exit;
    }
    
    // Fallback
    echo json_encode(['status' => 'error', 'message' => 'Unknown step requested.', 'details' => "Step id: $step is not recognized."]);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'An error occurred during verification.', 'details' => $e->getMessage()]);
}
?>
