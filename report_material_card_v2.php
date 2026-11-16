<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$supply_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($supply_id <= 0) {
    die("Invalid Details ID");
}

// Fetch Supply Name
$stmtS = $pdo->prepare("SELECT * FROM supplies WHERE id = ?");
$stmtS->execute([$supply_id]);
$supply = $stmtS->fetch();

if (!$supply)
    die("ไม่พบข้อมูลวัสดุ");

// History fetching exactly like API
$sqlIn = "SELECT 
            'receive' as type,
            p.import_date as transaction_date,
            p.supplier_name as description,
            p.receipt_no as document_no,
            pi.qty as qty_in,
            0 as qty_out,
            pi.unit_price as price
        FROM procurement_items pi
        JOIN procurements p ON pi.procurement_id = p.id
        WHERE pi.supply_id = ?";

$sqlOut = "SELECT 
            'issue' as type,
            r.approver_supply_date as transaction_date,
            u.section as description,
            r.request_name as document_no,
            0 as qty_in,
            COALESCE(ri.approved_qty, ri.qty) as qty_out,
            0 as price
        FROM request_items ri
        JOIN requests r ON ri.request_id = r.id
        JOIN users u ON r.user_id = u.id
        WHERE ri.supply_id = ? AND r.status IN ('approved', 'completed') AND (ri.is_deleted IS NULL OR ri.is_deleted = 0)";

// echo "DEBUG";
$stmtIn = $pdo->prepare($sqlIn);
$stmtIn->execute([$supply_id]);
$receives = $stmtIn->fetchAll(PDO::FETCH_ASSOC);

$stmtOut = $pdo->prepare($sqlOut);
$stmtOut->execute([$supply_id]);
$issues = $stmtOut->fetchAll(PDO::FETCH_ASSOC);

$transactions = array_merge($receives, $issues);

usort($transactions, function ($a, $b) {
    return strtotime($a['transaction_date']) - strtotime($b['transaction_date']);
});

$balance_qty = 0;
$current_unit_price = 0;

$results = [];
foreach ($transactions as $t) {
    if ($t['type'] == 'receive') {
        $balance_qty += $t['qty_in'];
        $current_unit_price = $t['price'];
        $t['total_price'] = $t['qty_in'] * $t['price'];
    } else {
        $balance_qty -= $t['qty_out'];
        $t['price'] = $current_unit_price;
        $t['total_price'] = $t['qty_out'] * $current_unit_price;
    }

    $t['balance_qty'] = $balance_qty;
    $t['balance_total'] = $balance_qty * $current_unit_price;
    $results[] = $t;
}

// Helper: Thai Date (short)
function thaiDateShort($date)
{
    if (!$date)
        return '-';
    $timestamp = strtotime($date);
    $months = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    $d = date('j', $timestamp);
    $m = $months[(int) date('n', $timestamp)];
    $y = date('Y', $timestamp) + 543;
    return "$d $m $y";
}

