<?php
/**
 * Feed query — read-side access to the vyg_videos + vyg_sources tables.
 *
 * Strict contract: NO YouTube API calls. All data comes from local DB. This is
 * the only class the front-end renderer is allowed to call for content data.
 *
 * @package VectorYT\Gallery\Render
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Render;

defined( 'ABSPATH' ) || exit;

final class FeedQuery {

    public function __construct() {}

    /**
     * Query videos for a source. Filters by content_type when supplied.
     *
     * @param array<string,mixed> $args {
     *     @type string  $source_uuid     Required.
     *     @type string  $content_type    Optional — single content_type or comma-separated list.
     *     @type string  $orderby         Optional — one of: published_at, view_count, created_at.
     *                                   Defaults to 'published_at'.
     *     @type string  $order           'ASC' or 'DESC'. Default DESC.
     *     @type int     $limit           1..200. Default 12.
     *     @type int     $offset          For pagination.
     *     @type bool    $include_unavailable If false, excludes availability != 'available'. Default false.
     *     @type bool    $include_hidden  If false, excludes is_hidden = 1. Default false.
     * }
     * @return array<int,array<string,mixed>>
     */
    public function videos_for_source( array $args ): array {
        global $wpdb;
        $defaults = array(
            'source_uuid'           => '',
            'content_type'          => '',
            'orderby'               => 'published_at',
            'order'                 => 'DESC',
            'limit'                 => 12,
            'offset'                => 0,
            'include_unavailable'   => false,
            'include_hidden'        => false,
        );
        $args = array_merge( $defaults, $args );
        $source_uuid = (string) $args['source_uuid'];
        if ( '' === $source_uuid ) {
            return array();
        }
        $limit  = max( 1, min( 200, (int) $args['limit'] ) );
        $offset = max( 0, (int) $args['offset'] );

        $videos_table = $wpdb->prefix . 'vyg_videos';
        $map_table    = $wpdb->prefix . 'vyg_playlist_video_map';
        $sources_table = $wpdb->prefix . 'vyg_sources';

        // Source UUID → source row → youtube_playlist_id OR youtube_channel_id.
        $source = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, source_type, youtube_channel_id, youtube_playlist_id, youtube_video_id FROM {$sources_table} WHERE source_uuid = %s",
            $source_uuid
        ), ARRAY_A );
        if ( ! $source ) {
            return array();
        }

        $joins = '';
        $where_extra = '';
        $params = array();

        $source_type = (string) $source['source_type'];
        if ( 'channel' === $source_type ) {
            // Channel: videos in the channel (via channel_id).
            $where_extra = 'AND v.youtube_channel_id = %s';
            $params[] = (string) $source['youtube_channel_id'];
        } elseif ( 'playlist' === $source_type ) {
            // Playlist: via the playlist_video_map.
            $joins = "INNER JOIN {$map_table} m ON m.video_id = v.id";
            $where_extra = 'AND m.youtube_playlist_id = %s AND m.is_removed = 0';
            $params[] = (string) $source['youtube_playlist_id'];
        } elseif ( 'video' === $source_type ) {
            // Single video: by id.
            $where_extra = 'AND v.youtube_video_id = %s';
            $params[] = (string) $source['youtube_video_id'];
        } else {
            return array();
        }

        if ( '' !== (string) $args['content_type'] ) {
            $types = array_filter( array_map( 'trim', explode( ',', (string) $args['content_type'] ) ) );
            if ( count( $types ) > 0 ) {
                $placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
                $where_extra .= ' AND v.content_type IN (' . $placeholders . ')';
                foreach ( $types as $t ) {
                    $params[] = sanitize_key( $t );
                }
            }
        }

        if ( ! $args['include_unavailable'] ) {
            $where_extra .= " AND v.availability_status = 'available'";
        }
        if ( ! $args['include_hidden'] ) {
            $where_extra .= ' AND v.is_hidden = 0';
        }

        $orderby = sanitize_key( (string) $args['orderby'] );
        $orderby = in_array( $orderby, array( 'published_at', 'view_count', 'created_at', 'duration_seconds' ), true )
            ? $orderby
            : 'published_at';
        $order = strtoupper( (string) $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        $params[] = $limit;
        $params[] = $offset;
        $sql = "SELECT v.id, v.youtube_video_id, v.youtube_channel_id, v.title, v.duration_seconds,
                       v.duration_iso, v.thumbnail_default, v.thumbnail_medium, v.thumbnail_high,
                       v.thumbnail_standard, v.thumbnail_maxres, v.content_type, v.live_status,
                       v.availability_status, v.published_at, v.view_count, v.actual_start_at,
                       v.actual_end_at, v.scheduled_start_at
                FROM {$videos_table} v
                {$joins}
                WHERE 1=1 {$where_extra}
                ORDER BY v.{$orderby} {$order}
                LIMIT %d OFFSET %d";

        $prepared = $wpdb->prepare( $sql, $params );
        $rows = $wpdb->get_results( $prepared, ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Count videos matching the same args as videos_for_source.
     *
     * @param array<string,mixed> $args Same as videos_for_source.
     * @return int
     */
    public function count_videos_for_source( array $args ): int {
        global $wpdb;
        $defaults = array(
            'source_uuid' => '',
            'content_type' => '',
            'include_unavailable' => false,
            'include_hidden' => false,
        );
        $args = array_merge( $defaults, $args );
        $source_uuid = (string) $args['source_uuid'];
        if ( '' === $source_uuid ) {
            return 0;
        }
        $videos_table = $wpdb->prefix . 'vyg_videos';
        $map_table    = $wpdb->prefix . 'vyg_playlist_video_map';
        $sources_table = $wpdb->prefix . 'vyg_sources';

        $source = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, source_type, youtube_channel_id, youtube_playlist_id, youtube_video_id FROM {$sources_table} WHERE source_uuid = %s",
            $source_uuid
        ), ARRAY_A );
        if ( ! $source ) {
            return 0;
        }
        $joins = '';
        $where_extra = '';
        $params = array();
        $source_type = (string) $source['source_type'];
        if ( 'channel' === $source_type ) {
            $where_extra = 'AND v.youtube_channel_id = %s';
            $params[] = (string) $source['youtube_channel_id'];
        } elseif ( 'playlist' === $source_type ) {
            $joins = "INNER JOIN {$map_table} m ON m.video_id = v.id";
            $where_extra = 'AND m.youtube_playlist_id = %s AND m.is_removed = 0';
            $params[] = (string) $source['youtube_playlist_id'];
        } elseif ( 'video' === $source_type ) {
            $where_extra = 'AND v.youtube_video_id = %s';
            $params[] = (string) $source['youtube_video_id'];
        } else {
            return 0;
        }
        if ( '' !== (string) $args['content_type'] ) {
            $types = array_filter( array_map( 'trim', explode( ',', (string) $args['content_type'] ) ) );
            if ( count( $types ) > 0 ) {
                $placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
                $where_extra .= ' AND v.content_type IN (' . $placeholders . ')';
                foreach ( $types as $t ) {
                    $params[] = sanitize_key( $t );
                }
            }
        }
        if ( ! $args['include_unavailable'] ) {
            $where_extra .= " AND v.availability_status = 'available'";
        }
        if ( ! $args['include_hidden'] ) {
            $where_extra .= ' AND v.is_hidden = 0';
        }
        $sql = "SELECT COUNT(*) FROM {$videos_table} v {$joins} WHERE 1=1 {$where_extra}";
        $prepared = $wpdb->prepare( $sql, $params );
        return (int) $wpdb->get_var( $prepared );
    }

    /**
     * Look up a source by UUID.
     *
     * @return array<string,mixed>|null
     */
    public function find_source_by_uuid( string $uuid ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'vyg_sources';
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, source_uuid, source_type, youtube_channel_id, youtube_playlist_id, youtube_video_id, title, thumbnail_url, status
             FROM {$table} WHERE source_uuid = %s",
            $uuid
        ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /**
     * List all active sources — used by the [youtube_feed] source picker UI.
     *
     * @return array<int,array<string,mixed>>
     */
    public function list_active_sources(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'vyg_sources';
        $rows = $wpdb->get_results(
            "SELECT id, source_uuid, source_type, youtube_channel_id, youtube_playlist_id, youtube_video_id, title, thumbnail_url
             FROM {$table} WHERE status = 'active' ORDER BY id DESC",
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : array();
    }
}