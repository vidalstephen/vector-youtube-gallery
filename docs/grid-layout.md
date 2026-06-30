# Grid layout

> **Phase 13.1** — server-rendered grid with section header, redesigned card,
> density presets, optional product-CTA, and an opt-in trust-badges strip.

The grid layout is the default `layout` value for the front-end
`[youtube_feed]` shortcode and the `vectoryt/gallery` Gutenberg block. It is
server-rendered from local data only (no YouTube API calls on the front end),
lazy-loads its bundled CSS, and ships its own per-card product-CTA hook.

## Quickstart

```php
// Shortcode
[youtube_feed source_uuid="abc" layout="grid"
  density="comfortable"
  header_title="My Channel"
  header_subtitle="Latest uploads"
  header_cta_label="Visit channel"
  header_cta_url="https://youtube.com/@my-channel"
  show_views_and_time="true"
  trust_strip="true"]

// Gutenberg block (equivalent attribute names)
<!-- wp:vectoryt/gallery {
  "layout":"grid",
  "density":"comfortable",
  "headerTitle":"My Channel",
  "headerSubtitle":"Latest uploads",
  "trustStrip":true
} /-->
```

## Attributes

| Attribute | Type | Default | Notes |
|---|---|---|---|
| `layout` | string | `grid` | Unchanged. |
| `columns` | int 1..6 | `3` | Unchanged. |
| `per_page` | int 1..200 | `12` | Unchanged. |
| `density` | enum | `comfortable` | `compact` / `comfortable` / `editorial`. Drives CSS variables for gap, padding, radius, shadow, and text size. |
| `header_title` | string | `''` | Empty = no header rendered. |
| `header_subtitle` | string | `''` | Only shown when `header_title` is non-empty. |
| `header_columns_visible` | bool | `true` | Shows the "N columns" indicator in the header (static `<span>`; interactive column switcher is Phase 13.5). |
| `header_cta_label` | string | `''` | Empty = no CTA in the header. |
| `header_cta_url` | string | `''` | URL for the CTA. |
| `show_channel_avatar` | bool | `true` | Reserved — only renders when source data is available (Phase 13.2). |
| `show_channel_name` | bool | `true` | Channel name from `vyg_sources.title`. |
| `show_subscriber_count` | bool | `false` | Off by default — see Open questions. |
| `show_verified_badge` | bool | `false` | Off by default — see Open questions. |
| `show_views_and_time` | bool | `true` | Eye + clock meta row (e.g. "125K views • 2 days ago"). |
| `product_cta_visible` | bool | `true` | When a feed has a product mapping, the cart icon renders. Off = no icon at all. |
| `trust_strip` | bool | `false` | Off by default — operators opt in per feed. |
| `card_radius` | string | `12px` | CSS value. Theme-overridable via `--vyg-card-radius`. |

All booleans accept `1` / `0`, `"true"` / `"false"`, and `"yes"` / `"no"`.

## Density presets

| Token | Compact | Comfortable (default) | Editorial |
|---|---|---|---|
| `--vyg-grid-gap` | `0.75rem` | `1.25rem` | `2rem` |
| `--vyg-card-padding` | `0.6rem` | `0.9rem` | `1.25rem` |
| `--vyg-card-radius` | `8px` | `12px` | `16px` |
| `--vyg-card-shadow` | `0 1px 2px rgba(0,0,0,0.04)` | `0 2px 8px rgba(0,0,0,0.06)` | `0 8px 24px rgba(0,0,0,0.08)` |
| `--vyg-card-title-size` | `0.875rem` | `0.95rem` | `1.05rem` |

Density tokens cascade through `.vyg-feed` and combine with the 5 white-label
presets (`default` / `minimal` / `cinema` / `pastel` / `developer`) — preset
wins for color/radius, density wins for gap/shadow/text-size.

## Card anatomy

