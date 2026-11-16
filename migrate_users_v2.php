<?php
require_once 'config.php';

try {
    echo "Starting Migration...\n";

    // 1. Fetch Users from OLD DB Format if in same DB, or check if 'authen' has rows.
    // Debug authen showed rows but migration said 0.
    // Check condition: authen_status_del IS NULL OR authen_status_del != 'delete'
    // Maybe data is 'Delete' or 'DELETE' or something else? Or table is empty?

    // Simpler query first
    $count = $pdo->query("SELECT COUNT(*) FROM authen")->fetchColumn();
    echo "Total rows in authen: $count\n";

    $authenUsers = $pdo->query("SELECT * FROM authen")->fetchAll(PDO::FETCH_ASSOC);

    // Hash for '1234'
    $newPassHash = password_hash('1234', PASSWORD_DEFAULT);

    // Admin Pass PRESERVE
    // Just hardcode admin pass or let it reset if not found.
    // Assuming admin exists in users table currently.
    $stmt = $pdo->prepare("SELECT password FROM users WHERE username = 'admin'");
    $stmt->execute();
    $adminPass = $stmt->fetchColumn();
    if (!$adminPass)
        echo "Admin not found in current users table, will use default hash.\n";

    // Truncate
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $pdo->exec("TRUNCATE TABLE users");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    $stmtInsert = $pdo->prepare("INSERT INTO users (username, password, prefix, firstname, lastname, position, level, section, role, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");

    $imported = 0;

    foreach ($authenUsers as $u) {
        // Filter logic?
        if (!empty($u['authen_status_del']) && strtolower($u['authen_status_del']) == 'delete')
            continue;

        $username = isset($u['authen_u']) ? trim($u['authen_u']) : '';
        if (empty($username)) {
            // echo "Skipping row with no username.\n";
            continue;
        }

        // Password
        $pass = $newPassHash;
        if ($username === 'admin') {
            // Admin pass preservation logic
            if ($adminPass)
                $pass = $adminPass;
            else
                echo "Admin reset to 1234.\n";
        }

        $prefix = '';
        $fname = $u['authen_name'] ?? $u['authen_f_name'] ?? '-';
        $lname = $u['authen_lastname'] ?? $u['authen_l_name'] ?? '-';
        $pos = $u['authen_position_id'] ?? 1;
        $lvl = $u['authen_l_position_id'] ?? 1;
        $section = "Section " . ($u['authen_org_id'] ?? '-');

        // Role Mapping
        // Check if `authen_super_user` exists?
        $role = 'user';
        if ($username === 'admin' || (!empty($u['authen_super_user']) && $u['authen_super_user'] == 1)) {
            $role = 'admin';
        }

        try {
            $stmtInsert->execute([$username, $pass, $prefix, $fname, $lname, $pos, $lvl, $section, $role]);
            $imported++;
        } catch (Exception $e) {
            echo "Failed to insert $username: " . $e->getMessage() . "\n";
        }
    }

    echo "Migration Complete. Imported $imported users.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>