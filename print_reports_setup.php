<?php
session_start();
require_once 'config.php';

// Access Check
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'director', 'supply_head', 'supply_officer'])) {
    header("Location: dashboard.php");
    exit;
}

$page = 'print_reports';

// Fetch supplies for dropdown
$supplies = $pdo->query("SELECT id, code, name FROM supplies WHERE status = 'active' ORDER BY name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>พิมพ์รายงาน - ระบบเบิกวัสดุ</title>
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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        body {
            font-family: 'Sarabun', sans-serif;
            background-color: #f4f6f8;
        }

        .glass-panel {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.6);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        .report-card {
            transition: all 0.2s ease;
            border: 1px solid #e0f2fe;
        }

        .report-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 15px -3px rgba(14, 165, 233, 0.1);
            border-color: #bae6fd;
        }

        .select2-container .select2-selection--single {
            height: 45px !important;
            border-radius: 0.75rem !important;
            border: 1px solid #e2e8f0 !important;
            display: flex;
            align-items: center;
            background-color: #f8fafc;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 43px !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__placeholder {
            color: #94a3b8;
        }

        .btn-print {
            background: linear-gradient(135deg, #0ea5e9, #0284c7);
            transition: all 0.3s;
        }

        .btn-print:hover {
            background: linear-gradient(135deg, #0284c7, #0369a1);
            shadow: 0 4px 12px rgba(2, 132, 199, 0.3);
        }
    </style>
</head>

<body class="min-h-screen flex flex-col">

    <!-- Navbar -->
    <header class="bg-white shadow-sm sticky top-0 z-50 border-b border-sky-100">
        <div class="w-full px-6 py-4 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <a href="reports.php"
                    class="w-10 h-10 flex items-center justify-center rounded-xl bg-slate-100 text-slate-500 hover:bg-sky-50 hover:text-sky-600 transition shadow-sm">
                    <i class="fas fa-arrow-left text-lg"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-800 tracking-tight">
                        <i class="fas fa-print mr-2 text-sky-500"></i>พิมพ์รายงาน (Print Reports)
                    </h1>
                    <p class="text-sm text-gray-500">เลือกประเภทรายงานและกำหนดเงื่อนไขที่ต้องการพิมพ์</p>
                </div>
            </div>
            <div class="hidden md:flex items-center gap-4">
                <div class="text-right">
                    <p class="text-sm font-bold text-gray-700"><?php echo $_SESSION['fullname']; ?></p>
                    <span class="text-xs px-2 py-1 bg-sky-100 text-sky-700 rounded-lg font-semibold">
                        <?php echo ucfirst($_SESSION['role']); ?>
                    </span>
                </div>
            </div>
        </div>
    </header>

    <main class="flex-1 w-full p-6">
        <div class="w-full h-full grid grid-cols-1 lg:grid-cols-12 gap-6">

            <!-- Sidebar / Menu (Optional Left Column for categorization if needed later, kept simple for now) -->

            <!-- Main Content Area -->
            <div class="lg:col-span-12 space-y-8">

                <!-- Section 1: Inventory -->
                <section class="animate-fade-in-up">
                    <div class="flex items-center gap-3 mb-4 border-b border-sky-100 pb-2">
                        <div
                            class="w-8 h-8 rounded-lg bg-sky-100 text-sky-600 flex items-center justify-center font-bold shadow-sm">
                            1</div>
                        <h2 class="text-xl font-bold text-gray-800">รายงานข้อมูลวัสดุ (Inventory)</h2>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Current Stock -->
                        <div
                            class="report-card bg-white p-6 rounded-2xl shadow-sm flex flex-col justify-between h-full">
                            <div>
                                <div class="flex justify-between items-start mb-4">
                                    <div class="p-3 bg-blue-50 text-blue-500 rounded-xl">
                                        <i class="fas fa-boxes text-2xl"></i>
                                    </div>
                                    <span
                                        class="bg-blue-100 text-blue-700 text-xs px-2 py-1 rounded-md font-bold">ยอดนิยม</span>
                                </div>
                                <h3 class="font-bold text-lg text-gray-800 mb-2">วัสดุคงคลังปัจจุบัน</h3>
                                <p class="text-sm text-gray-500 mb-6">แสดงรายการวัสดุทั้งหมด พร้อมยอดคงเหลือปัจจุบัน
                                    หน่วยนับ และสถานะ</p>
                            </div>
                            <button
                                onclick="confirmPrint('export_pdf_report.php?type=inventory_balance', 'รายงานวัสดุคงคลังปัจจุบัน')"
                                class="w-full py-3 rounded-xl text-white font-bold flex items-center justify-center gap-2 btn-print shadow-lg hover:-translate-y-0.5 transform transition">
                                <i class="fas fa-print"></i> พิมพ์รายงานนี้
                            </button>
                        </div>
                        <!-- Low Stock -->
                        <div
                            class="report-card bg-white p-6 rounded-2xl shadow-sm flex flex-col justify-between h-full">
                            <div>
                                <div class="flex justify-between items-start mb-4">
                                    <div class="p-3 bg-red-50 text-red-500 rounded-xl">
                                        <i class="fas fa-exclamation-triangle text-2xl"></i>
                                    </div>
                                    <span
                                        class="bg-red-100 text-red-700 text-xs px-2 py-1 rounded-md font-bold">สำคัญ</span>
                                </div>
                                <h3 class="font-bold text-lg text-gray-800 mb-2">วัสดุใกล้หมด/จุดสั่งซื้อ</h3>
                                <p class="text-sm text-gray-500 mb-6">
                                    แสดงรายการวัสดุที่มีจำนวนคงเหลือน้อยกว่าหรือเท่ากับจุดสั่งซื้อ (Min Stock)</p>
                            </div>
                            <button
                                onclick="confirmPrint('export_pdf_report.php?type=inventory_low', 'รายงานวัสดุใกล้หมด')"
                                class="w-full py-3 rounded-xl border-2 border-red-500 text-red-500 font-bold flex items-center justify-center gap-2 hover:bg-red-50 transition">
                                <i class="fas fa-print"></i> พิมพ์รายงานวัสดุขาดแคลน
                            </button>
                        </div>
                    </div>
                </section>

                <!-- Section 2: Withdrawals -->
                <section class="animate-fade-in-up" style="animation-delay: 0.1s;">
                    <div class="flex items-center gap-3 mb-4 border-b border-sky-100 pb-2">
                        <div
                            class="w-8 h-8 rounded-lg bg-emerald-100 text-emerald-600 flex items-center justify-center font-bold shadow-sm">
                            2</div>
                        <h2 class="text-xl font-bold text-gray-800">รายงานการเบิกจ่าย (Withdrawals)</h2>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <!-- Daily -->
                        <div class="report-card bg-white p-6 rounded-2xl shadow-sm">
                            <div class="mb-5">
                                <div
                                    class="w-12 h-12 bg-emerald-50 text-emerald-500 rounded-xl flex items-center justify-center mb-4">
                                    <i class="fas fa-calendar-day text-xl"></i>
                                </div>
                                <h3 class="font-bold text-lg text-gray-800">รายงานประจำวัน</h3>
                                <p class="text-sm text-gray-500 mt-1">สรุปรายการเบิกจ่ายรายวัน</p>
                            </div>
                            <form action="export_pdf_report.php" method="GET" target="_blank" class="print-form">
                                <input type="hidden" name="type" value="withdrawal_daily">
                                <div class="mb-4">
                                    <label
                                        class="block text-xs font-bold text-gray-500 uppercase mb-2">เลือกวันที่</label>
                                    <input type="date" name="date" value="<?php echo date('Y-m-d'); ?>"
                                        class="w-full border-gray-200 bg-gray-50 rounded-xl focus:ring-2 focus:ring-emerald-200 focus:border-emerald-400 outline-none p-3 transition">
                                </div>
                                <button type="submit"
                                    class="w-full py-3 bg-emerald-500 hover:bg-emerald-600 text-white rounded-xl font-bold shadow-md hover:shadow-lg transition flex items-center justify-center gap-2">
                                    <i class="fas fa-print"></i> พิมพ์รายงาน
                                </button>
                            </form>
                        </div>

                        <!-- Monthly -->
                        <div class="report-card bg-white p-6 rounded-2xl shadow-sm">
                            <div class="mb-5">
                                <div
                                    class="w-12 h-12 bg-teal-50 text-teal-500 rounded-xl flex items-center justify-center mb-4">
                                    <i class="fas fa-calendar-alt text-xl"></i>
                                </div>
                                <h3 class="font-bold text-lg text-gray-800">รายงานประจำเดือน</h3>
                                <p class="text-sm text-gray-500 mt-1">สรุปยอดรวมและการเบิกรายเดือน</p>
                            </div>
                            <form action="export_pdf_report.php" method="GET" target="_blank" class="print-form">
                                <input type="hidden" name="type" value="withdrawal_monthly">
                                <div class="grid grid-cols-2 gap-3 mb-4">
                                    <div>
                                        <label
                                            class="block text-xs font-bold text-gray-500 uppercase mb-2">เดือน</label>
                                        <select name="month"
                                            class="w-full border-gray-200 bg-gray-50 rounded-xl p-3 outline-none focus:ring-2 focus:ring-teal-200">
                                            <?php
                                            $months = ['01' => 'มกราคม', '02' => 'กุมภาพันธ์', '03' => 'มีนาคม', '04' => 'เมษายน', '05' => 'พฤษภาคม', '06' => 'มิถุนายน', '07' => 'กรกฎาคม', '08' => 'สิงหาคม', '09' => 'กันยายน', '10' => 'ตุลาคม', '11' => 'พฤศจิกายน', '12' => 'ธันวาคม'];
                                            $currM = date('m');
                                            foreach ($months as $k => $v) {
                                                echo "<option value='$k' " . ($k == $currM ? 'selected' : '') . ">$v</option>";
                                            }
                                            ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 uppercase mb-2">ปี
                                            พ.ศ.</label>
                                        <select name="year"
                                            class="w-full border-gray-200 bg-gray-50 rounded-xl p-3 outline-none focus:ring-2 focus:ring-teal-200">
                                            <?php
                                            $currY = date('Y');
                                            for ($y = $currY; $y >= $currY - 5; $y--) {
                                                echo "<option value='$y'>" . ($y + 543) . "</option>";
                                            }
                                            ?>
                                        </select>
                                    </div>
                                </div>
                                <button type="submit"
                                    class="w-full py-3 bg-teal-500 hover:bg-teal-600 text-white rounded-xl font-bold shadow-md hover:shadow-lg transition flex items-center justify-center gap-2">
                                    <i class="fas fa-print"></i> พิมพ์รายงาน
                                </button>
                            </form>
                        </div>

                        <!-- Yearly -->
                        <div class="report-card bg-white p-6 rounded-2xl shadow-sm">
                            <div class="mb-5">
                                <div
                                    class="w-12 h-12 bg-cyan-50 text-cyan-500 rounded-xl flex items-center justify-center mb-4">
                                    <i class="fas fa-calendar text-xl"></i>
                                </div>
                                <h3 class="font-bold text-lg text-gray-800">รายงานประจำปี</h3>
                                <p class="text-sm text-gray-500 mt-1">สรุปสถิติภาพรวมรายปี</p>
                            </div>
                            <form action="export_pdf_report.php" method="GET" target="_blank" class="print-form">
                                <input type="hidden" name="type" value="withdrawal_yearly">
                                <div class="mb-4">
                                    <label class="block text-xs font-bold text-gray-500 uppercase mb-2">เลือกปี
                                        พ.ศ.</label>
                                    <select name="year"
                                        class="w-full border-gray-200 bg-gray-50 rounded-xl p-3 outline-none focus:ring-2 focus:ring-cyan-200">
                                        <?php
                                        for ($y = $currY; $y >= $currY - 5; $y--) {
                                            echo "<option value='$y'>" . ($y + 543) . "</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                                <button type="submit"
                                    class="w-full py-3 bg-cyan-500 hover:bg-cyan-600 text-white rounded-xl font-bold shadow-md hover:shadow-lg transition flex items-center justify-center gap-2">
                                    <i class="fas fa-print"></i> พิมพ์รายงาน
                                </button>
                            </form>
                        </div>
                    </div>
                </section>

                <!-- Section 3: Advanced Reports -->
                <section class="animate-fade-in-up" style="animation-delay: 0.2s;">
                    <div class="flex items-center gap-3 mb-4 border-b border-sky-100 pb-2">
                        <div
                            class="w-8 h-8 rounded-lg bg-indigo-100 text-indigo-600 flex items-center justify-center font-bold shadow-sm">
                            3</div>
                        <h2 class="text-xl font-bold text-gray-800">รายงานขั้นสูง (Advanced)</h2>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                        <!-- Individual Material History -->
                        <div class="report-card bg-white p-6 rounded-2xl shadow-sm">
                            <div class="flex items-center gap-4 mb-5">
                                <div
                                    class="w-12 h-12 bg-indigo-50 text-indigo-500 rounded-xl flex items-center justify-center">
                                    <i class="fas fa-history text-xl"></i>
                                </div>
                                <div>
                                    <h3 class="font-bold text-lg text-gray-800">ประวัติการเบิกแยกรายวัสดุ</h3>
                                    <p class="text-sm text-gray-500">ดูความเคลื่อนไหวของวัสดุรายตัว</p>
                                </div>
                            </div>
                            <form action="export_pdf_report.php" method="GET" target="_blank" class="print-form">
                                <input type="hidden" name="type" value="material_history">
                                <div class="mb-4">
                                    <label
                                        class="block text-xs font-bold text-gray-500 uppercase mb-2">ค้นหาวัสดุ</label>
                                    <select name="supply_id" class="select2-search w-full">
                                        <option value="">-- พิมพ์ชื่อหรือรหัสวัสดุ --</option>
                                        <?php foreach ($supplies as $s): ?>
                                            <option value="<?php echo $s['id']; ?>">
                                                <?php echo htmlspecialchars($s['code'] . ' - ' . $s['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="grid grid-cols-2 gap-3 mb-4">
                                    <div>
                                        <label
                                            class="block text-xs font-bold text-gray-500 uppercase mb-2">เดือน</label>
                                        <select name="month" class="w-full border-gray-200 bg-gray-50 rounded-xl p-3">
                                            <option value="all">ทั้งปี</option>
                                            <?php foreach ($months as $k => $v)
                                                echo "<option value='$k' " . ($k == $currM ? 'selected' : '') . ">$v</option>"; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 uppercase mb-2">ปี
                                            พ.ศ.</label>
                                        <select name="year" class="w-full border-gray-200 bg-gray-50 rounded-xl p-3">
                                            <?php for ($y = $currY; $y >= $currY - 5; $y--)
                                                echo "<option value='$y'>" . ($y + 543) . "</option>"; ?>
                                        </select>
                                    </div>
                                </div>
                                <button type="submit"
                                    class="w-full py-3 bg-indigo-500 hover:bg-indigo-600 text-white rounded-xl font-bold shadow-md hover:shadow-lg transition flex items-center justify-center gap-2">
                                    <i class="fas fa-print"></i> พิมพ์ประวัติ
                                </button>
                            </form>
                        </div>

                        <!-- Procurement History -->
                        <div class="report-card bg-white p-6 rounded-2xl shadow-sm">
                            <div class="flex items-center gap-4 mb-5">
                                <div
                                    class="w-12 h-12 bg-amber-50 text-amber-500 rounded-xl flex items-center justify-center">
                                    <i class="fas fa-shipping-fast text-xl"></i>
                                </div>
                                <div>
                                    <h3 class="font-bold text-lg text-gray-800">ประวัติการรับเข้า/จัดซื้อ</h3>
                                    <p class="text-sm text-gray-500">รายงานวัสดุขาเข้าตามช่วงเวลา</p>
                                </div>
                            </div>
                            <form action="export_pdf_report.php" method="GET" target="_blank" class="print-form">
                                <input type="hidden" name="type" value="import_history">
                                <div class="grid grid-cols-2 gap-4 mb-4">
                                    <div>
                                        <label
                                            class="block text-xs font-bold text-gray-500 uppercase mb-2">จากวันที่</label>
                                        <input type="date" name="start_date" value="<?php echo date('Y-m-01'); ?>"
                                            class="w-full border-gray-200 bg-gray-50 rounded-xl p-3 focus:ring-2 focus:ring-amber-200 outline-none">
                                    </div>
                                    <div>
                                        <label
                                            class="block text-xs font-bold text-gray-500 uppercase mb-2">ถึงวันที่</label>
                                        <input type="date" name="end_date" value="<?php echo date('Y-m-t'); ?>"
                                            class="w-full border-gray-200 bg-gray-50 rounded-xl p-3 focus:ring-2 focus:ring-amber-200 outline-none">
                                    </div>
                                </div>
                                <div class="h-4"></div> <!-- Spacer to match height -->
                                <button type="submit"
                                    class="w-full py-3 bg-amber-500 hover:bg-amber-600 text-white rounded-xl font-bold shadow-md hover:shadow-lg transition flex items-center justify-center gap-2">
                                    <i class="fas fa-print"></i> พิมพ์รายงานรับเข้า
                                </button>
                            </form>
                        </div>

                    </div>
                </section>

            </div>
        </div>
    </main>

    <script>
        $(document).ready(function () {
            // Initialize Select2
            $('.select2-search').select2({
                placeholder: "พิมพ์ชื่อหรือรหัสวัสดุ...",
                allowClear: true,
                dropdownCssClass: "font-[Sarabun]"
            });

            // Handle Direct Link Prints (Simple confirmation)
            window.confirmPrint = function (url, title) {
                Swal.fire({
                    title: 'ยืนยันการพิมพ์',
                    text: `ต้องการพิมพ์ "${title}" ใช่หรือไม่?`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#0ea5e9',
                    cancelButtonColor: '#94a3b8',
                    confirmButtonText: 'ใช่, พิมพ์เลย',
                    cancelButtonText: 'ยกเลิก',
                    customClass: {
                        popup: 'rounded-2xl font-[Sarabun]',
                        confirmButton: 'rounded-xl',
                        cancelButton: 'rounded-xl'
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.open(url, '_blank');
                    }
                });
            };

            // Handle Form Submits with Validation/Confirmation
            $('.print-form').on('submit', function (e) {
                e.preventDefault();
                const form = this;

                // Special check for material dropdown
                const selectElement = $(form).find('select[name="supply_id"]');
                if (selectElement.length > 0 && !selectElement.val()) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'กรุณาเลือกวัสดุ',
                        text: 'ต้องระบุวัสดุที่ต้องการดูประวัติก่อนพิมพ์',
                        confirmButtonColor: '#eab308',
                        customClass: { popup: 'rounded-2xl font-[Sarabun]', confirmButton: 'rounded-xl' }
                    });
                    return;
                }

                Swal.fire({
                    title: 'กำลังสร้างรายงาน...',
                    text: 'กรุณารอสักครู่ ระบบกำลังเตรียมไฟล์ PDF',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                        // Submit form after a brief delay to show loading
                        setTimeout(() => {
                            Swal.close();
                            form.submit();
                        }, 800);
                    }
                });
            });
        });
    </script>
</body>

</html>