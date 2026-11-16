<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$role = strtolower($_SESSION['role']);

// Status Badge Formatter Helper
function renderStatusBadge($status) {
    $bgClass = 'bg-slate-50 text-slate-600 border-slate-100';
    $dotClass = 'bg-slate-500';
    $text = $status;

    switch ($status) {
        case 'pending_head':
            $bgClass = 'bg-amber-50 text-amber-600 border-amber-100';
            $dotClass = 'bg-amber-500';
            $text = 'รอหัวหน้าอนุมัติ';
            break;
        case 'pending_supply':
            $bgClass = 'bg-purple-50 text-purple-600 border-purple-100';
            $dotClass = 'bg-purple-500';
            $text = 'รอพัสดุตรวจสอบ';
            break;
        case 'pending_director':
            $bgClass = 'bg-orange-50 text-orange-600 border-orange-100';
            $dotClass = 'bg-orange-500';
            $text = 'รอผู้อำนวยการอนุมัติ';
            break;
        case 'approved':
            $bgClass = 'bg-emerald-50 text-emerald-600 border-emerald-100';
            $dotClass = 'bg-emerald-500';
            $text = 'อนุมัติแล้ว';
            break;
        case 'rejected':
            $bgClass = 'bg-rose-50 text-rose-600 border-rose-100';
            $dotClass = 'bg-rose-500';
            $text = 'ปฏิเสธการเบิก';
            break;
    }

    return '<div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold border shadow-sm ' . $bgClass . '">' .
           '<span class="w-1.5 h-1.5 rounded-full animate-pulse ' . $dotClass . '"></span>' .
           $text .
           '</div>';
}

// REMOVED: Strict role check redirection. Now all roles can access, but see different data.
// if (!in_array($role, $allowed_roles)) {
//     header("Location: dashboard.php");
//     exit;
// }

// Fetch Pending Requests
// Note: We use LEFT JOIN for users just in case, logic similar to before
$sql = "SELECT r.*, CONCAT(u.prefix, u.firstname, ' ', u.lastname) as fullname, u.section,
           (SELECT COUNT(*) FROM request_items WHERE request_id = r.id AND (is_deleted IS NULL OR is_deleted = 0)) as item_count,
           (SELECT SUM(qty) FROM request_items WHERE request_id = r.id AND (is_deleted IS NULL OR is_deleted = 0)) as total_qty
    FROM requests r
    LEFT JOIN users u ON r.user_id = u.id
    WHERE 1=1
";

$params = [];

// Filter by Role
// 1. Head: See only pending_head from SAME section
// Filter by Role
// 1. Head / Deputy Head: See only pending_head from SAME section
if ($role == 'head' || $role == 'deputy_head') {
    $sql .= " AND r.status = 'pending_head' AND TRIM(u.section) = TRIM(?)";
    $params[] = $_SESSION['section'];
}
// 2. Supply Head: See pending_supply + pending_director (acting as director) + pending_head (Only Inventory 'ส่วนคลัง')
elseif ($role == 'supply_head') {
    $sql .= " AND (r.status = 'pending_supply' OR r.status = 'pending_director' OR (r.status = 'pending_head' AND u.section = 'ส่วนคลัง'))";
}
// 2.1 Supply Officer: See pending_supply + pending_head (Only Inventory 'ส่วนคลัง')
elseif ($role == 'supply_officer') {
    $sql .= " AND (r.status = 'pending_supply' OR (r.status = 'pending_head' AND u.section = 'ส่วนคลัง'))";
}
// 3. Director: See pending_director
elseif ($role == 'director') {
    $sql .= " AND r.status = 'pending_director'";
}
// 4. Admin: See All Pending
elseif ($role == 'admin') {
    $sql .= " AND r.status LIKE 'pending%'";
}
// 5. User: Own Pending
else {
    $sql .= " AND r.user_id = ? AND r.status LIKE 'pending%'";
    $params[] = $_SESSION['user_id'];
}

$sql .= " ORDER BY r.request_date ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allRequests = $stmt->fetchAll();

// Split Requests logic
$groupedGeneralRequests = [];
$inventoryRequests = [];

