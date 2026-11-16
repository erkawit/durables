<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    if (isset($_POST['action']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Session expired, please login again.', 'logged_out' => true]);
        exit;
    }
    header("Location: index.php");
    exit;
}

// ── Cart TTL Constants (30 Minutes Hold) ──
if (!defined('CART_TTL_MINUTES')) define('CART_TTL_MINUTES', 30);
if (!defined('CART_TTL_SECONDS')) define('CART_TTL_SECONDS', 30 * 60);

function cleanExpiredCartItems($pdo) {
    try {
        $cutoff = date('Y-m-d H:i:s', time() - (CART_TTL_MINUTES * 60));
        $stmt = $pdo->prepare("DELETE FROM cart_items WHERE added_at < ?");
        $stmt->execute([$cutoff]);
    } catch (Exception $e) {}
}

cleanExpiredCartItems($pdo);

// ── Helper: get effective max for a supply (stock - other users' carts within TTL, capped by max_order) ──
function getEffectiveMax($pdo, $supplyId, $userId) {
    $stmt = $pdo->prepare("SELECT qty, min_order, max_order FROM supplies WHERE id = ?");
    $stmt->execute([$supplyId]);
    $item = $stmt->fetch();
    if (!$item) return ['effective_max' => 0, 'stock' => 0, 'min_order' => 1, 'max_order' => 0, 'other_total' => 0];

    $minOrder = ($item['min_order'] > 0) ? $item['min_order'] : 1;
    $maxOrder = ($item['max_order'] > 0) ? $item['max_order'] : 999999;
    $stock    = (int)$item['qty'];

    $cutoff = date('Y-m-d H:i:s', time() - (CART_TTL_MINUTES * 60));
    $stmt2 = $pdo->prepare("SELECT COALESCE(SUM(qty),0) FROM cart_items WHERE supply_id = ? AND user_id != ? AND added_at >= ?");
    $stmt2->execute([$supplyId, $userId, $cutoff]);
    $otherTotal = (int)$stmt2->fetchColumn();

    $effectiveMax = min($stock - $otherTotal, $maxOrder);
    if ($effectiveMax < 0) $effectiveMax = 0;

    return compact('effectiveMax', 'stock', 'minOrder', 'maxOrder', 'otherTotal');
}

// ── AJAX: check_stock_live — returns real-time stock info for all cart items ──
if (isset($_POST['action']) && $_POST['action'] == 'check_stock_live') {
    $userId = $_SESSION['user_id'];
    $result = [];
    if (!empty($_SESSION['cart'])) {
        foreach ($_SESSION['cart'] as $supplyId => $cartQty) {
            $info = getEffectiveMax($pdo, $supplyId, $userId);
            $result[$supplyId] = [
                'stock'        => $info['stock'],
                'other_total'  => $info['otherTotal'],
                'effective_max'=> $info['effectiveMax'],
                'min_order'    => $info['minOrder'],
                'max_order'    => $info['maxOrder'],
                'cart_qty'     => $cartQty,
                'over_limit'   => ($cartQty > $info['effectiveMax']),
            ];
        }
    }
    echo json_encode(['status' => 'ok', 'items' => $result]);
    exit;
}

// ── AJAX: extend_cart_time — renew the 30-min hold for the current user ──
if (isset($_POST['action']) && $_POST['action'] == 'extend_cart_time') {
    $userId = $_SESSION['user_id'];
    $pdo->prepare("UPDATE cart_items SET added_at = NOW() WHERE user_id = ?")->execute([$userId]);
    if (!empty($_SESSION['cart'])) {
        foreach (array_keys($_SESSION['cart']) as $sid) {
            $_SESSION['cart_time'][$sid] = time();
        }
    }
    echo json_encode([
        'status' => 'success',
        'ttl_seconds' => CART_TTL_SECONDS,
        'message' => 'ต่อเวลาการจองสต็อกอีก 30 นาทีเรียบร้อยแล้ว'
    ]);
    exit;
}

// ── AJAX: check_old_cart — checks items older than 12 hours & insufficient stock ──
if (isset($_POST['action']) && $_POST['action'] == 'check_old_cart') {
    $userId = $_SESSION['user_id'];
    $oldItems = [];
    $insufficientItems = [];
    $twelveHoursAgo = time() - (12 * 3600);

    if (!empty($_SESSION['cart'])) {
        // Get added_at times from DB
        $ids = implode(',', array_map('intval', array_keys($_SESSION['cart'])));
        $stmt = $pdo->prepare("SELECT ci.supply_id, ci.added_at, s.name, s.unit, s.qty as stock, s.min_order, s.max_order
                               FROM cart_items ci JOIN supplies s ON s.id = ci.supply_id
                               WHERE ci.user_id = ? AND ci.supply_id IN ($ids)");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $supplyId = $row['supply_id'];
            $cartQty  = $_SESSION['cart'][$supplyId] ?? 0;
            $addedAt  = strtotime($row['added_at']);
            $stock    = (int)$row['stock'];
            $minOrder = ($row['min_order'] > 0) ? (int)$row['min_order'] : 1;

            if ($addedAt < $twelveHoursAgo) {
                $oldItems[] = ['id' => $supplyId, 'name' => $row['name'],
                               'added_at' => $row['added_at'], 'cart_qty' => $cartQty];
            }
            if ($cartQty > $stock || $stock <= 0) {
                $insufficientItems[] = ['id' => $supplyId, 'name' => $row['name'],
                                        'cart_qty' => $cartQty, 'stock' => $stock,
                                        'unit' => $row['unit']];
            }
        }
    }
    echo json_encode([
        'old_items'         => $oldItems,
        'insufficient_items'=> $insufficientItems,
    ]);
    exit;
}

// ── AJAX: update_qty ──
if (isset($_POST['action']) && $_POST['action'] == 'update_qty') {
    $id  = (int)$_POST['id'];
    $qty = (int)$_POST['qty'];
    $userId = $_SESSION['user_id'];

    $info = getEffectiveMax($pdo, $id, $userId);
    if (!$info['stock'] && $info['stock'] !== 0) {
        unset($_SESSION['cart'][$id]);
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบรายการวัสดุ']);
        exit;
    }

    if ($qty > $info['effectiveMax']) $qty = $info['effectiveMax'];
    if ($qty < $info['minOrder'])     $qty = $info['minOrder'];

    $_SESSION['cart'][$id] = $qty;
    $pdo->prepare("UPDATE cart_items SET qty=? WHERE user_id=? AND supply_id=?")
        ->execute([$qty, $userId, $id]);

    echo json_encode([
        'status'        => 'success',
        'qty'           => $qty,
        'effective_max' => $info['effectiveMax'],
        'min_order'     => $info['minOrder'],
    ]);
    exit;
}

// ── AJAX: remove ──
if (isset($_POST['action']) && $_POST['action'] == 'remove' && isset($_POST['id'])) {
    $sid = (int)$_POST['id'];
    unset($_SESSION['cart'][$sid]);
    $pdo->prepare('DELETE FROM cart_items WHERE user_id=? AND supply_id=?')
        ->execute([$_SESSION['user_id'], $sid]);
    echo json_encode(['status' => 'success']);
    exit;
}