```html
<article class="vyg-card" data-video-id="…" data-content-type="standard" data-live-status="none">
  <a class="vyg-card__link" href="https://youtube.com/watch?v=…" data-vyg-lightbox="…">
    <div class="vyg-card__thumb-wrap">
      <img class="vyg-card__thumb" src="…" alt="…" loading="lazy" decoding="async" />
      <span class="vyg-card__duration">12:45</span>
      <span class="vyg-card__badge vyg-card__badge--live" hidden>LIVE</span>
    </div>
  </a>
  <div class="vyg-card__body">
    <h3 class="vyg-card__title">Exploring the Canadian Rockies (4K Scenic Adventure)</h3>
    <div class="vyg-card__channel">
      <span class="vyg-card__channel-name">Wander More</span>
      <span class="vyg-card__verified" aria-label="Verified" hidden>✓</span>
    </div>
    <div class="vyg-card__meta">
      <span class="vyg-card__meta-views">
        <svg class="vyg-icon" viewBox="0 0 24 24" aria-hidden="true">…</svg>
        125K views
      </span>
      <span class="vyg-card__meta-sep" aria-hidden="true">•</span>
      <span class="vyg-card__meta-time">
        <svg class="vyg-icon" viewBox="0 0 24 24" aria-hidden="true">…</svg>
        2 days ago
      </span>
    </div>
    <div class="vyg-card__cta-wrap">…cart icon button (Phase 10.3 hook)…</div>
  </div>
</article>
```

## Trust strip

When `trust_strip="true"`, the grid renders a 4-item strip below the cards:

- **Lazy Loaded** — thumbnails use `loading="lazy"`.
- **Privacy Safe** — front-end never makes YouTube API calls; you choose what to
  show from your own index.
- **Accessible** — keyboard-navigable cards, focus rings, ARIA live region for
  lightbox, `prefers-reduced-motion` respected.
- **Builder Ready** — works with Gutenberg, Elementor, Divi, and WooCommerce.

The trust strip is hidden in print and does not duplicate on load-more.

## Screenshot gallery

| Capture | File |
|---|---|
| Comfortable density, 1280×800 | `screenshots/phase13/grid-comfortable-desktop.png` |
| Comfortable density, 380×800 (mobile) | `screenshots/phase13/grid-comfortable-mobile.png` |
| Editorial density | `screenshots/phase13/grid-editorial-desktop.png` |
| Compact density | `screenshots/phase13/grid-compact-desktop.png` |
| Trust strip + header | `screenshots/phase13/grid-trust-strip.png` |
| Card zoom | `screenshots/phase13/grid-card-zoom.png` |

(Screenshots land in Phase 13.1 follow-up — see `DEV-CHECKLIST.md`.)

## Open questions

- **Channel avatar, verified badge, subscriber count** are part of the design
  but require data not currently in `vyg_videos`. Phase 13.1 ships with the
  channel name only; the `show_*` toggles are present but default off for the
  data we don't have yet. The data layer is scheduled for a follow-up phase.
- The "3 columns ▾" indicator in the mockup is a static label in 13.1; an
  interactive column switcher ships in 13.5.

## Accessibility

- Cards are keyboard-focusable via the watch-URL anchor.
- `:focus-visible` shows a 2px outline with 3px offset.
- `@media (prefers-reduced-motion: reduce)` disables hover transforms.
- Trust strip uses semantic `<ul>` with ARIA labels; the lazy/privacy/accessible
  text is real content, not a decorative icon, so it remains readable without
  CSS.
- All icons are inline SVG with `aria-hidden="true"`; adjacent text is the
  real label.

## Performance

- No new HTTP requests at render time.
- Card body adds ~600 bytes per card vs the legacy grid; negligible.
- Trust strip is one-time at the bottom (~400 bytes).
- The bundled CSS file (`assets/css/grid.css`) is enqueued lazily — only when a
  grid shortcode/block actually fires on the page.
