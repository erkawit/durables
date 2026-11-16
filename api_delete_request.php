<?php
session_start();
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'กรุณาเข้าสู่ระบบก่อนทำรายการ']);
    exit;
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'คุณไม่มีสิทธิ์ทำรายการนี้ เฉพาะผู้ดูแลระบบ (Admin) เท่านั้น']);
    exit;
}

// 1. ตรวจสอบและสร้างตาราง deleted_requests_log สำหรับบันทึกประวัติการลบ
try {
    $db_driver = getenv('DB_DRIVER') ?: 'mysql';
    if ($db_driver === 'pgsql') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS deleted_requests_log (
            id SERIAL PRIMARY KEY,
            request_id INT,
            request_name VARCHAR(100),
            last_approve VARCHAR(100) DEFAULT NULL,
            requester_name VARCHAR(255),
            requester_section VARCHAR(255),
            request_date TIMESTAMP WITH TIME ZONE,
            status_before VARCHAR(50),
            items_summary TEXT,
            deleted_by_id INT,
            deleted_by_name VARCHAR(255),
            delete_reason TEXT NOT NULL,
            deleted_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
            restored_stock INT DEFAULT 0
        )");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS deleted_requests_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            request_id INT,
            request_name VARCHAR(100),
            last_approve VARCHAR(100) DEFAULT NULL,
            requester_name VARCHAR(255),
            requester_section VARCHAR(255),
            request_date DATETIME,
            status_before VARCHAR(50),
            items_summary TEXT,
            deleted_by_id INT,
            deleted_by_name VARCHAR(255),
            delete_reason TEXT NOT NULL,
            deleted_at DATETIME NOT NULL,
            restored_stock TINYINT(1) DEFAULT 0,
            KEY idx_del_req_name (request_name),
            KEY idx_deleted_at (deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
} catch (Exception $e) {}

// Action 1: ดึงประวัติการลบรายการเบิก
if (isset($_GET['action']) && $_GET['action'] === 'get_deleted_logs') {
    try {
        $stmt = $pdo->query("SELECT * FROM deleted_requests_log ORDER BY deleted_at DESC LIMIT 100");
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $logs]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Action 2: ลบรายการประวัติการเบิก
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestCode = trim($_POST['request_code'] ?? $_POST['id'] ?? '');
    $deleteReason = trim($_POST['delete_reason'] ?? '');

    if (empty($requestCode)) {
        echo json_encode(['status' => 'error', 'message' => 'กรุณาระบุเลขที่ใบเบิกที่ต้องการลบ']);
        exit;
    }

    if (empty($deleteReason)) {
        echo json_encode(['status' => 'error', 'message' => 'กรุณาระบุสาเหตุการลบรายการ']);
        exit;
    }

    $adminId = $_SESSION['user_id'];
    $adminName = $_SESSION['fullname'] ?? ($_SESSION['username'] ?? "Admin (ID {$adminId})");

    try {
        $pdo->beginTransaction();

        $deletedCodes = [];
        $now = date('Y-m-d H:i:s');
        $found = false;

        // ค้นหาในระบบใหม่ (requests)
        $sql = "SELECT r.*, 
                       CONCAT(COALESCE(u.prefix,''), COALESCE(u.firstname,''), ' ', COALESCE(u.lastname,'')) as requester_name,
                       COALESCE(r.section, u.section) as requester_section
                FROM requests r
                LEFT JOIN users u ON r.user_id = u.id
                WHERE r.request_name = ? OR r.last_approve = ?";
        $params = [$requestCode, $requestCode];
        if (is_numeric($requestCode)) {
            $sql .= " OR r.id = ?";
            $params[] = (int)$requestCode;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $targets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($targets)) {
            $found = true;
            foreach ($targets as $req) {
                $reqId = $req['id'];
                $reqName = $req['request_name'] ?: ("ID #" . $reqId);
                $deletedCodes[] = $reqName;

                // ดึงรายการวัสดุในใบเบิกนี้
                $stmtItems = $pdo->prepare("SELECT ri.*, s.name as supply_name, s.unit as supply_unit 
                                           FROM request_items ri 
                                           LEFT JOIN supplies s ON ri.supply_id = s.id 
                                           WHERE ri.request_id = ?");
                $stmtItems->execute([$reqId]);
                $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

                // สร้างข้อความสรุปรายการวัสดุ
                $itemsSummaryArr = [];
                $restoredStock = 0;

                foreach ($items as $idx => $it) {
                    $sName = $it['supply_name'] ?: ("วัสดุ ID " . $it['supply_id']);
                    $sUnit = $it['supply_unit'] ?: "หน่วย";
                    $reqQty = $it['qty'];
                    $appQty = ($it['approved_qty'] !== null) ? $it['approved_qty'] : $reqQty;

                    $itemsSummaryArr[] = ($idx + 1) . ". {$sName} (ขอเบิก {$reqQty} {$sUnit}, อนุมัติ {$appQty} {$sUnit})";

                    // กรณีที่รายการเบิกได้รับอนุมัติไปแล้ว ให้คืนยอดสต็อกกลับเข้าคลัง supplies เพื่อความถูกต้องของคลัง
                    if (in_array($req['status'], ['approved', 'completed']) && empty($it['is_deleted'])) {
                        $returnQty = (float)$appQty;
                        if ($returnQty > 0 && !empty($it['supply_id'])) {
                            $stmtRestore = $pdo->prepare("UPDATE supplies SET qty = qty + ? WHERE id = ?");
                            $stmtRestore->execute([$returnQty, $it['supply_id']]);
                            $restoredStock = 1;
                        }
                    }
                }

                $itemsSummaryText = implode("\n", $itemsSummaryArr);

                // บันทึกประวัติการลบลงตาราง deleted_requests_log
                $stmtLog = $pdo->prepare("INSERT INTO deleted_requests_log 
                    (request_id, request_name, last_approve, requester_name, requester_section, request_date, status_before, items_summary, deleted_by_id, deleted_by_name, delete_reason, deleted_at, restored_stock) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmtLog->execute([
                    $reqId,
                    $reqName,
                    $req['last_approve'],
                    trim($req['requester_name']) ?: 'ไม่ระบุชื่อ',
                    $req['requester_section'] ?: '-',
                    $req['request_date'],
                    $req['status'],
                    $itemsSummaryText,
                    $adminId,
                    $adminName,
                    $deleteReason,
                    $now,
                    $restoredStock
                ]);

                // ลบรายการใน request_items และ requests
                $pdo->prepare("DELETE FROM request_items WHERE request_id = ?")->execute([$reqId]);
                $pdo->prepare("DELETE FROM requests WHERE id = ?")->execute([$reqId]);

                // บันทึก Log ลงตาราง system_logs ของระบบ
                if (function_exists('logAction')) {
                    $logDetail = "ลบรายการประวัติการเบิกเลขที่ '{$reqName}' (สถานะเดิม: {$req['status']}, ผู้เบิก: {$req['requester_name']}) สาเหตุ: {$deleteReason}";
                    if ($restoredStock) {
                        $logDetail .= " [คืนยอดวัสดุเข้าคลังเรียบร้อย]";
                    }
                    logAction($pdo, $adminId, 'DELETE_REQUEST', 'my_requests.php', $logDetail);
                }
            }
        }

        // ค้นหาในระบบประวัติเดิม (withdraw)
        if (isset($pdo_old)) {
            $stmtOld = $pdo_old->prepare("SELECT w.*, p.product_name, an.authen_name, an.authen_lname, ap.part_name 
                                          FROM withdraw w
                                          LEFT JOIN product p ON w.product_code = p.product_code
                                          LEFT JOIN authen_name an ON w.user_withdraw = an.id
                                          LEFT JOIN authen_part ap ON w.authen_part = ap.id
                                          WHERE w.withdraw_code = ? OR w.last_approve = ?");
            $stmtOld->execute([$requestCode, $requestCode]);
            $oldTargets = $stmtOld->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($oldTargets)) {
                $found = true;
                $deletedCodes[] = $requestCode;
                $itemsSummaryArr = [];
                foreach ($oldTargets as $idx => $ow) {
                    $pName = $ow['product_name'] ?: $ow['product_code'];
                    $itemsSummaryArr[] = ($idx + 1) . ". {$pName} (จำนวน " . $ow['withdraw_volume'] . ")";
                }
                $firstOw = $oldTargets[0];
                $reqNameOld = !empty($firstOw['last_approve']) ? $firstOw['last_approve'] : $firstOw['withdraw_code'];
                $requesterOld = trim(($firstOw['authen_name'] ?? '') . ' ' . ($firstOw['authen_lname'] ?? ''));

                $stmtLog = $pdo->prepare("INSERT INTO deleted_requests_log 
                    (request_id, request_name, last_approve, requester_name, requester_section, request_date, status_before, items_summary, deleted_by_id, deleted_by_name, delete_reason, deleted_at, restored_stock) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmtLog->execute([
                    $firstOw['id'],
                    $reqNameOld,
                    $firstOw['last_approve'],
                    $requesterOld ?: 'ไม่ระบุชื่อ',
                    $firstOw['part_name'] ?: '-',
                    $firstOw['date_accept_step2'] ?? $now,
                    'approved_old',
                    implode("\n", $itemsSummaryArr),
                    $adminId,
                    $adminName,
                    $deleteReason,
                    $now,
                    0
                ]);

                $delOld = $pdo_old->prepare("DELETE FROM withdraw WHERE withdraw_code = ? OR last_approve = ?");
                $delOld->execute([$requestCode, $requestCode]);

                if (function_exists('logAction')) {
                    $logDetail = "ลบรายการประวัติการเบิกเดิมเลขที่ '{$requestCode}' (ผู้เบิก: {$requesterOld}) สาเหตุ: {$deleteReason}";
                    logAction($pdo, $adminId, 'DELETE_REQUEST', 'my_requests.php', $logDetail);
                }
            }
        }

        if (!$found) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => "ไม่พบข้อมูลรายการเบิกสำหรับเลขที่ '{$requestCode}'"]);
            exit;
        }

        $pdo->commit();

        echo json_encode([
            'status' => 'success',
            'message' => "ลบรายการประวัติการเบิกเลขที่ " . implode(', ', array_unique($deletedCodes)) . " เรียบร้อยแล้ว",
            'deleted_codes' => array_unique($deletedCodes),
            'delete_reason' => $deleteReason,
            'deleted_at' => $now
        ]);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในการลบรายการ: ' . $e->getMessage()]);
        exit;
    }
}
?>