// ── AJAX: clear_cart ──
if (isset($_POST['action']) && $_POST['action'] == 'clear_cart') {
    unset($_SESSION['cart']);
    $pdo->prepare('DELETE FROM cart_items WHERE user_id=?')->execute([$_SESSION['user_id']]);
    echo json_encode(['status' => 'success']);
    exit;
}

// ── AJAX: verify_stock — pre-submit; also returns adjusted quantities ──
if (isset($_POST['action']) && $_POST['action'] == 'verify_stock') {
    $userId = $_SESSION['user_id'];
    $invalidItems = [];
    $validItems   = [];
    $adjustedItems = []; // items that will be auto-reduced

    if (!empty($_SESSION['cart'])) {
        $ids = implode(',', array_map('intval', array_keys($_SESSION['cart'])));
        $stmt = $pdo->query("SELECT id, name, qty, unit, code, min_order, max_order FROM supplies WHERE id IN ($ids)");
        $products = [];
        foreach ($stmt->fetchAll() as $p) $products[$p['id']] = $p;

        foreach ($_SESSION['cart'] as $mid => $reqQty) {
            if (!isset($products[$mid])) continue;
            $p        = $products[$mid];
            $stock    = (int)$p['qty'];
            $minOrder = ($p['min_order'] > 0) ? (int)$p['min_order'] : 1;
            $maxOrder = ($p['max_order'] > 0) ? (int)$p['max_order'] : 999999;

            // Get other users' cart reservations within TTL (30 minutes)
            $cutoff = date('Y-m-d H:i:s', time() - (CART_TTL_MINUTES * 60));
            $stmt2 = $pdo->prepare("SELECT COALESCE(SUM(qty),0) FROM cart_items WHERE supply_id=? AND user_id!=? AND added_at >= ?");
            $stmt2->execute([$mid, $userId, $cutoff]);
            $otherTotal = (int)$stmt2->fetchColumn();
            $available  = $stock - $otherTotal;

            $row = ['id' => $mid, 'name' => $p['name'], 'req_qty' => $reqQty,
                    'stock' => $stock, 'unit' => $p['unit'] ?? '',
                    'min_order' => $minOrder, 'max_order' => $maxOrder,
                    'available' => $available];

            if ($stock <= 0 || $available < $minOrder) {
                // Cannot fulfil even minimum
                $row['insufficient'] = true;
                $row['reject_reason'] = 'ไม่เพียงพอ';
                $invalidItems[] = $row;
            } elseif ($reqQty > $available) {
                // Can partially fulfil — reduce qty
                $adjustedQty = min($available, $maxOrder);
                $adjustedQty = max($adjustedQty, $minOrder);
                $row['insufficient']  = false;
                $row['adjusted']      = true;
                $row['adjusted_qty']  = $adjustedQty;
                $adjustedItems[] = $row;
                $validItems[] = $row;
            } else {
                $row['insufficient'] = false;
                $row['adjusted']     = false;
                $validItems[] = $row;
            }
        }
    }

    echo json_encode([
        'status'         => (count($invalidItems) > 0) ? 'invalid' : 'valid',
        'invalid_items'  => $invalidItems,
        'valid_items'    => $validItems,
        'adjusted_items' => $adjustedItems,
    ]);
    exit;
}

// ── AJAX: apply_adjustments — persist adjusted quantities to session/DB ──
if (isset($_POST['action']) && $_POST['action'] == 'apply_adjustments') {
    $adjustments = isset($_POST['adjustments']) ? $_POST['adjustments'] : [];
    $removals    = isset($_POST['removals'])    ? $_POST['removals']    : [];
    $userId = $_SESSION['user_id'];

    foreach ($adjustments as $sid => $newQty) {
        $sid    = (int)$sid;
        $newQty = (int)$newQty;
        if ($newQty > 0 && isset($_SESSION['cart'][$sid])) {
            $_SESSION['cart'][$sid] = $newQty;
            $pdo->prepare("UPDATE cart_items SET qty=? WHERE user_id=? AND supply_id=?")
                ->execute([$newQty, $userId, $sid]);
        }
    }
    foreach ($removals as $sid) {
        $sid = (int)$sid;
        unset($_SESSION['cart'][$sid]);
        $pdo->prepare('DELETE FROM cart_items WHERE user_id=? AND supply_id=?')
            ->execute([$userId, $sid]);
    }
    echo json_encode(['status' => 'success', 'remaining' => count($_SESSION['cart'] ?? [])]);
    exit;
}

// ── AJAX: remove_insufficient ──
if (isset($_POST['action']) && $_POST['action'] == 'remove_insufficient') {
    $ids = isset($_POST['ids']) ? $_POST['ids'] : [];
    foreach ($ids as $sid) {
        $sid = (int)$sid;
        unset($_SESSION['cart'][$sid]);
        $pdo->prepare('DELETE FROM cart_items WHERE user_id=? AND supply_id=?')
            ->execute([$_SESSION['user_id'], $sid]);
    }
    echo json_encode(['status' => 'success', 'remaining' => count($_SESSION['cart'] ?? [])]);
    exit;
}

