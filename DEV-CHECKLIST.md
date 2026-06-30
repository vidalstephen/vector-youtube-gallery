# Development Checklist — Vector YouTube Gallery

## Project Summary

**Vector YouTube Gallery** is a WordPress plugin that builds a local-indexed YouTube gallery system. YouTube remains the canonical media platform; WordPress stores a compliant, refreshable metadata index and renders fast galleries from local data only.

- **Namespace:** `VectorYT\Gallery\`
- **Plugin slug:** `vector-youtube-gallery`
- **Text domain:** `vector-youtube-gallery`
- **Min WP:** 6.4+, **Min PHP:** 8.1+
- **No scraping. No API calls on front-end render. No video file storage.**

## Current Development Status

- Current phase: **Phase 12 — Operations, Scale, and Multisite**
- Current sub-phase: 12.5 — Log rotation and configurable log levels
- Last completed item: 12.4 — `Multisite\NetworkPolicy` with network activation hook walking every site, `wp vyg network-diagnostics`, `wp vyg site-cleanup --yes` (destructive), idempotent re-seed. 8 new unit tests (369 / 1027 / 0 / 3 skipped).
- Next actionable item: 12.5 — Log rotation + configurable log levels: admin controls, redaction validation, max file size, and optional centralized shipping hook
- Blocked items: none
- Deferred items: 10.7 E2E browser verification remains deferred for page-builder integrations; Phase 11 E2E is complete via Dockerized Playwright.

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
- [x] 0.12 `src/Database/Installer.php` + `Schema.php` — completed in Phase 2 (9 tables + dbDelta migrations)
- [x] 0.13 `src/Admin/AdminMenu.php` registers top-level menu shell — completed in Phase 1 and expanded through Phase 6
- [x] 0.14 `src/Settings/SettingsRepository.php` — completed in Phase 1 and expanded through later phases
- [x] 0.15 Unit test scaffold: PHPUnit configured via `phpunit.xml.dist` — completed in Phase 1
- [~] 0.16 CI smoke: manual Docker/WP/plugin/test/Camofox smoke verified; automated CI remains Phase 12.7
- [x] 0.17 README.md with quickstart (docker compose up; wp-admin at :8000)
- [x] 0.18 `.env.example` for docker compose (DB creds, WP salts, ports)

### Phase 1 — Public API Key Connection

- [x] 1.1 `src/Settings/SecretsRepository.php` — stores API key in option with `autoload=no`
- [x] 1.2 `src/Admin/SettingsPage.php` — API key field, masked input, save handler, nonce — completed in Phase 1/SettingsPage work
- [x] 1.3 `src/YouTube/ApiClientInterface.php` — `channelsList`, `playlistsList`, `playlistItemsList`, `videosList`, `revokeToken`
- [x] 1.4 `src/YouTube/ApiKeyClient.php` — implements interface, signs requests with `key=` param
- [x] 1.5 `src/YouTube/MockApiClient.php` — dev-only, returns fixtures, registered when `VYG_USE_MOCK=1`
- [x] 1.6 `src/YouTube/ChannelResolver.php` — accepts ID, handle (with/without `@`), URL; normalizes; calls `channelsList`
- [x] 1.7 `src/YouTube/PlaylistResolver.php` — accepts playlist ID or URL; calls `playlistsList`
- [x] 1.8 `src/YouTube/VideoMetadataFetcher.php` — single video fetch with full parts
- [x] 1.10 `src/Admin/SourcesPage.php` — list sources, validate-by-resolving on add
- [x] 1.11 Source validation diagnostics: invalid key, channel not found, playlist not found, video not found
- [x] 1.12 `src/Admin/DiagnosticsPage.php` — shows last API call, response code, quota estimate
- [x] 1.13 Unit tests: handle normalization, ID parsing, mock client responses, error mapping (46 tests passing)
- [x] 1.14 Integration test: add source via admin form, confirm DB row created (manual curl test confirmed end-to-end)
- [x] 1.9 `src/Repository/SourceRepository.php` — CRUD over `vyg_sources` — completed in Phase 2

### Phase 0 — Foundation (carry-over from earlier)

- [x] 0.12 `src/Database/Installer.php` + `Schema.php` — deferred to Phase 2
- [x] 0.13 `src/Admin/AdminMenu.php` registers top-level menu shell only — **DONE in Phase 1** (now wired with Phase 1 submenus)
- [x] 0.14 `src/Settings/SettingsRepository.php` — **DONE in Phase 1**
- [x] 0.15 Unit test scaffold: PHPUnit configured via `phpunit.xml.dist` — **DONE in Phase 1**

### Phase 2 — Sync Engine

- [x] 2.1 `src/Database/Schema.php` — 9 CREATE TABLE statements (sources, videos, playlists, map, feeds, feed_overrides, sync_jobs, sync_logs, quota_log)
- [x] 2.2 `src/Database/Installer.php` — `dbDelta()` migrations
- [x] 2.3 `src/Database/Migrator.php` — versioned migrations table
- [x] 2.4 `src/Repository/VideoRepository.php` — CRUD over `vyg_videos`
- [x] 2.5 `src/Repository/PlaylistRepository.php` — CRUD over `vyg_playlists` + map
- [x] 2.6 `src/Sync/SyncScheduler.php` — WP-Cron backed scheduler complete; Action Scheduler adapter moved to Phase 12.2
- [x] 2.7 `src/Sync/SyncJobRunner.php` — generic runner with retry/backoff
- [x] 2.8 `src/Sync/InitialImportJob.php` — channel → uploads playlist → page through items → batch video fetches → normalize → save
- [x] 2.9 `src/Sync/IncrementalSyncJob.php` — first 1–3 pages only, stop when known IDs hit
- [x] 2.10 `src/Sync/MetadataRefreshJob.php` — refresh by video type (per plan §6 table)
- [x] 2.11 `src/Sync/LiveStatusPollJob.php` — completed in Phase 5 (Live Fallback Module)
- [x] 2.12 `src/Sync/DeletedVideoDetector.php` — mark deleted/private/embed-disabled/unavailable
- [x] 2.13 `src/Sync/RetryPolicy.php` — exponential backoff (5m, 15m, 1h, 6h, 24h) + hard-stop error codes
- [x] 2.14 `src/Normalize/VideoNormalizer.php` — map API resource → internal schema
- [x] 2.15 `src/YouTube/QuotaTracker.php` — log every API call with method + units + response code
- [x] 2.16 Manual "Sync now" admin button (rate-limited, nonce-protected)
- [x] 2.17 Scheduled sync via WP-Cron (vyg_cron_incremental_all hourly, vyg_cron_metadata_refresh twicedaily)
- [x] 2.18 `src/Repository/SyncLogRepository.php` — append-only log entries
- [x] 2.19 Unit tests: 67 / 148 assertions / 0 failures
- [x] 2.20 Integration test: mock source → initial sync → 2 videos indexed, sync_jobs success, sync_logs 3 entries, quota log 3 entries

### Phase 3 — Classification

- [x] 3.1 `src/Normalize/ShortsClassifier.php` — vertical + #Shorts tag + duration threshold (configurable)
- [x] 3.2 `src/Normalize/LiveClassifier.php` — `liveBroadcastContent` + `liveStreamingDetails` decision tree (4 states)
- [x] 3.3 `src/Normalize/AvailabilityClassifier.php` — available / private / deleted / restricted / embed_disabled
- [x] 3.4 Manual content type override — per-video UI on VideosPage, persists `manual_content_type` + `manual_content_source` (operator:user_id:iso8601) + `manual_reason` in `wp_vyg_videos`
- [x] 3.5 Configurable Shorts threshold — `SettingsRepository::DEFAULTS['shorts_max_duration_seconds']=60`, `short_candidate_max_duration=180`; exposed in Settings page
- [x] 3.6 Unit tests: 41 new tests across Shorts/Live/Availability classifier + SettingsRepository; 108 total, 223 assertions, 0 failures
- [x] 3.7 Videos admin page (list/search/filter/paginate/reclassify)
- [x] 3.8 DB schema 0.2.0: `wp_vyg_videos` adds `manual_content_source` + `manual_reason` columns
- [x] 3.9 E2E verified: manual override survives re-sync; Settings save persists int + bool

### Phase 4 — Rendering

- [x] 4.1 `src/Render/ShortcodeRegistrar.php` — `[youtube_feed]` with 10 attrs (source_uuid, layout, per_page, columns, orderby, order, content_type, pagination, offset, wrapper_id), sanitization, status check, asset enqueue
- [x] 4.2 `src/Render/BlockRegistrar.php` + block.json + render.php — server-side rendered Gutenberg block
- [x] 4.3 `src/Render/TemplateLoader.php` — theme override path + bundled fallback
- [x] 4.4 `src/Render/Layouts/GridLayout.php` + grid.php template — responsive CSS grid, lazy thumbs via loading="lazy"
- [x] 4.5 `src/Render/Layouts/FeaturedLayout.php` + featured.php — hero + grid
- [x] 4.6 `src/Render/Layouts/ListLayout.php` + list.php — single-column
- [x] 4.7 `src/Render/Layouts/ShortsLayout.php` + shorts.php — 9:16 vertical
- [x] 4.8 `src/Render/Layouts/LiveLayout.php` + live.php — sectioned by status (live / upcoming / replay) — stub, Phase 5 wires LiveStatusPollJob
- [x] 4.9 `src/Render/AssetManager.php` — base.css + per-layout CSS + lightbox/load-more JS; lazy enqueue via wp_enqueue_scripts
- [x] 4.10 Lightbox: vanilla JS, no jQuery, focus trap via dialog, esc-to-close, click-outside-to-close, iframe-replacement to stop playback
- [x] 4.11 Load-more pagination: REST `GET /vyg/v1/feed?source_uuid=&offset=` + JS handler
- [x] 4.12 Lazy thumbnails: `loading="lazy" decoding="async"` + thumbnail variant selection (maxres→standard→high→medium→default)
- [x] 4.13 Accessibility: aria-labels on watch links, semantic `<article>` per card, `<h3>` titles, role="dialog" on lightbox, aria-label on close button
- [x] 4.14 `src/REST/FeedController.php` — public read-only feed endpoint, sanitize_callback per arg, no secrets exposed, count_videos_for_source for pagination
- [x] 4.15 Manual E2E verified: front-end page renders 2 videos via shortcode, CSS+JS enqueued, REST returns JSON with has_more/next_offset/remaining, **5 page renders → 0 new API calls** (Phase 0 invariant holds)

### Phase 5 — Live Fallback Module

- [x] 5.1 `src/Sync/LiveStatusPollJob.php` — polls every 5 min via WP-Cron, fetches videos.list for live/upcoming videos, updates live_status + actual_start_at + actual_end_at + scheduled_start_at + concurrent_viewers + last_live_poll_at; promotes ended streams to vyg_previous_streams
- [x] 5.2 Fallback decision tree: LiveQuery exposes 3 buckets (live_now, upcoming, replay); LiveLayout renders them as sectioned panels (live_active → live_upcoming → live_replay); empty sections hidden
- [x] 5.3 Configurable per-feed fallback: `[youtube_feed layout="live"]` works on any source type (channel, playlist, video)
- [x] 5.4 Previous live stream playlist: `src/Repository/PreviousStreamsRepository.php` with UNIQUE(source_id, youtube_video_id), prune_to_limit(50 default), ORDER BY ended_at DESC
- [x] 5.5 Configurable live polling intervals: `live_poll_interval_seconds` (default 300), `live_upcoming_poll_seconds` (900), `live_recently_ended_seconds` (900), `live_previous_streams_retention` (50), `live_replay_retention_days` (14) — exposed in SettingsRepository
- [x] 5.6 Quota-aware polling: LiveStatusPollJob records each videos.list call to vyg_api_quota_log; future work can throttle when budget low (Phase 5 ships recording, not throttling)
- [x] 5.7 E2E verified: LiveStatusPollJob polled 2 mock live videos → stats {checked:2, updated:2, ended:0, errors:0}, last_live_poll_at updated, WP-Cron `vyg_cron_live_poll` scheduled every 5 min, LiveLayout renders Previous streams section with 2 manually-inserted streams

### Phase 6 — Admin Polish

- [x] 6.1 Dashboard page: connected sources, feed count, last sync, API health, quota estimate, sync errors, stale warnings, live status, recommended actions — `src/Admin/DashboardWidget.php` + `DashboardStats.php` (4 stat cards + gauge + recent jobs table; wired via `wp_dashboard_setup`)
- [x] 6.2 Sources list with status badges (active/paused/error/disconnected) — `src/Admin/SourcesPage.php` renders `vyg-status-badge--<status>`
- [x] 6.3 Feed builder (no-shortcode-required UI): name, source, layout, columns, metadata toggles, Shorts policy, sort, player mode, lightbox, load-more, custom CSS — `src/Admin/FeedsPage.php` + `src/Repository/FeedRepository.php`; `[youtube_feed feed_uuid="..."]` shortcode + scoped-CSS output via `Renderer::scope_css()`
- [x] 6.4 Diagnostics page: API health, recent errors, quota usage, stale data warnings, sync job health, per-source freshness — `src/Admin/DiagnosticsPage.php` (6 sections)
- [x] 6.5 Video moderation list: hide/pin/classify per video, paginated, async search — `src/Admin/VideosPage.php`
- [x] 6.6 Privacy & Compliance page: stored count, oldest data, next refresh, delete-data button, disconnect button, export settings — `src/Admin/PrivacyPage.php` (7 sections)
- [x] 6.7 `src/Compliance/DataRetentionManager.php` — daily `vyg_cron_data_retention` job: marks expired videos + hard-deletes unavailable, sync_logs, previous_streams
- [x] 6.8 `src/Compliance/DisconnectManager.php` — revokes OAuth (stub for API-key mode), disconnects sources, deletes API key from options
- [x] 6.9 `src/Compliance/PrivacyPolicyGenerator.php` — produces suggested privacy policy text (10-section English text)
- [x] 6.10 Settings import/export (JSON) — `ImporterExporter` + admin-post `vyg_export_settings` handler + PrivacyPage paste-to-import
- [x] 6.11 Clean uninstall option (admin toggle + `uninstall.php` honor) — `vyg_clean_uninstall` option read by `uninstall.php` (off = preserve data; on = drop tables/options/cron)
- [x] 6.12 `src/REST/AdminRestController.php` — all admin endpoints under `/vyg/v1/admin/*` (stats, feeds CRUD, disconnect, retention, import-settings) with nonce + manage_options cap checks
- [x] 6.13 Final security pass: XSS via video title (esc_html throughout), custom CSS scoping (`Renderer::scope_css` + defense-in-depth `<`/`>` stripping in both repo + renderer), key/token redaction in logs (`Logger::redact`), nonce enforcement (all admin POST + REST routes), SQL via `$wpdb->prepare()` (no string interpolation)
- [x] 6.14 Browser test E2E verified: feed-by-uuid shortcode renders 2 videos, scoped CSS applied, XSS payload stripped, Disconnect flips sources, retention sweep runs cleanly, REST stats endpoint returns full snapshot

### Phase 6 E2E verification — admin + front-end screenshots

Preferred capture path is now `scripts/capture-camofox-screenshots.py`: real Camofox browser session against the live WordPress Docker instance (`vyg-wp`), one-time dev login token (no password typed), temporary `siteurl/home=http://vyg-wp` while capturing, automatic restore to `http://localhost:8000` afterward. This supersedes the older `scripts/capture-screenshots.sh` fallback, which rendered curl-fetched admin HTML via `file://` and lost WP admin chrome styling.

| Page | Live Camofox screenshot | Approx size |
| --- | --- | --- |
| WordPress dashboard with Vector YouTube Gallery widget visible | ![](screenshots/camofox/01-dashboard.png) | 293 KB |
| Sources page with status badges + Sync-now + Disconnect + Auth Mode column | ![](screenshots/camofox/02-sources.png) | 232 KB |
| Feeds list view (saved feeds table + shortcode display) | ![](screenshots/camofox/03-feeds-list.png) | 144 KB |
| Feeds edit form (name/status/source/layout/display/filter/sort/custom CSS) | ![](screenshots/camofox/04-feeds-edit.png) | 219 KB |
| Privacy & Compliance (stored data, retention, clean uninstall, disconnect, export/import) | ![](screenshots/camofox/05-privacy.png) | 338 KB |
| Diagnostics (API status, OAuth health, quota, sync jobs, source health, stale, errors) | ![](screenshots/camofox/06-diagnostics.png) | 340 KB |
| Videos moderation (search, filter, hide/pin/reclassify) | ![](screenshots/camofox/07-videos.png) | 216 KB |
| System Info (copy-to-clipboard, table counts, cron events) | ![](screenshots/camofox/08-system-info.png) | 259 KB |
| Front-end gallery — feed-by-uuid shortcode rendering 2 videos with real thumbnails | ![](screenshots/camofox/09-frontend-feed.png) | 252 KB |
| Settings OAuth tab — mode selector, sealed client config status, callback URL, enabled connect/reconnect, disconnect/delete controls | ![](screenshots/camofox/10-settings-oauth.png) | 283 KB |

Notes from the live browser review:
- Camofox had to be attached to `vyg_net`; otherwise `browser_navigate`/Camofox cannot reach `vyg-wp`.
- WP `siteurl`/`home` must temporarily use `http://vyg-wp` during browser capture so redirects stay inside the Docker network.
- Dev/mock video rows need realistic `thumbnail_*` values; otherwise the front-end renders black thumbnail cards and the screenshots are not useful for UI/UX review.

### Phase 7 — OAuth Account Connection

Goal: add first-class OAuth support for operators who prefer channel-owner authorization over public API-key mode, while preserving API-key mode for lightweight/self-hosted installs.

- [x] 7.1 OAuth app prerequisites documented: redirect URI, required scopes, Google Cloud consent-screen notes, dev-mode caveats, and secret storage expectations — `docs/oauth-setup.md`
- [x] 7.2 `src/YouTube/OAuthClient.php` implements `ApiClientInterface` using access tokens, refresh tokens, token expiry, and automatic refresh-on-401; `youtube.oauth_api` service registered
- [x] 7.3 `src/Settings/OAuthTokenRepository.php` stores refresh/access tokens encrypted/sealed; never autoload; never logs token material; `oauth.tokens` service registered in `Plugin.php`
- [x] 7.4 Settings UI adds OAuth tab: client ID/secret status, callback URL, save/delete config, local disconnect, enabled connect/reconnect link, and API-key/OAuth mode selector; Camofox screenshot captured at `screenshots/camofox/10-settings-oauth.png`
- [x] 7.5 OAuth callback handler validates nonce/state, exchanges auth code, stores tokens, records connected account/channel identity, and redirects to admin status page; live smoke verifies authorization URL/state hashing without calling Google token/API endpoints
- [x] 7.6 Disconnect flow revokes OAuth token via Google endpoint where possible, always deletes local OAuth tokens, flips global disconnect back to API-key mode, preserves local metadata unless clean-uninstall is enabled, and keeps all-source disconnection behavior in Privacy/Compliance
- [x] 7.7 Source add/resolution can use OAuth mode for connected-account/private access cases while retaining public API-key behavior; new source rows persist `auth_mode=oauth` when OAuth mode is selected, access gating requires connected OAuth tokens outside mock mode, and the Sources UI exposes the current credential mode plus an `Auth Mode` column
- [x] 7.8 Diagnostics page shows OAuth health: connected account, masked client ID, token age, expiry/expired state, refresh-token presence, scopes, last refresh error, and redacted token metadata only; live smoke verified no raw client secret/access/refresh token leakage and fixed Diagnostics SQL column drift (`last_error_code`/`last_error_message`, `last_success_at`)
- [x] 7.9 Unit tests: token repository + OAuth client refresh behavior + callback state validation + disconnect revoke/failure cleanup + source-mode gating + diagnostics redaction coverage complete
- [x] 7.10 E2E/browser verification: live OAuth flow completed end-to-end against the real Google account `Stephen Vidal` (`UCAjP3V9fUdBX4jOqH1RjmAQ`); channel `The Way Of Holiness Broadcast` (`UCETTSWoXxA-oEbwxqpbVf-w`) resolved and synced (50 videos indexed at 3 YouTube Data API units); front-end gallery `https://srv1388017.tail209ed.ts.net/youtube-gallery-test/` renders 12 cards with real video IDs and zero YouTube API calls on render; `headers already sent` warnings fixed on `SettingsPage` + `SourcesPage` via output buffer; `dev/.env` flipped to `VYG_USE_MOCK=0` so the live OAuth client is active end-to-end

### Phase 8 — Multi-source Feeds + Feed Portability

Goal: let operators build higher-level feeds from multiple channels/playlists/manual video sets and move those feeds between sites.

- [x] 8.1 Extend feed schema/config to support multiple source IDs plus include/exclude lists without breaking existing single-source feeds
- [x] 8.2 FeedQuery supports mixed sources with deterministic sort, de-duplication by YouTube video ID, source status filtering, and per-source weighting/pinning rules
- [x] 8.3 FeedsPage UI supports adding/removing/reordering multiple sources, manual video IDs, and per-source badges in the edit form
- [x] 8.4 Public REST feed endpoint supports saved mixed feeds by `feed_uuid` without exposing internal source IDs or admin-only metadata
- [x] 8.5 Feed import/export JSON: export selected feeds + dependencies; import with conflict handling (replace/duplicate/skip), source remapping, and schema versioning
- [x] 8.6 Admin REST endpoints for feed import/export with nonce + capability checks; large payload and malformed JSON handling; audit log of import operations
- [x] 8.7 Unit tests: mixed-feed queries (10), de-duplication, import/export round-trip + source-remap + version-rejection + skip-mode (6), plus FeedRepository decode_config coverage and per-feed source-remap tests
- [x] 8.8 E2E/browser verification: front-end rendering of mixed and single-source saved feeds; verify-public-safety.py asserts no `data-source-uuid` attribute and no internal source UUIDs leak anywhere on the public front-end; 2 new Camofox screenshots (15-frontend-multi-source-public-safe.png, 16-frontend-single-source-public-safe.png); fixed shortcode to set `public_safe=true` for `feed_uuid` rendering (Phase 8.4 attribute toggle now applies to shortcode path)

### Phase 9 — Advanced Layouts + Front-end Polish

Goal: broaden front-end presentation options while maintaining no-API-on-render and accessible, responsive output.

- [x] 9.1 Masonry layout: CSS-first responsive masonry/waterfall layout with graceful fallback and theme override template
- [x] 9.2 Carousel/slider layout: accessible keyboard navigation, reduced-motion support, touch support, and no jQuery dependency
- [x] 9.3 Single-video/hero layout: featured latest video or manually pinned video with playlist/gallery below
- [x] 9.4 Block pattern library: prebuilt patterns for channel grid, shorts wall, live/replay hub, and featured-video landing section
- [x] 9.5 Schema.org markup: `VideoObject`/`ItemList` JSON-LD using locally cached metadata only, with per-feed toggle and validation notes
- [x] 9.6 White-label styling presets: preset themes, CSS variables, spacing/card controls, and preview in the Feed Builder
- [x] 9.7 Front-end performance pass: responsive image sizes, lazy iframe loading, asset splitting by layout, no duplicate enqueues across multiple feeds
- [x] 9.8 Unit tests: template escaping, layout dispatch, asset enqueue behavior, schema output, and CSS scoping for new layouts
- [x] 9.9 E2E/browser verification: Camofox screenshots for masonry, carousel, hero, mobile viewport; verify keyboard/focus behavior where scriptable

### Phase 10 — Page Builder + Commerce Integrations

Goal: make the plugin usable in common WordPress site-builder workflows without forcing manual shortcodes.

- [ ] 10.1 Elementor widget: feed selector, layout controls, responsive controls, editor preview, and front-end render via existing Renderer
- [ ] 10.2 Divi module: feed selector, layout/design controls, Visual Builder preview, and front-end render via existing Renderer
- [ ] 10.3 WooCommerce/product CTA integration: optional per-video/per-feed CTA button, product link mapping, and compliance-safe local metadata usage
- [ ] 10.4 Gutenberg block polish: feed picker UI, inspector controls matching Feed Builder options, server-rendered preview loading/error states
- [ ] 10.5 Integration safety: all builder controls sanitize values, respect capabilities, and never expose API keys/tokens in editor payloads
- [ ] 10.6 Unit/integration tests: widget registration guards when Elementor/Divi/WooCommerce are absent; render parity with shortcode/block
- [ ] 10.7 E2E/browser verification: builder pages render when plugins are active or skip gracefully when not installed; capture screenshots for available integrations

### Phase 11 — Analytics + Moderation Workflows

Goal: help operators understand feed performance and manage large video libraries efficiently without external tracking by default.

- [x] 11.1 Local analytics model: optional event table for impression/play/lightbox/load-more events with retention and privacy toggle
- [x] 11.2 Analytics dashboard: top videos, feed views, click/play rates, source freshness, quota usage trends, sync health, and date-range filters
- [x] 11.5 CSV/JSON export for analytics with capability checks and no secrets (moderation export remains tied to 11.3)
- [x] 11.6 Privacy controls: analytics off by default or clearly disclosed; retention controls exposed; export/erase behavior documented
- [x] 11.7 Unit tests: analytics event writes, aggregation queries, retention cleanup, export sanitization
- [x] 11.3 Advanced moderation queues: hidden candidates, unavailable videos, stale metadata, manual-review flags, and bulk approve/hide/classify actions, plus moderation CSV/JSON export
- [x] 11.4 Saved filters and bulk actions for VideosPage: content type, source, availability, live state, pinned/hidden, date ranges
- [x] 11.8 E2E/browser verification: analytics dashboard, moderation queues, and videos page render through Dockerized Playwright/Chromium on `vyg_net` with seeded data screenshots and `api_quota_delta=0`

### Phase 12 — Operations, Scale, and Multisite

Goal: harden the plugin for larger libraries, multisite installs, and operator automation.

- [x] 12.1 WP-CLI command suite: sync source/feed, list jobs, retry failed jobs, export/import feeds, run retention, diagnostics snapshot
- [x] 12.2 Action Scheduler adapter for sync jobs with migration path from WP-Cron and feature flag fallback to current scheduler
- [x] 12.3 Advanced object-cache support: `FeedQueryCache` extends `FeedQuery` (decorator pattern, drop-in), uses `wp_cache_*` with multisite-safe keys (blog id + cache-version counter for invalidation when no `wp_cache_flush_group` is available). New `cache_enabled` + `cache_ttl_seconds` settings. `wp vyg cache` and `wp vyg cache-flush` subcommands; `wp vyg diagnostics` now shows a Cache section. 19 new unit tests (361 / 1013 / 0 / 3 skipped).
- [x] 12.4 Multisite network tools: new `VectorYT\Gallery\Multisite\NetworkPolicy` (single source of truth for per-site policy). On `activate_*` (network activation), the hook walks every site via `switch_to_blog`/`restore_current_blog` and runs `Plugin::on_activate()` per site. New `wp vyg network-diagnostics` (per-site row table + JSON). New `wp vyg site-cleanup [--site-id=N] --yes` (drops vyg_* tables, options, cron, transients; refuses to run without `--yes`). Idempotent re-seed script `dev/reseed-phase12.php` for local-only data. 8 new unit tests (369 / 1027 / 0 / 3 skipped).
- [ ] 12.5 Log rotation and configurable log levels: admin controls, redaction validation, max file size, and optional centralized shipping hook
- [ ] 12.6 Large-library performance: query indexes review, pagination strategy, batch sizes, memory limits, and admin list-table performance
- [ ] 12.7 CI smoke hardening: install WordPress in Docker, activate plugin, run migrations, hit key admin/front-end pages, and run `make test-unit`
- [ ] 12.8 Unit/integration tests: WP-CLI commands, scheduler adapter, cache invalidation, multisite option/table behavior where feasible
- [ ] 12.9 E2E verification: Docker smoke run + Camofox screenshot capture script succeeds in a clean environment

### Phase 13 — Packaging, Updates, and Commercial/Distribution Layer

Goal: prepare the plugin for real distribution while keeping the core usable for self-hosted/free deployments.

- [ ] 13.1 Release packaging script: build production zip, exclude dev files, include vendor/assets, validate plugin headers and text domain
- [ ] 13.2 Upgrade/migration test matrix: clean install, upgrade from each schema version, deactivate/reactivate, uninstall preserve/delete paths
- [ ] 13.3 Licensing/update-server abstraction: optional update endpoint client, license status UI, and no hard dependency for self-hosted/free users
- [ ] 13.4 Internationalization pass: POT generation, translator comments, text-domain consistency, date/number localization
- [ ] 13.5 Accessibility audit: admin pages, front-end layouts, lightbox/carousel keyboard behavior, color contrast, reduced motion
- [ ] 13.6 Security audit: nonce/capability map, REST permission callbacks, escaping/sanitization review, secrets redaction, OAuth token storage review
- [ ] 13.7 Documentation set: install guide, API-key mode, OAuth mode, feed builder, privacy/compliance, shortcode/block reference, troubleshooting, screenshots
- [ ] 13.8 Final release candidate E2E: fresh Docker site, Camofox screenshots, zero front-end API calls, unit tests, packaging smoke, and changelog update

## Deferred Work

| Item | Reason Deferred | Resume Condition |
|---|---|---|
| (none) | Former Phase 7+ deferrals expanded into Phases 7–13 | Continue with Phase 7.1 |

## Blocked Work

| Item | Blocker | Needed To Unblock |
|---|---|---|
| Live OAuth authorization E2E | Requires real Google Cloud OAuth client ID/secret and approved redirect URI | User supplies/provisions OAuth app credentials out-of-band; never paste secrets into chat |

## Partial Work

| Item | Completed Portion | Remaining Work |
|---|---|---|
| CI smoke | Docker stack, WP install, plugin activation, PHPUnit, and Camofox screenshots have all been verified manually | Phase 12.7 turns these manual checks into automated CI |

## Lessons Learned (Phase 0)

- **Bind-mount file permissions**: Files written by `write_file` on this host come out as `0600`. When bind-mounted into the Docker WordPress container running as `www-data`, the PHP process can't read them → plugin silently fails to appear in `wp-admin/plugins.php` (no error, just absent). Workaround applied: `find . -type f -exec chmod 644 {} \;` after writes. Long-term fix: add a `Makefile`/`scripts/fix-perms.sh` invoked before `docker compose up`. Also worth a `.docker/entrypoint.sh` that chmod-gids the bind-mount to match www-data.
- **Port 8080 collision**: filebrowser container already binds host 8080 on this host. Adminer moved to 8090. Documented in `docker-compose.yml` comment and `.env.example`.
- **wp-cli image quirk**: `docker compose run wpcli wp core install` doesn't work because the wp-cli's entrypoint expects `wp` as first arg; passing `wp core install` fails with "exec: core: not found". Workaround: `run --rm wpcli -- core install ...`. Workaround not needed long-term since the wizard works fine for one-time install.

## Lessons Learned (Phase 1)

- **WP_DEBUG_LOG goes to /dev/stderr** in the official `wordpress:cli-php8.2` image, not to a file. To see real errors when WP shows "critical error", use `docker logs vyg-wp` — the Apache error stream is where PHP errors land.
- **Composer install inside the wp-cli container needs** `mkdir -p /tmp/composer-bin` + `COMPOSER_HOME=/tmp/.composer` (www-data can't write to `/usr/local/bin` or `/.composer`). Also the `vendor/bin/*` scripts need `chmod 755` after install because the bind-mount preserves host-side perms (where they came out as `0600` from `write_file`).
- **PHPUnit `final` classes can't be mocked**. Use real instances or extract an interface. We hit this on `Logger` — switched tests to `new Logger()`.
- **WP constant `DAY_IN_SECONDS` not defined in unit tests**. The bootstrap needs to define `DAY_IN_SECONDS`, `HOUR_IN_SECONDS`, `MINUTE_IN_SECONDS` for any code that uses them outside a real WP boot.
- **Plugin autoload must reach `vendor/autoload.php`**, not just `src/Plugin.php`. Without it, only Container/Plugin get loaded and the first call to `Container::get('admin.menu')` throws "Class not found". Fix: plugin header file checks `vendor/autoload.php` and uses it if present, falls back to manual requires.
- **`docker compose restart` doesn't always pick up new env vars**. Use `up -d --force-recreate <service>` when adding environment entries to a service. Otherwise the container keeps the old env.
- **PHPUnit test discovery** requires one class per file. Two test classes in one file produce a "Class ... cannot be found" warning + only one of the classes runs.

## Lessons Learned (Phase 2)

- **dbDelta() is fragile on column changes**: removing a column requires a `DROP COLUMN` SQL line; otherwise dbDelta silently keeps the column. Always re-read `dbDelta`'s output for "Created/Updated" tables. We avoided column drops in 0.1.0 schema but should add a self-test in CI for Phase 2.5+.
- **`SyncLogRepository` was marked `final` and broke PHPUnit mocking**. Dropped `final` to allow mocking in `RetryPolicyTest::test_schedule_retry_*`. Production code doesn't depend on finality, so this is safe. (Worth noting: any class we want to mock in tests must not be final.)
- **PHP anonymous class wpdb stub needs `prefix`, `insert_id`, and `prepare()`**. The production code reads `$wpdb->prefix`, `$wpdb->insert_id`, and calls `prepare()`. A bare `class { insert() }` stubs only the bare minimum and triggers a wave of "undefined property" warnings. Stub the full surface even if the test doesn't use it.
- **Plugin activation hook fires via `register_activation_hook` callback registration order**, but only when the URL parameter is exactly `action=activate&plugin=...&_wpnonce=...`. A curl with the wrong URL silently no-ops. WP-CLI's `activate_plugin()` is the most reliable way to trigger activation from outside a real browser.
- **WP redirects after `action=activate` (HTTP 302)** — the curl `-L` follows but the redirect query string often drops parameters. That's why my first curl-driven activation returned 200 but didn't actually activate. Use direct `wp_set_active_and_valid_plugin` or call `activate_plugin()` from a wp-cli container for reliable scripted activation.
- **Schema method-name collision**: I named CREATE-TABLE methods `sources()`, `videos()`, etc. — but `Schema::vyg_sources()` doesn't exist as a method; only `self::sources()` does. The static method array referenced nonexistent names and only surfaced as a fatal error at install-time. Lesson: pick unambiguous method names like `create_sources()`, `create_videos()`, etc. for schema builders, OR write a thin class-name-suffix helper.
- **WP-Cron hook args use associative arrays**: `wp_schedule_single_event(time, 'hook', ['vyg_job_id' => $id, 'source_id' => $sid])` — the array is passed as the second arg to the hook callback. Our `SyncJobRunner::handle($args)` reads `args['vyg_job_id']` directly. Keep arg keys consistent across all callsites.

## Lessons Learned (Phase 3)

- **Classifier extraction revealed a design tension**: Phase 2 VideoNormalizer had a `detect_content_type` private method that combined live + shorts heuristics. Splitting into 3 classes makes each testable in isolation but exposes implicit precedence rules (live always wins over shorts). Phase 3 codifies this by checking live first in the orchestrator (VideoNormalizer).
- **Shorts classification has 3 independent signals but no reliable vertical-orientation data** in the YouTube API at the videos.list level. Without parsing the player embed HTML for `<iframe width="..." height="...">`, we can't tell vertical from horizontal. Phase 3 ships a conservative classifier: tag-promoted → confirmed, otherwise standard. Phase 3.5 will parse player embed dimensions for proper vertical detection.
- **Manual override semantics matter**: do you override only content_type, or also live_status + availability? Phase 3 ships content_type-only override (the manual_content_source + manual_reason columns document this for future auditors). Live and availability are still auto-derived on the next sync.
- **`dbDelta` adds columns idempotently** but the migration is invisible unless we bump `VYG_DB_VERSION`. Without a version bump, an existing install would never re-run the schema and the new `manual_content_source` column would never be added. Bumped to 0.2.0; `dbDelta: vyg_videos changes: 2` confirms the 2 new columns.
- **SettingsRepository `save_posted` MUST drop unknown keys** (defense in depth — never trust posted form data). I tested by POSTing `injection_attempt=<script>alert(1)</script>` — the value is silently discarded. This prevents stored XSS via the Settings page even if a future field name collision occurs.
- **Hermes display redaction eats tokens in `***` and obfuscates terminal output**. When running `git push https://x-access-token:$TOKEN......` via terminal(), Hermes replaced the token in the eval line so the shell saw `***` instead of the real token → 401. Workaround: use `gh auth setup-git` to configure the credential helper, then plain `git push`.

## Lessons Learned (Phase 4)

- **Patchwork redefinition whitelist is plugin-scoped, not project-scoped**: Brain\Monkey uses Patchwork to stub WP functions. Internal PHP functions (e.g. `is_readable`, `number_format`, `file_exists`, `md5`) MUST be listed in `patchwork.json` at the plugin root, otherwise `Brain\Monkey\Functions\when('is_readable')->alias(...)` throws `NotUserDefined`. The whitelist is per-plugin, not per-test, so it lives at the project root.
- **WP needs `index.asset.php` next to `editorScript` JS files**: When `block.json` declares `"editorScript": "file:./index.js"`, WP loads `index.asset.php` for the dependencies array and version. Without it, `register_block_script_handle` throws a "missing asset file" notice and the editor preview fails. Generate it with `wp-scripts` or hand-write a small `return [ 'dependencies' => [...], 'version' => '0.1.0' ];` file.
- **The zero-API-on-render invariant is testable**: After E2E verification, render the front-end page 5 times and confirm `wp_vyg_api_quota_log` has 0 new rows. Phase 0 requirement (no API calls during front-end rendering) holds by construction because `FeedQuery` only reads from `wp_vyg_videos` / `wp_vyg_sources` / `wp_vyg_playlist_video_map` and never references the YouTube API client. This is a strong architectural test for any future change — keep it green.
- **WP-CLI in a bind-mounted container requires curl-install**: `wordpress:cli` image is not in the project's docker-compose; pulling it externally with `docker run` failed because the project network is `vyg_net`, not the auto-generated `vector-youtube-gallery_default`. Solution: install `wp-cli` directly inside the `vyg-wp` container via `curl -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar`. Then `docker exec -u www-data vyg-wp wp post create --path=/var/www/html ...` works as expected.
- **Brain\Monkey stubs must be called in EVERY test that uses WP globals** — forgetting `Brain\Monkey\setUp()` + `BrainHelpers::stubEscapeFunctions()` in `setUp()` causes cryptic "Call to undefined function esc_html()" errors. The fix is to put both in `setUp()` and `Brain\Monkey\tearDown()` in `tearDown()`.
- **WordPress block.json attribute keys must match PHP render callback exactly**: When `attributes.source_uuid` is declared in block.json as `type: string`, the PHP render callback receives it as `string`. Case-sensitive: `sourceUuid` (camelCase from JS) vs `source_uuid` (snake_case from PHP) is a common gotcha. Phase 4 used snake_case throughout for consistency with REST params.

## Lessons Learned (Phase 5)
- **dbDelta is invisible without a version bump**: Like Phase 3, adding `vyg_previous_streams` table + new columns to `vyg_videos` only takes effect when `VYG_DB_VERSION` changes. The trick: on deactivation→reactivation, the `register_activation_hook` runs `Installer::install()`, which re-runs `dbDelta()` against all schemas in `Schema::all_create_statements()`. For existing installs, you can trigger this manually with `wp plugin deactivate vector-youtube-gallery && wp plugin activate vector-youtube-gallery`.
- **WP-Cron custom intervals need both schedule + event registration**: The 5-minute schedule is registered via `add_filter('cron_schedules', ...)` returning a new entry with `interval` and `display`. The event is then scheduled with `wp_schedule_event(time() + MINUTE_IN_SECONDS, 'vyg_five_minutes', 'vyg_cron_live_poll')`. Verify with `wp cron event list` + `wp cron schedule list` — both should show your custom schedule name and event.
- **`final` keyword blocks test doubles**: When a class is `final`, PHPUnit can't extend it for a fake. Phase 5 had to drop `final` from `QuotaTracker`, `SettingsRepository`, and others. Phase 3 already dropped it from `SyncLogRepository`. Trade-off: lose the "this won't be subclassed" guarantee, gain testability. Acceptable for plugin code that goes through DI.
- **`$wpdb` needs `ARRAY_A` constant + `get_row` + `get_col` stubs in tests**: Phase 5 used `$wpdb->get_results($sql, ARRAY_A)` in LiveQuery and `$wpdb->get_row($sql, ARRAY_A)` in LiveStatusPollJob. Brain\\Monkey doesn't auto-define WP constants; bootstrap.php defines `ARRAY_A` as a passthrough string. The fake `$wpdb` must implement `get_row` (single result) and `get_col` (column array) in addition to `get_results` and `get_var`.
- **Constructor signatures must match when extending parent classes in tests**: Phase 5 hit `Declaration of FakeSyncLogRepository::create_job(string $type, int $source_id = 0): int must be compatible with SyncLogRepository::create_job(string $job_type, ?int $source_id = null, ?array $cursor = null): int`. Test fakes that extend production classes must use the EXACT parameter names + nullability of the parent. PHP enforces this strictly; type compatibility is by signature, not just types.
- **`update_by_id()` vs `mark_unavailable()` divergence**: Phase 2's `mark_unavailable()` took a `reason` arg and stored it in `availability_status`. Phase 5 needed a generic `update_by_id(int $id, array $updates)` for the live-poll job's varied column updates (live_status + actual_start_at + concurrent_viewers, etc). Kept both methods — `mark_unavailable` is the targeted API for Phase 2 deletion detection; `update_by_id` is the bulk-update API for Phase 5 live status.

## Lessons Learned (Phase 6)
- **Always check the actual schema, not just the CREATE statement**: Phase 6 hit `Unknown column 'last_refreshed_at' in 'WHERE'` because DataRetentionManager guessed the column name. The real schema has `last_success_at` (when YouTube metadata was last successfully fetched). Lesson: before writing a column reference, `SHOW COLUMNS FROM wp_vyg_<table>` first; never assume the column name from the variable in the PHP code.
- **Same for `last_error` vs `last_error_code` + `last_error_message`**: Schema has split error storage into code + message columns (not a JSON blob). DisconnectManager initially tried `last_error='...JSON...'`, hit `Unknown column 'last_error'`. The split design is better for indexing/queries but requires the writer to use both columns.
- **`strip_tags()` is NOT enough for XSS protection in CSS context**: A `<script>` tag survived `strip_tags()` in some paths when stored via raw `$wpdb->update`. Defense-in-depth: also `str_replace(['<','>'], '', $css)` at the emit site (Renderer), and at the store site (FeedRepository). Two layers, not one — if the storage sanitizer misses, the output sanitizer catches it.
- **CSS scoping via tokenization**: A simple regex on selectors won't handle nested rules (`& .bar` inside `.foo { ... }`). The robust approach is to tokenize on `{`/`}` delimiters, track brace depth, and only rewrite top-level selectors (those before the first `{`). At-rules like `@media` and `@keyframes` pass through verbatim (don't try to recurse — the depth tracking handles them naturally).
- **REST routes via `add_action('rest_api_init', ...)` work with permalink-disabled WP if you use `?rest_route=`**: The "pretty" `/wp-json/vyg/v1/...` URLs require permalink structure set + .htaccess writable. For containerized dev where .htaccess can't be written, hit `/index.php?rest_route=/vyg/v1/admin/stats` instead. Same endpoint, different URL form, no 404.
- **Cookie-jar auth via wp-cli container needs `-b cookies.txt -c cookies.txt` on every curl**: Each `docker compose exec` spawns a fresh process that doesn't inherit curl session state. The pattern: `curl -c /tmp/c.txt -b /tmp/c.txt -X POST ...wp-login.php...` then for subsequent requests just `-b /tmp/c.txt`. Forget `-c` and your session cookies don't persist between requests.
- **Admin REST endpoints need both `manage_options` capability AND valid nonce**: Returning 403 with `rest_cookie_invalid_nonce` for valid users means the X-WP-Nonce was either stale or the session cookies didn't carry the auth context. The pattern in AdminRestController uses a custom `cap_and_nonce($cap)` permission_callback that checks both — this is more secure than WP's default `permission_callback` which only checks caps.
- **Custom CSS in `<style>` blocks must scope to a parent selector to prevent bleed**: An operator's `.vyg-card { color: red }` on one gallery would otherwise turn every `.vyg-card` on the entire site red. `Renderer::scope_css()` rewrites every top-level selector with `#vyg-feed-<uuid>` prefix, scoping the CSS to that feed's wrapper.
- **`uninstall.php` should respect an "uninstall mode" option**: Deleting all data on plugin delete is the wrong default — many operators uninstall to debug and expect to reinstall. The `vyg_clean_uninstall` toggle (admin-controlled) defaults to OFF (preserve data on delete); when ON, drop tables/options/cron. The uninstall.php checks this option and either exits early or proceeds with full cleanup.
- **`wp eval` is the safest way to run wp-cli DB operations when the wp-cli image's `mysql` client is missing**: Phase 6 hit `/usr/bin/env: 'mysql': No such file or directory` when running `wp db query`. Workaround: `wp eval "global \$wpdb; ..."` runs the SQL via PHP+wpdb instead. Also `wp eval` is non-interactive so it can run via Hermes terminal without a PTY.
- **Headless chromium inside the WP container resolves `localhost` to IPv6 `::1`, which Apache may not listen on**: Symptom: chromium screenshot returns 25-30KB blank canvas; `curl http://localhost/` fails with "Failed to connect" but `curl http://127.0.0.1/` works. Fix: pass `http://127.0.0.1/` (not `http://localhost/`) as the URL argument to chromium. The recipe in `references/wp-admin-screenshots-headless-chromium.md` doesn't mention this; it's specific to bind-mounted WP containers where Apache is configured for IPv4 only. Set `WP_URL_INNER=http://127.0.0.1` env var in `scripts/capture-screenshots.sh`.
- **Pretty permalinks (`/page-slug/`) require writable .htaccess inside the container**: For screenshots, hit `?page_id=N` form (works without permalinks) instead of `/page-slug/` (404s without .htaccess). The container's `.htaccess` is owned by `www-data` and the entrypoint doesn't write it; manual `cat > /var/www/html/.htaccess <<EOF ... EOF` from a `docker exec -u root` shell is the workaround.
- **Admin-page screenshot via `file://` URL works only when the curl-fetched HTML is self-contained**: The HTML WP emits for an admin page references `wp-admin/load-styles.php` and `wp-admin/load-scripts.php` for CSS/JS aggregation. When loaded via `file://`, those URLs resolve to local files (404), so the page renders unstyled. The workaround in `scripts/capture-screenshots.sh`: rewrite `<link>` and `<script>` tags to use absolute `http://127.0.0.1/...` URLs (TODO — currently the rendered admin chrome is plain HTML without the WP admin CSS). For now the screenshots are ~25-30% smaller than they would be with full admin chrome, but still readable.

## Session Log

### 2026-06-28 (cont)

- Trigger: "set a goal to keep iterating the next phase until complete"
- Mode: Development Execution Mode (Phase 2 — Sync Engine)
- Current phase: Phase 2 — Sync Engine
- Selected task: 2.1 → 2.20 full sync engine
- Work completed:
  - Created `src/Database/Schema.php` with 9 CREATE TABLE statements (sources, videos, playlists, map, feeds, feed_overrides, sync_jobs, sync_logs, quota_log)
  - Created `src/Database/Installer.php` (dbDelta runner + version tracking)
  - Created `src/Database/Migrator.php` (0.1.0 migration: `vyg_sources_draft` option → `vyg_sources` table)
  - Created `src/Repository/{Source,Video,Playlist,SyncLog}Repository.php` — full CRUD
  - Created `src/Normalize/VideoNormalizer.php` — ISO 8601 parsing, content_type detection (standard/short_candidate/live_active/live_upcoming/live_replay), availability detection (deleted/private/embed_disabled/available), manual override support
  - Created `src/Sync/RetryPolicy.php` — 5m/15m/1h/6h/24h ladder, hard-stop (auth/quota/forbidden/not_found/bad_request), rate-limit shorter ladder (1m/5m/15m), 6 max attempts
  - Created `src/Sync/SyncScheduler.php` interface + `WpCronSyncScheduler` implementation
  - Created `src/Sync/SyncJobRunner.php` base class (lifecycle: start_job → run → complete_job | fail_job)
  - Created `src/Sync/InitialImportJob.php` — channel/playlist/video initial sync with pagination + batched video metadata fetch
  - Created `src/Sync/IncrementalSyncJob.php` — 1-3 pages of playlist, stops when known IDs hit
  - Created `src/Sync/MetadataRefreshJob.php` — tiered refresh (new <48h, recently_ended <24h, normal 1-7d, archive 14-30d)
  - Created `src/Sync/DeletedVideoDetector.php` — classifies missing videos
  - Rewrote `src/Admin/SourcesPage.php` on `SourceRepository` with rate-limited Sync-now button + delete
  - Updated `src/Plugin.php` — wires 4 sync jobs, registers WP-Cron events `vyg_cron_incremental_all` (hourly) and `vyg_cron_metadata_refresh` (twicedaily), activates the Installer
  - Updated `src/YouTube/QuotaTracker.php` to use `wp_vyg_api_quota_log` table (replaces Phase 1 option-based log)
  - Created `tests/Support/BrainHelpers.php` — shared Brain\Monkey stubs
  - Created `tests/unit/Normalize/VideoNormalizerTest.php` (15 tests)
  - Created `tests/unit/Sync/RetryPolicyTest.php` (7 tests)
  - Fixed `tests/unit/YouTube/QuotaTrackerTest.php` to stub wpdb with prefix/insert_id/prepare
  - Fixed `tests/unit/Settings/SecretsRepositoryTest.php` to use BrainHelpers
  - Fixed bug: `Schema::all_create_statements()` called `vyg_sources()` etc. but methods were named `sources()` etc.
- Files changed: 13 new source files, 4 new test files, 6 files modified
- Tests run: `make test-unit` → **67 tests, 148 assertions, 0 failures, 0 errors**
- E2E verification:
  - Tables created: `wp_vyg_api_quota_log`, `wp_vyg_feed_video_overrides`, `wp_vyg_feeds`, `wp_vyg_playlist_video_map`, `wp_vyg_playlists`, `wp_vyg_sources`, `wp_vyg_sync_jobs`, `wp_vyg_sync_logs`, `wp_vyg_videos` (all 9)
  - Migration: Phase 1 mock source migrated from `vyg_sources_draft` option → `wp_vyg_sources` row (UUID `ffdf1663-c27f-...`, channel `@GoogleDevelopers`)
  - Sync ran against mock API: 2 videos indexed (dQw4w9WgXcQ + 9bZkp7q19f0), 1 playlist (`UU_x5...`), 2 playlist→video mapping rows, sync_jobs row success/1-attempt, 3 sync_logs entries (started → upserted → completed), 3 quota_log entries (channels/playlistItems/videos, 1 unit each)
  - SourcesPage reads from `wp_vyg_sources` and shows migrated source with Sync-now button
- Result: **Phase 2 COMPLETE**. All 20 checklist items done. 1 deferred to Phase 5 (LiveStatusPollJob), 1 partial (Action Scheduler swap — WP-Cron works fine, interface in place).
- Next recommended action: Begin Phase 3 — Classification (Shorts scoring refinement, manual override table, configurable Shorts threshold)
- Committed as `phase-2: sync engine (schema + jobs + repositories + admin)`
- Pushed to GitHub: https://github.com/vidalstephen/vector-youtube-gallery

## Feature Ideas Not Yet in Original Plan

| Feature | Value | Priority | Status |
|---|---|---|---|
| PHPUnit bootstrap | Block on shipping tests from Phase 1 | P0 (Phase 0.15) | shipped Phase 1 |
| Playwright browser tests | Validate gallery rendering in real browser | P1 (Phase 4.15) | queued |
| GitHub Actions CI | Catch regressions early | P2 | queued |
| Xdebug config for `dev/` | Dev experience | P3 | queued |
| WP_DEBUG_LOG mount in compose | Easier debugging | P1 (Phase 0.3) | shipped Phase 1 (via docker logs) |
| `wp-cli` container service | Scriptable admin tasks | P1 | shipped Phase 0 (compose profile=manual) |
| Object cache (Redis) profile | Performance testing per plan §19 | P2 | queued |
| Adminer → dump first-class migrations sql button | DX | P3 | queued |

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

### 2026-06-28 (continued — Phase 1 execution)

- Trigger: phase-worker auto-progression through Phase 1
- Mode: Development Execution Mode
- Current phase: Phase 1 — Public API Key Connection
- Selected task: All Phase 1 items 1.1–1.14 (per user: "auto-continue through Phase 1, then pause for review")
- Work completed:
  - 1.1 `src/Settings/SecretsRepository.php` — autoload=no API key storage, masked accessor, validated_at + last_error metadata
  - 1.3 `src/YouTube/ApiClientInterface.php` — channels_list, playlists_list, playlist_items_list, videos_list, revoke_token, mode()
  - 1.4 `src/YouTube/ApiKeyClient.php` — live HTTP client using WP_Http, structured logging, ApiException on errors
  - 1.5 `src/YouTube/MockApiClient.php` — fixture-based, stable hash keying, in-memory handlers for tests
  - 1.6 `src/YouTube/ChannelResolver.php` — accepts UC-id, @handle, /user/, /c/, /channel/, /@handle URLs; classifies then resolves
  - 1.7 `src/YouTube/PlaylistResolver.php` — accepts PL/UU/LL/FL/OL/PU prefixes + URLs; validates length + chars
  - 1.8 `src/YouTube/VideoMetadataFetcher.php` — accepts bare 11-char ID + watch?v=, /shorts/, /embed/, youtu.be URLs; batch up to 50
  - 0.14 `src/Settings/SettingsRepository.php` — non-secret config storage (defaults empty in Phase 1)
  - `src/YouTube/ApiException.php` — kind classification (auth/quota/forbidden/not_found/rate_limit/transient/bad_request), hard-stop vs soft-retry
  - `src/YouTube/QuotaTracker.php` — append-only option-based quota log (Phase 2 will move to `vyg_api_quota_log` table)
  - 0.13 `src/Admin/AdminMenu.php` — top-level "YouTube Gallery" menu with Phase 6 placeholders (Dashboard/Feeds/Live Display/Sync Queue/Privacy)
  - 1.2 `src/Admin/SettingsPage.php` — API key form (masked, nonce-protected, save/delete actions); 1.11 errors surfaced inline
  - 1.10 `src/Admin/SourcesPage.php` — list + add + delete; resolves input via matching resolver; shows kind-specific error messages
  - 1.12 `src/Admin/DiagnosticsPage.php` — client mode, masked key, last validation, last error, 24h quota estimate, recent 20 entries
  - `src/Plugin.php` — Container wired with all services + hooks (`admin_menu`)
  - `src/Container.php` — supports 1-arg factories that take the Container itself (needed for dependency chains)
  - `vector-youtube-gallery.php` — autoloads `vendor/autoload.php` when present (Composer PSR-4), falls back to manual require
  - 4 mock fixtures in `tests/fixtures/` — channels/playlists/playlistItems/videos __default.json
  - 0.15 PHPUnit scaffold: `phpunit.xml.dist`, `tests/bootstrap.php`, `tests/Support/OptionsBag.php`
  - 1.13 Unit tests: 46 tests / 77 assertions / 0 failures across `tests/unit/YouTube/` and `tests/unit/Settings/`
  - 1.14 Integration: end-to-end test via curl — submitted @GoogleDevelopers channel source via admin form, mock client resolved to UC_x5XG1OV2P6uZZ5FSM9Ttw, draft row stored in `wp_options vyg_sources_draft`, surfaced in Sources list table
- Files changed (Phase 1 only):
  - `src/Settings/SecretsRepository.php`
  - `src/Settings/SettingsRepository.php`
  - `src/YouTube/ApiClientInterface.php`
  - `src/YouTube/ApiKeyClient.php`
  - `src/YouTube/MockApiClient.php`
  - `src/YouTube/ChannelResolver.php`
  - `src/YouTube/PlaylistResolver.php`
  - `src/YouTube/VideoMetadataFetcher.php`
  - `src/YouTube/ApiException.php`
  - `src/YouTube/QuotaTracker.php`
  - `src/Admin/AdminMenu.php`
  - `src/Admin/SettingsPage.php`
  - `src/Admin/SourcesPage.php`
  - `src/Admin/DiagnosticsPage.php`
  - `src/Plugin.php` (rewired)
  - `src/Container.php` (1-arg factory support)
  - `vector-youtube-gallery.php` (autoload fix)
  - `phpunit.xml.dist`
  - `tests/bootstrap.php`
  - `tests/Support/OptionsBag.php`
  - `tests/fixtures/*.json` (4 files)
  - `tests/unit/Settings/SecretsRepositoryTest.php`
  - `tests/unit/YouTube/QuotaTrackerTest.php`
  - `tests/unit/YouTube/ChannelResolverTest.php`
  - `tests/unit/YouTube/PlaylistResolverTest.php`
  - `tests/unit/YouTube/VideoMetadataFetcherTest.php`
  - `tests/unit/YouTube/ApiExceptionTest.php`
  - `Makefile`
  - `docker-compose.yml` (added phpunit service, VYG_USE_MOCK passthrough)
  - `composer.json` (autoload-dev + dev deps already in place from Phase 0)
  - `composer.lock` (generated)
  - `DEV-CHECKLIST.md`
- Tests run:
  - `php -l` clean on all 11 new PHP files
  - `make test-unit` — **46 tests, 77 assertions, 0 failures, 0 errors** ✓
  - `curl /wp-admin/` — HTTP 200, 85KB, "YouTube Gallery" menu present ✓
  - `curl /wp-admin/admin.php?page=vector-youtube-gallery` — HTTP 200, Sources page with form ✓
  - `curl /wp-admin/admin.php?page=vector-youtube-gallery-settings` — HTTP 200, Settings page with API key form ✓
  - `curl /wp-admin/admin.php?page=vector-youtube-gallery-diagnostics` — HTTP 200, Diagnostics page with mode=mock ✓
  - `curl POST` add-source — DB row created with full resource (title, ID, thumbnail) ✓
- Result:
  - **Phase 1 complete.** 12 of 14 items `[x]`; 1.9 (SourceRepository) deferred to Phase 2 (needs DB schema); 1.2 split with SettingsPage (same deliverable). 46 PHPUnit tests pass. End-to-end admin flow verified: form → resolver → mock API → DB → rendered list.
- Next recommended action:
  - **Begin Phase 2.** Start with 2.1–2.3 (DB schema, Installer, Migrator) using the full `vyg_sources` / `vyg_videos` / etc. tables per plan §5. Migrate the `vyg_sources_draft` option rows into the new `vyg_sources` table during activation. Add 2.6–2.8 (SyncScheduler + InitialImportJob) and 2.15 (QuotaTracker writes from real client path).
- Cross-ref: 1.9 (SourceRepository) depends on 2.1 (DB schema). Wire it once the schema lands so the Sources admin page writes to a real table instead of `vyg_sources_draft`.### 2026-06-28 (continued — Phase 3 execution)

- Trigger: "/queue set a goal to keep iterating the next phase until completed"
- Mode: Development Execution Mode (Phase 3 — Classification)
- Current phase: Phase 3 — Classification
- Selected task: 3.1 → 3.9 full classification system
- Work completed:
  - Refactored `src/Normalize/VideoNormalizer.php` — constructor takes Shorts/Live/Availability classifier instances; added `VideoNormalizer::with_defaults()` factory for zero-DI use; orchestrator pattern (Live first, then Shorts, then Availability; manual override wins)
  - Created `src/Normalize/ShortsClassifier.php` — `classify()` returns short_confirmed/short_candidate/standard; honors manual_content_type via `normalize_manual()`; tag detection via strict equality (case-insensitive, # stripped)
  - Created `src/Normalize/LiveClassifier.php` — `classify()` returns 4 content_types; `classify_full()` returns content_type + live_status; precedence: actualStart without actualEnd → active; scheduled without actualStart → upcoming; both start+end → replay
  - Created `src/Normalize/AvailabilityClassifier.php` — deleted > private > embed_disabled > region-restricted > available; treats unlisted as available
  - Expanded `src/Settings/SettingsRepository.php` — 13 keys: shorts_max_duration_seconds, short_candidate_max_duration, live poll intervals, retention windows, auto-classify toggles; `save_posted()` coerces types + drops unknown keys (defense in depth)
  - DB schema bump 0.1.0 → 0.2.0: `wp_vyg_videos` adds `manual_content_source varchar(190)` and `manual_reason varchar(500)` columns
  - Created `src/Admin/VideosPage.php` — list/search/filter/paginate; per-row reclassify form (nonce-protected) with reason field; on submit persists override + runs renormalize; reconstructs minimal API resource from row to re-run normalizer
  - Updated `src/Admin/AdminMenu.php` — adds Videos submenu
  - Updated `src/Admin/SettingsPage.php` — adds Classification & Sync Settings section (7 number inputs + 3 checkboxes); handles `save_settings` op
  - Updated `src/Plugin.php` — wires `admin.videos` + passes to `admin.menu`
  - Created `tests/unit/Normalize/ShortsClassifierTest.php` (13 tests) — tag variants, vertical confirmation, duration thresholds, manual overrides, custom thresholds, normalize_manual aliases
  - Created `tests/unit/Normalize/LiveClassifierTest.php` (11 tests) — 4 states, precedence, classify_full variants
  - Created `tests/unit/Normalize/AvailabilityClassifierTest.php` (8 tests) — 5 states, priority order, region blocks, unlisted
  - Created `tests/unit/Settings/SettingsRepositoryTest.php` (8 tests) — defaults, get/set, save_posted int coercion, bool coercion, unknown-key drop, negative clamp, reset
  - Fixed `src/Normalize/VideoNormalizer.php` to accept both `manual_content_type` and `content_type` keys in $classification (Phase 2 legacy)
  - Fixed `src/Normalize/ShortsClassifier.php` to strip leading `#` in `normalize_manual()` (so `#Shorts` → short_confirmed)
- Files changed: 5 new source files, 4 new test files, 4 modified files
- Tests run: `make test-unit` → **108 tests, 223 assertions, 0 failures, 0 errors**
- E2E verification (live WP):
  - Bumped VYG_DB_VERSION to 0.2.0; ran Installer via wp-cli; dbDelta added `manual_content_source` + `manual_reason` columns to `wp_vyg_videos` (logged `changes: 2`)
  - Videos admin page renders with 2 indexed mock videos, filter + search form, 2 reclassify forms with reason inputs
  - Submitted reclassification: Rick Astley (id=1) → short_confirmed with reason; DB row now `content_type=short_confirmed, manual_content_type=short_confirmed, manual_content_source='admin:1:2026-06-28T19:12:54+00:00', manual_reason='test override from Phase 3 E2E'`
  - Forced re-sync via `do_action('vyg_sync_source_initial', ...)` → manual override preserved (`content_type=short_confirmed` survived re-normalization)
  - Settings save POST: 6 int fields coerced correctly (45/200/120/14/60/180); 3 bool fields coerced; unknown keys silently dropped
- Result: **Phase 3 COMPLETE**. 9 checklist items done. All 3 classifier classes + manual override + configurable thresholds + UI all green.
- Next recommended action: Begin Phase 4 — Rendering (shortcode, block, grid/featured layouts, asset enqueue)
- Committed as `phase-3: classification (Shorts/Live/Availability classifiers + manual override UI)`
- Pushed to GitHub: https://github.com/vidalstephen/vector-youtube-gallery
### 2026-06-28 (continued — Phase 4 execution)

- Trigger: "Continue"
- Mode: Phase 4 — Rendering
- Current phase: Phase 4 — Rendering
- Selected task: 4.1 → 4.15 (entire rendering system)
- Work completed:
  - Created 8 new source files: src/Render/{FeedQuery,TemplateLoader,VideoRenderer,Renderer,ShortcodeRegistrar,BlockRegistrar,AssetManager}.php + src/Render/render.php
  - Created 5 layouts: src/Render/Layouts/{LayoutInterface,GridLayout,ListLayout,FeaturedLayout,ShortsLayout,LiveLayout}.php
  - Created 5 layout templates: src/Render/templates/{grid,list,featured,shorts,live}.php (PHP emitting escaped HTML)
  - Created REST endpoint: src/REST/FeedController.php (vyg/v1/feed, public read, X-WP-Nonce protection)
  - Created block.json + index.js (editor) + render.php (frontend) + index.asset.php
  - Created 6 CSS files (base, grid, list, featured, shorts, live) + 2 JS files (lightbox, load-more)
  - Wired 8 new services into Plugin.php container + registered shortcode, block, REST routes, asset enqueue hooks
  - Created 2 new test files: VideoRendererTest (11 tests), TemplateLoaderTest (6 tests)
  - Extended BrainHelpers with stubs for: add_query_arg, esc_html__, esc_html_e, esc_attr__, get_template_directory, get_stylesheet_directory, current_user_can, is_readable, wp_create_nonce
  - Added patchwork.json with redefinition whitelist (number_format, strtotime, gmdate, is_readable, file_exists, md5)
  - Fixed tests/bootstrap.php to chdir to plugin root before loading Brain\Monkey (so patchwork.json is found)
- Tests run: `make test-unit` → **123 tests, 254 assertions, 0 failures, 0 errors** (was 108/223 in Phase 3)
- E2E verification (live WP):
  - Created page #7 with `[youtube_feed source_uuid="..." layout="grid" per_page="12" columns="3"]`
  - Front-end render at `/?page_id=7`: HTTP 200, 69674 bytes, contains `<div class="vyg-feed vyg-feed--grid">` with 2 articles (data-video-id="9bZkp7q19f0" + data-video-id="dQw4w9WgXcQ")
  - CSS enqueued: `vyg-css` (base) + `vyg-grid-css` (grid layout), both reachable at HTTP 200
  - JS enqueued: `lightbox.js`, reachable at HTTP 200
  - REST endpoint `GET /wp-json/vyg/v1/feed?source_uuid=...&offset=0&layout=grid&per_page=2`: HTTP 200 JSON with `html: ...`, `has_more: true`, `next_offset: 2`, `remaining: 2`
  - **Zero API calls invariant**: 5 consecutive page renders → 0 new entries in `wp_vyg_api_quota_log` ✓
  - DB state confirms 9 tables, 2 videos, 1 source — all from Phase 2/3 state
- Result: **Phase 4 COMPLETE**. 15 checklist items done. All 5 layouts + shortcode + block + REST + lightbox + load-more + theme override + assets all working with zero front-end API calls.
- Next recommended action: Begin Phase 5 — Live Fallback Module (LiveStatusPollJob + LiveLayout data layer + previous-streams storage)
- Committed as `phase-4: rendering (shortcode + block + 5 layouts + REST + assets + tests)`
- Pushed to GitHub: https://github.com/vidalstephen/vector-youtube-gallery

### 2026-06-28 (continued — Phase 5 execution)

- Trigger: "Continue next phase"
- Mode: Phase 5 — Live Fallback Module
- Current phase: Phase 5 — Live Fallback Module
- Selected task: 5.1 → 5.7 (entire live module)
- Work completed:
  - DB schema 0.3.0: added vyg_previous_streams table (10 columns, UNIQUE on (source_id, youtube_video_id)) + concurrent_viewers + last_live_poll_at on vyg_videos
  - src/Repository/PreviousStreamsRepository.php (172 lines): upsert, list_for_source, prune_to_limit (50 default), count_for_source, normalize_dt helper for ISO 8601 → MySQL
  - src/Normalize/LiveStatus.php (78 lines): classify_live_status (live/upcoming/ended/none) + classify_content_type (live_active/live_upcoming/live_replay/standard)
  - src/Sync/LiveStatusPollJob.php (315 lines): polls live_active + live_upcoming videos via videos.list (50 per batch), updates live_status + actual_start_at + actual_end_at + scheduled_start_at + concurrent_viewers + last_live_poll_at + view_count + title; promotes ended streams to vyg_previous_streams + prunes
  - src/Render/LiveQuery.php (143 lines): buckets_for_source returns {live, upcoming, replay} — sectioned query layer for LiveLayout
  - src/Render/Layouts/LiveLayout.php: now takes LiveQuery constructor dep, calls buckets_for_source, truncates to per_page
  - src/Render/templates/live.php: sectioned render (live_active → live_upcoming → live_replay), vyg_render_live_card helper, LIVE/UPCOMING/REPLAY badges, scheduled_start_at countdown, ended_at timestamp
  - src/VideoRepository.php: added update_by_id(int, array) for bulk updates, format_for_column helper
  - src/Settings/SettingsRepository.php: added live_previous_streams_retention (50) + live_replay_retention_days (14)
  - src/Renderer.php: takes LiveQuery as 4th constructor arg, dispatches to LiveLayout with dep injection
  - src/Plugin.php: registered repo.previous + render.live + sync.live_poll services; added vyg_cron_live_poll + vyg_five_minutes schedule + on_deactivate cleanup
  - tests/Support/BrainHelpers.php: added current_time + mysql2date stubs
  - tests/bootstrap.php: defined ARRAY_A constant
  - src/YouTube/QuotaTracker.php: dropped `final` (test double needed)
  - src/Settings/SettingsRepository.php: dropped `final`
  - Dropped `videos.list → 1` quota recording, changed to `record('videos.list', 200, null)` to match QuotaTracker::record signature
  - tests/fixtures/videos__live.json: 3-video fixture (active/upcoming/ended) for future live integration tests
  - tests/unit/Normalize/LiveStatusTest.php (10 tests)
  - tests/unit/Sync/LiveStatusPollJobTest.php (5 tests) with FakeApiClient + FakeVideoRepository + FakePreviousRepo + FakeSyncLogRepository + FakeQuotaTracker + FakeSettingsRepository + FakeWpdb
- Tests run: `make test-unit` → **136 tests, 287 assertions, 0 failures, 0 errors** (was 123/254 in Phase 4)
- E2E verification (live WP):
  - Plugin deactivation→reactivation triggered dbDelta: `changes: 1` on vyg_previous_streams (new table created), concurrent_viewers + last_live_poll_at columns added to vyg_videos
  - vyg_db_version bumped to 0.3.0
  - Inserted 2 mock live videos (mock_live_001, live_ended_001) + 2 manual previous_streams rows (prev1, prev2)
  - Ran `wp eval`-style `LiveStatusPollJob::run_poll()` → stats {checked:2, updated:2, ended:0, errors:0}; last_live_poll_at updated to 2026-06-28 19:51:07
  - WP-Cron: `wp cron event list` shows `vyg_cron_live_poll` running every 5 minutes; `wp cron schedule list` shows custom `vyg_five_minutes` (300 seconds) registered
  - Front-end page 7 with `[youtube_feed layout="live"]`: HTTP 200, renders `<div class="vyg-feed--live vyg-live">` with `vyg-live__section--replay` panel containing 2 cards (`Previous Stream 1`, `Previous Stream 2`) with `data-live-status="ended"`
  - **Zero API calls invariant**: 3 page renders → 0 new entries in wp_vyg_api_quota_log ✓
- Result: **Phase 5 COMPLETE**. 7 checklist items done. LiveStatusPollJob + LiveQuery + PreviousStreamsRepository + LiveLayout data layer all green.
- Next recommended action: Begin Phase 6 — Admin Polish (Dashboard widget, bulk ops on SourcesPage, GDPR export/erase hooks, uninstall cleanup, advanced Settings tabs, source import/export as JSON)
- Committed as `phase-5: live fallback module (LiveStatusPollJob + LiveQuery + previous_streams + LiveLayout data layer)`
- Pushed to GitHub: https://github.com/vidalstephen/vector-youtube-gallery

### 2026-06-28 (continued — Phase 6 execution)

- Trigger: "Continue with phase 6 until complete using the phase worker skill"
- Mode: Development Execution Mode (Phase 6 — Admin Polish)
- Current phase: Phase 6 — Admin Polish
- Selected task: 6.1 → 6.14 (entire admin polish phase)
- Work completed:
  - 6.1/6.2/6.4/6.5 verified already shipped (DashboardWidget + DashboardStats wired; SourcesPage shows status badges; VideosPage hide/pin/reclassify)
  - **Enhanced 6.4** DiagnosticsPage to 6 sections: API Status / Quota Usage / Sync job health / Source health / Stale videos / Recent errors (24h)
  - **Built 6.3** FeedRepository (CRUD + JSON config + custom_css sanitization with strip_tags + javascript: stripping + 64KB cap), FeedsPage (list + edit form with 13 fields), Renderer::scope_css (CSS selector scoping to wrapper_id), ShortcodeRegistrar::feed_uuid resolution path
  - **Built 6.7** DataRetentionManager (4 cleanup operations, cron `vyg_cron_data_retention`)
  - **Built 6.8** DisconnectManager (revokes API key + flips all sources to status='disconnected' with last_error_code/message)
  - **Built 6.9** PrivacyPolicyGenerator (10-section English text covering data collected, retention, third-party services, contact)
  - **Built 6.6** PrivacyPage (7 sections: counts / retention / clean-uninstall toggle / disconnect / export-import / policy / GDPR links) + admin-post `vyg_export_settings` handler
  - **Implemented 6.11** clean_uninstall toggle in `uninstall.php` (off = preserve data; on = drop everything)
  - **Built 6.12** AdminRestController under `/vyg/v1/admin/*` with cap + X-WP-Nonce enforcement: stats, feeds CRUD, disconnect, retention, import-settings
  - **6.13 Security**: identified XSS via custom_css where `<script>` survived strip_tags in some paths → added defense-in-depth at Renderer level (str_replace `<`/`>` before emit); SQL via $wpdb->prepare throughout; nonces on every admin POST + REST route
  - Wired all new services in Plugin.php container; added Feeds + Privacy submenus to AdminMenu
  - 4 new test files: tests/unit/Repository/FeedRepositoryTest.php (11 tests), tests/unit/Compliance/PrivacyPolicyGeneratorTest.php (4 tests), tests/unit/Render/RendererScopeCssTest.php (6 tests), plus existing Admin/DashboardStatsTest + GdprHooksTest + ImporterExporterTest
- Files changed: 6 new source files (FeedRepository, FeedsPage, PrivacyPage, DataRetentionManager, DisconnectManager, PrivacyPolicyGenerator, AdminRestController), 4 modified source files (Renderer, ShortcodeRegistrar, DiagnosticsPage, AdminMenu, Plugin, uninstall.php), 4 new test files
- Tests run: `make test-unit` → **170 tests, 363 assertions, 0 failures, 0 errors** (was 149/325 in Phase 5)
- E2E verification (live WP):
  - All 6 admin submenu pages return HTTP 200 (Sources, Feeds, Privacy, System Info, Diagnostics, Videos)
  - REST `GET /vyg/v1/admin/stats` with valid X-WP-Nonce → 200 JSON with full snapshot (sources/videos/quota/recent_jobs/previous_streams)
  - REST `POST /vyg/v1/admin/feeds` → 201 with feed_uuid, persists to `wp_vyg_feeds`
  - REST `POST /vyg/v1/admin/disconnect` → 200 `{revoked:false, sources_disconnected:1}`; key deleted from options; source.status='disconnected'; last_error_code='disconnected'
  - REST `POST /vyg/v1/admin/retention/run` → 200 with stats (after fixing wrong column name `last_refreshed_at` → `last_success_at`)
  - WP-Cron: `vyg_cron_data_retention` scheduled daily
  - Front-end E2E: created WordPress page with `[youtube_feed feed_uuid="f57c6cfe-..."]` → HTTP 200, 70KB; renders 2 videos (`data-video-id="9bZkp7q19f0"` + `"dQw4w9WgXcQ"`); `id="vyg-feed-f57c6cfe-..."` wrapper; `vyg-grid vyg-grid--cols-3` (from feed config)
  - Scoped CSS E2E: feed with `.vyg-card { background: red }` → rendered as `#vyg-feed-f57c6cfe... .vyg-card { background: red }` (no bleed)
  - XSS test: `<script>alert(1)</script>.foo { color: red } <img onerror=alert(2)>` stored via direct DB → output contains NO `<script>` tag, NO `onerror` attribute (defense-in-depth at Renderer caught it)
- Result: **Phase 6 COMPLETE**. All 14 items done. The MVP is feature-complete per the Phase 7+ deferrals list.
- Next recommended action: Begin Phase 7+ deferred work (OAuth account connection is the highest-value addition; needs Google Cloud OAuth client config + capability checks)

### 2026-06-29 — deferred roadmap expansion

- Trigger: "Next phase development of we've completed all current phases let's extend the DEV-CHECKLIST to include the remaining deferred components"
- Mode: Checklist Reconciliation Mode
- Current phase: Phase 7 — OAuth Account Connection
- Selected task: Convert Phase 7+ flat deferral bucket into concrete remaining roadmap phases
- Work completed:
  - Replaced `Phase 7+ — Deferred (post-MVP)` flat list with actionable Phases 7–13.
  - Expanded OAuth into Phase 7 with token storage, callback, diagnostics, disconnect, tests, and E2E scope.
  - Expanded mixed-source feeds and feed portability into Phase 8.
  - Expanded masonry/carousel/schema/white-label/block-pattern work into Phase 9.
  - Expanded Elementor/Divi/WooCommerce/Gutenberg polish into Phase 10.
  - Expanded analytics and moderation workflows into Phase 11.
  - Expanded WP-CLI, Action Scheduler, object cache, multisite, logs, performance, and CI smoke into Phase 12.
  - Expanded packaging, update/licensing abstraction, i18n, accessibility, security, docs, and release-candidate checks into Phase 13.
  - Reconciled stale early-phase markers: 0.12–0.15, 1.2, 1.9, 2.6, and 2.11 now reflect completed/deferred-to-new-phase reality.
  - Updated current status: Phase 7 is now the next active phase; former deferred bucket is empty.
- Files changed:
  - `DEV-CHECKLIST.md`
- Tests run:
  - Checklist grep: no active `[>]` deferrals remain outside the status legend; Phase 7 is now next actionable.
- Result:
  - Remaining work is now a concrete Phase 7–13 roadmap instead of a flat deferred list.
- Next recommended action:
  - Begin Phase 7.1: OAuth app prerequisites + encrypted token storage scaffolding, with live OAuth E2E blocked until Google OAuth client credentials are provisioned out-of-band.

### 2026-06-29 — Phase 7.1 OAuth prerequisites + token storage scaffold

- Trigger: "Next phase"
- Mode: Development Execution Mode (Phase 7 — OAuth Account Connection)
- Current phase: Phase 7 — OAuth Account Connection
- Selected task: 7.1 OAuth prerequisites + 7.3 sealed token-storage scaffold
- Work completed:
  - Added `docs/oauth-setup.md` covering Google Cloud prerequisites, redirect URI shape, read-only YouTube scope, local-dev caveats, and secret-handling rules.
  - Added `src/Settings/OAuthTokenRepository.php` for sealed OAuth client secret + access/refresh token storage.
  - Registered `oauth.tokens` service in `src/Plugin.php` for future OAuthClient/settings/callback wiring.
  - Extended `tests/Support/OptionsBag.php` to track the `autoload` flag so unit tests can assert OAuth options use `autoload=no`.
  - Added `tests/unit/Settings/OAuthTokenRepositoryTest.php` covering sealed storage, no-autoload behavior, safe status output, refresh-error metadata, one-time OAuth state consumption, and deletion.
- Files changed:
  - `docs/oauth-setup.md`
  - `src/Settings/OAuthTokenRepository.php`
  - `src/Plugin.php`
  - `tests/Support/OptionsBag.php`
  - `tests/unit/Settings/OAuthTokenRepositoryTest.php`
  - `DEV-CHECKLIST.md`
- Tests run:
  - `php -l` inside `vyg-wp` for touched PHP files → no syntax errors
  - `make test-unit` → **177 tests, 409 assertions, 0 failures, 0 errors**
  - Live WP smoke: resolved `oauth.tokens` from the plugin container, stored dummy OAuth client/tokens, verified raw storage did not contain dummy token text, then deleted dummy OAuth state/config/tokens.
- Result:
  - Phase 7.1 and 7.3 are complete; Phase 7 is now in progress.
- Next recommended action:
  - Begin Phase 7.2: implement `src/YouTube/OAuthClient.php` with authorization URL, auth-code exchange, token refresh, and ApiClientInterface methods using OAuthTokenRepository.

### 2026-06-29 — Phase 7.2 OAuthClient implementation

- Trigger: "Proceed"
- Mode: Development Execution Mode (Phase 7 — OAuth Account Connection)
- Current phase: Phase 7 — OAuth Account Connection
- Selected task: 7.2 OAuthClient implementation
- Work completed:
  - Added `src/YouTube/OAuthClient.php` implementing `ApiClientInterface` for OAuth mode.
  - Added authorization URL generation with read-only YouTube scope, offline access, consent prompt, and one-time state persistence.
  - Added auth-code token exchange and sealed token persistence through `OAuthTokenRepository`.
  - Added access-token expiry checks, refresh-token exchange, refresh-error metadata, and one retry on HTTP 401 responses.
  - Added OAuth Bearer-token YouTube API calls for channels/playlists/playlistItems/videos with `key` stripping so API-key material is never mixed into OAuth requests.
  - Added token revocation against Google's revoke endpoint with local token cleanup on success.
  - Registered `youtube.oauth_api` service in `Plugin.php` for the future mode selector.
  - Added `tests/unit/YouTube/OAuthClientTest.php` covering auth URL params/state hashing, code exchange, bearer request signing, refresh-before-request, 401 refresh retry, refresh-error persistence, revoke cleanup, and missing-token auth errors.
- Files changed:
  - `src/YouTube/OAuthClient.php`
  - `src/Plugin.php`
  - `tests/unit/YouTube/OAuthClientTest.php`
  - `DEV-CHECKLIST.md`
- Tests run:
  - `php -l` inside `vyg-wp` for touched PHP files → no syntax errors
  - `make test-unit` → **185 tests, 454 assertions, 0 failures, 0 errors**
  - Live WP smoke: resolved `youtube.oauth_api`, generated Google authorization URL, confirmed sealed client-secret/state storage did not leak raw dummy values, then cleaned all dummy `vyg_oauth_%` options.
- Result:
  - Phase 7.2 is complete; Phase 7.9 is partial because OAuth repo/client tests are done while callback/diagnostics tests wait for later UI/callback work.
- Next recommended action:
  - Begin Phase 7.4: add the OAuth settings tab, client credential status, connect/reconnect/disconnect controls, and API-key/OAuth mode selector.

### 2026-06-29 — Phase 7.4 OAuth Settings UI

- Trigger: "Next phase"
- Mode: Development Execution Mode (Phase 7 — OAuth Account Connection)
- Current phase: Phase 7 — OAuth Account Connection
- Selected task: 7.4 Settings OAuth tab + mode selector
- Work completed:
  - Added `api_mode` setting (`api_key` default, `oauth` optional) with whitelist sanitization in `SettingsRepository`.
  - Updated runtime client selection so non-mock installs use `youtube.oauth_api` when `api_mode=oauth`; development mock mode still takes precedence.
  - Added an OAuth tab to `SettingsPage` showing credential status, connected status, callback URL, masked client ID, token expiry/refresh error metadata, and OAuth client fields.
  - Added OAuth client config save, config delete, local token disconnect, and API-key/OAuth mode selector handling with nonce + capability checks.
  - Added disabled Connect/Reconnect placeholder button that documents it becomes active with the Phase 7.5 callback handler.
  - Updated `scripts/capture-camofox-screenshots.py` to capture `screenshots/camofox/10-settings-oauth.png`.
  - Captured a live Camofox screenshot of the OAuth settings tab with dummy sealed OAuth config, then cleaned all dummy `vyg_oauth_%` options and restored `api_mode=api_key`.
- Files changed:
  - `src/Admin/SettingsPage.php`
  - `src/Settings/SettingsRepository.php`
  - `src/Plugin.php`
  - `tests/unit/Settings/SettingsRepositoryTest.php`
  - `scripts/capture-camofox-screenshots.py`
  - `screenshots/camofox/10-settings-oauth.png`
  - `DEV-CHECKLIST.md`
- Tests run:
  - `php -l` inside `vyg-wp` for touched PHP files → no syntax errors
  - `make test-unit` → **187 tests, 457 assertions, 0 failures, 0 errors**
  - Live WP smoke: dummy OAuth config saved sealed (`raw_secret_leaked=false`), OAuth tab rendered through Camofox, screenshot size 273 KB, cleanup verified `[]` for `vyg_oauth_%` options.
- Result:
  - Phase 7.4 is complete. Phase 7.5 remains the next step because actual Google redirect/callback handling is intentionally not active yet.
- Next recommended action:
  - Begin Phase 7.5: implement `admin-post.php?action=vyg_oauth_callback`, validate state, exchange the auth code, store tokens/account identity, and redirect back to the OAuth settings tab with status.

### 2026-06-29 — Phase 7.5 OAuth callback handler + quota-safe testing guidance

- Trigger: "Continue with recommendations"
- Mode: Development Execution Mode (Phase 7 — OAuth Account Connection)
- Current phase: Phase 7 — OAuth Account Connection
- Selected task: 7.5 OAuth callback handler, implemented with no-real-Google-call validation so development can continue without public/shared OAuth credentials.
- Work completed:
  - Added `src/Admin/OAuthController.php` with connect URL generation, one-time state creation, callback state validation, auth-code exchange, connected channel identity lookup, safe admin redirects, and error-code reporting.
  - Wired `admin.oauth` into `Plugin.php` and registered `admin_post_vyg_oauth_connect` / `admin_post_vyg_oauth_callback` hooks.
  - Enabled the Settings OAuth tab Connect/Reconnect control instead of the Phase 7.4 disabled placeholder.
  - Added `OAuthTokenRepository::update_connected_account()` so callback can attach channel identity without touching sealed token material.
  - Added callback/unit tests using mocked Google token + YouTube channel responses; no real Google OAuth credentials or YouTube API quota required.
  - Updated `docs/oauth-setup.md` with explicit guidance that Google/YouTube does not provide public shared OAuth credentials, OAuth token exchange itself does not spend YouTube Data API quota, and development should use mocked responses/cache/fixtures.
  - Captured updated Camofox OAuth settings screenshot with enabled Connect/Reconnect control.
- Files changed:
  - `src/Admin/OAuthController.php`
  - `src/Admin/SettingsPage.php`
  - `src/Plugin.php`
  - `src/Settings/OAuthTokenRepository.php`
  - `tests/unit/Admin/OAuthControllerTest.php`
  - `tests/unit/Settings/OAuthTokenRepositoryTest.php`
  - `docs/oauth-setup.md`
  - `screenshots/camofox/10-settings-oauth.png`
  - `DEV-CHECKLIST.md`
- Tests run:
  - `php -l` inside `vyg-wp` for touched PHP files → no syntax errors
  - `make test-unit` → **191 tests, 485 assertions, 0 failures, 0 errors**
  - Live WP smoke via `wp eval-file`: resolved `admin.oauth`, generated Google authorization URL, verified OAuth state hash stored, verified dummy secret did not leak in raw option storage, and made no Google token/API calls.
  - Camofox screenshot capture: `screenshots/camofox/10-settings-oauth.png` at 275 KB, visually confirmed Connect/Reconnect control is enabled.
  - Cleanup verification: `vyg_oauth_%` options `[]`, `api_mode=api_key`, `siteurl/home=http://localhost:8000`.
- Result:
  - Phase 7.5 is complete locally and safely without real OAuth credentials. Live Google OAuth E2E remains blocked until the operator supplies real client credentials through the admin UI.
- Next recommended action:
  - Begin Phase 7.6: implement token revocation/disconnect flow using `OAuthClient::revoke_token()`, delete local OAuth tokens, preserve cached metadata, and add mocked revoke integration tests.

### 2026-06-29 — Phase 7.6 OAuth disconnect/revoke flow

- Trigger: "Continue"
- Mode: Development Execution Mode (Phase 7 — OAuth Account Connection)
- Current phase: Phase 7 — OAuth Account Connection
- Selected task: 7.6 OAuth disconnect/revoke flow.
- Work completed:
  - Added `OAuthController::disconnect_url()`, `disconnect_redirect_url()`, and `handle_disconnect()`.
  - Registered `admin_post_vyg_oauth_disconnect` in `Plugin.php`.
  - Settings OAuth tab now shows `Disconnect OAuth Account` when connected; it attempts Google revoke and always deletes local OAuth token state.
  - `DisconnectManager` now clears local OAuth tokens and resets `api_mode=api_key` during the global Privacy/Compliance disconnect path while preserving local metadata and retaining all-source status disconnection.
  - Privacy/Compliance copy now describes API-key + OAuth credential removal and local metadata preservation.
  - Added unit tests for successful OAuth revoke and failed-revoke local cleanup.
  - Captured updated Camofox OAuth settings screenshot showing connected status plus Connect/Reconnect, Delete Config, and Disconnect OAuth Account controls.
- Files changed:
  - `src/Admin/OAuthController.php`
  - `src/Admin/SettingsPage.php`
  - `src/Admin/PrivacyPage.php`
  - `src/Compliance/DisconnectManager.php`
  - `src/Plugin.php`
  - `tests/unit/Admin/OAuthControllerTest.php`
  - `screenshots/camofox/10-settings-oauth.png`
  - `DEV-CHECKLIST.md`
- Tests run:
  - `php -l` inside `vyg-wp` for touched PHP files → no syntax errors
  - `make test-unit` → **193 tests, 495 assertions, 0 failures, 0 errors**
  - Live WP smoke: seeded dummy sealed OAuth config/tokens, verified `admin.oauth` disconnect URL, verified raw token storage did not contain dummy tokens, captured Camofox screenshot, then cleaned all dummy OAuth state without calling Google.
  - Cleanup verification: `vyg_oauth_%` options `[]`, `api_mode=api_key`, `siteurl/home=http://localhost:8000`.
- Result:
  - Phase 7.6 is complete locally and safely without real OAuth credentials. Google revoke is best-effort; local token cleanup is guaranteed.
- Next recommended action:
  - Begin Phase 7.7: make source add/resolution explicitly use OAuth mode for connected-account/private access cases while preserving public API-key behavior.

### 2026-06-29 — Phase 7.7 OAuth-mode source add/resolution

- Trigger: "Next phase"
- Mode: Development Execution Mode (Phase 7 — OAuth Account Connection)
- Current phase: Phase 7 — OAuth Account Connection
- Selected task: 7.7 Source add/resolution can use OAuth mode while preserving API-key behavior.
- Work completed:
  - `SourcesPage` now receives `SecretsRepository`, `OAuthTokenRepository`, and `SettingsRepository` through the container instead of constructing a new secrets repository internally.
  - New sources persist `auth_mode` based on the configured credential mode: `api_key` or `oauth`.
  - Mock mode remains a test/dev API-client override, but is not persisted as a source credential mode.
  - Source add access gating now accepts API-key mode with an API key or OAuth mode with connected OAuth tokens; mock mode still satisfies access during unit/dev tests.
  - Sources UI now displays the active credential mode notice and an `Auth Mode` column in the sources table.
  - Added `SourcesPageTest` coverage for API-key/OAuth mode selection and access behavior under the test mock environment.
  - Live WP smoke seeded dummy sealed OAuth tokens and a dummy OAuth source, verified `auth_mode=oauth`, captured the Sources page, then removed all dummy OAuth/source state.
- Files changed:
  - `src/Admin/SourcesPage.php`
  - `src/Plugin.php`
  - `tests/unit/Admin/SourcesPageTest.php`
  - `screenshots/camofox/02-sources.png`
  - `DEV-CHECKLIST.md`
- Tests run:
  - `php -l` inside `vyg-wp` for touched PHP files → no syntax errors
  - `make test-unit` → **195 tests, 501 assertions, 0 failures, 0 errors**
  - Live WP smoke via `wp eval-file`: source created with `auth_mode=oauth`, dummy OAuth token storage did not leak raw tokens, Camofox Sources screenshot captured, then cleanup removed dummy source and OAuth options.
  - Cleanup verification: `vyg_oauth_%` options `[]`, `api_mode=api_key`, smoke source count `0`, `siteurl/home=http://localhost:8000`.
- Screenshot evidence:
  - `screenshots/camofox/02-sources.png` shows OAuth credential mode notice and Auth Mode column with `oauth` and `api_key` rows.
- Result:
  - Phase 7.7 is complete locally without real OAuth credentials or live YouTube API calls.
- Next recommended action:
  - Begin Phase 7.8: add OAuth health to Diagnostics with connected account, token age/expiry, refresh errors, revoked/expired state, and redacted token metadata only.

### 2026-06-29 — Phase 7.8 OAuth diagnostics health + 7.9 test completion

- Trigger: "Continue"
- Mode: Development Execution Mode (Phase 7 — OAuth Account Connection)
- Current phase: Phase 7 — OAuth Account Connection
- Selected task: 7.8 Diagnostics OAuth health and diagnostics redaction coverage.
- Work completed:
  - Added `OAuthTokenRepository::diagnostics_status()` with safe OAuth metadata only: client configured, masked client ID, redirect URI, connected flag, token type, refresh-token presence, expiry, token age, expired state, scopes, connected account, and last refresh error.
  - Updated Diagnostics page with an OAuth Health section that renders connected account, token metadata, expiry/remaining time, scopes, and last refresh error without displaying raw access tokens, refresh tokens, or client secrets.
  - Wired `oauth.tokens` into `admin.diagnostics` through the container.
  - Added diagnostics redaction unit coverage to `OAuthTokenRepositoryTest`.
  - Live Diagnostics smoke seeded dummy sealed OAuth config/tokens and a refresh error, rendered Diagnostics as admin, verified no database errors and no raw client secret/access/refresh token leakage, captured a Camofox screenshot, then cleaned all dummy OAuth state.
  - Fixed stale Diagnostics SQL references discovered by the live smoke: source health now uses `last_error_code`/`last_error_message`, and stale-video detection uses `last_success_at` instead of a non-existent `last_refreshed_at` column.
- Files changed:
  - `src/Admin/DiagnosticsPage.php`
  - `src/Plugin.php`
  - `src/Settings/OAuthTokenRepository.php`
  - `tests/unit/Settings/OAuthTokenRepositoryTest.php`
  - `screenshots/camofox/06-diagnostics.png`
  - `DEV-CHECKLIST.md`
- Tests run:
  - `php -l` inside `vyg-wp` for touched PHP files → no syntax errors
  - `make test-unit` → **196 tests, 515 assertions, 0 failures, 0 errors**
  - Live WP render smoke as admin: `has_db_error=false`, `has_oauth_health=true`, `has_channel=true`, `has_masked_client=true`, `leaks_access=false`, `leaks_refresh=false`, `leaks_secret=false`
  - Camofox screenshot capture: `screenshots/camofox/06-diagnostics.png` (340 KB)
  - Cleanup verification: `vyg_oauth_%` options `[]`, `api_mode=api_key`, `siteurl/home=http://localhost:8000`.
- Screenshot evidence:
  - `screenshots/camofox/06-diagnostics.png` shows API Status, OAuth Health, quota usage, sync job health, source health, stale videos, and recent errors without database-error banners.
- Result:
  - Phase 7.8 and remaining 7.9 diagnostics redaction coverage are complete locally without real Google OAuth credentials or live YouTube API calls.
- Next recommended action:
  - Finish Phase 7.10 by documenting local no-real-Google OAuth browser verification as complete and marking live Google OAuth E2E blocked/deferred until credentials are entered through the admin UI.

### 2026-06-29 — Phase 8.1 + 8.2 multi-source feeds

- Trigger: "lets continue development, next phase"
- Mode: Development Execution Mode (Phase 8 — Multi-source Feeds + Feed Portability)
- Current phase: Phase 8 — Multi-source Feeds + Feed Portability
- Selected tasks: 8.1 (multi-source feed schema) + 8.2 (FeedQuery mixed sources)
- Work completed:
  - Added `FeedRepository::normalize_source_config()` to canonicalize the legacy `{source_uuid}` form into a multi-source shape: `{sources:[{source_uuid, weight, pinned, label}, ...], manual_video_ids:[], exclude_video_ids:[], include_query:'any'|'all'}`. Legacy single-source rows decode to one entry in `sources[]` so existing feeds continue to render unchanged.
  - Added `FeedQuery::videos_for_feed()` and `count_videos_for_feed()` that pull videos per source via the existing `videos_for_source()`, append manual video IDs by direct lookup, dedupe by `youtube_video_id` (pinned sources first), apply `exclude_video_ids`, then sort by `orderby`+`order` and slice by `limit`+`offset`.
  - Added private helpers `videos_for_ids()`, `dedupe_videos()`, `sort_videos()` to keep the multi-source query composable.
  - Extended `Renderer` with a `render_multi_source()` path + shared `emit_html()`. The render call now accepts an optional `source_config` array; multi-source feeds route through the new path, single-source feeds keep the existing code path.
  - Wired `ShortcodeRegistrar` to pass `source_config` (the decoded `config['source']`) when the shortcode is a `feed_uuid` reference; the legacy `source_uuid` path is unchanged.
  - Fixed a back-compat regression introduced by the normalized config: when a feed references a single source via `feed_uuid`, the registrar now falls back to `config['source']['sources'][0]['source_uuid']` if `config['source']['source_uuid']` is absent.
  - Updated `FeedRepositoryTest` with 4 new test cases: legacy migration, multi-source shape, weight coercion (out-of-range + non-numeric), invalid `include_query` fallback, and drop-empty-sources behavior. Existing `test_decode_config_returns_empty_arrays_for_null_json` and `test_decode_config_parses_valid_json` updated for the new shape.
  - Live WP integration: created a multi-source feed with two channels (real `The Way Of Holiness Broadcast` + mock `Google for Developers`), inserted a public page rendering `[youtube_feed feed_uuid=...]`, confirmed 16 cards rendered, 16 unique video IDs, real titles and durations, zero `googleapis.com`/`youtube/v3` requests during render. Exclude filter verified end-to-end (excluding `8Kjt_9e3XN0` removed exactly that video).
  - Camofox screenshot: `screenshots/camofox/11-multi-source-feed.png` (180 KB) shows the multi-source gallery grid.
- Files changed:
  - `src/Repository/FeedRepository.php`
  - `src/Render/FeedQuery.php`
  - `src/Render/Renderer.php`
  - `src/Render/ShortcodeRegistrar.php`
  - `tests/unit/Repository/FeedRepositoryTest.php`
  - `scripts/capture-camofox-screenshots.py`
  - `screenshots/camofox/11-multi-source-feed.png`
  - `DEV-CHECKLIST.md`
- Tests run:
  - `php -l` on all touched PHP files inside `vyg-wp` → no syntax errors
  - `make test-unit` → **200 tests, 536 assertions, 0 failures, 0 errors**
  - Live WP render smoke as admin + 3 page hits → all HTTP 200, identical 92 KB response, zero outbound YouTube API calls in access log
- Screenshot evidence:
  - `screenshots/camofox/11-multi-source-feed.png` — `Multi-source gallery test` page with 16 videos from two channels
- Result:
  - Phase 8.1 and 8.2 complete; multi-source feeds render correctly with dedupe, exclude, and sort; legacy single-source feeds still render unchanged. Next: Phase 8.3 (FeedsPage UI for multi-source editing).

### 2026-06-29 — Phase 8.3 multi-source FeedsPage UI

- Trigger: "next phase"
- Mode: Development Execution Mode (Phase 8 — Multi-source Feeds + Feed Portability)
- Current phase: Phase 8
- Selected task: 8.3 — FeedsPage UI supports add/remove/reorder multiple sources, manual video IDs, and per-source badges
- Work completed:
  - Replaced single `source_uuid <select>` in the edit form with a JS-driven multi-source repeater: each row exposes `sources[N][source_uuid]`, `sources[N][weight]` (clamped 0–10), `sources[N][pinned]`, `sources[N][label]`, plus a per-row remove × button and a per-row badge showing the resolved source title. The row template is cloned from `<template id="vyg-source-row-template">` when the "+ Add another source" button is clicked; HTML5 drag API provides drag-to-reorder; `reindex()` rewrites `name` attributes after each add/remove/reorder to keep form submission clean.
  - Added `manual_video_ids` textarea (one ID per line) and `exclude_video_ids` textarea (one ID per line). Added `parse_id_lines()` helper that trims, validates against `^[A-Za-z0-9_-]{1,32}$`, and de-duplicates.
  - Reworked `collect_posted()` to read `sources[]` + `manual_video_ids` + `exclude_video_ids` from `$_POST` and produce the canonical multi-source shape (`{sources:[{source_uuid, weight, pinned, label}], manual_video_ids:[], exclude_video_ids:[], include_query:'any'}`). Legacy single-source forms still work via a fallback to `$_POST['source_uuid']`.
  - Wrapped `FeedsPage::render()` in `ob_start()` / `ob_end_flush()` so the WP 7.0 update banner can no longer pollute the save redirect with `headers already sent` warnings.
  - List view (`render_list()`) gained a new "Sources" column with badges: green `N source(s)` chip, yellow `+ N manual` chip, red `− N excluded` chip, blue `★ N pinned` chip — all using `_n()` for plural form.
  - Live PHP probe (no request): rendered the edit page via `container->get('admin.feeds')->render()` for feed id=2 — 21,110 bytes containing every expected marker (repeater container, `<template>` block, "+ Add another source", manual/exclude textareas, 12 `name="sources[...]"` fields, inline `vyg-source-remove` JS handler, 2 badges). Saved HTML evidence to `screenshots/camofox/edit-feed-raw.html` then removed it before commit.
  - Live list view render: 6,706 bytes containing `<th>Sources<` header, "1 source" badge, "2 sources" badge.
  - Multi-source POST save round-trip via `Repo\FeedRepository::update(2, $data)` confirmed: 2 sources persisted, 2 manual IDs persisted, 1 exclude ID persisted (invalid entry filtered), pinned/label/weight correctly stored; restored to original state after the test.
  - Camofox captures: `screenshots/camofox/12-feeds-list-multi-source.png` (201 KB, 2800×2000 PNG) and `screenshots/camofox/13-feeds-edit-multi-source.png` (296 KB, 2800×2000 PNG).
- Files changed:
  - `src/Admin/FeedsPage.php` (~+225 lines)
  - `scripts/capture-camofox-screenshots.py` (+2 entries for 12 + 13)
  - `screenshots/camofox/12-feeds-list-multi-source.png`
  - `screenshots/camofox/13-feeds-edit-multi-source.png`
  - `DEV-CHECKLIST.md`
- Tests run:
  - `php -l` on `FeedsPage.php` (in `vyg-wp` container): no syntax errors
  - `make test-unit` → **200 tests, 536 assertions, 0 failures, 0 errors**
  - In-container render probes (`container->get('admin.feeds')->render()`) for edit + list views pass all 8 UI invariants
  - `Repo\FeedRepository::update` round-trip with multi-source payload passes
  - `Repo\FeedRepository::decode_config` post-update reveals 2 sources, 2 manual IDs, 1 exclude ID
  - Camofox screenshot script: 13 captures, all 200+ KB, real content (verified file headers + sizes)
- Screenshot evidence:
  - `screenshots/camofox/12-feeds-list-multi-source.png` — list view with Sources column badges
  - `screenshots/camofox/13-feeds-edit-multi-source.png` — edit view with repeater + manual/exclude textareas
- Result:
  - Phase 8.3 complete. FeedsPage now lets operators add, remove, reorder, weight, pin, and label multiple sources per feed; manually pin video IDs; and exclude videos from a feed. List view exposes the source composition at a glance. Next: Phase 8.4 (public REST feed endpoint for saved mixed feeds).

### 2026-06-29 — Phase 8.4 public REST feed-by-uuid endpoint

- Trigger: "next step"
- Mode: Development Execution Mode (Phase 8 — Multi-source Feeds + Feed Portability)
- Current phase: Phase 8
- Selected task: 8.4 — public REST feed endpoint supports saved mixed feeds by `feed_uuid` without exposing internal source IDs or admin-only metadata
- Work completed:
  - Added new route `GET /wp-json/vyg/v1/feed/<uuid>` to `FeedController`. Resolves the feed by `feed_uuid`, decodes the source_config, and dispatches through the existing multi-source renderer with `public_safe=true`.
  - Response shape (public-safe only): `{html, has_more, next_offset, remaining, feed: {feed_uuid, name, layout, status}}`. Internal source UUIDs, manual video IDs, exclude IDs, custom CSS, and admin-only fields are NEVER included.
  - 404 cases: unknown UUID → `vyg_feed_not_found`; draft/archived feed → `vyg_feed_not_published`. 400 for malformed UUIDs.
  - Status normalization: accepts both `publish` (legacy WP shortcode flow) and `published` (FeedsPage flow); public payload always emits `published`.
  - New helper `src/Render/TemplateAttributes.php` centralizes data-attribute generation for the root feed `<div>` and the load-more button. `feed_root()` and `load_more()` accept a `public_safe` flag that omits `data-source-uuid` when set.
  - All 5 templates (grid, list, featured, shorts, live) updated to use `TemplateAttributes::feed_root()` (and `load_more()` in grid.php) with `$public_safe` read from `$attrs['public_safe']`.
  - `Renderer::emit_html()` reads `args['public_safe']` and threads it through to the template context.
  - `load-more.js`: prefers `data-feed-uuid` when present and falls back to `data-source-uuid` for legacy single-source shortcodes. Adds a new `VYG.feedByUuidUrl` (localized via `wp_localize_script`) — JS replaces `{uuid}` at click time.
  - `AssetManager::enqueue_load_more()` exposes the new URL alongside the legacy `restUrl`.
- Files changed:
  - `src/REST/FeedController.php` (+105 lines: route + handler + helper)
  - `src/Render/TemplateAttributes.php` (new, 95 lines)
  - `src/Render/Renderer.php` (+13 lines: public_safe threading)
  - `src/Render/AssetManager.php` (+5 lines: feedByUuidUrl)
  - `src/Render/templates/grid.php`, `list.php`, `featured.php`, `shorts.php`, `live.php` (use TemplateAttributes helper, ~7 lines each)
  - `assets/js/load-more.js` (+14 lines: feed_uuid/source_uuid branching)
- Tests run:
  - `php -l` on all touched files inside `vyg-wp` → no syntax errors
  - `make test-unit` → **200 tests, 536 assertions, 0 failures, 0 errors**
  - Live `curl` against `https://srv1388017.tail209ed.ts.net/wp-json/vyg/v1/feed/<uuid>?per_page=4`:
    - Published feed (`f57c6cfe-...`) → 200, has_more=true, remaining=46, html=4701 bytes, `data-feed-uuid` present, `data-source-uuid` absent, internal source UUID `38f2b5e8-...` not in HTML, internal UUID `ffdf1663-...` not in HTML, 4 `<article>` cards
    - Multi-source feed (`13a832cd-...`) → 200, valid public payload, no internal ID leaks
    - Unknown UUID (`00000000-...`) → 404 `vyg_feed_not_found`
    - Malformed UUID (`not-a-uuid`) → 404 `rest_no_route` (regex pattern doesn't match)
    - Draft feed (`13a832cd-...` after flipping status) → 404 `vyg_feed_not_published`
    - Page 2 with `offset=4` → 200, fresh content, remaining=42
    - Legacy single-source endpoint `/wp-json/vyg/v1/feed?source_uuid=...` still 200, unchanged response shape
- Screenshot evidence: not captured this batch — endpoint is JSON-only and the curl/JSON inspection is the canonical evidence.
- Result:
  - Phase 8.4 complete. The new endpoint serves saved mixed feeds anonymously while exposing only public-safe fields. The legacy single-source endpoint remains unchanged. Front-end JS routes load-more requests through the right endpoint based on which data attribute is present.

### 2026-06-29 — Phase 8.5 feed import/export JSON

- Trigger: "next phase"
- Mode: Development Execution Mode (Phase 8 — Multi-source Feeds + Feed Portability)
- Current phase: Phase 8
- Selected task: 8.5 — feed import/export JSON with conflict handling (replace/duplicate/skip), source remapping, and schema versioning
- Work completed:
  - ImporterExporter extended with `export_feeds(array): string` and `import_feeds(string, array): array`.
  - `export_feeds` produces JSON: `{version: 0.2.0, kind: feeds, plugin_version, exported_at, feeds: [...], source_refs: { uuid → {channel_id, playlist_id, video_id, title} }}`. Each feed record includes feed_uuid, name, feed_type, layout, status, source_config_json, display_config_json, filter_config_json, sort_config_json, custom_css, created_at, updated_at.
  - `import_feeds` parses JSON, validates `kind === 'feeds'`, refuses newer export versions unless `force=true`, runs source remap (matches by YouTube channel_id/playlist_id/video_id), and resolves collisions:
    - `replace`: overwrites the existing feed row.
    - `duplicate`: creates a new feed row with a new feed_uuid and `(copy)` suffix.
    - `skip`: leaves the existing feed untouched (default).
  - Returns `{ok, imported, replaced, duplicated, skipped, errors, warnings}`. `errors` contains fatal parsing/validation failures; `warnings` contains non-fatal remap notes (e.g. "source_uuid X has no local match; removed from feed").
  - Empty-feeds-array is now a valid no-op (`ok=true` when no errors) rather than a hard error.
  - Plugin.php container binding for `admin.importer_exporter` now also passes `repo.feeds` and `repo.sources` so the importer can do its work.
  - SourceRepository un-finaled so test stubs can extend it (no production impact; it's still a single implementation).
  - Two new REST routes registered in AdminRestController:
    - `POST /wp-json/vyg/v1/admin/feeds/export` → `{json, count, kind, version}` (200).
    - `POST /wp-json/vyg/v1/admin/feeds/import` → `{ok, imported, replaced, duplicated, skipped, errors, warnings}` (200 on success, 400 on error).
    - Both gated by `manage_options` + `wp_rest` nonce (403 for anonymous / wrong nonce).
  - New FeedsPage UI section beneath the list view:
    - "Export all feeds as JSON" button → fetches the export endpoint and triggers a Blob download named `vyg-feeds-<YYYY-MM-DD>.json`.
    - Import form: textarea for paste-JSON, conflict-mode dropdown (skip/duplicate/replace), force-versions checkbox, inline notice with imported/replaced/duplicated/skipped counts and warnings.
  - 9 new unit tests in `tests/unit/Admin/ImporterExporterTest.php` using in-memory FakeFeedRepository/FakeSourceRepository: export shape, invalid JSON, wrong kind, version check + force, invalid conflict mode, create new row, skip preserves existing, replace overwrites, duplicate creates copy, source remap by YouTube ID.
  - wp_generate_uuid4 stub added to BrainHelpers (test-side).
- Files changed:
  - `src/Admin/ImporterExporter.php` (+190 lines: export_feeds + import_feeds + remap_sources + index_local_sources_by_youtube_id)
  - `src/Plugin.php` (+4 lines: container binding now passes both repos)
  - `src/Repository/SourceRepository.php` (un-finaled; semantic unchanged)
  - `src/REST/AdminRestController.php` (+85 lines: 2 routes + 2 callbacks)
  - `src/Admin/FeedsPage.php` (+160 lines: render_import_export_section with inline JS fetch handler)
  - `scripts/capture-camofox-screenshots.py` (+1 entry: 14-feeds-import-export)
  - `tests/Support/BrainHelpers.php` (+8 lines: wp_generate_uuid4 stub)
  - `tests/unit/Admin/ImporterExporterTest.php` (+310 lines: 9 new tests + 2 stub repos)
- Tests run:
  - `php -l` on all touched files inside `vyg-wp` → no syntax errors
  - `make test-unit` → **210 tests, 569 assertions, 0 failures, 0 errors** (up from 200/536)
- Live verification:
  - `POST /admin/feeds/export` (empty body) → 200 with `count=2, kind=feeds, version=0.2.0`, JSON has 2 feeds + 2 source_refs.
  - Re-export → re-import with skip → 200, `skipped=2, imported=0, ok=true`.
  - Re-export → re-import with duplicate → 200, `duplicated=2, imported=2, warnings=[]` (sources matched locally by YouTube ID).
  - Re-export with name tweak → re-import with replace → 200, `replaced=2, imported=2`. Verified feed name was actually overwritten in the DB.
  - Garbage JSON → 400 with `errors=[Invalid JSON.]`.
  - Anonymous POST → 403 `vyg_forbidden`.
  - Routes listed in `wp-json/vyg/v1/` index.
- Result:
  - Phase 8.5 complete. Feeds can be exported as JSON and imported on another site with conflict handling and source remap. Live verified end-to-end on the dev instance.

### 2026-06-29 — Phase 8.6 admin REST hardening: size cap + audit log + malformed-JSON tolerance

- Trigger: "next phase"
- Mode: Development Execution Mode (Phase 8 — Multi-source Feeds + Feed Portability)
- Current phase: Phase 8
- Selected task: 8.6 — admin REST endpoints hardening (large payloads, malformed-JSON tolerance, audit log of import operations)
- Work completed:
  - **Schema:** added `vyg_import_log` table with 22 columns: id, op (import|export), kind (feeds), user_id, user_login, payload_bytes, payload_hash (truncated SHA-256), conflict_mode, force_flag, ok_flag, imported_count, replaced_count, duplicated_count, skipped_count, errors_count, warnings_count, errors_json, warnings_json, duration_ms, ip, user_agent, created_at. Renamed reserved-word columns (`force` → `force_flag`, `ok` → `ok_flag`) to avoid MySQL syntax conflicts during dbDelta.
  - **Repository:** new `src/Repository/ImportLogRepository.php` with `record(array)`, `find(int)`, `list_recent(array)`, `count(array)`, `prune_older_than(int)` methods.
  - **Audit emission:** `ImporterExporter::export_feeds()` and `import_feeds()` both emit one audit row per call. Audit captures: user identity (id + login), payload bytes, payload SHA-256 hash, conflict mode, force flag, ok flag, all four counts, error/warning counts, duration_ms, IP, user-agent, created_at. Audit failures are caught and swallowed (never break the operation).
  - **Size cap:** `ImporterExporter::DEFAULT_IMPORT_SIZE_CAP_BYTES = 5 MB`. Enforced both inside `import_feeds()` (defensive, returns "Payload too large" error) AND at the REST boundary (HTTP 413). Operators can override the cap with `apply_filters('vyg_import_size_cap_bytes', $cap)`.
  - **Malformed-JSON tolerance:** added `ImporterExporter::json_error_message(int $code)` translating all JSON error codes to friendly messages. `import_feeds()` now distinguishes:
    - empty payload → "Empty payload."
    - top-level non-object → "Invalid JSON: top-level value is not an object."
    - truncated/syntactically broken → "Invalid JSON: <specific error>."
    - oversized → "Payload too large: X bytes (cap Y bytes)."
  - **Empty-feeds-array** is now a valid no-op (was an error in 8.5).
  - **Audit log REST endpoints:**
    - `GET /admin/import-log` — paginated list (per_page, page, op, kind filters); returns `{items, page, per_page, total}`.
    - `GET /admin/import-log/<id>` — single record with errors_list/warnings_list decoded from JSON.
    - Both gated by `manage_options` + `wp_rest` nonce.
  - **FeedsPage UI:** new "Recent imports / exports" table beneath the import/export form, listing the latest 25 audit rows with formatted columns (when, op, user, bytes, conflict, ok, imported/replaced/duplicated/skipped counts, errors/warnings counts, duration_ms).
- Files changed:
  - `src/Database/Schema.php` (+50 lines: import_log() method + table registration)
  - `src/Repository/ImportLogRepository.php` (new, 175 lines)
  - `src/Admin/ImporterExporter.php` (+130 lines: size cap enforcement, defensive JSON decode, audit() + audit_export() + json_error_message() helpers, integration of audit at every early-return branch)
  - `src/REST/AdminRestController.php` (+75 lines: 413 size check, list_import_log(), get_import_log() endpoints)
  - `src/Admin/FeedsPage.php` (+70 lines: render_recent_audit() method)
  - `src/Plugin.php` (+5 lines: container binding for repo.import_log + pass-through to importer/exporter/rest/feeds)
  - `tests/Support/BrainHelpers.php` (+2 lines: wp_get_current_user stub)
  - `tests/unit/Admin/ImporterExporterTest.php` (+160 lines: 7 new tests + FakeImportLogRepository stub)
- Tests run:
  - `php -l` on all touched files inside `vyg-wp` → no syntax errors
  - `make test-unit` → **217 tests, 595 assertions, 0 failures, 0 errors** (up from 210/569)
- Live verification:
  - Migration ran via `Installer::install()` → `dbDelta: vyg_import_log changes=1`, table_exists=yes, column_count=22.
  - Export 200 → audit row written (op=export, ok_flag=1, bytes=1399, hash=ca9689efda0cad67).
  - Re-import with skip → 200 → audit row (op=import, ok_flag=1, imported=0, bytes=3428, hash=4bf53ff0dba78746).
  - Garbage import → 400 → audit row (op=import, ok_flag=0, errors=1, bytes=8, hash=7ccfa1fbf3940e6f).
  - `GET /admin/import-log` → 200, total=3, items=3.
  - `GET /admin/import-log/<id>` → 200, errors_list=1 (the garbage import's "Invalid JSON" message).
  - 6 MB payload → 413 with "Payload too large: 6000016 bytes (cap 5242880 bytes)."
- Result:
  - Phase 8.6 complete. Admin REST endpoints now have hard size caps, malformed-JSON tolerance with specific error messages, and a full audit trail of every operation. Operators can audit past imports/exports via REST or the FeedsPage "Recent imports" table.

### 2026-06-29 — Phase 8.7 unit-test gap fill-in

- Trigger: "next phase"
- Mode: Development Execution Mode (Phase 8 — Multi-source Feeds + Feed Portability)
- Selected task: 8.7 — fill in remaining unit-test gaps for Phase 8 (FeedQuery multi-source merge/dedupe/sort; ImporterExporter full round-trip + remap + version handling)
- Work completed:
  - **FeedQueryTest** (new, 10 tests): StubFeedQuery subclass overrides `videos_for_source()` and `videos_for_ids()` so tests don't touch the real VideoRepository. Covers: empty merge returns empty, multi-source merge by published_at DESC, dedupe by `youtube_video_id`, pinned source takes dedupe priority (vs. date order), exclude_video_ids filter, manual_video_ids augmentation, limit/offset pagination, malformed source entry skip, count_videos_for_feed after exclude, no manual ids.
  - **ImporterExporterTest** (6 new tests): full export→import round-trip preserving all fields (name, layout, status, sources, weight, pinned, label, manual_video_ids, exclude_video_ids, include_query, display_config_json, custom_css); export-then-export symmetry; source-UUID remap by YouTube channel ID; orphan-source warning with feed still created; skip-mode collision preserves existing record (post-replace the record is untouched); newer-major-version rejection (without force); newer-major-version acceptance (with force).
  - **Bug fix** — `ImporterExporter::import_feeds()`: source/display/filter/sort_config_json were treated as already-decoded arrays, but the export shape stores them as either a JSON string (real DB rows) or array (test mocks). Added `decode_or_array()` helper that accepts both and normalizes to array before passing to FeedRepository::create. Without this fix, the round-trip test demonstrated the production code would lose multi-source data on re-import.
  - **Bug fix** — `ImporterExporter::index_local_sources_by_youtube_id()`: changed from `static $cache` (which leaked across test instances and masked source-remap behavior) to instance-scoped `$this->local_index_cache`. Also exposed `videos_for_ids()` as `protected` so test stubs can override it (was `private`).
  - **Test infrastructure**: `FeedQuery` un-finaled; `videos_for_ids` promoted to `protected`; `FakeFeedRepository::create/update` now JSON-encodes the *_config_json columns to mirror the real FeedRepository's behavior.
- Files changed:
  - `tests/unit/Render/FeedQueryTest.php` (new, 290 lines)
  - `tests/unit/Admin/ImporterExporterTest.php` (+280 lines: 6 new tests + FakeFeedRepository JSON-encoding)
  - `src/Admin/ImporterExporter.php` (+30 lines: decode_or_array helper, instance-scoped cache, config_json decode normalization)
  - `src/Render/FeedQuery.php` (un-finaled; videos_for_ids now `protected`)
- Tests run:
  - `php -l` clean on all touched files
  - `make test-unit` → **233 tests, 657 assertions, 0 failures, 0 errors** (up from 217/595)
- Live verification: `POST /admin/feeds/export` returns 200 with count=2, kind=feeds, version=0.2.0, 3428-char JSON containing 2 feeds + 2 source_refs.
- Result:
  - Phase 8.7 complete. Phase 8 multi-source feeds + feed portability now has comprehensive unit-test coverage: merge/dedupe/sort/exclude/manuals/pagination (10 tests) + import/export round-trip / source-remap / version policy (6 tests). One real bug found and fixed: source_config_json decoding inconsistency would have lost multi-source data on every real round-trip.

### 2026-06-29 — Phase 8.8 E2E public-safety verification

- Trigger: "next phase"
- Mode: Development Execution Mode (Phase 8 — Multi-source Feeds + Feed Portability)
- Selected task: 8.8 — E2E/browser verification of mixed-feed front-end rendering
- Work completed:
  - **Bug found**: the `[youtube_feed feed_uuid="..."]` shortcode was rendering public pages with both `data-feed-uuid="<uuid>"` AND `data-source-uuid="<internal-source-uuid>"`. Phase 8.4 had added the `public_safe` flag to the REST endpoint to strip internal source UUIDs, but the shortcode didn't pass that flag to the renderer — so the page-rendered HTML still leaked.
  - **Fix**: ShortcodeRegistrar now sets `public_safe = '' !== $feed_uuid`. When feed_uuid is provided (Phase 8 saved feed), the existing `TemplateAttributes::feed_root()` toggle omits the source_uuid attribute. Legacy source_uuid shortcodes (no feed_uuid) keep their source_uuid since their inline load-more.js relies on it.
  - **scripts/verify-public-safety.py** — new E2E check that fetches every public page with `[youtube_feed]` plus every REST feed-by-uuid endpoint plus the legacy feed-by-source endpoint, asserts:
    - Pages with feed_uuid shortcode have zero `data-source-uuid` attributes.
    - Pages don't contain any internal source UUIDs (read live from vyg_sources).
    - REST feed-by-uuid responses don't contain internal source UUIDs in either the JSON envelope or the rendered HTML.
    - Legacy `?source_uuid=` endpoints don't leak OTHER sources' UUIDs (only the requested one is allowed).
  - **Negation-tested the verify script**: with the fix reverted (`public_safe = false`), the script reports 6 violations across 3 pages (`data-source-uuid` × 3 + leaked source UUIDs × 3, exit code 1). Restoring the fix returns `OK: no internal-UUID leaks detected.` (exit 0).
  - **2 new Camofox screenshots**:
    - `screenshots/camofox/15-frontend-multi-source-public-safe.png` — page 17 (multi-source gallery test, feed_uuid 13a832cd) renders correctly with 2 mock-source cards visible.
    - `screenshots/camofox/16-frontend-single-source-public-safe.png` — page 7 (Phase 6 single-source test, feed_uuid f57c6cfe) renders 50 cards from the real connected channel.
  - **scripts/capture-camofox-screenshots.py** — added entries 15 and 16 so the standard capture run also produces these.
- Files changed:
  - `src/Render/ShortcodeRegistrar.php` (1 line + 4-line comment block)
  - `scripts/verify-public-safety.py` (new, 170 lines)
  - `scripts/capture-camofox-screenshots.py` (added 2 entries)
  - `screenshots/camofox/15-frontend-multi-source-public-safe.png` (new, 211 KB)
  - `screenshots/camofox/16-frontend-single-source-public-safe.png` (new, 625 KB)
- Tests run:
  - `php -l` clean on all touched files
  - `make test-unit` → **233 tests, 657 assertions, 0 failures, 0 errors**
  - `python3 scripts/verify-public-safety.py` → exit 0 with fix; exit 1 with fix reverted (negation-tested)
- Live verification:
  - `curl /?page_id=17` → 200 with `data-feed-uuid="13a832cd-..."`, zero `data-source-uuid` attributes.
  - `curl /?page_id=7` → 200 with `data-feed-uuid="f57c6cfe-..."`, zero `data-source-uuid` attributes.
  - `curl /?page_id=11` → 200 with `data-feed-uuid="f57c6cfe-..."`, zero `data-source-uuid` attributes.
  - Both Camofox screenshots show well-rendered front-end gallery pages.
- Result:
  - Phase 8.8 complete. Phase 8 multi-source feeds + feed portability is now end-to-end verified, with no internal-UUID leaks anywhere on the public front-end. The verify-public-safety.py script provides regression protection that will fail loudly if any future PR reintroduces this class of leak.

### 2026-06-30 — Phase 9 Advanced Layouts + Front-end Polish

- Trigger: "next phase" (after `/queue set a goal to keep iterating the next phase until completed` chain)
- Mode: Development Execution Mode (Phase 9 — Advanced Layouts + Front-end Polish)
- Selected task: 9.1 → 9.9 full phase
- Work completed:
  - **9.1 Masonry layout** — `MasonryLayout.php` + `templates/masonry.php` + `assets/css/masonry.css`. Pure CSS `column-count` + `break-inside: avoid`; responsive 6→3→2→1 column waterfall. No JS layout dependency.
  - **9.2 Carousel layout** — `CarouselLayout.php` + `templates/carousel.php` + `assets/css/carousel.css` + `assets/js/carousel.js`. Vanilla JS controller with **full a11y**:
    - `role="region"`, `aria-roledescription="carousel"`, `aria-posinset`, `aria-setsize`, `aria-selected`
    - `aria-live="polite"` slide-status region for screen readers
    - **Keyboard**: ArrowLeft / ArrowRight / Home / End move prev/next/end
    - **Touch**: native horizontal scroll with end-of-scroll snap-sync to current slide
    - **Reduced motion**: honors `prefers-reduced-motion` (auto-scroll instead of smooth)
    - **MutationObserver** so AJAX-loaded carousels auto-wire their controllers
    - **Focus-visible** outline on prev/next buttons
  - **9.3 Hero layout** — `HeroLayout.php` + `templates/hero.php` + `assets/css/hero.css`. Wide 16:9 `maxres` thumbnail with sidecar metadata (title h2, channel title, `date_i18n` published_at, 220-char description excerpt with ellipsis, `fetchpriority="high"` on hero image). Smaller grid below.
  - **9.4 Block patterns** — `src/Render/PatternsRegistrar.php`. Registers `vyg-patterns` block-pattern category + 4 patterns: `vyg/channel-grid`, `vyg/shorts-wall`, `vyg/live-hub`, `vyg/featured-landing`. Wire via `render.patterns` service registered in `Plugin.php`. **No pattern includes secrets / tokens / keys** — covered by 4 unit assertions.
  - **9.5 Schema.org JSON-LD** — `src/Render/SchemaLd.php`. Emits `<script type="application/ld+json">` with one `ItemList` + per-video `VideoObject` entries. **Hard rules verified by tests**:
    - Filters out `deleted` / `private` / `embed_disabled` / `unavailable` videos
    - Omits internal `source_uuid` from structured data
    - Neutralizes `</script>` injection in titles/descriptions
    - Converts duration to ISO 8601 (`PT#H#M#S`), uploadDate to ISO 8601 (`Y-m-d\TH:i:s\Z`)
    - Opt-in via `schema_enabled` attribute (default OFF); per-feed toggle + Feed Builder checkbox
  - **9.6 White-label presets** — `src/Render/Presets.php` + `assets/css/presets.css`. 5 named CSS-variable bundles (default / minimal / cinema / pastel / developer). Wired via `[data-vyg-preset="<slug>"]` attribute selector. Sanitize regex fix: `[^a-zA-Z0-9_-]+` (was dropping uppercase input → default fallback). Feed Builder form adds "Style preset" dropdown + "Emit Schema.org JSON-LD" checkbox.
  - **9.7 Performance** — `assets/js/lightbox.js` rebuilt: dynamic iframe construction on click only, `loading="lazy"` on iframe, `aria-modal="true"`, focus trap into close button on open, prior-element focus restored on close, `data-vyg-title` plumbed to iframe title, fixed `e && e.target === overlay` guard. All layouts already use `loading="lazy"` + `decoding="async"` + `aspect-ratio`. AssetManager tracks dedup via `$css_enqueued[]` map + 4 idempotent `*_enqueued` flags.
  - **9.8 Unit tests** — **+28 tests, +139 assertions; baseline 233/657 → 261/796**.
    - Added: `PresetsTest` (8), `LayoutDispatchTest` (5), `PatternsRegistrarTest` (3), `SchemaLdTest` (8), `LayoutTemplatesTest` (7).
    - Fixed `BrainHelpers.php` gap: added `esc_attr_e` + `date_i18n` stubs. Without these, the carousel/hero templates broke inside `ob_start()` and left buffers open (which only manifested as PHPUnit risky-tests / errors, not as test failures — easy to miss).
7. **9.9 E2E + Camofox** — captured 6 screenshots, verified Phase 0 invariant holds:
   - `dev/phase9-create-pages.php` creates 6 demo pages (masonry, carousel, hero, cinema, pastel, schema-jsonld)
   - `scripts/capture-phase9-screenshots.py` drives Camofox REST on `:9377` to capture all 6 in one run + verify `wp_vyg_api_quota_log` row delta is 0
   - Result: **Quota log Δ=0** across all 6 captures → zero API calls during front-end render
   - Screenshots saved as `17-phase-9-masonry.png` (489 KB), `18-phase-9-carousel.png` (218 KB), `19-phase-9-hero.png` (677 KB), `20-phase-9-preset-cinema.png` (345 KB), `21-phase-9-preset-pastel.png` (344 KB), `22-phase-9-schema-jsonld.png` (351 KB)
- Files changed:
  - 39 files in commit `a245d3a`: 11 source files (5 layouts + Presets + SchemaLd + PatternsRegistrar + Renderer/ShortcodeRegistrar/AssetManager/Plugin/FeedsPage wiring), 4 templates, 4 CSS files, 1 JS file, 5 test files, 1 capture script, 1 dev helper, 6 screenshots, `FeedRepository::allowed_layouts()`, `block.json` attributes
- Tests run:
  - `make test-unit` (261 tests / 796 assertions / 0 failures)
  - `python3 scripts/capture-phase9-screenshots.py` → exit 0 (Δ=0 quota log rows)
  - `curl /?page_id=18|19|20|21|22|23` → HTTP 200, all expected CSS classes + ARIA + JSON-LD markers present
- Live verification:
  - Masonry page: `vyg-feed--masonry`, `vyg-masonry--cols-3`, `vyg-masonry-css`, `vyg-presets-css` all present in HTML
  - Carousel page: `vyg-carousel--per-3`, `role="region"`, `aria-roledescription="carousel"`, `aria-selected="true"`, `vyg-carousel-css` all present
  - Hero page: `vyg-hero`, `vyg-hero__primary`, `vyg-hero__meta`, `vyg-hero__channel`, `vyg-hero__date`, `fetchpriority="high"`, `vyg-hero-css` all present
  - Schema page: `application/ld+json`, `"@context"`, `"@type":"ItemList"`, `"@type":"ListItem"`, `"@type":"VideoObject"`, `"uploadDate"`, `"duration":` all present
  - Cinema + Pastel preset pages: `[data-vyg-preset="cinema"]` / `[data-vyg-preset="pastel"]` attribute selector present in HTML
- Result:
  - **Phase 9 complete.** All 9 sub-items marked [x]. 261 tests pass (baseline+28). Live front-end screenshots captured for every new layout + preset. Phase 0 invariant verified across all 6 captures.
  - Committed as `phase-9: advanced layouts + front-end polish (masonry/carousel/hero/presets/schema/patterns/perf)` (a245d3a).
- Next recommended action:
  - Phase 10.1 — Elementor widget. Wire `vectoryt/gallery` block's Elementor wrapper: feed selector (saved feed_uuid dropdown + ad-hoc source_uuid), layout/columns/per_page/orderby/order/pagination/content_type/preset/schema_enabled controls, responsive controls (device-mode toggles), editor preview using `<template>` JS that hydrates from REST `/vyg/v1/feed/{uuid}`, front-end render delegated to the existing `Renderer::render()` (no double rendering paths).

### 2026-06-30 — Phase 11 Analytics review + dashboard

- Trigger: `/queue review what done for phase 11 so far fix any lingering issues of completed steps then move on to the next phase`
- Mode: Reconciliation + Development Execution Mode
- Current phase: Phase 11 — Analytics + Moderation Workflows
- Selected task: Review 11.1/11.5/11.6/11.7, then implement 11.2 Analytics dashboard
- Work completed:
  - Re-ran unit suite for completed Phase 11 work: 302 tests / 872 assertions / 0 failures / 3 skipped.
  - Re-linted Phase 11 completed source files: analytics repo, retention job, analytics/export REST controllers, PrivacyPage, AssetManager, Plugin — all syntax clean.
  - Corrected stale checklist wording: 11.5 is analytics export complete; moderation export remains tied to 11.3 moderation queues.
  - Added `src/Admin/AnalyticsPage.php`, a local-only admin analytics page with bounded date filters, summary cards, top videos, feed views, quota trends, and sync health.
  - Added Analytics submenu (`YouTube Gallery → Analytics`) via `AdminMenu` and wired `admin.analytics` service in `Plugin.php`.
  - Added `tests/unit/Admin/AnalyticsPageTest.php` for page shell/date-boundary coverage.
  - Ran live wp-cli render smoke with seeded local `wp_vyg_events` rows; verified heading/top videos/video ID render and no WordPress DB error.
  - Created rendered visual artifact `screenshots/camofox/29-phase-11-analytics-dashboard.html`. Camofox file navigation rejected and no Chromium/Playwright/Puppeteer browser is installed, so PNG capture remains for 11.8.
- Files changed:
  - `src/Admin/AnalyticsPage.php`
  - `src/Admin/AdminMenu.php`
  - `src/Plugin.php`
  - `tests/unit/Admin/AnalyticsPageTest.php`
  - `DEV-CHECKLIST.md`
  - `screenshots/camofox/29-phase-11-analytics-dashboard.html`
- Tests run:
  - `vendor/bin/phpunit --testsuite=unit --colors=never` → 305 tests / 876 assertions / 0 failures / 3 skipped.
  - `php -l` for new/modified PHP files → no syntax errors.
  - `wp eval-file dev/phase11-analytics-smoke.php` → `contains_heading=yes`, `contains_top_videos=yes`, `contains_video_id=yes`, `contains_db_error=no`, `html_bytes=2556`.
- Result:
  - 11.2 complete and checklist updated. Phase 11 now has 11.1, 11.2, 11.5, 11.6, 11.7 complete. 11.3, 11.4, and 11.8 remain pending.
- Next recommended action:
  - 11.3 — build moderation queues (hidden/unavailable/stale/manual-review candidates), bulk approve/hide/classify actions, and moderation CSV/JSON export.

### 2026-06-30 — Phase 11 Moderation queues

- Trigger: continuation of `/queue review what done for phase 11 so far fix any lingering issues of completed steps then move on to the next phase`
- Mode: Development Execution Mode
- Current phase: Phase 11 — Analytics + Moderation Workflows
- Selected task: 11.3 — Moderation queues
- Work completed:
  - Added durable moderation columns to `wp_vyg_videos`: `moderation_status`, `moderation_reason`, `moderated_by`, `moderated_at`.
  - Bumped `VYG_DB_VERSION` to 0.5.0 and verified live dbDelta: `vyg_videos changes:5`.
  - Added `src/Admin/ModerationPage.php` with queue tabs for needs-review, manual-review, unavailable, stale metadata, and hidden videos.
  - Added bulk moderation actions: approve, mark manual review, hide, unhide, classify-as content type.
  - Wired `YouTube Gallery → Moderation` submenu and `admin.moderation` container service.
  - Added moderation export via `GET /wp-json/vyg/v1/moderation/export?format=json|csv&queue=...` with `manage_options` capability check.
  - Added `tests/unit/Admin/ModerationPageTest.php` covering bulk action update payloads.
  - Created rendered visual artifact `screenshots/camofox/30-phase-11-moderation-queue.html` for the moderation queue. PNG capture remains part of 11.8 because Camofox file navigation was rejected and no Chromium/Playwright/Puppeteer browser is installed.
- Files changed:
  - `src/Admin/ModerationPage.php`
  - `src/Admin/AdminMenu.php`
  - `src/Plugin.php`
  - `src/Database/Schema.php`
  - `src/Repository/VideoRepository.php`
  - `src/REST/ExportController.php`
  - `vector-youtube-gallery.php`
  - `tests/bootstrap.php`
  - `tests/unit/Admin/ModerationPageTest.php`
  - `DEV-CHECKLIST.md`
  - `screenshots/camofox/30-phase-11-moderation-queue.html`
- Tests run:
  - `php -l` for all new/modified PHP files → no syntax errors.
  - `vendor/bin/phpunit --testsuite=unit --colors=never` → 310 tests / 895 assertions / 0 failures / 3 skipped.
  - `wp plugin deactivate/activate vector-youtube-gallery` → `dbDelta: vyg_videos changes:5`.
  - `wp eval-file dev/phase11-moderation-smoke.php` → `contains_heading=yes`, `contains_queue=yes`, `contains_video_id=yes`, `contains_db_error=no`, `html_bytes=3460`.
  - Direct export smoke: `ExportController::export_moderation(queue=manual_review)` → `queue=manual_review count=1 has_rows=yes`.
- Result:
  - 11.3 complete and checklist updated. Phase 11 now has 11.1, 11.2, 11.3, 11.5, 11.6, 11.7 complete. 11.4 and 11.8 remain pending.
- Next recommended action:
  - 11.4 — add saved filters and bulk actions to the existing VideosPage.

### 2026-06-30 — Dockerized Playwright screenshot pipeline

- Trigger: user asked to move forward with the recommended Dockerized Playwright screenshot setup before resuming development.
- Mode: Infrastructure unblock / E2E verification.
- Decision implemented:
  - Used the official Microsoft Playwright Docker image (`mcr.microsoft.com/playwright:v1.45.0-jammy`) attached to `vyg_net`.
  - Avoided public domain exposure and avoided host browser installs.
  - Avoided npm registry dependency after registry/container npm calls hung; the runner uses the Chromium binary already bundled in the Playwright image (`/ms-playwright/chromium-1124/chrome-linux/chrome`).
- Work completed:
  - Added `scripts/run-phase11-playwright.sh` reusable screenshot wrapper.
  - Added `scripts/mu-vyg-screenshot-login.php` one-time login helper template. The wrapper copies it into `wp-content/mu-plugins` only during capture, stores only `sha256(token)` in a temporary option, and removes it on exit.
  - Added `scripts/seed-phase11-screenshots.php` deterministic local-only seed data for analytics/moderation screenshots.
  - The wrapper temporarily sets `siteurl/home=http://vyg-wp` so WordPress redirects stay reachable from inside the Playwright container, then restores both to `http://localhost:8000` in cleanup.
  - Captured real styled WordPress admin PNG screenshots:
    - `screenshots/playwright/phase11-analytics-dashboard.png` — 119,572 bytes (~116 KB)
    - `screenshots/playwright/phase11-moderation-queue.png` — 116,069 bytes (~113 KB)
    - `screenshots/playwright/phase11-videos-page.png` — 177,694 bytes (~173 KB)
- Validation:
  - `api_quota_delta=0` during screenshot capture.
  - `siteurl` restored to `http://localhost:8000`.
  - `home` restored to `http://localhost:8000`.
  - Temporary MU-plugin removed from `wp-content/mu-plugins` after capture.
  - `bash -n scripts/run-phase11-playwright.sh` passed.
  - `php -l scripts/seed-phase11-screenshots.php` passed.
  - `php -l scripts/mu-vyg-screenshot-login.php` passed via stdin.
  - Visual inspection confirmed the PNGs are real styled WordPress admin pages, not browser error pages or unstyled file renders.
- Result:
  - 11.8 E2E/browser verification complete via Dockerized Playwright/Chromium; checklist updated from Camofox wording to Playwright wording.
- Next recommended action:
  - Resume 11.4 — Saved filters and bulk actions for VideosPage.

### 2026-06-30 — Phase 11 VideosPage saved filters + bulk actions

- Trigger: resumed development after confirming Dockerized Playwright screenshots work.
- Mode: Development Execution Mode.
- Selected task: 11.4 — Saved filters + bulk actions for VideosPage.
- Work completed:
  - Reworked `src/Admin/VideosPage.php` to support expanded filters: search, content type, channel/source ID (`youtube_channel_id`), availability, live state, pinned, hidden, published-after, published-before.
  - Added saved filter presets stored in `vyg_videos_saved_filters` with save/apply/delete controls.
  - Added row checkboxes and bulk actions: hide, unhide, pin, unpin, reclassify selected.
  - Preserved per-row reclassification forms and audit logging.
  - Added `tests/unit/Admin/VideosPageTest.php` for filter sanitization.
  - Fixed a live-smoke bug where the first implementation incorrectly queried nonexistent `source_id`; changed source filtering to existing `youtube_channel_id`.
  - Hardened `scripts/run-phase11-playwright.sh` so it temporarily suspends VYG cron hooks during screenshots and reschedules them in cleanup; this eliminated a `videos.list` background quota side effect.
  - Refreshed `screenshots/playwright/phase11-videos-page.png` showing saved filters, expanded filters, bulk action controls, checkboxes, and pinned/hidden column.
- Validation:
  - `php -l src/Admin/VideosPage.php` → no syntax errors.
  - `php -l tests/unit/Admin/VideosPageTest.php` → no syntax errors.
  - `vendor/bin/phpunit --testsuite=unit --colors=never` → 312 tests / 911 assertions / 0 failures / 3 skipped.
  - Live wp-cli smoke: `clean_type=live_replay`, `clean_source=UC-test`, invalid date dropped, bulk pin/unpin changed DB values, rendered HTML had saved filters + bulk controls + pinned/hidden column, `contains_db_error=no`, `html_bytes=53683`.
  - Dockerized Playwright screenshot runner: analytics/moderation/videos PNGs captured, `api_quota_delta=0`.
  - Visual inspection confirmed refreshed Videos screenshot is styled WP admin UI with the 11.4 controls visible.
- Result:
  - Phase 11 complete: 11.1, 11.2, 11.3, 11.4, 11.5, 11.6, 11.7, and 11.8 are all checked.
- Next recommended action:
  - Phase 12 — Operations, Scale, and Multisite.

### 2026-06-30 — Phase 12 WP-CLI command suite

- Trigger: "Next phase"
- Mode: Development Execution Mode.
- Current phase: Phase 12 — Operations, Scale, and Multisite.
- Selected task: 12.1 — WP-CLI command suite.
- Work completed:
  - Added `src/CLI/Command.php` as the `wp vyg` command namespace.
  - Registered the command only in WP-CLI context from `Plugin::register_hooks()`.
  - Added `wp vyg diagnostics` with safe counts, runtime metadata, cron snapshot, and recent sync jobs; no secrets are emitted.
  - Added `wp vyg jobs` for recent/filtered sync-job listing.
  - Added `wp vyg sync` for direct or queued sync jobs: `initial`, `incremental`, `metadata-refresh`, `live-poll`, and `cron-incremental-all`.
  - Added `wp vyg retry <job-id>` for immediate retry through existing job runners.
  - Added hyphenated feed portability commands: `wp vyg export-feeds` and `wp vyg import-feeds`.
  - Added `wp vyg retention` for data retention and analytics retention runs.
- Files changed:
  - `src/CLI/Command.php`
  - `src/Plugin.php`
  - `DEV-CHECKLIST.md`
- Validation:
  - `php -l src/CLI/Command.php` → no syntax errors.
  - `php -l src/Plugin.php` → no syntax errors.
  - `wp vyg diagnostics --format=json` → returned counts/cron/recent job JSON.
  - `wp vyg jobs --limit=3 --format=json` → returned recent jobs.
  - `wp vyg export-feeds --file=/tmp/vyg-feeds-cli-export.json` → wrote 3,428-byte JSON export.
  - `wp vyg import-feeds /tmp/vyg-feeds-cli-export.json --conflict=skip` → ok, skipped 2 existing feeds, no errors/warnings.
  - `wp vyg sync metadata-refresh --enqueue` → queued metadata refresh job without running YouTube calls.
  - API quota check around export + sync enqueue → `api_quota_delta=0`.
  - `wp vyg retention --analytics --format=json` → `{ "deleted": 0, "ran": true }`.
  - `vendor/bin/phpunit --testsuite=unit --colors=never` → 312 tests / 911 assertions / 0 failures / 3 skipped.
- Result:
  - 12.1 complete and checked. Current sub-phase moved to 12.2.
- Next recommended action:
  - 12.2 — Action Scheduler adapter for sync jobs with migration path from WP-Cron and feature flag fallback.
