#!/usr/bin/env python3
"""
Phase 9 screenshot capture.

Drives Camofox via its REST API (default http://localhost:9377) and saves
PNG screenshots under screenshots/camofox/. Also verifies the Phase 0
"zero API calls on front-end render" invariant: wp_vyg_api_quota_log
must NOT gain rows during the capture sweep.

Pattern mirrors scripts/capture-camofox-screenshots.py (Phase 6/8 baseline)
so it inherits the proven userId/sessionKey-on-tab-create flow.
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
USER_ID = os.environ.get("CAMOFOX_USER_ID", "hermes_phase9")
SESSION_KEY = os.environ.get("CAMOFOX_SESSION_KEY", "phase-9")
WP_INNER = os.environ.get("WP_INNER_URL", "http://vyg-wp")
WP_HOST = os.environ.get("WP_HOST_URL", "http://localhost:8000")


def run(cmd: list[str], *, input_text=None) -> str:
    r = subprocess.run(
        cmd, cwd=PROJECT, input=input_text, text=True,
        stdout=subprocess.PIPE, stderr=subprocess.STDOUT, check=True,
    )
    return r.stdout


def wp(*args: str) -> str:
    return run([
        "docker", "compose", "--env-file", "dev/.env", "--project-directory", str(PROJECT),
        "exec", "-T", "-u", "www-data", "wordpress", "wp", *args,
    ])


def http_json(method: str, path: str, payload=None):
    data = None if payload is None else json.dumps(payload).encode()
    req = urllib.request.Request(
        CAMOFOX + path, data=data, method=method,
        headers={"Content-Type": "application/json"} if payload is not None else {},
    )
    with urllib.request.urlopen(req, timeout=45) as resp:
        body = resp.read().decode("utf-8", errors="replace")
        return resp.status, (json.loads(body) if body else {})


def screenshot(tab_id: str, name: str) -> Path:
    params = urllib.parse.urlencode({"userId": USER_ID, "sessionKey": SESSION_KEY})
    url = f"{CAMOFOX}/tabs/{tab_id}/screenshot?{params}"
    with urllib.request.urlopen(url, timeout=45) as resp:
        data = resp.read()
    path = OUTDIR / f"{name}.png"
    path.write_bytes(data)
    print(f"  saved {path.name} ({len(data) // 1024} KB)")
    return path


def one_time_login_token() -> tuple[str, str]:
    """Mint a one-time dev login token and return (raw_token, login_url)."""
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
    wp("eval", "update_option('siteurl', 'http://vyg-wp'); update_option('home', 'http://vyg-wp');", "--skip-plugins", "--skip-themes")
    return token, f"{WP_INNER}/wp-login.php?vyg_dev_login={token}"


def restore_wp_urls() -> None:
    wp("eval", "update_option('siteurl', 'http://localhost:8000'); update_option('home', 'http://localhost:8000');", "--skip-plugins", "--skip-themes")


def count_quota_rows() -> int:
    out = wp("eval", "global $wpdb; echo (int) $wpdb->get_var(\"SELECT COUNT(*) FROM {$wpdb->prefix}vyg_api_quota_log\");", "--skip-plugins", "--skip-themes")
    out = out.strip().strip('"').strip()
    try:
        return int(out)
    except ValueError:
        return -1


def quota_rows_for_source() -> int:
    out = wp("eval", "global $wpdb; echo (int) $wpdb->get_var(\"SELECT COUNT(*) FROM {$wpdb->prefix}vyg_api_quota_log WHERE created_at > (NOW() - INTERVAL 5 MINUTE)\");", "--skip-plugins", "--skip-themes")
    out = out.strip().strip('"').strip()
    try:
        return int(out)
    except ValueError:
        return -1


PAGES = [
    ("17-phase-9-masonry",          "/phase-9-masonry/",          "Masonry layout"),
    ("18-phase-9-carousel",         "/phase-9-carousel/",         "Carousel layout"),
    ("19-phase-9-hero",             "/phase-9-hero/",             "Hero layout"),
    ("20-phase-9-preset-cinema",    "/phase-9-preset-cinema/",    "Cinema preset"),
    ("21-phase-9-preset-pastel",    "/phase-9-preset-pastel/",    "Pastel preset"),
    ("22-phase-9-schema-jsonld",    "/phase-9-schema/",           "Schema.org JSON-LD"),
]

# Map the file_stem back to the actual WP page slug for the warning above.
FILE_TO_PAGE_SLUG = {
    "17-phase-9-masonry":       "phase-9-masonry",
    "18-phase-9-carousel":      "phase-9-carousel",
    "19-phase-9-hero":          "phase-9-hero",
    "20-phase-9-preset-cinema": "phase-9-preset-cinema",
    "21-phase-9-preset-pastel": "phase-9-preset-pastel",
    "22-phase-9-schema-jsonld": "phase-9-schema",
}


def main() -> int:
    OUTDIR.mkdir(parents=True, exist_ok=True)

    print("One-time dev login + temporary siteurl/home → http://vyg-wp …")
    token, login_url = one_time_login_token()

    # Verify the 6 demo pages exist (Phase 9 deliverable dev/phase9-create-pages.php).
    pages = wp("post", "list", "--post_type=page", "--fields=post_name,ID", "--format=csv").strip().splitlines()
    slugs = {}
    for line in pages[1:]:  # skip header row
        if "," not in line:
            continue
        parts = line.split(",")
        try:
            slugs[parts[0]] = int(parts[1])
        except (ValueError, IndexError):
            continue
    expected = {"phase-9-masonry", "phase-9-carousel", "phase-9-hero",
                "phase-9-preset-cinema", "phase-9-preset-pastel", "phase-9-schema"}
    missing = expected - set(slugs)
    if missing:
        print(f"WARN: missing demo pages: {sorted(missing)} — run dev/phase9-create-pages.php")

    before = count_quota_rows()
    print(f"Quota log rows BEFORE: {before}")

    status, tab = http_json("POST", "/tabs", {
        "userId": USER_ID, "sessionKey": SESSION_KEY, "url": login_url,
    })
    assert status == 200, f"tab create returned {status}: {tab}"
    tab_id = tab["tabId"]
    print(f"Tab created: {tab_id}")

    # Set a generous viewport so screenshots look like full pages.
    http_json("POST", f"/tabs/{tab_id}/viewport", {
        "userId": USER_ID, "sessionKey": SESSION_KEY,
        "width": 1280, "height": 1800,
    })

    # Wait for login redirect to land us on wp-admin.
    time.sleep(2.0)

    for file_stem, path, label in PAGES:
        url = WP_INNER + path
        print(f"\n→ {label} ({path})")
        nav_status, _ = http_json("POST", f"/tabs/{tab_id}/navigate", {
            "userId": USER_ID, "sessionKey": SESSION_KEY, "url": url,
        })
        if nav_status != 200:
            print(f"  navigate failed (status={nav_status})", file=sys.stderr)
            continue
        time.sleep(2.5)
        screenshot(tab_id, file_stem)

    after = count_quota_rows()
    delta = after - before
    print(f"\nQuota log rows AFTER:  {after}  (Δ={delta})")
    if delta != 0:
        print("WARNING: Phase 9 render made API calls. Phase 0 invariant violated.", file=sys.stderr)
        rc = 1
    else:
        print("Phase 0 invariant OK: zero API calls on render.")
        rc = 0

    print("Restoring siteurl/home → http://localhost:8000")
    restore_wp_urls()
    return rc


if __name__ == "__main__":
    sys.exit(main())
