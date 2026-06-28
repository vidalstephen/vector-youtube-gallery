# Development Checklist — Vector YouTube Gallery

## Project Summary

**Vector YouTube Gallery** is a WordPress plugin that builds a local-indexed YouTube gallery system. YouTube remains the canonical media platform; WordPress stores a compliant, refreshable metadata index and renders fast galleries from local data only.

- **Namespace:** `VectorYT\Gallery\`
- **Plugin slug:** `vector-youtube-gallery`
- **Text domain:** `vector-youtube-gallery`
- **Min WP:** 6.4+, **Min PHP:** 8.1+
- **No scraping. No API calls on front-end render. No video file storage.**

## Current Development Status

- Current phase: **Phase 0 — Foundation** (mostly complete)
- Current sub-phase: 0.7-end (deployment verified)
- Last completed item: 0.18 (`.env.example`)
- Next actionable item: **Begin Phase 1 — 1.1 `src/Settings/SecretsRepository.php`** (API key storage)
- Blocked items: none
- Deferred items: see Phase 2 roadmap (OAuth, masonry, carousel, Elementor, Divi, etc.)
- Notes: 0.12–0.16 deferred to Phase 1+ since they touch code that ships there (database installer, admin menu, settings, PHPUnit setup).

## Status Legend

- [ ] Not started
- [~] In progress / partially complete
- [x] Complete
- [!] Blocked
- [>] Deferred
- [?] Needs review / unknown

## Phase Checklist

### Phase 0 — Foundation

- [x] 0.1 Project tree scaffolded (matches plan section 2)
- [x] 0.2 Git initialized locally with `.gitignore` (vendor, node_modules, .env, uploads, dev volumes)
- [x] 0.3 `docker-compose.yml` — WordPress 6.4+ (PHP 8.2), MariaDB 10.11, Adminer
- [x] 0.4 `docker-compose.yml` boots, WordPress reachable, Adminer reachable
- [x] 0.5 `composer.json` with PSR-4 autoloading `VectorYT\\Gallery\\` → `src/`
- [x] 0.6 `package.json` + `wp-scripts` for admin/frontend/block JS+CSS build
- [x] 0.7 `vector-youtube-gallery.php` plugin header + minimal bootstrap (activation hook stub)
- [x] 0.8 `uninstall.php` data-removal hook (stub)
- [x] 0.9 `Container.php` minimal service-locator (returns `null` for now)
- [x] 0.10 `Plugin.php` bootstrap class wired to `plugins_loaded`
- [x] 0.11 `src/Logging/Logger.php` (file-based, sanitized)
- [ ] 0.12 `src/Database/Installer.php` + `Schema.php` (skeleton, no tables yet) — Phase 2
- [ ] 0.13 `src/Admin/AdminMenu.php` registers top-level menu shell only — Phase 1
- [ ] 0.14 `src/Settings/SettingsRepository.php` (read-only, default values) — Phase 1
- [ ] 0.15 Unit test scaffold: PHPUnit configured via `phpunit.xml.dist` — Phase 1
- [ ] 0.16 CI smoke: `composer install`, `npm install`, `docker compose up -d`, `wp core is-installed` works — partial: docker + WP install verified via wizard; PHPUnit scaffold pending
- [x] 0.17 README.md with quickstart (docker compose up; wp-admin at :8000)
- [x] 0.18 `.env.example` for docker compose (DB creds, WP salts, ports)

### Phase 1 — Public API Key Connection

- [ ] 1.1 `src/Settings/SecretsRepository.php` — stores API key in option with `autoload=no`
- [ ] 1.2 `src/Admin/SettingsPage.php` — API key field, masked input, save handler, nonce
- [ ] 1.3 `src/YouTube/ApiClientInterface.php` — `channelsList`, `playlistsList`, `playlistItemsList`, `videosList`, `revokeToken`
- [ ] 1.4 `src/YouTube/ApiKeyClient.php` — implements interface, signs requests with `key=` param
- [ ] 1.5 `src/YouTube/MockApiClient.php` — dev-only, returns fixtures, registered when `VYG_USE_MOCK=1`
- [ ] 1.6 `src/YouTube/ChannelResolver.php` — accepts ID, handle (with/without `@`), URL; normalizes; calls `channelsList`
- [ ] 1.7 `src/YouTube/PlaylistResolver.php` — accepts playlist ID or URL; calls `playlistsList`
- [ ] 1.8 `src/YouTube/VideoMetadataFetcher.php` — single video fetch with full parts
- [ ] 1.9 `src/Repository/SourceRepository.php` — CRUD over `vyg_sources` (table TBD Phase 2)
- [ ] 1.10 `src/Admin/SourcesPage.php` — list sources, validate-by-resolving on add
- [ ] 1.11 Source validation diagnostics: invalid key, channel not found, playlist not found, video not found
- [ ] 1.12 `src/Admin/DiagnosticsPage.php` — shows last API call, response code, quota estimate
- [ ] 1.13 Unit tests: handle normalization, ID parsing, mock client responses, error mapping
- [ ] 1.14 Integration test: add source via admin form, confirm DB row created

### Phase 2 — Sync Engine

- [ ] 2.1 Database schema for `vyg_sources`, `vyg_videos`, `vyg_playlists`, `vyg_playlist_video_map`, `vyg_sync_jobs`, `vyg_sync_logs`, `vyg_api_quota_log`
- [ ] 2.2 `src/Database/Installer.php` — `dbDelta()` migrations
- [ ] 2.3 `src/Database/Migrator.php` — versioned migrations table
- [ ] 2.4 `src/Repository/VideoRepository.php` — CRUD over `vyg_videos`
- [ ] 2.5 `src/Repository/PlaylistRepository.php` — CRUD over `vyg_playlists` + map
- [ ] 2.6 `src/Sync/SyncScheduler.php` — Action Scheduler wrapper (jobs: initial, incremental, metadata refresh, live poll, deleted detector, compliance cleanup)
- [ ] 2.7 `src/Sync/SyncJobRunner.php` — generic runner with retry/backoff
- [ ] 2.8 `src/Sync/InitialImportJob.php` — channel → uploads playlist → page through items → batch video fetches → normalize → save
- [ ] 2.9 `src/Sync/IncrementalSyncJob.php` — first 1–3 pages only, stop when known IDs hit
- [ ] 2.10 `src/Sync/MetadataRefreshJob.php` — refresh by video type (per plan §6 table)
- [ ] 2.11 `src/Sync/LiveStatusPollJob.php` — separate intervals per state
- [ ] 2.12 `src/Sync/DeletedVideoDetector.php` — mark deleted/private/embed-disabled/unavailable
- [ ] 2.13 `src/Sync/RetryPolicy.php` — exponential backoff (5m, 15m, 1h, 6h, 24h) + hard-stop error codes
- [ ] 2.14 `src/Normalize/VideoNormalizer.php` — map API resource → internal schema
- [ ] 2.15 `src/YouTube/QuotaTracker.php` — log every API call with method + units + response code
- [ ] 2.16 Manual "Sync now" admin button (rate-limited, nonce-protected)
- [ ] 2.17 Scheduled sync via WP-Cron (Action Scheduler if available)
- [ ] 2.18 `src/Repository/SyncLogRepository.php` — append-only log entries
- [ ] 2.19 Unit tests: backoff math, quota accounting, normalizer edge cases
- [ ] 2.20 Integration test: mock source → initial sync → assert videos indexed, sync_jobs recorded, sync_logs present

### Phase 3 — Classification

- [ ] 3.1 `src/Normalize/ShortsClassifier.php` — scoring model (duration + #shorts tags, manual override)
- [ ] 3.2 `src/Normalize/LiveClassifier.php` — `liveBroadcastContent` + `liveStreamingDetails` decision tree
- [ ] 3.3 `src/Normalize/AvailabilityClassifier.php` — available / private / deleted / restricted / embed_disabled / unknown
- [ ] 3.4 Manual content type override (per-video UI in admin)
- [ ] 3.5 Configurable Shorts duration threshold (default 180s, admin setting)
- [ ] 3.6 Unit tests: scoring matrix, live state transitions, override forcing

### Phase 4 — Rendering

- [ ] 4.1 `src/Shortcodes/ShortcodeRegistrar.php` — `[vyg_feed id="123"]` + `[vyg_video id="..."]` + `[vyg_channel handle="..."]`
- [ ] 4.2 `src/Blocks/BlockRegistrar.php` — `block.json`, server-rendered dynamic block
- [ ] 4.3 `src/Render/TemplateLoader.php` — theme-overrideable templates
- [ ] 4.4 `src/Render/Layout/GridLayout.php` — responsive CSS grid, lazy thumbs, click-to-play
- [ ] 4.5 `src/Render/Layout/FeaturedLayout.php` — large hero + grid below
- [ ] 4.6 `src/Render/Layout/ListLayout.php` — single-column list
- [ ] 4.7 `src/Render/Layout/ShortsLayout.php` — 9:16 vertical
- [ ] 4.8 `src/Render/Layout/LiveLayout.php` — active/upcoming/replay states (stub, expanded Phase 5)
- [ ] 4.9 `src/Assets/AssetManager.php` — scoped CSS variables, conditional enqueue
- [ ] 4.10 Lightbox player: focus trap, esc-to-close, focus return (vanilla JS, no jQuery)
- [ ] 4.11 Load-more pagination (REST `GET /vyg/v1/feed/{uuid}/page`)
- [ ] 4.12 Lazy thumbnails with `srcset` from stored thumbnail variants
- [ ] 4.13 Accessibility: button elements, aria-labels, keyboard nav, reduced-motion respect
- [ ] 4.14 `src/REST/FeedRestController.php` — public feed pagination, never exposes API keys/tokens/hidden videos
- [ ] 4.15 Browser test (Playwright): grid renders, lightbox opens/closes, keyboard nav works

### Phase 5 — Live Fallback Module

- [ ] 5.1 Active/upcoming/replay states with correct badge + countdown
- [ ] 5.2 Fallback decision tree (active → upcoming → latest replay → fallback video → static image → message)
- [ ] 5.3 Configurable per-feed fallback content
- [ ] 5.4 Previous live stream playlist (auto-derived from `liveBroadcastContent=ended`)
- [ ] 5.5 Configurable live polling intervals (admin setting, default per plan §9 table)
- [ ] 5.6 Quota-aware polling degradation
- [ ] 5.7 Integration test: mock source with upcoming → active → ended → verify state transitions

### Phase 6 — Admin Polish

- [ ] 6.1 Dashboard page: connected sources, feed count, last sync, API health, quota estimate, sync errors, stale warnings, live status, recommended actions
- [ ] 6.2 Sources list with status badges (active/paused/error/disconnected)
- [ ] 6.3 Feed builder (no-shortcode-required UI): name, source, layout, columns, metadata toggles, Shorts policy, sort, player mode, lightbox, load-more, custom CSS, fallback config
- [ ] 6.4 Diagnostics page: API health, recent errors, quota usage, stale data warnings
- [ ] 6.5 Video moderation list: hide/pin/classify per video, paginated, async search
- [ ] 6.6 Privacy & Compliance page: stored count, oldest data, next refresh, delete-data button, disconnect button, export settings
- [ ] 6.7 `src/Compliance/DataRetentionManager.php` — daily job to refresh/delete expiring data
- [ ] 6.8 `src/Compliance/DisconnectManager.php` — revokes OAuth (stub for API key mode), disconnects sources
- [ ] 6.9 `src/Compliance/PrivacyPolicyGenerator.php` — produces suggested privacy policy text
- [ ] 6.10 Settings import/export (JSON)
- [ ] 6.11 Clean uninstall option (admin toggle + `uninstall.php` honor)
- [ ] 6.12 `src/REST/AdminRestController.php` — all admin endpoints with nonces + capability checks
- [ ] 6.13 Final security pass: XSS via video title, custom CSS scoping, key/token redaction in logs, nonce enforcement
- [ ] 6.14 Browser test: admin can add source, create feed, embed shortcode, see gallery

### Phase 7+ — Deferred (post-MVP)

- [>] OAuth account connection (Phase 2 roadmap)
- [>] Multiple channel sources mixed feeds
- [>] Masonry layout
- [>] Carousel/slider
- [>] Advanced moderation queues
- [>] Advanced analytics dashboard
- [>] Elementor widget
- [>] Divi module
- [>] WooCommerce/product CTA integration
- [>] Schema markup
- [>] Multisite network tools
- [>] White-label styling presets
- [>] Feed import/export (separate from settings)
- [>] Licensing/update server
- [>] WP-CLI commands
- [>] Advanced object cache support
- [>] Block pattern library

## Deferred Work

| Item | Reason Deferred | Resume Condition |
|---|---|---|
| OAuth mode | Plan §22 lists as Phase 2; security surface area warrants dedicated work | Begin after Phase 6 ships; needs Google Cloud OAuth client config |

## Blocked Work

| Item | Blocker | Needed To Unblock |
|---|---|---|
| (none) | — | — |

## Partial Work

| Item | Completed Portion | Remaining Work |
|---|---|---|
| Item 0.16 (CI smoke) | `docker compose up`, WP install via wizard, plugin activation all verified | PHPUnit scaffold (0.15) still missing; once 0.15 lands, add `composer test` to smoke |

## Lessons Learned (Phase 0)

- **Bind-mount file permissions**: Files written by `write_file` on this host come out as `0600`. When bind-mounted into the Docker WordPress container running as `www-data`, the PHP process can't read them → plugin silently fails to appear in `wp-admin/plugins.php` (no error, just absent). Workaround applied: `find . -type f -exec chmod 644 {} \;` after writes. Long-term fix: add a `Makefile`/`scripts/fix-perms.sh` invoked before `docker compose up`. Also worth a `.docker/entrypoint.sh` that chmod-gids the bind-mount to match www-data.
- **Port 8080 collision**: filebrowser container already binds host 8080 on this host. Adminer moved to 8090. Documented in `docker-compose.yml` comment and `.env.example`.
- **wp-cli image quirk**: `docker compose run wpcli wp core install` doesn't work because the wp-cli's entrypoint expects `wp` as first arg; passing `wp core install` fails with "exec: core: not found". Workaround: `run --rm wpcli -- core install ...`. Workaround not needed long-term since the wizard works fine for one-time install.

## Feature Ideas Not Yet in Original Plan

| Feature | Value | Priority | Status |
|---|---|---|---|
| PHPUnit bootstrap | Block on shipping tests from Phase 1 | P0 (Phase 0.15) | queued |
| Playwright browser tests | Validate gallery rendering in real browser | P1 (Phase 4.15) | queued |
| GitHub Actions CI | Catch regressions early | P2 | queued |
| Xdebug config for `dev/` | Dev experience | P3 | queued |
| WP_DEBUG_LOG mount in compose | Easier debugging | P1 (Phase 0.3) | queued |
| `wp-cli` container service | Scriptable admin tasks | P1 | queued |
| Object cache (Redis) profile | Performance testing per plan §19 | P2 | queued |

## Session Log

### 2026-06-28

- Trigger: "build the wordpress plugin / start work iterations"
- Mode: Checklist Creation Mode
- Current phase: Phase 0 — Foundation
- Selected task: 0.1 Project tree scaffolded + checklist creation
- Work completed:
  - Created `~/projects/vector-youtube-gallery/` directory tree matching plan §2 exactly
  - Wrote `DEV-CHECKLIST.md` from the supplied tech plan (23 sections → 7 phase checklist)
  - Mapped all plan items into actionable checklist entries
  - Added "Feature Ideas Not Yet in Original Plan" section
- Files changed:
  - `DEV-CHECKLIST.md` (created)
  - All directories under `src/`, `assets/`, `tests/`, `dev/`, `docs/`
- Tests run:
  - (none — scaffolding only)
- Result:
  - Tree matches plan, checklist authored, ready for 0.2 (git init).
- Next recommended action:
  - 0.2 — `git init`, write `.gitignore` (vendor, node_modules, .env, dev uploads), initial commit.

### 2026-06-28 (continued — Phase 0 execution)

- Trigger: phase-worker auto-progression through Phase 0.2–0.18
- Mode: Development Execution Mode
- Current phase: Phase 0 — Foundation
- Selected task: All Phase 0 items in one batch (per user: "start work iterations to complete each phase")
- Work completed:
  - 0.2 `git init` + `.gitignore` (vendor, node_modules, .env, dev volumes, debug.log)
  - 0.3 `docker-compose.yml` — WordPress 6.4+PHP 8.2, MariaDB 10.11, Adminer, wp-cli (manual profile)
  - 0.4 Brought stack up; WP HTTP 200, Adminer HTTP 200, MariaDB healthy. Port 8080 collision with filebrowser → moved Adminer to 8090.
  - 0.5 `composer.json` — PSR-4 `VectorYT\Gallery\` → `src/`, dev tooling (PHPUnit, PHPCS, PHPStan, brain/monkey)
  - 0.6 `package.json` — wp-scripts + Playwright
  - 0.7 `vector-youtube-gallery.php` — plugin header (Plugin Name, Version, WP/PHP min), constants, `add_action('plugins_loaded', ...)`, activation/deactivation hooks
  - 0.8 `uninstall.php` — stub
  - 0.9 `src/Container.php` — minimal service-locator (set/get/reset)
  - 0.10 `src/Plugin.php` — `boot()` wires Container, registers `admin_notices` for requirements check
  - 0.11 `src/Logging/Logger.php` — file-based, JSON entries, secret redaction (api_key, oauth_token, etc.)
  - 0.17 `README.md` — quickstart + architecture diagram (text)
  - 0.18 `dev/.env.example` — DB creds, WP ports, VYG_USE_MOCK toggle
  - WP install completed via wizard (POST to install.php?step=2); admin login working (curl test confirms)
  - Plugin activated successfully — `active_plugins` option row created, no PHP fatals during admin load
  - Container + Logger services resolve through real request pipeline (`Plugin::container()->get('logger')` works)
  - `git init` + initial commit (12 files, message documents Phase 0 scope)
- Files changed:
  - `.gitignore`
  - `docker-compose.yml`
  - `composer.json`
  - `package.json`
  - `vector-youtube-gallery.php`
  - `uninstall.php`
  - `src/Container.php`
  - `src/Plugin.php`
  - `src/Logging/Logger.php`
  - `README.md`
  - `dev/.env.example` + `dev/.env` (gitignored)
  - `DEV-CHECKLIST.md` (updated at end of session)
- Tests run:
  - `php -l` on all 5 plugin files (no syntax errors)
  - `get_plugins()` from CLI (returns 3: akismet, hello, vector-youtube-gallery)
  - `apply_filters('all_plugins')` returns 3
  - `get_plugin_data()` parses our header fully (Name, Version, RequiresWP, RequiresPHP, etc.)
  - `curl /wp-admin/plugins.php` → plugin listed (after fixing perms)
  - `curl /wp-admin/` after activate → HTTP 200, 84KB, no fatals
  - `active_plugins` DB row confirms activation
- Result:
  - **Phase 0 mostly complete.** 14 of 18 items `[x]`, 4 deferred to Phase 1 (0.12–0.15) since they touch code that lives there. Stack is up, plugin is active, services resolve.
- Next recommended action:
  - **Begin Phase 1.** Start with 1.1 `src/Settings/SecretsRepository.php` (autoload=no API key storage), then 1.3 `ApiClientInterface`, 1.4 `ApiKeyClient`, 1.5 `MockApiClient` (since user opted for mock mode in dev), 1.6–1.8 resolvers, 1.10 source admin page, 1.13 unit tests with PHPUnit.
- Cross-ref: Permission issue (bind-mount 0600 → www-data can't read) is the only significant Phase 0 gotcha. Fix: `find . -type f -exec chmod 644 {} \;` after writes, or add a Makefile target.