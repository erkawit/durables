// server.js
const express = require('express');
const http = require('http');
const { Server } = require('socket.io');
const mysql = require('mysql2/promise');
const cors = require('cors');
const axios = require('axios');
const puppeteer = require('puppeteer-core');
const FormData = require('form-data');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
require('dotenv').config();

const app = express();
app.use(cors());
app.use(express.json());

const server = http.createServer(app);
const io = new Server(server, {
  cors: {
    origin: '*',
    methods: ['GET', 'POST']
  }
});

// Database pool setup
const pool = mysql.createPool({
  host: process.env.DB_HOST || 'localhost',
  user: process.env.DB_USER || 'root',
  password: process.env.DB_PASS || '',
  database: process.env.DB_NAME || 'durables_db',
  waitForConnections: true,
  connectionLimit: 10,
  queueLimit: 0
});

// Browser executable search paths for Windows / Linux
const browserPaths = [
  'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
  'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
  'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
  'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
  '/usr/bin/google-chrome',
  '/usr/bin/chromium-browser',
  '/usr/bin/chromium'
];

function getBrowserExecutable() {
  return browserPaths.find(p => fs.existsSync(p));
}

// Debounce map for group approvals
const groupPdfDebounceMap = new Map();

// Helper: Query settings from DB
async function getSystemSettings() {
  try {
    const [rows] = await pool.query("SELECT setting_key, setting_value FROM system_settings");
    const settings = {};
    rows.forEach(r => {
      settings[r.setting_key] = r.setting_value;
    });
    return settings;
  } catch (error) {
    console.error("Error fetching system settings:", error);
    return {};
  }
}

// Helper: Send Telegram Text Message
async function sendTelegramMessage(botToken, chatId, text) {
  if (!botToken || !chatId || !text) return false;
  try {
    const url = `https://api.telegram.org/bot${botToken}/sendMessage`;
    const response = await axios.post(url, {
      chat_id: chatId,
      text: text,
      parse_mode: 'HTML'
    }, { timeout: 10000 });
    return response.data.ok;
  } catch (error) {
    console.error(`Failed to send Telegram message to ${chatId}:`, error.message);
    return false;
  }
}

// Helper: Send Telegram Document (PDF)
async function sendTelegramDocument(botToken, chatId, filePath, caption) {
  if (!botToken || !chatId || !filePath || !fs.existsSync(filePath)) {
    console.error(`Cannot send Telegram document: invalid params or file not found (${filePath})`);
    return false;
  }
  try {
    const form = new FormData();
    form.append('chat_id', chatId);
    form.append('document', fs.createReadStream(filePath));
    if (caption) {
      form.append('caption', caption);
      form.append('parse_mode', 'HTML');
    }

    const response = await axios.post(`https://api.telegram.org/bot${botToken}/sendDocument`, form, {
      headers: form.getHeaders(),
      timeout: 30000
    });
    console.log(`[Telegram Document] Sent PDF to ${chatId}: success=${response.data.ok}`);
    return response.data.ok;
  } catch (error) {
    console.error(`[Telegram Document] Failed to send PDF to ${chatId}:`, error.response?.data || error.message);
    return false;
  }
}

