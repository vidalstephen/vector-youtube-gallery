// Phase 15.7 verification: capture desktop (1440x900) full-page and
// focused .vyg-feed screenshots of all 8 layouts on the live site.
// Also confirms the show_feed_header master gate is OFF by default
// and the trust strip + layout pill are not present in the output.

const fs = require('fs');

const { chromium } = require('/root/.npm/_npx/e41f203b7505f1fb/node_modules/playwright');

const BASE = 'https://wpt.nsystems.live';
const LAYOUTS = [
  'grid', 'masonry', 'carousel', 'list',
  'featured', 'hero', 'shorts', 'live',
];
const OUT_DIR = '/root/projects/vector-youtube-gallery/screenshots/phase157';
const VIEWPORT = { width: 1440, height: 900 };
const WAIT_MS = 4000;

fs.mkdirSync(OUT_DIR, { recursive: true });

async function dismissBanners(page) {
  // Try a few common cookie/consent selectors.
  const candidates = [
    'button:has-text("Accept")',
    'button:has-text("Accept All")',
    'button:has-text("I Accept")',
    'button:has-text("Got it")',
    'button:has-text("Close")',
    '[aria-label*="dismiss" i]',
    '.cookie-banner-dismiss',
  ];
  for (const sel of candidates) {
    try {
      const el = await page.$(sel);
      if (el) {
        await el.click({ timeout: 1000 });
        await page.waitForTimeout(200);
      }
    } catch (e) { /* ignore */ }
  }
}

async function captureLayout(page, layout) {
  const url = `${BASE}/vyg-14-12-${layout}/?cb=${Date.now()}`;
  console.log(`\n=== ${layout} ===  ${url}`);
  try {
    await page.goto(url, { waitUntil: 'networkidle', timeout: 30000 });
  } catch (e) {
    console.log(`  goto: ${e.message}`);
  }
  await page.waitForTimeout(WAIT_MS);
  await dismissBanners(page);

  // Verify the gates are working: section head should be absent
  const gateStatus = await page.evaluate(() => {
    const sectionHeads = document.querySelectorAll('.vyg-section-head__title');
    const pills = document.querySelectorAll('.vyg-section-head__pill');
    const trustStrips = document.querySelectorAll('.vyg-trust-strip, .vyg-grid__trust-strip');
    return {
      hasFeedHeaderH2: sectionHeads.length > 0,
      hasLayoutPill: pills.length > 0,
      hasTrustStrip: trustStrips.length > 0,
    };
  });
  console.log('  gate status:', JSON.stringify(gateStatus));

  // Focused .vyg-feed screenshot
  try {
    const feed = await page.$('.vyg-feed');
    if (feed) {
      await feed.screenshot({ path: `${OUT_DIR}/${layout}-desktop-focused.png` });
    } else {
      console.log('  no .vyg-feed element found');
    }
  } catch (e) {
    console.log(`  focused screenshot: ${e.message}`);
  }

  // Full-page screenshot
  try {
    await page.screenshot({ path: `${OUT_DIR}/${layout}-desktop.png`, fullPage: true });
  } catch (e) {
    console.log(`  full-page: ${e.message}`);
  }

  return gateStatus;
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  const ctx = await browser.newContext({ viewport: VIEWPORT });
  await ctx.route('**/*', async (route) => {
    const headers = {
      ...route.request().headers(),
      'cache-control': 'no-cache, no-store, must-revalidate',
      'pragma': 'no-cache',
    };
    await route.continue({ headers });
  });
  const page = await ctx.newPage();
  const summary = {};
  for (const layout of LAYOUTS) {
    summary[layout] = await captureLayout(page, layout);
  }
  console.log('\n=== Summary ===');
  console.log(JSON.stringify(summary, null, 2));
  await browser.close();
})().catch(e => {
  console.error('FAILED:', e);
  process.exit(1);
});
