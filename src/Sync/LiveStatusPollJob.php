<?php
/**
 * LiveStatusPollJob — Phase 5.
 *
 * Recurring job (default every 5 min) that refreshes live broadcast metadata
 * for currently-active and upcoming live streams tracked by the plugin.
 *
 * For each video with content_type in (live_active, live_upcoming):
 *   1. Fetch latest metadata from YouTube via videos.list.
 *   2. Update the row's live_status, actual_start_at, actual_end_at,
 *      scheduled_start_at, concurrent_viewers, last_live_poll_at.
 *   3. If the stream ended (live_status flipped from active to ended),
 *      promote the row to vyg_previous_streams and prune to limit.
 *
 * The job logs progress via SyncLogRepository so admin can audit.
 *
 * @package VectorYT\Gallery\Sync
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Sync;

use VectorYT\Gallery\Logging\Logger;
use VectorYT\Gallery\NormalizedClassification;
use VectorYT\Gallery\Repository\PreviousStreamsRepository;
use VectorYT\Gallery\Repository\SyncLogRepository;
use VectorYT\Gallery\Repository\VideoRepository;
use VectorYT\Gallery\Settings\SettingsRepository;
use VectorYT\Gallery\Sync\SyncJobRunner;
use VectorYT\Gallery\YouTube\ApiClientInterface;
use VectorYT\Gallery\YouTube\ApiException;
use VectorYT\Gallery\YouTube\QuotaTracker;

defined( 'ABSPATH' ) || exit;

final class LiveStatusPollJob {

    public const HOOK = 'vyg_live_status_poll';

    /**
     * Live content_types that need polling.
     *
     * @var array<int,string>
     */
    public const WATCHED_STATUSES = array( 'live_active', 'live_upcoming' );

    public function __construct(
        private readonly ApiClientInterface $api,
        private readonly VideoRepository $videos,
        private readonly PreviousStreamsRepository $previous,
        private readonly SyncLogRepository $logs,
        private readonly QuotaTracker $quota,
        private readonly Logger $logger,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * SyncJobRunner hook — invoked from WP-Cron.
     *
     * @param array<string,mixed> $args
     */
    public function handle( array $args = array() ): void {
        $job_id = isset( $args['vyg_job_id'] ) ? (int) $args['vyg_job_id'] : 0;
        if ( $job_id <= 0 ) {
            // No job row — run as a one-shot, still logged.
            $this->run_poll();
            return;
        }
        $runner = new SyncJobRunner( $this->logs, $this->logger );
        $runner->run(
            $job_id,
            array(
                'started'  => function () {},
                'complete' => function () {},
            ),
            array( $this, 'run_poll' ),
            'live_status_poll'
        );
    }

    /**
     * The actual poll logic — extractable for direct invocation from tests.
     *
     * Returns a small stats array: { checked, updated, ended, errors }.
     */
    public function run_poll(): array {
        $stats = array(
            'checked' => 0,
            'updated' => 0,
            'ended'   => 0,
            'errors'  => 0,
        );

        $videos = $this->find_live_videos();
        if ( empty( $videos ) ) {
            $this->logger->info( 'LiveStatusPollJob: no live/upcoming videos to check.' );
            return $stats;
        }

        // Batch up to 50 per videos.list call.
        $batches = array_chunk( $videos, 50 );
        foreach ( $batches as $batch ) {
            $ids = array_map( static fn( array $v ): string => (string) $v['youtube_video_id'], $batch );
            try {
                $response = $this->api->videos_list(
                    array( 'id' => implode( ',', $ids ), 'part' => 'snippet,contentDetails,status,liveStreamingDetails,statistics' )
                );
            } catch ( ApiException $e ) {
                ++$stats['errors'];
                $this->logger->error( 'LiveStatusPollJob: API error', array(
                    'error_kind' => $e->kind(),
                    'message'    => $e->getMessage(),
                ) );
                continue;
            }
            $this->quota->record( 'videos.list', 200, null );
            $items = isset( $response['items'] ) && is_array( $response['items'] ) ? $response['items'] : array();
            $by_id = array();
            foreach ( $items as $item ) {
                if ( isset( $item['id'] ) ) {
                    $by_id[ (string) $item['id'] ] = $item;
                }
            }
            foreach ( $batch as $video ) {
                ++$stats['checked'];
                $youtube_id = (string) $video['youtube_video_id'];
                $item = $by_id[ $youtube_id ] ?? null;
                if ( null === $item ) {
                    // No longer in API response — could be deleted/private.
                    $this->update_missing( $video );
                    ++$stats['updated'];
                    continue;
                }
                $changes = $this->apply_updates( $video, $item );
                if ( $changes > 0 ) {
                    ++$stats['updated'];
                }
                if ( $this->just_ended( $video, $item ) ) {
                    $this->promote_to_previous_streams( $video, $item );
                    ++$stats['ended'];
                }
            }
        }
        return $stats;
    }

    /**
     * Return up to N rows for videos whose content_type is in WATCHED_STATUSES.
     *
     * @return array<int,array<string,mixed>>
     */
    public function find_live_videos( int $limit = 200 ): array {
        global $wpdb;
        $table    = $wpdb->prefix . 'vyg_videos';
        $statuses = self::WATCHED_STATUSES;
        $placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
        $params = array_merge( $statuses, array( $limit ) );
        $sql = "SELECT * FROM {$table} WHERE content_type IN ({$placeholders}) ORDER BY last_live_poll_at ASC LIMIT %d";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Apply fresh API data to a stored video row. Returns count of columns updated.
     */
    private function apply_updates( array $video, array $api_resource ): int {
        $details = $api_resource['liveStreamingDetails'] ?? array();
        $status  = $api_resource['status'] ?? array();
        $stats   = $api_resource['statistics'] ?? array();
        $snippet = $api_resource['snippet'] ?? array();
        $updates = array();
        $now     = current_time( 'mysql' );

        // liveStreamingDetails fields.
        if ( isset( $details['actualStartTime'] ) ) {
            $updates['actual_start_at'] = $this->iso_to_mysql( (string) $details['actualStartTime'] );
        }
        if ( isset( $details['actualEndTime'] ) ) {
            $updates['actual_end_at'] = $this->iso_to_mysql( (string) $details['actualEndTime'] );
        }
        if ( isset( $details['scheduledStartTime'] ) ) {
            $updates['scheduled_start_at'] = $this->iso_to_mysql( (string) $details['scheduledStartTime'] );
        }
        if ( array_key_exists( 'concurrentViewers', $details ) ) {
            $updates['concurrent_viewers'] = is_numeric( $details['concurrentViewers'] )
                ? (int) $details['concurrentViewers']
                : null;
        }

        // Status.
        $status_obj = new \VectorYT\Gallery\Normalize\LiveStatus();
        $new_live_status = $status_obj->classify_live_status( $details, $status );
        $updates['live_status'] = $new_live_status;
        // Also re-classify content_type if no manual override is set.
        if ( empty( $video['manual_content_type'] ) ) {
            $updates['content_type'] = $new_live_status;
        }

        // Statistics.
        if ( isset( $stats['viewCount'] ) ) {
            $updates['view_count'] = (int) $stats['viewCount'];
        }
        if ( isset( $stats['likeCount'] ) ) {
            $updates['like_count'] = (int) $stats['likeCount'];
        }

        // Snippet.
        if ( isset( $snippet['title'] ) ) {
            $updates['title'] = (string) $snippet['title'];
        }

        // Always update last_live_poll_at so the next poll cycles fairly.
        $updates['last_live_poll_at'] = $now;

        if ( empty( $updates ) ) {
            return 0;
        }
        $this->videos->update_by_id( (int) $video['id'], $updates );
        return count( $updates );
    }

    /**
     * Mark a video missing from API response as unavailable (deleted/private).
     */
    private function update_missing( array $video ): void {
        $this->videos->update_by_id( (int) $video['id'], array(
            'availability_status' => 'deleted',
            'live_status'         => 'none',
            'last_live_poll_at'   => current_time( 'mysql' ),
        ) );
    }

    /**
     * Detect the moment a stream ends: had actualStartTime but now also has actualEndTime.
     */
    private function just_ended( array $video, array $api_resource ): bool {
        $details = $api_resource['liveStreamingDetails'] ?? array();
        if ( empty( $details['actualEndTime'] ) ) {
            return false;
        }
        $previous_end = (string) ( $video['actual_end_at'] ?? '' );
        // If we already had an end time AND it equals what the API just returned, not "just ended".
        return '' === $previous_end || $previous_end !== $this->iso_to_mysql( (string) $details['actualEndTime'] );
    }

    /**
     * Promote an ended stream to vyg_previous_streams + prune.
     */
    private function promote_to_previous_streams( array $video, array $api_resource ): void {
        $stats = $api_resource['statistics'] ?? array();
        $snip  = $api_resource['snippet'] ?? array();
        $details = $api_resource['liveStreamingDetails'] ?? array();

        // Derive source_id by looking up which source contains this video.
        $source_id = $this->find_source_id_for_video( (string) $video['youtube_video_id'] );
        if ( $source_id <= 0 ) {
            return;
        }

        $started = $this->iso_to_mysql( (string) ( $details['actualStartTime'] ?? '' ) );
        $ended   = $this->iso_to_mysql( (string) ( $details['actualEndTime'] ?? '' ) );

        $duration = null;
        if ( $started && $ended ) {
            $a = strtotime( $started . ' UTC' );
            $b = strtotime( $ended . ' UTC' );
            if ( $a && $b && $b > $a ) {
                $duration = $b - $a;
            }
        }

        $this->previous->upsert( array(
            'source_id'               => $source_id,
            'youtube_video_id'        => (string) $video['youtube_video_id'],
            'title'                   => (string) ( $snip['title'] ?? $video['title'] ?? '' ),
            'thumbnail_default'       => (string) ( $video['thumbnail_default'] ?? '' ),
            'started_at'              => $started,
            'ended_at'                => $ended,
            'duration_seconds'        => $duration,
            'peak_concurrent_viewers' => isset( $details['concurrentViewers'] ) ? (int) $details['concurrentViewers'] : null,
            'view_count'              => isset( $stats['viewCount'] ) ? (int) $stats['viewCount'] : 0,
        ) );

        $limit = (int) $this->settings->get( 'live_previous_streams_retention', 50 );
        $this->previous->prune_to_limit( $source_id, $limit );
    }

    /**
     * Find source_id that contains this video — channel-based lookups use the
     * vyg_playlist_video_map, single-video sources use youtube_video_id.
     */
    private function find_source_id_for_video( string $youtube_video_id ): int {
        global $wpdb;
        $map_table    = $wpdb->prefix . 'vyg_playlist_video_map';
        $videos_table = $wpdb->prefix . 'vyg_videos';
        $sources      = $wpdb->prefix . 'vyg_sources';

        // Try via map first.
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT m.source_id FROM {$map_table} m
             INNER JOIN {$videos_table} v ON v.id = m.video_id
             WHERE v.youtube_video_id = %s LIMIT 1",
            $youtube_video_id
        ), ARRAY_A );
        if ( is_array( $row ) && isset( $row['source_id'] ) ) {
            return (int) $row['source_id'];
        }
        // Fallback: single-video source type.
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$sources} WHERE youtube_video_id = %s LIMIT 1",
            $youtube_video_id
        ), ARRAY_A );
        return is_array( $row ) && isset( $row['id'] ) ? (int) $row['id'] : 0;
    }

    private function iso_to_mysql( string $iso ): ?string {
        if ( '' === $iso ) {
            return null;
        }
        $iso = preg_replace( '/Z$/', '', $iso );
        $iso = preg_replace( '/[+-]\d{2}:\d{2}$/', '', $iso );
        $ts = strtotime( $iso );
        if ( false === $ts ) {
            return null;
        }
        return gmdate( 'Y-m-d H:i:s', $ts );
    }
}