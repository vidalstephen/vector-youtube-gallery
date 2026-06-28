<?php
/**
 * Metadata refresh — re-fetch metadata for videos whose refresh interval has elapsed.
 *
 * Per plan §6:
 *   - New videos (< 48h old): refresh every 2-6 hours
 *   - Normal public videos: refresh every 1-7 days
 *   - Older archive videos: refresh every 14-30 days
 *   - Live upcoming: every 5-15 minutes (handled by LiveStatusPollJob in Phase 5)
 *   - Live active: every 1-5 minutes (Phase 5)
 *   - Live ended in last 24h: every 15-60 minutes
 *   - Deleted/private: exponential backoff, then mark unavailable
 *
 * Phase 2 implements: standard, short, live_replay, archive tiers.
 *
 * @package VectorYT\Gallery\Sync
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Sync;

use VectorYT\Gallery\Logging\Logger;
use VectorYT\Gallery\Repository\SyncLogRepository;
use VectorYT\Gallery\Repository\VideoRepository;
use VectorYT\Gallery\YouTube\ApiClientInterface;
use VectorYT\Gallery\YouTube\QuotaTracker;

defined( 'ABSPATH' ) || exit;

final class MetadataRefreshJob extends SyncJobRunner {

    protected string $hook = 'vyg_refresh_video_batch';

    public function __construct(
        SyncLogRepository $logs,
        RetryPolicy $retry,
        QuotaTracker $quota,
        Logger $logger,
        private readonly VideoRepository $videos,
        private readonly ApiClientInterface $api,
        private readonly DeletedVideoDetector $deleted_detector,
    ) {
        parent::__construct( $logs, $retry, $quota, $logger );
    }

    protected function run( array $args, int $job_id ): void {
        $max_videos = (int) ( $args['max_videos'] ?? 100 );
        $max_videos = max( 1, min( 200, $max_videos ) );

        $batches = $this->pick_batches( $max_videos );

        $refreshed = 0;
        $marked_unavailable = 0;
        foreach ( $batches as $tier => $videos ) {
            if ( 0 === count( $videos ) ) {
                continue;
            }
            $this->logs->record( 'info', 'refresh_tier', 'tier=' . $tier . ' count=' . count( $videos ), $job_id, null, array(
                'tier'  => $tier,
                'count' => count( $videos ),
            ) );

            $ids = array_map( static fn( array $v ): string => (string) $v['youtube_video_id'], $videos );
            foreach ( array_chunk( $ids, 50 ) as $chunk ) {
                $resp = $this->api->videos_list( array(
                    'part'       => 'snippet,contentDetails,status,statistics,liveStreamingDetails',
                    'id'         => implode( ',', $chunk ),
                    'maxResults' => 50,
                ) );
                $this->quota->record( 'videos', 200 );

                $returned = array();
                foreach ( (array) ( $resp['items'] ?? array() ) as $item ) {
                    $this->videos->upsert_from_api( $item );
                    $returned[ (string) $item['id'] ] = true;
                    $refreshed++;
                }
                // Anything in our chunk but NOT returned by YouTube is deleted/private.
                foreach ( $chunk as $vid ) {
                    if ( ! isset( $returned[ $vid ] ) ) {
                        $reason = $this->deleted_detector->classify_missing( $vid );
                        $this->videos->mark_unavailable( $vid, $reason );
                        $marked_unavailable++;
                    }
                }
            }
        }

        $this->logs->record( 'info', 'refresh_done', 'refreshed=' . $refreshed . ' unavailable=' . $marked_unavailable, $job_id );
    }

    /**
     * Bucket videos by refresh tier; cap total at $max.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function pick_batches( int $max ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'vyg_videos';
        $now = current_time( 'mysql', true ); // GMT

        // New (< 48h): every 2-6h
        $new = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, youtube_video_id, published_at, last_success_at, content_type, live_status
             FROM {$table}
             WHERE published_at >= DATE_SUB(%s, INTERVAL 48 HOUR)
               AND (last_success_at IS NULL OR last_success_at <= DATE_SUB(%s, INTERVAL 2 HOUR))
             ORDER BY published_at DESC LIMIT %d",
            $now, $now, $max
        ), ARRAY_A );

        // Recently-ended live (last 24h): every 15-60m
        $recently_ended = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, youtube_video_id, published_at, last_success_at, content_type, live_status
             FROM {$table}
             WHERE actual_end_at >= DATE_SUB(%s, INTERVAL 24 HOUR)
               AND live_status = 'ended'
               AND (last_success_at IS NULL OR last_success_at <= DATE_SUB(%s, INTERVAL 15 MINUTE))
             ORDER BY actual_end_at DESC LIMIT %d",
            $now, $now, $max
        ), ARRAY_A );

        // Normal public (1-7 days)
        $normal = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, youtube_video_id, published_at, last_success_at, content_type, live_status
             FROM {$table}
             WHERE content_type IN ('standard','short_candidate')
               AND published_at < DATE_SUB(%s, INTERVAL 48 HOUR)
               AND (last_success_at IS NULL OR last_success_at <= DATE_SUB(%s, INTERVAL 1 DAY))
             ORDER BY last_success_at ASC LIMIT %d",
            $now, $now, $max
        ), ARRAY_A );

        // Archive (14-30 days)
        $archive = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, youtube_video_id, published_at, last_success_at, content_type, live_status
             FROM {$table}
             WHERE content_type = 'standard'
               AND published_at < DATE_SUB(%s, INTERVAL 30 DAY)
               AND (last_success_at IS NULL OR last_success_at <= DATE_SUB(%s, INTERVAL 14 DAY))
             ORDER BY last_success_at ASC LIMIT %d",
            $now, $now, $max
        ), ARRAY_A );

        return array(
            'new'             => is_array( $new ) ? $new : array(),
            'recently_ended'  => is_array( $recently_ended ) ? $recently_ended : array(),
            'normal'          => is_array( $normal ) ? $normal : array(),
            'archive'         => is_array( $archive ) ? $archive : array(),
        );
    }
}