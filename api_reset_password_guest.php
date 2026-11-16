<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Request']);
    exit;
}

$username = trim($_POST['username'] ?? '');

if (empty($username)) {
    echo json_encode(['status' => 'error', 'message' => 'กรุณาระบุ Username']);
    exit;
}

if (strtolower($username) === 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'ไม่อนุญาตให้รีเซ็ตรหัสผ่านของบัญชี admin']);
    exit;
}

try {
    // 1. ตรวจสอบ/สร้างตารางเก็บประวัติการรีเซ็ตรหัสผ่านชั่วคราว หากยังไม่มี
    $db_driver = getenv('DB_DRIVER') ?: 'mysql';
    if ($db_driver === 'pgsql') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_password_resets (
            id SERIAL PRIMARY KEY,
            user_id INT NOT NULL,
            temp_password VARCHAR(255) NOT NULL,
            reset_date DATE NOT NULL,
            created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
            is_active INT DEFAULT 1
        )");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            temp_password VARCHAR(255) NOT NULL,
            reset_date DATE NOT NULL,
            created_at DATETIME NOT NULL,
            is_active TINYINT(1) DEFAULT 1,
            KEY idx_user_date (user_id, reset_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    // 2. ตรวจสอบข้อมูลผู้ใช้งาน (ค้นหาแบบไม่สนตัวพิมพ์เล็ก-ใหญ่)
    $stmt = $pdo->prepare("SELECT id, username, role, password, status FROM users WHERE LOWER(username) = LOWER(?)");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูลผู้ใช้งานนี้ในระบบ']);
        exit;
    }

    if ($user['role'] === 'admin') {
        echo json_encode(['status' => 'error', 'message' => 'ไม่อนุญาตให้รีเซ็ตรหัสผ่านของผู้ดูแลระบบ']);
        exit;
    }

    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');

    // เคลียร์ประวัติการล็อกอินผิดพลาด (delay_false_login) เพื่อให้เข้าใช้งานได้ทันที
    try {
        $clearDelay = $pdo->prepare("DELETE FROM delay_false_login WHERE LOWER(username) = LOWER(?)");
        $clearDelay->execute([$user['username']]);
    } catch (Exception $e) {}

    // 3. ตรวจสอบว่าในวันนี้ (ก่อนเที่ยงคืน) เคยขอรีเซ็ตรหัสผ่านไปแล้วหรือไม่
    $stmtReset = $pdo->prepare("SELECT * FROM user_password_resets WHERE user_id = ? AND reset_date = ? AND is_active = 1 ORDER BY id DESC LIMIT 1");
    $stmtReset->execute([$user['id'], $today]);
    $existingReset = $stmtReset->fetch();

    if ($existingReset && !empty($existingReset['temp_password'])) {
        $tempPass = trim($existingReset['temp_password']);

        // อัปเดตรหัสผ่านในฐานข้อมูลให้เป็นค่า hash ของ temp_password เสมอ เพื่อรับประกันว่าตรงกันแน่นอน 100%
        $hash = password_hash($tempPass, PASSWORD_DEFAULT);
        $updateUser = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $updateUser->execute([$hash, $user['id']]);

        logAction($pdo, $user['id'], 'password_reset_repeat_today', 'api_reset_password_guest.php', 'User requested existing same-day reset password');

        echo json_encode([
            'status' => 'success',
            'is_same_day' => true,
            'new_password' => $tempPass,
            'reset_date' => $existingReset['reset_date'],
            'created_at' => $existingReset['created_at'],
            'message' => 'คุณได้ขอรีเซ็ตรหัสผ่านไปแล้วในวันนี้ (ระบบรีเซ็ตสิทธิ์ทุกเที่ยงคืน) รหัสผ่านปัจจุบันที่คุณขอไปล่าสุดคือ'
        ]);
        exit;
    }

    // 4. หากเป็นการขอครั้งแรกของวันนี้ หรือข้ามวันใหม่แล้ว ให้สุ่มรหัสผ่านใหม่ 8 หลัก (อ่านง่าย ไม่สับสน)
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $newPass = substr(str_shuffle($chars), 0, 8);

    // แฮชรหัสผ่านใหม่
    $hash = password_hash($newPass, PASSWORD_DEFAULT);

    // บันทึกลงฐานข้อมูลตาราง users ทันที
    $update = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
    $update->execute([$hash, $user['id']]);

    // ปิดสถานะรายการรีเซ็ตเดิมของผู้ใช้นี้
    $deactivate = $pdo->prepare("UPDATE user_password_resets SET is_active = 0 WHERE user_id = ?");
    $deactivate->execute([$user['id']]);

    // บันทึกลงตาราง user_password_resets ทันที
    $insert = $pdo->prepare("INSERT INTO user_password_resets (user_id, temp_password, reset_date, created_at, is_active) VALUES (?, ?, ?, ?, 1)");
    $insert->execute([$user['id'], $newPass, $today, $now]);

    logAction($pdo, $user['id'], 'password_reset_new', 'api_reset_password_guest.php', 'User generated new reset password');

    // ส่งรหัสผ่านกลับไปแสดงผล
    echo json_encode([
        'status' => 'success',
        'is_same_day' => false,
        'new_password' => $newPass,
        'reset_date' => $today,
        'created_at' => $now,
        'message' => 'รีเซ็ตรหัสผ่านใหม่เรียบร้อยแล้ว'
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()]);
}
?>
