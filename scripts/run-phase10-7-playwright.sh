#!/usr/bin/env bash
# Capture Phase 10.7 page-builder screenshots with the official Microsoft
# Playwright Docker image. Mirrors scripts/run-phase11-playwright.sh: no npm
# install, no host browser dependency, and zero YouTube API quota rows during
# capture.

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
CAPTURE_TIMEOUT="${CAPTURE_TIMEOUT:-90s}"
LOGIN_MU_SRC="scripts/mu-vyg-screenshot-login.php"
LOGIN_MU_DEST="${WP_PATH}/wp-content/mu-plugins/vyg-screenshot-login.php"
PRODUCT_MU_SRC="dev/mu-vyg-phase10-7-product-map.php"
PRODUCT_MU_DEST="${WP_PATH}/wp-content/mu-plugins/vyg-phase10-7-product-map.php"
SEED_FILE="${WP_PATH}/wp-content/plugins/vector-youtube-gallery/dev/seed-phase10-7.php"
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
  docker exec -u www-data "$WP_CONTAINER" wp eval '
foreach (["vyg_cron_incremental_all", "vyg_cron_metadata_refresh", "vyg_cron_live_poll", "vyg_cron_data_retention"] as $hook) {
    wp_clear_scheduled_hook($hook);
}
if (! wp_next_scheduled("vyg_cron_incremental_all")) { wp_schedule_event(time() + HOUR_IN_SECONDS, "hourly", "vyg_cron_incremental_all"); }
if (! wp_next_scheduled("vyg_cron_metadata_refresh")) { wp_schedule_event(time() + DAY_IN_SECONDS, "twicedaily", "vyg_cron_metadata_refresh"); }
if (! wp_next_scheduled("vyg_cron_live_poll")) { wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, "vyg_five_minutes", "vyg_cron_live_poll"); }
if (! wp_next_scheduled("vyg_cron_data_retention")) { wp_schedule_event(time() + DAY_IN_SECONDS, "daily", "vyg_cron_data_retention"); }
' --path="$WP_PATH" --allow-root >/dev/null 2>&1 || true
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

capture_url() {
  local name="$1"
  local target="$2"
  local wait_ms="${3:-3000}"
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
    timeout "$CAPTURE_TIMEOUT" node --experimental-websocket scripts/cdp-screenshot.js \
      "$login_url" \
      "$out" \
      "$wait_ms" >/dev/null 2>&1
  size="$(stat -c '%s' "${SCREENSHOT_DIR}/${name}.png")"
  if [[ "$size" -lt 50000 ]]; then
    echo "[phase10.7-playwright] ERROR: ${name}.png suspiciously small (${size} bytes)" >&2
    return 1
  fi
  echo "[phase10.7-playwright] ${name}.png $((size / 1024)) KB"
}

require_plugin_active() {
  local slug="$1"
  if ! docker exec -u www-data "$WP_CONTAINER" wp plugin is-active "$slug" --path="$WP_PATH" --allow-root >/dev/null 2>&1; then
    echo "[phase10.7-playwright] ERROR: required plugin inactive: ${slug}" >&2
    exit 1
  fi
}

mkdir -p "$SCREENSHOT_DIR"

require_plugin_active elementor
require_plugin_active woocommerce
require_plugin_active vector-youtube-gallery

ORIG_SITEURL="$(docker exec -u www-data "$WP_CONTAINER" wp option get siteurl --path="$WP_PATH" --allow-root)"
ORIG_HOME="$(docker exec -u www-data "$WP_CONTAINER" wp option get home --path="$WP_PATH" --allow-root)"
echo "[phase10.7-playwright] temporarily routing WordPress URLs through ${WP_BASE_URL}..."
docker exec -u www-data "$WP_CONTAINER" wp option update siteurl "$WP_BASE_URL" --path="$WP_PATH" --allow-root >/dev/null
docker exec -u www-data "$WP_CONTAINER" wp option update home "$WP_BASE_URL" --path="$WP_PATH" --allow-root >/dev/null

echo "[phase10.7-playwright] seeding Phase 10.7 data..."
docker exec -u root "$WP_CONTAINER" chmod 644 "$SEED_FILE"
docker exec -u www-data "$WP_CONTAINER" wp eval-file "$SEED_FILE" --path="$WP_PATH" --allow-root

