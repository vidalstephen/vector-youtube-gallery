<?php
/**
 * Versioned data migrations.
 *
 * Each migration is keyed by the db_version it ships with. On install, the
 * migrator walks every migration between the previous installed version and
 * the new one in order.
 *
 * Phase 2 ships with one migration (2.0.0): move rows from the
 * `vyg_sources_draft` option (Phase 1 placeholder) into the new `vyg_sources`
 * table, then delete the option.
 *
 * @package VectorYT\Gallery\Database
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Database;

use VectorYT\Gallery\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class Migrator {

    /**
     * Map version => migration callable.
     * Callables receive (wpdb $wpdb) and may run any SQL.
     *
     * @var array<string,callable(\wpdb):void>
     */
    private array $migrations;

    public function __construct(
        private readonly Logger $logger,
    ) {
        $this->migrations = array(
            '0.1.0' => array( self::class, 'migrate_draft_sources_to_table' ),
        );
    }

    /**
     * Run every migration whose version is between $previous and $current.
     * If $previous == $current, do nothing (idempotent re-install).
     */
    public function run( string $previous, string $current ): void {
        if ( version_compare( $previous, $current, '>=' ) ) {
            return; // no upgrade
        }

        foreach ( $this->migrations as $version => $callable ) {
            if ( version_compare( $previous, $version, '<' ) && version_compare( $current, $version, '>=' ) ) {
                $this->logger->info( 'Running migration ' . $version );
                $callable( $GLOBALS['wpdb'] );
            }
        }
    }

    /**
     * Migration 0.1.0: copy Phase 1 `vyg_sources_draft` rows into `vyg_sources`.
     * Phase 1 stored sources as a serialized option array. Phase 2 reads them
     * and inserts one row per source.
     */
    public static function migrate_draft_sources_to_table( \wpdb $wpdb ): void {
        $draft = get_option( 'vyg_sources_draft', null );
        if ( ! is_array( $draft ) || 0 === count( $draft ) ) {
            return;
        }

        $table = Schema::table( 'vyg_sources' );
        $now   = gmdate( 'Y-m-d H:i:s' );

        foreach ( $draft as $s ) {
            if ( ! is_array( $s ) ) {
                continue;
            }
            $uuid = sanitize_text_field( (string) ( $s['uuid'] ?? wp_generate_uuid4() ) );
            $type = sanitize_key( (string) ( $s['source_type'] ?? '' ) );
            $input = sanitize_text_field( (string) ( $s['input'] ?? '' ) );
            $yt_id = sanitize_text_field( (string) ( $s['youtube_id'] ?? '' ) );
            $title = sanitize_text_field( (string) ( $s['title'] ?? '' ) );
            $thumb = esc_url_raw( (string) ( $s['thumbnail'] ?? '' ) );

            // Map Phase 1 source_type → DB column values.
            $youtube_channel_id = $youtube_playlist_id = $youtube_video_id = null;
            $source_type = $type;
            if ( 'channel' === $type ) {
                $youtube_channel_id = $yt_id;
            } elseif ( 'playlist' === $type ) {
                $youtube_playlist_id = $yt_id;
            } elseif ( 'video' === $type ) {
                $youtube_video_id = $yt_id;
            }

            $wpdb->insert(
                $table,
                array(
                    'source_uuid'         => $uuid,
                    'source_type'         => $source_type,
                    'auth_mode'           => 'api_key',
                    'youtube_channel_id'  => $youtube_channel_id,
                    'youtube_playlist_id' => $youtube_playlist_id,
                    'youtube_video_id'    => $youtube_video_id,
                    'handle'              => null,
                    'title'               => $title,
                    'thumbnail_url'       => $thumb,
                    'status'              => 'active',
                    'sync_interval'       => DAY_IN_SECONDS,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ),
                array( '%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s' )
            );
        }

        delete_option( 'vyg_sources_draft' );
    }
}