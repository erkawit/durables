<?php
// telegram_helper.php - Direct Telegram Notification Helper for PHP

if (!function_exists('sendTelegramDirect')) {
    /**
     * Send message directly to Telegram Bot API
     * @param string $botToken
     * @param string $chatId
     * @param string $message
     * @param string $parseMode
     * @return array ['success' => bool, 'message' => string, 'raw' => mixed]
     */
    function sendTelegramDirect($botToken, $chatId, $message, $parseMode = 'HTML') {
        $botToken = trim($botToken);
        $chatId = trim($chatId);
        if (empty($botToken)) {
            return ['success' => false, 'message' => 'Telegram Bot Token ไม่ถูกต้องหรือไม่ได้ระบุ'];
        }
        if (empty($chatId)) {
            return ['success' => false, 'message' => 'Telegram Chat ID ไม่ถูกต้องหรือไม่ได้ระบุ'];
        }

        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
        $postData = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => $parseMode,
            'disable_web_page_preview' => true
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'message' => 'cURL Error: ' . $curlError];
        }

        $data = json_decode($response, true);
        if ($httpCode === 200 && !empty($data['ok'])) {
            return ['success' => true, 'message' => 'ส่งข้อความสำเร็จ', 'raw' => $data];
        }

        $errorDesc = $data['description'] ?? "HTTP Code {$httpCode}: {$response}";
        return ['success' => false, 'message' => 'Telegram API Error: ' . $errorDesc, 'raw' => $data];
    }
}

if (!function_exists('sendTelegramDocument')) {
    /**
     * Send document (e.g. PDF file) directly to Telegram Bot API
     * @param string $botToken
     * @param string $chatId
     * @param string $filePath Absolute or relative path to PDF file
     * @param string $caption Optional caption text (HTML formatted)
     * @param string|null $displayFileName Optional display name of document in Telegram
     * @return array ['success' => bool, 'message' => string, 'raw' => mixed]
     */
    function sendTelegramDocument($botToken, $chatId, $filePath, $caption = '', $displayFileName = null) {
        $botToken = trim($botToken);
        $chatId = trim($chatId);
        if (empty($botToken)) {
            return ['success' => false, 'message' => 'Telegram Bot Token ไม่ถูกต้องหรือไม่ได้ระบุ'];
        }
        if (empty($chatId)) {
            return ['success' => false, 'message' => 'Telegram Chat ID ไม่ถูกต้องหรือไม่ได้ระบุ'];
        }
        $targetFile = realpath($filePath);
        if (!$targetFile || !file_exists($targetFile)) {
            $targetFile = $filePath;
        }
        if (!file_exists($targetFile)) {
            return ['success' => false, 'message' => 'ไม่พบไฟล์เอกสาร: ' . $filePath];
        }

        $url = "https://api.telegram.org/bot{$botToken}/sendDocument";
        $fileName = $displayFileName ?: basename($filePath);
        if (!preg_match('/\.pdf$/i', $fileName)) {
            $fileName .= '.pdf';
        }
        $cFile = new CURLFile($targetFile, 'application/pdf', $fileName);

        // Telegram limit for caption is 1024 characters
        $safeCaption = mb_substr($caption, 0, 1024, 'UTF-8');

        $postData = [
            'chat_id' => $chatId,
            'document' => $cFile,
            'caption' => $safeCaption,
            'parse_mode' => 'HTML'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 45);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'message' => 'cURL Error: ' . $curlError];
        }

        $data = json_decode($response, true);
        if ($httpCode === 200 && !empty($data['ok'])) {
            return ['success' => true, 'message' => 'ส่งเอกสาร PDF สำเร็จ', 'raw' => $data];
        }

        // Defensive fallback: If failed because Telegram can't parse HTML entities, retry as plain text
        $errorDesc = $data['description'] ?? "HTTP Code {$httpCode}: {$response}";
        if (stripos($errorDesc, 'parse') !== false || stripos($errorDesc, 'entity') !== false) {
            $postData['caption'] = strip_tags($safeCaption);
            unset($postData['parse_mode']);

            $chRetry = curl_init();
            curl_setopt($chRetry, CURLOPT_URL, $url);
            curl_setopt($chRetry, CURLOPT_POST, true);
            curl_setopt($chRetry, CURLOPT_POSTFIELDS, $postData);
            curl_setopt($chRetry, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chRetry, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($chRetry, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($chRetry, CURLOPT_TIMEOUT, 45);
            curl_setopt($chRetry, CURLOPT_CONNECTTIMEOUT, 15);
            $retryResp = curl_exec($chRetry);
            curl_close($chRetry);

            $retryData = json_decode($retryResp, true);
            if (!empty($retryData['ok'])) {
                return ['success' => true, 'message' => 'ส่งเอกสาร PDF สำเร็จ (Fallback Plain Text)', 'raw' => $retryData];
            }
        }

        return ['success' => false, 'message' => 'Telegram API Error: ' . $errorDesc, 'raw' => $data];
    }
}

