<?php
session_start();
require_once 'config.php';

// Check Auth
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

// 1. Fetch Dropdown Data
try {
    // Correct Table Names based on system inspection
    $positions = $pdo->query("SELECT * FROM authen_position")->fetchAll();
    $levels = $pdo->query("SELECT * FROM authen_l_position")->fetchAll(); // Corrected from 'level'
    $sections = $pdo->query("SELECT * FROM authen_part")->fetchAll();
} catch (Exception $e) {
    die("Database Error (Fetch Dropdown): " . $e->getMessage());
}

// Hardcoded Prefix Options
$prefixes = [
    ['front_name' => 'นาย'],
    ['front_name' => 'นาง'],
    ['front_name' => 'นางสาว'],
    ['front_name' => 'ว่าที่ร้อยตรี'],
    ['front_name' => 'ดร.'],
    ['front_name' => 'ผศ.'],
    ['front_name' => 'ผศ.ดร.']
];

// 2. Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];

        if ($action === 'delete') {
            if ($_POST['id'] == $_SESSION['user_id']) {
                echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถลบบัญชีของตนเองที่กำลังเข้าสู่ระบบอยู่ได้']);
                exit;
            }
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?"); // Assuming 'users' table exists 
            $stmt->execute([$_POST['id']]);
            echo json_encode(['status' => 'success']);
            exit;
        }

        if ($action === 'save') {
            try {
                $id = $_POST['id'];
                $username = trim($_POST['username']);
                $prefix = $_POST['prefix'];
                $firstname = trim($_POST['firstname']);
                $lastname = trim($_POST['lastname']);
                $position = $_POST['position']; // ID from authen_position
                $level = $_POST['level']; // ID from authen_l_position
                $section = $_POST['section']; // Name string from authen_part
                $role = $_POST['role'];
                $status = $_POST['status'];
                $telegram_chat_id = !empty($_POST['telegram_chat_id']) ? trim($_POST['telegram_chat_id']) : null;

                // Validate Role Enum
                $valid_roles = ['admin', 'director', 'supply_head', 'head', 'deputy_head', 'user', 'supply_officer'];
                if (!in_array($role, $valid_roles))
                    $role = 'user';

                if ($id) {
                    // Update
                    $sql = "UPDATE users SET 
                                username = ?, 
                                prefix = ?, firstname = ?, lastname = ?, 
                                position = ?, level = ?, section = ?, 
                                role = ?, status = ?, telegram_chat_id = ? 
                            WHERE id = ?";
                    $params = [$username, $prefix, $firstname, $lastname, $position, $level, $section, $role, $status, $telegram_chat_id, $id];

                    // Password Reset
                    if (!empty($_POST['reset_password']) && $_POST['reset_password'] === 'true') {
                        $sql = "UPDATE users SET 
                                    username = ?, password = ?,
                                    prefix = ?, firstname = ?, lastname = ?, 
                                    position = ?, level = ?, section = ?, 
                                    role = ?, status = ?, telegram_chat_id = ? 
                                WHERE id = ?";
                        $pwd = password_hash('password', PASSWORD_DEFAULT);
                        $params = [$username, $pwd, $prefix, $firstname, $lastname, $position, $level, $section, $role, $status, $telegram_chat_id, $id];
                    }

                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);

                } else {
                    // Insert
                    $sql = "INSERT INTO users (username, password, prefix, firstname, lastname, position, level, section, role, status, telegram_chat_id)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $pwd = password_hash('password', PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$username, $pwd, $prefix, $firstname, $lastname, $position, $level, $section, $role, $status, $telegram_chat_id]);
                }

                echo json_encode(['status' => 'success']);
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            exit;
        }
    }
}

// 3. Fetch Users
// Corrected Join Table Names
$joinCast = (($db_driver ?? 'mysql') === 'pgsql') ? 'CAST(%s AS VARCHAR)' : '%s';
$posJoin = sprintf($joinCast, 'p.id');
$lvlJoin = sprintf($joinCast, 'l.id');
$sql = "SELECT u.*, 
           CONCAT(u.prefix, u.firstname, ' ', u.lastname) as fullname,
           p.position as position_name,
           l.level_position as level_name
        FROM users u 
        LEFT JOIN authen_position p ON u.position = $posJoin
        LEFT JOIN authen_l_position l ON u.level = $lvlJoin
        ORDER BY CASE u.role
            WHEN 'admin' THEN 1
            WHEN 'supply_officer' THEN 2
            WHEN 'director' THEN 3
            WHEN 'head' THEN 4
            WHEN 'supply_head' THEN 5
            WHEN 'user' THEN 6
            WHEN 'staff' THEN 7
            ELSE 99
        END, u.id ASC";

