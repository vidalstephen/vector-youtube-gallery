<?php
/**
 * Video normalizer — converts a YouTube API video resource into the internal
 * schema columns for vyg_videos.
 *
 * Pure transformation: no DB writes, no API calls. Classification (live,
 * Shorts, availability) is provided by the caller via the $classification
 * arg — Phase 3 will populate this; Phase 2 passes defaults from the
 * liveBroadcastContent + status fields directly.
 *
 * @package VectorYT\Gallery\Normalize
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Normalize;

defined( 'ABSPATH' ) || exit;

final class VideoNormalizer {

    /**
     * @param array<string,mixed> $api_resource YouTube videos.list item.
     * @param array<string,mixed> $classification Optional overrides (Phase 3).
     * @return array<string,mixed> Column => value for vyg_videos INSERT/UPDATE.
     */
    public function normalize( array $api_resource, array $classification = array() ): array {
        $snippet = (array) ( $api_resource['snippet'] ?? array() );
        $details = (array) ( $api_resource['contentDetails'] ?? array() );
        $status  = (array) ( $api_resource['status'] ?? array() );
        $stats   = (array) ( $api_resource['statistics'] ?? array() );
        $thumbs  = (array) ( $snippet['thumbnails'] ?? array() );
        $live    = (array) ( $api_resource['liveStreamingDetails'] ?? array() );

        $duration_iso = (string) ( $details['duration'] ?? '' );

        $live_bc = (string) ( $snippet['liveBroadcastContent'] ?? 'none' );
        $has_actual_start  = ! empty( $live['actualStartTime'] );
        $has_actual_end    = ! empty( $live['actualEndTime'] );
        $has_scheduled     = ! empty( $live['scheduledStartTime'] );

        // Content type — default to standard; live detection runs here as a
        // safe default. Phase 3 ShortsClassifier will refine.
        $content_type = $this->detect_content_type( $duration_iso, $live_bc, $has_actual_start, $has_actual_end, $has_scheduled, $classification );
        $live_status  = $this->detect_live_status( $live_bc, $has_actual_start, $has_actual_end, $has_scheduled );
        $availability = $this->detect_availability( $status );

        // Tags — only persist if present (per plan §13, limit sensitive storage).
        $tags = isset( $snippet['tags'] ) && is_array( $snippet['tags'] )
            ? wp_json_encode( array_values( $snippet['tags'] ) )
            : null;

        $row = array(
            'youtube_video_id'    => (string) ( $api_resource['id'] ?? '' ),
            'youtube_channel_id'  => sanitize_text_field( (string) ( $snippet['channelId'] ?? '' ) ),
            'title'               => sanitize_text_field( (string) ( $snippet['title'] ?? '' ) ),
            'description_excerpt' => sanitize_text_field( wp_trim_words( (string) ( $snippet['description'] ?? '' ), 50 ) ),
            'published_at'        => $this->parse_mysql_datetime( (string) ( $snippet['publishedAt'] ?? '' ) ),
            'duration_iso'        => $duration_iso,
            'duration_seconds'    => $this->parse_iso8601_duration_to_seconds( $duration_iso ),
            'thumbnail_default'   => esc_url_raw( (string) ( $thumbs['default']['url'] ?? '' ) ),
            'thumbnail_medium'    => esc_url_raw( (string) ( $thumbs['medium']['url'] ?? '' ) ),
            'thumbnail_high'      => esc_url_raw( (string) ( $thumbs['high']['url'] ?? '' ) ),
            'thumbnail_standard'  => esc_url_raw( (string) ( $thumbs['standard']['url'] ?? '' ) ),
            'thumbnail_maxres'    => esc_url_raw( (string) ( $thumbs['maxres']['url'] ?? '' ) ),
            'privacy_status'      => sanitize_key( (string) ( $status['privacyStatus'] ?? 'unknown' ) ),
            'upload_status'       => sanitize_key( (string) ( $status['uploadStatus'] ?? 'processed' ) ),
            'embeddable'          => ! empty( $status['embeddable'] ) ? 1 : 0,
            'availability_status' => $availability,
            'content_type'        => $content_type,
            'live_status'         => $live_status,
            'scheduled_start_at'  => $this->parse_mysql_datetime( (string) ( $live['scheduledStartTime'] ?? '' ) ),
            'actual_start_at'     => $this->parse_mysql_datetime( (string) ( $live['actualStartTime'] ?? '' ) ),
            'actual_end_at'       => $this->parse_mysql_datetime( (string) ( $live['actualEndTime'] ?? '' ) ),
            'view_count'          => isset( $stats['viewCount'] ) ? (int) $stats['viewCount'] : null,
            'comment_count'       => isset( $stats['commentCount'] ) ? (int) $stats['commentCount'] : null,
            'category_id'         => sanitize_text_field( (string) ( $snippet['categoryId'] ?? '' ) ),
            'tags_json'           => $tags,
            'raw_api_hash'        => substr( md5( wp_json_encode( $api_resource ) ), 0, 32 ),
            'last_checked_at'     => gmdate( 'Y-m-d H:i:s' ),
            'last_success_at'     => gmdate( 'Y-m-d H:i:s' ),
            'api_data_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ),
            'manual_content_type' => isset( $classification['content_type'] ) ? sanitize_key( (string) $classification['content_type'] ) : null,
        );

        // Manual override wins.
        if ( ! empty( $row['manual_content_type'] ) ) {
            $row['content_type'] = $row['manual_content_type'];
        }

        return $row;
    }

    private function detect_content_type(
        string $duration_iso,
        string $live_bc,
        bool $has_actual_start,
        bool $has_actual_end,
        bool $has_scheduled,
        array $classification,
    ): string {
        // Live classification overrides any duration check.
        if ( 'live' === $live_bc || ( $has_actual_start && ! $has_actual_end ) ) {
            return 'live_active';
        }
        if ( 'upcoming' === $live_bc || ( $has_scheduled && ! $has_actual_start ) ) {
            return 'live_upcoming';
        }
        if ( $has_actual_start && $has_actual_end ) {
            return 'live_replay';
        }

        // Phase 3 will refine Shorts classification (duration + tags + override).
        // For now: anything <= 180s is a candidate Short, but we leave content_type=standard
        // so feeds don't break. Phase 3 sets content_type=short_candidate.
        $seconds = $this->parse_iso8601_duration_to_seconds( $duration_iso );
        if ( null !== $seconds && $seconds <= 180 && $seconds > 0 ) {
            // Default to short_candidate; Phase 3 ShortsClassifier can upgrade
            // to short_confirmed_manual or downgrade to standard.
            return $classification['content_type'] ?? 'short_candidate';
        }

        return 'standard';
    }

    private function detect_live_status(
        string $live_bc,
        bool $has_actual_start,
        bool $has_actual_end,
        bool $has_scheduled,
    ): string {
        if ( $has_actual_start && ! $has_actual_end ) {
            return 'live';
        }
        if ( $has_actual_start && $has_actual_end ) {
            return 'ended';
        }
        if ( $has_scheduled ) {
            return 'upcoming';
        }
        return 'none';
    }

    private function detect_availability( array $status ): string {
        if ( isset( $status['uploadStatus'] ) && 'deleted' === $status['uploadStatus'] ) {
            return 'deleted';
        }
        $privacy = (string) ( $status['privacyStatus'] ?? '' );
        if ( 'private' === $privacy ) {
            return 'private';
        }
        if ( isset( $status['embeddable'] ) && $status['embeddable'] === false ) {
            return 'embed_disabled';
        }
        // 'restricted' is rare at the API level; treated as available unless flagged.
        return 'available';
    }

    /**
     * Parse ISO 8601 duration (PT#H#M#S) to seconds.
     */
    public function parse_iso8601_duration_to_seconds( ?string $iso ): ?int {
        if ( null === $iso || '' === $iso ) {
            return null;
        }
        if ( ! preg_match( '/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', $iso, $m ) ) {
            return null;
        }
        $h = isset( $m[1] ) ? (int) $m[1] : 0;
        $mi = isset( $m[2] ) ? (int) $m[2] : 0;
        $s = isset( $m[3] ) ? (int) $m[3] : 0;
        return $h * 3600 + $mi * 60 + $s;
    }

    /**
     * Parse an ISO 8601 datetime to MySQL DATETIME (UTC). Returns null on parse failure.
     */
    private function parse_mysql_datetime( string $iso ): ?string {
        if ( '' === $iso ) {
            return null;
        }
        $ts = strtotime( $iso );
        return false === $ts ? null : gmdate( 'Y-m-d H:i:s', $ts );
    }
}