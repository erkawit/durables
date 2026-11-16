<?php
session_start();
require_once 'config.php';

// Access Check
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'director', 'supply_head', 'supply_officer'])) {
    header("Location: dashboard.php");
    exit;
}

$type = isset($_GET['type']) ? $_GET['type'] : 'inventory_balance';
$year = isset($_GET['year']) ? (int) $_GET['year'] : date('Y');
$month = isset($_GET['month']) ? $_GET['month'] : date('m');
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$supplyId = isset($_GET['supply_id']) ? (int) $_GET['supply_id'] : 0;
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');

// Helper: Thai Date
function thaiDate($date, $time = false)
{
    if (!$date)
        return '-';
    $timestamp = strtotime($date);
    $months = [
        1 => 'มกราคม',
        'กุมภาพันธ์',
        'มีนาคม',
        'เมษายน',
        'พฤษภาคม',
        'มิถุนายน',
        'กรกฎาคม',
        'สิงหาคม',
        'กันยายน',
        'ตุลาคม',
        'พฤศจิกายน',
        'ธันวาคม'
    ];
    $d = date('j', $timestamp);
    $m = $months[(int) date('n', $timestamp)];
    $y = date('Y', $timestamp) + 543;
    $t = $time ? ' เวลา ' . date('H:i', $timestamp) . ' น.' : '';
    return "$d $m $y$t";
}

// Helper: Thai Date Short
function thaiDateShort($date)
{
    if (!$date)
        return '-';
    $timestamp = strtotime($date);
    $months = [
        1 => 'ม.ค.',
        'ก.พ.',
        'มี.ค.',
        'เม.ย.',
        'พ.ค.',
        'มิ.ย.',
        'ก.ค.',
        'ส.ค.',
        'ก.ย.',
        'ต.ค.',
        'พ.ย.',
        'ธ.ค.'
    ];
    $d = date('j', $timestamp);
    $m = $months[(int) date('n', $timestamp)];
    $y = date('Y', $timestamp) + 543;
    return "$d $m $y";
}

