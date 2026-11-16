<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

// Logout
if (isset($_GET['logout'])) {
    // logAction($pdo, $_SESSION['user_id'], 'logout', 'dashboard.php', 'User logged out');
    session_destroy();
    header("Location: index.php");
    exit;
}

$page = $_GET['page'] ?? 'home';
$role = strtolower($_SESSION['role']);

if ($role === 'user') {
    header("Location: inventory.php");
    exit;
}
$section = $_SESSION['section'] ?? '';

// Check Cart Expiration Logic (12 Hours)
$expiredItems = [];
$showExpiryAlert = false;
$expiredItemsJson = '[]';

if (isset($_SESSION['cart']) && !empty($_SESSION['cart']) && isset($_SESSION['cart_time'])) {
    $now = time();
    $limit = 12 * 3600; // 12 hours in seconds
    $foundExpired = false;

    // Check if ANY item is expired. The requirement implies checking the whole cart or items.
    // If we find expired items, we prompt for the whole cart? User said "items in cart... > 12h".
    // Usually, we handle the whole cart session. Let's collect names of expired items.

    $expiredIds = [];
    foreach ($_SESSION['cart'] as $id => $qty) {
        $addedTime = $_SESSION['cart_time'][$id] ?? 0;
        // If addedTime is 0 (legacy item), do we expire? Let's assume NO for now to avoid annoyance, 
        // OR assume YES if we want strictness.
        // Let's assume only tracks items added since the update. 
        // Or set default time to now? No.
        if ($addedTime > 0 && ($now - $addedTime) > $limit) {
            $foundExpired = true;
            $expiredIds[] = $id;
        }
    }

    if ($foundExpired && !empty($expiredIds)) {
        $showExpiryAlert = true;
        $inQuery = implode(',', array_fill(0, count($expiredIds), '?'));
        $stmt = $pdo->prepare("SELECT name, qty FROM supplies WHERE id IN ($inQuery)");
        $stmt->execute($expiredIds);
        $expiredItemsData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format for JS: Name (Qty)
        $displayList = [];
        foreach ($expiredItemsData as $item) {
            $displayList[] = $item['name'];
        }
        $expiredItemsJson = json_encode($displayList);
    }
}

$dashCategories = $pdo->query("SELECT type_name FROM product_type ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
$dashCategoriesJson = json_encode($dashCategories, JSON_UNESCAPED_UNICODE);

// Pending approvals count for sidebar badge
$pendingApprovalCount = 0;
try {
    if ($role == 'head' || $role == 'deputy_head') {
        // Count distinct users (people) in same section requesting approvals
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT r.user_id) FROM requests r JOIN users u ON r.user_id=u.id WHERE r.status='pending_head' AND TRIM(u.section)=TRIM(?)");
        $stmt->execute([$section]);
        $pendingApprovalCount = (int) $stmt->fetchColumn();
    } elseif ($role == 'supply_head') {
        // Count distinct sections: pending_supply + pending_director (acting as director) + pending_head from ส่วนคลัง
        $stmt = $pdo->query("SELECT COUNT(DISTINCT u.section) FROM requests r JOIN users u ON r.user_id=u.id WHERE (r.status='pending_supply' OR r.status='pending_director' OR (r.status='pending_head' AND u.section='ส่วนคลัง'))");
        $pendingApprovalCount = (int) $stmt->fetchColumn();
    } elseif (in_array($role, ['supply_officer', 'admin'])) {
        // Count distinct sections
        $stmt = $pdo->query("SELECT COUNT(DISTINCT u.section) FROM requests r JOIN users u ON r.user_id=u.id WHERE (r.status='pending_supply' OR (r.status='pending_head' AND u.section='ส่วนคลัง'))");
        $pendingApprovalCount = (int) $stmt->fetchColumn();
    } elseif ($role == 'director') {
        // Count distinct sections
        $stmt = $pdo->query("SELECT COUNT(DISTINCT section) FROM requests WHERE status='pending_director'");
        $pendingApprovalCount = (int) $stmt->fetchColumn();
    } elseif ($role == 'user') {
        // Count distinct requests
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM requests WHERE user_id=? AND status LIKE 'pending%'");
        $stmt->execute([$_SESSION['user_id']]);
        $pendingApprovalCount = (int) $stmt->fetchColumn();
    }
} catch (Exception $e) {
    $pendingApprovalCount = 0;
}

$dashVolumes = $pdo->query("SELECT id, type_volume FROM type_volume ORDER BY id ASC")->fetchAll();
$dashVolumesJson = json_encode($dashVolumes, JSON_UNESCAPED_UNICODE);

// Role Groups
$roleGroups = [
    'admin' => ['admin'],
    'approver_top' => ['director', 'supply_head'],
    'approver_head' => ['head'],
    'user' => ['user']
];

// Helper to check role
function hasRole($needV, $role)
{
    global $roleGroups;
    if ($needV == 'admin')
        return in_array($role, $roleGroups['admin']);
    if ($needV == 'director_supply' || $needV == 'supply_head')
        return in_array($role, $roleGroups['approver_top']);
    if ($needV == 'head')
        return in_array($role, ['head', 'supply_head', 'director', 'admin']); // loosely checked
    return false;
}

// Redirect logic based on Role for "Home"
// 1. Admin => Dashboard (Keep current view)
// 2. Director/Supply Head => Approvals Pending
// 3. Head => Approvals Pending (Own Section)
// 4. User => Inventory (Shoppping)

// BUT the user wants the "Dashboard" *content* to change, not a redirect.
// So we render differently based on role below.

?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบเบิกวัสดุสำนักงาน</title>
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <style>


        body {
            font-family: 'Sarabun', sans-serif;
        }

        html.swal2-height-auto,
        body.swal2-height-auto {
            height: 100vh !important;
        }



        .dataTables_wrapper .dataTables_length select,
        .dataTables_wrapper .dataTables_filter input {
            border: 1px solid #e2e8f0;
            padding: 0.5rem;
            border-radius: 0.5rem;
        }

        table.dataTable.no-footer {
            border-bottom: 1px solid #e2e8f0;
        }

        .dt-row:hover {
            background-color: #f8fafc !important;
        }
    </style>
</head>

