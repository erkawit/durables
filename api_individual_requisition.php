<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'director', 'supply_head', 'supply_officer'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$userId    = isset($_GET['user_id'])    ? (int)$_GET['user_id']        : 0;
$startDate = isset($_GET['start_date']) ? $_GET['start_date']          : date('Y-m-01');
$endDate   = isset($_GET['end_date'])   ? $_GET['end_date']            : date('Y-m-t');

// Validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) $startDate = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate))   $endDate   = date('Y-m-t');

$params = [$startDate . ' 00:00:00', $endDate . ' 23:59:59'];
$userCond = '';
if ($userId > 0) {
    $userCond = ' AND r.user_id = ?';
    $params[] = $userId;
}

$sql = "SELECT
            ri.id                                                    AS item_id,
            r.request_name                                           AS req_code,
            r.request_date,
            CONCAT(u.prefix, u.firstname, ' ', u.lastname)          AS requester,
            u.section,
            s.code                                                   AS supply_code,
            s.name                                                   AS supply_name,
            COALESCE(ri.approved_qty, ri.qty)                        AS qty,
            s.unit,
            COALESCE(s.price, 0)                                     AS unit_price,
            COALESCE(s.price, 0) * COALESCE(ri.approved_qty, ri.qty) AS total_price
        FROM request_items ri
        JOIN requests r  ON r.id  = ri.request_id
        JOIN users    u  ON u.id  = r.user_id
        JOIN supplies s  ON s.id  = ri.supply_id
        WHERE r.request_date BETWEEN ? AND ?
          AND r.status NOT IN ('cancelled', 'rejected')
          AND (ri.is_deleted IS NULL OR ri.is_deleted = 0)
          $userCond
        ORDER BY r.request_date ASC, ri.id ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$grandTotal = 0;
$items = [];
$no = 1;
foreach ($rows as $row) {
    $grandTotal += (float)$row['total_price'];
    $items[] = [
        'no'          => $no++,
        'req_code'    => $row['req_code'],
        'req_date'    => $row['request_date'],
        'requester'   => $row['requester'],
        'section'     => $row['section'],
        'supply_code' => $row['supply_code'],
        'supply_name' => $row['supply_name'],
        'qty'         => (int)$row['qty'],
        'unit'        => $row['unit'],
        'unit_price'  => (float)$row['unit_price'],
        'total_price' => (float)$row['total_price'],
    ];
}

echo json_encode([
    'status'      => 'ok',
    'items'       => $items,
    'grand_total' => $grandTotal,
    'count'       => count($items),
]);