$users = $pdo->query($sql)->fetchAll();

// Helper for Role Badge
function getRoleBadge($role)
{
    $roles = [
        'admin' => '<span class="bg-purple-100 text-purple-700 px-2 py-1 rounded text-xs font-bold border border-purple-200">ผู้ดูแลระบบ</span>',
        'director' => '<span class="bg-indigo-100 text-indigo-700 px-2 py-1 rounded text-xs font-bold border border-indigo-200">ผู้อำนวยการฯ</span>',
        'supply_head' => '<span class="bg-blue-100 text-blue-700 px-2 py-1 rounded text-xs font-bold border border-blue-200">หัวหน้าส่วนคลัง</span>',
        'head' => '<span class="bg-orange-100 text-orange-700 px-2 py-1 rounded text-xs font-bold border border-orange-200">หัวหน้าส่วนงาน</span>',
        'deputy_head' => '<span class="bg-amber-100 text-amber-700 px-2 py-1 rounded text-xs font-bold border border-amber-200">รองหัวหน้าส่วน</span>',
        'user' => '<span class="bg-green-100 text-green-700 px-2 py-1 rounded text-xs font-bold border border-green-200">ผู้ใช้งานทั่วไป</span>',
        'supply_officer' => '<span class="bg-teal-100 text-teal-700 px-2 py-1 rounded text-xs font-bold border border-teal-200">เจ้าหน้าที่พัสดุ</span>',
        'staff' => '<span class="bg-green-100 text-green-700 px-2 py-1 rounded text-xs font-bold border border-green-200">ผู้ใช้งานทั่วไป</span>' // Fallback
    ];
    return $roles[$role] ?? '<span class="bg-gray-100 text-gray-500 px-2 py-1 rounded text-xs">' . $role . '</span>';
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการผู้ใช้งานระบบ</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        sky: {
                            50: '#f4f6f8',
                            100: '#e1e5ec',
                            200: '#c4ccd9',
                            300: '#9fabbe',
                            400: '#7587a1',
                            500: '#17365a',
                            600: '#112A46',
                            700: '#0c1e32',
                            800: '#081421',
                            900: '#040a11'
                        }
                    }
                }
            }
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- DataTables -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>

    <style>
        body {
            font-family: 'Sarabun', sans-serif;
        }

        .dataTables_wrapper .dataTables_length select,
        .dataTables_wrapper .dataTables_filter input {
            border: 1px solid #e2e8f0;
            padding: 0.5rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
        }

        table.dataTable.no-footer {
            border-bottom: 1px solid #e2e8f0;
        }

        .swal-wide {
            width: 800px !important;
        }
    </style>
</head>

