<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config.php';
// require_once 'config2.php';  // Old database connection disabled to fix udtc_all_app error
header('Content-Type: text/html; charset=utf-8'); // Ensure UTF-8

if (!isset($_SESSION['user_id'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'เซสชันการใช้งานหมดอายุ กรุณาเข้าสู่ระบบใหม่อีกครั้ง'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header("Location: index.php");
    exit;
}

// ── Handle AJAX Requests Immediately (Prevent HTML / Notices from Corrupting JSON) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_telegram_pdf') {
    ini_set('display_errors', 0);
    error_reporting(0);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    require_once 'api_send_telegram_pdf.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['get_details_new', 'get_details_old'])) {
    ini_set('display_errors', 0);
    error_reporting(E_ALL);
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $action = $_POST['action'];
    $id = trim($_POST['id'] ?? '');

    try {
        if ($action === 'get_details_new') {
            if (strpos($id, ',') !== false || is_numeric($id)) {
                $idList = array_values(array_filter(array_map('intval', explode(',', $id))));
                if (!empty($idList)) {
                    $inPlaceholders = implode(',', array_fill(0, count($idList), '?'));
                    $sql = "SELECT ri.qty, ri.approved_qty, s.name as product_name, s.unit as product_unit, ri.edit_reason, ri.is_deleted,
                                   u.firstname, u.lastname, r.request_name, s.image, s.code as supply_code
                            FROM request_items ri 
                            JOIN supplies s ON ri.supply_id = s.id 
                            JOIN requests r ON ri.request_id = r.id
                            LEFT JOIN users u ON r.user_id = u.id
                            WHERE ri.request_id IN ($inPlaceholders)
                            ORDER BY r.id ASC, ri.id ASC";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($idList);
                } else {
                    $stmt = $pdo->prepare("SELECT 1 WHERE 1=0");
                    $stmt->execute();
                }
            } else {
                // Group Code (last_approve) or Request Code (request_name)
                $sql = "SELECT ri.qty, ri.approved_qty, s.name as product_name, s.unit as product_unit, ri.edit_reason, ri.is_deleted, 
                               u.firstname, u.lastname, r.request_name, s.image, s.code as supply_code
                        FROM request_items ri 
                        JOIN supplies s ON ri.supply_id = s.id 
                        JOIN requests r ON ri.request_id = r.id
                        LEFT JOIN users u ON r.user_id = u.id
                        WHERE (r.last_approve = ? OR r.request_name = ?)
                        ORDER BY r.id ASC, ri.id ASC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$id, $id]);
            }

            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($items as &$item) {
                if ($item['approved_qty'] === null || $item['approved_qty'] === '') {
                    $item['approved_qty'] = (!empty($item['is_deleted'])) ? 0 : $item['qty'];
                }
            }
            unset($item);
            echo json_encode(['status' => 'success', 'data' => $items]);
            exit;
        }

        if ($action === 'get_details_old') {
            if (!isset($pdo_old)) {
                echo json_encode(['status' => 'success', 'data' => []]);
                exit;
            }
            $sql = "SELECT 
                        w.withdraw_volume as qty,
                        w.withdraw_volume as approved_qty,
                        p.product_name,
                        tv.type_volume as product_unit,
                        an.authen_name as firstname,
                        an.authen_lname as lastname,
                        CASE WHEN w.last_approve IS NOT NULL AND w.last_approve != '' THEN w.last_approve ELSE w.withdraw_code END as request_name,
                        p.product_img as image,
                        p.product_code as supply_code
                    FROM withdraw w 
                    LEFT JOIN product p ON w.product_code = p.product_code
                    LEFT JOIN product_insert pi ON p.product_code = pi.product_code
                    LEFT JOIN type_volume tv ON pi.type_volume = tv.id
                    LEFT JOIN authen_name an ON w.user_withdraw = an.id
                    WHERE w.last_approve = ? OR w.withdraw_code = ?
                    ORDER BY w.withdraw_code ASC, w.id ASC";
            $stmt = $pdo_old->prepare($sql);
            $stmt->execute([$id, $id]);
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

$displayItems = [];
$history = [];
$newReqs = [];
$section = $_SESSION['section'] ?? '-';
$userId = $_SESSION['user_id'];

// Role-based visibility logic
$role = $_SESSION['role'] ?? null;
$userId = $_SESSION['user_id'] ?? 0;
$userSection = $_SESSION['section'] ?? '';

// Role-based visibility logic (Auto-fetch if missing from session)
if (!$role && $userId) {
    try {
        $stmtRole = $pdo->prepare("SELECT role, section FROM users WHERE id = ?");
        $stmtRole->execute([$userId]);
        $uData = $stmtRole->fetch();
        if ($uData) {
            $role = $uData['role'];
            $_SESSION['role'] = $role;
            if (empty($userSection) && !empty($uData['section'])) {
                $userSection = $uData['section'];
                $_SESSION['section'] = $userSection;
            }
        }
    } catch (Exception $e) {}
}
if (!$role) $role = 'user';
$userSection = $_SESSION['section'] ?? '';


// 1. Fetch New System Requests (Table: requests)
try {
    $sqlNew = "SELECT r.*, 
                u.section as requester_section,
                CONCAT(u.prefix, u.firstname, ' ', u.lastname) as requester_full_name,
                (SELECT COUNT(*) FROM request_items WHERE request_id = r.id AND (is_deleted IS NULL OR is_deleted = 0)) as item_count 
             FROM requests r
             LEFT JOIN users u ON r.user_id = u.id
             WHERE 1=1 ";

    $paramsNew = [];

    if (in_array($role, ['admin', 'director', 'supply_head', 'supply_officer'])) {
        // Can see ALL
    } elseif ($role == 'head') {
        // Can see OWN section
        $sqlNew .= " AND u.section = ?";
        $paramsNew[] = $userSection;
    } else {
        // Regular user: Can see OWN requests only
        $sqlNew .= " AND r.user_id = ?";
        $paramsNew[] = $userId;
    }

    $sqlNew .= " ORDER BY r.request_date DESC";

    $stmt = $pdo->prepare($sqlNew);
    $stmt->execute($paramsNew);
    $newReqs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Ignore error
}

// 2. Fetch Old System Requests
try {
    // Adjusted SQL to include last_approve if it exists in schema (Assuming it does based on user request)
    // We group by last_approve IF allow, otherwise withdraw_code.
    // Since we can't easily dynamic group by in sql mode optional, we fetch raw and PHP group or try to GROUP BY unique ID logic.
    // User requested "Group by last_approve".

    $sqlOld = "SELECT 
                w.withdraw_code,
                w.last_approve,
                MAX(w.date_accept_step2) as request_date,
                COUNT(w.id) as item_count,
                w.authen_part,
                ap.part_name as section_name,
                GROUP_CONCAT(DISTINCT CONCAT(an.authen_name, ' ', an.authen_lname) SEPARATOR ', ') as requester_name,
                GROUP_CONCAT(p.product_name SEPARATOR ', ') as product_names,
                'approved_old' as status
            FROM withdraw w
            LEFT JOIN product p ON w.product_code = p.product_code
            LEFT JOIN authen_part ap ON w.authen_part = ap.id
            LEFT JOIN authen_name an ON w.user_withdraw = an.id
            LEFT JOIN authen a_login ON an.id = a_login.authen_name_id 
            WHERE w.date_accept_step2 IS NOT NULL 
            AND w.date_accept_step2 != '0000-00-00 00:00:00' ";

    $paramsOld = [];

    // Apply logic for Old System
    if (in_array($role, ['admin', 'director', 'supply_head', 'supply_officer'])) {
        // See ALL
    } elseif ($role == 'head') {
        // See OWN section (match part_name)
        // Use authen_part directly from withdraw table more reliably if possible, but part_name match is requested
        $sqlOld .= " AND ap.part_name = ?";
        $paramsOld[] = $userSection;
    } else {
        // Regular user: 
        // Logic: withdraw joined authen (via authen_name_id) -> get authen_u (username) -> Match with current session username
        // We assume $_SESSION['username'] holds the current user's username
        $currentUsername = $_SESSION['username'] ?? '';
        $sqlOld .= " AND a_login.authen_u = ?";
        $paramsOld[] = $currentUsername;
    }

    // Grouping by last_approve if available (fallback to withdraw_code is implicit if stored uniquely)
    // To support grouping correctly:
    $sqlOld .= " GROUP BY CASE WHEN w.last_approve IS NOT NULL AND w.last_approve != '' THEN w.last_approve ELSE w.withdraw_code END 
                 ORDER BY w.date_accept_step2 DESC";

    if (!isset($pdo_old)) {
        throw new PDOException("Old database connection is disabled");
    }
    $stmt = $pdo_old->prepare($sqlOld);
    $stmt->execute($paramsOld);
    $historyResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Process History Results to standardize display items
    foreach ($historyResults as $h) {
        $code = !empty($h['last_approve']) ? $h['last_approve'] : $h['withdraw_code'];
        $displayItems[] = [
            'date' => $h['request_date'],
            'code' => $code,
            'section' => $h['section_name'],
            'requester' => $h['requester_name'], // Is concatenated in list query
            'items' => $h['product_names'],
            'count' => $h['item_count'],
            'status' => 'approved_old',
            'reject_reason' => '',
            'id' => $code, // Use last_approve or withdraw_code as ID for details
            'type' => 'old'
        ];
    }

} catch (Exception $e) {
    // Ignore error
}

// REQUEST GROUPING LOGIC (New System)
// Group new requests by last_approve code
$groupedRequests = [];

foreach ($newReqs as $r) {
    // Use last_approve as key if exists, otherwise use unique ID prefix
    $sec = trim($r['requester_section'] ?: 'ทั่วไป');
    if (!empty($r['last_approve'])) {
        $groupKey = 'la_' . $r['last_approve'];
    } elseif (strpos($r['status'], 'pending') !== false) {
        $groupKey = 'pending_' . $sec . '_' . $r['status'];
    } else {
        $reqDay = date('Y-m-d', strtotime($r['request_date']));
        $groupKey = 'sec_' . $sec . '_' . $r['status'] . '_' . $reqDay;
    }

    if (!isset($groupedRequests[$groupKey])) {
        $groupedRequests[$groupKey] = [
            'last_approve' => $r['last_approve'] ?? null,
            'section' => $sec,
            'latest_date' => $r['request_date'],
            'status' => $r['status'],
            'request_count' => 0,
            'total_items' => 0,
            'reject_reason' => $r['reject_reason'] ?? '',
            'ids' => [],
            'request_names' => [],
            'requesters' => [],
            'requests' => []
        ];
    }

    $groupedRequests[$groupKey]['request_count']++;
    $groupedRequests[$groupKey]['total_items'] += $r['item_count'];
    $groupedRequests[$groupKey]['requests'][] = $r;
    $groupedRequests[$groupKey]['ids'][] = (int)$r['id'];
    if (!empty($r['request_name'])) {
        $groupedRequests[$groupKey]['request_names'][] = $r['request_name'];
    }
    if (!empty($r['requester_full_name']) && !in_array($r['requester_full_name'], $groupedRequests[$groupKey]['requesters'])) {
        $groupedRequests[$groupKey]['requesters'][] = $r['requester_full_name'];
    }

    // Use the LATEST date for the group
    if (strtotime($r['request_date']) > strtotime($groupedRequests[$groupKey]['latest_date'])) {
        $groupedRequests[$groupKey]['latest_date'] = $r['request_date'];
    }
}

// Convert grouped requests to display items
foreach ($groupedRequests as $groupKey => $group) {
    $hasLastApprove = !empty($group['last_approve']);

    if ($hasLastApprove) {
        $codeDisplay = $group['last_approve'];
        $idToSend = $group['last_approve'];
        $deleteCode = $group['last_approve'];
    } else {
        $reqNames = array_values(array_unique(array_filter($group['request_names'])));
        if (count($reqNames) === 1) {
            $codeDisplay = $reqNames[0];
        } elseif (count($reqNames) > 1) {
            $first = $reqNames[0];
            $last = end($reqNames);
            $lastShort = substr($last, -4);
            $codeDisplay = $first . ' - ' . $lastShort . ' (' . count($reqNames) . ' ใบ)';
        } else {
            $codeDisplay = 'ส่วนงาน ' . $group['section'];
        }
        $idToSend = implode(',', $group['ids']);
        $deleteCode = !empty($reqNames) ? implode(',', $reqNames) : $idToSend;
    }

    $reqCount = count($group['requesters']);
    if ($reqCount === 1) {
        $requesterDisplay = $group['requesters'][0];
    } elseif ($reqCount > 1) {
        $requesterDisplay = $reqCount . ' คน (' . implode(', ', array_slice($group['requesters'], 0, 2)) . ($reqCount > 2 ? '...' : '') . ')';
    } else {
        $requesterDisplay = 'บุคลากร ' . $group['section'];
    }

    $displayItems[] = [
        'date' => $group['latest_date'],
        'code' => $codeDisplay,
        'delete_code' => $deleteCode,
        'section' => $group['section'],
        'requester' => $requesterDisplay,
        'request_count' => $group['request_count'],
        'items' => 'รายการเบิกตามส่วนงาน',
        'count' => $group['total_items'],
        'status' => $group['status'],
        'reject_reason' => $group['reject_reason'] ?? '',
        'id' => $idToSend,
        'type' => 'new'
    ];
}

// Sort merged array by date DESC
usort($displayItems, function ($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

// ── AJAX Handlers สำหรับประวัติการลบและการลบรายการเบิก (เฉพาะ Admin) ──
if (isset($_GET['action']) && $_GET['action'] === 'get_deleted_logs') {
    header('Content-Type: application/json; charset=utf-8');
    if ($role !== 'admin') {
        echo json_encode(['status' => 'error', 'message' => 'เฉพาะผู้ดูแลระบบ (Admin) เท่านั้น']);
        exit;
    }
    try {
        $stmt = $pdo->query("SELECT * FROM deleted_requests_log ORDER BY deleted_at DESC LIMIT 100");
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $logs]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_request') {
    header('Content-Type: application/json; charset=utf-8');
    if ($role !== 'admin') {
        echo json_encode(['status' => 'error', 'message' => 'คุณไม่มีสิทธิ์ทำรายการนี้ เฉพาะผู้ดูแลระบบ (Admin) เท่านั้น']);
        exit;
    }

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
        // ตรวจสอบและสร้างตาราง deleted_requests_log หากยังไม่มี
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

        $pdo->beginTransaction();

        $deletedCodes = [];
        $now = date('Y-m-d H:i:s');
        $found = false;

        // 1. ค้นหาในระบบใหม่ (ตาราง requests) รองรับทั้งรหัสเดี่ยว, รหัสกลุ่ม หรือหลายรหัสคั่นด้วยจุลภาค
        $rawCodes = array_values(array_filter(array_map('trim', explode(',', $requestCode))));
        $numericIds = [];
        $stringCodes = [];
        foreach ($rawCodes as $c) {
            if (is_numeric($c)) $numericIds[] = (int)$c;
            else $stringCodes[] = $c;
        }

        $whereParts = [];
        $params = [];

        if (!empty($stringCodes)) {
            $inStr = implode(',', array_fill(0, count($stringCodes), '?'));
            $whereParts[] = "r.request_name IN ($inStr) OR r.last_approve IN ($inStr)";
            $params = array_merge($params, $stringCodes, $stringCodes);
        }
        if (!empty($numericIds)) {
            $inNum = implode(',', array_fill(0, count($numericIds), '?'));
            $whereParts[] = "r.id IN ($inNum)";
            $params = array_merge($params, $numericIds);
        }

        if (empty($whereParts)) {
            $whereParts[] = "r.request_name = ? OR r.last_approve = ?";
            $params = [$requestCode, $requestCode];
        }

        $sql = "SELECT r.*, 
                       CONCAT(COALESCE(u.prefix,''), COALESCE(u.firstname,''), ' ', COALESCE(u.lastname,'')) as requester_name,
                       COALESCE(r.section, u.section) as requester_section
                FROM requests r
                LEFT JOIN users u ON r.user_id = u.id
                WHERE (" . implode(' OR ', $whereParts) . ")";

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

                $itemsSummaryArr = [];
                $restoredStock = 0;

                foreach ($items as $idx => $it) {
                    $sName = $it['supply_name'] ?: ("วัสดุ ID " . $it['supply_id']);
                    $sUnit = $it['supply_unit'] ?: "หน่วย";
                    $reqQty = $it['qty'];
                    $appQty = ($it['approved_qty'] !== null) ? $it['approved_qty'] : $reqQty;

                    $itemsSummaryArr[] = ($idx + 1) . ". {$sName} (ขอเบิก {$reqQty} {$sUnit}, อนุมัติ {$appQty} {$sUnit})";

                    // กรณีที่รายการเบิกได้รับอนุมัติไปแล้ว คืนยอดสต็อกเข้า supplies เพื่อความถูกต้องของคลัง
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

                // บันทึก Log ลงตาราง system_logs
                if (function_exists('logAction')) {
                    $logDetail = "ลบรายการประวัติการเบิกเลขที่ '{$reqName}' (สถานะเดิม: {$req['status']}, ผู้เบิก: {$req['requester_name']}) สาเหตุ: {$deleteReason}";
                    if ($restoredStock) $logDetail .= " [คืนยอดวัสดุเข้าคลังเรียบร้อย]";
                    logAction($pdo, $adminId, 'DELETE_REQUEST', 'my_requests.php', $logDetail);
                }
            }
        }

        // 2. ถ้าไม่พบใน requests หรือเป็นรายการประวัติระบบเก่า ให้ตรวจสอบในตาราง withdraw
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

// Old AJAX Details moved to top
if (false) { // Deprecated: already handled at top of file
    if ($_POST['action'] == 'get_details_old') {
        $id = $_POST['id']; // This is last_approve OR withdraw_code

        // Fetch items matching either withdraw_code OR last_approve
        $sql = "SELECT 
                    w.withdraw_volume as qty,
                    -- w.amount_admin as approved_qty, -- Assuming amount_admin is approved qty
                    p.product_name,
                    tv.type_volume as product_unit,
                    an.authen_name as firstname,
                    an.authen_lname as lastname,
                    CASE WHEN w.last_approve IS NOT NULL AND w.last_approve != '' THEN w.last_approve ELSE w.withdraw_code END as request_name,
                    p.product_img as image, -- Adjust if image column differs
                    p.product_code as supply_code
                FROM withdraw w 
                LEFT JOIN product p ON w.product_code = p.product_code
                LEFT JOIN product_insert pi ON p.product_code = pi.product_code
                LEFT JOIN type_volume tv ON pi.type_volume = tv.id
                LEFT JOIN authen_name an ON w.user_withdraw = an.id
                WHERE w.last_approve = ?
                ORDER BY w.withdraw_code ASC, w.id ASC";

        if (!isset($pdo_old)) {
            echo json_encode(['status' => 'success', 'data' => []]);
            exit;
        }
        $stmt = $pdo_old->prepare($sql);
        $stmt->execute([$id, $id]);
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;

    } elseif ($_POST['action'] == 'get_details_new') {
        $id = $_POST['id'];

        if (!is_numeric($id)) {
            // It's a Group Code (last_approve)
            $sql = "SELECT ri.qty, ri.approved_qty, s.name as product_name, s.unit as product_unit, ri.edit_reason, ri.is_deleted, 
                           u.firstname, u.lastname, r.request_name, s.image, s.code as supply_code
                    FROM request_items ri 
                    JOIN supplies s ON ri.supply_id = s.id 
                    JOIN requests r ON ri.request_id = r.id
                    LEFT JOIN users u ON r.user_id = u.id
                    WHERE r.last_approve = ?
                    ORDER BY r.id ASC, ri.id ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$id]);
        } else {
            // It's a Single Request ID
            $sql = "SELECT ri.qty, ri.approved_qty, s.name as product_name, s.unit as product_unit, ri.edit_reason, ri.is_deleted,
                           u.firstname, u.lastname, r.request_name, s.image, s.code as supply_code
                    FROM request_items ri 
                    JOIN supplies s ON ri.supply_id = s.id 
                    JOIN requests r ON ri.request_id = r.id
                    LEFT JOIN users u ON r.user_id = u.id
                    WHERE ri.request_id = ?
                    ORDER BY ri.id ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$id]);
        }

        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }
}

