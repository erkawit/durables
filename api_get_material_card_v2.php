<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$supply_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($supply_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid ID']);
    exit;
}

// Fetch Receives
$sqlIn = "SELECT 
            'receive' as type,
            p.import_date as transaction_date,
            p.supplier_name as description,
            p.receipt_no as document_no,
            pi.qty as qty_in,
            0 as qty_out,
            pi.unit_price as price
        FROM procurement_items pi
        JOIN procurements p ON pi.procurement_id = p.id
        WHERE pi.supply_id = ?";

// Fetch Issues
$sqlOut = "SELECT 
            'issue' as type,
            r.approver_supply_date as transaction_date,
            CAST(u.section AS CHAR) as description,
            r.request_name as document_no,
            0 as qty_in,
            COALESCE(ri.approved_qty, ri.qty) as qty_out,
            0 as price
        FROM request_items ri
        JOIN requests r ON ri.request_id = r.id
        JOIN users u ON r.user_id = u.id
        WHERE ri.supply_id = ? AND r.status IN ('approved', 'completed') AND (ri.is_deleted IS NULL OR ri.is_deleted = 0)";

$stmtIn = $pdo->prepare($sqlIn);
$stmtIn->execute([$supply_id]);
$receives = $stmtIn->fetchAll(PDO::FETCH_ASSOC);

$stmtOut = $pdo->prepare($sqlOut);
$stmtOut->execute([$supply_id]);
$issues = $stmtOut->fetchAll(PDO::FETCH_ASSOC);

// For Issues, let's fix price based on moving average or last receive price.
// Since it's a simple report, we'll calculate running balance and moving average price
$transactions = array_merge($receives, $issues);

usort($transactions, function ($a, $b) {
    return strtotime($a['transaction_date']) - strtotime($b['transaction_date']);
});

$balance_qty = 0;
$current_unit_price = 0;

$results = [];

foreach ($transactions as $t) {
    if ($t['type'] == 'receive') {
        $balance_qty += $t['qty_in'];
        $current_unit_price = $t['price']; // Simplified logic: Latest receive price
        $t['total_price'] = $t['qty_in'] * $t['price'];
    } else {
        $balance_qty -= $t['qty_out'];
        $t['price'] = $current_unit_price; // Issue price = last receive price
        $t['total_price'] = $t['qty_out'] * $current_unit_price;
    }

    $t['balance_qty'] = $balance_qty;
    $t['balance_total'] = $balance_qty * $current_unit_price;

    // Format date specifically for history view if needed
    $results[] = $t;
}

echo json_encode([
    'status' => 'success',
    'data' => $results
]);
