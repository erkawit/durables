<?php
session_start();
require_once 'config.php';

// Auto-migrate: check and add inspection_threshold column if missing
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM supplies LIKE 'inspection_threshold'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE supplies ADD COLUMN inspection_threshold INT DEFAULT 100 AFTER price");
    }
} catch (Exception $e) {
    // Ignore database errors
}

// Access Check
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'supply_head', 'supply_officer'])) {
    header("Location: dashboard.php");
    exit;
}

$currentUserId   = $_SESSION['user_id'];
$currentUserRole = $_SESSION['role'] ?? '';
$canEditQty      = in_array($currentUserRole, ['admin', 'supply_head', 'supply_officer']);
$currentUserName = $_SESSION['fullname'] ?? ($_SESSION['username'] ?? "User #{$currentUserId}");

// Auto-migrate: check and create supplies_stock_log table for stock history
try {
    $db_driver = getenv('DB_DRIVER') ?: 'mysql';
    if ($db_driver === 'pgsql') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS supplies_stock_log (
            id SERIAL PRIMARY KEY,
            supply_id INT NULL,
            supply_code VARCHAR(100) NULL,
            supply_name VARCHAR(255) NULL,
            action_type VARCHAR(20) NOT NULL,
            old_qty DECIMAL(10,2) DEFAULT 0,
            new_qty DECIMAL(10,2) DEFAULT 0,
            details TEXT NULL,
            user_id INT NULL,
            user_name VARCHAR(255) NULL,
            user_role VARCHAR(50) NULL,
            created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
        )");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS supplies_stock_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            supply_id INT NULL,
            supply_code VARCHAR(100) NULL,
            supply_name VARCHAR(255) NULL,
            action_type VARCHAR(20) NOT NULL,
            old_qty DECIMAL(10,2) DEFAULT 0,
            new_qty DECIMAL(10,2) DEFAULT 0,
            details TEXT NULL,
            user_id INT NULL,
            user_name VARCHAR(255) NULL,
            user_role VARCHAR(50) NULL,
            created_at DATETIME NOT NULL,
            KEY idx_supply_id (supply_id),
            KEY idx_action_type (action_type),
            KEY idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
} catch (Exception $e) {}

