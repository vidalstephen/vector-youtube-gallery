<?php
/**
 * Live classifier — decides whether a video is live/upcoming/replay/none.
 *
 * Output content_type values:
 *   - 'live_active'   Currently streaming or pre-stream waiting room.
 *   - 'live_upcoming' Scheduled to start; not yet started.
 *   - 'live_replay'   Was a live broadcast that has ended.
 *   - 'standard'      Not a live stream.
 *
 * Companion `live_status` output (one of: live|upcoming|ended|none) is also
 * returned via classify_full().
 *
 * @package VectorYT\Gallery\Normalize
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Normalize;

defined( 'ABSPATH' ) || exit;

final class LiveClassifier {

    public const CONTENT_LIVE_ACTIVE   = 'live_active';
    public const CONTENT_LIVE_UPCOMING = 'live_upcoming';
    public const CONTENT_LIVE_REPLAY   = 'live_replay';
    public const CONTENT_STANDARD      = 'standard';

    public const STATUS_LIVE           = 'live';
    public const STATUS_UPCOMING       = 'upcoming';
    public const STATUS_ENDED          = 'ended';
    public const STATUS_NONE           = 'none';

    /**
     * Return content_type only.
     *
     * @param array<string,mixed> $api_resource
     * @return string One of CONTENT_*.
     */
    public function classify( array $api_resource ): string {
        $live_bc = (string) ( $api_resource['snippet']['liveBroadcastContent'] ?? 'none' );
        $live    = (array) ( $api_resource['liveStreamingDetails'] ?? array() );

        $has_actual_start = ! empty( $live['actualStartTime'] );
        $has_actual_end   = ! empty( $live['actualEndTime'] );
        $has_scheduled    = ! empty( $live['scheduledStartTime'] );

        // Active: stream is on the air right now (no end yet).
        if ( 'live' === $live_bc ) {
            return self::CONTENT_LIVE_ACTIVE;
        }
        if ( $has_actual_start && ! $has_actual_end ) {
            return self::CONTENT_LIVE_ACTIVE;
        }

        // Upcoming: scheduled but not yet started.
        if ( 'upcoming' === $live_bc ) {
            return self::CONTENT_LIVE_UPCOMING;
        }
        if ( $has_scheduled && ! $has_actual_start ) {
            return self::CONTENT_LIVE_UPCOMING;
        }

        // Replay: was live, has both start and end.
        if ( $has_actual_start && $has_actual_end ) {
            return self::CONTENT_LIVE_REPLAY;
        }

        return self::CONTENT_STANDARD;
    }

    /**
     * Return both content_type and live_status.
     *
     * @param array<string,mixed> $api_resource
     * @return array{content_type:string,live_status:string}
     */
    public function classify_full( array $api_resource ): array {
        $live_bc = (string) ( $api_resource['snippet']['liveBroadcastContent'] ?? 'none' );
        $live    = (array) ( $api_resource['liveStreamingDetails'] ?? array() );

        $has_actual_start = ! empty( $live['actualStartTime'] );
        $has_actual_end   = ! empty( $live['actualEndTime'] );
        $has_scheduled    = ! empty( $live['scheduledStartTime'] );

        if ( $has_actual_start && ! $has_actual_end ) {
            return array( 'content_type' => self::CONTENT_LIVE_ACTIVE, 'live_status' => self::STATUS_LIVE );
        }
        if ( 'live' === $live_bc ) {
            return array( 'content_type' => self::CONTENT_LIVE_ACTIVE, 'live_status' => self::STATUS_LIVE );
        }
        if ( $has_scheduled && ! $has_actual_start ) {
            return array( 'content_type' => self::CONTENT_LIVE_UPCOMING, 'live_status' => self::STATUS_UPCOMING );
        }
        if ( 'upcoming' === $live_bc ) {
            return array( 'content_type' => self::CONTENT_LIVE_UPCOMING, 'live_status' => self::STATUS_UPCOMING );
        }
        if ( $has_actual_start && $has_actual_end ) {
            return array( 'content_type' => self::CONTENT_LIVE_REPLAY, 'live_status' => self::STATUS_ENDED );
        }
        return array( 'content_type' => self::CONTENT_STANDARD, 'live_status' => self::STATUS_NONE );
    }
}