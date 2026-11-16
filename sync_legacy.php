<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'supply_head', 'supply_officer'])) {
    header("Location: dashboard.php");
    exit;
}

// ── AJAX handlers ─────────────────────────────────────────────────────────────
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['action'];

    // ── Price comparison ──────────────────────────────────────────────────────
    if ($action === 'get_price_compare') {
        $sql = "SELECT s.id, s.code, s.name, s.unit,
                       COALESCE(s.price, 0)          AS new_price,
                       p.product_price               AS old_price,
                       p.product_img
                FROM durables_db.supplies s
                LEFT JOIN durables_db.product p ON CAST(p.product_code AS CHAR) = s.code
                WHERE s.status = 'active'
                ORDER BY s.code + 0";
        $rows = $pdo->query($sql)->fetchAll();
        echo json_encode(['status' => 'ok', 'rows' => $rows]);
        exit;
    }

    // ── Image preview ─────────────────────────────────────────────────────────
    if ($action === 'get_image_preview') {
        $sql = "SELECT s.id, s.code, s.name,
                       COALESCE(s.image,'')          AS current_image,
                       p.product_img                 AS old_img
                FROM durables_db.supplies s
                LEFT JOIN durables_db.product p ON CAST(p.product_code AS CHAR) = s.code
                WHERE s.status = 'active'
                  AND p.product_img IS NOT NULL AND p.product_img != ''
                ORDER BY s.code + 0";
        $rows = $pdo->query($sql)->fetchAll();
        echo json_encode(['status' => 'ok', 'rows' => $rows]);
        exit;
    }

    // ── Apply image sync ──────────────────────────────────────────────────────
    if ($action === 'sync_images') {
        $sql = "UPDATE durables_db.supplies s
                JOIN durables_db.product p ON CAST(p.product_code AS CHAR) = s.code
                SET s.image = CONCAT('img/img_app_4/', p.product_img)
                WHERE p.product_img IS NOT NULL AND p.product_img != ''
                  AND s.status = 'active'";
        $cnt = $pdo->exec($sql);
        echo json_encode(['status' => 'ok', 'updated' => $cnt]);
        exit;
    }

    // ── Withdraw stats (modified for Carryover Stock sync) ──────────────────────
    if ($action === 'get_withdraw_stats') {
        // Fetch if already imported carryover
        $importedInfo = $pdo->query(
            "SELECT p.import_date, CONCAT(COALESCE(u.prefix,''), COALESCE(u.firstname,''), ' ', COALESCE(u.lastname,'')) AS importer_name, p.receipt_no
             FROM durables_db.procurements p
             LEFT JOIN durables_db.users u ON u.id = p.importer_id
             WHERE p.supplier_name = 'ยกยอด' OR p.receipt_no = 'ยกยอดตั้งต้น'
             ORDER BY p.id DESC LIMIT 1"
        )->fetch();

        // Fetch supplies with legacy withdrawals
        $sql = "SELECT s.id, s.code, s.name, s.unit, s.qty AS current_qty, s.price,
                       COALESCE(w.total_withdrawn, 0) AS legacy_withdrawn
                FROM durables_db.supplies s
                LEFT JOIN (
                    SELECT product_code, SUM(withdraw_volume) AS total_withdrawn
                    FROM durables_db.withdraw
                    WHERE withdraw_status = '5'
                    GROUP BY product_code
                ) w ON CAST(w.product_code AS CHAR) = s.code
                WHERE s.status = 'active'
                ORDER BY s.code + 0";
        $rows = $pdo->query($sql)->fetchAll();

        echo json_encode([
            'status'        => 'ok',
            'imported_info' => $importedInfo ? $importedInfo : null,
            'rows'          => $rows,
        ]);
        exit;
    }

    // ── Run carryover import ───────────────────────────────────────────────────
    if ($action === 'import_withdraw') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
            exit;
        }

        $items = [];
        if (isset($_POST['items'])) {
            $items = json_decode($_POST['items'], true);
        } else {
            $rawInput = file_get_contents('php://input');
            $jsonData = json_decode($rawInput, true);
            if (isset($jsonData['items'])) {
                $items = $jsonData['items'];
            }
        }

        if (empty($items)) {
            echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูลวัสดุที่ต้องการนำเข้า']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            // 1. Insert Procurement Header
            $stmt = $pdo->prepare("INSERT INTO durables_db.procurements (receipt_no, supplier_name, importer_id, import_date) VALUES (?, ?, ?, NOW())");
            $stmt->execute(['ยกยอดตั้งต้น', 'ยกยอด', $_SESSION['user_id']]);
            $procurement_id = (int)$pdo->lastInsertId();

            $insertItem = $pdo->prepare(
                "INSERT INTO durables_db.procurement_items (procurement_id, supply_id, qty, unit_price) VALUES (?, ?, ?, ?)"
            );
            $updateSupply = $pdo->prepare(
                "UPDATE durables_db.supplies SET qty = ? WHERE id = ?"
            );
            $getSupplyPrice = $pdo->prepare(
                "SELECT price FROM durables_db.supplies WHERE id = ?"
            );

            $imported = 0;
            foreach ($items as $item) {
                $supplyId = (int)$item['id'];
                $qty = (int)$item['qty'];
                if ($qty < 0) $qty = 0;

                // Fetch current price for this supply
                $getSupplyPrice->execute([$supplyId]);
                $price = (float)$getSupplyPrice->fetchColumn();

                // Insert into procurement_items
                $insertItem->execute([$procurement_id, $supplyId, $qty, $price]);

                // Update supplies quantity
                $updateSupply->execute([$qty, $supplyId]);
                $imported++;
            }

            $pdo->commit();
            echo json_encode(['status' => 'ok', 'imported' => $imported]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ซิงค์ข้อมูลจากระบบเดิม</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        sky: {
                            50:'#f4f6f8', 100:'#e1e5ec', 200:'#c4ccd9',
                            300:'#9fabbe', 400:'#7587a1', 500:'#17365a',
                            600:'#112A46', 700:'#0c1e32', 800:'#081421', 900:'#040a11'
                        }
                    }
                }
            }
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        (function() {
            function wrapSwal(swalObj) {
                if (!swalObj || !swalObj.fire || swalObj._wrapped) return;
                const originalFire = swalObj.fire;
                swalObj.fire = function(...args) {
                    let opts = args[0];
                    if (opts && typeof opts === 'object') {
                        if (opts.icon === 'success' || opts.status === 'success') {
                            opts.showConfirmButton = false;
                            opts.timer = 1000;
                        }
                        
                        let titleText = opts.title ? String(opts.title) : '';
                        let htmlText = opts.html ? String(opts.html) : '';
                        let isEdit = (titleText.includes('แก้ไข') || titleText.includes('เพิ่ม') || titleText.includes('จัดการ') || htmlText.includes('<input') || htmlText.includes('<select') || htmlText.includes('<textarea')) && !titleText.includes('เปลี่ยนรหัสผ่าน');
                        if (isEdit) {
                            opts.width = '80%';
                            let originalDidOpen = opts.didOpen;
                            opts.didOpen = function(popupEl) {
                                if (popupEl) {
                                    popupEl.style.height = 'auto';
                                }
                                if (originalDidOpen) {
                                    originalDidOpen.apply(this, arguments);
                                }
                            };
                        }
                    } else if (typeof opts === 'string' && args[2] === 'success') {
                        return originalFire.call(this, {
                            title: opts,
                            text: args[1] || '',
                            icon: 'success',
                            showConfirmButton: false,
                            timer: 1000
                        });
                    }
                    return originalFire.apply(this, args);
                };
                swalObj._wrapped = true;
            }
            if (window.Swal) wrapSwal(window.Swal);
        })();
    </script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <style>
        body { font-family: 'Sarabun', sans-serif; animation: pgIn .3s ease both; }
        @keyframes pgIn { from{opacity:0} to{opacity:1} }
        body.pg-exit { opacity:0!important; transition:opacity .12s ease; pointer-events:none }
        .tab-btn { transition: all .2s; }
        .tab-btn.active { background:#17365a; color:#fff; }
        .tab-pane { display:none; }
        .tab-pane.active { display:block; }
        .dataTables_wrapper .dataTables_filter input,
        .dataTables_wrapper .dataTables_length select { border:1px solid #e2e8f0; padding:.4rem .6rem; border-radius:.5rem; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">

<!-- Header -->
<header class="bg-white shadow px-6 py-4 flex justify-between items-center sticky top-0 z-10">
    <div class="flex items-center gap-4">
        <a href="supplies.php" class="text-gray-500 hover:text-sky-600 transition"><i class="fas fa-arrow-left text-xl"></i></a>
        <div>
            <h1 class="text-xl font-bold text-gray-800">ซิงค์ข้อมูลจากระบบเดิม</h1>
            <p class="text-xs text-gray-400 mt-0.5">เปรียบเทียบและนำเข้าข้อมูลจาก <span class="font-mono bg-gray-100 px-1 rounded">udtc_all_app</span></p>
        </div>
    </div>
    <span class="text-xs bg-violet-100 text-violet-700 px-3 py-1 rounded-full font-bold">
        <i class="fas fa-shield-alt mr-1"></i>เฉพาะผู้ดูแลระบบ
    </span>
</header>

<!-- Tabs -->
<div class="px-4 mt-4">
    <div class="flex gap-2 bg-white rounded-xl shadow-sm border border-gray-100 p-2 inline-flex">
        <button class="tab-btn active px-5 py-2 rounded-lg text-sm font-bold text-gray-600" onclick="switchTab('price')">
            <i class="fas fa-tags mr-1.5"></i> เปรียบเทียบราคา
        </button>
        <button class="tab-btn px-5 py-2 rounded-lg text-sm font-bold text-gray-600" onclick="switchTab('image')">
            <i class="fas fa-images mr-1.5"></i> ซิงค์รูปภาพ
        </button>
        <button class="tab-btn px-5 py-2 rounded-lg text-sm font-bold text-gray-600" onclick="switchTab('withdraw')">
            <i class="fas fa-history mr-1.5"></i> นำเข้าพัสดุยกยอดตั้งต้น
        </button>
    </div>
</div>

<main class="p-4 space-y-4">

<!-- ── Tab: Price Compare ─────────────────────────────────────────────────── -->
<div id="tab-price" class="tab-pane active">
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h2 class="text-lg font-bold text-gray-800"><i class="fas fa-tags text-sky-600 mr-2"></i>เปรียบเทียบราคาวัสดุ</h2>
                <p class="text-xs text-gray-400 mt-0.5">เปรียบเทียบราคาใน <span class="font-mono">durables_db.supplies.price</span> กับ <span class="font-mono">udtc_all_app.product.product_price</span></p>
            </div>
            <button onclick="loadPriceCompare()" class="bg-sky-500 hover:bg-sky-600 text-white px-4 py-2 rounded-lg text-sm font-bold transition">
                <i class="fas fa-sync-alt mr-1"></i> โหลดข้อมูล
            </button>
        </div>
        <div id="price-container">
            <div class="text-center py-12 text-gray-400">
                <i class="fas fa-tags text-4xl mb-3 block opacity-30"></i>
                คลิก "โหลดข้อมูล" เพื่อดูการเปรียบเทียบราคา
            </div>
        </div>
    </div>
</div>

<!-- ── Tab: Image Sync ────────────────────────────────────────────────────── -->
<div id="tab-image" class="tab-pane">
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h2 class="text-lg font-bold text-gray-800"><i class="fas fa-images text-emerald-600 mr-2"></i>ซิงค์รูปภาพจากระบบเดิม</h2>
                <p class="text-xs text-gray-400 mt-0.5">นำรูปภาพจาก <span class="font-mono">udtc_all_app.product.product_img</span> มาอัปเดตในฐานข้อมูลหลัก</p>
            </div>
            <div class="flex gap-2">
                <button onclick="loadImagePreview()" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm font-bold transition">
                    <i class="fas fa-eye mr-1"></i> ดูตัวอย่าง
                </button>
                <button onclick="applySyncImages()" id="btn-sync-images" class="bg-emerald-500 hover:bg-emerald-600 text-white px-4 py-2 rounded-lg text-sm font-bold transition" disabled>
                    <i class="fas fa-sync-alt mr-1"></i> ซิงค์รูปภาพ
                </button>
            </div>
        </div>
        <div id="image-container">
            <div class="text-center py-12 text-gray-400">
                <i class="fas fa-images text-4xl mb-3 block opacity-30"></i>
                คลิก "ดูตัวอย่าง" เพื่อดูรูปภาพที่จะนำเข้า
            </div>
        </div>
    </div>
</div>

<!-- ── Tab: Withdraw Import ───────────────────────────────────────────────── -->
<div id="tab-withdraw" class="tab-pane">
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h2 class="text-lg font-bold text-gray-800"><i class="fas fa-history text-amber-600 mr-2"></i>นำเข้าประวัติการเบิกจากระบบเดิม</h2>
                <p class="text-xs text-gray-400 mt-0.5">นำเข้าข้อมูล <span class="font-mono">udtc_all_app.withdraw</span> (สถานะ 5 = อนุมัติแล้ว) ไปยัง <span class="font-mono">requests / request_items</span></p>
            </div>
            <div class="flex gap-2">
                <button onclick="loadWithdrawStats()" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm font-bold transition">
                    <i class="fas fa-chart-bar mr-1"></i> ตรวจสอบ
                </button>
                <button onclick="runImport()" id="btn-import" class="bg-amber-500 hover:bg-amber-600 text-white px-4 py-2 rounded-lg text-sm font-bold transition" disabled>
                    <i class="fas fa-file-import mr-1"></i> นำเข้าทั้งหมด
                </button>
            </div>
        </div>
        <div id="withdraw-container">
            <div class="text-center py-12 text-gray-400">
                <i class="fas fa-history text-4xl mb-3 block opacity-30"></i>
                คลิก "ตรวจสอบ" เพื่อดูสรุปข้อมูลก่อนนำเข้า
            </div>
        </div>
    </div>
</div>

</main>


<script>
// ── Tab switching ─────────────────────────────────────────────────────────────
function switchTab(name) {
    document.querySelectorAll('.tab-btn').forEach((b,i) => {
        const names = ['price','image','withdraw'];
        b.classList.toggle('active', names[i] === name);
    });
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function thaiDate(d) {
    if (!d || d.startsWith('0000')) return '-';
    const months = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    const dt = new Date(d);
    return dt.getDate() + ' ' + months[dt.getMonth()] + ' ' + (dt.getFullYear() + 543);
}

// ── Price compare ─────────────────────────────────────────────────────────────
function loadPriceCompare() {
    document.getElementById('price-container').innerHTML =
        '<div class="text-center py-8 text-gray-400"><i class="fas fa-spinner fa-spin text-2xl"></i><p class="mt-2">กำลังโหลด...</p></div>';
    fetch('sync_legacy.php?action=get_price_compare')
        .then(r => r.json()).then(data => {
            if (data.status !== 'ok') return;
            const rows = data.rows;
            const diffCount = rows.filter(r => r.old_price !== null && parseFloat(r.old_price) !== parseFloat(r.new_price)).length;
            const nullOld   = rows.filter(r => r.old_price === null).length;

            let html = `
            <div class="flex gap-4 mb-4">
                <div class="bg-blue-50 border border-blue-100 rounded-xl px-4 py-3 text-center flex-1">
                    <p class="text-2xl font-bold text-blue-700">${rows.length}</p>
                    <p class="text-xs text-blue-500">รายการทั้งหมด</p>
                </div>
                <div class="bg-red-50 border border-red-100 rounded-xl px-4 py-3 text-center flex-1">
                    <p class="text-2xl font-bold text-red-600">${diffCount}</p>
                    <p class="text-xs text-red-400">ราคาต่างกัน</p>
                </div>
                <div class="bg-gray-50 border border-gray-100 rounded-xl px-4 py-3 text-center flex-1">
                    <p class="text-2xl font-bold text-gray-500">${nullOld}</p>
                    <p class="text-xs text-gray-400">ไม่มีราคาในระบบเดิม</p>
                </div>
            </div>
            <div class="overflow-x-auto">
            <table id="price-table" class="w-full text-sm" style="width:100%">
                <thead><tr class="bg-sky-50 text-sky-800">
                    <th class="py-3 px-3 text-left">รหัส</th>
                    <th class="py-3 px-3 text-left">ชื่อวัสดุ</th>
                    <th class="py-3 px-3 text-center">หน่วย</th>
                    <th class="py-3 px-3 text-right">ราคาปัจจุบัน (บาท)</th>
                    <th class="py-3 px-3 text-right">ราคาระบบเดิม (บาท)</th>
                    <th class="py-3 px-3 text-center">สถานะ</th>
                </tr></thead>
                <tbody>`;
            rows.forEach(r => {
                const oldP = r.old_price !== null ? parseFloat(r.old_price) : null;
                const newP = parseFloat(r.new_price);
                let badge = '';
                if (oldP === null) {
                    badge = '<span class="bg-gray-100 text-gray-500 text-xs px-2 py-0.5 rounded-full">ไม่มีข้อมูล</span>';
                } else if (Math.abs(oldP - newP) < 0.001) {
                    badge = '<span class="bg-green-100 text-green-700 text-xs px-2 py-0.5 rounded-full"><i class="fas fa-check mr-1"></i>ตรงกัน</span>';
                } else {
                    badge = '<span class="bg-red-100 text-red-600 text-xs px-2 py-0.5 rounded-full"><i class="fas fa-exclamation-triangle mr-1"></i>ต่างกัน</span>';
                }
                html += `<tr class="border-b border-gray-50 hover:bg-gray-50">
                    <td class="px-3 py-2 font-mono text-gray-500 text-xs">${r.code}</td>
                    <td class="px-3 py-2 font-bold text-gray-800">${r.name}</td>
                    <td class="px-3 py-2 text-center text-gray-500">${r.unit}</td>
                    <td class="px-3 py-2 text-right font-bold text-sky-700">฿${parseFloat(r.new_price).toFixed(2)}</td>
                    <td class="px-3 py-2 text-right ${oldP===null?'text-gray-400':'font-bold text-gray-700'}">${oldP!==null?'฿'+oldP.toFixed(2):'—'}</td>
                    <td class="px-3 py-2 text-center">${badge}</td>
                </tr>`;
            });
            html += `</tbody></table></div>`;
            document.getElementById('price-container').innerHTML = html;
            $('#price-table').DataTable({
                language: {
                    search:"ค้นหา:", lengthMenu:"แสดง _MENU_ รายการ",
                    info:"แสดง _START_–_END_ จาก _TOTAL_",
                    paginate:{next:"ถัดไป",previous:"ก่อนหน้า"},
                    emptyTable:"ไม่มีข้อมูล"
                }, pageLength: 25
            });
        });
}

// ── Image preview ─────────────────────────────────────────────────────────────
function loadImagePreview() {
    document.getElementById('image-container').innerHTML =
        '<div class="text-center py-8 text-gray-400"><i class="fas fa-spinner fa-spin text-2xl"></i><p class="mt-2">กำลังโหลด...</p></div>';
    fetch('sync_legacy.php?action=get_image_preview')
        .then(r => r.json()).then(data => {
            if (data.status !== 'ok') return;
            const rows = data.rows;
            const noImg = rows.filter(r => !r.current_image || r.current_image === '').length;
            const hasImg = rows.length - noImg;

            let html = `
            <div class="flex gap-4 mb-4">
                <div class="bg-blue-50 border border-blue-100 rounded-xl px-4 py-3 text-center flex-1">
                    <p class="text-2xl font-bold text-blue-700">${rows.length}</p>
                    <p class="text-xs text-blue-500">รายการที่มีรูปในระบบเดิม</p>
                </div>
                <div class="bg-amber-50 border border-amber-100 rounded-xl px-4 py-3 text-center flex-1">
                    <p class="text-2xl font-bold text-amber-600">${noImg}</p>
                    <p class="text-xs text-amber-400">ยังไม่มีรูปในระบบใหม่</p>
                </div>
                <div class="bg-emerald-50 border border-emerald-100 rounded-xl px-4 py-3 text-center flex-1">
                    <p class="text-2xl font-bold text-emerald-600">${hasImg}</p>
                    <p class="text-xs text-emerald-400">มีรูปอยู่แล้ว (จะถูกอัปเดต)</p>
                </div>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3 max-h-[480px] overflow-y-auto pr-1">`;
            rows.forEach(r => {
                const oldSrc = 'img/img_app_4/' + r.old_img;
                const curSrc = r.current_image || 'img/packaging.png';
                const badge  = r.current_image
                    ? '<span class="absolute top-1 right-1 bg-amber-400 text-white text-[9px] px-1 rounded">อัปเดต</span>'
                    : '<span class="absolute top-1 right-1 bg-emerald-500 text-white text-[9px] px-1 rounded">ใหม่</span>';
                html += `<div class="bg-gray-50 border border-gray-100 rounded-xl p-2 relative hover:shadow-md transition">
                    ${badge}
                    <div class="flex gap-2 mb-1.5 items-center">
                        <div class="w-12 h-12 flex-shrink-0 rounded overflow-hidden bg-white border border-gray-100">
                            <img src="${oldSrc}" onerror="this.src='img/packaging.png'" class="w-full h-full object-contain">
                        </div>
                    </div>
                    <p class="text-[11px] font-bold text-gray-700 leading-tight truncate" title="${r.name}">${r.name}</p>
                    <p class="text-[10px] font-mono text-gray-400">${r.code}</p>
                </div>`;
            });
            html += `</div>`;
            document.getElementById('image-container').innerHTML = html;
            document.getElementById('btn-sync-images').disabled = false;
        });
}

function applySyncImages() {
    Swal.fire({
        title: 'ยืนยันการซิงค์รูปภาพ?',
        text: 'รูปภาพในฐานข้อมูลหลักจะถูกอัปเดตจากระบบเดิม',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-sync-alt mr-1"></i> ซิงค์เลย',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#10b981',
        cancelButtonColor: '#94a3b8',
    }).then(res => {
        if (!res.isConfirmed) return;
        Swal.fire({ title: 'กำลังซิงค์...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        fetch('sync_legacy.php?action=sync_images')
            .then(r => r.json()).then(data => {
                if (data.status === 'ok') {
                    Swal.fire('สำเร็จ!', `อัปเดตรูปภาพแล้ว ${data.updated} รายการ`, 'success');
                } else {
                    Swal.fire('ผิดพลาด', data.message || 'เกิดข้อผิดพลาด', 'error');
                }
            });
    });
}

// ── Withdraw import ───────────────────────────────────────────────────────────
let withdrawTable = null;

function loadWithdrawStats() {
    document.getElementById('withdraw-container').innerHTML =
        '<div class="text-center py-8 text-gray-400"><i class="fas fa-spinner fa-spin text-2xl"></i><p class="mt-2">กำลังตรวจสอบ...</p></div>';
    fetch('sync_legacy.php?action=get_withdraw_stats')
        .then(r => r.json()).then(data => {
            if (data.status !== 'ok') {
                document.getElementById('withdraw-container').innerHTML =
                    `<div class="text-center py-8 text-red-500"><i class="fas fa-exclamation-circle text-2xl"></i><p class="mt-2">โหลดข้อมูลล้มเหลว: ${data.message || 'ไม่ทราบสาเหตุ'}</p></div>`;
                return;
            }

            // Enable button
            document.getElementById('btn-import').disabled = false;

            let bannerHtml = '';
            if (data.imported_info) {
                bannerHtml = `
                <div class="bg-green-50 border border-green-200 rounded-xl px-4 py-3 mb-4 text-sm text-green-800 flex justify-between items-center">
                    <div>
                        <i class="fas fa-check-circle mr-2 text-green-600 text-base"></i>
                        <strong>นำเข้าข้อมูลยกยอดตั้งต้นแล้ว</strong> เมื่อวันที่ ${thaiDate(data.imported_info.import_date)}
                        โดย <strong>${data.imported_info.importer_name || 'ไม่ทราบผู้ใช้งาน'}</strong> (${data.imported_info.receipt_no})
                    </div>
                    <span class="bg-green-100 text-green-800 text-xs px-2.5 py-1 rounded-full font-bold">นำเข้าเสร็จสิ้น</span>
                </div>`;
            } else {
                bannerHtml = `
                <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 mb-4 text-sm text-amber-800">
                    <i class="fas fa-info-circle mr-2"></i>
                    ยังไม่เคยมีการนำเข้ายอดวัสดุตั้งต้นจากระบบเดิม — กรุณาตรวจสอบและระบุ <strong>จำนวนตั้งต้นที่จะยกยอด</strong> ก่อนกดปุ่มยืนยันด้านบน
                </div>`;
            }

            let sumCurrent = 0;
            let sumLegacy = 0;
            data.rows.forEach(r => {
                sumCurrent += parseInt(r.current_qty) || 0;
                sumLegacy += parseInt(r.legacy_withdrawn) || 0;
            });

            let tableRows = '';
            data.rows.forEach((r, idx) => {
                tableRows += `
                <tr class="border-b border-gray-50 hover:bg-gray-50 transition">
                    <td class="px-3 py-2 text-gray-500 font-mono text-xs">${r.code}</td>
                    <td class="px-3 py-2 font-bold text-gray-800">${r.name}</td>
                    <td class="px-3 py-2 text-center text-gray-500 text-xs">${r.unit}</td>
                    <td class="px-3 py-2 text-right font-semibold text-gray-600">${parseInt(r.current_qty).toLocaleString()}</td>
                    <td class="px-3 py-2 text-right font-semibold text-amber-600">${parseInt(r.legacy_withdrawn).toLocaleString()}</td>
                    <td class="px-3 py-2 text-center">
                        <input type="number" min="0" value="${r.legacy_withdrawn}" data-id="${r.id}"
                               class="carryover-qty-input border border-gray-200 hover:border-amber-400 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 rounded-lg px-2 py-1 w-28 text-center font-bold text-gray-800 transition">
                    </td>
                </tr>`;
            });

            document.getElementById('withdraw-container').innerHTML = `
            ${bannerHtml}

            <div class="flex gap-4 mb-5 flex-wrap">
                <div class="bg-blue-50 border border-blue-100 rounded-xl px-5 py-3 text-center flex-1 min-w-[150px]">
                    <p class="text-2xl font-bold text-blue-700">${data.rows.length}</p>
                    <p class="text-xs text-blue-500">รายการวัสดุทั้งหมด</p>
                </div>
                <div class="bg-indigo-50 border border-indigo-100 rounded-xl px-5 py-3 text-center flex-1 min-w-[150px]">
                    <p class="text-2xl font-bold text-indigo-700">${sumCurrent.toLocaleString()}</p>
                    <p class="text-xs text-indigo-500">ยอดรวมปัจจุบันในคลัง</p>
                </div>
                <div class="bg-amber-50 border border-amber-100 rounded-xl px-5 py-3 text-center flex-1 min-w-[150px]">
                    <p class="text-2xl font-bold text-amber-700">${sumLegacy.toLocaleString()}</p>
                    <p class="text-xs text-amber-500">ยอดเบิกรวมจากระบบเดิม</p>
                </div>
            </div>

            <div class="overflow-x-auto mt-4 font-normal">
                <table id="withdraw-table" class="w-full text-sm" style="width:100%">
                    <thead>
                        <tr class="bg-sky-50 text-sky-800 text-xs">
                            <th class="px-3 py-3 text-left">รหัสวัสดุ</th>
                            <th class="px-3 py-3 text-left">ชื่อวัสดุ</th>
                            <th class="px-3 py-3 text-center">หน่วยนับ</th>
                            <th class="px-3 py-3 text-right">จำนวนปัจจุบันในคลัง</th>
                            <th class="px-3 py-3 text-right">ยอดเบิกสะสมระบบเดิม</th>
                            <th class="px-3 py-3 text-center w-36">จำนวนตั้งต้นที่จะยกยอด</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${tableRows}
                    </tbody>
                </table>
            </div>`;

            if (withdrawTable) {
                withdrawTable.destroy();
            }

            withdrawTable = $('#withdraw-table').DataTable({
                language: {
                    search: "ค้นหา:",
                    lengthMenu: "แสดง _MENU_ รายการ",
                    info: "แสดง _START_–_END_ จาก _TOTAL_",
                    paginate: { next: "ถัดไป", previous: "ก่อนหน้า" },
                    emptyTable: "ไม่มีข้อมูล"
                },
                pageLength: 200,
                lengthMenu: [25, 50, 100, 200, 500]
            });
        })
        .catch(err => {
            console.error(err);
            document.getElementById('withdraw-container').innerHTML =
                `<div class="text-center py-8 text-red-500"><i class="fas fa-exclamation-circle text-2xl"></i><p class="mt-2">เกิดข้อผิดพลาดในการโหลดข้อมูล: ${err.message}</p></div>`;
        });
}

function runImport() {
    if (!withdrawTable) return;
    const items = [];
    let hasInvalid = false;
    withdrawTable.$('.carryover-qty-input').each(function() {
        const id = $(this).data('id');
        const val = $(this).val();
        const qty = parseInt(val);
        if (isNaN(qty) || qty < 0) {
            hasInvalid = true;
        }
        items.push({ id: id, qty: qty });
    });

    if (hasInvalid) {
        Swal.fire('ผิดพลาด', 'กรุณาระบุจำนวนยกยอดให้ถูกต้อง (เป็นตัวเลขตั้งแต่ 0 ขึ้นไป)', 'error');
        return;
    }

    if (items.length === 0) {
        Swal.fire('ผิดพลาด', 'ไม่พบข้อมูลวัสดุที่จะนำเข้า', 'error');
        return;
    }

    Swal.fire({
        title: 'ยืนยันการนำเข้าข้อมูลยกยอดตั้งต้น?',
        html: `<p class="text-sm text-gray-600">ระบบจะทำการอัปเดตจำนวนคงคลังคงเหลือ (qty) ของพัสดุทั้งหมดตามยอดที่ท่านกำหนด และบันทึกประวัติเป็นรายการยกยอด</p>
               <p class="text-xs text-red-500 mt-2 font-bold"><i class="fas fa-exclamation-triangle mr-1"></i>คำเตือน: การนำเข้าจะเขียนทับจำนวนคงคลังปัจจุบันของวัสดุที่เลือก!</p>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-check mr-1"></i> ยืนยันนำเข้าข้อมูลยกยอด',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#f59e0b',
        cancelButtonColor: '#94a3b8',
    }).then(res => {
        if (!res.isConfirmed) return;
        Swal.fire({
            title: 'กำลังนำเข้าข้อมูล...',
            html: '<p class="text-sm text-gray-500">กรุณาอย่าปิดหน้าต่างนี้</p>',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });

        fetch('sync_legacy.php?action=import_withdraw', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json; charset=utf-8'
            },
            body: JSON.stringify({ items: items })
        })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'ok') {
                Swal.fire({
                    title: 'นำเข้าสำเร็จ!',
                    text: `นำเข้าพัสดุยกยอดตั้งต้นจำนวน ${data.imported} รายการสำเร็จเรียบร้อยแล้ว`,
                    icon: 'success',
                    confirmButtonColor: '#10b981'
                }).then(() => loadWithdrawStats());
            } else {
                Swal.fire('ผิดพลาด', data.message || 'เกิดข้อผิดพลาดขณะนำเข้า', 'error');
            }
        })
        .catch(err => {
            console.error(err);
            Swal.fire('ผิดพลาด', 'เกิดข้อผิดพลาดในการเชื่อมต่อหรือแปลงผลลัพธ์', 'error');
        });
    });
}
</script>
</body>
</html>
