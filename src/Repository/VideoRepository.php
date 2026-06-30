<?php
/**
 * Video repository — CRUD over the vyg_videos table.
 *
 * @package VectorYT\Gallery\Repository
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Repository;

use VectorYT\Gallery\Database\Schema;
use VectorYT\Gallery\Normalize\VideoNormalizer;

defined( 'ABSPATH' ) || exit;

class VideoRepository {

    public function table(): string {
        return Schema::table( 'vyg_videos' );
    }

    /**
     * Upsert a video from an API resource. Returns the internal row id.
     *
     * @param array<string,mixed> $api_resource   Raw YouTube video resource.
     * @param array<string,mixed> $classification Optional classification overrides (Phase 3).
     * @return int Internal vyg_videos.id
     */
    public function upsert_from_api( array $api_resource, array $classification = array() ): int {
        global $wpdb;

        $existing = $this->find_by_youtube_id( (string) $api_resource['id'] );
        $normalized = VideoNormalizer::with_defaults()->normalize( $api_resource, $classification );
        $normalized['updated_at'] = gmdate( 'Y-m-d H:i:s' );

        if ( null === $existing ) {
            $normalized['created_at'] = gmdate( 'Y-m-d H:i:s' );
            $wpdb->insert( $this->table(), $normalized );
            return (int) $wpdb->insert_id;
        }

        $id = (int) $existing['id'];
        $wpdb->update( $this->table(), $normalized, array( 'id' => $id ), '%s', '%d' );
        return $id;
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
    public function find_by_youtube_id( string $youtube_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE youtube_video_id = %s LIMIT 1", $youtube_id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /**
     * Bulk-fetch by YouTube IDs (used by sync after playlist pages).
     *
     * @param array<int,string> $youtube_ids
     * @return array<string,array<string,mixed>>  Keyed by youtube_video_id.
     */
    public function find_many_by_youtube_ids( array $youtube_ids ): array {
        if ( 0 === count( $youtube_ids ) ) {
            return array();
        }
        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $youtube_ids ), '%s' ) );
        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE youtube_video_id IN ($placeholders)", $youtube_ids ),
            ARRAY_A
        );
        $out = array();
        foreach ( (array) $rows as $row ) {
            $out[ (string) $row['youtube_video_id'] ] = $row;
        }
        return $out;
    }

    /**
     * Find videos by content_type filter — used by sync jobs that need to
     * pick batches of upcoming-live or recently-ended videos.
     *
     * @return array<int,array<string,mixed>>
     */
    public function list_by_content_type( string $content_type, int $limit = 50 ): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE content_type = %s ORDER BY published_at DESC LIMIT %d",
                $content_type,
                $limit
            ),
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function most_recent_for_channel( string $youtube_channel_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE youtube_channel_id = %s ORDER BY published_at DESC LIMIT 1",
            $youtube_channel_id
        ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    public function count_for_channel( string $youtube_channel_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table()} WHERE youtube_channel_id = %s",
            $youtube_channel_id
        ) );
    }

    /**
     * Mark a video as unavailable (deleted / private / embed-disabled).
     */
    public function mark_unavailable( string $youtube_id, string $reason ): void {
        global $wpdb;
        $table = $this->table();
        $wpdb->update(
            $table,
            array(
                'availability_status' => substr( $reason, 0, 32 ),
                'updated_at'           => gmdate( 'Y-m-d H:i:s' ),
            ),
            array( 'youtube_video_id' => $youtube_id ),
            array( '%s', '%s' ),
            array( '%s' )
        );
    }

    /**
     * Generic column-update by primary key. Used by LiveStatusPollJob.
     *
     * @param array<string,mixed> $updates
     */
    public function update_by_id( int $id, array $updates ): int {
        global $wpdb;
        if ( $id <= 0 || empty( $updates ) ) {
            return 0;
        }
        $table = $this->table();
        $formats = array();
        foreach ( array_keys( $updates ) as $col ) {
            $formats[] = $this->format_for_column( $col );
        }
        $result = $wpdb->update( $table, $updates, array( 'id' => $id ), $formats, array( '%d' ) );
        return false === $result ? 0 : count( $updates );
    }

    /**
     * Format spec for a known column.
     */
    private function format_for_column( string $col ): string {
        $int_cols = array(
            'id', 'duration_seconds', 'view_count', 'like_count', 'comment_count',
            'is_short', 'is_live', 'is_deleted', 'is_hidden', 'is_pinned',
            'concurrent_viewers', 'source_id', 'moderated_by',
        );
        return in_array( $col, $int_cols, true ) ? '%d' : '%s';
    }
}