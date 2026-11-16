<?php
// sidebar.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config.php';

$role = $_SESSION['role'] ?? '';
$section = $_SESSION['section'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;

$user_telegram_chat_id = '';
$sys_telegram_bot_username = '';
if ($user_id > 0) {
    try {
        $stmtTelegram = $pdo->prepare("SELECT telegram_chat_id FROM users WHERE id = ?");
        $stmtTelegram->execute([$user_id]);
        $user_telegram_chat_id = $stmtTelegram->fetchColumn() ?: '';
    } catch (Exception $e) {}
    
    try {
        $stmtBotUsername = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'telegram_bot_username'");
        $stmtBotUsername->execute();
        $sys_telegram_bot_username = $stmtBotUsername->fetchColumn() ?: '';
    } catch (Exception $e) {}
}

// Darken HEX color helper
function darkenColor($hex, $percent) {
    $hex = ltrim($hex, '#');
    if (strlen($hex) == 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));

    $r = max(0, min(255, $r - ($r * ($percent / 100))));
    $g = max(0, min(255, $g - ($g * ($percent / 100))));
    $b = max(0, min(255, $b - ($b * ($percent / 100))));

    return sprintf("#%02x%02x%02x", $r, $g, $b);
}

// Compute pending approval count if not already defined
if (!isset($pendingApprovalCount)) {
    $pendingApprovalCount = 0;
    try {
        if ($role == 'head' || $role == 'deputy_head') {
            // Count distinct users (people) in same section requesting approvals
            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT r.user_id) FROM requests r JOIN users u ON r.user_id=u.id WHERE r.status='pending_head' AND TRIM(u.section)=TRIM(?)");
            $stmt->execute([$section]);
            $pendingApprovalCount = (int) $stmt->fetchColumn();
        } elseif ($role == 'supply_head') {
            // Count distinct sections: pending_supply + pending_director + pending_head from ส่วนคลัง
            $stmt = $pdo->query("SELECT COUNT(DISTINCT u.section) FROM requests r JOIN users u ON r.user_id=u.id WHERE (r.status='pending_supply' OR r.status='pending_director' OR (r.status='pending_head' AND u.section='ส่วนคลัง'))");
            $pendingApprovalCount = (int) $stmt->fetchColumn();
        } elseif (in_array($role, ['supply_officer', 'admin'])) {
            // Count distinct sections
            $stmt = $pdo->query("SELECT COUNT(DISTINCT u.section) FROM requests r JOIN users u ON r.user_id=u.id WHERE (r.status='pending_supply' OR (r.status='pending_head' AND u.section='ส่วนคลัง'))");
            $pendingApprovalCount = (int) $stmt->fetchColumn();
        } elseif ($role == 'director') {
            // Count distinct sections
            $stmt = $pdo->query("SELECT COUNT(DISTINCT section) FROM requests WHERE status='pending_director'");
            $pendingApprovalCount = (int) $stmt->fetchColumn();
        } elseif ($role == 'user') {
            // Count distinct requests
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM requests WHERE user_id=? AND status LIKE 'pending%'");
            $stmt->execute([$user_id]);
            $pendingApprovalCount = (int) $stmt->fetchColumn();
        }
    } catch (Exception $e) {
        $pendingApprovalCount = 0;
    }
}

