<?php
// api_send_telegram_pdf.php - Generate requisition PDF and send/resend to Telegram
ini_set('display_errors', 0);
error_reporting(0);
if (ob_get_level() == 0) {
    ob_start();
}

// Emergency Shutdown Handler to always return JSON even on fatal PHP errors
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'status' => 'error',
            'message' => 'เกิดข้อผิดพลาดในการประมวลผลเซิร์ฟเวอร์: ' . $err['message']
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
});

if (!function_exists('sendJsonResponse')) {
    function sendJsonResponse($data) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
require_once 'telegram_helper.php';

if (!isset($_SESSION['user_id'])) {
    sendJsonResponse(['status' => 'error', 'message' => 'กรุณาเข้าสู่ระบบก่อนดำเนินการ']);
}

$currentUserId = $_SESSION['user_id'];
$currentUserRole = $_SESSION['role'] ?? 'user';

// Close session writing immediately to prevent deadlock with headless Chrome requests
session_write_close();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(['status' => 'error', 'message' => 'Invalid request method']);
}

$id = trim($_POST['id'] ?? '');
if (!empty($id)) {
    $id = urldecode($id);
}

if (empty($id)) {
    sendJsonResponse(['status' => 'error', 'message' => 'ไม่ได้ระบุรหัสใบเบิก']);
}

try {
    // 1. Fetch Telegram System Settings
    $settings = getTelegramSystemSettings($pdo);
    $botToken = trim($settings['telegram_bot_token'] ?? '');
    $groupChatId = trim($settings['telegram_group_chat_id'] ?? '');

    if (empty($botToken)) {
        sendJsonResponse([
            'status' => 'error', 
            'message' => 'ยังไม่ได้ตั้งค่า Telegram Bot Token ในระบบ กรุณาตรวจสอบที่เมนู "ตั้งค่าระบบ"'
        ]);
    }

    // 2. Fetch Request & Items Details (support single ID, multiple IDs, or group approval code)
    $rawCodes = array_values(array_filter(array_map('trim', explode(',', $id))));
    $numericIds = [];
    $stringCodes = [];
    foreach ($rawCodes as $c) {
        if (is_numeric($c)) {
            $numericIds[] = (int)$c;
        } else {
            $stringCodes[] = $c;
        }
    }

    $whereParts = [];
    $params = [];
    if (!empty($stringCodes)) {
        $inStr = implode(',', array_fill(0, count($stringCodes), '?'));
        $whereParts[] = "r.last_approve IN ($inStr) OR r.request_name IN ($inStr)";
        $params = array_merge($params, $stringCodes, $stringCodes);
    }
    if (!empty($numericIds)) {
        $inNum = implode(',', array_fill(0, count($numericIds), '?'));
        $whereParts[] = "r.id IN ($inNum)";
        $params = array_merge($params, $numericIds);
    }
    if (empty($whereParts)) {
        $whereParts[] = "r.last_approve = ? OR r.request_name = ?";
        $params = [$id, $id];
    }

    $sqlRequests = "SELECT r.*, 
                           CONCAT(u.prefix, u.firstname, ' ', u.lastname) as requester_name,
                           u.section as requester_section,
                           u.telegram_chat_id as requester_telegram
                    FROM requests r
                    LEFT JOIN users u ON r.user_id = u.id
                    WHERE (" . implode(' OR ', $whereParts) . ")
                    ORDER BY r.id ASC";
    $stmtReqs = $pdo->prepare($sqlRequests);
    $stmtReqs->execute($params);
    $requests = $stmtReqs->fetchAll(PDO::FETCH_ASSOC);

    $isOldSystem = false;
    $oldWithdrawData = [];

    if (empty($requests)) {
        $sqlOld = "SELECT w.*, 
                          CONCAT(an.authen_name, ' ', an.authen_lname) as requester_name,
                          ap.part_name as requester_section
                   FROM withdraw w
                   LEFT JOIN authen_name an ON w.user_withdraw = an.id
                   LEFT JOIN authen_part ap ON an.part_id = ap.id
                   WHERE w.last_approve = ? OR w.withdraw_code = ?
                   ORDER BY w.id ASC";

        // Check main database ($pdo) first
        try {
            $stmtOld = $pdo->prepare($sqlOld);
            $stmtOld->execute([$id, $id]);
            $oldWithdrawData = $stmtOld->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($oldWithdrawData)) {
                $isOldSystem = true;
            }
        } catch (Throwable $e) {}

        // Fallback to legacy database if available
        if (empty($oldWithdrawData)) {
            if (!isset($pdo_old)) {
                try {
                    @include_once 'config2.php';
                } catch (Throwable $e) {}
            }
            if (isset($pdo_old)) {
                try {
                    $stmtOld = $pdo_old->prepare($sqlOld);
                    $stmtOld->execute([$id, $id]);
                    $oldWithdrawData = $stmtOld->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($oldWithdrawData)) {
                        $isOldSystem = true;
                    }
                } catch (Throwable $e) {}
            }
        }
    }

    if (empty($requests) && !$isOldSystem) {
        sendJsonResponse(['status' => 'error', 'message' => 'ไม่พบข้อมูลรายการขอเบิกที่ระบุ']);
    }

    // Check status: must be approved
    if (!$isOldSystem) {
        $hasApproved = false;
        foreach ($requests as $r) {
            if ($r['status'] === 'approved') {
                $hasApproved = true;
                break;
            }
        }
        if (!$hasApproved) {
            sendJsonResponse([
                'status' => 'error', 
                'message' => 'รายการนี้ยังไม่ได้รับการอนุมัติเสร็จสิ้น ไม่สามารถส่งใบเบิกได้'
            ]);
        }
    }

    // Permission check: Admin, Supply Officers, or Requester
    $canSend = in_array($currentUserRole, ['admin', 'supply_officer', 'supply_head', 'director', 'head']);
    if (!$canSend && !$isOldSystem) {
        foreach ($requests as $r) {
            if ($r['user_id'] == $currentUserId) {
                $canSend = true;
                break;
            }
        }
    }
    if (!$canSend) {
        sendJsonResponse(['status' => 'error', 'message' => 'ท่านไม่มีสิทธิ์ในการส่งเอกสารใบเบิกนี้']);
    }

    // 3. Dispatch PDF generation and Telegram sending via centralized helper
    $result = dispatchRequisitionPdfTelegram($pdo, $id);
    sendJsonResponse($result);

} catch (Throwable $e) {
    sendJsonResponse(['status' => 'error', 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
}
