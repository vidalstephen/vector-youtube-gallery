#!/usr/bin/env bash
# Phase 13.1 — capture the redesigned grid layout (header / densities / trust
# strip / mobile / card zoom) with the official Microsoft Playwright Docker
# image. Mirrors scripts/run-phase10-7-playwright.sh: no host browser, no
# host Node, deterministic seed, and an api_quota_delta=0 check at the end.
#
# Captures (under screenshots/phase13/):
#   - grid-comfortable-desktop.png   1280×900
#   - grid-comfortable-mobile.png     380×800 (mobile viewport)
#   - grid-compact-desktop.png       1280×900
#   - grid-editorial-desktop.png     1280×900
#   - grid-with-header-cta-trust.png 1280×1200 (full mockup replica)
#   - grid-card-zoom.png             640×520 (single card)
#
# Usage:
#   scripts/run-phase13-1-playwright.sh
#
# Optional overrides:
#   SCREENSHOT_DIR=/path   WP_BASE_URL=http://vyg-wp   IMAGE=playwright:tag

set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_DIR"

IMAGE="${IMAGE:-mcr.microsoft.com/playwright:v1.45.0-jammy}"
NETWORK="${NETWORK:-vyg_net}"
WP_CONTAINER="${WP_CONTAINER:-vyg-wp}"
WP_PATH="${WP_PATH:-/var/www/html}"
WP_BASE_URL="${WP_BASE_URL:-http://vyg-wp}"
SCREENSHOT_DIR="${SCREENSHOT_DIR:-screenshots/phase13}"
CHROME="${CHROME:-/ms-playwright/chromium-1124/chrome-linux/chrome}"
CAPTURE_TIMEOUT="${CAPTURE_TIMEOUT:-90s}"
SEED_FILE="${WP_PATH}/wp-content/plugins/vector-youtube-gallery/dev/seed-phase13-1.php"
LOGIN_MU_SRC="scripts/mu-vyg-screenshot-login.php"
LOGIN_MU_DEST="${WP_PATH}/wp-content/mu-plugins/vyg-screenshot-login.php"

cleanup() {
    set +e
    docker exec -u root "$WP_CONTAINER" rm -f "$LOGIN_MU_DEST" >/dev/null 2>&1 || true
    docker exec -u www-data "$WP_CONTAINER" wp option delete vyg_screenshot_token_hash --path="$WP_PATH" --allow-root >/dev/null 2>&1 || true
    docker exec -u www-data "$WP_CONTAINER" wp option delete vyg_screenshot_user_id --path="$WP_PATH" --allow-root >/dev/null 2>&1 || true
}
trap cleanup EXIT

urlencode() {
    python3 - "$1" <<'PY'
import sys, urllib.parse
print(urllib.parse.quote(sys.argv[1], safe=''))
PY
}

new_token() {
    python3 - <<'PY'
import secrets
print(secrets.token_urlsafe(32))
PY
}

set_token() {
    local token="$1"
    local hash
    hash="$(python3 - "$token" <<'PY'
import hashlib, sys
print(hashlib.sha256(sys.argv[1].encode()).hexdigest())
PY
)"
    docker exec -u www-data "$WP_CONTAINER" wp option update vyg_screenshot_token_hash "$hash" --autoload=no --path="$WP_PATH" --allow-root >/dev/null
}

wp_eval() {
    docker exec -u www-data "$WP_CONTAINER" wp eval "$1" --path="$WP_PATH" --allow-root
}

# capture_url <name> <page_id> [width] [height] [wait_ms] [mobile_flag]
capture_url() {
    local name="$1"
    local page_id="$2"
    local width="${3:-1440}"
    local height="${4:-1400}"
    local wait_ms="${5:-3000}"
    local mobile_flag="${6:-}"
    local token login_url out size
    token="$(new_token)"
    set_token "$token"
    local target="${WP_BASE_URL}/?page_id=${page_id}"
    login_url="${WP_BASE_URL}/?vyg_screenshot_login=${token}&redirect_to=$(urlencode "$target")"
    out="/work/${SCREENSHOT_DIR}/${name}.png"
    docker run --rm \
        --network "$NETWORK" \
        -v "$PROJECT_DIR:/work" \
        -w /work \
        "$IMAGE" \
        timeout "$CAPTURE_TIMEOUT" node --experimental-websocket scripts/cdp-screenshot-viewport.js \
            "$login_url" \
            "$out" \
            "$wait_ms" \
            "$width" \
            "$height" \
            "$mobile_flag" >/dev/null 2>&1
    size="$(stat -c '%s' "${SCREENSHOT_DIR}/${name}.png" 2>/dev/null || echo 0)"
    if [[ "$size" -lt 50000 ]]; then
        echo "[phase13.1-playwright] ERROR: ${name}.png suspiciously small (${size} bytes)" >&2
        return 1
    fi
    echo "[phase13.1-playwright] ${name}.png $((size / 1024)) KB"
}

mkdir -p "$SCREENSHOT_DIR"

if ! docker exec -u www-data "$WP_CONTAINER" wp plugin is-active vector-youtube-gallery --path="$WP_PATH" --allow-root >/dev/null 2>&1; then
    echo "[phase13.1-playwright] ERROR: vector-youtube-gallery not active" >&2
    exit 1
fi

echo "[phase13.1-playwright] seeding Phase 13.1 data..."
docker exec -u root "$WP_CONTAINER" chmod 644 "$SEED_FILE"
docker exec -u www-data "$WP_CONTAINER" wp eval-file "$SEED_FILE" --path="$WP_PATH" --allow-root

