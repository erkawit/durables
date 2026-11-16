<?php
session_start();
require_once 'config.php';

// Access Check
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'director', 'supply_head', 'supply_officer'])) {
    header("Location: dashboard.php");
    exit;
}

// Allow year selection (default to current year)
$year = isset($_GET['year']) ? (int) $_GET['year'] : date('Y');

// Get available years from database
$availableYears = $pdo->query("SELECT DISTINCT EXTRACT(YEAR FROM request_date) as year FROM requests WHERE request_date IS NOT NULL ORDER BY year DESC")->fetchAll(PDO::FETCH_COLUMN);
$availableYears = array_map('intval', $availableYears);
if (empty($availableYears)) {
    $availableYears = [date('Y')];
}

// 1. Stats Overview
$stats = [
    'total' => $pdo->query("SELECT COUNT(*) FROM requests")->fetchColumn(),
    'pending' => $pdo->query("SELECT COUNT(*) FROM requests WHERE status LIKE 'pending%'")->fetchColumn(),
    'approved' => $pdo->query("SELECT COUNT(*) FROM requests WHERE status = 'approved'")->fetchColumn(),
    'rejected' => $pdo->query("SELECT COUNT(*) FROM requests WHERE status = 'rejected'")->fetchColumn()
];

// 2. Monthly Requests (Isolate by Year)
$monthlyData = array_fill(1, 12, 0);
$sqlMonthly = "SELECT EXTRACT(MONTH FROM request_date) as m, COUNT(*) as c FROM requests WHERE EXTRACT(YEAR FROM request_date) = ? GROUP BY m";
$stmt = $pdo->prepare($sqlMonthly);
$stmt->execute([$year]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $monthlyData[(int)$row['m']] = (int)$row['c'];
}
$monthLabels = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
$monthValues = array_values($monthlyData);

// 3. Top 10 Popular Items
$sqlTop = "SELECT s.name as material_name, s.code as material_code, SUM(COALESCE(ri.approved_qty, ri.qty)) as total_qty, s.unit 
            FROM request_items ri 
            JOIN supplies s ON ri.supply_id = s.id 
            JOIN requests r ON ri.request_id = r.id
            WHERE r.status IN ('approved', 'completed') AND (ri.is_deleted IS NULL OR ri.is_deleted = 0)
            GROUP BY ri.supply_id, s.name, s.code, s.unit 
            ORDER BY total_qty DESC 
            LIMIT 10";
$topItems = $pdo->query($sqlTop)->fetchAll(PDO::FETCH_ASSOC);

// 4. Recent Requests Table
$sqlRecent = "SELECT r.*, CONCAT(u.prefix, u.firstname, ' ', u.lastname) as fullname, u.section
                FROM requests r
                JOIN users u ON r.user_id = u.id
                ORDER BY r.request_date DESC
                LIMIT 20";
$recentRequests = $pdo->query($sqlRecent)->fetchAll(PDO::FETCH_ASSOC);

// 5. Statistics by Section (Material withdrawals by department)
$sqlBySection = "SELECT 
                    u.section,
                    COUNT(DISTINCT r.id) as request_count,
                    SUM(COALESCE(ri.approved_qty, ri.qty)) as total_qty
                FROM requests r
                JOIN users u ON r.user_id = u.id
                JOIN request_items ri ON r.id = ri.request_id
                WHERE r.status IN ('approved', 'completed') AND (ri.is_deleted IS NULL OR ri.is_deleted = 0)
                GROUP BY u.section
                ORDER BY total_qty DESC";
$sectionStats = $pdo->query($sqlBySection)->fetchAll(PDO::FETCH_ASSOC);

// 6. Material breakdown by Section (Which materials were withdrawn by each section)
$sqlMaterialBySection = "SELECT 
                            u.section,
                            s.name as material_name,
                            s.code as material_code,
                            SUM(COALESCE(ri.approved_qty, ri.qty)) as total_qty,
                            s.unit
                        FROM requests r
                        JOIN users u ON r.user_id = u.id
                        JOIN request_items ri ON r.id = ri.request_id
                        JOIN supplies s ON ri.supply_id = s.id
                        WHERE r.status IN ('approved', 'completed') AND (ri.is_deleted IS NULL OR ri.is_deleted = 0)
                        GROUP BY u.section, s.id
                        ORDER BY u.section ASC, total_qty DESC";
$materialBySection = $pdo->query($sqlMaterialBySection)->fetchAll(PDO::FETCH_ASSOC);

// 7. Low Stock Items
$sqlLowStock = "SELECT * FROM supplies WHERE status = 'active' AND (qty <= min_stock OR qty = 0) ORDER BY qty ASC";
$lowStockItems = $pdo->query($sqlLowStock)->fetchAll(PDO::FETCH_ASSOC);

// 8. Recent Imports
$sqlRecentImports = "SELECT p.*, CONCAT(u.prefix, u.firstname, ' ', u.lastname) as fullname
                FROM procurements p
                LEFT JOIN users u ON p.importer_id = u.id
                ORDER BY p.import_date DESC
                LIMIT 20";
$recentImports = $pdo->query($sqlRecentImports)->fetchAll(PDO::FETCH_ASSOC);

