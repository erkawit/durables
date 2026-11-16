<?php
session_start();
require_once 'config.php';
header('Content-Type: application/json');

// Disable display_errors to prevent HTML injections in JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}


$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Data']);
    exit;
}

$requestId = $data['request_id'];
$action = $data['action']; // 'approve' or 'reject'
$rejectReason = $data['reject_reason'] ?? '';
$items = $data['items'] ?? []; // Array of {item_id, approved_qty, is_deleted, reason}
$sharedApprovalCode = $data['shared_approval_code'] ?? null; // For group approvals

$role = $_SESSION['role'];
$userId = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Unknown';
$mySection = $_SESSION['section'] ?? '';

// Role Authorization Check
if (!in_array($role, ['head', 'deputy_head', 'supply_head', 'supply_officer', 'director', 'admin'])) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่มีสิทธิ์ในการดำเนินการนี้']);
    exit;
}

if ($role === 'head' || $role === 'deputy_head') {
    $stmtOwner = $pdo->prepare("SELECT u.section FROM requests r JOIN users u ON r.user_id = u.id WHERE r.id = ?");
    $stmtOwner->execute([$requestId]);
    $ownerSec = trim($stmtOwner->fetchColumn() ?: '');
    if (strcasecmp($ownerSec, trim($mySection)) !== 0) {
        echo json_encode(['status' => 'error', 'message' => 'ไม่มีสิทธิ์อนุมัติคำขอของส่วนงานอื่น']);
        exit;
    }
}

// Helper function defined properly
function notify($pdo, $uid, $msg, $link, $type, $relId)
{
    if (!$uid) return;
    try {
        $pdo->prepare("INSERT INTO notifications (user_id, message, link, type, related_id) VALUES (?, ?, ?, ?, ?)")
            ->execute([$uid, $msg, $link, $type, $relId]);
    } catch (Exception $e) {}
}

// Section-deduped notification: one unread per section per type per user
function notify_section($pdo, $uid, $section, $msg, $link, $type)
{
    if (!$uid) return;
    try {
        $check = $pdo->prepare("SELECT id FROM notifications WHERE user_id=? AND is_read=0 AND type=? AND message LIKE ?");
        $check->execute([$uid, $type, "%{$section}%"]);
        if (!$check->fetch()) {
            $pdo->prepare("INSERT INTO notifications (user_id, message, link, type) VALUES (?, ?, ?, ?)")
                ->execute([$uid, $msg, $link, $type]);
        }
    } catch (Exception $e) {}
}

// Approval-code-deduped notification: one unread per code per type per user
function notify_code($pdo, $uid, $code, $msg, $link, $type, $relId)
{
    if (!$uid) return;
    try {
        $check = $pdo->prepare("SELECT id FROM notifications WHERE user_id=? AND is_read=0 AND type=? AND message LIKE ?");
        $check->execute([$uid, $type, "%{$code}%"]);
        if (!$check->fetch()) {
            $pdo->prepare("INSERT INTO notifications (user_id, message, link, type, related_id) VALUES (?, ?, ?, ?, ?)")
                ->execute([$uid, $msg, $link, $type, $relId]);
        }
    } catch (Exception $e) {}
}