if (!function_exists('getTelegramSystemSettings')) {
    /**
     * Fetch Telegram system settings from database
     */
    function getTelegramSystemSettings($pdo) {
        try {
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('telegram_bot_token', 'telegram_group_chat_id', 'telegram_bot_username', 'site_name_th')");
            $settings = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            return $settings;
        } catch (Exception $e) {
            return [];
        }
    }
}

if (!function_exists('notifyTelegramAction')) {
    /**
     * Dispatch rich Telegram notifications for request actions (submit, approve, reject)
     * @param PDO $pdo
     * @param int $requestId
     * @param string $actionType ('submit'|'approve'|'reject')
     */
    function notifyTelegramAction($pdo, $requestId, $actionType) {
        try {
            $settings = getTelegramSystemSettings($pdo);
            $botToken = $settings['telegram_bot_token'] ?? '';
            $groupChatId = $settings['telegram_group_chat_id'] ?? '';

            if (empty($botToken)) {
                return; // Telegram not configured
            }

            // Fetch request details
            $stmt = $pdo->prepare("
                SELECT r.*, 
                       CONCAT(u.prefix, u.firstname, ' ', u.lastname) AS requester_name, 
                       u.section AS requester_section,
                       u.telegram_chat_id AS requester_telegram
                FROM requests r
                JOIN users u ON r.user_id = u.id
                WHERE r.id = ?
            ");
            $stmt->execute([$requestId]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$request) return;

            // Fetch items
            $stmtItems = $pdo->prepare("
                SELECT ri.*, s.name AS supply_name, s.unit, s.code AS supply_code
                FROM request_items ri
                JOIN supplies s ON ri.supply_id = s.id
                WHERE ri.request_id = ? AND (ri.is_deleted IS NULL OR ri.is_deleted = 0)
            ");
            $stmtItems->execute([$requestId]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            $withdrawCode = $request['request_name'];
            $requesterName = $request['requester_name'];
            $requesterSection = $request['requester_section'] ?: $request['section'];
            $status = $request['status'];

            // ปรับลดการส่งข้อความ: ส่งเฉพาะเมื่อมีการอนุมัติจากผู้อำนวยการหรือผู้ปฏิบัติหน้าที่แทน (status == 'approved') เท่านั้น
            if ($status !== 'approved') {
                return;
            }

            $statusMap = [
                'pending_head' => 'รอหัวหน้าส่วนงานอนุมัติ',
                'pending_supply' => 'รอฝ่ายพัสดุตรวจสอบ',
                'pending_director' => 'รอผู้อำนวยการอนุมัติ',
                'approved' => 'อนุมัติการเบิกสำเร็จ (พร้อมเบิกจ่าย)',
                'rejected' => 'ปฏิเสธคำขอการเบิก',
                'completed' => 'ดำเนินการเบิกจ่ายเสร็จสิ้น'
            ];
            $statusThai = $statusMap[$status] ?? $status;

            // Build items list
            $itemsListText = "";
            foreach ($items as $idx => $it) {
                $qtyStr = ($it['approved_qty'] !== null) ? $it['approved_qty'] : $it['qty'];
                $unit = $it['unit'] ?: 'ชิ้น';
                $itemsListText .= ($idx + 1) . ". {$it['supply_name']} - จำนวน {$qtyStr} {$unit}\n";
            }

            // 1. Central Group Notification
            if (!empty($groupChatId)) {
                $centralMsg = "<b>📦 ความเคลื่อนไหวการเบิกพัสดุ (ส่วนกลาง)</b>\n";
                $centralMsg .= "เลขที่ใบเบิก: <b>{$withdrawCode}</b>\n";
                $centralMsg .= "ผู้ขอเบิก: {$requesterName} ({$requesterSection})\n";
                $centralMsg .= "สถานะ: <b>{$statusThai}</b>\n";
                if (!empty($request['reason'])) {
                    $centralMsg .= "เหตุผลการเบิก: <i>{$request['reason']}</i>\n";
                }
                if (!empty($request['reject_reason'])) {
                    $centralMsg .= "เหตุผลการปฏิเสธ: <i>{$request['reject_reason']}</i>\n";
                }
                $centralMsg .= "\n<b>รายการพัสดุ:</b>\n{$itemsListText}";

                if ($status === 'approved') {
                    $centralMsg .= "\n🎉 <b>อนุมัติเสร็จสิ้นการเบิกเรียบร้อยแล้ว</b>\n👉 <i>กรุณาเข้าสู่ระบบเพื่อดำเนินการจัดจ่ายวัสดุต่อไป</i>";
                }
                sendTelegramDirect($botToken, $groupChatId, $centralMsg);
            }

            // 2. Individual Notification to Requester
            if (!empty($request['requester_telegram'])) {
                $userMsg = "<b>🔔 แจ้งเตือนสถานะคำขอเบิกพัสดุ</b>\n";
                $userMsg .= "เลขที่ใบเบิก: <b>{$withdrawCode}</b>\n";
                $userMsg .= "สถานะล่าสุด: <b>{$statusThai}</b>\n";
                if (!empty($request['reject_reason'])) {
                    $userMsg .= "เหตุผลปฏิเสธ: <i>{$request['reject_reason']}</i>\n";
                }
                $userMsg .= "\n<b>รายการพัสดุ:</b>\n{$itemsListText}";
                sendTelegramDirect($botToken, $request['requester_telegram'], $userMsg);
            }

            // 3. Individual Notification to Approver of Next Step
            if ($status === 'pending_head') {
                // Head & Deputy Head of same section
                $stmtApprovers = $pdo->prepare("SELECT telegram_chat_id FROM users WHERE role IN ('head', 'deputy_head') AND TRIM(section) = TRIM(?) AND status = 'active' AND telegram_chat_id IS NOT NULL");
                $stmtApprovers->execute([$requesterSection]);
                while ($chatId = $stmtApprovers->fetchColumn()) {
                    $appMsg = "<b>📝 มีรายการขอเบิกรอการอนุมัติ (หัวหน้า/รองหัวหน้าส่วน)</b>\n";
                    $appMsg .= "ผู้ขอเบิก: {$requesterName} ({$requesterSection})\n";
                    $appMsg .= "เลขที่ใบเบิก: <b>{$withdrawCode}</b>\n";
                    $appMsg .= "เหตุผล: <i>" . ($request['reason'] ?: '-') . "</i>\n";
                    $appMsg .= "\n<b>รายการพัสดุ:</b>\n{$itemsListText}";
                    $appMsg .= "\n👉 <i>กรุณาเข้าสู่ระบบเพื่อตรวจสอบและอนุมัติรายการ</i>";
                    sendTelegramDirect($botToken, $chatId, $appMsg);
                }
            } elseif ($status === 'pending_supply') {
                // Supply officers & head
                $stmtApprovers = $pdo->query("SELECT telegram_chat_id FROM users WHERE role IN ('supply_head', 'supply_officer') AND status = 'active' AND telegram_chat_id IS NOT NULL");
                while ($chatId = $stmtApprovers->fetchColumn()) {
                    $appMsg = "<b>📝 มีรายการขอเบิกผ่านหัวหน้าส่วนแล้ว รอฝ่ายพัสดุตรวจสอบ</b>\n";
                    $appMsg .= "ผู้ขอเบิก: {$requesterName} ({$requesterSection})\n";
                    $appMsg .= "เลขที่ใบเบิก: <b>{$withdrawCode}</b>\n";
                    $appMsg .= "\n<b>รายการพัสดุ:</b>\n{$itemsListText}";
                    $appMsg .= "\n👉 <i>กรุณาเข้าสู่ระบบเพื่อตรวจสอบรายการ</i>";
                    sendTelegramDirect($botToken, $chatId, $appMsg);
                }
            } elseif ($status === 'pending_director') {
                // Director & Supply Head acting as Director
                $stmtApprovers = $pdo->query("SELECT telegram_chat_id FROM users WHERE role IN ('director', 'supply_head') AND status = 'active' AND telegram_chat_id IS NOT NULL");
                while ($chatId = $stmtApprovers->fetchColumn()) {
                    $appMsg = "<b>📝 มีรายการขอเบิกผ่านการตรวจสอบแล้ว รอผู้อำนวยการอนุมัติ</b>\n";
                    $appMsg .= "ผู้ขอเบิก: {$requesterName} ({$requesterSection})\n";
                    $appMsg .= "เลขที่ใบเบิก: <b>{$withdrawCode}</b>\n";
                    $appMsg .= "\n<b>รายการพัสดุ:</b>\n{$itemsListText}";
                    $appMsg .= "\n👉 <i>กรุณาเข้าสู่ระบบเพื่ออนุมัติรายการ</i>";
                    sendTelegramDirect($botToken, $chatId, $appMsg);
                }
            }

        } catch (Exception $e) {
            // Silently continue so core flow is never blocked
            error_log("notifyTelegramAction Error: " . $e->getMessage());
        }
    }
}

if (!function_exists('dispatchRequisitionPdfTelegram')) {
    /**
     * Generate Requisition PDF via Headless Chrome and send document to Telegram
     * @param PDO $pdo
     * @param string|int $idOrCode Single ID, comma-separated IDs, or group code (last_approve / request_name)
     * @return array
     */
    function dispatchRequisitionPdfTelegram($pdo, $idOrCode) {
        $idOrCode = trim((string)$idOrCode);
        if (empty($idOrCode)) {
            return ['success' => false, 'status' => 'error', 'message' => 'ไม่ได้ระบุรหัสใบเบิก'];
        }

        $settings = getTelegramSystemSettings($pdo);
        $botToken = trim($settings['telegram_bot_token'] ?? '');
        $groupChatId = trim($settings['telegram_group_chat_id'] ?? '');

        if (empty($botToken)) {
            return [
                'success' => false,
                'status' => 'error',
                'message' => 'ยังไม่ได้ตั้งค่า Telegram Bot Token ในระบบ กรุณาตรวจสอบที่เมนู "ตั้งค่าระบบ"'
            ];
        }

        // Query requests
        $rawCodes = array_values(array_filter(array_map('trim', explode(',', $idOrCode))));
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
            $params = [$idOrCode, $idOrCode];
        }

        $sqlRequests = "SELECT r.*, 
                               CONCAT(u.prefix, u.firstname, ' ', u.lastname) as requester_name,
                               u.section as requester_section,
                               u.telegram_chat_id as requester_telegram
                        FROM requests r
                        LEFT JOIN users u ON r.user_id = u.id
                        WHERE (" . implode(' OR ', $whereParts) . ")
                        ORDER BY r.id ASC";
        $stmtReqs = $pdo->prepare($sqlRequests);
        $stmtReqs->execute($params);
        $requests = $stmtReqs->fetchAll(PDO::FETCH_ASSOC);

        $isOldSystem = false;
        $oldWithdrawData = [];

        if (empty($requests)) {
            $sqlOld = "SELECT w.*, 
                              CONCAT(an.authen_name, ' ', an.authen_lname) as requester_name,
                              ap.part_name as requester_section
                       FROM withdraw w
                       LEFT JOIN authen_name an ON w.user_withdraw = an.id
                       LEFT JOIN authen_part ap ON an.part_id = ap.id
                       WHERE w.last_approve = ? OR w.withdraw_code = ?
                       ORDER BY w.id ASC";
            try {
                $stmtOld = $pdo->prepare($sqlOld);
                $stmtOld->execute([$idOrCode, $idOrCode]);
                $oldWithdrawData = $stmtOld->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($oldWithdrawData)) {
                    $isOldSystem = true;
                }
            } catch (Throwable $e) {}

            if (empty($oldWithdrawData)) {
                if (!isset($GLOBALS['pdo_old'])) {
                    @include_once __DIR__ . '/config2.php';
                }
                if (isset($GLOBALS['pdo_old']) && $GLOBALS['pdo_old'] !== null) {
                    try {
                        $stmtOld = $GLOBALS['pdo_old']->prepare($sqlOld);
                        $stmtOld->execute([$idOrCode, $idOrCode]);
                        $oldWithdrawData = $stmtOld->fetchAll(PDO::FETCH_ASSOC);
                        if (!empty($oldWithdrawData)) {
                            $isOldSystem = true;
                        }
                    } catch (Throwable $e) {}
                }
            }
        }

        if (empty($requests) && !$isOldSystem) {
            return ['success' => false, 'status' => 'error', 'message' => "ไม่พบข้อมูลรายการขอเบิกสำหรับรหัส '{$idOrCode}'"];
        }

        // Determine codes and metadata
        $primaryCode = $idOrCode;
        $requesterNames = [];
        $requesterTelegrams = [];
        $sectionName = 'ส่วนงาน';

        if (!$isOldSystem) {
            $firstReq = $requests[0];
            $primaryCode = $firstReq['last_approve'] ?: $firstReq['request_name'];
            $sectionName = $firstReq['requester_section'] ?: ($firstReq['section'] ?: 'ส่วนงาน');
            foreach ($requests as $r) {
                if (!empty($r['requester_name'])) $requesterNames[] = trim($r['requester_name']);
                if (!empty($r['requester_telegram'])) $requesterTelegrams[] = trim($r['requester_telegram']);
            }
        } else {
            $firstOld = $oldWithdrawData[0];
            $primaryCode = $firstOld['last_approve'] ?: $firstOld['withdraw_code'];
            $sectionName = $firstOld['requester_section'] ?: 'ส่วนงาน';
            foreach ($oldWithdrawData as $ow) {
                if (!empty($ow['requester_name'])) $requesterNames[] = trim($ow['requester_name']);
            }
        }

        $requesterNames = array_values(array_unique(array_filter($requesterNames)));
        $requesterTelegrams = array_values(array_unique(array_filter($requesterTelegrams)));

        // Safe ASCII filename for disk storage to prevent Windows proc_open encoding issues
        $safeCode = preg_replace('/[^\w\-]/', '_', $primaryCode);
        $diskFilename = "req_" . $safeCode . "_" . time();
        $displayFilename = "ใบเบิก_" . $primaryCode . ".pdf";

        $uploadsDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'pdf_requisitions';
        if (!file_exists($uploadsDir)) {
            @mkdir($uploadsDir, 0777, true);
        }

        $pdfPath = null;
        $relativePdfPath = '';

        // Detect Base URL
        $baseUrl = 'http://localhost/durables';
        if (isset($_SERVER['HTTP_HOST'])) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
            $baseUrl = $protocol . $_SERVER['HTTP_HOST'] . ($scriptDir ? $scriptDir : '/durables');
        } elseif (getenv('BASE_URL')) {
            $baseUrl = rtrim(getenv('BASE_URL'), '/');
        }

        $cliError = '';

        // ── Method A: Call running Node.js Coordinator Service (Port 3000) ──
        try {
            $nodeApiUrl = 'http://localhost:3000/api/generate-pdf';
            $chNode = curl_init($nodeApiUrl);
            curl_setopt($chNode, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chNode, CURLOPT_POST, true);
            curl_setopt($chNode, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($chNode, CURLOPT_POSTFIELDS, json_encode([
                'request_id' => $idOrCode,
                'group_code' => $idOrCode
            ]));
            curl_setopt($chNode, CURLOPT_TIMEOUT, 30);
            curl_setopt($chNode, CURLOPT_CONNECTTIMEOUT, 2);
            $nodeResp = curl_exec($chNode);
            $nodeHttp = curl_getinfo($chNode, CURLINFO_HTTP_CODE);
            curl_close($chNode);

            if ($nodeHttp === 200 && !empty($nodeResp)) {
                $nodeData = json_decode($nodeResp, true);
                if (!empty($nodeData['pdf_path'])) {
                    $possiblePdf = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $nodeData['pdf_path']);
                    if (file_exists($possiblePdf) && filesize($possiblePdf) > 0) {
                        $pdfPath = $possiblePdf;
                        $relativePdfPath = $nodeData['pdf_path'];
                    }
                }
            }
        } catch (Throwable $ne) {
            // Node service not reachable, will fallback to CLI
        }

        // ── Method B: Fallback to standalone CLI (Puppeteer Headless Chrome) ──
        if (!$pdfPath || !file_exists($pdfPath)) {
            $cliScript = __DIR__ . DIRECTORY_SEPARATOR . 'node_server' . DIRECTORY_SEPARATOR . 'generate_pdf_cli.js';
            if (file_exists($cliScript)) {
                $nodeBin = 'node';
                $possibleNodes = [
                    'C:\\Program Files\\nodejs\\node.exe',
                    'C:\\Program Files (x86)\\nodejs\\node.exe'
                ];
                foreach ($possibleNodes as $np) {
                    if (file_exists($np)) {
                        $nodeBin = $np;
                        break;
                    }
                }

                $cmd = '"' . $nodeBin . '" ' . escapeshellarg($cliScript) . ' ' .
                       escapeshellarg($idOrCode) . ' ' .
                       escapeshellarg($diskFilename) . ' ' .
                       escapeshellarg($botToken) . ' ' .
                       escapeshellarg($baseUrl);

                $descriptors = [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w']
                ];
                $process = proc_open($cmd, $descriptors, $pipes, __DIR__ . DIRECTORY_SEPARATOR . 'node_server');
                if (is_resource($process)) {
                    fclose($pipes[0]);
                    $stdout = stream_get_contents($pipes[1]);
                    $stderr = stream_get_contents($pipes[2]);
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    proc_close($process);

                    if (!empty($stdout)) {
                        $cliData = json_decode(trim($stdout), true);
                        if (!empty($cliData['success']) && !empty($cliData['pdf_path']) && file_exists($cliData['pdf_path'])) {
                            $pdfPath = $cliData['pdf_path'];
                            $relativePdfPath = $cliData['relative_path'] ?? ('uploads/pdf_requisitions/' . basename($pdfPath));
                        } elseif (!empty($cliData['error'])) {
                            $cliError = $cliData['error'];
                        }
                    }
                    if (empty($pdfPath) && !empty($stderr)) {
                        $cliError .= ' ' . trim($stderr);
                    }
                }
            }
        }

        if (!$pdfPath || !file_exists($pdfPath) || filesize($pdfPath) === 0) {
            $errMsg = 'ไม่สามารถสร้างไฟล์ PDF เอกสารใบเบิกได้ กรุณาตรวจสอบ Chrome/Node.js ในระบบ';
            if (!empty($cliError)) {
                $errMsg .= ' (' . trim($cliError) . ')';
            }
            return [
                'success' => false,
                'status' => 'error',
                'message' => $errMsg,
                'pdf_url' => '',
                'filename' => ''
            ];
        }

        // Format Caption for Telegram
        $requesterText = implode(', ', array_slice($requesterNames, 0, 3));
        if (count($requesterNames) > 3) {
            $requesterText .= ' และอื่นๆ รวม ' . count($requesterNames) . ' ท่าน';
        }

        $thaiMonths = [1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.', 5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.', 9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.'];
        $thaiDateStr = date('j') . ' ' . $thaiMonths[(int)date('n')] . ' ' . (date('Y') + 543) . ' ' . date('H:i') . ' น.';

        $safePrimaryCode = htmlspecialchars($primaryCode, ENT_QUOTES, 'UTF-8');
        $safeRequesterText = htmlspecialchars($requesterText, ENT_QUOTES, 'UTF-8');
        $safeSectionName = htmlspecialchars($sectionName, ENT_QUOTES, 'UTF-8');

        $caption = "📄 <b>เอกสารรายการใบเบิกพัสดุ (PDF)</b>\n" .
                   "เลขที่ใบเบิก: <b>{$safePrimaryCode}</b>\n" .
                   "ผู้ขอเบิก: <b>{$safeRequesterText}</b>\n" .
                   "ส่วนงาน: {$safeSectionName}\n" .
                   "สถานะ: <b>อนุมัติเรียบร้อย พร้อมเบิกจ่าย</b>\n" .
                   "ส่งเมื่อ: {$thaiDateStr}";

        $sentTargets = [];
        $errors = [];

        // 1. Send to central group
        if (!empty($groupChatId)) {
            $resGroup = sendTelegramDocument($botToken, $groupChatId, $pdfPath, $caption, $displayFilename);
            if (!empty($resGroup['success'])) {
                $sentTargets[] = 'กลุ่ม Telegram ส่วนกลาง';
            } else {
                $errors[] = 'กลุ่มส่วนกลาง: ' . ($resGroup['message'] ?? 'ไม่สำเร็จ');
            }
        }

        // 2. Send to individual requesters
        foreach ($requesterTelegrams as $uChatId) {
            if ($uChatId === $groupChatId) continue;
            $resUser = sendTelegramDocument($botToken, $uChatId, $pdfPath, $caption, $displayFilename);
            if (!empty($resUser['success'])) {
                $sentTargets[] = "ผู้ขอเบิก (Telegram Chat: {$uChatId})";
            } else {
                $errors[] = "ผู้ขอเบิก ({$uChatId}): " . ($resUser['message'] ?? 'ไม่สำเร็จ');
            }
        }

        $webPdfUrl = str_replace('\\', '/', $relativePdfPath);

        if (empty($sentTargets)) {
            $errDetail = !empty($errors) ? implode('; ', $errors) : 'ไม่พบบัญชี Telegram ของกลุ่มส่วนกลางหรือผู้ขอเบิก';
            return [
                'success' => false,
                'status' => 'warning',
                'message' => 'สร้างเอกสาร PDF สำเร็จแล้ว แต่ไม่สามารถส่งเข้า Telegram ได้ (' . $errDetail . ')',
                'pdf_url' => $webPdfUrl,
                'filename' => $displayFilename,
                'sent_targets' => [],
                'errors' => $errors
            ];
        }

        return [
            'success' => true,
            'status' => 'success',
            'message' => 'สร้างเอกสาร PDF และส่งไปยัง Telegram เรียบร้อยแล้ว',
            'pdf_url' => $webPdfUrl,
            'filename' => $displayFilename,
            'sent_targets' => $sentTargets,
            'errors' => $errors
        ];
    }
}