// Helper to accumulate grouped data
function addToGroup(&$groups, $req)
{
    $sec = trim($req['section']);
    if (!isset($groups[$sec])) {
        $groups[$sec] = [
            'section' => $sec,
            'ids' => [],
            'latest_date' => $req['request_date'],
            'total_requests' => 0,
            'total_items' => 0,
            'status' => $req['status'] // Assuming status alignment
        ];
    }
    $groups[$sec]['ids'][] = $req['id'];
    $groups[$sec]['total_requests']++;
    $groups[$sec]['total_items'] += $req['item_count'];
    if ($req['request_date'] > $groups[$sec]['latest_date']) {
        $groups[$sec]['latest_date'] = $req['request_date'];
    }
}

if (in_array($role, ['supply_head', 'supply_officer', 'director'])) {
    foreach ($allRequests as $req) {
        $isInventory = ($req['section'] == 'ส่วนคลัง');

        // Director: Split by Section Origin purely (Inventory vs others)
        if ($role == 'director') {
            if ($isInventory)
                $inventoryRequests[] = $req;
            else
                addToGroup($groupedGeneralRequests, $req);
        }
        // Supply Head/Officer: 
        // Inventory Table = pending_head AND section=Stock
        // General Table = pending_supply (from any section)
        else {
            if ($req['status'] == 'pending_head' && $isInventory) {
                $inventoryRequests[] = $req;
            } else {
                // Determine if we should group pending_supply from Stock into General? Yes.
                addToGroup($groupedGeneralRequests, $req);
            }
        }
    }
} else {
    // Admin / Head / User -> No Grouping (Legacy view or Single view)
    // Actually, Admin might want grouping too? Let's keep Admin as simple list for now as per "Director/Supply Head" request.
    // Wait, prompt request was specific to "Director/Supply Head/Inventory Chief".
    // If Admin needs it, we can add later. For now, keep Admin using $generalRequests as Flat Array?
    // To safe break: Let's make everyone see the logic but check Role in view.
    // If standard role, we map flattened to $generalRequests?
    // Actually, let's just group everyone for consistency if possible, OR keep separate logic.
    // Let's stick to the prompt: "In role Director/Supply Head... collapse info".
    // Admin/Head/User code flow below uses $generalRequests (flat).
    $generalRequests = $allRequests;
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายการรออนุมัติ - ระบบเบิกวัสดุ</title>
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
            background-color: #f8fafc;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f5f9;
        }

        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* DataTables Customization */
        .dataTables_wrapper .dataTables_length select {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 0.5rem center;
            background-repeat: no-repeat;
            background-size: 1.5em 1.5em;
            padding-right: 2.5rem;
            border-color: #e2e8f0;
            color: #475569;
            border-radius: 0.75rem;
        }

        .dataTables_wrapper .dataTables_filter input {
            border: 1px solid #e2e8f0;
            padding: 0.6rem 1rem;
            border-radius: 0.75rem;
            margin-bottom: 1rem;
            outline: none;
            transition: all 0.2s;
        }

        .dataTables_wrapper .dataTables_filter input:focus {
            border-color: #0ea5e9;
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
        }

        table.dataTable.no-footer {
            border-bottom: none !important;
        }

        table.dataTable thead th,
        table.dataTable thead td {
            border-bottom: 1px solid #f1f5f9 !important;
        }
    </style>
</head>

