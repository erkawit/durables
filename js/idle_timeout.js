/**
 * idle_timeout.js
 * จัดการตรวจจับ Inactivity / ไม่มีการใช้งานระบบเกิน 1 ชั่วโมง
 * - หากไม่ขยับ/ใช้งานเกิน 1 ชั่วโมง จะเด้ง SweetAlert ขึ้นมาถาม
 * - มีแถบ Progress bar นับถอยหลัง 5 วินาที
 * - หากไม่กดยืนยัน พอเข้าวินาทีที่ 6 จะทำการ Logout อัตโนมัติและกลับสู่หน้าล็อกอิน
 */

(function () {
    // 1 ชั่วโมง = 3600 วินาที = 3,600,000 มิลลิวินาที
    let INACTIVITY_LIMIT_MS = 60 * 60 * 1000;
    const COUNTDOWN_SECONDS = 5;
    const STORAGE_KEY = 'durables_last_activity';

    let isWarningShowing = false;
    let checkInterval = null;
    let throttleTimeout = null;

    // บันทึกกิจกรรมล่าสุด
    function updateActivity() {
        if (isWarningShowing) return;
        localStorage.setItem(STORAGE_KEY, Date.now().toString());
    }

    // หน่วงการบันทึกกิจกรรม (Throttle) ไม่ให้ยิงถี่เกินไป
    function onUserActivity() {
        if (isWarningShowing) return;
        if (!throttleTimeout) {
            updateActivity();
            throttleTimeout = setTimeout(function () {
                throttleTimeout = null;
            }, 5000); // อัปเดตไม่เกินทุกๆ 5 วินาที
        }
    }

    // ตรวจสอบและโหลด SweetAlert2 หากยังไม่มี
    function ensureSweetAlert(callback) {
        if (typeof Swal !== 'undefined') {
            callback();
            return;
        }
        var script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/sweetalert2@11';
        script.onload = callback;
        document.head.appendChild(script);
    }

    // ดำเนินการ Logout
    function performLogout(isTimeout) {
        localStorage.removeItem(STORAGE_KEY);
        var url = 'logout.php' + (isTimeout ? '?timeout=1' : '');
        window.location.href = url;
    }

    // แสดงหน้าต่าง SweetAlert แจ้งเตือน
    function showInactivityAlert() {
        if (isWarningShowing) return;
        isWarningShowing = true;

        ensureSweetAlert(function () {
            var timerInterval;
            Swal.fire({
                title: 'ท่านยังต้องการใช้งานระบบต่อไปหรือไม่?',
                html: '<div style="font-size:15px;color:#475569;margin-top:4px;">' +
                      'ไม่มีการใช้งานระบบเกิน 1 ชั่วโมง<br>' +
                      'ระบบจะทำการออกจากระบบโดยอัตโนมัติในอีก ' +
                      '<b id="idle-countdown-val" style="color:#ef4444;font-size:1.25em;">' + COUNTDOWN_SECONDS + '</b> วินาที' +
                      '</div>',
                icon: 'warning',
                timer: COUNTDOWN_SECONDS * 1000, // 5000 ms
                timerProgressBar: true,
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-check mr-1"></i> ต้องการใช้งานต่อ',
                cancelButtonText: '<i class="fas fa-sign-out-alt mr-1"></i> ออกจากระบบทันที',
                confirmButtonColor: '#0284c7',
                cancelButtonColor: '#ef4444',
                allowOutsideClick: false,
                allowEscapeKey: false,
                focusConfirm: true,
                customClass: {
                    popup: 'rounded-2xl font-[Sarabun] shadow-2xl',
                    confirmButton: 'rounded-xl px-5 py-2.5 font-medium shadow-sm',
                    cancelButton: 'rounded-xl px-5 py-2.5 font-medium shadow-sm'
                },
                didOpen: function () {
                    var el = document.getElementById('idle-countdown-val');
                    timerInterval = setInterval(function () {
                        if (el && typeof Swal !== 'undefined' && Swal.isVisible()) {
                            var timeLeft = Swal.getTimerLeft();
                            if (timeLeft !== null) {
                                var secondsRemaining = Math.max(1, Math.ceil(timeLeft / 1000));
                                el.textContent = secondsRemaining;
                            }
                        }
                    }, 100);
                },
                willClose: function () {
                    clearInterval(timerInterval);
                }
            }).then(function (result) {
                isWarningShowing = false;
                if (result.isConfirmed) {
                    // ผู้ใช้กดใช้งานต่อ -> รีเซ็ตเวลาและส่ง ping ให้เซิร์ฟเวอร์
                    updateActivity();
                    try {
                        fetch('api_notifications.php?action=ping').catch(function () {});
                    } catch (e) {}

                    Swal.fire({
                        icon: 'success',
                        title: 'ต่อเวลาการใช้งานเรียบร้อย',
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 2000,
                        customClass: {
                            popup: 'rounded-xl font-[Sarabun]'
                        }
                    });
                } else if (result.dismiss === Swal.DismissReason.timer) {
                    // ครบ 5 วินาทีเข้าสู่วินาทีที่ 6 -> Logout ทันที
                    performLogout(true);
                } else if (result.dismiss === Swal.DismissReason.cancel) {
                    // ผู้ใช้กดปุ่มออกจากระบบทันทีจากหน้าต่างแจ้งเตือน Inactivity
                    performLogout(true);
                } else {
                    performLogout(true);
                }
            });
        });
    }

    // เริ่มต้นตัวตรวจจับ
    function initIdleDetector() {
        // อัปเดตเวลาเริ่มต้นหากยังไม่มี
        if (!localStorage.getItem(STORAGE_KEY)) {
            updateActivity();
        }

        // ตรวจจับเหตุการณ์การใช้งานของผู้ใช้
        var events = ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'click'];
        events.forEach(function (eventName) {
            window.addEventListener(eventName, onUserActivity, { passive: true });
        });

        // Loop ตรวจสอบทุก 1 วินาที
        checkInterval = setInterval(function () {
            if (isWarningShowing) return;

            var lastActive = parseInt(localStorage.getItem(STORAGE_KEY) || Date.now(), 10);
            var now = Date.now();
            var idleTime = now - lastActive;

            if (idleTime >= INACTIVITY_LIMIT_MS) {
                showInactivityAlert();
            }
        }, 1000);
    }

    // ฟังก์ชันเสริมสำหรับทดสอบผ่านคอนโซลได้สะดวก
    window.triggerIdleModal = function () {
        showInactivityAlert();
    };

    window.setIdleTimeoutForTesting = function (seconds) {
        INACTIVITY_LIMIT_MS = (seconds || 5) * 1000;
        updateActivity();
        console.log('[IdleTimer] Inactivity limit set to ' + seconds + ' seconds for testing.');
    };

    // เริ่มทำงานเมื่อโหลดเอกสารเสร็จ
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initIdleDetector);
    } else {
        initIdleDetector();
    }
})();
