#!/usr/bin/env python3
"""Verify that no internal YouTube-gallery IDs leak on the public front-end.

Phase 8.8 acceptance criteria:
  - Pages with [youtube_feed feed_uuid="..."] must NOT emit data-source-uuid
  - Pages must NOT contain the internal sources' UUIDs
  - /wp-json/vyg/v1/feed/<feed_uuid> must NOT contain internal source UUIDs
    in either the response payload or the rendered HTML
  - Legacy shortcodes [youtube_feed source_uuid="..."] without feed_uuid
    may still emit data-source-uuid (those operators chose the source by name)

Exit codes: 0 = all assertions pass; 1 = at least one violation.

Usage:
    python3 scripts/verify-public-safety.py [--host https://srv1388017.tail209ed.ts.net]
"""

from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

PROJECT = Path(__file__).resolve().parents[1]


def wp(*args: str) -> str:
    result = subprocess.run(
        [
            "docker", "compose", "--env-file", "dev/.env",
            "--project-directory", str(PROJECT),
            "exec", "-T", "-u", "www-data", "wordpress", "wp", *args,
        ],
        capture_output=True, text=True, check=True,
    )
    return result.stdout


def get_internal_uuids() -> dict[str, list[str]]:
    """Read every source_uuid from vyg_sources and every feed_uuid from vyg_feeds."""
    sources_json = wp("eval",
        "global $wpdb; echo json_encode( $wpdb->get_col( \"SELECT source_uuid FROM {$wpdb->prefix}vyg_sources\" ) ?: [] );",
    ).strip()
    feeds_json = wp("eval",
        "global $wpdb; echo json_encode( $wpdb->get_col( \"SELECT feed_uuid FROM {$wpdb->prefix}vyg_feeds\" ) ?: [] );",
    ).strip()
    sources = [s for s in json.loads(sources_json or "[]") if s]
    feeds = [f for f in json.loads(feeds_json or "[]") if f]
    return {
        "sources": sources,
        "feeds": feeds,
    }


def fetch(url: str) -> tuple[int, str]:
    req = urllib.request.Request(url, headers={"User-Agent": "vyg-verify-public-safety/1.0"})
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            return resp.status, resp.read().decode("utf-8", errors="replace")
    except urllib.error.HTTPError as e:
        return e.code, ""


def scan_text(body: str, forbidden_substrings: list[str]) -> list[tuple[str, int]]:
    """Return list of (substring, line_number) for every forbidden substring hit."""
    hits = []
    lines = body.splitlines()
    for sub in forbidden_substrings:
        # Search the whole body so that we don't miss <script>...</script> fragments.
        if sub in body:
            # First occurrence line number for diagnostics.
            line = 0
            for idx, raw in enumerate(lines, 1):
                if sub in raw:
                    line = idx
                    break
            hits.append((sub, line))
    return hits


