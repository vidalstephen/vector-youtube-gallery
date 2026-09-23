#!/usr/bin/env bash
# Phase 14.1 — capture the three width modes (theme / wide / full)
# side-by-side via the official Microsoft Playwright Docker image.
# Mirrors scripts/run-phase13-1-playwright.sh: no host browser, no
# host Node, deterministic seed (dev/seed-14-1-page.php), and an
# api_quota_delta=0 check at the end.
#
# Captures (under screenshots/prototype-parity/):
#   - width-modes-desktop.png   1280x1800 (full page, all 3 widths)
#   - width-modes-mobile.png     380x2400 (mobile viewport)
#
# Usage: scripts/run-14-1-playwright.sh

set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_DIR"

IMAGE="${IMAGE:-mcr.microsoft.com/playwright:v1.45.0-jammy}"
NETWORK="${NETWORK:-vyg_net}"
WP_CONTAINER="${WP_CONTAINER:-vyg-wp}"
WP_PATH="${WP_PATH:-/var/www/html}"
WP_BASE_URL="${WP_BASE_URL:-http://vyg-wp}"
SCREENSHOT_DIR="${SCREENSHOT_DIR:-screenshots/prototype-parity}"
CHROME="${CHROME:-/ms-playwright/chromium-1124/chrome-linux/chrome}"
CAPTURE_TIMEOUT="${CAPTURE_TIMEOUT:-90s}"
SEED_FILE="${WP_PATH}/wp-content/plugins/vector-youtube-gallery/dev/seed-14-1-page.php"

cleanup() {
    set +e
    docker exec -u root "$WP_CONTAINER" rm -f "${WP_PATH}/.htaccess.bak" >/dev/null 2>&1 || true
}
trap cleanup EXIT

mkdir -p "$SCREENSHOT_DIR"

# Quota baseline.
quota_before=$(docker exec -u www-data "$WP_CONTAINER" wp eval \
    'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}vyg_api_quota_log");' \
    --path="$WP_PATH" 2>/dev/null || echo 0)
echo "[14.1] api_quota_log before: $quota_before"

# Re-seed the page.
echo "[14.1] seeding dev/seed-14-1-page.php"
docker exec -u www-data "$WP_CONTAINER" wp eval-file "$SEED_FILE" --path="$WP_PATH" 2>&1 | tail -3

PAGE_ID=$(docker exec -u www-data "$WP_CONTAINER" wp post list \
    --post_type=page \
    --name=vyg-14-1-width-modes \
    --field=ID \
    --path="$WP_PATH" 2>/dev/null | head -1)
[[ -z "$PAGE_ID" ]] && { echo "[14.1] FATAL: seed page not found"; exit 1; }
PAGE_URL="${WP_BASE_URL}/?page_id=${PAGE_ID}"
echo "[14.1] capture URL: $PAGE_URL"

# Desktop capture (1280x1800 — full page so all 3 widths are visible).
docker run --rm \
    --network "$NETWORK" \
    -v "$PROJECT_DIR:$PROJECT_DIR" \
    -w "$PROJECT_DIR" \
    "$IMAGE" \
    "$CHROME" \
    --headless=new \
    --no-sandbox \
    --disable-gpu \
    --hide-scrollbars \
    --window-size=1280,1800 \
    --virtual-time-budget=10000 \
    --screenshot="${SCREENSHOT_DIR}/width-modes-desktop.png" \
    "$PAGE_URL" 2>&1 | tail -5

# Mobile capture (380x2400).
docker run --rm \
    --network "$NETWORK" \
    -v "$PROJECT_DIR:$PROJECT_DIR" \
    -w "$PROJECT_DIR" \
    "$IMAGE" \
    "$CHROME" \
    --headless=new \
    --no-sandbox \
    --disable-gpu \
    --hide-scrollbars \
    --window-size=380,2400 \
    --virtual-time-budget=10000 \
    --screenshot="${SCREENSHOT_DIR}/width-modes-mobile.png" \
    "$PAGE_URL" 2>&1 | tail -5

# Quota delta check.
quota_after=$(docker exec -u www-data "$WP_CONTAINER" wp eval \
    'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}vyg_api_quota_log");' \
    --path="$WP_PATH" 2>/dev/null || echo 0)
delta=$(( quota_after - quota_before ))
echo "[14.1] api_quota_delta=$delta (must be 0)"

ls -la "$SCREENSHOT_DIR"/width-modes-*.png 2>&1

if [[ "$delta" != "0" ]]; then
    echo "[14.1] FATAL: api_quota_delta != 0"
    exit 1
fi

echo "[14.1] OK"
