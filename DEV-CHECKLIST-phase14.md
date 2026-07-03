# Development Checklist — Phase 14 (Prototype Parity)

> **Active roadmap:** Phase 14 — Prototype Parity. The pre-prototype
> `DEV-CHECKLIST.md` (Phases 0–13.8) is preserved at
> `docs/archive/DEV-CHECKLIST-2026-07-02-pre-prototype.md`. When Phase 14
> is complete (14.13), the pre-prototype file's status block will be
> updated so its resume point is Phase 13.2 — Channel metadata sync.
>
> **Source of truth:** the visual prototype at
> `prototype/vector-youtube-gallery-visual-prototype.html`. Each item
> below is a gap from a prototype control to the current renderer.

## Project Summary

**Vector YouTube Gallery** is a WordPress plugin that builds a local-indexed YouTube gallery system. YouTube remains the canonical media platform; WordPress stores a compliant, refreshable metadata index and renders fast galleries from local data only.

- **Namespace:** `VectorYT\Gallery\`
- **Plugin slug:** `vector-youtube-gallery`
- **Text domain:** `vector-youtube-gallery`
- **Min WP:** 6.4+, **Min PHP:** 8.1+
- **No scraping. No API calls on front-end render. No video file storage.**

## Current Development Status

- Current phase: **Phase 14 — Prototype Parity**
- Current sub-phase: **14.13 — Close out + resume note**
- Last completed item: 14.12 — WOH-filled final parity contact-sheet capture + public dev access at wpt.nsystems.live; 899 PHPUnit tests, 2513 assertions; smoke 36/36 OK; Playwright recapture api_quota_delta=0; commit 7fb9c9e
- Next actionable item: 14.13 — close out Phase 14, archive/update the phase file, and write the resume note for the deferred 13.2–13.8 work (channel metadata sync, licensing, i18n, accessibility audit, packaging, security audit, docs, final E2E).
- Blocked items: none
- Deferred items: 13.2–13.8 (channel metadata sync, licensing, i18n, accessibility audit, packaging, security audit, docs, final E2E) — resume point archived in `docs/archive/DEV-CHECKLIST-2026-07-02-pre-prototype.md`.

## Status Legend

- [ ] Not started
- [~] In progress / partially complete
- [x] Complete
- [!] Blocked
- [>] Deferred
- [?] Needs review / unknown

## Phase Checklist

### Phase 14 — Prototype Parity

- [x] 14.0 Archive pre-prototype checklist; create `DEV-CHECKLIST-phase14.md`; commit + push
- [x] 14.1 Width modes wrapper class (`vyg-theme / vyg-wide / vyg-full`) with `--vyg-max` tokens (880 / 1180 / 100%)
- [x] 14.2 Carousel: prev/next nav buttons (already wired), dots, active-card state
- [x] 14.3 Live section pill counters (`2 live`, `3 upcoming`, `Recent replays`)
- [x] 14.4 Section head + "View all →" link (featured / hero layouts)
- [x] 14.5 Card play icon center + thumbnail gradient overlay
- [x] 14.6 Per-video tone color (`--tone` CSS variable) for varied card backgrounds
- [x] 14.7 Per-channel avatar gradient (`--aa`, `--ab` two-color CSS variables)
- [x] 14.8 Badge type × style matrix (7 types × 4 styles)
- [x] 14.9 Shared header (kicker + h1 + intro + layout pill + channel CTA) across all 8 layouts
- [x] 14.10 Trust strip on grid/masonry/carousel
- [x] 14.11 Relative countdown for live upcoming
- [x] 14.12 Final parity contact-sheet capture (WOH-filled contact sheets + wpt.nsystems.live access)
- [ ] 14.13 Close out + resume note

## Scope Lock

- **Branch:** continue on `main` (24 ahead-of-origin commits stay visible in history).
- **Archive:** `DEV-CHECKLIST.md` → `docs/archive/DEV-CHECKLIST-2026-07-02-pre-prototype.md`; new file at `DEV-CHECKLIST-phase14.md` (does not replace the root `DEV-CHECKLIST.md` slot).
- **Panel:** the prototype's developer-aid panel is NOT a feature. Controls live in the existing Admin Feed Builder, Gutenberg Inspector, Elementor controls, and shortcode attrs.
- **Out of scope:** Channel metadata sync (original 13.2) and the rest of 13.4–13.8 stay in the archived checklist.
- **Validation per sub-phase:** `php -l`, `make test-unit`, `make ci-smoke`, Playwright capture, secret-scan, `git commit` with `phase-14.N: <summary>` and a deliverables body, `git push origin main`.

## Resume After Phase 14

When all 14.x items are `[x]`, the next active item is **Phase 13.2** in the archived file:
> Channel metadata sync (avatar / verified / subscriber count) — data-layer extension for channel metadata; then toggle the corresponding `show_*` Inspector controls back on by default.

Restore path: `git mv docs/archive/DEV-CHECKLIST-2026-07-02-pre-prototype.md DEV-CHECKLIST.md` (the archived file already has a "Phase 14 — Prototype Parity (COMPLETE)" footer appended at 14.13).

---

# Phase 15 — Frontend Polish (IN PROGRESS)

> **Active phase:** Phase 15 — Frontend Polish. Goal: take the
> rendering pipeline from "functional but unpolished" to "Smash
> Balloon-quality production ready." Roadmap in
> `docs/frontend-polish-plan.md` (1,067 lines, 5 phases, 43
> sub-phases).

## 15.0 — Architectural Foundations + Critical Bug Fixes (DONE)

All 9 confirmed frontend issues from the live-screenshot investigation
fixed in 3 commits (6235702, 0584dec, 3ac9537). Pushed to GitHub.

| # | Issue | Status | Fix |
|---|-------|--------|-----|
| 1 | CSS load order: `presets.css` loaded after `card.css`, overriding line-clamp | ✅ | `AssetManager` now makes `vyg-card` depend on `vyg-presets` |
| 2 | Title line-clamp specificity lost to `.vyg-feed .vyg-card__title` (0,2,0) in presets | ✅ | All `.vyg-card__title` rules now use `.vyg-feed` descendant (0,2,0 base) + `!important` on `display: -webkit-box` |
| 3 | Card thumb `height: 100%` lost to `.vyg-feed img { height:auto }` (0,1,1) | ✅ | `.vyg-feed .vyg-card__thumb` (0,2,0) wins |
| 4 | Legacy templates (carousel, masonry) had no constrained height for thumb-wrap | ✅ | Added `aspect-ratio: 16/9` as default on `.vyg-card__thumb-wrap` |
| 5 | `data-vyg-lightbox` missing on shared partial outer link | ✅ | Added to both outer link and title link in `video-card.php` |
| 6 | Lightbox data attribute missing on title link | ✅ | Added `data-vyg-lightbox` + `data-vyg-title` to `.vyg-card__title-link` |
| 7 | List layout showed raw channel ID instead of joined channel title | ✅ | `list.php` now uses `youtube_channel_title` from JOIN with 3-tier fallback |
| 8 | Legacy templates missing `vyg-card__title--2` class | ✅ | All 5 legacy templates (carousel, masonry, featured, hero, list) updated |
| 9 | Hard-coded `-webkit-line-clamp: 3` in masonry CSS overriding modifier | ✅ | Removed from `masonry.css` |

## 15.5b — Channel Name Truncation (DONE)

`vyg-card__channel-name` was rendering as "The Way Of Holines..."
on every card. Root cause: flex parent + 2-line clamp didn't work
because the flex item kept its natural content width. Added
`flex: 1 1 0` and `min-width: 0` so the box actually shrinks.

## 15.6 — Default Width Bump (DONE)

`.vyg-wide` max-width increased from 1180px to 1400px. On 1920px+
displays the old default was making the gallery look like a thin
column in the middle.

## 16.1 — Sans-Serif Default Font (DONE)

Theme was inheriting Twenty Twenty-Four's Charter/IBM Plex Serif
fonts, making video metadata look unprofessional. Added a
`--vyg-font-sans` CSS custom property with a clean system UI sans
stack. Operators can override with `inherit` if they want theme
fonts.

## 15.5 — Shorts Letterboxing (DONE)

Added `object-fit: cover !important` to `.vyg-shorts__thumb` to
defend against any theme or preset rule that sets `object-fit: contain`.

## Tests

- **904 PHPUnit tests passing**, 0 failures (was 899 before, +5 new)
- **CSS load order verified live**: presets.css now at position 2, card.css at position 3
- **HTML verified live**: 12 `vyg-card__title--2` classes, 24 `data-vyg-lightbox` attrs on grid page
- **Screenshots captured**: `/screenshots/phase15/{layout}-desktop.png` for all 8 layouts

## Remaining Work (Sub-Phases Not Yet Implemented)

- **15.5**: Channel header partial (avatar + sub count + subscribe button) — deferred to Phase 16.7
- **15.7**: Live layout sectioning polish — already works
- **16.2**: Sensible card defaults (per-layout density) — partial
- **16.3**: Per-layout spacing tuning — partial
- **16.4**: Tablet breakpoints
- **16.5**: Hover enhancement
- **16.6**: Thumbnail fallback for missing images
- **16.7**: Channel header partial (Smash Balloon Gallery parity)
- **17.x**: Channel metadata sync (Phase 13.2 deferred work)
- **18.x**: Elementor widget enhancement (full Card Design + Header tabs)
- **19.x**: Lightbox enhancement + production readiness

The plugin is now in a significantly better visual state. The 9
critical issues are all fixed. Phase 15 is technically "done" at
the 15.0–15.5 level (the bug-fix layer). The remaining sub-phases
(15.5b/16.7 channel header, 17.x metadata sync, 18.x Elementor
enhancement, 19.x lightbox) are the polish layer and can be
scheduled as separate phases.
