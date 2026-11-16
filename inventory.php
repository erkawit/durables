<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    if ((isset($_GET['ajax']) && $_GET['ajax'] === '1') || 
        (isset($_GET['action']) && $_GET['action'] === 'get_supply_detail') ||
        (isset($_POST['action']))) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Session expired, please login again.', 'logged_out' => true]);
        exit;
    }
    header("Location: index.php");
    exit;
}

// ── Helper: render a single supply card ────────────────────────────────────
function renderCard($item, $cart)
{
    $isLowStock = $item['qty'] <= $item['min_stock'];
    $isLocked = $item['qty'] < $item['min_stock'];
    $isOutOfStock = $item['qty'] == 0;
    $isDisabled = $isOutOfStock || $isLocked;

    $statusText = '';
    if ($isOutOfStock)
        $statusText = 'วัสดุหมด';
    elseif ($isLocked)
        $statusText = 'ต่ำกว่าเกณฑ์';

    $minOrder = ($item['min_order'] && $item['min_order'] > 0) ? $item['min_order'] : 1;
    $maxOrder = ($item['max_request'] && $item['max_request'] > 0) ? $item['max_request'] : 999999;
    $showMax = ($maxOrder < 999999) ? $maxOrder : '-';
    $cartQty = isset($cart[$item['id']]) ? $cart[$item['id']] : 0;
    $effectiveMax = min($maxOrder, $item['qty']);
    $remaining = $effectiveMax - $cartQty;
    $isMaxedInCart = ($remaining < $minOrder);
    $dropdownMax = ($remaining > 0) ? $remaining : 0;

    $imgSrc = htmlspecialchars(!empty($item['image']) ? $item['image'] : 'img/packaging.png');
    $name = htmlspecialchars($item['name']);
    $id = (int) $item['id'];
    $nameJs = addslashes($item['name']);
    $dimCls = $isDisabled ? 'opacity-70 grayscale' : '';

    ob_start(); ?>
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden hover:shadow-lg transition-shadow duration-300 group <?= $dimCls ?>"
        data-supply-id="<?= $id ?>">
        <div class="h-48 bg-gray-100 relative overflow-hidden group-hover:bg-gray-50 transition cursor-pointer"
            onclick="showSupplyDetail(<?= $id ?>)" title="ดูรายละเอียด">
            <img src="<?= $imgSrc ?>" alt="<?= $name ?>"
                class="w-full h-full object-contain p-2 group-hover:scale-105 transition duration-500" loading="lazy"
                onerror="this.onerror=null;this.src='img/packaging.png'">
            <?php if ($isDisabled): ?>
                <div class="absolute inset-0 bg-black/10 flex items-center justify-center z-10">
                    <span
                        class="bg-black/70 text-white px-3 py-1 rounded-full text-sm font-bold backdrop-blur-sm shadow-md border border-white/20">
                        <?= $statusText ?>
                    </span>
                </div>
            <?php elseif ($isLowStock): ?>
                <div
                    class="absolute top-2 right-2 bg-amber-50 text-amber-600 text-xs px-2 py-1 rounded-lg border border-amber-100 font-bold shadow-sm z-10">
                    ใกล้หมด
                </div>
            <?php endif; ?>
        </div>

        <div class="p-4 border-t border-gray-50">
            <h3 class="font-bold text-gray-800 mb-1 line-clamp-1 text-base text-center cursor-pointer hover:text-sky-600 transition-colors"
                title="คลิกเพื่อดูรายละเอียด: <?= $name ?>" onclick="showSupplyDetail(<?= $id ?>)">
                <?= $name ?>
            </h3>

            <div class="flex justify-between items-center mb-1 text-xs bg-gray-50 rounded-lg p-2">
                <span class="font-bold <?= $isLowStock ? 'text-amber-600' : 'text-green-600' ?>">
                    คงเหลือ: <?= $item['qty'] ?>
                </span>
            </div>

            <div class="flex justify-between text-[10px] text-gray-400 px-1 mb-2">
                <span>เบิกขั้นต่ำ: <?= $minOrder ?></span>
                <span>สูงสุด: <?= $showMax ?></span>
            </div>

            <div class="flex items-center justify-between gap-2">
                <?php if (!$isDisabled && !$isMaxedInCart):
                    $isSingleOption = ($minOrder == 1 && $dropdownMax == 1); ?>
                    <div class="relative w-20 h-9">
                        <select id="qty-<?= $id ?>"
                            class="w-full h-full appearance-none bg-gray-50 border border-gray-200 text-gray-700 text-sm font-bold rounded-lg pl-3 pr-8 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-transparent cursor-pointer hover:bg-white transition text-center shadow-sm <?= $isSingleOption ? 'cursor-not-allowed opacity-75 bg-gray-100' : '' ?>"
                            <?= $isSingleOption ? 'disabled' : '' ?>>
                            <?php
                            $loopMax = ($dropdownMax > 100) ? 100 : $dropdownMax;
                            for ($q = $minOrder; $q <= $loopMax; $q++): ?>
                                <option value="<?= $q ?>"><?= $q ?></option>
                            <?php endfor;
                            if ($dropdownMax > 100): ?>
                                <option value="<?= $dropdownMax ?>"><?= $dropdownMax ?> (Max)</option>
                            <?php endif; ?>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-500">
                            <i class="fas fa-chevron-down text-xs"></i>
                        </div>
                    </div>
                    <button onclick="addToCart(<?= $id ?>, '<?= $nameJs ?>')"
                        class="bg-gradient-to-b from-sky-400 to-sky-500 hover:from-sky-500 hover:to-sky-600 text-white h-9 px-3 rounded-lg shadow-md shadow-sky-100 transition-all active:scale-95 flex-1 text-center text-xs font-bold flex items-center justify-center gap-1 group/btn">
                        <i class="fas fa-cart-plus group-hover/btn:animate-bounce"></i> เบิก
                    </button>

                <?php elseif ($isMaxedInCart): ?>
                    <div class="relative w-20 h-9 opacity-50">
                        <select disabled
                            class="w-full h-full appearance-none bg-gray-100 border border-gray-200 text-gray-400 text-sm font-bold rounded-lg pl-3 pr-8 shadow-sm cursor-not-allowed">
                            <option>-</option>
                        </select>
                    </div>
                    <button disabled
                        class="bg-gray-100 text-gray-400 h-9 px-3 rounded-lg border border-gray-200 flex-1 text-center text-xs font-bold flex items-center justify-center gap-1 cursor-not-allowed">
                        <i class="fas fa-check-circle"></i> จำนวนสูงสุดแล้ว
                    </button>

                <?php else: ?>
                    <button disabled
                        class="bg-gray-100 text-gray-400 w-full h-9 rounded-lg text-xs font-bold cursor-not-allowed border border-gray-200">
                        <?= $statusText ?>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// ── Handle Add to Cart (POST) ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_to_cart') {
    $supply_id = (int) $_POST['supply_id'];
    $qty = (int) $_POST['qty'];
    $force = isset($_POST['force']) && $_POST['force'] == '1';

    $stmt = $pdo->prepare(
        "SELECT id, name, code, category, unit, qty, min_stock,
                min_order, max_order AS max_request, price, status, image
         FROM supplies WHERE id = ?"
    );
    $stmt->execute([$supply_id]);
    $item = $stmt->fetch();

    if (!$item) {
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบรายการวัสดุ']);
        exit;
    }

    $minOrder = ($item['min_order'] && $item['min_order'] > 0) ? $item['min_order'] : 1;
    $maxOrder = ($item['max_request'] && $item['max_request'] > 0) ? $item['max_request'] : 999999;
    $stockQty = $item['qty'];

    if ($qty < $minOrder) {
        echo json_encode(['status' => 'error', 'message' => "ต้องเบิกขั้นต่ำ $minOrder ชิ้น"]);
        exit;
    }

    if (!isset($_SESSION['cart']))
        $_SESSION['cart'] = [];

    $cutoff30Min = date('Y-m-d H:i:s', time() - 1800);

    // Clean expired cart items (TTL 30 minutes)
    try {
        $stmtDel = $pdo->prepare("DELETE FROM cart_items WHERE added_at < ?");
        $stmtDel->execute([$cutoff30Min]);
    } catch (Exception $e) {}

    $currentCartQty = isset($_SESSION['cart'][$supply_id]) ? $_SESSION['cart'][$supply_id] : 0;
    $newTotal = $currentCartQty + $qty;

    // Check available stock after subtracting other users' active cart reservations (within 30 mins)
    $stmtOtherCheck = $pdo->prepare("SELECT COALESCE(SUM(qty),0) FROM cart_items WHERE supply_id=? AND user_id!=? AND added_at >= ?");
    $stmtOtherCheck->execute([$supply_id, $_SESSION['user_id'], $cutoff30Min]);
    $otherReserved = (int) $stmtOtherCheck->fetchColumn();
    $availableStock = $stockQty - $otherReserved - $currentCartQty; // net new stock available to this user

    if ($availableStock < $minOrder && $currentCartQty == 0) {
        // Stock reserved by others leaves less than minimum — cannot add at all
        echo json_encode(['status' => 'error', 'message' => "วัสดุคงเหลือไม่เพียงพอสำหรับการเบิกขั้นต่ำ ($minOrder ชิ้น) เนื่องจากผู้ใช้อื่นกำลังอยู่ในขั้นตอนทำรายการจอง (30 นาที)"]);
        exit;
    }

    if ($newTotal > $maxOrder) {
        $remaining = $maxOrder - $currentCartQty;
        $msg = ($currentCartQty > 0)
            ? "ในตะกร้ามีอยู่แล้ว $currentCartQty ชิ้น. เบิกได้สูงสุด $maxOrder ชิ้น (เพิ่มได้อีก $remaining ชิ้น)"
            : "เบิกได้สูงสุดไม่เกิน $maxOrder ชิ้นต่อครั้ง";
        echo json_encode(['status' => 'error', 'message' => $msg]);
        exit;
    }

    if ($newTotal > $stockQty) {
        echo json_encode(['status' => 'error', 'message' => "สินค้าคงเหลือไม่เพียงพอ (คงเหลือ $stockQty). ในตะกร้ามี $currentCartQty"]);
        exit;
    }

    if (!$force) {
        $userId = $_SESSION['user_id'];
        $stmt = $pdo->prepare(
            "SELECT ci.qty, ci.user_id, CONCAT(IFNULL(u.prefix,''), u.firstname, ' ', u.lastname) AS full_name
             FROM cart_items ci
             LEFT JOIN users u ON u.id = ci.user_id
             WHERE ci.supply_id = ? AND ci.user_id != ? AND ci.added_at >= ?"
        );
        $stmt->execute([$supply_id, $userId, $cutoff30Min]);
        $otherCarts = $stmt->fetchAll();

        if (!empty($otherCarts)) {
            $othersTotal = array_sum(array_column($otherCarts, 'qty'));
            $remainingAfter = $stockQty - $othersTotal - $newTotal;
            $conflictUsers = [];
            foreach ($otherCarts as $oc) {
                $conflictUsers[] = $oc['full_name'] . ' (' . $oc['qty'] . ' ชิ้น)';
            }
            echo json_encode([
                'status' => 'conflict',
                'item_name' => $item['name'],
                'stock' => $stockQty,
                'others_total' => $othersTotal,
                'remaining_after' => $remainingAfter,
                'conflict_users' => $conflictUsers,
                'supply_id' => $supply_id,
                'qty' => $qty
            ]);
            exit;
        }
    }

    $_SESSION['cart'][$supply_id] = $newTotal;
    if (!isset($_SESSION['cart_time']))
        $_SESSION['cart_time'] = [];
    if (!isset($_SESSION['cart_time'][$supply_id]))
        $_SESSION['cart_time'][$supply_id] = time();

    if (($db_driver ?? 'mysql') === 'pgsql') {
        $stmt = $pdo->prepare(
            "INSERT INTO cart_items (user_id, supply_id, qty, added_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)
             ON CONFLICT (user_id, supply_id) DO UPDATE SET qty = EXCLUDED.qty, added_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([$_SESSION['user_id'], $supply_id, $newTotal]);
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO cart_items (user_id, supply_id, qty) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE qty = ?, added_at = NOW()"
        );
        $stmt->execute([$_SESSION['user_id'], $supply_id, $newTotal, $newTotal]);
    }

    echo json_encode(['status' => 'success', 'message' => 'เพิ่มลงตะกร้าเรียบร้อย', 'cart_count' => count($_SESSION['cart'])]);
    exit;
}

