const fs = require('fs');
const http = require('http');
const { spawn } = require('child_process');

const url = process.argv[2];
const out = process.argv[3];
const waitMs = Number(process.argv[4] || 2500);
const chrome = process.env.CHROME || '/ms-playwright/chromium-1124/chrome-linux/chrome';
const port = 9222 + Math.floor(Math.random() * 1000);

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
  const events = [];
  ws.onmessage = (ev) => {
    const msg = JSON.parse(ev.data);
    if (msg.id && pending.has(msg.id)) {
      const { resolve, reject } = pending.get(msg.id);
      pending.delete(msg.id);
      if (msg.error) reject(new Error(JSON.stringify(msg.error)));
      else resolve(msg.result || {});
    } else if (msg.method) {
      events.push(msg);
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
      events,
    });
  });
}

(async () => {
  const args = [
    '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
    '--disable-background-networking', '--disable-extensions', '--disable-component-update',
    '--hide-scrollbars', '--window-size=1440,1400', `--remote-debugging-port=${port}`,
    'about:blank'
  ];
  const child = spawn(chrome, args, { stdio: ['ignore', 'ignore', 'pipe'] });
  const version = await waitForVersion();
  const targets = await getJson('/json/list');
  const pageTarget = targets.find(t => t.type === 'page' && t.webSocketDebuggerUrl);
  if (!pageTarget) {
    throw new Error('No page target WebSocket endpoint found');
  }
  const client = await cdp(pageTarget.webSocketDebuggerUrl);
  await client.send('Page.enable');
  await client.send('Runtime.enable');
  await client.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1400, deviceScaleFactor: 1, mobile: false });
  await client.send('Page.navigate', { url });
  await new Promise(r => setTimeout(r, waitMs));
  await client.send('Runtime.evaluate', { expression: 'window.scrollTo(0,0)' });
  const shot = await client.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, fromSurface: true });
  fs.writeFileSync(out, Buffer.from(shot.data, 'base64'));
  client.close();
  child.kill('SIGTERM');
})().catch(err => { console.error(err && err.stack || err); process.exit(1); });
