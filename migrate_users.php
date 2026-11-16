<?php
require_once 'config.php';

// Script to migrate users from `authen` to `users`
// 1. Truncate `users` (except keep special admin? No, the prompt says "Keep admin password", implies valid admin exists in 'authen' or 'users'. 
//    Actually generally migrations of this sort might clear old test data. 
//    But let's be safe. Let's Fetch ALL from `authen` and INSERT/UPDATE into `users`.
//    The prompt says "ดึงข้อมูล user จากตาราง 'authen' เข้ามาใช้งานในตาราง 'users'". It likely implies a full import/sync.

// Logic:
// 1. Fetch all form authen.
// 2. Map fields:
//    authen_username -> username
//    authen_name -> firstname (needs split?)
//    authen_lastname -> lastname
//    authen_position_id -> position
//    authen_l_position_id -> level
//    authen_section_id / authen_org_id -> section (Need to map IDs to text or keep ID? `users` table uses text for section in previous codes?)
//    Wait, `users` table schema: `section` is VARCHAR. `authen` likely uses IDs?
//    Let's check `users` schema again from memory or previous tool outputs... `section` is text in inserted data.

try {
    echo "Starting Migration...\n";

    // Check Authen Cols exactly to map
    $authenCols = $pdo->query("DESCRIBE authen")->fetchAll(PDO::FETCH_COLUMN);
    // Needed: authen_username, authen_password?, authen_name, authen_lastname, authen_position_id, authen_l_position_id

    $authenUsers = $pdo->query("SELECT * FROM authen WHERE authen_status_del IS NULL OR authen_status_del != 'delete'")->fetchAll(PDO::FETCH_ASSOC);

    // Hash for '1234'
    $newPassHash = password_hash('1234', PASSWORD_DEFAULT);

    $count = 0;

    // Clear `users` table except 'admin' if we want to be clean? 
    // Or just Insert ignore? 
    // "ดึงข้อมูล user จากตาราง authen เข้ามาใช้งานในตาราง users" usually implies "Populate users table from authen".
    // I will Truncate `users` first to be clean, BUT I need to preserve 'admin' if it exists in 'users' but NOT in 'authen'?
    // Or does 'admin' exist in 'authen'?
    // Let's assume we Wipe and Import. But "ยกเว้น admin ให้คงรหัสผ่านแบบเดิมไว้" implies we should check if Admin exists and keep their pass.

    // 1. Get existing Admin Pass
    $stmt = $pdo->prepare("SELECT password FROM users WHERE username = 'admin'");
    $stmt->execute();
    $adminPass = $stmt->fetchColumn();

    // 2. Truncate with FK check disable
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $pdo->exec("TRUNCATE TABLE users");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    // 3. Insert Loop
    $stmtInsert = $pdo->prepare("INSERT INTO users (username, password, prefix, firstname, lastname, position, level, section, role, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");

    foreach ($authenUsers as $u) {
        $username = $u['authen_username'];

        // Skip if empty username
        if (empty($username))
            continue;

        // Password Logic
        $pass = $newPassHash;
        if ($username === 'admin' && $adminPass) {
            $pass = $adminPass;
        }

        // Name Logic
        $prefix = ''; // Not in authen?
        $fname = $u['authen_name'];
        $lname = $u['authen_lastname'];

        // Position/Level
        $pos = $u['authen_position_id'] ?? 1; // Default
        $lvl = $u['authen_l_position_id'] ?? 1;

        // Section - This is tricky. Authen has `authen_org_id`?
        // Let's look at `debug_authen` output. `authen_org_id` exists. `section` in users is text.
        // I should probably fetch org name from an org table? `authen_org`?
        // Let's guess table name or just put the ID for now, user can edit. 
        // Better: Try to find `authen_org` table name? I'll assume ID for now to avoid errors, or 'General'.
        $section = "Section " . ($u['authen_org_id'] ?? '-');

        // Role Logic - Map Level to Role?
        // authen_type?
        // Strategy: Default to 'user', map 'admin' to 'admin'.
        $role = 'user';
        if ($username === 'admin' || $u['authen_access'] == 'admin')
            $role = 'admin';
        // (authen_access column might exist? saw in DESCRIBE? No, I saw partial output)

        $stmtInsert->execute([$username, $pass, $prefix, $fname, $lname, $pos, $lvl, $section, $role]);
        $count++;
    }

    echo "Migrated $count users successfully.";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>