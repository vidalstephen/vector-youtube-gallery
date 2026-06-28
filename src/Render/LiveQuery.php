<?php
/**
 * LiveQuery — read-side helper for the LiveLayout.
 *
 * Returns videos in the order the LiveLayout's decision tree wants:
 *   1. Live now       (live_status = 'live' AND content_type IN (live_active))
 *   2. Upcoming       (live_status = 'upcoming')
 *   3. Recent streams (from vyg_previous_streams, ended within the retention window)
 *
 * The renderer does the sectioning; LiveQuery just returns the union ordered
 * sensibly.
 *
 * @package VectorYT\Gallery\Render
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Render;

use VectorYT\Gallery\Repository\PreviousStreamsRepository;

defined( 'ABSPATH' ) || exit;

class LiveQuery {

    public function __construct(
        private readonly PreviousStreamsRepository $previous,
    ) {}

    /**
     * Bucketed response for the LiveLayout.
     *
     * @return array{live: array, upcoming: array, replay: array}
     */
    public function buckets_for_source( array $source ): array {
        $buckets = array(
            'live'     => array(),
            'upcoming' => array(),
            'replay'   => array(),
        );
        $buckets['live']     = $this->live_now( $source );
        $buckets['upcoming'] = $this->upcoming( $source );
        $buckets['replay']   = $this->replay( $source );
        return $buckets;
    }

    /**
     * Live broadcasts currently streaming.
     *
     * @return array<int,array<string,mixed>>
     */
    public function live_now( array $source, int $limit = 10 ): array {
        global $wpdb;
        $videos_table = $wpdb->prefix . 'vyg_videos';
        $ch = (string) ( $source['youtube_channel_id'] ?? '' );
        $vid = (string) ( $source['youtube_video_id'] ?? '' );
        $pl  = (string) ( $source['youtube_playlist_id'] ?? '' );
        $where = $this->source_where( $source, 'v.', $ch, $vid, $pl );
        if ( '' === $where ) {
            return array();
        }
        $sql = "SELECT * FROM {$videos_table} v WHERE v.live_status = 'live' AND {$where} ORDER BY v.concurrent_viewers DESC LIMIT %d";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $limit ), ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Upcoming scheduled broadcasts.
     *
     * @return array<int,array<string,mixed>>
     */
    public function upcoming( array $source, int $limit = 10 ): array {
        global $wpdb;
        $videos_table = $wpdb->prefix . 'vyg_videos';
        $ch = (string) ( $source['youtube_channel_id'] ?? '' );
        $vid = (string) ( $source['youtube_video_id'] ?? '' );
        $pl  = (string) ( $source['youtube_playlist_id'] ?? '' );
        $where = $this->source_where( $source, 'v.', $ch, $vid, $pl );
        if ( '' === $where ) {
            return array();
        }
        $sql = "SELECT * FROM {$videos_table} v WHERE v.live_status = 'upcoming' AND {$where} ORDER BY v.scheduled_start_at ASC LIMIT %d";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $limit ), ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Recently ended streams from vyg_previous_streams.
     *
     * @return array<int,array<string,mixed>>
     */
    public function replay( array $source, int $limit = 20 ): array {
        $source_id = isset( $source['id'] ) ? (int) $source['id'] : 0;
        if ( $source_id <= 0 ) {
            return array();
        }
        $rows = $this->previous->list_for_source( $source_id, $limit );
        // Normalize to the same shape the live query returns, so the template can render both.
        return array_map( static function ( array $row ): array {
            return array(
                'youtube_video_id'   => (string) ( $row['youtube_video_id'] ?? '' ),
                'title'              => (string) ( $row['title'] ?? '' ),
                'thumbnail_default'  => (string) ( $row['thumbnail_default'] ?? '' ),
                'thumbnail_medium'   => (string) ( $row['thumbnail_default'] ?? '' ),
                'thumbnail_high'     => (string) ( $row['thumbnail_default'] ?? '' ),
                'duration_seconds'   => (int) ( $row['duration_seconds'] ?? 0 ),
                'view_count'         => (int) ( $row['view_count'] ?? 0 ),
                'concurrent_viewers' => (int) ( $row['peak_concurrent_viewers'] ?? 0 ),
                'live_status'        => 'ended',
                'content_type'       => 'live_replay',
                'started_at'         => $row['started_at'] ?? null,
                'ended_at'           => $row['ended_at'] ?? null,
            );
        }, $rows );
    }

    /**
     * Build a WHERE clause that scopes a query to this source.
     *
     * Returns '' when the source_type is unknown (caller should short-circuit).
     */
    private function source_where( array $source, string $alias, string $ch, string $vid, string $pl ): string {
        $type = (string) ( $source['source_type'] ?? '' );
        if ( 'channel' === $type && '' !== $ch ) {
            return $alias . 'youtube_channel_id = %s AND ' . $alias . 'availability_status = \'available\'';
        }
        if ( 'video' === $type && '' !== $vid ) {
            return $alias . 'youtube_video_id = %s AND ' . $alias . 'availability_status = \'available\'';
        }
        if ( 'playlist' === $type && '' !== $pl ) {
            return $alias . 'source_id = %d AND ' . $alias . 'availability_status = \'available\'';
        }
        return '';
    }
}