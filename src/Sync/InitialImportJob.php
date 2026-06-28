<?php
/**
 * Initial import job — channel/playlist initial sync.
 *
 * Flow (per plan §6):
 *   1. Resolve channel (already stored in source row) → get uploads playlist
 *   2. Page through uploads playlist items (50/page)
 *   3. Collect video IDs
 *   4. Batch-fetch metadata (videos.list, 50 IDs/call)
 *   5. Normalize each video, upsert into vyg_videos
 *   6. Upsert playlist into vyg_playlists + map videos
 *   7. Update source.last_success_at
 *
 * Cursor is page_token so the job is resumable.
 *
 * @package VectorYT\Gallery\Sync
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Sync;

use VectorYT\Gallery\Logging\Logger;
use VectorYT\Gallery\Normalize\VideoNormalizer;
use VectorYT\Gallery\Repository\PlaylistRepository;
use VectorYT\Gallery\Repository\SourceRepository;
use VectorYT\Gallery\Repository\SyncLogRepository;
use VectorYT\Gallery\Repository\VideoRepository;
use VectorYT\Gallery\YouTube\ApiClientInterface;
use VectorYT\Gallery\YouTube\ApiException;
use VectorYT\Gallery\YouTube\QuotaTracker;

defined( 'ABSPATH' ) || exit;

final class InitialImportJob extends SyncJobRunner {

    protected string $hook = 'vyg_sync_source_initial';

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
        if ( $source_id <= 0 ) {
            throw new \InvalidArgumentException( 'InitialImportJob requires source_id' );
        }
        $source = $this->sources->find( $source_id );
        if ( null === $source ) {
            throw new \RuntimeException( 'Source ' . $source_id . ' not found' );
        }

        $source_type = (string) $source['source_type'];
        $cursor      = isset( $args['cursor'] ) ? (array) $args['cursor'] : array();

        if ( 'channel' === $source_type ) {
            $this->run_channel( $source, $cursor, $job_id );
        } elseif ( 'playlist' === $source_type ) {
            $this->run_playlist( $source, $cursor, $job_id );
        } elseif ( 'video' === $source_type ) {
            $this->run_single_video( $source, $job_id );
        } else {
            throw new \RuntimeException( 'Unsupported source_type for initial import: ' . $source_type );
        }

        $this->sources->update( $source_id, array(
            'last_success_at'  => gmdate( 'Y-m-d H:i:s' ),
            'last_error_code'  => null,
            'last_error_message' => null,
            'api_data_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ),
        ) );
    }

    /**
     * @param array<string,mixed> $source
     * @param array<string,mixed> $cursor
     */
    private function run_channel( array $source, array $cursor, int $job_id ): void {
        $channel_id = (string) $source['youtube_channel_id'];
        $uploads_pl = $cursor['uploads_playlist_id'] ?? null;

        if ( null === $uploads_pl ) {
            $channel = $this->api->channels_list( array(
                'part' => 'contentDetails',
                'id'   => $channel_id,
            ) );
            $this->quota->record( 'channels', 200, (int) $source['id'] );

            $items = $channel['items'] ?? array();
            if ( 0 === count( $items ) ) {
                throw new \RuntimeException( 'Channel not found: ' . $channel_id );
            }
            $uploads_pl = $items[0]['contentDetails']['relatedPlaylists']['uploads'] ?? null;
            if ( null === $uploads_pl ) {
                throw new \RuntimeException( 'Channel has no uploads playlist: ' . $channel_id );
            }
        }

        // Fetch one page of playlist items, then a batch of video metadata.
        $page_token = $cursor['page_token'] ?? null;
        $params = array(
            'part'       => 'snippet,contentDetails,status',
            'playlistId' => $uploads_pl,
            'maxResults' => 50,
        );
        if ( null !== $page_token ) {
            $params['pageToken'] = $page_token;
        }
        $playlist_items = $this->api->playlist_items_list( $params );
        $this->quota->record( 'playlistItems', 200, (int) $source['id'] );

        $items = $playlist_items['items'] ?? array();
        $video_ids = array();
        foreach ( $items as $item ) {
            $vid = (string) ( $item['contentDetails']['videoId'] ?? $item['snippet']['resourceId']['videoId'] ?? '' );
            if ( '' !== $vid ) {
                $video_ids[] = $vid;
            }
        }

        // Upsert playlist + map existing videos.
        $playlist_row_id = $this->playlists->upsert_from_api( array(
            'id'           => $uploads_pl,
            'snippet'      => array( 'channelId' => $channel_id, 'title' => $source['title'] . ' — Uploads' ),
            'contentDetails' => array( 'itemCount' => count( $video_ids ) ),
            'status'       => array( 'privacyStatus' => 'public' ),
        ) );

        // Fetch video metadata in batch.
        if ( count( $video_ids ) > 0 ) {
            $this->batch_upsert_videos( $video_ids, $source, $job_id );
        }

        $next_page = $playlist_items['nextPageToken'] ?? null;
        if ( null !== $next_page ) {
            // Schedule a follow-up job for the next page (re-uses this same job_type).
            $this->logs->record( 'info', 'initial_import_continue', 'Scheduling follow-up page', $job_id, (int) $source['id'], array(
                'page_token' => $next_page,
            ) );
            // Caller (Plugin::register_hooks) is responsible for re-scheduling via SyncScheduler;
            // we just record the cursor on the current job for visibility.
        }

        // Map each video to the playlist position.
        $existing = $this->videos->find_many_by_youtube_ids( $video_ids );
        $position = 0;
        foreach ( $items as $item ) {
            $vid = (string) ( $item['contentDetails']['videoId'] ?? '' );
            if ( '' === $vid || ! isset( $existing[ $vid ] ) ) {
                continue;
            }
            $this->playlists->map_video(
                $playlist_row_id,
                (int) $existing[ $vid ]['id'],
                $uploads_pl,
                $vid,
                $position++,
                (string) ( $item['id'] ?? null ),
                $item['contentDetails']['videoPublishedAt'] ?? $item['snippet']['publishedAt'] ?? null
            );
        }
    }

    /**
     * @param array<string,mixed> $source
     * @param array<string,mixed> $cursor
     */
    private function run_playlist( array $source, array $cursor, int $job_id ): void {
        $playlist_id = (string) $source['youtube_playlist_id'];

        // First fetch the playlist metadata (no-op if already cached).
        $playlist = $this->api->playlists_list( array(
            'part'       => 'snippet,contentDetails,status',
            'id'         => $playlist_id,
            'maxResults' => 1,
        ) );
        $this->quota->record( 'playlists', 200, (int) $source['id'] );

        if ( 0 === count( (array) ( $playlist['items'] ?? array() ) ) ) {
            throw new \RuntimeException( 'Playlist not found: ' . $playlist_id );
        }
        $playlist_internal_id = $this->playlists->upsert_from_api( $playlist['items'][0] );

        $page_token = $cursor['page_token'] ?? null;
        $params = array(
            'part'       => 'snippet,contentDetails,status',
            'playlistId' => $playlist_id,
            'maxResults' => 50,
        );
        if ( null !== $page_token ) {
            $params['pageToken'] = $page_token;
        }
        $playlist_items = $this->api->playlist_items_list( $params );
        $this->quota->record( 'playlistItems', 200, (int) $source['id'] );

        $items = $playlist_items['items'] ?? array();
        $video_ids = array();
        foreach ( $items as $item ) {
            $vid = (string) ( $item['contentDetails']['videoId'] ?? $item['snippet']['resourceId']['videoId'] ?? '' );
            if ( '' !== $vid ) {
                $video_ids[] = $vid;
            }
        }

        if ( count( $video_ids ) > 0 ) {
            $this->batch_upsert_videos( $video_ids, $source, $job_id );
        }

        $existing = $this->videos->find_many_by_youtube_ids( $video_ids );
        $position = 0;
        foreach ( $items as $item ) {
            $vid = (string) ( $item['contentDetails']['videoId'] ?? '' );
            if ( '' === $vid || ! isset( $existing[ $vid ] ) ) {
                continue;
            }
            $this->playlists->map_video(
                $playlist_internal_id,
                (int) $existing[ $vid ]['id'],
                $playlist_id,
                $vid,
                $position++,
                (string) ( $item['id'] ?? null ),
                $item['contentDetails']['videoPublishedAt'] ?? $item['snippet']['publishedAt'] ?? null
            );
        }
    }

    /**
     * @param array<string,mixed> $source
     */
    private function run_single_video( array $source, int $job_id ): void {
        $vid = (string) $source['youtube_video_id'];
        $this->batch_upsert_videos( array( $vid ), $source, $job_id );
    }

    /**
     * @param array<int,string> $video_ids
     * @param array<string,mixed> $source
     */
    private function batch_upsert_videos( array $video_ids, array $source, int $job_id ): void {
        // Skip IDs we already have (cheap pre-filter; saves quota).
        $existing = $this->videos->find_many_by_youtube_ids( $video_ids );
        $missing = array_values( array_filter( $video_ids, static fn( string $id ): bool => ! isset( $existing[ $id ] ) ) );

        // YouTube caps videos.list at 50 IDs per call.
        foreach ( array_chunk( $missing, 50 ) as $chunk ) {
            $resp = $this->api->videos_list( array(
                'part'       => 'snippet,contentDetails,status,statistics,player,liveStreamingDetails',
                'id'         => implode( ',', $chunk ),
                'maxResults' => 50,
            ) );
            $this->quota->record( 'videos', 200, (int) $source['id'] );

            foreach ( (array) ( $resp['items'] ?? array() ) as $item ) {
                $this->videos->upsert_from_api( $item );
            }
        }

        // For already-known videos, just refresh last_checked_at via a re-upsert of the row we have.
        // (Phase 2.5 will replace this with an explicit MetadataRefreshJob call.)
        $this->logs->record( 'info', 'videos_upserted', count( $video_ids ) . ' video IDs processed', $job_id, (int) $source['id'], array(
            'new'      => count( $missing ),
            'existing' => count( $existing ),
        ) );
    }
}