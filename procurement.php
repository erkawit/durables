<?php
session_start();
require_once 'config.php';
// require_once 'config2.php';  // Old database connection has been disabled to prevent error

// Access Check
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'supply_head', 'supply_officer'])) {
    header("Location: dashboard.php");
    exit;
}

// Fetch Active Supplies for Search
$suppliesJson = [];
$stmt = $pdo->query("SELECT id, code, name, category, unit, qty FROM supplies WHERE status = 'active'");
$supplies = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($supplies as $s) {
    // create a searchable label
    $s['label'] = $s['code'] . ' - ' . $s['name'];
    $suppliesJson[] = $s;
}

// Fetch Units from type_volume table (from old database)
try {
    // Query from current durables_db
    $unitsStmt = $pdo->query("SELECT id, type_volume FROM type_volume ORDER BY type_volume");
    $units = $unitsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Fallback to default units if type_volume table is not accessible
    $units = [
        ['id' => 1, 'type_volume' => 'ชิ้น'],
        ['id' => 2, 'type_volume' => 'แผ่น'],
        ['id' => 3, 'type_volume' => 'เล่ม'],
        ['id' => 4, 'type_volume' => 'กล่อง'],
        ['id' => 5, 'type_volume' => 'ห่อ']
    ];
}

// Handle Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'submit_procurement') {
    try {
        $pdo->beginTransaction();

        $receipt_no = $_POST['receipt_no'];
        $supplier = $_POST['supplier']; // Location
        $items = json_decode($_POST['items'], true);

        // 1. Insert Procurement Header
        $stmt = $pdo->prepare("INSERT INTO procurements (receipt_no, supplier_name, importer_id, import_date) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$receipt_no, $supplier, $_SESSION['user_id']]);
        $procurement_id = $pdo->lastInsertId();

        // 2. Process Items
        foreach ($items as $item) {
            $supply_id = null;

            if ($item['type'] == 'new') {
                // Insert New Supply
                // Check if code exists? (Skip for now or just insert)
                $stmtSup = $pdo->prepare("INSERT INTO supplies (code, name, category, unit, min_stock, qty, status) VALUES (?, ?, ?, ?, ?, 0, 'active')");
                $stmtSup->execute([
                    $item['code'],
                    $item['name'],
                    $item['category'],
                    $item['unit'],
                    $item['min_stock']
                ]);
                $supply_id = $pdo->lastInsertId();
            } else {
                // Existing Supply
                $supply_id = $item['id'];
            }

            // Fetch price from supplies
            $stmtPrice = $pdo->prepare("SELECT price FROM supplies WHERE id = ?");
            $stmtPrice->execute([$supply_id]);
            $supplyData = $stmtPrice->fetch(PDO::FETCH_ASSOC);
            $supplyPrice = ($supplyData && isset($supplyData['price'])) ? floatval($supplyData['price']) : 0.00;
            $priceAll = $supplyPrice * $item['qty'];

            // Insert Procurement Item
            $stmtItem = $pdo->prepare("INSERT INTO procurement_items (procurement_id, supply_id, qty, unit_price) VALUES (?, ?, ?, ?)");
            $stmtItem->execute([$procurement_id, $supply_id, $item['qty'], $supplyPrice]);

            // Update Stock
            $stmtUpdate = $pdo->prepare("UPDATE supplies SET qty = qty + ? WHERE id = ?");
            $stmtUpdate->execute([$item['qty'], $supply_id]);
        }

        $pdo->commit();
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดซื้อ/รับเข้าวัสดุ - ระบบคลังพัสดุ</title>
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
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Select2 for Search -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Sarabun', sans-serif;
            background: #f9fafb;
            min-height: 100vh;
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .select2-container .select2-selection--single {
            height: 44px !important;
            border-radius: 0.75rem !important;
            border: 2px solid #e5e7eb !important;
            display: flex;
            align-items: center;
            transition: all 0.3s ease;
        }

        .select2-container--default .select2-selection--single:focus {
            border-color: #0ea5e9 !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 40px !important;
            padding-left: 12px !important;
        }

        .gradient-btn {
            background: linear-gradient(135deg, #17365a 0%, #112A46 100%);
            transition: all 0.3s ease;
        }

        .gradient-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(17, 42, 70, 0.3);
        }



        .tab-btn {
            transition: all 0.3s ease;
        }

        .tab-btn.active {
            background: linear-gradient(135deg, #17365a 0%, #112A46 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(17, 42, 70, 0.3);
        }

        .info-card {
            background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
            border: 2px solid #7dd3fc;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-fade-in {
            animation: fadeInUp 0.5s ease-out;
        }

        .delete-btn {
            transition: all 0.2s ease;
        }

        .delete-btn:hover {
            transform: scale(1.2);
        }

        .instruction-box {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-left: 4px solid #f59e0b;
        }
    </style>
</head>

<body class="bg-gray-50 flex h-screen overflow-hidden">

    <?php $page = 'procurement'; include 'sidebar.php'; ?>

    <main class="flex-1 flex flex-col overflow-hidden relative">

    <!-- Header -->
    <header class="bg-white shadow-md px-6 py-4 sticky top-0 z-50 border-b border-gray-100 shrink-0">
        <div class="flex items-center gap-4">
            <a href="dashboard.php" class="text-gray-500 hover:text-sky-600 transition-all duration-300">
                <i class="fas fa-arrow-left text-2xl"></i>
            </a>
            <div class="flex-1">
                <h1 class="text-2xl font-bold text-gray-800">
                    <i class="fas fa-shopping-cart mr-2 text-sky-500"></i>บันทึกการรับเข้าวัสดุ (Procurement)
                </h1>
                <p class="text-sm text-gray-500 mt-1">
                    <i class="fas fa-info-circle mr-1"></i>จัดการรายการรับเข้าวัสดุใหม่และวัสดุเดิม
                </p>
            </div>
        </div>
    </header>

    <!-- Main Scrollable Content -->
    <div class="flex-1 overflow-y-auto p-6">
        <div class="w-full space-y-6 animate-fade-in">

            <!-- Document Info Card -->
            <div class="glass-card rounded-2xl shadow-lg p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-file-invoice text-sky-500"></i> ข้อมูลเอกสาร
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-600 mb-1">
                            <i class="fas fa-receipt mr-1 text-sky-400"></i> เลขที่ใบเสร็จ / เอกสารอ้างอิง
                        </label>
                        <input type="text" id="receipt_no" placeholder="เช่น INV-2026-001"
                            class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:border-sky-400 focus:ring-2 focus:ring-sky-100 outline-none transition-all text-gray-700 font-medium">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-600 mb-1">
                            <i class="fas fa-store mr-1 text-sky-400"></i> สถานที่ซื้อ / ผู้จำหน่าย
                        </label>
                        <input type="text" id="supplier" placeholder="เช่น ร้าน ABC / บริษัท XYZ"
                            class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:border-sky-400 focus:ring-2 focus:ring-sky-100 outline-none transition-all text-gray-700 font-medium">
                    </div>
                </div>
            </div>

            <!-- Add Items Section -->
            <div class="glass-card rounded-2xl shadow-lg p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-boxes text-sky-500"></i> เพิ่มรายการวัสดุ
                </h3>

                <!-- Tab Buttons -->
                <div class="flex gap-2 mb-6">
                    <button id="btn-existing" onclick="setTab('existing')"
                        class="tab-btn active px-6 py-2.5 rounded-xl text-sm font-bold flex items-center gap-2 border border-gray-200">
                        <i class="fas fa-search"></i> ค้นหาวัสดุเดิม
                    </button>
                    <button id="btn-new" onclick="setTab('new')"
                        class="tab-btn text-gray-500 px-6 py-2.5 rounded-xl text-sm font-bold flex items-center gap-2 border border-gray-200 hover:bg-gray-50">
                        <i class="fas fa-plus-circle"></i> เพิ่มวัสดุใหม่
                    </button>
                </div>

                <!-- Existing Supply Form -->
                <div id="form-existing" class="space-y-4">
                    <div class="instruction-box rounded-xl p-4 mb-4">
                        <p class="text-sm text-amber-800 font-medium">
                            <i class="fas fa-lightbulb mr-1"></i>
                            พิมพ์รหัสหรือชื่อวัสดุเพื่อค้นหาจากระบบ แล้วระบุจำนวนที่รับเข้า
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-600 mb-1">
                            <i class="fas fa-search mr-1 text-sky-400"></i> ค้นหาวัสดุ
                        </label>
                        <select id="select-supply" class="w-full">
                            <option></option>
                        </select>
                    </div>

                    <!-- Selected Supply Info -->
                    <div id="existing-info" class="hidden info-card rounded-xl p-4 mt-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-4">
                                <div class="w-12 h-12 bg-white rounded-xl flex items-center justify-center shadow-sm">
                                    <i class="fas fa-cube text-sky-500 text-xl"></i>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500">รหัสวัสดุ: <span id="info-code" class="font-bold text-sky-700"></span></p>
                                    <p class="text-sm text-gray-500">คงเหลือ: <span id="info-qty" class="font-bold text-sky-700"></span> <span id="info-unit" class="text-gray-400"></span></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                        <div class="md:col-span-2">
                            <label class="block text-sm font-semibold text-gray-600 mb-1">
                                <i class="fas fa-sort-numeric-up mr-1 text-sky-400"></i> จำนวนที่รับเข้า
                            </label>
                            <input type="number" id="qty-existing" min="1" placeholder="ระบุจำนวน"
                                class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:border-sky-400 focus:ring-2 focus:ring-sky-100 outline-none transition-all text-gray-700 font-bold text-lg">
                        </div>
                        <div>
                            <button onclick="addExisting()"
                                class="gradient-btn w-full text-white px-6 py-3 rounded-xl font-bold text-sm flex items-center justify-center gap-2 shadow-lg">
                                <i class="fas fa-plus-circle"></i> เพิ่มลงตะกร้า
                            </button>
                        </div>
                    </div>
                </div>

                <!-- New Supply Form -->
                <div id="form-new" class="hidden space-y-4">
                    <div class="instruction-box rounded-xl p-4 mb-4">
                        <p class="text-sm text-amber-800 font-medium">
                            <i class="fas fa-lightbulb mr-1"></i>
                            กรอกข้อมูลวัสดุใหม่ที่ยังไม่มีในระบบ ระบบจะสร้างรายการใหม่ให้อัตโนมัติ
                        </p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-600 mb-1">
                                <i class="fas fa-barcode mr-1 text-sky-400"></i> รหัสวัสดุ <span class="text-red-500">*</span>
                            </label>
                            <input type="text" id="new-code" placeholder="เช่น SUP-001"
                                class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:border-sky-400 focus:ring-2 focus:ring-sky-100 outline-none transition-all text-gray-700 font-medium">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-600 mb-1">
                                <i class="fas fa-tag mr-1 text-sky-400"></i> ชื่อวัสดุ <span class="text-red-500">*</span>
                            </label>
                            <input type="text" id="new-name" placeholder="ชื่อวัสดุ"
                                class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:border-sky-400 focus:ring-2 focus:ring-sky-100 outline-none transition-all text-gray-700 font-medium">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-600 mb-1">
                                <i class="fas fa-folder mr-1 text-sky-400"></i> หมวดหมู่
                            </label>
                            <input type="text" id="new-cat" placeholder="เช่น อุปกรณ์สำนักงาน"
                                class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:border-sky-400 focus:ring-2 focus:ring-sky-100 outline-none transition-all text-gray-700 font-medium">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-600 mb-1">
                                <i class="fas fa-ruler mr-1 text-sky-400"></i> หน่วยนับ
                            </label>
                            <select id="new-unit"
                                class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:border-sky-400 focus:ring-2 focus:ring-sky-100 outline-none transition-all text-gray-700 font-medium bg-white">
                                <?php foreach ($units as $u): ?>
                                    <option value="<?php echo htmlspecialchars($u['type_volume']); ?>"><?php echo htmlspecialchars($u['type_volume']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-600 mb-1">
                                <i class="fas fa-exclamation-triangle mr-1 text-amber-400"></i> จุดสั่งซื้อขั้นต่ำ
                            </label>
                            <input type="number" id="new-min" value="10" min="1"
                                class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:border-sky-400 focus:ring-2 focus:ring-sky-100 outline-none transition-all text-gray-700 font-bold">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-600 mb-1">
                                <i class="fas fa-sort-numeric-up mr-1 text-sky-400"></i> จำนวนที่รับเข้า <span class="text-red-500">*</span>
                            </label>
                            <input type="number" id="new-qty" min="1" placeholder="ระบุจำนวน"
                                class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:border-sky-400 focus:ring-2 focus:ring-sky-100 outline-none transition-all text-gray-700 font-bold text-lg">
                        </div>
                    </div>

                    <div class="flex justify-end mt-2">
                        <button onclick="addNew()"
                            class="gradient-btn text-white px-8 py-3 rounded-xl font-bold text-sm flex items-center gap-2 shadow-lg">
                            <i class="fas fa-plus-circle"></i> เพิ่มวัสดุใหม่ลงตะกร้า
                        </button>
                    </div>
                </div>
            </div>

            <!-- Cart / Basket Table -->
            <div class="glass-card rounded-2xl shadow-lg p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                        <i class="fas fa-shopping-basket text-sky-500"></i> ตะกร้ารับเข้า
                        <span id="total-count" class="text-sm font-medium text-gray-400 ml-2">0 รายการ</span>
                    </h3>
                    <button id="btn-clear-cart" onclick="clearCart()" class="hidden items-center gap-2 px-4 py-2 rounded-xl text-sm font-bold text-red-500 bg-red-50 border border-red-200 hover:bg-red-100 hover:text-red-700 transition-all active:scale-95">
                        <i class="fas fa-broom"></i> ล้างตะกร้า
                    </button>
                </div>

                <div class="overflow-x-auto rounded-xl border border-gray-100">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="bg-gray-50/80 text-gray-500 text-xs uppercase tracking-wider font-semibold border-b border-gray-100">
                                <th class="py-4 px-4 rounded-l-xl">ประเภท</th>
                                <th class="py-4 px-4">รหัส</th>
                                <th class="py-4 px-4">ชื่อวัสดุ</th>
                                <th class="py-4 px-4 text-center">จำนวนรับเข้า</th>
                                <th class="py-4 px-4 text-center rounded-r-xl">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody id="cart-list" class="divide-y divide-gray-50">
                        </tbody>
                    </table>

                    <!-- Empty State -->
                    <div id="empty-state" class="flex flex-col items-center justify-center py-16 text-center">
                        <div class="w-20 h-20 bg-gray-100 rounded-full flex items-center justify-center mb-4">
                            <i class="fas fa-inbox text-4xl text-gray-300"></i>
                        </div>
                        <h4 class="text-gray-400 font-bold text-lg">ยังไม่มีรายการ</h4>
                        <p class="text-gray-300 text-sm mt-1">เพิ่มวัสดุจากด้านบนเพื่อเริ่มต้น</p>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="flex justify-end mt-6">
                    <button onclick="submitProcurement()"
                        class="gradient-btn text-white px-10 py-3.5 rounded-xl font-bold text-base flex items-center gap-3 shadow-xl hover:shadow-2xl transition-all">
                        <i class="fas fa-save text-lg"></i> บันทึกการรับเข้า
                    </button>
                </div>
            </div>

        </div>
    </div>

    <script>
        const allSupplies = <?php echo json_encode($suppliesJson); ?>;
        const currentUserId = <?php echo json_encode($_SESSION['user_id'] ?? ''); ?>;
        const storageKey = 'procurement_draft_' + currentUserId;
        let cart = [];
        let selectedExisting = null;

        // Load draft from localStorage
        try {
            const savedDraft = localStorage.getItem(storageKey);
            if (savedDraft) {
                const draft = JSON.parse(savedDraft);
                cart = draft.cart || [];
                $(document).ready(function () {
                    if (draft.receipt_no) $('#receipt_no').val(draft.receipt_no);
                    if (draft.supplier) $('#supplier').val(draft.supplier);
                    if (cart.length > 0) renderCart();
                });
            }
        } catch (e) {
            console.error("Failed to load draft", e);
        }

        function saveDraft() {
            const draft = {
                receipt_no: $('#receipt_no').val(),
                supplier: $('#supplier').val(),
                cart: cart
            };
            localStorage.setItem(storageKey, JSON.stringify(draft));
        }

        $(document).ready(function () {
            // Save draft when typing
            $('#receipt_no, #supplier').on('input focusout change', saveDraft);

            // Initialize Select2
            $('#select-supply').select2({
                data: [{id: '', text: ''}].concat(allSupplies.map(s => ({ id: s.id, text: s.label }))),
                placeholder: 'เลือกรายการวัสดุ',
                allowClear: true,
                width: '100%'
            });

            $('#select-supply').on('select2:select', function (e) {
                let id = e.params.data.id;
                selectedExisting = allSupplies.find(s => s.id == id);
                if (selectedExisting) {
                    $('#existing-info').removeClass('hidden');
                    $('#info-code').text(selectedExisting.code);
                    $('#info-qty').text(selectedExisting.qty);
                    $('#info-unit').text(selectedExisting.unit);
                    $('#qty-existing').val('').focus();
                }
            });

            $('#select-supply').on('select2:clear', function (e) {
                selectedExisting = null;
                $('#existing-info').addClass('hidden');
            });
        });

        function setTab(tab) {
            if (tab === 'existing') {
                $('#form-existing').removeClass('hidden');
                $('#form-new').addClass('hidden');
                $('#btn-existing').addClass('active').removeClass('text-gray-500');
                $('#btn-new').removeClass('active').addClass('text-gray-500');
            } else {
                $('#form-existing').addClass('hidden');
                $('#form-new').removeClass('hidden');
                $('#btn-new').addClass('active').removeClass('text-gray-500');
                $('#btn-existing').removeClass('active').addClass('text-gray-500');
            }
        }

        function addExisting() {
            if (!selectedExisting) {
                return Swal.fire({
                    icon: 'warning',
                    title: 'แจ้งเตือน',
                    text: 'กรุณาเลือกวัสดุจากรายการ',
                    confirmButtonColor: '#0ea5e9'
                });
            }

            let qty = parseInt($('#qty-existing').val());
            if (!qty || qty <= 0) {
                return Swal.fire({
                    icon: 'warning',
                    title: 'แจ้งเตือน',
                    text: 'กรุณาระบุจำนวนที่ถูกต้อง (มากกว่า 0)',
                    confirmButtonColor: '#0ea5e9'
                });
            }

            cart.push({
                type: 'existing',
                id: selectedExisting.id,
                code: selectedExisting.code,
                name: selectedExisting.name,
                qty: qty
            });

            // Reset form
            $('#qty-existing').val('');
            $('#select-supply').val(null).trigger('change');
            selectedExisting = null;
            $('#existing-info').addClass('hidden');

            renderCart();
            saveDraft();

            Swal.fire({
                position: 'top-end',
                icon: 'success',
                title: 'เพิ่มรายการสำเร็จ',
                showConfirmButton: false,
                timer: 1500,
                toast: true
            });
        }

        function addNew() {
            let code = $('#new-code').val().trim();
            let name = $('#new-name').val().trim();
            let cat = $('#new-cat').val().trim();
            let unit = $('#new-unit').val().trim();
            let min = parseInt($('#new-min').val()) || 10;
            let qty = parseInt($('#new-qty').val());

            if (!code || !name || !qty) {
                return Swal.fire({
                    icon: 'warning',
                    title: 'ข้อมูลไม่ครบถ้วน',
                    text: 'กรุณากรอก รหัส, ชื่อ และจำนวน',
                    confirmButtonColor: '#10b981'
                });
            }

            if (qty <= 0) {
                return Swal.fire({
                    icon: 'warning',
                    title: 'จำนวนไม่ถูกต้อง',
                    text: 'กรุณาระบุจำนวนที่มากกว่า 0',
                    confirmButtonColor: '#10b981'
                });
            }

            cart.push({
                type: 'new',
                code: code,
                name: name,
                category: cat || 'ทั่วไป',
                unit: unit || 'ชิ้น',
                min_stock: min,
                qty: qty
            });

            // Reset form
            $('#new-code').val('');
            $('#new-name').val('');
            $('#new-cat').val('');
            $('#new-unit').val('');
            $('#new-min').val('10');
            $('#new-qty').val('');

            renderCart();
            saveDraft();

            Swal.fire({
                position: 'top-end',
                icon: 'success',
                title: 'สร้างวัสดุใหม่สำเร็จ',
                showConfirmButton: false,
                timer: 1500,
                toast: true
            });
        }

        function renderCart() {
            let html = '';
            cart.forEach((item, index) => {
                let badge = item.type === 'new'
                    ? '<span class="bg-gradient-to-r from-sky-400 to-sky-500 text-white px-3 py-1 rounded-full text-xs font-bold shadow-sm"><i class="fas fa-plus-circle mr-1"></i>ใหม่</span>'
                    : '<span class="bg-gradient-to-r from-sky-600 to-sky-700 text-white px-3 py-1 rounded-full text-xs font-bold shadow-sm"><i class="fas fa-archive mr-1"></i>เดิม</span>';

                html += `
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="py-4 px-4">${badge}</td>
                        <td class="py-4 px-4 font-mono text-gray-600 font-semibold">${item.code}</td>
                        <td class="py-4 px-4 font-medium text-gray-800">${item.name}</td>
                        <td class="py-4 px-4 text-center">
                            <span class="bg-sky-100 text-sky-700 px-3 py-1 rounded-lg font-bold">
                                +${item.qty}
                            </span>
                        </td>
                        <td class="py-4 px-4 text-center">
                            <button onclick="removeCart(${index})" 
                                class="delete-btn text-red-500 hover:text-red-700 hover:bg-red-50 p-2 rounded-lg"
                                title="ลบรายการนี้">
                                <i class="fas fa-trash-alt text-lg"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });

            $('#cart-list').html(html);
            $('#total-count').text(cart.length + ' รายการ');

            if (cart.length > 0) {
                $('#empty-state').addClass('hidden');
                $('#btn-clear-cart').removeClass('hidden').addClass('flex');
            } else {
                $('#empty-state').removeClass('hidden');
                $('#btn-clear-cart').addClass('hidden').removeClass('flex');
            }
        }

        function clearCart() {
            if (cart.length === 0) return;
            Swal.fire({
                title: 'ล้างตะกร้าทั้งหมด?',
                text: 'ต้องการลบรายการทั้งหมด (' + cart.length + ' รายการ) ออกจากตะกร้าหรือไม่?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280',
                confirmButtonText: '<i class="fas fa-broom mr-1"></i> ล้างทั้งหมด',
                cancelButtonText: 'ยกเลิก',
                customClass: {
                    popup: 'rounded-2xl',
                    confirmButton: 'rounded-xl',
                    cancelButton: 'rounded-xl'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    cart = [];
                    renderCart();
                    saveDraft();
                    Swal.fire({
                        position: 'top-end',
                        icon: 'success',
                        title: 'ล้างตะกร้าเรียบร้อย',
                        showConfirmButton: false,
                        timer: 1500,
                        toast: true
                    });
                }
            });
        }

        function removeCart(index) {
            Swal.fire({
                title: 'ยืนยันการลบ?',
                text: `ต้องการลบ "${cart[index].name}" ออกจากตะกร้า?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280',
                confirmButtonText: '<i class="fas fa-trash mr-1"></i> ลบ',
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    cart.splice(index, 1);
                    renderCart();
                    saveDraft();
                    Swal.fire({
                        position: 'top-end',
                        icon: 'success',
                        title: 'ลบรายการแล้ว',
                        showConfirmButton: false,
                        timer: 1000,
                        toast: true
                    });
                }
            });
        }

        function submitProcurement() {
            if (cart.length === 0) {
                return Swal.fire({
                    icon: 'warning',
                    title: 'ไม่มีรายการ',
                    text: 'กรุณาเพิ่มรายการสินค้าก่อนบันทึก',
                    confirmButtonColor: '#0ea5e9'
                });
            }

            let receipt = $('#receipt_no').val().trim();
            let supplier = $('#supplier').val().trim();

            if (!receipt || !supplier) {
                return Swal.fire({
                    icon: 'warning',
                    title: 'ข้อมูลไม่ครบถ้วน',
                    text: 'กรุณากรอกเลขที่ใบเสร็จและสถานที่ซื้อ',
                    confirmButtonColor: '#0ea5e9'
                });
            }

            Swal.fire({
                title: 'ยืนยันการบันทึก?',
                html: `
                    <div class="text-left space-y-2 mt-4">
                        <p><strong>เลขที่เอกสาร:</strong> ${receipt}</p>
                        <p><strong>สถานที่:</strong> ${supplier}</p>
                        <p><strong>จำนวนรายการ:</strong> ${cart.length} รายการ</p>
                    </div>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#0ea5e9',
                cancelButtonColor: '#6b7280',
                confirmButtonText: '<i class="fas fa-check mr-1"></i> ยืนยันบันทึก',
                cancelButtonText: 'ยกเลิก',
                showLoaderOnConfirm: true,
                preConfirm: () => {
                    return $.post('procurement.php', {
                        action: 'submit_procurement',
                        receipt_no: receipt,
                        supplier: supplier,
                        items: JSON.stringify(cart)
                    }).then(response => {
                        return JSON.parse(response);
                    }).catch(error => {
                        Swal.showValidationMessage(`Request failed: ${error}`);
                    });
                },
                allowOutsideClick: () => !Swal.isLoading()
            }).then((result) => {
                if (result.isConfirmed) {
                    if (result.value.status === 'success') {
                        localStorage.removeItem(storageKey); // Clear draft on success
                        Swal.fire({
                            icon: 'success',
                            title: 'บันทึกสำเร็จ!',
                            text: 'ระบบได้อัปเดตข้อมูลเรียบร้อยแล้ว',
                            confirmButtonColor: '#10b981'
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'เกิดข้อผิดพลาด',
                            text: result.value.message || 'ไม่สามารถบันทึกข้อมูลได้',
                            confirmButtonColor: '#ef4444'
                        });
                    }
                }
            });
        }
    </script>
</main>
</body>

</html>