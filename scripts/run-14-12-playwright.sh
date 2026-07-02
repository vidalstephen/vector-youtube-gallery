#!/usr/bin/env bash
# Phase 14.12 — render all 8 layouts on the dev source and capture
# desktop (1280x1800) + mobile (380x2400) PNGs side-by-side. Produces
# the per-layout PNGs that feed the contact-sheet montage.
#
# Mirrors scripts/run-14-1-playwright.sh (proven Approach A: official
# Microsoft Playwright Docker image + Node cdp-screenshot-viewport.js
# helper) and scripts/capture-way-of-holiness-layouts.sh (8-layout
# iteration pattern). Uses the real Way Of Holiness Broadcast source
# (UCETTSWoXxA-oEbwxqpbVf-w), so the captures show actual channel
# videos and thumbnails rather than the demo fixture.
#
# Captures (under screenshots/prototype-parity/):
#   {layout}-desktop.png   1280x1800
#   {layout}-mobile.png    380x2400
#   contact-sheet-desktop.png  (built by assemble-contact-sheet.sh)
#   contact-sheet-mobile.png   (built by assemble-contact-sheet.sh)
#
# Usage: scripts/run-14-12-playwright.sh

set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_DIR"

IMAGE="${IMAGE:-mcr.microsoft.com/playwright:v1.45.0-jammy}"
NETWORK="${NETWORK:-vyg_net}"
WP_CONTAINER="${WP_CONTAINER:-vyg-wp}"
WP_PATH="${WP_PATH:-/var/www/html}"
WP_BASE_URL="${WP_BASE_URL:-http://vyg-wp}"
SCREENSHOT_DIR="${SCREENSHOT_DIR:-screenshots/prototype-parity}"
SEED_FILE="${WP_PATH}/wp-content/plugins/vector-youtube-gallery/dev/seed-14-12-pages.php"
DESKTOP_W="${DESKTOP_W:-1280}"
DESKTOP_H="${DESKTOP_H:-1800}"
MOBILE_W="${MOBILE_W:-380}"
MOBILE_H="${MOBILE_H:-2400}"

mkdir -p "$SCREENSHOT_DIR"

# Quota baseline — must be 0 delta at the end.
quota_before=$(docker exec -u www-data "$WP_CONTAINER" wp eval \
    'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}vyg_api_quota_log");' \
    --path="$WP_PATH" 2>/dev/null || echo 0)
echo "[14.12] api_quota_log before: $quota_before"

# 1. Re-seed the 8 layout pages (idempotent).
echo "[14.12] seeding dev/seed-14-12-pages.php"
docker exec -u www-data "$WP_CONTAINER" wp eval-file "$SEED_FILE" --path="$WP_PATH" 2>&1 | tail -3

# 2. Resolve the 8 page IDs in a known order.
LAYOUTS=(grid list featured hero shorts masonry carousel live)
declare -A PAGE_IDS
for layout in "${LAYOUTS[@]}"; do
    pid=$(docker exec -u www-data "$WP_CONTAINER" wp post list \
        --post_type=page \
        --name="vyg-14-12-${layout}" \
        --field=ID \
        --path="$WP_PATH" 2>/dev/null | head -1)
    if [[ -z "$pid" ]]; then
        echo "[14.12] FATAL: seed page for layout='${layout}' not found"
        exit 1
    fi
    PAGE_IDS[$layout]="$pid"
done
echo "[14.12] resolved page_ids: $(printf '%s ' "${PAGE_IDS[@]}")"

# 3. Capture helper — runs an isolated Playwright container per layout.
capture_one() {
    local layout="$1"
    local viewport_w="$2"
    local viewport_h="$3"
    local suffix="$4"   # desktop | mobile
    local page_id="${PAGE_IDS[$layout]}"
    local url="${WP_BASE_URL}/?page_id=${page_id}&vyg_cache_bust=$(date +%s%N)-${layout}-${suffix}"
    local out="${SCREENSHOT_DIR}/${layout}-${suffix}.png"

    echo "[14.12] capture ${layout} ${suffix} (${viewport_w}x${viewport_h}) -> ${out}"
    docker run --rm \
        --network "$NETWORK" \
        -v "$PROJECT_DIR:$PROJECT_DIR" \
        -w "$PROJECT_DIR" \
        "$IMAGE" \
        node --experimental-websocket scripts/cdp-screenshot-viewport.js \
        "$url" "$PROJECT_DIR/$out" 10000 "$viewport_w" "$viewport_h" "false" \
        2>&1 | tail -3 || true

    if [[ ! -s "$out" ]]; then
        echo "[14.12] FATAL: empty/missing output $out"
        return 1
    fi
    local size
    size=$(stat -c '%s' "$out")
    echo "[14.12]   wrote ${out} (${size} bytes)"
}

# 4. Iterate — 8 layouts x 2 viewports = 16 PNGs.
for layout in "${LAYOUTS[@]}"; do
    capture_one "$layout" "$DESKTOP_W" "$DESKTOP_H" "desktop"
    capture_one "$layout" "$MOBILE_W"  "$MOBILE_H"  "mobile"
done

# 5. Quota delta check.
quota_after=$(docker exec -u www-data "$WP_CONTAINER" wp eval \
    'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}vyg_api_quota_log");' \
    --path="$WP_PATH" 2>/dev/null || echo 0)
delta=$(( quota_after - quota_before ))
echo "[14.12] api_quota_delta=${delta} (must be 0)"

ls -la "$SCREENSHOT_DIR"/*.png 2>&1

if [[ "$delta" != "0" ]]; then
    echo "[14.12] FATAL: api_quota_delta != 0"
    exit 1
fi

echo "[14.12] capture OK (16 PNGs, contact sheets assembled by assemble-contact-sheet.sh)"
