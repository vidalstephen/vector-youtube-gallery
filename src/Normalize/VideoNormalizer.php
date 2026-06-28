<?php
/**
 * Video normalizer — converts a YouTube API video resource into the internal
 * schema columns for vyg_videos.
 *
 * Pure transformation: no DB writes, no API calls. Classification (live,
 * Shorts, availability) is delegated to dedicated classifier classes.
 *
 * @package VectorYT\Gallery\Normalize
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Normalize;

defined( 'ABSPATH' ) || exit;

final class VideoNormalizer {

    public function __construct(
        private readonly ShortsClassifier $shorts,
        private readonly LiveClassifier $live,
        private readonly AvailabilityClassifier $availability,
        private readonly int $shorts_max_duration = 60,
        private readonly int $short_candidate_max_duration = 180,
    ) {}

    /**
     * Build from defaults — useful in tests that don't need DI.
     */
    public static function with_defaults(): self {
        return new self(
            new ShortsClassifier(),
            new LiveClassifier(),
            new AvailabilityClassifier(),
        );
    }

    /**
     * @param array<string,mixed> $api_resource YouTube videos.list item.
     * @param array<string,mixed> $classification Optional: 'manual_content_type' override.
     * @param int|null $shorts_max  Override threshold for this call (settings override wins).
     * @param int|null $short_candidate_max  Same.
     * @return array<string,mixed> Column => value for vyg_videos INSERT/UPDATE.
     */
    public function normalize(
        array $api_resource,
        array $classification = array(),
        ?int $shorts_max = null,
        ?int $short_candidate_max = null,
    ): array {
        $snippet  = (array) ( $api_resource['snippet'] ?? array() );
        $details  = (array) ( $api_resource['contentDetails'] ?? array() );
        $status   = (array) ( $api_resource['status'] ?? array() );
        $stats    = (array) ( $api_resource['statistics'] ?? array() );
        $thumbs   = (array) ( $snippet['thumbnails'] ?? array() );
        $live     = (array) ( $api_resource['liveStreamingDetails'] ?? array() );

        $duration_iso = (string) ( $details['duration'] ?? '' );
        $duration_seconds = $this->parse_iso8601_duration_to_seconds( $duration_iso );

        // Live classification first — live always wins over Shorts/standard.
        $live_result = $this->live->classify_full( $api_resource );
        $live_content_type = $live_result['content_type'];
        $live_status = $live_result['live_status'];

        // If live, skip Shorts check.
        if ( self::is_live_content_type( $live_content_type ) ) {
            $content_type = $live_content_type;
        } else {
            $context = array_merge( $classification, array( 'duration_seconds' => $duration_seconds ) );
            $content_type = $this->shorts->classify(
                $api_resource,
                $context,
                $shorts_max ?? $this->shorts_max_duration,
                $short_candidate_max ?? $this->short_candidate_max_duration,
            );
        }

        // Manual override wins regardless of auto classification.
        // Accept both 'manual_content_type' (canonical) and 'content_type' (Phase 2 legacy).
        $manual = '';
        if ( isset( $classification['manual_content_type'] ) && '' !== (string) $classification['manual_content_type'] ) {
            $manual = (string) $classification['manual_content_type'];
        } elseif ( isset( $classification['content_type'] ) && '' !== (string) $classification['content_type'] ) {
            $manual = (string) $classification['content_type'];
        }
        if ( '' !== $manual && 'auto' !== $manual ) {
            $content_type = $this->shorts->normalize_manual( $manual );
        }

        $availability = $this->availability->classify( $api_resource );

        // Tags — only persist if present.
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
            'duration_seconds'    => $duration_seconds,
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
            'manual_content_type' => '' !== $manual ? sanitize_key( $manual ) : null,
        );

        return $row;
    }

    private static function is_live_content_type( string $content_type ): bool {
        return in_array( $content_type, array(
            LiveClassifier::CONTENT_LIVE_ACTIVE,
            LiveClassifier::CONTENT_LIVE_UPCOMING,
            LiveClassifier::CONTENT_LIVE_REPLAY,
        ), true );
    }

    public function parse_iso8601_duration_to_seconds( ?string $iso ): ?int {
        if ( null === $iso || '' === $iso ) {
            return null;
        }
        if ( ! preg_match( '/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', $iso, $m ) ) {
            return null;
        }
        $h  = isset( $m[1] ) ? (int) $m[1] : 0;
        $mi = isset( $m[2] ) ? (int) $m[2] : 0;
        $s  = isset( $m[3] ) ? (int) $m[3] : 0;
        return $h * 3600 + $mi * 60 + $s;
    }

    private function parse_mysql_datetime( string $iso ): ?string {
        if ( '' === $iso ) {
            return null;
        }
        $ts = strtotime( $iso );
        return false === $ts ? null : gmdate( 'Y-m-d H:i:s', $ts );
    }
}