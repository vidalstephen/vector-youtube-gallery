<?php
/**
 * PreviousStreamsRepository — CRUD on vyg_previous_streams.
 *
 * Phase 5 — when a live broadcast ends, LiveStatusPollJob promotes the row
 * from vyg_videos into vyg_previous_streams. This repository owns:
 *   - upsert(promoted row)        : INSERT … ON DUPLICATE KEY UPDATE
 *   - list_for_source(source_id)  : ordered by ended_at DESC, optional limit
 *   - prune_to_limit(source_id)   : keep last N (default 50) per source
 *
 * The unique key on (source_id, youtube_video_id) prevents duplicate copies.
 *
 * @package VectorYT\Gallery\Repository
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Repository;

defined( 'ABSPATH' ) || exit;

class PreviousStreamsRepository {

    public function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'vyg_previous_streams';
    }

    /**
     * Promote an ended live broadcast. Idempotent via UNIQUE(source_id, youtube_video_id).
     *
     * @param array<string,mixed> $stream {
     *     @type int    $source_id
     *     @type string $youtube_video_id
     *     @type string $title
     *     @type string $thumbnail_default
     *     @type string $started_at   ISO 8601 or 'YYYY-MM-DD HH:MM:SS'
     *     @type string $ended_at     ISO 8601 or 'YYYY-MM-DD HH:MM:SS'
     *     @type int    $duration_seconds
     *     @type int    $peak_concurrent_viewers
     *     @type int    $view_count
     * }
     */
    public function upsert( array $stream ): int {
        global $wpdb;
        $now = current_time( 'mysql' );
        $row = array(
            'source_id'              => (int) ( $stream['source_id'] ?? 0 ),
            'youtube_video_id'       => (string) ( $stream['youtube_video_id'] ?? '' ),
            'title'                  => (string) ( $stream['title'] ?? '' ),
            'thumbnail_default'      => (string) ( $stream['thumbnail_default'] ?? '' ),
            'started_at'             => $this->normalize_dt( $stream['started_at'] ?? null ),
            'ended_at'               => $this->normalize_dt( $stream['ended_at'] ?? null ),
            'duration_seconds'       => isset( $stream['duration_seconds'] ) ? (int) $stream['duration_seconds'] : null,
            'peak_concurrent_viewers'=> isset( $stream['peak_concurrent_viewers'] ) ? (int) $stream['peak_concurrent_viewers'] : null,
            'view_count'             => isset( $stream['view_count'] ) ? (int) $stream['view_count'] : null,
            'created_at'             => $now,
        );
        // Strip nulls for ON DUPLICATE KEY UPDATE so they don't overwrite.
        $update_row = $row;
        unset( $update_row['created_at'] );
        $update_row = array_filter( $update_row, static fn( $v ) => $v !== null && $v !== '' );

        $result = $wpdb->replace( $this->table(), $row, $this->format_columns( $row ) );
        if ( false === $result ) {
            return 0;
        }
        return (int) ( $wpdb->insert_id ?? 0 );
    }

    /**
     * List previous streams for a source.
     *
     * @return array<int,array<string,mixed>>
     */
    public function list_for_source( int $source_id, int $limit = 50 ): array {
        global $wpdb;
        $table = $this->table();
        $limit = max( 1, min( 200, $limit ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE source_id = %d ORDER BY ended_at DESC LIMIT %d",
            $source_id,
            $limit
        ), ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Keep only the most-recent $limit streams per source. Delete the rest.
     */
    public function prune_to_limit( int $source_id, int $limit = 50 ): int {
        global $wpdb;
        $table = $this->table();
        $limit = max( 1, $limit );

        // Identify rows to keep (top N by ended_at DESC, ties broken by id DESC).
        $keep_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE source_id = %d ORDER BY ended_at DESC, id DESC LIMIT %d",
            $source_id,
            $limit
        ) );
        if ( empty( $keep_ids ) ) {
            return 0;
        }
        // Delete the rest. IN clause with placeholders.
        $placeholders = implode( ',', array_fill( 0, count( $keep_ids ), '%d' ) );
        $params = array_merge( array( $source_id ), array_map( 'intval', $keep_ids ) );
        $sql = "DELETE FROM {$table} WHERE source_id = %d AND id NOT IN ({$placeholders})";
        $prepared = $wpdb->prepare( $sql, $params );
        $deleted = $wpdb->query( $prepared );
        return is_int( $deleted ) ? $deleted : 0;
    }

    /**
     * Count streams currently in the table for a source.
     */
    public function count_for_source( int $source_id ): int {
        global $wpdb;
        $table = $this->table();
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE source_id = %d",
            $source_id
        ) );
    }

    /**
     * Convert ISO 8601 or 'Y-m-d H:i:s' to MySQL datetime. Returns null for empty/invalid.
     */
    private function normalize_dt( mixed $value ): ?string {
        if ( null === $value || '' === $value ) {
            return null;
        }
        if ( $value instanceof \DateTimeInterface ) {
            return $value->format( 'Y-m-d H:i:s' );
        }
        $str = (string) $value;
        // Strip trailing Z / timezone for MySQL.
        $str = preg_replace( '/Z$/', '', $str );
        $str = preg_replace( '/[+-]\d{2}:\d{2}$/', '', $str );
        // Try strtotime.
        $ts = strtotime( $str );
        if ( false === $ts ) {
            return null;
        }
        return gmdate( 'Y-m-d H:i:s', $ts );
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,string>
     */
    private function format_columns( array $row ): array {
        $map = array(
            'source_id'               => '%d',
            'youtube_video_id'        => '%s',
            'title'                   => '%s',
            'thumbnail_default'       => '%s',
            'started_at'              => '%s',
            'ended_at'                => '%s',
            'duration_seconds'        => '%d',
            'peak_concurrent_viewers' => '%d',
            'view_count'              => '%d',
            'created_at'              => '%s',
        );
        $out = array();
        foreach ( $row as $key => $_value ) {
            if ( isset( $map[ $key ] ) ) {
                $out[ $key ] = $map[ $key ];
            }
        }
        return $out;
    }
}