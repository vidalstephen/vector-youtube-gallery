<?php
/**
 * Schema definitions for all custom tables.
 *
 * Each public method returns the dbDelta-compatible SQL for one table.
 * Indexes are inline with the CREATE TABLE statement (dbDelta parses them).
 *
 * Per plan §5 — table prefix is {$wpdb->prefix}vyg_
 *
 * @package VectorYT\Gallery\Database
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Database;

defined( 'ABSPATH' ) || exit;

final class Schema {

    /**
     * Return every CREATE TABLE statement keyed by table name (no prefix).
     * Use dbDelta() on each — dbDelta is idempotent on column shape.
     *
     * @return array<string,string>
     */
    public static function all_create_statements(): array {
        return array(
            'vyg_sources'              => self::sources(),
            'vyg_videos'               => self::videos(),
            'vyg_playlists'            => self::playlists(),
            'vyg_playlist_video_map'   => self::playlist_video_map(),
            'vyg_feeds'                => self::feeds(),
            'vyg_feed_video_overrides' => self::feed_video_overrides(),
            'vyg_sync_jobs'            => self::sync_jobs(),
            'vyg_sync_logs'            => self::sync_logs(),
            'vyg_api_quota_log'        => self::api_quota_log(),
            'vyg_previous_streams'     => self::previous_streams(),
        );
    }

    /**
     * @return string[]
     */
    public static function table_names(): array {
        return array_keys( self::all_create_statements() );
    }

    public static function sources(): string {
        global $wpdb;
        $t   = self::table( 'vyg_sources' );
        $c   = $wpdb->get_charset_collate();
        // dbDelta requires two spaces between columns for column-change detection.
        return "CREATE TABLE {$t} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_uuid char(36) NOT NULL,
            source_type varchar(32) NOT NULL,
            auth_mode varchar(16) NOT NULL DEFAULT 'api_key',
            youtube_channel_id varchar(64) DEFAULT NULL,
            youtube_playlist_id varchar(128) DEFAULT NULL,
            youtube_video_id varchar(32) DEFAULT NULL,
            handle varchar(128) DEFAULT NULL,
            title text DEFAULT NULL,
            thumbnail_url text DEFAULT NULL,
            status varchar(32) NOT NULL DEFAULT 'active',
            sync_interval int(11) NOT NULL DEFAULT 86400,
            live_poll_interval int(11) NOT NULL DEFAULT 0,
            last_sync_at datetime DEFAULT NULL,
            last_success_at datetime DEFAULT NULL,
            last_error_code varchar(128) DEFAULT NULL,
            last_error_message text DEFAULT NULL,
            api_data_expires_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY source_uuid (source_uuid),
            KEY source_type (source_type),
            KEY youtube_channel_id (youtube_channel_id),
            KEY youtube_playlist_id (youtube_playlist_id),
            KEY status (status),
            KEY last_success_at (last_success_at),
            KEY api_data_expires_at (api_data_expires_at)
        ) {$c};";
    }

    public static function videos(): string {
        global $wpdb;
        $t = self::table( 'vyg_videos' );
        $c = $wpdb->get_charset_collate();
        return "CREATE TABLE {$t} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            youtube_video_id varchar(32) NOT NULL,
            youtube_channel_id varchar(64) DEFAULT NULL,
            title text DEFAULT NULL,
            description_excerpt text DEFAULT NULL,
            published_at datetime DEFAULT NULL,
            duration_iso varchar(32) DEFAULT NULL,
            duration_seconds int(11) DEFAULT NULL,
            thumbnail_default text DEFAULT NULL,
            thumbnail_medium text DEFAULT NULL,
            thumbnail_high text DEFAULT NULL,
            thumbnail_standard text DEFAULT NULL,
            thumbnail_maxres text DEFAULT NULL,
            privacy_status varchar(32) DEFAULT NULL,
            upload_status varchar(32) DEFAULT NULL,
            embeddable tinyint(1) NOT NULL DEFAULT 1,
            availability_status varchar(32) DEFAULT 'unknown',
            content_type varchar(32) DEFAULT 'standard',
            live_status varchar(32) DEFAULT 'none',
            scheduled_start_at datetime DEFAULT NULL,
            actual_start_at datetime DEFAULT NULL,
            actual_end_at datetime DEFAULT NULL,
            view_count bigint(20) DEFAULT NULL,
            comment_count bigint(20) DEFAULT NULL,
            category_id varchar(64) DEFAULT NULL,
            tags_json longtext DEFAULT NULL,
            player_embed_html_hash char(64) DEFAULT NULL,
            raw_api_hash char(64) DEFAULT NULL,
            raw_api_snapshot longtext DEFAULT NULL,
            is_hidden tinyint(1) NOT NULL DEFAULT 0,
            is_pinned tinyint(1) NOT NULL DEFAULT 0,
            concurrent_viewers int(11) DEFAULT NULL,
            last_live_poll_at datetime DEFAULT NULL,
            manual_content_type varchar(32) DEFAULT NULL,
            manual_content_source varchar(190) DEFAULT NULL,
            manual_reason varchar(500) DEFAULT NULL,
            last_checked_at datetime DEFAULT NULL,
            last_success_at datetime DEFAULT NULL,
            api_data_expires_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY youtube_video_id (youtube_video_id),
            KEY youtube_channel_id (youtube_channel_id),
            KEY published_at (published_at),
            KEY content_type (content_type),
            KEY live_status (live_status),
            KEY availability_status (availability_status),
            KEY is_hidden (is_hidden),
            KEY api_data_expires_at (api_data_expires_at)
        ) {$c};";
    }

    public static function playlists(): string {
        global $wpdb;
        $t = self::table( 'vyg_playlists' );
        $c = $wpdb->get_charset_collate();
        return "CREATE TABLE {$t} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            youtube_playlist_id varchar(128) NOT NULL,
            youtube_channel_id varchar(64) DEFAULT NULL,
            title text DEFAULT NULL,
            description_excerpt text DEFAULT NULL,
            thumbnail_url text DEFAULT NULL,
            item_count int(11) DEFAULT NULL,
            privacy_status varchar(32) DEFAULT NULL,
            last_sync_at datetime DEFAULT NULL,
            api_data_expires_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY youtube_playlist_id (youtube_playlist_id),
            KEY youtube_channel_id (youtube_channel_id),
            KEY api_data_expires_at (api_data_expires_at)
        ) {$c};";
    }

    public static function playlist_video_map(): string {
        global $wpdb;
        $t = self::table( 'vyg_playlist_video_map' );
        $c = $wpdb->get_charset_collate();
        return "CREATE TABLE {$t} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            playlist_id bigint(20) unsigned NOT NULL,
            video_id bigint(20) unsigned NOT NULL,
            youtube_playlist_id varchar(128) NOT NULL,
            youtube_video_id varchar(32) NOT NULL,
            position int(11) DEFAULT NULL,
            playlist_item_id varchar(128) DEFAULT NULL,
            added_at datetime DEFAULT NULL,
            is_removed tinyint(1) NOT NULL DEFAULT 0,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY playlist_video (playlist_id,video_id),
            KEY youtube_playlist_id (youtube_playlist_id),
            KEY youtube_video_id (youtube_video_id),
            KEY position (position)
        ) {$c};";
    }

    public static function feeds(): string {
        global $wpdb;
        $t = self::table( 'vyg_feeds' );
        $c = $wpdb->get_charset_collate();
        return "CREATE TABLE {$t} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            feed_uuid char(36) NOT NULL,
            name varchar(190) NOT NULL,
            feed_type varchar(32) NOT NULL,
            layout varchar(32) NOT NULL DEFAULT 'grid',
            source_config_json longtext DEFAULT NULL,
            display_config_json longtext DEFAULT NULL,
            filter_config_json longtext DEFAULT NULL,
            sort_config_json longtext DEFAULT NULL,
            custom_css longtext DEFAULT NULL,
            status varchar(32) NOT NULL DEFAULT 'draft',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY feed_uuid (feed_uuid),
            KEY feed_type (feed_type),
            KEY status (status)
        ) {$c};";
    }

    public static function feed_video_overrides(): string {
        global $wpdb;
        $t = self::table( 'vyg_feed_video_overrides' );
        $c = $wpdb->get_charset_collate();
        return "CREATE TABLE {$t} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            feed_id bigint(20) unsigned NOT NULL,
            video_id bigint(20) unsigned NOT NULL,
            is_hidden tinyint(1) NOT NULL DEFAULT 0,
            is_pinned tinyint(1) NOT NULL DEFAULT 0,
            manual_order int(11) DEFAULT NULL,
            manual_label varchar(190) DEFAULT NULL,
            manual_content_type varchar(32) DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY feed_video (feed_id,video_id),
            KEY is_pinned (is_pinned)
        ) {$c};";
    }

    public static function sync_jobs(): string {
        global $wpdb;
        $t = self::table( 'vyg_sync_jobs' );
        $c = $wpdb->get_charset_collate();
        return "CREATE TABLE {$t} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_uuid char(36) NOT NULL,
            source_id bigint(20) unsigned DEFAULT NULL,
            job_type varchar(64) NOT NULL,
            status varchar(32) NOT NULL DEFAULT 'queued',
            cursor_json longtext DEFAULT NULL,
            attempts int(11) NOT NULL DEFAULT 0,
            next_attempt_at datetime DEFAULT NULL,
            started_at datetime DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            error_code varchar(128) DEFAULT NULL,
            error_message text DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY job_uuid (job_uuid),
            KEY source_id (source_id),
            KEY job_type (job_type),
            KEY status (status),
            KEY next_attempt_at (next_attempt_at)
        ) {$c};";
    }

    public static function sync_logs(): string {
        global $wpdb;
        $t = self::table( 'vyg_sync_logs' );
        $c = $wpdb->get_charset_collate();
        return "CREATE TABLE {$t} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_id bigint(20) unsigned DEFAULT NULL,
            source_id bigint(20) unsigned DEFAULT NULL,
            level varchar(16) NOT NULL,
            event varchar(128) NOT NULL,
            message text NOT NULL,
            context_json longtext DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY job_id (job_id),
            KEY source_id (source_id),
            KEY level (level),
            KEY event (event),
            KEY created_at (created_at)
        ) {$c};";
    }

    public static function api_quota_log(): string {
        global $wpdb;
        $t = self::table( 'vyg_api_quota_log' );
        $c = $wpdb->get_charset_collate();
        return "CREATE TABLE {$t} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_id bigint(20) unsigned DEFAULT NULL,
            method varchar(64) NOT NULL,
            quota_units int(11) NOT NULL DEFAULT 1,
            request_hash char(64) DEFAULT NULL,
            response_code int(11) DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY source_id (source_id),
            KEY method (method),
            KEY created_at (created_at)
        ) {$c};";
    }

    /**
     * Phase 5 — previous streams storage.
     *
     * Keeps the last N ended live broadcasts per source (default 50). When a
     * live stream ends, LiveStatusPollJob promotes the row from vyg_videos into
     * vyg_previous_streams so the LiveLayout can render a "Recent streams"
     * section without losing them when the source's regular sync deletes or
     * archives the underlying video.
     *
     * Retention is enforced by PreviousStreamsRepository::prune_to_limit().
     */
    public static function previous_streams(): string {
        global $wpdb;
        $t = self::table( 'vyg_previous_streams' );
        $c = $wpdb->get_charset_collate();
        return "CREATE TABLE {$t} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_id bigint(20) unsigned NOT NULL,
            youtube_video_id varchar(32) NOT NULL,
            title varchar(500) DEFAULT NULL,
            thumbnail_default varchar(500) DEFAULT NULL,
            started_at datetime DEFAULT NULL,
            ended_at datetime DEFAULT NULL,
            duration_seconds int(11) DEFAULT NULL,
            peak_concurrent_viewers int(11) DEFAULT NULL,
            view_count int(11) DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_source_video (source_id, youtube_video_id),
            KEY source_ended (source_id, ended_at)
        ) {$c};";
    }

    /**
     * Return the fully-prefixed table name.
     */
    public static function table( string $short ): string {
        global $wpdb;
        return $wpdb->prefix . $short;
    }
}