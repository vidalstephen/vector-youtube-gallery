#!/usr/bin/env node
// Phase 15.0 deep diagnostic — measures actual visual evidence of the fixes.
const path = require('path');
const fs = require('fs');
const { chromium } = require('/root/.npm/_npx/e41f203b7505f1fb/node_modules/playwright');

const BASE = 'https://wpt.nsystems.live';
const LAYOUTS = ['grid','masonry','carousel','list','featured','hero','shorts','live'];
const OUT_DIR = '/root/projects/vector-youtube-gallery/screenshots/phase15';
const VIEWPORT = { width: 1440, height: 900 };
const WAIT_MS = 4500;

(async () => {
  const browser = await chromium.launch({ headless: true, args: ['--no-sandbox','--disable-dev-shm-usage'] });
  const ctx = await browser.newContext({ viewport: VIEWPORT, deviceScaleFactor: 1 });
  const page = await ctx.newPage();

  for (const layout of LAYOUTS) {
    const url = `${BASE}/vyg-14-12-${layout}/?vyg_cache_bust=${Date.now()}-${layout}`;
    console.log(`\n=== ${layout.toUpperCase()} ===`);
    try {
      await page.goto(url, { waitUntil: 'networkidle', timeout: 45000 });
      await page.waitForTimeout(WAIT_MS);

      const diag = await page.evaluate(() => {
        const out = { url: location.href, viewport: { w: window.innerWidth, h: window.innerHeight } };

        // 1. .vyg-feed container width
        const feed = document.querySelector('.vyg-feed');
        out.feed = feed ? {
          width: feed.clientWidth,
          offsetWidth: feed.offsetWidth,
          classes: feed.className,
          parentWidth: feed.parentElement?.clientWidth,
        } : null;

        // 2. The article entry wrapper
        const article = document.querySelector('.vyg-feed-article, article, .vyg-feed > *');
        out.article = article ? {
          tag: article.tagName.toLowerCase(),
          classes: article.className,
          width: article.clientWidth,
        } : null;

        // 3. Card metrics
        const cards = Array.from(document.querySelectorAll('.vyg-card, li.vyg-card, .vyg-feed__item'));
        out.cardMetrics = {
          count: cards.length,
          widths: cards.slice(0,3).map(c => c.clientWidth),
          classes: cards.slice(0,3).map(c => c.className),
        };

        // 4. Title metrics — actual height vs 2 lines, ellipsis working
        const titles = Array.from(document.querySelectorAll('.vyg-card__title, h3'));
        out.titleMetrics = titles.slice(0,5).map(t => {
          const cs = getComputedStyle(t);
          return {
            text: t.textContent.trim().slice(0, 50),
            clientHeight: t.clientHeight,
            scrollHeight: t.scrollHeight,
            overflows: t.scrollHeight > t.clientHeight + 1,
            lineHeight: cs.lineHeight,
            webkitLineClamp: cs.webkitLineClamp || cs['-webkit-line-clamp'],
            display: cs.display,
            overflow: cs.overflow,
            textOverflow: cs.textOverflow,
            classes: t.className,
          };
        });

        // 5. Channel name metrics (try multiple selectors since list/shorts/live use different markup)
        const chans = Array.from(document.querySelectorAll('.vyg-card__channel-name, .vyg-row__channel, .vyg-shorts__channel, .vyg-live__channel, .vyg-hero__channel'));
        out.channelMetrics = {
          count: chans.length,
          samples: chans.slice(0,3).map(c => {
            const cs = getComputedStyle(c);
            return {
              text: c.textContent.trim().slice(0, 60),
              clientWidth: c.clientWidth,
              scrollWidth: c.scrollWidth,
              clientHeight: c.clientHeight,
              scrollHeight: c.scrollHeight,
              overflowsX: c.scrollWidth > c.clientWidth + 1,
              overflowsY: c.scrollHeight > c.clientHeight + 1,
              whiteSpace: cs.whiteSpace,
              display: cs.display,
              webkitLineClamp: cs.webkitLineClamp || cs['-webkit-line-clamp'],
              classes: c.className,
              hasUC: /^UC[\w-]{10,}/.test(c.textContent.trim()),
            };
          }),
        };

        // 6. Thumbnail metrics
        const thumbs = Array.from(document.querySelectorAll('.vyg-card__thumb'));
        out.thumbMetrics = {
          count: thumbs.length,
          samples: thumbs.slice(0,3).map(t => {
            const r = t.getBoundingClientRect();
            const img = t.querySelector('img');
            return {
              thumbW: r.width,
              thumbH: r.height,
              cardW: t.closest('.vyg-card')?.clientWidth || null,
              fillRatio: t.closest('.vyg-card') ? (r.width / t.closest('.vyg-card').clientWidth).toFixed(2) : null,
              objectFit: img ? getComputedStyle(img).objectFit : null,
              imgNatural: img ? { w: img.naturalWidth, h: img.naturalHeight } : null,
              backgroundImage: getComputedStyle(t).backgroundImage.slice(0, 80),
            };
          }),
        };

        // 7. Look for the CSS file load order
        const links = Array.from(document.querySelectorAll('link[rel="stylesheet"]')).map(l => l.href);
        out.css = {
          presetcss: links.find(l => l.includes('presets') || l.includes('preset')),
          cardcss: links.find(l => l.includes('card')),
          allWithVyg: links.filter(l => l.includes('vyg')),
        };

        return out;
      });

      console.log(JSON.stringify(diag, null, 2));
      fs.writeFileSync(path.join(OUT_DIR, `${layout}-diag.json`), JSON.stringify(diag, null, 2));
    } catch (e) {
      console.log(`  [ERR] ${e.message}`);
    }
  }
  await browser.close();
})();
