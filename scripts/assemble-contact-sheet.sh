#!/usr/bin/env bash
# Phase 14.12 — assemble the per-layout PNGs from run-14-12-playwright.sh
# into a 4x2 contact sheet (desktop) and a 4x2 contact sheet (mobile)
# using the same PIL approach as scripts/capture-way-of-holiness-layouts.sh.
# Also emits diffs/ side-by-side comparisons against the prototype's
# screenshots/way-of-holiness/layouts/{layout}-*.png.
#
# Usage: scripts/assemble-contact-sheet.sh
# Expects: screenshots/prototype-parity/{layout}-desktop.png +
#          screenshots/prototype-parity/{layout}-mobile.png (16 files).

set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_DIR"

PARITY_DIR="screenshots/prototype-parity"
WOH_DIR="screenshots/way-of-holiness/layouts"
DIFF_DIR="${PARITY_DIR}/diffs"
mkdir -p "$DIFF_DIR"

LAYOUTS=(grid list featured hero shorts masonry carousel live)

# Verify all 16 inputs exist before we start.
missing=0
for layout in "${LAYOUTS[@]}"; do
    for suffix in desktop mobile; do
        f="${PARITY_DIR}/${layout}-${suffix}.png"
        if [[ ! -s "$f" ]]; then
            echo "[14.12] missing $f"
            missing=$(( missing + 1 ))
        fi
    done
done
if [[ "$missing" -gt 0 ]]; then
    echo "[14.12] FATAL: ${missing} input PNGs missing — run scripts/run-14-12-playwright.sh first"
    exit 1
fi

# 1. 4x2 contact sheet (desktop + mobile). Uses PIL just like
#    capture-way-of-holiness-layouts.sh so the two sheets are
#    visually comparable.
python3 - <<'PY'
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont

parity = Path('screenshots/prototype-parity')
layouts = ['grid', 'list', 'featured', 'hero', 'shorts', 'masonry', 'carousel', 'live']

def gallery_crop(im: Image.Image, suffix: str) -> Image.Image:
    """Crop past the WordPress theme/nav header so cards are visible."""
    if suffix == 'mobile':
        top = min(490, max(0, im.height - 900))
        crop_w = im.width
        crop_h = min(1500, im.height - top)
    else:
        top = min(1280, max(0, im.height - 520))
        crop_w = min(im.width, 1280)
        crop_h = min(720, im.height - top)
    return im.crop((0, top, crop_w, top + crop_h))

def build_sheet(suffix: str) -> Path:
    thumbs = []
    for layout in layouts:
        p = parity / f'{layout}-{suffix}.png'
        im = Image.open(p).convert('RGB')
        crop = gallery_crop(im, suffix)
        crop.thumbnail((360, 270))
        canvas = Image.new('RGB', (380, 300), 'white')
        canvas.paste(crop, ((380 - crop.width) // 2, 30))
        try:
            font = ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', 16)
        except Exception:
            font = ImageFont.load_default()
        ImageDraw.Draw(canvas).text((10, 6), layout, fill=(0, 0, 0), font=font)
        thumbs.append(canvas)
    # 4 columns x 2 rows = 8 layouts.
    sheet = Image.new('RGB', (4 * 380, 2 * 300), (245, 245, 245))
    for i, thumb in enumerate(thumbs):
        sheet.paste(thumb, ((i % 4) * 380, (i // 4) * 300))
    out = parity / f'contact-sheet-{suffix}.png'
    sheet.save(out)
    print(f'{out}: {sheet.width}x{sheet.height}, {out.stat().st_size:,} bytes')
    return out

for suffix in ('desktop', 'mobile'):
    build_sheet(suffix)
PY

# 2. Side-by-side diffs against the prototype's WOH captures, when
#    they exist. Pure visual — both PNGs in the same row, prototype
#    on the left, plugin on the right.
python3 - <<'PY'
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont

parity = Path('screenshots/prototype-parity')
woh    = Path('screenshots/way-of-holiness/layouts')
diffs  = parity / 'diffs'
diffs.mkdir(parents=True, exist_ok=True)

try:
    font = ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', 14)
except Exception:
    font = ImageFont.load_default()

def gallery_crop(im: Image.Image, suffix: str) -> Image.Image:
    if suffix == 'mobile':
        top = min(490, max(0, im.height - 900))
        crop_w = im.width
        crop_h = min(1500, im.height - top)
    else:
        top = min(1280, max(0, im.height - 520))
        crop_w = min(im.width, 1280)
        crop_h = min(720, im.height - top)
    return im.crop((0, top, crop_w, top + crop_h))

for suffix in ('desktop', 'mobile'):
    for layout in ('grid', 'list', 'featured', 'hero', 'shorts', 'masonry', 'carousel', 'live'):
        a = woh / f'{layout}-{suffix}.png'
        b = parity / f'{layout}-{suffix}.png'
        if not a.exists() or not b.exists():
            continue
        ia = Image.open(a).convert('RGB')
        ib = gallery_crop(Image.open(b).convert('RGB'), suffix)
        # Normalize both sides for side-by-side review. Prototype capture
        # is already layout-focused; plugin capture needs gallery crop.
        ia = ia.crop((0, 0, min(ia.width, 1200), min(ia.height, 900))).resize((600, 450))
        ib = ib.resize((600, 450))
        sheet = Image.new('RGB', (1220, 480), 'white')
        ImageDraw.Draw(sheet).text((10, 6), f'prototype / {layout}', fill=(80, 80, 80), font=font)
        ImageDraw.Draw(sheet).text((620, 6), f'plugin (14.12) / {layout}', fill=(80, 80, 80), font=font)
        sheet.paste(ia, (10, 30))
        sheet.paste(ib, (620, 30))
        out = diffs / f'{layout}-{suffix}.png'
        sheet.save(out)
PY

echo "[14.12] contact sheets + diffs ready"
ls -la "$PARITY_DIR"/contact-sheet-*.png
ls -la "$DIFF_DIR" | head -20