function formatThaiDate($dateString)
{
    if (empty($dateString) || $dateString == '0000-00-00 00:00:00')
        return '-';
    $timestamp = strtotime($dateString);
    $thaiMonths = [1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.', 5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.', 9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.'];
    return date('j', $timestamp) . ' ' . $thaiMonths[(int) date('n', $timestamp)] . ' ' . (date('Y', $timestamp) + 543) . ' ' . date('H:i', $timestamp) . ' น.';
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <title>ประวัติการเบิก - ระบบเบิกวัสดุ</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        sky: {
                            50: '#f4f6f8',
                            100: '#e1e5ec',
                            200: '#c4ccd9',
                            300: '#9fabbe',
                            400: '#7587a1',
                            500: '#17365a',
                            600: '#112A46',
                            700: '#0c1e32',
                            800: '#081421',
                            900: '#040a11'
                        }
                    }
                }
            }
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script>
        var isAdmin = <?php echo $role === 'admin' ? 'true' : 'false'; ?>;
        var canPrintReport = <?php echo in_array($role, ['admin', 'supply_officer', 'supply_head']) ? 'true' : 'false'; ?>;

        function printRequest(id) {
            if (!id) return;
            window.open('export_pdf_request.php?id=' + encodeURIComponent(id), '_blank');
        }

        function sendPdfToTelegram(id) {
            if (!id) return;

            let progressHtml = `
                <div class="p-6 text-center font-[Sarabun]">
                    <div class="relative w-20 h-20 mx-auto mb-4 flex items-center justify-center">
                        <div class="absolute inset-0 rounded-2xl bg-sky-100 animate-ping opacity-25"></div>
                        <div class="relative w-16 h-16 rounded-2xl bg-gradient-to-tr from-sky-500 to-indigo-600 text-white flex items-center justify-center text-3xl shadow-lg shadow-sky-500/30">
                            <i class="fab fa-telegram-plane"></i>
                        </div>
                    </div>
                    
                    <h3 class="text-xl font-bold text-slate-800 mb-1">ส่งเอกสารใบเบิก (PDF) ไป Telegram</h3>
                    <p id="tgStatusText" class="text-sm text-slate-500 mb-5">กำลังเตรียมข้อมูลและจัดสร้างไฟล์เอกสาร...</p>

                    <div class="w-full bg-slate-100 rounded-full h-4 mb-2 overflow-hidden border border-slate-200/80 p-0.5 shadow-inner">
                        <div id="tgProgressBar" class="bg-gradient-to-r from-sky-500 via-indigo-500 to-emerald-500 h-full rounded-full transition-all duration-300 ease-out" style="width: 15%;"></div>
                    </div>

                    <div class="flex justify-between items-center text-xs font-bold text-slate-500 px-1 mt-1">
                        <span id="tgStepText"><i class="fas fa-spinner fa-spin mr-1 text-sky-500"></i> ขั้นตอนที่ 1/3: ตรวจสอบข้อมูล</span>
                        <span id="tgPercentText" class="text-sky-600 font-mono text-sm">15%</span>
                    </div>
                </div>
            `;

            Swal.fire({
                html: progressHtml,
                showConfirmButton: false,
                allowOutsideClick: false,
                allowEscapeKey: false,
                width: '520px',
                background: '#ffffff',
                customClass: {
                    popup: 'rounded-2xl shadow-2xl p-0 overflow-hidden border border-slate-100'
                }
            });

            const updateProgress = (percent, stepText, statusText) => {
                const bar = document.getElementById('tgProgressBar');
                const pText = document.getElementById('tgPercentText');
                const sText = document.getElementById('tgStepText');
                const stText = document.getElementById('tgStatusText');
                if (bar) bar.style.width = percent + '%';
                if (pText) pText.innerText = percent + '%';
                if (sText) sText.innerHTML = `<i class="fas fa-spinner fa-spin mr-1 text-sky-500"></i> ` + stepText;
                if (stText && statusText) stText.innerText = statusText;
            };

            // Progressive stage visual transitions
            let timer1 = setTimeout(() => updateProgress(40, 'ขั้นตอนที่ 2/3: กำลังสร้างไฟล์ PDF (A4)', 'กำลังเรนเดอร์เอกสารและจัดสร้างไฟล์ PDF...'), 600);
            let timer2 = setTimeout(() => updateProgress(75, 'ขั้นตอนที่ 3/3: กำลังส่งเอกสารเข้า Telegram', 'สร้างไฟล์ PDF สำเร็จแล้ว กำลังอัปโหลดส่ง Telegram...'), 2000);

            $.ajax({
                url: 'my_requests.php',
                type: 'POST',
                data: { action: 'send_telegram_pdf', id: id },
                dataType: 'text',
                timeout: 90000
            }).done(function (rawResponse) {
                clearTimeout(timer1);
                clearTimeout(timer2);

                let res = null;
                try {
                    let cleaned = (typeof rawResponse === 'string') ? rawResponse.trim() : JSON.stringify(rawResponse);
                    const sIdx = cleaned.indexOf('{');
                    const eIdx = cleaned.lastIndexOf('}');
                    if (sIdx !== -1 && eIdx !== -1 && eIdx >= sIdx) {
                        res = JSON.parse(cleaned.substring(sIdx, eIdx + 1));
                    } else {
                        res = JSON.parse(cleaned);
                    }
                } catch (parseErr) {
                    if (rawResponse && (rawResponse.includes('<!DOCTYPE html>') || rawResponse.includes('<html'))) {
                        Swal.fire({
                            icon: 'warning',
                            title: 'เซสชันหมดอายุ',
                            text: 'กรุณาเข้าสู่ระบบใหม่อีกครั้ง',
                            confirmButtonText: 'เข้าสู่ระบบ',
                            confirmButtonColor: '#0284c7'
                        }).then(() => {
                            window.location.href = 'index.php';
                        });
                        return;
                    }
                    Swal.fire({
                        icon: 'error',
                        title: 'เกิดข้อผิดพลาดในการประมวลผล',
                        text: 'ไม่สามารถอ่านข้อมูลผลลัพธ์จากเซิร์ฟเวอร์ได้',
                        confirmButtonText: 'ตกลง',
                        confirmButtonColor: '#ef4444'
                    });
                    return;
                }

                if (res && res.status === 'success') {
                    updateProgress(100, 'เสร็จสิ้นทุกขั้นตอน', 'ส่งเอกสาร PDF เข้า Telegram สำเร็จเรียบร้อย!');

                    setTimeout(() => {
                        let targetsHtml = (res.sent_targets && res.sent_targets.length > 0)
                            ? res.sent_targets.map(t => `<li class="flex items-center gap-1.5"><i class="fas fa-check text-emerald-500"></i> ${t}</li>`).join('')
                            : '<li class="flex items-center gap-1.5"><i class="fas fa-check text-emerald-500"></i> กลุ่ม Telegram ส่วนกลาง</li>';

                        Swal.fire({
                            icon: 'success',
                            title: 'ส่งเอกสาร PDF สำเร็จเรียบร้อย!',
                            html: `
                                <div class="text-left font-[Sarabun] text-sm text-slate-600 space-y-3 pt-2">
                                    <div class="p-3 bg-emerald-50 border border-emerald-200 rounded-xl text-emerald-800 text-xs leading-relaxed">
                                        <p class="font-bold text-sm mb-1"><i class="fas fa-paper-plane mr-1"></i> ส่งไฟล์ PDF เข้า Telegram แล้ว</p>
                                        <p>สามารถเปิดอ่าน ดาวน์โหลด หรือบันทึกไฟล์เอกสารได้ทันทีจากมือถือในห้องแชท Telegram</p>
                                    </div>
                                    <div class="bg-slate-50 border border-slate-200 rounded-xl p-3 text-xs space-y-1.5">
                                        <p><span class="text-slate-400">ชื่อไฟล์:</span> <span class="font-mono font-bold text-slate-700">${res.filename || '-'}</span></p>
                                        <div>
                                            <span class="text-slate-400">ปลายทางที่ได้รับ:</span>
                                            <ul class="mt-1 pl-1 space-y-0.5 text-slate-700 font-medium">${targetsHtml}</ul>
                                        </div>
                                    </div>
                                </div>
                            `,
                            showCancelButton: true,
                            confirmButtonText: '<i class="fas fa-file-pdf mr-1.5"></i> เปิดดูไฟล์ PDF',
                            confirmButtonColor: '#0284c7',
                            cancelButtonText: 'ปิดหน้าต่าง',
                            cancelButtonColor: '#64748b',
                            customClass: {
                                popup: 'rounded-2xl shadow-2xl p-6',
                                confirmButton: 'rounded-xl px-5 py-2.5 font-bold text-xs shadow',
                                cancelButton: 'rounded-xl px-5 py-2.5 font-bold text-xs'
                            }
                        }).then((r) => {
                            if (r.isConfirmed && res.pdf_url) {
                                window.open(res.pdf_url, '_blank');
                            }
                        });
                    }, 500);

                } else if (res && res.status === 'warning' && res.pdf_url) {
                    updateProgress(100, 'สร้างไฟล์ PDF สำเร็จ', 'สร้างไฟล์เอกสาร PDF เรียบร้อยแล้ว');
                    setTimeout(() => {
                        Swal.fire({
                            icon: 'warning',
                            title: 'สร้าง PDF สำเร็จ (ส่งเข้า Telegram ไม่สำเร็จ)',
                            html: `
                                <div class="text-left font-[Sarabun] text-sm text-slate-600 space-y-3 pt-2">
                                    <div class="p-3 bg-amber-50 border border-amber-200 rounded-xl text-amber-900 text-xs leading-relaxed">
                                        <p class="font-bold text-sm mb-1 flex items-center gap-1.5">
                                            <i class="fas fa-exclamation-triangle text-amber-600"></i> ข้อขัดข้องในการส่ง Telegram:
                                        </p>
                                        <p class="text-amber-800">${res.message || 'ไม่สามารถส่งข้อความ/เอกสารเข้า Telegram ได้'}</p>
                                    </div>
                                    <div class="bg-slate-50 border border-slate-200 rounded-xl p-3 text-xs space-y-1">
                                        <p><span class="text-slate-400">ชื่อไฟล์:</span> <span class="font-mono font-bold text-slate-700">${res.filename || '-'}</span></p>
                                        <p class="text-emerald-700 font-semibold"><i class="fas fa-check-circle mr-1"></i> ไฟล์เอกสารถูกสร้างและบันทึกในระบบเรียบร้อยแล้ว</p>
                                    </div>
                                </div>
                            `,
                            showCancelButton: true,
                            confirmButtonText: '<i class="fas fa-file-pdf mr-1.5"></i> เปิดดูไฟล์ PDF ที่สร้างสำเร็จ',
                            confirmButtonColor: '#0284c7',
                            cancelButtonText: 'ปิดหน้าต่าง',
                            cancelButtonColor: '#64748b',
                            customClass: {
                                popup: 'rounded-2xl shadow-2xl p-6',
                                confirmButton: 'rounded-xl px-5 py-2.5 font-bold text-xs shadow',
                                cancelButton: 'rounded-xl px-5 py-2.5 font-bold text-xs'
                            }
                        }).then((r) => {
                            if (r.isConfirmed && res.pdf_url) {
                                window.open(res.pdf_url, '_blank');
                            }
                        });
                    }, 500);

                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'ไม่สามารถส่งเอกสารได้',
                        text: (res && res.message) ? res.message : 'เกิดข้อผิดพลาดในการสร้างหรือส่งเอกสาร PDF',
                        confirmButtonText: 'ตกลง',
                        confirmButtonColor: '#ef4444',
                        customClass: { popup: 'rounded-2xl shadow-xl' }
                    });
                }
            }).fail(function (xhr, status, error) {
                clearTimeout(timer1);
                clearTimeout(timer2);
                let errText = 'การเชื่อมต่อระบบขัดข้อง (' + status + '): ' + error;
                if (xhr.status === 500) {
                    errText = 'เซิร์ฟเวอร์เกิดข้อผิดพลาดภายใน (Internal Server Error 500) กรุณาลองใหม่อีกครั้ง';
                }
                Swal.fire({
                    icon: 'error',
                    title: 'เกิดข้อผิดพลาด',
                    text: errText,
                    confirmButtonText: 'ตกลง',
                    confirmButtonColor: '#ef4444',
                    customClass: { popup: 'rounded-2xl shadow-xl' }
                });
            });
        }

        function showReason(reason) {
            Swal.fire({
                title: 'เหตุผลการไม่อนุมัติ',
                text: reason || 'ไม่ระบุเหตุผล',
                icon: 'error',
                confirmButtonText: 'รับทราบ'
            });
        }

        function viewDetails(id, type, action, reqStatus) {
            if (!type) type = 'new';
            if (!action) action = (type === 'old') ? 'get_details_old' : 'get_details_new';
            if (!id) return;

            Swal.fire({
                title: 'กำลังโหลดข้อมูล...',
                html: '<div class="flex justify-center my-4"><i class="fas fa-circle-notch fa-spin text-4xl text-sky-500"></i></div>',
                showConfirmButton: false,
                allowOutsideClick: false,
                customClass: { popup: 'rounded-2xl' }
            });

            $.post('my_requests.php', { action: action, id: id }, function (res) {
                try {
                    let data = (typeof res === 'object') ? res : JSON.parse(res);
                    if (data && data.status === 'success') {
                        let headerTitle = type === 'new' ? 'รายละเอียดการเบิก (ระบบใหม่)' : 'รายละเอียดการเบิก (ระบบเดิม)';
                        let headerIcon = type === 'new' ? 'fa-shopping-bag' : 'fa-history';

                        let contentHtml = '';

                        if (!data.data || data.data.length === 0) {
                            contentHtml = '<div class="text-center py-10 text-gray-400">ไม่พบข้อมูลรายการ</div>';
                        } else {
                            const grouped = {};
                            const requestOrder = [];

                            data.data.forEach(item => {
                                const key = item.request_name || 'Unknown';
                                if (!grouped[key]) {
                                    grouped[key] = {
                                        request_name: item.request_name,
                                        requester: item.firstname ? (item.firstname + ' ' + (item.lastname || '')) : 'ไม่ระบุชื่อ',
                                        items: []
                                    };
                                    requestOrder.push(key);
                                }
                                grouped[key].items.push(item);
                            });

                            contentHtml += '<div class="p-6 space-y-6 max-h-[600px] overflow-y-auto bg-slate-50">';

                            requestOrder.forEach((key, rIndex) => {
                                const group = grouped[key];

                                contentHtml += `
                                    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                                        <div class="bg-indigo-50/50 px-5 py-4 border-b border-indigo-100 flex justify-between items-center group-header">
                                            <div class="flex items-center gap-4">
                                                <div class="w-10 h-10 rounded-full bg-indigo-600 text-white flex items-center justify-center font-bold text-lg shadow-md ring-2 ring-indigo-100">
                                                    ${rIndex + 1}
                                                </div>
                                                <div>
                                                    <div class="font-bold text-slate-800 text-base">${group.requester}</div>
                                                    <div class="flex items-center gap-2 mt-1">
                                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-md text-xs font-medium bg-white text-indigo-700 border border-indigo-200 shadow-sm">
                                                            <i class="fas fa-file-invoice mr-1.5"></i> ${group.request_name}
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="px-2">
                                            <table class="w-full text-left border-collapse">
                                                <thead class="text-slate-500 text-xs font-semibold uppercase tracking-wider border-b border-slate-100">
                                                    <tr>
                                                        <th class="p-4 w-20 text-center">รูปภาพ</th>
                                                        <th class="p-4">รายการวัสดุ</th>
                                                        <th class="p-4 text-center w-28">ขอเบิก</th>
                                                        <th class="p-4 text-center w-28 text-indigo-600">จำนวนที่อนุมัติ</th>
                                                        <th class="p-4 text-center w-24">หน่วย</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-slate-50">`;

                                group.items.forEach(item => {
                                    let quantity = Number(item.qty) || 0;
                                    let approvedVal = (item.approved_qty !== null && item.approved_qty !== undefined && item.approved_qty !== '') 
                                        ? Number(item.approved_qty) 
                                        : quantity;
                                    let appQty = '';
                                    let name = item.product_name;

                                    let rowClass = 'hover:bg-indigo-50/30 transition-colors';
                                    let statusBadge = '';
                                    let imgUrl = item.image || 'img/packaging.png';
                                    let imgElement = `<img src="${imgUrl}" class="w-full h-full rounded-md object-cover transition-transform hover:scale-110 cursor-pointer" onclick="Swal.fire({imageUrl: '${imgUrl}', showConfirmButton: false, width: 'auto', padding: '1em', background: 'transparent', backdrop: 'rgba(0,0,0,0.8)'})" onerror="this.onerror=null;this.src='img/packaging.png'">`;

                                    if (item.is_deleted == 1 || approvedVal === 0) {
                                        rowClass = 'bg-red-50 hover:bg-red-50';
                                        statusBadge = `<div class="inline-flex items-center mt-1 px-2 py-0.5 rounded text-[10px] bg-red-100 text-red-700 font-medium"><i class="fas fa-trash-alt mr-1"></i> ถูกลบ: ${item.edit_reason || '-'}</div>`;
                                        appQty = '<span class="text-red-500 font-bold">0</span>';
                                        name = `<span class="line-through text-red-800 opacity-60 decoration-2">${name}</span>`;
                                    } else if (item.edit_reason || approvedVal < quantity) {
                                        let diff = quantity - approvedVal;
                                        statusBadge = `<div class="inline-flex items-center mt-1 px-2 py-0.5 rounded text-[10px] bg-amber-100 text-amber-700 font-medium"><i class="fas fa-edit mr-1"></i> ปรับลด ${diff > 0 ? diff : ''} ${item.product_unit || ''} ${item.edit_reason ? '(' + item.edit_reason + ')' : ''}</div>`;
                                        appQty = `<span class="text-amber-600 font-bold text-base">${approvedVal}</span> <span class="text-[11px] text-slate-400 font-normal line-through">(${quantity})</span>`;
                                    } else {
                                        appQty = `<span class="text-indigo-600 font-bold text-base">${approvedVal}</span>`;
                                    }

                                    contentHtml += `
                                        <tr class="${rowClass}">
                                            <td class="p-4 text-center">
                                                 <div class="w-12 h-12 rounded-lg bg-white border border-slate-200 p-0.5 shadow-sm mx-auto overflow-hidden relative group">
                                                    ${imgElement}
                                                 </div>
                                            </td>
                                            <td class="p-4 align-top">
                                                <div class="font-bold text-slate-700 text-sm mb-0.5">${name}</div>
                                                <div class="flex items-center gap-2">
                                                    <span class="text-[10px] font-mono font-medium text-slate-500 bg-slate-100 px-1.5 py-0.5 rounded border border-slate-200">
                                                        CODE: ${item.supply_code || '-'}
                                                    </span>
                                                    ${statusBadge}
                                                </div>
                                            </td>
                                            <td class="p-4 text-center align-top font-bold text-slate-600 text-base">${quantity}</td>
                                            <td class="p-4 text-center align-top font-bold text-indigo-600 text-base border-x border-dashed border-indigo-100 bg-indigo-50/20 shadow-inner">${appQty}</td>
                                            <td class="p-4 text-center align-top text-xs text-slate-500">${item.product_unit || '-'}</td>
                                        </tr>
                                    `;
                                });

                                contentHtml += `</tbody></table></div></div>`;
                            });

                            contentHtml += `</div>`;
                        }

                        renderModalContent(headerTitle, headerIcon, type, data, contentHtml, id, reqStatus);

                    } else {
                        throw new Error((data && data.message) ? data.message : 'ไม่สามารถโหลดข้อมูลได้');
                    }
                } catch (e) {
                    console.error(e);
                    Swal.fire('Error', e.message || 'ไม่สามารถโหลดข้อมูลได้', 'error');
                }
            }).fail(function (xhr, status, error) {
                console.error('AJAX Error:', status, error, xhr.responseText);
                Swal.fire('Error', 'ไม่สามารถเชื่อมต่อกับระบบได้ (' + status + ')', 'error');
            });
        }

        function renderModalContent(headerTitle, headerIcon, type, data, contentHtml, id, reqStatus) {
            let userCount = 0;
            if (data && data.data) {
                const uKeys = [];
                data.data.forEach(item => {
                    const key = item.request_name || 'Unknown';
                    if (!uKeys.includes(key)) uKeys.push(key);
                });
                userCount = uKeys.length;
            }

            let firstReqName = (data && data.data && data.data[0] && data.data[0].request_name) ? data.data[0].request_name : '';

            let isApproved = (reqStatus === 'approved' || reqStatus === 'approved_old');
            if (!isApproved && data && data.data && data.data.length > 0) {
                if (data.data.some(d => d.status === 'approved' || d.status === 'approved_old')) {
                    isApproved = true;
                }
            }
            let showPrint = canPrintReport && isApproved;

            let telegramBtnHtml = '';
            if (showPrint && id) {
                telegramBtnHtml = `
                    <button onclick="sendPdfToTelegram('${encodeURIComponent(id)}')" class="bg-sky-500 hover:bg-sky-600 text-white text-xs font-bold px-3 py-2 rounded-xl transition flex items-center gap-1.5 shadow-sm border border-sky-400 cursor-pointer" title="สร้างไฟล์ PDF และส่งไปยัง Telegram ซ้ำได้">
                        <i class="fab fa-telegram-plane"></i> ส่ง Telegram (PDF)
                    </button>
                `;
            }

            let printBtnHtml = '';
            if (showPrint && id) {
                printBtnHtml = `
                    <button onclick="printRequest('${encodeURIComponent(id)}')" class="bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-bold px-3.5 py-2 rounded-xl transition flex items-center gap-1.5 shadow-sm border border-emerald-400 cursor-pointer">
                        <i class="fas fa-print"></i> พิมพ์ใบเบิก
                    </button>
                `;
            }

            let deleteBtnHtml = '';
            if (isAdmin && type === 'new' && firstReqName) {
                deleteBtnHtml = `
                    <button onclick="deleteRequestPrompt('${firstReqName.replace(/'/g, "\\'")}')" class="bg-red-500 hover:bg-red-600 text-white text-xs font-bold px-3 py-2 rounded-xl transition flex items-center gap-1.5 shadow-sm">
                        <i class="fas fa-trash-alt"></i> ลบรายการนี้
                    </button>
                `;
            }

            let userCountHtml = '';
            if (type === 'new' && userCount > 0) {
                userCountHtml = `
                    <div class="bg-white/20 hover:bg-white/30 transition-colors backdrop-blur-md px-4 py-2 rounded-xl text-white text-sm font-bold shadow-sm border border-white/20 flex items-center gap-2">
                        <i class="fas fa-users"></i>
                        <span>${userCount} ผู้ขอเบิก</span>
                    </div>
                `;
            }

            let bottomBarHtml = `
                <div class="px-6 py-4 bg-slate-100/90 border-t border-slate-200 flex flex-wrap justify-between items-center gap-3">
                    <div class="flex items-center gap-2">
                        ${showPrint && id ? `
                            <button type="button" onclick="sendPdfToTelegram('${encodeURIComponent(id)}')" class="bg-sky-500 hover:bg-sky-600 text-white text-xs font-bold px-4 py-2.5 rounded-xl transition flex items-center gap-1.5 shadow-md border border-sky-400 cursor-pointer">
                                <i class="fab fa-telegram-plane"></i> ส่งเอกสาร PDF ไป Telegram
                            </button>
                            <button type="button" onclick="printRequest('${encodeURIComponent(id)}')" class="bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold px-4 py-2.5 rounded-xl transition flex items-center gap-1.5 shadow-md border border-emerald-500 cursor-pointer">
                                <i class="fas fa-print"></i> พิมพ์ใบเบิก (A4)
                            </button>
                        ` : ''}
                    </div>
                    <button type="button" onclick="Swal.close()" class="bg-slate-600 hover:bg-slate-700 text-white px-7 py-2.5 rounded-xl font-bold text-xs shadow transition">
                        ปิดหน้าต่าง
                    </button>
                </div>
            `;

            let htmlContent = `
                <div class="text-left font-[Sarabun]">
                    <div class="bg-gradient-to-r from-violet-600 to-indigo-600 p-6 rounded-t-2xl shadow-lg relative overflow-hidden">
                         <div class="absolute top-0 right-0 p-4 opacity-10">
                            <i class="fas fa-layer-group text-8xl text-white transform rotate-12 translate-x-4 -translate-y-4"></i>
                         </div>
                         
                         <div class="flex justify-between items-start relative z-10">
                             <div class="flex items-center gap-4">
                                <div class="w-14 h-14 bg-white/20 rounded-2xl flex items-center justify-center backdrop-blur-md shadow-inner border border-white/20">
                                    <i class="fas ${headerIcon} text-2xl text-white"></i>
                                </div> 
                                <div>
                                    <h4 class="text-white font-bold text-xl tracking-wide">${headerTitle}</h4>
                                    <p class="text-indigo-100 text-sm font-medium mt-1 opacity-90">ตรวจสอบรายละเอียดรายการเบิก (แยกตามผู้ขอเบิก)</p>
                                </div>
                             </div>
                             
                             <div class="flex items-center gap-2">
                                 ${telegramBtnHtml}
                                 ${printBtnHtml}
                                 ${deleteBtnHtml}
                                 ${userCountHtml}
                             </div>
                         </div>
                    </div>
                    ${contentHtml}
                    ${bottomBarHtml}
                </div>`;

            let swalConfig = {
                width: '900px',
                html: htmlContent,
                showConfirmButton: false,
                background: '#f8fafc',
                customClass: {
                    popup: 'rounded-2xl shadow-2xl p-0 overflow-hidden'
                }
            };

            Swal.fire(swalConfig);
        }

        function deleteRequestPrompt(code) {
            if (!code) return;
            Swal.fire({
                title: 'ยืนยันการลบรายการประวัติการเบิก',
                html: `
                    <div class="text-left font-[Sarabun] space-y-4 pt-2">
                        <div class="bg-red-50 border border-red-200 rounded-xl p-3.5 text-xs text-red-800 leading-relaxed">
                            <p class="font-bold text-sm text-red-900 mb-1 flex items-center gap-1.5">
                                <i class="fas fa-exclamation-triangle text-red-600"></i> คำเตือน (เฉพาะสิทธิ์ Admin)
                            </p>
                            <p>คุณกำลังจะลบรายการประวัติการเบิกเลขที่: <span class="font-bold font-mono text-red-900 text-sm bg-white px-2 py-0.5 rounded border border-red-200 select-all">${code}</span></p>
                            <p class="mt-1.5 text-[11px] text-red-700">• การลบนี้จะนำรายการออกจากระบบประวัติการเบิกทั้งหมด</p>
                            <p class="text-[11px] text-red-700">• หากรายการได้รับการอนุมัติไปแล้ว ระบบจะคืนยอดวัสดุเข้าคลัง supplies ให้อัตโนมัติ</p>
                            <p class="text-[11px] text-red-700">• ระบบจะบันทึก วันที่ เวลา ผู้ลบ และสาเหตุการลบไว้เป็นหลักฐาน</p>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1.5 ml-1">
                                สาเหตุการลบรายการ <span class="text-red-500">* (จำเป็นต้องระบุ)</span>
                            </label>
                            <textarea id="swal_delete_reason" rows="3" class="w-full p-3 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-red-500 focus:border-red-500 outline-none transition" placeholder="ระบุสาเหตุ เช่น ขอเบิกผิดพลาด, รายการซ้ำซ้อน, ผู้เบิกขอยกเลิกคำขอ..."></textarea>
                        </div>
                    </div>
                `,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-trash-alt mr-1"></i> ยืนยันลบรายการ',
                confirmButtonColor: '#ef4444',
                cancelButtonText: 'ยกเลิก',
                cancelButtonColor: '#94a3b8',
                customClass: {
                    popup: 'rounded-3xl shadow-2xl w-[500px]',
                    confirmButton: 'rounded-xl px-6 py-2.5 font-bold text-sm shadow-lg shadow-red-200',
                    cancelButton: 'rounded-xl px-6 py-2.5 font-bold text-sm'
                },
                didOpen: () => {
                    const ta = Swal.getPopup().querySelector('#swal_delete_reason');
                    if (ta) ta.focus();
                },
                preConfirm: async () => {
                    const reason = Swal.getPopup().querySelector('#swal_delete_reason').value.trim();
                    if (!reason) {
                        Swal.showValidationMessage('กรุณาระบุสาเหตุการลบรายการ');
                        return false;
                    }

                    try {
                        const formData = new FormData();
                        formData.append('action', 'delete_request');
                        formData.append('request_code', code);
                        formData.append('delete_reason', reason);

                        const response = await fetch('my_requests.php', {
                            method: 'POST',
                            body: formData
                        });

                        if (!response.ok) {
                            throw new Error('เกิดข้อผิดพลาดจากเซิร์ฟเวอร์ (' + response.status + ')');
                        }

                        const res = await response.json();
                        if (res.status !== 'success') {
                            Swal.showValidationMessage(res.message || 'เกิดข้อผิดพลาด');
                            return false;
                        }
                        return res;
                    } catch (error) {
                        Swal.showValidationMessage(error.message || 'เกิดข้อผิดพลาดในการเชื่อมต่อ');
                        return false;
                    }
                }
            }).then((result) => {
                if (result.isConfirmed && result.value) {
                    Swal.fire({
                        icon: 'success',
                        title: 'ลบรายการสำเร็จ!',
                        text: result.value.message || 'ลบรายการประวัติการเบิกเรียบร้อยแล้ว',
                        timer: 1800,
                        showConfirmButton: false,
                        customClass: { popup: 'rounded-2xl' }
                    }).then(() => {
                        location.reload();
                    });
                }
            });
        }

        function openDeleteByCodeModal() {
            Swal.fire({
                title: 'ลบรายการประวัติการเบิกตามเลขที่',
                html: `
                    <div class="text-left font-[Sarabun] space-y-4 pt-2">
                        <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 text-xs text-amber-800 leading-relaxed">
                            <i class="fas fa-info-circle text-amber-600 mr-1"></i> ระบุเลขที่ใบเบิกที่ต้องการลบ (สามารถลบได้ทุกสถานะ เช่น รออนุมัติ, อนุมัติแล้ว หรือไม่อนุมัติ)
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1.5 ml-1">
                                เลขที่ใบเบิกที่ต้องการลบ <span class="text-red-500">*</span>
                            </label>
                            <input type="text" id="manual_del_code" class="w-full px-3.5 py-2.5 border border-gray-300 rounded-xl text-sm font-mono focus:ring-2 focus:ring-red-500 focus:border-red-500 outline-none transition uppercase" placeholder="ระบุเลขที่ใบเบิก เช่น REQ-2024-xxxx หรือรหัสกลุ่ม">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1.5 ml-1">
                                สาเหตุการลบรายการ <span class="text-red-500">* (จำเป็นต้องระบุ)</span>
                            </label>
                            <textarea id="manual_del_reason" rows="3" class="w-full p-3 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-red-500 focus:border-red-500 outline-none transition" placeholder="ระบุสาเหตุการลบรายการ เช่น ข้อมูลซ้ำซ้อน, กรอกผิดพลาด, ยกเลิกตามคำขอ..."></textarea>
                        </div>
                    </div>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-trash-alt mr-1"></i> ค้นหาและลบรายการ',
                confirmButtonColor: '#ef4444',
                cancelButtonText: 'ยกเลิก',
                cancelButtonColor: '#94a3b8',
                customClass: {
                    popup: 'rounded-3xl shadow-2xl w-[500px]',
                    confirmButton: 'rounded-xl px-6 py-2.5 font-bold text-sm shadow-lg shadow-red-200',
                    cancelButton: 'rounded-xl px-6 py-2.5 font-bold text-sm'
                },
                didOpen: () => {
                    const inp = Swal.getPopup().querySelector('#manual_del_code');
                    if (inp) inp.focus();
                },
                preConfirm: async () => {
                    const code = Swal.getPopup().querySelector('#manual_del_code').value.trim();
                    const reason = Swal.getPopup().querySelector('#manual_del_reason').value.trim();

                    if (!code) {
                        Swal.showValidationMessage('กรุณาระบุเลขที่ใบเบิกที่ต้องการลบ');
                        return false;
                    }
                    if (!reason) {
                        Swal.showValidationMessage('กรุณาระบุสาเหตุการลบรายการ');
                        return false;
                    }

                    try {
                        const formData = new FormData();
                        formData.append('action', 'delete_request');
                        formData.append('request_code', code);
                        formData.append('delete_reason', reason);

                        const response = await fetch('my_requests.php', {
                            method: 'POST',
                            body: formData
                        });

                        if (!response.ok) {
                            throw new Error('เกิดข้อผิดพลาดจากเซิร์ฟเวอร์ (' + response.status + ')');
                        }

                        const res = await response.json();
                        if (res.status !== 'success') {
                            Swal.showValidationMessage(res.message || 'เกิดข้อผิดพลาด');
                            return false;
                        }
                        return res;
                    } catch (error) {
                        Swal.showValidationMessage(error.message || 'เกิดข้อผิดพลาดในการเชื่อมต่อ');
                        return false;
                    }
                }
            }).then((result) => {
                if (result.isConfirmed && result.value) {
                    Swal.fire({
                        icon: 'success',
                        title: 'ลบรายการสำเร็จ!',
                        text: result.value.message || 'ลบรายการประวัติการเบิกเรียบร้อยแล้ว',
                        timer: 1800,
                        showConfirmButton: false,
                        customClass: { popup: 'rounded-2xl' }
                    }).then(() => {
                        location.reload();
                    });
                }
            });
        }

        async function viewDeletedLogsModal() {
            Swal.fire({
                title: 'กำลังโหลดประวัติการลบ...',
                html: '<div class="flex justify-center my-4"><i class="fas fa-circle-notch fa-spin text-4xl text-sky-500"></i></div>',
                showConfirmButton: false,
                allowOutsideClick: false,
                customClass: { popup: 'rounded-2xl' }
            });

            try {
                const response = await fetch('my_requests.php?action=get_deleted_logs');
                const res = await response.json();

                if (res.status !== 'success') {
                    throw new Error(res.message || 'เกิดข้อผิดพลาด');
                }

                const logs = res.data || [];
                let rowsHtml = '';

                if (logs.length === 0) {
                    rowsHtml = `<tr><td colspan="6" class="p-8 text-center text-gray-400">ยังไม่มีประวัติการลบรายการเบิก</td></tr>`;
                } else {
                    logs.forEach((log, idx) => {
                        const restoredBadge = log.restored_stock == 1 
                            ? `<span class="bg-emerald-50 text-emerald-700 border border-emerald-200 px-2 py-0.5 rounded text-[10px] font-bold"><i class="fas fa-undo mr-1"></i> คืนสต็อกแล้ว</span>`
                            : '';

                        rowsHtml += `
                            <tr class="border-b border-gray-100 hover:bg-gray-50 text-xs">
                                <td class="p-3 text-center text-gray-400">${idx + 1}</td>
                                <td class="p-3 font-mono font-bold text-gray-700 whitespace-nowrap">${log.deleted_at}</td>
                                <td class="p-3 font-bold text-sky-700 whitespace-nowrap">
                                    ${log.request_name || '-'}
                                    ${restoredBadge ? '<div class="mt-0.5">' + restoredBadge + '</div>' : ''}
                                </td>
                                <td class="p-3">
                                    <div class="font-bold text-gray-800">${log.requester_name || '-'}</div>
                                    <div class="text-[11px] text-gray-400">${log.requester_section || '-'}</div>
                                </td>
                                <td class="p-3 text-red-600 font-medium">
                                    ${log.delete_reason || '-'}
                                </td>
                                <td class="p-3 text-gray-600 whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1 font-semibold text-gray-700"><i class="fas fa-user-shield text-gray-400"></i> ${log.deleted_by_name || '-'}</span>
                                </td>
                            </tr>
                        `;
                    });
                }

                const tableHtml = `
                    <div class="font-[Sarabun] text-left">
                        <div class="p-4 bg-gradient-to-r from-red-600 to-rose-600 rounded-t-2xl text-white flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center text-lg">
                                    <i class="fas fa-history"></i>
                                </div>
                                <div>
                                    <h3 class="font-bold text-lg">ประวัติการลบรายการเบิกพัสดุ</h3>
                                    <p class="text-xs text-red-100">บันทึกวัน-เวลา สาเหตุ และผู้ดำเนินการลบ (เฉพาะ Admin)</p>
                                </div>
                            </div>
                            <span class="bg-white/20 px-3 py-1 rounded-full text-xs font-bold">${logs.length} รายการล่าสุด</span>
                        </div>
                        <div class="p-4 max-h-[500px] overflow-y-auto">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="bg-gray-100 text-gray-600 text-xs uppercase font-bold">
                                        <th class="p-3 text-center w-10">#</th>
                                        <th class="p-3 whitespace-nowrap">วัน-เวลาที่ลบ</th>
                                        <th class="p-3 whitespace-nowrap">เลขที่ใบเบิก</th>
                                        <th class="p-3">ผู้ขอเบิก</th>
                                        <th class="p-3">สาเหตุการลบ</th>
                                        <th class="p-3 whitespace-nowrap">ผู้ดำเนินการลบ</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    ${rowsHtml}
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;

                Swal.fire({
                    html: tableHtml,
                    width: '1000px',
                    showConfirmButton: true,
                    confirmButtonText: 'ปิดหน้าต่าง',
                    confirmButtonColor: '#64748b',
                    customClass: {
                        popup: 'rounded-2xl shadow-2xl p-0 overflow-hidden',
                        confirmButton: 'rounded-xl px-8 py-2.5 font-bold text-sm mb-4 mt-2'
                    }
                });

            } catch (e) {
                Swal.fire('Error', e.message || 'ไม่สามารถโหลดประวัติการลบได้', 'error');
            }
        }

        // Attach explicitly to window for global access
        window.isAdmin = isAdmin;
        window.canPrintReport = canPrintReport;
        window.viewDetails = viewDetails;
        window.printRequest = printRequest;
        window.sendPdfToTelegram = sendPdfToTelegram;
        window.showReason = showReason;
        window.renderModalContent = renderModalContent;
        window.deleteRequestPrompt = deleteRequestPrompt;
        window.openDeleteByCodeModal = openDeleteByCodeModal;
        window.viewDeletedLogsModal = viewDeletedLogsModal;
    </script>
<style>body{animation:pgFadeIn .3s ease both}@keyframes pgFadeIn{from{opacity:0}to{opacity:1}}body.pg-exit{opacity:0!important;transition:opacity .12s ease;pointer-events:none}</style>
</head>

<body class="bg-gray-50 flex h-screen overflow-hidden font-[Sarabun]">

    <?php $page = 'my_requests'; include 'sidebar.php'; ?>

    <main class="flex-1 flex flex-col overflow-hidden relative">

    <header class="bg-white shadow-sm px-6 py-4 flex items-center justify-between sticky top-0 z-20 shrink-0">
        <div class="flex items-center gap-4">
            <a href="dashboard.php" class="text-gray-400 hover:text-sky-600 transition"><i
                    class="fas fa-arrow-left text-xl"></i></a>
            <h1 class="text-xl font-bold text-gray-800">ประวัติการเบิกวัสดุ</h1>
        </div>
        <?php if ($role === 'admin'): ?>
            <div class="flex items-center gap-2">
                <button onclick="viewDeletedLogsModal()" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3.5 py-2 rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-sm">
                    <i class="fas fa-history text-gray-500"></i> ประวัติการลบรายการเบิก
                </button>
                <button onclick="openDeleteByCodeModal()" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-md shadow-red-200">
                    <i class="fas fa-trash-alt"></i> ลบรายการตามเลขที่เบิก
                </button>
            </div>
        <?php endif; ?>
    </header>

    <div class="p-6 flex-1 overflow-y-auto">
        <div class="bg-white rounded-2xl shadow-lg border border-gray-100 p-6">
            <table id="requestsTable" class="w-full text-left stripe hover nowrap">
                <thead>
                    <tr class="bg-sky-50 text-sky-800">
                        <th class="py-4 px-4 text-center">วันที่</th>
                        <th class="py-4 px-4">เลขที่ / รหัสกลุ่ม</th>
                        <th class="py-4 px-4">ส่วนงาน</th>
                        <th class="py-4 px-4">ผู้ขอเบิก</th>
                        <th class="py-4 px-4 text-center">จำนวน</th>
                        <th class="py-4 px-4 text-center">สถานะ</th>
                        <th class="py-4 px-4 text-center">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($displayItems as $req): ?>
                        <tr class="border-b border-gray-50 hover:bg-slate-50/60 transition-colors">
                            <td class="px-4 py-3 text-center" data-order="<?php echo strtotime($req['date']); ?>">
                                <?php echo formatThaiDate($req['date']); ?>
                            </td>
                            <td class="px-4 py-3 font-mono font-bold text-sky-700"><?php echo htmlspecialchars($req['code']); ?></td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-sky-50 text-sky-800 border border-sky-100 text-xs font-bold">
                                    <i class="fas fa-building text-sky-500"></i>
                                    <?php echo htmlspecialchars($req['section'] ?? '-'); ?>
                                </span>
                            </td>
                            <td class="px-4 py-3" title="<?php echo htmlspecialchars($req['requester']); ?>">
                                <?php
                                $rName = $req['requester'];
                                echo mb_strlen($rName, 'UTF-8') > 50 ? mb_substr($rName, 0, 50, 'UTF-8') . '...' : htmlspecialchars($rName);
                                ?>
                            </td>
                            <td class="px-4 py-3 text-center font-bold text-slate-700"><?php echo $req['count']; ?></td>
                            <td class="px-4 py-3 text-center">
                                <?php
                                $s = $req['status'];
                                if ($s == 'approved_old')
                                    echo '<span class="bg-gray-100 text-gray-600 px-2.5 py-1 rounded-md text-xs font-bold">ประวัติเก่า</span>';
                                elseif ($s == 'approved')
                                    echo '<span class="bg-green-100 text-green-700 px-2.5 py-1 rounded-md text-xs font-bold">อนุมัติเรียบร้อย</span>';
                                elseif ($s == 'rejected')
                                    echo '<span class="bg-red-100 text-red-700 px-2.5 py-1 rounded-md text-xs font-bold cursor-pointer" onclick="showReason(\'' . htmlspecialchars($req['reject_reason']) . '\')"><i class="fas fa-info-circle mr-1"></i>ไม่อนุมัติ</span>';
                                elseif (strpos($s, 'pending') !== false)
                                    echo '<span class="bg-amber-100 text-amber-700 px-2.5 py-1 rounded-md text-xs font-bold">รอการอนุมัติ</span>';
                                else
                                    echo $s;
                                ?>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <div class="flex items-center justify-center gap-1.5">
                                    <button onclick="viewDetails('<?php echo htmlspecialchars($req['id'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars($req['type'], ENT_QUOTES, 'UTF-8'); ?>', null, '<?php echo htmlspecialchars($req['status'], ENT_QUOTES, 'UTF-8'); ?>')"
                                        class="bg-sky-100 text-sky-700 hover:bg-sky-200 px-3 py-1.5 rounded-lg transition text-xs font-bold flex items-center gap-1">
                                        <i class="fas fa-search"></i> รายละเอียด
                                    </button>
                                    <?php if ($req['status'] === 'approved' || $req['status'] === 'approved_old'): ?>
                                        <button onclick="sendPdfToTelegram('<?php echo htmlspecialchars($req['id'], ENT_QUOTES, 'UTF-8'); ?>')"
                                            class="bg-sky-50 text-sky-600 hover:bg-sky-100 px-2.5 py-1.5 rounded-lg transition text-xs font-bold flex items-center gap-1 border border-sky-200"
                                            title="สร้างไฟล์ PDF และส่งไปยัง Telegram ซ้ำได้">
                                            <i class="fab fa-telegram-plane"></i> ส่ง Telegram
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($role === 'admin'): ?>
                                        <button onclick="deleteRequestPrompt('<?php echo htmlspecialchars($req['delete_code'] ?? $req['code'], ENT_QUOTES, 'UTF-8'); ?>')"
                                            class="bg-red-50 text-red-600 hover:bg-red-100 px-2.5 py-1.5 rounded-lg transition text-xs font-bold flex items-center gap-1 border border-red-200"
                                            title="ลบรายการเบิกนี้ (เฉพาะ Admin)">
                                            <i class="fas fa-trash-alt"></i> ลบ
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    </main>

    <script>
        $(document).ready(function () {
            if ($.fn.DataTable) {
                $('#requestsTable').DataTable({ responsive: true, order: [[0, 'desc']] });
            }
        });
    </script>
<script src="js/transitions.js"></script>
</body>

</html>