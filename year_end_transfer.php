<?php
// year_end_transfer.php
session_start();
require_once 'config.php';

// Access Check: Admin, Supply Head, or Supply Officer
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'supply_head', 'supply_officer'])) {
    header("Location: dashboard.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>งานตัดโอนสิ้นปี - <?php echo htmlspecialchars($sys_settings['site_name_th']); ?></title>
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
                            500: '<?php echo $sys_settings['theme_color']; ?>',
                            600: '<?php echo $sys_settings['theme_color']; ?>',
                            700: '#0c1e32',
                            800: '#081421',
                            900: '#040a11'
                        }
                    }
                }
            }
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>

    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

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

        .tab-btn.active {
            border-bottom-color: var(--primary-color);
            color: var(--primary-color);
            font-weight: 700;
        }
    </style>
</head>
<body class="bg-gray-50 flex h-screen overflow-hidden">

    <?php $page = 'year_end'; include 'sidebar.php'; ?>

    <main class="flex-1 flex flex-col overflow-hidden relative">
        <header class="bg-white shadow-sm px-6 py-4 flex items-center justify-between sticky top-0 z-20 flex-shrink-0">
            <div class="flex items-center gap-4">
                <a href="dashboard.php" class="text-gray-400 hover:text-sky-600 transition">
                    <i class="fas fa-arrow-left text-xl"></i>
                </a>
                <h1 class="text-xl font-bold text-gray-800">งานตัดโอนสิ้นปี</h1>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-xs bg-sky-50 text-sky-600 border border-sky-100 px-3 py-1.5 rounded-full font-bold">
                    <i class="fas fa-calendar-check mr-1"></i>ประมวลผลยกยอด
                </span>
            </div>
        </header>

        <div class="flex-1 overflow-y-auto p-6 w-full space-y-6">
            
            <!-- Controls Section -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
                    <div>
                        <h2 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                            <i class="fas fa-database text-sky-600"></i> สำรองข้อมูลประจำปีและตัดโอนยกยอด
                        </h2>
                        <p class="text-xs text-gray-500 mt-1">สำรองฐานข้อมูลวัสดุคงเหลือและรายการเบิกจ่ายแยกตาราง เพื่อนำไปคำนวณและประเมินผลสำหรับงบประมาณปีถัดไป</p>
                    </div>
                    
                    <div class="flex items-center gap-4">
                        <div class="flex items-center gap-2">
                            <label class="text-sm font-bold text-gray-700 whitespace-nowrap">เลือกปี พ.ศ. :</label>
                            <select id="yearSelector" class="px-4 py-2 border rounded-xl bg-gray-50 focus:ring-2 focus:ring-sky-500 outline-none font-bold text-gray-700">
                                <option value="2026" selected>2569 (2026)</option>
                                <option value="2025">2568 (2025)</option>
                                <option value="2024">2567 (2024)</option>
                                <option value="2023">2566 (2023)</option>
                                <option value="2027">2570 (2027)</option>
                            </select>
                        </div>
                        
                        <button id="btnBackup" onclick="runBackup()" class="bg-sky-600 hover:bg-sky-700 text-white px-6 py-2.5 rounded-xl shadow-md transition font-bold text-sm flex items-center gap-2">
                            <i class="fas fa-file-export"></i> สำรองข้อมูลประจำปี
                        </button>
                    </div>
                </div>

                <!-- Backup status badge -->
                <div id="statusAlert" class="mt-5 p-4 rounded-xl border flex items-center justify-between transition-all hidden">
                    <div class="flex items-center gap-3">
                        <div id="statusIcon" class="w-10 h-10 rounded-full flex items-center justify-center"></div>
                        <div>
                            <p id="statusTitle" class="font-bold text-sm"></p>
                            <p id="statusDesc" class="text-xs mt-0.5 text-gray-500"></p>
                        </div>
                    </div>
                    <span id="statusBadge" class="text-xs font-bold px-3 py-1 rounded-full border"></span>
                </div>
            </div>

            <!-- Dashboard Analytics Wrapper -->
            <div id="reportSection" class="space-y-6 hidden">
                <div class="border-b border-gray-200/60 pb-2">
                    <h2 class="text-xl font-bold text-gray-800 flex items-center gap-2" id="reportTitle">
                        <i class="fas fa-file-signature text-sky-600"></i> รายงานประมวลผลการเบิกจ่ายวัสดุสำนักงานศาลจังหวัดอุดรธานีประจำปี
                    </h2>
                </div>

                <!-- Metric Cards -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div class="bg-white rounded-xl border border-gray-100 p-5 flex items-center gap-4 shadow-sm">
                        <div class="w-12 h-12 bg-sky-50 text-sky-600 rounded-xl flex items-center justify-center text-xl shrink-0"><i class="fas fa-list-check"></i></div>
                        <div>
                            <p class="text-xs text-gray-500 font-bold">ประเภทพัสดุที่มีการเบิก</p>
                            <p class="text-2xl font-black text-sky-700 mt-1" id="statItems">0 <span class="text-xs font-normal text-gray-400">รายการ</span></p>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-100 p-5 flex items-center gap-4 shadow-sm">
                        <div class="w-12 h-12 bg-emerald-50 text-emerald-600 rounded-xl flex items-center justify-center text-xl shrink-0"><i class="fas fa-boxes"></i></div>
                        <div>
                            <p class="text-xs text-gray-500 font-bold">จำนวนเบิกรวมทั้งหมด</p>
                            <p class="text-2xl font-black text-emerald-700 mt-1" id="statQty">0 <span class="text-xs font-normal text-gray-400">ชิ้น</span></p>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-100 p-5 flex items-center gap-4 shadow-sm">
                        <div class="w-12 h-12 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center text-xl shrink-0"><i class="fas fa-coins"></i></div>
                        <div>
                            <p class="text-xs text-gray-500 font-bold">งบประมาณรายจ่ายรวม</p>
                            <p class="text-2xl font-black text-indigo-700 mt-1" id="statExpenditure">฿0.00</p>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-100 p-5 flex items-center gap-4 shadow-sm">
                        <div class="w-12 h-12 bg-red-50 text-red-600 rounded-xl flex items-center justify-center text-xl shrink-0"><i class="fas fa-exclamation-triangle"></i></div>
                        <div>
                            <p class="text-xs text-gray-500 font-bold">การเบิกพัสดุผิดปกติ</p>
                            <p class="text-2xl font-black text-red-700 mt-1" id="statAnomalies">0 <span class="text-xs font-normal text-gray-400">ครั้ง</span></p>
                        </div>
                    </div>
                </div>

                <!-- Tabs Menu -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="flex border-b border-gray-100 bg-gray-50/50">
                        <button onclick="switchReportTab('qty')" id="tabBtn-qty" class="tab-btn active flex-1 py-4 px-6 text-center border-b-2 border-transparent text-sm font-bold text-gray-500 hover:text-sky-600 transition flex items-center justify-center gap-2">
                            <i class="fas fa-sort-amount-down"></i> เบิกจ่ายสูงสุด (ตามจำนวน)
                        </button>
                        <button onclick="switchReportTab('value')" id="tabBtn-value" class="tab-btn flex-1 py-4 px-6 text-center border-b-2 border-transparent text-sm font-bold text-gray-500 hover:text-sky-600 transition flex items-center justify-center gap-2">
                            <i class="fas fa-dollar-sign"></i> เบิกจ่ายมูลค่าสูงสุด (ตามรายจ่าย)
                        </button>
                        <button onclick="switchReportTab('anomaly')" id="tabBtn-anomaly" class="tab-btn flex-1 py-4 px-6 text-center border-b-2 border-transparent text-sm font-bold text-gray-500 hover:text-sky-600 transition flex items-center justify-center gap-2">
                            <i class="fas fa-chart-line text-red-500"></i> วิเคราะห์ปริมาณเบิกผิดปกติ
                        </button>
                    </div>

                    <div class="p-6">
                        <!-- Tab Content 1: Quantity -->
                        <div id="tabContent-qty" class="tab-content space-y-6">
                            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                                <div class="lg:col-span-1 bg-gray-50 rounded-xl p-5 border border-gray-100 flex flex-col justify-center">
                                    <h3 class="font-bold text-gray-700 mb-4 text-center"><i class="fas fa-chart-bar mr-1 text-sky-500"></i> แผนภูมิ 10 อันดับวัสดุที่มีการเบิกจ่ายสูงสุด</h3>
                                    <div class="h-64 relative">
                                        <canvas id="chartQty"></canvas>
                                    </div>
                                </div>
                                <div class="lg:col-span-2 overflow-x-auto">
                                    <table id="tblQty" class="w-full text-sm text-left align-middle" style="width:100%">
                                        <thead>
                                            <tr class="bg-sky-50 text-sky-800">
                                                <th class="py-3 px-3 rounded-l-lg text-center w-12">อันดับ</th>
                                                <th class="py-3 px-3">รหัสพัสดุ</th>
                                                <th class="py-3 px-3">ชื่อวัสดุ</th>
                                                <th class="py-3 px-3 text-center">จำนวนเบิก</th>
                                                <th class="py-3 px-3 text-center">หน่วยนับ</th>
                                                <th class="py-3 px-3 text-right">ราคา/หน่วย</th>
                                                <th class="py-3 px-3 rounded-r-lg text-right">งบรายจ่ายรวม</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Tab Content 2: Value -->
                        <div id="tabContent-value" class="tab-content space-y-6 hidden">
                            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                                <div class="lg:col-span-1 bg-gray-50 rounded-xl p-5 border border-gray-100 flex flex-col justify-center">
                                    <h3 class="font-bold text-gray-700 mb-4 text-center"><i class="fas fa-chart-pie mr-1 text-indigo-500"></i> สัดส่วนมูลค่า 5 อันดับแรกที่มีการใช้จ่ายสูงสุด</h3>
                                    <div class="h-64 relative">
                                        <canvas id="chartValue"></canvas>
                                    </div>
                                </div>
                                <div class="lg:col-span-2 overflow-x-auto">
                                    <table id="tblValue" class="w-full text-sm text-left align-middle" style="width:100%">
                                        <thead>
                                            <tr class="bg-indigo-50 text-indigo-800">
                                                <th class="py-3 px-3 rounded-l-lg text-center w-12">อันดับ</th>
                                                <th class="py-3 px-3">รหัสพัสดุ</th>
                                                <th class="py-3 px-3">ชื่อวัสดุ</th>
                                                <th class="py-3 px-3 text-center">จำนวนเบิก</th>
                                                <th class="py-3 px-3 text-center">หน่วยนับ</th>
                                                <th class="py-3 px-3 text-right">ราคา/หน่วย</th>
                                                <th class="py-3 px-3 rounded-r-lg text-right">งบรายจ่ายรวม</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Tab Content 3: Anomaly -->
                        <div id="tabContent-anomaly" class="tab-content space-y-6 hidden">
                            <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-r-xl">
                                <div class="flex gap-3">
                                    <i class="fas fa-circle-exclamation text-red-500 text-lg mt-0.5"></i>
                                    <div>
                                        <h4 class="font-bold text-red-800">เกณฑ์วิเคราะห์พฤติกรรมการเบิกพัสดุที่ผิดปกติ</h4>
                                        <p class="text-xs text-red-700 mt-1 leading-relaxed">ตรวจจับและแจ้งเตือนพฤติกรรมการเบิกพัสดุใดๆ ในแต่ละครั้งที่มีจำนวนเบิก<strong>มากกว่า 3 เท่าของค่าเฉลี่ย</strong>การเบิกจ่ายวัสดุชนิดนั้นๆ (โดยมีขั้นต่ำตั้งแต่ 5 ชิ้นขึ้นไป) เพื่อวิเคราะห์ความเหมาะสมในการเบิกและควบคุมงบประมาณแผ่นดิน</p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="overflow-x-auto">
                                <table id="tblAnomaly" class="w-full text-sm text-left align-middle" style="width:100%">
                                    <thead>
                                        <tr class="bg-red-50 text-red-800">
                                            <th class="py-3 px-3 rounded-l-lg">รหัสใบเบิก</th>
                                            <th class="py-3 px-3">วันที่เบิก</th>
                                            <th class="py-3 px-3">ผู้เบิกพัสดุ</th>
                                            <th class="py-3 px-3">ส่วนงาน</th>
                                            <th class="py-3 px-3">พัสดุ</th>
                                            <th class="py-3 px-3 text-center">จำนวนเบิก</th>
                                            <th class="py-3 px-3 text-center">เฉลี่ยต่อครั้ง</th>
                                            <th class="py-3 px-3 text-center">ความผิดปกติ</th>
                                            <th class="py-3 px-3 rounded-r-lg text-right">มูลค่ารวม</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
        </div>
    </main>

    <script>
        let dtQty = null;
        let dtValue = null;
        let dtAnomaly = null;
        let chartQtyInstance = null;
        let chartValueInstance = null;

        $(document).ready(function() {
            // Check status for initial year
            checkBackupStatus();

            $('#yearSelector').on('change', function() {
                checkBackupStatus();
            });
        });

        function checkBackupStatus() {
            const year = $('#yearSelector').val();
            
            $.getJSON('api_year_end.php?action=check_status&year=' + year, function(res) {
                const alertBox = $('#statusAlert');
                const icon = $('#statusIcon');
                const title = $('#statusTitle');
                const desc = $('#statusDesc');
                const badge = $('#statusBadge');
                
                alertBox.removeClass('hidden');

                if (res.exists) {
                    // Backed up
                    alertBox.removeClass('bg-red-50 border-red-200 text-red-800 bg-gray-50 border-gray-200').addClass('bg-green-50 border-green-200 text-green-800');
                    icon.removeClass('bg-red-100 text-red-600 bg-gray-100 text-gray-600').addClass('bg-green-100 text-green-600').html('<i class="fas fa-check-double"></i>');
                    title.text('สำรองข้อมูลเรียบร้อยแล้ว');
                    desc.text('พัสดุที่ทำการบันทึกตัดโอนไว้จำนวน ' + res.count.toLocaleString() + ' รายการ พร้อมสำหรับการสร้างรายงานวิเคราะห์');
                    badge.removeClass('bg-red-100 text-red-700 bg-gray-100 text-gray-700').addClass('bg-green-100 text-green-700 border-green-200').text('สำรองแล้ว');
                    $('#btnBackup').html('<i class="fas fa-redo"></i> เขียนทับข้อมูลสำรอง');
                    
                    // Show report
                    loadReports(year);
                } else {
                    // Not backed up
                    alertBox.removeClass('bg-green-50 border-green-200 text-green-800 bg-gray-50 border-gray-200').addClass('bg-red-50 border-red-200 text-red-800');
                    icon.removeClass('bg-green-100 text-green-600 bg-gray-100 text-gray-600').addClass('bg-red-100 text-red-600').html('<i class="fas fa-exclamation-triangle"></i>');
                    title.text('ยังไม่ได้สำรองข้อมูลตัดโอน');
                    desc.text('กรุณากดปุ่ม "สำรองข้อมูลประจำปี" เพื่อสำรองตารางข้อมูลและเรียกดูสถิติการเบิกจ่ายวัสดุ');
                    badge.removeClass('bg-green-100 text-green-700 bg-gray-100 text-gray-700').addClass('bg-red-100 text-red-700 border-red-200').text('รอดำเนินการ');
                    $('#btnBackup').html('<i class="fas fa-file-export"></i> สำรองข้อมูลประจำปี');
                    
                    // Hide report
                    $('#reportSection').addClass('hidden');
                }
            });
        }

        function runBackup() {
            const year = $('#yearSelector').val();
            const yearBe = parseInt(year) + 543;
            
            Swal.fire({
                title: 'ยืนยันการสำรองข้อมูลตัดโอน?',
                text: 'ระบบจะคัดลอกตารางข้อมูลพัสดุ รายการเบิกจ่ายปี พ.ศ. ' + yearBe + ' แยกเก็บไว้ในตารางเฉพาะของปีนี้เพื่อนำไปประมวลผล',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'ตกลง, สำรองข้อมูล',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '<?php echo $sys_settings['theme_color']; ?>',
                cancelButtonColor: '#cbd5e1',
                customClass: {
                    popup: 'rounded-2xl',
                    confirmButton: 'rounded-xl',
                    cancelButton: 'rounded-xl text-gray-600'
                }
            }).then((res) => {
                if (res.isConfirmed) {
                    Swal.fire({
                        title: 'กำลังดำเนินการสำรองข้อมูล...',
                        text: 'กรุณารอสักครู่',
                        allowOutsideClick: false,
                        didOpen: () => { Swal.showLoading(); }
                    });
                    
                    $.post('api_year_end.php?action=backup', { year: year }, function(response) {
                        if (response.status === 'success') {
                            Swal.fire({
                                icon: 'success',
                                title: 'สำรองข้อมูลสำเร็จ!',
                                text: response.message,
                                showConfirmButton: false,
                                timer: 1000,
                                customClass: { popup: 'rounded-2xl' }
                            }).then(() => {
                                checkBackupStatus();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'เกิดข้อผิดพลาด',
                                text: response.message,
                                confirmButtonColor: '#ef4444',
                                customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-xl' }
                            });
                        }
                    }).fail(function() {
                        Swal.fire({
                            icon: 'error',
                            title: 'ผิดพลาด',
                            text: 'ไม่สามารถสำรองข้อมูลได้เนื่องจากการเชื่อมต่อขัดข้อง',
                            confirmButtonColor: '#ef4444',
                            customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-xl' }
                        });
                    });
                }
            });
        }

        function loadReports(year) {
            const yearBe = parseInt(year) + 543;
            $('#reportTitle').html('<i class="fas fa-file-signature text-sky-600"></i> รายงานประมวลผลการเบิกจ่ายวัสดุสำนักงานศาลจังหวัดอุดรธานีประจำปี พ.ศ. ' + yearBe);
            $('#reportSection').removeClass('hidden');

            $.getJSON('api_year_end.php?action=get_report&year=' + year, function(res) {
                if (res.status !== 'success') return;

                // Render metrics
                $('#statItems').html(res.summary.total_items.toLocaleString() + ' <span class="text-xs font-normal text-gray-400">รายการ</span>');
                $('#statQty').html(res.summary.total_qty.toLocaleString() + ' <span class="text-xs font-normal text-gray-400">ชิ้น</span>');
                $('#statExpenditure').text('฿' + parseFloat(res.summary.total_expenditure).toLocaleString('th-TH', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
                $('#statAnomalies').html(res.summary.total_anomalies.toLocaleString() + ' <span class="text-xs font-normal text-gray-400">ครั้ง</span>');

                // A. Quantity Tab
                renderQtyTable(res.by_qty);
                renderQtyChart(res.by_qty.slice(0, 10));

                // B. Value Tab
                renderValueTable(res.by_value);
                renderValueChart(res.by_value.slice(0, 5));

                // C. Anomaly Tab
                renderAnomalyTable(res.anomalies);
            });
        }

        function switchReportTab(tabName) {
            $('.tab-btn').removeClass('active');
            $('.tab-content').addClass('hidden');
            
            $('#tabBtn-' + tabName).addClass('active');
            $('#tabContent-' + tabName).removeClass('hidden');
        }

        function renderQtyTable(data) {
            if (dtQty) dtQty.destroy();
            
            let html = '';
            data.forEach((r, idx) => {
                html += `
                    <tr class="hover:bg-slate-50 transition border-b border-gray-50">
                        <td class="px-3 py-2.5 text-center font-bold text-gray-500">${idx + 1}</td>
                        <td class="px-3 py-2.5 font-mono text-xs text-gray-500">${r.code}</td>
                        <td class="px-3 py-2.5 font-bold text-gray-700">${r.name}</td>
                        <td class="px-3 py-2.5 text-center font-bold text-sky-600">${parseInt(r.total_qty).toLocaleString()}</td>
                        <td class="px-3 py-2.5 text-center text-gray-400 text-xs">${r.unit}</td>
                        <td class="px-3 py-2.5 text-right font-medium text-gray-400">฿${parseFloat(r.price).toFixed(2)}</td>
                        <td class="px-3 py-2.5 text-right font-bold text-gray-700">฿${parseFloat(r.total_expenditure).toLocaleString('th-TH', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                    </tr>
                `;
            });
            $('#tblQty tbody').html(html);
            dtQty = $('#tblQty').DataTable({
                responsive: true,
                destroy: true,
                pageLength: 10,
                lengthMenu: [10, 25, 50, 100],
                language: {
                    search: "ค้นหา:",
                    lengthMenu: "แสดง _MENU_ แถว",
                    info: "แสดง _START_–_END_ จาก _TOTAL_ รายการ",
                    paginate: { next: "ถัดไป", previous: "ก่อนหน้า" }
                }
            });
        }

        function renderValueTable(data) {
            if (dtValue) dtValue.destroy();
            
            let html = '';
            data.forEach((r, idx) => {
                html += `
                    <tr class="hover:bg-slate-50 transition border-b border-gray-50">
                        <td class="px-3 py-2.5 text-center font-bold text-gray-500">${idx + 1}</td>
                        <td class="px-3 py-2.5 font-mono text-xs text-gray-500">${r.code}</td>
                        <td class="px-3 py-2.5 font-bold text-gray-700">${r.name}</td>
                        <td class="px-3 py-2.5 text-center font-bold text-gray-500">${parseInt(r.total_qty).toLocaleString()}</td>
                        <td class="px-3 py-2.5 text-center text-gray-400 text-xs">${r.unit}</td>
                        <td class="px-3 py-2.5 text-right font-medium text-gray-400">฿${parseFloat(r.price).toFixed(2)}</td>
                        <td class="px-3 py-2.5 text-right font-bold text-indigo-600">฿${parseFloat(r.total_expenditure).toLocaleString('th-TH', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                    </tr>
                `;
            });
            $('#tblValue tbody').html(html);
            dtValue = $('#tblValue').DataTable({
                responsive: true,
                destroy: true,
                pageLength: 10,
                lengthMenu: [10, 25, 50, 100],
                language: {
                    search: "ค้นหา:",
                    lengthMenu: "แสดง _MENU_ แถว",
                    info: "แสดง _START_–_END_ จาก _TOTAL_ รายการ",
                    paginate: { next: "ถัดไป", previous: "ก่อนหน้า" }
                }
            });
        }

        function renderAnomalyTable(data) {
            if (dtAnomaly) dtAnomaly.destroy();
            
            let html = '';
            data.forEach(r => {
                let dp = new Date(r.request_date).toLocaleDateString('th-TH', {day:'numeric', month:'short', year:'2-digit'});
                html += `
                    <tr class="hover:bg-red-50/30 transition border-b border-gray-50">
                        <td class="px-3 py-3 font-bold text-red-600">${r.request_name}</td>
                        <td class="px-3 py-3 text-gray-400 text-xs">${dp}</td>
                        <td class="px-3 py-3 font-semibold text-gray-800">${r.requester}</td>
                        <td class="px-3 py-3 text-gray-500 text-xs">${r.section}</td>
                        <td class="px-3 py-3">
                            <div class="font-bold text-gray-700">${r.name}</div>
                            <div class="text-[10px] text-gray-400 font-mono">CODE: ${r.code}</div>
                        </td>
                        <td class="px-3 py-3 text-center font-bold text-gray-800">${r.approved_qty} ${r.unit}</td>
                        <td class="px-3 py-3 text-center text-gray-500 text-xs">${r.avg_qty} ${r.unit}</td>
                        <td class="px-3 py-3 text-center">
                            <span class="bg-red-100 text-red-700 text-[10px] font-black px-2 py-1 rounded-full border border-red-200">
                                <i class="fas fa-arrow-trend-up mr-0.5"></i> ${r.multiplier} เท่า
                            </span>
                        </td>
                        <td class="px-3 py-3 text-right font-black text-red-600">฿${parseFloat(r.total_expenditure).toLocaleString('th-TH', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                    </tr>
                `;
            });
            $('#tblAnomaly tbody').html(html);
            dtAnomaly = $('#tblAnomaly').DataTable({
                responsive: true,
                destroy: true,
                pageLength: 10,
                lengthMenu: [10, 25, 50, 100],
                language: {
                    search: "ค้นหา:",
                    lengthMenu: "แสดง _MENU_ แถว",
                    info: "แสดง _START_–_END_ จาก _TOTAL_ รายการ",
                    paginate: { next: "ถัดไป", previous: "ก่อนหน้า" }
                }
            });
        }

        function renderQtyChart(topData) {
            if (chartQtyInstance) chartQtyInstance.destroy();
            
            const labels = topData.map(r => r.name.substring(0, 15) + (r.name.length > 15 ? '...' : ''));
            const qtyValues = topData.map(r => r.total_qty);
            
            const ctx = document.getElementById('chartQty').getContext('2d');
            chartQtyInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'จำนวนเบิกรวม (ชิ้น)',
                        data: qtyValues,
                        backgroundColor: 'rgba(14, 165, 233, 0.75)',
                        borderColor: '#0ea5e9',
                        borderWidth: 2,
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: { beginAtZero: true, grid: { color: '#f1f5f9' } },
                        x: { grid: { display: false } }
                    }
                }
            });
        }

        function renderValueChart(topData) {
            if (chartValueInstance) chartValueInstance.destroy();
            
            const labels = topData.map(r => r.name.substring(0, 15) + (r.name.length > 15 ? '...' : ''));
            const costValues = topData.map(r => r.total_expenditure);
            
            const ctx = document.getElementById('chartValue').getContext('2d');
            chartValueInstance = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: costValues,
                        backgroundColor: [
                            'rgba(99, 102, 241, 0.8)',
                            'rgba(14, 165, 233, 0.8)',
                            'rgba(16, 185, 129, 0.8)',
                            'rgba(245, 158, 11, 0.8)',
                            'rgba(239, 68, 68, 0.8)'
                        ],
                        borderColor: '#ffffff',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 12, font: { family: 'Sarabun', size: 10 } } }
                    }
                }
            });
        }
    </script>
</body>
</html>
