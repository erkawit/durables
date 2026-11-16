<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'director', 'supply_head', 'supply_officer'])) {
    header("Location: dashboard.php");
    exit;
}

$userId    = isset($_GET['user_id'])    ? (int)$_GET['user_id']    : 0;
$startDate = isset($_GET['start_date']) ? $_GET['start_date']      : date('Y-m-01');
$endDate   = isset($_GET['end_date'])   ? $_GET['end_date']        : date('Y-m-t');
$userName  = isset($_GET['user_name'])  ? trim($_GET['user_name']) : 'ทุกคน';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) $startDate = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate))   $endDate   = date('Y-m-t');

$params = [$startDate . ' 00:00:00', $endDate . ' 23:59:59'];
$userCond = '';
if ($userId > 0) {
    $userCond = ' AND r.user_id = ?';
    $params[] = $userId;
}

$sql = "SELECT
            ri.id                                                    AS item_id,
            r.request_name                                           AS req_code,
            r.request_date,
            CONCAT(u.prefix, u.firstname, ' ', u.lastname)          AS requester,
            u.section,
            s.code                                                   AS supply_code,
            s.name                                                   AS supply_name,
            COALESCE(ri.approved_qty, ri.qty)                        AS qty,
            s.unit,
            COALESCE(s.price, 0)                                     AS unit_price,
            COALESCE(s.price, 0) * COALESCE(ri.approved_qty, ri.qty) AS total_price
        FROM request_items ri
        JOIN requests r  ON r.id  = ri.request_id
        JOIN users    u  ON u.id  = r.user_id
        JOIN supplies s  ON s.id  = ri.supply_id
        WHERE r.request_date BETWEEN ? AND ?
          AND r.status NOT IN ('cancelled', 'rejected')
          AND (ri.is_deleted IS NULL OR ri.is_deleted = 0)
          $userCond
        ORDER BY r.request_date ASC, ri.id ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$grandTotal = 0;
foreach ($items as &$row) { $grandTotal += (float)$row['total_price']; }
unset($row);

