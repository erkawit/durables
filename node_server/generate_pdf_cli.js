// generate_pdf_cli.js - Standalone CLI to generate requisition PDF via Headless Chrome
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

// Resolve puppeteer-core from local node_modules
let puppeteer;
try {
  puppeteer = require('./node_modules/puppeteer-core');
} catch (e) {
  try {
    puppeteer = require('puppeteer-core');
  } catch (err) {
    console.log(JSON.stringify({ success: false, error: 'Cannot find puppeteer-core module' }));
    process.exit(1);
  }
}

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

async function run() {
  const args = process.argv.slice(2);
  const idOrGroupCode = args[0];
  const customFilename = args[1] || `ใบเบิก_${idOrGroupCode}`;
  const botToken = args[2] || 'durables_pdf_secret';
  const baseUrl = args[3] || 'http://localhost/durables';

  if (!idOrGroupCode) {
    console.log(JSON.stringify({ success: false, error: 'No ID or group code provided' }));
    process.exit(1);
  }

  const executablePath = getBrowserExecutable();
  if (!executablePath) {
    console.log(JSON.stringify({ success: false, error: 'No Chrome or Edge browser executable found' }));
    process.exit(1);
  }

  const outDir = path.resolve(__dirname, '..', 'uploads', 'pdf_requisitions');
  if (!fs.existsSync(outDir)) {
    fs.mkdirSync(outDir, { recursive: true });
  }

  const safeName = customFilename.replace(/[\/\\:*?"<>|]/g, '_');
  const pdfFilename = safeName.toLowerCase().endsWith('.pdf') ? safeName : `${safeName}.pdf`;
  const pdfPath = path.join(outDir, pdfFilename);

  const os = require('os');
  const tempProfileId = 'p_prof_' + Date.now() + '_' + Math.random().toString(36).substring(2, 7);
  const tempProfileDir = path.join(os.tmpdir(), tempProfileId);

  // Generate internal verification token matching PHP's hash check
  const token = crypto.createHash('md5').update((botToken || 'durables_pdf_secret') + '_internal_pdf_' + idOrGroupCode).digest('hex');
  const targetUrl = `${baseUrl}/export_pdf_request.php?id=${encodeURIComponent(idOrGroupCode)}&token=${token}&headless=1`;

  let browser = null;
  try {
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
    
    // Use domcontentloaded for high performance, then wait for font rendering
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

    const stats = fs.statSync(pdfPath);
    console.log(JSON.stringify({
      success: true,
      pdf_path: pdfPath,
      relative_path: 'uploads/pdf_requisitions/' + pdfFilename,
      filename: pdfFilename,
      size: stats.size
    }));
  } catch (err) {
    console.log(JSON.stringify({
      success: false,
      error: err.message
    }));
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

run();
