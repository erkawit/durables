<?php
session_start();
require_once 'config.php';

// Check Auth: Admin only
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

// Handle starting Node server
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'start_node') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $node_dir = realpath(__DIR__ . '/node_server');
        if (!$node_dir || !file_exists($node_dir . '/server.js')) {
            throw new Exception("ไม่พบโฟลเดอร์ node_server หรือไฟล์ server.js");
        }

        if (stristr(PHP_OS, 'WIN')) {
            // Windows background execution using popen / start
            // We use cmd /c start /B node server.js to run in background.
            $cmd = "cmd /c \"cd /d " . escapeshellarg($node_dir) . " && node server.js\"";
            $handle = popen("start /B " . $cmd, "r");
            if ($handle === false) {
                throw new Exception("ไม่สามารถเรียกทำงานคำสั่งบนระบบปฏิบัติการได้");
            }
            pclose($handle);
        } else {
            // Unix background execution
            $cmd = "cd " . escapeshellarg($node_dir) . " && node server.js > /dev/null 2>&1 &";
            exec($cmd);
        }

        // Log this action
        logAction($pdo, $_SESSION['user_id'], 'START_NODE', 'settings.php', 'สั่งรัน Node.js Service ในเบื้องหลัง');

        echo json_encode(['status' => 'success', 'message' => 'ส่งคำสั่งรัน Node.js Service เรียบร้อยแล้ว กรุณารอสักครู่ให้บริการเริ่มทำงาน']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// $sys_settings is loaded in config.php
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตั้งค่าระบบ</title>
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
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>

    <style>
        body {
            font-family: 'Sarabun', sans-serif;
        }
        
        .color-preset-btn {
            position: relative;
            transition: all 0.2s ease-in-out;
        }
        .color-preset-btn:hover {
            transform: scale(1.1);
        }
        .color-preset-btn.active::after {
            content: "\f00c";
            font-family: "Font Awesome 5 Free";
            font-weight: 900;
            color: white;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 1rem;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.5);
        }
    </style>
