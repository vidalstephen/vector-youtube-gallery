#!/usr/bin/env node
// Phase 15.0 verification: capture desktop (1440x900) full-page and
// focused .vyg-feed screenshots of all 8 layouts on the live site.
//
// Usage: node scripts/capture-phase15.js
//
// Outputs to /root/projects/vector-youtube-gallery/screenshots/phase15/:
//   {layout}-desktop.png          full-page
//   {layout}-desktop-focused.png  just .vyg-feed element
//   {layout}-meta.json            computed widths/heights of cards/titles

const path = require('path');
const fs = require('fs');

const { chromium } = require('/root/.npm/_npx/e41f203b7505f1fb/node_modules/playwright');

const BASE = 'https://wpt.nsystems.live';
const LAYOUTS = [
  'grid', 'masonry', 'carousel', 'list',
  'featured', 'hero', 'shorts', 'live',
];
const OUT_DIR = '/root/projects/vector-youtube-gallery/screenshots/phase15';
const VIEWPORT = { width: 1440, height: 900 };
const WAIT_MS = 4000;  // generous wait for YouTube embeds + CSS

fs.mkdirSync(OUT_DIR, { recursive: true });

async function dismissBanners(page) {
  // Try a few common cookie/consent selectors.
  const candidates = [
    'button:has-text("Accept")',
    'button:has-text("I agree")',
    'button:has-text("Got it")',
    'button:has-text("OK")',
    'button:has-text("Close")',
    'button:has-text("Reject")',
    '.cookie-banner button',
    '[aria-label*="cookie" i] button',
    '.cmplz-btn',
    '#cookie-notice button',
  ];
  for (const sel of candidates) {
    try {
      const btn = page.locator(sel).first();
      if (await btn.count() && await btn.isVisible({ timeout: 800 })) {
        await btn.click({ timeout: 1500 });
        console.log(`  [banner] dismissed: ${sel}`);
        await page.waitForTimeout(300);
      }
    } catch (_) { /* keep trying */ }
  }
}

