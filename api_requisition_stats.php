<?php
session_start();
require_once 'config.php';

// Check Auth
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'director', 'supply_head', 'supply_officer'])) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

if ($userId <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Invalid User ID']);
    exit;
}

try {
    // We sum COALESCE(ri.approved_qty, ri.qty) for approved or completed requests
    $stmt = $pdo->prepare("
        SELECT 
            s.id AS supply_id,
            s.code,
            s.name,
            s.unit,
            COALESCE(s.inspection_threshold, 100) AS inspection_threshold,
            SUM(COALESCE(ri.approved_qty, ri.qty)) AS total_qty
        FROM request_items ri
        JOIN requests r ON ri.request_id = r.id
        JOIN supplies s ON ri.supply_id = s.id
        WHERE r.user_id = ? 
          AND EXTRACT(YEAR FROM r.request_date) = ?
          AND r.status IN ('approved', 'completed')
          AND (ri.is_deleted IS NULL OR ri.is_deleted = 0)
        GROUP BY s.id, s.code, s.name, s.unit, s.inspection_threshold
        ORDER BY total_qty DESC
    ");
    $stmt->execute([$userId, $year]);
    $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Add exceeded flag
    foreach ($stats as &$item) {
        $item['total_qty'] = (int)$item['total_qty'];
        $item['inspection_threshold'] = (int)$item['inspection_threshold'];
        $item['exceeded'] = $item['total_qty'] > $item['inspection_threshold'];
    }

    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'data' => $stats]);
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
