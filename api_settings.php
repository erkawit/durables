<?php
// api_settings.php
session_start();
require_once 'config.php';

// Check permission: Admin only
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'admin') {
    header('Content-Type: application/json; charset=utf-8');
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['status' => 'error', 'message' => 'ไม่มีสิทธิ์เข้าถึง']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    // Handle Telegram Test Message Action
    if (isset($_POST['action']) && $_POST['action'] === 'test_telegram') {
        require_once 'telegram_helper.php';
        $bot_token = trim($_POST['telegram_bot_token'] ?? '');
        $chat_id = trim($_POST['telegram_group_chat_id'] ?? '');

        if (empty($bot_token) || empty($chat_id)) {
            echo json_encode(['status' => 'error', 'message' => 'กรุณากรอกทั้ง Telegram Bot Token และ Telegram Group Chat ID ก่อนทำการทดสอบ']);
            exit;
        }

        $testMsg = "🔔 <b>ทดสอบการเชื่อมต่อระบบแจ้งเตือน Telegram สำเร็จ</b>\n";
        $testMsg .= "ระบบ: <b>" . htmlspecialchars($sys_settings['site_name_th'] ?? 'ระบบเบิกจ่ายวัสดุ') . "</b>\n";
        $testMsg .= "วันเวลาที่ทดสอบ: <code>" . date('d/m/Y H:i:s') . "</code>\n";
        $testMsg .= "ผู้ทดสอบ: <b>" . htmlspecialchars($_SESSION['fullname'] ?? $_SESSION['username'] ?? 'ผู้ดูแลระบบ') . "</b>\n";
        $testMsg .= "✅ <i>การตั้งค่า Telegram ถูกต้องและพร้อมรับการแจ้งเตือน</i>";

        $res = sendTelegramDirect($bot_token, $chat_id, $testMsg);
        if ($res['success']) {
            echo json_encode(['status' => 'success', 'message' => 'ส่งข้อความทดสอบไปยัง Telegram เรียบร้อยแล้ว! ตรวจสอบข้อความในกลุ่มของคุณ']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถส่งข้อความได้: ' . $res['message']]);
        }
        exit;
    }
    $site_name_th = trim($_POST['site_name_th'] ?? '');
    $site_name_en = trim($_POST['site_name_en'] ?? '');
    $theme_color = trim($_POST['theme_color'] ?? '#17365a');
    $telegram_bot_token = trim($_POST['telegram_bot_token'] ?? '');
    $telegram_bot_username = trim($_POST['telegram_bot_username'] ?? '');
    $telegram_group_chat_id = trim($_POST['telegram_group_chat_id'] ?? '');
    $node_service_enabled = isset($_POST['node_service_enabled']) ? '1' : '0';

    // Simple validation
    if (empty($site_name_th) || empty($site_name_en)) {
        echo json_encode(['status' => 'error', 'message' => 'กรุณากรอกชื่อระบบทั้งภาษาไทยและภาษาอังกฤษ']);
        exit;
    }

    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $theme_color)) {
        echo json_encode(['status' => 'error', 'message' => 'รูปแบบสีไม่ถูกต้อง (ต้องเป็น #ตามด้วยเลขฐานสิบหก 6 หลัก)']);
        exit;
    }

    $db_updates = [
        'site_name_th' => $site_name_th,
        'site_name_en' => $site_name_en,
        'theme_color' => $theme_color,
        'telegram_bot_token' => $telegram_bot_token,
        'telegram_bot_username' => $telegram_bot_username,
        'telegram_group_chat_id' => $telegram_group_chat_id,
        'node_service_enabled' => $node_service_enabled
    ];

    // Ensure img directory exists
    if (!file_exists('img')) {
        mkdir('img', 0755, true);
    }

    // Handle Logo upload
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $logo_tmp = $_FILES['logo']['tmp_name'];
        $logo_name = $_FILES['logo']['name'];
        $ext = strtolower(pathinfo($logo_name, PATHINFO_EXTENSION));
        $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
        
        if (in_array($ext, $allowed_exts)) {
            $dest_name = 'logo_' . time() . '.' . $ext;
            $dest_path = 'img/' . $dest_name;
            if (move_uploaded_file($logo_tmp, $dest_path)) {
                $db_updates['logo_path'] = $dest_path;
            } else {
                echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถบันทึกไฟล์โลโก้ได้']);
                exit;
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'ประเภทไฟล์โลโก้ไม่ถูกต้อง (อนุญาตเฉพาะ JPG, PNG, GIF, WEBP, SVG)']);
            exit;
        }
    }

    // Handle Favicon upload
    if (isset($_FILES['favicon']) && $_FILES['favicon']['error'] === UPLOAD_ERR_OK) {
        $favicon_tmp = $_FILES['favicon']['tmp_name'];
        $favicon_name = $_FILES['favicon']['name'];
        $ext = strtolower(pathinfo($favicon_name, PATHINFO_EXTENSION));
        $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico'];
        
        if (in_array($ext, $allowed_exts)) {
            $dest_name = 'favicon_' . time() . '.' . $ext;
            $dest_path = 'img/' . $dest_name;
            if (move_uploaded_file($favicon_tmp, $dest_path)) {
                $db_updates['favicon_path'] = $dest_path;
            } else {
                echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถบันทึกไฟล์ favicon ได้']);
                exit;
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'ประเภทไฟล์ favicon ไม่ถูกต้อง (อนุญาตเฉพาะ JPG, PNG, GIF, WEBP, SVG, ICO)']);
            exit;
        }
    }

    // Update DB
    try {
        if (($db_driver ?? 'mysql') === 'pgsql') {
            $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP) ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = CURRENT_TIMESTAMP");
            foreach ($db_updates as $key => $val) {
                $stmt->execute([$key, $val]);
            }
        } else {
            $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            foreach ($db_updates as $key => $val) {
                $stmt->execute([$key, $val, $val]);
            }
        }
        
        // Log action
        logAction($pdo, $_SESSION['user_id'], 'update_settings', 'settings.php', 'อัปเดตการตั้งค่าระบบ');

        // Automatically send Telegram Test Message if credentials provided
        $telegramStatus = null;
        if (!empty($telegram_bot_token) && !empty($telegram_group_chat_id)) {
            require_once 'telegram_helper.php';
            $saveTestMsg = "⚙️ <b>บันทึกการตั้งค่าระบบและ Telegram เรียบร้อยแล้ว</b>\n";
            $saveTestMsg .= "ระบบ: <b>" . htmlspecialchars($site_name_th) . "</b>\n";
            $saveTestMsg .= "วันเวลา: <code>" . date('d/m/Y H:i:s') . "</code>\n";
            $saveTestMsg .= "ผู้บันทึก: <b>" . htmlspecialchars($_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Admin') . "</b>\n";
            $saveTestMsg .= "สถานะการแจ้งเตือน: <b>พร้อมใช้งาน ✅</b>";
            $tgRes = sendTelegramDirect($telegram_bot_token, $telegram_group_chat_id, $saveTestMsg);
            $telegramStatus = [
                'sent' => $tgRes['success'],
                'message' => $tgRes['message']
            ];
        }

        echo json_encode([
            'status' => 'success', 
            'message' => 'บันทึกการตั้งค่าระบบเรียบร้อยแล้ว',
            'telegram' => $telegramStatus
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในการบันทึกข้อมูล: ' . $e->getMessage()]);
    }
    exit;
} else {
    header('HTTP/1.1 405 Method Not Allowed');
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}