// ── AJAX: get_supply_detail ────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_supply_detail' && isset($_GET['id'])) {
    $sid = (int) $_GET['id'];

    // Supply base info
    $stmtS = $pdo->prepare("SELECT id, name, code, category, unit, qty, min_stock, min_order, max_order, price, image FROM supplies WHERE id = ?");
    $stmtS->execute([$sid]);
    $supply = $stmtS->fetch();

    if (!$supply) {
        echo json_encode(['status' => 'error']);
        exit;
    }

    // Total qty withdrawn (approved + completed requests only)
    $stmtW = $pdo->prepare(
        "SELECT COALESCE(SUM(ri.qty), 0) AS total_qty,
                COUNT(DISTINCT ri.request_id) AS total_requests
         FROM request_items ri
         JOIN requests r ON r.id = ri.request_id
         WHERE ri.supply_id = ?
           AND r.status NOT IN ('cancelled','rejected')
           AND (ri.is_deleted IS NULL OR ri.is_deleted = 0)"
    );
    $stmtW->execute([$sid]);
    $wStats = $stmtW->fetch();

    // Last 5 withdrawals
    $stmtH = $pdo->prepare(
        "SELECT r.request_name, r.request_date, r.status,
                CONCAT(u.prefix, u.firstname, ' ', u.lastname) AS requester,
                COALESCE(ri.approved_qty, ri.qty) AS qty
         FROM request_items ri
         JOIN requests r ON r.id = ri.request_id
         JOIN users    u ON u.id = r.user_id
         WHERE ri.supply_id = ?
           AND r.status NOT IN ('cancelled','rejected')
           AND (ri.is_deleted IS NULL OR ri.is_deleted = 0)
         ORDER BY r.request_date DESC LIMIT 5"
    );
    $stmtH->execute([$sid]);
    $history = $stmtH->fetchAll();

    $totalCostWithdrawn = (float) $wStats['total_qty'] * (float) $supply['price'];

    echo json_encode([
        'status' => 'ok',
        'supply' => $supply,
        'total_qty' => (int) $wStats['total_qty'],
        'total_requests' => (int) $wStats['total_requests'],
        'total_cost' => $totalCostWithdrawn,
        'history' => $history,
    ]);
    exit;
}

