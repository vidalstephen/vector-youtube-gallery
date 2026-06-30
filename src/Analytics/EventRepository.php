<?php
/**
 * Phase 11.1 — local analytics events repository.
 *
 * Hard rules (Phase 0 + privacy invariants):
 *   - **Privacy off by default.** `record()` is a no-op unless the
 *     operator has explicitly enabled `vyg_analytics_enabled`.
 *   - **Hash before storage.** IPs and User-Agent strings are SHA-256
 *     hashed; raw values are never persisted.
 *   - **No outbound API.** Recording an event never calls the YouTube
 *     API. The capture path is fire-and-forget INSERTs.
 *   - **Retention pruneable.** Rows older than `vyg_analytics_retention_days`
 *     are deleted by AnalyticsRetentionJob (daily cron). Default 30 days.
 *
 * @package VectorYT\Gallery\Analytics
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Analytics;

defined('ABSPATH') || exit;

final class EventRepository {

    /** Allowed event types — locked-down enum. Unknown values are
     * coerced to 'unknown' so a malformed client cannot poison the
     * `event_type` index. */
    public const EVENT_TYPES = array(
        'impression',       // video card rendered
        'play',             // user clicked a card to open YouTube watch URL
        'lightbox_open',    // user opened the in-page lightbox
        'load_more_click',  // user clicked the load-more button
        'unknown',          // defensive fallback
    );

    /**
     * Privacy gate: returns true if recording is enabled.
     *
     * Default is FALSE — operator must opt in via the Analytics settings page.
     */
    public static function is_enabled(): bool {
        if (defined('VYG_TEST_FORCE_ANALYTICS_OFF') && VYG_TEST_FORCE_ANALYTICS_OFF) {
            return false;
        }
        return (bool) get_option('vyg_analytics_enabled', false);
    }

    /**
     * Privacy gate: returns the configured retention window in days.
     * Default 30. Capped to [1, 3650].
     */
    public static function retention_days(): int {
        $v = (int) get_option('vyg_analytics_retention_days', 30);
        return max(1, min(3650, $v));
    }

    /**
     * Record a single event.
     *
     * @param array<string,mixed> $args {
     *   @type string $event_type        One of EVENT_TYPES.
     *   @type string $youtube_video_id   Optional 11-char video ID.
     *   @type int    $source_id         Optional FK.
     *   @type string $feed_uuid         Optional saved-feed UUID.
     *   @type string $wrapper_id        Optional wrapper ID.
     *   @type string $session_id        Optional client-supplied session cookie.
     *   @type string $event_data        Optional structured payload (JSON-encoded).
     * }
     * @return int  Insert ID, or 0 when disabled / invalid.
     */
    public static function record(array $args): int {
        if (! self::is_enabled()) {
            return 0;
        }
        $type = isset($args['event_type']) && in_array((string) $args['event_type'], self::EVENT_TYPES, true)
            ? (string) $args['event_type']
            : 'unknown';
        $video_id = isset($args['youtube_video_id']) ? (string) $args['youtube_video_id'] : '';
        if ('' !== $video_id && ! self::is_youtube_id($video_id)) {
            $video_id = '';
        }
        $source_id = isset($args['source_id']) ? max(0, (int) $args['source_id']) : 0;
        if ($source_id === 0) {
            $source_id = null;
        }
        $feed_uuid   = isset($args['feed_uuid']) ? (string) $args['feed_uuid'] : '';
        $feed_uuid   = self::normalize_uuid($feed_uuid);
        $wrapper_id  = isset($args['wrapper_id']) ? substr((string) $args['wrapper_id'], 0, 64) : '';
        $session_id  = isset($args['session_id']) ? (string) $args['session_id'] : '';
        $event_data  = isset($args['event_data']) ? (string) $args['event_data'] : null;
        if (null !== $event_data && strlen($event_data) > 65535) {
            $event_data = substr($event_data, 0, 65535);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'vyg_events';
        $ok = $wpdb->insert(
            $table,
            array(
                'event_type'        => $type,
                'youtube_video_id'  => '' !== $video_id ? $video_id : null,
                'source_id'         => $source_id,
                'feed_uuid'         => '' !== $feed_uuid ? $feed_uuid : null,
                'wrapper_id'        => '' !== $wrapper_id ? $wrapper_id : null,
                'session_hash'      => '' !== $session_id ? self::hash($session_id) : null,
                'ip_hash'           => self::hash(self::client_ip()),
                'user_agent_hash'   => self::hash(self::user_agent()),
                'event_data_json'   => $event_data,
                'created_at'        => current_time('mysql', true),
            ),
            array('%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Delete rows older than the configured retention window.
     *
     * @return int Number of rows deleted.
     */
    public static function prune(): int {
        global $wpdb;
        $table    = $wpdb->prefix . 'vyg_events';
        $days     = self::retention_days();
        $cutoff   = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
        $deleted  = (int) $wpdb->query(
            $wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s", $cutoff)
        );
        return $deleted;
    }

    /**
     * Count total events. Used by the dashboard summary.
     */
    public static function total_count(): int {
        global $wpdb;
        $table = $wpdb->prefix . 'vyg_events';
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    /**
     * Reset the analytics table (used by privacy erase).
     *
     * @return int Number of rows deleted.
     */
    public static function wipe_all(): int {
        global $wpdb;
        $table = $wpdb->prefix . 'vyg_events';
        return (int) $wpdb->query("TRUNCATE TABLE {$table}");
    }

    /**
     * @param string $id
     */
    public static function is_youtube_id(string $id): bool {
        return (bool) preg_match('/^[A-Za-z0-9_-]{11}$/', $id);
    }

    /**
     * Validate UUID-like string.
     */
    private static function normalize_uuid(string $uuid): string {
        if ('' === $uuid) {
            return '';
        }
        return (bool) preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $uuid)
            ? strtolower($uuid)
            : '';
    }

    private static function hash(string $value): string {
        return hash('sha256', $value);
    }

    /**
     * Best-effort client IP — proxies, X-Forwarded-For, REMOTE_ADDR, in
     * that order. First match wins. Always hashed before storage.
     */
    private static function client_ip(): string {
        $candidates = array(
            isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? (string) $_SERVER['HTTP_X_FORWARDED_FOR'] : '',
            isset($_SERVER['HTTP_X_REAL_IP']) ? (string) $_SERVER['HTTP_X_REAL_IP'] : '',
            isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '',
        );
        foreach ($candidates as $c) {
            $c = trim($c);
            if ('' !== $c) {
                return $c;
            }
        }
        return '';
    }

    private static function user_agent(): string {
        return isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
    }
}