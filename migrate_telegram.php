<?php
// migrate_telegram.php
require_once 'config.php';

try {
    $pdo->beginTransaction();
    echo "Starting migration...\n";

    // 1. Check if telegram_chat_id column exists in users table
    $stmt = $pdo->query("DESCRIBE users");
    $hasTelegramColumn = false;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($row['Field'] === 'telegram_chat_id') {
            $hasTelegramColumn = true;
            break;
        }
    }

    if (!$hasTelegramColumn) {
        $pdo->exec("ALTER TABLE users ADD COLUMN telegram_chat_id VARCHAR(100) NULL AFTER status");
        echo "Successfully added 'telegram_chat_id' column to 'users' table.\n";
    } else {
        echo "'telegram_chat_id' column already exists in 'users' table.\n";
    }

    // 2. Initialize default Telegram settings
    $settings = [
        'telegram_bot_token' => '',
        'telegram_group_chat_id' => ''
    ];

    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_key = ?");
    $insertStmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)");

    foreach ($settings as $key => $val) {
        $checkStmt->execute([$key]);
        if ($checkStmt->fetchColumn() == 0) {
            $insertStmt->execute([$key, $val]);
            echo "Successfully initialized setting '$key'.\n";
        } else {
            echo "Setting '$key' already exists.\n";
        }
    }

    $pdo->commit();
    echo "Migration completed successfully!\n";
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Migration failed: " . $e->getMessage() . "\n";
}