<body class="bg-gray-50 flex h-screen overflow-hidden">

    <?php $page = 'approvals'; include 'sidebar.php'; ?>

    <main class="flex-1 flex flex-col overflow-hidden relative">

    <header class="bg-white shadow-sm px-6 py-4 flex items-center justify-between sticky top-0 z-20 shrink-0">
        <div class="flex items-center gap-4">
            <a href="dashboard.php" class="text-gray-400 hover:text-sky-600 transition"><i
                    class="fas fa-arrow-left text-xl"></i></a>
            <h1 class="text-xl font-bold text-gray-800">รายการรออนุมัติ (<?php echo ucfirst($role); ?>)</h1>
        </div>

        <!-- Notifications Bell -->
        <div class="relative" id="notif-wrapper">
            <button id="notif-btn" class="text-gray-500 hover:text-sky-600 transition relative p-2 rounded-xl hover:bg-gray-100" aria-label="การแจ้งเตือน">
                <i class="fas fa-bell text-xl"></i>
                <span id="notif-badge" class="hidden absolute -top-0.5 -right-0.5 bg-red-500 text-white text-[10px] font-bold min-w-[18px] h-[18px] rounded-full flex items-center justify-center px-1 leading-none">0</span>
            </button>
            <!-- Dropdown -->
            <div id="notif-dropdown" class="hidden absolute right-0 mt-2 w-96 bg-white rounded-2xl shadow-2xl border border-gray-100 z-50 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between bg-gradient-to-r from-sky-50 to-white">
                    <span class="text-sm font-bold text-gray-700 flex items-center gap-2"><i class="fas fa-bell text-sky-500"></i> การแจ้งเตือน</span>
                    <button onclick="markAllRead()" class="text-xs text-sky-500 hover:text-sky-700 font-medium transition">อ่านทั้งหมด</button>
                </div>
                <div id="notif-list" class="max-h-80 overflow-y-auto divide-y divide-gray-50">
                    <div class="p-6 text-center text-gray-400 text-sm">กำลังโหลด...</div>
                </div>
            </div>
        </div>
    </header>

    <main class="flex-1 overflow-y-auto p-6">
        <div class="w-full space-y-6">

    <!-- Table 1: General Requests (External / Forwarded) -->
    <div class="bg-white rounded-2xl shadow-lg border border-gray-100 p-6">
        <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
            <i class="fas fa-list-alt text-sky-500"></i>
            <?php echo (in_array($role, ['supply_head', 'supply_officer', 'director'])) ? 'รายการรออนุมัติ (ทั่วไป/ต่างส่วนงาน)' : 'รายการรออนุมัติ'; ?>
        </h3>

        <?php if (in_array($role, ['supply_head', 'supply_officer', 'director'])): ?>
            <!-- GROUPED VIEW -->
            <?php if (empty($groupedGeneralRequests)): ?>
                <div
                    class="flex flex-col items-center justify-center py-16 border-2 border-dashed border-slate-100 rounded-3xl bg-slate-50/50">
                    <div class="w-20 h-20 bg-white rounded-full flex items-center justify-center shadow-sm mb-4">
                        <i class="fas fa-check-circle text-4xl text-emerald-400"></i>
                    </div>
                    <h4 class="text-slate-500 font-bold text-lg">ไม่มีรายการค้างจัดการ</h4>
                    <p class="text-slate-400 text-sm">คุณจัดการงานทุกอย่างเรียบร้อยแล้ว</p>
                </div>
            <?php else: ?>
                <table id="groupedTable" class="w-full text-left stripe hover nowrap align-middle">
                    <thead>
                        <tr
                            class="bg-slate-50/80 text-slate-500 text-xs uppercase tracking-wider font-semibold border-b border-slate-100">
                            <th class="py-4 px-6 rounded-l-2xl">วันที่ล่าสุด</th>
                            <th class="py-4 px-6">ส่วนงาน</th>
                            <th class="py-4 px-6 text-center">จำนวนผู้ขอเบิก</th>
                            <th class="py-4 px-6 text-center">รวมรายการวัสดุ</th>
                            <th class="py-4 px-6 text-center">สถานะ</th>
                            <th class="py-4 px-6 text-center rounded-r-2xl">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php foreach ($groupedGeneralRequests as $group): ?>
                            <tr class="group hover:bg-sky-50/30 transition-colors duration-200">
                                <td class="px-6 py-5">
                                    <div class="flex flex-col">
                                        <span
                                            class="font-bold text-slate-700 text-sm"><?php echo date('d M', strtotime($group['latest_date'])); ?></span>
                                        <span
                                            class="text-xs text-slate-400"><?php echo date('Y H:i', strtotime($group['latest_date'])); ?></span>
                                    </div>
                                </td>
                                <td class="px-6 py-5">
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="w-10 h-10 rounded-xl bg-sky-100 text-sky-600 flex items-center justify-center text-lg font-bold shadow-sm">
                                            <i class="fas fa-building"></i>
                                        </div>
                                        <span
                                            class="text-base font-bold text-slate-700"><?php echo htmlspecialchars($group['section']); ?></span>
                                    </div>
                                </td>
                                <td class="px-6 py-5 text-center">
                                    <span
                                        class="bg-white border border-slate-200 text-slate-600 px-3 py-1 rounded-full text-xs font-bold shadow-sm">
                                        <i class="fas fa-users mr-1"></i> <?php echo $group['total_requests']; ?> คน
                                    </span>
                                </td>
                                <td class="px-6 py-5 text-center">
                                    <span class="font-bold text-slate-700 text-lg"><?php echo $group['total_items']; ?></span>
                                    <span class="text-xs text-slate-400 ml-1">ชิ้น</span>
                                </td>
                                <td class="px-6 py-5 text-center">
                                    <?php echo renderStatusBadge($group['status']); ?>
                                </td>
                                <td class="px-6 py-5 text-center">
                                    <button
                                        onclick="openGroupModal('<?php echo implode(',', $group['ids']); ?>', '<?php echo htmlspecialchars($group['section']); ?>')"
                                        class="bg-gradient-to-r from-sky-500 to-blue-600 hover:from-sky-600 hover:to-blue-700 text-white px-5 py-2.5 rounded-xl text-xs font-bold shadow-lg shadow-sky-200 hover:shadow-sky-300 transition-all transform active:scale-95 flex items-center justify-center gap-2 mx-auto">
                                        <i class="fas fa-layer-group"></i> ตรวจสอบ (กลุ่ม)
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

        <?php else: ?>
            <!-- LEGACY FLAT VIEW (Admin / User / Head) -->
            <?php if (empty($generalRequests)): ?>
                <div class="text-center py-8 border-2 border-dashed border-gray-100 rounded-xl">
                    <div class="text-gray-400 text-sm">ไม่มีรายการ</div>
                </div>
            <?php else: ?>
                <table id="generalTable" class="w-full text-left stripe hover nowrap align-middle">
                    <!-- ... (Same Header as before) ... -->
                    <thead>
                        <tr
                            class="bg-slate-50/80 text-slate-500 text-xs uppercase tracking-wider font-semibold border-b border-slate-100">
                            <th class="py-4 px-6 rounded-l-2xl">วันที่ขอเบิก</th>
                            <th class="py-4 px-6">เลขที่ใบเบิก</th>
                            <th class="py-4 px-6">ผู้ขอเบิก</th>
                            <th class="py-4 px-6">ส่วนงาน</th>
                            <th class="py-4 px-6 text-center">จำนวน</th>
                            <th class="py-4 px-6 text-center">สถานะ</th>
                            <th class="py-4 px-6 text-center rounded-r-2xl">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php foreach ($generalRequests as $req): ?>
                            <tr class="group hover:bg-sky-50/30 transition-colors duration-200">
                                <td class="px-6 py-5">
                                    <div class="flex flex-col">
                                        <span
                                            class="font-bold text-slate-700 text-sm"><?php echo date('d M', strtotime($req['request_date'])); ?></span>
                                        <span
                                            class="text-xs text-slate-400"><?php echo date('Y H:i', strtotime($req['request_date'])); ?></span>
                                    </div>
                                </td>
                                <td class="px-6 py-5">
                                    <span
                                        class="font-mono font-bold text-sky-600 bg-sky-50 px-2.5 py-1 rounded-lg text-xs border border-sky-100 group-hover:border-sky-200 transition-colors">
                                        <?php echo htmlspecialchars($req['request_name']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-5">
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="w-8 h-8 rounded-full bg-gradient-to-br from-slate-100 to-slate-200 flex items-center justify-center text-slate-500 text-xs font-bold shadow-sm ring-2 ring-white">
                                            <?php echo mb_substr($req['fullname'], 0, 1); ?>
                                        </div>
                                        <span
                                            class="text-sm font-medium text-slate-700"><?php echo htmlspecialchars($req['fullname']); ?></span>
                                    </div>
                                </td>
                                <td class="px-6 py-5">
                                    <span
                                        class="inline-block px-2.5 py-1 rounded-full text-xs font-semibold bg-slate-100 text-slate-500 border border-slate-200">
                                        <?php echo htmlspecialchars($req['section']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-5 text-center">
                                    <span class="font-bold text-slate-700"><?php echo $req['item_count']; ?></span>
                                    <span class="text-xs text-slate-400 ml-1">รายการ</span>
                                </td>
                                <td class="px-6 py-5 text-center">
                                    <?php echo renderStatusBadge($req['status']); ?>
                                </td>
                                <td class="px-6 py-5 text-center">
                                    <button
                                        onclick="openModal(<?php echo $req['id']; ?>, '<?php echo htmlspecialchars($req['request_name']); ?>')"
                                        class="bg-gradient-to-r from-sky-500 to-blue-600 hover:from-sky-600 hover:to-blue-700 text-white px-5 py-2.5 rounded-xl text-xs font-bold shadow-lg shadow-sky-200 hover:shadow-sky-300 transition-all transform active:scale-95 flex items-center justify-center gap-2 mx-auto">
                                        <i class="fas fa-search-plus"></i> ตรวจสอบ
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Table 2: Inventory Section Requests (Internal) -->
    <?php if (in_array($role, ['supply_head', 'supply_officer', 'director'])): ?>
        <div class="bg-white rounded-2xl shadow-lg border border-gray-100 p-6 mt-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                <i class="fas fa-warehouse text-amber-500"></i>
                รายการรออนุมัติ (ภายในส่วนคลัง)
            </h3>

            <?php if (empty($inventoryRequests)): ?>
                <div
                    class="flex flex-col items-center justify-center py-16 border-2 border-dashed border-slate-100 rounded-3xl bg-slate-50/50">
                    <div class="w-20 h-20 bg-white rounded-full flex items-center justify-center shadow-sm mb-4">
                        <i class="fas fa-check-circle text-4xl text-emerald-400"></i>
                    </div>
                    <h4 class="text-slate-500 font-bold text-lg">ไม่มีรายการจากส่วนคลัง</h4>
                    <p class="text-slate-400 text-sm">ไม่มีคำขอที่ค้างรอจัดการในคลัง</p>
                </div>
            <?php else: ?>
                <table id="inventoryTable" class="w-full text-left stripe hover nowrap align-middle">
                    <thead>
                        <tr class="bg-slate-50/80 text-slate-500 text-xs uppercase tracking-wider font-semibold border-b border-slate-100">
                            <th class="py-4 px-6 rounded-l-2xl">วันที่ขอเบิก</th>
                            <th class="py-4 px-6">เลขที่ใบเบิก</th>
                            <th class="py-4 px-6">ผู้ขอเบิก</th>
                            <th class="py-4 px-6">ส่วนงาน</th>
                            <th class="py-4 px-6 text-center">จำนวน</th>
                            <th class="py-4 px-6 text-center">สถานะ</th>
                            <th class="py-4 px-6 text-center rounded-r-2xl">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php foreach ($inventoryRequests as $req): ?>
                            <tr class="group hover:bg-amber-50/20 transition-colors duration-200">
                                <td class="px-6 py-5">
                                    <div class="flex flex-col">
                                        <span class="font-bold text-slate-700 text-sm"><?php echo date('d M', strtotime($req['request_date'])); ?></span>
                                        <span class="text-xs text-slate-400"><?php echo date('Y H:i', strtotime($req['request_date'])); ?></span>
                                    </div>
                                </td>
                                <td class="px-6 py-5">
                                    <span class="font-mono font-bold text-amber-600 bg-amber-50 px-2.5 py-1 rounded-lg text-xs border border-amber-100 group-hover:border-amber-200 transition-colors">
                                        <?php echo htmlspecialchars($req['request_name']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-5">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 rounded-full bg-gradient-to-br from-amber-100 to-amber-200 flex items-center justify-center text-amber-700 text-xs font-bold shadow-sm ring-2 ring-white">
                                            <?php echo mb_substr($req['fullname'], 0, 1); ?>
                                        </div>
                                        <span class="text-sm font-medium text-slate-700"><?php echo htmlspecialchars($req['fullname']); ?></span>
                                    </div>
                                </td>
                                <td class="px-6 py-5">
                                    <span class="inline-block px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-700 border border-amber-200">
                                        <?php echo htmlspecialchars($req['section']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-5 text-center">
                                    <span class="font-bold text-slate-700"><?php echo $req['item_count']; ?></span>
                                    <span class="text-xs text-slate-400 ml-1">รายการ</span>
                                </td>
                                <td class="px-6 py-5 text-center">
                                    <?php echo renderStatusBadge($req['status']); ?>
                                </td>
                                <td class="px-6 py-5 text-center">
                                    <button
                                        onclick="openModal(<?php echo $req['id']; ?>, '<?php echo htmlspecialchars($req['request_name']); ?>')"
                                        class="bg-gradient-to-r from-amber-500 to-orange-600 hover:from-amber-600 hover:to-orange-700 text-white px-5 py-2.5 rounded-xl text-xs font-bold shadow-lg shadow-amber-200 hover:shadow-amber-300 transition-all transform active:scale-95 flex items-center justify-center gap-2 mx-auto">
                                        <i class="fas fa-search-plus"></i> ตรวจสอบ
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>


    <script>
        $(document).ready(function () {
            if ($('#groupedTable').length) {
                $('#groupedTable').DataTable({ responsive: true, language: { search: 'ค้นหา:', emptyTable: 'ไม่มีข้อมูล' } });
            }
            if ($('#generalTable').length) {
                $('#generalTable').DataTable({ responsive: true, language: { search: 'ค้นหา:', emptyTable: 'ไม่มีข้อมูล' } });
            }
            if ($('#inventoryTable').length) {
                $('#inventoryTable').DataTable({ responsive: true, language: { search: 'ค้นหา:', emptyTable: 'ไม่มีข้อมูล' } });
            }
        });

        // ===== NOTIFICATION SYSTEM =====
        (function () {
            const btn      = document.getElementById('notif-btn');
            const dropdown = document.getElementById('notif-dropdown');
            const badge    = document.getElementById('notif-badge');
            const list     = document.getElementById('notif-list');
            if (!btn) return;

            const ICON_MAP = {
                request_new:              { bg: 'bg-blue-100',   ic: 'fas fa-file-alt',            cl: 'text-blue-500'   },
                section_pending_supply:   { bg: 'bg-amber-100',  ic: 'fas fa-clock',               cl: 'text-amber-500'  },
                section_pending_director: { bg: 'bg-orange-100', ic: 'fas fa-user-tie',             cl: 'text-orange-500' },
                supply_pending:           { bg: 'bg-purple-100', ic: 'fas fa-hourglass-half',       cl: 'text-purple-500' },
                supply_approved:          { bg: 'bg-green-100',  ic: 'fas fa-check-circle',         cl: 'text-green-500'  },
                success:                  { bg: 'bg-green-100',  ic: 'fas fa-check-circle',         cl: 'text-green-500'  },
                danger:                   { bg: 'bg-red-100',    ic: 'fas fa-times-circle',         cl: 'text-red-500'    },
                warning:                  { bg: 'bg-yellow-100', ic: 'fas fa-exclamation-triangle', cl: 'text-yellow-600' },
                info:                     { bg: 'bg-sky-100',    ic: 'fas fa-info-circle',          cl: 'text-sky-500'    },
            };

            function getIcon(type) { return ICON_MAP[type] || ICON_MAP['info']; }

            function timeAgo(dateStr) {
                const d    = new Date(dateStr.replace(' ', 'T'));
                const diff = Math.floor((Date.now() - d.getTime()) / 1000);
                if (diff < 60)    return 'เมื่อกี้';
                if (diff < 3600)  return Math.floor(diff / 60) + ' นาทีที่แล้ว';
                if (diff < 86400) return Math.floor(diff / 3600) + ' ชั่วโมงที่แล้ว';
                return Math.floor(diff / 86400) + ' วันที่แล้ว';
            }

            function loadNotifications() {
                fetch('api_notifications.php?action=get', { credentials: 'same-origin' })
                    .then(r => r.json())
                    .then(data => {
                        if (data.status !== 'success') return;
                        const count = data.unread_count;
                        if (count > 0) {
                            badge.textContent = count > 99 ? '99+' : count;
                            badge.classList.remove('hidden');
                        } else {
                            badge.classList.add('hidden');
                        }
                        if (!data.notifications || data.notifications.length === 0) {
                            list.innerHTML = '<div class="py-10 text-center text-gray-400 text-sm"><i class="fas fa-bell-slash text-2xl mb-2 block"></i>ไม่มีการแจ้งเตือน</div>';
                            return;
                        }
                        let html = '';
                        data.notifications.forEach(n => {
                            const read = n.is_read == 1;
                            const ic   = getIcon(n.type);
                            const safeMsg  = n.message.replace(/</g,'&lt;').replace(/>/g,'&gt;');
                            const safeLink = (n.link || 'approvals.php').replace(/'/g,'%27');
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
                    .catch(() => {});
            }

            window.notifReadAndGo = function (id, link) {
                fetch('api_notifications.php?action=mark_read', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'id=' + id
                }).then(() => { dropdown.classList.add('hidden'); window.location.href = link; });
            };

            window.markAllRead = function () {
                fetch('api_notifications.php?action=mark_all_read', { method: 'POST', credentials: 'same-origin' })
                    .then(() => loadNotifications());
            };

            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                const hidden = dropdown.classList.toggle('hidden');
                if (!hidden) loadNotifications();
            });

            document.addEventListener('click', function (e) {
                if (!dropdown.contains(e.target) && e.target !== btn) dropdown.classList.add('hidden');
            });

            loadNotifications();
            setInterval(loadNotifications, 30000);
        })();
    </script>
    <!-- Approval Logic Scripts -->
    <?php if (in_array($role, ['admin', 'director', 'supply_head', 'supply_officer', 'head', 'deputy_head', 'user'])): ?>
        <script>
            const currentRole = '<?php echo $role; ?>';
            const isUser = <?php echo ($role === 'user') ? 'true' : 'false'; ?>;

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
                            <p class="text-xs text-sky-600 mt-1 ml-6">${isUser ? 'รายละเอียดรายการวัสดุที่ขอเบิก' : 'ตรวจสอบรายการและจำนวนที่เบิก'}</p>
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
                                    <th class="px-4 py-3 text-center w-28">${isUser ? 'จำนวนที่ขอ' : 'จำนวนอนุมัติ'}</th>
                                    ${!isUser ? '<th class="px-4 py-3 text-center w-16">ลบ</th>' : ''}
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
                    const reasonBadge = item.is_deleted ? `<div class="text-xs text-red-500 font-bold no-underline mt-1"><i class="fas fa-comment-alt mr-1"></i>เหตุผล: ${item.reason || item.edit_reason || ''}</div>` : '';

                    let qtyCol = '';
                    let actionCol = '';

                    if (isUser) {
                        qtyCol = `
                            <td class="px-4 py-3 text-center">
                                <span class="font-bold text-gray-700 text-base">${item.qty || item.current_qty}</span>
                                <span class="text-xs text-gray-500 ml-1">${item.unit || ''}</span>
                            </td>
                        `;
                    } else {
                        // Input Disabled for Approver
                        const inputDisabled = item.is_deleted ? 'disabled' : '';
                        const inputBg = item.is_deleted ? 'bg-gray-100 text-gray-500' : 'bg-white text-gray-700';

                        qtyCol = `
                            <td class="px-4 py-3 text-center">
                                <input type="number" min="1" 
                                    value="${item.current_qty}" 
                                    onchange="updateQty(${index}, this)"
                                    class="w-20 text-center border-2 border-gray-100 rounded-lg p-1.5 focus:ring-2 focus:ring-sky-500 focus:border-sky-500 font-bold ${inputBg} shadow-sm outline-none transition"
                                    ${inputDisabled}
                                >
                            </td>
                        `;

                        actionCol = `
                            <td class="px-4 py-3 text-center">
                                <button type="button" 
                                    onclick="handleClickDelete(${index})"
                                    class="${item.is_deleted ? 'bg-red-100 text-red-500 hover:bg-red-200' : 'bg-gray-100 text-gray-400 hover:bg-red-500 hover:text-white'} w-10 h-10 rounded-xl transition shadow-sm flex items-center justify-center mx-auto"
                                >
                                    <i class="${item.is_deleted ? 'fas fa-undo' : 'fas fa-trash-alt'}"></i>
                                </button>
                            </td>
                        `;
                    }

                    html += `
                     <tr class="${rowClass} ${opacity} transition group">
                        <td class="px-4 py-3 text-center">
                             <img src="${imgSrc}" class="w-12 h-12 object-cover rounded-lg border border-gray-200 shadow-sm mx-auto">
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-bold ${textClass} text-base">${item.supply_name}</div>
                            <div class="text-xs text-gray-400 font-normal">รหัส: ${item.code || '-'}</div>
                            ${reasonBadge}
                        </td>
                        ${qtyCol}
                        ${actionCol}
                     </tr>
                `;
                });

                html += `</tbody></table></div></div>`;

                // View Only Mode for Regular User
                if (isUser) {
                    Swal.fire({
                        width: '750px',
                        html: html,
                        showConfirmButton: false,
                        showDenyButton: false,
                        showCancelButton: true,
                        cancelButtonText: 'ปิดหน้าต่าง',
                        cancelButtonColor: '#64748b',
                        customClass: {
                            popup: 'rounded-3xl shadow-2xl',
                            cancelButton: 'rounded-xl px-6 py-2.5 font-bold text-sm bg-slate-500 text-white hover:bg-slate-600'
                        }
                    });
                    return;
                }

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
        </div>
    </main>
</main>
</body>

</html>