echo "[phase10.7-playwright] installing temporary login + product-map MU plugins..."
docker exec -u root "$WP_CONTAINER" mkdir -p "${WP_PATH}/wp-content/mu-plugins"
docker cp "$LOGIN_MU_SRC" "${WP_CONTAINER}:${LOGIN_MU_DEST}"
docker cp "$PRODUCT_MU_SRC" "${WP_CONTAINER}:${PRODUCT_MU_DEST}"
docker exec -u root "$WP_CONTAINER" chown www-data:www-data "$LOGIN_MU_DEST" "$PRODUCT_MU_DEST"
docker exec -u root "$WP_CONTAINER" chmod 644 "$LOGIN_MU_DEST" "$PRODUCT_MU_DEST"
ADMIN_ID="$(docker exec -u www-data "$WP_CONTAINER" wp user list --role=administrator --field=ID --path="$WP_PATH" --allow-root | head -1)"
docker exec -u www-data "$WP_CONTAINER" wp eval "update_option('vyg_screenshot_user_id', (int) ${ADMIN_ID}, false);" --path="$WP_PATH" --allow-root >/dev/null

echo "[phase10.7-playwright] creating deterministic Elementor/Gutenberg pages..."
PAGE_DATA="$(wp_eval '
$feed_uuid = "phase-10-7-feed";
$elementor_slug = "phase-10-7-elementor";
$gutenberg_slug = "phase-10-7-gutenberg";
$shortcode_slug = "phase-10-7-integration-demo";
$elementor = get_page_by_path($elementor_slug, OBJECT, "page");
if (! $elementor) {
  $elementor_id = wp_insert_post(["post_title" => "Phase 10.7 — Elementor Widget", "post_name" => $elementor_slug, "post_status" => "publish", "post_type" => "page"]);
} else { $elementor_id = (int) $elementor->ID; wp_update_post(["ID" => $elementor_id, "post_status" => "publish"]); }
$edata = [["id"=>"vyg10e7c","elType"=>"container","settings"=>[],"elements"=>[["id"=>"vyg10e7w","elType"=>"widget","settings"=>["feed_uuid"=>$feed_uuid,"layout"=>"grid","columns"=>3,"per_page"=>6,"preset"=>"cinema","schema_enabled"=>"yes"],"elements"=>[],"widgetType"=>"vyg_gallery"]],"isInner"=>false]];
update_post_meta($elementor_id, "_elementor_edit_mode", "builder");
update_post_meta($elementor_id, "_elementor_template_type", "wp-page");
update_post_meta($elementor_id, "_elementor_version", defined("ELEMENTOR_VERSION") ? ELEMENTOR_VERSION : "4.1.4");
update_post_meta($elementor_id, "_elementor_data", wp_slash(wp_json_encode($edata)));
$gutenberg = get_page_by_path($gutenberg_slug, OBJECT, "page");
$block = "<!-- wp:vectoryt/gallery {\"feed_uuid\":\"$feed_uuid\",\"layout\":\"grid\",\"columns\":3,\"per_page\":6,\"preset\":\"cinema\",\"schema_enabled\":true} /-->";
if (! $gutenberg) {
  $gutenberg_id = wp_insert_post(["post_title" => "Phase 10.7 — Gutenberg Block", "post_name" => $gutenberg_slug, "post_status" => "publish", "post_type" => "page", "post_content" => $block]);
} else { $gutenberg_id = (int) $gutenberg->ID; wp_update_post(["ID" => $gutenberg_id, "post_status" => "publish", "post_content" => $block]); }
$shortcode = get_page_by_path($shortcode_slug, OBJECT, "page");
$shortcode_id = $shortcode ? (int) $shortcode->ID : 0;
if ($shortcode_id) { wp_update_post(["ID" => $shortcode_id, "post_status" => "publish"]); }
echo wp_json_encode(["elementor_id"=>$elementor_id,"gutenberg_id"=>$gutenberg_id,"shortcode_id"=>$shortcode_id]);
')"
echo "[phase10.7-playwright] pages=${PAGE_DATA}"
ELEMENTOR_ID="$(python3 - "$PAGE_DATA" <<'PY'
import json, sys
print(json.loads(sys.argv[1])["elementor_id"])
PY
)"
GUTENBERG_ID="$(python3 - "$PAGE_DATA" <<'PY'
import json, sys
print(json.loads(sys.argv[1])["gutenberg_id"])
PY
)"
SHORTCODE_ID="$(python3 - "$PAGE_DATA" <<'PY'
import json, sys
print(json.loads(sys.argv[1])["shortcode_id"])
PY
)"

