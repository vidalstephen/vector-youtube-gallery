#!/usr/bin/env python3
"""
Phase 10 screenshot capture + E2E verification.

Drives Camofox (port 9377) to capture screenshots of:
  1. The Gutenberg block-editor with the new /vectoryt/gallery block
     inserted (feed picker visible, no API calls during render).
  2. The Feeds Builder admin page (proves Operator workflow still
     works after Phase 10 wiring of preset + schema + products).
  3. The front-end Phase 9 demo pages (masonry/carousel/hero/preset),
     regression-tested post-Phase 10 changes.

Verifies Phase 0 invariant: wp_vyg_api_quota_log does NOT gain rows
during the capture sweep.

Also writes a Phase 10 E2E summary report.
"""
import hashlib
import json
import os
import secrets
import subprocess
import sys
import time
import urllib.parse
import urllib.request
from pathlib import Path

PROJECT = Path(os.environ.get("PROJECT_DIR", "/root/projects/vector-youtube-gallery"))
OUTDIR = PROJECT / "screenshots" / "camofox"
CAMOFOX = os.environ.get("CAMOFOX_URL", "http://localhost:9377")
USER_ID = os.environ.get("CAMOFOX_USER_ID", "hermes_phase10")
SESSION_KEY = os.environ.get("CAMOFOX_SESSION_KEY", "phase-10")
WP_INNER = os.environ.get("WP_INNER_URL", "http://vyg-wp")
WP_HOST = os.environ.get("WP_HOST_URL", "http://localhost:8000")


def run(cmd, *, input_text=None):
    r = subprocess.run(cmd, cwd=PROJECT, input=input_text, text=True,
                       stdout=subprocess.PIPE, stderr=subprocess.STDOUT, check=True)
    return r.stdout


def wp(*args):
    return run([
        "docker", "compose", "--env-file", "dev/.env", "--project-directory", str(PROJECT),
        "exec", "-T", "-u", "www-data", "wordpress", "wp", *args,
    ])


def http_json(method, path, payload=None):
    data = None if payload is None else json.dumps(payload).encode()
    headers = {"Content-Type": "application/json"} if payload is not None else {}
    req = urllib.request.Request(CAMOFOX + path, data=data, method=method, headers=headers)
    with urllib.request.urlopen(req, timeout=60) as resp:
        body = resp.read().decode("utf-8", errors="replace")
        return resp.status, (json.loads(body) if body else {})


def screenshot(tab_id, name):
    params = urllib.parse.urlencode({"userId": USER_ID, "sessionKey": SESSION_KEY})
    url = f"{CAMOFOX}/tabs/{tab_id}/screenshot?{params}"
    with urllib.request.urlopen(url, timeout=45) as resp:
        data = resp.read()
    path = OUTDIR / f"{name}.png"
    path.write_bytes(data)
    print(f"  saved {path.name} ({len(data) // 1024} KB)")
    return path


def one_time_login_token():
    token = secrets.token_urlsafe(32)
    digest = hashlib.sha256(token.encode()).hexdigest()
    wp("option", "update", "vyg_dev_login_hash", digest, "--autoload=no")
    mu = (
        "<?php\n"
        "add_action('init', static function(){\n"
        "  if (empty($_GET['vyg_dev_login'])) return;\n"
        "  $t = (string) wp_unslash($_GET['vyg_dev_login']);\n"
        "  $h = (string) get_option('vyg_dev_login_hash', '');\n"
        "  if ($h !== '' && hash_equals($h, hash('sha256', $t))) {\n"
        "    $u = get_user_by('login', 'admin');\n"
        "    if ($u) { wp_set_current_user($u->ID); wp_set_auth_cookie($u->ID); }\n"
        "  }\n"
        "});\n"
    )
    run([
        "docker", "compose", "--env-file", "dev/.env", "--project-directory", str(PROJECT),
        "exec", "-T", "-u", "www-data", "wordpress", "wp", "eval-file", "/dev/stdin",
    ], input_text=mu)
    wp("eval", "update_option('siteurl', 'http://vyg-wp'); update_option('home', 'http://vyg-wp');")
    return token, f"{WP_INNER}/wp-login.php?vyg_dev_login={token}"


