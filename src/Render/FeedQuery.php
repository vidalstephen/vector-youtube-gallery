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

    /**
     * Query videos for a multi-source feed config.
     *
     * Accepts the canonical config shape produced by FeedRepository::decode_config():
     *   source.sources           array<int,array{source_uuid, weight, pinned, label}>
     *   source.manual_video_ids  array<int,string>
     *   source.exclude_video_ids array<int,string>
     *   source.include_query     'any' | 'all' (reserved for future multi-keyword search)
     *
     * Algorithm:
     *   1. Query each source independently via videos_for_source().
     *   2. Append manual_video_ids (by ID, joined with vyg_videos).
     *   3. Dedupe by youtube_video_id (first wins; pinned sources always first).
     *   4. Apply exclude_video_ids.
     *   5. Sort by orderby + order.
     *   6. Slice by limit + offset.
     *
     * @param array<string,mixed> $args {
     *     @type array<string,mixed> $source  Canonical multi-source config.
     *     @type string              $content_type Optional content_type filter.
     *     @type string              $orderby     Default 'published_at'.
     *     @type string              $order       'ASC'|'DESC'. Default 'DESC'.
     *     @type int                 $limit       Default 12.
     *     @type int                 $offset      Default 0.
     *     @type bool                $include_unavailable Default false.
     *     @type bool                $include_hidden      Default false.
     *     @type bool                $include_manual      Default true. Set false to skip manual_video_ids.
     * }
     * @return array<int,array<string,mixed>>
     */
    public function videos_for_feed( array $args ): array {
        $defaults = array(
            'source'             => array(),
            'content_type'       => '',
            'orderby'            => 'published_at',
            'order'              => 'DESC',
            'limit'              => 12,
            'offset'             => 0,
            'include_unavailable' => false,
            'include_hidden'      => false,
            'include_manual'     => true,
        );
        $args = array_merge( $defaults, $args );

        $cfg    = is_array( $args['source'] ) ? $args['source'] : array();
        $sources       = isset( $cfg['sources'] ) && is_array( $cfg['sources'] ) ? $cfg['sources'] : array();
        $manual_ids    = isset( $cfg['manual_video_ids'] ) && is_array( $cfg['manual_video_ids'] ) ? $cfg['manual_video_ids'] : array();
        $exclude_ids   = isset( $cfg['exclude_video_ids'] ) && is_array( $cfg['exclude_video_ids'] ) ? $cfg['exclude_video_ids'] : array();

        $per_source_limit = max( 1, min( 200, (int) $args['limit'] ) + (int) $args['offset'] );

        // 1. Pull videos from each source. Pinned sources go first so dedupe keeps them.
        $ordered = array();
        $pinned  = array();
        foreach ( $sources as $entry ) {
            if ( ! is_array( $entry ) || empty( $entry['source_uuid'] ) ) {
                continue;
            }
            $rows = $this->videos_for_source( array(
                'source_uuid'         => (string) $entry['source_uuid'],
                'content_type'        => (string) $args['content_type'],
                'orderby'             => (string) $args['orderby'],
                'order'               => (string) $args['order'],
                'limit'               => $per_source_limit,
                'offset'              => 0,
                'include_unavailable' => (bool) $args['include_unavailable'],
                'include_hidden'      => (bool) $args['include_hidden'],
            ) );
            if ( ! empty( $entry['pinned'] ) ) {
                $pinned = array_merge( $pinned, $rows );
            } else {
                $ordered = array_merge( $ordered, $rows );
            }
        }

        // 2. Manual video IDs.
        if ( ! empty( $args['include_manual'] ) && ! empty( $manual_ids ) ) {
            $manual_rows = $this->videos_for_ids( $manual_ids, array(
                'include_unavailable' => (bool) $args['include_unavailable'],
                'include_hidden'      => (bool) $args['include_hidden'],
            ) );
            $ordered = array_merge( $ordered, $manual_rows );
        }

        // 3. Dedupe by youtube_video_id. Pinned first.
        $merged = $this->dedupe_videos( array_merge( $pinned, $ordered ) );

        // 4. Exclude.
        if ( ! empty( $exclude_ids ) ) {
            $exclude_set = array_flip( $exclude_ids );
            $merged = array_values( array_filter( $merged, static function ( array $row ) use ( $exclude_set ): bool {
                return ! isset( $exclude_set[ (string) ( $row['youtube_video_id'] ?? '' ) ] );
            } ) );
        }

        // 5. Sort (pinned first, then by orderby).
        $merged = $this->sort_videos( $merged, (string) $args['orderby'], (string) $args['order'] );

        // 6. Slice.
        $limit  = max( 1, min( 200, (int) $args['limit'] ) );
        $offset = max( 0, (int) $args['offset'] );
        return array_slice( $merged, $offset, $limit );
    }

    /**
     * Count videos matching a multi-source feed config.
     *
     * @param array<string,mixed> $args Same shape as videos_for_feed (limit/offset ignored).
     * @return int
     */
    public function count_videos_for_feed( array $args ): int {
        $args['limit']  = 1;
        $args['offset'] = 0;
        // Get one row, then count via sum of per-source counts (cheap; no extra round-trip).
        $cfg = is_array( $args['source'] ) ? $args['source'] : array();
        $sources = isset( $cfg['sources'] ) && is_array( $cfg['sources'] ) ? $cfg['sources'] : array();
        $manual_ids = isset( $cfg['manual_video_ids'] ) && is_array( $cfg['manual_video_ids'] ) ? $cfg['manual_video_ids'] : array();
        $exclude_ids = isset( $cfg['exclude_video_ids'] ) && is_array( $cfg['exclude_video_ids'] ) ? $cfg['exclude_video_ids'] : array();

        $seen = array();
        foreach ( $sources as $entry ) {
            if ( ! is_array( $entry ) || empty( $entry['source_uuid'] ) ) {
                continue;
            }
            $rows = $this->videos_for_source( array(
                'source_uuid'         => (string) $entry['source_uuid'],
                'content_type'        => (string) ( $args['content_type'] ?? '' ),
                'include_unavailable' => (bool) ( $args['include_unavailable'] ?? false ),
                'include_hidden'      => (bool) ( $args['include_hidden'] ?? false ),
                'limit'               => 200,
                'offset'              => 0,
            ) );
            foreach ( $rows as $row ) {
                $vid = (string) ( $row['youtube_video_id'] ?? '' );
                if ( '' !== $vid ) {
                    $seen[ $vid ] = true;
                }
            }
        }
        if ( ! empty( $args['include_manual'] ) && ! empty( $manual_ids ) ) {
            $manual_rows = $this->videos_for_ids( $manual_ids, array(
                'include_unavailable' => (bool) ( $args['include_unavailable'] ?? false ),
                'include_hidden'      => (bool) ( $args['include_hidden'] ?? false ),
            ) );
            foreach ( $manual_rows as $row ) {
                $vid = (string) ( $row['youtube_video_id'] ?? '' );
                if ( '' !== $vid ) {
                    $seen[ $vid ] = true;
                }
            }
        }
        if ( ! empty( $exclude_ids ) ) {
            foreach ( $exclude_ids as $vid ) {
                unset( $seen[ (string) $vid ] );
            }
        }
        return count( $seen );
    }

    /**
     * Look up specific YouTube videos by ID. Used for manual_video_ids.
     *
     * @param array<int,string> $ids
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    private function videos_for_ids( array $ids, array $filters ): array {
        global $wpdb;
        $ids = array_values( array_unique( array_filter( array_map( 'strval', $ids ) ) ) );
        if ( empty( $ids ) ) {
            return array();
        }
        $table = $wpdb->prefix . 'vyg_videos';
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
        $params = $ids;
        $where = "WHERE v.youtube_video_id IN ($placeholders)";
        if ( empty( $filters['include_unavailable'] ) ) {
            $where .= " AND v.availability_status = 'available'";
        }
        if ( empty( $filters['include_hidden'] ) ) {
            $where .= ' AND v.is_hidden = 0';
        }
        $sql = "SELECT v.id, v.youtube_video_id, v.youtube_channel_id, v.title, v.duration_seconds,
                       v.duration_iso, v.thumbnail_default, v.thumbnail_medium, v.thumbnail_high,
                       v.thumbnail_standard, v.thumbnail_maxres, v.content_type, v.live_status,
                       v.availability_status, v.published_at, v.view_count, v.actual_start_at,
                       v.actual_end_at, v.scheduled_start_at
                FROM {$table} v {$where}
                ORDER BY v.published_at DESC
                LIMIT 200";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Dedupe video rows by youtube_video_id, preserving first occurrence.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function dedupe_videos( array $rows ): array {
        $seen = array();
        $out  = array();
        foreach ( $rows as $row ) {
            $vid = (string) ( $row['youtube_video_id'] ?? '' );
            if ( '' === $vid || isset( $seen[ $vid ] ) ) {
                continue;
            }
            $seen[ $vid ] = true;
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Sort video rows by orderby + order.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private function sort_videos( array $rows, string $orderby, string $order ): array {
        $allowed = array( 'published_at', 'view_count', 'created_at', 'duration_seconds' );
        $orderby = in_array( $orderby, $allowed, true ) ? $orderby : 'published_at';
        $order   = strtoupper( $order ) === 'ASC' ? 'ASC' : 'DESC';
        usort( $rows, static function ( array $a, array $b ) use ( $orderby, $order ): int {
            $av = (string) ( $a[ $orderby ] ?? '' );
            $bv = (string) ( $b[ $orderby ] ?? '' );
            if ( $av === $bv ) {
                return 0;
            }
            $cmp = strcmp( $av, $bv );
            return 'ASC' === $order ? $cmp : -$cmp;
        } );
        return $rows;
    }
}