<?php
session_start();
require_once 'config.php';

$timeout = isset($_GET['timeout']) && $_GET['timeout'] == '1';

if (isset($_SESSION['user_id'])) {
    try {
        $detail = $timeout ? 'ออกจากระบบอัตโนมัติเนื่องจากไม่มีการใช้งานเกิน 1 ชั่วโมง' : 'ผู้ใช้งานออกจากระบบด้วยตนเอง';
        logAction($pdo, $_SESSION['user_id'], 'logout', 'logout.php', $detail);
    } catch (Exception $e) {}
}

$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}
session_destroy();

$redirectUrl = $timeout ? 'index.html' : 'index.php';
header("Location: " . $redirectUrl);
exit;
