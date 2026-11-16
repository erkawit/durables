<?php
// api_telegram_settings.php
session_start();
require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'กรุณาเข้าสู่ระบบก่อนทำรายการ']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = $_SESSION['user_id'];
    $telegramChatId = isset($_POST['telegram_chat_id']) ? trim($_POST['telegram_chat_id']) : '';

    // Validate chat ID format (can be numeric or empty, e.g. empty to disable)
    if (!empty($telegramChatId) && !preg_match('/^-?\d+$/', $telegramChatId)) {
        echo json_encode(['status' => 'error', 'message' => 'Chat ID ต้องเป็นตัวเลขเท่านั้น (หากต้องการเว้นว่างเพื่อปิดใช้งานสามารถลบได้)']);
        exit;
    }

    try {
        $dbVal = !empty($telegramChatId) ? $telegramChatId : null;
        $stmt = $pdo->prepare("UPDATE users SET telegram_chat_id = ? WHERE id = ?");
        $stmt->execute([$dbVal, $userId]);

        logAction($pdo, $userId, 'update_personal_telegram', 'sidebar.php', 'อัปเดต Telegram Chat ID ส่วนบุคคล');

        echo json_encode(['status' => 'success', 'message' => 'บันทึกการตั้งค่า Telegram เรียบร้อยแล้ว']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในการบันทึกข้อมูล: ' . $e->getMessage()]);
    }
    exit;
} else {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}
