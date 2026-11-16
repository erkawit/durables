<?php
// Configuration for connecting to the old database (udtc_all_app)
// This is used for fetching historical data from the legacy system

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Asia/Bangkok');

$old_db_host = 'localhost';
$old_db_user = 'root';
$old_db_pass = '';
$old_db_name = 'udtc_all_app';

try {
    $pdo_old = new PDO("mysql:host=$old_db_host;dbname=$old_db_name;charset=utf8", $old_db_user, $old_db_pass);
    $pdo_old->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo_old->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Graceful fallback - never die so AJAX/JSON endpoints don't crash with plaintext output
    $pdo_old = null;
    error_log("Connection to old database (udtc_all_app) failed: " . $e->getMessage());
}
