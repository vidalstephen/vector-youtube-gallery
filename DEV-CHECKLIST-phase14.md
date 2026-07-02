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
- Current sub-phase: **14.9 — Shared header (kicker + h1 + intro + layout pill + channel CTA) across all 8 layouts**
- Last completed item: 14.8 — badge type × style matrix (7 types × 4 styles); 820 PHPUnit tests, 2311 assertions, 0 failures; commit `0e83755` on `main` ahead of `origin/main`. **Audit found 4/7 types partial, 3/7 missing, 0/4 styles emitted** — real substantive ship scope.
- Next actionable item: 14.9 (shared header across 8 layouts). Audit first — grid may already have it; check what the other 7 layouts have.
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
- [ ] 14.10 Trust strip on grid/masonry/carousel
- [ ] 14.11 Relative countdown for live upcoming
- [ ] 14.12 Final parity contact-sheet capture
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
