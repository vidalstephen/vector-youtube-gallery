<?php
/**
 * Live status helper — extracts the *live_status* value from a YouTube
 * videos.list response.
 *
 * Distinct from LiveClassifier::classify() which determines content_type
 * (live_active/live_upcoming/live_replay/standard). This class determines
 * the *runtime* live_status field stored on vyg_videos.live_status, which
 * can be one of:
 *
 *   - 'live'      : actualStartTime set, actualEndTime NOT set.
 *   - 'upcoming'  : scheduledStartTime set, actualStartTime NOT set.
 *   - 'ended'     : actualStartTime AND actualEndTime set.
 *   - 'none'      : no liveBroadcastContent OR not a live broadcast.
 *
 * @package VectorYT\Gallery\Normalize
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Normalize;

defined( 'ABSPATH' ) || exit;

class LiveStatus {

    /**
     * Map a YouTube API response (liveStreamingDetails + status blocks) into
     * a runtime live_status string suitable for vyg_videos.live_status.
     *
     * @param array<string,mixed> $liveStreaming_details The `liveStreamingDetails` sub-resource.
     * @param array<string,mixed> $status                The `status` sub-resource (unused but kept
     *                                                   for future expansion, e.g. uploadStatus).
     * @return string One of 'live', 'upcoming', 'ended', 'none'.
     */
    public function classify_live_status( array $liveStreaming_details, array $status = array() ): string {
        $has_actual_start = ! empty( $liveStreaming_details['actualStartTime'] );
        $has_actual_end   = ! empty( $liveStreaming_details['actualEndTime'] );
        $has_scheduled    = ! empty( $liveStreaming_details['scheduledStartTime'] );

        if ( $has_actual_start && ! $has_actual_end ) {
            return 'live';
        }
        if ( $has_actual_start && $has_actual_end ) {
            return 'ended';
        }
        if ( ! $has_actual_start && $has_scheduled ) {
            return 'upcoming';
        }
        return 'none';
    }

    /**
     * Map live_status + has_liveStreamingDetails to content_type.
     *
     * This is what VideoNormalizer (Phase 3) would have produced originally
     * — kept here so LiveStatusPollJob can re-classify without depending
     * on the rest of the classifier stack.
     *
     * @return string One of 'live_active', 'live_upcoming', 'live_replay', 'standard'.
     */
    public function classify_content_type( array $liveStreaming_details, array $status = array() ): string {
        $live_status = $this->classify_live_status( $liveStreaming_details, $status );
        switch ( $live_status ) {
            case 'live':
                return 'live_active';
            case 'upcoming':
                return 'live_upcoming';
            case 'ended':
                return 'live_replay';
            default:
                return 'standard';
        }
    }
}