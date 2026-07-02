# Prototype Parity — Vector YouTube Gallery

> **Active roadmap:** `DEV-CHECKLIST-phase14.md`. This file is the
> narrative companion for non-Telegram readers.

## What this is

The HTML file at `prototype/vector-youtube-gallery-visual-prototype.html`
is a single-file reference for the front-end we want the plugin to
produce. It contains a settings panel (Layout / Card Design / Thumbnail
/ Content / Metadata / CTA & Actions / Badges) and 8 layout renderers
(grid / list / featured / hero / shorts / live / masonry / carousel)
all wired to a 12-video JavaScript fixture.

This is NOT a from-scratch spec. Phase 13.1 (24 commits on `main`,
2026-06-30 to 2026-07-01) already shipped:

- A shared `CardRenderer` + 36-key `CardSettings` system
- 5 card styles: `bordered / elevated / flat / minimal / commerce`
- 3 density presets: `compact / comfortable / editorial`
- 5 thumbnail ratios: `16:9 / 4:3 / 1:1 / 9:16 / auto`
- 9 mode variants: `standard / compact / media-row / hero-primary /
  hero-secondary / short-vertical / live-status / overlay /
  minimal-thumb`
- All 8 layouts: `grid / list / featured / hero / shorts / live /
  masonry / carousel` — with templates, CSS, and PHP layout classes
- A 17-image `way-of-holiness` capture set (each layout at desktop +
  mobile)

Phase 14 is therefore a **parity audit + gap-closure pass**, not a
rewrite. The 13 sub-phases are concrete gaps discovered by grepping
the codebase against every prototype control, not aspirational work.

## How the panel maps to the plugin

The prototype's panel is a developer aid for live tweaking. In the
shipped plugin, those same controls live in three real surfaces:

| Panel control | Admin Feed Builder field | Shortcode / block attr | Elementor control |
|---|---|---|---|
| Layout | `layout` | `layout` | Layout select |
| Width (14.1) | `width` | `width="theme\|wide\|full"` | Width select |
| Columns | `columns` | `columns` | Columns slider |
| Items | `per_page` | `per_page` | Items slider |
| Gap | `card_settings.gap` | `gap` | Gap slider |
| Style | `card_settings.card_style` | `card_style` | Card style select |
| Density | `card_settings.density` | `density` | Density select |
| Card radius | `card_settings.card_radius` | `card_radius` | Radius slider |
| Thumb radius | `card_settings.thumb_radius` | `thumb_radius` | Thumb radius slider |
| Padding | `card_settings.padding` | `padding` | Padding slider |
| Shadow | `card_settings.shadow` | `shadow` | Shadow select |
| Thumbnail ratio | `card_settings.thumbnail_ratio` | `thumbnail_ratio` | Ratio select |
| Duration pos | `card_settings.duration_position` | `duration_position` | Duration pos select |
| Show thumb / duration / play / overlay | `card_settings.show_thumbnail` etc. | `show_thumbnail` etc. | Toggles |
| Title / channel / avatar / verified / meta / desc | `card_settings.show_*` | `show_*` | Toggles |
| Title lines / desc lines | `card_settings.title_lines` | `title_lines` | Select |
| Metadata fields | `card_settings.metadata_fields` | `metadata_fields` (csv) | Checkbox matrix |
| CTA mode / style / label | `card_settings.cta_*` | `cta_mode`, `cta_style`, `cta_label` | Selects + text |
| Actions / share / youtube / loadmore | `card_settings.show_actions` | `show_actions` | Toggles |
| Badge type / style | `card_settings.badge_type` | `badge_type` | Selects (14.8) |

The Phase 14 work extends the **card settings** system (the canonical
internal API) and the **shortcode / block / Elementor surface** so
operators can reach every prototype control through the existing UI.

## Sub-phases

1. **14.0** — Branch prep + archive (DONE at session start)
2. **14.1** — Width modes (theme / wide / full) wrapper class
3. **14.2** — Carousel: prev/next nav buttons, dots, active-card state
4. **14.3** — Live section pill counters (live / upcoming / replay)
5. **14.4** — Section head + "View all →" link (featured / hero)
6. **14.5** — Card play icon center + thumbnail gradient overlay
7. **14.6** — Per-video tone color (`--tone` CSS variable)
8. **14.7** — Per-channel avatar gradient (`--aa`, `--ab`)
9. **14.8** — Badge type × style matrix (7 × 4)
10. **14.9** — Shared header across all 8 layouts
11. **14.10** — Trust strip on grid / masonry / carousel
12. **14.11** — Relative countdown for live upcoming
13. **14.12** — Final parity contact-sheet capture
14. **14.13** — Close-out + resume note

## Out of scope (deferred to post-Phase 14)

- 13.2 Channel metadata sync (avatar / verified / subscriber data layer)
- 13.4 Internationalization pass
- 13.5 Interactive column switcher
- 13.6 Security audit
- 13.7 Documentation set
- 13.8 Final release candidate E2E
- 13.3 Licensing / update-server abstraction

These live in `docs/archive/DEV-CHECKLIST-2026-07-02-pre-prototype.md`
and resume after Phase 14.13 closes.

## References

- Prototype: `prototype/vector-youtube-gallery-visual-prototype.html`
- Grid layout contract: `docs/grid-layout.md`
- Phase 13.1 (shipped): git log `9d7464..ceedfc3` (24 commits on `main` ahead of `origin/main`)
- Phase 14 plan: `/root/.hermes/plans/2026-07-02_160247-prototype-parity.md`
