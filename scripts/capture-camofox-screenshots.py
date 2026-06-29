#!/usr/bin/env python3
"""Capture real browser screenshots of the WordPress plugin through Camofox.

This is the preferred UI/UX screenshot path for Phase Worker runs:
- Uses the live Camofox browser service, not file:// rendered HTML.
- Uses a one-time dev login token instead of typing/storing the admin password.
- Temporarily sets WordPress siteurl/home to http://vyg-wp so redirects stay inside
  the Docker network reachable from Camofox.
- Restores siteurl/home and removes the MU-plugin after capture.

Prereqs:
- WordPress Docker service: vyg-wp on vyg_net.
- Camofox container: hermes-camofox attached to vyg_net.
- Camofox API: http://localhost:9377.
"""

from __future__ import annotations

import hashlib
import json
import os
import secrets
import subprocess
import time
import urllib.parse
import urllib.request
from pathlib import Path

PROJECT = Path(os.environ.get("PROJECT_DIR", "/root/projects/vector-youtube-gallery"))
OUTDIR = PROJECT / "screenshots" / "camofox"
CAMOFOX = os.environ.get("CAMOFOX_URL", "http://localhost:9377")
USER_ID = os.environ.get("CAMOFOX_USER_ID", "hermes_phase_worker_shots")
SESSION_KEY = os.environ.get("CAMOFOX_SESSION_KEY", "phase-worker")
WP_INNER = os.environ.get("WP_INNER_URL", "http://vyg-wp")
WP_HOST = os.environ.get("WP_HOST_URL", "http://localhost:8000")


def run(cmd: list[str], *, input_text: str | None = None) -> str:
    result = subprocess.run(
        cmd,
        cwd=PROJECT,
        input=input_text,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        check=True,
    )
    return result.stdout


def wp(*args: str) -> str:
    return run([
        "docker", "compose", "--env-file", "dev/.env", "--project-directory", str(PROJECT),
        "exec", "-T", "-u", "www-data", "wordpress", "wp", *args,
    ])


def wp_root_shell(script: str, input_text: str | None = None) -> str:
    return run([
        "docker", "compose", "--env-file", "dev/.env", "--project-directory", str(PROJECT),
        "exec", "-T", "-u", "root", "wordpress", "sh", "-lc", script,
    ], input_text=input_text)


def http_json(method: str, path: str, payload: dict | None = None) -> tuple[int, dict]:
    data = None if payload is None else json.dumps(payload).encode()
    req = urllib.request.Request(
        CAMOFOX + path,
        data=data,
        method=method,
        headers={"Content-Type": "application/json"} if payload is not None else {},
    )
    with urllib.request.urlopen(req, timeout=45) as resp:
        body = resp.read().decode("utf-8", errors="replace")
        return resp.status, json.loads(body) if body else {}


def screenshot(tab_id: str, name: str) -> Path:
    params = urllib.parse.urlencode({"userId": USER_ID, "sessionKey": SESSION_KEY})
    url = f"{CAMOFOX}/tabs/{tab_id}/screenshot?{params}"
    with urllib.request.urlopen(url, timeout=45) as resp:
        data = resp.read()
    path = OUTDIR / f"{name}.png"
    path.write_bytes(data)
    print(f"{name}: {len(data) // 1024} KB -> {path}")
    return path


def install_one_time_login() -> str:
    token = secrets.token_urlsafe(32)
    digest = hashlib.sha256(token.encode()).hexdigest()
    wp("option", "update", "vyg_dev_login_hash", digest, "--autoload=no")
    mu = r'''<?php
/** One-time dev autologin for Camofox screenshot capture. */
add_action('init', static function (): void {
    if (empty($_GET['vyg_dev_login'])) {
        return;
    }
    $token = (string) wp_unslash($_GET['vyg_dev_login']);
    $hash = hash('sha256', $token);
    $expected = (string) get_option('vyg_dev_login_hash', '');
    if ($expected === '' || ! hash_equals($expected, $hash)) {
        status_header(403);
        exit('Forbidden');
    }
    delete_option('vyg_dev_login_hash');
    wp_set_current_user(1);
    wp_set_auth_cookie(1, false, is_ssl());
    wp_safe_redirect(admin_url('index.php'));
    exit;
});
'''
    wp_root_shell("mkdir -p /var/www/html/wp-content/mu-plugins && cat > /var/www/html/wp-content/mu-plugins/vyg-dev-autologin.php && chown www-data:www-data /var/www/html/wp-content/mu-plugins/vyg-dev-autologin.php", input_text=mu)  # type: ignore[arg-type]
    return token