?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <title>บัญชีวัสดุ - <?php echo htmlspecialchars($supply['name']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Sarabun', sans-serif;
            background: white;
            color: #000;
            font-size: 13px;
            margin: 0;
            padding: 20px;
        }

        .no-print {
            text-align: center;
            margin-bottom: 20px;
        }

        .no-print button {
            padding: 8px 16px;
            font-family: 'Sarabun';
            font-size: 14px;
            cursor: pointer;
            margin: 0 5px;
        }

        .header-layout {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .col-left,
        .col-center,
        .col-right {
            display: flex;
            flex-direction: column;
            gap: 15px;
            /* increase gap nicely */
        }

        .col-left {
            width: 25%;
        }

        .col-center {
            width: 45%;
        }

        .col-right {
            width: 30%;
        }

        .form-group {
            display: flex;
            align-items: baseline;
            gap: 10px;
        }

        .form-group .label {
            font-weight: 600;
            white-space: nowrap;
        }

        .form-group .value {
            border-bottom: 1px dotted #ccc;
            flex-grow: 1;
            padding-bottom: 2px;
            min-height: 18px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 12px;
        }

        th,
        td {
            border: 1px solid #000;
            padding: 6px;
            text-align: center;
            vertical-align: middle;
        }

        th {
            font-weight: 600;
        }

        .text-left {
            text-align: left;
        }

        .text-right {
            text-align: right;
        }

        @media print {
            .no-print {
                display: none;
            }

            @page {
                size: landscape;
                margin: 10mm;
            }

            body {
                padding: 0;
            }
        }
    </style>
</head>

<body>

    <div class="no-print">
        <button onclick="window.print()">พิมพ์รายงาน</button>
        <button onclick="window.close()">ปิด</button>
    </div>

    <div class="header-layout text-right" style="justify-content: flex-end; margin-bottom: 15px;">
        <div style="display: flex; flex-direction: column; gap: 5px; text-align: left;">
            <div class="form-group"><span class="label">ส่วนราชการ :</span> <span>สำนักงานศาลยุติธรรม</span></div>
            <div class="form-group"><span class="label">หน่วยงาน :</span>
                <span>สำนักอำนวยการประจำศาลจังหวัดอุดรธานี</span>
            </div>
        </div>
    </div>

    <div class="header-layout">
        <div class="col-left">
            <div class="form-group">
                <span class="label">ประเภท</span>
                <span class="value"><?php echo htmlspecialchars($supply['category']); ?></span>
            </div>
            <div class="form-group">
                <span class="label">ขนาดหรือลักษณะ</span>
                <span class="value"></span>
            </div>
            <div class="form-group">
                <span class="label">หน่วยที่นับ</span>
                <span class="value"><?php echo htmlspecialchars($supply['unit']); ?></span>
            </div>
        </div>

        <div class="col-center">
            <div class="form-group">
                <span class="label">ชื่อหรือชนิดวัสดุ</span>
                <span class="value"><?php echo htmlspecialchars($supply['name']); ?></span>
            </div>
            <div class="form-group" style="margin-top:40px;">
                <span class="label">ที่เก็บ</span>
                <span class="value">ห้องพัสดุ ชั้น 1</span>
            </div>
        </div>

        <div class="col-right">
            <div class="form-group">
                <span class="label">รหัส</span>
                <span class="value text-center"
                    style="border: none; text-decoration: underline;"><?php echo htmlspecialchars($supply['code']); ?></span>
            </div>
            <div class="form-group" style="margin-top:15px;">
                <span class="label">จำนวนอย่างสูง</span>
                <span
                    class="value text-center px-4"><?php echo $supply['max_order'] > 0 ? htmlspecialchars($supply['max_order']) : 'ไม่จำกัด'; ?></span>
                <span><?php echo htmlspecialchars($supply['unit']); ?></span>
            </div>
            <div class="form-group">
                <span class="label">จำนวนอย่างต่ำ</span>
                <span class="value text-center px-4"><?php echo htmlspecialchars($supply['min_stock']); ?></span>
                <span><?php echo htmlspecialchars($supply['unit']); ?></span>
            </div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th rowspan="2" width="10%">วัน เดือน ปี</th>
                <th rowspan="2" width="20%">รายการ <br>(รับ - จ่าย)</th>
                <th colspan="4">รายการรับ</th>
                <th colspan="4">รายการจ่าย</th>
                <th colspan="2">คงเหลือ</th>
                <th rowspan="2" width="10%">หมายเหตุ</th>
            </tr>
            <tr>
                <th width="8%">ใบรับที่</th>
                <th width="5%">จำนวน</th>
                <th width="6%">ราคา<br>ต่อหน่วย</th>
                <th width="6%">จำนวนเงิน</th>

                <th width="8%">ใบเบิกที่</th>
                <th width="5%">จำนวน</th>
                <th width="6%">ราคา<br>ต่อหน่วย</th>
                <th width="6%">จำนวนเงิน</th>

                <th width="5%">จำนวน</th>
                <th width="5%">จำนวนเงิน</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="text-center">-</td>
                <?php
                $current_m = (int) date('n');
                $current_y_be = (int) date('Y') + 543;
                if ($current_m >= 10) {
                    $bf_text = "ยอดยกมา 1 ตุลาคม " . ($current_y_be - 1);
                } else {
                    $bf_text = "ยอดยกมา 1 ตุลาคม " . ($current_y_be - 1);
                }
                ?>
                <td class="text-left">
                    <?php echo $bf_text; ?>
                </td>

                <td></td>
                <td>0</td>
                <td></td>
                <td>0</td>

                <td></td>
                <td></td>
                <td></td>
                <td></td>

                <td>0</td>
                <td>0</td>
                <td></td>
            </tr>

            <?php foreach ($results as $h): ?>
                <tr>
                    <td><?php echo thaiDateShort($h['transaction_date']); ?></td>
                    <td class="text-left"><?php echo htmlspecialchars($h['description']); ?></td>

                    <?php if ($h['type'] == 'receive'): ?>
                        <!-- รับ -->
                        <td><?php echo htmlspecialchars($h['document_no']); ?></td>
                        <td><?php echo number_format($h['qty_in']); ?></td>
                        <td><?php echo number_format($h['price'], 2); ?></td>
                        <td><?php echo number_format($h['total_price'], 2); ?></td>

                        <!-- จ่าย -->
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                    <?php else: ?>
                        <!-- รับ -->
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>

                        <!-- จ่าย -->
                        <td><?php echo htmlspecialchars($h['document_no']); ?></td>
                        <td><?php echo number_format($h['qty_out']); ?></td>
                        <td><?php echo number_format($h['price'], 2); ?></td>
                        <td><?php echo number_format($h['total_price'], 2); ?></td>
                    <?php endif; ?>

                    <!-- คงเหลือ -->
                    <td><?php echo number_format($h['balance_qty']); ?></td>
                    <td><?php echo number_format($h['balance_total'], 2); ?></td>

                    <td></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div style="margin-top: 30px; text-align: left; font-size: 13px;">
        <span style="float:left;">วันที่พิมพ์ :
            <?php echo date('d') . ' ' . ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'][(int) date('m')] . ' ' . (date('Y') + 543) . ' ' . date('H:i:s'); ?></span>
        <span style="float:right;">ที่มา : ระบบเบิกวัสดุสำนักงานออนไลน์ [Durables]</span>
    </div>

</body>

</html>