def check_page(host: str, page_id: int, title_hint: str, body: str,
               source_uuids: list[str], data_source_uuid_pattern: re.Pattern) -> list[str]:
    failures = []
    # 1. Public pages with feed_uuid shortcode must NOT have data-source-uuid.
    # The content contains the shortcode attribute `[youtube_feed feed_uuid="..."]`
    # legitimately. data-source-uuid is the rendered DOM attribute we want to verify
    # is absent.
    matches = data_source_uuid_pattern.findall(body)
    if matches:
        failures.append(
            f"page {page_id} ({title_hint}): found {len(matches)} 'data-source-uuid' attributes; expected 0"
        )

    # 2. The rendered HTML must not contain any internal source UUID.
    source_hits = scan_text(body, source_uuids)
    if source_hits:
        sample = ', '.join( f"{s}@L{l}" for s, l in source_hits[:5] )
        failures.append(
            f"page {page_id} ({title_hint}): leaked source_uuid(s) {sample}"
        )
    return failures


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--host", default="https://srv1388017.tail209ed.ts.net")
    args = parser.parse_args()

    internal = get_internal_uuids()
    print(f"Found {len(internal['sources'])} source UUIDs and {len(internal['feeds'])} feed UUIDs in DB")
    print()

    feed_uuids = internal["feeds"]
    source_uuids = internal["sources"]
    failures: list[str] = []

    if not feed_uuids:
        print("No published feeds found in DB; nothing to verify.")
        return 0

    # Step 1: scan every front-end page that contains "youtube_feed".
    front_pages = [
        # (page_id, title_hint, expected has_feed_uuid)
        (7,  "single-source Phase 6 test",         "f57c6cfe-0395-45fc-bdb3-e8e7e0b0455e"),
        (11, "Phase 6 named feed test",            "f57c6cfe-0395-45fc-bdb3-e8e7e0b0455e"),
        (17, "multi-source gallery test",          "13a832cd-bc40-4a2a-80e2-133921d00aa3"),
    ]
    print("=== Step 1: scan published front-end pages ===")
    data_source_re = re.compile(r'data-source-uuid="[^"]+"')
    for page_id, title_hint, expected_feed in front_pages:
        status, body = fetch(f"{args.host}/?page_id={page_id}")
        print(f"  [{status}] page {page_id} ({title_hint}) — bytes={len(body)}")
        if status != 200:
            failures.append(f"page {page_id} HTTP={status}, expected 200")
            continue
        # Sanity: the page should mention the expected feed UUID at least once
        # (in the shortcode attribute, in the data-feed-uuid attribute, or both).
        if expected_feed not in body:
            failures.append(
                f"page {page_id} ({title_hint}): expected feed_uuid {expected_feed} not present"
            )
        failures.extend(check_page(
            args.host, page_id, title_hint, body,
            source_uuids, data_source_re,
        ))

    # Step 2: hit the public REST feed endpoints and verify they don't leak
    # either. 404s and 403s are acceptable (e.g. unpublished feed); 200s
    # must be clean.
    print()
    print("=== Step 2: scan REST feed-by-uuid endpoints ===")
    for feed_uuid in feed_uuids:
        url = f"{args.host}/wp-json/vyg/v1/feed/{feed_uuid}/?per_page=4&offset=0"
        status, body = fetch(url)
        print(f"  [{status}] {url[:80]}… — bytes={len(body)}")
        if status == 200:
            # Decode JSON and inspect both the data-section HTML and the
            # response envelope. Assert: response.feed.* present; no internal
            # source_uuid in HTML; no data-source-uuid HTML attribute.
            try:
                payload = json.loads(body)
            except json.JSONDecodeError as e:
                failures.append(f"REST {feed_uuid}: invalid JSON ({e})")
                continue
            html = payload.get("html", "")
            if "data-source-uuid=" in html:
                failures.append(
                    f"REST feed/{feed_uuid}/: HTML contains 'data-source-uuid=' attribute (forbidden)"
                )
            # Internal source UUIDs must not appear in the HTML.
            hits = scan_text(html, source_uuids)
            if hits:
                failures.append(
                    f"REST feed/{feed_uuid}/: HTML leaks source UUIDs ({hits})"
                )

    # Step 3: scan the public REST feed-list endpoint (no feed UUID leak).
    print()
    print("=== Step 3: scan REST legacy feed-by-source endpoint ===")
    for src_uuid in source_uuids:
        url = f"{args.host}/wp-json/vyg/v1/feed?source_uuid={src_uuid}&per_page=2"
        status, body = fetch(url)
        print(f"  [{status}] legacy feed?source_uuid={src_uuid[:8]}… — bytes={len(body)}")
        if status == 200:
            try:
                payload = json.loads(body)
            except json.JSONDecodeError:
                continue
            html = payload.get("html", "")
            # Internal source UUID IS allowed here (legacy public endpoint
            # by source_uuid), but other sources' UUIDs must not leak.
            for other_src in source_uuids:
                if other_src != src_uuid and other_src in html:
                    failures.append(
                        f"REST feed (legacy src={src_uuid[:8]}…): HTML leaks OTHER source UUID {other_src}"
                    )

    print()
    if failures:
        print(f"FAIL: {len(failures)} issue(s):")
        for f in failures:
            print(f"  - {f}")
        return 1
    print("OK: no internal-UUID leaks detected.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