// Current page variable fallback
if (!isset($page)) {
    $page = '';
}
?>
<!-- Dynamic Styling and Overrides based on settings -->
<style>
    :root {
        --primary-color: <?php echo $sys_settings['theme_color']; ?>;
        --primary-hover: <?php echo darkenColor($sys_settings['theme_color'], 15); ?>;
        --primary-light: <?php echo $sys_settings['theme_color']; ?>1a; /* 10% opacity */
    }

    /* Sidebar dynamic colors */
    aside.w-64 {
        background-color: var(--primary-color) !important;
        border-color: rgba(255, 255, 255, 0.1) !important;
    }
    aside.w-64 .border-b, aside.w-64 .border-t {
        border-color: rgba(255, 255, 255, 0.1) !important;
    }
    aside.w-64 h1 {
        color: #ffffff !important;
    }
    aside.w-64 p {
        color: rgba(255, 255, 255, 0.65) !important;
    }
    .sidebar-link {
        color: rgba(255, 255, 255, 0.8) !important;
        transition: all 0.3s;
    }
    .sidebar-link:hover {
        background-color: rgba(255, 255, 255, 0.1) !important;
        color: #ffffff !important;
        border-right: none !important;
    }
    .sidebar-link.active {
        background-color: #ffffff !important;
        color: #000000 !important;
        border-right: 4px solid #ffffff !important;
        font-weight: bold !important;
        border-right: 4px solid #ffffff !important;
    }
    .sidebar-link.active i {
        color: #000000 !important;
    }
    aside.w-64 nav p {
        color: rgba(255, 255, 255, 0.45) !important;
    }
    aside.w-64 .p-4 a:first-child {
        color: rgba(255, 255, 255, 0.8) !important;
    }
    aside.w-64 .p-4 a:first-child:hover {
        background-color: rgba(255, 255, 255, 0.1) !important;
        color: #ffffff !important;
    }

    /* System color overrides */
    .bg-sky-500, .bg-sky-600, .bg-blue-500, .bg-indigo-600, .bg-violet-600 {
        background-color: var(--primary-color) !important;
    }
    .hover\:bg-sky-600:hover, .hover\:bg-sky-700:hover, .hover\:bg-blue-600:hover {
        background-color: var(--primary-hover) !important;
    }
    .text-sky-500, .text-sky-600, .text-blue-500, .text-indigo-600, .text-violet-600 {
        color: var(--primary-color) !important;
    }
    .border-sky-500, .border-blue-500 {
        border-color: var(--primary-color) !important;
    }
    .bg-sky-50 {
        background-color: var(--primary-light) !important;
    }
    .text-sky-700 {
        color: var(--primary-hover) !important;
    }
    .gradient-btn {
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-hover) 100%) !important;
    }
    .focus\:ring-sky-100:focus {
        --tw-ring-color: var(--primary-light) !important;
    }
    
    /* Navbar / Header dynamic styles */
    main header, header.sticky {
        background-color: var(--primary-color) !important;
        color: #ffffff !important;
    }
    main header h1:not(#notif-dropdown *), 
    main header h2:not(#notif-dropdown *), 
    main header h3:not(#notif-dropdown *), 
    main header p:not(#notif-dropdown *), 
    main header i:not(#notif-dropdown *):not(form *), 
    main header span:not(#notif-dropdown *), 
    main header a:not(#notif-dropdown *) {
        color: #ffffff !important;
    }
    main header a:not(#notif-dropdown *):hover {
        color: rgba(255, 255, 255, 0.8) !important;
    }
    #notif-dropdown {
        color: #374151 !important;
    }
    main header input, 
    main header select, 
    main header textarea {
        color: #1f2937 !important;
        background-color: #ffffff !important;
    }
    main header input::placeholder {
        color: #9ca3af !important;
    }
    main header form i,
    main header form button i {
        color: #9ca3af !important;
    }
    main header form button:hover i {
        color: #ef4444 !important;
    }
</style>

<script>
    (function() {
        // Intercept Swal success alerts globally
        function wrapSwal(swalObj) {
            if (!swalObj || !swalObj.fire || swalObj._wrapped) return;
            const originalFire = swalObj.fire;
            swalObj.fire = function(...args) {
                let opts = args[0];
                if (opts && typeof opts === 'object') {
                    if ((opts.icon === 'success' || opts.status === 'success') && opts.timer === undefined && !opts.showConfirmButton && !opts.keepOpen) {
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

        if (window.Swal) {
            wrapSwal(window.Swal);
        } else {
            let _Swal = undefined;
            Object.defineProperty(window, 'Swal', {
                configurable: true,
                set: function(val) {
                    _Swal = val;
                    wrapSwal(val);
                },
                get: function() {
                    return _Swal;
                }
            });
        }

        // Load custom Favicon and update Document Title dynamically
        var favicon = '<?php echo htmlspecialchars($sys_settings['favicon_path']); ?>';
        var link = document.querySelector("link[rel~='icon']");
        if (!link) {
            link = document.createElement('link');
            link.rel = 'icon';
            document.getElementsByTagName('head')[0].appendChild(link);
        }
        link.href = favicon;

        var siteTh = <?php echo json_encode($sys_settings['site_name_th']); ?>;
        var siteEn = <?php echo json_encode($sys_settings['site_name_en']); ?>;
        if (document.title.includes(" - ระบบเบิกวัสดุ")) {
            document.title = document.title.replace(" - ระบบเบิกวัสดุ", " - " + siteTh);
        } else if (document.title.includes(" - DURABLES")) {
            document.title = document.title.replace(" - DURABLES", " - " + siteEn);
        } else {
            if (!document.title.includes(siteTh) && !document.title.includes(siteEn)) {
                document.title = document.title + " - " + siteTh;
            }
        }
    })();
    function toggleReportSubmenu() {
        const submenu = document.getElementById('report-submenu');
        const chevron = document.getElementById('report-submenu-chevron');
        if (!submenu) return;
        const isHidden = submenu.classList.toggle('hidden');
        if (chevron) {
            if (isHidden) {
                chevron.classList.remove('rotate-180');
            } else {
                chevron.classList.add('rotate-180');
            }
        }
    }
</script>

<aside class="w-64 shadow-xl hidden md:flex flex-col z-10 transition-all duration-300 flex-shrink-0 h-screen sticky top-0 font-[Sarabun]">
    <div class="p-6 flex items-center justify-center border-b border-gray-100">
        <div class="flex items-center gap-3">
            <div class="w-16 h-16 bg-white/20 rounded-lg flex items-center justify-center text-white font-bold text-xl shadow-lg">
                <img src="<?php echo htmlspecialchars($sys_settings['logo_path']); ?>" alt="System Logo" style="border-radius:10px;" class="h-16 w-16 object-cover">
            </div>
            <div>
                <h1 class="text-xl font-bold tracking-tight text-white"><?php echo htmlspecialchars($sys_settings['site_name_en']); ?></h1>
                <p class="text-xs font-medium text-white/70"><?php echo htmlspecialchars($sys_settings['site_name_th']); ?></p>
            </div>
        </div>
    </div>

    <nav class="flex-1 overflow-y-auto py-4 space-y-1">
        <p class="px-6 text-xs text-gray-400 font-semibold uppercase tracking-wider mb-2">เมนูหลัก</p>

        <!-- 1. Home Link -->
        <a href="<?php echo $role === 'user' ? 'inventory.php' : 'dashboard.php'; ?>" class="sidebar-link <?php echo (($page == 'home' && $role !== 'user') || ($page == 'inventory' && $role === 'user')) ? 'active' : ''; ?> flex items-center px-6 py-3 text-gray-600">
            <i class="fas fa-chart-pie w-6"></i>
            <span class="font-medium">หน้าหลัก</span>
        </a>

        <!-- 2. Inventory (Visible to All) -->
        <a href="inventory.php" class="sidebar-link <?php echo $page == 'inventory' ? 'active' : ''; ?> flex items-center px-6 py-3 text-gray-600">
            <i class="fas fa-shopping-basket w-6"></i>
            <span class="font-medium">เบิกวัสดุ (Shop)</span>
        </a>

        <!-- 4. Approvals (Visible to All) -->
        <a href="approvals.php" class="sidebar-link <?php echo $page == 'approvals' ? 'active' : ''; ?> flex items-center px-6 py-3 text-gray-600">
            <i class="fas fa-check-circle w-6"></i>
            <span class="font-medium">รายการรออนุมัติ</span>
            <?php if ($pendingApprovalCount > 0): ?>
                <span id="sidebar-approval-badge" class="ml-auto bg-red-500 text-white text-[10px] font-bold min-w-[18px] h-[18px] rounded-full flex items-center justify-center px-1"><?php echo $pendingApprovalCount > 99 ? '99+' : $pendingApprovalCount; ?></span>
            <?php endif; ?>
        </a>

        <!-- 3. My Requests (Visible to All) -->
        <a href="my_requests.php" class="sidebar-link <?php echo $page == 'my_requests' ? 'active' : ''; ?> flex items-center px-6 py-3 text-gray-600">
            <i class="fas fa-list-ul w-6"></i>
            <span class="font-medium">ประวัติการเบิก</span>
        </a>

        <!-- Admin / Supply Head / Supply Officer Menus -->
        <?php if (in_array($role, ['admin', 'supply_head', 'supply_officer'])): ?>
            <p class="px-6 text-xs text-gray-400 font-semibold uppercase tracking-wider mt-6 mb-2">การจัดการคลัง</p>
            <a href="supplies.php" class="sidebar-link <?php echo $page == 'supplies' ? 'active' : ''; ?> flex items-center px-6 py-3 text-gray-600">
                <i class="fas fa-boxes w-6"></i>
                <span class="font-medium">จัดการคลังวัสดุ</span>
            </a>
            <a href="procurement.php" class="sidebar-link <?php echo $page == 'procurement' ? 'active' : ''; ?> flex items-center px-6 py-3 text-gray-600">
                <i class="fas fa-truck-loading w-6"></i>
                <span class="font-medium">นำเข้าวัสดุ</span>
            </a>
        <?php endif; ?>

        <!-- Reports Section with Collapsible Submenu -->
        <?php if (in_array($role, ['admin', 'director', 'supply_head', 'supply_officer'])): ?>
            <p class="px-6 text-xs text-gray-400 font-semibold uppercase tracking-wider mt-6 mb-2">ระบบรายงาน</p>
            <div class="space-y-1">
                <button type="button" onclick="toggleReportSubmenu()" class="w-full sidebar-link <?php echo in_array($page, ['reports', 'requisition_stats', 'print_reports', 'year_end']) ? 'active text-sky-600 bg-sky-50/70' : 'text-gray-600 hover:text-sky-600 hover:bg-gray-50'; ?> flex items-center justify-between px-6 py-3 transition rounded-xl">
                    <div class="flex items-center">
                        <i class="fas fa-chart-pie w-6"></i>
                        <span class="font-medium">รายงานและการพิมพ์</span>
                    </div>
                    <i id="report-submenu-chevron" class="fas fa-chevron-down text-xs transition-transform duration-200 <?php echo in_array($page, ['reports', 'requisition_stats', 'print_reports', 'year_end']) ? 'rotate-180' : ''; ?>"></i>
                </button>
                <div id="report-submenu" class="<?php echo in_array($page, ['reports', 'requisition_stats', 'print_reports', 'year_end']) ? '' : 'hidden'; ?> bg-slate-50/80 py-1.5 space-y-1 rounded-xl border border-slate-100 mx-3">
                    <a href="reports.php" class="flex items-center px-4 py-2 text-xs font-semibold rounded-lg text-slate-600 hover:text-sky-600 hover:bg-white transition <?php echo $page == 'reports' ? 'text-sky-600 font-bold bg-white shadow-sm' : ''; ?>">
                        <i class="fas fa-file-invoice-dollar w-5 text-sky-500"></i>
                        <span>รายงานรวมและสถิติ</span>
                    </a>
                    <a href="requisition_stats.php" class="flex items-center px-4 py-2 text-xs font-semibold rounded-lg text-slate-600 hover:text-sky-600 hover:bg-white transition <?php echo $page == 'requisition_stats' ? 'text-sky-600 font-bold bg-white shadow-sm' : ''; ?>">
                        <i class="fas fa-chart-line w-5 text-indigo-500"></i>
                        <span>สถิติการเบิกสะสมรายบุคคล</span>
                    </a>
                    <a href="print_reports_setup.php" class="flex items-center px-4 py-2 text-xs font-semibold rounded-lg text-slate-600 hover:text-sky-600 hover:bg-white transition <?php echo $page == 'print_reports' ? 'text-sky-600 font-bold bg-white shadow-sm' : ''; ?>">
                        <i class="fas fa-print w-5 text-amber-500"></i>
                        <span>พิมพ์รายงาน / แบบฟอร์ม</span>
                    </a>
                    <?php if (in_array($role, ['admin', 'supply_head', 'supply_officer'])): ?>
                    <a href="year_end_transfer.php" class="flex items-center px-4 py-2 text-xs font-semibold rounded-lg text-slate-600 hover:text-sky-600 hover:bg-white transition <?php echo $page == 'year_end' ? 'text-sky-600 font-bold bg-white shadow-sm' : ''; ?>">
                        <i class="fas fa-calendar-check w-5 text-rose-500"></i>
                        <span>งานตัดโอนสิ้นปีและวิเคราะห์</span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Admin Logs & Users -->
        <?php if ($role == 'admin'): ?>
            <p class="px-6 text-xs text-gray-400 font-semibold uppercase tracking-wider mt-6 mb-2">Admin Only</p>
            <a href="users.php" class="sidebar-link <?php echo $page == 'users' ? 'active' : ''; ?> flex items-center px-6 py-3 text-gray-600">
                <i class="fas fa-users w-6"></i>
                <span class="font-medium">จัดการผู้ใช้งาน</span>
            </a>
            <a href="admin_check_data.php" class="sidebar-link <?php echo $page == 'admin_check_data' ? 'active' : ''; ?> flex items-center px-6 py-3 text-gray-600">
                <i class="fas fa-database w-6"></i>
                <span class="font-medium">ตรวจสอบข้อมูลระบบ</span>
            </a>
            <a href="settings.php" class="sidebar-link <?php echo $page == 'settings' ? 'active' : ''; ?> flex items-center px-6 py-3 text-gray-600">
                <i class="fas fa-cog w-6"></i>
                <span class="font-medium">ตั้งค่าระบบ</span>
            </a>
        <?php endif; ?>

        <?php if (in_array($role, ['admin', 'supply_head'])): ?>
            <?php if ($role != 'admin'): ?>
                <p class="px-6 text-xs text-gray-400 font-semibold uppercase tracking-wider mt-6 mb-2">Admin Only</p>
            <?php endif; ?>
            <a href="logs.php" class="sidebar-link <?php echo $page == 'logs' ? 'active' : ''; ?> flex items-center px-6 py-3 text-gray-600">
                <i class="fas fa-history w-6"></i>
                <span class="font-medium">ประวัติการใช้งาน</span>
            </a>
        <?php endif; ?>
    </nav>

    <div class="p-4 border-t border-gray-100 space-y-2">
        <a href="javascript:void(0)" onclick="telegramSettings()" class="flex items-center gap-3 px-4 py-3 text-gray-600 hover:bg-sky-50 hover:text-sky-600 rounded-xl transition-colors">
            <i class="fab fa-telegram"></i>
            <span class="font-medium">ตั้งค่าแจ้งเตือน Telegram</span>
        </a>
        <a href="javascript:void(0)" onclick="changePassword()" class="flex items-center gap-3 px-4 py-3 text-gray-600 hover:bg-sky-50 hover:text-sky-600 rounded-xl transition-colors">
            <i class="fas fa-key"></i>
            <span class="font-medium">เปลี่ยนรหัสผ่าน</span>
        </a>
        <a href="javascript:void(0)" onclick="confirmLogout()" class="flex items-center gap-3 px-4 py-3 bg-red-50 text-red-600 rounded-xl hover:bg-red-100 transition-colors">
            <i class="fas fa-sign-out-alt"></i>
            <span class="font-medium">ออกจากระบบ</span>
        </a>
    </div>
</aside>

<script>
    if (typeof confirmLogout !== 'function') {
        function confirmLogout() {
            Swal.fire({
                title: 'ยืนยันการออกจากระบบ?',
                text: "คุณต้องการออกจากระบบหรือไม่",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#cbd5e1',
                confirmButtonText: 'ใช่, ออกจากระบบ',
                cancelButtonText: 'ยกเลิก',
                customClass: {
                    popup: 'rounded-2xl',
                    confirmButton: 'rounded-xl',
                    cancelButton: 'rounded-xl text-gray-600'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'ออกจากระบบสำเร็จ',
                        text: 'กำลังพาท่านออกจากระบบ...',
                        icon: 'success',
                        showConfirmButton: false,
                        timer: 1000
                    }).then(() => {
                        window.location.href = 'dashboard.php?logout=true';
                    });
                }
            })
        }
    }

    if (typeof togglePasswordVisibility !== 'function') {
        function togglePasswordVisibility(inputId, btn) {
            const input = typeof inputId === 'string' ? document.getElementById(inputId) : inputId;
            if (!input) return;
            const isPass = input.type === 'password';
            input.type = isPass ? 'text' : 'password';
            const icon = btn.querySelector('i') || btn;
            if (icon) {
                if (isPass) {
                    icon.className = 'fas fa-eye-slash text-sm';
                    btn.classList.add('text-sky-600');
                    btn.classList.remove('text-gray-400');
                } else {
                    icon.className = 'far fa-eye text-sm';
                    btn.classList.remove('text-sky-600');
                    btn.classList.add('text-gray-400');
                }
            }
        }
        window.togglePasswordVisibility = togglePasswordVisibility;
    }

    if (typeof changePassword !== 'function') {
        function changePassword() {
            Swal.fire({
                title: 'เปลี่ยนรหัสผ่าน',
                html: `
                    <div class="text-left font-[Sarabun] space-y-4 pt-2">
                        <div>
                            <label class="block text-xs font-bold text-sky-700 mb-1 ml-1">รหัสผ่านเดิม (Current Password)</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-sky-400 pointer-events-none"><i class="fas fa-lock"></i></span>
                                <input type="password" id="cp_old_pass" autocomplete="new-password" class="w-full pl-10 pr-10 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-sky-500 focus:border-sky-500 outline-none transition shadow-sm placeholder-gray-300" placeholder="ระบุรหัสผ่านเดิม">
                                <button type="button" tabindex="-1" onclick="togglePasswordVisibility('cp_old_pass', this)" class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-sky-600 focus:outline-none transition-colors cursor-pointer" title="แสดง/ซ่อนรหัสผ่าน">
                                    <i class="far fa-eye text-sm"></i>
                                </button>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-sky-700 mb-1 ml-1">รหัสผ่านใหม่ (New Password)</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-sky-400 pointer-events-none"><i class="fas fa-key"></i></span>
                                <input type="password" id="cp_new_pass" autocomplete="new-password" class="w-full pl-10 pr-10 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-sky-500 focus:border-sky-500 outline-none transition shadow-sm placeholder-gray-300" placeholder="ระบุอย่างน้อย 6 ตัวอักษร">
                                <button type="button" tabindex="-1" onclick="togglePasswordVisibility('cp_new_pass', this)" class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-sky-600 focus:outline-none transition-colors cursor-pointer" title="แสดง/ซ่อนรหัสผ่าน">
                                    <i class="far fa-eye text-sm"></i>
                                </button>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-sky-700 mb-1 ml-1">ยืนยันรหัสผ่านใหม่ (Confirm New)</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-sky-400 pointer-events-none"><i class="fas fa-check-double"></i></span>
                                <input type="password" id="cp_confirm_pass" autocomplete="new-password" class="w-full pl-10 pr-10 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-sky-500 focus:border-sky-500 outline-none transition shadow-sm placeholder-gray-300" placeholder="ระบุอีกครั้ง">
                                <button type="button" tabindex="-1" onclick="togglePasswordVisibility('cp_confirm_pass', this)" class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-sky-600 focus:outline-none transition-colors cursor-pointer" title="แสดง/ซ่อนรหัสผ่าน">
                                    <i class="far fa-eye text-sm"></i>
                                </button>
                            </div>
                            <div id="cp_match_msg" class="h-5 mt-1 transition-all"></div>
                        </div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'บันทึกรหัสผ่านใหม่',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#0ea5e9',
                cancelButtonColor: '#cbd5e1',
                customClass: {
                    popup: 'rounded-3xl shadow-2xl w-[450px]',
                    title: 'text-xl font-bold text-gray-800',
                    confirmButton: 'rounded-xl px-6 py-2.5 font-bold text-sm shadow-lg shadow-sky-200',
                    cancelButton: 'rounded-xl px-6 py-2.5 font-bold text-sm text-gray-600'
                },
                didOpen: () => {
                    const oldP = Swal.getPopup().querySelector('#cp_old_pass');
                    const newP = Swal.getPopup().querySelector('#cp_new_pass');
                    const confP = Swal.getPopup().querySelector('#cp_confirm_pass');
                    const msg = Swal.getPopup().querySelector('#cp_match_msg');
                    const btn = Swal.getConfirmButton();

                    // Force clear values to prevent autocomplete
                    oldP.value = '';
                    newP.value = '';
                    confP.value = '';

                    btn.disabled = true;
                    btn.style.opacity = '0.5';
                    btn.style.cursor = 'not-allowed';

                    function validate() {
                        const vOld = oldP.value;
                        const v1 = newP.value;
                        const v2 = confP.value;

                        newP.classList.remove('border-red-500', 'border-green-500');
                        confP.classList.remove('border-red-500', 'border-green-500');

                        if (v1.length > 0 && v1.length < 6) {
                            msg.innerHTML = '<span class="text-xs text-red-500 font-bold flex items-center gap-1"><i class="fas fa-times-circle"></i> รหัสผ่านสั้นเกินไป</span>';
                            newP.classList.add('border-red-500');
                            btn.disabled = true; btn.style.opacity = '0.5'; btn.style.cursor = 'not-allowed';
                            return;
                        }

                        if (vOld.length > 0 && v1.length >= 6 && v2.length > 0) {
                            if (v1 === v2) {
                                msg.innerHTML = '<span class="text-xs text-green-500 font-bold flex items-center gap-1"><i class="fas fa-check-circle"></i> รหัสผ่านตรงกัน</span>';
                                confP.classList.add('border-green-500');
                                newP.classList.add('border-green-500');
                                btn.disabled = false; btn.style.opacity = '1'; btn.style.cursor = 'pointer';
                            } else {
                                msg.innerHTML = '<span class="text-xs text-red-500 font-bold flex items-center gap-1"><i class="fas fa-times-circle"></i> รหัสผ่านไม่ตรงกัน</span>';
                                confP.classList.add('border-red-500');
                                btn.disabled = true; btn.style.opacity = '0.5'; btn.style.cursor = 'not-allowed';
                            }
                        } else {
                            msg.innerHTML = '';
                            btn.disabled = true; btn.style.opacity = '0.5'; btn.style.cursor = 'not-allowed';
                        }
                    }

                    oldP.addEventListener('input', validate);
                    newP.addEventListener('input', validate);
                    confP.addEventListener('input', validate);
                    oldP.focus();
                },
                preConfirm: async () => {
                    const oldP = Swal.getPopup().querySelector('#cp_old_pass').value.trim();
                    const newP = Swal.getPopup().querySelector('#cp_new_pass').value.trim();
                    const confP = Swal.getPopup().querySelector('#cp_confirm_pass').value.trim();

                    try {
                        const formData = new FormData();
                        formData.append('old_password', oldP);
                        formData.append('new_password', newP);
                        formData.append('confirm_password', confP);

                        const response = await fetch('api_change_password.php', {
                            method: 'POST',
                            body: formData
                        });

                        if (!response.ok) {
                            throw new Error('เกิดข้อผิดพลาดจากเซิร์ฟเวอร์ (' + response.status + ')');
                        }

                        const res = await response.json();
                        if (res.status !== 'success') {
                            Swal.showValidationMessage(res.message || 'รหัสผ่านไม่ถูกต้อง');
                            return false;
                        }
                        return res;
                    } catch (error) {
                        Swal.showValidationMessage(error.message || 'เกิดข้อผิดพลาดในการเชื่อมต่อ');
                        return false;
                    }
                }
            }).then((result) => {
                if (result.isConfirmed && result.value) {
                    Swal.fire({
                        icon: 'success',
                        title: 'สำเร็จ!',
                        text: result.value.message || 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว',
                        timer: 1500,
                        showConfirmButton: false,
                        customClass: { popup: 'rounded-2xl' }
                    });
                }
            });
        }
    }

    if (typeof telegramSettings !== 'function') {
        function telegramSettings() {
            Swal.fire({
                title: 'ตั้งค่าแจ้งเตือน Telegram ส่วนบุคคล',
                html: `
                    <div class="text-left font-[Sarabun] space-y-4 pt-2">
                        <div class="bg-sky-50 p-4 rounded-xl border border-sky-100 mb-4 text-xs text-sky-800 leading-relaxed space-y-2">
                            <strong class="text-sky-950 font-bold block"><i class="fas fa-info-circle mr-1"></i> ขั้นตอนการรับ Chat ID ส่วนบุคคล:</strong>
                            <ol class="list-decimal pl-4 space-y-1">
                                <li>เปิด Telegram ค้นหาบอทตรวจสอบชื่อ <a href="https://t.me/userinfobot" target="_blank" class="font-bold underline text-sky-600 font-semibold">@userinfobot</a> หรือ <a href="https://t.me/raw_tracker_bot" target="_blank" class="font-bold underline text-sky-600 font-semibold">@raw_tracker_bot</a></li>
                                <li>กดปุ่ม <strong>Start</strong> บอทจะแจ้งข้อมูลโปรไฟล์ของคุณในทันที</li>
                                <li>คัดลอกตัวเลขในบรรทัด <strong>Id</strong> (เช่น <code>987654321</code>) มากรอกลงในช่องด้านล่าง</li>
                            </ol>
                            <?php if (!empty($sys_telegram_bot_username)): ?>
                            <div class="border-t border-sky-200/50 pt-2 mt-1.5">
                                <span class="text-amber-700 font-bold block text-[10px]"><i class="fas fa-exclamation-triangle mr-1"></i> สำคัญมาก (ต้องทำ):</span>
                                <p class="text-[10px] text-gray-600 leading-normal">คุณต้องเข้าไปทักทายและกดปุ่ม <strong>Start</strong> เพื่อเปิดคุยกับบอทประจำระบบพัสดุของเราอย่างน้อย 1 ครั้งที่ลิงก์นี้: <a href="https://t.me/<?php echo htmlspecialchars($sys_telegram_bot_username); ?>" target="_blank" class="font-bold underline text-sky-600">@<?php echo htmlspecialchars($sys_telegram_bot_username); ?></a> บอทจึงจะส่งสัญญานแจ้งเตือนเข้าแชทของคุณสำเร็จ</p>
                            </div>
                            <?php else: ?>
                            <div class="border-t border-sky-200/50 pt-2 mt-1.5">
                                <span class="text-amber-700 font-bold block text-[10px]"><i class="fas fa-exclamation-triangle mr-1"></i> สำคัญมาก (ต้องทำ):</span>
                                <p class="text-[10px] text-gray-600 leading-normal">คุณต้องเปิดแชทกับบอทแจ้งเตือนพัสดุของระบบงานอย่างน้อย 1 ครั้งก่อน (สอบถามลิงก์ของบอทได้ที่ผู้ดูแลระบบ) บอทจึงจะได้รับสิทธิ์ส่งข้อความแจ้งเตือนหาท่านได้</p>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-sky-700 mb-1 ml-1">Telegram Chat ID ของท่าน</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-sky-400"><i class="fab fa-telegram"></i></span>
                                <input type="text" id="tg_chat_id" class="w-full pl-10 pr-3 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-sky-500 focus:border-sky-500 outline-none transition shadow-sm" 
                                       value="<?php echo htmlspecialchars($user_telegram_chat_id); ?>" placeholder="ระบุตัวเลข Chat ID เช่น 987654321">
                            </div>
                        </div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'บันทึกการตั้งค่า',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#0ea5e9',
                cancelButtonColor: '#cbd5e1',
                customClass: {
                    popup: 'rounded-3xl shadow-2xl w-[480px]',
                    title: 'text-xl font-bold text-gray-800',
                    confirmButton: 'rounded-xl px-6 py-2.5 font-bold text-sm shadow-lg shadow-sky-200',
                    cancelButton: 'rounded-xl px-6 py-2.5 font-bold text-sm text-gray-600'
                },
                preConfirm: async () => {
                    const chatId = Swal.getPopup().querySelector('#tg_chat_id').value.trim();
                    try {
                        const formData = new FormData();
                        formData.append('telegram_chat_id', chatId);

                        const response = await fetch('api_telegram_settings.php', {
                            method: 'POST',
                            body: formData
                        });

                        if (!response.ok) {
                            throw new Error('เกิดข้อผิดพลาดจากเซิร์ฟเวอร์ (' + response.status + ')');
                        }

                        const res = await response.json();
                        if (res.status !== 'success') {
                            Swal.showValidationMessage(res.message || 'เกิดข้อผิดพลาด');
                            return false;
                        }
                        return res;
                    } catch (error) {
                        Swal.showValidationMessage(error.message || 'เกิดข้อผิดพลาดในการเชื่อมต่อ');
                        return false;
                    }
                }
            }).then((result) => {
                if (result.isConfirmed && result.value) {
                    Swal.fire({
                        icon: 'success',
                        title: 'สำเร็จ!',
                        text: result.value.message || 'ตั้งค่าการแจ้งเตือน Telegram ของท่านเรียบร้อยแล้ว',
                        timer: 1500,
                        showConfirmButton: false,
                        customClass: { popup: 'rounded-2xl' }
                    }).then(() => {
                        location.reload();
                    });
                }
            });
        }
    }
    function toggleReportSubmenu() {
        const submenu = document.getElementById('report-submenu');
        const chevron = document.getElementById('report-submenu-chevron');
        if (!submenu) return;
        const isHidden = submenu.classList.toggle('hidden');
        if (chevron) {
            if (isHidden) {
                chevron.classList.remove('rotate-180');
            } else {
                chevron.classList.add('rotate-180');
            }
        }
    }
</script>

<?php if (isset($_SESSION['force_password_change']) && $_SESSION['force_password_change']): 
    $forceReason = $_SESSION['force_password_reason'] ?? 'default';
    $bannerText = ($forceReason === 'reset')
        ? 'ระบบตรวจพบว่าคุณเข้าสู่ระบบด้วยรหัสผ่านจากการรีเซ็ต เพื่อความปลอดภัย กรุณาตั้งรหัสผ่านใหม่ของคุณทันทีก่อนเริ่มใช้งานระบบ'
        : 'ระบบตรวจพบว่าคุณใช้งานรหัสผ่านเริ่มต้น กรุณาตั้งรหัสผ่านใหม่ก่อนเข้าใช้งาน';
?>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        Swal.fire({
            title: 'ตั้งรหัสผ่านใหม่ (ต้องเปลี่ยนรหัสผ่าน)',
            html: `
                <div class="text-left font-[Sarabun] space-y-4 pt-2">
                    <div class="bg-amber-50 p-3.5 rounded-xl border border-amber-200 text-amber-900 text-xs leading-relaxed flex items-start gap-2.5 shadow-sm">
                        <i class="fas fa-shield-alt text-amber-500 text-lg mt-0.5 shrink-0"></i> 
                        <div>
                            <span class="font-bold block text-sm mb-0.5 text-amber-800">เพื่อความปลอดภัยของบัญชีผู้ใช้</span>
                            <?php echo htmlspecialchars($bannerText); ?>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-sky-700 mb-1 ml-1">รหัสผ่านใหม่ (New Password)</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-sky-400 pointer-events-none"><i class="fas fa-key"></i></span>
                            <input type="password" id="forced_new_pass" autocomplete="new-password" class="w-full pl-10 pr-10 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-sky-500 focus:border-sky-500 outline-none transition shadow-sm placeholder-gray-300" placeholder="ระบุอย่างน้อย 6 ตัวอักษร">
                            <button type="button" tabindex="-1" onclick="togglePasswordVisibility('forced_new_pass', this)" class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-sky-600 focus:outline-none transition-colors cursor-pointer" title="แสดง/ซ่อนรหัสผ่าน">
                                <i class="far fa-eye text-sm"></i>
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-sky-700 mb-1 ml-1">ยืนยันรหัสผ่านใหม่ (Confirm New Password)</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-sky-400 pointer-events-none"><i class="fas fa-check-double"></i></span>
                            <input type="password" id="forced_confirm_pass" autocomplete="new-password" class="w-full pl-10 pr-10 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-sky-500 focus:border-sky-500 outline-none transition shadow-sm placeholder-gray-300" placeholder="ระบุอีกครั้งให้ตรงกัน">
                            <button type="button" tabindex="-1" onclick="togglePasswordVisibility('forced_confirm_pass', this)" class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-sky-600 focus:outline-none transition-colors cursor-pointer" title="แสดง/ซ่อนรหัสผ่าน">
                                <i class="far fa-eye text-sm"></i>
                            </button>
                        </div>
                        <div id="forced_match_msg" class="h-5 mt-1 transition-all"></div>
                    </div>
                </div>
            `,
            allowOutsideClick: false,
            allowEscapeKey: false,
            showCancelButton: false,
            showCloseButton: false,
            confirmButtonText: 'บันทึกรหัสผ่านใหม่',
            confirmButtonColor: '#0ea5e9',
            customClass: {
                popup: 'rounded-3xl shadow-2xl w-[480px]',
                title: 'text-xl font-bold text-gray-800',
                confirmButton: 'rounded-xl px-8 py-3 font-bold text-sm shadow-lg shadow-sky-200 w-full mt-2'
            },
            didOpen: () => {
                const newP = Swal.getPopup().querySelector('#forced_new_pass');
                const confP = Swal.getPopup().querySelector('#forced_confirm_pass');
                const msg = Swal.getPopup().querySelector('#forced_match_msg');
                const btn = Swal.getConfirmButton();

                btn.disabled = true;
                btn.style.opacity = '0.5';
                btn.style.cursor = 'not-allowed';

                function validate() {
                    const v1 = newP.value;
                    const v2 = confP.value;

                    newP.classList.remove('border-red-500', 'border-green-500');
                    confP.classList.remove('border-red-500', 'border-green-500');

                    if (v1.length > 0 && v1.length < 6) {
                        msg.innerHTML = '<span class="text-xs text-red-500 font-bold flex items-center gap-1"><i class="fas fa-times-circle"></i> รหัสผ่านต้องยาวอย่างน้อย 6 ตัวอักษร</span>';
                        newP.classList.add('border-red-500');
                        btn.disabled = true; btn.style.opacity = '0.5'; btn.style.cursor = 'not-allowed';
                        return;
                    }

                    if (v2.length > 0) {
                        if (v1 === v2 && v1.length >= 6) {
                            msg.innerHTML = '<span class="text-xs text-green-500 font-bold flex items-center gap-1"><i class="fas fa-check-circle"></i> รหัสผ่านตรงกัน สามารถใช้งานได้</span>';
                            confP.classList.add('border-green-500');
                            newP.classList.add('border-green-500');
                            btn.disabled = false; btn.style.opacity = '1'; btn.style.cursor = 'pointer';
                        } else {
                            msg.innerHTML = '<span class="text-xs text-red-500 font-bold flex items-center gap-1"><i class="fas fa-times-circle"></i> รหัสผ่านยังไม่ตรงกัน</span>';
                            confP.classList.add('border-red-500');
                            btn.disabled = true; btn.style.opacity = '0.5'; btn.style.cursor = 'not-allowed';
                        }
                    } else {
                        msg.innerHTML = '';
                        btn.disabled = true; btn.style.opacity = '0.5'; btn.style.cursor = 'not-allowed';
                    }
                }

                newP.addEventListener('input', validate);
                confP.addEventListener('input', validate);
                newP.focus();
            },
            preConfirm: async () => {
                const newP = Swal.getPopup().querySelector('#forced_new_pass').value.trim();
                const confP = Swal.getPopup().querySelector('#forced_confirm_pass').value.trim();

                try {
                    const formData = new FormData();
                    formData.append('new_password', newP);
                    formData.append('confirm_password', confP);

                    const response = await fetch('api_change_password.php', {
                        method: 'POST',
                        body: formData
                    });

                    if (!response.ok) {
                        throw new Error('เกิดข้อผิดพลาดจากเซิร์ฟเวอร์ (' + response.status + ')');
                    }

                    const res = await response.json();
                    if (res.status !== 'success') {
                        Swal.showValidationMessage(res.message || 'เกิดข้อผิดพลาด');
                        return false;
                    }
                    return res;
                } catch (error) {
                    Swal.showValidationMessage(error.message || 'เกิดข้อผิดพลาดในการเชื่อมต่อ');
                    return false;
                }
            }
        }).then((result) => {
            if (result.isConfirmed && result.value) {
                Swal.fire({
                    icon: 'success',
                    title: 'เปลี่ยนรหัสผ่านสำเร็จ!',
                    text: 'คุณสามารถใช้งานระบบได้ตามปกติแล้ว',
                    timer: 2000,
                    showConfirmButton: false,
                    customClass: { popup: 'rounded-2xl' }
                }).then(() => {
                    location.reload();
                });
            }
        });
    });
</script>
<?php endif; ?>

<!-- Auto Logout when Inactive for 1 Hour with 5s countdown SweetAlert -->
<script src="js/idle_timeout.js"></script>

