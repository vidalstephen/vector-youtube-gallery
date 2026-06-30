// Card-zoom screenshot helper: navigates to the URL, scrolls to the first
// .vyg-card, and captures only that element. Mirrors scripts/cdp-screenshot.js
// but uses element.getBoundingClientRect() to crop.
const fs = require('fs');
const http = require('http');
const { spawn } = require('child_process');

const url    = process.argv[2];
const out    = process.argv[3];
const waitMs = Number(process.argv[4] || 2500);
const chrome = process.env.CHROME || '/ms-playwright/chromium-1124/chrome-linux/chrome';
const port   = 9222 + Math.floor(Math.random() * 1000);

function getJson(path) {
  return new Promise((resolve, reject) => {
    http.get({ host: '127.0.0.1', port, path }, (res) => {
      let body = '';
      res.setEncoding('utf8');
      res.on('data', chunk => body += chunk);
      res.on('end', () => {
        try { resolve(JSON.parse(body)); } catch (e) { reject(e); }
      });
    }).on('error', reject);
  });
}

async function waitForVersion() {
  const deadline = Date.now() + 15000;
  while (Date.now() < deadline) {
    try { return await getJson('/json/version'); } catch (e) { await new Promise(r => setTimeout(r, 250)); }
  }
  throw new Error('Chrome DevTools endpoint did not become ready');
}

function cdp(wsUrl) {
  const ws = new WebSocket(wsUrl);
  let id = 0;
  const pending = new Map();
  ws.onmessage = (ev) => {
    const msg = JSON.parse(ev.data);
    if (msg.id && pending.has(msg.id)) {
      const { resolve, reject } = pending.get(msg.id);
      pending.delete(msg.id);
      if (msg.error) reject(new Error(JSON.stringify(msg.error)));
      else resolve(msg.result || {});
    }
  };
  return new Promise((resolve, reject) => {
    ws.onerror = reject;
    ws.onopen = () => resolve({
      send(method, params = {}) {
        const msgId = ++id;
        ws.send(JSON.stringify({ id: msgId, method, params }));
        return new Promise((resolve, reject) => pending.set(msgId, { resolve, reject }));
      },
      close() { ws.close(); },
    });
  });
}

(async () => {
  const args = [
    '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
    '--disable-background-networking', '--disable-extensions', '--disable-component-update',
    '--hide-scrollbars', '--window-size=1280,1400', `--remote-debugging-port=${port}`,
    'about:blank',
  ];
  const child = spawn(chrome, args, { stdio: ['ignore', 'ignore', 'pipe'] });
  const version = await waitForVersion();
  const targets = await getJson('/json/list');
  const pageTarget = targets.find(t => t.type === 'page' && t.webSocketDebuggerUrl);
  if (!pageTarget) throw new Error('No page target WebSocket endpoint found');
  const client = await cdp(pageTarget.webSocketDebuggerUrl);
  await client.send('Page.enable');
  await client.send('Runtime.enable');
  await client.send('Emulation.setDeviceMetricsOverride', {
    width: 1280, height: 1400, deviceScaleFactor: 1, mobile: false,
  });
  await client.send('Page.navigate', { url });
  await new Promise(r => setTimeout(r, waitMs));

  // Locate the first .vyg-card, scroll it into view, and read its rect.
  await client.send('Runtime.evaluate', {
    expression: 'document.querySelector(".vyg-card") && document.querySelector(".vyg-card").scrollIntoView({block: "center"})',
  });
  await new Promise(r => setTimeout(r, 500));

  const rect = await client.send('Runtime.evaluate', {
    expression: 'JSON.stringify(document.querySelector(".vyg-card") ? document.querySelector(".vyg-card").getBoundingClientRect() : null)',
    returnByValue: true,
  });
  const parsed = JSON.parse(rect.result.value || 'null');
  if (!parsed) {
    throw new Error('No .vyg-card found on page');
  }

  // Capture the page then crop with ImageMagick. Falls back to full screenshot
  // if convert isn't available.
  const tmp = out + '.full.png';
  const shot = await client.send('Page.captureScreenshot', { format: 'png', fromSurface: true });
  fs.writeFileSync(tmp, Buffer.from(shot.data, 'base64'));

  client.close();
  child.kill('SIGTERM');

  const { execFileSync } = require('child_process');
  try {
    const x = Math.max(0, Math.floor(parsed.x));
    const y = Math.max(0, Math.floor(parsed.y));
    const w = Math.max(100, Math.min(800, Math.floor(parsed.width)));
    const h = Math.max(100, Math.min(800, Math.floor(parsed.height)));
    execFileSync('convert', [tmp, '-crop', `${w}x${h}+${x}+${y}`, '+repage', out]);
    fs.unlinkSync(tmp);
  } catch (e) {
    // ImageMagick not available — keep the full-page screenshot as a fallback.
    fs.renameSync(tmp, out);
  }
})().catch(err => { console.error(err && err.stack || err); process.exit(1); });
