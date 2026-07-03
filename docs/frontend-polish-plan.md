# Vector YouTube Gallery — Frontend Polish Plan

> **Author:** Research agent (Hermes), commissioned by Stephen Vidal
> **Date:** July 3, 2026
> **Status:** Research + planning document — **no implementation in this pass**
> **Implementation owner:** Separate agent (to be assigned)

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [How We Got Here — Evolution of the Plugin](#2-how-we-got-here--evolution-of-the-plugin)
3. [Current State Assessment](#3-current-state-assessment)
4. [Smash Balloon Reference Research](#4-smash-balloon-reference-research)
5. [Reference Image Analysis — The Visual Target](#5-reference-image-analysis--the-visual-target)
6. [Gap Analysis — Current vs. Target](#6-gap-analysis--current-vs-target)
7. [Phased Implementation Plan](#7-phased-implementation-plan)
8. [Elementor Integration Roadmap](#8-elementor-integration-roadmap)
9. [Success Criteria](#9-success-criteria)
10. [Risk Register](#10-risk-register)

---

## 1. Executive Summary

Vector YouTube Gallery has reached an impressive technical milestone: a local-indexed YouTube gallery system with 8 layouts, 899 PHPUnit tests, OAuth + API key support, Elementor/Divi/Gutenberg integrations, and a 36-key card-settings system. The backend and data architecture are solid and production-grade.

**The frontend does not yet match the quality of that backend.** Despite having sophisticated CSS (line-clamp, aspect-ratio, object-fit cover, density presets, badge matrix, hover states), the live rendering at wpt.nsystems.live shows:

- **Long titles wrapping for 4+ lines** without truncation (line-clamp not taking effect in production)
- **Raw channel IDs** displayed instead of channel names (deferred Phase 13.2 metadata sync)
- **Duration badges** rendering as heavy black bars rather than clean pills
- **Thumbnails** that are repetitive, small, and don't fill cards with visual confidence
- **Cards too narrow** relative to available page width
- **Shorts layout** showing black letterboxing instead of properly cropped vertical thumbnails
- **No visual cohesion** across the 8 layouts — each feels like a separate experiment

This plan defines **5 phases (15–19)** to take Vector YouTube Gallery from functional prototype to a polished, Smash Balloon-quality frontend that is ready for production use on Stephen's live website, with a primary focus on Elementor compatibility.

---

## 2. How We Got Here — Evolution of the Plugin

### Phase 0–1: Foundation & API Connection (Complete)
- Plugin scaffold, PSR-4 autoloading, Docker dev environment
- YouTube API key client, channel/playlist resolution, mock client for dev
- Admin settings page, sources management, diagnostics

### Phase 2–5: Sync Engine & Data Layer (Complete)
- Database schema (9 tables), sync job runner, incremental sync
- Video normalizer, live status classifier, shorts classifier
- Caching layer, feed query, video metadata fetcher
- Quota tracker, retry policy, scheduler resolver

### Phase 6–8: Feeds, Rendering & Admin (Complete)
- Named feeds system, feed repository, admin feeds page
- Renderer, shortcode registrar, template loader
- Grid layout, load-more AJAX, lightbox JS
- Analytics, moderation, privacy/GDPR, importer/exporter

### Phase 9–12: Layouts, Integrations & Hardening (Complete)
- 8 layouts: grid, list, featured, hero, shorts, live, masonry, carousel
- Elementor widget, Divi module, Gutenberg block
- WooCommerce product link integration
- Multisite support, logging, log rotation
- Analytics retention, dashboard widget, system info

### Phase 13.1: Grid Layout Refinement (Complete)
- 24 commits on `main` (2026-06-30 to 2026-07-01)
- Shared CardRenderer + 36-key CardSettings system
- 5 card styles, 3 density presets, 5 thumbnail ratios
- 9 mode variants, all 8 layouts with templates/CSS/PHP classes

### Phase 14: Prototype Parity (Nearly Complete — 14.13 remaining)
- 14.0–14.12: 12 sub-phases shipped
  - Width modes, carousel dots, live pills, section heads
  - Play icon, thumbnail gradient overlay, per-video tone color
  - Per-channel avatar gradient, badge type × style matrix
  - Shared feed header, trust strip, relative countdown
  - Final parity contact-sheet capture
- 14.13: Close-out + resume note (pending)

### What Was Deferred (Phase 13.2–13.8)
These items were explicitly deferred to resume after Phase 14:

| Phase | Item | Impact on Frontend Polish |
|-------|------|---------------------------|
| 13.2 | Channel metadata sync (avatar, verified, subscriber count) | **CRITICAL** — currently shows raw channel ID |
| 13.4 | Internationalization pass | Low — English-only is acceptable for v1 |
| 13.5 | Interactive column switcher | Medium — nice-to-have for Elementor |
| 13.6 | Security audit | Required before production launch |
| 13.7 | Documentation set | Required for handoff |
| 13.8 | Final release candidate E2E | Required before production launch |
| 13.3 | Licensing / update-server abstraction | Low — self-hosted, not distributing yet |

---

## 3. Current State Assessment

### What's Working Well
- ✅ 8 layouts fully implemented with templates, CSS, and PHP layout classes
- ✅ 36-key card settings system with 6-layer precedence resolution
- ✅ CSS variable architecture with presets.css as the single override point
- ✅ `object-fit: cover` on thumbnails, `aspect-ratio` on media containers
- ✅ Line-clamp CSS classes defined (`--1`, `--2`, `--3`, `--unlimited`)
- ✅ Density presets (compact, comfortable, editorial)
- ✅ Badge type × style matrix (7 types × 4 styles = 28 combinations)
- ✅ Hover states with translateY lift and shadow elevation
- ✅ `prefers-reduced-motion` support
- ✅ Play icon overlay, thumbnail gradient overlay
- ✅ Per-video tone color and per-channel avatar gradient
- ✅ Elementor widget with feed selector, layout, columns, filter, sort
- ✅ Lightbox with 16:9 aspect-ratio frame
- ✅ Load-more AJAX pagination
- ✅ Schema.org JSON-LD structured data
- ✅ 899 PHPUnit tests, 2513 assertions

### What's Broken or Missing

#### Architectural Root Cause: Two Divergent Rendering Paths

The most critical finding from the code analysis is that **the shared `CardRenderer` + `video-card.php` partial is only used by the grid layout**. All other layouts (carousel, masonry, featured, hero, shorts, live, list) use **hand-rolled legacy markup** that bypasses the entire 36-key card settings system.

This means:
- Title line-clamp (`title_lines` setting) → **only works on grid**
- Configurable `thumbnail_ratio` → **only works on grid** (other layouts hardcode ratios in their CSS)
- `show_play_icon`, `thumbnail_overlay` → **only on grid**
- Per-video `--tone` color → **only on grid**
- Avatar gradient `--aa`/`--ab` → **only on grid**
- Badge type × style matrix → **only on grid**
- `data-vyg-lightbox` attribute → **missing on grid** (grid clicks go straight to YouTube, no lightbox)

This architectural split is the **root cause** of the inconsistent, unpolished feel across layouts. Any card-level improvement only lands on grid and must be separately re-implemented on every other layout.

#### Critical Issues (blocking production use)

1. **Title line-clamp broken across most layouts** — The shared partial (grid) correctly emits `vyg-card__title--{N}` classes, and live screenshots confirm grid titles ARE being clamped to 2 lines with ellipsis. However, the fix is fragile: `presets.css` loads AFTER `card.css` and uses higher-specificity selectors, and the theme's late-loading `global-styles-inline-css` sets `line-height: 1.2` on `h3` which can break the fragile `-webkit-box` model. Legacy templates (carousel, featured, hero, list-desktop) emit bare `<h3 class="vyg-card__title">` with **no line-clamp modifier** — these titles ARE unclamped. Masonry hardcodes 3-line clamp in its own CSS.

2. **`.vyg-feed img { height:auto }` out-specifics `.vyg-card__thumb { height:100% }`** — In `base.css`, the rule `.vyg-feed img { max-width:100%; height:auto; display:block }` has CSS specificity (0,1,1) which beats `.vyg-card__thumb { height:100% }` at (0,1,0). In legacy templates (carousel, masonry) where the thumb-wrap has no explicit aspect-ratio, this means thumbnails render at intrinsic height rather than filling the container. **This is why "thumbnails don't fill cards properly."**

3. **Carousel & masonry thumb-wrap has no aspect-ratio** — The legacy templates don't emit the `vyg-thumb-ratio--*` class, so the media container has no constrained dimensions. Combined with bug #2, thumbnails in these layouts collapse or render at intrinsic size.

4. **Raw channel ID displayed in List layout** — The list layout shows `UCETTSWoXxA-oEbwxqpbVf-w` instead of "The Way Of Holiness Broadcast". The grid layout (shared partial) correctly shows the channel name, but it's **truncated to "The Way Of Holiness Br..."** due to `white-space: nowrap; overflow: hidden; text-overflow: ellipsis` on `.vyg-card__channel-name`. This is the deferred Phase 13.2 (channel metadata sync) combined with a card-width issue.

5. **Shorts layout has massive black letterboxing** — Live screenshots confirm landscape (16:9) YouTube thumbnails placed inside portrait (9:16) card containers with large black empty areas. The `object-fit: cover` isn't taking effect due to the `height:auto` specificity bug (#2 above). Top-row cards appear almost entirely black with only duration badges visible.

6. **Masonry layout is broken** — Live screenshots show cards are clipped, text overflows card boundaries, the masonry flow is not working properly, and there's a large empty gap between the masonry grid and the footer. Cards appear squeezed into a small area rather than flowing naturally.

7. **Duration badge renders as a full-width black bar** — The CSS defines it as a pill (`border-radius: 999px`, positioned `bottom: 10px; right: 10px`), but in production it appears as a wide black strip. This may be a CSS load-order issue or a template rendering problem.

8. **Cards too narrow relative to page width** — The grid renders 3 columns in a narrow centered container, leaving large empty margins on both sides and excessive whitespace below the gallery before the footer. The width mode default should be `wide` (1180px) but may be defaulting to `theme` (880px) or the theme's content width is overriding it.

9. **Grid layout doesn't open lightbox** — The shared `video-card.php` partial omits `data-vyg-lightbox` on its `<a class="vyg-card__link">`, so grid clicks navigate directly to YouTube instead of opening the lightbox. Only legacy templates have the lightbox wiring.

10. **Inline `transform:scale()` on portrait thumbnails overrides hover zoom** — When `thumbnail_style_attr()` emits `transform:scale(1.45)` for portrait zoom, the inline style beats the `:hover` stylesheet rule `transform:scale(1.035)`, so hover zoom doesn't work on shorts/portrait cards.

11. **Thumbnails too short vertically** — Even on the grid layout where thumbnails fill the width, the thumbnail area appears shallow/compressed vertically. The 16:9 aspect ratio may not be taking effect properly, or the card body (title + channel + metadata) takes up too much relative space, making thumbnails feel like "shallow strips."

12. **Channel name truncation on grid** — "The Way Of Holiness Broadcast" is truncated to "The Way Of Holiness Br..." on grid cards. The `.vyg-card__channel-name` CSS uses `white-space: nowrap; overflow: hidden; text-overflow: ellipsis` which cuts the name at ~24 characters. For a single-channel gallery, this looks repetitive and broken.

#### Significant Issues (reducing polish)

6. **No channel avatar displayed** — `show_channel_avatar` defaults to `false`, and even when enabled, the `channel_avatar_url` column doesn't exist in the database yet (Phase 13.2 dependency). The avatar gradient fallback is in place but the setting is off.

7. **No verified badge** — `show_verified_badge` defaults to `false`. The infrastructure exists but is gated on Phase 13.2 metadata.

8. **Description hidden by default** — `show_description` defaults to `false`. For a teaching/ministry channel with long video titles, showing a 2-line description excerpt would significantly improve scannability.

9. **Status badges disabled** — `show_status_badge` defaults to `false`. For a channel with livestreams and replays, badges would add visual richness and help users identify content type.

10. **Actions disabled** — `show_actions` defaults to `false`. The three-dot menu and action buttons (Watch, YouTube, Share) are not shown.

11. **CTA hidden** — `show_cta` defaults to `'mapped_only'` but no product mappings exist, so CTA buttons never appear.

12. **Inconsistent spacing across layouts** — Grid/masonry feel cramped; carousel/live feel too sparse. The density presets exist but aren't tuned per-layout.

13. **No "View more on YouTube" header button** — The shared feed header supports it (`show_channel_cta`) but it defaults to `false`.

14. **Typography uses theme serif font** — The live screenshots show card titles in a serif font (inherited from the WordPress theme). The reference images all use clean sans-serif fonts. The plugin should ship a font-family override or at minimum use `font-family: inherit` with a fallback to system-ui sans-serif.

#### Enhancement Opportunities

15. **No skeleton/loading state** — When videos are loading, there's no visual placeholder. Smash Balloon shows a shimmer effect.

16. **No responsive column breakpoints beyond 600px and 380px** — The grid collapses to 2 columns at 600px and 1 column at 380px, but there's no tablet breakpoint (768px/1024px).

17. **Lightbox is basic** — It's a simple dark overlay with an iframe and close button. Smash Balloon's lightbox includes video title, description, share buttons, and navigation arrows.

18. **No filter/sort controls on the frontend** — The reference images show category tabs and sort dropdowns. The shortcode supports `orderby`/`order` but there's no interactive frontend filter UI.

19. **No hover zoom on thumbnails** — The CSS has `transform: scale(1.035)` on hover, but this is subtle. The reference images show more dramatic zoom effects.

20. **No "View all videos →" link** — The section head CSS exists but may not be wired to all layouts.

---

## 4. Smash Balloon Reference Research

*Based on research of smashballoon.com/youtube-feed/, /features/, /demo/, and Elementor integration pages.*

### Layouts
Smash Balloon YouTube Feed Pro offers 4 primary layouts:
- **Grid** — Responsive multi-column grid with customizable columns (1–6)
- **Gallery** — Masonry/waterfall layout with varied card heights
- **List** — Single-column horizontal cards (thumbnail left, content right)
- **Carousel** — Horizontal slider with navigation arrows and dots

Vector YouTube Gallery has 8 layouts (grid, list, featured, hero, shorts, live, masonry, carousel) — **already exceeding Smash Balloon's layout count**.

### Card Design
Smash Balloon's card characteristics:
- 16:9 thumbnail aspect ratio, `object-fit: cover`
- Title displayed below thumbnail, typically clamped to 2 lines
- Channel name with optional avatar
- Metadata: views, publish date, duration badge
- Duration badge: small dark pill in bottom-right of thumbnail
- Play button overlay on thumbnail hover
- Clean sans-serif typography (system font or Inter-like)
- Subtle box-shadow, rounded corners (8–12px)
- Hover: slight lift + stronger shadow

### Thumbnail Handling
- YouTube thumbnails are natively 16:9 (1280×720)
- Smash Balloon uses `object-fit: cover` to fill the card
- No empty/letterboxed thumbnails — the image always fills
- Duration overlay positioned bottom-right
- No visible thumbnail quality issues in their demos

### Title Truncation
- Smash Balloon clamps titles to 2 lines by default
- Uses CSS `-webkit-line-clamp` with ellipsis
- Configurable: users can set 1, 2, or 3 lines
- The truncation is clean and intentional-looking

### Header/Feed Customization
- Custom header text (title + subtitle)
- "View on YouTube" button in the header
- Customizable header background, padding, typography
- Optional channel avatar in header

### Lightbox
- Full-screen dark overlay
- 16:9 video iframe centered
- Video title visible in lightbox
- Close button (top-right)
- Optional: share buttons, video description
- Keyboard navigation (ESC to close, arrow keys for prev/next)

### Elementor Integration
- Native Elementor widget (works in Elementor Free and Pro)
- No Elementor Pro required
- Widget controls include: feed source, layout type, columns, items per page, header settings, customize settings
- Available in Elementor widget panel under "general" category
- Server-side rendering (no client-side API calls)
- Live preview in Elementor editor

### Load More
- AJAX-powered "Load More" button
- Customizable button text
- Loading spinner during fetch
- Appends new cards to existing grid without page refresh

### Responsive Behavior
- Configurable columns per breakpoint (desktop, tablet, mobile)
- Automatic column reduction on smaller screens
- Cards maintain consistent aspect ratio across breakpoints

### Color/Style Customization
- Customizable accent color
- Background color per feed
- Card background, border, shadow, border-radius
- Typography controls (font family, size, weight)
- Preset color schemes

### Key Differences: Smash Balloon vs. Vector YouTube Gallery

| Feature | Smash Balloon | VYG Current | Gap |
|---------|---------------|-------------|-----|
| Layouts | 4 (grid, gallery, list, carousel) | 8 (adds featured, hero, shorts, live, masonry) | **VYG exceeds** |
| Duration badges | **None visible** | Defined in CSS (renders as black bar due to bug) | **VYG has the feature, just needs the bug fix** |
| Title truncation | 2-line clamp, working | 2-line clamp defined, working on grid only | Must fix for all layouts |
| Channel avatar | Shows real avatar in Gallery header | Gradient fallback only (Phase 13.2 needed) | Must implement |
| Channel name | Shows real name | Shows raw channel ID on list; truncated on grid | Must fix |
| Play button | Dark rounded overlay, white triangle | 58px white circle with ▶ | **VYG already matches** |
| Lazy-loading facade | YouTube player loads on click only | Lightbox iframe on click | **VYG already does this** |
| Typography | Inherits theme fonts | Inherits theme fonts (currently serif) | Both inherit — VYG should override to sans-serif |
| Card styling | Subtle shadow, 4-8px radius, no border | 18px radius, layered shadow | VYG is more dramatic; both valid |
| Metadata format | "Channel • views • Date" with • separators | "views · Date" with · separators | VYG format is fine; could add channel to metadata |
| Channel header | Gallery layout: avatar + name + subs + Subscribe button | Shared feed header (kicker + h1 + intro + pill) | VYG lacks channel avatar/subscriber/subscribe in header |
| Subscribe button | Links to YouTube `?sub_confirmation=1` | Not implemented | Should add |
| Live stream indicator | Red "🔴 Live" badge in title | Badge matrix (live, upcoming, replay, etc.) | **VYG exceeds** (more badge types) |
| Elementor widget | Feed selector only — all styling in customizer | Feed selector + basic layout controls | VYG should add full card controls (Phase 18) |
| Custom video pause/end actions | Configurable CTA on video end | Not implemented | Future enhancement |
| SEO (captions in HTML) | Embeds YouTube captions | Schema.org JSON-LD only | Future enhancement |
| Custom post types | Converts videos to WP posts | Not implemented | Future enhancement |
| Price | $49–$199/year | Free, self-hosted | **VYG advantage** |
| Privacy | API calls on render | No API calls on render | **VYG advantage** |
| Performance | Good (but lazy-loads player) | Excellent (local DB only, lightbox on click) | **VYG advantage** |
| GDPR compliance | 1-click GDPR mode | Compliance/disconnect manager | **VYG already has this** |

### Key Takeaways from Smash Balloon Research

1. **VYG already exceeds Smash Balloon** in layout count (8 vs 4), badge system (7 types × 4 styles vs none), and privacy (no API calls on render)

2. **Smash Balloon has NO duration badges** — VYG already has this feature, just needs the CSS bug fixed to render as a proper pill instead of a black bar

3. **Smash Balloon's Elementor widget is simpler** than VYG's target — it's just a feed selector with all styling done in a separate customizer. VYG's plan to put full card controls directly in Elementor is actually **more advanced** than Smash Balloon's approach

4. **Gallery layout with channel header** is Smash Balloon's standout feature — avatar + channel name + subscriber count + Subscribe button. VYG's featured/hero layouts are structurally similar but lack the channel identity header. This should be added to Phase 16 or 17.

5. **Lazy-loading facade** — Smash Balloon loads thumbnails as images and only loads the YouTube player iframe when clicked. VYG already does this via the lightbox pattern. No work needed.

6. **Theme font inheritance** — Smash Balloon explicitly inherits theme fonts. This is a valid approach, but Stephen's theme uses serif fonts which look wrong for video cards. The plan should include a sans-serif override option (Phase 16.1) while keeping theme inheritance as a fallback.

7. **Custom video pause/end actions** — showing related videos or a CTA when a video ends. This is a future enhancement opportunity for VYG.

---

## 5. Reference Image Analysis — The Visual Target

11 reference images were analyzed in detail. They represent the visual quality Stephen expects. Key patterns across all references:

### Card Anatomy (Target)
```
┌─────────────────────────────────┐
│  [FEATURED badge]    [duration]│
│                                 │
│         THUMBNAIL (16:9)        │
│      [play button center]       │
│                    [12:45] ──── │
├─────────────────────────────────┤
│  Exploring the Canadian Rockies │  ← Title (bold, 2-line clamp, sans-serif)
│  (4K Adventure)                 │
│  ⊙ Wander More ✓                │  ← Channel row (avatar + name + verified)
│  👁 125K views  ·  📅 2 days ago│  ← Metadata (icons + muted text)
│  Join me on an unforgettable... │  ← Description (2-line clamp, muted)
│  ────────────────────────────── │  ← Divider
│  [🛒 View Product]    [▶] [♡] [⋮]│  ← CTA + actions
└─────────────────────────────────┘
```

### Visual Design Language (Target)
- **Background:** Very light gray / off-white (`#F8FAFC` or similar)
- **Cards:** Pure white (`#FFFFFF`), rounded corners (10–18px)
- **Card shadow:** Soft, subtle — `0 4px 16px rgba(15, 23, 42, 0.06)`
- **Card border:** Very light gray (`#E5E7EB`) or none (shadow-only)
- **Text primary:** Dark navy / near-black (`#0F172A` or `#111827`)
- **Text secondary:** Muted slate gray (`#64748B` or `#667085`)
- **Accent:** Purple/indigo (`#7C3AED` or `#6D28D9`) for badges, CTAs, active states
- **YouTube red:** Used sparingly for YouTube logo, live badges, cart icons
- **Duration badge:** Dark translucent (`rgba(2, 6, 23, 0.82)`), white text, pill shape
- **Typography:** Modern sans-serif (Inter, system-ui, SF Pro)
- **Title:** Bold (700–880 weight), 15–17px on cards, 2-line clamp
- **Metadata:** Regular weight, 13–14px, muted color
- **Spacing:** Generous — 16–20px card padding, 20–24px grid gap

### Layout-Specific Targets

#### Grid (Primary Layout)
- 3–4 columns on desktop, 2 on tablet, 1 on mobile
- Cards: white, rounded, subtle shadow
- Thumbnails: full-width 16:9, `object-fit: cover`
- Title: bold, 2-line clamp, sans-serif
- Channel row: avatar (24–28px) + name + verified check
- Metadata: "125K views · 2 days ago" with icons
- Duration badge: bottom-right pill
- Play icon: centered, 58px white circle
- Hover: card lifts 3px, shadow strengthens, thumbnail zooms 4%

#### List
- Single column, horizontal cards
- Thumbnail: 42% width on left, 16:9
- Content: right side with title, channel, metadata, description
- CTA + actions on right
- Divider between rows

#### Featured
- Large 2-column hero (thumbnail left, content right)
- Supporting grid of 5–6 cards below
- "More Videos" section header with "View all →" link

#### Hero
- Similar to featured but with richer metadata
- Subscriber count, description excerpt
- Product CTA card

#### Shorts
- 9:16 vertical thumbnails
- 4–5 columns on desktop
- Compact metadata
- Duration badge top-right

#### Live
- 3 sections: Live Now (2 large cards), Upcoming (3 medium), Replay (4 compact)
- Section headers with colored pill badges (red/purple/blue)
- Live: "Watch Live" button, viewer count overlay
- Upcoming: "Set Reminder" button, date/time badge
- Replay: duration badge, "Streamed X days ago · YK views"

#### Carousel
- Horizontal scroll with snap
- 3–5 visible cards
- Active/center card: purple border, elevated
- Navigation arrows (circular, white, purple arrows)
- Dot pagination below
- Keyboard navigation hints

#### Masonry
- CSS column-count layout
- Varied card heights (some 16:9, some 4:3, some 1:1)
- Clean waterfall flow
- Consistent gutters

---

## 6. Gap Analysis — Current vs. Target

### Priority 1: Critical Bugs (Must Fix First)

| # | Gap | Root Cause | Impact |
|---|-----|------------|--------|
| G1 | Title line-clamp not working in production | CSS specificity/load-order conflict with theme | Long titles break card layout, inconsistent heights, unprofessional appearance |
| G2 | Raw channel ID shown instead of name | Phase 13.2 deferred — `channel_name` not populated in `vyg_videos` | Technical ID visible to visitors, looks broken |
| G3 | Duration badge renders as black bar | CSS conflict or template issue | Thumbnails look crude and heavy |
| G4 | Shorts thumbnails show massive black letterboxing | `object-fit: cover` not applying due to `.vyg-feed img { height:auto }` specificity bug | Shorts layout unusable — top cards almost entirely black |
| G5 | Cards too narrow / excessive page margins | Width mode defaulting to `theme` or theme overriding `--vyg-max` | Grid looks cramped, wasted space |
| G6 | Masonry layout broken — cards clipped, text overflow | Legacy masonry template not using shared CardRenderer; CSS column-count layout has height calculation issues | Masonry layout unusable |
| G7 | Channel name truncated on grid | `.vyg-card__channel-name { white-space: nowrap }` cuts "The Way Of Holiness Broadcast" to "Br..." | Looks repetitive and broken on every card |
| G8 | Thumbnails too short vertically on grid | 16:9 aspect-ratio may not be taking effect, or card body dominates | Thumbnails feel like shallow strips, not full video previews |

### Priority 2: Visual Polish (Must Fix for Production)

| # | Gap | Root Cause | Impact |
|---|-----|------------|--------|
| G6 | Typography inherits theme serif font | No `font-family` override in plugin CSS | Cards look inconsistent with reference designs |
| G7 | No channel avatar | `show_channel_avatar=false` default + no avatar data | Missing visual identity, less YouTube-like |
| G8 | No verified badge | `show_verified_badge=false` default | Missing credibility signal |
| G9 | No description excerpt | `show_description=false` default | Cards are thin, lack context for long-titled ministry videos |
| G10 | No status badges | `show_status_badge=false` default | Live/replay/featured content not visually distinguished |
| G11 | Inconsistent spacing across layouts | Density presets not tuned per layout | Some cramped, some sparse |
| G12 | No "View on YouTube" header button | `show_channel_cta=false` default | Missing key navigation affordance |

### Priority 3: Enhancement (Should Fix for v1 Release)

| # | Gap | Root Cause | Impact |
|---|-----|------------|--------|
| G13 | Basic lightbox (no title, share, navigation) | Minimal implementation | Below Smash Balloon quality |
| G14 | No skeleton loading state | Not implemented | Flash of empty content during load |
| G15 | No tablet breakpoint (768px/1024px) | Only 600px and 380px breakpoints | Grid looks wrong on tablets |
| G16 | No frontend filter/sort controls | Not implemented | Less interactive than Smash Balloon |
| G17 | Hover zoom too subtle | `scale(1.035)` vs reference's `scale(1.04)` | Cards feel less tactile |
| G18 | No thumbnail fallback for missing images | Not implemented | Empty thumbnails look broken |

### Priority 4: Future Enhancement

| # | Gap | Impact |
|---|-----|--------|
| G19 | No interactive column switcher on frontend | Nice-to-have, not critical |
| G20 | No video count / pagination info text | Minor UX nicety |
| G21 | No custom CSS per-feed override UI | Power user feature |
| G22 | No i18n | English-only is acceptable for v1 |

---

## 7. Phased Implementation Plan

### Phase 15: Critical Frontend Bug Fixes + Architectural Unification

> **Goal:** Fix the critical bugs AND unify all layouts to use the shared CardRenderer so card settings work everywhere.
> **Validation:** Live screenshots at wpt.nsystems.live showing real Way of Holiness videos with proper title truncation, channel names, duration pills, filled thumbnails, and appropriate card width across ALL 8 layouts.

#### 15.0 — Unify all layouts to use the shared CardRenderer (PREREQUISITE)

**Problem:** The shared `CardRenderer` + `video-card.php` partial is only used by the grid layout. All other 7 layouts (carousel, masonry, featured, hero, shorts, live, list) use hand-rolled legacy markup that bypasses the 36-key card settings system. This means title line-clamp, thumbnail ratio, play icon, overlay, tone color, avatar gradient, badge matrix, and lightbox wiring only work on grid.

**This is the single most impactful change.** Without it, every fix must be applied separately to each layout template. With it, all card-level fixes land on all 8 layouts at once.

**Implementation:**
1. Migrate `carousel.php` to call `CardRenderer::render()` for each card
2. Migrate `masonry.php` to call `CardRenderer::render()` for each card
3. Migrate `featured.php` to call `CardRenderer::render()` for both the featured card and the supporting grid cards (with role variants: `hero-primary` for featured, `standard` for supporting)
4. Migrate `hero.php` similarly (role: `hero-primary` for main, `hero-secondary` for supporting)
5. Migrate `shorts.php` to call `CardRenderer::render()` with mode `short-vertical`
6. Migrate `live.php` to call `CardRenderer::render()` for live/upcoming/replay cards (mode: `live-status`)
7. Migrate `list.php` to call `CardRenderer::render()` with mode `media-row`
8. Each layout template keeps its **wrapper** markup (grid container, carousel track, masonry columns, section headers) but delegates **card rendering** to the shared partial
9. Update each layout's CSS to work with the shared card's BEM classes instead of the old layout-specific card classes
10. **Remove** the old hand-rolled card markup from each layout template

**Risk mitigation:**
- Each layout migration is a discrete commit (8 commits)
- PHPUnit tests must pass after each migration
- Visual screenshots must match the prototype after each migration
- If a layout migration breaks, revert that one commit and continue with the others

**Test:** All 8 layouts render with proper card classes, line-clamp, thumbnail ratio, play icon, tone color, and lightbox wiring.

#### 15.1 — Fix CSS specificity bugs (`.vyg-feed img` vs `.vyg-card__thumb`)

**Problem:** `.vyg-feed img { height:auto }` (specificity 0,1,1) overrides `.vyg-card__thumb { height:100% }` (0,1,0), causing thumbnails to render at intrinsic height instead of filling the container.

**Fix:**
```css
/* In card.css, increase specificity: */
.vyg-feed .vyg-card__thumb {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
```
Or alternatively, scope the `height:auto` rule to exclude card thumbs:
```css
/* In base.css, change: */
.vyg-feed img:not(.vyg-card__thumb) {
    max-width: 100%;
    height: auto;
    display: block;
}
```

**Also fix:** The inline `transform:scale()` on portrait thumbnails overrides hover zoom. Move the portrait zoom to a CSS class instead of inline style, so `:hover` can override it.

#### 15.2 — Fix title line-clamp on all layouts

**Problem:** After 15.0, all layouts use the shared partial which correctly emits `vyg-card__title--{N}` classes. But the line-clamp doesn't work in production due to a **CSS load-order problem**.

**Root cause (confirmed by live-site investigation):**
1. The PHP pipeline is correct — `title_lines` is properly resolved and `vyg-card__title--2` IS present in the live HTML
2. The CSS in `card.css` is correct — defines `display:-webkit-box; -webkit-box-orient:vertical; overflow:hidden` and `-webkit-line-clamp:2`
3. **BUT `presets.css` loads AFTER `card.css`** (confirmed: card-css at position ~13450, presets-css at ~13772). `presets.css` uses higher-specificity selectors (`.vyg-feed .vyg-card__title` at specificity 0,2,0) which override the card.css rules (`.vyg-card__title` at 0,1,0)
4. The theme's `global-styles-inline-css` loads even later (~30624) and sets `line-height: 1.2` on `h3` — `-webkit-line-clamp` is notoriously fragile and even a `line-height` override from a later stylesheet can break the box model's line count calculation
5. WooCommerce CSS loads at ~54043, adding more late cascade pressure

**Fix approach (3 layers):**

1. **Fix CSS load order:** In `AssetManager::enqueue_card_assets()`, set `vyg-presets` as a dependency of `vyg-card` so `card.css` loads AFTER `presets.css`. This ensures the card.css line-clamp rules aren't overridden by later-loading preset rules.

2. **Increase specificity of line-clamp rules:** Change card.css to use the same descendant selector pattern as presets.css:
```css
.vyg-feed .vyg-card__title {
    display: -webkit-box;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.vyg-feed .vyg-card__title--1 { -webkit-line-clamp: 1; }
.vyg-feed .vyg-card__title--2 { -webkit-line-clamp: 2; }
.vyg-feed .vyg-card__title--3 { -webkit-line-clamp: 3; }
```
This gives specificity (0,2,0) + (0,3,0) for the modifier — matching or beating presets.css.

3. **Defensive `!important` on the box-orient property:** As a last resort against theme overrides, add `!important` only to the two properties that MUST be preserved for line-clamp to work:
```css
.vyg-feed .vyg-card__title {
    display: -webkit-box !important;
    -webkit-box-orient: vertical !important;
}
```
Use `!important` sparingly — only on these two properties, not on line-clamp itself or overflow.

4. **Add modern `line-clamp` alongside `-webkit-line-clamp`:** For future-proofing:
```css
.vyg-feed .vyg-card__title--2 {
    -webkit-line-clamp: 2;
    line-clamp: 2;
}
```

**Test:** Render grid layout on live site with long Way of Holiness titles, verify titles are clamped to 2 lines with ellipsis. Check in DevTools that no later-loading stylesheet overrides `display` or `-webkit-box-orient`.

#### 15.3 — Fix channel name display (Phase 13.2 quick fix)

**Problem:** `channel_name` not populated in `vyg_videos` table.

**Quick fix (no schema change):** JOIN `vyg_sources` in `FeedQuery` so channel name is available at render time. The `FeedQuery` already has the source UUID — it can JOIN to get `channel_name` from `vyg_sources`.

**Full fix (Phase 17):** Add `channel_name` column to `vyg_videos`, populate during sync, backfill existing rows.

#### 15.4 — Fix duration badge rendering

**Problem:** Duration badge appears as a full-width black bar instead of a compact pill.

**Fix:**
- Verify the shared partial emits correct classes: `vyg-card__duration vyg-card__duration--{position}`
- Ensure sufficient CSS specificity for `position: absolute; border-radius: 999px; padding: 0.28rem 0.48rem`
- If theme overrides: scope with `.vyg-feed .vyg-card__duration`

#### 15.5 — Fix Shorts thumbnail letterboxing

**Problem:** After 15.0 + 15.1, shorts layout should use the shared partial with `vyg-thumb-ratio--9-16` class. The `object-fit: cover` fix from 15.1 should resolve the letterboxing. Live screenshots confirm the current state shows massive black areas — top-row cards are almost entirely black with only duration badges visible.

**Additional fix:** Ensure the 9:16 ratio class is properly applied and the image fills it. YouTube thumbnails are natively 16:9; `object-fit: cover` on a 9:16 container will crop the sides to fill the vertical frame — this is correct behavior. The key is ensuring `height: 100%` actually applies (fixed by 15.1 specificity fix).

#### 15.5a — Fix masonry layout

**Problem:** Live screenshots show the masonry layout is broken — cards are clipped, text overflows card boundaries, and there's a large empty gap. The CSS `column-count` masonry layout has height calculation issues.

**Fix:** After 15.0 migration to shared CardRenderer, the masonry layout should work better because all cards will have proper aspect-ratio constraints. Additionally:
- Ensure `break-inside: avoid` is set on masonry cards
- Verify the `column-count` CSS isn't being overridden
- Check that the masonry container doesn't have a fixed height or `overflow: hidden` that clips cards

#### 15.5b — Fix channel name truncation on grid

**Problem:** "The Way Of Holiness Broadcast" is truncated to "The Way Of Holiness Br..." on grid cards because `.vyg-card__channel-name` uses `white-space: nowrap; overflow: hidden; text-overflow: ellipsis`.

**Fix:** For single-channel galleries, the channel name is the same on every card. Options:
1. Allow channel name to wrap to 2 lines (remove `white-space: nowrap`)
2. Increase the truncation threshold (show more characters before ellipsis)
3. Hide the channel name entirely when all videos are from the same channel (add a `show_channel_when_multiple` setting)
4. **Recommended:** Allow 2-line wrap for channel name, since it's important metadata and shouldn't be aggressively truncated

#### 15.6 — Fix card width / page margin issue

**Problem:** Gallery appears too narrow with excessive margins.

**Fix:**
- Default `width` to `wide` (1180px) in shortcode defaults and Elementor widget
- For Elementor canvas pages: recommend `width="full"`
- Verify `.vyg-feed.vyg-wide { width: min(100%, var(--vyg-max)); margin-inline: auto; }` is not overridden by theme

#### 15.7 — Wire lightbox on shared partial

**Problem:** `video-card.php` doesn't emit `data-vyg-lightbox` on the thumbnail link.

**Fix:** Add `data-vyg-lightbox` attribute to the `<a class="vyg-card__link">` in the shared partial when the lightbox is enabled (which it is by default — the lightbox JS listens for this attribute).

**Test:** Click a grid card thumbnail → lightbox opens with embedded video.

---

### Phase 16: Visual Polish — Typography, Defaults, and Spacing

> **Goal:** Make the gallery look intentional and polished, matching the reference image visual language.
> **Validation:** Side-by-side comparison of live screenshots with reference images.

#### 16.1 — Enforce sans-serif typography

**Current:** Card titles inherit the theme's serif font.
**Target:** Clean sans-serif (Inter / system-ui / -apple-system / Segoe UI).

**Implementation:**
```css
.vyg-feed {
    font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}
.vyg-card__title {
    font-family: inherit; /* inherits from .vyg-feed */
}
```

Consider bundling Inter as a web font (or using the WordPress default system font stack to avoid external requests).

#### 16.2 — Enable sensible card defaults for production

Change these defaults in `CardSettings::defaults()`:

| Setting | Current Default | Proposed Default | Rationale |
|--------|----------------|------------------|-----------|
| `show_description` | `false` | `true` | Ministry videos have long titles; 2-line excerpt adds context |
| `description_lines` | `2` | `2` | Keep as-is |
| `show_status_badge` | `false` | `true` | Channel has livestreams and replays |
| `show_channel_avatar` | `false` | `true` | Adds visual identity (Phase 13.2 needed for real avatars; gradient fallback works now) |
| `show_verified_badge` | `false` | `false` | Keep off until Phase 13.2 (no real verified data) |
| `show_channel_cta` | `false` | `true` | "View on YouTube" button is a key navigation affordance |
| `feed_cta_label` | `''` | `'Watch on YouTube →'` | Default label for the header CTA |

#### 16.3 — Tune density presets per layout

Currently, the 3 density presets (compact, comfortable, editorial) apply the same spacing regardless of layout. Some layouts need different defaults:

| Layout | Recommended Default Density | Rationale |
|--------|---------------------------|-----------|
| Grid | comfortable | Balanced — matches reference |
| List | comfortable | Needs room for description |
| Featured | editorial | Hero needs more space |
| Hero | editorial | Hero needs more space |
| Shorts | compact | Vertical cards should be tight |
| Live | comfortable | Live cards need emphasis |
| Masonry | comfortable | Balanced waterfall |
| Carousel | comfortable | Cards in scroll need room |

**Implementation:** Update `CardProfiles::get()` to set layout-specific density defaults.

#### 16.4 — Add tablet breakpoint (768px / 1024px)

**Current breakpoints:** 600px (→ 2 cols) and 380px (→ 1 col).
**Add:** 1024px (→ reduce by 1 col) and 768px (→ reduce by 2 cols).

```css
@media (max-width: 1024px) {
    .vyg-feed--grid.vyg-grid--cols-5 .vyg-grid__cards,
    .vyg-feed--grid.vyg-grid--cols-6 .vyg-grid__cards {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }
}
@media (max-width: 768px) {
    .vyg-feed--grid.vyg-grid--cols-4 .vyg-grid__cards,
    .vyg-feed--grid.vyg-grid--cols-5 .vyg-grid__cards,
    .vyg-feed--grid.vyg-grid--cols-6 .vyg-grid__cards {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
```

#### 16.5 — Enhance hover states

**Current:** `translateY(-3px)` + shadow + `scale(1.035)` on thumbnail.
**Target:** More dramatic, tactile:
- Card: `translateY(-4px)` + stronger shadow
- Thumbnail: `scale(1.06)` + `saturate(1.1) contrast(1.04)`
- Play icon: `scale(1.12)` with faster transition
- Title link: color shift to accent on hover

#### 16.6 — Add thumbnail fallback for missing images

When `thumb_url` is empty or the image fails to load:
- Show a branded placeholder with the channel gradient (using `--tone` and `--aa`/`--ab`)
- Display the video title as text inside the placeholder
- This replaces the current behavior of showing an empty/black thumbnail

**Implementation:** In `video-card.php`, add an `onerror` handler on the `<img>` that hides the broken image and shows a fallback `<div>` with the gradient + title text.

#### 16.7 — Add channel header to featured/hero layouts (Smash Balloon Gallery parity)

Smash Balloon's Gallery layout includes a **channel header** with:
- Channel avatar image
- Channel name as a heading
- Subscriber count (e.g., "26.2M subscribers")
- Subscribe button linking to `https://youtube.com/channel/{id}?sub_confirmation=1`

VYG's featured and hero layouts are structurally similar to Smash Balloon's Gallery but lack the channel identity header. This is a high-impact feature for a ministry channel.

**Implementation:**
1. Add a `channel_header` partial that renders above the featured/hero video
2. Include: avatar (or gradient fallback), channel name, subscriber count (when available from Phase 17), and a "Subscribe" button
3. The Subscribe button links to `https://youtube.com/channel/{channel_id}?sub_confirmation=1&feature=subscribe-embed-click`
4. Add a `show_channel_header` card setting (default `true` for featured/hero layouts, `false` for others)
5. Style to match the reference images: circular avatar, bold channel name, muted subscriber count, red/branded subscribe button

---

### Phase 17: Channel Metadata Sync (Phase 13.2 Unblock)

> **Goal:** Populate real channel names, avatars, verified badges, and subscriber counts.
> **Dependency:** This was the deferred Phase 13.2 from the archived checklist.

#### 17.1 — Extend `vyg_videos` schema for channel metadata

Add columns to `vyg_videos`:
- `channel_name` VARCHAR(255) — cached from source
- `channel_avatar_url` VARCHAR(500) — YouTube channel avatar
- `channel_verified` TINYINT(1) — verified flag
- `channel_subscriber_count` BIGINT — subscriber count

#### 17.2 — Populate channel metadata during sync

Update `VideoNormalizer::normalize()` to include channel metadata fields from the YouTube API response (or from the source record).

#### 17.3 — Backfill existing videos

Run a one-time migration that joins `vyg_videos` to `vyg_sources` and backfills `channel_name` for all existing rows. For avatar/verified/subscriber count, fetch from YouTube API during the next metadata refresh cycle.

#### 17.4 — Enable avatar and verified badge display

After metadata is populated:
- Set `show_channel_avatar` default to `true`
- Set `show_verified_badge` default to `true` (only renders when `channel_verified` is truthy)
- Verify the avatar gradient fallback still works when avatar URL is empty

---

### Phase 18: Elementor Widget Enhancement

> **Goal:** Make the Elementor widget as feature-rich as Smash Balloon's, with all card settings exposed as controls.
> **Primary focus:** Stephen uses Elementor extensively.

#### 18.1 — Add card design controls to Elementor widget

Currently the Elementor widget has: feed, layout, columns, per_page, content_type, orderby, order, pagination, preset, schema_enabled, wrapper_id.

**Add a "Card Design" tab with:**

| Control | Type | Options |
|---------|------|---------|
| Card style | SELECT | bordered, elevated, flat, minimal, commerce |
| Density | SELECT | compact, comfortable, editorial |
| Card radius | SLIDER | 0–34px |
| Thumbnail ratio | SELECT | 16:9, 4:3, 1:1, 9:16, auto |
| Title lines | SELECT | 1, 2, 3, unlimited |
| Show thumbnail | SWITCHER | on/off |
| Show duration | SWITCHER | on/off |
| Show title | SWITCHER | on/off |
| Show channel | SWITCHER | on/off |
| Show channel avatar | SWITCHER | on/off |
| Show verified badge | SWITCHER | on/off |
| Show metadata | SWITCHER | on/off |
| Show description | SWITCHER | on/off |
| Description lines | SELECT | 1, 2, 3 |
| Show status badge | SWITCHER | on/off |
| Badge style | SELECT | solid, soft, outline, minimal |
| Show play icon | SWITCHER | on/off |
| Thumbnail overlay | SWITCHER | on/off |
| Show CTA | SELECT | always, mapped_only, hidden |
| CTA label | TEXT | "View Product" |
| CTA style | SELECT | primary, secondary, outline, ghost, icon_only |
| Show actions | SWITCHER | on/off |
| Width mode | SELECT | theme, wide, full |

#### 18.2 — Add header controls to Elementor widget

**Add a "Header" tab with:**

| Control | Type | Options |
|---------|------|---------|
| Feed title | TEXT | Custom title (auto-filled with layout name) |
| Feed kicker | TEXT | Eyebrow text (e.g. "Vector YouTube Gallery") |
| Feed intro | TEXTAREA | Intro paragraph |
| Show header | SWITCHER | on/off |
| Show kicker | SWITCHER | on/off |
| Show H1 | SWITCHER | on/off |
| Show layout pill | SWITCHER | on/off |
| Show "View on YouTube" button | SWITCHER | on/off |
| YouTube button label | TEXT | "Watch on YouTube →" |
| YouTube button URL | TEXT | Channel URL (auto-filled from source) |

#### 18.3 — Add "Load More" controls to Elementor widget

| Control | Type | Options |
|---------|------|---------|
| Enable load more | SWITCHER | on/off |
| Button text | TEXT | "Load More Videos" |
| Items per page | NUMBER | 12 |

#### 18.4 — Wire Elementor controls to CardSettings::resolve()

The Elementor widget's `render()` method currently passes only structural args. It needs to:
1. Read all card-system controls from `$settings`
2. Pass them as `$args` to `Renderer::render()`
3. The renderer already resolves them through `CardSettings::resolve()` via the `inline` layer

#### 18.5 — Improve Elementor editor preview

The current `content_template()` shows a static placeholder. Improve it to:
- Show a simplified card layout (static HTML, no API calls)
- Display the selected layout name and column count
- Show a visual representation of the card style
- Include a "Preview renders on the live page" hint

---

### Phase 19: Lightbox Enhancement & Production Readiness

> **Goal:** Bring the lightbox to Smash Balloon quality and do final polish.

#### 19.1 — Enhance lightbox

**Current:** Dark overlay + iframe + close button.
**Add:**
- Video title displayed at top of lightbox
- Channel name with avatar
- View count and publish date
- "Watch on YouTube" external link
- "Share" button (copy URL)
- Close on ESC key (already implemented?)
- Keyboard navigation between videos in the feed (left/right arrows)
- Loading spinner before iframe loads
- Fade-in transition

#### 19.2 — Add skeleton loading state

When the feed is loading (AJAX load-more or initial render):
- Show shimmer placeholder cards with the same dimensions as real cards
- Use a subtle CSS animation (pulse/shimmer)
- Replace with real content when loaded

#### 19.3 — Add "View all videos" link to all layouts

Wire the `see_all_url` and `see_all_label` shortcode attributes to the section head partial on all layouts (not just featured/hero).

#### 19.4 — Final responsive audit

- Test all 8 layouts at 320px, 375px, 414px, 768px, 1024px, 1280px, 1440px, 1920px
- Verify no horizontal scroll at any breakpoint
- Verify cards are never too narrow (< 200px) or too wide (> 500px)
- Verify thumbnails fill cards at all sizes
- Verify touch targets are ≥ 44px

#### 19.5 — Cross-theme compatibility audit

- Test with Blocksy, Astra, GeneratePress, Twenty Twenty-Four, Twenty Twenty-Three
- Verify line-clamp works across all themes
- Verify duration badge renders as pill across all themes
- Verify `object-fit: cover` works across all themes
- Document any theme-specific CSS conflicts and add defensive overrides

---

## 8. Elementor Integration Roadmap

### Current State
The Elementor widget exists and works but has minimal controls:
- Source section: feed selector, source UUID
- Layout section: layout, columns, per_page, wrapper_id
- Filter section: content_type, orderby, order, pagination
- Style section: preset, schema_enabled

### Target State (after Phase 18)
The Elementor widget will have 5 tabs:
1. **Source** — feed selector, source UUID (unchanged)
2. **Layout** — layout, columns, per_page, width mode, wrapper_id
3. **Card Design** — all 36 card settings as visual controls
4. **Header** — feed title, kicker, intro, channel CTA button
5. **Filter & Sort** — content_type, orderby, order, pagination, load_more

### Elementor-Specific Considerations

1. **Canvas pages:** When using Elementor's "Canvas" page template (no header/footer), the gallery should use `width="full"` to fill the entire viewport.

2. **Section width:** When placed inside an Elementor section, the gallery should respect the section's width. The `width="theme"` option should match the theme's content width.

3. **Responsive controls:** Elementor has responsive control tabs (desktop/tablet/mobile). The widget should support different column counts per device.

4. **Live preview:** The `content_template()` should show a visual card preview, not just text. Consider rendering a static HTML card that matches the selected style.

5. **No Elementor Pro required:** All controls should work in Elementor Free. No Pro-only features (like Theme Builder or dynamic tags).

---

## 9. Success Criteria

### Phase 15 (Critical Bug Fixes) — Complete when:
- [ ] Long titles on Way of Holiness videos are clamped to 2 lines with ellipsis
- [ ] Channel name "The Way Of Holiness Broadcast" appears on all cards (not UC... ID)
- [ ] Duration badge appears as a small dark pill in bottom-right of thumbnails
- [ ] Shorts layout thumbnails fill vertical containers without black bars
- [ ] Grid layout uses appropriate width (not cramped with huge margins)
- [ ] Screenshots captured at desktop + mobile for all 8 layouts on live site

### Phase 16 (Visual Polish) — Complete when:
- [ ] Card titles use clean sans-serif font on live site
- [ ] Description excerpts (2 lines) appear on cards by default
- [ ] Status badges appear on live/replay/featured content
- [ ] Channel avatar gradient appears (real avatar when Phase 17 lands)
- [ ] "View on YouTube" button appears in feed header
- [ ] Hover states feel tactile (card lifts, thumbnail zooms, play icon scales)
- [ ] Tablet breakpoint prevents 4+ column grids on medium screens
- [ ] Missing thumbnails show branded fallback, not black/empty

### Phase 17 (Channel Metadata) — Complete when:
- [ ] Real channel avatar images load in card channel rows
- [ ] Verified badge appears if channel is verified
- [ ] Subscriber count available in metadata (if enabled)
- [ ] All existing videos have channel_name populated

### Phase 18 (Elementor Enhancement) — Complete when:
- [ ] Elementor widget has Card Design tab with all major card settings
- [ ] Elementor widget has Header tab with feed title/kicker/intro/CTA controls
- [ ] Changes in Elementor editor are reflected on live page after save
- [ ] Widget works in Elementor Free (no Pro required)
- [ ] Editor preview shows visual representation of card style

### Phase 19 (Lightbox & Production Readiness) — Complete when:
- [ ] Lightbox shows video title, channel, metadata
- [ ] Lightbox has "Watch on YouTube" and "Share" buttons
- [ ] Keyboard navigation works (ESC, arrow keys)
- [ ] Skeleton loading state appears during AJAX load-more
- [ ] All 8 layouts verified at 7 viewport widths
- [ ] Plugin tested with at least 3 popular themes
- [ ] No horizontal scroll at any breakpoint

---

## 10. Risk Register

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Theme CSS overrides are difficult to fix without `!important` | High | Medium | Use targeted `!important` only on `-webkit-box`, `-webkit-box-orient`, `object-fit`, `position: absolute` — the minimum set for card integrity |
| Phase 13.2 schema migration fails on some installs | Low | High | Test migration on fresh install + existing data; provide rollback SQL |
| Inter web font adds render-blocking request | Medium | Low | Use `font-display: swap` or rely on system font stack; Inter is optional |
| Elementor API changes break widget | Low | Medium | Test against current Elementor version; pin min version requirement |
| YouTube thumbnail quality for Way of Holiness is low/inconsistent | Medium | Medium | Implement branded fallback; consider thumbnail override feature (already exists in CardSettings) |
| Live site (wpt.nsystems.live) Docker container restart loses changes | Low | High | All changes committed to git; Docker volume persists DB; verify after restart |

---

## Appendix A: File Map for Implementation

### CSS Files to Modify
```
assets/css/card.css         — Line-clamp fixes, hover enhancements, font-family, thumbnail fallback, specificity fixes
assets/css/grid.css        — Tablet breakpoints, spacing tuning
assets/css/base.css        — Font-family override, lightbox enhancements, fix .vyg-feed img specificity
assets/css/carousel.css    — Update to work with shared card BEM classes (after 15.0 migration)
assets/css/masonry.css     — Update to work with shared card BEM classes (after 15.0 migration)
assets/css/featured.css     — Update to work with shared card BEM classes (after 15.0 migration)
assets/css/hero.css        — Update to work with shared card BEM classes (after 15.0 migration)
assets/css/shorts.css      — Update to work with shared card BEM classes (after 15.0 migration)
assets/css/live.css        — Update to work with shared card BEM classes (after 15.0 migration)
assets/css/list.css        — Update to work with shared card BEM classes (after 15.0 migration)
assets/css/presets.css     — Update default values
```

### PHP Files to Modify
```
src/Render/CardSettings.php           — Update defaults (show_description, show_status_badge, etc.)
src/Render/CardProfiles.php           — Per-layout density defaults
src/Render/CardRenderer.php           — Thumbnail fallback logic, lightbox data attribute, ensure all modes work
src/Render/templates/partials/video-card.php — Fallback markup, onerror handler, data-vyg-lightbox attribute
src/Render/templates/carousel.php     — MIGRATE to shared CardRenderer (15.0)
src/Render/templates/masonry.php     — MIGRATE to shared CardRenderer (15.0)
src/Render/templates/featured.php    — MIGRATE to shared CardRenderer (15.0)
src/Render/templates/hero.php         — MIGRATE to shared CardRenderer (15.0)
src/Render/templates/shorts.php      — MIGRATE to shared CardRenderer (15.0)
src/Render/templates/live.php        — MIGRATE to shared CardRenderer (15.0)
src/Render/templates/list.php        — MIGRATE to shared CardRenderer (15.0)
src/Render/AssetManager.php           — CSS enqueue priority
src/Render/FeedQuery.php              — JOIN to vyg_sources for channel_name
src/Repository/VideoRepository.php    — JOIN to vyg_sources for channel_name
src/Database/Installer.php            — New columns for channel metadata (Phase 17)
src/Database/Migrator.php             — Migration for new columns + backfill (Phase 17)
src/Sync/VideoMetadataFetcher.php     — Fetch channel metadata during sync (Phase 17)
src/Integrations/Elementor/GalleryWidget.php — Full control panel (Phase 18)
assets/js/lightbox.js                  — Title, share, navigation (Phase 19)
```

### New Files
```
assets/css/skeleton.css               — Skeleton loading state
assets/js/skeleton.js                 — Skeleton loader (optional, could be CSS-only)
```

### Reference Files (do not modify)
```
prototype/vector-youtube-gallery-visual-prototype.html — The visual target
reference/*.png                       — 11 ChatGPT-generated reference images
docs/prototype-parity.md              — Phase 14 narrative companion
```

---

## Appendix B: Recommended Phase Sequencing

```
Phase 15 (Critical Bugs + Unification)  ────────────► 3–4 days
    │  (15.0 unification is the bulk — 8 layout migrations)
    ▼
Phase 16 (Visual Polish)     ────────────────────► 2–3 days
    │
    ▼
Phase 17 (Channel Metadata)  ────────────────────► 1–2 days
    │
    ▼
Phase 18 (Elementor)         ────────────────────► 2–3 days
    │
    ▼
Phase 19 (Lightbox + Final)  ────────────────────► 1–2 days
    │
    ▼
Production Ready             ────────────────────► ~9–14 days total
```

**Total estimated effort:** 9–14 days of focused implementation work.

---

*This plan is a living document. The implementation agent should update the status checkboxes as work progresses and flag any deviations from the plan.*