<?php
// api_year_end.php
session_start();
require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

// Access Check: Only admin, supply_head, or supply_officer
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'supply_head', 'supply_officer'])) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่มีสิทธิ์เข้าถึงข้อมูล']);
    exit;
}

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'backup') {
        $year = intval($_POST['year'] ?? 0);
        if ($year < 2000 || $year > 2100) {
            echo json_encode(['status' => 'error', 'message' => 'ระบุปีไม่ถูกต้อง']);
            exit;
        }

        try {
            $tables = [
                'supplies' => "archive_supplies_$year",
                'requests' => "archive_requests_$year",
                'request_items' => "archive_request_items_$year",
                'procurements' => "archive_procurements_$year",
                'procurement_items' => "archive_procurement_items_$year"
            ];

            // 1. Drop existing archive tables if overwrite is requested
            foreach ($tables as $orig => $arch) {
                $pdo->exec("DROP TABLE IF EXISTS `$arch`");
            }

            // 2. Re-create and populate archive_supplies
            $pdo->exec("CREATE TABLE `archive_supplies_$year` LIKE `supplies`");
            $pdo->exec("INSERT INTO `archive_supplies_$year` SELECT * FROM `supplies`");

            // 3. Re-create and populate archive_requests (Filter by year of request_date)
            $pdo->exec("CREATE TABLE `archive_requests_$year` LIKE `requests`");
            $stmtReq = $pdo->prepare("INSERT INTO `archive_requests_$year` SELECT * FROM `requests` WHERE YEAR(`request_date`) = ?");
            $stmtReq->execute([$year]);

            // 4. Re-create and populate archive_request_items (Filter by year of request_date)
            $pdo->exec("CREATE TABLE `archive_request_items_$year` LIKE `request_items`");
            $stmtItems = $pdo->prepare("
                INSERT INTO `archive_request_items_$year` 
                SELECT ri.* FROM `request_items` ri 
                JOIN `requests` r ON ri.`request_id` = r.`id` 
                WHERE YEAR(r.`request_date`) = ?
            ");
            $stmtItems->execute([$year]);

            // 5. Re-create and populate archive_procurements (Filter by year of import_date)
            $pdo->exec("CREATE TABLE `archive_procurements_$year` LIKE `procurements`");
            $stmtProc = $pdo->prepare("INSERT INTO `archive_procurements_$year` SELECT * FROM `procurements` WHERE YEAR(`import_date`) = ?");
            $stmtProc->execute([$year]);

            // 6. Re-create and populate archive_procurement_items (Filter by year of import_date)
            $pdo->exec("CREATE TABLE `archive_procurement_items_$year` LIKE `procurement_items`");
            $stmtProcItems = $pdo->prepare("
                INSERT INTO `archive_procurement_items_$year` 
                SELECT pi.* FROM `procurement_items` pi 
                JOIN `procurements` p ON pi.`procurement_id` = p.`id` 
                WHERE YEAR(p.`import_date`) = ?
            ");
            $stmtProcItems->execute([$year]);

            // Log action
            logAction($pdo, $_SESSION['user_id'], 'backup_year_end', 'year_end_transfer.php', "Backed up year-end data for year $year");

            echo json_encode(['status' => 'success', 'message' => "สำรองข้อมูลปี $year เรียบร้อยแล้ว"]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในการสำรองข้อมูล: ' . $e->getMessage()]);
        }
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'check_status') {
        $year = intval($_GET['year'] ?? 0);
        if ($year < 2000 || $year > 2100) {
            echo json_encode(['status' => 'error', 'message' => 'ระบุปีไม่ถูกต้อง']);
            exit;
        }

        try {
            $tableName = "archive_supplies_$year";
            $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$tableName]);
            $exists = ($stmt->rowCount() > 0);

            $count = 0;
            if ($exists) {
                $count = (int)$pdo->query("SELECT COUNT(*) FROM `$tableName`")->fetchColumn();
            }

            echo json_encode(['status' => 'success', 'exists' => $exists, 'count' => $count]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'get_report') {
        $year = intval($_GET['year'] ?? 0);
        if ($year < 2000 || $year > 2100) {
            echo json_encode(['status' => 'error', 'message' => 'ระบุปีไม่ถูกต้อง']);
            exit;
        }

        try {
            // Check if archive tables exist
            $chkTable = "archive_supplies_$year";
            $stmtChk = $pdo->prepare("SHOW TABLES LIKE ?");
            $stmtChk->execute([$chkTable]);
            if ($stmtChk->rowCount() == 0) {
                echo json_encode(['status' => 'error', 'message' => "ยังไม่มีการสำรองข้อมูลของปี $year กรุณากดปุ่มสำรองข้อมูลก่อน"]);
                exit;
            }

            // A. Top by Quantity
            $q1 = "
                SELECT s.code, s.name, s.unit, s.price,
                       SUM(COALESCE(ri.approved_qty, ri.qty)) as total_qty,
                       SUM(COALESCE(ri.approved_qty, ri.qty) * s.price) as total_expenditure
                FROM `archive_request_items_$year` ri
                JOIN `archive_requests_$year` r ON ri.request_id = r.id
                JOIN `archive_supplies_$year` s ON ri.supply_id = s.id
                WHERE r.status IN ('approved', 'completed')
                  AND (ri.is_deleted IS NULL OR ri.is_deleted = 0)
                GROUP BY s.id
                ORDER BY total_qty DESC
            ";
            $byQty = $pdo->query($q1)->fetchAll();

            // B. Top by Value
            $q2 = "
                SELECT s.code, s.name, s.unit, s.price,
                       SUM(COALESCE(ri.approved_qty, ri.qty)) as total_qty,
                       SUM(COALESCE(ri.approved_qty, ri.qty) * s.price) as total_expenditure
                FROM `archive_request_items_$year` ri
                JOIN `archive_requests_$year` r ON ri.request_id = r.id
                JOIN `archive_supplies_$year` s ON ri.supply_id = s.id
                WHERE r.status IN ('approved', 'completed')
                  AND (ri.is_deleted IS NULL OR ri.is_deleted = 0)
                GROUP BY s.id
                ORDER BY total_expenditure DESC
            ";
            $byValue = $pdo->query($q2)->fetchAll();

            // C. Anomaly analysis
            $q3 = "
                SELECT r.request_name, r.request_date,
                       CONCAT(u.prefix, u.firstname, ' ', u.lastname) as requester,
                       r.section, s.code, s.name, s.unit, s.price,
                       ri.qty as requested_qty,
                       COALESCE(ri.approved_qty, ri.qty) as approved_qty,
                       ROUND(avg_tbl.avg_qty, 2) as avg_qty,
                       ROUND((COALESCE(ri.approved_qty, ri.qty) / avg_tbl.avg_qty), 1) as multiplier,
                       (COALESCE(ri.approved_qty, ri.qty) * s.price) as total_expenditure
                FROM `archive_request_items_$year` ri
                JOIN `archive_requests_$year` r ON ri.request_id = r.id
                JOIN `archive_supplies_$year` s ON ri.supply_id = s.id
                JOIN `users` u ON r.user_id = u.id
                JOIN (
                    SELECT supply_id, AVG(COALESCE(qty, 1)) as avg_qty
                    FROM `archive_request_items_$year`
                    WHERE (is_deleted IS NULL OR is_deleted = 0)
                    GROUP BY supply_id
                ) avg_tbl ON s.id = avg_tbl.supply_id
                WHERE r.status IN ('approved', 'completed')
                  AND (ri.is_deleted IS NULL OR ri.is_deleted = 0)
                  AND (COALESCE(ri.approved_qty, ri.qty) > 3 * avg_tbl.avg_qty)
                  AND (COALESCE(ri.approved_qty, ri.qty) > 5)
                ORDER BY multiplier DESC, total_expenditure DESC
            ";
            $anomalies = $pdo->query($q3)->fetchAll();

            // Total overall stats
            $totalQtySum = 0;
            $totalExpenditureSum = 0;
            foreach ($byQty as $row) {
                $totalQtySum += (int)$row['total_qty'];
                $totalExpenditureSum += (float)$row['total_expenditure'];
            }

            echo json_encode([
                'status' => 'success',
                'summary' => [
                    'total_items' => count($byQty),
                    'total_qty' => $totalQtySum,
                    'total_expenditure' => $totalExpenditureSum,
                    'total_anomalies' => count($anomalies)
                ],
                'by_qty' => $byQty,
                'by_value' => $byValue,
                'anomalies' => $anomalies
            ]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในการดึงข้อมูลรายงาน: ' . $e->getMessage()]);
        }
        exit;
    }
}

echo json_encode(['status' => 'error', 'message' => 'คำขอไม่ถูกต้อง']);
exit;
?>