</head>
<body class="bg-gray-50 flex h-screen overflow-hidden">

    <?php $page = 'settings'; include 'sidebar.php'; ?>

    <main class="flex-1 flex flex-col overflow-hidden relative">
        <header class="bg-white shadow-sm px-6 py-4 flex items-center justify-between sticky top-0 z-20 flex-shrink-0 border-b border-gray-100">
            <div class="flex items-center gap-4">
                <a href="dashboard.php" class="text-gray-400 hover:text-sky-600 transition">
                    <i class="fas fa-arrow-left text-xl"></i>
                </a>
                <h1 class="text-xl font-bold text-gray-800">ตั้งค่าระบบ</h1>
            </div>
            <div>
                <button type="submit" form="settingsForm" class="bg-sky-600 hover:bg-sky-700 text-white px-5 py-2.5 rounded-xl shadow-md transition font-bold text-sm flex items-center gap-2">
                    <i class="fas fa-save"></i> บันทึกการตั้งค่า
                </button>
            </div>
        </header>

        <div class="flex-1 overflow-y-auto p-6 w-full">
            <form id="settingsForm" class="space-y-6 w-full max-w-full mx-auto" enctype="multipart/form-data">
                
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    
                    <!-- Column 1: Identity & Notifications -->
                    <div class="space-y-6">
                        <!-- Card 1: System Identity -->
                        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                            <div class="border-b border-gray-100 pb-4 mb-5">
                                <h2 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                                    <i class="fas fa-id-card text-sky-600"></i> ข้อมูลชื่อระบบและข้อมูลทั่วไป
                                </h2>
                                <p class="text-xs text-gray-500 mt-1">กำหนดชื่อและรายละเอียดเบื้องต้นของระบบที่แสดงบนหน้าจอ</p>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">ชื่อระบบ (ภาษาไทย) <span class="text-red-500">*</span></label>
                                    <input type="text" name="site_name_th" required
                                           value="<?php echo htmlspecialchars($sys_settings['site_name_th']); ?>" 
                                           class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-sky-500 focus:border-sky-500 outline-none transition font-medium text-gray-800">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">ชื่อระบบ (ภาษาอังกฤษ) <span class="text-red-500">*</span></label>
                                    <input type="text" name="site_name_en" required
                                           value="<?php echo htmlspecialchars($sys_settings['site_name_en']); ?>" 
                                           class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-sky-500 focus:border-sky-500 outline-none transition font-medium text-gray-800">
                                </div>
                            </div>
                        </div>

                        <!-- Card 4: Telegram Notification Settings -->
                        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                            <div class="border-b border-gray-100 pb-4 mb-5 flex items-center justify-between">
                                <div>
                                    <h2 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                                        <i class="fab fa-telegram text-sky-600"></i> การตั้งค่าการแจ้งเตือน Telegram (ส่วนกลาง)
                                    </h2>
                                    <p class="text-xs text-gray-500 mt-1">กำหนดค่าบอทและแชทกลุ่มสำหรับส่งการแจ้งเตือนความเคลื่อนไหวไปยังเจ้าหน้าที่พัสดุ</p>
                                </div>
                            </div>

                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">Telegram Bot Token</label>
                                    <input type="text" id="telegram_bot_token" name="telegram_bot_token" 
                                           value="<?php echo htmlspecialchars($sys_settings['telegram_bot_token'] ?? ''); ?>" 
                                           placeholder="เช่น 123456789:ABCdefGh..."
                                           class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-sky-500 focus:border-sky-500 outline-none transition font-medium text-gray-800">
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label class="block text-sm font-bold text-gray-700 mb-2">Telegram Bot Username (ชื่อบอทโดยไม่มี @)</label>
                                        <input type="text" id="telegram_bot_username" name="telegram_bot_username" 
                                               value="<?php echo htmlspecialchars($sys_settings['telegram_bot_username'] ?? ''); ?>" 
                                               placeholder="เช่น MyOfficeDurableBot"
                                               class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-sky-500 focus:border-sky-500 outline-none transition font-medium text-gray-800">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-bold text-gray-700 mb-2">Telegram Group/Channel Chat ID (พัสดุส่วนกลาง)</label>
                                        <input type="text" id="telegram_group_chat_id" name="telegram_group_chat_id" 
                                               value="<?php echo htmlspecialchars($sys_settings['telegram_group_chat_id'] ?? ''); ?>" 
                                               placeholder="เช่น -100123456789 หรือ 987654321"
                                               class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-sky-500 focus:border-sky-500 outline-none transition font-medium text-gray-800">
                                    </div>
                                </div>
                                <div class="pt-2 flex flex-wrap items-center justify-between gap-3 bg-sky-50/70 p-3.5 rounded-xl border border-sky-100">
                                    <div class="text-xs text-sky-800 flex items-center gap-2">
                                        <i class="fas fa-paper-plane text-sky-600"></i>
                                        <span>ทดสอบการส่งการแจ้งเตือนไปยังแชทกลุ่ม Telegram ส่วนกลาง</span>
                                    </div>
                                    <button type="button" id="btnTestTelegram"
                                        class="inline-flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-sky-500 to-blue-600 hover:from-sky-600 hover:to-blue-700 text-white rounded-xl text-xs font-bold shadow-md shadow-sky-100 hover:shadow-sky-200 transition active:scale-95">
                                        <i class="fab fa-telegram-plane"></i> ทดสอบส่งข้อความ Telegram
                                    </button>
                                </div>
                            </div>

                            <!-- Widescreen Collapsible System Telegram Admin Guide -->
                            <details class="group mt-5 bg-sky-50/50 border border-sky-100 rounded-2xl overflow-hidden transition-all duration-300">
                                <summary class="flex justify-between items-center p-4 cursor-pointer select-none font-bold text-xs text-sky-800 hover:bg-sky-50/80 transition">
                                    <span class="flex items-center gap-1.5"><i class="fas fa-book-open"></i> คู่มือและคำอธิบายขั้นตอนการตั้งค่าระบบแจ้งเตือน Telegram โดยละเอียด (สำหรับผู้ดูแลระบบ)</span>
                                    <span class="transition-transform duration-300 group-open:rotate-180"><i class="fas fa-chevron-down text-[10px]"></i></span>
                                </summary>
                                <div class="p-5 border-t border-sky-100/60 text-xs text-sky-900/90 space-y-4 leading-relaxed bg-white">
                                    <div class="bg-amber-50 border border-amber-100 rounded-xl p-3.5 text-amber-800 space-y-1">
                                        <h4 class="font-bold text-xs flex items-center gap-1.5"><i class="fas fa-exclamation-triangle"></i> ข้อมูลสำคัญก่อนเริ่มใช้งาน:</h4>
                                        <p class="text-[11px]">ระบบแจ้งเตือนนี้อาศัยบอทของ Telegram ในการส่งข้อความ ทั้งสำหรับการแจ้งเตือนส่วนกลาง (กลุ่มพัสดุ) และการแจ้งเตือนส่วนบุคคล (ผู้ขอเบิก/ผู้อนุมัติ) หากไม่เปิดใช้งาน Node.js Service การแจ้งเตือนอาจไม่ทำงานแบบเรียลไทม์</p>
                                    </div>
                                    
                                    <div>
                                        <strong class="text-sky-950 font-bold block mb-1">ขั้นตอนที่ 1: วิธีการสร้างบอทผ่าน @BotFather และรับ API Token</strong>
                                        <ol class="list-decimal pl-4 space-y-2">
                                            <li>เปิดโปรแกรม Telegram ค้นหาบอททางการในช่องค้นหาชื่อ <a href="https://t.me/BotFather" target="_blank" class="text-sky-600 font-bold underline">@BotFather</a> (สังเกตเครื่องหมายติ๊กถูกสีฟ้าข้างชื่อ)</li>
                                            <li>กดปุ่ม <strong>Start</strong> หรือพิมพ์ข้อความส่งไปว่า <code class="bg-gray-100 px-1 py-0.5 rounded border border-gray-200 font-mono text-[11px]">/newbot</code> เพื่อเริ่มต้นขั้นตอนการสร้างบอทใหม่</li>
                                            <li><strong>ตั้งชื่อแสดงผลของบอท (Display Name):</strong> พิมพ์ชื่อที่ท่านต้องการให้แสดงเมื่อบอททักข้อความไป (เช่น <code class="font-mono text-gray-700">ระบบเบิกพัสดุแจ้งเตือน</code>)</li>
                                            <li><strong>ตั้งชื่อผู้ใช้บอท (Bot Username):</strong> พิมพ์ชื่อผู้ใช้ภาษาอังกฤษเพื่อใช้ค้นหาบอท ชื่อนี้<strong>ต้องลงท้ายด้วยคำว่า _bot เสมอ</strong> (เช่น <code class="font-mono text-sky-700">durable_notify_bot</code> หรือ <code class="font-mono text-sky-700">office_warehouse_bot</code>) และต้องไม่ซ้ำกับใคร</li>
                                            <li><strong>คัดลอก HTTP API Token:</strong> เมื่อทำเสร็จสิ้น BotFather จะแสดงข้อความยินดีและให้รหัสผ่านยาว ๆ (เช่น <code class="font-mono text-xs text-sky-700">123456789:ABCdefGhIjklmNoPQRsTuvWxYz</code>) ให้นำค่านี้มากรอกลงในช่อง <strong>Telegram Bot Token</strong> ด้านบน</li>
                                            <li><strong>คัดลอกชื่อบอท:</strong> นำชื่อ Username ของบอทที่ท่านตั้งไว้ในข้อ 4 (เช่น <code class="font-mono text-sky-700">durable_notify_bot</code> <strong>โดยไม่ต้องใส่เครื่องหมาย @ นำหน้า</strong>) นำมากรอกลง in ช่อง <strong>Telegram Bot Username</strong> เพื่อให้ระบบสร้างลิงก์สำหรับผู้ใช้งานกดทักแชทได้ทันที</li>
                                        </ol>
                                    </div>
                                    
                                    <div class="border-t border-gray-100 pt-3">
                                        <strong class="text-sky-950 font-bold block mb-1">ขั้นตอนที่ 2: วิธีการหาไอดีห้องแชทกลุ่มพัสดุ (Telegram Group Chat ID) สำหรับแจ้งเตือนส่วนกลาง</strong>
                                        <ol class="list-decimal pl-4 space-y-2">
                                            <li>สร้างกลุ่มแชทใน Telegram (New Group) หรือเข้าไปยังกลุ่มงานพัสดุเดิมของหน่วยงานที่ต้องการให้แจ้งเตือน</li>
                                            <li>กดเพิ่มสมาชิกใหม่ (Add Members) ค้นหาชื่อ Username บอทของระบบที่ท่านเพิ่งสร้างในขั้นตอนที่ 1 แล้วกดเชิญเข้าร่วมกลุ่ม</li>
                                            <li>เชิญบอทสำหรับตรวจไอดีสาธารณะชื่อ <a href="https://t.me/raw_tracker_bot" target="_blank" class="text-sky-600 font-bold underline">@raw_tracker_bot</a> หรือ <a href="https://t.me/GetIdsBot" target="_blank" class="text-sky-600 font-bold underline">@GetIdsBot</a> เข้ามาในกลุ่มชั่วคราว</li>
                                            <li>เมื่อบอทภายนอกเข้ามาในกลุ่ม มันจะพิมพ์ข้อความตอบกลับแจ้งข้อมูลระบบของกลุ่มแชทนั้นลงในหน้าต่างแชททันที</li>
                                            <li>ให้สังเกตบรรทัดที่เขียนว่า <strong>Chat ID</strong> หรือ <strong>Group ID</strong> โดยทั่วไปจะเริ่มต้นด้วยเครื่องหมายลบและตัวเลข 9-13 หลัก (เช่น <code class="font-mono font-bold text-sky-700">-100987654321</code> หรือ <code class="font-mono font-bold text-sky-700">-123456789</code>) คัดลอกค่านี้รวมเครื่องหมายลบมากรอกลงในช่อง <strong>Telegram Group/Channel Chat ID (พัสดุส่วนกลาง)</strong> ด้านบน</li>
                                            <li>เมื่อกรอกและทดสอบบันทึกข้อมูลเรียบร้อยแล้ว ท่านสามารถกดลบ (Remove) บอทตรวจไอดีภายนอกออกจากกลุ่มได้เลยเพื่อความปลอดภัย</li>
                                        </ol>
                                    </div>

                                    <div class="border-t border-gray-100 pt-3">
                                        <strong class="text-sky-950 font-bold block mb-1">ขั้นตอนที่ 3: วิธีลงทะเบียนและเปิดใช้งานการแจ้งเตือนส่วนบุคคลของพนักงาน</strong>
                                        <ol class="list-decimal pl-4 space-y-2">
                                            <li>เพื่อให้หัวหน้าส่วนงาน ผู้อนุมัติ และพนักงานทั่วไปได้รับแจ้งเตือนสถานะใบเบิกของตนเอง ทุกคนจะต้องระบุ <strong>Telegram Chat ID</strong> ในหน้าตั้งค่าโปรไฟล์ของตนเอง</li>
                                            <li>พนักงานสามารถกดที่เมนู <strong>"ตั้งค่าแจ้งเตือน Telegram"</strong> ที่แถบสีน้ำเงินด้านซ้าย (Sidebar) เพื่อเปิดป๊อปอัปกรอกไอดี</li>
                                            <li>ในป๊อปอัปจะมีลิงก์ให้กดคุยกับบอทหาไอดี และ<strong>สำคัญมาก: พนักงานทุกคนต้องเข้าไปทักทายและกดปุ่ม Start คุยกับบอทของระบบ (@BotUsername) อย่างน้อย 1 ครั้งก่อนบันทึก</strong> เพื่อให้บอทได้รับสิทธิ์ส่งข้อความกลับหาพนักงาน หากไม่ได้เริ่มคุยก่อน Telegram จะปฏิเสธข้อความและส่งแจ้งเตือนไม่สำเร็จ</li>
                                        </ol>
                                    </div>
                                </div>
                            </details>
                        </div>
                    </div>

                    <!-- Column 2: Logo, Theme & Node Service -->
                    <div class="space-y-6">
                        <!-- Card 2: Logo and Favicon Uploader -->
                        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                            <div class="border-b border-gray-100 pb-4 mb-5">
                                <h2 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                                    <i class="fas fa-images text-sky-600"></i> โลโก้และไอคอนระบบ
                                </h2>
                                <p class="text-xs text-gray-500 mt-1">อัปโหลดภาพโลโก้และไอคอน Favicon ที่จะปรากฏบนเบราว์เซอร์และแถบเมนู</p>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- Logo Upload -->
                                <div class="space-y-4">
                                    <label class="block text-sm font-bold text-gray-700">รูปภาพโลโก้ระบบ (Logo)</label>
                                    <div class="flex items-center gap-4 p-4 bg-gray-50 border border-dashed border-gray-200 rounded-2xl">
                                        <div class="w-16 h-16 bg-white border border-gray-100 rounded-xl flex items-center justify-center overflow-hidden flex-shrink-0 shadow-sm">
                                            <img id="logoPreview" src="<?php echo htmlspecialchars($sys_settings['logo_path']); ?>" alt="Logo Preview" class="w-full h-full object-cover">
                                        </div>
                                        <div class="flex-1 space-y-1.5">
                                            <input type="file" id="logoInput" name="logo" accept="image/*" class="hidden">
                                            <button type="button" onclick="document.getElementById('logoInput').click()" 
                                                    class="bg-white border border-gray-200 text-gray-700 px-3 py-1.5 rounded-lg text-xs font-bold hover:bg-gray-100 transition shadow-sm flex items-center gap-1.5">
                                                <i class="fas fa-upload text-gray-500"></i> เลือกรูปภาพ
                                            </button>
                                            <p class="text-[10px] text-gray-400">ขนาดไฟล์ไม่เกิน 2MB</p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Favicon Upload -->
                                <div class="space-y-4">
                                    <label class="block text-sm font-bold text-gray-700">ไอคอนเบราว์เซอร์ (Favicon)</label>
                                    <div class="flex items-center gap-4 p-4 bg-gray-50 border border-dashed border-gray-200 rounded-2xl">
                                        <div class="w-16 h-16 bg-white border border-gray-100 rounded-xl flex items-center justify-center overflow-hidden flex-shrink-0 shadow-sm">
                                            <img id="faviconPreview" src="<?php echo htmlspecialchars($sys_settings['favicon_path']); ?>" alt="Favicon Preview" class="w-10 h-10 object-contain">
                                        </div>
                                        <div class="flex-1 space-y-1.5">
                                            <input type="file" id="faviconInput" name="favicon" accept="image/*" class="hidden">
                                            <button type="button" onclick="document.getElementById('faviconInput').click()" 
                                                    class="bg-white border border-gray-200 text-gray-700 px-3 py-1.5 rounded-lg text-xs font-bold hover:bg-gray-100 transition shadow-sm flex items-center gap-1.5">
                                                <i class="fas fa-upload text-gray-500"></i> เลือกรูปภาพ
                                            </button>
                                            <p class="text-[10px] text-gray-400">ไฟล์ ICO, PNG, JPG</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Card 3: Color Theme Selector -->
                        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                            <div class="border-b border-gray-100 pb-4 mb-5">
                                <h2 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                                    <i class="fas fa-palette text-sky-600"></i> ธีมสีระบบ (Theme Color)
                                </h2>
                                <p class="text-xs text-gray-500 mt-1">เลือกสีเมนู แถบด้านข้าง และปุ่มต่าง ๆ ในระบบ</p>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-center">
                                <div class="md:col-span-1 bg-gray-50 rounded-2xl p-4 border border-gray-100 text-center flex flex-col items-center justify-center gap-3">
                                    <span class="text-[10px] font-bold text-gray-400 tracking-wider uppercase">สีธีมปัจจุบัน</span>
                                    <div class="w-12 h-12 rounded-xl shadow-inner border border-white relative overflow-hidden transition-all duration-300" id="themePreviewBox">
                                        <input type="color" id="themeColorInput" name="theme_color" 
                                               value="<?php echo htmlspecialchars($sys_settings['theme_color']); ?>" 
                                               class="absolute inset-0 w-full h-full opacity-0 cursor-pointer">
                                    </div>
                                    <span class="font-mono font-bold text-gray-700 text-sm" id="themeHexText"><?php echo htmlspecialchars($sys_settings['theme_color']); ?></span>
                                </div>

                                <div class="md:col-span-2 space-y-3">
                                    <label class="block text-xs font-bold text-gray-600">เลือกจากชุดสีแนะนำ</label>
                                    <div class="flex flex-wrap gap-2.5">
                                        <button type="button" class="color-preset-btn w-9 h-9 rounded-full shadow-md cursor-pointer focus:outline-none" style="background-color: #17365a;" data-color="#17365a" title="Navy"></button>
                                        <button type="button" class="color-preset-btn w-9 h-9 rounded-full shadow-md cursor-pointer focus:outline-none" style="background-color: #065f46;" data-color="#065f46" title="Green"></button>
                                        <button type="button" class="color-preset-btn w-9 h-9 rounded-full shadow-md cursor-pointer focus:outline-none" style="background-color: #4c1d95;" data-color="#4c1d95" title="Purple"></button>
                                        <button type="button" class="color-preset-btn w-9 h-9 rounded-full shadow-md cursor-pointer focus:outline-none" style="background-color: #7f1d1d;" data-color="#7f1d1d" title="Red"></button>
                                        <button type="button" class="color-preset-btn w-9 h-9 rounded-full shadow-md cursor-pointer focus:outline-none" style="background-color: #1f2937;" data-color="#1f2937" title="Slate"></button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Card 5: Node.js Connection Status & Control -->
                        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                            <div class="border-b border-gray-100 pb-4 mb-5">
                                <h2 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                                    <i class="fas fa-server text-sky-600"></i> การเปิดใช้งาน Node.js Service (Real-time Engine)
                                </h2>
                                <p class="text-xs text-gray-500 mt-1">ควบคุมและตรวจสอบสถานะการทำงานของบริการจัดการสต็อกและแจ้งเตือน Real-time</p>
                            </div>

                            <div class="space-y-4">
                                <!-- Toggle Switch -->
                                <div class="flex items-center justify-between p-4 bg-gray-50 rounded-2xl border border-gray-100 shadow-sm">
                                    <div class="pr-4">
                                        <span class="text-sm font-bold text-gray-800 block">เปิดใช้งาน Real-time Stock Check & Telegram</span>
                                        <span class="text-[10px] text-gray-400 block mt-0.5">เปิดระบบล็อกจำนวนพัสดุและส่งแจ้งเตือนผ่าน Node.js (แนะนำให้เปิด)</span>
                                    </div>
                                    <label class="relative inline-flex items-center cursor-pointer flex-shrink-0">
                                        <input type="checkbox" name="node_service_enabled" value="1" class="sr-only peer" <?php echo ($sys_settings['node_service_enabled'] ?? '1') === '1' ? 'checked' : ''; ?>>
                                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-sky-600"></div>
                                    </label>
                                </div>

                                <!-- Status Panel -->
                                <div class="p-4 bg-gray-50 rounded-2xl border border-gray-100 shadow-sm space-y-3">
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm font-bold text-gray-700">สถานะการเชื่อมต่อบริการ:</span>
                                        <span id="nodeStatusBadge" class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold border shadow-sm bg-gray-100 text-gray-700 border-gray-200">
                                            <i class="fas fa-spinner fa-spin mr-1"></i>กำลังตรวจสอบ...
                                        </span>
                                    </div>
                                    
                                    <div class="flex justify-between items-center gap-2 pt-2 border-t border-gray-200/60 flex-wrap">
                                        <div class="flex gap-2">
                                            <button type="button" onclick="checkNodeStatus()" class="bg-sky-600 hover:bg-sky-700 text-white px-3 py-1.5 rounded-xl text-xs font-bold shadow-md transition flex items-center gap-1.5 hover:scale-105">
                                                <i class="fas fa-sync-alt"></i> ตรวจสอบการเชื่อมต่อใหม่
                                            </button>
                                            <button type="button" id="startNodeBtn" onclick="startNodeService()" class="hidden bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 rounded-xl text-xs font-bold shadow-md transition flex items-center gap-1.5 hover:scale-105">
                                                <i class="fas fa-play"></i> สั่งรัน Node Service
                                            </button>
                                        </div>
                                        <span class="text-[10px] text-gray-400 font-medium">Node Port: 3000</span>
                                    </div>
                                </div>

                                <!-- Instructions -->
                                <div class="text-[10px] text-gray-500 bg-amber-50/50 border border-amber-100 rounded-xl p-3 space-y-1">
                                    <strong class="text-amber-800"><i class="fas fa-info-circle mr-1"></i>วิธีการเปิดใช้งาน Node Service:</strong>
                                    <p class="leading-relaxed">หากสถานะเป็น ออฟไลน์ (Offline) ให้เปิด Command Prompt/Terminal แล้วพิมพ์คำสั่งดังนี้เพื่อเริ่มการทำงาน:</p>
                                    <pre class="bg-white border border-gray-200/80 rounded p-1.5 text-[9px] font-mono text-sky-700 overflow-x-auto shadow-inner">cd c:/xampp/htdocs/durables/node_server