// Action: ดึงประวัติการแก้ไขและจัดการสต็อกวัสดุ (Audit Logs)
if (isset($_GET['action']) && $_GET['action'] === 'get_stock_history') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $stmt = $pdo->query("SELECT * FROM supplies_stock_log ORDER BY id DESC LIMIT 100");
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $logs]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle Add/Edit
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $unit = trim($_POST['unit'] ?? '');
    $min_stock = $_POST['min_stock'] ?? 0;
    $min_order = $_POST['min_order'] ?? 1;
    $max_order = $_POST['max_order'] ?? 0;
    $price = $_POST['price'] ?? 0.00;
    $inspection_threshold = $_POST['inspection_threshold'] ?? 100;
    $id = $_POST['id'] ?? null;
    $action = $_POST['action'];

    $now = date('Y-m-d H:i:s');

    if ($action == 'save') {
        // Image Upload Handling
        $imagePath = null;
        if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] == 0) {
            $ext = strtolower(pathinfo($_FILES['image_file']['name'], PATHINFO_EXTENSION));
            $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];
            if (!in_array($ext, $allowed_exts)) {
                echo json_encode(['status' => 'error', 'message' => 'ประเภทไฟล์รูปภาพไม่ถูกต้อง (อนุญาตเฉพาะ JPG, PNG, GIF, WEBP, AVIF)']);
                exit;
            }
            if (!is_dir('uploads')) {
                mkdir('uploads', 0755, true);
            }
            $filename = 'supply_' . time() . '_' . rand(100, 999) . '.' . $ext;
            $target = 'uploads/' . $filename;
            if (move_uploaded_file($_FILES['image_file']['tmp_name'], $target)) {
                $imagePath = $target; // Store relative path
            }
        }

        if ($id) {
            // ── EDIT (แก้ไขรายการวัสดุ) ──
            $stmtOld = $pdo->prepare("SELECT * FROM supplies WHERE id = ?");
            $stmtOld->execute([$id]);
            $oldSupply = $stmtOld->fetch(PDO::FETCH_ASSOC);

            if (!$oldSupply) {
                echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูลวัสดุที่ต้องการแก้ไข']);
                exit;
            }

            $oldQty = (float)($oldSupply['qty'] ?? 0);
            $newQty = $oldQty;
            $qtyChanged = false;

            // ตรวจสอบสิทธิ์การแก้ไขจำนวนคงเหลือ
            if ($canEditQty && isset($_POST['qty']) && $_POST['qty'] !== '') {
                $inputQty = (float)$_POST['qty'];
                if ($inputQty < 0) {
                    echo json_encode(['status' => 'error', 'message' => 'จำนวนวัสดุคงเหลือต้องไม่ติดลบ']);
                    exit;
                }
                if ($inputQty != $oldQty) {
                    $newQty = $inputQty;
                    $qtyChanged = true;
                }
            }

            $sql = "UPDATE supplies SET name=?, code=?, category=?, unit=?, min_stock=?, min_order=?, max_order=?, price=?, inspection_threshold=?";
            $params = [$name, $code, $category, $unit, $min_stock, $min_order, $max_order, $price, $inspection_threshold];

            if ($canEditQty && isset($_POST['qty']) && $_POST['qty'] !== '') {
                $sql .= ", qty=?";
                $params[] = $newQty;
            }

            if ($imagePath) {
                $sql .= ", image=?";
                $params[] = $imagePath;
            }

            $sql .= " WHERE id=?";
            $params[] = $id;

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            // บันทึกรายละเอียดการเปลี่ยนแปลง
            $changeDetails = [];
            if ($qtyChanged) {
                $changeDetails[] = "จำนวนคงเหลือ: {$oldQty} -> {$newQty} {$unit}";
            }
            if ($oldSupply['name'] !== $name) {
                $changeDetails[] = "ชื่อ: '{$oldSupply['name']}' -> '{$name}'";
            }
            if ($oldSupply['code'] !== $code) {
                $changeDetails[] = "รหัส: '{$oldSupply['code']}' -> '{$code}'";
            }
            if ((float)$oldSupply['price'] != (float)$price) {
                $changeDetails[] = "ราคา: {$oldSupply['price']} -> {$price}";
            }
            if ($oldSupply['category'] !== $category) {
                $changeDetails[] = "หมวดหมู่: '{$oldSupply['category']}' -> '{$category}'";
            }
            if ($oldSupply['unit'] !== $unit) {
                $changeDetails[] = "หน่วย: '{$oldSupply['unit']}' -> '{$unit}'";
            }

            $logText = "แก้ไขข้อมูลวัสดุ '{$name}' (รหัส: {$code})";
            if (!empty($changeDetails)) {
                $logText .= " [" . implode(', ', $changeDetails) . "]";
            }

            // บันทึกประวัติใน supplies_stock_log
            try {
                $stmtLog = $pdo->prepare("INSERT INTO supplies_stock_log 
                    (supply_id, supply_code, supply_name, action_type, old_qty, new_qty, details, user_id, user_name, user_role, created_at) 
                    VALUES (?, ?, ?, 'EDIT', ?, ?, ?, ?, ?, ?, ?)");
                $stmtLog->execute([
                    $id, $code, $name, $oldQty, $newQty, $logText, $currentUserId, $currentUserName, $currentUserRole, $now
                ]);
            } catch (Exception $e) {}

            // บันทึกใน system_logs (ระบบหลังบ้านหลัก)
            if (function_exists('logAction')) {
                logAction($pdo, $currentUserId, 'EDIT', 'supplies.php', $logText);
            }

            echo json_encode(['status' => 'success', 'message' => 'บันทึกการแก้ไขข้อมูลเรียบร้อยแล้ว']);
            exit;

        } else {
            // ── ADD (เพิ่มวัสดุใหม่) ──
            $finalImage = $imagePath ? $imagePath : '';
            $initialQty = 0;
            if ($canEditQty && isset($_POST['qty']) && $_POST['qty'] !== '') {
                $initialQty = max(0, (float)$_POST['qty']);
            }

            $stmt = $pdo->prepare("INSERT INTO supplies (name, code, category, unit, min_stock, qty, status, image, min_order, max_order, price, inspection_threshold) VALUES (?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $code, $category, $unit, $min_stock, $initialQty, $finalImage, $min_order, $max_order, $price, $inspection_threshold]);
            $newId = $pdo->lastInsertId();

            $logText = "เพิ่มรายการวัสดุใหม่ '{$name}' (รหัส: {$code}) หมวดหมู่: {$category}, จำนวนคงเหลือเริ่มต้น: {$initialQty} {$unit}, ราคา: {$price} บาท";

            // บันทึกประวัติใน supplies_stock_log
            try {
                $stmtLog = $pdo->prepare("INSERT INTO supplies_stock_log 
                    (supply_id, supply_code, supply_name, action_type, old_qty, new_qty, details, user_id, user_name, user_role, created_at) 
                    VALUES (?, ?, ?, 'ADD', 0, ?, ?, ?, ?, ?, ?)");
                $stmtLog->execute([
                    $newId, $code, $name, $initialQty, $logText, $currentUserId, $currentUserName, $currentUserRole, $now
                ]);
            } catch (Exception $e) {}

            // บันทึกใน system_logs
            if (function_exists('logAction')) {
                logAction($pdo, $currentUserId, 'ADD', 'supplies.php', $logText);
            }

            echo json_encode(['status' => 'success', 'message' => 'เพิ่มรายการวัสดุใหม่เรียบร้อยแล้ว']);
            exit;
        }

    } elseif ($action == 'delete') {
        // ── DELETE (ลบรายการวัสดุ) ──
        $stmtOld = $pdo->prepare("SELECT * FROM supplies WHERE id = ?");
        $stmtOld->execute([$id]);
        $delSupply = $stmtOld->fetch(PDO::FETCH_ASSOC);

        if ($delSupply) {
            $delQty = (float)($delSupply['qty'] ?? 0);
            $delName = $delSupply['name'];
            $delCode = $delSupply['code'];
            $delUnit = $delSupply['unit'] ?? 'หน่วย';

            $stmt = $pdo->prepare("UPDATE supplies SET status='inactive' WHERE id=?");
            $stmt->execute([$id]);

            $logText = "ลบรายการวัสดุ (เปลี่ยนเป็น Inactive) '{$delName}' (รหัส: {$delCode}) จำนวนคงเหลือ ณ ตอนลบ: {$delQty} {$delUnit}";

            // บันทึกประวัติใน supplies_stock_log
            try {
                $stmtLog = $pdo->prepare("INSERT INTO supplies_stock_log 
                    (supply_id, supply_code, supply_name, action_type, old_qty, new_qty, details, user_id, user_name, user_role, created_at) 
                    VALUES (?, ?, ?, 'DELETE', ?, 0, ?, ?, ?, ?, ?)");
                $stmtLog->execute([
                    $id, $delCode, $delName, $delQty, $logText, $currentUserId, $currentUserName, $currentUserRole, $now
                ]);
            } catch (Exception $e) {}

            // บันทึกใน system_logs
            if (function_exists('logAction')) {
                logAction($pdo, $currentUserId, 'DELETE', 'supplies.php', $logText);
            }

            echo json_encode(['status' => 'success', 'message' => 'ลบรายการวัสดุเรียบร้อยแล้ว']);
            exit;
        } else {
            echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูลรายการวัสดุที่ต้องการลบ']);
            exit;
        }
    }
    exit;
}

// Fetch Supplies
$stmt = $pdo->query("SELECT * FROM supplies WHERE status = 'active' ORDER BY id DESC");
$supplies = $stmt->fetchAll();

// Fetch Distinct Categories
try {
    $catStmt = $pdo->query("SELECT type_name FROM product_type ORDER BY type_name");
    $sysCategories = $catStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $sysCategories = [];
}

// Fetch Units from type_volume table
try {
    $unitStmt = $pdo->query("SELECT type_volume FROM type_volume ORDER BY type_volume");
    $sysUnits = $unitStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $sysUnits = [];
}

// Summary stats
$statTotalItems = count($supplies);
$statCurrentValue = 0;
foreach ($supplies as $s) {
    $statCurrentValue += (float)$s['qty'] * (float)($s['price'] ?? 0);
}
$statDisbursedValue = (float)$pdo->query(
    "SELECT COALESCE(SUM(COALESCE(ri.approved_qty, ri.qty) * COALESCE(s.price, 0)), 0)
     FROM request_items ri
     JOIN requests r ON r.id = ri.request_id
     JOIN supplies s ON s.id = ri.supply_id
     WHERE r.status NOT IN ('cancelled','rejected')
       AND (ri.is_deleted IS NULL OR ri.is_deleted = 0)"
)->fetchColumn();
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <title>จัดการคลังวัสดุ</title>
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

    <!-- DataTables & Realtime -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.socket.io/4.8.1/socket.io.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>

    <!-- Select2 for searchable dropdowns -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

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

        /* Custom File Input Styling */
        .file-upload-wrapper {
            position: relative;
            width: 100%;
            height: 150px;
            border: 2px dashed #cbd5e1;
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            background-color: #f8fafc;
            transition: all 0.3s;
            cursor: pointer;
        }

        .file-upload-wrapper:hover {
            border-color: #0ea5e9;
            background-color: #f4f6f8;
        }

        .file-upload-wrapper input[type="file"] {
            position: absolute;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }

        .preview-img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            padding: 4px;
        }

        .placeholder {
            text-align: center;
            color: #64748b;
        }

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
    </style>
</head>

<body class="bg-gray-50 flex h-screen overflow-hidden">

    <?php $page = 'supplies'; include 'sidebar.php'; ?>

    <main class="flex-1 flex flex-col overflow-hidden relative">

        <header class="bg-white shadow px-6 py-4 flex justify-between items-center sticky top-0 z-10 shrink-0">
            <div class="flex items-center gap-4">
                <a href="dashboard.php" class="text-gray-500 hover:text-sky-600 transition"><i
                        class="fas fa-arrow-left text-xl"></i></a>
                <h1 class="text-xl font-bold text-gray-800">จัดการคลังวัสดุ</h1>
            </div>
            <div class="flex items-center gap-2">
                <?php if ($canEditQty): ?>
                <button onclick="viewStockHistoryModal()"
                    class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3.5 py-2 rounded-lg text-sm font-bold shadow-sm transition flex items-center gap-1.5 border border-gray-200">
                    <i class="fas fa-history text-gray-500"></i> ประวัติการแก้ไขวัสดุ
                </button>
                <?php endif; ?>
                <?php if (in_array($_SESSION['role'], ['admin', 'supply_head', 'supply_officer'])): ?>
                <a href="sync_legacy.php"
                    class="bg-white hover:bg-gray-100 px-4 py-2 rounded-lg text-sm font-bold shadow transition flex items-center gap-2"
                    style="color: var(--primary-color) !important;">
                    <i class="fas fa-sync-alt" style="color: var(--primary-color) !important;"></i> ซิงค์ระบบเดิม
                </a>
                <?php endif; ?>
                <a href="procurement.php"
                    class="bg-sky-500 hover:bg-sky-600 text-white px-4 py-2 rounded-lg text-sm font-bold shadow transition flex items-center gap-2">
                    <i class="fas fa-plus"></i> เพิ่ม/รับเข้าวัสดุ
                </a>
            </div>
        </header>

        <div class="flex-1 overflow-y-auto p-6 space-y-6 w-full">

            <!-- Summary Stats Bar -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 px-5 py-4 flex items-center gap-4">
                    <div class="w-11 h-11 rounded-xl bg-sky-100 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-boxes text-sky-600 text-lg"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 font-medium">จำนวนรายการวัสดุทั้งหมด</p>
                        <p class="text-2xl font-bold text-sky-700"><?php echo number_format($statTotalItems); ?> <span class="text-sm font-normal text-gray-400">รายการ</span></p>
                    </div>
                </div>
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 px-5 py-4 flex items-center gap-4">
                    <div class="w-11 h-11 rounded-xl bg-emerald-100 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-warehouse text-emerald-600 text-lg"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 font-medium">มูลค่าวัสดุคงคลังปัจจุบัน</p>
                        <p class="text-2xl font-bold text-emerald-700">฿<?php echo number_format($statCurrentValue, 2); ?></p>
                    </div>
                </div>
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 px-5 py-4 flex items-center gap-4">
                    <div class="w-11 h-11 rounded-xl bg-amber-100 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-hand-holding-usd text-amber-600 text-lg"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 font-medium">มูลค่าวัสดุที่เบิกจ่ายทั้งหมด</p>
                        <p class="text-2xl font-bold text-amber-700">฿<?php echo number_format($statDisbursedValue, 2); ?></p>
                    </div>
                </div>
            </div>

            <!-- Instructions Banner -->
            <div class="bg-gradient-to-r from-sky-50 to-blue-50 border-l-4 border-sky-500 px-6 py-4 rounded-lg shadow-sm">
                <div class="flex items-start gap-3">
                    <div class="flex-shrink-0">
                        <i class="fas fa-info-circle text-sky-500 text-2xl"></i>
                    </div>
                    <div class="flex-1">
                        <h3 class="font-bold text-gray-800 mb-2 flex items-center gap-2">
                            <i class="fas fa-lightbulb text-amber-500"></i>
                            คำแนะนำการใช้งาน
                        </h3>
                        <ul class="text-sm text-gray-700 space-y-1">
                            <li class="flex items-start gap-2">
                                <i class="fas fa-check-circle text-green-500 mt-0.5"></i>
                                <span><strong>คลิกที่รูปภาพ</strong> เพื่อดูรูปขนาดใหญ่แบบเต็มหน้าจอ</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <i class="fas fa-check-circle text-green-500 mt-0.5"></i>
                                <span><strong>คลิกที่หัวตาราง</strong> เพื่อเรียงลำดับข้อมูล (เช่น รหัส, ชื่อ, คงเหลือ)</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <i class="fas fa-check-circle text-green-500 mt-0.5"></i>
                                <span><strong>ใช้ช่องค้นหา</strong> ด้านบนขวาเพื่อค้นหาวัสดุที่ต้องการ</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <i class="fas fa-check-circle text-green-500 mt-0.5"></i>
                                <span><strong>สีแดง</strong> = วัสดุเหลือน้อยกว่าหรือเท่ากับขั้นต่ำ, <strong>สีเขียว</strong> = วัสดุพอใช้</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Table Card -->
            <div class="bg-white p-6 rounded-2xl shadow-lg border border-gray-100 overflow-x-auto w-full">
                <table id="table" class="w-full text-left stripe hover nowrap align-middle">
                    <thead>
                        <tr class="bg-sky-50 text-sky-800">
                            <th class="py-4 px-4 rounded-l-lg w-16">
                                <div class="flex items-center gap-1">
                                    รูปภาพ
                                    <i class="fas fa-search-plus text-xs text-sky-400" title="คลิกที่รูปเพื่อขยาย"></i>
                                </div>
                            </th>
                            <th class="py-4 px-4">รหัส</th>
                            <th class="py-4 px-4">ชื่อวัสดุ</th>
                            <th class="py-4 px-4">หมวดหมู่</th>
                            <th class="py-4 px-4 text-center">คงเหลือ</th>
                            <th class="py-4 px-4 text-center">หน่วย</th>
                            <th class="py-4 px-4 text-center">ราคา/หน่วย</th>
                            <th class="py-4 px-4 rounded-r-lg text-center">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($supplies as $s):
                            $imgSrc = !empty($s['image']) ? $s['image'] : 'img/packaging.png';
                            ?>
                            <tr class="border-b border-gray-50 dt-row">
                                <td class="px-4 py-2">
                                    <div class="w-12 h-12 rounded-lg bg-gray-100 border border-gray-200 overflow-hidden cursor-pointer hover:shadow-md hover:scale-110 transition-all duration-200 relative group"
                                        onclick="showImage('<?php echo htmlspecialchars($imgSrc); ?>', '<?php echo htmlspecialchars($s['name']); ?>')">
                                        <img src="<?php echo htmlspecialchars($imgSrc); ?>" alt="img"
                                            class="w-full h-full object-cover" onerror="this.onerror=null;this.src='img/packaging.png'">
                                        <div
                                            class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-30 transition-all duration-200 flex items-center justify-center">
                                            <i
                                                class="fas fa-search-plus text-white opacity-0 group-hover:opacity-100 transition-all duration-200"></i>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-4 text-gray-500 font-mono"><?php echo $s['code']; ?></td>
                                <td class="px-4 py-3 font-bold text-gray-800"><?php echo $s['name']; ?></td>
                                <td class="px-4 py-3"><span
                                        class="bg-sky-50 text-sky-600 px-2 py-1 rounded-md text-sm font-bold border border-sky-100"><?php echo $s['category']; ?></span>
                                </td>
                                <td
                                    class="qty-cell px-4 py-3 text-center font-bold <?php echo $s['qty'] <= $s['min_stock'] ? 'text-red-500' : 'text-green-600'; ?>"
                                    data-id="<?php echo $s['id']; ?>"
                                    data-min-stock="<?php echo $s['min_stock']; ?>">
                                    <?php echo $s['qty']; ?>
                                </td>
                                <td class="px-4 py-3 text-center text-gray-500"><?php echo $s['unit']; ?></td>
                                <td class="px-4 py-3 text-center text-sky-600 font-bold">
                                    ฿<?php echo number_format($s['price'] ?? 0, 2); ?></td>
                                <td class="px-4 py-3 text-center flex justify-center gap-2 items-center h-full pt-4">
                                    <button onclick='showDetailsModal(<?php echo json_encode($s); ?>)'
                                        class="bg-blue-100 text-blue-600 hover:bg-blue-200 px-3 py-1.5 rounded-lg transition text-sm font-bold shadow-sm"
                                        title="รายละเอียดและประวัติ">
                                        <i class="fas fa-info-circle mr-1"></i> รายละเอียด
                                    </button>
                                    <button onclick='editItem(<?php echo json_encode($s); ?>)'
                                        class="bg-amber-100 text-amber-600 hover:bg-amber-200 px-3 py-1.5 rounded-lg transition text-sm font-bold shadow-sm"
                                        title="แก้ไขข้อมูลวัสดุ ชื่อ รหัส หมวดหมู่ และรูปภาพ">
                                        <i class="fas fa-edit mr-1"></i> แก้ไข
                                    </button>
                                    <button onclick="deleteItem(<?php echo $s['id']; ?>)"
                                        class="bg-red-100 text-red-600 hover:bg-red-200 px-3 py-1.5 rounded-lg transition text-sm font-bold shadow-sm"
                                        title="เปลี่ยนสถานะวัสดุเป็น Inactive (ไม่ลบถาวร)">
                                        <i class="fas fa-trash-alt mr-1"></i> ลบ
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </div>

    <script>
        $(document).ready(function () {
            $('#table').DataTable({
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
        });

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

        // Dynamic DB Options
        const sysCategories = <?php echo json_encode($sysCategories); ?>;
        const sysUnits = <?php echo json_encode($sysUnits); ?>;
        const canEditQty = <?php echo $canEditQty ? 'true' : 'false'; ?>;

        // Shared Modal Logic
        function openModal(item = null) {
            let isEdit = !!item;
            let imgSrc = (isEdit && item.image) ? item.image : '';
            let showImg = imgSrc ? '' : 'hidden';
            let showPlaceholder = imgSrc ? 'hidden' : '';

            // Qty field is rendered ONLY for authorized roles (admin, supply_head, supply_officer)
            let qtySectionHtml = '';
            if (canEditQty) {
                let currentQty = isEdit ? (item.qty !== undefined && item.qty !== null ? Number(item.qty) : 0) : 0;
                let currentUnit = (isEdit && item.unit) ? item.unit : '';
                qtySectionHtml = `
                    <div class="bg-amber-50/90 border border-amber-200 rounded-xl p-3.5 my-1 shadow-sm">
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="text-xs font-bold text-amber-900 ml-0.5 flex items-center gap-1.5">
                                <i class="fas fa-boxes text-amber-600"></i> จำนวนวัสดุคงเหลือทั้งหมดในคลัง (สต็อก) <span class="text-red-500">*</span>
                            </label>
                            <span class="text-[10px] bg-amber-200 text-amber-900 px-2.5 py-0.5 rounded-full font-bold">
                                เฉพาะเจ้าหน้าที่พัสดุ / Admin
                            </span>
                        </div>
                        <div class="relative">
                            <input id="swal-qty" type="number" step="any" min="0" class="w-full px-3.5 py-2 border-2 border-amber-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-500 font-bold text-amber-950 text-base bg-white shadow-inner" placeholder="0" value="${currentQty}">
                            <span class="absolute right-3.5 top-2.5 text-xs text-amber-700 font-bold" id="swal-qty-unit">${currentUnit}</span>
                        </div>
                        <p class="text-[11px] text-amber-700 mt-1 ml-0.5">
                            <i class="fas fa-info-circle text-amber-500 mr-0.5"></i> เมื่อแก้ไขจำนวนยอด ระบบจะบันทึกประวัติ (EDIT) ไว้ในระบบหลังบ้านเพื่อตรวจสอบย้อนหลัง
                        </p>
                    </div>
                `;
            }

            Swal.fire({
                title: isEdit ? 'แก้ไขข้อมูลวัสดุ' : 'เพิ่มวัสดุใหม่',
                html: `
                    <div class="space-y-4 pt-2 text-left">
                        <!-- Image Upload -->
                        <div class="flex justify-center">
                            <div class="file-upload-wrapper w-48 h-48 mx-auto">
                                <input type="file" id="swal-file" accept="image/*" onchange="previewUpload(this)">
                                <div id="upload-placeholder" class="placeholder ${showPlaceholder} flex flex-col items-center pointer-events-none">
                                    <i class="fas fa-cloud-upload-alt text-3xl mb-2 text-gray-400"></i>
                                    <span class="text-xs text-gray-500 font-bold">คลิกเพื่ออัพโหลดรูปภาพ</span>
                                    <span class="text-xs text-gray-400 mt-1">รองรับ JPG, PNG (แนะนำขนาด 500x500px)</span>
                                </div>
                                <img id="img-preview" src="${imgSrc}" class="preview-img ${showImg} pointer-events-none">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-3">
                            <div>
                                <label class="text-xs font-bold text-gray-500 ml-1">รหัสวัสดุ</label>
                                <input id="swal-code" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-sky-500 ${isEdit ? 'bg-gray-100 cursor-not-allowed text-gray-500' : ''}" placeholder="ระบุรหัสวัสดุ" value="${isEdit ? item.code : ''}" ${isEdit ? 'disabled' : ''}>
                            </div>
                            <div>
                                <label class="text-xs font-bold text-gray-500 ml-1">ชื่อวัสดุ</label>
                                <input id="swal-name" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-sky-500" placeholder="ระบุชื่อวัสดุ" value="${isEdit ? item.name : ''}">
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="text-xs font-bold text-gray-500 ml-1">หมวดหมู่</label>
                                    <select id="swal-cat" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-sky-500">
                                        <option value="">-- เลือกหมวดหมู่ --</option>
                                        ${sysCategories.map(cat => `<option value="${cat}" ${isEdit && item.category === cat ? 'selected' : ''}>${cat}</option>`).join('')}
                                    </select>
                                </div>
                                <div>
                                    <label class="text-xs font-bold text-gray-500 ml-1">หน่วยนับ</label>
                                    <select id="swal-unit" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-sky-500">
                                        <option value="">-- เลือกหน่วย --</option>
                                        ${sysUnits.map(unit => `<option value="${unit}" ${isEdit && item.unit === unit ? 'selected' : ''}>${unit}</option>`).join('')}
                                    </select>
                                </div>
                            </div>
                            ${qtySectionHtml}
                            <div class="grid grid-cols-2 gap-3 mb-2">
                                <div>
                                    <label class="text-xs font-bold text-gray-500 ml-1" title="ราคาของวัสดุต่อส่วนหนึ่งหน่วย">ราคาวัสดุ / หน่วย</label>
                                    <input id="swal-price" type="number" step="0.01" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-sky-500" placeholder="0.00" value="${isEdit ? item.price : ''}">
                                </div>
                                <div>
                                    <label class="text-xs font-bold text-gray-500 ml-1" title="เกณฑ์การแจ้งเตือนการเบิกสะสมประจำปีสำหรับผู้ใช้รายคน">เกณฑ์สะสมประจำปี (Threshold)</label>
                                    <input id="swal-threshold" type="number" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-sky-500" placeholder="100" value="${isEdit ? (item.inspection_threshold ?? 100) : 100}">
                                </div>
                            </div>
                            <div class="grid grid-cols-3 gap-3">
                                <div>
                                    <label class="text-xs font-bold text-gray-500 ml-1" title="เมื่อวัสดุเหลือน้อยกว่าหรือเท่ากับจำนวนนี้ จะแสดงสีแดง">ขั้นต่ำแจ้งเตือน <i class="fas fa-question-circle text-gray-400"></i></label>
                                    <input id="swal-min" type="number" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-sky-500" placeholder="0" value="${isEdit ? item.min_stock : ''}">
                                </div>
                                <div>
                                    <label class="text-xs font-bold text-gray-500 ml-1" title="จำนวนขั้นต่ำที่ผู้ใช้สามารถเบิกได้ต่อครั้ง">เบิกขั้นต่ำ <i class="fas fa-question-circle text-gray-400"></i></label>
                                    <input id="swal-min-order" type="number" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-sky-500" placeholder="1" value="${isEdit ? (item.min_order ?? 1) : 1}">
                                </div>
                                <div>
                                    <label class="text-xs font-bold text-gray-500 ml-1" title="จำนวนสูงสุดที่ผู้ใช้สามารถเบิกได้ต่อครั้ง (0 = ไม่จำกัด)">เบิกสูงสุด <i class="fas fa-question-circle text-gray-400"></i></label>
                                    <input id="swal-max-order" type="number" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-sky-500" placeholder="0 = ไม่จำกัด" value="${isEdit ? (item.max_order ?? 0) : 0}">
                                </div>
                            </div>
                        </div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-save mr-1"></i> บันทึกข้อมูล',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#0ea5e9',
                cancelButtonColor: '#94a3b8',
                focusConfirm: false,
                didOpen: () => {
                    $('#swal-cat').select2({
                        dropdownParent: $('.swal2-container'),
                        width: '100%'
                    });
                    $('#swal-unit').select2({
                        dropdownParent: $('.swal2-container'),
                        width: '100%'
                    }).on('change', function () {
                        let u = $(this).val();
                        let lbl = document.getElementById('swal-qty-unit');
                        if (lbl) lbl.textContent = u;
                    });
                },
                preConfirm: () => {
                    // Create FormData
                    let formData = new FormData();
                    formData.append('action', 'save');
                    if (isEdit) formData.append('id', item.id);

                    formData.append('code', document.getElementById('swal-code').value);
                    formData.append('name', document.getElementById('swal-name').value);
                    formData.append('category', $('#swal-cat').val() || '');
                    formData.append('unit', $('#swal-unit').val() || '');
                    formData.append('min_stock', document.getElementById('swal-min').value);
                    formData.append('min_order', document.getElementById('swal-min-order').value);
                    formData.append('max_order', document.getElementById('swal-max-order').value);
                    formData.append('price', document.getElementById('swal-price').value || 0);
                    formData.append('inspection_threshold', document.getElementById('swal-threshold').value || 100);

                    if (canEditQty && document.getElementById('swal-qty')) {
                        let qVal = document.getElementById('swal-qty').value.trim();
                        if (qVal === '' || isNaN(qVal) || Number(qVal) < 0) {
                            Swal.showValidationMessage('กรุณาระบุจำนวนวัสดุคงเหลือให้ถูกต้อง (ต้องเป็นตัวเลข 0 ขึ้นไป)');
                            return false;
                        }
                        formData.append('qty', qVal);
                    }

                    let file = document.getElementById('swal-file').files[0];
                    if (file) {
                        formData.append('image_file', file);
                    }

                    return formData;
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    saveData(result.value);
                }
            });
        }

        function showDetailsModal(item) {
            Swal.fire({
                title: 'รายละเอียดพัสดุ',
                html: `
                    <div class="space-y-4 pt-2 text-left font-[Sarabun]">
                        <div class="flex flex-col items-center">
                            <img src="${item.image || 'img/packaging.png'}" onerror="this.onerror=null;this.src='img/packaging.png'" class="w-32 h-32 object-contain rounded-lg border-2 border-gray-200 shadow-sm mb-3">
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
            openModal(item);
        }

        function saveData(formData) {
            Swal.fire({
                title: 'กำลังบันทึก...',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            $.ajax({
                url: 'supplies.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function (response) {
                    try {
                        let res = typeof response === 'object' ? response : JSON.parse(response);
                        if (res.status === 'success') {
                            if (typeof socket !== 'undefined') {
                                socket.emit('supply_change', { action: 'save' });
                            }
                            Swal.fire('สำเร็จ', res.message || 'บันทึกข้อมูลเรียบร้อย', 'success').then(() => location.reload());
                        } else {
                            Swal.fire('ข้อผิดพลาด', res.message || 'ไม่สามารถบันทึกได้', 'error');
                        }
                    } catch (e) {
                        Swal.fire('ข้อผิดพลาด', 'Server Response Error', 'error');
                        console.error(response);
                    }
                },
                error: function () {
                    Swal.fire('ข้อผิดพลาด', 'Network Error', 'error');
                }
            });
        }

        function deleteItem(id) {
            Swal.fire({
                title: 'ยืนยันการลบรายการวัสดุ?',
                text: 'รายการนี้จะถูกเปลี่ยนสถานะเป็น Inactive และบันทึกประวัติการลบ (DELETE)',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-trash-alt mr-1"></i> ยืนยันลบ',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#94a3b8',
                customClass: { popup: 'rounded-2xl' }
            }).then((res) => {
                if (res.isConfirmed) {
                    $.post('supplies.php', { id: id, action: 'delete' }, function (resp) {
                        try {
                            let r = typeof resp === 'object' ? resp : JSON.parse(resp);
                            if (r.status === 'success') {
                                if (typeof socket !== 'undefined') {
                                    socket.emit('supply_change', { action: 'delete', id: id });
                                }
                                Swal.fire('สำเร็จ', r.message || 'ลบข้อมูลเรียบร้อยแล้ว', 'success').then(() => location.reload());
                            } else {
                                Swal.fire('ข้อผิดพลาด', r.message || 'ไม่สามารถลบข้อมูลได้', 'error');
                            }
                        } catch (e) {
                            location.reload();
                        }
                    });
                }
            });
        }

        // ฟังก์ชันเปิดดูประวัติการจัดการวัสดุ (Audit Logs: ADD, EDIT, DELETE)
        async function viewStockHistoryModal() {
            Swal.fire({
                title: 'กำลังโหลดประวัติการแก้ไขวัสดุ...',
                html: '<div class="flex justify-center my-4"><i class="fas fa-circle-notch fa-spin text-4xl text-sky-500"></i></div>',
                showConfirmButton: false,
                allowOutsideClick: false,
                customClass: { popup: 'rounded-2xl' }
            });

            try {
                const response = await fetch('supplies.php?action=get_stock_history');
                const res = await response.json();
                if (res.status !== 'success') {
                    throw new Error(res.message || 'เกิดข้อผิดพลาดในการโหลดข้อมูล');
                }

                const logs = res.data || [];
                let rowsHtml = '';

                if (logs.length === 0) {
                    rowsHtml = `<tr><td colspan="6" class="p-8 text-center text-gray-400 font-medium">ยังไม่มีประวัติการแก้ไขวัสดุ</td></tr>`;
                } else {
                    logs.forEach((log, idx) => {
                        let badge = '';
                        if (log.action_type === 'ADD') {
                            badge = '<span class="bg-emerald-100 text-emerald-700 border border-emerald-300 px-2.5 py-1 rounded-lg text-xs font-bold inline-flex items-center gap-1"><i class="fas fa-plus-circle"></i> ADD</span>';
                        } else if (log.action_type === 'EDIT') {
                            badge = '<span class="bg-blue-100 text-blue-700 border border-blue-300 px-2.5 py-1 rounded-lg text-xs font-bold inline-flex items-center gap-1"><i class="fas fa-edit"></i> EDIT</span>';
                        } else if (log.action_type === 'DELETE') {
                            badge = '<span class="bg-rose-100 text-rose-700 border border-rose-300 px-2.5 py-1 rounded-lg text-xs font-bold inline-flex items-center gap-1"><i class="fas fa-trash-alt"></i> DELETE</span>';
                        } else {
                            badge = `<span class="bg-gray-100 text-gray-700 px-2 py-0.5 rounded text-xs font-bold">${log.action_type}</span>`;
                        }

                        let qtyDisplay = '-';
                        if (log.action_type === 'EDIT') {
                            if (log.old_qty != log.new_qty) {
                                qtyDisplay = `<div class="font-bold"><span class="text-gray-500">${Number(log.old_qty)}</span> <i class="fas fa-arrow-right text-[10px] text-gray-400 mx-1"></i> <span class="text-indigo-600 font-extrabold text-sm">${Number(log.new_qty)}</span></div>`;
                            } else {
                                qtyDisplay = `<span class="text-gray-500 text-xs">${Number(log.new_qty)} (คงเดิม)</span>`;
                            }
                        } else if (log.action_type === 'ADD') {
                            qtyDisplay = `<span class="text-emerald-600 font-bold">+${Number(log.new_qty)}</span>`;
                        } else if (log.action_type === 'DELETE') {
                            qtyDisplay = `<span class="text-rose-600 font-bold">${Number(log.old_qty)} (ลบ)</span>`;
                        }

                        rowsHtml += `
                            <tr class="border-b border-gray-100 hover:bg-gray-50/80 text-xs">
                                <td class="p-3 text-center text-gray-400">${idx + 1}</td>
                                <td class="p-3 font-mono text-gray-600 whitespace-nowrap">${log.created_at}</td>
                                <td class="p-3 whitespace-nowrap text-center">${badge}</td>
                                <td class="p-3">
                                    <div class="font-bold text-gray-800">${log.supply_name || '-'}</div>
                                    <div class="text-[11px] text-gray-400 font-mono">รหัส: ${log.supply_code || '-'}</div>
                                </td>
                                <td class="p-3 text-center whitespace-nowrap">${qtyDisplay}</td>
                                <td class="p-3 whitespace-nowrap">
                                    <div class="font-bold text-gray-700 flex items-center gap-1">
                                        <i class="fas fa-user-circle text-gray-400"></i> ${log.user_name || '-'}
                                    </div>
                                    <div class="text-[10px] text-gray-400">${log.user_role || '-'}</div>
                                </td>
                            </tr>
                        `;
                    });
                }

                const modalHtml = `
                    <div class="font-[Sarabun] text-left">
                        <div class="p-4 bg-gradient-to-r from-slate-800 to-indigo-900 rounded-t-2xl text-white flex items-center justify-between shadow-md">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-white/10 rounded-xl flex items-center justify-center text-lg border border-white/20">
                                    <i class="fas fa-history text-amber-300"></i>
                                </div>
                                <div>
                                    <h3 class="font-bold text-base">ประวัติการจัดการวัสดุและปรับปรุงสต็อก</h3>
                                    <p class="text-xs text-slate-300">บันทึกประวัติการกระทำ ADD, EDIT, DELETE และผู้ทำรายการอย่างละเอียด</p>
                                </div>
                            </div>
                            <span class="bg-white/20 px-3 py-1 rounded-full text-xs font-bold border border-white/20">${logs.length} รายการล่าสุด</span>
                        </div>
                        <div class="p-4 max-h-[500px] overflow-y-auto">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="bg-slate-100 text-slate-600 text-xs font-bold border-b border-slate-200">
                                        <th class="p-3 text-center w-10">#</th>
                                        <th class="p-3 whitespace-nowrap">วัน-เวลา</th>
                                        <th class="p-3 text-center whitespace-nowrap">การกระทำ</th>
                                        <th class="p-3">รายการวัสดุ</th>
                                        <th class="p-3 text-center whitespace-nowrap">ยอดคงเหลือ</th>
                                        <th class="p-3 whitespace-nowrap">ผู้ดำเนินการ</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    ${rowsHtml}
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;

                Swal.fire({
                    html: modalHtml,
                    width: '1000px',
                    showConfirmButton: true,
                    confirmButtonText: 'ปิดหน้าต่าง',
                    confirmButtonColor: '#64748b',
                    customClass: {
                        popup: 'rounded-2xl shadow-2xl p-0 overflow-hidden',
                        confirmButton: 'rounded-xl px-8 py-2.5 font-bold text-sm mb-4 mt-2'
                    }
                });
            } catch (e) {
                Swal.fire('ข้อผิดพลาด', e.message || 'ไม่สามารถโหลดประวัติได้', 'error');
            }
        }


        // Connect to Real-time Node Server dynamically using the host's hostname
        const socket = io('http://' + window.location.hostname + ':3000');

        socket.on('connect', () => {
            console.log('Connected to Real-time Stock Coordinator (supplies page)');
        });

        // Listen for supply changes (insert/update/delete)
        socket.on('supply_change', (data) => {
            if (!Swal.isVisible()) {
                location.reload();
            } else {
                Swal.fire({
                    icon: 'info',
                    title: 'คลังวัสดุมีการปรับปรุง',
                    text: 'ข้อมูลคลังวัสดุถูกแก้ไขโดยเครื่องอื่น กรุณารีเฟรชเพื่อแสดงผลล่าสุด',
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: true,
                    confirmButtonText: 'รีเฟรช',
                    timer: 10000
                }).then((result) => {
                    if (result.isConfirmed) {
                        location.reload();
                    }
                });
            }
        });

        // Listen for stock updates (checkout/approvals)
        socket.on('stock_update', (data) => {
            if (data && data.items) {
                data.items.forEach(item => {
                    const cell = $(`.qty-cell[data-id="${item.id}"]`);
                    if (cell.length) {
                        cell.text(item.qty);
                        const minStock = parseInt(cell.attr('data-min-stock')) || 0;
                        if (item.qty <= minStock) {
                            cell.removeClass('text-green-600').addClass('text-red-500');
                        } else {
                            cell.removeClass('text-red-500').addClass('text-green-600');
                        }
                        
                        // Apply pulse visual effect
                        cell.addClass('text-amber-500 scale-105');
                        setTimeout(() => {
                            cell.removeClass('text-amber-500 scale-105');
                        }, 2000);
                    }
                });
            }
        });
    </script>
    </main>
</body>

</html>