// Helper: Generate PDF via Headless Chrome / Puppeteer
async function generateRequisitionPdf(idOrGroupCode, customFilename, botToken) {
  const executablePath = getBrowserExecutable();
  if (!executablePath) {
    console.error("[PDF Generator] No Chrome or Edge executable found. Cannot generate PDF.");
    return null;
  }

  const outDir = path.join(__dirname, '..', 'uploads', 'pdf_requisitions');
  if (!fs.existsSync(outDir)) {
    fs.mkdirSync(outDir, { recursive: true });
  }

  const safeName = (customFilename || `req_${idOrGroupCode}`).replace(/[\/\\:*?"<>|]/g, '_');
  const pdfFilename = safeName.toLowerCase().endsWith('.pdf') ? safeName : `${safeName}.pdf`;
  const pdfPath = path.join(outDir, pdfFilename);

  const os = require('os');
  const tempProfileId = 'p_prof_' + Date.now() + '_' + Math.random().toString(36).substring(2, 7);
  const tempProfileDir = path.join(os.tmpdir(), tempProfileId);

  // Generate internal verification token matching PHP's hash check
  const token = crypto.createHash('md5').update((botToken || 'durables_pdf_secret') + '_internal_pdf_' + idOrGroupCode).digest('hex');
  const targetUrl = `http://localhost/durables/export_pdf_request.php?id=${encodeURIComponent(idOrGroupCode)}&token=${token}&headless=1`;

  let browser = null;
  try {
    console.log(`[PDF Generator] Starting PDF generation for ID/Group '${idOrGroupCode}' using ${executablePath}...`);
    browser = await puppeteer.launch({
      executablePath,
      headless: true,
      pipe: true,
      userDataDir: tempProfileDir,
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-gpu',
        '--disable-dev-shm-usage',
        '--no-first-run',
        '--no-zygote',
        '--disable-extensions',
        '--disable-sync'
      ]
    });

    const page = await browser.newPage();
    await page.setViewport({ width: 1200, height: 1600 });
    try {
      await page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout: 20000 });
      await new Promise(r => setTimeout(r, 1200));
    } catch (navErr) {
      // In case of network timeout, continue to render if DOM is loaded
    }

    // Emulate print media
    await page.emulateMediaType('print');

    await page.pdf({
      path: pdfPath,
      format: 'A4',
      printBackground: true,
      margin: {
        top: '8mm',
        right: '8mm',
        bottom: '8mm',
        left: '8mm'
      }
    });

    console.log(`[PDF Generator] Successfully generated PDF: ${pdfPath}`);
    return pdfPath;
  } catch (err) {
    console.error(`[PDF Generator] Error generating PDF for '${idOrGroupCode}':`, err.message);
    return null;
  } finally {
    if (browser) {
      try { await browser.close(); } catch (e) {}
    }
    try {
      if (fs.existsSync(tempProfileDir)) {
        fs.rmSync(tempProfileDir, { recursive: true, force: true });
      }
    } catch (e) {}
  }
}

