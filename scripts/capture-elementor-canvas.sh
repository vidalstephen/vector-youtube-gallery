#!/usr/bin/env bash
# Capture the Elementor Canvas page (page ID 72) using the same Playwright
# Docker approach as run-phase13-1-playwright.sh.
#
# Usage: bash scripts/capture-elementor-canvas.sh

set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_DIR"

SCREENSHOT_DIR="screenshots/phase13"
IMAGE="mcr.microsoft.com/playwright:v1.45.0-jammy"
NETWORK="vyg_net"
CAPTURE_TIMEOUT=45

mkdir -p "$SCREENSHOT_DIR"

PAGE_ID=72
WP_BASE_URL="http://vyg-wp"
TARGET="${WP_BASE_URL}/?page_id=${PAGE_ID}"

echo "[elementor-canvas] Capturing page ${PAGE_ID} at 1280×1400..."

OUT="/work/${SCREENSHOT_DIR}/grid-elementor-canvas-desktop.png"

docker run --rm \
    --network "$NETWORK" \
    -v "$PROJECT_DIR:/work" \
    -w /work \
    "$IMAGE" \
    timeout "$CAPTURE_TIMEOUT" node --experimental-websocket scripts/cdp-screenshot-viewport.js \
        "$TARGET" \
        "$OUT" \
        "4000" \
        "1280" \
        "1400" \
        "" 2>&1 || true

SIZE="$(stat -c '%s' "${SCREENSHOT_DIR}/grid-elementor-canvas-desktop.png" 2>/dev/null || echo 0)"
if [[ "$SIZE" -lt 50000 ]]; then
    echo "[elementor-canvas] ERROR: desktop screenshot suspiciously small (${SIZE} bytes)" >&2
    # Try with localhost URL as fallback
    echo "[elementor-canvas] Retrying with localhost URL..."
    TARGET="http://localhost:8000/?page_id=${PAGE_ID}"
    docker run --rm \
        --network host \
        -v "$PROJECT_DIR:/work" \
        -w /work \
        "$IMAGE" \
        timeout "$CAPTURE_TIMEOUT" node --experimental-websocket scripts/cdp-screenshot-viewport.js \
            "$TARGET" \
            "$OUT" \
            "4000" \
            "1280" \
            "1400" \
            "" 2>&1 || true
    SIZE="$(stat -c '%s' "${SCREENSHOT_DIR}/grid-elementor-canvas-desktop.png" 2>/dev/null || echo 0)"
    if [[ "$SIZE" -lt 50000 ]]; then
        echo "[elementor-canvas] ERROR: desktop screenshot still too small (${SIZE} bytes)" >&2
        exit 1
    fi
fi

echo "[elementor-canvas] grid-elementor-canvas-desktop.png $((SIZE / 1024)) KB"

# Also capture a mobile viewport
echo "[elementor-canvas] Capturing mobile viewport at 380×800..."

OUT_MOBILE="/work/${SCREENSHOT_DIR}/grid-elementor-canvas-mobile.png"

docker run --rm \
    --network "$NETWORK" \
    -v "$PROJECT_DIR:/work" \
    -w /work \
    "$IMAGE" \
    timeout "$CAPTURE_TIMEOUT" node --experimental-websocket scripts/cdp-screenshot-viewport.js \
        "$TARGET" \
        "$OUT_MOBILE" \
        "4000" \
        "380" \
        "800" \
        "mobile" 2>&1 || true

SIZE_MOBILE="$(stat -c '%s' "${SCREENSHOT_DIR}/grid-elementor-canvas-mobile.png" 2>/dev/null || echo 0)"
if [[ "$SIZE_MOBILE" -lt 50000 ]]; then
    echo "[elementor-canvas] WARNING: mobile screenshot suspiciously small (${SIZE_MOBILE} bytes)" >&2
else
    echo "[elementor-canvas] grid-elementor-canvas-mobile.png $((SIZE_MOBILE / 1024)) KB"
fi

echo "[elementor-canvas] Done. Screenshots in ${SCREENSHOT_DIR}/"