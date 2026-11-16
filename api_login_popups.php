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

$role = strtolower($_SESSION['role'] ?? '');
$userId = $_SESSION['user_id'];
$section = trim($_SESSION['section'] ?? '');

$action = $_GET['action'] ?? $_POST['action'] ?? 'get_popups';

try {
    if ($action === 'get_popups') {
        $popups = [];

        if ($role == 'user') {
            // Find requests that are approved but not acknowledged by user
            $stmt = $pdo->prepare("SELECT id, request_name, request_date, status FROM requests WHERE user_id = ? AND status = 'approved' AND (user_acknowledged = 0 OR user_acknowledged IS NULL) ORDER BY request_date ASC LIMIT 1");
            $stmt->execute([$userId]);
            $approvedReq = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($approvedReq) {
                // Fetch items
                $stmtItem = $pdo->prepare("SELECT COALESCE(ri.approved_qty, ri.qty) AS approved_qty, s.name, s.unit FROM request_items ri JOIN supplies s ON ri.supply_id = s.id WHERE ri.request_id = ? AND (ri.is_deleted IS NULL OR ri.is_deleted = 0)");
                $stmtItem->execute([$approvedReq['id']]);
                $items = $stmtItem->fetchAll(PDO::FETCH_ASSOC);

                $popups[] = [
                    'type' => 'user_approved',
                    'request_id' => $approvedReq['id'],
                    'request_name' => $approvedReq['request_name'],
                    'date' => $approvedReq['request_date'],
                    'items' => $items
                ];
            } else {
                // Find requests in progress
                $stmt = $pdo->prepare("SELECT id, request_name, request_date, status FROM requests WHERE user_id = ? AND status IN ('pending_head', 'pending_supply', 'pending_director') ORDER BY request_date DESC");
                $stmt->execute([$userId]);
                $pendingReqs = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (count($pendingReqs) > 0) {
                    $popups[] = [
                        'type' => 'user_progress',
                        'requests' => $pendingReqs
                    ];
                }
            }
        } elseif ($role == 'head') {
            $stmt = $pdo->prepare("SELECT r.id, r.request_name, r.request_date, u.firstname, u.lastname FROM requests r JOIN users u ON r.user_id = u.id WHERE r.status = 'pending_head' AND TRIM(u.section) = ? ORDER BY r.request_date ASC");
            $stmt->execute([$section]);
            $pendingReqs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($pendingReqs) > 0) {
                $popups[] = [
                    'type' => 'head_pending',
                    'requests' => $pendingReqs
                ];
            }
        } elseif ($role == 'supply_head') {
            $stmt = $pdo->prepare("SELECT r.id, r.request_name, r.request_date, r.section, u.firstname, u.lastname FROM requests r JOIN users u ON r.user_id = u.id WHERE (r.status = 'pending_supply' OR (r.status = 'pending_head' AND u.section = 'ส่วนคลัง')) ORDER BY r.request_date ASC");
            $stmt->execute();
            $pendingReqs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($pendingReqs) > 0) {
                $popups[] = [
                    'type' => 'supply_head_pending',
                    'requests' => $pendingReqs
                ];
            }
        } elseif ($role == 'director') {
            $stmt = $pdo->prepare("SELECT r.id, r.request_name, r.request_date, r.section, u.firstname, u.lastname FROM requests r JOIN users u ON r.user_id = u.id WHERE r.status = 'pending_director' ORDER BY r.request_date ASC");
            $stmt->execute();
            $pendingReqs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($pendingReqs) > 0) {
                $popups[] = [
                    'type' => 'director_pending',
                    'requests' => $pendingReqs
                ];
            }
        }

        echo json_encode(['status' => 'success', 'popups' => $popups]);
        exit;

    } elseif ($action === 'acknowledge_user_approval') {
        $requestId = $_POST['request_id'] ?? 0;
        if ($requestId && $role == 'user') {
            $stmt = $pdo->prepare("UPDATE requests SET user_acknowledged = 1, user_acknowledged_date = NOW() WHERE id = ? AND user_id = ?");
            $stmt->execute([$requestId, $userId]);
            
            // Notify Supply Officer
            $check = $pdo->prepare("SELECT request_name FROM requests WHERE id = ?");
            $check->execute([$requestId]);
            $req = $check->fetch(PDO::FETCH_ASSOC);
            if ($req) {
                $msg = "ผู้เบิกรับทราบการอนุมัติแล้ว: " . $req['request_name'];
                $officers = $pdo->query("SELECT id FROM users WHERE role='supply_officer' AND status='active'")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($officers as $sid) {
                    try {
                        $pdo->prepare("INSERT INTO notifications (user_id, message, link, type, related_id) VALUES (?, ?, ?, ?, ?)")
                            ->execute([$sid, $msg, "approvals.php", "info", $requestId]);
                    } catch (Exception $e) {}
                }
            }
            
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Invalid parameters']);
        }
        exit;
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
?>