// 9. Users list for individual requisition filter
$userList = $pdo->query("SELECT id, CONCAT(prefix, firstname, ' ', lastname) AS fullname, section FROM users WHERE status='active' ORDER BY section, firstname")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายงานสรุป - ระบบเบิกวัสดุ</title>
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- DataTables -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>

    <!-- Tom Select (searchable dropdown) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.min.css">
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>

    <!-- Flatpickr (Thai B.E. date picker) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/th.js"></script>

    <style>
        body {
            font-family: 'Sarabun', sans-serif;
        }

        .dt-row:hover {
            background-color: #f8fafc !important;
        }

        .dataTables_wrapper .dataTables_length select,
        .dataTables_wrapper .dataTables_filter input {
            border: 1px solid #e2e8f0;
            padding: 0.5rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
        }

        .stat-card {
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
        }

        .instruction-banner {
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-fade-in {
            animation: fadeIn 0.5s ease-out;
        }

        /* ── Tom Select overrides ── */
        .ts-wrapper { width: 100% !important; }
        .ts-control {
            border: 1px solid #d1d5db !important;
            border-radius: 0.5rem !important;
            padding: 0.45rem 0.75rem !important;
            font-family: 'Sarabun', sans-serif !important;
            font-size: 0.875rem !important;
            min-height: 38px !important;
            box-shadow: none !important;
        }
        .ts-control:focus-within {
            border-color: #6366f1 !important;
            box-shadow: 0 0 0 2px rgba(99,102,241,.25) !important;
        }
        .ts-dropdown {
            font-family: 'Sarabun', sans-serif !important;
            font-size: 0.875rem !important;
            border-radius: 0.5rem !important;
            border: 1px solid #e2e8f0 !important;
            box-shadow: 0 8px 24px rgba(0,0,0,.1) !important;
        }
        .ts-dropdown .option.selected { background: #6366f1 !important; }
        .ts-dropdown .option:hover    { background: #eef2ff !important; color: #3730a3 !important; }
        .ts-dropdown-content { max-height: 260px !important; }

        /* ── Flatpickr Thai B.E. overrides ── */
        .flatpickr-input {
            font-family: 'Sarabun', sans-serif !important;
            font-size: 0.875rem !important;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            padding: 0.45rem 0.75rem;
            width: 100%;
            outline: none;
            transition: border-color .15s, box-shadow .15s;
            background: #fff;
        }
        .flatpickr-input:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 2px rgba(99,102,241,.25);
        }
        .flatpickr-calendar {
            font-family: 'Sarabun', sans-serif !important;
            border-radius: 0.75rem !important;
            box-shadow: 0 12px 32px rgba(0,0,0,.15) !important;
        }
        .flatpickr-months .flatpickr-month,
        .flatpickr-weekdays { background: #4f46e5 !important; color: #fff !important; border-radius: 0.75rem 0.75rem 0 0; }
        .flatpickr-current-month .flatpickr-monthDropdown-months,
        .flatpickr-current-month input.cur-year { color: #fff !important; font-family: 'Sarabun', sans-serif !important; font-size: 14px !important; }
        .flatpickr-current-month .numInputWrapper span { border-color: rgba(255,255,255,.4) !important; }
        .flatpickr-current-month .numInputWrapper span svg { fill: #fff !important; }
        span.flatpickr-weekday { color: rgba(255,255,255,.8) !important; font-weight: 600 !important; }
        .flatpickr-day.selected, .flatpickr-day.selected:hover {
            background: #4f46e5 !important; border-color: #4f46e5 !important;
        }
        .flatpickr-day:hover { background: #eef2ff !important; }
        .flatpickr-day.today { border-color: #4f46e5 !important; color: #4f46e5 !important; }

        .pdf-btn {
            transition: all 0.2s;
        }

        .pdf-btn:hover {
            transform: scale(1.05);
        }
    </style>
</head>

<body class="bg-gray-50 flex h-screen overflow-hidden">

    <?php $page = 'reports'; include 'sidebar.php'; ?>

    <main class="flex-1 flex flex-col overflow-hidden relative">
        <!-- Header -->
        <header class="bg-white shadow-sm px-6 py-4 sticky top-0 z-20 border-b border-gray-100 flex-shrink-0">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <a href="dashboard.php" class="text-gray-400 hover:text-sky-600 transition">
                    <i class="fas fa-arrow-left text-xl"></i>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-800">
                        <i class="fas fa-chart-line mr-2 text-sky-500"></i>รายงานสรุปภาพรวม
                    </h1>
                    <p class="text-sm text-gray-500 mt-1">
                        <i class="fas fa-info-circle mr-1"></i>สถิติและรายงานการเบิกวัสดุทั้งหมด
                    </p>
                </div>
            </div>
            <!-- Year Selector -->
            <div class="flex items-center gap-3">
                <a href="print_reports_setup.php"
                    class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-bold shadow-md transition-all flex items-center gap-2">
                    <i class="fas fa-print"></i> พิมพ์รายงาน
                </a>
                <div class="h-8 w-px bg-gray-300 mx-1"></div>
                <label class="text-sm font-semibold text-gray-600">เลือกปี:</label>
                <select id="yearSelector"
                    class="border-2 border-gray-200 rounded-lg px-4 py-2 focus:ring-2 focus:ring-sky-400 focus:border-sky-400 outline-none transition-all">
                    <?php foreach ($availableYears as $y): ?>
                        <option value="<?php echo $y; ?>" <?php echo ($y == $year) ? 'selected' : ''; ?>>
                            <?php echo $y + 543; ?> (<?php echo $y; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </header>

    <div class="flex-1 overflow-y-auto p-6 w-full space-y-6">

        <!-- Instructions Banner -->
        <div class="instruction-banner rounded-2xl p-6 text-white shadow-lg animate-fade-in">
            <div class="flex items-start gap-4">
                <div class="w-12 h-12 bg-white bg-opacity-20 rounded-xl flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-lightbulb text-2xl"></i>
                </div>
                <div class="flex-1">
                    <h3 class="font-bold text-lg mb-2"><i class="fas fa-book-reader mr-2"></i>คำแนะนำการใช้งาน</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                        <div class="flex items-start gap-2">
                            <i class="fas fa-check-circle mt-1"></i>
                            <span><strong>สถิติภาพรวม:</strong> แสดงจำนวนคำขอทั้งหมด รออนุมัติ อนุมัติแล้ว
                                และไม่อนุมัติ</span>
                        </div>
                        <div class="flex items-start gap-2">
                            <i class="fas fa-check-circle mt-1"></i>
                            <span><strong>กราฟรายเดือน:</strong> แสดงแนวโน้มการเบิกวัสดุแต่ละเดือนของปีที่เลือก</span>
                        </div>
                        <div class="flex items-start gap-2">
                            <i class="fas fa-check-circle mt-1"></i>
                            <span><strong>วัสดุยอดนิยม:</strong> รายการวัสดุที่ถูกเบิกมากที่สุด 10 อันดับแรก</span>
                        </div>
                        <div class="flex items-start gap-2">
                            <i class="fas fa-check-circle mt-1"></i>
                            <span><strong>ปุ่ม PDF:</strong> คลิกปุ่ม <i class="fas fa-file-pdf"></i>
                                เพื่อพิมพ์รายงานรายงานแต่ละส่วนเป็นไฟล์ PDF</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <!-- Total Card -->
            <div
                class="stat-card bg-gradient-to-br from-blue-500 to-blue-600 p-6 rounded-2xl shadow-lg border border-blue-400 text-white">
                <div class="flex justify-between items-start mb-4">
                    <div>
                        <p class="text-blue-100 text-sm font-medium">คำขอทั้งหมด</p>
                        <h3 class="text-4xl font-bold mt-2"><?php echo number_format($stats['total']); ?></h3>
                        <p class="text-blue-100 text-xs mt-1">รายการ</p>
                    </div>
                    <div class="w-14 h-14 bg-white bg-opacity-20 rounded-xl flex items-center justify-center">
                        <i class="fas fa-file-alt text-2xl"></i>
                    </div>
                </div>
                <div class="flex items-center justify-between pt-3 border-t border-blue-400">
                    <span class="text-xs text-blue-100">รวมทุกสถานะ</span>
                    <i class="fas fa-arrow-up text-sm"></i>
                </div>
            </div>

            <!-- Pending Card -->
            <div
                class="stat-card bg-gradient-to-br from-amber-500 to-amber-600 p-6 rounded-2xl shadow-lg border border-amber-400 text-white">
                <div class="flex justify-between items-start mb-4">
                    <div>
                        <p class="text-amber-100 text-sm font-medium">รออนุมัติ</p>
                        <h3 class="text-4xl font-bold mt-2"><?php echo number_format($stats['pending']); ?></h3>
                        <p class="text-amber-100 text-xs mt-1">รายการ</p>
                    </div>
                    <div class="w-14 h-14 bg-white bg-opacity-20 rounded-xl flex items-center justify-center">
                        <i class="fas fa-clock text-2xl"></i>
                    </div>
                </div>
                <div class="flex items-center justify-between pt-3 border-t border-amber-400">
                    <span class="text-xs text-amber-100">รอการพิจารณา</span>
                    <i class="fas fa-hourglass-half text-sm"></i>
                </div>
            </div>

            <!-- Approved Card -->
            <div
                class="stat-card bg-gradient-to-br from-green-500 to-green-600 p-6 rounded-2xl shadow-lg border border-green-400 text-white">
                <div class="flex justify-between items-start mb-4">
                    <div>
                        <p class="text-green-100 text-sm font-medium">อนุมัติแล้ว</p>
                        <h3 class="text-4xl font-bold mt-2"><?php echo number_format($stats['approved']); ?></h3>
                        <p class="text-green-100 text-xs mt-1">รายการ</p>
                    </div>
                    <div class="w-14 h-14 bg-white bg-opacity-20 rounded-xl flex items-center justify-center">
                        <i class="fas fa-check-circle text-2xl"></i>
                    </div>
                </div>
                <div class="flex items-center justify-between pt-3 border-t border-green-400">
                    <span class="text-xs text-green-100">ผ่านการอนุมัติ</span>
                    <i class="fas fa-check-double text-sm"></i>
                </div>
            </div>

            <!-- Rejected Card -->
            <div
                class="stat-card bg-gradient-to-br from-red-500 to-red-600 p-6 rounded-2xl shadow-lg border border-red-400 text-white">
                <div class="flex justify-between items-start mb-4">
                    <div>
                        <p class="text-red-100 text-sm font-medium">ไม่อนุมัติ/ยกเลิก</p>
                        <h3 class="text-4xl font-bold mt-2"><?php echo number_format($stats['rejected']); ?></h3>
                        <p class="text-red-100 text-xs mt-1">รายการ</p>
                    </div>
                    <div class="w-14 h-14 bg-white bg-opacity-20 rounded-xl flex items-center justify-center">
                        <i class="fas fa-times-circle text-2xl"></i>
                    </div>
                </div>
                <div class="flex items-center justify-between pt-3 border-t border-red-400">
                    <span class="text-xs text-red-100">ปฏิเสธ/ยกเลิก</span>
                    <i class="fas fa-ban text-sm"></i>
                </div>
            </div>
        </div>

        <!-- Charts & Top Items -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Monthly Chart -->
            <div class="bg-white p-6 rounded-2xl shadow-lg border border-gray-100 lg:col-span-2 animate-fade-in">
                <div class="flex justify-between items-center mb-4">
                    <div>
                        <h3 class="font-bold text-gray-800 text-lg flex items-center gap-2">
                            <i class="fas fa-chart-bar text-sky-500"></i>
                            สถิติการเบิกรายเดือน (ปี <?php echo $year + 543; ?>)
                        </h3>
                        <p class="text-sm text-gray-500 mt-1">แสดงจำนวนครั้งที่มีการเบิกวัสดุแต่ละเดือน</p>
                    </div>
                    <button onclick="exportPDF('monthly')"
                        class="pdf-btn bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-bold shadow-md flex items-center gap-2">
                        <i class="fas fa-file-pdf"></i> พิมพ์รายงาน PDF
                    </button>
                </div>
                <div class="relative h-64 w-full">
                    <canvas id="monthlyChart"></canvas>
                </div>
            </div>

            <!-- Top Items -->
            <div class="bg-white p-6 rounded-2xl shadow-lg border border-gray-100 animate-fade-in">
                <div class="flex justify-between items-center mb-4">
                    <div>
                        <h3 class="font-bold text-gray-800 text-lg flex items-center gap-2">
                            <i class="fas fa-trophy text-amber-500"></i>
                            Top 10 วัสดุเบิกสูงสุด
                        </h3>
                        <p class="text-sm text-gray-500 mt-1">วัสดุที่ถูกเบิกมากที่สุด</p>
                    </div>
                    <button onclick="exportPDF('top')"
                        class="pdf-btn bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-bold shadow-md">
                        <i class="fas fa-file-pdf"></i> พิมพ์รายงาน PDF
                    </button>
                </div>
                <div class="overflow-y-auto max-h-64 pr-2 space-y-3">
                    <?php if (empty($topItems)): ?>
                        <div class="text-center py-8">
                            <i class="fas fa-inbox text-4xl text-gray-300 mb-2"></i>
                            <p class="text-gray-400">ไม่มีข้อมูล</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($topItems as $index => $item): ?>
                            <div
                                class="flex justify-between items-center p-3 bg-gradient-to-r from-gray-50 to-white rounded-lg hover:shadow-md transition-all border border-gray-100">
                                <div class="flex items-center gap-3">
                                    <span
                                        class="w-8 h-8 bg-gradient-to-br from-sky-400 to-sky-600 text-white rounded-full flex items-center justify-center text-sm font-bold shadow-md">
                                        <?php echo $index + 1; ?>
                                    </span>
                                    <div>
                                        <p class="text-sm font-bold text-gray-800 line-clamp-1">
                                            <?php echo htmlspecialchars($item['material_name']); ?>
                                        </p>
                                        <p class="text-xs text-gray-400"><?php echo htmlspecialchars($item['material_code']); ?>
                                        </p>
                                    </div>
                                </div>
                                <span class="font-bold text-sky-600 text-sm whitespace-nowrap">
                                    <?php echo number_format($item['total_qty']); ?>
                                    <?php echo htmlspecialchars($item['unit']); ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Section Statistics -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Section Chart -->
            <div class="bg-white p-6 rounded-2xl shadow-lg border border-gray-100 animate-fade-in">
                <div class="flex justify-between items-center mb-4">
                    <div>
                        <h3 class="font-bold text-gray-800 text-lg flex items-center gap-2">
                            <i class="fas fa-chart-pie text-sky-500"></i>
                            การเบิกวัสดุแยกตามส่วนงาน
                        </h3>
                        <p class="text-sm text-gray-500 mt-1">สัดส่วนการเบิกของแต่ละส่วนงาน</p>
                    </div>
                    <button onclick="exportPDF('section')"
                        class="pdf-btn bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-bold shadow-md flex items-center gap-2">
                        <i class="fas fa-file-pdf"></i> พิมพ์รายงาน PDF
                    </button>
                </div>
                <div class="relative h-64 w-full">
                    <canvas id="sectionChart"></canvas>
                </div>
            </div>

            <!-- Section Stats Table -->
            <div class="bg-white p-6 rounded-2xl shadow-lg border border-gray-100 animate-fade-in">
                <div class="flex justify-between items-center mb-4">
                    <div>
                        <h3 class="font-bold text-gray-800 text-lg flex items-center gap-2">
                            <i class="fas fa-list-alt text-sky-500"></i>
                            สถิติการเบิกตามส่วนงาน
                        </h3>
                        <p class="text-sm text-gray-500 mt-1">รายละเอียดจำนวนครั้งและปริมาณ</p>
                    </div>
                </div>
                <div class="overflow-y-auto max-h-64 pr-2 space-y-2">
                    <?php if (empty($sectionStats)): ?>
                        <div class="text-center py-8">
                            <i class="fas fa-inbox text-4xl text-gray-300 mb-2"></i>
                            <p class="text-gray-400">ไม่มีข้อมูล</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($sectionStats as $stat): ?>
                            <div
                                class="flex justify-between items-center p-3 bg-gradient-to-r from-sky-50 to-white rounded-lg hover:shadow-md transition-all border border-sky-100">
                                <div>
                                    <p class="text-sm font-bold text-gray-800"><?php echo htmlspecialchars($stat['section']); ?>
                                    </p>
                                    <p class="text-xs text-gray-500">
                                        <i class="fas fa-sync-alt mr-1"></i><?php echo number_format($stat['request_count']); ?>
                                        ครั้ง
                                    </p>
                                </div>
                                <span class="font-bold text-sky-600 text-sm">
                                    <?php echo number_format($stat['total_qty']); ?> รายการ
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Material Breakdown by Section -->
        <div class="bg-white p-6 rounded-2xl shadow-lg border border-gray-100 animate-fade-in">
            <div class="flex justify-between items-center mb-4">
                <div>
                    <h3 class="font-bold text-gray-800 text-lg flex items-center gap-2">
                        <i class="fas fa-th-list text-sky-500"></i>
                        รายละเอียดการเบิกวัสดุแยกตามส่วนงาน
                    </h3>
                    <p class="text-sm text-gray-500 mt-1">แสดงว่าแต่ละส่วนงานเบิกวัสดุอะไรบ้างและเบิกเท่าไหร่</p>
                </div>
                <button onclick="exportPDF('material')"
                    class="pdf-btn bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-bold shadow-md flex items-center gap-2">
                    <i class="fas fa-file-pdf"></i> พิมพ์รายงาน PDF
                </button>
            </div>
            <table id="materialBySection" class="w-full text-left stripe hover nowrap" style="width:100%">
                <thead>
                    <tr class="bg-sky-50 text-sky-800">
                        <th class="py-3 px-4">ส่วนงาน</th>
                        <th class="py-3 px-4">รหัสวัสดุ</th>
                        <th class="py-3 px-4">ชื่อวัสดุ</th>
                        <th class="py-3 px-4 text-center">จำนวนที่เบิก</th>
                        <th class="py-3 px-4 text-center">หน่วย</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Group by section for display
                    $groupedData = [];
                    foreach ($materialBySection as $item) {
                        $groupedData[$item['section']][] = $item;
                    }

                    foreach ($groupedData as $section => $materials):
                        foreach ($materials as $index => $material):
                            ?>
                            <tr class="dt-row border-b border-gray-50">
                                <td class="px-4 py-3 font-bold text-gray-700 bg-gray-50">
                                    <?php echo htmlspecialchars($section); ?>
                                </td>
                                <td class="px-4 py-3 text-gray-500 font-mono text-sm">
                                    <?php echo htmlspecialchars($material['material_code']); ?>
                                </td>
                                <td class="px-4 py-3 text-gray-800"><?php echo htmlspecialchars($material['material_name']); ?>
                                </td>
                                <td class="px-4 py-3 text-center font-bold text-sky-600">
                                    <?php echo number_format($material['total_qty']); ?>
                                </td>
                                <td class="px-4 py-3 text-center text-gray-500 text-sm">
                                    <?php echo htmlspecialchars($material['unit']); ?>
                                </td>
                            </tr>
                            <?php
                        endforeach;
                    endforeach;
                    ?>
                </tbody>
            </table>
        </div>

        <!-- Low Stock Materials -->
        <div class="bg-white p-6 rounded-2xl shadow-lg border border-gray-100 animate-fade-in">
            <div class="flex justify-between items-center mb-4">
                <div>
                    <h3 class="font-bold text-gray-800 text-lg flex items-center gap-2">
                        <i class="fas fa-exclamation-triangle text-red-500"></i>
                        รายงานวัสดุคงเหลือน้อย (Low Stock)
                    </h3>
                    <p class="text-sm text-gray-500 mt-1">รายการวัสดุที่มีจำนวนคงเหลือน้อยกว่าหรือเท่ากับจุดสั่งซื้อ
                        (Min Stock)</p>
                </div>
                <button onclick="exportPDF('inventory_low')"
                    class="pdf-btn bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-bold shadow-md flex items-center gap-2">
                    <i class="fas fa-file-pdf"></i> พิมพ์รายงาน PDF
                </button>
            </div>
            <table id="lowStockTable" class="w-full text-left stripe hover nowrap" style="width:100%">
                <thead>
                    <tr class="bg-red-50 text-red-800">
                        <th class="py-3 px-4">รหัสวัสดุ</th>
                        <th class="py-3 px-4">ชื่อวัสดุ</th>
                        <th class="py-3 px-4">หมวดหมู่</th>
                        <th class="py-3 px-4 text-center">คงเหลือ</th>
                        <th class="py-3 px-4 text-center">Min Stock/หน่วยนับ</th>
                        <th class="py-3 px-4 text-center">ราคา/หน่วย</th>
                        <th class="py-3 px-4 text-center">มูลค่าคงเหลือรวม (บาท)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $totalLowStockValue = 0;
                    foreach ($lowStockItems as $item):
                        $itemValue = $item['qty'] * $item['price'];
                        $totalLowStockValue += $itemValue;
                    ?>
                        <tr class="dt-row border-b border-gray-50">
                            <td class="px-4 py-3 text-gray-500 font-mono text-sm">
                                <?php echo htmlspecialchars($item['code']); ?>
                            </td>
                            <td class="px-4 py-3 font-bold text-gray-800"><?php echo htmlspecialchars($item['name']); ?>
                            </td>
                            <td class="px-4 py-3 text-gray-600 text-sm"><?php echo htmlspecialchars($item['category']); ?>
                            </td>
                            <td class="px-4 py-3 text-center font-bold text-red-600">
                                <?php echo number_format($item['qty']); ?>
                            </td>
                            <td class="px-4 py-3 text-center text-gray-500">
                                <?php echo number_format($item['min_stock']); ?> <?php echo htmlspecialchars($item['unit']); ?>
                            </td>
                            <td class="px-4 py-3 text-center text-gray-600">
                                <?php echo number_format($item['price'], 2); ?> บาท
                            </td>
                            <td class="px-4 py-3 text-center font-bold text-blue-600">
                                <?php echo number_format($itemValue, 2); ?> บาท
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="bg-red-50">
                        <td colspan="6" class="px-4 py-3 text-right font-bold text-gray-700">มูลค่าคงเหลือรวมทั้งหมด:</td>
                        <td class="px-4 py-3 text-center font-bold text-red-600">
                            <?php echo number_format($totalLowStockValue, 2); ?> บาท
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Recent Requests -->
        <div class="bg-white p-6 rounded-2xl shadow-lg border border-gray-100 animate-fade-in">
            <div class="flex justify-between items-center mb-4">
                <div>
                    <h3 class="font-bold text-gray-800 text-lg flex items-center gap-2">
                        <i class="fas fa-history text-sky-500"></i>
                        รายการเบิกล่าสุด
                    </h3>
                    <p class="text-sm text-gray-500 mt-1">20 รายการล่าสุดที่มีการเบิกวัสดุ</p>
                </div>
                <button onclick="exportPDF('recent')"
                    class="pdf-btn bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-bold shadow-md flex items-center gap-2">
                    <i class="fas fa-file-pdf"></i> พิมพ์รายงาน PDF
                </button>
            </div>
            <table id="reportTable" class="w-full text-left stripe hover nowrap" style="width:100%">
                <thead>
                    <tr class="bg-gray-50 text-gray-700">
                        <th class="py-3 px-4">วันที่</th>
                        <th class="py-3 px-4">เลขที่ใบเบิก</th>
                        <th class="py-3 px-4">ผู้ขอเบิก</th>
                        <th class="py-3 px-4">ส่วนงาน</th>
                        <th class="py-3 px-4 text-center">สถานะ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentRequests as $r): ?>
                        <tr class="dt-row border-b border-gray-50">
                            <td class="px-4 py-3 text-gray-600">
                                <?php echo date('d/m/Y H:i', strtotime($r['request_date'])); ?>
                            </td>
                            <td class="px-4 py-3 font-bold text-sky-600"><?php echo htmlspecialchars($r['request_name']); ?>
                            </td>
                            <td class="px-4 py-3"><?php echo htmlspecialchars($r['fullname']); ?></td>
                            <td class="px-4 py-3"><span
                                    class="bg-gray-100 text-gray-600 px-2 py-1 rounded text-xs"><?php echo htmlspecialchars($r['section']); ?></span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span
                                    class="bg-gray-100 text-gray-600 px-2 py-1 rounded-full text-xs font-bold border border-gray-200">
                                    <?php echo htmlspecialchars($r['status']); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Recent Procurements -->
        <div class="bg-white p-6 rounded-2xl shadow-lg border border-gray-100 animate-fade-in">
            <div class="flex justify-between items-center mb-4">
                <div>
                    <h3 class="font-bold text-gray-800 text-lg flex items-center gap-2">
                        <i class="fas fa-truck-loading text-green-500"></i>
                        ประวัติการนำเข้าวัสดุล่าสุด
                    </h3>
                    <p class="text-sm text-gray-500 mt-1">20 รายการล่าสุดที่มีการนำเข้าวัสดุสู่ระบบ</p>
                </div>
                <button onclick="exportPDF('import_history')"
                    class="pdf-btn bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-bold shadow-md flex items-center gap-2">
                    <i class="fas fa-file-pdf"></i> พิมพ์รายงาน PDF
                </button>
            </div>
            <table id="procurementTable" class="w-full text-left stripe hover nowrap" style="width:100%">
                <thead>
                    <tr class="bg-gray-50 text-gray-700">
                        <th class="py-3 px-4">วันที่นำเข้า</th>
                        <th class="py-3 px-4">เลขที่เอกสาร</th>
                        <th class="py-3 px-4">ร้านค้า/แหล่งที่มา</th>
                        <th class="py-3 px-4">ผู้บันทึก</th>
                        <th class="py-3 px-4 text-center">รายการวัสดุ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentImports as $imp): ?>
                        <tr class="dt-row border-b border-gray-50">
                            <td class="px-4 py-3 text-gray-600">
                                <?php echo date('d/m/Y H:i', strtotime($imp['import_date'])); ?>
                            </td>
                            <td class="px-4 py-3 font-bold text-green-600">
                                <?php echo htmlspecialchars($imp['receipt_no']); ?>
                            </td>
                            <td class="px-4 py-3 text-sm"><?php echo htmlspecialchars($imp['supplier_name']); ?></td>
                            <td class="px-4 py-3 text-sm"><?php echo htmlspecialchars($imp['fullname'] ?: 'ไม่ระบุ'); ?>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <?php
                                // Fetch items for this procurement
                                $stmtImpItems = $pdo->prepare("SELECT pi.qty, s.name, s.unit FROM procurement_items pi JOIN supplies s ON pi.supply_id = s.id WHERE pi.procurement_id = ?");
                                $stmtImpItems->execute([$imp['id']]);
                                $impItems = $stmtImpItems->fetchAll();
                                ?>
                                <div class="flex justify-center gap-2">
                                    <button onclick='Swal.fire({
                                        title: "รายการวัสดุ",
                                        html: `<div class="text-left"><ul class="list-disc pl-5 mt-2 space-y-1"><?php foreach ($impItems as $i)
                                            echo "<li>" . htmlspecialchars($i['name']) . " <span class=\"font-bold text-sky-600\">+" . $i['qty'] . " " . $i['unit'] . "</span></li>"; ?></ul></div>`,
                                        confirmButtonColor: "#0ea5e9"
                                    })'
                                        class="bg-sky-50 text-sky-600 hover:bg-sky-100 px-3 py-1 rounded text-xs transition duration-200 shadow-sm border border-sky-200">
                                        <i class="fas fa-search-plus mr-1"></i>ดูรายการ
                                    </button>
                                    <button onclick="exportPDF('import_single', <?php echo $imp['id']; ?>)"
                                        class="bg-red-50 text-red-600 hover:bg-red-100 px-3 py-1 rounded text-xs transition duration-200 shadow-sm border border-red-200"
                                        title="พิมพ์รายงาน PDF">
                                        <i class="fas fa-file-pdf mr-1"></i> พิมพ์
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- ══════════════════════════════════════════════════════════════
             Individual Requisition Items
        ═══════════════════════════════════════════════════════════════ -->
        <div class="bg-white p-6 rounded-2xl shadow-lg border border-gray-100 animate-fade-in">
            <div class="flex flex-wrap justify-between items-center mb-4 gap-3">
                <div>
                    <h3 class="font-bold text-gray-800 text-lg flex items-center gap-2">
                        <i class="fas fa-user-clock text-indigo-500"></i>
                        รายการเบิกวัสดุรายบุคคล
                    </h3>
                    <p class="text-sm text-gray-500 mt-1">เลือกผู้ใช้และช่วงเวลาที่ต้องการดูรายการเบิก</p>
                </div>
                <button id="btn-print-indiv"
                    class="pdf-btn bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-bold shadow-md flex items-center gap-2 disabled:opacity-40 disabled:cursor-not-allowed"
                    onclick="printIndivReport()" disabled>
                    <i class="fas fa-file-pdf"></i> พิมพ์รายงาน PDF
                </button>
            </div>

            <!-- Filters -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-5 p-4 bg-gray-50 rounded-xl border border-gray-200">
                <div>
                    <label class="block text-sm font-semibold text-gray-600 mb-1">ผู้ขอเบิก</label>
                    <select id="indiv-user">
                        <option value="0">— ทุกคน —</option>
                        <?php foreach ($userList as $u): ?>
                            <option value="<?php echo $u['id']; ?>">
                                <?php echo htmlspecialchars($u['fullname']); ?>
                                (<?php echo htmlspecialchars($u['section']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-600 mb-1">วันที่เริ่มต้น</label>
                    <!-- visible B.E. input -->
                    <input type="text" id="indiv-start-display" placeholder="ว/ด/ปปปป (พ.ศ.)" readonly
                        class="flatpickr-input cursor-pointer">
                    <!-- hidden Gregorian value read by JS -->
                    <input type="hidden" id="indiv-start" value="<?php echo date('Y-m-01'); ?>">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-600 mb-1">วันที่สิ้นสุด</label>
                    <input type="text" id="indiv-end-display" placeholder="ว/ด/ปปปป (พ.ศ.)" readonly
                        class="flatpickr-input cursor-pointer">
                    <input type="hidden" id="indiv-end" value="<?php echo date('Y-m-t'); ?>">
                </div>
            </div>
            <div class="flex justify-end mb-4">
                <button onclick="loadIndivData()"
                    class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2 rounded-lg font-bold text-sm shadow transition flex items-center gap-2">
                    <i class="fas fa-search"></i> ค้นหา / โหลดข้อมูล
                </button>
            </div>

            <!-- DataTable container -->
            <div id="indiv-table-wrap" class="hidden">
                <table id="indivTable" class="w-full text-left stripe hover" style="width:100%">
                    <thead>
                        <tr class="bg-indigo-50 text-indigo-800">
                            <th class="py-3 px-4">ลำดับ</th>
                            <th class="py-3 px-4">เลขที่ใบเบิก</th>
                            <th class="py-3 px-4">วันที่</th>
                            <th class="py-3 px-4">ผู้ขอเบิก</th>
                            <th class="py-3 px-4">ชื่อวัสดุที่เบิก</th>
                            <th class="py-3 px-4 text-center">จำนวน/หน่วย</th>
                            <th class="py-3 px-4 text-right">ราคา/หน่วย (บาท)</th>
                            <th class="py-3 px-4 text-right">รวม (บาท)</th>
                        </tr>
                    </thead>
                    <tbody id="indiv-tbody"></tbody>
                    <tfoot>
                        <tr class="bg-indigo-50 font-bold">
                            <td colspan="7" class="px-4 py-3 text-right text-gray-700">รวมมูลค่าทั้งหมด:</td>
                            <td class="px-4 py-3 text-right text-indigo-700 text-lg" id="indiv-grand-total">0.00 บาท</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div id="indiv-empty" class="hidden text-center py-10">
                <i class="fas fa-inbox text-4xl text-gray-300 mb-2"></i>
                <p class="text-gray-400">ไม่มีข้อมูลสำหรับเงื่อนไขที่เลือก</p>
            </div>
        </div>

    </div>

    <script>
        // Year Selector
        document.getElementById('yearSelector').addEventListener('change', function () {
            window.location.href = 'reports.php?year=' + this.value;
        });

        // PDF Export Function
        function exportPDF(type, id = null) {
            const year = <?php echo $year; ?>;
            Swal.fire({
                title: 'กำลังสร้างรายงาน PDF',
                html: 'กรุณารอสักครู่...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                    // Simulate PDF generation
                    setTimeout(() => {
                        let url = `export_pdf_report.php?type=${type}&year=${year}`;

                        // For import history, we pass the full year date range
                        if (type === 'import_history') {
                            url += `&start_date=${year}-01-01&end_date=${year}-12-31`;
                        } else if (type === 'import_single' && id !== null) {
                            url += `&id=${id}`;
                        }

                        window.open(url, '_blank');
                        Swal.close();
                    }, 1000);
                }
            });
        }

        // ── Tom Select: searchable user dropdown ──────────────────────
        new TomSelect('#indiv-user', {
            placeholder: 'พิมพ์เพื่อค้นหาผู้ขอเบิก...',
            allowEmptyOption: true,
            maxOptions: 200,
            render: {
                option: function(data, escape) {
                    return '<div style="padding:6px 10px;line-height:1.4">' + escape(data.text) + '</div>';
                },
                item: function(data, escape) {
                    return '<div>' + escape(data.text) + '</div>';
                }
            }
        });

        // ── Flatpickr Thai B.E. date pickers ───────────────────────
        const thaiMonthNames = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน',
                                'กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
        const thaiDayNames   = ['อา','จ','อ','พ','พฤ','ศ','ส'];

        function toBEDisplay(date) {
            if (!date) return '';
            const d  = String(date.getDate()).padStart(2,'0');
            const m  = String(date.getMonth()+1).padStart(2,'0');
            const y  = date.getFullYear() + 543;
            return d + '/' + m + '/' + y;
        }

        function patchCalYear(fp) {
            if (!fp.calendarContainer) return;
            const yrInput = fp.calendarContainer.querySelector('.cur-year');
            if (!yrInput) return;
            const gYear = parseInt(yrInput.value || fp.currentYear);
            if (gYear < 2500) {
                yrInput.value = gYear + 543;
            }
            // Intercept user typing in the year box
            if (!yrInput._bePatch) {
                yrInput._bePatch = true;
                yrInput.addEventListener('input', function() {
                    const typed = parseInt(this.value);
                    if (typed > 2400) fp.changeYear(typed - 543);
                });
                // Clamp displayed range
                const maxAttr = parseInt(yrInput.getAttribute('max') || '9999');
                if (maxAttr < 2500) yrInput.setAttribute('max', maxAttr + 543);
                const minAttr = parseInt(yrInput.getAttribute('min') || '1900');
                if (minAttr < 2500) yrInput.setAttribute('min', minAttr + 543);
            }
        }

        function makeBEPicker(displayId, hiddenId, defaultGreg) {
            const hiddenEl  = document.getElementById(hiddenId);
            const displayEl = document.getElementById(displayId);

            const fp = flatpickr(displayEl, {
                dateFormat: 'Y-m-d',
                defaultDate: defaultGreg,
                disableMobile: true,
                locale: {
                    weekdays: {
                        shorthand: thaiDayNames,
                        longhand:  thaiDayNames
                    },
                    months: {
                        shorthand: thaiMonthNames,
                        longhand:  thaiMonthNames
                    },
                    firstDayOfWeek: 0
                },
                onReady(dates, str, fp)       { patchCalYear(fp); if(dates[0]) displayEl.value = toBEDisplay(dates[0]); },
                onMonthChange(dates, str, fp) { patchCalYear(fp); },
                onYearChange(dates, str, fp)  { patchCalYear(fp); },
                onOpen(dates, str, fp)        { patchCalYear(fp); },
                onChange(dates, str) {
                    if (dates[0]) {
                        displayEl.value = toBEDisplay(dates[0]);
                        hiddenEl.value  = str; // Gregorian YYYY-MM-DD
                    }
                }
            });

            // Set initial B.E. display
            if (defaultGreg) {
                const d = new Date(defaultGreg);
                if (!isNaN(d)) displayEl.value = toBEDisplay(d);
            }
            return fp;
        }

        makeBEPicker('indiv-start-display', 'indiv-start', '<?php echo date('Y-m-01'); ?>');
        makeBEPicker('indiv-end-display',   'indiv-end',   '<?php echo date('Y-m-t'); ?>');

        // DataTables
        $(document).ready(function () {
            $('#reportTable').DataTable({
                responsive: true,
                order: [[0, 'desc']],
                language: {
                    search: "ค้นหา:",
                    lengthMenu: "แสดง _MENU_ รายการ",
                    info: "แสดง _START_ ถึง _END_ จาก _TOTAL_ รายการ",
                    paginate: { first: "หน้าแรก", last: "หน้าสุดท้าย", next: "ถัดไป", previous: "ก่อนหน้า" },
                    emptyTable: "ไม่มีข้อมูลรายการ"
                }
            });

            $('#materialBySection').DataTable({
                responsive: true,
                order: [[0, 'asc'], [3, 'desc']],
                language: {
                    search: "ค้นหา:",
                    lengthMenu: "แสดง _MENU_ รายการ",
                    info: "แสดง _START_ ถึง _END_ จาก _TOTAL_ รายการ",
                    paginate: { first: "หน้าแรก", last: "หน้าสุดท้าย", next: "ถัดไป", previous: "ก่อนหน้า" },
                    emptyTable: "ไม่มีข้อมูลรายการ"
                }
            });

            $('#lowStockTable').DataTable({
                responsive: true,
                order: [[3, 'asc']], // order by qty ascending
                language: {
                    search: "ค้นหา:",
                    lengthMenu: "แสดง _MENU_ รายการ",
                    info: "แสดง _START_ ถึง _END_ จาก _TOTAL_ รายการ",
                    paginate: { first: "หน้าแรก", last: "หน้าสุดท้าย", next: "ถัดไป", previous: "ก่อนหน้า" },
                    emptyTable: "ไม่มีข้อมูลวัสดุคงเหลือน้อย"
                }
            });

            $('#procurementTable').DataTable({
                responsive: true,
                order: [[0, 'desc']],
                language: {
                    search: "ค้นหา:",
                    lengthMenu: "แสดง _MENU_ รายการ",
                    info: "แสดง _START_ ถึง _END_ จาก _TOTAL_ รายการ",
                    paginate: { first: "หน้าแรก", last: "หน้าสุดท้าย", next: "ถัดไป", previous: "ก่อนหน้า" },
                    emptyTable: "ไม่มีข้อมูลการนำเข้า"
                }
            });
        });

        // Monthly Chart
        const ctx = document.getElementById('monthlyChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($monthLabels); ?>,
                datasets: [{
                    label: 'จำนวนคำขอเบิก (ครั้ง)',
                    data: <?php echo json_encode($monthValues); ?>,
                    backgroundColor: '#0ea5e9',
                    borderRadius: 8,
                    barPercentage: 0.7
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        titleFont: { size: 14 },
                        bodyFont: { size: 13 }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { display: true, borderDash: [5, 5], color: '#e5e7eb' },
                        ticks: { font: { size: 12 } }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 12 } }
                    }
                }
            }
        });

        // Section Pie Chart
        const sectionData = <?php echo json_encode(array_column($sectionStats, 'section')); ?>;
        const sectionValues = <?php echo json_encode(array_map('intval', array_column($sectionStats, 'total_qty'))); ?>;

        const sectionCtx = document.getElementById('sectionChart').getContext('2d');
        new Chart(sectionCtx, {
            type: 'doughnut',
            data: {
                labels: sectionData,
                datasets: [{
                    label: 'จำนวนรายการที่เบิก',
                    data: sectionValues,
                    backgroundColor: [
                        '#0ea5e9', '#10b981', '#f59e0b', '#ef4444',
                        '#8b5cf6', '#ec4899', '#14b8a6', '#f97316'
                    ],
                    borderWidth: 3,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            font: { size: 12 },
                            usePointStyle: true
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        callbacks: {
                            label: function (context) {
                                return context.label + ': ' + context.parsed.toLocaleString() + ' รายการ';
                            }
                        }
                    }
                }
            }
        });

        // ══════════════════════════════════════════════════════════════
        // Individual Requisition Items
        // ══════════════════════════════════════════════════════════════
        let _indivDT = null;
        let _indivData = null;

        function loadIndivData() {
            const userId    = document.getElementById('indiv-user').value;
            const startDate = document.getElementById('indiv-start').value;
            const endDate   = document.getElementById('indiv-end').value;

            if (!startDate || !endDate) {
                Swal.fire({ icon: 'warning', title: 'กรุณาเลือกช่วงวันที่', toast: true, position: 'top-end', showConfirmButton: false, timer: 2500 });
                return;
            }

            // SweetAlert2 progressbar
            let timerInterval;
            Swal.fire({
                title: 'กำลังโหลดข้อมูล...',
                html: '<div style="margin-top:8px"><div id="swal-prog-bar" style="height:8px;background:#e2e8f0;border-radius:99px;overflow:hidden"><div id="swal-prog-fill" style="height:100%;width:0%;background:#6366f1;transition:width 0.2s"></div></div><p id="swal-prog-text" style="font-size:13px;color:#6b7280;margin-top:6px">กำลังดึงข้อมูล...</p></div>',
                allowOutsideClick: false, showConfirmButton: false,
                didOpen: () => {
                    let pct = 0;
                    timerInterval = setInterval(() => {
                        pct = Math.min(pct + 8, 85);
                        const el = document.getElementById('swal-prog-fill');
                        if (el) el.style.width = pct + '%';
                    }, 200);
                }
            });

            $.ajax({
                url: 'api_individual_requisition.php',
                method: 'GET',
                data: { user_id: userId, start_date: startDate, end_date: endDate },
                success: function(res) {
                    clearInterval(timerInterval);
                    const fill = document.getElementById('swal-prog-fill');
                    if (fill) fill.style.width = '100%';

                    setTimeout(function() {
                        Swal.close();
                        try {
                            let d = (typeof res === 'string') ? JSON.parse(res) : res;
                            renderIndivTable(d);
                        } catch(e) {
                            Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาดในการโหลดข้อมูล', text: e.message });
                        }
                    }, 300);
                },
                error: function() {
                    clearInterval(timerInterval);
                    Swal.fire({ icon: 'error', title: 'ไม่สามารถโหลดข้อมูลได้', text: 'กรุณาลองใหม่อีกครั้ง' });
                }
            });
        }

        function renderIndivTable(d) {
            if (!d || !d.items) return;
            _indivData = d;

            const tbody  = document.getElementById('indiv-tbody');
            const wrap   = document.getElementById('indiv-table-wrap');
            const empty  = document.getElementById('indiv-empty');
            const gtEl   = document.getElementById('indiv-grand-total');
            const pdfBtn = document.getElementById('btn-print-indiv');

            // Destroy old DataTable if exists
            if (_indivDT) { _indivDT.destroy(); _indivDT = null; }

            if (d.items.length === 0) {
                wrap.classList.add('hidden');
                empty.classList.remove('hidden');
                pdfBtn.disabled = true;
                return;
            }

            empty.classList.add('hidden');
            wrap.classList.remove('hidden');

            let rows = '';
            d.items.forEach(function(item) {
                rows += `<tr class="dt-row border-b border-gray-50">
                    <td class="px-4 py-3 text-center text-gray-500">${item.no}</td>
                    <td class="px-4 py-3 font-mono text-sm text-indigo-600">${escH(item.req_code)}</td>
                    <td class="px-4 py-3 text-gray-600 text-sm">${fmtDate(item.req_date)}</td>
                    <td class="px-4 py-3 text-sm">${escH(item.requester)}<br><span class="text-xs text-gray-400">${escH(item.section)}</span></td>
                    <td class="px-4 py-3 font-semibold text-gray-800">${escH(item.supply_name)}<br><span class="text-xs text-gray-400 font-mono">${escH(item.supply_code)}</span></td>
                    <td class="px-4 py-3 text-center font-bold text-indigo-700">${item.qty} ${escH(item.unit)}</td>
                    <td class="px-4 py-3 text-right text-gray-700">${fmtNum(item.unit_price)}</td>
                    <td class="px-4 py-3 text-right font-bold text-indigo-700">${fmtNum(item.total_price)}</td>
                </tr>`;
            });
            tbody.innerHTML = rows;
            gtEl.textContent = fmtNum(d.grand_total) + ' บาท';
            pdfBtn.disabled = false;

            _indivDT = $('#indivTable').DataTable({
                responsive: true,
                order: [[0, 'asc']],
                pageLength: 25,
                language: {
                    search: "ค้นหา:", lengthMenu: "แสดง _MENU_ รายการ",
                    info: "แสดง _START_ ถึง _END_ จาก _TOTAL_ รายการ",
                    paginate: { first: "หน้าแรก", last: "หน้าสุดท้าย", next: "ถัดไป", previous: "ก่อนหน้า" },
                    emptyTable: "ไม่มีข้อมูลรายการ"
                }
            });
        }

        function printIndivReport() {
            if (!_indivData) return;
            const userId    = document.getElementById('indiv-user').value;
            const startDate = document.getElementById('indiv-start').value;
            const endDate   = document.getElementById('indiv-end').value;
            const userName  = document.getElementById('indiv-user').selectedOptions[0].text;
            const url = `print_individual_requisition.php?user_id=${userId}&start_date=${startDate}&end_date=${endDate}&user_name=${encodeURIComponent(userName)}`;
            window.open(url, '_blank');
        }

        function fmtNum(n) { return parseFloat(n || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
        function fmtDate(s) {
            if (!s) return '-';
            const d = new Date(s);
            return d.toLocaleDateString('th-TH', { day: '2-digit', month: '2-digit', year: 'numeric' });
        }
        function escH(s) {
            if (!s) return '';
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        }

    </script>
    </main>
</body>

</html>