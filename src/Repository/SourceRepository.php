<?php
/**
 * Source repository — CRUD over the vyg_sources table.
 *
 * Replaces the Phase 1 `vyg_sources_draft` option. Migrator copies any
 * existing draft rows on first install.
 *
 * @package VectorYT\Gallery\Repository
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Repository;

use VectorYT\Gallery\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class SourceRepository {

    public function __construct() {}

    public function table(): string {
        return Schema::table( 'vyg_sources' );
    }

    /**
     * @param array<string,mixed> $data Column => value.
     * @return int Inserted row id.
     */
    public function create( array $data ): int {
        global $wpdb;
        $defaults = array(
            'source_uuid'   => wp_generate_uuid4(),
            'source_type'   => 'channel',
            'auth_mode'     => 'api_key',
            'status'        => 'active',
            'sync_interval' => DAY_IN_SECONDS,
            'created_at'    => gmdate( 'Y-m-d H:i:s' ),
            'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
        );
        $row = array_merge( $defaults, $this->sanitize( $data ) );
        $wpdb->insert( $this->table(), $row, $this->format( $row ) );
        return (int) $wpdb->insert_id;
    }

    public function update( int $id, array $data ): bool {
        global $wpdb;
        $data['updated_at'] = gmdate( 'Y-m-d H:i:s' );
        $row = $this->sanitize( $data );
        return false !== $wpdb->update( $this->table(), $row, array( 'id' => $id ), $this->format( $row ), array( '%d' ) );
    }

    public function delete( int $id ): bool {
        global $wpdb;
        return false !== $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find_by_uuid( string $uuid ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE source_uuid = %s", $uuid ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find_by_youtube_channel_id( string $youtube_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE youtube_channel_id = %s LIMIT 1", $youtube_id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find_by_youtube_playlist_id( string $youtube_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE youtube_playlist_id = %s LIMIT 1", $youtube_id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find_by_youtube_video_id( string $youtube_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE youtube_video_id = %s LIMIT 1", $youtube_id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /**
     * List sources, newest first.
     *
     * @param array{status?:string,source_type?:string,limit?:int,offset?:int} $args
     * @return array<int,array<string,mixed>>
     */
    public function list( array $args = array() ): array {
        global $wpdb;
        $where = '1=1';
        $params = array();
        if ( isset( $args['status'] ) && '' !== $args['status'] ) {
            $where .= ' AND status = %s';
            $params[] = $args['status'];
        }
        if ( isset( $args['source_type'] ) && '' !== $args['source_type'] ) {
            $where .= ' AND source_type = %s';
            $params[] = $args['source_type'];
        }
        $limit  = max( 1, (int) ( $args['limit'] ?? 100 ) );
        $offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
        $params[] = $limit;
        $params[] = $offset;
        $sql = "SELECT * FROM {$this->table()} WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d";
        $prepared = $wpdb->prepare( $sql, $params );
        $rows = $wpdb->get_results( $prepared, ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    public function count(): int {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()}" );
    }

    /**
     * Filter to known DB columns; ignore everything else (defense in depth).
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function sanitize( array $data ): array {
        $allowed = array(
            'source_uuid', 'source_type', 'auth_mode',
            'youtube_channel_id', 'youtube_playlist_id', 'youtube_video_id',
            'handle', 'title', 'thumbnail_url', 'status',
            'sync_interval', 'live_poll_interval',
            'last_sync_at', 'last_success_at', 'last_error_code', 'last_error_message',
            'api_data_expires_at', 'updated_at',
        );
        return array_intersect_key( $data, array_flip( $allowed ) );
    }

    /**
     * @param array<string,mixed> $row
     * @return array<int,string>
     */
    private function format( array $row ): array {
        // Conservative formatting: %s for strings, %d for ints.
        $out = array();
        foreach ( array_keys( $row ) as $col ) {
            $out[] = in_array( $col, array( 'sync_interval', 'live_poll_interval' ), true ) ? '%d' : '%s';
        }
        return $out;
    }
}