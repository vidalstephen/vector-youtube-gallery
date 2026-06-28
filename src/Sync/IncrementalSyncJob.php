<?php
/**
 * Incremental sync — fetch only the first 1-3 pages and stop when known IDs are reached.
 *
 * Per plan §6:
 *   1. Fetch the first 1-3 pages of the uploads playlist.
 *   2. Stop early when already-known video IDs are reached.
 *   3. Insert new video IDs.
 *   4. Refresh metadata for new IDs.
 *
 * @package VectorYT\Gallery\Sync
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Sync;

use VectorYT\Gallery\Logging\Logger;
use VectorYT\Gallery\Repository\SourceRepository;
use VectorYT\Gallery\Repository\SyncLogRepository;
use VectorYT\Gallery\Repository\VideoRepository;
use VectorYT\Gallery\Repository\PlaylistRepository;
use VectorYT\Gallery\YouTube\ApiClientInterface;
use VectorYT\Gallery\YouTube\QuotaTracker;

defined( 'ABSPATH' ) || exit;

final class IncrementalSyncJob extends SyncJobRunner {

    protected string $hook = 'vyg_sync_source_incremental';

    public const MAX_PAGES = 3;

    public function __construct(
        SyncLogRepository $logs,
        RetryPolicy $retry,
        QuotaTracker $quota,
        Logger $logger,
        private readonly SourceRepository $sources,
        private readonly VideoRepository $videos,
        private readonly PlaylistRepository $playlists,
        private readonly ApiClientInterface $api,
    ) {
        parent::__construct( $logs, $retry, $quota, $logger );
    }

    protected function run( array $args, int $job_id ): void {
        $source_id = (int) ( $args['source_id'] ?? 0 );
        $source    = $this->sources->find( $source_id );
        if ( null === $source ) {
            throw new \RuntimeException( 'Source ' . $source_id . ' not found' );
        }
        if ( 'channel' !== $source['source_type'] && 'playlist' !== $source['source_type'] ) {
            $this->logs->record( 'info', 'incremental_skip', 'Source type ' . $source['source_type'] . ' not eligible', $job_id, $source_id );
            return;
        }

        // Resolve the playlist to walk.
        $playlist_id = 'channel' === $source['source_type']
            ? $this->resolve_uploads_playlist( $source )
            : (string) $source['youtube_playlist_id'];

        $existing_ids = $this->known_video_ids( $source_id );
        $new_ids      = array();
        $page_token   = null;
        $pages_walked = 0;

        for ( $i = 0; $i < self::MAX_PAGES; $i++ ) {
            $params = array(
                'part'       => 'contentDetails',
                'playlistId' => $playlist_id,
                'maxResults' => 50,
            );
            if ( null !== $page_token ) {
                $params['pageToken'] = $page_token;
            }
            $resp = $this->api->playlist_items_list( $params );
            $this->quota->record( 'playlistItems', 200, $source_id );

            $items = (array) ( $resp['items'] ?? array() );
            $pages_walked++;
            $stop = false;
            foreach ( $items as $item ) {
                $vid = (string) ( $item['contentDetails']['videoId'] ?? '' );
                if ( '' === $vid ) {
                    continue;
                }
                if ( isset( $existing_ids[ $vid ] ) ) {
                    // Already known — stop here (top of uploads playlist is newest).
                    $stop = true;
                    break;
                }
                $new_ids[] = $vid;
            }
            if ( $stop ) {
                break;
            }
            $page_token = $resp['nextPageToken'] ?? null;
            if ( null === $page_token ) {
                break;
            }
        }

        if ( count( $new_ids ) > 0 ) {
            foreach ( array_chunk( array_values( array_unique( $new_ids ) ), 50 ) as $chunk ) {
                $resp = $this->api->videos_list( array(
                    'part'       => 'snippet,contentDetails,status,statistics,liveStreamingDetails',
                    'id'         => implode( ',', $chunk ),
                    'maxResults' => 50,
                ) );
                $this->quota->record( 'videos', 200, $source_id );

                foreach ( (array) ( $resp['items'] ?? array() ) as $item ) {
                    $this->videos->upsert_from_api( $item );
                }
            }
        }

        $this->sources->update( $source_id, array(
            'last_sync_at'     => gmdate( 'Y-m-d H:i:s' ),
            'last_success_at'  => gmdate( 'Y-m-d H:i:s' ),
        ) );
        $this->logs->record( 'info', 'incremental_done', 'pages=' . $pages_walked . ' new=' . count( $new_ids ), $job_id, $source_id );
    }

    /**
     * @param array<string,mixed> $source
     */
    private function resolve_uploads_playlist( array $source ): string {
        $channel = $this->api->channels_list( array(
            'part' => 'contentDetails',
            'id'   => (string) $source['youtube_channel_id'],
        ) );
        $this->quota->record( 'channels', 200, (int) $source['id'] );

        $items = (array) ( $channel['items'] ?? array() );
        if ( 0 === count( $items ) ) {
            throw new \RuntimeException( 'Channel not found: ' . $source['youtube_channel_id'] );
        }
        $uploads = $items[0]['contentDetails']['relatedPlaylists']['uploads'] ?? null;
        if ( null === $uploads ) {
            throw new \RuntimeException( 'Channel has no uploads playlist' );
        }
        return $uploads;
    }

    /**
     * @return array<string,bool> Set of known YouTube video IDs for this source.
     */
    private function known_video_ids( int $source_id ): array {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT v.youtube_video_id
             FROM {$wpdb->prefix}vyg_videos v
             INNER JOIN {$wpdb->prefix}vyg_playlist_video_map m ON m.video_id = v.id
             WHERE m.youtube_playlist_id IN (
                 SELECT COALESCE(youtube_playlist_id, '') FROM {$wpdb->prefix}vyg_sources WHERE id = %d
             )
                OR v.youtube_channel_id IN (
                    SELECT COALESCE(youtube_channel_id, '') FROM {$wpdb->prefix}vyg_sources WHERE id = %d
                )",
            $source_id,
            $source_id
        );
        $rows = $wpdb->get_col( $sql );
        return array_fill_keys( (array) $rows, true );
    }
}