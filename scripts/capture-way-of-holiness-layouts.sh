#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

SOURCE_UUID="${VYG_WOH_SOURCE_UUID:-d58dd67c-5686-4245-90ab-cf5e915b9e37}"
PAGE_SLUG="${VYG_WOH_PAGE_SLUG:-vyg-way-of-holiness-layout-polish}"
PAGE_TITLE="${VYG_WOH_PAGE_TITLE:-VYG Way Of Holiness Layout Polish}"
OUT_DIR="${VYG_WOH_OUT_DIR:-screenshots/way-of-holiness/layouts}"
mkdir -p "$OUT_DIR"

PAGE_ID=$(docker exec -u www-data vyg-wp wp post list \
  --post_type=page \
  --name="$PAGE_SLUG" \
  --field=ID \
  --path=/var/www/html 2>/dev/null | head -1 || true)

if [[ -z "$PAGE_ID" ]]; then
  PAGE_ID=$(docker exec -u www-data vyg-wp wp post create \
    --post_type=page \
    --post_status=publish \
    --post_name="$PAGE_SLUG" \
    --post_title="$PAGE_TITLE" \
    --porcelain \
    --path=/var/www/html)
fi

quota_before=$(docker exec -u www-data vyg-wp wp eval \
  'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}vyg_api_quota_log");' \
  --path=/var/www/html 2>/dev/null || echo 0)

capture_layout() {
  local layout="$1"
  local content_type="live_replay"
  local per_page="12"
  local columns="3"

  case "$layout" in
    shorts)
      # The Way Of Holiness source currently has no confirmed shorts in the
      # local cache. Force cached replay rows through the shorts layout so the
      # CSS/card treatment remains visually testable with this real source.
      content_type="live_replay"
      columns="4"
      ;;
    live)
      content_type="live_active,live_upcoming,live_replay"
      ;;
  esac

  local shortcode="[youtube_feed source_uuid=\"${SOURCE_UUID}\" layout=\"${layout}\" columns=\"${columns}\" per_page=\"${per_page}\" content_type=\"${content_type}\" orderby=\"published_at\" order=\"DESC\" pagination=\"none\"]"
  docker exec -u www-data vyg-wp wp post update "$PAGE_ID" \
    --post_content="$shortcode" \
    --path=/var/www/html >/dev/null

  docker exec -u www-data -e PAGE_ID="$PAGE_ID" -e LAYOUT="$layout" vyg-wp wp eval '
    $post = get_post((int) getenv("PAGE_ID"));
    $html = $post ? do_shortcode($post->post_content) : "";
    echo getenv("LAYOUT") . " render_len=" . strlen($html)
      . " vyg_card=" . substr_count($html, "vyg-card")
      . " shorts_card=" . substr_count($html, "vyg-shorts__card")
      . " live_card=" . substr_count($html, "vyg-live__card")
      . " has_title=" . (str_contains($html, "The Great World Reset") ? "yes" : "no")
      . " has_error=" . (str_contains($html, "Missing source_uuid") || str_contains($html, "Source not found") ? "yes" : "no")
      . "\n";
  ' --path=/var/www/html

  local url="http://vyg-wp/?page_id=${PAGE_ID}&vyg_cache_bust=$(date +%s)-${layout}"
  docker run --rm --network vyg_net \
    -v "$PROJECT_ROOT:/work" \
    -w /work \
    mcr.microsoft.com/playwright:v1.45.0-jammy \
    node --experimental-websocket scripts/cdp-screenshot-viewport.js \
    "$url" "$OUT_DIR/${layout}-desktop.png" 1280 1200 >/tmp/vyg-${layout}-desktop.log 2>&1
  docker run --rm --network vyg_net \
    -v "$PROJECT_ROOT:/work" \
    -w /work \
    mcr.microsoft.com/playwright:v1.45.0-jammy \
    node --experimental-websocket scripts/cdp-screenshot-viewport.js \
    "$url" "$OUT_DIR/${layout}-mobile.png" 390 900 >/tmp/vyg-${layout}-mobile.log 2>&1
}

for layout in grid list featured shorts masonry carousel hero live; do
  capture_layout "$layout"
done

python3 - <<'PY'
from pathlib import Path
from PIL import Image, ImageDraw
base = Path('screenshots/way-of-holiness/layouts')
thumbs = []
for layout in ['grid','list','featured','shorts','masonry','carousel','hero','live']:
    p = base / f'{layout}-desktop.png'
    im = Image.open(p).convert('RGB')
    crop = im.crop((0, 0, min(im.width, 1200), min(im.height, 900)))
    crop.thumbnail((360, 260))
    canvas = Image.new('RGB', (380, 300), 'white')
    canvas.paste(crop, ((380 - crop.width) // 2, 30))
    ImageDraw.Draw(canvas).text((10, 8), layout, fill=(0, 0, 0))
    thumbs.append(canvas)
sheet = Image.new('RGB', (760, 1200), (245, 245, 245))
for i, thumb in enumerate(thumbs):
    sheet.paste(thumb, ((i % 2) * 380, (i // 2) * 300))
sheet.save(base / 'contact-sheet-desktop.png')
for p in sorted(base.glob('*.png')):
    im = Image.open(p)
    print(f'{p}: {im.width}x{im.height}, {p.stat().st_size:,} bytes')
PY

quota_after=$(docker exec -u www-data vyg-wp wp eval \
  'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}vyg_api_quota_log");' \
  --path=/var/www/html 2>/dev/null || echo 0)

echo "api_quota_before=${quota_before}"
echo "api_quota_after=${quota_after}"
echo "api_quota_delta=$((quota_after - quota_before))"
echo "page_id=${PAGE_ID}"