// Helper: Thai Month
function thaiMonth($m)
{
    $months = [
        '01' => 'มกราคม',
        '02' => 'กุมภาพันธ์',
        '03' => 'มีนาคม',
        '04' => 'เมษายน',
        '05' => 'พฤษภาคม',
        '06' => 'มิถุนายน',
        '07' => 'กรกฎาคม',
        '08' => 'สิงหาคม',
        '09' => 'กันยายน',
        '10' => 'ตุลาคม',
        '11' => 'พฤศจิกายน',
        '12' => 'ธันวาคม'
    ];
    return $months[$m] ?? '-';
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <title>รายงาน PDF - ระบบเบิกวัสดุ</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <style>
        body {
            font-family: 'Sarabun', sans-serif;
            padding: 30px;
            background: white;
            color: #1e293b;
            font-size: 14px;
        }

        .no-print {
            text-align: center;
            margin-bottom: 20px;
        }

        .no-print button {
            padding: 8px 20px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-weight: bold;
            font-family: 'Sarabun';
        }

        .btn-print {
            background: #0ea5e9;
            color: white;
        }

        .btn-close {
            background: #64748b;
            color: white;
            margin-left: 10px;
        }

        .header {
            text-align: center;
            border-bottom: 2px solid #0ea5e9;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .header h1 {
            font-size: 20px;
            margin-bottom: 5px;
            color: #1e293b;
        }

        .header p {
            font-size: 14px;
            color: #64748b;
        }

        .report-info {
            margin-bottom: 20px;
            background: #f8fafc;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }

        .report-info h2 {
            margin: 0 0 5px 0;
            font-size: 16px;
            color: #0ea5e9;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th {
            background: #0ea5e9;
            color: white;
            padding: 10px;
            border: 1px solid #0ea5e9;
            text-align: left;
            font-size: 13px;
        }

        td {
            padding: 8px 10px;
            border: 1px solid #e2e8f0;
            vertical-align: top;
        }

        tr:nth-child(even) {
            background: #f8fafc;
        }

        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .font-bold {
            font-weight: bold;
        }

        .text-red {
            color: #ef4444;
        }

        .text-green {
            color: #10b981;
        }

        .footer {
            margin-top: 40px;
            border-top: 1px solid #e2e8f0;
            padding-top: 10px;
            font-size: 12px;
            color: #94a3b8;
            text-align: center;
        }

        @media print {
            .no-print {
                display: none;
            }

            body {
                padding: 0;
            }

            @page {
                margin: 15mm;
                size: A4 landscape;
            }
        }
    </style>
</head>

<body>
    <div class="no-print">
        <button onclick="window.print()" class="btn-print"><i class="fas fa-print"></i> พิมพ์รายงาน</button>
        <button onclick="window.close(); window.history.back();" class="btn-close">ปิดหน้าต่าง</button>
    </div>

    <div class="header">
        <h1>ระบบบริหารจัดการวัสดุสำนักงาน (Durables System)</h1>
        <p>หน่วยงาน: ศาลจังหวัดอุดรธานี</p>
    </div>

    <!-- REPORT CONTENT -->
    <?php if ($type == 'inventory_balance' || $type == 'inventory_low'): ?>
        <?php
        $condition = "status = 'active'";
        $title = "รายงานวัสดุคงคลังปัจจุบัน";
        $order = "ORDER BY name ASC";
        if ($type == 'inventory_low') {
            $condition .= " AND (qty <= min_stock OR qty = 0)";
            $title = "รายงานวัสดุใกล้หมด/จุดสั่งซื้อ (Low Stock)";
            $order = "ORDER BY qty ASC";
        }
        $items = $pdo->query("SELECT * FROM supplies WHERE $condition $order")->fetchAll();
        ?>
        <div class="report-info">
            <h2><?php echo $title; ?></h2>
            <p>ข้อมูล ณ วันที่: <?php echo thaiDate(date('Y-m-d H:i:s'), true); ?></p>
            <p>จำนวนรายการทั้งหมด: <strong><?php echo count($items); ?></strong> รายการ</p>
        </div>
        <?php if ($type == 'inventory_low'): ?>
        <table>
            <thead>
                <tr>
                    <th width="4%" class="text-center">#</th>
                    <th width="10%">รหัสวัสดุ</th>
                    <th width="28%">ชื่อวัสดุ</th>
                    <th width="13%">หมวดหมู่</th>
                    <th width="8%" class="text-center">คงเหลือ</th>
                    <th width="13%" class="text-center">Min Stock/หน่วยนับ</th>
                    <th width="12%" class="text-center">ราคา/หน่วย</th>
                    <th width="12%" class="text-center">มูลค่าคงเหลือรวม (บาท)</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $totalLowStockValue = 0;
                foreach ($items as $index => $item):
                    $itemValue = $item['qty'] * $item['price'];
                    $totalLowStockValue += $itemValue;
                ?>
                    <tr>
                        <td class="text-center"><?php echo $index + 1; ?></td>
                        <td><?php echo htmlspecialchars($item['code']); ?></td>
                        <td><?php echo htmlspecialchars($item['name']); ?></td>
                        <td><?php echo htmlspecialchars($item['category']); ?></td>
                        <td class="text-center font-bold <?php echo $item['qty'] <= $item['min_stock'] ? 'text-red' : 'text-green'; ?>">
                            <?php echo number_format($item['qty']); ?>
                        </td>
                        <td class="text-center"><?php echo number_format($item['min_stock']); ?> <?php echo htmlspecialchars($item['unit']); ?></td>
                        <td class="text-center"><?php echo number_format($item['price'], 2); ?> บาท</td>
                        <td class="text-center font-bold"><?php echo number_format($itemValue, 2); ?> บาท</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background-color: #fff3f3; font-weight: bold;">
                    <td colspan="7" style="text-align: right; padding: 6px 8px;">มูลค่าคงเหลือรวมทั้งหมด:</td>
                    <td style="text-align: center; padding: 6px 8px; color: #dc2626;"><?php echo number_format($totalLowStockValue, 2); ?> บาท</td>
                </tr>
            </tfoot>
        </table>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th width="5%" class="text-center">#</th>
                    <th width="15%">รหัสวัสดุ</th>
                    <th width="35%">ชื่อวัสดุ</th>
                    <th width="15%">หมวดหมู่</th>
                    <th width="10%" class="text-center">คงเหลือ</th>
                    <th width="10%" class="text-center">หน่วย</th>
                    <th width="10%" class="text-center">Min Stock</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $index => $item): ?>
                    <tr>
                        <td class="text-center"><?php echo $index + 1; ?></td>
                        <td><?php echo htmlspecialchars($item['code']); ?></td>
                        <td><?php echo htmlspecialchars($item['name']); ?></td>
                        <td><?php echo htmlspecialchars($item['category']); ?></td>
                        <td class="text-center font-bold <?php echo $item['qty'] <= $item['min_stock'] ? 'text-red' : 'text-green'; ?>">
                            <?php echo number_format($item['qty']); ?>
                        </td>
                        <td class="text-center"><?php echo htmlspecialchars($item['unit']); ?></td>
                        <td class="text-center text-gray-500"><?php echo number_format($item['min_stock']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

    <?php elseif ($type == 'withdrawal_daily'): ?>
        <?php
        $sql = "SELECT r.*, CONCAT(u.prefix, u.firstname, ' ', u.lastname) as fullname, u.section 
                FROM requests r 
                JOIN users u ON r.user_id = u.id 
                WHERE r.status = 'approved' AND DATE(r.approver_supply_date) = ? 
                ORDER BY r.approver_supply_date ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$date]);
        $requests = $stmt->fetchAll();
        ?>
        <div class="report-info">
            <h2>รายงานการเบิกจ่ายประจำวัน</h2>
            <p>ประจำวันที่: <strong><?php echo thaiDate($date); ?></strong></p>
            <p>จำนวนรายการเบิก: <strong><?php echo count($requests); ?></strong> ใบเบิก</p>
        </div>
        <table>
            <thead>
                <tr>
                    <th width="10%" class="text-center">เวลา</th>
                    <th width="15%">เลขที่ใบเบิก</th>
                    <th width="25%">ผู้เบิก</th>
                    <th width="20%">ส่วนงาน</th>
                    <th width="30%">รายการวัสดุ (จำนวน)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $req):
                    // Fetch items for this request
                    $stmtItems = $pdo->prepare("SELECT ri.qty, s.name, s.unit FROM request_items ri JOIN supplies s ON ri.supply_id = s.id WHERE ri.request_id = ?");
                    $stmtItems->execute([$req['id']]);
                    $rItems = $stmtItems->fetchAll();
                    $itemList = [];
                    foreach ($rItems as $ri)
                        $itemList[] = $ri['name'] . " (" . $ri['qty'] . " " . $ri['unit'] . ")";
                    ?>
                    <tr>
                        <td class="text-center"><?php echo date('H:i', strtotime($req['approver_supply_date'])); ?></td>
                        <td><?php echo htmlspecialchars($req['request_name']); ?></td>
                        <td><?php echo htmlspecialchars($req['fullname']); ?></td>
                        <td><?php echo htmlspecialchars($req['section']); ?></td>
                        <td><?php echo implode(', ', $itemList); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (count($requests) == 0): ?>
                    <tr>
                        <td colspan="5" class="text-center py-4">-- ไม่มีรายการเบิกจ่ายในวันนี้ --</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

    <?php elseif ($type == 'withdrawal_monthly' || $type == 'withdrawal_yearly'): ?>
        <?php
        $isYearly = ($type == 'withdrawal_yearly');
        $title = $isYearly ? "รายงานสรุปการเบิกจ่ายประจำปี $year" : "รายงานสรุปการเบิกจ่ายประจำเดือน " . thaiMonth($month) . " พ.ศ. " . ($year + 543);

        $sql = "SELECT s.code, s.name, s.unit, SUM(ri.qty) as total_qty, COUNT(DISTINCT r.id) as times
                FROM request_items ri
                JOIN requests r ON ri.request_id = r.id
                JOIN supplies s ON ri.supply_id = s.id
                WHERE r.status = 'approved' AND YEAR(r.approver_supply_date) = ?";

        $params = [$year];
        if (!$isYearly) {
            $sql .= " AND MONTH(r.approver_supply_date) = ?";
            $params[] = $month;
        }

        $sql .= " GROUP BY s.id ORDER BY total_qty DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll();
        ?>
        <div class="report-info">
            <h2><?php echo $title; ?></h2>
            <p>จำนวนรายการวัสดุที่มีการเบิก: <strong><?php echo count($items); ?></strong> รายการ</p>
        </div>
        <table>
            <thead>
                <tr>
                    <th width="5%" class="text-center">#</th>
                    <th width="15%">รหัสวัสดุ</th>
                    <th width="40%">ชื่อวัสดุ</th>
                    <th width="15%" class="text-center">จำนวนครั้งที่เบิก</th>
                    <th width="15%" class="text-center">รวมจำนวนเบิก</th>
                    <th width="10%" class="text-center">หน่วย</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $index => $item): ?>
                    <tr>
                        <td class="text-center"><?php echo $index + 1; ?></td>
                        <td><?php echo htmlspecialchars($item['code']); ?></td>
                        <td><?php echo htmlspecialchars($item['name']); ?></td>
                        <td class="text-center"><?php echo number_format($item['times']); ?></td>
                        <td class="text-center font-bold text-blue-600"><?php echo number_format($item['total_qty']); ?></td>
                        <td class="text-center"><?php echo htmlspecialchars($item['unit']); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (count($items) == 0): ?>
                    <tr>
                        <td colspan="6" class="text-center py-4">-- ไม่มีการเบิกจ่ายในช่วงเวลาที่เลือก --</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

    <?php elseif ($type == 'material_history'): ?>
        <?php
        // Fetch Supply Name
        $stmtS = $pdo->prepare("SELECT * FROM supplies WHERE id = ?");
        $stmtS->execute([$supplyId]);
        $supply = $stmtS->fetch();

        if (!$supply)
            die("ไม่พบข้อมูลวัสดุ");

        $sql = "SELECT r.approver_supply_date, r.request_name, u.firstname, u.lastname, u.section, ri.qty
                FROM request_items ri
                JOIN requests r ON ri.request_id = r.id
                JOIN users u ON r.user_id = u.id
                WHERE ri.supply_id = ? AND r.status = 'approved' AND YEAR(r.approver_supply_date) = ?";

        $params = [$supplyId, $year];
        $periodStr = "ปี พ.ศ. " . ($year + 543);

        if ($month != 'all') {
            $sql .= " AND MONTH(r.approver_supply_date) = ?";
            $params[] = $month;
            $periodStr .= " เดือน " . thaiMonth($month);
        }

        $sql .= " ORDER BY r.approver_supply_date DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $history = $stmt->fetchAll();
        $totalQty = 0;
        ?>
        <div class="report-info">
            <h2>รายงานประวัติการเบิกรายวัสดุ</h2>
            <p>วัสดุ: <strong><?php echo htmlspecialchars($supply['code'] . ' - ' . $supply['name']); ?></strong></p>
            <p>ช่วงเวลา: <?php echo $periodStr; ?></p>
        </div>
        <table>
            <thead>
                <tr>
                    <th width="20%">วันที่/เวลา</th>
                    <th width="20%">เลขที่ใบเบิก</th>
                    <th width="30%">ผู้เบิก (ส่วนงาน)</th>
                    <th width="15%" class="text-center">จำนวนที่เบิก</th>
                    <th width="15%" class="text-center">หน่วย</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $h):
                    $totalQty += $h['qty']; ?>
                    <tr>
                        <td><?php echo thaiDate($h['approver_supply_date'], true); ?></td>
                        <td><?php echo htmlspecialchars($h['request_name']); ?></td>
                        <td><?php echo htmlspecialchars($h['firstname'] . ' ' . $h['lastname'] . ' (' . $h['section'] . ')'); ?>
                        </td>
                        <td class="text-center font-bold"><?php echo number_format($h['qty']); ?></td>
                        <td class="text-center"><?php echo $supply['unit']; ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr style="background: #f0fdf4;">
                    <td colspan="3" class="text-right font-bold p-3">รวมทั้งหมด</td>
                    <td class="text-center font-bold text-green-700 text-lg"><?php echo number_format($totalQty); ?></td>
                    <td class="text-center font-bold text-green-700"><?php echo $supply['unit']; ?></td>
                </tr>
                <?php if (count($history) == 0): ?>
                    <tr>
                        <td colspan="5" class="text-center py-4">-- ไม่มีประวัติการเบิกในช่วงเวลานี้ --</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

    <?php elseif ($type == 'import_single'): ?>
        <?php
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $sql = "SELECT p.*, CONCAT(u.firstname, ' ', u.lastname) as recorder 
                FROM procurements p
                LEFT JOIN users u ON p.importer_id = u.id
                WHERE p.id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);
        $imports = $stmt->fetchAll();
        ?>
        <div class="report-info">
            <h2>รายงานรายละเอียดการรับเข้าวัสดุ</h2>
            <?php if (count($imports) > 0): ?>
                <p>วันที่รับเข้า: <strong><?php echo thaiDateShort($imports[0]['import_date']); ?></strong></p>
                <p>เลขที่เอกสาร: <strong><?php echo htmlspecialchars($imports[0]['receipt_no']); ?></strong></p>
                <p>ร้านค้า/แหล่งที่มา: <strong><?php echo htmlspecialchars($imports[0]['supplier_name']); ?></strong></p>
                <p>ผู้ทำรายการ: <strong><?php echo htmlspecialchars($imports[0]['recorder']); ?></strong></p>
            <?php endif; ?>
        </div>
        <table>
            <thead>
                <tr>
                    <th width="10%">ที่</th>
                    <th width="45%">รายการวัสดุ</th>
                    <th width="15%" class="text-right">ราคาต่อหน่วย</th>
                    <th width="15%" class="text-center">จำนวน/หน่วย</th>
                    <th width="15%" class="text-right">เป็นเงินรวม</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $grandTotal = 0;
                if (count($imports) > 0) {
                    $imp = $imports[0];
                    $stmtPi = $pdo->prepare("SELECT pi.qty, COALESCE(NULLIF(pi.unit_price, 0), s.price, 0) as price, (pi.qty * COALESCE(NULLIF(pi.unit_price, 0), s.price, 0)) as price_all, s.name, s.unit, s.code FROM procurement_items pi JOIN supplies s ON pi.supply_id = s.id WHERE pi.procurement_id = ?");
                    $stmtPi->execute([$imp['id']]);
                    $pItems = $stmtPi->fetchAll();

                    $index = 1;
                    foreach ($pItems as $pi) {
                        $p_all = isset($pi['price_all']) ? floatval($pi['price_all']) : 0;
                        $p_unit = isset($pi['price']) ? floatval($pi['price']) : 0;
                        $grandTotal += $p_all;
                        ?>
                        <tr>
                            <td class="text-center"><?php echo $index++; ?></td>
                            <td><?php echo htmlspecialchars($pi['name']); ?></td>
                            <td class="text-right"><?php echo number_format($p_unit, 2); ?></td>
                            <td class="text-center"><?php echo $pi['qty'] . ' ' . $pi['unit']; ?></td>
                            <td class="text-right"><strong><?php echo number_format($p_all, 2); ?> บาท</strong></td>
                        </tr>
                        <?php
                    }
                }
                ?>
                <?php if (count($imports) == 0 || empty($pItems)): ?>
                    <tr>
                        <td colspan="5" class="text-center py-4">-- ไม่พบข้อมูลการรับเข้า --</td>
                    </tr>
                <?php else: ?>
                    <tr class="bg-gray-100">
                        <th colspan="4" class="text-right" style="font-size:18px;">รวมเป็นเงินทั้งสิ้น</th>
                        <th class="text-right text-green-700" style="font-size:18px;">
                            <?php echo number_format($grandTotal, 2); ?> บาท
                        </th>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

    <?php elseif ($type == 'import_history'): ?>
        <?php
        $sql = "SELECT p.*, CONCAT(u.firstname, ' ', u.lastname) as recorder 
                FROM procurements p
                LEFT JOIN users u ON p.importer_id = u.id
                WHERE DATE(p.import_date) BETWEEN ? AND ?
                ORDER BY p.import_date DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$startDate, $endDate]);
        $imports = $stmt->fetchAll();
        ?>
        <div class="report-info">
            <h2>รายงานการรับเข้า/จัดซื้อวัสดุ</h2>
            <p>ช่วงวันที่: <strong><?php echo thaiDate($startDate); ?></strong> ถึง
                <strong><?php echo thaiDate($endDate); ?></strong>
            </p>
            <p>จำนวนรายการรับเข้า: <strong><?php echo count($imports); ?></strong> รายการ</p>
        </div>
        <table>
            <thead>
                <tr>
                    <th width="15%">วันที่รับเข้า</th>
                    <th width="15%">เลขที่เอกสาร</th>
                    <th width="15%">ร้านค้า/แหล่งที่มา</th>
                    <th width="25%">รายการวัสดุ (จำนวน)</th>
                    <th width="15%">ผู้ทำรายการ</th>
                    <th width="15%">เป็นเงินรวม</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $grandTotal = 0;
                foreach ($imports as $imp):
                    $stmtPi = $pdo->prepare("SELECT pi.qty, COALESCE(NULLIF(pi.unit_price, 0), s.price, 0) as price, (pi.qty * COALESCE(NULLIF(pi.unit_price, 0), s.price, 0)) as price_all, s.name, s.unit, s.code FROM procurement_items pi JOIN supplies s ON pi.supply_id = s.id WHERE pi.procurement_id = ?");
                    $stmtPi->execute([$imp['id']]);
                    $pItems = $stmtPi->fetchAll();
                    $dItems = [];
                    $rowTotal = 0;
                    foreach ($pItems as $pi) {
                        $p_all = isset($pi['price_all']) ? floatval($pi['price_all']) : 0;
                        $p_unit = isset($pi['price']) ? floatval($pi['price']) : 0;
                        $rowTotal += $p_all;

                        $dItems[] = "<strong style='font-size:16px;'>" . $pi['name'] . "</strong><br>(" . $pi['qty'] . " " . $pi['unit'] . " x " . number_format($p_unit, 2) . " บาท) = " . number_format($p_all, 2) . " บาท";
                    }
                    $grandTotal += $rowTotal;
                    ?>
                    <tr>
                        <td class="text-center"><?php echo thaiDateShort($imp['import_date']); ?></td>
                        <td class="text-center"><?php echo htmlspecialchars($imp['receipt_no']); ?></td>
                        <td><?php echo htmlspecialchars($imp['supplier_name']); ?></td>
                        <td>
                            <ul style="padding-left: 15px; margin: 0;">
                                <?php foreach ($dItems as $d): ?>
                                    <li><?php echo $d; ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </td>
                        <td class="text-center"><?php echo htmlspecialchars($imp['recorder']); ?></td>
                        <td class="text-right"><strong><?php echo number_format($rowTotal, 2); ?> บาท</strong></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (count($imports) == 0): ?>
                    <tr>
                        <td colspan="6" class="text-center py-4">-- ไม่มีการรับเข้าในช่วงเวลานี้ --</td>
                    </tr>
                <?php else: ?>
                    <tr class="bg-gray-100">
                        <th colspan="5" class="text-right" style="font-size:20px;">รวมเป็นเงินทั้งสิ้น</th>
                        <th class="text-right text-green-700" style="font-size:20px;">
                            <?php echo number_format($grandTotal, 2); ?> บาท
                        </th>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

    <?php elseif ($type == 'monthly'): ?>
        <?php
        $monthlyData = array_fill(1, 12, 0);
        $stmt = $pdo->prepare("SELECT MONTH(request_date) as m, COUNT(*) as c FROM requests WHERE YEAR(request_date) = ? GROUP BY m");
        $stmt->execute([$year]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $monthlyData[$row['m']] = $row['c'];
        }
        $monthLabels = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
        ?>
        <div class="report-info">
            <h2>สถิติการเบิกรายเดือน ปี พ.ศ. <?php echo $year + 543; ?></h2>
            <p>แสดงจำนวนครั้งที่มีการเบิกวัสดุแต่ละเดือน</p>
        </div>
        <table>
            <thead>
                <tr>
                    <th width="10%" class="text-center">#</th>
                    <th width="50%">เดือน</th>
                    <th width="40%" class="text-center">จำนวนครั้งที่เบิก</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($monthLabels as $i => $label): ?>
                    <tr>
                        <td class="text-center"><?php echo $i + 1; ?></td>
                        <td><?php echo $label; ?></td>
                        <td class="text-center font-bold"><?php echo number_format($monthlyData[$i + 1]); ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr style="background:#f0fdf4;">
                    <td colspan="2" class="text-right font-bold">รวมทั้งปี</td>
                    <td class="text-center font-bold text-green-700"><?php echo number_format(array_sum($monthlyData)); ?> ครั้ง</td>
                </tr>
            </tbody>
        </table>

    <?php elseif ($type == 'top'): ?>
        <?php
        $stmt = $pdo->query("SELECT s.name as material_name, s.code as material_code, SUM(ri.qty) as total_qty, s.unit
                             FROM request_items ri
                             JOIN supplies s ON ri.supply_id = s.id
                             JOIN requests r ON ri.request_id = r.id
                             WHERE r.status = 'approved'
                             GROUP BY ri.supply_id ORDER BY total_qty DESC LIMIT 10");
        $topItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="report-info">
            <h2>Top 10 วัสดุที่ถูกเบิกสูงสุด</h2>
            <p>วัสดุที่ถูกเบิกมากที่สุดทุกช่วงเวลา (เฉพาะที่อนุมัติแล้ว)</p>
        </div>
        <table>
            <thead>
                <tr>
                    <th width="8%" class="text-center">อันดับ</th>
                    <th width="15%">รหัสวัสดุ</th>
                    <th width="45%">ชื่อวัสดุ</th>
                    <th width="17%" class="text-center">รวมจำนวนที่เบิก</th>
                    <th width="15%" class="text-center">หน่วย</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($topItems as $i => $item): ?>
                    <tr>
                        <td class="text-center font-bold"><?php echo $i + 1; ?></td>
                        <td><?php echo htmlspecialchars($item['material_code']); ?></td>
                        <td><?php echo htmlspecialchars($item['material_name']); ?></td>
                        <td class="text-center font-bold"><?php echo number_format($item['total_qty']); ?></td>
                        <td class="text-center"><?php echo htmlspecialchars($item['unit']); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($topItems)): ?>
                    <tr><td colspan="5" class="text-center py-4">-- ไม่มีข้อมูล --</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

    <?php elseif ($type == 'section'): ?>
        <?php
        $stmt = $pdo->query("SELECT u.section, COUNT(DISTINCT r.id) as request_count, SUM(ri.qty) as total_qty
                             FROM requests r
                             JOIN users u ON r.user_id = u.id
                             JOIN request_items ri ON r.id = ri.request_id
                             WHERE r.status = 'approved'
                             GROUP BY u.section ORDER BY total_qty DESC");
        $sectionStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="report-info">
            <h2>สถิติการเบิกวัสดุแยกตามส่วนงาน</h2>
            <p>สัดส่วนการเบิกของแต่ละส่วนงาน (เฉพาะที่อนุมัติแล้ว)</p>
        </div>
        <table>
            <thead>
                <tr>
                    <th width="5%" class="text-center">#</th>
                    <th width="50%">ส่วนงาน</th>
                    <th width="20%" class="text-center">จำนวนครั้งที่เบิก</th>
                    <th width="25%" class="text-center">รวมจำนวนรายการ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sectionStats as $i => $stat): ?>
                    <tr>
                        <td class="text-center"><?php echo $i + 1; ?></td>
                        <td><?php echo htmlspecialchars($stat['section']); ?></td>
                        <td class="text-center"><?php echo number_format($stat['request_count']); ?> ครั้ง</td>
                        <td class="text-center font-bold"><?php echo number_format($stat['total_qty']); ?> รายการ</td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($sectionStats)): ?>
                    <tr><td colspan="4" class="text-center py-4">-- ไม่มีข้อมูล --</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

    <?php elseif ($type == 'material'): ?>
        <?php
        $stmt = $pdo->query("SELECT u.section, s.name as material_name, s.code as material_code, SUM(ri.qty) as total_qty, s.unit
                             FROM requests r
                             JOIN users u ON r.user_id = u.id
                             JOIN request_items ri ON r.id = ri.request_id
                             JOIN supplies s ON ri.supply_id = s.id
                             WHERE r.status = 'approved'
                             GROUP BY u.section, s.id ORDER BY u.section ASC, total_qty DESC");
        $materialBySection = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="report-info">
            <h2>รายละเอียดการเบิกวัสดุแยกตามส่วนงาน</h2>
            <p>แสดงว่าแต่ละส่วนงานเบิกวัสดุอะไรบ้างและเบิกเท่าไหร่</p>
        </div>
        <table>
            <thead>
                <tr>
                    <th width="25%">ส่วนงาน</th>
                    <th width="15%">รหัสวัสดุ</th>
                    <th width="35%">ชื่อวัสดุ</th>
                    <th width="15%" class="text-center">จำนวนที่เบิก</th>
                    <th width="10%" class="text-center">หน่วย</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($materialBySection as $item): ?>
                    <tr>
                        <td class="font-bold"><?php echo htmlspecialchars($item['section']); ?></td>
                        <td><?php echo htmlspecialchars($item['material_code']); ?></td>
                        <td><?php echo htmlspecialchars($item['material_name']); ?></td>
                        <td class="text-center font-bold"><?php echo number_format($item['total_qty']); ?></td>
                        <td class="text-center"><?php echo htmlspecialchars($item['unit']); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($materialBySection)): ?>
                    <tr><td colspan="5" class="text-center py-4">-- ไม่มีข้อมูล --</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

    <?php elseif ($type == 'recent'): ?>
        <?php
        $stmt = $pdo->query("SELECT r.*, CONCAT(u.prefix, u.firstname, ' ', u.lastname) as fullname, u.section
                             FROM requests r
                             JOIN users u ON r.user_id = u.id
                             ORDER BY r.request_date DESC LIMIT 20");
        $recentRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $statusMap = ['approved' => 'อนุมัติแล้ว', 'rejected' => 'ไม่อนุมัติ', 'pending' => 'รออนุมัติ', 'pending_supply' => 'รอจ่ายวัสดุ'];
        ?>
        <div class="report-info">
            <h2>รายการเบิกล่าสุด 20 รายการ</h2>
            <p>ข้อมูล ณ วันที่: <?php echo thaiDate(date('Y-m-d H:i:s'), true); ?></p>
        </div>
        <table>
            <thead>
                <tr>
                    <th width="15%">วันที่</th>
                    <th width="20%">เลขที่ใบเบิก</th>
                    <th width="25%">ผู้ขอเบิก</th>
                    <th width="25%">ส่วนงาน</th>
                    <th width="15%" class="text-center">สถานะ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentRequests as $r): ?>
                    <tr>
                        <td><?php echo thaiDateShort($r['request_date']); ?></td>
                        <td><?php echo htmlspecialchars($r['request_name']); ?></td>
                        <td><?php echo htmlspecialchars($r['fullname']); ?></td>
                        <td><?php echo htmlspecialchars($r['section']); ?></td>
                        <td class="text-center"><?php echo $statusMap[$r['status']] ?? htmlspecialchars($r['status']); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($recentRequests)): ?>
                    <tr><td colspan="5" class="text-center py-4">-- ไม่มีข้อมูล --</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

    <?php endif; ?>

    <div class="footer">
        ออกรายงานโดย: <?php echo htmlspecialchars($_SESSION['fullname'] ?? ($_SESSION['username'] ?? 'เจ้าหน้าที่')); ?> (<?php echo ucfirst($_SESSION['role'] ?? ''); ?>) |
        <?php echo thaiDate(date('Y-m-d H:i:s'), true); ?>
    </div>
</body>

</html>