// Phase 15 — post-fix verification with cache-busting.
const { chromium } = require('playwright');

const VIEWPORT = { width: 1440, height: 900 };

(async () => {
  const browser = await chromium.launch({ headless: true });
  // Disable HTTP cache completely.
  const ctx = await browser.newContext({
    viewport: VIEWPORT,
  });
  const page = await ctx.newPage();
  // Intercept every request to add no-cache
  await page.route('**/*', async (route) => {
    const headers = {
      ...route.request().headers(),
      'cache-control': 'no-cache, no-store, must-revalidate',
      'pragma': 'no-cache',
    };
    await route.continue({ headers });
  });
  const url = 'https://wpt.nsystems.live/vyg-14-12-grid/?cb=' + Date.now();
  await page.goto(url, { waitUntil: 'networkidle', timeout: 30000 });
  await page.waitForTimeout(2000);

  const diag = await page.evaluate(() => {
    const feed = document.querySelector('.vyg-feed');
    const card = document.querySelector('.vyg-card');
    const cn = document.querySelector('.vyg-card__channel-name');
    const cs = cn ? getComputedStyle(cn) : null;
    let cnRule = null;
    if (cn) {
      for (const sheet of document.styleSheets) {
        try {
          for (const rule of sheet.cssRules) {
            if (rule.selectorText && rule.selectorText.includes('vyg-card__channel-name')) {
              cnRule = rule.cssText;
              break;
            }
          }
        } catch (e) {}
        if (cnRule) break;
      }
    }
    return {
      feed: feed ? { offsetWidth: feed.offsetWidth, clientWidth: feed.clientWidth, classes: feed.className } : null,
      card: card ? { offsetWidth: card.offsetWidth, clientWidth: card.clientWidth } : null,
      channelName: cn ? {
        text: cn.textContent,
        clientWidth: cn.clientWidth,
        scrollWidth: cn.scrollWidth,
        clientHeight: cn.clientHeight,
        scrollHeight: cn.scrollHeight,
        whiteSpace: cs.whiteSpace,
        display: cs.display,
        webkitLineClamp: cs.webkitLineClamp,
      } : null,
      cnRule,
    };
  });

  console.log(JSON.stringify(diag, null, 2));

  if (diag.feed) {
    const feed = await page.$('.vyg-feed');
    await feed.screenshot({ path: '/root/projects/vector-youtube-gallery/screenshots/phase15/post-fix-focused.png' });
  }
  await page.screenshot({ path: '/root/projects/vector-youtube-gallery/screenshots/phase15/post-fix-full.png', fullPage: true });

  await browser.close();
})().catch(e => {
  console.error('FAILED:', e);
  process.exit(1);
});
