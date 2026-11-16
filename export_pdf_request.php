<?php
// Avoid session deadlock when headless Chrome generates PDF
$isHeadless = isset($_GET['headless']) || isset($_GET['token']);
if (!$isHeadless && session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config.php';

$botSecret = trim($sys_settings['telegram_bot_token'] ?? '');
if (empty($botSecret)) {
    $botSecret = 'durables_pdf_secret';
}
$expectedToken = md5($botSecret . '_internal_pdf_' . trim($_GET['id'] ?? ''));
$isInternalTokenValid = isset($_GET['token']) && hash_equals($expectedToken, trim($_GET['token']));

if (!$isInternalTokenValid) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['user_id'])) {
        header("Location: index.php");
        exit;
    }
}

if (!isset($_GET['id']) || trim($_GET['id']) === '') {
    die("Error: No ID specified.");
}

$id = trim($_GET['id']);

// Parse ID parameter: can be single numeric ID, comma-separated numeric IDs, last_approve code, or request_name
$rawCodes = array_values(array_filter(array_map('trim', explode(',', $id))));
$numericIds = [];
$stringCodes = [];
foreach ($rawCodes as $c) {
    if (is_numeric($c)) {
        $numericIds[] = (int)$c;
    } else {
        $stringCodes[] = $c;
    }
}

$whereParts = [];
$params = [];

if (!empty($stringCodes)) {
    $inStr = implode(',', array_fill(0, count($stringCodes), '?'));
    $whereParts[] = "r.last_approve IN ($inStr) OR r.request_name IN ($inStr)";
    $params = array_merge($params, $stringCodes, $stringCodes);
}
if (!empty($numericIds)) {
    $inNum = implode(',', array_fill(0, count($numericIds), '?'));
    $whereParts[] = "r.id IN ($inNum)";
    $params = array_merge($params, $numericIds);
}

if (empty($whereParts)) {
    $whereParts[] = "r.last_approve = ? OR r.request_name = ?";
    $params = [$id, $id];
}

