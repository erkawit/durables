<?php
session_start();
require_once 'config.php';

// Check Auth
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'director', 'supply_head', 'supply_officer'])) {
    header("Location: dashboard.php");
    exit;
}

$page = 'requisition_stats';

// Fetch users
$userStmt = $pdo->query("SELECT id, prefix, firstname, lastname, section, role FROM users WHERE status = 'active' ORDER BY firstname ASC");
$users = $userStmt->fetchAll();

// Fetch years
$yearQuery = $pdo->query("SELECT DISTINCT EXTRACT(YEAR FROM request_date) AS yr FROM requests WHERE request_date IS NOT NULL ORDER BY yr DESC");
$years = array_map('intval', $yearQuery->fetchAll(PDO::FETCH_COLUMN));

// Always ensure current year is in the list
$currentYear = (int)date('Y');
if (!in_array($currentYear, $years)) {
    $years[] = $currentYear;
    rsort($years);
}

// Default user to select: current user if they are in the list, otherwise the first user
$defaultUserId = $_SESSION['user_id'];
$userExists = false;
foreach ($users as $u) {
    if ($u['id'] == $defaultUserId) {
        $userExists = true;
        break;
    }
}
if (!$userExists && !empty($users)) {
    $defaultUserId = $users[0]['id'];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สถิติการเบิกสะสมประจำปี - DURABLES</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        sky: {
                            50: '#f4f6f8', 100: '#e1e5ec', 200: '#c4ccd9',
                            300: '#9fabbe', 400: '#7587a1', 500: '#17365a',
                            600: '#112A46', 700: '#0c1e32', 800: '#081421', 900: '#040a11'
                        }
                    }
                }
            }
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="js/sweetalert2.all.min.js"></script>
    
    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>

    <style>
        body { font-family: 'Sarabun', sans-serif; }
        
        /* Select2 Customization */
        .select2-container .select2-selection--single {
            height: 42px !important;
            border-radius: 0.5rem !important;
            border: 1px solid #e2e8f0 !important;
            display: flex;
            align-items: center;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 40px !important;
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
        .dt-row {
            transition: all 0.2s;
        }
        .dt-row:hover {
            background-color: #f8fafc !important;
        }
        
        @media print {
            aside, header, .no-print, .dataTables_filter, .dataTables_length, .dataTables_paginate, .dataTables_info {
                display: none !important;
            }
            body, main {
                background: white !important;
                overflow: visible !important;
                height: auto !important;
            }
            .print-container {
                padding: 0 !important;
                box-shadow: none !important;
                border: none !important;
            }
            .print-title {
                display: block !important;
                text-align: center;
                margin-bottom: 2rem;
            }
        }
    </style>
</head>
<body class="bg-gray-50 flex h-screen overflow-hidden">

    <?php include 'sidebar.php'; ?>

    <main class="flex-1 flex flex-col overflow-hidden relative">
        <!-- Header -->
        <header class="bg-white shadow px-6 py-4 flex justify-between items-center sticky top-0 z-10 shrink-0 no-print">
            <div class="flex items-center gap-4">
                <a href="dashboard.php" class="text-gray-500 hover:text-sky-600 transition"><i class="fas fa-arrow-left text-xl"></i></a>
                <h1 class="text-xl font-bold text-gray-800">สถิติการเบิกสะสมประจำปี</h1>
            </div>
            <button onclick="window.print()" class="bg-sky-600 hover:bg-sky-700 text-white font-semibold px-4 py-2 rounded-xl transition flex items-center gap-2 text-sm shadow-sm">
                <i class="fas fa-print"></i> พิมพ์รายงาน
            </button>
        </header>

        <!-- Main Content Scroll Area -->
        <main class="flex-1 overflow-y-auto p-6 print-container">
            
            <!-- Print Header (Hidden on screen) -->
            <div class="hidden print-title font-bold text-2xl text-center pb-6 border-b mb-6 font-[Sarabun]">
                รายงานสถิติการเบิกสะสมประจำปีวัสดุสำนักงาน<br>
                <span class="text-sm font-normal text-gray-600">ศาลจังหวัดอุดรธานี</span>
            </div>

            <!-- Filter Section -->
            <div class="bg-white p-6 rounded-2xl shadow-lg border border-gray-100 mb-6 no-print">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-end">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">เลือกผู้ใช้งาน</label>
                        <select id="user-select" class="w-full">
                            <?php foreach ($users as $u): 
                                $displayName = $u['prefix'] . $u['firstname'] . ' ' . $u['lastname'] . ' (' . ($u['section'] ?: 'ไม่ระบุฝ่าย') . ')';
                                ?>
                                <option value="<?php echo $u['id']; ?>" <?php echo $u['id'] == $defaultUserId ? 'selected' : ''; ?> data-section="<?php echo htmlspecialchars($u['section']); ?>" data-role="<?php echo htmlspecialchars($u['role']); ?>">
                                    <?php echo htmlspecialchars($displayName); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">เลือกปีปฏิทิน</label>
                        <select id="year-select" class="w-full border border-gray-200 rounded-lg p-2.5 outline-none focus:ring-2 focus:ring-sky-500">
                            <?php foreach ($years as $yr): ?>
                                <option value="<?php echo $yr; ?>" <?php echo $yr == $currentYear ? 'selected' : ''; ?>>
                                    ปี พ.ศ. <?php echo $yr + 543; ?> (<?php echo $yr; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <button id="refresh-btn" class="w-full bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold py-2.5 px-4 rounded-xl transition flex items-center justify-center gap-2 border border-gray-200 shadow-sm">
                            <i class="fas fa-sync-alt"></i> รีเฟรชข้อมูล
                        </button>
                    </div>
                </div>
            </div>

            <!-- Summary Cards Section -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <!-- User Card -->
                <div class="bg-white p-6 rounded-2xl shadow-lg border border-gray-100 flex items-center gap-4">
                    <div class="w-12 h-12 bg-sky-50 rounded-xl flex items-center justify-center text-sky-600 flex-shrink-0 text-xl font-bold">
                        <i class="fas fa-user"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-400 font-bold uppercase tracking-wider">ผู้เบิกวัสดุ</p>
                        <h3 id="sum-user-name" class="text-lg font-bold text-gray-800">กำลังโหลด...</h3>
                        <p id="sum-user-section" class="text-xs text-gray-500">ฝ่าย: -</p>
                    </div>
                </div>

                <!-- Unique Items Card -->
                <div class="bg-white p-6 rounded-2xl shadow-lg border border-gray-100 flex items-center gap-4">
                    <div class="w-12 h-12 bg-emerald-50 rounded-xl flex items-center justify-center text-emerald-600 flex-shrink-0 text-xl font-bold">
                        <i class="fas fa-boxes"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-400 font-bold uppercase tracking-wider">รายการวัสดุที่เบิก</p>
                        <h3 id="sum-items-count" class="text-lg font-bold text-gray-800">0 รายการ</h3>
                        <p class="text-xs text-gray-500">สะสมในปีนี้</p>
                    </div>
                </div>

                <!-- Exceeded Warnings Card -->
                <div id="warn-card" class="bg-white p-6 rounded-2xl shadow-lg border border-gray-100 flex items-center gap-4 transition-colors duration-300">
                    <div id="warn-icon-wrapper" class="w-12 h-12 bg-gray-50 rounded-xl flex items-center justify-center text-gray-500 flex-shrink-0 text-xl font-bold">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-400 font-bold uppercase tracking-wider">รายการที่เกินเกณฑ์</p>
                        <h3 id="sum-exceeded-count" class="text-lg font-bold text-gray-800">0 รายการ</h3>
                        <p id="warn-subtext" class="text-xs text-gray-500">ไม่เกินปริมาณควบคุม</p>
                    </div>
                </div>
            </div>

            <!-- Main Statistics Table Card -->
            <div class="bg-white p-6 rounded-2xl shadow-lg border border-gray-100 overflow-x-auto">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-lg font-bold text-gray-800"><i class="fas fa-clipboard-list text-sky-500 mr-2"></i>รายละเอียดการเบิกสะสมประจำปี</h2>
                    <span id="print-date-text" class="hidden print:inline text-xs text-gray-400"></span>
                </div>
                <table id="stats-table" class="w-full text-left stripe hover nowrap align-middle">
                    <thead>
                        <tr class="bg-sky-50 text-sky-800">
                            <th class="py-4 px-4 rounded-l-lg w-12 text-center">ลำดับ</th>
                            <th class="py-4 px-4 w-24">รหัส</th>
                            <th class="py-4 px-4">ชื่อวัสดุ</th>
                            <th class="py-4 px-4 text-center w-24">หน่วย</th>
                            <th class="py-4 px-4 text-center w-32">เกณฑ์สะสม (ปี)</th>
                            <th class="py-4 px-4 text-center w-32">เบิกสะสมรวม</th>
                            <th class="py-4 px-4 w-48">สัดส่วนการใช้</th>
                            <th class="py-4 px-4 rounded-r-lg text-center w-32">สถานะ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Dynamically populated -->
                    </tbody>
                </table>
            </div>
        </main>
    </main>

    <script>
        $(document).ready(function () {
            // Set print date
            const today = new Date();
            const dateString = 'พิมพ์ข้อมูล ณ วันที่ ' + today.toLocaleDateString('th-TH', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' }) + ' น.';
            $('#print-date-text').text(dateString);

            // Initialize select2
            $('#user-select').select2({
                placeholder: '-- ค้นหา/เลือกผู้ใช้งาน --',
                width: '100%'
            });

            // Initialize DataTable
            const table = $('#stats-table').DataTable({
                responsive: true,
                language: {
                    search: "ค้นหา:",
                    lengthMenu: "แสดง _MENU_ รายการ",
                    info: "แสดง _START_ ถึง _END_ จาก _TOTAL_ รายการ",
                    paginate: { first: "หน้าแรก", last: "หน้าสุดท้าย", next: "ถัดไป", previous: "ก่อนหน้า" },
                    emptyTable: "ไม่มีข้อมูลการเบิกสะสมในปีนี้"
                },
                columnDefs: [
                    { className: "text-center", targets: [0, 3, 4, 5, 7] }
                ]
            });

            // Load data function
            function loadStats() {
                const userId = $('#user-select').val();
                const year = $('#year-select').val();
                
                // Update User Info Summary Cards
                const selectedOption = $('#user-select').find(':selected');
                const fullName = selectedOption.text().split('(')[0].trim();
                const section = selectedOption.data('section') || 'ไม่ระบุฝ่าย';
                const role = selectedOption.data('role') || '';
                
                $('#sum-user-name').text(fullName);
                $('#sum-user-section').text('ฝ่าย: ' + section + (role ? ' (' + role + ')' : ''));

                // Show loading SweetAlert
                Swal.fire({
                    title: 'กำลังโหลดข้อมูล...',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                $.ajax({
                    url: 'api_requisition_stats.php',
                    type: 'GET',
                    data: { user_id: userId, year: year },
                    success: function (res) {
                        Swal.close();
                        table.clear();
                        
                        if (res.status === 'success') {
                            const data = res.data;
                            let uniqueCount = data.length;
                            let exceededCount = 0;
                            
                            data.forEach((item, index) => {
                                const qty = item.total_qty;
                                const threshold = item.inspection_threshold;
                                const pct = threshold > 0 ? (qty / threshold) * 100 : 0;
                                const displayPct = Math.round(pct);
                                
                                // Progress bar style
                                let barColor = 'bg-emerald-500';
                                if (qty > threshold) {
                                    barColor = 'bg-red-500';
                                    exceededCount++;
                                } else if (pct >= 80) {
                                    barColor = 'bg-amber-500';
                                }
                                
                                const progressBar = `
                                    <div class="w-full bg-gray-200 rounded-full h-3.5 relative overflow-hidden font-[Sarabun]" title="${displayPct}% ของเกณฑ์">
                                        <div class="${barColor} h-3.5 rounded-full" style="width: ${Math.min(displayPct, 100)}%"></div>
                                        <span class="absolute inset-0 flex items-center justify-center text-[10px] font-bold text-gray-700 mix-blend-difference">${displayPct}%</span>
                                    </div>
                                `;

                                // Status Badge
                                let statusBadge = '';
                                if (qty > threshold) {
                                    statusBadge = `<span class="bg-red-100 text-red-700 border border-red-200 font-bold px-2 py-1 rounded-lg text-xs shadow-sm flex items-center justify-center gap-1 font-[Sarabun]"><i class="fas fa-exclamation-circle"></i> เกินเกณฑ์</span>`;
                                } else {
                                    statusBadge = `<span class="bg-green-100 text-green-700 border border-green-200 font-bold px-2 py-1 rounded-lg text-xs shadow-sm flex items-center justify-center gap-1 font-[Sarabun]"><i class="fas fa-check-circle"></i> ปกติ</span>`;
                                }
                                
                                const rowNode = table.row.add([
                                    index + 1,
                                    `<span class="font-mono text-gray-500">${item.code}</span>`,
                                    `<span class="font-bold text-gray-800">${item.name}</span>`,
                                    item.unit,
                                    `<span class="font-bold text-gray-600">${threshold}</span>`,
                                    `<span class="font-extrabold ${qty > threshold ? 'text-red-600' : 'text-sky-600'}">${qty}</span>`,
                                    progressBar,
                                    statusBadge
                                ]).node();
                                
                                if (qty > threshold) {
                                    $(rowNode).addClass('bg-red-50/50');
                                }
                            });
                            
                            table.draw();
                            
                            // Update Summary Cards
                            $('#sum-items-count').text(uniqueCount + ' รายการ');
                            $('#sum-exceeded-count').text(exceededCount + ' รายการ');
                            
                            if (exceededCount > 0) {
                                $('#warn-card').removeClass('bg-white border-gray-100').addClass('bg-red-50 border-red-200');
                                $('#warn-icon-wrapper').removeClass('bg-gray-50 text-gray-500').addClass('bg-red-100 text-red-600');
                                $('#warn-subtext').text('ควรตรวจสอบการเบิกจ่าย').addClass('text-red-500').removeClass('text-gray-500');
                                
                                // Warning message box if requested user exceeds
                                Swal.fire({
                                    icon: 'warning',
                                    title: 'พบรายการเกินเกณฑ์สะสม!',
                                    text: `ผู้ใช้งานรายนี้มีรายการเบิกสะสมเกินเกณฑ์ประจำปีจำนวน ${exceededCount} รายการ`,
                                    confirmButtonColor: '#ef4444'
                                });
                            } else {
                                $('#warn-card').removeClass('bg-red-50 border-red-200').addClass('bg-white border-gray-100');
                                $('#warn-icon-wrapper').removeClass('bg-red-100 text-red-600').addClass('bg-gray-50 text-gray-500');
                                $('#warn-subtext').text('ไม่เกินปริมาณควบคุม').removeClass('text-red-500').addClass('text-gray-500');
                            }
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'ผิดพลาด',
                                text: res.message || 'ไม่สามารถโหลดข้อมูลได้',
                                confirmButtonColor: '#0ea5e9'
                            });
                        }
                    },
                    error: function () {
                        Swal.fire({
                            icon: 'error',
                            title: 'ผิดพลาด',
                            text: 'เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์',
                            confirmButtonColor: '#0ea5e9'
                        });
                    }
                });
            }

            // Bind change events
            $('#user-select').on('change', loadStats);
            $('#year-select').on('change', loadStats);
            $('#refresh-btn').on('click', loadStats);

            // Initial load
            loadStats();
        });
    </script>
</body>
</html>