// Process and send combined Section Requisition PDF
async function processAndSendGroupPdf(sharedApprovalCode, sectionName, botToken, groupChatId, approverLabel) {
  try {
    console.log(`[Group PDF] Starting processing for group: ${sharedApprovalCode} (Section: ${sectionName})`);

    // 1. Fetch all requests belonging to this group approval code
    const [rows] = await pool.query(`
      SELECT r.id, r.request_name, r.section,
             CONCAT(u.prefix, u.firstname, ' ', u.lastname) as fullname,
             u.telegram_chat_id
      FROM requests r
      JOIN users u ON r.user_id = u.id
      WHERE r.last_approve = ? AND r.status = 'approved'
    `, [sharedApprovalCode]);

    if (rows.length === 0) {
      console.log(`[Group PDF] No approved requests found for group code: ${sharedApprovalCode}`);
      return;
    }

    const requesterNames = [...new Set(rows.map(r => r.fullname).filter(Boolean))];
    const requesterTelegrams = [...new Set(rows.map(r => r.telegram_chat_id).filter(Boolean))];
    const actualSection = rows[0].section || sectionName || 'ส่วนงาน';

    // 2. Fetch total items count
    const [itemsCountRows] = await pool.query(`
      SELECT COUNT(ri.id) as total_items, SUM(COALESCE(ri.approved_qty, ri.qty)) as total_pieces
      FROM request_items ri
      JOIN requests r ON ri.request_id = r.id
      WHERE r.last_approve = ? AND (ri.is_deleted IS NULL OR ri.is_deleted = 0)
    `, [sharedApprovalCode]);
    const totalItems = itemsCountRows[0]?.total_items || 0;
    const totalPieces = itemsCountRows[0]?.total_pieces || 0;

    // 3. Generate combined PDF using the shared group code
    const customFilename = `ใบเบิกกลุ่ม_${actualSection}_${sharedApprovalCode}`;
    const pdfPath = await generateRequisitionPdf(sharedApprovalCode, customFilename, botToken);

    if (!pdfPath) {
      console.error(`[Group PDF] Failed to generate combined PDF for ${sharedApprovalCode}`);
      return;
    }

    // 4. Build Telegram Caption
    let requestersText = requesterNames.slice(0, 3).join(', ');
    if (requesterNames.length > 3) {
      requestersText += ` และอื่นๆ รวม ${requesterNames.length} ท่าน`;
    }

    const caption = `📄 <b>เอกสารใบเบิกพัสดุรวมส่วนงาน (อนุมัติเสร็จสิ้น)</b>\n` +
                    `รหัสใบเบิกกลุ่ม: <b>${sharedApprovalCode}</b>\n` +
                    `ส่วนงาน: <b>${actualSection}</b>\n` +
                    `ผู้อนุมัติ: <b>${approverLabel || 'ผู้อำนวยการ / ผู้ปฏิบัติหน้าที่แทน'}</b>\n` +
                    `ผู้ขอเบิกในส่วนงาน: ${requestersText}\n` +
                    `รวมรายการพัสดุ: <b>${totalItems} รายการ (${totalPieces} ชิ้น)</b>\n` +
                    `สถานะ: <b>อนุมัติเรียบร้อย พร้อมเบิกจ่าย</b>`;

    // 5. Send PDF Document to Central Telegram Group
    if (groupChatId) {
      console.log(`[Group PDF] Sending combined PDF to central group: ${groupChatId}`);
      await sendTelegramDocument(botToken, groupChatId, pdfPath, caption);
    }

    // 6. Send PDF Document to individual requesters in this group who registered Telegram
    for (const chatId of requesterTelegrams) {
      console.log(`[Group PDF] Sending combined PDF to requester chat: ${chatId}`);
      await sendTelegramDocument(botToken, chatId, pdfPath, caption);
    }

    console.log(`[Group PDF] Successfully sent combined PDF for group ${sharedApprovalCode}`);
  } catch (error) {
    console.error(`[Group PDF] Error in processAndSendGroupPdf:`, error);
  }
}

// Debounce helper to ensure all requests in a group batch are finished before creating the PDF
function scheduleGroupPdfDispatch(sharedApprovalCode, sectionName, botToken, groupChatId, approverLabel) {
  if (!sharedApprovalCode) return;

  if (groupPdfDebounceMap.has(sharedApprovalCode)) {
    clearTimeout(groupPdfDebounceMap.get(sharedApprovalCode));
  }

  // Wait 1.5 seconds after last request in the batch updates
  const timer = setTimeout(() => {
    groupPdfDebounceMap.delete(sharedApprovalCode);
    processAndSendGroupPdf(sharedApprovalCode, sectionName, botToken, groupChatId, approverLabel);
  }, 1500);

  groupPdfDebounceMap.set(sharedApprovalCode, timer);
  console.log(`[Group PDF] Debounced group PDF dispatch for ${sharedApprovalCode} in 1500ms...`);
}

// Helper: Get status description in Thai
function getStatusTextThai(status) {
  const statuses = {
    'pending_head': 'รอหัวหน้าส่วนงานอนุมัติ',
    'pending_supply': 'รอฝ่ายพัสดุตรวจสอบ',
    'pending_director': 'รอผู้อำนวยการอนุมัติ',
    'approved': 'อนุมัติการเบิกสำเร็จ (พร้อมเบิกจ่าย)',
    'rejected': 'ปฏิเสธคำขอการเบิก',
    'completed': 'ดำเนินการเบิกจ่ายเสร็จสิ้น',
    'cancelled': 'ยกเลิกรายการเบิก'
  };
  return statuses[status] || status;
}

// API: Health check for monitoring and settings page status
app.get('/api/health', (req, res) => {
  res.json({
    status: 'success',
    message: 'Real-time Stock Coordinator and PDF Dispatcher is online',
    browser_available: !!getBrowserExecutable(),
    browser_path: getBrowserExecutable() || null
  });
});