def cleanup() -> None:
    try:
        wp_root_shell("rm -f /var/www/html/wp-content/mu-plugins/vyg-dev-autologin.php")
    except Exception as exc:  # noqa: BLE001
        print(f"cleanup warning: {exc}")
    try:
        wp("option", "delete", "vyg_dev_login_hash")
    except Exception:
        pass
    wp("option", "update", "siteurl", WP_HOST)
    wp("option", "update", "home", WP_HOST)


def main() -> None:
    OUTDIR.mkdir(parents=True, exist_ok=True)

    # Camofox must be attached to vyg_net. Verify before changing WP state.
    run(["docker", "exec", "hermes-camofox", "sh", "-lc", f"curl -sS --connect-timeout 5 -o /dev/null -w '%{{http_code}}' {WP_INNER}/wp-login.php"])

    old_siteurl = wp("option", "get", "siteurl").strip()
    old_home = wp("option", "get", "home").strip()
    print(f"old siteurl={old_siteurl} home={old_home}")

    try:
        # Hide the WP welcome panel so dashboard widget is visible.
        wp("eval", "update_user_meta(1, 'show_welcome_panel', 0);")

        # Make redirects resolve from inside Camofox.
        wp("option", "update", "siteurl", WP_INNER)
        wp("option", "update", "home", WP_INNER)

        token = install_one_time_login()
        login_url = f"{WP_INNER}/?vyg_dev_login={urllib.parse.quote(token)}"
        status, tab = http_json("POST", "/tabs", {"userId": USER_ID, "sessionKey": SESSION_KEY, "url": login_url})
        if status != 200:
            raise RuntimeError(f"Camofox tab create failed: {tab}")
        tab_id = tab["tabId"]
        print(f"tab={tab_id}")

        # DPR in Camofox is often ~2, so use a wide viewport to get desktop-ish captures.
        http_json("POST", f"/tabs/{tab_id}/viewport", {"userId": USER_ID, "sessionKey": SESSION_KEY, "width": 2800, "height": 2000})

        pages = [
            ("01-dashboard", f"{WP_INNER}/wp-admin/index.php"),
            ("02-sources", f"{WP_INNER}/wp-admin/admin.php?page=vector-youtube-gallery"),
            ("03-feeds-list", f"{WP_INNER}/wp-admin/admin.php?page=vector-youtube-gallery-feeds"),
            ("04-feeds-edit", f"{WP_INNER}/wp-admin/admin.php?page=vector-youtube-gallery-feeds&action=edit&id=1"),
            ("05-privacy", f"{WP_INNER}/wp-admin/admin.php?page=vector-youtube-gallery-privacy"),
            ("06-diagnostics", f"{WP_INNER}/wp-admin/admin.php?page=vector-youtube-gallery-diagnostics"),
            ("07-videos", f"{WP_INNER}/wp-admin/admin.php?page=vector-youtube-gallery-videos"),
            ("08-system-info", f"{WP_INNER}/wp-admin/admin.php?page=vector-youtube-gallery-system-info"),
            ("09-frontend-feed", f"{WP_INNER}/?page_id=11"),
            ("10-settings-oauth", f"{WP_INNER}/wp-admin/admin.php?page=vector-youtube-gallery-settings&tab=oauth"),
            ("11-multi-source-feed", f"{WP_INNER}/?page_id=17"),
            ("12-feeds-list-multi-source", f"{WP_INNER}/wp-admin/admin.php?page=vector-youtube-gallery-feeds"),
            ("13-feeds-edit-multi-source", f"{WP_INNER}/wp-admin/admin.php?page=vector-youtube-gallery-feeds&action=edit&id=2"),
            ("14-feeds-import-export", f"{WP_INNER}/wp-admin/admin.php?page=vector-youtube-gallery-feeds#vyg-feed-impex"),
        ]
        for name, url in pages:
            http_json("POST", f"/tabs/{tab_id}/navigate", {"userId": USER_ID, "sessionKey": SESSION_KEY, "url": url})
            time.sleep(1.5)
            screenshot(tab_id, name)
    finally:
        cleanup()


if __name__ == "__main__":
    main()