$items = [];
try {
    $sqlItems = "SELECT ri.qty, ri.approved_qty, ri.edit_reason, ri.is_deleted,
                        s.name as product_name, s.unit as product_unit, s.code as supply_code, s.image,
                        r.id as request_id, r.request_name, r.request_date, r.last_approve, r.status,
                        r.approver_head_date, r.approver_supply_date, r.approver_director_date, r.approver_director_id,
                        u.id as user_id, u.prefix, u.firstname, u.lastname, COALESCE(r.section, u.section) as section
                 FROM request_items ri
                 JOIN requests r ON ri.request_id = r.id
                 JOIN supplies s ON ri.supply_id = s.id
                 LEFT JOIN users u ON r.user_id = u.id
                 WHERE (" . implode(' OR ', $whereParts) . ")
                   AND (ri.is_deleted IS NULL OR ri.is_deleted = 0)
                 ORDER BY r.id ASC, ri.id ASC";

    $stmtItems = $pdo->prepare($sqlItems);
    $stmtItems->execute($params);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

if (empty($items)) {
    $sqlOld = "SELECT 
                w.withdraw_volume as qty,
                w.withdraw_volume as approved_qty,
                '' as edit_reason,
                0 as is_deleted,
                p.product_name,
                tv.type_volume as product_unit,
                p.product_code as supply_code,
                p.product_img as image,
                w.id as request_id,
                w.withdraw_code as request_name,
                w.date_accept_step2 as request_date,
                w.last_approve,
                'approved_old' as status,
                w.date_accept_step1 as approver_head_date,
                w.date_accept_step2 as approver_supply_date,
                w.date_accept_step2 as approver_director_date,
                0 as approver_director_id,
                w.user_withdraw as user_id,
                '' as prefix,
                an.authen_name as firstname,
                an.authen_lname as lastname,
                ap.part_name as section
            FROM withdraw w
            LEFT JOIN product p ON w.product_code = p.product_code
            LEFT JOIN product_insert pi ON p.product_code = pi.product_code
            LEFT JOIN type_volume tv ON pi.type_volume = tv.id
            LEFT JOIN authen_name an ON w.user_withdraw = an.id
            LEFT JOIN authen_part ap ON an.part_id = ap.id
            WHERE w.last_approve = ? OR w.withdraw_code = ?
            ORDER BY w.withdraw_code ASC, w.id ASC";

    // Try current PDO first (durables_db)
    try {
        $stmtOld = $pdo->prepare($sqlOld);
        $stmtOld->execute([$id, $id]);
        $items = $stmtOld->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    // Fallback to pdo_old if available
    if (empty($items) && isset($pdo_old)) {
        try {
            $stmtOld = $pdo_old->prepare($sqlOld);
            $stmtOld->execute([$id, $id]);
            $items = $stmtOld->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
    }
}

if (empty($items)) {
    die("<div style='font-family:Sarabun,sans-serif;padding:30px;text-align:center;'><h3>ไม่พบข้อมูลรายการใบเบิก</h3><p>รหัส: " . htmlspecialchars($id) . "</p></div>");
}

// Helper Date Functions
if (!function_exists('thaiDate')) {
    function thaiDate($date)
    {
        if (!$date) return '-';
        $months = [
            1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
            5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
            9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม'
        ];
        $ts = strtotime($date);
        $d = date('j', $ts);
        $m = $months[(int) date('n', $ts)] ?? '';
        $y = date('Y', $ts) + 543;
        return "$d $m $y";
    }
}

if (!function_exists('thaiDateTime')) {
    function thaiDateTime($date)
    {
        if (!$date) return '-';
        $ts = strtotime($date);
        return thaiDate($date) . ' เวลา ' . date('H:i', $ts) . ' น.';
    }
}

// Group items by Requester & Request Name
$groupedByRequester = [];
$totalRequestedQty = 0;
$totalApprovedQty = 0;

foreach ($items as $item) {
    $reqKey = $item['request_name'] ?: ('REQ_' . $item['request_id']);
    if (!isset($groupedByRequester[$reqKey])) {
        $fullName = trim(($item['prefix'] ?? '') . $item['firstname'] . ' ' . ($item['lastname'] ?? ''));
        $groupedByRequester[$reqKey] = [
            'request_id'   => $item['request_id'],
            'request_name' => $item['request_name'],
            'requester'    => $fullName ?: 'ไม่ระบุชื่อ',
            'section'      => $item['section'] ?: '-',
            'request_date' => $item['request_date'],
            'status'       => $item['status'],
            'items'        => []
        ];
    }
    $qty = (float)$item['qty'];
    $appQty = ($item['approved_qty'] !== null && $item['approved_qty'] !== '') ? (float)$item['approved_qty'] : $qty;
    $totalRequestedQty += $qty;
    $totalApprovedQty += $appQty;

    $groupedByRequester[$reqKey]['items'][] = $item;
}

$firstItem = $items[0];
$batchCode = (!empty($firstItem['last_approve'])) ? $firstItem['last_approve'] : (!empty($firstItem['request_name']) ? $firstItem['request_name'] : $id);
$sectionName = $firstItem['section'] ?? '-';
$reqDate = !empty($firstItem['request_date']) ? thaiDate($firstItem['request_date']) : thaiDate(date('Y-m-d'));
$approveDate = !empty($firstItem['approver_supply_date']) ? thaiDate($firstItem['approver_supply_date']) : (!empty($firstItem['approver_director_date']) ? thaiDate($firstItem['approver_director_date']) : thaiDate(date('Y-m-d')));
$dirDate = !empty($firstItem['approver_director_date']) ? thaiDate($firstItem['approver_director_date']) : $approveDate;

$firstRequesterName = !empty($groupedByRequester) ? reset($groupedByRequester)['requester'] : '-';
$requesterCount = count($groupedByRequester);

// Fetch Supply Officer & Supply Head & Director
$stmtSO = $pdo->prepare("SELECT prefix, firstname, lastname FROM users WHERE role = 'supply_officer' LIMIT 1");
$stmtSO->execute();
$so = $stmtSO->fetch(PDO::FETCH_ASSOC);
$soName = $so ? ($so['prefix'] . $so['firstname'] . ' ' . $so['lastname']) : '';

$stmtSH = $pdo->prepare("SELECT prefix, firstname, lastname FROM users WHERE role = 'supply_head' LIMIT 1");
$stmtSH->execute();
$sh = $stmtSH->fetch(PDO::FETCH_ASSOC);
$shName = $sh ? ($sh['prefix'] . $sh['firstname'] . ' ' . $sh['lastname']) : '';

$dirName = '';
if (!empty($firstItem['approver_director_id'])) {
    $stmtDir = $pdo->prepare("SELECT prefix, firstname, lastname FROM users WHERE id = ?");
    $stmtDir->execute([$firstItem['approver_director_id']]);
    $dir = $stmtDir->fetch(PDO::FETCH_ASSOC);
    if ($dir) {
        $dirName = $dir['prefix'] . $dir['firstname'] . ' ' . $dir['lastname'];
    }
}
if (empty($dirName)) {
    $stmtDir = $pdo->prepare("SELECT prefix, firstname, lastname FROM users WHERE role = 'director' LIMIT 1");
    $stmtDir->execute();
    $dir = $stmtDir->fetch(PDO::FETCH_ASSOC);
    if ($dir) {
        $dirName = $dir['prefix'] . $dir['firstname'] . ' ' . $dir['lastname'];
    }
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <title>ใบเบิกวัสดุสำนักงาน - <?php echo htmlspecialchars($batchCode); ?></title>
    <!-- Google Fonts: Sarabun -->
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ── Page setup (A4 Portrait) ── */
        @page {
            size: A4 portrait;
            margin: 12mm 15mm 15mm 15mm;
        }

        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        body {
            font-family: 'Sarabun', sans-serif;
            margin: 0;
            padding: 20px 0;
            background: #eef2f6;
            color: #1e293b;
            font-size: 13px;
            line-height: 1.45;
        }

        /* ── Action bar (Screen only) ── */
        .action-bar {
            max-width: 210mm;
            margin: 0 auto 16px auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 4px;
        }

        .action-bar-title {
            font-size: 14px;
            font-weight: 700;
            color: #334155;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
        }

        .btn {
            padding: 8px 18px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            border-radius: 8px;
            border: none;
            transition: all 0.2s;
            font-family: 'Sarabun', sans-serif;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }

        .btn-print {
            background-color: #0284c7;
            color: white;
            box-shadow: 0 4px 6px -1px rgba(2, 132, 199, 0.3);
        }

        .btn-print:hover {
            background-color: #0369a1;
            transform: translateY(-1px);
        }

        .btn-close {
            background-color: #fff;
            color: #64748b;
            border: 1px solid #cbd5e1;
        }

        .btn-close:hover {
            background-color: #f1f5f9;
            color: #334155;
        }

        /* ── Report Sheet (A4 Portrait Container) ── */
        .container {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            background: #fff;
            padding: 14mm 16mm 16mm 16mm;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.08);
            border-radius: 4px;
            position: relative;
        }

        /* ── Header ── */
        .report-header {
            text-align: center;
            border-bottom: 2px solid #0284c7;
            padding-bottom: 12px;
            margin-bottom: 14px;
        }

        .report-header h1 {
            margin: 0 0 4px 0;
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.3px;
        }

        .report-header h2 {
            margin: 0;
            font-size: 15px;
            font-weight: 600;
            color: #334155;
        }

        .report-header p {
            margin: 4px 0 0 0;
            font-size: 12px;
            color: #64748b;
        }

        /* ── Info Box ── */
        .info-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 10px 14px;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12.5px;
        }

        .info-col {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .info-row {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .label {
            font-weight: 700;
            color: #475569;
        }

        .val-strong {
            font-weight: 700;
            color: #0f172a;
        }

        .badge-batch {
            background: #0284c7;
            color: #fff;
            padding: 2px 8px;
            border-radius: 6px;
            font-family: monospace;
            font-size: 12px;
            font-weight: 700;
        }

        /* ── Requester Card Group (Matches SweetAlert layout) ── */
        .requester-card {
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 16px;
            page-break-inside: avoid;
            background: #fff;
        }

        .requester-header {
            background: #f1f5f9;
            border-bottom: 1px solid #cbd5e1;
            padding: 8px 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .requester-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .number-badge {
            width: 26px;
            height: 26px;
            background: #4338ca;
            color: #fff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 12.5px;
            box-shadow: 0 1px 3px rgba(67, 56, 202, 0.3);
        }

        .requester-name {
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
        }

        .req-code-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 2.5px 8px;
            border-radius: 6px;
            background: #fff;
            border: 1px solid #cbd5e1;
            font-size: 11.5px;
            font-family: monospace;
            font-weight: 700;
            color: #3730a3;
        }

        /* ── Table Styling ── */
        table.items-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12.5px;
        }

        table.items-table th {
            background: #f8fafc;
            color: #475569;
            font-weight: 700;
            font-size: 12px;
            padding: 6px 8px;
            border-bottom: 1px solid #cbd5e1;
            text-align: left;
        }

        table.items-table th.center,
        table.items-table td.center {
            text-align: center;
        }

        table.items-table td {
            padding: 6px 8px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            color: #1e293b;
        }

        table.items-table tr:last-child td {
            border-bottom: none;
        }

        .thumb-img {
            width: 34px;
            height: 34px;
            object-fit: cover;
            border-radius: 5px;
            border: 1px solid #e2e8f0;
            display: block;
            margin: 0 auto;
        }

        .item-name {
            font-weight: 600;
            color: #1e293b;
        }

        .item-code-tag {
            display: inline-block;
            font-size: 10px;
            font-family: monospace;
            padding: 1px 5px;
            background: #f1f5f9;
            border-radius: 4px;
            border: 1px solid #e2e8f0;
            color: #64748b;
            margin-top: 2px;
        }

        .qty-val {
            font-weight: 600;
            font-size: 13px;
            text-align: center;
        }

        .qty-approved-box {
            background: #eef2ff;
            color: #3730a3;
            font-weight: 800;
            font-size: 13.5px;
            padding: 2px 6px;
            border-radius: 4px;
            border: 1px solid #e0e7ff;
            display: inline-block;
            min-width: 24px;
            text-align: center;
        }

        .item-remark {
            font-size: 11px;
            color: #d97706;
            margin-top: 2px;
        }

        /* ── Report Total Footer Bar ── */
        .report-total-bar {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 8px 14px;
            margin-top: 14px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12.5px;
            font-weight: 700;
            page-break-inside: avoid;
        }

        /* ── Signatures Section ── */
        .signatures {
            margin-top: 20px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            page-break-inside: avoid;
            border-top: 1px dashed #cbd5e1;
            padding-top: 18px;
        }

        .sig-box {
            width: 32%;
            text-align: center;
            font-size: 12px;
            color: #334155;
            line-height: 1.6;
        }

        .sig-label {
            margin-top: 4px;
            font-size: 12px;
            color: #334155;
        }

        .sig-sub {
            font-size: 11px;
            color: #64748b;
        }

        /* ── Print Media Query ── */
        @media print {
            body {
                padding: 0;
                background: #fff;
            }

            .no-print {
                display: none !important;
            }

            .container {
                width: 100% !important;
                min-height: auto !important;
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
                border: none !important;
                border-radius: 0 !important;
            }

            .requester-card {
                border-color: #94a3b8;
            }

            .requester-header {
                background: #f8fafc !important;
                border-bottom-color: #94a3b8 !important;
            }

            table.items-table th {
                background: #f1f5f9 !important;
                border-bottom-color: #94a3b8 !important;
            }

            .qty-approved-box {
                background: transparent !important;
                border: none !important;
                color: #000 !important;
            }

            @page {
                size: A4 portrait;
                margin: 12mm 15mm 15mm 15mm;
            }
        }
    </style>
</head>

<body>
    <!-- Action Bar for Screen Preview -->
    <div class="action-bar no-print">
        <div class="action-bar-title">
            <i class="fas fa-file-invoice text-sky-600"></i>
            <span>ตัวอย่างแบบรายงานใบเบิกวัสดุ (A4 แนวตั้ง)</span>
        </div>
        <div class="action-buttons">
            <button onclick="window.print()" class="btn btn-print">
                <i class="fas fa-print"></i> พิมพ์ใบเบิก (Print / PDF)
            </button>
            <button onclick="window.close()" class="btn btn-close">
                <i class="fas fa-times"></i> ปิด (Close)
            </button>
        </div>
    </div>

    <!-- Main Report Page Sheet -->
    <div class="container">
        <!-- Report Header -->
        <div class="report-header">
            <h1>ใบเบิกวัสดุสำนักงาน</h1>
            <h2><?php echo htmlspecialchars($sys_settings['site_name_th'] ?? 'ศาลจังหวัดอุดรธานี'); ?></h2>
            <p>รายงานสรุปรายการเบิกจ่ายวัสดุตามส่วนงาน (แยกตามชื่อผู้ขอเบิก)</p>
        </div>

        <!-- Info Summary Box -->
        <div class="info-box">
            <div class="info-col">
                <div class="info-row">
                    <span class="label">ส่วนงาน:</span>
                    <span class="val-strong"><?php echo htmlspecialchars($sectionName); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">ผู้ขอเบิก:</span>
                    <span>
                        <?php 
                        if ($requesterCount > 1) {
                            echo htmlspecialchars($firstRequesterName) . " และคณะ (รวม {$requesterCount} คน)";
                        } else {
                            echo htmlspecialchars($firstRequesterName);
                        }
                        ?>
                    </span>
                </div>
            </div>
            <div class="info-col" style="align-items: flex-end;">
                <div class="info-row">
                    <span class="label">เลขที่ใบเบิก / กลุ่ม:</span>
                    <span class="badge-batch"><?php echo htmlspecialchars($batchCode); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">วันที่ทำรายการ:</span>
                    <span class="val-strong"><?php echo htmlspecialchars($reqDate); ?></span>
                </div>
            </div>
        </div>

        <!-- Requester Grouped Tables (Matching SweetAlert Style) -->
        <?php 
        $groupIndex = 1;
        foreach ($groupedByRequester as $reqKey => $group): 
        ?>
            <div class="requester-card">
                <!-- Group Header: Person & Individual Request Code -->
                <div class="requester-header">
                    <div class="requester-info">
                        <div class="number-badge"><?php echo $groupIndex++; ?></div>
                        <div>
                            <div class="requester-name"><?php echo htmlspecialchars($group['requester']); ?></div>
                            <div style="margin-top: 2px;">
                                <span class="req-code-pill">
                                    <i class="fas fa-file-invoice"></i> <?php echo htmlspecialchars($group['request_name']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div style="text-align: right; font-size: 11.5px; color: #64748b;">
                        <div>วันที่ขอ: <?php echo htmlspecialchars(thaiDate($group['request_date'])); ?></div>
                        <div style="font-weight: 700; color: #0284c7;"><?php echo count($group['items']); ?> รายการ</div>
                    </div>
                </div>

                <!-- Items Table for this Requester -->
                <table class="items-table">
                    <thead>
                        <tr>
                            <th class="center" style="width: 48px;">รูปภาพ</th>
                            <th>รายการวัสดุ</th>
                            <th class="center" style="width: 75px;">ขอเบิก</th>
                            <th class="center" style="width: 100px;">จำนวนที่อนุมัติ</th>
                            <th class="center" style="width: 70px;">หน่วย</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        foreach ($group['items'] as $item): 
                            $quantity = (float)$item['qty'];
                            $approvedVal = ($item['approved_qty'] !== null && $item['approved_qty'] !== '') ? (float)$item['approved_qty'] : $quantity;
                            $imgUrl = !empty($item['image']) ? $item['image'] : 'img/packaging.png';
                            $editReason = trim($item['edit_reason'] ?? '');
                        ?>
                            <tr>
                                <td class="center">
                                    <img src="<?php echo htmlspecialchars($imgUrl); ?>" alt="Supply" class="thumb-img" onerror="this.onerror=null;this.src='img/packaging.png';">
                                </td>
                                <td>
                                    <div class="item-name"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                    <span class="item-code-tag">CODE: <?php echo htmlspecialchars($item['supply_code'] ?: '-'); ?></span>
                                    <?php if (!empty($editReason)): ?>
                                        <div class="item-remark"><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($editReason); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="center qty-val">
                                    <?php echo $quantity; ?>
                                </td>
                                <td class="center">
                                    <span class="qty-approved-box"><?php echo $approvedVal; ?></span>
                                </td>
                                <td class="center" style="color: #64748b;">
                                    <?php echo htmlspecialchars($item['product_unit'] ?: '-'); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>

        <!-- Report Total Summary Bar -->
        <div class="report-total-bar">
            <div>
                รวมทั้งสิ้น: <span style="color:#0284c7;"><?php echo $requesterCount; ?></span> ผู้ขอเบิก 
                (ทั้งหมด <span style="color:#0284c7;"><?php echo count($items); ?></span> รายการ)
            </div>
            <div>
                ยอดขอเบิกรวม: <span style="color:#64748b;"><?php echo $totalRequestedQty; ?></span> หน่วย | 
                ยอดอนุมัติจ่ายรวม: <span style="color:#4338ca;font-size:14px;"><?php echo $totalApprovedQty; ?></span> หน่วย
            </div>
        </div>

        <!-- Signatures Section -->
        <div class="signatures">
            <div class="sig-box">
                <div>ลงชื่อ............................................................ผู้เบิก</div>
                <div class="sig-label">
                    ( <?php echo htmlspecialchars($requesterCount > 1 ? ($firstRequesterName . ' และคณะ') : $firstRequesterName); ?> )
                </div>
                <div class="sig-sub"><?php echo htmlspecialchars($sectionName); ?></div>
                <div class="sig-sub">วันที่ <?php echo htmlspecialchars($reqDate); ?></div>
            </div>

            <div class="sig-box">
                <div>ลงชื่อ............................................................ผู้จ่ายพัสดุ</div>
                <div class="sig-label">( <?php echo htmlspecialchars($soName ?: 'เจ้าหน้าที่พัสดุ'); ?> )</div>
                <div class="sig-sub">เจ้าหน้าที่พัสดุ</div>
                <div class="sig-sub">วันที่ <?php echo htmlspecialchars($approveDate); ?></div>
            </div>

            <div class="sig-box">
                <div>ลงชื่อ............................................................ผู้อนุมัติ</div>
                <div class="sig-label">( <?php echo htmlspecialchars($dirName ?: ($shName ?: 'ผู้อำนวยการ / ผู้ที่ได้รับมอบหมาย')); ?> )</div>
                <div class="sig-sub">ผู้อำนวยการ / ผู้ที่ได้รับมอบหมาย</div>
                <div class="sig-sub">วันที่ <?php echo htmlspecialchars($dirDate); ?></div>
            </div>
        </div>
    </div>

    <?php if (!isset($_GET['headless'])): ?>
    <script>
        // Auto trigger browser print preview
        window.addEventListener('load', function () {
            setTimeout(function () {
                window.print();
            }, 600);
        });
    </script>
    <?php endif; ?>
</body>

</html>
