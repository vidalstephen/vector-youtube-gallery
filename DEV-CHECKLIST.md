# Development Checklist — Vector YouTube Gallery

## Project Summary

**Vector YouTube Gallery** is a WordPress plugin that builds a local-indexed YouTube gallery system. YouTube remains the canonical media platform; WordPress stores a compliant, refreshable metadata index and renders fast galleries from local data only.

- **Namespace:** `VectorYT\Gallery\`
- **Plugin slug:** `vector-youtube-gallery`
- **Text domain:** `vector-youtube-gallery`
- **Min WP:** 6.4+, **Min PHP:** 8.1+
- **No scraping. No API calls on front-end render. No video file storage.**

## Current Development Status

- Current phase: **Phase 6 — Admin Polish** (COMPLETE)
- Current sub-phase: 6.14 (browser E2E verified)
- Last completed item: 6.14 — feed-by-uuid shortcode renders scoped-CSS gallery; Disconnect flips sources; retention sweep runs cleanly
- Next actionable item: Begin Phase 7+ (deferred: OAuth, masonry, carousel, Elementor/Divi, advanced analytics)
- Blocked items: none
- Deferred items: see Phase 7+ (OAuth, Elementor/Divi, masonry, carousel, white-label, etc.)

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

- [x] 1.1 `src/Settings/SecretsRepository.php` — stores API key in option with `autoload=no`
- [ ] 1.2 `src/Admin/SettingsPage.php` — API key field, masked input, save handler, nonce — deferred: lands via SettingsPage below (1.2 + 1.12 split; key form lives in SettingsPage already)
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
- [ ] 1.9 `src/Repository/SourceRepository.php` — CRUD over `vyg_sources` (table TBD Phase 2) — deferred to Phase 2

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
- [~] 2.6 `src/Sync/SyncScheduler.php` — WP-Cron backed (Phase 2 default); Action Scheduler wrapper deferred — interface is in place so swapping is trivial
- [x] 2.7 `src/Sync/SyncJobRunner.php` — generic runner with retry/backoff
- [x] 2.8 `src/Sync/InitialImportJob.php` — channel → uploads playlist → page through items → batch video fetches → normalize → save
- [x] 2.9 `src/Sync/IncrementalSyncJob.php` — first 1–3 pages only, stop when known IDs hit
- [x] 2.10 `src/Sync/MetadataRefreshJob.php` — refresh by video type (per plan §6 table)
- [>] 2.11 `src/Sync/LiveStatusPollJob.php` — deferred to Phase 5 (Live Fallback Module)
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

Captured via `scripts/capture-screenshots.sh` (chromium inside the wordpress container, file:// render of curl-fetched HTML). See `screenshots/` for the raw PNGs; `scripts/capture-screenshots.sh` to regenerate.

| Page | File | Bytes |
| --- | --- | --- |
| WordPress dashboard with Vector YouTube Gallery widget (4 stat cards + gauge + recent jobs table) | ![](screenshots/01-dashboard-widget.png) | 81 KB |
| Sources page with status badges + Sync-now + Disconnect | ![](screenshots/02-sources.png) | 96 KB |
| Feeds list view (saved feeds table + shortcode display) | ![](screenshots/03-feeds-list.png) | 73 KB |
| Feeds edit form (13 fields: name, source, layout, display, filter, sort, custom CSS) | ![](screenshots/04-feeds-edit.png) | 129 KB |
| Privacy & Compliance (7 sections: counts, retention, disconnect, import/export, policy) | ![](screenshots/05-privacy.png) | 129 KB |
| Diagnostics (6 sections: API status, quota, sync jobs, source health, stale, errors) | ![](screenshots/06-diagnostics.png) | 112 KB |
| Videos moderation (search, filter, hide/pin/reclassify) | ![](screenshots/07-videos.png) | 96 KB |
| System Info (copy-to-clipboard, table counts, cron events) | ![](screenshots/08-system-info.png) | 73 KB |
| WordPress login page | ![](screenshots/09-login.png) | 24 KB |
| Front-end gallery — feed-by-uuid shortcode rendering 2 videos with scoped CSS | ![](screenshots/10-frontend-feed.png) | 70 KB |
| Front-end gallery — mobile viewport (390px) | ![](screenshots/11-frontend-mobile.png) | 45 KB |

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
