<?php
/**
 * Remote dev admin URL support for Docker WordPress.
 *
 * Loaded from WORDPRESS_CONFIG_EXTRA in docker-compose.yml. This file keeps
 * PHP variables out of Compose YAML so Compose interpolation cannot corrupt
 * the generated wp-config.php.
 *
 * The dev WordPress container is intentionally exposed only on
 * 127.0.0.1:8000. Public/remote access is provided by either:
 *   https://srv1388017.tail209ed.ts.net
 *   https://wpt.nsystems.live
 *
 * When a trusted remote/local Host header is present, derive WP_HOME and
 * WP_SITEURL from that request origin. This keeps wp-admin form actions,
 * redirects, cookies, and asset URLs on the same hostname the browser used.
 */

// Public dev/staging access should not execute WP-Cron. This prevents
// anonymous page views through wpt.nsystems.live from draining YouTube
// quota by running queued sync jobs. Manual sync remains available via
// WP-CLI/admin actions.
if (!defined('DISABLE_WP_CRON')) {
    define('DISABLE_WP_CRON', true);
}

$host = '';
if (isset($_SERVER['HTTP_X_FORWARDED_HOST']) && '' !== $_SERVER['HTTP_X_FORWARDED_HOST']) {
    $host = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_HOST'])[0]);
} elseif (isset($_SERVER['HTTP_HOST'])) {
    $host = (string) $_SERVER['HTTP_HOST'];
}

$allowed_hosts = array(
    'localhost:8000',
    '127.0.0.1:8000',
    'vyg-wp',
    'vyg-wp:80',
    'srv1388017.tail209ed.ts.net',
    'wpt.nsystems.live',
);

if (in_array($host, $allowed_hosts, true)) {
    $proto = 'http';
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && '' !== $_SERVER['HTTP_X_FORWARDED_PROTO']) {
        $proto = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]);
    } elseif (isset($_SERVER['HTTPS']) && 'off' !== $_SERVER['HTTPS']) {
        $proto = 'https';
    }

    if ('https' === $proto) {
        $_SERVER['HTTPS'] = 'on';
    }

    $origin = $proto . '://' . $host;
    if (!defined('WP_HOME')) {
        define('WP_HOME', $origin);
    }
    if (!defined('WP_SITEURL')) {
        define('WP_SITEURL', $origin);
    }
}