echo "[phase10.7-playwright] suspending VYG cron hooks during browser capture..."
wp_eval 'foreach (["vyg_cron_incremental_all", "vyg_cron_metadata_refresh", "vyg_cron_live_poll", "vyg_cron_data_retention"] as $hook) { wp_clear_scheduled_hook($hook); }' >/dev/null

API_BEFORE="$(wp_eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}vyg_api_quota_log");')"

echo "[phase10.7-playwright] preflight rendering checks..."
wp_eval '
$feed_uuid = "phase-10-7-feed";
$container = \VectorYT\Gallery\Plugin::container();
$feeds = $container->get("repo.feeds");
$renderer = $container->get("render.renderer");
$row = $feeds->find_by_uuid($feed_uuid);
$config = \VectorYT\Gallery\Repository\FeedRepository::decode_config($row);
$checks = [];
$checks["shortcode"] = do_shortcode("[youtube_feed feed_uuid=\"phase-10-7-feed\" layout=\"grid\" columns=\"3\" per_page=\"6\" preset=\"cinema\" schema_enabled=\"1\"]");
$checks["elementor"] = $renderer->render([
  "feed_uuid" => $feed_uuid,
  "source_config" => $config["source"] ?? [],
  "feed_config" => $config,
  "layout" => "grid",
  "columns" => 3,
  "per_page" => 6,
  "preset" => "cinema",
  "schema_enabled" => true,
  "public_safe" => true,
]);
if (! function_exists("render_block_vectoryt_gallery")) { require_once WP_PLUGIN_DIR . "/vector-youtube-gallery/src/Render/render.php"; }
$checks["gutenberg"] = render_block_vectoryt_gallery([
  "feed_uuid" => $feed_uuid,
  "layout" => "grid",
  "columns" => 3,
  "per_page" => 6,
  "preset" => "cinema",
  "schema_enabled" => true,
]);
foreach ($checks as $name => $html) {
  $missing = strpos($html, "Missing source_uuid") !== false ? "yes" : "no";
  $cards = substr_count($html, "vyg-card");
  $cta = strpos($html, "vyg-card__cta") !== false ? "yes" : "no";
  echo $name . " missing=" . $missing . " cards=" . $cards . " cta=" . $cta . PHP_EOL;
  if ($missing === "yes" || $cards < 1) { exit(2); }
}
' >/tmp/phase10-7-preflight.txt
cat /tmp/phase10-7-preflight.txt
if ! grep -q 'shortcode missing=no' /tmp/phase10-7-preflight.txt; then exit 1; fi
if ! grep -q 'elementor missing=no' /tmp/phase10-7-preflight.txt; then exit 1; fi
if ! grep -q 'gutenberg missing=no' /tmp/phase10-7-preflight.txt; then exit 1; fi
if ! grep -q 'shortcode.* cta=yes' /tmp/phase10-7-preflight.txt; then
  echo "[phase10.7-playwright] ERROR: shortcode/WooCommerce CTA did not render" >&2
  exit 1
fi

echo "[phase10.7-playwright] capturing with bundled Chromium from ${IMAGE}..."
capture_url "phase10-7-elementor-widget-frontend" "${WP_BASE_URL}/?page_id=${ELEMENTOR_ID}" 3000
capture_url "phase10-7-woocommerce-cta" "${WP_BASE_URL}/?page_id=${SHORTCODE_ID}" 3000
capture_url "phase10-7-gutenberg-block-frontend" "${WP_BASE_URL}/?page_id=${GUTENBERG_ID}" 3000
capture_url "phase10-7-divi-fallback-shortcode" "${WP_BASE_URL}/?page_id=${SHORTCODE_ID}" 3000
capture_url "phase10-7-phase9-hero-regression" "${WP_BASE_URL}/phase-9-hero/" 3000

API_AFTER="$(wp_eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}vyg_api_quota_log");')"
DELTA=$(( API_AFTER - API_BEFORE ))
echo "[phase10.7-playwright] api_quota_delta=${DELTA}"
if [[ "$DELTA" -ne 0 ]]; then
  echo "[phase10.7-playwright] ERROR: screenshot capture triggered YouTube API quota log rows" >&2
  exit 1
fi

echo "[phase10.7-playwright] screenshots written to ${SCREENSHOT_DIR}"
find "$SCREENSHOT_DIR" -maxdepth 1 -type f -name 'phase10-7-*.png' -printf '%f %k KB\n' | sort