// ── Filter logic (shared by page render & AJAX) ────────────────────────────
$category = isset($_GET['category']) ? $_GET['category'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$sql = "SELECT id, name, code, category, unit, qty, min_stock, min_order, max_order AS max_request, price, status, image FROM supplies WHERE status = 'active'";
$params = [];

if ($category !== 'all') {
    $sql .= " AND category = ?";
    $params[] = $category;
}
if (!empty($search)) {
    $sql .= " AND (name LIKE ? OR code LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$supplies = $stmt->fetchAll();

// ── AJAX: return rendered cards as JSON ────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    $cart = isset($_SESSION['cart']) ? $_SESSION['cart'] : [];
    ob_start();
    foreach ($supplies as $item) {
        echo renderCard($item, $cart);
    }
    $cardsHtml = ob_get_clean();

    header('Content-Type: application/json');
    echo json_encode(['html' => $cardsHtml, 'count' => count($supplies)]);
    exit;
}

// ── Categories for tab bar ─────────────────────────────────────────────────
$catStmt = $pdo->query("SELECT DISTINCT category FROM supplies WHERE status = 'active' AND category IS NOT NULL AND category != ''");
$dbCategories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

$categories = ['all' => 'ทั้งหมด'];
foreach ($dbCategories as $cat) {
    $categories[$cat] = $cat;
}

// ── Summary stats (top-right panel) ───────────────────────────────────────
$statTotalItems = (int) $pdo->query("SELECT COUNT(*) FROM supplies WHERE status='active'")->fetchColumn();
$statTotalWithdrawals = (int) $pdo->query(
    "SELECT COALESCE(SUM(ri.qty),0)
     FROM request_items ri
     JOIN requests r ON r.id = ri.request_id
     WHERE r.status NOT IN ('cancelled','rejected')
       AND (ri.is_deleted IS NULL OR ri.is_deleted = 0)"
)->fetchColumn();
$statTotalCost = (float) $pdo->query(
    "SELECT COALESCE(SUM(ri.qty * s.price),0)
     FROM request_items ri
     JOIN requests r  ON r.id  = ri.request_id
     JOIN supplies s  ON s.id  = ri.supply_id
     WHERE r.status NOT IN ('cancelled','rejected')
       AND (ri.is_deleted IS NULL OR ri.is_deleted = 0)"
)->fetchColumn();
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายการวัสดุ - ระบบเบิกวัสดุ</title>
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
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>


        body {
            font-family: 'Sarabun', sans-serif;
        }

        html.swal2-shown, body.swal2-shown {
            height: 100% !important;
            overflow: hidden !important;
        }
        body.swal2-height-auto {
            height: 100% !important;
        }

        .scrollbar-hide::-webkit-scrollbar {
            display: none;
        }

        .scrollbar-hide {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
    </style>
</head>

<body class="bg-gray-50 flex h-screen overflow-hidden">

    <?php $page = 'inventory'; include 'sidebar.php'; ?>

    <main class="flex-1 flex flex-col overflow-hidden relative">

    <!-- Navbar -->
    <header class="bg-white shadow-md z-30 px-6 py-3 flex justify-between items-center sticky top-0 gap-4">
        <div class="flex items-center gap-4 shrink-0">
            <?php if ($role !== 'user'): ?>
            <a href="dashboard.php" class="text-gray-500 hover:text-sky-600 transition" title="กลับหน้าหลัก">
                <i class="fas fa-arrow-left text-xl"></i>
            </a>
            <?php endif; ?>
            <h1 class="text-xl font-bold text-gray-800 hidden sm:block">เบิกวัสดุ</h1>
        </div>

        <!-- Search bar (no page reload — AJAX handles it) -->
        <form id="search-form" class="flex-1 max-w-2xl mx-auto relative z-20" onsubmit="return false;">
            <input id="search-input" type="text" value="<?php echo htmlspecialchars($search); ?>"
                placeholder="ค้นหาชื่อวัสดุ หรือรหัส..." autocomplete="off"
                class="w-full pl-10 pr-10 py-2 bg-white text-gray-800 placeholder-gray-400 border border-gray-300 rounded-full focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-transparent transition-all shadow-sm text-sm cursor-text relative z-10">
            <i class="fas fa-search absolute left-3.5 top-2.5 text-gray-400 pointer-events-none z-20"></i>
            <button id="search-clear" type="button" class="absolute right-3 top-2 text-gray-400 hover:text-red-500 z-20"
                style="display:<?php echo !empty($search) ? '' : 'none'; ?>">
                <i class="fas fa-times-circle"></i>
            </button>
        </form>

        <div class="flex items-center gap-4 shrink-0 relative z-20">
            <!-- Notification Bell -->
            <div class="relative" id="notif-wrapper">
                <button id="notif-btn"
                    class="text-gray-500 hover:text-sky-600 transition relative p-2 rounded-xl hover:bg-gray-100"
                    aria-label="การแจ้งเตือน">
                    <i class="fas fa-bell text-xl"></i>
                    <span id="notif-badge"
                        class="hidden absolute -top-0.5 -right-0.5 bg-red-500 text-white text-[10px] font-bold min-w-[18px] h-[18px] rounded-full flex items-center justify-center px-1 leading-none">0</span>
                </button>
                <!-- Notification Dropdown -->
                <div id="notif-dropdown"
                    class="hidden absolute right-0 mt-2 w-96 bg-white rounded-2xl shadow-2xl border border-gray-100 z-50 overflow-hidden">
                    <div
                        class="px-4 py-3 border-b border-gray-100 flex items-center justify-between bg-gradient-to-r from-sky-50 to-white">
                        <span class="text-sm font-bold text-gray-700 flex items-center gap-2"><i
                                class="fas fa-bell text-sky-500"></i> การแจ้งเตือน</span>
                        <button onclick="markAllRead()"
                            class="text-xs text-sky-500 hover:text-sky-700 font-medium transition">อ่านทั้งหมด</button>
                    </div>
                    <div id="notif-list" class="max-h-80 overflow-y-auto divide-y divide-gray-50">
                        <div class="p-6 text-center text-gray-400 text-sm">กำลังโหลด...</div>
                    </div>
                </div>
            </div>

            <!-- Cart Icon -->
            <a href="cart.php" class="relative group shrink-0">
                <div class="bg-sky-100 p-2.5 rounded-xl text-sky-600 group-hover:bg-sky-200 transition duration-300">
                    <i class="fas fa-shopping-basket text-lg"></i>
                </div>
                <span id="cart-badge"
                    class="absolute -top-1 -right-1 bg-red-500 text-white text-xs font-bold w-5 h-5 flex items-center justify-center rounded-full border-2 border-white shadow-sm transform group-hover:scale-110 transition">
                    <?php echo isset($_SESSION['cart']) ? count($_SESSION['cart']) : 0; ?>
                </span>
            </a>

            <!-- User Info -->
            <div class="text-right hidden sm:block">
                <p class="text-sm font-bold text-gray-800"><?php echo htmlspecialchars($_SESSION['fullname'] ?? ''); ?></p>
                <p class="text-xs text-gray-500"><?php echo ucfirst($_SESSION['role'] ?? ''); ?> | <?php echo htmlspecialchars($_SESSION['section'] ?? ''); ?></p>
            </div>
            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-sky-400 to-blue-500 flex items-center justify-center text-white shadow-lg shadow-sky-200">
                <span class="font-bold text-sm"><?php echo mb_substr($_SESSION['fullname'] ?? 'U', 0, 1); ?></span>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <div id="inventory-scroll-container" class="flex-1 overflow-y-auto p-6">
        <div class="w-full px-4">

            <!-- Category Tabs + Summary Stats -->
            <div class="flex items-start justify-between gap-4 mb-6">
                <!-- Tabs -->
                <div class="flex gap-3 overflow-x-auto pb-2 scrollbar-hide flex-1">
                    <?php foreach ($categories as $key => $label): ?>
                        <button data-category-tab="<?php echo htmlspecialchars($key); ?>"
                            class="<?php echo ($category == $key)
                                ? 'bg-sky-600 text-white shadow-md shadow-sky-200'
                                : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50'; ?>
                                       px-4 py-2 rounded-full text-sm font-medium whitespace-nowrap transition-all duration-200 cursor-pointer select-none">
                            <?php echo htmlspecialchars($label); ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <!-- Summary Stats Panel -->
                <div
                    class="shrink-0 flex items-stretch gap-3 bg-white border border-gray-200 rounded-2xl shadow-sm px-5 py-3">
                    <!-- Total items -->
                    <div class="flex flex-col items-center justify-center min-w-[80px]">
                        <div class="w-9 h-9 rounded-xl bg-sky-50 flex items-center justify-center mb-1">
                            <i class="fas fa-boxes text-sky-500 text-base"></i>
                        </div>
                        <span class="text-xl font-extrabold text-sky-700 leading-none">
                            <?php echo number_format($statTotalItems); ?>
                        </span>
                        <span class="text-[10px] text-gray-400 mt-0.5 whitespace-nowrap">รายการวัสดุ</span>
                    </div>

                    <div class="w-px bg-gray-100 self-stretch mx-1"></div>

                    <!-- Total withdrawals -->
                    <div class="flex flex-col items-center justify-center min-w-[80px]">
                        <div class="w-9 h-9 rounded-xl bg-emerald-50 flex items-center justify-center mb-1">
                            <i class="fas fa-hand-holding text-emerald-500 text-base"></i>
                        </div>
                        <span class="text-xl font-extrabold text-emerald-700 leading-none">
                            <?php echo number_format($statTotalWithdrawals); ?>
                        </span>
                        <span class="text-[10px] text-gray-400 mt-0.5 whitespace-nowrap">รวมเบิกทั้งหมด</span>
                    </div>

                    <div class="w-px bg-gray-100 self-stretch mx-1"></div>

                    <!-- Total cost -->
                    <div class="flex flex-col items-center justify-center min-w-[90px]">
                        <div class="w-9 h-9 rounded-xl bg-amber-50 flex items-center justify-center mb-1">
                            <i class="fas fa-baht-sign text-amber-500 text-base"></i>
                        </div>
                        <span class="text-xl font-extrabold text-amber-700 leading-none">
                            <?php echo number_format($statTotalCost, 0); ?>
                        </span>
                        <span class="text-[10px] text-gray-400 mt-0.5 whitespace-nowrap">มูลค่ารวม (บาท)</span>
                    </div>
                </div>
            </div>

            <!-- Supply Grid -->
            <div id="supply-grid"
                class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6 gap-6"
                <?php echo empty($supplies) ? 'style="display:none"' : ''; ?>>
                <?php
                $cart = isset($_SESSION['cart']) ? $_SESSION['cart'] : [];
                foreach ($supplies as $item) {
                    echo renderCard($item, $cart);
                }
                ?>
            </div>

            <!-- Empty State -->
            <div id="empty-state" class="text-center py-20" <?php echo !empty($supplies) ? 'style="display:none"' : ''; ?>>
                <div class="text-gray-300 text-6xl mb-4"><i class="fas fa-search"></i></div>
                <h3 class="text-xl font-bold text-gray-400">ไม่พบรายการวัสดุ</h3>
                <p class="text-gray-400">ลองค้นหาด้วยคำอื่น หรือเลือกหมวดหมู่ใหม่</p>
            </div>

        </div>
    </div>

    <script>
        // ── State ─────────────────────────────────────────────────────────────────
        let currentCategory = '<?= addslashes($category) ?>';
        let currentSearch = '<?= addslashes($search) ?>';
        let searchTimer = null;
        let abortController = null;

        // SweetAlert loading state
        let swalVisible = false;
        let showSwalTimer = null;
        let progressTimer = null;
        let animInterval = null;
        let fakeProgress = 0;
        let progressActive = false;

        // ── Loading indicator ──────────────────────────────────────────────────────
        function showSearchLoading() {
            clearTimeout(showSwalTimer);
            clearTimeout(progressTimer);
            clearInterval(animInterval);
            fakeProgress = 0;
            progressActive = false;
            swalVisible = false;

            // Only open SweetAlert after 300 ms — instant results need no overlay
            showSwalTimer = setTimeout(() => {
                swalVisible = true;
                Swal.fire({
                    title: 'กำลังค้นหาข้อมูล...',
                    html: `
                    <div id="swal-prog-wrap" style="display:none;margin-top:12px;">
                        <div style="background:#f3f4f6;border-radius:8px;height:10px;overflow:hidden;">
                            <div id="swal-prog-bar"
                                 style="height:100%;width:0%;background:linear-gradient(90deg,#38bdf8,#0ea5e9);
                                        border-radius:8px;transition:width 0.45s ease;"></div>
                        </div>
                        <div id="swal-prog-pct"
                             style="text-align:center;font-size:13px;color:#6b7280;margin-top:6px;font-family:'Sarabun',sans-serif;">
                            0%
                        </div>
                    </div>
                `,
                    allowOutsideClick: false,
                    showConfirmButton: false,
                    width: 340,
                    didOpen: () => {
                        Swal.showLoading();
                        // After ~5 s total (4700 ms here + 300 ms delay above), add progress bar
                        progressTimer = setTimeout(activateProgressBar, 4700);
                    }
                });
            }, 300);
        }

        function activateProgressBar() {
            const wrap = document.getElementById('swal-prog-wrap');
            if (!wrap) return;
            wrap.style.display = 'block';
            progressActive = true;

            animInterval = setInterval(() => {
                if (fakeProgress < 88) {
                    fakeProgress += (88 - fakeProgress) * 0.04 + 0.3;
                    if (fakeProgress > 88) fakeProgress = 88;
                    setProg(fakeProgress);
                } else {
                    clearInterval(animInterval);
                }
            }, 200);
        }

        function setProg(pct) {
            const bar = document.getElementById('swal-prog-bar');
            const txt = document.getElementById('swal-prog-pct');
            if (bar) bar.style.width = Math.round(pct) + '%';
            if (txt) txt.textContent = Math.round(pct) + '%';
        }

        function hideSearchLoading(cb) {
            clearTimeout(showSwalTimer);
            clearTimeout(progressTimer);
            clearInterval(animInterval);
            showSwalTimer = null;

            if (!swalVisible) {
                swalVisible = false;
                if (cb) cb();
                return;
            }

            swalVisible = false;
            if (progressActive) {
                setProg(100);
                setTimeout(() => { Swal.close(); if (cb) cb(); }, 400);
            } else {
                Swal.close();
                if (cb) cb();
            }
        }

        // ── Search / filter ────────────────────────────────────────────────────────
        async function performSearch(category, search) {
            if (abortController) abortController.abort();
            abortController = new AbortController();

            showSearchLoading();

            try {
                const qs = new URLSearchParams({ category, search, ajax: '1' });
                const res = await fetch('inventory.php?' + qs, { signal: abortController.signal });
                const data = await res.json();
                hideSearchLoading(() => applyResults(data, category, search));
            } catch (e) {
                if (e.name === 'AbortError') return;
                hideSearchLoading();
                Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: 'ไม่สามารถโหลดข้อมูลได้' });
            }
        }

        function applyResults(data, category, search) {
            const grid = document.getElementById('supply-grid');
            const empty = document.getElementById('empty-state');

            if (data.count > 0) {
                grid.innerHTML = data.html;
                grid.style.display = '';
                empty.style.display = 'none';
            } else {
                grid.innerHTML = '';
                grid.style.display = 'none';
                empty.style.display = '';
            }

            // Update category tab active styles
            document.querySelectorAll('[data-category-tab]').forEach(btn => {
                const active = btn.dataset.categoryTab === category;
                btn.className = (active
                    ? 'bg-sky-600 text-white shadow-md shadow-sky-200'
                    : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50')
                    + ' px-4 py-2 rounded-full text-sm font-medium whitespace-nowrap transition-all duration-200 cursor-pointer select-none';
            });

            currentCategory = category;
            currentSearch = search;

            // Sync URL bar without reload
            const url = new URL(window.location.href);
            url.searchParams.set('category', category);
            if (search) url.searchParams.set('search', search);
            else url.searchParams.delete('search');
            history.pushState({}, '', url);

            // Clear button visibility
            const clearBtn = document.getElementById('search-clear');
            if (clearBtn) clearBtn.style.display = search ? '' : 'none';
        }

        // ── Event listeners ────────────────────────────────────────────────────────
        document.addEventListener('DOMContentLoaded', () => {
            const searchInput = document.getElementById('search-input');

            searchInput.addEventListener('input', function () {
                const val = this.value;
                const clearBtn = document.getElementById('search-clear');
                if (clearBtn) clearBtn.style.display = val ? '' : 'none';

                clearTimeout(searchTimer);
                searchTimer = setTimeout(() => performSearch(currentCategory, val.trim()), 350);
            });

            document.getElementById('search-form').addEventListener('submit', e => {
                e.preventDefault();
                clearTimeout(searchTimer);
                performSearch(currentCategory, searchInput.value.trim());
            });

            document.getElementById('search-clear').addEventListener('click', () => {
                searchInput.value = '';
                document.getElementById('search-clear').style.display = 'none';
                clearTimeout(searchTimer);
                performSearch(currentCategory, '');
            });

            document.querySelectorAll('[data-category-tab]').forEach(btn => {
                btn.addEventListener('click', function () {
                    performSearch(this.dataset.categoryTab, currentSearch);
                });
            });
        });

        // ── Add to Cart ────────────────────────────────────────────────────────────
        function doAddToCart(id, qty, force) {
            const fd = new FormData();
            fd.append('action', 'add_to_cart');
            fd.append('supply_id', id);
            fd.append('qty', qty);
            if (force) fd.append('force', '1');
            return fetch('inventory.php', { method: 'POST', body: fd }).then(r => r.json());
        }

        function addToCart(id, name) {
            const selectEl = document.getElementById('qty-' + id);
            const qty = selectEl ? selectEl.value : 1;

            doAddToCart(id, qty, false).then(data => {
                if (data.status === 'success') {
                    updateCartUI(data.cart_count);
                } else if (data.status === 'conflict') {
                    showConflictAlert(data, id, qty);
                } else {
                    Swal.fire({ icon: 'warning', title: 'ไม่สามารถเพิ่มได้', text: data.message, confirmButtonText: 'รับทราบ', confirmButtonColor: '#f59e0b' });
                }
            }).catch(() => {
                Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้' });
            });
        }

        function showConflictAlert(data, id, qty) {
            const userList = data.conflict_users
                .map(u => `<li class="flex items-center gap-2 py-1 border-b border-amber-100 last:border-0">
                          <i class="fas fa-user-clock text-amber-500 text-xs"></i>
                          <span class="text-sm text-gray-700">${u}</span>
                       </li>`)
                .join('');

            const isShortage = data.remaining_after < 0;
            const remainingHtml = isShortage
                ? `<span class="text-red-600 font-bold">⚠️ สต็อกจะไม่เพียงพอหากทุกคนเบิก (ขาด ${Math.abs(data.remaining_after)} ชิ้น)</span>`
                : `<span class="text-green-600 font-bold">✅ หากทุกคนเบิก จะยังเหลือสต็อก ${data.remaining_after} ชิ้น</span>`;

            Swal.fire({
                title: '<span class="text-orange-600">⚠️ มีผู้ใช้อื่นต้องการวัสดุชิ้นนี้</span>',
                html: `
                <div class="text-left space-y-3">
                    <div class="bg-sky-50 border border-sky-200 rounded-xl p-3">
                        <p class="font-semibold text-sky-800 text-sm">วัสดุ: ${data.item_name}</p>
                        <p class="text-xs text-sky-600 mt-1">คงเหลือในคลัง: <strong>${data.stock} ชิ้น</strong></p>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-gray-700 mb-2">
                            <i class="fas fa-users text-amber-500 mr-1"></i>ผู้ใช้ที่กำลังขอวัสดุชิ้นนี้:
                        </p>
                        <ul class="bg-amber-50 border border-amber-200 rounded-xl px-3 py-1 list-none">
                            ${userList}
                        </ul>
                    </div>
                    <div class="bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm">
                        ${remainingHtml}
                    </div>
                    <p class="text-xs text-gray-500 text-center">กด <strong>"ตกลง — เพิ่มในตะกร้าต่อไป"</strong> เพื่อเพิ่มรายการ หรือ <strong>"ยกเลิก"</strong> เพื่อไม่เพิ่ม</p>
                </div>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'ตกลง — เพิ่มในตะกร้าต่อไป',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#0ea5e9',
                cancelButtonColor: '#94a3b8',
                width: 520,
                customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-xl px-5', cancelButton: 'rounded-xl px-5' }
            }).then(result => {
                if (result.isConfirmed) {
                    Swal.fire({ title: 'กำลังเพิ่มในตะกร้า...', didOpen: () => Swal.showLoading(), allowOutsideClick: false });
                    doAddToCart(id, qty, true).then(d2 => {
                        Swal.close();
                        if (d2.status === 'success') {
                            updateCartUI(d2.cart_count);
                        } else {
                            Swal.fire({ icon: 'warning', title: 'ไม่สามารถเพิ่มได้', text: d2.message, confirmButtonText: 'รับทราบ', confirmButtonColor: '#f59e0b' });
                        }
                    });
                }
            });
        }

        function updateCartUI(cartCount) {
            const badge = document.getElementById('cart-badge');
            if (badge) {
                badge.textContent = cartCount;
            }
            const cartIconWrap = document.querySelector('.fa-shopping-basket')?.parentElement;
            if (cartIconWrap) {
                cartIconWrap.classList.add('scale-125');
                setTimeout(() => cartIconWrap.classList.remove('scale-125'), 200);
            }
            refreshInventory();
        }

        async function refreshInventory() {
            try {
                const qs = new URLSearchParams({ category: currentCategory, search: currentSearch, ajax: '1' });
                const res = await fetch('inventory.php?' + qs);
                const data = await res.json();
                
                const grid = document.getElementById('supply-grid');
                const empty = document.getElementById('empty-state');

                if (data.count > 0) {
                    grid.innerHTML = data.html;
                    grid.style.display = '';
                    empty.style.display = 'none';
                } else {
                    grid.innerHTML = '';
                    grid.style.display = 'none';
                    empty.style.display = '';
                }
            } catch (e) {
                console.error(e);
            }
        }

        // ── Supply Detail Modal ────────────────────────────────────────────────────
        function showSupplyDetail(id) {
            const scrollContainer = document.getElementById('inventory-scroll-container') || document.querySelector('.overflow-y-auto');
            const savedScrollTop = (scrollContainer && scrollContainer.scrollTop !== undefined) ? scrollContainer.scrollTop : (window.scrollY || 0);
            const targetCard = document.querySelector(`[data-supply-id="${id}"]`);

            const restoreScroll = () => {
                if (scrollContainer && scrollContainer.scrollTop !== undefined) {
                    scrollContainer.scrollTop = savedScrollTop;
                } else {
                    window.scrollTo(0, savedScrollTop);
                }
            };

            Swal.fire({
                title: 'กำลังโหลด...',
                heightAuto: false,
                returnFocus: false,
                didOpen: () => {
                    Swal.showLoading();
                    restoreScroll();
                },
                allowOutsideClick: false
            });

            fetch('inventory.php?action=get_supply_detail&id=' + id)
                .then(r => r.json())
                .then(d => {
                    Swal.close();
                    restoreScroll();
                    if (!d || d.status !== 'ok') {
                        Swal.fire({
                            icon: 'error',
                            title: 'ไม่พบข้อมูล',
                            text: 'ไม่สามารถโหลดข้อมูลวัสดุได้',
                            heightAuto: false,
                            returnFocus: false,
                            didClose: restoreScroll
                        });
                        return;
                    }
                    const s = d.supply;
                    const imgSrc = s.image || 'img/packaging.png';
                    const minO = (s.min_order > 0) ? s.min_order : 1;
                    const maxO = (s.max_order > 0) ? s.max_order : '-';
                    const price = parseFloat(s.price || 0).toLocaleString('th-TH', { minimumFractionDigits: 2 });
                    const stockVal = (parseFloat(s.price || 0) * parseInt(s.qty || 0)).toLocaleString('th-TH', { minimumFractionDigits: 2 });
                    const wCost = d.total_cost.toLocaleString('th-TH', { minimumFractionDigits: 2 });
                    const isLow = parseInt(s.qty) <= parseInt(s.min_stock);

                    // History rows
                    const statusLabel = {
                        pending_head: 'รอหัวหน้า', pending_supply: 'รอพัสดุ',
                        pending_director: 'รอผอ.', approved: 'อนุมัติ', completed: 'เบิกแล้ว'
                    };
                    const statusColor = {
                        pending_head: '#f59e0b', pending_supply: '#3b82f6',
                        pending_director: '#8b5cf6', approved: '#10b981', completed: '#0ea5e9'
                    };
                    let histRows = '';
                    if (d.history.length === 0) {
                        histRows = `<tr><td colspan="4" style="text-align:center;color:#94a3b8;padding:10px">ยังไม่มีประวัติการเบิก</td></tr>`;
                    } else {
                        d.history.forEach(h => {
                            const dt = new Date(h.request_date);
                            const dStr = dt.toLocaleDateString('th-TH', { day: '2-digit', month: 'short', year: 'numeric' });
                            const lbl = statusLabel[h.status] || h.status;
                            const col = statusColor[h.status] || '#6b7280';
                            histRows += `<tr style="border-bottom:1px solid #f1f5f9">
                            <td style="padding:5px 8px;font-size:11px;color:#475569">${dStr}</td>
                            <td style="padding:5px 8px;font-size:11px;font-family:monospace;color:#0369a1">${h.request_name}</td>
                            <td style="padding:5px 8px;font-size:11px">${h.requester}</td>
                            <td style="padding:5px 8px;font-size:11px;text-align:center;font-weight:700;color:#1e293b">${h.qty}</td>
                            <td style="padding:5px 8px;font-size:10px;text-align:center">
                                <span style="background:${col}22;color:${col};border-radius:999px;padding:2px 8px;font-weight:600;white-space:nowrap">${lbl}</span>
                            </td>
                        </tr>`;
                        });
                    }

                    Swal.fire({
                        title: '',
                        width: 620,
                        padding: '0',
                        showConfirmButton: false,
                        showCloseButton: true,
                        heightAuto: false,
                        returnFocus: false,
                        customClass: { popup: 'rounded-2xl overflow-hidden', closeButton: 'text-gray-400 hover:text-gray-700' },
                        didOpen: () => {
                            restoreScroll();
                        },
                        willClose: () => {
                            restoreScroll();
                        },
                        didClose: () => {
                            restoreScroll();
                            if (targetCard) {
                                targetCard.scrollIntoView({ behavior: 'auto', block: 'nearest' });
                            }
                        },
                        html: `
                    <div style="font-family:'Sarabun',sans-serif">
                        <!-- Header with image -->
                        <div style="background:linear-gradient(135deg,#0f2744 0%,#17365a 100%);padding:20px 24px 16px;display:flex;gap:16px;align-items:center">
                            <div style="width:72px;height:72px;background:#fff;border-radius:12px;overflow:hidden;flex-shrink:0;box-shadow:0 4px 12px rgba(0,0,0,.3)">
                                <img src="${imgSrc}" style="width:100%;height:100%;object-fit:contain;padding:6px"
                                     onerror="this.src='img/packaging.png'">
                            </div>
                            <div style="text-align:left">
                                <div style="color:#fff;font-size:17px;font-weight:700;line-height:1.3">${s.name}</div>
                                <div style="color:#93c5fd;font-size:12px;margin-top:3px">
                                    รหัส: <strong style="font-family:monospace">${s.code}</strong>
                                    &nbsp;|&nbsp; หมวดหมู่: ${s.category || '-'}
                                </div>
                                <div style="color:#bfdbfe;font-size:11px;margin-top:2px">หน่วยนับ: ${s.unit || '-'}</div>
                            </div>
                        </div>

                        <!-- Stats grid -->
                        <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:0;border-bottom:1px solid #f1f5f9">
                            <div style="padding:14px 12px;text-align:center;border-right:1px solid #f1f5f9">
                                <div style="font-size:22px;font-weight:800;color:${isLow ? '#f59e0b' : '#10b981'}">${parseInt(s.qty).toLocaleString()}</div>
                                <div style="font-size:10px;color:#94a3b8;margin-top:2px">คงเหลือ (${s.unit})</div>
                            </div>
                            <div style="padding:14px 12px;text-align:center;border-right:1px solid #f1f5f9">
                                <div style="font-size:22px;font-weight:800;color:#6366f1">${d.total_qty.toLocaleString()}</div>
                                <div style="font-size:10px;color:#94a3b8;margin-top:2px">รวมเบิกไปแล้ว (${s.unit})</div>
                            </div>
                            <div style="padding:14px 12px;text-align:center;border-right:1px solid #f1f5f9">
                                <div style="font-size:22px;font-weight:800;color:#0ea5e9">${d.total_requests}</div>
                                <div style="font-size:10px;color:#94a3b8;margin-top:2px">ครั้งที่เบิก</div>
                            </div>
                            <div style="padding:14px 12px;text-align:center">
                                <div style="font-size:18px;font-weight:800;color:#f59e0b">${wCost}</div>
                                <div style="font-size:10px;color:#94a3b8;margin-top:2px">มูลค่าเบิกรวม (บาท)</div>
                            </div>
                        </div>

                        <!-- Details row -->
                        <div style="display:flex;justify-content:space-around;padding:10px 16px;background:#f8fafc;border-bottom:1px solid #f1f5f9;font-size:12px;color:#64748b;flex-wrap:wrap;gap:8px">
                            <span><i class="fas fa-tag" style="color:#0ea5e9;margin-right:4px"></i>ราคา/หน่วย: <strong style="color:#1e293b">${price} บาท</strong></span>
                            <span><i class="fas fa-warehouse" style="color:#0ea5e9;margin-right:4px"></i>มูลค่าคงเหลือ: <strong style="color:#1e293b">${stockVal} บาท</strong></span>
                            <span><i class="fas fa-arrow-down" style="color:#0ea5e9;margin-right:4px"></i>เบิกขั้นต่ำ: <strong style="color:#1e293b">${minO}</strong></span>
                            <span><i class="fas fa-arrow-up" style="color:#0ea5e9;margin-right:4px"></i>เบิกสูงสุด: <strong style="color:#1e293b">${maxO}</strong></span>
                            <span><i class="fas fa-exclamation-triangle" style="color:#f59e0b;margin-right:4px"></i>Min Stock: <strong style="color:#1e293b">${s.min_stock}</strong></span>
                        </div>

                        <!-- History table -->
                        <div style="padding:12px 16px">
                            <div style="font-size:12px;font-weight:700;color:#475569;margin-bottom:8px">
                                <i class="fas fa-history" style="color:#6366f1;margin-right:6px"></i>ประวัติการเบิกล่าสุด
                            </div>
                            <div style="max-height:160px;overflow-y:auto;border-radius:8px;border:1px solid #e2e8f0">
                                <table style="width:100%;border-collapse:collapse">
                                    <thead>
                                        <tr style="background:#f8fafc;font-size:11px;color:#64748b">
                                            <th style="padding:5px 8px;text-align:left;font-weight:600">วันที่</th>
                                            <th style="padding:5px 8px;text-align:left;font-weight:600">เลขที่ใบเบิก</th>
                                            <th style="padding:5px 8px;text-align:left;font-weight:600">ผู้เบิก</th>
                                            <th style="padding:5px 8px;text-align:center;font-weight:600">จำนวน</th>
                                            <th style="padding:5px 8px;text-align:center;font-weight:600">สถานะ</th>
                                        </tr>
                                    </thead>
                                    <tbody>${histRows}</tbody>
                                </table>
                            </div>
                        </div>
                    </div>`
                    });
                })
                .catch(() => {
                    restoreScroll();
                    Swal.fire({
                        icon: 'error',
                        title: 'เกิดข้อผิดพลาด',
                        text: 'ไม่สามารถโหลดข้อมูลได้',
                        heightAuto: false,
                        returnFocus: false,
                        didClose: restoreScroll
                    });
                });
        }
    </script>
    <?php if (($sys_settings['node_service_enabled'] ?? '1') === '1'): ?>
        <script src="https://cdn.socket.io/4.8.1/socket.io.min.js"></script>
        <script src="js/realtime.js"></script>
    <?php endif; ?>
    
    <!-- ===== NOTIFICATION SYSTEM ===== -->
    <script>
        (function () {
            const btn = document.getElementById('notif-btn');
            const dropdown = document.getElementById('notif-dropdown');
            const badge = document.getElementById('notif-badge');
            const list = document.getElementById('notif-list');
            if (!btn) return;

            const ICON_MAP = {
                request_new: { bg: 'bg-blue-100', ic: 'fas fa-file-alt', cl: 'text-blue-500' },
                section_pending_supply: { bg: 'bg-amber-100', ic: 'fas fa-clock', cl: 'text-amber-500' },
                section_pending_director: { bg: 'bg-orange-100', ic: 'fas fa-user-tie', cl: 'text-orange-500' },
                supply_pending: { bg: 'bg-purple-100', ic: 'fas fa-hourglass-half', cl: 'text-purple-500' },
                supply_approved: { bg: 'bg-green-100', ic: 'fas fa-check-circle', cl: 'text-green-500' },
                success: { bg: 'bg-green-100', ic: 'fas fa-check-circle', cl: 'text-green-500' },
                danger: { bg: 'bg-red-100', ic: 'fas fa-times-circle', cl: 'text-red-500' },
                warning: { bg: 'bg-yellow-100', ic: 'fas fa-exclamation-triangle', cl: 'text-yellow-600' },
                info: { bg: 'bg-sky-100', ic: 'fas fa-info-circle', cl: 'text-sky-500' },
            };

            function getIcon(type) {
                return ICON_MAP[type] || ICON_MAP['info'];
            }

            function timeAgo(dateStr) {
                const d = new Date(dateStr.replace(' ', 'T'));
                const diff = Math.floor((Date.now() - d.getTime()) / 1000);
                if (diff < 60) return 'เมื่อกี้';
                if (diff < 3600) return Math.floor(diff / 60) + ' นาทีที่แล้ว';
                if (diff < 86400) return Math.floor(diff / 3600) + ' ชั่วโมงที่แล้ว';
                return Math.floor(diff / 86400) + ' วันที่แล้ว';
            }

            function renderBadge(count) {
                if (count > 0) {
                    badge.textContent = count > 99 ? '99+' : count;
                    badge.classList.remove('hidden');
                } else {
                    badge.classList.add('hidden');
                }
            }

            function loadNotifications() {
                fetch('api_notifications.php?action=get', { credentials: 'same-origin' })
                    .then(r => r.json())
                    .then(data => {
                        if (data.status !== 'success') return;
                        renderBadge(data.unread_count);

                        if (!data.notifications || data.notifications.length === 0) {
                            list.innerHTML = '<div class="py-10 text-center text-gray-400 text-sm"><i class="fas fa-bell-slash text-2xl mb-2 block"></i>ไม่มีการแจ้งเตือน</div>';
                            return;
                        }

                        let html = '';
                        data.notifications.forEach(n => {
                            const read = n.is_read == 1;
                            const ic = getIcon(n.type);
                            const safeMsg = n.message.replace(/</g, '&lt;').replace(/>/g, '&gt;');
                            const safeLink = (n.link || 'my_requests.php').replace(/'/g, '%27');
                            html += `
                        <div class="flex items-start gap-3 px-4 py-3 hover:bg-gray-50 cursor-pointer transition-colors ${read ? 'opacity-60' : ''}"
                             onclick="notifReadAndGo(${n.id},'${safeLink}')">
                            <div class="w-9 h-9 rounded-full ${ic.bg} flex items-center justify-center flex-shrink-0 mt-0.5">
                                <i class="${ic.ic} text-sm ${ic.cl}"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs text-gray-700 leading-snug ${read ? '' : 'font-semibold'}">${safeMsg}</p>
                                <p class="text-[10px] text-gray-400 mt-1">${timeAgo(n.created_at)}</p>
                            </div>
                            ${!read ? '<span class="w-2 h-2 bg-sky-500 rounded-full flex-shrink-0 mt-2"></span>' : ''}
                        </div>`;
                        });
                        list.innerHTML = html;
                    })
                    .catch(() => { });
            }

            window.notifReadAndGo = function (id, link) {
                fetch('api_notifications.php?action=mark_read', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'id=' + id
                }).then(() => {
                    dropdown.classList.add('hidden');
                    window.location.href = link;
                });
            };

            window.markAllRead = function () {
                fetch('api_notifications.php?action=mark_all_read', { method: 'POST', credentials: 'same-origin' })
                    .then(() => loadNotifications());
            };

            // Toggle dropdown
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                const hidden = dropdown.classList.toggle('hidden');
                if (!hidden) loadNotifications();
            });

            // Close on outside click
            document.addEventListener('click', function (e) {
                if (!dropdown.contains(e.target) && e.target !== btn) {
                    dropdown.classList.add('hidden');
                }
            });

            // Initial load + poll every 30 s
            loadNotifications();
            setInterval(loadNotifications, 30000);
        })();
    </script>

    <!-- ===== LOGIN NOTIFICATION POPUPS ===== -->
    <script>
        (function () {
            window.showLoginPopups = function () {
                fetch('api_login_popups.php?action=get_popups')
                    .then(r => r.json())
                    .then(data => {
                        if (data.status !== 'success' || !data.popups || data.popups.length === 0) {
                            return;
                        }

                        let popup = data.popups[0];

                        if (popup.type === 'user_approved') {
                            let itemsHtml = popup.items.map(i => `<tr><td class="px-4 py-2 border-b">${i.name}</td><td class="px-4 py-2 border-b text-center font-bold text-sky-600">${i.approved_qty} ${i.unit}</td></tr>`).join('');
                            Swal.fire({
                                title: 'รายการรอรับวัสดุ (Pending material delivery)',
                                html: `
                                <div class="text-left font-[Sarabun]">
                                    <div class="mb-4 bg-green-50 p-4 rounded-xl border border-green-100">
                                        <p class="text-green-700 font-bold"><i class="fas fa-check-circle mr-2"></i>Request approved</p>
                                        <p class="text-sm mt-1">เลขที่ใบเบิก: <strong>${popup.request_name}</strong></p>
                                        <p class="text-sm">วันที่: <strong>${popup.date}</strong></p>
                                    </div>
                                    <table class="w-full text-sm text-left border">
                                        <thead class="bg-gray-100">
                                            <tr>
                                                <th class="px-4 py-2 border-b">รายการวัสดุ</th>
                                                <th class="px-4 py-2 border-b text-center">จำนวนที่อนุมัติ</th>
                                            </tr>
                                        </thead>
                                        <tbody>${itemsHtml}</tbody>
                                    </table>
                                </div>
                            `,
                                width: '80%',
                                allowOutsideClick: false,
                                confirmButtonText: 'รับทราบ',
                                confirmButtonColor: '#0ea5e9'
                            }).then((res) => {
                                if (res.isConfirmed) {
                                    fetch('api_login_popups.php', {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                        body: 'action=acknowledge_user_approval&request_id=' + popup.request_id
                                    }).then(() => {
                                        showLoginPopups();
                                    });
                                }
                            });
                        } else if (popup.type === 'user_progress') {
                            if (sessionStorage.getItem('user_progress_shown')) return;
                            sessionStorage.setItem('user_progress_shown', '1');

                            let reqHtml = popup.requests.map(r => {
                                let sName = '';
                                if (r.status === 'pending_head') sName = '<span class="text-amber-500 font-bold">รอหัวหน้าส่วนอนุมัติ</span>';
                                else if (r.status === 'pending_supply') sName = '<span class="text-purple-500 font-bold">รอพัสดุตรวจสอบ</span>';
                                else if (r.status === 'pending_director') sName = '<span class="text-orange-500 font-bold">รอผู้อำนวยการอนุมัติ</span>';
                                return `<tr class="border-b"><td class="py-2 px-2">${r.request_name}</td><td class="py-2 px-2">${r.request_date}</td><td class="py-2 px-2 text-center">${sName}</td></tr>`;
                            }).join('');

                            Swal.fire({
                                title: 'สถานะการเบิกวัสดุของคุณ',
                                html: `
                                <div class="text-left font-[Sarabun] max-h-[60vh] overflow-y-auto">
                                    <table class="w-full text-sm">
                                        <thead class="bg-gray-100"><tr><th class="py-2 px-2">เลขที่ใบเบิก</th><th class="py-2 px-2">วันที่</th><th class="py-2 px-2 text-center">สถานะ</th></tr></thead>
                                        <tbody>${reqHtml}</tbody>
                                    </table>
                                    <p class="text-xs text-gray-500 mt-4">*สามารถติดตามความคืบหน้าได้ที่หน้า "ประวัติการเบิก"</p>
                                </div>
                            `,
                                width: '80%',
                                allowOutsideClick: false,
                                confirmButtonText: 'ปิด',
                                confirmButtonColor: '#64748b'
                            });
                        }
                    });
            };
            showLoginPopups();
        })();
    </script>
</main>
</body>

</html>