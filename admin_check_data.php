<?php
session_start();
require_once 'config.php';

// Only Admin can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

$page = 'admin_check_data';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตรวจสอบข้อมูลระบบ (Check Existing System Data) - DURABLES</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { font-family: 'Sarabun', sans-serif; }
        .sidebar-link { transition: all 0.3s; }
        .sidebar-link:hover, .sidebar-link.active { background-color: #f4f6f8; color: #112A46; border-right: 4px solid #112A46; }
        
        .dos-console {
            background-color: #0c0c0c;
            color: #0f0;
            font-family: 'Courier New', Courier, monospace;
            padding: 1rem;
            border-radius: 0.5rem;
            height: 400px;
            overflow-y: auto;
            box-shadow: inset 0 0 10px rgba(0,0,0,0.8);
            border: 2px solid #333;
        }
        .dos-console p { margin: 0; padding: 2px 0; font-size: 14px; }
        .dos-success { color: #0f0; }
        .dos-error { color: #f00; font-weight: bold; }
        .dos-warning { color: #ff0; }
        .dos-info { color: #0ff; }
        
        /* Blinking cursor */
        .cursor { display: inline-block; width: 8px; height: 15px; background-color: #0f0; animation: blink 1s step-end infinite; }
        @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0; } }

        /* SweetAlert height auto fix */
        html.swal2-height-auto, body.swal2-height-auto { height: 100vh !important; }
    </style>
</head>
<body class="bg-gray-50 flex h-screen overflow-hidden">

    <?php $page = 'admin_check_data'; include 'sidebar.php'; ?>

    <main class="flex-1 flex flex-col h-screen overflow-hidden bg-gray-50">
        <!-- Header -->
        <header class="bg-white border-b border-gray-100 px-8 py-5 flex items-center justify-between sticky top-0 z-20 shadow-sm">
            <h2 class="text-2xl font-bold text-gray-800 flex items-center gap-3">
                <i class="fas fa-database text-sky-500"></i>
                ตรวจสอบข้อมูลระบบ (Check Existing System Data)
            </h2>
            <div class="flex items-center gap-4">
                <div class="text-right">
                    <p class="text-sm font-bold text-gray-800"><?php echo htmlspecialchars($_SESSION['firstname'] . ' ' . $_SESSION['lastname']); ?></p>
                    <p class="text-xs text-sky-500 font-medium">Administrator</p>
                </div>
            </div>
        </header>

        <div class="flex-1 overflow-y-auto p-8 relative">
            <div class="max-w-6xl mx-auto space-y-6">
                
                <!-- Description & Instructions -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 bg-sky-50">
                        <h3 class="font-bold text-sky-800 text-lg flex items-center gap-2">
                            <i class="fas fa-info-circle"></i> คำอธิบายและวิธีการอัปเดตโครงสร้างฐานข้อมูล
                        </h3>
                    </div>
                    <div class="p-6 text-gray-700 leading-relaxed text-sm space-y-4 font-medium">
                        <p>ระบบนี้ทำการตรวจสอบความสมบูรณ์และเปรียบเทียบข้อมูลระหว่างฐานข้อมูลต้นทาง <strong>(udtc_all_app)</strong> และฐานข้อมูลปัจจุบัน <strong>(durables_db)</strong> ก่อนที่คุณจะทำการอัปเดตโครงสร้าง</p>
                        <ul class="list-disc pl-5 space-y-2 text-gray-600">
                            <li><strong>udtc_all_app:</strong> ฐานข้อมูลหลักที่เก็บข้อมูลวัสดุ ยอดคงเหลือ และข้อมูลพนักงาน</li>
                            <li><strong>durables_db:</strong> ฐานข้อมูลระบบเบิกวัสดุ (ปัจจุบัน) ที่ต้องการซิงค์ข้อมูล</li>
                        </ul>
                        <div class="bg-amber-50 border border-amber-200 text-amber-800 p-4 rounded-xl mt-4">
                            <h4 class="font-bold mb-2"><i class="fas fa-exclamation-triangle"></i> ข้อควรระวังและการเตรียมตัวก่อนอัปเดต:</h4>
                            <ol class="list-decimal pl-5 space-y-1">
                                <li>กรุณาสำรองข้อมูล (Backup) ฐานข้อมูล durables_db เสมอก่อนดำเนินการใดๆ</li>
                                <li>ให้คลิกปุ่ม <strong>"เริ่มตรวจสอบข้อมูลระบบ"</strong> ด้านล่าง และรอจนกว่ากระบวนการจะเสร็จสิ้น</li>
                                <li>ระบบจะแสดงขั้นตอนต่างๆ ในหน้าจอ <strong>DOS-like mode</strong> เพื่อให้เห็นว่าขั้นตอนใดสำเร็จหรือไม่สำเร็จ พร้อมเหตุผล</li>
                                <li>หากพบข้อผิดพลาด ให้ตรวจสอบการตั้งค่า Connection และสิทธิ์การเข้าถึงฐานข้อมูล udtc_all_app</li>
                            </ol>
                        </div>
                    </div>
                </div>

                <!-- Control Button -->
                <div class="flex justify-end gap-3">
                    <button id="btnBackup" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-xl font-bold shadow-lg transition-transform hover:scale-105 flex items-center gap-2" onclick="backupDatabase()">
                        <i class="fas fa-save"></i> สำรองข้อมูล (Backup Database)
                    </button>
                    <button id="btnCheckData" class="bg-slate-800 hover:bg-slate-900 text-green-400 px-6 py-3 rounded-xl font-bold shadow-lg transition-transform hover:scale-105 flex items-center gap-2" onclick="startDataCheck()">
                        <i class="fas fa-terminal"></i> เริ่มตรวจสอบข้อมูลระบบ (Start Check)
                    </button>
                </div>

                <!-- DOS Console -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="px-4 py-2 bg-gray-800 text-gray-300 text-xs flex justify-between items-center">
                        <span class="font-mono">SYSTEM DATA VERIFICATION TERMINAL</span>
                        <div class="flex gap-1">
                            <div class="w-3 h-3 rounded-full bg-red-500"></div>
                            <div class="w-3 h-3 rounded-full bg-yellow-500"></div>
                            <div class="w-3 h-3 rounded-full bg-green-500"></div>
                        </div>
                    </div>
                    <div class="dos-console" id="dosConsole">
                        <p>Welcome to Durables System Maintenance Terminal.</p>
                        <p>Type 'help' for commands or click "Start Check" to begin diagnostics.</p>
                        <p>Awaiting command... <span class="cursor" id="cursor"></span></p>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <script>
        const consoleEl = document.getElementById('dosConsole');
        const cursor = document.getElementById('cursor');
        let checkResults = [];

        function printLog(text, type = 'dos-info', delay = 0) {
            return new Promise(resolve => {
                setTimeout(() => {
                    // Remove cursor
                    if(cursor.parentNode) cursor.parentNode.removeChild(cursor);
                    
                    const p = document.createElement('p');
                    p.className = type;
                    p.innerHTML = text;
                    consoleEl.appendChild(p);
                    
                    // Add cursor back
                    const cursorP = document.createElement('p');
                    cursorP.appendChild(cursor);
                    consoleEl.appendChild(cursorP);
                    
                    consoleEl.scrollTop = consoleEl.scrollHeight;
                    resolve();
                }, delay);
            });
        }

        function backupDatabase() {
            const btn = document.getElementById('btnBackup');
            btn.disabled = true;
            btn.classList.add('opacity-50', 'cursor-not-allowed');
            
            Swal.fire({
                title: 'กำลังสำรองข้อมูล...',
                text: 'กรุณารอสักครู่ ระบบกำลังสร้างไฟล์ Backup...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            fetch('api_backup_db.php')
                .then(r => r.json())
                .then(data => {
                    btn.disabled = false;
                    btn.classList.remove('opacity-50', 'cursor-not-allowed');
                    
                    if (data.status === 'success') {
                        Swal.fire({
                            icon: 'success',
                            title: 'สำรองข้อมูลสำเร็จ!',
                            html: `บันทึกไฟล์เป็น: <strong>${data.file}</strong><br>โฟลเดอร์: /backups/`,
                            confirmButtonColor: '#10b981',
                            confirmButtonText: 'ตกลง'
                        });
                    } else {
                        Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                    }
                })
                .catch(err => {
                    btn.disabled = false;
                    btn.classList.remove('opacity-50', 'cursor-not-allowed');
                    Swal.fire('ข้อผิดพลาดเครือข่าย', 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'error');
                });
        }

        async function startDataCheck() {
            document.getElementById('btnCheckData').disabled = true;
            document.getElementById('btnCheckData').classList.add('opacity-50', 'cursor-not-allowed');
            
            consoleEl.innerHTML = '';
            consoleEl.appendChild(cursor);
            checkResults = [];
            
            await printLog('INITIALIZING DIAGNOSTICS...', 'dos-info', 100);
            await printLog('==============================================', 'dos-info', 200);
            
            const steps = [
                { id: 'db_conn', name: 'Database Connection (udtc_all_app)' },
                { id: 'tbl_product', name: 'Table Structure: product' },
                { id: 'tbl_type_volume', name: 'Table Structure: type_volume' },
                { id: 'data_product', name: 'Balance Data Verification' },
                { id: 'auth_users', name: 'User Authentication Match' }
            ];

            let allSuccess = true;

            for (let i = 0; i < steps.length; i++) {
                let step = steps[i];
                await printLog(`[STEP ${i+1}/${steps.length}] Checking ${step.name}...`, 'dos-warning', 800);
                
                try {
                    const response = await fetch('api_check_data.php?step=' + step.id);
                    const result = await response.json();
                    
                    if (result.status === 'success') {
                        await printLog(`[OK] ${result.message}`, 'dos-success', 400);
                        if(result.details) await printLog(`     Details: ${result.details}`, 'dos-success', 200);
                        checkResults.push({ step: step.name, status: 'Success', details: result.message, why: 'Condition met successfully.' });
                    } else {
                        await printLog(`[FAILED] ${result.message}`, 'dos-error', 400);
                        if(result.details) await printLog(`     Why: ${result.details}`, 'dos-error', 200);
                        checkResults.push({ step: step.name, status: 'Failed', details: result.message, why: result.details });
                        allSuccess = false;
                    }
                } catch (e) {
                    await printLog(`[ERROR] Network or server error while executing check.`, 'dos-error', 400);
                    checkResults.push({ step: step.name, status: 'Error', details: 'Execution halted', why: e.message });
                    allSuccess = false;
                    break;
                }
            }

            await printLog('==============================================', 'dos-info', 500);
            if (allSuccess) {
                await printLog('DIAGNOSTICS COMPLETED SUCCESSFULLY.', 'dos-success', 200);
            } else {
                await printLog('DIAGNOSTICS COMPLETED WITH ERRORS.', 'dos-error', 200);
            }

            document.getElementById('btnCheckData').disabled = false;
            document.getElementById('btnCheckData').classList.remove('opacity-50', 'cursor-not-allowed');

            showSummaryModal(allSuccess);
        }

        function showSummaryModal(allSuccess) {
            let trs = checkResults.map((r, idx) => {
                let badge = r.status === 'Success' 
                    ? '<span class="bg-green-100 text-green-700 px-2 py-1 rounded-md text-xs font-bold">SUCCESS</span>' 
                    : '<span class="bg-red-100 text-red-700 px-2 py-1 rounded-md text-xs font-bold">FAILED</span>';
                return `
                    <tr class="border-b">
                        <td class="py-3 px-2 text-center">${idx + 1}</td>
                        <td class="py-3 px-2 font-bold">${r.step}</td>
                        <td class="py-3 px-2 text-center">${badge}</td>
                        <td class="py-3 px-2 text-sm">${r.details}<br><span class="text-xs text-gray-500">Why: ${r.why}</span></td>
                    </tr>
                `;
            }).join('');

            let headerIcon = allSuccess ? '<i class="fas fa-check-circle text-green-500 mr-2"></i>' : '<i class="fas fa-exclamation-triangle text-red-500 mr-2"></i>';
            let headerText = allSuccess ? 'การตรวจสอบสำเร็จ (All Checks Passed)' : 'พบข้อผิดพลาดบางรายการ (Checks Failed)';

            Swal.fire({
                title: 'สรุปผลการตรวจสอบข้อมูล (Diagnostics Summary)',
                html: `
                    <div class="text-left font-[Sarabun]">
                        <h4 class="text-lg font-bold mb-4 flex items-center">${headerIcon} ${headerText}</h4>
                        <p class="text-sm text-gray-600 mb-4">ตารางด้านล่างแสดงผลการตรวจสอบแต่ละขั้นตอน ว่ามีการทำงานอย่างไร สำเร็จหรือไม่ และเหตุผล</p>
                        <div class="max-h-[50vh] overflow-y-auto">
                            <table class="w-full text-sm text-left border">
                                <thead class="bg-gray-100 sticky top-0">
                                    <tr>
                                        <th class="py-2 px-2 text-center w-12">ลำดับ</th>
                                        <th class="py-2 px-2">ขั้นตอน (Step)</th>
                                        <th class="py-2 px-2 text-center w-24">สถานะ</th>
                                        <th class="py-2 px-2">รายละเอียด (Details & Why)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${trs}
                                </tbody>
                            </table>
                        </div>
                    </div>
                `,
                width: '80%',
                showCloseButton: true,
                allowOutsideClick: false,
                confirmButtonText: 'ปิด (Close)',
                confirmButtonColor: '#64748b'
            });
        }
    </script>
</body>
</html>