// API: Check stock sufficiency
app.post('/api/check-stock', async (req, res) => {
  const { items } = req.body; // Array of { supply_id, qty }
  if (!items || !Array.isArray(items) || items.length === 0) {
    return res.status(400).json({ status: 'error', message: 'Invalid items array' });
  }

  try {
    const insufficient = [];
    const checkedItems = [];

    for (const item of items) {
      const supplyId = parseInt(item.supply_id);
      const reqQty = parseInt(item.qty);

      // Query database for current stock directly
      const [rows] = await pool.query("SELECT id, name, qty, unit, min_stock FROM supplies WHERE id = ?", [supplyId]);

      if (rows.length === 0) {
        insufficient.push({ supply_id: supplyId, name: 'Unknown Item', available: 0, requested: reqQty, reason: 'ไม่พบพัสดุในระบบ' });
        continue;
      }

      const supply = rows[0];
      const available = parseInt(supply.qty);

      checkedItems.push({
        id: supplyId,
        name: supply.name,
        available: available,
        requested: reqQty,
        unit: supply.unit
      });

      if (reqQty > available) {
        insufficient.push({
          supply_id: supplyId,
          name: supply.name,
          available: available,
          requested: reqQty,
          unit: supply.unit,
          reason: 'จำนวนพัสดุคงคลังไม่เพียงพอ'
        });
      }
    }

    if (insufficient.length > 0) {
      return res.json({
        status: 'insufficient',
        message: 'มีรายการพัสดุไม่เพียงพอ',
        items: insufficient
      });
    }

    return res.json({
      status: 'success',
      message: 'ตรวจสอบสต็อกผ่านเกณฑ์ทั้งหมด',
      items: checkedItems
    });

  } catch (error) {
    console.error("Error checking stock:", error);
    return res.status(500).json({ status: 'error', message: 'Database error: ' + error.message });
  }
});

// API: Generate PDF manually or on-demand
app.post('/api/generate-pdf', async (req, res) => {
  const { request_id, group_code } = req.body;
  const targetId = group_code || request_id;
  if (!targetId) {
    return res.status(400).json({ status: 'error', message: 'Missing request_id or group_code parameter' });
  }

  try {
    const settings = await getSystemSettings();
    const botToken = settings['telegram_bot_token'] || '';

    let customFilename = `ใบเบิก_${targetId}`;
    if (group_code) {
      customFilename = `ใบเบิกกลุ่ม_${group_code}`;
    }

    const pdfPath = await generateRequisitionPdf(targetId, customFilename, botToken);
    if (!pdfPath) {
      return res.status(500).json({ status: 'error', message: 'Failed to generate PDF document' });
    }

    const relativePath = 'uploads/pdf_requisitions/' + path.basename(pdfPath);
    return res.json({
      status: 'success',
      message: 'สร้างไฟล์ PDF เอกสารใบเบิกสำเร็จ',
      pdf_path: relativePath,
      filename: path.basename(pdfPath)
    });
  } catch (error) {
    return res.status(500).json({ status: 'error', message: error.message });
  }
});

