<?php
/**
 * Deleted video detector — classifies a missing YouTube video into an availability_status.
 *
 * Called when videos.list returns no item for an ID we expected. We can't always
 * tell *why* a video is gone (YouTube doesn't expose a reason for missing IDs),
 * so we apply a heuristic ladder and log the decision.
 *
 * @package VectorYT\Gallery\Sync
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Sync;

defined( 'ABSPATH' ) || exit;

final class DeletedVideoDetector {

    /**
     * Best-effort classifier. Returns one of:
     *   deleted, private, embed_disabled, restricted, unknown
     */
    public function classify_missing( string $youtube_video_id ): string {
        // Phase 2 default: mark 'deleted' and let admin manually reclassify.
        // Phase 5 (live detection) will refine for live-status videos.
        // A real YouTube-side distinction would require OAuth + videos.list with extra parts.
        return 'deleted';
    }
}