<?php
session_start();
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$role = strtolower($_SESSION['role'] ?? '');
$section = $_SESSION['section'] ?? '';
$action = $_GET['action'] ?? $_POST['action'] ?? 'get';

if ($action === 'ping') {
    echo json_encode(['status' => 'success', 'message' => 'session_active', 'timestamp' => time()]);
    exit;
}

try {
    // Build the queries based on role
    if ($role === 'user') {
        $sqlCount = "SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0";
        $sqlList = "SELECT * FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 20";
        $sqlUpdateAll = "UPDATE notifications SET is_read = 1 WHERE user_id = :user_id";
        $params = [':user_id' => $userId];
    } elseif ($role === 'head') {
        $sqlCount = "SELECT COUNT(*) FROM notifications n 
                     LEFT JOIN requests r ON n.related_id = r.id 
                     WHERE n.user_id = :user_id AND n.is_read = 0 
                       AND (n.related_id IS NULL OR TRIM(r.section) = TRIM(:section))";
        $sqlList = "SELECT n.* FROM notifications n 
                    LEFT JOIN requests r ON n.related_id = r.id 
                    WHERE n.user_id = :user_id 
                      AND (n.related_id IS NULL OR TRIM(r.section) = TRIM(:section)) 
                    ORDER BY n.created_at DESC LIMIT 20";
        $sqlUpdateAll = "UPDATE notifications n 
                         LEFT JOIN requests r ON n.related_id = r.id 
                         SET n.is_read = 1 
                         WHERE n.user_id = :user_id 
                           AND (n.related_id IS NULL OR TRIM(r.section) = TRIM(:section))";
        $params = [':user_id' => $userId, ':section' => $section];
    } elseif (in_array($role, ['supply_head', 'director'])) {
        $sqlCount = "SELECT COUNT(*) FROM notifications n 
                     LEFT JOIN requests r ON n.related_id = r.id 
                     WHERE n.user_id = :user_id AND n.is_read = 0 
                       AND (n.related_id IS NULL OR r.approver_head_id IS NOT NULL OR r.status IN ('pending_supply', 'pending_director', 'approved', 'completed'))";
        $sqlList = "SELECT n.* FROM notifications n 
                    LEFT JOIN requests r ON n.related_id = r.id 
                    WHERE n.user_id = :user_id 
                      AND (n.related_id IS NULL OR r.approver_head_id IS NOT NULL OR r.status IN ('pending_supply', 'pending_director', 'approved', 'completed')) 
                    ORDER BY n.created_at DESC LIMIT 20";
        $sqlUpdateAll = "UPDATE notifications n 
                         LEFT JOIN requests r ON n.related_id = r.id 
                         SET n.is_read = 1 
                         WHERE n.user_id = :user_id 
                           AND (n.related_id IS NULL OR r.approver_head_id IS NOT NULL OR r.status IN ('pending_supply', 'pending_director', 'approved', 'completed'))";
        $params = [':user_id' => $userId];
    } else {
        $sqlCount = "SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0";
        $sqlList = "SELECT * FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 20";
        $sqlUpdateAll = "UPDATE notifications SET is_read = 1 WHERE user_id = :user_id";
        $params = [':user_id' => $userId];
    }

    if ($action == 'get') {
        $stmt = $pdo->prepare($sqlCount);
        $stmt->execute($params);
        $unreadCount = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare($sqlList);
        $stmt->execute($params);
        $notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['status' => 'success', 'unread_count' => $unreadCount, 'notifications' => $notifs]);

    } elseif ($action == 'mark_read') {
        $id = $_POST['id'] ?? null;
        if ($id) {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $userId]);
        }
        echo json_encode(['status' => 'success']);

    } elseif ($action == 'mark_all_read') {
        $stmt = $pdo->prepare($sqlUpdateAll);
        $stmt->execute($params);
        echo json_encode(['status' => 'success']);

    } elseif ($action == 'count') {
        $stmt = $pdo->prepare($sqlCount);
        $stmt->execute($params);
        echo json_encode(['status' => 'success', 'unread_count' => (int)$stmt->fetchColumn()]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
