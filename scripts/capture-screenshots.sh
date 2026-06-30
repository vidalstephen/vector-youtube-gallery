#!/usr/bin/env bash
#
# scripts/capture-screenshots.sh
#
# Captures admin + front-end screenshots for Phase 6 (and beyond) verification.
# Follows the phase-worker recipe: chromium lives inside the wordpress container,
# we fetch HTML via host curl with a cookie jar, copy HTML into the container, render
# with chromium, copy PNGs back out.
#
# Usage:
#   ./scripts/capture-screenshots.sh
#
# Output:
#   screenshots/*.png  (also visible inside the container at /screenshots/)

set -euo pipefail

PROJECT_DIR="${PROJECT_DIR:-/root/projects/vector-youtube-gallery}"
SHOTS_DIR="$PROJECT_DIR/screenshots"
SCRATCH_DIR="/tmp/vyg-shots"
WP_URL="${WP_URL:-http://localhost:8000}"
# Chromium inside the container resolves localhost to IPv6 ::1 which Apache
# may not listen on. Always use 127.0.0.1 inside the container.
WP_URL_INNER="${WP_URL_INNER:-http://127.0.0.1}"
COOKIES="$SCRATCH_DIR/cookies.txt"
CONTAINER_SCREENSHOTS="/screenshots"

mkdir -p "$SHOTS_DIR" "$SCRATCH_DIR"

# Log in.
echo "==> Logging in to $WP_URL"
curl -fsS -c "$COOKIES" -b "$COOKIES" \
  -X POST "$WP_URL/wp-login.php" \
  -d "log=admin&pwd=changeme_wp_admin_password&wp-submit=Log+In&redirect_to=${WP_URL}%2Fwp-admin%2F&testcookie=1" \
  -o /dev/null

# Page map: filename|page_slug|window_size
ADMIN_PAGES=(
  "01-dashboard-widget|index.php|1400x1200"
  "02-sources|admin.php?page=vector-youtube-gallery|1400x1400"
  "03-feeds-list|admin.php?page=vector-youtube-gallery-feeds|1400x1100"
  "04-feeds-edit|admin.php?page=vector-youtube-gallery-feeds&action=edit&id=1|1400x1800"
  "05-privacy|admin.php?page=vector-youtube-gallery-privacy|1400x1800"
  "06-diagnostics|admin.php?page=vector-youtube-gallery-diagnostics|1400x1600"
  "07-videos|admin.php?page=vector-youtube-gallery-videos|1400x1400"
  "08-system-info|admin.php?page=vector-youtube-gallery-system-info|1400x1100"
)

# Helper: fetch HTML with cookies, copy into container, render with chromium, copy out.
capture() {
  local filename="$1"
  local path="$2"
  local size="$3"
  local html_in="$SCRATCH_DIR/${filename}.html"
  local html_container="/screenshots/${filename}.html"
  local png_container="/screenshots/${filename}.png"
  local png_out="$SHOTS_DIR/${filename}.png"

  echo "==> Capturing $filename ($path @ $size)"
  curl -fsS -b "$COOKIES" "$WP_URL/wp-admin/$path" -o "$html_in"
  docker cp "$html_in" "vyg-wp:$html_container" >/dev/null

  docker exec -u root vyg-wp bash -c "
    chromium --headless --disable-gpu --no-sandbox --hide-scrollbars \
      --window-size=$size \
      --screenshot=$png_container \
      --virtual-time-budget=3000 \
      file://$html_container
  " 2>&1 | grep -v -E 'dbus|D-Bus|GPU|EGL' | tail -3 || true

  if docker exec vyg-wp test -f "$png_container"; then
    docker cp "vyg-wp:$png_container" "$png_out"
    local kb=$(du -k "$png_out" | cut -f1)
    echo "    saved $png_out ($kb KB)"
  else
    echo "    FAILED to render $filename"
    return 1
  fi
}

# Front-end pages (no auth needed).
capture_public() {
  local filename="$1"
  local url="$2"
  local size="$3"
  local png_container="/screenshots/${filename}.png"
  local png_out="$SHOTS_DIR/${filename}.png"

  echo "==> Capturing $filename (public @ $size)"
  docker exec -u root vyg-wp bash -c "
    chromium --headless --disable-gpu --no-sandbox --hide-scrollbars \
      --window-size=$size \
      --screenshot=$png_container \
      --virtual-time-budget=4000 \
      '$url'
  " 2>&1 | grep -v -E 'dbus|D-Bus|GPU|EGL' | tail -3 || true

  if docker exec vyg-wp test -f "$png_container"; then
    docker cp "vyg-wp:$png_container" "$png_out"
    local kb=$(du -k "$png_out" | cut -f1)
    echo "    saved $png_out ($kb KB)"
  else
    echo "    FAILED to render $filename"
    return 1
  fi
}

# Run admin captures.
for entry in "${ADMIN_PAGES[@]}"; do
  IFS='|' read -r fname slug size <<< "$entry"
  capture "$fname" "$slug" "$size"
done

# Run public captures (front-end + login). Chromium must hit 127.0.0.1 directly
# (not localhost) because Apache inside the wordpress container may not listen
# on IPv6 loopback. Use ?page_id=N form because permalinks require .htaccess.
capture_public "09-login" "$WP_URL_INNER/wp-login.php" "1400x900"
capture_public "10-frontend-feed" "$WP_URL_INNER/?page_id=11" "1400x1400"
capture_public "11-frontend-mobile" "$WP_URL_INNER/?page_id=11" "390x900"

echo ""
echo "==> Done. Output: $SHOTS_DIR/"
ls -la "$SHOTS_DIR/"