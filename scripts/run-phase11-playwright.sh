#!/usr/bin/env bash
# Capture Phase 11 screenshots with the official Microsoft Playwright Docker
# image. This no-npm runner uses the Chromium binary already bundled in the
# image, avoiding host browser installs and avoiding npm registry dependency.

set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_DIR"

IMAGE="${PLAYWRIGHT_IMAGE:-mcr.microsoft.com/playwright:v1.45.0-jammy}"
NETWORK="${PLAYWRIGHT_NETWORK:-vyg_net}"
WP_CONTAINER="${WP_CONTAINER:-vyg-wp}"
WP_PATH="${WP_PATH:-/var/www/html}"
WP_BASE_URL="${WP_BASE_URL:-http://vyg-wp}"
SCREENSHOT_DIR="${SCREENSHOT_DIR:-screenshots/playwright}"
CHROME="/ms-playwright/chromium-1124/chrome-linux/chrome"
MU_PLUGIN_SRC="scripts/mu-vyg-screenshot-login.php"
MU_PLUGIN_DEST="${WP_PATH}/wp-content/mu-plugins/vyg-screenshot-login.php"
API_BEFORE=""
API_AFTER=""
ORIG_SITEURL=""
ORIG_HOME=""

cleanup() {
  set +e
  if [[ -n "$ORIG_SITEURL" ]]; then
    docker exec -u www-data "$WP_CONTAINER" wp option update siteurl "$ORIG_SITEURL" --path="$WP_PATH" --allow-root >/dev/null 2>&1 || true
  fi
  if [[ -n "$ORIG_HOME" ]]; then
    docker exec -u www-data "$WP_CONTAINER" wp option update home "$ORIG_HOME" --path="$WP_PATH" --allow-root >/dev/null 2>&1 || true
  fi
  docker exec -u root "$WP_CONTAINER" rm -f "$MU_PLUGIN_DEST" >/dev/null 2>&1 || true
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

capture_url() {
  local name="$1"
  local target="$2"
  local token login_url out size
  token="$(new_token)"
  set_token "$token"
  login_url="${WP_BASE_URL}/?vyg_screenshot_login=${token}&redirect_to=$(urlencode "$target")"
  out="/work/${SCREENSHOT_DIR}/${name}.png"
  docker run --rm \
    --network "$NETWORK" \
    -v "$PROJECT_DIR:/work" \
    -w /work \
    "$IMAGE" \
    "$CHROME" \
      --headless=new \
      --no-sandbox \
      --disable-gpu \
      --disable-dev-shm-usage \
      --hide-scrollbars \
      --window-size=1440,1400 \
      --virtual-time-budget=2500 \
      --screenshot="$out" \
      "$login_url" >/dev/null 2>&1
  size="$(stat -c '%s' "${SCREENSHOT_DIR}/${name}.png")"
  if [[ "$size" -lt 50000 ]]; then
    echo "[phase11-playwright] ERROR: ${name}.png suspiciously small (${size} bytes)" >&2
    return 1
  fi
  echo "[phase11-playwright] ${name}.png $((size / 1024)) KB"
}

mkdir -p "$SCREENSHOT_DIR"

ORIG_SITEURL="$(docker exec -u www-data "$WP_CONTAINER" wp option get siteurl --path="$WP_PATH" --allow-root)"
ORIG_HOME="$(docker exec -u www-data "$WP_CONTAINER" wp option get home --path="$WP_PATH" --allow-root)"
echo "[phase11-playwright] temporarily routing WordPress URLs through ${WP_BASE_URL}..."
docker exec -u www-data "$WP_CONTAINER" wp option update siteurl "$WP_BASE_URL" --path="$WP_PATH" --allow-root >/dev/null
docker exec -u www-data "$WP_CONTAINER" wp option update home "$WP_BASE_URL" --path="$WP_PATH" --allow-root >/dev/null

echo "[phase11-playwright] seeding screenshot data..."
docker exec -u root "$WP_CONTAINER" chmod 644 "/var/www/html/wp-content/plugins/vector-youtube-gallery/scripts/seed-phase11-screenshots.php"
docker exec -u www-data "$WP_CONTAINER" wp eval-file "/var/www/html/wp-content/plugins/vector-youtube-gallery/scripts/seed-phase11-screenshots.php" --path="$WP_PATH" --allow-root

echo "[phase11-playwright] installing temporary one-time login MU plugin..."
docker exec -u root "$WP_CONTAINER" mkdir -p "${WP_PATH}/wp-content/mu-plugins"
docker cp "$MU_PLUGIN_SRC" "${WP_CONTAINER}:${MU_PLUGIN_DEST}"
docker exec -u root "$WP_CONTAINER" chown www-data:www-data "$MU_PLUGIN_DEST" && docker exec -u root "$WP_CONTAINER" chmod 644 "$MU_PLUGIN_DEST"
ADMIN_ID="$(docker exec -u www-data "$WP_CONTAINER" wp user list --role=administrator --field=ID --path="$WP_PATH" --allow-root | head -1)"
docker exec -u www-data "$WP_CONTAINER" wp option update vyg_screenshot_user_id "$ADMIN_ID" --autoload=no --path="$WP_PATH" --allow-root >/dev/null

API_BEFORE="$(docker exec -u www-data "$WP_CONTAINER" wp eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}vyg_api_quota_log");' --path="$WP_PATH" --allow-root)"

echo "[phase11-playwright] capturing with bundled Chromium from ${IMAGE}..."
capture_url "phase11-analytics-dashboard" "${WP_BASE_URL}/wp-admin/admin.php?page=vector-youtube-gallery-analytics&days=30"
capture_url "phase11-moderation-queue" "${WP_BASE_URL}/wp-admin/admin.php?page=vector-youtube-gallery-moderation&queue=needs_review"
capture_url "phase11-videos-page" "${WP_BASE_URL}/wp-admin/admin.php?page=vector-youtube-gallery-videos"

API_AFTER="$(docker exec -u www-data "$WP_CONTAINER" wp eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}vyg_api_quota_log");' --path="$WP_PATH" --allow-root)"
DELTA=$(( API_AFTER - API_BEFORE ))
echo "[phase11-playwright] api_quota_delta=${DELTA}"
if [[ "$DELTA" -ne 0 ]]; then
  echo "[phase11-playwright] ERROR: screenshot capture triggered YouTube API quota log rows" >&2
  exit 1
fi

echo "[phase11-playwright] screenshots written to ${SCREENSHOT_DIR}"
find "$SCREENSHOT_DIR" -maxdepth 1 -type f -name 'phase11-*.png' -printf '%f %k KB\n' | sort