npm start</pre>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </form>
        </div>
    </main>

    <script>
        // Global checkNodeStatus function
        function checkNodeStatus() {
            const badge = $('#nodeStatusBadge');
            const startBtn = $('#startNodeBtn');
            badge.html('<i class="fas fa-spinner fa-spin mr-1"></i>กำลังตรวจสอบ...');
            badge.removeClass('bg-green-100 text-green-700 border-green-200 bg-red-100 text-red-700 border-red-200');
            badge.addClass('bg-gray-100 text-gray-700 border-gray-200');
            
            $.ajax({
                url: 'http://' + window.location.hostname + ':3000/api/health',
                type: 'GET',
                timeout: 3000,
                success: function(response) {
                    badge.html('<span class="flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse"></span> ออนไลน์ (Online)</span>');
                    badge.removeClass('bg-gray-100 text-gray-700 border-gray-200');
                    badge.addClass('bg-green-100 text-green-700 border-green-200');
                    startBtn.addClass('hidden');
                },
                error: function() {
                    badge.html('<span class="flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-red-500"></span> ออฟไลน์ (Offline)</span>');
                    badge.removeClass('bg-gray-100 text-gray-700 border-gray-200');
                    badge.addClass('bg-red-100 text-red-700 border-red-200');
                    startBtn.removeClass('hidden');
                }
            });
        }

        function startNodeService() {
            Swal.fire({
                title: 'ต้องการเปิดรัน Node Service?',
                text: 'ระบบจะส่งคำสั่งเปิดใช้งาน Node.js Service ในเบื้องหลังเพื่อรองรับ Real-time Stock และ Telegram',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'รันบริการ',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#059669',
                cancelButtonColor: '#6b7280',
                showLoaderOnConfirm: true,
                preConfirm: () => {
                    return $.ajax({
                        url: 'settings.php',
                        type: 'POST',
                        data: { action: 'start_node' },
                        dataType: 'json'
                    }).then(response => {
                        if (response.status !== 'success') {
                            throw new Error(response.message || 'เกิดข้อผิดพลาดในการรัน Node Service');
                        }
                        return response;
                    }).catch(error => {
                        Swal.showValidationMessage(`ผิดพลาด: ${error.message || error.statusText || 'ไม่สามารถติดต่อเซิร์ฟเวอร์ได้'}`);
                    });
                },
                allowOutsideClick: () => !Swal.isLoading()
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        icon: 'info',
                        title: 'กำลังเริ่มต้น Node.js Service',
                        text: 'กรุณารอสักครู่ (ประมาณ 3 วินาที) จากนั้นระบบจะสแกนหาการเชื่อมต่อใหม่...',
                        timer: 3000,
                        showConfirmButton: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    }).then(() => {
                        checkNodeStatus();
                    });
                }
            });
        }

        // Set Preview Logic for inputs
        $(document).ready(function() {
            // Initial Node.js check on page load
            checkNodeStatus();

            // Theme colors logic
            const colorInput = $('#themeColorInput');
            const previewBox = $('#themePreviewBox');
            const hexText = $('#themeHexText');

            function updateThemePreview(color) {
                previewBox.css('background-color', color);
                hexText.text(color.toUpperCase());
                colorInput.val(color);

                // Update active preset button highlight
                $('.color-preset-btn').removeClass('active');
                $(`.color-preset-btn[data-color="${color.toLowerCase()}"]`).addClass('active');
            }

            // Init state
            updateThemePreview(colorInput.val());

            // Color picker input listener
            colorInput.on('input change', function() {
                updateThemePreview($(this).val());
            });

            // Preset click listeners
            $('.color-preset-btn').on('click', function() {
                const selectedColor = $(this).data('color');
                updateThemePreview(selectedColor);
            });

            // Image Preview listeners
            $('#logoInput').on('change', function() {
                const file = this.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        $('#logoPreview').attr('src', e.target.result);
                    }
                    reader.readAsDataURL(file);
                }
            });

            $('#faviconInput').on('change', function() {
                const file = this.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        $('#faviconPreview').attr('src', e.target.result);
                    }
                    reader.readAsDataURL(file);
                }
            });

            // Test Telegram Button Listener
            $('#btnTestTelegram').on('click', function() {
                const token = $('#telegram_bot_token').val().trim();
                const chatId = $('#telegram_group_chat_id').val().trim();

                if (!token || !chatId) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'ข้อมูลไม่ครบถ้วน',
                        text: 'กรุณากรอกทั้ง Telegram Bot Token และ Telegram Group Chat ID ก่อนทำการทดสอบ',
                        confirmButtonText: 'ตกลง',
                        customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-xl px-5' }
                    });
                    return;
                }

                Swal.fire({
                    title: 'กำลังส่งแจ้งเตือนไปยัง Telegram...',
                    html: '<div class="text-sm text-gray-500 mt-2">กำลังเชื่อมต่อกับ Telegram Bot API และส่งข้อความทดสอบ</div>',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                $.ajax({
                    url: 'api_settings.php',
                    type: 'POST',
                    data: {
                        action: 'test_telegram',
                        telegram_bot_token: token,
                        telegram_group_chat_id: chatId
                    },
                    dataType: 'json',
                    success: function(res) {
                        if (res.status === 'success') {
                            Swal.fire({
                                icon: 'success',
                                title: 'ส่งข้อความทดสอบสำเร็จ!',
                                text: res.message,
                                confirmButtonText: 'ยอดเยี่ยม',
                                confirmButtonColor: '#0ea5e9',
                                customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-xl px-6' }
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'ส่งข้อความไม่สำเร็จ',
                                text: res.message,
                                confirmButtonText: 'ปิด',
                                confirmButtonColor: '#ef4444',
                                customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-xl px-6' }
                            });
                        }
                    },
                    error: function() {
                        Swal.fire({
                            icon: 'error',
                            title: 'เกิดข้อผิดพลาด',
                            text: 'ไม่สามารถติดต่อเซิร์ฟเวอร์เพื่อทดสอบ Telegram ได้',
                            confirmButtonText: 'ปิด',
                            confirmButtonColor: '#ef4444',
                            customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-xl px-6' }
                        });
                    }
                });
            });

            // Form Submit logic
            $('#settingsForm').on('submit', function(e) {
                e.preventDefault();
                
                Swal.fire({
                    title: 'กำลังบันทึกการตั้งค่า...',
                    text: 'กรุณารอสักครู่ระบบกำลังบันทึกข้อมูลและส่งแจ้งเตือนไปยัง Telegram',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                const formData = new FormData(this);

                $.ajax({
                    url: 'api_settings.php',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(response) {
                        if (response.status === 'success') {
                            let alertHtml = `<div class="text-gray-700">${response.message}</div>`;
                            if (response.telegram) {
                                if (response.telegram.sent) {
                                    alertHtml += `<div class="mt-3 p-3 bg-green-50 border border-green-200 rounded-xl text-green-700 text-xs font-bold flex items-center justify-center gap-2"><i class="fab fa-telegram-plane text-base"></i> ส่งข้อความทดสอบไปยัง Telegram สำเร็จแล้ว</div>`;
                                } else {
                                    alertHtml += `<div class="mt-3 p-3 bg-amber-50 border border-amber-200 rounded-xl text-amber-700 text-xs text-left"><div class="font-bold mb-1"><i class="fas fa-exclamation-triangle mr-1"></i> แจ้งเตือน Telegram ไม่สำเร็จ:</div>${response.telegram.message}</div>`;
                                }
                            }

                            Swal.fire({
                                icon: (response.telegram && !response.telegram.sent) ? 'warning' : 'success',
                                title: 'บันทึกเรียบร้อย!',
                                html: alertHtml,
                                confirmButtonText: 'ตกลง',
                                confirmButtonColor: colorInput.val(),
                                customClass: {
                                    popup: 'rounded-2xl',
                                    confirmButton: 'rounded-xl px-6'
                                }
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'ไม่สำเร็จ',
                                text: response.message,
                                confirmButtonText: 'ตกลง',
                                confirmButtonColor: '#ef4444',
                                customClass: {
                                    popup: 'rounded-2xl',
                                    confirmButton: 'rounded-xl px-6'
                                }
                            });
                        }
                    },
                    error: function() {
                        Swal.fire({
                            icon: 'error',
                            title: 'ผิดพลาด',
                            text: 'ไม่สามารถติดต่อเซิร์ฟเวอร์ได้ กรุณาลองใหม่อีกครั้ง',
                            confirmButtonText: 'ตกลง',
                            confirmButtonColor: '#ef4444',
                            customClass: {
                                popup: 'rounded-2xl',
                                confirmButton: 'rounded-xl px-6'
                            }
                        });
                    }
                });
            });
        });
    </script>
</body>
</html>