<body class="bg-gray-50 flex h-screen overflow-hidden">

    <?php $page = 'users'; include 'sidebar.php'; ?>

    <main class="flex-1 flex flex-col overflow-hidden relative">
        <header class="bg-white shadow-sm px-6 py-4 flex items-center justify-between sticky top-0 z-20 flex-shrink-0">
            <div class="flex items-center gap-4">
                <a href="dashboard.php" class="text-gray-400 hover:text-sky-600 transition"><i
                        class="fas fa-arrow-left text-xl"></i></a>
                <h1 class="text-xl font-bold text-gray-800">จัดการผู้ใช้งานระบบ</h1>
            </div>
            <button onclick="openModal()"
                class="bg-sky-600 hover:bg-sky-700 text-white px-4 py-2 rounded-lg shadow-sm transition font-bold text-sm">
                <i class="fas fa-user-plus mr-2"></i>เพิ่มผู้ใช้งาน
            </button>
        </header>

        <div class="flex-1 overflow-y-auto p-6 w-full">

        <!-- Instructions -->
        <div class="bg-sky-700 rounded-lg p-4 mb-6 shadow-md text-white">
            <h3 class="font-bold text-lg mb-2"><i class="fas fa-info-circle mr-2"></i>คำแนะนำการใช้งาน</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm text-sky-100">
                <ul class="list-disc pl-5 space-y-1">
                    <li>เพิ่มผู้ใช้: คลิกปุ่ม "เพิ่มผู้ใช้งาน" ด้านบนขวา</li>
                    <li>แก้ไข: คลิกปุ่ม "แก้ไข" เพื่อปรับข้อมูลหรือรีเซ็ตรหัสผ่าน</li>
                </ul>
                <ul class="list-disc pl-5 space-y-1">
                    <li>ค้นหา: ใช้ช่องค้นหาเพื่อกรองข้อมูลผู้ใช้งาน</li>
                    <li>การเรียงข้อมูล: เรียงตามระดับสิทธิ์ (admin → user)</li>
                </ul>
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-lg border border-gray-100 p-6 overflow-hidden">
            <table id="usersTable" class="w-full text-left stripe hover nowrap" style="width:100%">
                <thead>
                    <tr class="bg-gray-100 text-gray-700">
                        <th class="py-3 px-4 rounded-l-lg">Username</th>
                        <th class="py-3 px-4">ชื่อ-สกุล</th>
                        <th class="py-3 px-4">ตำแหน่ง</th>
                        <th class="py-3 px-4">ส่วนงาน</th>
                        <th class="py-3 px-4">สิทธิ์การใช้งาน</th>
                        <th class="py-3 px-4 text-center">สถานะ</th>
                        <th class="py-3 px-4 rounded-r-lg text-center">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr class="border-b border-gray-50 hover:bg-sky-50 transition">
                            <td class="px-4 py-3 font-bold text-sky-600">
                                <?php echo htmlspecialchars($u['username']); ?>
                            </td>
                            <td class="px-4 py-3 font-medium text-gray-800">
                                <?php echo htmlspecialchars($u['prefix'] . $u['firstname'] . ' ' . $u['lastname']); ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-500">
                                <?php echo htmlspecialchars($u['position_name'] ?? '-'); ?>
                                <div class="text-xs text-gray-400"><?php echo htmlspecialchars($u['level_name'] ?? ''); ?>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600">
                                <?php echo htmlspecialchars($u['section']); ?>
                            </td>
                            <td class="px-4 py-3">
                                <?php echo getRoleBadge($u['role']); ?>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <?php if ($u['status'] == 'active'): ?>
                                    <span class="text-green-500 font-bold text-xs"><i
                                            class="fas fa-check-circle mr-1"></i>ปกติ</span>
                                <?php else: ?>
                                    <span class="text-red-500 font-bold text-xs"><i class="fas fa-ban mr-1"></i>ระงับ</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <div class="flex justify-center gap-2">
                                    <button
                                        onclick="openModal(<?php echo htmlspecialchars(json_encode($u), ENT_QUOTES, 'UTF-8'); ?>)"
                                        class="bg-amber-100 hover:bg-amber-200 text-amber-600 px-3 py-1.5 rounded-lg text-xs font-bold transition">
                                        <i class="fas fa-edit mr-1"></i> แก้ไข
                                    </button>
                                    <button onclick="deleteUser(<?php echo $u['id']; ?>)"
                                        class="bg-red-100 hover:bg-red-200 text-red-600 px-3 py-1.5 rounded-lg text-xs font-bold transition">
                                        <i class="fas fa-trash-alt mr-1"></i> ลบ
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        </div>

    <script>
        // Use JSON Pass from PHP to JS (Ensure proper array encoding)
        const prefixes = <?php echo json_encode($prefixes); ?>;
        const positions = <?php echo json_encode($positions); ?>;
        const levels = <?php echo json_encode($levels); ?>;
        const sections = <?php echo json_encode($sections); ?>;

        $(document).ready(function () {
            $('#usersTable').DataTable({
                responsive: true,
                order: [], // Disable initial sort to keep SQL ordering
                language: {
                    search: "ค้นหา:",
                    lengthMenu: "แสดง _MENU_ รายการ",
                    info: "แสดง _START_ ถึง _END_ จาก _TOTAL_ รายการ",
                    paginate: { first: "หน้าแรก", last: "หน้าสุดท้าย", next: "ถัดไป", previous: "ก่อนหน้า" },
                    emptyTable: "ไม่มีข้อมูลผู้ใช้งาน"
                }
            });
        });

        function openModal(user = null) {
            let isEdit = !!user;

            // Options Logic
            let roleOptions = `
                <option value="admin">ผู้ดูแลระบบ (Admin)</option>
                <option value="director">ผู้อำนวยการฯ (Director)</option>
                <option value="supply_head">หัวหน้าส่วนคลัง (Supply Head)</option>
                <option value="head">หัวหน้าส่วนงาน (Head)</option>
                <option value="deputy_head">รองหัวหน้าส่วนงาน (Deputy Head)</option>
                <option value="supply_officer">เจ้าหน้าที่พัสดุ (Supply Officer)</option>
                <option value="user">ผู้ใช้งานทั่วไป (User)</option>
            `;
            let posOptions = positions.map(p => `<option value="${p.id}">${p.position}</option>`).join('');
            let lvlOptions = levels.map(l => `<option value="${l.id}">${l.level_position}</option>`).join('');
            let sectionOptions = sections.map(s => `<option value="${s.part_name}">${s.part_name}</option>`).join('');
            let prefixOptions = prefixes.map(p => `<option value="${p.front_name}">${p.front_name}</option>`).join('');

            // Modern UI HTML - Beautified
            let htmlContent = `
                <div class="text-left font-[Sarabun]">
                    <!-- Section 1: Account Info -->
                    <div class="bg-sky-50 p-5 rounded-2xl border border-sky-100 mb-5 relative overflow-hidden">
                        <div class="absolute top-0 right-0 p-4 opacity-10"><i class="fas fa-user-shield text-6xl text-sky-600"></i></div>
                        <h4 class="text-sky-800 font-bold mb-4 flex items-center gap-2"><div class="p-1 bg-sky-200 rounded-lg"><i class="fas fa-key text-sky-700"></i></div> ข้อมูลบัญชี (Account)</h4>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5 relative z-10">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 mb-1 ml-1 uppercase tracking-wide">Username <span class="text-red-500">*</span></label>
                                <div class="relative group">
                                    <span class="absolute left-3 top-3 text-gray-400 group-focus-within:text-sky-500 transition"><i class="fas fa-user"></i></span>
                                    <input id="swal-username" class="w-full pl-10 pr-4 py-2.5 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-sky-500 focus:border-sky-500 outline-none transition shadow-sm font-bold text-gray-700" 
                                        value="${user ? user.username : ''}" placeholder="ระบุชื่อผู้ใช้งาน (ภาษาอังกฤษ)">
                                </div>
                            </div>
                            <div class="flex items-end">
                                ${isEdit ? `
                                <label class="flex items-center gap-3 cursor-pointer bg-white px-4 py-2.5 rounded-xl border border-red-100 hover:bg-red-50 hover:border-red-200 transition w-full shadow-sm group">
                                    <div class="relative flex items-center">
                                        <input type="checkbox" id="swal-reset" class="w-5 h-5 text-red-500 rounded border-gray-300 focus:ring-red-500">
                                    </div>
                                    <div class="flex flex-col">
                                        <span class="font-bold text-red-600 text-sm group-hover:text-red-700 transition">รีเซ็ตรหัสผ่าน</span>
                                        <span class="text-[10px] text-red-400">ค่าเริ่มต้น: "password"</span>
                                    </div>
                                </label>
                                ` : `
                                <div class="bg-white/80 backdrop-blur px-4 py-2.5 rounded-xl border border-gray-200 w-full text-center shadow-sm">
                                    <span class="text-gray-500 text-sm"><i class="fas fa-lock mr-1 text-gray-400"></i> รหัสผ่านเริ่มต้น: <strong class="text-sky-600">password</strong></span>
                                </div>
                                `}
                            </div>
                        </div>
                    </div>

                    <!-- Section 2: Personal Info -->
                    <div class="bg-white p-5 rounded-2xl border border-gray-100 mb-5 shadow-sm">
                        <h4 class="text-gray-700 font-bold mb-4 flex items-center gap-2"><div class="p-1 bg-gray-100 rounded-lg"><i class="fas fa-id-card text-gray-600"></i></div> ข้อมูลส่วนตัว (Personal)</h4>
                        
                        <div class="grid grid-cols-12 gap-4 mb-4">
                            <div class="col-span-12 md:col-span-3">
                                <label class="block text-xs font-bold text-gray-500 mb-1 ml-1">คำนำหน้า</label>
                                <select id="swal-prefix" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-sky-500 bg-gray-50 outline-none">${prefixOptions}</select>
                            </div>
                            <div class="col-span-12 md:col-span-4">
                                <label class="block text-xs font-bold text-gray-500 mb-1 ml-1">ชื่อจริง</label>
                                <input id="swal-firstname" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-sky-500 outline-none transition" value="${user ? user.firstname : ''}">
                            </div>
                            <div class="col-span-12 md:col-span-5">
                                <label class="block text-xs font-bold text-gray-500 mb-1 ml-1">นามสกุล</label>
                                <input id="swal-lastname" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-sky-500 outline-none transition" value="${user ? user.lastname : ''}">
                            </div>
                        </div>

                         <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 mb-1 ml-1">ตำแหน่ง (Position)</label>
                                <div class="relative">
                                    <select id="swal-position" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-sky-500 bg-white outline-none appearance-none">${posOptions}</select>
                                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-500"><i class="fas fa-chevron-down text-xs"></i></div>
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 mb-1 ml-1">ระดับ (Level)</label>
                                <div class="relative">
                                    <select id="swal-level" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-sky-500 bg-white outline-none appearance-none">${lvlOptions}</select>
                                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-500"><i class="fas fa-chevron-down text-xs"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Section 3: System Role & Status -->
                    <div class="bg-indigo-50 p-5 rounded-2xl border border-indigo-100 relative overflow-hidden">
                        <div class="absolute top-0 left-0 p-4 opacity-10"><i class="fas fa-layer-group text-6xl text-indigo-600"></i></div>
                        <h4 class="text-indigo-800 font-bold mb-4 flex items-center gap-2 relative z-10"><div class="p-1 bg-indigo-200 rounded-lg"><i class="fas fa-user-tag text-indigo-700"></i></div> สิทธิ์และสถานะ (Role & Status)</h4>
                        
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-5 relative z-10">
                             <div>
                                <label class="block text-xs font-bold text-gray-500 mb-1 ml-1">ส่วนงาน (Section)</label>
                                <div class="relative group">
                                     <span class="absolute left-3 top-2.5 text-gray-400 group-focus-within:text-indigo-500"><i class="fas fa-building"></i></span>
                                     <select id="swal-section" class="w-full pl-10 pr-3 py-2 border border-gray-200 rounded-xl focus:ring-2 focus:ring-indigo-500 bg-white outline-none shadow-sm cursor-pointer hover:border-indigo-300 transition">${sectionOptions}</select>
                                </div>
                            </div>
                             <div>
                                <label class="block text-xs font-bold text-indigo-600 mb-1 ml-1">สิทธิ์การใช้งาน (System Role)</label>
                                <div class="relative group">
                                    <span class="absolute left-3 top-2.5 text-indigo-500"><i class="fas fa-user-circle"></i></span>
                                    <select id="swal-role" class="w-full pl-10 pr-8 py-2 border-2 border-indigo-100 rounded-xl focus:ring-2 focus:ring-indigo-500 bg-white font-bold text-indigo-700 shadow-sm outline-none cursor-pointer hover:border-indigo-300 transition appearance-none">
                                        ${roleOptions}
                                    </select>
                                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-indigo-400"><i class="fas fa-chevron-down"></i></div>
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-sky-600 mb-1 ml-1">Telegram Chat ID</label>
                                <div class="relative group">
                                    <span class="absolute left-3 top-2.5 text-sky-500"><i class="fab fa-telegram"></i></span>
                                    <input id="swal-telegram" class="w-full pl-10 pr-4 py-2.5 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-sky-500 outline-none transition font-medium text-gray-700" 
                                           value="${user ? (user.telegram_chat_id || '') : ''}" placeholder="ระบุ Chat ID">
                                </div>
                            </div>
                        </div>

                        <div class="mt-5 pt-4 border-t border-indigo-200/50 relative z-10">
                             <label class="block text-xs font-bold text-gray-500 mb-2 ml-1">สถานะบัญชี</label>
                             <div class="flex gap-4">
                                <label class="flex-1 flex items-center justify-center gap-2 cursor-pointer bg-white p-2 rounded-xl border border-green-100 hover:border-green-300 hover:bg-green-50 transition shadow-sm relative overflow-hidden group">
                                    <input type="radio" name="swal-status" value="active" class="hidden peer" ${(!user || user.status === 'active') ? 'checked' : ''}>
                                    <div class="absolute inset-0 border-2 border-transparent peer-checked:border-green-500 rounded-xl"></div>
                                    <div class="w-2 h-2 rounded-full bg-gray-300 peer-checked:bg-green-500 transition"></div>
                                    <span class="text-sm font-medium text-gray-600 peer-checked:text-green-700 transition">ใช้งานปกติ (Active)</span>
                                </label>

                                <label class="flex-1 flex items-center justify-center gap-2 cursor-pointer bg-white p-2 rounded-xl border border-red-100 hover:border-red-300 hover:bg-red-50 transition shadow-sm relative overflow-hidden group">
                                    <input type="radio" name="swal-status" value="inactive" class="hidden peer" ${(user && user.status !== 'active') ? 'checked' : ''}>
                                    <div class="absolute inset-0 border-2 border-transparent peer-checked:border-red-500 rounded-xl"></div>
                                    <div class="w-2 h-2 rounded-full bg-gray-300 peer-checked:bg-red-500 transition"></div>
                                    <span class="text-sm font-medium text-gray-600 peer-checked:text-red-700 transition">ระงับการใช้งาน (Inactive)</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            Swal.fire({
                title: isEdit ? 'แก้ไขข้อมูลผู้ใช้งาน' : 'เพิ่มผู้ใช้งานใหม่',
                width: '850px',
                html: htmlContent,
                didOpen: () => {
                    if (user) {
                        $('#swal-prefix').val(user.prefix);
                        $('#swal-position').val(user.position);
                        $('#swal-level').val(user.level);
                        $('#swal-section').val(user.section);

                        // Robust Role Selection
                        let userRole = user.role;
                        if (!userRole || userRole === 'staff') userRole = 'user';

                        // Set Value
                        $('#swal-role').val(userRole);

                        // Fallback check
                        if (!$('#swal-role').val()) $('#swal-role').val('user');
                    }
                },
                showCancelButton: true,
                confirmButtonText: isEdit ? 'บันทึกการเปลี่ยนแปลง' : 'ยืนยันการเพิ่มข้อมูล',
                confirmButtonColor: '#0ea5e9',
                cancelButtonText: 'ยกเลิก',
                cancelButtonColor: '#cbd5e1',
                padding: '2em',
                customClass: {
                    popup: 'rounded-3xl shadow-2xl',
                    title: 'text-2xl font-bold text-gray-800 mb-2',
                    confirmButton: 'rounded-xl px-6 py-3 font-bold text-sm shadow-lg shadow-sky-200',
                    cancelButton: 'rounded-xl px-6 py-3 font-bold text-sm text-gray-600'
                },
                preConfirm: () => {
                    return {
                        action: 'save',
                        id: user ? user.id : null,
                        username: $('#swal-username').val().trim(),
                        reset_password: $('#swal-reset').is(':checked'),
                        prefix: $('#swal-prefix').val(),
                        firstname: $('#swal-firstname').val().trim(),
                        lastname: $('#swal-lastname').val().trim(),
                        position: $('#swal-position').val(),
                        level: $('#swal-level').val(),
                        section: $('#swal-section').val(),
                        role: $('#swal-role').val(),
                        telegram_chat_id: $('#swal-telegram').val().trim(),
                        status: $('input[name="swal-status"]:checked').val()
                    }
                }
            }).then((res) => {
                if (res.isConfirmed) {
                    Swal.fire({ title: 'กำลังบันทึก...', didOpen: () => Swal.showLoading() });
                    $.post('users.php', res.value, function (response) {
                        try {
                            let data = JSON.parse(response);
                            if (data.status === 'success') {
                                Swal.fire({
                                    title: 'บันทึกสำเร็จ!',
                                    text: 'ข้อมูลผู้ใช้งานได้รับการอัปเดตเรียบร้อยแล้ว',
                                    icon: 'success',
                                    confirmButtonText: 'ตกลง',
                                    confirmButtonColor: '#10b981',
                                    customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-xl px-6' }
                                }).then(() => location.reload());
                            } else {
                                Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                            }
                        } catch (e) {
                            Swal.fire('Error', 'Server Error', 'error');
                        }
                    });
                }
            });
        }

        function deleteUser(id) {
            Swal.fire({
                title: 'ยืนยันการลบ?',
                text: "คุณต้องการลบผู้ใช้งานรายนี้หรือไม่? การกระทำนี้ไม่สามารถเรียกคืนได้",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#e2e8f0',
                confirmButtonText: 'ลบผู้ใช้งาน',
                cancelButtonText: 'ยกเลิก',
                customClass: {
                    popup: 'rounded-2xl',
                    confirmButton: 'rounded-xl shadow-lg shadow-red-200',
                    cancelButton: 'rounded-xl text-gray-600'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    $.post('users.php', { action: 'delete', id: id }, function (res) {
                        location.reload();
                    });
                }
            })
        }
    </script>
    </main>
</body>

</html>