function thaiMonths() {
    return [1=>'มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
}
function thaiDateFull($d) {
    if (!$d) return '-';
    $ts = strtotime($d);
    $m  = thaiMonths()[(int)date('n', $ts)];
    return date('j', $ts) . ' ' . $m . ' ' . (date('Y', $ts) + 543);
}
function thaiDateShort($d) {
    if (!$d) return '-';
    $ts = strtotime($d);
    $ms = [1=>'ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    return date('j', $ts) . ' ' . $ms[(int)date('n', $ts)] . ' ' . (date('Y', $ts) + 543);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>รายการเบิกวัสดุรายบุคคล</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ── Page setup ── */
        @page { size: A4 landscape; margin: 10mm 12mm 10mm 15mm; }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Sarabun', sans-serif; font-size: 13px; color: #1e293b; background: #e5e7eb; }

        /* Screen: 80% wide, centred */
        .page {
            width: 80%;
            min-height: auto;
            margin: 0 auto;
            padding: 20px 24px 24px;
            background: #fff;
            box-shadow: 0 4px 24px rgba(0,0,0,.12);
            border-radius: 8px;
        }

        .print-header { text-align: center; margin-bottom: 16px; border-bottom: 2px solid #17365a; padding-bottom: 12px; }
        .print-header h1 { font-size: 18px; font-weight: 700; color: #17365a; }
        .print-header h2 { font-size: 14px; font-weight: 600; margin-top: 4px; color: #334155; }
        .print-header p  { font-size: 12px; color: #64748b; margin-top: 4px; }

        .meta-row { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 4px; margin-bottom: 12px; font-size: 12px; color: #475569; }

        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        thead th {
            background: #1e3a5f; color: #fff; padding: 8px 10px;
            text-align: left; font-size: 12px; font-weight: 600;
            white-space: nowrap;
        }
        thead th.center { text-align: center; }
        thead th.right  { text-align: right; }

        tbody tr:nth-child(even) { background: #f8fafc; }
        tbody td { padding: 7px 10px; border-bottom: 1px solid #e2e8f0; font-size: 12px; vertical-align: middle; }
        tbody td.center { text-align: center; }
        tbody td.right  { text-align: right; }

        tfoot td { padding: 9px 10px; font-weight: 700; font-size: 13px; border-top: 2px solid #1e3a5f; }
        tfoot td.right { text-align: right; }

        .total-box {
            margin-top: 14px; text-align: right;
            border: 2px solid #1e3a5f; display: inline-block;
            padding: 8px 20px; border-radius: 8px; float: right;
        }
        .total-box .label  { font-size: 12px; color: #64748b; }
        .total-box .amount { font-size: 18px; font-weight: 700; color: #1e3a5f; }

        .no-data { text-align: center; padding: 40px; color: #94a3b8; font-size: 14px; }

        .print-footer {
            margin-top: 24px; clear: both; border-top: 1px solid #e2e8f0;
            padding-top: 8px; display: flex; justify-content: space-between;
            font-size: 11px; color: #94a3b8;
        }

        /* Screen toolbar (hidden when printing) */
        .toolbar {
            position: fixed; top: 0; left: 0; right: 0;
            background: #1e3a5f; color: #fff; padding: 10px 20px;
            display: flex; justify-content: space-between; align-items: center; z-index: 999;
        }
        .toolbar button {
            background: #fff; color: #1e3a5f; border: none; padding: 6px 18px;
            border-radius: 6px; font-family: 'Sarabun', sans-serif;
            font-size: 13px; font-weight: 700; cursor: pointer;
        }
        .toolbar button:hover { background: #e0e7ef; }

        @media print {
            @page { size: A4 landscape; margin: 10mm 12mm 10mm 15mm; }

            body { background: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

            .toolbar { display: none !important; }

            /* Full printable width, no card shadow */
            .page {
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
                border-radius: 0 !important;
            }
        }
    </style>
</head>
<body>

<!-- Toolbar (screen only) -->
<div class="toolbar">
    <span><i>รายการเบิกวัสดุรายบุคคล</i> — พร้อมพิมพ์</span>
    <div style="display:flex;gap:10px">
        <button onclick="window.print()">🖨 พิมพ์ / บันทึก PDF</button>
        <button onclick="window.close()">✕ ปิด</button>
    </div>
</div>

<div class="page" style="margin-top:58px;margin-bottom:32px">
    <!-- Header -->
    <div class="print-header">
        <h1>รายงานรายการเบิกวัสดุรายบุคคล</h1>
        <h2>ระบบบริหารจัดการวัสดุคงคลัง</h2>
        <p>พิมพ์เมื่อ: <?php echo thaiDateFull(date('Y-m-d')); ?> เวลา <?php echo date('H:i'); ?> น.</p>
    </div>

    <!-- Meta info -->
    <div class="meta-row">
        <span><strong>ผู้ขอเบิก:</strong> <?php echo htmlspecialchars($userName); ?></span>
        <span><strong>ช่วงเวลา:</strong> <?php echo thaiDateShort($startDate); ?> — <?php echo thaiDateShort($endDate); ?></span>
        <span><strong>จำนวนรายการ:</strong> <?php echo count($items); ?> รายการ</span>
    </div>

    <?php if (empty($items)): ?>
        <div class="no-data">
            <p>ไม่มีข้อมูลสำหรับเงื่อนไขที่เลือก</p>
        </div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th style="width:48px" class="center">ลำดับ</th>
                    <th style="width:130px">เลขที่ใบเบิก</th>
                    <th style="width:95px">วันที่</th>
                    <th style="width:160px">ผู้ขอเบิก</th>
                    <th>ชื่อวัสดุที่เบิก</th>
                    <th style="width:100px" class="center">จำนวน/หน่วย</th>
                    <th style="width:110px" class="right">ราคา/หน่วย (บาท)</th>
                    <th style="width:110px" class="right">รวม (บาท)</th>
                </tr>
            </thead>
            <tbody>
                <?php $no = 1; foreach ($items as $item): ?>
                <tr>
                    <td class="center"><?php echo $no++; ?></td>
                    <td style="font-family:monospace;font-size:11px"><?php echo htmlspecialchars($item['req_code']); ?></td>
                    <td style="font-size:11px"><?php echo thaiDateShort($item['request_date']); ?></td>
                    <td>
                        <?php echo htmlspecialchars($item['requester']); ?>
                        <br><span style="font-size:10px;color:#94a3b8"><?php echo htmlspecialchars($item['section']); ?></span>
                    </td>
                    <td>
                        <?php echo htmlspecialchars($item['supply_name']); ?>
                        <br><span style="font-size:10px;color:#94a3b8;font-family:monospace"><?php echo htmlspecialchars($item['supply_code']); ?></span>
                    </td>
                    <td class="center" style="font-weight:600"><?php echo number_format($item['qty']); ?> <?php echo htmlspecialchars($item['unit']); ?></td>
                    <td class="right"><?php echo number_format((float)$item['unit_price'], 2); ?></td>
                    <td class="right" style="font-weight:600"><?php echo number_format((float)$item['total_price'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="7" class="right">รวมมูลค่าทั้งหมด:</td>
                    <td class="right" style="color:#1e3a5f;font-size:15px"><?php echo number_format($grandTotal, 2); ?> บาท</td>
                </tr>
            </tfoot>
        </table>

        <div style="clear:both;margin-top:12px">
            <div class="total-box">
                <div class="label">มูลค่ารวมทั้งสิ้น</div>
                <div class="amount"><?php echo number_format($grandTotal, 2); ?> บาท</div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Signature block -->
    <div style="clear:both;margin-top:48px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;text-align:center">
        <div>
            <div style="border-bottom:1px solid #334155;margin-bottom:4px;height:32px"></div>
            <p style="font-size:11px;color:#64748b">ผู้จัดทำรายงาน</p>
        </div>
        <div>
            <div style="border-bottom:1px solid #334155;margin-bottom:4px;height:32px"></div>
            <p style="font-size:11px;color:#64748b">ผู้ตรวจสอบ</p>
        </div>
        <div>
            <div style="border-bottom:1px solid #334155;margin-bottom:4px;height:32px"></div>
            <p style="font-size:11px;color:#64748b">ผู้อนุมัติ</p>
        </div>
    </div>

    <div class="print-footer">
        <span>ระบบบริหารจัดการวัสดุคงคลัง</span>
        <span>พิมพ์โดย: <?php echo htmlspecialchars($_SESSION['fullname'] ?? '-'); ?></span>
        <span><?php echo thaiDateFull(date('Y-m-d')); ?></span>
    </div>
</div>
</body>
</html>