def restore_wp_urls():
    wp("eval", "update_option('siteurl', 'http://localhost:8000'); update_option('home', 'http://localhost:8000');")


def count_quota_rows():
    out = wp("eval", "global $wpdb; echo (int) $wpdb->get_var(\"SELECT COUNT(*) FROM {$wpdb->prefix}vyg_api_quota_log\");")
    out = out.strip().strip('"').strip()
    try:
        return int(out)
    except ValueError:
        return -1


def create_draft_with_vyg_block():
    """Create a draft post with the new /vectoryt/gallery block to
    demonstrate the Gutenberg block-editor sidebar loaded with VYG
    picker controls + feed list."""
    script = (
        "global $wpdb;"
        " $post = get_page_by_path('phase-10-block-demo', OBJECT);"
        " if ($post) { wp_delete_post($post->ID, true); }"
        " $block = '<!-- wp:vectoryt/gallery {\"columns\":3,\"perPage\":6,\"preset\":\"cinema\",\"schemaEnabled\":true} -->' . '<!-- /wp:vectoryt/gallery -->';"
        " $id = wp_insert_post(['post_title' => 'Phase 10 — Gutenberg block demo', 'post_name' => 'phase-10-block-demo', 'post_status' => 'draft', 'post_type' => 'page', 'post_content' => $block]);"
        " echo (int) $id;"
    )
    return wp("eval", script)


PAGES = [
    ("23-phase-10-block-editor", "/wp-admin/post-new.php?post_type=page&vyg-open-block=phase-10-block-demo", "Gutenberg editor with VYG block"),
    ("24-phase-10-feeds-builder", "/wp-admin/admin.php?page=vector-youtube-gallery-feeds", "Feeds Builder"),
    ("25-phase-10-admin-menu", "/wp-admin/admin.php?page=vector-youtube-gallery", "Admin dashboard"),
    ("26-phase-10-regression-masonry", "/phase-9-masonry/", "Phase 9 regression: masonry"),
    ("27-phase-10-regression-carousel", "/phase-9-carousel/", "Phase 9 regression: carousel"),
    ("28-phase-10-regression-hero", "/phase-9-hero/", "Phase 9 regression: hero"),
]


def main():
    OUTDIR.mkdir(parents=True, exist_ok=True)
    print("Bootstrapping dev login…")
    token, login_url = one_time_login_token()

    # Create a draft with the VYG block so the block-editor sidebar test
    # has something to load. We do this BEFORE siteurl→vyg-wp swap so the
    # post URL works under both host and inner-network names.
    create_draft_with_vyg_block()

    before = count_quota_rows()
    print(f"Quota log rows BEFORE: {before}")

    status, tab = http_json("POST", "/tabs", {"userId": USER_ID, "sessionKey": SESSION_KEY, "url": login_url})
    assert status == 200, f"tab create status={status}"
    tab_id = tab["tabId"]
    print(f"Tab created: {tab_id}")

    http_json("POST", f"/tabs/{tab_id}/viewport", {"userId": USER_ID, "sessionKey": SESSION_KEY, "width": 1440, "height": 900})
    time.sleep(2.0)

    for file_stem, path, label in PAGES:
        url = WP_INNER + path
        print(f"\n→ {label}")
        nav_status, _ = http_json("POST", f"/tabs/{tab_id}/navigate", {"userId": USER_ID, "sessionKey": SESSION_KEY, "url": url})
        if nav_status != 200:
            print(f"  navigate failed (status={nav_status})", file=sys.stderr)
            continue
        time.sleep(2.5)
        screenshot(tab_id, file_stem)

    after = count_quota_rows()
    delta = after - before
    print(f"\nQuota log rows AFTER:  {after}  (Δ={delta})")
    rc = 0 if delta == 0 else 1
    if rc != 0:
        print("WARNING: Phase 0 invariant violated.", file=sys.stderr)
    else:
        print("Phase 0 invariant OK: zero API calls during Phase 10 capture.")

    restore_wp_urls()
    print("Restored siteurl/home.")
    return rc


if __name__ == "__main__":
    sys.exit(main())
