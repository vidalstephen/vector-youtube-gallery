# Vector YouTube Gallery

A local-indexed YouTube gallery system for WordPress. YouTube stays the canonical media platform; this plugin stores a compliant, refreshable metadata index and renders fast galleries from local data only.

- No scraping
- No downloading or storing YouTube video files
- No API calls during front-end rendering
- Video playback happens via official YouTube embed/player URLs
- Stored metadata is refreshed or deleted per YouTube API policy

## Status

**Phase 0 — Foundation.** Plugin skeleton loads, activates, and runs in a local Docker stack. No user-facing features yet.

See `DEV-CHECKLIST.md` for the full 7-phase roadmap.

## Quickstart (local development)

```bash
# 1. Copy the env template and edit if needed
cp dev/.env.example dev/.env
chmod 600 dev/.env

# 2. Bring up the stack (WordPress + MariaDB + Adminer)
docker compose --env-file dev/.env up -d

# 3. Wait ~10s for healthchecks, then verify
curl -fsS -o /dev/null -w "%{http_code}\n" http://localhost:8000/
curl -fsS -o /dev/null -w "%{http_code}\n" http://localhost:8090/   # Adminer (server=db)

# 4. Open in browser
#    Local WordPress:   http://localhost:8000
#    Remote wp-admin:   https://srv1388017.tail209ed.ts.net/wp-admin  (Tailscale tailnet only)
#    Adminer:           http://localhost:8090  (server=db, user=wordpress, pass from dev/.env, db=wordpress)
#
#    Sanity-check remote admin routing:
#    make remote-admin-status

# 5. Activate the plugin
#    wp-admin → Plugins → Vector YouTube Gallery → Activate
```

## Architecture

```
src/
  Plugin.php          # Bootstrap: wires Container, registers hooks
  Container.php       # Minimal service-locator
  Logging/Logger.php  # File-based logger with secret redaction

vector-youtube-gallery.php   # Plugin header + constants
uninstall.php                # Runs only on plugin deletion

docker-compose.yml           # WP+MariaDB+Adminer stack
dev/.env.example             # Template (commit this)
dev/.env                     # Real dev creds (gitignored, chmod 600)
```

Namespace: `VectorYT\Gallery\` · Text domain: `vector-youtube-gallery` · DB prefix: `{$wpdb->prefix}vyg_`

## Requirements

- PHP 8.1+ (tested on 8.2)
- WordPress 6.4+
- MySQL 5.7+ / MariaDB equivalent
- Docker + Docker Compose (for the dev stack)

## Roadmap

See `DEV-CHECKLIST.md`. High-level phases:

| Phase | Scope |
|---|---|
| 0 | Foundation (scaffold, dev stack, plugin header, logger) |
| 1 | Public API key connection + source resolvers |
| 2 | Sync engine (Action Scheduler, retry/backoff, quota log) |
| 3 | Classification (Shorts, live, availability) |
| 4 | Rendering (shortcode, block, grid/featured/single, lightbox) |
| 5 | Live fallback module |
| 6 | Admin polish (dashboard, feed builder, compliance) |

## License

GPL-2.0-or-later.