// ── AJAX: submit_request ──
if (isset($_POST['action']) && $_POST['action'] == 'submit_request') {
    if (empty($_SESSION['cart'])) {
        echo json_encode(['status' => 'error', 'message' => 'ตะกร้าสินค้าว่างเปล่า']);
        exit;
    }
    $reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';
    if (empty($reason)) {
        echo json_encode(['status' => 'error', 'message' => 'กรุณาระบุเหตุผลการเบิก']);
        exit;
    }

    $userId = $_SESSION['user_id'];

    $useNode = ($sys_settings['node_service_enabled'] ?? '1') === '1';

    if ($useNode) {
        // ── 1. Call Node.js Real-time Stock Check ──
        $nodeItems = [];
        foreach ($_SESSION['cart'] as $supplyId => $qty) {
            $nodeItems[] = [
                'supply_id' => (int)$supplyId,
                'qty' => (int)$qty
            ];
        }
        
        $ch = curl_init('http://localhost:3000/api/check-stock');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['items' => $nodeItems]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $resObj = json_decode($response, true);
            if ($resObj && $resObj['status'] === 'insufficient') {
                $msgDetails = [];
                foreach ($resObj['items'] as $ins) {
                    $msgDetails[] = "- " . $ins['name'] . " (คงเหลือจริง: " . $ins['available'] . " " . ($ins['unit'] ?? 'ชิ้น') . ")";
                }
                echo json_encode([
                    'status' => 'error', 
                    'message' => "พัสดุบางรายการไม่เพียงพอในคลังจริง:\n" . implode("\n", $msgDetails)
                ]);
                exit;
            }
        }
    }

    try {
        $pdo->beginTransaction();
        cleanExpiredCartItems($pdo);

        // ── Atomic Row-Level Lock & Stock Verification (FOR UPDATE) ──
        $cartSupplyIds = array_map('intval', array_keys($_SESSION['cart']));
        $placeholders  = implode(',', array_fill(0, count($cartSupplyIds), '?'));
        $stmtLock = $pdo->prepare("SELECT id, name, qty, unit, min_order, max_order FROM supplies WHERE id IN ($placeholders) FOR UPDATE");
        $stmtLock->execute($cartSupplyIds);
        $lockedItems = [];
        foreach ($stmtLock->fetchAll(PDO::FETCH_ASSOC) as $lItem) {
            $lockedItems[$lItem['id']] = $lItem;
        }

        // Check each requested item against the locked stock
        $insufficientStockList = [];
        foreach ($_SESSION['cart'] as $sId => $rQty) {
            if (!isset($lockedItems[$sId])) {
                $insufficientStockList[] = "ไม่พบรายการวัสดุ (ID: $sId) ในระบบ";
                continue;
            }
            $lItem = $lockedItems[$sId];
            $stockNow = (int)$lItem['qty'];
            $rQty     = (int)$rQty;
            $minOrd   = ($lItem['min_order'] > 0) ? (int)$lItem['min_order'] : 1;

            if ($stockNow < $minOrd) {
                $insufficientStockList[] = "• " . $lItem['name'] . ": สินค้าหมดหรือเหลือน้อยกว่าขั้นต่ำ ($minOrd {$lItem['unit']}) ปัจจุบันคงเหลือเพียง $stockNow {$lItem['unit']}";
            } elseif ($rQty > $stockNow) {
                $insufficientStockList[] = "• " . $lItem['name'] . ": ขอเบิก $rQty {$lItem['unit']} แต่ในคลังเหลือจริงเพียง $stockNow {$lItem['unit']}";
            }
        }

        if (!empty($insufficientStockList)) {
            $pdo->rollBack();
            echo json_encode([
                'status'   => 'error',
                'conflict' => true,
                'message'  => "ไม่สามารถบันทึกการเบิกได้ เนื่องจากสต็อกในคลังมีการเปลี่ยนแปลงตัดหน้า:\n\n" . implode("\n", $insufficientStockList) . "\n\nระบบจะรีเฟรชหน้าจอเพื่อให้คุณปรับจำนวนวัสดุในตะกร้าใหม่"
            ]);
            exit;
        }

        $dateStr = date('Ymd');
        $prefix  = "W-$dateStr-";
        $stmt    = $pdo->query("SELECT COUNT(*) FROM requests WHERE DATE(request_date) = CURRENT_DATE");
        $count   = $stmt->fetchColumn() + 1;
        $withdrawCode = $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);

        $status = 'pending_head';
        $role = $_SESSION['role'];
        $section = $_SESSION['section'] ?? '';

        $approver_head_id = null;
        $approver_head_date = null;
        $approver_supply_id = null;
        $approver_supply_date = null;
        $approver_director_id = null;
        $approver_director_date = null;

        if (in_array($role, ['supply_head', 'director'])) {
            $status = 'approved';
            $approver_head_id = $userId;
            $approver_head_date = date('Y-m-d H:i:s');
            $approver_supply_id = $userId;
            $approver_supply_date = date('Y-m-d H:i:s');
            $approver_director_id = $userId;
            $approver_director_date = date('Y-m-d H:i:s');
        } elseif (in_array($role, ['admin', 'head', 'deputy_head'])) {
            $status = 'pending_supply';
            $approver_head_id = $userId;
            $approver_head_date = date('Y-m-d H:i:s');
        }

        $stmt = $pdo->prepare("INSERT INTO requests (user_id, request_name, status, request_date, reason, section, approver_head_id, approver_head_date, approver_supply_id, approver_supply_date, approver_director_id, approver_director_date) VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $withdrawCode, $status, $reason, $section, $approver_head_id, $approver_head_date, $approver_supply_id, $approver_supply_date, $approver_director_id, $approver_director_date]);
        $requestId = $pdo->lastInsertId();

        $stmtItem = $pdo->prepare("INSERT INTO request_items (request_id, supply_id, qty) VALUES (?, ?, ?)");
        foreach ($_SESSION['cart'] as $supplyId => $qty) {
            $stmtItem->execute([$requestId, $supplyId, $qty]);
        }

        // ── 2. Decrement physical stock on immediate approval (Atomic Check) ──
        if ($status == 'approved') {
            $stmtDeduct = $pdo->prepare("UPDATE supplies SET qty = qty - ? WHERE id = ? AND qty >= ?");
            foreach ($_SESSION['cart'] as $supplyId => $qty) {
                $stmtDeduct->execute([$qty, $supplyId, $qty]);
                if ($stmtDeduct->rowCount() === 0) {
                    $pdo->rollBack();
                    echo json_encode([
                        'status'   => 'error',
                        'conflict' => true,
                        'message'  => "เกิดข้อผิดพลาดในการตัดยอดสต็อก พัสดุอาจถูกเบิกไปแล้ว กรุณาลองใหม่อีกครั้ง"
                    ]);
                    exit;
                }
            }
        }

        unset($_SESSION['cart']);
        unset($_SESSION['cart_time']);
        $pdo->prepare('DELETE FROM cart_items WHERE user_id=?')->execute([$_SESSION['user_id']]);
        $pdo->commit();

        // ── 3. Notify Node.js Coordinator ──
        if ($useNode) {
            $chNotify = curl_init('http://localhost:3000/api/on-action');
            curl_setopt($chNotify, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chNotify, CURLOPT_POST, true);
            curl_setopt($chNotify, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($chNotify, CURLOPT_POSTFIELDS, json_encode([
                'request_id' => $requestId,
                'action_type' => 'submit',
                'actor_id' => $userId
            ]));
            curl_setopt($chNotify, CURLOPT_TIMEOUT, 2);
            curl_exec($chNotify);
            curl_close($chNotify);
        }

        try {
            $requesterSection = $_SESSION['section'] ?? '';
            $requesterName    = $_SESSION['fullname'] ?? 'ผู้ใช้งาน';
            $notifyCart = function ($uid, $msg, $link, $type) use ($pdo, $requestId) {
                if (!$uid) return;
                $pdo->prepare("INSERT INTO notifications (user_id, message, link, type, related_id) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$uid, $msg, $link, $type, $requestId]);
            };

            if ($status == 'pending_supply') {
                $targets = $pdo->query("SELECT id, role FROM users WHERE role IN ('supply_head','supply_officer','director') AND status='active'")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($targets as $t) {
                    if ($t['role'] == 'director') {
                        $notifyCart($t['id'], "มีรายการจากส่วน {$requesterSection} รออนุมัติจากฝ่ายพัสดุ", "approvals.php", 'section_pending_supply');
                    } else {
                        $notifyCart($t['id'], "มีรายการรออนุมัติใหม่จากส่วนงาน {$requesterSection}", "approvals.php", 'section_pending_supply');
                    }
                }
            } elseif ($status == 'approved') {
                $officers = $pdo->query("SELECT id FROM users WHERE role='supply_officer' AND status='active'")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($officers as $sid) {
                    $notifyCart($sid, "ผู้อำนวยการอนุมัติแล้ว รหัส {$withdrawCode} พร้อมเบิกจ่าย", "approvals.php", 'supply_approved');
                }
            } else {
                $heads = $pdo->prepare("SELECT id FROM users WHERE role IN ('head', 'deputy_head') AND section=? AND status='active'");
                $heads->execute([$requesterSection]);
                foreach ($heads->fetchAll(PDO::FETCH_COLUMN) as $hid) {
                    $notifyCart($hid, "{$requesterName} ยืนยันรายการเบิก {$withdrawCode} รออนุมัติ", "approvals.php", 'request_new');
                }
            }

            foreach ($pdo->query("SELECT id FROM users WHERE role='admin' AND status='active'")->fetchAll(PDO::FETCH_COLUMN) as $aid) {
                if ($aid != $_SESSION['user_id'])
                    $notifyCart($aid, "มีรายการขอเบิกใหม่ {$withdrawCode} จากส่วน {$requesterSection}", "approvals.php", 'info');
            }

            // Telegram Notification: ส่งเฉพาะเมื่อผู้อำนวยการอนุมัติเท่านั้น (ข้ามขั้นตอน submit)
        } catch (Exception $ne) {}

        echo json_encode(['status' => 'success', 'code' => $withdrawCode]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
    }
    exit;
}

// ── Page render: fetch cart items + check on-load insufficient & TTL (30 mins) ──
cleanExpiredCartItems($pdo);
$cartItems = [];
$onLoadInsufficient = []; // items that are already over stock on page load
$expiredItemsOnLoad = [];
$earliestAddedTime = null;

if (isset($_SESSION['cart']) && !empty($_SESSION['cart'])) {
    $ids  = implode(',', array_map('intval', array_keys($_SESSION['cart'])));
    $stmt = $pdo->query("SELECT s.*, ci.added_at
                         FROM supplies s
                         LEFT JOIN cart_items ci ON ci.supply_id = s.id AND ci.user_id = {$_SESSION['user_id']}
                         WHERE s.id IN ($ids)");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $validIds = [];

    foreach ($products as $product) {
        $pId = (int)$product['id'];
        $addedAt = !empty($product['added_at']) ? strtotime($product['added_at']) : ($_SESSION['cart_time'][$pId] ?? time());

        // Check if expired (> 30 mins)
        if ((time() - $addedAt) > CART_TTL_SECONDS) {
            $expiredItemsOnLoad[] = $product['name'];
            unset($_SESSION['cart'][$pId]);
            unset($_SESSION['cart_time'][$pId]);
            $pdo->prepare("DELETE FROM cart_items WHERE user_id = ? AND supply_id = ?")->execute([$_SESSION['user_id'], $pId]);
            continue;
        }

        if ($earliestAddedTime === null || $addedAt < $earliestAddedTime) {
            $earliestAddedTime = $addedAt;
        }

        $product['cart_qty'] = $_SESSION['cart'][$pId];
        $cartItems[] = $product;
        $validIds[]  = $pId;

        // On-load insufficient check
        if ($product['cart_qty'] > (int)$product['qty'] || (int)$product['qty'] <= 0) {
            $onLoadInsufficient[] = $pId;
        }
    }
    foreach (array_keys($_SESSION['cart']) as $cmdId) {
        if (!in_array($cmdId, $validIds)) {
            unset($_SESSION['cart'][$cmdId]);
            unset($_SESSION['cart_time'][$cmdId]);
        }
    }
}

$remainingTtlSeconds = $earliestAddedTime ? max(0, CART_TTL_SECONDS - (time() - $earliestAddedTime)) : CART_TTL_SECONDS;
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตะกร้าเบิกวัสดุ</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        sky: {
                            50: '#f4f6f8', 100: '#e1e5ec', 200: '#c4ccd9',
                            300: '#9fabbe', 400: '#7587a1', 500: '#17365a',
                            600: '#112A46', 700: '#0c1e32', 800: '#081421', 900: '#040a11'
                        }
                    }
                }
            }
        }
    </script>
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
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <style>
        body { font-family: 'Sarabun', sans-serif; }
        .dataTables_wrapper .dataTables_length select,
        .dataTables_wrapper .dataTables_filter input {
            border: 1px solid #e2e8f0; padding: 0.5rem; border-radius: 0.5rem; margin-bottom: 1rem;
        }
        table.dataTable.no-footer { border-bottom: 1px solid #e2e8f0; }
        .dt-row { transition: all 0.2s; }
        .dt-row:hover { background-color: #f8fafc !important; }
        .insufficient-row td { opacity: 0.7; }
        .insufficient-row .item-name { text-decoration: line-through; color: #dc2626 !important; }
        .insufficient-badge {
            display: inline-block; background: #fef2f2; color: #dc2626;
            border: 1px solid #fca5a5; border-radius: 6px; font-size: 11px;
            padding: 2px 8px; margin-top: 3px; font-weight: 600;
        }
        .adjusted-note {
            color: #dc2626; font-size: 12px; font-weight: 600; margin-top: 4px; display: block;
        }
        .stock-warn { color: #f59e0b; font-size: 11px; }
        .stock-ok { color: #16a34a; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen flex flex-col">

<header class="bg-white shadow-sm px-6 py-4 flex justify-between items-center sticky top-0 z-20">
    <div class="flex items-center gap-4">
        <a href="javascript:history.back()"
            class="bg-gray-100 hover:bg-gray-200 text-gray-600 w-10 h-10 rounded-full flex items-center justify-center transition">
            <i class="fas fa-arrow-left"></i>
        </a>
        <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
            <i class="fas fa-shopping-basket text-sky-500"></i> ตะกร้าเบิกวัสดุ
        </h1>
    </div>
    <a href="inventory.php" class="text-sky-600 hover:text-sky-800 font-medium transition flex items-center gap-1">
        <i class="fas fa-plus-circle"></i> เลือกเพิ่มเติม
    </a>
</header>

<!-- Instruction Banner -->
<div class="px-4 md:px-8 pt-6">
    <div class="bg-gradient-to-r from-sky-500 to-sky-600 rounded-xl p-6 text-white shadow-lg mx-auto w-full">
        <div class="flex items-start gap-4">
            <div class="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center flex-shrink-0 backdrop-blur-sm">
                <i class="fas fa-clipboard-list text-2xl"></i>
            </div>
            <div class="flex-1">
                <h3 class="font-bold text-lg mb-3">ขั้นตอนการเบิกวัสดุ</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                    <div class="flex items-center gap-3 bg-white/10 p-3 rounded-lg">
                        <div class="w-6 h-6 rounded-full bg-white text-sky-600 flex items-center justify-center font-bold text-xs">1</div>
                        <span>ตรวจสอบรายการและจำนวน</span>
                    </div>
                    <div class="flex items-center gap-3 bg-white/10 p-3 rounded-lg">
                        <div class="w-6 h-6 rounded-full bg-white text-sky-600 flex items-center justify-center font-bold text-xs">2</div>
                        <span>กดปุ่ม "ยืนยันการเบิก"</span>
                    </div>
                    <div class="flex items-center gap-3 bg-white/10 p-3 rounded-lg">
                        <div class="w-6 h-6 rounded-full bg-white text-sky-600 flex items-center justify-center font-bold text-xs">3</div>
                        <span>ระบุเหตุผลการเบิกให้ครบถ้วน</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 30-minute reservation countdown banner -->
<?php if (!empty($cartItems)): ?>
<div class="px-4 md:px-8 pt-4">
    <div class="bg-gradient-to-r from-amber-50 to-orange-50 border border-amber-200 rounded-xl p-4 flex flex-col sm:flex-row items-center justify-between gap-4 shadow-sm">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-amber-100 flex items-center justify-center text-amber-600 flex-shrink-0">
                <i class="fas fa-hourglass-half text-lg"></i>
            </div>
            <div>
                <p class="font-bold text-amber-800 text-sm flex items-center gap-2">
                    <span>ระบบกันสต็อกชั่วคราวให้คุณ 30 นาที</span>
                    <span class="text-xs bg-amber-200/70 text-amber-900 font-semibold px-2 py-0.5 rounded-full">Soft Reserve</span>
                </p>
                <p class="text-amber-700 text-xs mt-0.5">
                    เหลือเวลาจองอีก: <strong id="cart-countdown" class="font-mono text-sm text-red-600 bg-white px-2 py-0.5 rounded border border-amber-300">--:--</strong> นาที (หากไม่ยืนยันในเวลา ระบบจะปล่อยสต็อกให้ผู้อื่นเบิกได้)
                </p>
            </div>
        </div>
        <button type="button" onclick="extendCartTime()"
            class="bg-white hover:bg-amber-100 text-amber-700 border border-amber-300 px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-sm whitespace-nowrap">
            <i class="fas fa-clock-rotate-left"></i> ต่อเวลาอีก 30 นาที
        </button>
    </div>
</div>
<?php endif; ?>

<main class="flex-1 w-full px-4 md:px-8 py-8">

    <?php if (empty($cartItems)): ?>
        <div class="text-center py-20 bg-white rounded-3xl shadow-sm border border-gray-100 max-w-2xl mx-auto">
            <div class="bg-gray-50 w-24 h-24 rounded-full flex items-center justify-center mx-auto mb-6">
                <i class="fas fa-basket-shopping text-4xl text-gray-300"></i>
            </div>
            <h2 class="text-xl font-bold text-gray-400 mb-2">ตะกร้าของคุณว่างเปล่า</h2>
            <p class="text-gray-400 mb-8">ยังไม่มีรายการวัสดุที่เลือกไว้</p>
            <a href="inventory.php"
                class="bg-sky-500 hover:bg-sky-600 text-white px-8 py-3 rounded-xl shadow-lg shadow-sky-200 transition-all font-bold">
                เลือกเบิกวัสดุ
            </a>
        </div>
    <?php else: ?>

        <div class="bg-white rounded-2xl shadow-xl overflow-hidden border border-gray-100 p-6">
            <table id="cartTable" class="w-full text-left stripe hover nowrap" style="width:100%">
                <thead>
                    <tr class="bg-sky-50 text-sky-800">
                        <th class="py-4 px-4 rounded-l-lg">รูปภาพ</th>
                        <th class="py-4 px-4">รายการวัสดุ</th>
                        <th class="py-4 px-4">รหัส</th>
                        <th class="py-4 px-4 text-center">คงเหลือ</th>
                        <th class="py-4 px-4 text-center">จำนวนที่เบิก</th>
                        <th class="py-4 px-4 rounded-r-lg text-center">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cartItems as $item):
                        $minOrder  = ($item['min_order'] > 0)  ? $item['min_order']  : 1;
                        $maxLimit  = ($item['max_order'] > 0)  ? $item['max_order']  : 999999;
                        $realMax   = ($item['qty'] < $maxLimit) ? $item['qty']         : $maxLimit;
                        $isInsuf   = in_array($item['id'], $onLoadInsufficient);
                    ?>
                    <tr class="border-b border-gray-50 dt-row <?php echo $isInsuf ? 'insufficient-row' : ''; ?>"
                        id="cart-row-<?php echo $item['id']; ?>"
                        data-id="<?php echo $item['id']; ?>"
                        data-min="<?php echo $minOrder; ?>"
                        data-max="<?php echo $realMax; ?>">
                        <td class="px-4 py-4">
                            <div class="w-16 h-16 bg-gray-100 rounded-lg overflow-hidden border border-gray-200">
                                <img src="<?php echo htmlspecialchars(!empty($item['image']) ? $item['image'] : 'img/packaging.png'); ?>"
                                    class="w-full h-full object-cover"
                                    onerror="this.onerror=null;this.src='img/packaging.png'">
                            </div>
                        </td>
                        <td class="px-4 py-4">
                            <div class="font-bold text-gray-800 text-lg item-name" id="item-name-<?php echo $item['id']; ?>">
                                <?php echo htmlspecialchars($item['name']); ?>
                            </div>
                            <div class="text-sm text-gray-400"><?php echo htmlspecialchars($item['category'] ?? '-'); ?></div>
                            <?php if ($isInsuf): ?>
                                <span class="insufficient-badge"><i class="fas fa-times-circle mr-1"></i>วัสดุไม่เพียงพอ — รายการนี้จะถูกตัดออก</span>
                            <?php endif; ?>
                            <span id="adjusted-note-<?php echo $item['id']; ?>" class="adjusted-note hidden"></span>
                        </td>
                        <td class="px-4 py-4 text-gray-500 font-mono text-sm">
                            <?php echo htmlspecialchars($item['code']); ?>
                        </td>
                        <td class="px-4 py-4 text-center">
                            <span id="stock-badge-<?php echo $item['id']; ?>"
                                class="<?php echo $isInsuf ? 'bg-red-100 text-red-600' : 'bg-gray-100 text-gray-600'; ?> px-2 py-1 rounded font-bold text-sm">
                                <?php echo $item['qty']; ?> <?php echo $item['unit']; ?>
                            </span>
                            <div id="other-reserve-<?php echo $item['id']; ?>" class="stock-warn text-xs mt-1 hidden"></div>
                        </td>
                        <td class="px-4 py-4">
                            <div class="flex items-center justify-center border border-gray-200 rounded-lg h-10 w-32 mx-auto"
                                id="qty-ctrl-<?php echo $item['id']; ?>">
                                <button
                                    class="px-3 text-gray-500 hover:bg-gray-100 h-full rounded-l-lg disabled:opacity-30 disabled:cursor-not-allowed"
                                    id="btn-dec-<?php echo $item['id']; ?>"
                                    onclick="updateQty(<?php echo $item['id']; ?>, -1)"
                                    <?php echo ($item['cart_qty'] <= $minOrder || $isInsuf) ? 'disabled' : ''; ?>>-</button>

                                <input type="number" id="qty-<?php echo $item['id']; ?>"
                                    value="<?php echo $item['cart_qty']; ?>"
                                    class="w-full text-center text-gray-700 font-bold focus:outline-none h-full bg-white"
                                    readonly <?php echo $isInsuf ? 'disabled' : ''; ?>>

                                <button
                                    class="px-3 text-gray-500 hover:bg-gray-100 h-full rounded-r-lg disabled:opacity-30 disabled:cursor-not-allowed"
                                    id="btn-inc-<?php echo $item['id']; ?>"
                                    onclick="updateQty(<?php echo $item['id']; ?>, 1)"
                                    <?php echo ($item['cart_qty'] >= $realMax || $isInsuf) ? 'disabled' : ''; ?>>+</button>
                            </div>
                            <p class="text-xs text-gray-400 text-center mt-1" id="qty-hint-<?php echo $item['id']; ?>">
                                ขั้นต่ำ: <?php echo $minOrder; ?> | สูงสุด: <?php echo $realMax; ?>
                            </p>
                        </td>
                        <td class="px-4 py-4 text-center">
                            <button onclick="removeItem(<?php echo $item['id']; ?>)"
                                class="text-red-400 hover:text-red-600 hover:bg-red-50 p-2 rounded-full transition">
                                <i class="fas fa-trash-alt text-xl"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="mt-8 flex justify-between gap-3 border-t border-gray-100 pt-6">
                <button onclick="clearCart()"
                    class="bg-gray-100 hover:bg-gray-200 text-gray-500 hover:text-red-500 px-6 py-3 rounded-xl font-bold transition-all flex items-center gap-2">
                    <i class="fas fa-trash-alt"></i> เคลียร์ตะกร้า
                </button>
                <button onclick="confirmRequest()"
                    class="bg-gradient-to-r from-sky-500 to-blue-600 hover:from-sky-600 hover:to-blue-700 text-white text-lg px-8 py-3 rounded-xl shadow-lg shadow-sky-200 font-bold transition-all transform hover:-translate-y-0.5 active:translate-y-0 flex items-center gap-2">
                    <i class="fas fa-paper-plane"></i> ยืนยันการเบิก
                </button>
            </div>
        </div>

    <?php endif; ?>
</main>

<script>
$(document).ready(function () {
    $('#cartTable').DataTable({
        responsive: true, paging: false, searching: false, info: false,
        language: { emptyTable: "ไม่มีรายการในตะกร้า" }
    });

    // On-load checks
    checkOldCart();
});

// ══════════════════════════════════════════════════════
// Real-time stock polling state
// ══════════════════════════════════════════════════════
let _pollTimers = {}; // { supplyId: intervalId }
let _pollCounts = {}; // { supplyId: count }
const POLL_MAX  = 15; // stop after 15 checks (15 seconds)

function startStockPolling(id) {
    if (_pollTimers[id]) {
        clearInterval(_pollTimers[id]);
        _pollCounts[id] = 0;
    }
    _pollCounts[id] = 0;
    _pollTimers[id] = setInterval(function () {
        _pollCounts[id]++;
        if (_pollCounts[id] >= POLL_MAX) {
            clearInterval(_pollTimers[id]);
            delete _pollTimers[id];
            return;
        }
        $.post('cart.php', { action: 'check_stock_live' }, function (res) {
            try {
                let d = JSON.parse(res);
                if (d.items && d.items[id]) applyStockUpdate(id, d.items[id]);
            } catch(e) {}
        });
    }, 1000);
}

function applyStockUpdate(id, info) {
    let input   = document.getElementById('qty-' + id);
    let decBtn  = document.getElementById('btn-dec-' + id);
    let incBtn  = document.getElementById('btn-inc-' + id);
    let badge   = document.getElementById('stock-badge-' + id);
    let reserve = document.getElementById('other-reserve-' + id);
    let hint    = document.getElementById('qty-hint-' + id);
    if (!input) return;

    let currentQty   = parseInt(input.value);
    let effectiveMax = info.effective_max;
    let minOrder     = info.min_order;

    // Update stock badge
    if (badge) {
        badge.textContent = info.stock + ' ' + (badge.dataset.unit || '');
        badge.className = (info.stock <= 0)
            ? 'bg-red-100 text-red-600 px-2 py-1 rounded font-bold text-sm'
            : 'bg-gray-100 text-gray-600 px-2 py-1 rounded font-bold text-sm';
    }

    // Show other-user reservation warning
    if (reserve) {
        if (info.other_total > 0) {
            reserve.textContent = '⚠ ผู้ใช้อื่นจองไว้ ' + info.other_total + ' ชิ้น';
            reserve.classList.remove('hidden');
        } else {
            reserve.classList.add('hidden');
        }
    }

    // Update hint
    if (hint) hint.textContent = 'ขั้นต่ำ: ' + minOrder + ' | สูงสุด: ' + effectiveMax;

    // If current qty now exceeds effective max, reduce it
    if (currentQty > effectiveMax && effectiveMax >= minOrder) {
        input.value = effectiveMax;
        $.post('cart.php', { action: 'update_qty', id: id, qty: effectiveMax });
        Swal.fire({
            icon: 'warning', toast: true, position: 'top-end', showConfirmButton: false, timer: 3500,
            title: 'ปรับจำนวนอัตโนมัติ',
            text: 'ผู้ใช้อื่นจองสินค้าชิ้นนี้ไปบางส่วน ปรับเป็น ' + effectiveMax + ' ชิ้น'
        });
    } else if (effectiveMax < minOrder) {
        // Mark row as insufficient
        let row = document.getElementById('cart-row-' + id);
        if (row && !row.classList.contains('insufficient-row')) {
            row.classList.add('insufficient-row');
            let nameEl = document.getElementById('item-name-' + id);
            if (nameEl) nameEl.classList.add('line-through', 'text-red-600');
            let note = document.getElementById('adjusted-note-' + id);
            if (note) {
                note.textContent = '⚠ วัสดุนี้ไม่เพียงพอสำหรับการเบิกขั้นต่ำแล้ว';
                note.classList.remove('hidden');
            }
        }
        clearInterval(_pollTimers[id]);
        delete _pollTimers[id];
    }

    // Update buttons
    if (decBtn) decBtn.disabled = (parseInt(input.value) <= minOrder);
    if (incBtn) incBtn.disabled = (parseInt(input.value) >= effectiveMax);
}

// ══════════════════════════════════════════════════════
// updateQty: update quantity + start polling
// ══════════════════════════════════════════════════════
function updateQty(id, change) {
    let input  = document.getElementById('qty-' + id);
    let row    = document.getElementById('cart-row-' + id);
    let minVal = parseInt(row ? row.dataset.min : 1);
    let maxVal = parseInt(row ? row.dataset.max : 999999);

    let currentVal = parseInt(input.value);
    let newVal     = currentVal + change;

    if (newVal < minVal) {
        Swal.fire({ icon: 'warning', title: 'ถึงปริมาณขั้นต่ำแล้ว', toast: true, position: 'top-end', showConfirmButton: false, timer: 2000 });
        return;
    }
    if (newVal > maxVal) {
        Swal.fire({ icon: 'warning', title: 'ถึงปริมาณสูงสุดแล้ว', toast: true, position: 'top-end', showConfirmButton: false, timer: 2000 });
        return;
    }

    input.value = newVal;
    document.getElementById('btn-dec-' + id).disabled = (newVal <= minVal);
    document.getElementById('btn-inc-' + id).disabled = (newVal >= maxVal);

    $.post('cart.php', { action: 'update_qty', id: id, qty: newVal }, function (res) {
        let data = JSON.parse(res);
        if (data.status !== 'success') {
            input.value = currentVal;
            document.getElementById('btn-dec-' + id).disabled = (currentVal <= minVal);
            document.getElementById('btn-inc-' + id).disabled = (currentVal >= maxVal);
            Swal.fire({ icon: 'warning', title: 'ไม่สามารถปรับเปลี่ยนจำนวนได้', text: data.message || 'เกิดข้อผิดพลาด', toast: true, position: 'top-end', showConfirmButton: false, timer: 3000 });
        } else {
            // Server may have clamped the value
            if (data.qty !== newVal) {
                input.value = data.qty;
                document.getElementById('btn-dec-' + id).disabled = (data.qty <= data.min_order);
                document.getElementById('btn-inc-' + id).disabled = (data.qty >= data.effective_max);
            }
            // Start real-time polling for this item
            startStockPolling(id);
        }
    });
}

// ══════════════════════════════════════════════════════
// checkOldCart: on-load 12-hour & insufficient check
// ══════════════════════════════════════════════════════
function checkOldCart() {
    $.post('cart.php', { action: 'check_old_cart' }, function (res) {
        try {
            let d = JSON.parse(res);

            // 12-hour banner
            if (d.old_items && d.old_items.length > 0) {
                let names = d.old_items.map(i => `<strong>${i.name}</strong>`).join(', ');
                document.getElementById('old-cart-names').innerHTML =
                    'รายการที่ค้างนานเกิน 12 ชั่วโมง: ' + names;
                document.getElementById('old-cart-banner').classList.remove('hidden');
            }

            // Mark insufficient rows (already done server-side, but re-confirm)
            if (d.insufficient_items && d.insufficient_items.length > 0) {
                d.insufficient_items.forEach(function (item) {
                    let row = document.getElementById('cart-row-' + item.id);
                    if (row && !row.classList.contains('insufficient-row')) {
                        row.classList.add('insufficient-row');
                    }
                });
            }
        } catch(e) {}
    });
}

// ══════════════════════════════════════════════════════
// removeItem
// ══════════════════════════════════════════════════════
function removeItem(id) {
    Swal.fire({
        title: 'ลบรายการ?', text: "ต้องการลบรายการนี้ออกจากตะกร้าหรือไม่",
        icon: 'warning', showCancelButton: true,
        confirmButtonColor: '#ef4444', cancelButtonColor: '#cbd5e1',
        confirmButtonText: 'ลบ', cancelButtonText: 'ยกเลิก'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('cart.php', { action: 'remove', id: id }, function () { location.reload(); });
        }
    });
}

function clearCart() {
    Swal.fire({
        title: 'เคลียร์ตะกร้า?', text: "ต้องการลบรายการทั้งหมดออกจากตะกร้าหรือไม่",
        icon: 'warning', showCancelButton: true,
        confirmButtonColor: '#ef4444', cancelButtonColor: '#cbd5e1',
        confirmButtonText: 'ลบทั้งหมด', cancelButtonText: 'ยกเลิก'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('cart.php', { action: 'clear_cart' }, function () { location.reload(); });
        }
    });
}

// ══════════════════════════════════════════════════════
// confirmRequest: enhanced flow
// ══════════════════════════════════════════════════════
function confirmRequest() {
    Swal.fire({ title: 'ตรวจสอบรายการ...', didOpen: () => Swal.showLoading(), allowOutsideClick: false });

    $.post('cart.php', { action: 'verify_stock' }, function (res) {
        let d = JSON.parse(res);
        Swal.close();

        // Apply red adjusted-notes to the page before showing dialog
        if (d.adjusted_items && d.adjusted_items.length > 0) {
            d.adjusted_items.forEach(function(item) {
                let note = document.getElementById('adjusted-note-' + item.id);
                if (note) {
                    let cap = Math.min(item.available, item.max_order);
                    note.textContent = 'เนื่องจากวัสดุคงเหลือมีจำกัด สามารถเบิกได้เพียง ' + cap +
                        ' ' + item.unit + ' (ปรับจาก ' + item.req_qty + ')';
                    note.classList.remove('hidden');
                }
            });
        }

        if (d.status === 'valid' && (!d.adjusted_items || d.adjusted_items.length === 0)) {
            askReason();
        } else {
            showSubmitDialog(d.invalid_items || [], d.valid_items || [], d.adjusted_items || []);
        }
    });
}

// Build summary dialog HTML
function buildSubmitDialogHtml(invalidItems, validItems, adjustedItems) {
    let html = '<div style="max-height:360px;overflow-y:auto;margin-top:8px">';
    html += '<table style="width:100%;border-collapse:collapse;font-size:13px">';
    html += '<thead><tr style="background:#f1f5f9;color:#475569">';
    html += '<th style="padding:6px 8px;text-align:left">วัสดุ</th>';
    html += '<th style="padding:6px 8px;text-align:center">ขอเบิก</th>';
    html += '<th style="padding:6px 8px;text-align:center">คงเหลือ</th>';
    html += '<th style="padding:6px 8px;text-align:center">สถานะ</th>';
    html += '</tr></thead><tbody>';

    // Valid unchanged rows
    validItems.filter(i => !i.adjusted).forEach(item => {
        html += `<tr style="border-bottom:1px solid #e2e8f0">
            <td style="padding:6px 8px;font-weight:500">${escHtml(item.name)}</td>
            <td style="padding:6px 8px;text-align:center;color:#0369a1">${item.req_qty} ${escHtml(item.unit)}</td>
            <td style="padding:6px 8px;text-align:center;color:#16a34a">${item.stock}</td>
            <td style="padding:6px 8px;text-align:center"><span style="color:#16a34a;font-weight:600">✓ ผ่าน</span></td>
        </tr>`;
    });

    // Adjusted rows (amber)
    adjustedItems.forEach(item => {
        let cap = Math.min(item.available, item.max_order);
        html += `<tr style="border-bottom:1px solid #fde68a;background:#fffbeb">
            <td style="padding:6px 8px;font-weight:600;color:#92400e">
                ${escHtml(item.name)}
                <div style="color:#dc2626;font-size:11px;margin-top:2px">
                    เนื่องจากวัสดุคงเหลือมีจำกัด สามารถเบิกได้เพียง ${cap} ${escHtml(item.unit)}
                </div>
            </td>
            <td style="padding:6px 8px;text-align:center;color:#92400e;text-decoration:line-through">${item.req_qty}</td>
            <td style="padding:6px 8px;text-align:center;color:#d97706">${item.available}</td>
            <td style="padding:6px 8px;text-align:center"><span style="color:#d97706;font-weight:600">⚠ ปรับเป็น ${cap}</span></td>
        </tr>`;
    });

    // Invalid rows (red) — will be removed
    invalidItems.forEach(item => {
        html += `<tr style="border-bottom:1px solid #fca5a5;background:#fef2f2">
            <td style="padding:6px 8px;font-weight:600;color:#b91c1c;text-decoration:line-through">${escHtml(item.name)}</td>
            <td style="padding:6px 8px;text-align:center;color:#b91c1c">${item.req_qty}</td>
            <td style="padding:6px 8px;text-align:center;color:#b91c1c;font-weight:700">${item.stock} (ไม่พอ)</td>
            <td style="padding:6px 8px;text-align:center"><span style="color:#ef4444;font-weight:600">✗ ตัดออก</span></td>
        </tr>`;
    });

    html += '</tbody></table></div>';
    return html;
}

function escHtml(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function showSubmitDialog(invalidItems, validItems, adjustedItems) {
    let hasInvalid   = invalidItems.length > 0;
    let hasAdjusted  = adjustedItems.length > 0;
    let validCount   = validItems.filter(i => !i.adjusted).length + adjustedItems.length;

    let titleHtml = hasInvalid
        ? `<span style="color:#b91c1c">⚠ พบรายการไม่เพียงพอ</span>`
        : `<span style="color:#d97706">⚠ พบรายการที่ต้องปรับจำนวน</span>`;

    let subText = '';
    if (hasInvalid && hasAdjusted)
        subText = `พบ <strong>${invalidItems.length} รายการ</strong> ที่จะถูกตัดออก และ <strong>${adjustedItems.length} รายการ</strong> ที่จะถูกปรับจำนวน`;
    else if (hasInvalid)
        subText = `พบ <strong>${invalidItems.length} รายการ</strong> ที่วัสดุไม่เพียงพอ — จะถูกตัดออกอัตโนมัติ`;
    else if (hasAdjusted)
        subText = `พบ <strong>${adjustedItems.length} รายการ</strong> ที่จะถูกปรับจำนวนลงตามยอดคงเหลือ`;

    if (validCount === 0 && !hasAdjusted) {
        Swal.fire({ icon: 'error', title: 'ไม่มีรายการที่สามารถเบิกได้', text: 'วัสดุทุกรายการไม่เพียงพอ กรุณาลบรายการออกและเลือกใหม่' });
        return;
    }

    Swal.fire({
        title: titleHtml,
        html: `<p style="font-size:13px;color:#64748b;margin-bottom:8px">${subText}</p>
               ${buildSubmitDialogHtml(invalidItems, validItems, adjustedItems)}`,
        width: 680, showCancelButton: true,
        confirmButtonText: validCount > 0 ? `ยืนยัน — เบิก ${validCount} รายการ` : 'ยืนยัน',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#0ea5e9', cancelButtonColor: '#94a3b8'
    }).then(result => {
        if (!result.isConfirmed) return;

        // Build adjustments & removals payloads
        let adjustments = {};
        adjustedItems.forEach(item => {
            adjustments[item.id] = Math.min(item.available, item.max_order);
        });
        let removals = invalidItems.map(i => i.id);

        Swal.fire({ title: 'กำลังบันทึกการปรับ...', didOpen: () => Swal.showLoading(), allowOutsideClick: false });
        $.post('cart.php', { action: 'apply_adjustments', adjustments: adjustments, removals: removals }, function (r) {
            let rd = JSON.parse(r);
            if (rd.remaining === 0) {
                Swal.fire({ icon: 'info', title: 'ไม่มีรายการที่เพียงพอเหลือ', text: 'ตะกร้าว่างเปล่าแล้ว', confirmButtonText: 'ตกลง' })
                    .then(() => location.reload());
            } else {
                Swal.close();
                askReason();
            }
        });
    });
}

// ══════════════════════════════════════════════════════
// askReason & submit
// ══════════════════════════════════════════════════════
function askReason() {
    Swal.fire({
        title: 'ยืนยันการเบิกวัสดุ',
        input: 'textarea',
        inputLabel: 'ระบุเหตุผลการเบิก (จำเป็น)',
        inputPlaceholder: 'เช่น ใช้สำหรับโครงการ... / เพื่อทดแทนของเดิมที่ชำรุด...',
        showCancelButton: true,
        confirmButtonText: 'ยืนยันการเบิก', cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#0ea5e9', cancelButtonColor: '#94a3b8',
        inputValidator: (v) => { if (!v) return 'กรุณาระบุเหตุผลการเบิก!'; }
    }).then(result => {
        if (!result.isConfirmed) return;
        Swal.fire({ title: 'กำลังบันทึก...', didOpen: () => Swal.showLoading() });
        $.post('cart.php', { action: 'submit_request', reason: result.value }, function (res) {
            let data = JSON.parse(res);
            if (data.status === 'success') {
                Swal.fire({ title: 'ทำรายการสำเร็จ!', text: 'เลขที่ใบเบิก: ' + data.code, icon: 'success', confirmButtonText: 'ตกลง' })
                    .then(() => { window.location.href = 'my_requests.php'; });
            } else if (data.conflict) {
                Swal.fire({
                    title: 'สต็อกในคลังไม่เพียงพอ',
                    html: '<div style="text-align:left;white-space:pre-line;font-size:14px;color:#b91c1c;">' + escHtml(data.message) + '</div>',
                    icon: 'warning',
                    confirmButtonText: 'รีเฟรชเพื่อปรับจำนวน',
                    confirmButtonColor: '#0ea5e9'
                }).then(() => {
                    location.reload();
                });
            } else {
                Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
            }
        });
    });
}

// ══════════════════════════════════════════════════════
// Cart Reservation Countdown Timer (30 minutes TTL)
// ══════════════════════════════════════════════════════
let remainingTtl = <?php echo (int)$remainingTtlSeconds; ?>;
let countdownEl = document.getElementById('cart-countdown');

function updateCountdownDisplay() {
    if (!countdownEl) return;
    if (remainingTtl <= 0) {
        countdownEl.textContent = '00:00 (หมดเวลา)';
        countdownEl.className = 'font-mono text-sm bg-red-600 text-white px-2 py-0.5 rounded border border-red-700 font-bold';
        return;
    }
    let m = Math.floor(remainingTtl / 60);
    let s = remainingTtl % 60;
    countdownEl.textContent = String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
}

if (countdownEl && remainingTtl > 0) {
    updateCountdownDisplay();
    let ttlInterval = setInterval(function() {
        remainingTtl--;
        updateCountdownDisplay();
        if (remainingTtl <= 0) {
            clearInterval(ttlInterval);
            Swal.fire({
                icon: 'warning',
                title: 'หมดเวลาการจองสต็อกในตะกร้า',
                text: 'รายการในตะกร้าค้างเกิน 30 นาที ระบบได้คืนสต็อกสู่คลังแล้ว หน้าเว็บจะรีเฟรชเพื่ออัปเดตข้อมูลล่าสุด',
                confirmButtonText: 'รับทราบ',
                confirmButtonColor: '#0ea5e9'
            }).then(() => {
                location.reload();
            });
        }
    }, 1000);
}

function extendCartTime() {
    Swal.fire({ title: 'กำลังต่อเวลา...', didOpen: () => Swal.showLoading(), allowOutsideClick: false });
    $.post('cart.php', { action: 'extend_cart_time' }, function(res) {
        Swal.close();
        let d = JSON.parse(res);
        if (d.status === 'success') {
            remainingTtl = d.ttl_seconds || 1800;
            updateCountdownDisplay();
            Swal.fire({
                icon: 'success',
                title: 'ต่อเวลาสำเร็จ',
                text: d.message,
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000
            });
        } else {
            Swal.fire('เกิดข้อผิดพลาด', d.message || 'ไม่สามารถต่อเวลาได้', 'error');
        }
    });
}
</script>
<?php if (!empty($expiredItemsOnLoad)): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({
            icon: 'warning',
            title: 'รายการหมดเวลาจอง',
            html: '<p style="font-size:14px;color:#475569;">รายการต่อไปนี้ค้างในตะกร้าเกิน 30 นาที ระบบจึงปล่อยคืนคลังอัตโนมัติ:</p><div class="mt-2 text-left bg-amber-50 p-3 rounded-lg border border-amber-200 text-sm font-semibold text-amber-800"><?php echo implode("<br>", array_map("htmlspecialchars", $expiredItemsOnLoad)); ?></div>',
            confirmButtonColor: '#0ea5e9',
            confirmButtonText: 'รับทราบ'
        });
    });
</script>
<?php endif; ?>
<?php if (($sys_settings['node_service_enabled'] ?? '1') === '1'): ?>
    <script src="https://cdn.socket.io/4.8.1/socket.io.min.js"></script>
    <script src="js/realtime.js"></script>
<?php endif; ?>
<!-- Auto Logout when Inactive for 1 Hour with 5s countdown SweetAlert -->
<script src="js/idle_timeout.js"></script>
</body>
</html>