try {
    $pdo->beginTransaction();

    // 1. Validate Request
    $stmt = $pdo->prepare("SELECT * FROM requests WHERE id = ?");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        throw new Exception('Request Not Found');
    }

    $currentStatus = $request['status'];
    $reqUserId = $request['user_id'];
    $reqName = $request['request_name'];

    // 2. Update Items if Approve
    $hasChanges = false;
    foreach ($items as $item) {
        $itemId = $item['item_id'];
        $newQty = $item['approved_qty'];
        $isDeleted = $item['is_deleted'];
        $reason = $item['reason'] ?? '';

        // Fetch original
        $currItemStmt = $pdo->prepare("SELECT qty, approved_qty, is_deleted FROM request_items WHERE id = ?");
        $currItemStmt->execute([$itemId]);
        $currItem = $currItemStmt->fetch(PDO::FETCH_ASSOC);

        if (!$currItem)
            continue;

        $dbQty = $currItem['qty'];

        if ($isDeleted) {
            $pdo->prepare("UPDATE request_items SET is_deleted=1, edit_reason=? WHERE id=?")->execute([$reason, $itemId]);
            $hasChanges = true;
        } else {
            if ($newQty != $dbQty) {
                $pdo->prepare("UPDATE request_items SET approved_qty=?, edit_reason=? WHERE id=?")->execute([$newQty, $reason, $itemId]);
                $hasChanges = true;
            }
        }
    }

    $useNode = ($sys_settings['node_service_enabled'] ?? '1') === '1';

    // ── 2.1 Verify stock via Node.js before proceeding ──
    if ($useNode && $action == 'approve') {
        $stmtCheckItems = $pdo->prepare("SELECT ri.supply_id, COALESCE(ri.approved_qty, ri.qty) as qty, s.name FROM request_items ri JOIN supplies s ON s.id = ri.supply_id WHERE ri.request_id = ? AND (ri.is_deleted IS NULL OR ri.is_deleted = 0)");
        $stmtCheckItems->execute([$requestId]);
        $reqItems = $stmtCheckItems->fetchAll(PDO::FETCH_ASSOC);

        $nodeItems = [];
        foreach ($reqItems as $item) {
            $nodeItems[] = [
                'supply_id' => (int)$item['supply_id'],
                'qty' => (int)$item['qty']
            ];
        }

        $ch = curl_init('http://localhost:3000/api/check-stock');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['items' => $nodeItems]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $resObj = json_decode($response, true);
            if ($resObj && $resObj['status'] === 'insufficient') {
                $msgDetails = [];
                foreach ($resObj['items'] as $ins) {
                    $msgDetails[] = "- " . $ins['name'] . " (คงเหลือจริง: " . $ins['available'] . ")";
                }
                throw new Exception("ไม่สามารถอนุมัติได้เนื่องจากพัสดุบางรายการไม่เพียงพอ:\n" . implode("\n", $msgDetails));
            }
        }
    }

    // 3. Status Transition Logic
    $newStatus = $currentStatus;
    $notificationMsg = "";
    $isDirectorApproval = false;
    $approverRoleLabel = '';

    if ($action == 'approve') {
        if ($role == 'head' || $role == 'deputy_head') {
            $newStatus = 'pending_supply';
            $roleLabel = ($role == 'deputy_head') ? 'รองหัวหน้าส่วน' : 'หัวหน้าส่วน';
            $notificationMsg = "{$roleLabel}อนุมัติคำขอ {$reqName} แล้ว";
            // Update approver info AND populate 'section' from the Head's session/user info to snapshot the origin section
            // Assuming $mySection is defined from session at top of file
            $pdo->prepare("UPDATE requests SET approver_head_id=?, approver_head_date=NOW(), section=? WHERE id=?")->execute([$userId, $mySection, $requestId]);
        } elseif ($role == 'supply_head' || $role == 'supply_officer') {
            // Check if current status is pending_director: supply_head can approve on behalf of director
            if ($currentStatus == 'pending_director' && $role == 'supply_head') {
                $newStatus = 'approved';
                $isDirectorApproval = true;
                $approverRoleLabel = 'หัวหน้าส่วนคลัง (ปฏิบัติหน้าที่แทนผู้อำนวยการฯ)';
                $notificationMsg = "หัวหน้าส่วนคลัง (ปฏิบัติหน้าที่แทนผู้อำนวยการฯ) อนุมัติคำขอ {$reqName} เรียบร้อยแล้ว";
                $pdo->prepare("UPDATE requests SET approver_director_id=?, approver_director_date=NOW() WHERE id=?")->execute([$userId, $requestId]);
            } else {
                // If approving an Internal Request (status=pending_head), also sign as HEAD
                if ($currentStatus == 'pending_head') {
                    $pdo->prepare("UPDATE requests SET approver_head_id=?, approver_head_date=NOW() WHERE id=?")->execute([$userId, $requestId]);
                }

                $newStatus = 'pending_director';
                $notificationMsg = "ฝ่ายพัสดุตรวจสอบและอนุมัติผ่านถึงผู้อำนวยการแล้ว";
                $pdo->prepare("UPDATE requests SET approver_supply_id=?, approver_supply_date=NOW() WHERE id=?")->execute([$userId, $requestId]);
            }

            // Update last_approve with shared code if provided
            if ($sharedApprovalCode) {
                $pdo->prepare("UPDATE requests SET last_approve=? WHERE id=?")->execute([$sharedApprovalCode, $requestId]);
            }
        } elseif ($role == 'director') {
            $newStatus = 'approved';
            $isDirectorApproval = true;
            $approverRoleLabel = 'ผู้อำนวยการ';
            $notificationMsg = "ผู้อำนวยการอนุมัติคำขอ {$reqName} เรียบร้อยแล้ว";
            $pdo->prepare("UPDATE requests SET approver_director_id=?, approver_director_date=NOW() WHERE id=?")->execute([$userId, $requestId]);

            // Update last_approve with shared code if provided
            if ($sharedApprovalCode) {
                $pdo->prepare("UPDATE requests SET last_approve=? WHERE id=?")->execute([$sharedApprovalCode, $requestId]);
            }
        } elseif ($role == 'admin') {
            $newStatus = 'approved';
            $notificationMsg = "ผู้ดูแลระบบอนุมัติคำขอ {$reqName} เรียบร้อยแล้ว";
            if ($sharedApprovalCode) {
                $pdo->prepare("UPDATE requests SET last_approve=? WHERE id=?")->execute([$sharedApprovalCode, $requestId]);
            }
        }
    } elseif ($action == 'reject') {
        $newStatus = 'rejected';
        $notificationMsg = "คำขอ {$reqName} ถูกปฏิเสธ";
        $pdo->prepare("UPDATE requests SET reject_reason=? WHERE id=?")->execute([$rejectReason, $requestId]);
    }

    // Update Status
    $pdo->prepare("UPDATE requests SET status=? WHERE id=?")->execute([$newStatus, $requestId]);

    // ── 3.1 Decrement physical stock on final approval ──
    if ($newStatus == 'approved') {
        if (!isset($reqItems)) {
            $stmtCheckItems = $pdo->prepare("SELECT ri.supply_id, COALESCE(ri.approved_qty, ri.qty) as qty FROM request_items ri WHERE ri.request_id = ? AND (ri.is_deleted IS NULL OR ri.is_deleted = 0)");
            $stmtCheckItems->execute([$requestId]);
            $reqItems = $stmtCheckItems->fetchAll(PDO::FETCH_ASSOC);
        }
        $stmtDeduct = $pdo->prepare("UPDATE supplies SET qty = qty - ? WHERE id = ? AND qty >= ?");
        foreach ($reqItems as $item) {
            $stmtDeduct->execute([$item['qty'], $item['supply_id'], $item['qty']]);
            if ($stmtDeduct->rowCount() === 0) {
                $sCheck = $pdo->prepare("SELECT name, qty FROM supplies WHERE id = ?");
                $sCheck->execute([$item['supply_id']]);
                $sRow = $sCheck->fetch(PDO::FETCH_ASSOC);
                $sName = $sRow['name'] ?? "ID {$item['supply_id']}";
                $sQty  = $sRow['qty'] ?? 0;
                throw new Exception("ไม่สามารถอนุมัติได้: วัสดุ '{$sName}' ในคลังไม่เพียงพอ (คงเหลือ {$sQty} แต่ต้องการตัดยอด {$item['qty']})");
            }
        }
    }

    $link = "my_requests.php?highlight=$requestId";

    // 4.1 Notify Request Owner
    $userMsg = $notificationMsg;
    if ($action == 'reject')
        $userMsg .= " (เหตุผล: $rejectReason)";
    if ($hasChanges)
        $userMsg .= " *มีการแก้ไขรายการ";

    notify($pdo, $reqUserId, $userMsg, $link, ($action == 'approve' ? 'success' : 'danger'), $requestId);

    // 4.2 Notify Admin
    $allAdmins = $pdo->query("SELECT id FROM users WHERE role='admin'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($allAdmins as $adminId) {
        if ($adminId != $userId) {
            notify($pdo, $adminId, "Admin Alert: $notificationMsg by $username", "approvals.php", 'info', $requestId);
        }
    }

    // 4.3 Notify Next Step Approver
    if ($action == 'approve') {
        $reqSection  = $request['section'] ?: $mySection;
        $approvalCode = $sharedApprovalCode ?: $reqName;

        if ($newStatus == 'pending_supply') {
            // Head approved → notify supply_head, supply_officer, director (section-deduped)
            $targets = $pdo->query("SELECT id, role FROM users WHERE role IN ('supply_head','supply_officer','director') AND status='active'")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($targets as $t) {
                if ($t['role'] == 'director') {
                    notify_section($pdo, $t['id'], $mySection, "มีรายการจากส่วน {$mySection} รออนุมัติจากฝ่ายพัสดุ", "approvals.php", 'section_pending_supply');
                } else {
                    notify_section($pdo, $t['id'], $mySection, "มีรายการรออนุมัติใหม่จากส่วนงาน {$mySection}", "approvals.php", 'section_pending_supply');
                }
            }
        } elseif ($newStatus == 'pending_director') {
            // Supply_head approved → notify director (section-deduped) + supply_officer (code-deduped)
            $directors = $pdo->query("SELECT id FROM users WHERE role='director' AND status='active'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($directors as $did) {
                notify_section($pdo, $did, $reqSection, "ฝ่ายพัสดุตรวจสอบแล้ว มีรายการจากส่วน {$reqSection} รออนุมัติจากผู้อำนวยการ", "approvals.php", 'section_pending_director');
            }
            $officers = $pdo->query("SELECT id FROM users WHERE role='supply_officer' AND status='active'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($officers as $sid) {
                notify_code($pdo, $sid, $approvalCode, "รหัส {$approvalCode} รอผู้อำนวยการอนุมัติ (ตรวจสอบโดยฝ่ายพัสดุแล้ว)", "approvals.php", 'supply_pending', $requestId);
            }
        } elseif ($newStatus == 'approved') {
            // Director or admin approved → notify supply_officer (code-deduped)
            $officers = $pdo->query("SELECT id FROM users WHERE role='supply_officer' AND status='active'")->fetchAll(PDO::FETCH_COLUMN);
            $approverLabel = ($role == 'director') ? 'ผู้อำนวยการ' : 'ผู้ดูแลระบบ';
            foreach ($officers as $sid) {
                notify_code($pdo, $sid, $approvalCode, "{$approverLabel}อนุมัติแล้ว รหัส {$approvalCode} พร้อมเบิกจ่าย", "approvals.php", 'supply_approved', $requestId);
            }
        }
    }

    $pdo->commit();

    // ── 3.2 Direct Telegram Notification & PDF Dispatch ──
    if ($isDirectorApproval && $newStatus === 'approved') {
        try {
            require_once 'telegram_helper.php';
            // Send rich text summary to Telegram
            notifyTelegramAction($pdo, $requestId, $action);
            // Automatically generate Requisition PDF and dispatch to Telegram
            $pdfTarget = !empty($sharedApprovalCode) ? $sharedApprovalCode : $requestId;
            dispatchRequisitionPdfTelegram($pdo, $pdfTarget);
        } catch (Exception $te) {
            error_log("Telegram PDF notify error in approval: " . $te->getMessage());
        }
    }

    // ── 3.3 Notify Node.js Coordinator (Optional realtime socket) ──
    if ($useNode) {
        $chNotify = curl_init('http://localhost:3000/api/on-action');
        curl_setopt($chNotify, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chNotify, CURLOPT_POST, true);
        curl_setopt($chNotify, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($chNotify, CURLOPT_POSTFIELDS, json_encode([
            'request_id' => $requestId,
            'action_type' => $action,
            'actor_id' => $userId,
            'role' => $role,
            'is_director_approval' => $isDirectorApproval,
            'approver_role_label' => $approverRoleLabel,
            'shared_approval_code' => $sharedApprovalCode,
            'section' => ($request['section'] ?: $mySection)
        ]));
        curl_setopt($chNotify, CURLOPT_TIMEOUT, 1);
        curl_setopt($chNotify, CURLOPT_CONNECTTIMEOUT, 1);
        curl_exec($chNotify);
        curl_close($chNotify);
    }

    echo json_encode(['status' => 'success']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Return Clean JSON Error
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>