echo "[phase13.1-playwright] installing temporary login MU plugin..."
docker exec -u root "$WP_CONTAINER" mkdir -p "${WP_PATH}/wp-content/mu-plugins"
docker cp "$LOGIN_MU_SRC" "${WP_CONTAINER}:${LOGIN_MU_DEST}"
docker exec -u root "$WP_CONTAINER" chown www-data:www-data "$LOGIN_MU_DEST"
docker exec -u root "$WP_CONTAINER" chmod 644 "$LOGIN_MU_DEST"
ADMIN_ID="$(docker exec -u www-data "$WP_CONTAINER" wp user list --role=administrator --field=ID --path="$WP_PATH" --allow-root | head -1)"
docker exec -u www-data "$WP_CONTAINER" wp eval "update_option('vyg_screenshot_user_id', (int) ${ADMIN_ID}, false);" --path="$WP_PATH" --allow-root >/dev/null

echo "[phase13.1-playwright] resolving page IDs..."
PAGES_JSON="$(wp_eval '
$slugs = ["phase-13-1-comfortable","phase-13-1-compact","phase-13-1-editorial","phase-13-1-with-header"];
$out = [];
foreach ($slugs as $slug) {
    $p = get_page_by_path($slug, OBJECT, "page");
    $out[$slug] = $p ? (int) $p->ID : 0;
}
echo wp_json_encode($out);
')"
echo "[phase13.1-playwright] pages=${PAGES_JSON}"

pid_comfortable="$(python3 - "$PAGES_JSON" <<'PY'
import json, sys
print(json.loads(sys.argv[1])["phase-13-1-comfortable"])
PY
)"
pid_compact="$(python3 - "$PAGES_JSON" <<'PY'
import json, sys
print(json.loads(sys.argv[1])["phase-13-1-compact"])
PY
)"
pid_editorial="$(python3 - "$PAGES_JSON" <<'PY'
import json, sys
print(json.loads(sys.argv[1])["phase-13-1-editorial"])
PY
)"
pid_header="$(python3 - "$PAGES_JSON" <<'PY'
import json, sys
print(json.loads(sys.argv[1])["phase-13-1-with-header"])
PY
)"

echo "[phase13.1-playwright] suspending VYG cron hooks during browser capture..."
wp_eval 'foreach (["vyg_cron_incremental_all", "vyg_cron_metadata_refresh", "vyg_cron_live_poll", "vyg_cron_data_retention"] as $hook) { wp_clear_scheduled_hook($hook); }' >/dev/null

API_BEFORE="$(wp_eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}vyg_api_quota_log");')"

echo "[phase13.1-playwright] preflight rendering checks..."
docker exec -u www-data "$WP_CONTAINER" wp eval-file \
    "${WP_PATH}/wp-content/plugins/vector-youtube-gallery/dev/preflight-phase13-1.php" \
    --path="$WP_PATH" --allow-root 2>&1 \
    | tee /tmp/phase13-1-preflight.txt
preflight_status="${PIPESTATUS[0]}"
if [[ "$preflight_status" -ne 0 ]]; then
    echo "[phase13.1-playwright] ERROR: preflight failed (status=$preflight_status)" >&2
    exit 1
fi
if grep -q '^FAIL' /tmp/phase13-1-preflight.txt; then
    echo "[phase13.1-playwright] ERROR: preflight reported too few cards" >&2
    exit 1
fi

echo "[phase13.1-playwright] capturing screenshots with bundled Chromium from ${IMAGE}..."
capture_url "grid-comfortable-desktop"   "$pid_comfortable" 1280 900 3500
capture_url "grid-comfortable-mobile"    "$pid_comfortable" 380  800 3500 mobile
capture_url "grid-compact-desktop"       "$pid_compact"     1280 900 3500
capture_url "grid-editorial-desktop"     "$pid_editorial"   1280 1200 3500
capture_url "grid-with-header-cta-trust" "$pid_header"      1280 1400 3500

# Card-zoom: navigate to the same page and crop to the first .vyg-card via
# Runtime.evaluate. The script will be a separate one-shot step below.
echo "[phase13.1-playwright] capturing card zoom (first .vyg-card in the with-header page)..."
TOKEN="$(new_token)"
set_token "$TOKEN"
TARGET="${WP_BASE_URL}/?page_id=${pid_header}"
LOGIN_URL="${WP_BASE_URL}/?vyg_screenshot_login=${TOKEN}&redirect_to=$(urlencode "$TARGET")"
OUT="/work/${SCREENSHOT_DIR}/grid-card-zoom.png"
docker run --rm \
    --network "$NETWORK" \
    -v "$PROJECT_DIR:/work" \
    -w /work \
    "$IMAGE" \
    timeout "$CAPTURE_TIMEOUT" node --experimental-websocket scripts/cdp-screenshot-card.js \
        "$LOGIN_URL" \
        "$OUT" \
        "3500" >/dev/null 2>&1
size="$(stat -c '%s' "${SCREENSHOT_DIR}/grid-card-zoom.png" 2>/dev/null || echo 0)"
if [[ "$size" -lt 30000 ]]; then
    echo "[phase13.1-playwright] ERROR: grid-card-zoom.png suspiciously small (${size} bytes)" >&2
    exit 1
fi
echo "[phase13.1-playwright] grid-card-zoom.png $((size / 1024)) KB"

API_AFTER="$(wp_eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}vyg_api_quota_log");')"
DELTA=$(( API_AFTER - API_BEFORE ))
echo "[phase13.1-playwright] api_quota_delta=${DELTA}"
if [[ "$DELTA" -ne 0 ]]; then
    echo "[phase13.1-playwright] ERROR: screenshot capture triggered YouTube API quota log rows" >&2
    exit 1
fi

echo "[phase13.1-playwright] screenshots written to ${SCREENSHOT_DIR}"
find "$SCREENSHOT_DIR" -maxdepth 1 -type f -name 'grid-*.png' -printf '%f %k KB\n' | sort
