<?php
require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $oldPass = trim($_POST['old_password'] ?? '');
    $newPass = trim($_POST['new_password'] ?? '');
    $confirmPass = trim($_POST['confirm_password'] ?? '');

    // ตรวจสอบว่าผู้ใช้อยู่ในสถานะบังคับเปลี่ยนรหัสผ่านหรือไม่ (ทั้งจาก Session และตาราง user_password_resets)
    $isForced = !empty($_SESSION['force_password_change']);
    if (!$isForced && isset($_SESSION['user_id'])) {
        try {
            $checkReset = $pdo->prepare("SELECT id FROM user_password_resets WHERE user_id = ? AND is_active = 1 LIMIT 1");
            $checkReset->execute([$_SESSION['user_id']]);
            if ($checkReset->fetch()) {
                $isForced = true;
            }
        } catch (Exception $e) {}
    }

    // ถ้าไม่ใช่กรณีบังคับเปลี่ยนรหัสผ่าน (เป็นการเปลี่ยนรหัสผ่านตามปกติ) ต้องตรวจสอบรหัสผ่านเดิม
    if (!$isForced) {
        if (empty($oldPass)) {
            echo json_encode(['status' => 'error', 'message' => 'กรุณากรอกรหัสผ่านเดิม']);
            exit;
        }

        try {
            $stmtUser = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $stmtUser->execute([$_SESSION['user_id']]);
            $user = $stmtUser->fetch();

            $oldPassValid = false;
            if ($user && password_verify($oldPass, $user['password'])) {
                $oldPassValid = true;
            } else {
                // ตรวจสอบเพิ่มเติมว่าตรงกับ temp_password ในตาราง user_password_resets หรือไม่ เพื่อป้องกันข้อผิดพลาด
                $checkTemp = $pdo->prepare("SELECT id FROM user_password_resets WHERE user_id = ? AND temp_password = ? LIMIT 1");
                $checkTemp->execute([$_SESSION['user_id'], $oldPass]);
                if ($checkTemp->fetch()) {
                    $oldPassValid = true;
                }
            }

            if (!$oldPassValid) {
                echo json_encode(['status' => 'error', 'message' => 'รหัสผ่านเดิมไม่ถูกต้อง']);
                exit;
            }
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในการตรวจสอบรหัสผ่านเดิม']);
            exit;
        }
    }

    if (strlen($newPass) < 6) {
        echo json_encode(['status' => 'error', 'message' => 'รหัสผ่านใหม่ต้องมีความยาวอย่างน้อย 6 ตัวอักษร']);
        exit;
    }

    if ($newPass !== $confirmPass) {
        echo json_encode(['status' => 'error', 'message' => 'รหัสผ่านใหม่และรหัสผ่านยืนยันไม่ตรงกัน']);
        exit;
    }

    // อัปเดตรหัสผ่านใหม่
    try {
        $hash = password_hash($newPass, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hash, $_SESSION['user_id']]);

        // ปิดสถานะรหัสผ่านชั่วคราวทั้งหมดของผู้ใช้นี้ใน user_password_resets
        try {
            $stmtReset = $pdo->prepare("UPDATE user_password_resets SET is_active = 0 WHERE user_id = ?");
            $stmtReset->execute([$_SESSION['user_id']]);
        } catch (Exception $e) {}

        // ล้างตัวแปร session บังคับเปลี่ยนรหัสผ่าน
        unset($_SESSION['force_password_change']);
        unset($_SESSION['force_password_reason']);

        logAction($pdo, $_SESSION['user_id'], 'change_password_success', 'api_change_password.php', 'Password changed successfully' . ($isForced ? ' (Forced)' : ''));

        echo json_encode(['status' => 'success', 'message' => 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในการบันทึกข้อมูล']);
    }
    exit;
}
?>
