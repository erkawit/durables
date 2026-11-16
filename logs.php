<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'supply_head'])) {
    header("Location: dashboard.php");
    exit;
}

// Handle log clearing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_logs') {
    try {
        $period = $_POST['period'] ?? '';
        $interval = '';
        
        switch ($period) {
            case '1_month':
                $interval = '1 MONTH';
                break;
            case '2_months':
                $interval = '2 MONTH';
                break;
            case '3_months':
                $interval = '3 MONTH';
                break;
            case '1_year':
                $interval = '1 YEAR';
                break;
            default:
                throw new Exception("ช่วงเวลาไม่ถูกต้อง");
        }
        
        $stmt = $pdo->prepare("DELETE FROM system_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL $interval)");
        $stmt->execute();
        
        logAction($pdo, $_SESSION['user_id'], 'DELETE', 'logs.php', "ล้างประวัติการใช้งานให้เหลือข้อมูลย้อนหลังล่าสุด " . ($period === '1_year' ? '1 ปี' : str_replace('_months', ' เดือน', str_replace('1_month', '1 เดือน', $period))));
        
        echo json_encode(['status' => 'success', 'message' => 'ล้างข้อมูลประวัติเรียบร้อยแล้ว']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Fetch Logs
$stmt = $pdo->query("
    SELECT l.*, CONCAT(u.prefix, u.firstname, ' ', u.lastname) as fullname 
    FROM system_logs l 
    LEFT JOIN users u ON l.user_id = u.id 
    ORDER BY l.created_at DESC LIMIT 100
");
$logs = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <title>ประวัติการใช้งานระบบ</title>
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
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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

        .dt-row {
            transition: all 0.2s;
        }

        .dt-row:hover {
            background-color: #f8fafc !important;
        }
    </style>
</head>

<body class="bg-gray-50 flex h-screen overflow-hidden">

    <?php $page = 'logs'; include 'sidebar.php'; ?>

    <main class="flex-1 flex flex-col overflow-hidden relative">
        <header class="bg-white shadow px-6 py-4 flex justify-between items-center sticky top-0 z-10 flex-shrink-0">
            <div class="flex items-center gap-4">
                <a href="dashboard.php" class="text-gray-500 hover:text-sky-600 transition"><i
                        class="fas fa-arrow-left text-xl"></i></a>
                <h1 class="text-xl font-bold text-gray-800">ประวัติการใช้งานระบบ</h1>
            </div>
            <div class="flex items-center gap-4">
                <div class="text-sm text-gray-500">
                    <i class="fas fa-database mr-2"></i>
                    <span class="font-bold"><?php echo count($logs); ?></span> รายการ
                </div>
                <button onclick="openClearLogModal()" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition flex items-center gap-2 shadow-sm">
                    <i class="fas fa-trash-alt"></i> เคลียร์ Log
                </button>
            </div>
        </header>

        <div class="flex-1 overflow-y-auto p-6 w-full">
            <div class="bg-white p-6 rounded-2xl shadow-lg border border-gray-100 overflow-hidden">
                <table id="logTable" class="w-full text-left stripe hover nowrap">
                    <thead>
                        <tr class="bg-sky-50 text-sky-800">
                            <th class="py-4 px-4 rounded-l-lg">เวลา</th>
                            <th class="py-4 px-4">ผู้ใช้งาน</th>
                            <th class="py-4 px-4">การกระทำ</th>
                            <th class="py-4 px-4 rounded-r-lg">รายละเอียด</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr class="border-b border-gray-50 dt-row">
                                <td class="px-4 py-3 text-gray-500 whitespace-nowrap font-mono text-sm">
                                    <?php echo $log['created_at']; ?>
                                </td>
                                <td class="px-4 py-3 font-bold text-gray-800">
                                    <?php echo htmlspecialchars($log['fullname']); ?>
                                </td>
                                <td class="px-4 py-3">
                                    <?php 
                                    $act = strtoupper($log['action_type']);
                                    $badgeStyle = 'bg-sky-100 text-sky-700 border-sky-200';
                                    if ($act === 'ADD') {
                                        $badgeStyle = 'bg-emerald-100 text-emerald-800 border-emerald-300 font-extrabold';
                                    } elseif ($act === 'EDIT') {
                                        $badgeStyle = 'bg-blue-100 text-blue-800 border-blue-300 font-extrabold';
                                    } elseif ($act === 'DELETE') {
                                        $badgeStyle = 'bg-rose-100 text-rose-800 border-rose-300 font-extrabold';
                                    }
                                    ?>
                                    <span class="<?php echo $badgeStyle; ?> border px-3 py-1 rounded-lg text-xs font-bold inline-block shadow-sm">
                                        <?php echo $act; ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-gray-600">
                                    <?php echo htmlspecialchars($log['details']); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <script>
        $(document).ready(function () {
            $('#logTable').DataTable({
                responsive: true,
                order: [[0, 'desc']],
                language: {
                    search: "ค้นหา:",
                    lengthMenu: "แสดง _MENU_ รายการ",
                    info: "แสดง _START_ ถึง _END_ จาก _TOTAL_ รายการ",
                    paginate: { first: "หน้าแรก", last: "หน้าสุดท้าย", next: "ถัดไป", previous: "ก่อนหน้า" },
                    emptyTable: "ไม่มีข้อมูลประวัติการใช้งาน"
                }
            });
        });

        function openClearLogModal() {
            Swal.fire({
                title: 'เคลียร์ประวัติการใช้งานระบบ',
                html: `
                    <div class="text-left text-sm text-gray-600 mb-4 font-sans">
                        กรุณาเลือกช่วงเวลาของข้อมูลที่ต้องการ<strong>เก็บรักษาไว้</strong> ข้อมูลที่เก่ากว่าช่วงเวลาที่เลือกจะถูกลบออกทั้งหมด:
                    </div>
                    <div class="space-y-3 text-left font-sans">
                        <label class="flex items-center gap-3 p-3 border rounded-xl hover:bg-gray-50 cursor-pointer transition">
                            <input type="radio" name="clear_period" value="1_month" checked class="w-4 h-4 text-sky-600">
                            <div>
                                <div class="font-bold text-gray-800">เก็บข้อมูลย้อนหลังล่าสุด 1 เดือน</div>
                                <div class="text-xs text-gray-500">ลบข้อมูลเก่าทั้งหมดที่มีอายุมากกว่า 1 เดือน</div>
                            </div>
                        </label>
                        <label class="flex items-center gap-3 p-3 border rounded-xl hover:bg-gray-50 cursor-pointer transition">
                            <input type="radio" name="clear_period" value="2_months" class="w-4 h-4 text-sky-600">
                            <div>
                                <div class="font-bold text-gray-800">เก็บข้อมูลย้อนหลังล่าสุด 2 เดือน</div>
                                <div class="text-xs text-gray-500">ลบข้อมูลเก่าทั้งหมดที่มีอายุมากกว่า 2 เดือน</div>
                            </div>
                        </label>
                        <label class="flex items-center gap-3 p-3 border rounded-xl hover:bg-gray-50 cursor-pointer transition">
                            <input type="radio" name="clear_period" value="3_months" class="w-4 h-4 text-sky-600">
                            <div>
                                <div class="font-bold text-gray-800">เก็บข้อมูลย้อนหลังล่าสุด 3 เดือน</div>
                                <div class="text-xs text-gray-500">ลบข้อมูลเก่าทั้งหมดที่มีอายุมากกว่า 3 เดือน</div>
                            </div>
                        </label>
                        <label class="flex items-center gap-3 p-3 border rounded-xl hover:bg-gray-50 cursor-pointer transition">
                            <input type="radio" name="clear_period" value="1_year" class="w-4 h-4 text-sky-600">
                            <div>
                                <div class="font-bold text-gray-800">เก็บข้อมูลย้อนหลังล่าสุด 1 ปี</div>
                                <div class="text-xs text-gray-500">ลบข้อมูลเก่าทั้งหมดที่มีอายุมากกว่า 1 ปี</div>
                            </div>
                        </label>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'เคลียร์ข้อมูล',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280',
                preConfirm: () => {
                    return document.querySelector('input[name="clear_period"]:checked').value;
                }
            }).then((result) => {
                if (result.isConfirmed && result.value) {
                    Swal.fire({
                        title: 'ยืนยันการเคลียร์ข้อมูล?',
                        text: "การดำเนินการนี้ไม่สามารถย้อนกลับได้!",
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'ใช่, ยืนยันการลบ',
                        cancelButtonText: 'ยกเลิก',
                        confirmButtonColor: '#ef4444',
                        cancelButtonColor: '#6b7280'
                    }).then((confirmResult) => {
                        if (confirmResult.isConfirmed) {
                            $.ajax({
                                url: 'logs.php',
                                type: 'POST',
                                data: {
                                    action: 'clear_logs',
                                    period: result.value
                                },
                                dataType: 'json',
                                success: function(response) {
                                    if (response.status === 'success') {
                                        Swal.fire({
                                            icon: 'success',
                                            title: 'สำเร็จ',
                                            text: response.message,
                                            timer: 1500,
                                            showConfirmButton: false
                                        }).then(() => {
                                            location.reload();
                                        });
                                    } else {
                                        Swal.fire({
                                            icon: 'error',
                                            title: 'เกิดข้อผิดพลาด',
                                            text: response.message
                                        });
                                    }
                                },
                                error: function() {
                                    Swal.fire({
                                        icon: 'error',
                                        title: 'เกิดข้อผิดพลาด',
                                        text: 'ไม่สามารถติดต่อเซิร์ฟเวอร์ได้'
                                    });
                                }
                            });
                        }
                    });
                }
            });
        }
    </script>
</body>

</html>