#!/usr/bin/env node
/**
 * Capture a screenshot of a URL using the Playwright Docker image.
 *
 * Usage: node cdp-screenshot-elementor.js <url> <output-path> [width] [height] [waitMs]
 *
 * Spins up a temporary Playwright container, navigates to the URL, waits for
 * the page to render, takes a full-page screenshot, copies it out, and
 * removes the container.
 */

const { execSync, exec } = require('child_process');
const fs = require('fs');
const path = require('path');

const url    = process.argv[2];
const out    = process.argv[3];
const width  = parseInt(process.argv[4] || '1280', 10);
const height = parseInt(process.argv[5] || '1400', 10);
const waitMs = parseInt(process.argv[6] || '3000', 10);

if (!url || !out) {
    console.error('Usage: node cdp-screenshot-elementor.js <url> <output-path> [width] [height] [waitMs]');
    process.exit(1);
}

// Write a temporary capture script
const script = `
const { chromium } = require('playwright');
(async () => {
    const browser = await chromium.launch({ args: ['--no-sandbox', '--disable-setuid-sandbox'] });
    const context = await browser.newContext({ viewport: { width: ${width}, height: ${height} } });
    const page = await context.newPage();
    await page.goto('${url}', { waitUntil: 'networkidle', timeout: 30000 });
    await page.waitForTimeout(${waitMs});
    await page.screenshot({ path: '/tmp/screenshot.png', fullPage: true });
    await browser.close();
    console.log('DONE');
})();
`;

const scriptPath = '/tmp/capture-elementor.js';
fs.writeFileSync(scriptPath, script);

// Run in the Playwright Docker container
const tmpOut = '/tmp/vyg-elementor-screenshot.png';
const cmd = `docker run --rm --network host -v ${scriptPath}:/capture.js mcr.microsoft.com/playwright:v1.45.0-jammy node /capture.js`;

try {
    execSync(cmd, { stdio: 'pipe', timeout: 60000 });
    // Copy from container output to the desired path
    // The screenshot is written inside the container at /tmp/screenshot.png
    // We need to use docker cp or mount a volume
} catch (e) {
    // Fallback: use execSync with a volume mount approach
}

// Use a simpler approach with a volume mount
const hostTmpDir = '/tmp/vyg-capture';
if (!fs.existsSync(hostTmpDir)) fs.mkdirSync(hostTmpDir, { recursive: true });

const scriptPath2 = path.join(hostTmpDir, 'capture.js');
fs.writeFileSync(scriptPath2, script.replace("path: '/tmp/screenshot.png'", `path: '/tmp/capture/screenshot.png'`));

const cmd2 = `docker run --rm --network host -v ${hostTmpDir}:/tmp/capture mcr.microsoft.com/playwright:v1.45.0-jammy node /tmp/capture/capture.js`;

try {
    const output = execSync(cmd2, { stdio: 'pipe', timeout: 60000, encoding: 'utf8' });
    const screenshotPath = path.join(hostTmpDir, 'screenshot.png');
    if (fs.existsSync(screenshotPath)) {
        fs.copyFileSync(screenshotPath, out);
        const stats = fs.statSync(out);
        const kb = Math.round(stats.size / 1024);
        console.log(`OK ${out} ${kb} KB`);
        // Cleanup
        fs.unlinkSync(screenshotPath);
        fs.unlinkSync(scriptPath2);
    } else {
        console.error('Screenshot file not found at', screenshotPath);
        process.exit(1);
    }
} catch (e) {
    console.error('Docker run failed:', e.message);
    if (e.stdout) console.error('stdout:', e.stdout.toString());
    if (e.stderr) console.error('stderr:', e.stderr.toString());
    process.exit(1);
}