// API: Handle action triggers (checkout, approvals, rejections)
app.post('/api/on-action', async (req, res) => {
  const {
    request_id,
    action_type,
    actor_id,
    role,
    is_director_approval,
    approver_role_label,
    shared_approval_code,
    section
  } = req.body;

  if (!request_id || !action_type) {
    return res.status(400).json({ status: 'error', message: 'Missing parameters' });
  }

  // Acknowledge receipt immediately to keep PHP responsive
  res.json({ status: 'received' });

  // Process notifications and broadcasts in the background
  try {
    // 1. Fetch Request & Items Details
    const [requestsRows] = await pool.query(`
      SELECT r.*, 
             CONCAT(u.prefix, u.firstname, ' ', u.lastname) AS requester_name, 
             u.section AS requester_section,
             u.telegram_chat_id AS requester_telegram
      FROM requests r
      JOIN users u ON r.user_id = u.id
      WHERE r.id = ?
    `, [request_id]);

    if (requestsRows.length === 0) return;
    const request = requestsRows[0];

    const [itemsRows] = await pool.query(`
      SELECT ri.*, s.name AS supply_name, s.unit, s.qty AS current_stock
      FROM request_items ri
      JOIN supplies s ON ri.supply_id = s.id
      WHERE ri.request_id = ? AND (ri.is_deleted IS NULL OR ri.is_deleted = 0)
    `, [request_id]);

    // 2. Broadcast Stock Updates to all connected web browsers
    const stockUpdates = itemsRows.map(item => ({
      id: item.supply_id,
      name: item.supply_name,
      qty: item.current_stock
    }));
    io.emit('stock_update', { items: stockUpdates });

    // 3. Telegram Dispatch: ส่งเฉพาะเมื่อมีการอนุมัติจากผู้อำนวยการ หรือผู้ปฏิบัติงานแทนผู้อำนวยการเท่านั้น
    if (action_type !== 'approve' || !is_director_approval) {
      // ข้ามการส่ง Telegram สำหรับขั้นตอนอื่นๆ ทั้งหมด (submit, pending_head, pending_supply ฯลฯ)
      return;
    }

    const settings = await getSystemSettings();
    const botToken = settings['telegram_bot_token'];
    const groupChatId = settings['telegram_group_chat_id'];

    if (!botToken) {
      console.log("Telegram Bot Token not configured. Skipping Telegram notifications.");
      return;
    }

    // กรณีที่ 1: รายการใบเบิกที่รวมเป็นส่วนงาน (มี shared_approval_code)
    // ส่งข้อความสรุปและแนบไฟล์ PDF รวมของส่วนงานไปพร้อมกันในขั้นตอนนี้ (Debounced 1 ครั้งต่อกลุ่ม)
    if (shared_approval_code) {
      scheduleGroupPdfDispatch(
        shared_approval_code,
        section || request.requester_section || request.section,
        botToken,
        groupChatId,
        approver_role_label
      );
      return;
    }

    // กรณีที่ 2: รายการเบิกเดี่ยวที่ได้รับอนุมัติโดยตรงจากผู้อำนวยการ (ถ้ามี)
    const withdrawCode = request.request_name;
    const requesterName = request.requester_name;
    const requesterSection = request.requester_section || request.section;

    let itemsListText = '';
    itemsRows.forEach((item, index) => {
      const qtyStr = item.approved_qty !== null ? item.approved_qty : item.qty;
      itemsListText += `${index + 1}. ${item.supply_name} - จำนวน ${qtyStr} ${item.unit || 'ชิ้น'}\n`;
    });

    let approveMsg = `<b>🎉 แจ้งเตือนการอนุมัติเบิกพัสดุ (เสร็จสิ้น)</b>\n`;
    approveMsg += `เลขที่ใบเบิก: <b>${withdrawCode}</b>\n`;
    approveMsg += `ผู้ขอเบิก: ${requesterName} (${requesterSection})\n`;
    approveMsg += `ผู้อนุมัติ: <b>${approver_role_label || 'ผู้อำนวยการ'}</b>\n`;
    approveMsg += `สถานะ: <b>อนุมัติเรียบร้อย พร้อมเบิกจ่าย</b>\n`;
    approveMsg += `\n<b>รายการพัสดุ:</b>\n${itemsListText}`;

    if (groupChatId) {
      await sendTelegramMessage(botToken, groupChatId, approveMsg);
    }
    if (request.requester_telegram) {
      await sendTelegramMessage(botToken, request.requester_telegram, approveMsg);
    }

  } catch (error) {
    console.error("Error processing background action:", error);
  }
});

// Start WebSocket connection listeners
io.on('connection', (socket) => {
  console.log(`Web browser client connected: ${socket.id}`);
  
  socket.on('supply_change', (data) => {
    console.log('Broadcasting supply_change event:', data);
    socket.broadcast.emit('supply_change', data);
  });
  
  socket.on('disconnect', () => {
    console.log(`Web browser client disconnected: ${socket.id}`);
  });
});

const PORT = process.env.PORT || 3000;
server.listen(PORT, () => {
  console.log(`Realtime Stock Coordinator and PDF Dispatcher is running on port ${PORT}`);
});
