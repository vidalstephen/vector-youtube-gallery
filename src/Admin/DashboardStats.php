<?php
/**
 * DashboardStats — read-only aggregate queries for the admin dashboard widget.
 *
 * All queries read-only; never write. Heavy queries use direct $wpdb for clarity;
 * light queries go through repositories when available.
 *
 * @package VectorYT\Gallery\Admin
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Admin;

defined( 'ABSPATH' ) || exit;

final class DashboardStats {

    private const DAILY_QUOTA_LIMIT = 10000;

    /**
     * Aggregate stats used by the widget.
     *
     * @return array<string,mixed>
     */
    public function collect(): array {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $sources_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}vyg_sources" );
        $sources_active = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}vyg_sources WHERE status='active'" );
        $sources_paused = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}vyg_sources WHERE status='paused'" );
        $sources_error  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}vyg_sources WHERE status='error'" );

        $videos_total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}vyg_videos" );
        $videos_live    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}vyg_videos WHERE content_type IN ('live_active','live_upcoming')" );
        $videos_avail   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}vyg_videos WHERE availability_status='available'" );
        $videos_hidden  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}vyg_videos WHERE is_hidden=1" );

        // Quota today (units).
        $quota_today = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(quota_units),0) FROM {$prefix}vyg_api_quota_log WHERE DATE(created_at) = %s",
            gmdate( 'Y-m-d' )
        ) );

        // Last 24h error count from sync_logs.
        $errors_24h = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}vyg_sync_logs WHERE level='error' AND created_at >= %s",
            gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
        ) );

        // Last successful sync per source.
        $last_sync = $wpdb->get_var(
            "SELECT MAX(last_success_at) FROM {$prefix}vyg_sources"
        );

        // Live-poll freshness.
        $last_live_poll = $wpdb->get_var(
            "SELECT MAX(last_live_poll_at) FROM {$prefix}vyg_videos"
        );

        // Recent sync jobs (last 5).
        $recent_jobs = $wpdb->get_results(
            "SELECT id, job_type, source_id, status, attempts, started_at, completed_at
             FROM {$prefix}vyg_sync_jobs
             ORDER BY id DESC LIMIT 5",
            ARRAY_A
        );

        // Previous streams count.
        $previous_streams = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}vyg_previous_streams" );

        return array(
            'sources' => array(
                'total'  => $sources_total,
                'active' => $sources_active,
                'paused' => $sources_paused,
                'error'  => $sources_error,
            ),
            'videos' => array(
                'total'   => $videos_total,
                'live'    => $videos_live,
                'available' => $videos_avail,
                'hidden'  => $videos_hidden,
            ),
            'quota' => array(
                'used'      => $quota_today,
                'limit'     => self::DAILY_QUOTA_LIMIT,
                'percent'   => self::DAILY_QUOTA_LIMIT > 0 ? min( 100, (int) round( $quota_today * 100 / self::DAILY_QUOTA_LIMIT ) ) : 0,
            ),
            'errors_24h'      => $errors_24h,
            'last_sync'       => $last_sync,
            'last_live_poll'  => $last_live_poll,
            'recent_jobs'     => is_array( $recent_jobs ) ? $recent_jobs : array(),
            'previous_streams'=> $previous_streams,
            'plugin_version'  => defined( 'VYG_VERSION' ) ? VYG_VERSION : '0.0.0',
            'db_version'      => defined( 'VYG_DB_VERSION' ) ? VYG_DB_VERSION : '0.0.0',
        );
    }

    /**
     * System info for the About page / support requests.
     *
     * @return array<string,mixed>
     */
    public function system_info(): array {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $tables = array();
        foreach ( array( 'vyg_sources', 'vyg_videos', 'vyg_playlists', 'vyg_playlist_video_map', 'vyg_feeds', 'vyg_feed_video_overrides', 'vyg_sync_jobs', 'vyg_sync_logs', 'vyg_api_quota_log', 'vyg_previous_streams' ) as $short ) {
            $table = $prefix . $short;
            $exists = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
            $row_count = $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) : 0;
            $tables[ $short ] = array(
                'exists'    => $exists,
                'row_count' => $row_count,
            );
        }
        $cron = function_exists( 'wp_get_scheduled_events' ) ? (array) wp_get_scheduled_events() : array();
        $vyg_cron = array();
        foreach ( $cron as $hook => $entries ) {
            if ( strpos( (string) $hook, 'vyg_' ) === 0 ) {
                $vyg_cron[ (string) $hook ] = is_array( $entries ) ? count( $entries ) : 0;
            }
        }
        return array(
            'plugin_version' => defined( 'VYG_VERSION' ) ? VYG_VERSION : '0.0.0',
            'db_version'     => defined( 'VYG_DB_VERSION' ) ? VYG_DB_VERSION : '0.0.0',
            'wp_version'     => get_bloginfo( 'version' ),
            'php_version'    => PHP_VERSION,
            'mysql_version'  => $wpdb->db_version(),
            'table_prefix'   => $prefix,
            'tables'         => $tables,
            'cron_events'    => $vyg_cron,
            'memory_limit'   => ini_get( 'memory_limit' ),
            'max_execution_time' => (int) ini_get( 'max_execution_time' ),
        );
    }
}