async function captureLayout(browser, layout) {
  const url = `${BASE}/vyg-14-12-${layout}/?vyg_cache_bust=${Date.now()}-${layout}`;
  console.log(`\n=== ${layout.toUpperCase()} (${url}) ===`);
  const ctx = await browser.newContext({
    viewport: VIEWPORT,
    deviceScaleFactor: 1,
    userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
  });
  const page = await ctx.newPage();

  page.on('pageerror', (e) => console.log(`  [pageerror] ${e.message}`));
  page.on('requestfailed', (req) => {
    if (!req.url().includes('youtube.com') && !req.url().includes('ytimg.com')) {
      console.log(`  [reqfail] ${req.url()} ${req.failure() && req.failure().errorText}`);
    }
  });

  await page.goto(url, { waitUntil: 'networkidle', timeout: 45000 });
  await page.waitForTimeout(WAIT_MS);
  await dismissBanners(page);

  // Scroll to the .vyg-feed and let it lay out.
  const feed = page.locator('.vyg-feed').first();
  if (await feed.count()) {
    try { await feed.scrollIntoViewIfNeeded({ timeout: 5000 }); } catch (_) {}
    await page.waitForTimeout(800);
  }

  // Full page (after scroll to top for predictable order).
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.waitForTimeout(400);
  const fullOut = path.join(OUT_DIR, `${layout}-desktop.png`);
  await page.screenshot({ path: fullOut, fullPage: true });
  const fullSize = fs.statSync(fullOut).size;
  console.log(`  [full] ${fullOut} (${(fullSize/1024).toFixed(1)} KB)`);

  // Focused gallery.
  const focusedOut = path.join(OUT_DIR, `${layout}-desktop-focused.png`);
  let focusedSize = 0;
  if (await feed.count()) {
    try {
      await feed.screenshot({ path: focusedOut });
      focusedSize = fs.statSync(focusedOut).size;
      console.log(`  [focused] ${focusedOut} (${(focusedSize/1024).toFixed(1)} KB)`);
    } catch (e) {
      console.log(`  [focused] FAILED: ${e.message}`);
    }
  } else {
    console.log(`  [focused] no .vyg-feed found`);
  }

  // Diagnostic metadata.
  const meta = await page.evaluate(() => {
    const feed = document.querySelector('.vyg-feed');
    if (!feed) return { found: false };
    const cards = Array.from(document.querySelectorAll('.vyg-card'));
    const titles = Array.from(document.querySelectorAll('.vyg-card__title'));
    const chans = Array.from(document.querySelectorAll('.vyg-card__channel-name'));
    const thumbs = Array.from(document.querySelectorAll('.vyg-card__thumb'));
    const overflowTitles = titles.filter(t => t.scrollHeight > t.clientHeight + 1);
    const missingChan = cards.filter(c => {
      const cn = c.querySelector('.vyg-card__channel-name');
      if (!cn) return true;
      if (cn.scrollWidth > cn.clientWidth + 1) return true;
      return false;
    });
    const zeroThumb = thumbs.filter(t => {
      const r = t.getBoundingClientRect();
      return r.width < 50 || r.height < 50;
    });
    const firstCard = cards[0];
    const firstThumb = thumbs[0];
    const firstTitle = titles[0];
    const titleStyle = firstTitle && getComputedStyle(firstTitle);
    return {
      found: true,
      layout: feed.dataset.layout || (feed.className.match(/vyg-feed--(\w+)/) || [,'?'])[1],
      feedWidth: feed.clientWidth,
      cardCount: cards.length,
      thumbCount: thumbs.length,
      firstCard: firstCard ? {
        width: firstCard.clientWidth,
        height: firstCard.clientHeight,
        classes: firstCard.className,
      } : null,
      firstThumb: firstThumb ? {
        width: firstThumb.clientWidth,
        height: firstThumb.clientHeight,
        objectFit: getComputedStyle(firstThumb.querySelector('img') || firstThumb).objectFit,
      } : null,
      title: firstTitle ? {
        text: firstTitle.textContent.trim().slice(0, 80),
        height: firstTitle.clientHeight,
        scrollHeight: firstTitle.scrollHeight,
        lineHeight: titleStyle.lineHeight,
        webkitLineClamp: titleStyle.webkitLineClamp || titleStyle['-webkit-line-clamp'],
        overflow: titleStyle.overflow,
        display: titleStyle.display,
        classes: firstTitle.className,
      } : null,
      overflowTitleCount: overflowTitles.length,
      missingChannelCount: missingChan.length,
      zeroThumbCount: zeroThumb.length,
      sampleChannel: chans[0] ? chans[0].textContent.trim() : null,
      titleSample: titles.slice(0, 3).map(t => t.textContent.trim().slice(0, 50)),
    };
  });

  fs.writeFileSync(path.join(OUT_DIR, `${layout}-meta.json`), JSON.stringify(meta, null, 2));
  console.log(`  [meta] cards=${meta.cardCount} thumbs=${meta.thumbCount} overflowTitles=${meta.overflowTitleCount} missingChan=${meta.missingChannelCount} zeroThumb=${meta.zeroThumbCount}`);
  if (meta.title) {
    console.log(`  [meta] titleHeight=${meta.title.height} lineHeight=${meta.title.lineHeight} clamp=${meta.title.webkitLineClamp}`);
  }
  if (meta.firstThumb) {
    console.log(`  [meta] firstThumb=${meta.firstThumb.width}x${meta.firstThumb.height} objectFit=${meta.firstThumb.objectFit}`);
  }

  await ctx.close();
}

(async () => {
  const browser = await chromium.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  });
  try {
    for (const layout of LAYOUTS) {
      try { await captureLayout(browser, layout); }
      catch (e) { console.log(`  [ERROR ${layout}] ${e.message}\n${e.stack}`); }
    }
  } finally {
    await browser.close();
  }
  console.log('\n=== DONE ===');
  console.log('Files written:');
  for (const f of fs.readdirSync(OUT_DIR).sort()) {
    const s = fs.statSync(path.join(OUT_DIR, f)).size;
    console.log(`  ${f} (${(s/1024).toFixed(1)} KB)`);
  }
})().catch(err => { console.error(err.stack || err); process.exit(1); });
