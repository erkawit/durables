<?php
require_once 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if (!isset($_GET['request_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Missing Request ID']);
    exit;
}

$requestId = $_GET['request_id'];

try {
    $stmt = $pdo->prepare("SELECT ri.id as item_id, ri.qty, ri.approved_qty, ri.edit_reason, ri.is_deleted, ri.original_qty,
               s.name as supply_name, s.unit, s.image, s.code
        FROM request_items ri
        JOIN supplies s ON ri.supply_id = s.id
        WHERE ri.request_id = ? AND (ri.is_deleted = 0 OR ri.is_deleted IS NULL)
    ");
    $stmt->execute([$requestId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Prepare data ensuring approved_qty defaults to requested qty if null
    foreach ($items as &$item) {
        if ($item['approved_qty'] === null) {
            $item['approved_qty'] = $item['qty'];
        }
    }

    echo json_encode(['status' => 'success', 'items' => $items]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>