<?php
require_once 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if (!isset($_GET['ids'])) {
    echo json_encode(['status' => 'error', 'message' => 'Missing IDs']);
    exit;
}

$ids = explode(',', $_GET['ids']);
if (empty($ids)) {
    echo json_encode(['status' => 'error', 'message' => 'Empty IDs']);
    exit;
}

try {
    // Generate placeholders for IN clause
    $placeholders = str_repeat('?,', count($ids) - 1) . '?';

    // Fetch requests info + items
    // We need to group by Request (Person) -> Items
    $sql = "SELECT 
                r.id as request_id, 
                r.request_name, 
                CONCAT(u.prefix, u.firstname, ' ', u.lastname) as requester_name,
                u.section,
                ri.id as item_id, 
                ri.qty, 
                ri.approved_qty, 
                ri.edit_reason, 
                ri.is_deleted, 
                s.name as supply_name, 
                s.unit, 
                s.image, 
                s.code
            FROM requests r
            JOIN users u ON r.user_id = u.id
            JOIN request_items ri ON ri.request_id = r.id
            JOIN supplies s ON ri.supply_id = s.id
            WHERE r.id IN ($placeholders) 
            AND (ri.is_deleted = 0 OR ri.is_deleted IS NULL)
            ORDER BY r.request_date ASC, r.id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Grouping
    $grouped = [];
    foreach ($rows as $row) {
        $rid = $row['request_id'];
        if (!isset($grouped[$rid])) {
            $grouped[$rid] = [
                'request_id' => $rid,
                'request_name' => $row['request_name'],
                'requester_name' => $row['requester_name'],
                'section' => $row['section'],
                'items' => []
            ];
        }

        // Handle Item Defaults
        if ($row['approved_qty'] === null) {
            $row['approved_qty'] = $row['qty'];
        }

        $grouped[$rid]['items'][] = [
            'item_id' => $row['item_id'],
            'supply_name' => $row['supply_name'],
            'code' => $row['code'],
            'unit' => $row['unit'],
            'image' => $row['image'],
            'current_qty' => $row['approved_qty'], // For Frontend Edit
            'original_qty' => $row['qty'],
            'is_deleted' => $row['is_deleted'] == 1,
            'reason' => $row['edit_reason'] ?? ''
        ];
    }

    echo json_encode(['status' => 'success', 'data' => array_values($grouped)]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>