<body class="bg-gray-50 flex h-screen overflow-hidden">

    <!-- Sidebar -->
    <?php $page = 'home'; include 'sidebar.php'; ?>

    <!-- Main Content -->
    <main class="flex-1 flex flex-col overflow-hidden relative">
        <header
            class="bg-white/80 backdrop-blur-md sticky top-0 z-20 border-b border-gray-100 px-8 py-4 flex justify-between items-center">
            <div class="flex items-center gap-4">
                <h2 class="text-xl font-bold text-gray-800">
                    <?php
                    if ($role == 'admin') {
                        echo 'ภาพรวมระบบ (Dashboard)';
                    } elseif ($role == 'supply_officer') {
                        echo 'จัดการข้อมูลวัสดุ (Inventory)';
                    } elseif (in_array($role, ['director', 'supply_head', 'head'])) {
                        echo 'รายการรอตรวจสอบอนุมัติ';
                    } else {
                        echo 'รายการวัสดุน่าสนใจ';
                    }
                    ?>
                </h2>
            </div>
            <div class="flex items-center gap-4">
                <!-- Notification Bell -->
                <div class="relative" id="notif-wrapper">
                    <button id="notif-btn"
                        class="text-gray-500 hover:text-sky-600 transition relative p-2 rounded-xl hover:bg-gray-100"
                        aria-label="การแจ้งเตือน">
                        <i class="fas fa-bell text-xl"></i>
                        <span id="notif-badge"
                            class="hidden absolute -top-0.5 -right-0.5 bg-red-500 text-white text-[10px] font-bold min-w-[18px] h-[18px] rounded-full flex items-center justify-center px-1 leading-none">0</span>
                    </button>
                    <!-- Notification Dropdown -->
                    <div id="notif-dropdown"
                        class="hidden absolute right-0 mt-2 w-96 bg-white rounded-2xl shadow-2xl border border-gray-100 z-50 overflow-hidden">
                        <div
                            class="px-4 py-3 border-b border-gray-100 flex items-center justify-between bg-gradient-to-r from-sky-50 to-white">
                            <span class="text-sm font-bold text-gray-700 flex items-center gap-2"><i
                                    class="fas fa-bell text-sky-500"></i> การแจ้งเตือน</span>
                            <button onclick="markAllRead()"
                                class="text-xs text-sky-500 hover:text-sky-700 font-medium transition">อ่านทั้งหมด</button>
                        </div>
                        <div id="notif-list" class="max-h-80 overflow-y-auto divide-y divide-gray-50">
                            <div class="p-6 text-center text-gray-400 text-sm">กำลังโหลด...</div>
                        </div>
                    </div>
                </div>
                <div class="text-right hidden sm:block">
                    <p class="text-sm font-bold text-gray-800"><?php echo $_SESSION['fullname']; ?></p>
                    <p class="text-xs text-gray-500"><?php echo ucfirst($_SESSION['role']); ?> |
                        <?php echo $_SESSION['section']; ?>
                    </p>
                </div>
                <div
                    class="w-10 h-10 rounded-full bg-gradient-to-br from-sky-400 to-blue-500 flex items-center justify-center text-white shadow-lg shadow-sky-200">
                    <span class="font-bold text-sm"><?php echo mb_substr($_SESSION['fullname'], 0, 1); ?></span>
                </div>
            </div>
        </header>

        <div class="flex-1 overflow-y-auto p-8 scroll-smooth">

            <?php if ($role == 'admin'): ?>
                <?php
                // --- ADMIN LOGIC ---
                // 1. Filter Date Logic (Same as Supply Officer)
                $filterDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
                $thaiMonthNames = [
                    '01' => 'มกราคม',
                    '02' => 'กุมภาพันธ์',
                    '03' => 'มีนาคม',
                    '04' => 'เมษายน',
                    '05' => 'พฤษภาคม',
                    '06' => 'มิถุนายน',
                    '07' => 'กรกฎาคม',
                    '08' => 'สิงหาคม',
                    '09' => 'กันยายน',
                    '10' => 'ตุลาคม',
                    '11' => 'พฤศจิกายน',
                    '12' => 'ธันวาคม'
                ];
                list($fYear, $fMonth, $fDay) = explode('-', $filterDate);

                // 2. Fetch Daily Approved Requests
                $sqlDaily = "SELECT r.*, CONCAT(u.prefix, u.firstname, ' ', u.lastname) as fullname, u.section 
                             FROM requests r 
                             JOIN users u ON r.user_id = u.id 
                             WHERE r.status = 'approved' 
                             AND DATE(r.approver_supply_date) = ?
                             ORDER BY r.approver_supply_date DESC";
                $stmtDaily = $pdo->prepare($sqlDaily);
                $stmtDaily->execute([$filterDate]);
                $dailyRequests = $stmtDaily->fetchAll();

                // 3. Fetch Low Stock Items (Top 4)
                $sqlLow = "SELECT * FROM supplies WHERE qty <= min_stock AND status='active' ORDER BY qty ASC LIMIT 4";
                $lowStockItems = $pdo->query($sqlLow)->fetchAll();

                // 4. Fetch All Supplies
                $stmtAll = $pdo->query("SELECT * FROM supplies WHERE status = 'active' ORDER BY id DESC");
                $allSupplies = $stmtAll->fetchAll();

                // 5. Fetch Logs
                $logs = $pdo->query("SELECT l.*, u.username FROM system_logs l JOIN users u ON l.user_id = u.id ORDER BY l.id DESC LIMIT 10")->fetchAll();

                // 6. Fetch Type Volume (หน่วยนับ)
                $typeVolumes = $pdo->query("SELECT id, type_volume FROM type_volume ORDER BY id ASC")->fetchAll();
                $typeVolumesJson = json_encode($typeVolumes, JSON_UNESCAPED_UNICODE);
                ?>

                <div class="space-y-8">
                    <!-- 1. Daily Completed Approvals -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <div
                            class="p-6 border-b border-gray-100 flex flex-col md:flex-row justify-between items-center gap-4">
                            <div class="flex items-center gap-3">
                                <div
                                    class="bg-green-100 text-green-600 w-10 h-10 rounded-xl flex items-center justify-center">
                                    <i class="fas fa-check-circle text-lg"></i>
                                </div>
                                <div>
                                    <h3 class="text-lg font-bold text-gray-800">รายการอนุมัติแล้ว (ประจำวัน)</h3>
                                    <p class="text-xs text-gray-500">แสดงรายการที่ได้รับการอนุมัติเสร็จสิ้น</p>
                                </div>
                            </div>

                            <!-- Date Filter Form -->
                            <form method="GET"
                                class="flex items-center gap-2 bg-gray-50 p-2 rounded-lg border border-gray-200">
                                <input type="hidden" name="page" value="home">
                                <span class="text-xs font-bold text-gray-500 pl-2">เลือกวันที่:</span>
                                <select name="d" id="d_select"
                                    class="bg-white border text-sm rounded px-2 py-1 outline-none focus:ring-2 focus:ring-sky-400">
                                    <?php for ($i = 1; $i <= 31; $i++):
                                        $dVal = str_pad($i, 2, '0', STR_PAD_LEFT); ?>
                                        <option value="<?php echo $dVal; ?>" <?php echo $dVal == $fDay ? 'selected' : ''; ?>>
                                            <?php echo $i; ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                                <select name="m" id="m_select"
                                    class="bg-white border text-sm rounded px-2 py-1 outline-none focus:ring-2 focus:ring-sky-400">
                                    <?php foreach ($thaiMonthNames as $mKey => $mName): ?>
                                        <option value="<?php echo $mKey; ?>" <?php echo $mKey == $fMonth ? 'selected' : ''; ?>>
                                            <?php echo $mName; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="y" id="y_select"
                                    class="bg-white border text-sm rounded px-2 py-1 outline-none focus:ring-2 focus:ring-sky-400">
                                    <?php $currY = date('Y');
                                    for ($i = $currY - 1; $i <= $currY + 1; $i++):
                                        $thY = $i + 543; ?>
                                        <option value="<?php echo $i; ?>" <?php echo $i == $fYear ? 'selected' : ''; ?>>
                                            <?php echo $thY; ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                                <button type="button" onclick="applyDateFilter()"
                                    class="bg-sky-500 hover:bg-sky-600 text-white px-3 py-1 rounded text-sm font-bold transition">
                                    <i class="fas fa-filter"></i>
                                </button>
                            </form>
                            <script>
                                function applyDateFilter() {
                                    let d = document.getElementById('d_select').value;
                                    let m = document.getElementById('m_select').value;
                                    let y = document.getElementById('y_select').value;
                                    window.location.href = `dashboard.php?date=${y}-${m}-${d}`;
                                }
                            </script>
                        </div>
                        <div class="p-6">
                            <?php if (count($dailyRequests) > 0): ?>
                                <table id="dailyApprovedTableAdmin" class="w-full text-left stripe hover nowrap">
                                    <thead>
                                        <tr class="bg-green-50 text-green-800">
                                            <th class="py-3 px-4 rounded-l-lg w-16">เวลา</th>
                                            <th class="py-3 px-4">เลขที่ใบเบิก</th>
                                            <!-- <th class="py-3 px-4">ผู้เบิก</th> -->
                                            <th class="py-3 px-4">ส่วนงาน</th>
                                            <th class="py-3 px-4 text-center">สถานะ</th>
                                            <th class="py-3 px-4 text-center rounded-r-lg">รายละเอียด</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($dailyRequests as $req): ?>
                                            <tr>
                                                <td class="px-4 py-3 font-mono text-gray-600">
                                                    <?php echo date('H:i', strtotime($req['approver_supply_date'])); ?> น.
                                                </td>
                                                <!-- <td class="px-4 py-3 font-bold text-sky-600"><?php echo $req['request_name']; ?>
                                                </td> -->
                                                <td class="px-4 py-3 text-gray-800"><?php echo $req['fullname']; ?></td>
                                                <td class="px-4 py-3 text-gray-500 text-sm"><?php echo $req['section']; ?></td>
                                                <td class="px-4 py-3 text-center">
                                                    <span
                                                        class="bg-green-100 text-green-600 px-3 py-1 rounded-full text-xs font-bold">อนุมัติแล้ว</span>
                                                </td>
                                                <td class="px-4 py-3 text-center">
                                                    <button onclick="viewDetails('<?php echo $req['id']; ?>', 'new')"
                                                        class="bg-sky-100 text-sky-600 hover:bg-sky-200 px-3 py-1.5 rounded-lg transition text-sm font-bold">
                                                        <i class="fas fa-search"></i> รายละเอียด
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div
                                    class="text-center py-8 text-gray-400 bg-gray-50 rounded-xl border border-dashed border-gray-200">
                                    <i class="fas fa-calendar-times text-3xl mb-2"></i>
                                    <p>ไม่มีรายการอนุมัติในวันที่เลือก</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 2. Low Stock Alerts -->
                    <div>
                        <div class="flex justify-between items-center mb-4 px-2">
                            <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                                <i class="fas fa-exclamation-triangle text-amber-500"></i> วัสดุใกล้หมด (4 อันดับแรก)
                            </h3>
                            <a href="supplies.php"
                                class="text-sm font-bold text-sky-500 hover:text-sky-600 hover:underline">
                                ดูทั้งหมด <i class="fas fa-arrow-right ml-1"></i>
                            </a>
                        </div>
                        <?php if (count($lowStockItems) > 0): ?>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                                <?php foreach ($lowStockItems as $item):
                                    $imgSrc = !empty($item['image']) ? $item['image'] : 'img/packaging.png';
                                    ?>
                                    <div class="bg-white rounded-xl shadow-sm border border-red-100 p-4 flex gap-4 items-center hover:shadow-md transition cursor-pointer"
                                        onclick="openSupplyModal(<?php echo htmlspecialchars(json_encode($item), ENT_QUOTES, 'UTF-8'); ?>)">
                                        <div
                                            class="w-16 h-16 rounded-lg bg-gray-100 flex-shrink-0 overflow-hidden border border-gray-200">
                                            <img src="<?php echo htmlspecialchars($imgSrc); ?>"
                                                onerror="this.onerror=null;this.src='img/packaging.png'"
                                                class="w-full h-full object-cover">
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <h4 class="font-bold text-gray-800 truncate"
                                                title="<?php echo htmlspecialchars($item['name']); ?>">
                                                <?php echo htmlspecialchars($item['name']); ?>
                                            </h4>
                                            <p class="text-xs text-gray-500 font-mono mb-1"><?php echo $item['code']; ?></p>
                                            <div class="flex justify-between items-end">
                                                <span class="text-xs text-gray-400">คงเหลือ</span>
                                                <span class="text-lg font-bold text-red-500"><?php echo $item['qty']; ?> <span
                                                        class="text-xs text-gray-400 font-normal"><?php echo $item['unit']; ?></span></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="bg-green-50 rounded-xl p-6 text-center text-green-600 border border-green-100">
                                <i class="fas fa-check-circle mr-2"></i> สต็อกสินค้าปกติ ไม่มีรายการใกล้หมด
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- 3. All Supplies Table -->
                    <div class="bg-white p-6 rounded-2xl shadow-lg border border-gray-100 overflow-hidden">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-bold text-gray-800">รายการวัสดุทั้งหมด</h3>
                            <button onclick="openSupplyModal()"
                                class="bg-sky-500 hover:bg-sky-600 text-white px-4 py-2 rounded-lg text-sm font-bold shadow transition flex items-center gap-2">
                                <i class="fas fa-plus"></i> เพิ่มวัสดุใหม่
                            </button>
                        </div>
                        <table id="supplyTableAdmin" class="w-full text-left stripe hover nowrap align-middle">
                            <thead>
                                <tr class="bg-sky-50 text-sky-800">
                                    <th class="py-4 px-4 rounded-l-lg w-16">รูปภาพ</th>
                                    <th class="py-4 px-4">รหัส</th>
                                    <th class="py-4 px-4">ชื่อวัสดุ</th>
                                    <th class="py-4 px-4">หมวดหมู่</th>
                                    <th class="py-4 px-4 text-center">คงเหลือ</th>
                                    <th class="py-4 px-4 text-center">ราคา/หน่วย</th>
                                    <th class="py-4 px-4 rounded-r-lg text-center">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allSupplies as $s):
                                    $imgSrc = !empty($s['image']) ? $s['image'] : 'img/packaging.png';
                                    ?>
                                    <tr class="border-b border-gray-50 dt-row">
                                        <td class="px-4 py-2">
                                            <div class="w-12 h-12 rounded-lg bg-gray-100 border border-gray-200 overflow-hidden cursor-pointer hover:shadow-md hover:scale-110 transition-all duration-200 relative group"
                                                onclick="showImage('<?php echo htmlspecialchars($imgSrc); ?>', '<?php echo htmlspecialchars($s['name']); ?>')">
                                                <img src="<?php echo htmlspecialchars($imgSrc); ?>"
                                                    onerror="this.onerror=null;this.src='img/packaging.png'"
                                                    class="w-full h-full object-cover">
                                            </div>
                                        </td>
                                        <td class="px-4 py-4 text-gray-500 font-mono"><?php echo $s['code']; ?></td>
                                        <td class="px-4 py-3 font-bold text-gray-800"><?php echo $s['name']; ?></td>
                                        <td class="px-4 py-3"><span
                                                class="bg-sky-50 text-sky-600 px-2 py-1 rounded-md text-sm font-bold border border-sky-100"><?php echo $s['category']; ?></span>
                                        </td>
                                        <td
                                            class="px-4 py-3 text-center font-bold <?php echo $s['qty'] <= $s['min_stock'] ? 'text-red-500' : 'text-green-600'; ?>">
                                            <?php echo $s['qty']; ?>
                                        </td>
                                        <td class="px-4 py-3 text-center text-sky-600 font-bold">฿<?php echo number_format((float)($s['price'] ?? 0), 2); ?></td>
                                        <td class="px-4 py-3 text-center">
                                            <div class="flex justify-center flex-wrap gap-2">
                                                <button
                                                    onclick='showDetailsModal(<?php echo json_encode($s, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
                                                    class="bg-blue-100 text-blue-600 hover:bg-blue-200 px-3 py-1.5 rounded-lg transition text-sm font-bold shadow-sm"
                                                    title="รายละเอียดและประวัติ">
                                                    <i class="fas fa-info-circle mr-1"></i> รายละเอียด
                                                </button>
                                                <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'supply_officer'])): ?>
                                                    <button
                                                        onclick='editItem(<?php echo json_encode($s, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
                                                        class="bg-amber-100 text-amber-600 hover:bg-amber-200 px-3 py-1.5 rounded-lg transition text-sm font-bold shadow-sm"
                                                        title="แก้ไขข้อมูลวัสดุ ชื่อ รหัส หมวดหมู่ และรูปภาพ">
                                                        <i class="fas fa-edit mr-1"></i> แก้ไข
                                                    </button>
                                                    <button onclick="deleteItem(<?php echo $s['id']; ?>)"
                                                        class="bg-red-100 text-red-600 hover:bg-red-200 px-3 py-1.5 rounded-lg transition text-sm font-bold shadow-sm"
                                                        title="เปลี่ยนสถานะวัสดุเป็น Inactive (ไม่ลบถาวร)">
                                                        <i class="fas fa-trash-alt mr-1"></i> ลบ
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- 4. System Logs -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <div class="flex items-center gap-3 mb-6">
                            <div class="bg-gray-100 text-gray-600 w-10 h-10 rounded-xl flex items-center justify-center">
                                <i class="fas fa-history text-lg"></i>
                            </div>
                            <h3 class="text-lg font-bold text-gray-800">System Logs (Last 10)</h3>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead>
                                    <tr class="border-b bg-gray-50 text-gray-600">
                                        <th class="py-3 px-4 rounded-tl-lg">Time</th>
                                        <th class="py-3 px-4">User</th>
                                        <th class="py-3 px-4">Action</th>
                                        <th class="py-3 px-4 rounded-tr-lg">Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($logs as $l): ?>
                                        <tr class="border-b hover:bg-gray-50 transition">
                                            <td class="py-3 px-4 text-gray-500 font-mono"><?php echo $l['created_at']; ?></td>
                                            <td class="py-3 px-4 font-bold text-gray-800"><?php echo $l['username']; ?></td>
                                            <td class="py-3 px-4"><span
                                                    class="bg-blue-50 text-blue-600 px-2 py-1 rounded-md text-xs font-bold ring-1 ring-blue-100"><?php echo $l['action_type']; ?></span>
                                            </td>
                                            <td class="py-3 px-4 text-gray-600"><?php echo htmlspecialchars($l['details']); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Scripts for Admin Dashboard -->
                <script>
                    $(document).ready(function () {
                        // Init Supply Table for Admin
                        $('#supplyTableAdmin').DataTable({
                            responsive: true,
                            columnDefs: [{ orderable: false, targets: 0 }],
                            language: {
                                search: "ค้นหา:",
                                lengthMenu: "แสดง _MENU_ รายการ",
                                info: "แสดง _START_ ถึง _END_ จาก _TOTAL_ รายการ",
                                paginate: { first: "หน้าแรก", last: "หน้าสุดท้าย", next: "ถัดไป", previous: "ก่อนหน้า" },
                                emptyTable: "ไม่มีข้อมูลวัสดุ"
                            }
                        });

                        // Init Daily Approved Table for Admin
                        $('#dailyApprovedTableAdmin').DataTable({
                            responsive: true,
                            order: [[0, 'desc']],
                            searching: false,
                            lengthChange: false,
                            pageLength: 5,
                            language: {
                                emptyTable: "ไม่มีรายการอนุมัติในวันนี้",
                                paginate: { next: ">", previous: "<" },
                                info: "แสดง _START_-_END_ จาก _TOTAL_"
                            }
                        });
                    });
                </script>
            <?php elseif ($role == 'supply_officer'): ?>
                <?php
                // --- Logic for Supply Officer Dashboard ---
            
                // 1. Filter Date (Defaults to Today)
                $filterDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
                $thaiMonthNames = [
                    '01' => 'มกราคม',
                    '02' => 'กุมภาพันธ์',
                    '03' => 'มีนาคม',
                    '04' => 'เมษายน',
                    '05' => 'พฤษภาคม',
                    '06' => 'มิถุนายน',
                    '07' => 'กรกฎาคม',
                    '08' => 'สิงหาคม',
                    '09' => 'กันยายน',
                    '10' => 'ตุลาคม',
                    '11' => 'พฤศจิกายน',
                    '12' => 'ธันวาคม'
                ];
                list($fYear, $fMonth, $fDay) = explode('-', $filterDate);

                // 2. Fetch Daily Approved Requests
                // Group by last_approve to show batch requests
                $idCastExpr = (($db_driver ?? 'mysql') === 'pgsql') ? 'CAST(r.id AS VARCHAR)' : 'CAST(r.id AS CHAR)';
                $sqlDaily = "SELECT 
                                MAX(r.last_approve) as last_approve,
                                MAX(r.id) as id,
                                MAX(r.approver_supply_date) as approver_supply_date,
                                MAX(r.request_name) as request_name,
                                COUNT(r.id) as batch_count,
                                MAX(CONCAT(u.prefix, u.firstname, ' ', u.lastname)) as fullname,
                                MAX(u.section) as section
                             FROM requests r 
                             JOIN users u ON r.user_id = u.id 
                             WHERE r.status = 'approved' 
                             AND DATE(r.approver_supply_date) = ?
                             GROUP BY CASE WHEN r.last_approve IS NOT NULL AND r.last_approve != '' THEN r.last_approve ELSE $idCastExpr END
                             ORDER BY MAX(r.approver_supply_date) DESC";
                $stmtDaily = $pdo->prepare($sqlDaily);
                $stmtDaily->execute([$filterDate]);
                $dailyRequests = $stmtDaily->fetchAll();

                // 3. Fetch Low Stock Items (Limit 4)
                $sqlLow = "SELECT * FROM supplies WHERE qty <= min_stock AND status='active' ORDER BY qty ASC LIMIT 4";
                $lowStockItems = $pdo->query($sqlLow)->fetchAll();

                // 4. Fetch All Supplies (For bottom table)
                $stmtAll = $pdo->query("SELECT * FROM supplies WHERE status = 'active' ORDER BY id DESC");
                $allSupplies = $stmtAll->fetchAll();

                // 5. Fetch Type Volume (หน่วยนับ)
                $typeVolumes = $pdo->query("SELECT id, type_volume FROM type_volume ORDER BY id ASC")->fetchAll();
                $typeVolumesJson = json_encode($typeVolumes, JSON_UNESCAPED_UNICODE);
                ?>

                <div class="space-y-8">

                    <!-- Section 1: Daily Approved Requests -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <div
                            class="p-6 border-b border-gray-100 flex flex-col md:flex-row justify-between items-center gap-4">
                            <div class="flex items-center gap-3">
                                <div
                                    class="bg-green-100 text-green-600 w-10 h-10 rounded-xl flex items-center justify-center">
                                    <i class="fas fa-check-circle text-lg"></i>
                                </div>
                                <div>
                                    <h3 class="text-lg font-bold text-gray-800">รายการอนุมัติแล้ว (ประจำวัน)</h3>
                                    <p class="text-xs text-gray-500">แสดงรายการที่ได้รับการอนุมัติเสร็จสิ้น</p>
                                </div>
                            </div>

                            <!-- Thai Calendar Date Picker -->
                            <form method="GET"
                                class="flex items-center gap-2 bg-gray-50 p-2 rounded-lg border border-gray-200">
                                <input type="hidden" name="page" value="home">
                                <span class="text-xs font-bold text-gray-500 pl-2">เลือกวันที่:</span>

                                <!-- Day -->
                                <select name="d" id="d_select"
                                    class="bg-white border text-sm rounded px-2 py-1 outline-none focus:ring-2 focus:ring-sky-400">
                                    <?php for ($i = 1; $i <= 31; $i++):
                                        $dVal = str_pad($i, 2, '0', STR_PAD_LEFT); ?>
                                        <option value="<?php echo $dVal; ?>" <?php echo $dVal == $fDay ? 'selected' : ''; ?>>
                                            <?php echo $i; ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>

                                <!-- Month -->
                                <select name="m" id="m_select"
                                    class="bg-white border text-sm rounded px-2 py-1 outline-none focus:ring-2 focus:ring-sky-400">
                                    <?php foreach ($thaiMonthNames as $mKey => $mName): ?>
                                        <option value="<?php echo $mKey; ?>" <?php echo $mKey == $fMonth ? 'selected' : ''; ?>>
                                            <?php echo $mName; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <!-- Year (Thai) -->
                                <select name="y" id="y_select"
                                    class="bg-white border text-sm rounded px-2 py-1 outline-none focus:ring-2 focus:ring-sky-400">
                                    <?php
                                    $currY = date('Y');
                                    for ($i = $currY - 1; $i <= $currY + 1; $i++):
                                        $thY = $i + 543;
                                        ?>
                                        <option value="<?php echo $i; ?>" <?php echo $i == $fYear ? 'selected' : ''; ?>>
                                            <?php echo $thY; ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>

                                <button type="button" onclick="applyDateFilter()"
                                    class="bg-sky-500 hover:bg-sky-600 text-white px-3 py-1 rounded text-sm font-bold transition">
                                    <i class="fas fa-filter"></i>
                                </button>
                            </form>
                            <script>
                                function applyDateFilter() {
                                    let d = document.getElementById('d_select').value;
                                    let m = document.getElementById('m_select').value;
                                    let y = document.getElementById('y_select').value;
                                    window.location.href = `dashboard.php?date=${y}-${m}-${d}`;
                                }
                            </script>
                        </div>

                        <div class="p-6">
                            <?php if (count($dailyRequests) > 0): ?>
                                <table id="dailyApprovedTable" class="w-full text-left stripe hover nowrap">
                                    <thead>
                                        <tr class="bg-green-50 text-green-800">
                                            <th class="py-3 px-4 rounded-l-lg w-16">เวลา</th>
                                            <th class="py-3 px-4">เลขที่ใบเบิก</th>
                                            <!-- <th class="py-3 px-4">ผู้เบิก</th> -->
                                            <th class="py-3 px-4">ส่วนงาน</th>
                                            <th class="py-3 px-4 text-center ">สถานะ</th>
                                            <th class="py-3 px-4 text-center rounded-r-lg">รายละเอียด</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($dailyRequests as $req):
                                            // Determine ID to use (Group Code or Single ID)
                                            $useId = !empty($req['last_approve']) ? $req['last_approve'] : $req['id'];
                                            $displayName = !empty($req['last_approve']) ? $req['last_approve'] : $req['request_name'];
                                            ?>
                                            <tr>
                                                <td class="px-4 py-3 font-mono text-gray-600">
                                                    <?php echo date('H:i', strtotime($req['approver_supply_date'])); ?> น.
                                                </td>
                                                <td class="px-4 py-3 font-bold text-sky-600">
                                                    <?php echo $displayName; ?>
                                                </td>
                                                <!-- <td class="px-4 py-3 text-gray-800"><?php echo $req['fullname']; ?></td> -->
                                                <td class="px-4 py-3 text-gray-500 text-sm"><?php echo $req['section']; ?></td>
                                                <td class="px-4 py-3 text-center">
                                                    <span
                                                        class="bg-green-100 text-green-600 px-3 py-1 rounded-full text-xs font-bold">
                                                        อนุมัติแล้ว
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3 text-center">
                                                    <button onclick="viewDetails('<?php echo $useId; ?>', 'new')"
                                                        class="bg-sky-100 text-sky-600 hover:bg-sky-200 px-3 py-1.5 rounded-lg transition text-sm font-bold">
                                                        <i class="fas fa-search"></i> รายละเอียด
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div
                                    class="text-center py-8 text-gray-400 bg-gray-50 rounded-xl border border-dashed border-gray-200">
                                    <i class="fas fa-calendar-times text-3xl mb-2"></i>
                                    <p>ไม่มีรายการอนุมัติในวันที่เลือก</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Section 2: Low Stock Alerts -->
                    <div>
                        <div class="flex justify-between items-center mb-4 px-2">
                            <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                                <i class="fas fa-exclamation-triangle text-amber-500"></i> วัสดุใกล้หมด (4 อันดับแรก)
                            </h3>
                            <a href="supplies.php"
                                class="text-sm font-bold text-sky-500 hover:text-sky-600 hover:underline">
                                ดูทั้งหมด <i class="fas fa-arrow-right ml-1"></i>
                            </a>
                        </div>

                        <?php if (count($lowStockItems) > 0): ?>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                                <?php foreach ($lowStockItems as $item):
                                    $imgSrc = !empty($item['image']) ? $item['image'] : 'img/packaging.png';
                                    ?>
                                    <div class="bg-white rounded-xl shadow-sm border border-red-100 p-4 flex gap-4 items-center hover:shadow-md transition cursor-pointer"
                                        onclick="openSupplyModal(<?php echo htmlspecialchars(json_encode($item), ENT_QUOTES, 'UTF-8'); ?>)">
                                        <div
                                            class="w-16 h-16 rounded-lg bg-gray-100 flex-shrink-0 overflow-hidden border border-gray-200">
                                            <img src="<?php echo htmlspecialchars($imgSrc); ?>"
                                                onerror="this.onerror=null;this.src='img/packaging.png'"
                                                class="w-full h-full object-cover">
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <h4 class="font-bold text-gray-800 truncate"
                                                title="<?php echo htmlspecialchars($item['name']); ?>">
                                                <?php echo htmlspecialchars($item['name']); ?>
                                            </h4>
                                            <p class="text-xs text-gray-500 font-mono mb-1"><?php echo $item['code']; ?></p>
                                            <div class="flex justify-between items-end">
                                                <span class="text-xs text-gray-400">คงเหลือ</span>
                                                <span class="text-lg font-bold text-red-500"><?php echo $item['qty']; ?> <span
                                                        class="text-xs text-gray-400 font-normal"><?php echo $item['unit']; ?></span></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="bg-green-50 rounded-xl p-6 text-center text-green-600 border border-green-100">
                                <i class="fas fa-check-circle mr-2"></i> สต็อกสินค้าปกติ ไม่มีรายการใกล้หมด
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Section 3: All Supplies (Original) -->
                    <div class="bg-white p-6 rounded-2xl shadow-lg border border-gray-100 overflow-hidden">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-bold text-gray-800">รายการวัสดุทั้งหมด</h3>
                            <button onclick="openSupplyModal()"
                                class="bg-sky-500 hover:bg-sky-600 text-white px-4 py-2 rounded-lg text-sm font-bold shadow transition flex items-center gap-2">
                                <i class="fas fa-plus"></i> เพิ่มวัสดุใหม่
                            </button>
                        </div>

                        <table id="supplyTable" class="w-full text-left stripe hover nowrap align-middle">
                            <thead>
                                <tr class="bg-sky-50 text-sky-800">
                                    <th class="py-4 px-4 rounded-l-lg w-16">รูปภาพ</th>
                                    <th class="py-4 px-4">รหัส</th>
                                    <th class="py-4 px-4">ชื่อวัสดุ</th>
                                    <th class="py-4 px-4">หมวดหมู่</th>
                                    <th class="py-4 px-4 text-center">คงเหลือ</th>
                                    <th class="py-4 px-4 text-center">ราคา/หน่วย</th>
                                    <th class="py-4 px-4 rounded-r-lg text-center">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allSupplies as $s):
                                    $imgSrc = !empty($s['image']) ? $s['image'] : 'img/packaging.png';
                                    ?>
                                    <tr class="border-b border-gray-50 dt-row">
                                        <td class="px-4 py-2">
                                            <div class="w-12 h-12 rounded-lg bg-gray-100 border border-gray-200 overflow-hidden cursor-pointer hover:shadow-md hover:scale-110 transition-all duration-200 relative group"
                                                onclick="showImage('<?php echo htmlspecialchars($imgSrc); ?>', '<?php echo htmlspecialchars($s['name']); ?>')">
                                                <img src="<?php echo htmlspecialchars($imgSrc); ?>"
                                                    onerror="this.onerror=null;this.src='img/packaging.png'" alt="img"
                                                    class="w-full h-full object-cover">
                                            </div>
                                        </td>
                                        <td class="px-4 py-4 text-gray-500 font-mono"><?php echo $s['code']; ?></td>
                                        <td class="px-4 py-3 font-bold text-gray-800"><?php echo $s['name']; ?></td>
                                        <td class="px-4 py-3"><span
                                                class="bg-sky-50 text-sky-600 px-2 py-1 rounded-md text-sm font-bold border border-sky-100"><?php echo $s['category']; ?></span>
                                        </td>
                                        <td
                                            class="px-4 py-3 text-center font-bold <?php echo $s['qty'] <= $s['min_stock'] ? 'text-red-500' : 'text-green-600'; ?>">
                                            <?php echo $s['qty']; ?>
                                        </td>
                                        <td class="px-4 py-3 text-center text-sky-600 font-bold">฿<?php echo number_format((float)($s['price'] ?? 0), 2); ?></td>
                                        <td class="px-4 py-3 text-center">
                                            <div class="flex justify-center flex-wrap gap-2">
                                                <button
                                                    onclick='showDetailsModal(<?php echo json_encode($s, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
                                                    class="bg-blue-100 text-blue-600 hover:bg-blue-200 px-3 py-1.5 rounded-lg transition text-sm font-bold shadow-sm"
                                                    title="รายละเอียดและประวัติ">
                                                    <i class="fas fa-info-circle mr-1"></i> รายละเอียด
                                                </button>
                                                <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'supply_officer'])): ?>
                                                    <button
                                                        onclick='editItem(<?php echo json_encode($s, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
                                                        class="bg-amber-100 text-amber-600 hover:bg-amber-200 px-3 py-1.5 rounded-lg transition text-sm font-bold shadow-sm"
                                                        title="แก้ไขข้อมูลวัสดุ ชื่อ รหัส หมวดหมู่ และรูปภาพ">
                                                        <i class="fas fa-edit mr-1"></i> แก้ไข
                                                    </button>
                                                    <button onclick="deleteItem(<?php echo $s['id']; ?>)"
                                                        class="bg-red-100 text-red-600 hover:bg-red-200 px-3 py-1.5 rounded-lg transition text-sm font-bold shadow-sm"
                                                        title="เปลี่ยนสถานะวัสดุเป็น Inactive (ไม่ลบถาวร)">
                                                        <i class="fas fa-trash-alt mr-1"></i> ลบ
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Supply Modal Scripts (Recycled from existing) -->
                    <script>
                        $(document).ready(function () {
                            $('#supplyTable').DataTable({
                                responsive: true,
                                columnDefs: [{ orderable: false, targets: 0 }],
                                language: {
                                    search: "ค้นหา:",
                                    lengthMenu: "แสดง _MENU_ รายการ",
                                    info: "แสดง _START_ ถึง _END_ จาก _TOTAL_ รายการ",
                                    paginate: { first: "หน้าแรก", last: "หน้าสุดท้าย", next: "ถัดไป", previous: "ก่อนหน้า" },
                                    emptyTable: "ไม่มีข้อมูลวัสดุ"
                                }
                            });

                            // Initialize Daily Table if items exist
                            if ($('#dailyApprovedTable').length) {
                                $('#dailyApprovedTable').DataTable({
                                    responsive: true,
                                    order: [[0, 'desc']], // Sort by Time
                                    searching: false, // Cleaner look
                                    lengthChange: false,
                                    pageLength: 5,
                                    language: {
                                        emptyTable: "ไม่มีรายการอนุมัติในวันนี้",
                                        paginate: { next: ">", previous: "<" },
                                        info: "แสดง _START_-_END_ จาก _TOTAL_"
                                    }
                                });
                            }
                        });



                    </script>
                </div>


                <!-- DIRECTOR / SUPPLY HEAD / HEAD: View Pending Approvals (Full Interface) -->
            <?php elseif (in_array($role, ['director', 'supply_head', 'head'])): ?>

                <?php
                // Init vars
                $generalRequests = []; // Flat list
                $inventoryRequests = []; // Internal Inventory
                $groupedRequests = []; // Grouped list
                $isGrouped = false;

                // --- 1. HEAD & DEPUTY HEAD (General) ---
                if ($role == 'head' || $role == 'deputy_head') {
                    $sql = "SELECT r.*, CONCAT(u.prefix, u.firstname, ' ', u.lastname) as fullname, u.section 
                            FROM requests r LEFT JOIN users u ON r.user_id = u.id 
                            WHERE r.status = 'pending_head' AND u.section = ? ORDER BY r.request_date ASC";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$section]);
                    $generalRequests = $stmt->fetchAll();
                }

                // --- 2. SUPPLY HEAD ---
                elseif ($role == 'supply_head') {
                    // A. Internal (Inventory Section -> Pending Head)
                    $sqlInt = "SELECT r.*, CONCAT(u.prefix, u.firstname, ' ', u.lastname) as fullname, u.section 
                               FROM requests r LEFT JOIN users u ON r.user_id = u.id 
                               WHERE r.status = 'pending_head' AND u.section = 'ส่วนคลัง' 
                               GROUP BY r.section
                               ORDER BY r.request_date ASC";
                    $inventoryRequests = $pdo->query($sqlInt)->fetchAll();

                    // B. General (Pending Supply) -> Group by 'section' only
                    // We remove GROUP BY from SQL to ensure we get ALL requests for accurate counting and modal IDs.
                    $sqlGen = "SELECT r.*, CONCAT(u.prefix, u.firstname, ' ', u.lastname) as fullname, 
                               COALESCE(r.section, u.section) as section, r.approver_head_date
                               FROM requests r LEFT JOIN users u ON r.user_id = u.id 
                               WHERE r.status = 'pending_supply' 
                               ORDER BY r.request_date ASC";
                    $allGen = $pdo->query($sqlGen)->fetchAll();

                    $isGrouped = true;
                    foreach ($allGen as $req) {
                        // Group strictly by Section as requested
                        $key = $req['section']; // Use section name as unique key
            
                        if (!isset($groupedRequests[$key])) {
                            $groupedRequests[$key] = [
                                'key_display' => $key,
                                'section' => $key,
                                'requests' => [],
                                'latest_date' => $req['approver_head_date'] ?? $req['request_date'] // Use Head Approve Date
                            ];
                        }
                        $groupedRequests[$key]['requests'][] = $req;

                        // Keep latest date
                        $currentDate = $req['approver_head_date'] ?? $req['request_date'];
                        if ($currentDate > $groupedRequests[$key]['latest_date']) {
                            $groupedRequests[$key]['latest_date'] = $currentDate;
                        }
                    }
                }

                // --- 3. DIRECTOR ---
                elseif ($role == 'director') {
                    // Pending Director -> Group by Section
                    $sqlDir = "SELECT r.*, CONCAT(u.prefix, u.firstname, ' ', u.lastname) as fullname, u.section, r.approver_head_date
                               FROM requests r LEFT JOIN users u ON r.user_id = u.id 
                               WHERE r.status = 'pending_director' ORDER BY r.request_date ASC";
                    $allDir = $pdo->query($sqlDir)->fetchAll();

                    $isGrouped = true;
                    foreach ($allDir as $req) {
                        $key = $req['section'];
                        if (!isset($groupedRequests[$key])) {
                            $groupedRequests[$key] = [
                                'key_display' => $key,
                                'section' => $key,
                                'requests' => [],
                                'latest_date' => $req['approver_head_date'] ?? $req['request_date']
                            ];
                        }
                        $groupedRequests[$key]['requests'][] = $req;
                        $currentDate = $req['approver_head_date'] ?? $req['request_date'];
                        if ($currentDate > $groupedRequests[$key]['latest_date']) {
                            $groupedRequests[$key]['latest_date'] = $currentDate;
                        }
                    }
                }
                ?>

                <div class="space-y-8">
                    <!-- BLOCK 1: MAIN Pending List (General / External) -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="p-6 border-b border-gray-100 flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <span class="bg-sky-100 text-sky-600 w-10 h-10 rounded-xl flex items-center justify-center">
                                    <i class="fas fa-clipboard-check text-lg"></i>
                                </span>
                                <div>
                                    <h3 class="text-lg font-bold text-gray-800">
                                        <?php if ($role == 'supply_head')
                                            echo 'รายการรออนุมัติ (ทั่วไป/ต่างส่วนงาน)';
                                        elseif ($role == 'head' || $role == 'deputy_head')
                                            echo 'รายการรออนุมัติ (ส่วนงานของฉัน)';
                                        else
                                            echo 'รายการรออนุมัติ (ทั้งหมด)'; ?>
                                    </h3>
                                    <?php
                                    $countTotal = $isGrouped ? count($groupedRequests) : count($generalRequests);
                                    ?>
                                    <p class="text-xs text-gray-500">ทั้งหมด <span
                                            class="font-bold text-sky-600"><?php echo $countTotal; ?></span> รายการ/กลุ่ม
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="p-6">
                            <?php if ($isGrouped && !empty($groupedRequests)): ?>
                                <!-- GROUPED TABLE (Supply Head / Director) -->
                                <table id="approvalTableGrouped" class="w-full text-left stripe hover nowrap">
                                    <thead>
                                        <tr class="bg-sky-50 text-sky-800">
                                            <th class="py-4 px-4 text-center">วันที่หัวหน้าส่วนอนุมัติ</th>
                                            <th class="py-4 px-4">ส่วน</th>
                                            <th class="py-4 px-4 text-center">จำนวนคำขอ</th>
                                            <th class="py-4 px-4 rounded-r-xl text-center">จัดการ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $i = 1;
                                        foreach ($groupedRequests as $group):
                                            $ids = array_column($group['requests'], 'id');
                                            $idsJson = json_encode($ids);
                                            // Format Count string
                                            $countStr = count($group['requests']) . " รายการ";
                                            // Format Date
                                            $dateStr = (!empty($group['latest_date'])) ? date('d/m/Y', strtotime($group['latest_date'])) : '-';
                                            ?>
                                            <tr class="border-b border-gray-50 hover:bg-sky-50/30 transition">
                                                <td class="px-4 py-4 text-center text-gray-600 font-mono text-sm">
                                                    <?php echo $dateStr; ?>
                                                </td>
                                                <td class="px-4 py-4">
                                                    <div class="flex items-center gap-2">
                                                        <i class="fas fa-building text-gray-400"></i>
                                                        <span
                                                            class="text-gray-700 font-bold text-sm"><?php echo htmlspecialchars($group['section']); ?></span>
                                                    </div>
                                                </td>
                                                <td class="px-4 py-4 text-center">
                                                    <span
                                                        class="bg-amber-100 text-amber-700 px-3 py-1 rounded-lg text-xs font-bold">
                                                        <?php echo $countStr; ?>
                                                    </span>
                                                </td>
                                                <td class="px-4 py-4 text-center">
                                                    <button
                                                        onclick='openGroupModal(<?php echo $idsJson; ?>, "<?php echo htmlspecialchars($group['section']); ?>")'
                                                        class="bg-sky-500 hover:bg-sky-600 text-white px-4 py-2 rounded-xl font-bold text-sm shadow-md transition transform hover:scale-105">
                                                        <i class="fas fa-search-plus mr-1"></i> ตรวจสอบ
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>

                            <?php elseif (!$isGrouped && !empty($generalRequests)): ?>
                                <!-- FLAT TABLE (Head) -->
                                <table id="approvalTableFlat" class="w-full text-left stripe hover nowrap">
                                    <thead>
                                        <tr class="bg-sky-50 text-sky-800">
                                            <th class="py-4 px-4 rounded-l-xl w-16 text-center">ลำดับ</th>
                                            <th class="py-4 px-4 text-center">วันที่</th>
                                            <th class="py-4 px-4 text-center">เลขที่ใบเบิก</th>
                                            <th class="py-4 px-4 text-center">ผู้เบิก</th>
                                            <th class="py-4 px-4 text-center">สถานะ</th>
                                            <th class="py-4 px-4 rounded-r-xl text-center">จัดการ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $i = 1;
                                        foreach ($generalRequests as $row): ?>
                                            <tr class="border-b border-gray-50 hover:bg-sky-50/30 transition">
                                                <td class="px-4 py-4 text-gray-500 font-bold text-center"><?php echo $i++; ?></td>
                                                <td class="px-4 py-4">
                                                    <div class="flex flex-col">
                                                        <span
                                                            class="font-bold text-gray-700"><?php echo date('d/m/Y', strtotime($row['request_date'])); ?></span>
                                                        <span
                                                            class="text-xs text-gray-400"><?php echo date('H:i', strtotime($row['request_date'])); ?>
                                                            น.</span>
                                                    </div>
                                                </td>
                                                <td class="px-4 py-4"><span
                                                        class="font-mono font-bold text-sky-600 bg-sky-50 px-2 py-1 rounded text-xs"><?php echo $row['request_name']; ?></span>
                                                </td>
                                                <td class="px-4 py-4 text-sm text-gray-700"><?php echo $row['fullname']; ?></td>
                                                <td class="px-4 py-4 text-center"><span
                                                        class="bg-amber-100 text-amber-600 px-2 py-1 rounded-full text-xs font-bold">รออนุมัติ</span>
                                                </td>
                                                <td class="px-4 py-4 text-center">
                                                    <button
                                                        onclick="openModal(<?php echo $row['id']; ?>, '<?php echo $row['request_name']; ?>')"
                                                        class="bg-sky-500 hover:bg-sky-600 text-white px-3 py-1.5 rounded-lg text-xs font-bold transition">
                                                        ตรวจสอบ
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>

                            <?php else: ?>
                                <div
                                    class="text-center py-12 text-gray-400 bg-gray-50/50 rounded-xl border border-dashed border-gray-200">
                                    <i class="fas fa-check-circle text-4xl text-gray-300 mb-2"></i>
                                    <p>ไม่มีรายการคงค้าง</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- BLOCK 2: INTERNAL INVENTORY Pending (Supply Head Only) -->
                    <?php if ($role == 'supply_head'): ?>
                        <div class="bg-white rounded-2xl shadow-lg border border-gray-100 overflow-hidden">
                            <div class="p-6 border-b border-gray-100">
                                <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                                    <i class="fas fa-warehouse text-amber-500"></i>
                                    รายการรออนุมัติ (ภายในส่วนคลัง)
                                </h3>
                            </div>
                            <div class="p-6">
                                <?php if (count($inventoryRequests) > 0): ?>
                                    <table id="inventoryApprovalTable" class="w-full text-left stripe hover nowrap">
                                        <thead>
                                            <tr class="bg-amber-50 text-amber-900 text-center">
                                                <th class="py-3 px-4 text-center">วันที่ขอเบิก</th>
                                                <th class="py-3 px-4 text-center">เลขที่ใบเบิก</th>
                                                <th class="py-3 px-4 text-center">ผู้ขอเบิก</th>
                                                <th class="py-3 px-4 text-center">จัดการ</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($inventoryRequests as $req): ?>
                                                <tr>
                                                    <td class="px-4 py-3 text-sm text-gray-600">
                                                        <?php echo date('d/m/Y H:i', strtotime($req['request_date'])); ?>
                                                    </td>
                                                    <td class="px-4 py-3 font-bold text-amber-600"><?php echo $req['request_name']; ?>
                                                    </td>
                                                    <td class="px-4 py-3 text-gray-800"><?php echo $req['fullname']; ?></td>
                                                    <td class="px-4 py-3 text-center">
                                                        <button
                                                            onclick="openModal(<?php echo $req['id']; ?>, '<?php echo $req['request_name']; ?>')"
                                                            class="bg-amber-500 hover:bg-amber-600 text-white px-4 py-2 rounded-lg text-sm font-bold shadow-sm transition">
                                                            <i class="fas fa-check-circle mr-1"></i> ตรวจสอบ
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php else: ?>
                                    <div class="text-center py-8 text-gray-400">
                                        <p>ไม่มีรายการค้างอนุมัติจากส่วนคลัง</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                </div>

                <script>
                    $(document).ready(function () {
                        if ($.fn.DataTable.isDataTable('#approvalTableGrouped')) $('#approvalTableGrouped').DataTable().destroy();
                        if ($.fn.DataTable.isDataTable('#approvalTableFlat')) $('#approvalTableFlat').DataTable().destroy();
                        if ($.fn.DataTable.isDataTable('#inventoryApprovalTable')) $('#inventoryApprovalTable').DataTable().destroy();

                        $('#approvalTableGrouped').DataTable({ responsive: true, language: { search: "ค้นหา:", emptyTable: "ไม่มีข้อมูล" } });
                        $('#approvalTableFlat').DataTable({ responsive: true, language: { search: "ค้นหา:", emptyTable: "ไม่มีข้อมูล" } });
                        $('#inventoryApprovalTable').DataTable({ responsive: true, language: { search: "ค้นหา:", emptyTable: "ไม่มีข้อมูล" } });
                    });
                </script>

                <!-- USER View: Show Supplies (Like Inventory Promo) -->
            <?php else: ?>

                <div class="text-center mb-8">
                    <h2 class="text-3xl font-bold text-gray-800 mb-2">ยินดีต้อนรับสู่ระบบเบิกวัสดุ</h2>
                    <p class="text-gray-500">เลือกเบิกวัสดุที่คุณต้องการได้จากรายการด้านล่าง หรือไปที่เมนู "เบิกวัสดุ"</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <?php
                    // Show random 4 items
                    $items = $pdo->query("SELECT * FROM supplies WHERE status='active' AND qty > 0 ORDER BY RAND() LIMIT 4")->fetchAll();
                    foreach ($items as $item):
                        ?>
                        <div
                            class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100 hover:shadow-lg transition flex flex-col items-center">
                            <div
                                class="h-32 w-full flex items-center justify-center bg-gray-50 rounded-xl mb-4 overflow-hidden">
                                <img src="<?php echo htmlspecialchars(!empty($item['image']) ? $item['image'] : 'img/packaging.png'); ?>"
                                    onerror="this.onerror=null;this.src='img/packaging.png'"
                                    class="w-full h-full object-contain">
                            </div>
                            <h4 class="font-bold text-gray-800 mb-1"><?php echo $item['name']; ?></h4>
                            <p class="text-sky-500 font-bold mb-3"><?php echo $item['qty']; ?>         <?php echo $item['unit']; ?></p>
                            <a href="inventory.php"
                                class="w-full bg-sky-50 text-sky-600 py-2 rounded-lg text-sm font-bold hover:bg-sky-500 hover:text-white transition text-center">
                                ดูรายละเอียด
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div
                    class="mt-8 bg-sky-500 rounded-2xl p-8 text-white flex justify-between items-center shadow-lg shadow-sky-200">
                    <div>
                        <h3 class="text-2xl font-bold mb-2">ต้องการตรวจสอบสถานะการเบิก?</h3>
                        <p class="text-sky-100">คุณสามารถดูประวัติและติดตามสถานะคำขอของคุณได้ที่เมนู "ประวัติการเบิก"</p>
                    </div>
                    <a href="my_requests.php"
                        class="bg-white text-sky-600 px-6 py-3 rounded-xl font-bold shadow-md hover:bg-gray-50 transition">
                        ไปที่ประวัติการเบิก
                    </a>
                </div>

            <?php endif; ?>

        </div>
    </main>

    <script>
        function confirmLogout() {
            Swal.fire({
                title: 'ยืนยันการออกจากระบบ?',
                text: "คุณต้องการออกจากระบบหรือไม่",
                icon: 'warning',
                showCancelButton: true,
                heightAuto: false,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#cbd5e1',
                confirmButtonText: 'ใช่, ออกจากระบบ',
                cancelButtonText: 'ยกเลิก',
                customClass: {
                    popup: 'rounded-2xl',
                    confirmButton: 'rounded-xl',
                    cancelButton: 'rounded-xl text-gray-600'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'ออกจากระบบสำเร็จ',
                        text: 'กำลังพาท่านออกจากระบบ...',
                        icon: 'success',
                        showConfirmButton: false,
                        timer: 1000
                    }).then(() => {
                        window.location.href = '?logout=true';
                    });
                }
            })
        }

        if (typeof togglePasswordVisibility !== 'function') {
            function togglePasswordVisibility(inputId, btn) {
                const input = typeof inputId === 'string' ? document.getElementById(inputId) : inputId;
                if (!input) return;
                const isPass = input.type === 'password';
                input.type = isPass ? 'text' : 'password';
                const icon = btn.querySelector('i') || btn;
                if (icon) {
                    if (isPass) {
                        icon.className = 'fas fa-eye-slash text-sm';
                        btn.classList.add('text-sky-600');
                        btn.classList.remove('text-gray-400');
                    } else {
                        icon.className = 'far fa-eye text-sm';
                        btn.classList.remove('text-sky-600');
                        btn.classList.add('text-gray-400');
                    }
                }
            }
            window.togglePasswordVisibility = togglePasswordVisibility;
        }

        function changePassword() {
            Swal.fire({
                title: 'เปลี่ยนรหัสผ่าน',
                html: `
                    <div class="text-left font-[Sarabun] space-y-4 pt-2">
                        <div>
                            <label class="block text-xs font-bold text-sky-700 mb-1 ml-1">รหัสผ่านเดิม (Current Password)</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-sky-400 pointer-events-none"><i class="fas fa-lock"></i></span>
                                <input type="password" id="cp_old_pass" autocomplete="new-password" class="w-full pl-10 pr-10 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-sky-500 focus:border-sky-500 outline-none transition shadow-sm placeholder-gray-300" placeholder="ระบุรหัสผ่านเดิม">
                                <button type="button" tabindex="-1" onclick="togglePasswordVisibility('cp_old_pass', this)" class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-sky-600 focus:outline-none transition-colors cursor-pointer" title="แสดง/ซ่อนรหัสผ่าน">
                                    <i class="far fa-eye text-sm"></i>
                                </button>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-sky-700 mb-1 ml-1">รหัสผ่านใหม่ (New Password)</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-sky-400 pointer-events-none"><i class="fas fa-key"></i></span>
                                <input type="password" id="cp_new_pass" autocomplete="new-password" class="w-full pl-10 pr-10 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-sky-500 focus:border-sky-500 outline-none transition shadow-sm placeholder-gray-300" placeholder="ระบุอย่างน้อย 6 ตัวอักษร">
                                <button type="button" tabindex="-1" onclick="togglePasswordVisibility('cp_new_pass', this)" class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-sky-600 focus:outline-none transition-colors cursor-pointer" title="แสดง/ซ่อนรหัสผ่าน">
                                    <i class="far fa-eye text-sm"></i>
                                </button>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-sky-700 mb-1 ml-1">ยืนยันรหัสผ่านใหม่ (Confirm New)</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-sky-400 pointer-events-none"><i class="fas fa-check-double"></i></span>
                                <input type="password" id="cp_confirm_pass" autocomplete="new-password" class="w-full pl-10 pr-10 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-sky-500 focus:border-sky-500 outline-none transition shadow-sm placeholder-gray-300" placeholder="ระบุอีกครั้ง">
                                <button type="button" tabindex="-1" onclick="togglePasswordVisibility('cp_confirm_pass', this)" class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-sky-600 focus:outline-none transition-colors cursor-pointer" title="แสดง/ซ่อนรหัสผ่าน">
                                    <i class="far fa-eye text-sm"></i>
                                </button>
                            </div>
                            <div id="cp_match_msg" class="h-5 mt-1 transition-all"></div>
                        </div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'บันทึกรหัสผ่านใหม่',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#0ea5e9',
                cancelButtonColor: '#cbd5e1',
                customClass: {
                    popup: 'rounded-3xl shadow-2xl w-[450px]',
                    title: 'text-xl font-bold text-gray-800',
                    confirmButton: 'rounded-xl px-6 py-2.5 font-bold text-sm shadow-lg shadow-sky-200',
                    cancelButton: 'rounded-xl px-6 py-2.5 font-bold text-sm text-gray-600'
                },
                didOpen: () => {
                    const oldP = Swal.getPopup().querySelector('#cp_old_pass');
                    const newP = Swal.getPopup().querySelector('#cp_new_pass');
                    const confP = Swal.getPopup().querySelector('#cp_confirm_pass');
                    const msg = Swal.getPopup().querySelector('#cp_match_msg');
                    const btn = Swal.getConfirmButton();

                    // Force clear values to prevent autocomplete
                    oldP.value = '';
                    newP.value = '';
                    confP.value = '';

                    btn.disabled = true;
                    btn.style.opacity = '0.5';
                    btn.style.cursor = 'not-allowed';

                    function validate() {
                        const vOld = oldP.value;
                        const v1 = newP.value;
                        const v2 = confP.value;

                        newP.classList.remove('border-red-500', 'border-green-500');
                        confP.classList.remove('border-red-500', 'border-green-500');

                        if (v1.length > 0 && v1.length < 6) {
                            msg.innerHTML = '<span class="text-xs text-red-500 font-bold flex items-center gap-1"><i class="fas fa-times-circle"></i> รหัสผ่านสั้นเกินไป</span>';
                            newP.classList.add('border-red-500');
                            btn.disabled = true; btn.style.opacity = '0.5'; btn.style.cursor = 'not-allowed';
                            return;
                        }

                        if (vOld.length > 0 && v1.length >= 6 && v2.length > 0) {
                            if (v1 === v2) {
                                msg.innerHTML = '<span class="text-xs text-green-500 font-bold flex items-center gap-1"><i class="fas fa-check-circle"></i> รหัสผ่านตรงกัน</span>';
                                confP.classList.add('border-green-500');
                                newP.classList.add('border-green-500');
                                btn.disabled = false; btn.style.opacity = '1'; btn.style.cursor = 'pointer';
                            } else {
                                msg.innerHTML = '<span class="text-xs text-red-500 font-bold flex items-center gap-1"><i class="fas fa-times-circle"></i> รหัสผ่านไม่ตรงกัน</span>';
                                confP.classList.add('border-red-500');
                                btn.disabled = true; btn.style.opacity = '0.5'; btn.style.cursor = 'not-allowed';
                            }
                        } else {
                            msg.innerHTML = '';
                            btn.disabled = true; btn.style.opacity = '0.5'; btn.style.cursor = 'not-allowed';
                        }
                    }

                    oldP.addEventListener('input', validate);
                    newP.addEventListener('input', validate);
                    confP.addEventListener('input', validate);
                    oldP.focus();
                },
                preConfirm: async () => {
                    const oldP = Swal.getPopup().querySelector('#cp_old_pass').value.trim();
                    const newP = Swal.getPopup().querySelector('#cp_new_pass').value.trim();
                    const confP = Swal.getPopup().querySelector('#cp_confirm_pass').value.trim();

                    try {
                        const formData = new FormData();
                        formData.append('old_password', oldP);
                        formData.append('new_password', newP);
                        formData.append('confirm_password', confP);

                        const response = await fetch('api_change_password.php', {
                            method: 'POST',
                            body: formData
                        });

                        if (!response.ok) {
                            throw new Error('เกิดข้อผิดพลาดจากเซิร์ฟเวอร์ (' + response.status + ')');
                        }

                        const res = await response.json();
                        if (res.status !== 'success') {
                            Swal.showValidationMessage(res.message || 'รหัสผ่านไม่ถูกต้อง');
                            return false;
                        }
                        return res;
                    } catch (error) {
                        Swal.showValidationMessage(error.message || 'เกิดข้อผิดพลาดในการเชื่อมต่อ');
                        return false;
                    }
                }
            }).then((result) => {
                if (result.isConfirmed && result.value) {
                    Swal.fire({
                        icon: 'success',
                        title: 'สำเร็จ!',
                        text: result.value.message || 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว',
                        timer: 1500,
                        showConfirmButton: false,
                        customClass: { popup: 'rounded-2xl' }
                    });
                }
            });
        }
    </script>
    <!-- Approval Logic Scripts (Only if Role is Approver) -->
    <?php if (in_array($role, ['admin', 'director', 'supply_head', 'supply_officer', 'head', 'deputy_head'])): ?>
        <script>
            const currentRole = '<?php echo $role; ?>';
            const isUser = false; // Dashboard view for approver acts as approver

            // Global State for the current modal
            let currentItems = [];
            let currentRequestId = null;
            let currentRequestName = '';

            // Group State
            let isGroupMode = false;
            let groupRequestDetails = [];

            // --- GROUP MODAL LOGIC ---
            async function openGroupModal(ids, sectionName) {
                isGroupMode = true;
                currentRequestName = sectionName;
                groupRequestDetails = [];

                // Convert ids from array or comma-separated string to array
                const idsArray = Array.isArray(ids) ? ids : ids.split(',').map(id => id.trim());

                Swal.fire({ title: 'กำลังโหลดข้อมูลกลุ่ม...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

                try {
                    const response = await fetch(`api_get_group_items.php?ids=${idsArray.join(',')}`);
                    const data = await response.json();
                    if (data.status !== 'success') throw new Error(data.message);

                    groupRequestDetails = data.data;
                    renderGroupSwal();
                } catch (error) {
                    Swal.fire('Error', error.message, 'error');
                }
            }

            function renderGroupSwal() {
                let html = `
                    <div class="text-left font-[Sarabun]">
                         <div class="bg-gradient-to-r from-indigo-500 to-purple-600 p-5 rounded-2xl shadow-lg mb-4 flex items-center justify-between">
                            <div>
                                <h4 class="text-white font-bold text-xl flex items-center gap-3">
                                    <i class="fas fa-layer-group text-white text-2xl"></i> ส่วนงาน: ${currentRequestName}
                                </h4>
                                <p class="text-indigo-100 text-sm mt-2 ml-10">ตรวจสอบรายการเบิกแบบกลุ่ม (แยกตามบุคคล)</p>
                            </div>
                            <div class="text-right">
                                 <span class="bg-white text-indigo-600 px-4 py-2 rounded-xl text-sm font-bold shadow-md">
                                    <i class="fas fa-users mr-1"></i> ${groupRequestDetails.length} ผู้ขอเบิก
                                 </span>
                            </div>
                        </div>

                        <div class="modal-table-container max-h-[600px] overflow-y-auto border border-gray-200 rounded-xl shadow-sm bg-gray-50/50 p-3 space-y-5">
                `;

                groupRequestDetails.forEach((req, rIndex) => {
                    html += `
                        <div class="bg-white rounded-xl shadow-md border-2 border-gray-200 overflow-hidden hover:shadow-lg transition-shadow">
                            <div class="bg-gradient-to-r from-blue-50 to-indigo-50 px-5 py-3 flex justify-between items-center border-b-2 border-indigo-200">
                                 <div class="flex items-center gap-3">
                                    <span class="w-8 h-8 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center text-sm font-bold shadow-md">${rIndex + 1}</span>
                                    <div>
                                        <span class="font-bold text-gray-800 text-base block">${req.requester_name}</span>
                                        <span class="text-sm font-bold text-indigo-600 bg-white px-3 py-1 rounded-lg border border-indigo-200 inline-block mt-1">
                                            <i class="fas fa-file-invoice mr-1"></i> ${req.request_name}
                                        </span>
                                    </div>
                                 </div>
                            </div>
                            <table class="w-full text-sm text-left text-gray-600">
                                <thead class="text-xs text-gray-600 font-semibold uppercase bg-gray-100 border-b-2 border-gray-300">
                                    <tr>
                                        <th class="px-4 py-3 w-16 text-center">รูป</th>
                                        <th class="px-4 py-3">รายการวัสดุ</th>
                                        <th class="px-4 py-3 text-center w-28">ขอเบิก</th>
                                        <th class="px-4 py-3 text-center w-28">อนุมัติ</th>
                                        <th class="px-4 py-3 text-center w-16">ลบ</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                    `;

                    req.items.forEach((item, iIndex) => {
                        const isDeleted = item.is_deleted;
                        const rowBg = isDeleted ? 'bg-red-50/50' : 'bg-white hover:bg-blue-50/30';
                        const textClass = isDeleted ? 'line-through text-red-500 font-semibold' : 'text-gray-900 font-semibold';
                        const opacity = isDeleted ? 'opacity-60' : '';
                        const delBtnClass = isDeleted
                            ? 'bg-red-100 text-red-600 hover:bg-red-200 border-2 border-red-300'
                            : 'bg-gray-100 text-gray-500 hover:bg-red-500 hover:text-white border-2 border-gray-300';

                        html += `
                            <tr class="${rowBg} ${opacity} transition-all duration-200">
                                <td class="px-4 py-3 text-center">
                                    <img src="${item.image || 'img/packaging.png'}" onerror="this.onerror=null;this.src='img/packaging.png'" class="w-10 h-10 rounded-lg object-cover border-2 border-gray-200 mx-auto shadow-sm">
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-sm ${textClass}">${item.supply_name}</div>
                                    <div class="text-xs text-gray-400 mt-1">รหัส: ${item.code || 'N/A'}</div>
                                    ${isDeleted ? `<div class="text-xs text-red-600 font-bold mt-2 bg-red-50 px-2 py-1 rounded border border-red-200"><i class="fas fa-info-circle mr-1"></i>เหตุผล: ${item.reason}</div>` : ''}
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="font-bold text-gray-600 text-base">${item.original_qty}</span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <input type="number" 
                                        min="0" 
                                        max="${item.original_qty}"
                                        value="${item.current_qty}" 
                                        onchange="updateGroupQty(${rIndex}, ${iIndex}, this)"
                                        class="w-20 text-center border-2 ${isDeleted ? 'border-gray-300 bg-gray-100' : 'border-indigo-300'} rounded-lg p-2 text-base font-bold focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition-all"
                                        ${isDeleted ? 'disabled' : ''}>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <button onclick="toggleGroupDelete(${rIndex}, ${iIndex})" 
                                        class="w-10 h-10 rounded-lg flex items-center justify-center transition-all transform hover:scale-110 active:scale-95 ${delBtnClass}">
                                        <i class="fas ${isDeleted ? 'fa-undo text-base' : 'fa-trash text-base'}"></i>
                                    </button>
                                </td>
                            </tr>
                        `;
                    });

                    html += `</tbody></table></div>`;
                });

                html += `</div></div>`;

                showDecisionSwal(html, true);
            }

            function updateGroupQty(rIndex, iIndex, input) {
                let val = parseInt(input.value) || 1;
                if (val < 1) {
                    val = 1;
                    input.value = 1;
                }
                if (val > groupRequestDetails[rIndex].items[iIndex].original_qty) {
                    val = groupRequestDetails[rIndex].items[iIndex].original_qty;
                    input.value = val;
                }
                groupRequestDetails[rIndex].items[iIndex].current_qty = val;
            }

            function toggleGroupDelete(rIndex, iIndex) {
                const item = groupRequestDetails[rIndex].items[iIndex];
                if (item.is_deleted) {
                    item.is_deleted = false;
                    item.reason = '';
                    renderGroupSwal();
                } else {
                    Swal.fire({
                        title: 'ระบุเหตุผลการลบ',
                        input: 'text',
                        inputPlaceholder: 'กรุณากรอกเหตุผลการลบรายการนี้...',
                        showCancelButton: true,
                        confirmButtonText: 'ยืนยัน',
                        cancelButtonText: 'ยกเลิก',
                        confirmButtonColor: '#ef4444',
                        cancelButtonColor: '#6b7280',
                        customClass: {
                            popup: 'rounded-2xl shadow-2xl',
                            confirmButton: 'rounded-xl px-5 py-2.5 font-bold',
                            cancelButton: 'rounded-xl px-5 py-2.5 font-bold'
                        },
                        inputValidator: (value) => {
                            if (!value || value.trim() === '') {
                                return 'กรุณาระบุเหตุผลการลบ!';
                            }
                        }
                    }).then((res) => {
                        if (res.isConfirmed && res.value) {
                            item.is_deleted = true;
                            item.reason = res.value;
                            renderGroupSwal();
                        } else if (res.isDismissed) {
                            // User cancelled - return to group view
                            renderGroupSwal();
                        }
                    });
                }
            }

            async function handleGroupApprove() {
                // Generate shared approval code for group approval
                const now = new Date();
                const year = now.getFullYear() + 543; // Buddhist year
                const month = String(now.getMonth() + 1).padStart(2, '0');
                const day = String(now.getDate()).padStart(2, '0');
                const random = Math.floor(Math.random() * 9999).toString().padStart(4, '0');
                const sharedApprovalCode = `GA-${year}${month}${day}-${random}`;

                Swal.fire({
                    title: 'กำลังบันทึกข้อมูล...',
                    html: `<div class="text-sm text-gray-600 mt-2">รหัสการอนุมัติกลุ่ม: <span class="font-mono font-bold text-indigo-600">${sharedApprovalCode}</span></div>`,
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading()
                });

                const promises = groupRequestDetails.map(req => {
                    const itemsPayload = req.items.map(item => ({
                        item_id: item.item_id,
                        approved_qty: item.current_qty,
                        is_deleted: item.is_deleted,
                        reason: item.reason
                    }));

                    return fetch('api_approve_request.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            request_id: req.request_id,
                            action: 'approve',
                            reject_reason: '',
                            items: itemsPayload,
                            shared_approval_code: sharedApprovalCode
                        })
                    }).then(res => res.json());
                });

                try {
                    const results = await Promise.all(promises);
                    const errors = results.filter(r => r.status !== 'success');
                    if (errors.length > 0) {
                        Swal.fire({
                            icon: 'error',
                            title: 'ข้อผิดพลาด',
                            html: `เกิดข้อผิดพลาด ${errors.length} รายการ<br><small class="text-gray-500">${errors.map(e => e.message).join(', ')}</small>`,
                            confirmButtonColor: '#ef4444'
                        });
                    } else {
                        Swal.fire({
                            icon: 'success',
                            title: 'อนุมัติสำเร็จ',
                            html: `
                                <div class="text-center">
                                    <p class="mb-2">อนุมัติทุกรายการเรียบร้อย</p>
                                    <div class="bg-indigo-50 p-3 rounded-lg border border-indigo-200">
                                        <p class="text-xs text-gray-600 mb-1">รหัสการอนุมัติกลุ่ม</p>
                                        <p class="font-mono font-bold text-indigo-600 text-lg">${sharedApprovalCode}</p>
                                    </div>
                                </div>
                            `,
                            confirmButtonColor: '#0ea5e9'
                        }).then(() => location.reload());
                    }
                } catch (err) {
                    Swal.fire('Connection Error', err.message, 'error');
                }
            }

            async function handleGroupReject() {
                const result = await Swal.fire({
                    input: 'textarea',
                    title: 'เหตุผลการปฏิเสธ (ใช้ร่วมกัน)',
                    inputPlaceholder: 'กรุณาระบุเหตุผลการปฏิเสธทั้งกลุ่ม...',
                    showCancelButton: true,
                    confirmButtonText: 'ยืนยันปฏิเสธทั้งหมด',
                    cancelButtonText: 'ยกเลิก',
                    confirmButtonColor: '#ef4444',
                    cancelButtonColor: '#6b7280',
                    customClass: {
                        popup: 'rounded-2xl shadow-2xl',
                        confirmButton: 'rounded-xl px-5 py-2.5 font-bold',
                        cancelButton: 'rounded-xl px-5 py-2.5 font-bold'
                    },
                    inputValidator: (value) => {
                        if (!value || value.trim() === '') {
                            return 'กรุณาระบุเหตุผลการปฏิเสธ!';
                        }
                    }
                });

                if (result.isConfirmed && result.value) {
                    Swal.fire({ title: 'กำลังบันทึก...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                    const reason = result.value.trim();

                    const promises = groupRequestDetails.map(req => {
                        return fetch('api_approve_request.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                request_id: req.request_id,
                                action: 'reject',
                                reject_reason: reason,
                                items: []
                            })
                        }).then(res => res.json());
                    });

                    try {
                        const results = await Promise.all(promises);
                        const errors = results.filter(r => r.status !== 'success');
                        if (errors.length > 0) {
                            Swal.fire('ข้อผิดพลาด', `เกิดข้อผิดพลาด ${errors.length} รายการ`, 'error');
                        } else {
                            Swal.fire('สำเร็จ', 'ปฏิเสธรายการทั้งหมดเรียบร้อย', 'success').then(() => location.reload());
                        }
                    } catch (err) {
                        Swal.fire('Error', err.message, 'error');
                    }
                } else {
                    // Return to group view if cancelled
                    renderGroupSwal();
                }
            }

            function showDecisionSwal(html, isGroup) {
                Swal.fire({
                    width: isGroup ? '900px' : '850px', // Adjust width based on group or single
                    html: html,
                    showDenyButton: true,
                    showCancelButton: true,
                    confirmButtonText: isGroup ? '<i class="fas fa-check-double mr-2"></i>ยืนยันอนุมัติทั้งหมด' : '<i class="fas fa-check-circle mr-2"></i>ยืนยันอนุมัติ',
                    denyButtonText: isGroup ? '<i class="fas fa-times mr-2"></i>ปฏิเสธทั้งหมด' : '<i class="fas fa-times-circle mr-2"></i>ไม่อนุมัติทั้งหมด',
                    cancelButtonText: 'ปิดหน้าต่าง',
                    confirmButtonColor: '#0ea5e9',
                    denyButtonColor: '#ef4444',
                    cancelButtonColor: '#cbd5e1',
                    customClass: {
                        popup: 'rounded-3xl shadow-2xl',
                        confirmButton: 'rounded-xl px-6 py-3 font-bold text-sm shadow-lg shadow-sky-200',
                        denyButton: 'rounded-xl px-6 py-3 font-bold text-sm shadow-lg shadow-red-200',
                        cancelButton: 'rounded-xl px-6 py-3 font-bold text-sm text-gray-600'
                    },
                    didOpen: () => {
                        // Any post-render logic
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        if (isGroup) handleGroupApprove();
                        else handleApprove();
                    } else if (result.isDenied) {
                        if (isGroup) handleGroupReject();
                        else handleReject(currentRequestId);
                    }
                });
            }

            // --- SINGLE MODAL LOGIC ---
            async function openModal(id, name) {
                isGroupMode = false; // Ensure group mode is off for single requests
                currentRequestId = id;
                currentRequestName = name;

                Swal.fire({
                    title: 'กำลังโหลดข้อมูล...',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading()
                });

                try {
                    const response = await fetch(`api_get_request_items.php?request_id=${id}`);
                    const data = await response.json();

                    if (data.status !== 'success') throw new Error(data.message);

                    // Initialize State
                    currentItems = data.items.map(item => ({
                        ...item,
                        current_qty: item.approved_qty,
                        is_deleted: false,
                        reason: '' // Reason starts empty
                    }));

                    renderMainSwal();

                } catch (error) {
                    Swal.fire('Error', error.message, 'error');
                }
            }

            function renderMainSwal() {
                // Build HTML
                // Header
                let html = `
                <div class="text-left font-[Sarabun]">
                     <div class="bg-sky-50 p-4 rounded-xl border border-sky-100 mb-4 flex items-center justify-between">
                        <div>
                            <h4 class="text-sky-800 font-bold text-lg flex items-center gap-2">
                                <i class="fas fa-file-invoice text-sky-600"></i> ใบเบิก: ${currentRequestName}
                            </h4>
                            <p class="text-xs text-sky-600 mt-1 ml-6">ตรวจสอบรายการและจำนวนที่เบิก</p>
                        </div>
                        <div class="text-right">
                             <span class="bg-white text-sky-600 px-3 py-1 rounded-lg border border-sky-200 text-xs font-bold shadow-sm">
                                ${currentItems.length} รายการ
                             </span>
                        </div>
                    </div>

                    <div class="modal-table-container max-h-[500px] overflow-y-auto border border-gray-200 rounded-xl shadow-sm">
                        <table class="w-full text-sm text-left text-gray-600">
                             <thead class="text-xs text-gray-700 uppercase bg-gray-50 sticky top-0 z-10 shadow-sm">
                                <tr>
                                    <th class="px-4 py-3 text-center w-16">รูป</th>
                                    <th class="px-4 py-3">รายการวัสดุ</th>
                                    <th class="px-4 py-3 text-center w-28">จำนวนอนุมัติ</th>
                                    <th class="px-4 py-3 text-center w-16">ลบ</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
            `;

                currentItems.forEach((item, index) => {
                    const imgSrc = item.image || 'img/packaging.png';

                    // Styling for deleted row (approver view)
                    const rowClass = item.is_deleted ? 'bg-red-50' : 'bg-white hover:bg-sky-50';
                    const textClass = item.is_deleted ? 'text-red-400 line-through' : 'text-gray-900';
                    const opacity = item.is_deleted ? 'opacity-70' : '';

                    // Reason display if deleted
                    const reasonBadge = item.is_deleted ? `<div class="text-xs text-red-500 font-bold no-underline mt-1"><i class="fas fa-comment-alt mr-1"></i>เหตุผล: ${item.reason}</div>` : '';

                    // Input Disabled for User
                    const inputDisabled = item.is_deleted ? 'disabled' : '';
                    const inputBg = item.is_deleted ? 'bg-gray-100 text-gray-500' : 'bg-white text-gray-700';

                    // Delete Button Visibility
                    let deleteBtn = `
                        <button type="button" 
                            onclick="handleClickDelete(${index})"
                            class="${item.is_deleted ? 'bg-red-100 text-red-500 hover:bg-red-200' : 'bg-gray-100 text-gray-400 hover:bg-red-500 hover:text-white'} w-10 h-10 rounded-xl transition shadow-sm flex items-center justify-center mx-auto"
                        >
                            <i class="${item.is_deleted ? 'fas fa-undo' : 'fas fa-trash-alt'}"></i>
                        </button>
                    `;

                    html += `
                     <tr class="${rowClass} ${opacity} transition group">
                        <td class="px-4 py-3 text-center">
                             <img src="${imgSrc}" onerror="this.onerror=null;this.src='img/packaging.png'" class="w-12 h-12 object-cover rounded-lg border border-gray-200 shadow-sm mx-auto">
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-bold ${textClass} text-base">${item.supply_name}</div>
                            <div class="text-xs text-gray-400 font-normal">รหัส: ${item.code}</div>
                            ${reasonBadge}
                        </td>
                        <td class="px-4 py-3 text-center">
                            <input type="number" min="1" 
                                value="${item.current_qty}" 
                                onchange="updateQty(${index}, this)"
                                class="w-20 text-center border-2 border-gray-100 rounded-lg p-1.5 focus:ring-2 focus:ring-sky-500 focus:border-sky-500 font-bold ${inputBg} shadow-sm outline-none transition"
                                ${inputDisabled}
                            >
                        </td>
                         <td class="px-4 py-3 text-center">
                            ${deleteBtn}
                        </td>
                     </tr>
                `;
                });

                html += `</tbody></table></div></div>`;

                // Config for Approver
                let confirmText = '<i class="fas fa-check-circle mr-2"></i>ยืนยันอนุมัติ';
                let confirmColor = '#0ea5e9';
                let showDeny = true;
                let denyText = '<i class="fas fa-times-circle mr-2"></i>ไม่อนุมัติทั้งหมด';

                // Display Swal
                Swal.fire({
                    width: '850px',
                    html: html,
                    showDenyButton: showDeny,
                    showCancelButton: true,
                    confirmButtonText: confirmText,
                    denyButtonText: denyText,
                    cancelButtonText: 'ปิดหน้าต่าง',
                    confirmButtonColor: confirmColor,
                    denyButtonColor: '#ef4444',
                    cancelButtonColor: '#cbd5e1',
                    customClass: {
                        popup: 'rounded-3xl shadow-2xl',
                        confirmButton: 'rounded-xl px-6 py-3 font-bold text-sm shadow-lg shadow-sky-200',
                        denyButton: 'rounded-xl px-6 py-3 font-bold text-sm shadow-lg shadow-red-200',
                        cancelButton: 'rounded-xl px-6 py-3 font-bold text-sm text-gray-600'
                    },
                    didOpen: () => {
                        // Any post-render logic
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        handleApprove();
                    } else if (result.isDenied) {
                        handleReject(currentRequestId);
                    }
                });
            }

            function updateQty(index, input) {
                let val = parseInt(input.value) || 1;
                if (val < 1) {
                    val = 1;
                    input.value = 1;
                }
                currentItems[index].current_qty = val;
            }

            function handleClickDelete(index) {
                const item = currentItems[index];

                if (item.is_deleted) {
                    // Restore
                    item.is_deleted = false;
                    item.reason = '';
                    renderMainSwal();
                } else {
                    // Delete
                    Swal.fire({
                        title: 'ระบุเหตุผลการลบ',
                        text: `ต้องการลบรายการ "${item.supply_name}" หรือไม่?`,
                        input: 'textarea',
                        inputPlaceholder: 'กรุณาระบุเหตุผล...',
                        showCancelButton: true,
                        confirmButtonText: 'ยืนยันลบ',
                        cancelButtonText: 'ยกเลิก',
                        confirmButtonColor: '#ef4444',
                        cancelButtonColor: '#cbd5e1',
                        customClass: {
                            popup: 'rounded-2xl',
                            confirmButton: 'rounded-xl',
                            cancelButton: 'rounded-xl shadow-none text-gray-600'
                        },
                        inputValidator: (value) => {
                            if (!value) {
                                return 'จำเป็นต้องระบุเหตุผล!'
                            }
                        }
                    }).then((res) => {
                        if (res.isConfirmed) {
                            item.is_deleted = true;
                            item.reason = res.value;
                            renderMainSwal();
                        } else {
                            renderMainSwal(); // Just go back
                        }
                    });
                }
            }

            async function handleApprove() {
                const itemsPayload = currentItems.map(item => ({
                    item_id: item.item_id,
                    approved_qty: item.current_qty,
                    is_deleted: item.is_deleted,
                    reason: item.reason
                }));

                submitDecision(currentRequestId, 'approve', '', itemsPayload);
            }

            async function handleReject(requestId) {
                const result = await Swal.fire({
                    input: 'textarea',
                    inputLabel: 'เหตุผลการไม่อนุมัติ',
                    inputPlaceholder: 'กรุณาระบุเหตุผล...',
                    showCancelButton: true,
                    confirmButtonText: 'ยืนยันการไม่อนุมัติ',
                    cancelButtonText: 'ยกเลิก',
                    confirmButtonColor: '#ef4444',
                    cancelButtonColor: '#cbd5e1',
                    customClass: {
                        popup: 'rounded-2xl',
                        confirmButton: 'rounded-xl shadow-lg shadow-red-200',
                        cancelButton: 'rounded-xl text-gray-600'
                    },
                    inputValidator: (value) => {
                        if (!value) return 'จำเป็นต้องระบุเหตุผล!'
                    }
                });

                if (result.isConfirmed && result.value) {
                    submitDecision(requestId, 'reject', result.value, []);
                } else {
                    renderMainSwal();
                }
            }

            function submitDecision(id, action, reason, items) {
                Swal.fire({ title: 'กำลังบันทึก...', didOpen: () => Swal.showLoading() });

                fetch('api_approve_request.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        request_id: id,
                        action: action,
                        reject_reason: reason,
                        items: items
                    })
                })
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            Swal.fire({
                                title: 'สำเร็จ',
                                text: 'บันทึกข้อมูลเรียบร้อยแล้ว',
                                icon: 'success',
                                confirmButtonText: 'ตกลง',
                                customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-xl' }
                            }).then(() => location.reload());
                        } else {
                            Swal.fire('ผิดพลาด', data.message, 'error');
                        }
                    })
                    .catch(err => Swal.fire('Error', err.message, 'error'));
            }
        </script>
    <?php endif; ?>
    <script>
        // Details View Function matching my_requests.php logic
        function viewDetails(id, type = 'new', action = 'get_details_new') {
            if (!id) return;

            Swal.fire({
                title: 'กำลังโหลดข้อมูล...',
                html: '<div class="flex justify-center my-4"><i class="fas fa-circle-notch fa-spin text-4xl text-sky-500"></i></div>',
                showConfirmButton: false,
                allowOutsideClick: false,
                customClass: { popup: 'rounded-2xl' }
            });

            // Post to my_requests.php to reuse existing backend logic
            $.post('my_requests.php', { action: action, id: id }, function (res) {
                try {
                    let data = typeof res === 'string' ? JSON.parse(res) : res;
                    if (data.status === 'success') {
                        // Header Styling
                        let headerTitle = type === 'new' ? 'รายละเอียดการเบิก (ระบบใหม่)' : 'รายละเอียดการเบิก (ระบบเดิม)';
                        let headerIcon = type === 'new' ? 'fa-shopping-bag' : 'fa-history';

                        let contentHtml = '';

                        if (data.data.length === 0) {
                            contentHtml = `<div class="text-center py-10 text-gray-400">ไม่พบข้อมูลรายการ</div>`;
                        } else {
                            // Unified System (Grouped by Request)
                            const grouped = {};
                            const requestOrder = []; // To keep order

                            data.data.forEach(item => {
                                const key = item.request_name || 'Unknown';
                                if (!grouped[key]) {
                                    grouped[key] = {
                                        request_name: item.request_name,
                                        requester: item.firstname ? `${item.firstname} ${item.lastname || ''}` : 'ไม่ระบุชื่อ',
                                        items: []
                                    };
                                    requestOrder.push(key);
                                }
                                grouped[key].items.push(item);
                            });

                            // Render Groups
                            contentHtml += `<div class="p-6 space-y-6 max-h-[600px] overflow-y-auto bg-slate-50">`;

                            requestOrder.forEach((key, rIndex) => {
                                const group = grouped[key];

                                contentHtml += `
                                    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                                        <!-- Group Header (Person) -->
                                        <div class="bg-indigo-50/50 px-5 py-4 border-b border-indigo-100 flex justify-between items-center group-header">
                                            <div class="flex items-center gap-4">
                                                <div class="w-10 h-10 rounded-full bg-indigo-600 text-white flex items-center justify-center font-bold text-lg shadow-md ring-2 ring-indigo-100">
                                                    ${rIndex + 1}
                                                </div>
                                                <div>
                                                    <div class="font-bold text-slate-800 text-base">${group.requester}</div>
                                                    <div class="flex items-center gap-2 mt-1">
                                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-md text-xs font-medium bg-white text-indigo-700 border border-indigo-200 shadow-sm">
                                                            <i class="fas fa-file-invoice mr-1.5"></i> ${group.request_name}
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Items Table -->
                                        <div class="px-2">
                                            <table class="w-full text-left border-collapse">
                                                <thead class="text-slate-500 text-xs font-semibold uppercase tracking-wider border-b border-slate-100">
                                                    <tr>
                                                        <th class="p-4 w-20 text-center">รูปภาพ</th>
                                                        <th class="p-4">รายการวัสดุ</th>
                                                        <th class="p-4 text-center w-28">ขอเบิก</th>
                                                        <th class="p-4 text-center w-28 text-indigo-600">จำนวนที่อนุมัติ</th>
                                                        <th class="p-4 text-center w-24">หน่วย</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-slate-50">`;

                                group.items.forEach(item => {
                                    let appQty = item.approved_qty !== null ? item.approved_qty : '<span class="text-slate-400">-</span>';
                                    let name = item.product_name;
                                    let quantity = item.qty;
                                    let rowClass = 'hover:bg-indigo-50/30 transition-colors';
                                    let statusBadge = '';
                                    let imgUrl = item.image || 'img/packaging.png';
                                    let imgElement = `<img src="${imgUrl}" class="w-full h-full rounded-md object-cover transition-transform hover:scale-110 cursor-pointer" onclick="Swal.fire({imageUrl: '${imgUrl}', showConfirmButton: false, width: 'auto', padding: '1em', background: 'transparent', backdrop: 'rgba(0,0,0,0.8)'})" onerror="this.onerror=null;this.src='img/packaging.png'">`;

                                    if (item.is_deleted == 1) {
                                        rowClass = 'bg-red-50 hover:bg-red-50';
                                        statusBadge = `<div class="inline-flex items-center mt-1 px-2 py-0.5 rounded text-[10px] bg-red-100 text-red-700 font-medium"><i class="fas fa-trash-alt mr-1"></i> ถูกลบ: ${item.edit_reason || '-'}</div>`;
                                        appQty = '<span class="text-red-400 font-bold opacity-50">0</span>';
                                        name = `<span class="line-through text-red-800 opacity-60 decoration-2">${name}</span>`;
                                    } else if (item.edit_reason) {
                                        statusBadge = `<div class="inline-flex items-center mt-1 px-2 py-0.5 rounded text-[10px] bg-amber-100 text-amber-700 font-medium"><i class="fas fa-edit mr-1"></i> แก้ไข: ${item.edit_reason}</div>`;
                                        appQty = `<span class="text-amber-600 font-bold">${appQty}</span>`;
                                    }

                                    contentHtml += `
                                        <tr class="${rowClass}">
                                            <td class="p-4 text-center">
                                                 <div class="w-12 h-12 rounded-lg bg-white border border-slate-200 p-0.5 shadow-sm mx-auto overflow-hidden relative group">
                                                    ${imgElement}
                                                 </div>
                                            </td>
                                            <td class="p-4 align-top">
                                                <div class="font-bold text-slate-700 text-sm mb-0.5">${name}</div>
                                                <div class="flex items-center gap-2">
                                                    <span class="text-[10px] font-mono font-medium text-slate-500 bg-slate-100 px-1.5 py-0.5 rounded border border-slate-200">
                                                        CODE: ${item.supply_code || '-'}
                                                    </span>
                                                    ${statusBadge}
                                                </div>
                                            </td>
                                            <td class="p-4 text-center align-top font-bold text-slate-600 text-base">${quantity}</td>
                                            <td class="p-4 text-center align-top font-bold text-indigo-600 text-base border-x border-dashed border-indigo-100 bg-indigo-50/20 shadow-inner">${appQty}</td>
                                            <td class="p-4 text-center align-top text-xs text-slate-500">${item.product_unit || '-'}</td>
                                        </tr>
                                    `;
                                });

                                contentHtml += `</tbody></table></div></div>`;
                            });

                            contentHtml += `</div>`;
                        }

                        renderModalContent(headerTitle, headerIcon, type, data, contentHtml, id);

                    } else {
                        throw new Error('Invalid Data');
                    }
                } catch (e) {
                    console.error(e);
                    Swal.fire('Error', 'ไม่สามารถโหลดข้อมูลได้', 'error');
                }
            });
        }

        <?php if ($showExpiryAlert): ?>
            // Cart Expiry Alert Logic
            const expiredItems = <?php echo $expiredItemsJson; ?>;

            function showCartExpiryAlert() {
                let itemListHtml = '<ul class="text-left text-sm text-gray-600 list-disc pl-5 mt-2 space-y-1">';
                expiredItems.forEach(item => {
                    itemListHtml += `<li>${item}</li>`;
                });
                itemListHtml += '</ul>';

                Swal.fire({
                    title: 'สินค้าในตะกร้าค้างเกินกำหนด',
                    html: `
                    <div class="mb-4">
                        คุณมีรายการวัสดุค้างในตะกร้านานเกิน 12 ชั่วโมง:
                        ${itemListHtml}
                    </div>
                    <p class="font-bold text-sky-600">คุณต้องการเบิกวัสดุเหล่านี้หรือไม่?</p>
                `,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: '<i class="fas fa-cart-arrow-down"></i> เบิกวัสดุ',
                    cancelButtonText: 'ไม่ต้องการเบิก',
                    confirmButtonColor: '#0ea5e9', // Sky-500
                    cancelButtonColor: '#64748b', // Slate-500
                    allowOutsideClick: false,
                    allowEscapeKey: false
                }).then((result) => {
                    if (result.isConfirmed) {
                        location.href = 'cart.php';
                    } else if (result.dismiss === Swal.DismissReason.cancel) {
                        confirmClearCart();
                    }
                });
            }

            function confirmClearCart() {
                Swal.fire({
                    title: 'ยืนยันลบรายการ?',
                    text: "คุณยืนยันที่จะไม่เบิกวัสดุในตะกร้าจริงหรือไม่? หากตกลง ระบบจะทำการล้างวัสดุในตะกร้าออกทั้งหมด",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'ยืนยัน (ล้างตะกร้า)',
                    cancelButtonText: 'ยกเลิก',
                    confirmButtonColor: '#ef4444', // Red-500
                    cancelButtonColor: '#cbd5e1', // Slate-300
                    reverseButtons: true,
                    allowOutsideClick: false
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Call Clear Cart API
                        $.post('cart.php', { action: 'clear_cart' }, function (res) {
                            Swal.fire({
                                title: 'ล้างตะกร้าเรียบร้อย',
                                icon: 'success',
                                timer: 1500,
                                showConfirmButton: false
                            }).then(() => {
                                location.reload();
                            });
                        });
                    } else {
                        // Go back to first alert
                        showCartExpiryAlert();
                    }
                });
            }

            // Trigger on load
            document.addEventListener('DOMContentLoaded', () => {
                // Delay slightly to ensure fonts/CSS loaded
                setTimeout(showCartExpiryAlert, 500);
            });
        <?php endif; ?>

        // Helper to render the modal (Keep consistent style)
        function renderModalContent(headerTitle, headerIcon, type, data, contentHtml, id) {
            let htmlContent = `
                <div class="text-left font-[Sarabun]">
                    <!-- Main Header -->
                    <div class="bg-gradient-to-r from-violet-600 to-indigo-600 p-6 rounded-t-2xl shadow-lg relative overflow-hidden">
                         <div class="absolute top-0 right-0 p-4 opacity-10">
                            <i class="fas fa-layer-group text-8xl text-white transform rotate-12 translate-x-4 -translate-y-4"></i>
                         </div>
                         
                         <div class="flex justify-between items-start relative z-10">
                             <div class="flex items-center gap-4">
                                <div class="w-14 h-14 bg-white/20 rounded-2xl flex items-center justify-center backdrop-blur-md shadow-inner border border-white/20">
                                    <i class="fas ${headerIcon} text-2xl text-white"></i>
                                </div>
                                <div class="text-white">
                                    <h2 class="text-2xl font-bold tracking-tight">${headerTitle}</h2>
                                    <p class="text-indigo-100 text-sm opacity-90"><i class="fas fa-calendar-alt mr-1"></i> ${data.header_date || 'ไม่ระบุวันที่'}</p>
                                </div>
                             </div>
                             <div class="bg-white/20 backdrop-blur-md px-3 py-1 rounded-lg border border-white/10 shadow-sm cursor-pointer hover:bg-white/30 transition" onclick="Swal.close()">
                                <i class="fas fa-times text-white text-lg"></i>
                             </div>
                         </div>
                    </div>

                    <!-- Content Body -->
                    ${contentHtml}
                    
                    <!-- Footer -->
                     <div class="bg-slate-50 px-6 py-4 rounded-b-2xl border-t border-slate-200 flex justify-end gap-3">
                        <button onclick="printRequest('${id}')" class="px-5 py-2.5 rounded-xl bg-sky-500 text-white font-bold hover:bg-sky-600 shadow-md shadow-sky-200 transition text-sm flex items-center gap-2">
                            <i class="fas fa-print"></i> พิมพ์ใบเบิกวัสดุ
                        </button>
                        <button onclick="Swal.close()" class="px-5 py-2.5 rounded-xl text-slate-600 font-bold hover:bg-slate-200 transition text-sm">ปิดหน้าต่าง</button>
                    </div>
                </div>
            `;

            Swal.fire({
                html: htmlContent,
                width: '900px',
                showConfirmButton: false,
                background: 'transparent',
                customClass: { popup: 'p-0 bg-transparent shadow-none overflow-visible' },
                backdrop: `rgba(15, 23, 42, 0.6)`
            });
        }

        function printRequest(id) {
            window.open('export_pdf_request.php?id=' + id, '_blank');
        }

        // Show Full Image
        function showImage(src, name) {
            Swal.fire({
                title: name,
                imageUrl: src,
                imageWidth: 400,
                imageAlt: 'Product Image',
                showCloseButton: true,
                showConfirmButton: false,
                backdrop: `rgba(0,0,123,0.4)`
            });
        }

        // Preview upload
        window.previewUpload = function (input) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function (e) {
                    document.getElementById('img-preview').src = e.target.result;
                    document.getElementById('img-preview').classList.remove('hidden');
                    document.getElementById('upload-placeholder').classList.add('hidden');
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        function openSupplyModal(item = null) {
            let isEdit = !!item;
            let imgSrc = (isEdit && item.image) ? item.image : '';
            let showImg = imgSrc ? '' : 'hidden';
            let showPlaceholder = imgSrc ? 'hidden' : '';

            // ดึงข้อมูลหน่วยนับและหมวดหมู่จาก PHP
            const typeVolumes = <?php echo $dashVolumesJson ?? '[]'; ?>;
            const sysCategories = <?php echo $dashCategoriesJson ?? '[]'; ?>;

            // สร้าง options สำหรับ select หน่วยนับ
            let unitOptions = '<option value="">-- เลือกหน่วยนับ --</option>';
            typeVolumes.forEach(tv => {
                const selected = (isEdit && item.unit === tv.type_volume) ? 'selected' : '';
                unitOptions += `<option value="${tv.type_volume}" ${selected}>${tv.type_volume}</option>`;
            });

            // สร้าง options สำหรับ select หมวดหมู่
            let catOptions = '<option value="">-- เลือกหมวดหมู่ --</option>';
            sysCategories.forEach(cat => {
                const selected = (isEdit && item.category === cat) ? 'selected' : '';
                catOptions += `<option value="${cat}" ${selected}>${cat}</option>`;
            });

            Swal.fire({
                title: isEdit ? 'แก้ไขข้อมูลวัสดุ' : 'เพิ่มวัสดุใหม่',
                html: `
                                    <div class="space-y-4 pt-2 text-left font-[Sarabun]">
                                        <div class="flex justify-center">
                                            <div class="file-upload-wrapper w-48 h-48 mx-auto relative border-2 border-dashed border-gray-300 rounded-lg flex items-center justify-center bg-gray-50 overflow-hidden hover:bg-sky-50 transition cursor-pointer">
                                                <input type="file" id="swal-file" accept="image/*" onchange="previewUpload(this)" class="absolute inset-0 opacity-0 cursor-pointer w-full h-full z-10">
                                                <div id="upload-placeholder" class="${showPlaceholder} flex flex-col items-center pointer-events-none">
                                                    <i class="fas fa-cloud-upload-alt text-3xl mb-2 text-gray-400"></i>
                                                    <span class="text-xs text-gray-500 font-bold">คลิกเพื่ออัพโหลด</span>
                                                </div>
                                                <img id="img-preview" src="${imgSrc}" class="${showImg} w-full h-full object-contain pointer-events-none">
                                            </div>
                                        </div>
                                        <div class="grid grid-cols-1 gap-3">
                                            <div>
                                                <label class="text-xs font-bold text-gray-500 ml-1">รหัสวัสดุ</label>
                                                <input id="swal-code" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-sky-500 outline-none ${isEdit ? 'bg-gray-100 cursor-not-allowed text-gray-500' : ''}" placeholder="ระบุรหัสวัสดุ" value="${isEdit ? item.code : ''}" ${isEdit ? 'disabled' : ''}>
                                            </div>
                                            <div>
                                                <label class="text-xs font-bold text-gray-500 ml-1">ชื่อวัสดุ</label>
                                                <input id="swal-name" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-sky-500 outline-none" placeholder="ระบุชื่อวัสดุ" value="${isEdit ? item.name : ''}">
                                            </div>
                                            <div class="grid grid-cols-2 gap-3">
                                                <div>
                                                    <label class="text-xs font-bold text-gray-500 ml-1">หมวดหมู่</label>
                                                    <select id="swal-cat" class="w-full px-4 py-2 border rounded-lg outline-none focus:ring-2 focus:ring-sky-500 bg-white">
                                                        ${catOptions}
                                                    </select>
                                                </div>
                                                <div>
                                                    <label class="text-xs font-bold text-gray-500 ml-1">หน่วยนับ</label>
                                                    <select id="swal-unit" class="w-full px-4 py-2 border rounded-lg outline-none focus:ring-2 focus:ring-sky-500 bg-white">
                                                        ${unitOptions}
                                                    </select>
                                                </div>
                                            <div class="grid grid-cols-2 gap-3">
                                                <div>
                                                    <label class="text-xs font-bold text-gray-500 ml-1">ราคาวัสดุ / หน่วย</label>
                                                    <input id="swal-price" type="number" step="0.01" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-sky-500 outline-none" placeholder="0.00" value="${isEdit ? item.price : ''}">
                                                </div>
                                                <div>
                                                    <label class="text-xs font-bold text-gray-500 ml-1">เกณฑ์สะสมประจำปี (Threshold)</label>
                                                    <input id="swal-threshold" type="number" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-sky-500 outline-none" placeholder="100" value="${isEdit ? (item.inspection_threshold ?? 100) : 100}">
                                                </div>
                                            </div>
                                            <div class="grid grid-cols-3 gap-3">
                                                <div>
                                                    <label class="text-xs font-bold text-gray-500 ml-1">Min. Stock</label>
                                                    <input id="swal-min" type="number" class="w-full px-4 py-2 border rounded-lg outline-none focus:ring-2 focus:ring-sky-500" value="${isEdit ? item.min_stock : 1}">
                                                </div>
                                                <div>
                                                    <label class="text-xs font-bold text-gray-500 ml-1">เบิกขั้นต่ำ</label>
                                                    <input id="swal-min-order" type="number" class="w-full px-4 py-2 border rounded-lg outline-none focus:ring-2 focus:ring-sky-500" value="${isEdit ? (item.min_order ?? 1) : 1}">
                                                </div>
                                                <div>
                                                    <label class="text-xs font-bold text-gray-500 ml-1">เบิกสูงสุด</label>
                                                    <input id="swal-max-order" type="number" class="w-full px-4 py-2 border rounded-lg outline-none focus:ring-2 focus:ring-sky-500" value="${isEdit ? (item.max_order ?? 0) : 0}">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                `,
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-save mr-1"></i> บันทึก',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#0ea5e9',
                cancelButtonColor: '#94a3b8',
                focusConfirm: false,
                didOpen: () => {
                    $('#swal-cat').select2({
                        tags: true, /* อนุญาตให้พิมพ์เพิ่มใหม่ได้ */
                        placeholder: '-- เลือกหมวดหมู่ --',
                        dropdownParent: $('.swal2-container'),
                        width: '100%'
                    });
                    $('#swal-unit').select2({
                        tags: true, /* อนุญาตให้พิมพ์เพิ่มใหม่ได้ */
                        placeholder: '-- เลือกหน่วยนับ --',
                        dropdownParent: $('.swal2-container'),
                        width: '100%'
                    });
                },
                preConfirm: () => {
                    let file = document.getElementById('swal-file').files[0];
                    let formData = new FormData();
                    formData.append('action', 'save');
                    if (isEdit) formData.append('id', item.id);
                    formData.append('code', document.getElementById('swal-code').value);
                    formData.append('name', document.getElementById('swal-name').value);
                    formData.append('category', document.getElementById('swal-cat').value);
                    formData.append('unit', document.getElementById('swal-unit').value);
                    formData.append('min_stock', document.getElementById('swal-min').value);
                    formData.append('min_order', document.getElementById('swal-min-order').value);
                    formData.append('max_order', document.getElementById('swal-max-order').value);
                    formData.append('price', document.getElementById('swal-price').value || 0);
                    formData.append('inspection_threshold', document.getElementById('swal-threshold').value || 100);
                    if (file) formData.append('image_file', file);
                    return formData;
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    saveSupplyData(result.value);
                }
            });
        }

        function showDetailsModal(item) {
            Swal.fire({
                title: 'รายละเอียดพัสดุ',
                html: `
                    <div class="space-y-4 pt-2 text-left font-[Sarabun]">
                        <div class="flex flex-col items-center">
                            <img src="${item.image ? item.image : 'img/packaging.png'}" onerror="this.onerror=null;this.src='img/packaging.png'" class="w-32 h-32 object-contain rounded-lg border-2 border-gray-200 shadow-sm mb-3">
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-xs font-bold text-gray-500 ml-1">รหัสวัสดุ</label>
                                <input class="w-full px-4 py-2 border rounded-lg bg-gray-50 text-gray-600 cursor-not-allowed" value="${item.code}" disabled>
                            </div>
                            <div>
                                <label class="text-xs font-bold text-gray-500 ml-1">ชื่อวัสดุ</label>
                                <input class="w-full px-4 py-2 border rounded-lg bg-gray-50 text-gray-600 cursor-not-allowed" value="${item.name}" disabled>
                            </div>
                            <div>
                                <label class="text-xs font-bold text-gray-500 ml-1">หมวดหมู่</label>
                                <input class="w-full px-4 py-2 border rounded-lg bg-gray-50 text-gray-600 cursor-not-allowed" value="${item.category}" disabled>
                            </div>
                            <div>
                                <label class="text-xs font-bold text-gray-500 ml-1">หน่วยนับ</label>
                                <input class="w-full px-4 py-2 border rounded-lg bg-gray-50 text-gray-600 cursor-not-allowed" value="${item.unit}" disabled>
                            </div>
                        </div>
                        <div>
                            <label class="text-xs font-bold text-gray-500 ml-1">ราคา/หน่วย</label>
                            <div class="w-full px-4 py-2.5 border border-sky-200 rounded-lg bg-sky-50 flex items-center justify-between">
                                <span class="text-xs text-sky-400 font-medium">ราคาต่อหน่วย</span>
                                <span class="text-lg font-bold text-sky-600">฿${parseFloat(item.price ?? 0).toLocaleString('th-TH', {minimumFractionDigits:2, maximumFractionDigits:2})}</span>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-xs font-bold text-gray-500 ml-1">เบิกขั้นต่ำ</label>
                                <input class="w-full px-4 py-2 border rounded-lg bg-gray-50 text-gray-600 cursor-not-allowed" value="${item.min_order ?? '1'} ${item.unit}" disabled>
                            </div>
                            <div>
                                <label class="text-xs font-bold text-gray-500 ml-1">เบิกสูงสุด</label>
                                <input class="w-full px-4 py-2 border rounded-lg bg-gray-50 text-gray-600 cursor-not-allowed" value="${item.max_order > 0 ? item.max_order + ' ' + item.unit : 'ไม่จำกัด'}" disabled>
                            </div>
                        </div>
                        <div class="mt-4 pt-4 border-t border-gray-200">
                            <div class="flex justify-between items-center mb-2">
                                <h4 class="font-bold text-sky-700"><i class="fas fa-history mr-1"></i> ประวัติการเข้า-ออก (ล่าสุด)</h4>
                            </div>
                            <div id="history-container" class="text-center text-gray-500 text-sm py-4">
                                กำลังโหลดข้อมูล... <i class="fas fa-spinner fa-spin ml-2"></i>
                            </div>
                        </div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-file-pdf mr-1"></i> พิมพ์บัญชีวัสดุ (PDF)',
                cancelButtonText: 'ปิด',
                confirmButtonColor: '#0ea5e9',
                cancelButtonColor: '#64748b',
                customClass: {
                    popup: 'w-[600px] rounded-2xl p-6',
                    confirmButton: 'rounded-xl px-5 py-2.5 font-bold',
                    cancelButton: 'rounded-xl px-5 py-2.5 font-bold'
                },
                didOpen: () => {
                    fetch('api_get_material_card_v2.php?id=' + item.id)
                        .then(res => res.json())
                        .then(data => {
                            if (data.status === 'success') {
                                let html = `<table class="w-full text-xs text-left" style="border-collapse: collapse;">
                                    <thead><tr class="bg-gray-100 border-b"><th class="py-2 px-2 text-gray-600">วันที่</th><th class="py-2 px-2 text-gray-600">รายการ</th><th class="py-2 px-2 text-center text-gray-600">จำนวนที่เข้า/ออก</th><th class="py-2 px-2 text-center text-gray-600">คงเหลือ</th></tr></thead>
                                    <tbody class="divide-y divide-gray-100">`;

                                if (data.data.length === 0) {
                                    html += `<tr><td colspan="4" class="text-center py-6 text-gray-400">ยังไม่มีประวัติเข้า-ออก</td></tr>`;
                                } else {
                                    let latestData = data.data;
                                    // Reverse the array to show latest first, get up to 5 items
                                    latestData.slice().reverse().slice(0, 5).forEach(row => {
                                        let isRecv = row.type === 'receive';
                                        let badge = isRecv
                                            ? '<span class="text-green-600 font-bold bg-green-50 px-2 py-0.5 rounded-sm"><i class="fas fa-long-arrow-alt-down"></i> รับเข้า</span>'
                                            : '<span class="text-amber-500 font-bold bg-amber-50 px-2 py-0.5 rounded-sm"><i class="fas fa-long-arrow-alt-up"></i> จ่ายออก</span>';

                                        let d = new Date(row.transaction_date);
                                        let dp = d.toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: '2-digit' });

                                        let diffQty = Math.abs(isRecv ? row.qty_in : row.qty_out);
                                        let diffStyle = isRecv ? 'text-green-600' : 'text-amber-500';

                                        html += `<tr class="hover:bg-slate-50 transition">
                                            <td class="py-2 px-2 whitespace-nowrap text-gray-500">${dp}</td>
                                            <td class="py-2 px-2">
                                                <div class="mb-0.5">${badge}</div>
                                                <span class="text-[10px] text-gray-400 truncate max-w-[150px] inline-block" title="${row.description}">${row.description}</span>
                                            </td>
                                            <td class="py-2 px-2 text-center font-bold ${diffStyle}">${isRecv ? '+' : '-'}${diffQty}</td>
                                            <td class="py-2 px-2 text-center font-bold text-gray-700 bg-gray-50">${row.balance_qty}</td>
                                        </tr>`;
                                    });
                                }
                                html += `</tbody></table>`;
                                document.getElementById('history-container').innerHTML = html;
                            } else {
                                document.getElementById('history-container').innerHTML = '<span class="text-red-500">เกิดข้อผิดพลาด: ' + data.message + '</span>';
                            }
                        })
                        .catch(err => {
                            document.getElementById('history-container').innerText = 'เกิดข้อผิดพลาดในการเชื่อมต่อเครือข่าย';
                        });
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    window.open('report_material_card_v2.php?id=' + item.id, '_blank');
                }
            });
        }

        function editItem(item) {
            openSupplyModal(item);
        }

        function saveSupplyData(formData) {
            Swal.fire({
                title: 'กำลังบันทึก...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            $.ajax({
                url: 'supplies.php', // Target supplies.php for logic
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function (response) {
                    try {
                        let res = typeof response === 'string' ? JSON.parse(response) : response;
                        if (res.status === 'success') {
                            Swal.fire('สำเร็จ', 'บันทึกข้อมูลเรียบร้อย', 'success').then(() => location.reload());
                        } else {
                            Swal.fire('ข้อผิดพลาด', 'ไม่สามารถบันทึกได้', 'error');
                        }
                    } catch (e) {
                        console.error(response);
                        Swal.fire('ข้อผิดพลาด', 'Server Response Error', 'error');
                    }
                },
                error: function () {
                    Swal.fire('ข้อผิดพลาด', 'Network Error', 'error');
                }
            });
        }

        function deleteItem(id) {
            Swal.fire({
                title: 'ยืนยันการลบ?',
                text: "ข้อมูลจะถูกเปลี่ยนสถานะเป็น Inactive",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'ลบข้อมูล',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#ef4444'
            }).then((res) => {
                if (res.isConfirmed) {
                    $.post('supplies.php', {
                        id: id,
                        action: 'delete'
                    }, function () {
                        Swal.fire('ลบแล้ว', 'ข้อมูลถูกลบเรียบร้อย', 'success').then(() => location.reload());
                    });
                }
            })
        }
    </script>

    <!-- ===== NOTIFICATION SYSTEM ===== -->
    <script>
        (function () {
            const btn = document.getElementById('notif-btn');
            const dropdown = document.getElementById('notif-dropdown');
            const badge = document.getElementById('notif-badge');
            const list = document.getElementById('notif-list');
            if (!btn) return;

            const ICON_MAP = {
                request_new: { bg: 'bg-blue-100', ic: 'fas fa-file-alt', cl: 'text-blue-500' },
                section_pending_supply: { bg: 'bg-amber-100', ic: 'fas fa-clock', cl: 'text-amber-500' },
                section_pending_director: { bg: 'bg-orange-100', ic: 'fas fa-user-tie', cl: 'text-orange-500' },
                supply_pending: { bg: 'bg-purple-100', ic: 'fas fa-hourglass-half', cl: 'text-purple-500' },
                supply_approved: { bg: 'bg-green-100', ic: 'fas fa-check-circle', cl: 'text-green-500' },
                success: { bg: 'bg-green-100', ic: 'fas fa-check-circle', cl: 'text-green-500' },
                danger: { bg: 'bg-red-100', ic: 'fas fa-times-circle', cl: 'text-red-500' },
                warning: { bg: 'bg-yellow-100', ic: 'fas fa-exclamation-triangle', cl: 'text-yellow-600' },
                info: { bg: 'bg-sky-100', ic: 'fas fa-info-circle', cl: 'text-sky-500' },
            };

            function getIcon(type) {
                return ICON_MAP[type] || ICON_MAP['info'];
            }

            function timeAgo(dateStr) {
                const d = new Date(dateStr.replace(' ', 'T'));
                const diff = Math.floor((Date.now() - d.getTime()) / 1000);
                if (diff < 60) return 'เมื่อกี้';
                if (diff < 3600) return Math.floor(diff / 60) + ' นาทีที่แล้ว';
                if (diff < 86400) return Math.floor(diff / 3600) + ' ชั่วโมงที่แล้ว';
                return Math.floor(diff / 86400) + ' วันที่แล้ว';
            }

            function renderBadge(count) {
                if (count > 0) {
                    badge.textContent = count > 99 ? '99+' : count;
                    badge.classList.remove('hidden');
                } else {
                    badge.classList.add('hidden');
                }
            }

            function loadNotifications() {
                fetch('api_notifications.php?action=get', { credentials: 'same-origin' })
                    .then(r => r.json())
                    .then(data => {
                        if (data.status !== 'success') return;
                        renderBadge(data.unread_count);

                        if (!data.notifications || data.notifications.length === 0) {
                            list.innerHTML = '<div class="py-10 text-center text-gray-400 text-sm"><i class="fas fa-bell-slash text-2xl mb-2 block"></i>ไม่มีการแจ้งเตือน</div>';
                            return;
                        }

                        let html = '';
                        data.notifications.forEach(n => {
                            const read = n.is_read == 1;
                            const ic = getIcon(n.type);
                            const safeMsg = n.message.replace(/</g, '&lt;').replace(/>/g, '&gt;');
                            const safeLink = (n.link || 'approvals.php').replace(/'/g, '%27');
                            html += `
                        <div class="flex items-start gap-3 px-4 py-3 hover:bg-gray-50 cursor-pointer transition-colors ${read ? 'opacity-60' : ''}"
                             onclick="notifReadAndGo(${n.id},'${safeLink}')">
                            <div class="w-9 h-9 rounded-full ${ic.bg} flex items-center justify-center flex-shrink-0 mt-0.5">
                                <i class="${ic.ic} text-sm ${ic.cl}"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs text-gray-700 leading-snug ${read ? '' : 'font-semibold'}">${safeMsg}</p>
                                <p class="text-[10px] text-gray-400 mt-1">${timeAgo(n.created_at)}</p>
                            </div>
                            ${!read ? '<span class="w-2 h-2 bg-sky-500 rounded-full flex-shrink-0 mt-2"></span>' : ''}
                        </div>`;
                        });
                        list.innerHTML = html;
                    })
                    .catch(() => { });
            }

            window.notifReadAndGo = function (id, link) {
                fetch('api_notifications.php?action=mark_read', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'id=' + id
                }).then(() => {
                    dropdown.classList.add('hidden');
                    window.location.href = link;
                });
            };

            window.markAllRead = function () {
                fetch('api_notifications.php?action=mark_all_read', { method: 'POST', credentials: 'same-origin' })
                    .then(() => loadNotifications());
            };

            // Toggle dropdown
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                const hidden = dropdown.classList.toggle('hidden');
                if (!hidden) loadNotifications();
            });

            // Close on outside click
            document.addEventListener('click', function (e) {
                if (!dropdown.contains(e.target) && e.target !== btn) {
                    dropdown.classList.add('hidden');
                }
            });

            // Initial load + poll every 30 s
            loadNotifications();
            setInterval(loadNotifications, 30000);
        })();
    </script>

    <!-- ===== LOGIN NOTIFICATION POPUPS ===== -->
    <script>
        (function () {
            // Track if popup has been shown this session to prevent loops if we reload,
            // but user says "every time a user logs in", so we'll just check if sessionStorage has a flag.
            // Wait, "every time a user logs in" means once per login session. 
            // We can use sessionStorage.
            if (sessionStorage.getItem('login_notif_shown')) {
                // Already shown for this tab session
                // But if we want it literally every load unless dismissed, we might not use this.
                // Let's use it only for progress, but for 'acknowledge', we don't set this until they click close.
            }

            window.showLoginPopups = function () {
                fetch('api_login_popups.php?action=get_popups')
                    .then(r => r.json())
                    .then(data => {
                        if (data.status !== 'success' || !data.popups || data.popups.length === 0) {
                            return;
                        }

                        let popup = data.popups[0]; // Process one type at a time

                        if (popup.type === 'user_approved') {
                            let itemsHtml = popup.items.map(i => `<tr><td class="px-4 py-2 border-b">${i.name}</td><td class="px-4 py-2 border-b text-center font-bold text-sky-600">${i.approved_qty} ${i.unit}</td></tr>`).join('');
                            Swal.fire({
                                title: 'รายการรอรับวัสดุ (Pending material delivery)',
                                html: `
                                <div class="text-left font-[Sarabun]">
                                    <div class="mb-4 bg-green-50 p-4 rounded-xl border border-green-100">
                                        <p class="text-green-700 font-bold"><i class="fas fa-check-circle mr-2"></i>Request approved</p>
                                        <p class="text-sm mt-1">เลขที่ใบเบิก: <strong>${popup.request_name}</strong></p>
                                        <p class="text-sm">วันที่: <strong>${popup.date}</strong></p>
                                    </div>
                                    <table class="w-full text-sm text-left border">
                                        <thead class="bg-gray-100">
                                            <tr>
                                                <th class="px-4 py-2 border-b">รายการวัสดุ</th>
                                                <th class="px-4 py-2 border-b text-center">จำนวนที่อนุมัติ</th>
                                            </tr>
                                        </thead>
                                        <tbody>${itemsHtml}</tbody>
                                    </table>
                                </div>
                            `,
                                width: '80%',
                                allowOutsideClick: false,
                                confirmButtonText: 'รับทราบ',
                                confirmButtonColor: '#0ea5e9'
                            }).then((res) => {
                                if (res.isConfirmed) {
                                    // Acknowledge
                                    fetch('api_login_popups.php', {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                        body: 'action=acknowledge_user_approval&request_id=' + popup.request_id
                                    }).then(() => {
                                        // re-check if more
                                        showLoginPopups();
                                    });
                                }
                            });
                        } else if (popup.type === 'user_progress') {
                            if (sessionStorage.getItem('user_progress_shown')) return;
                            sessionStorage.setItem('user_progress_shown', '1');

                            let reqHtml = popup.requests.map(r => {
                                let sName = '';
                                if (r.status === 'pending_head') sName = '<span class="text-amber-500 font-bold">รอหัวหน้าส่วนอนุมัติ</span>';
                                else if (r.status === 'pending_supply') sName = '<span class="text-purple-500 font-bold">รอพัสดุตรวจสอบ</span>';
                                else if (r.status === 'pending_director') sName = '<span class="text-orange-500 font-bold">รอผู้อำนวยการอนุมัติ</span>';
                                return `<tr class="border-b"><td class="py-2 px-2">${r.request_name}</td><td class="py-2 px-2">${r.request_date}</td><td class="py-2 px-2 text-center">${sName}</td></tr>`;
                            }).join('');

                            Swal.fire({
                                title: 'สถานะการเบิกวัสดุของคุณ',
                                html: `
                                <div class="text-left font-[Sarabun] max-h-[60vh] overflow-y-auto">
                                    <table class="w-full text-sm">
                                        <thead class="bg-gray-100"><tr><th class="py-2 px-2">เลขที่ใบเบิก</th><th class="py-2 px-2">วันที่</th><th class="py-2 px-2 text-center">สถานะ</th></tr></thead>
                                        <tbody>${reqHtml}</tbody>
                                    </table>
                                    <p class="text-xs text-gray-500 mt-4">*สามารถติดตามความคืบหน้าได้ที่หน้า "รายการรออนุมัติ"</p>
                                </div>
                            `,
                                width: '80%',
                                allowOutsideClick: false,
                                confirmButtonText: 'ปิด',
                                confirmButtonColor: '#64748b'
                            });
                        } else if (['head_pending', 'supply_head_pending', 'director_pending'].includes(popup.type)) {
                            let title = popup.type === 'head_pending' ? 'รายการรออนุมัติ (หัวหน้าส่วน)' : (popup.type === 'director_pending' ? 'รายการรออนุมัติ (ผู้อำนวยการ)' : 'รายการรออนุมัติ (พัสดุ)');

                            let reqHtml = popup.requests.map(r => {
                                let sect = r.section ? `<br><span class="text-xs text-gray-400">${r.section}</span>` : '';
                                return `<tr class="border-b hover:bg-gray-50">
                                <td class="py-3 px-2 font-mono text-sky-600 font-bold">${r.request_name}</td>
                                <td class="py-3 px-2">${r.firstname} ${r.lastname}${sect}</td>
                                <td class="py-3 px-2 text-gray-500 text-xs">${r.request_date}</td>
                                <td class="py-3 px-2 text-right">
                                    <button onclick="quickApprove(${r.id}, '${r.request_name}', '${r.firstname} ${r.lastname}')" class="bg-green-500 hover:bg-green-600 text-white px-3 py-1.5 rounded-lg text-sm font-bold shadow-sm transition"><i class="fas fa-check mr-1"></i> อนุมัติ</button>
                                </td>
                            </tr>`;
                            }).join('');

                            Swal.fire({
                                title: title,
                                html: `
                                <div class="text-left font-[Sarabun] max-h-[60vh] overflow-y-auto">
                                    <table class="w-full text-sm">
                                        <thead class="bg-gray-100 sticky top-0"><tr><th class="py-2 px-2">เลขที่ใบเบิก</th><th class="py-2 px-2">ผู้เบิก</th><th class="py-2 px-2">วันที่ขอ</th><th class="py-2 px-2 text-right">จัดการ</th></tr></thead>
                                        <tbody>${reqHtml}</tbody>
                                    </table>
                                </div>
                            `,
                                width: '80%',
                                allowOutsideClick: false,
                                showCancelButton: true,
                                showConfirmButton: false,
                                cancelButtonText: 'ปิดหน้าต่าง',
                                cancelButtonColor: '#64748b'
                            });
                        }
                    });
            };

            window.quickApprove = function (id, reqName, reqUser) {
                Swal.fire({
                    title: 'กำลังอนุมัติ...',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading()
                });

                fetch('api_approve_request.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ request_id: id, action: 'approve', items: [] })
                })
                    .then(r => r.json())
                    .then(res => {
                        if (res.status === 'success') {
                            let now = new Date().toLocaleString('th-TH');
                            Swal.fire({
                                icon: 'success',
                                title: 'Withdrawal code approved.',
                                html: `
                            <div class="text-left font-[Sarabun] text-sm mt-4 bg-green-50 p-4 rounded-xl">
                                <p><strong>รหัสการเบิก:</strong> ${reqName}</p>
                                <p><strong>ชื่อผู้เบิก:</strong> ${reqUser}</p>
                                <p><strong>วันเวลาที่อนุมัติ:</strong> ${now}</p>
                                <p class="text-green-700 mt-2 font-bold text-center">ดำเนินการอนุมัติเบิกรหัสเบิกนี้แล้ว</p>
                            </div>
                        `,
                                width: '80%',
                                allowOutsideClick: false,
                                confirmButtonText: 'OK',
                                confirmButtonColor: '#0ea5e9'
                            }).then(() => {
                                showLoginPopups(); // Reload list
                            });
                        } else {
                            Swal.fire('ข้อผิดพลาด', res.message || 'ไม่สามารถอนุมัติได้', 'error').then(() => showLoginPopups());
                        }
                    })
                    .catch(() => Swal.fire('ข้อผิดพลาด', 'Network Error', 'error').then(() => showLoginPopups()));
            };

            // Call on load
            showLoginPopups();
        })();
    </script>

</body>

</html>