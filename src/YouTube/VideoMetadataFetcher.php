<?php
/**
 * Fetcher for single-video resources.
 *
 * Used when the admin adds a specific video as a "single video" source.
 * Also used by sync jobs to fetch metadata in batches of up to 50 IDs.
 *
 * @package VectorYT\Gallery\YouTube
 */

declare(strict_types=1);

namespace VectorYT\Gallery\YouTube;

use VectorYT\Gallery\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class VideoMetadataFetcher {

    public function __construct(
        private readonly ApiClientInterface $api,
        private readonly Logger $logger,
    ) {}

    /**
     * Parse user input to a normalized YouTube video ID.
     * Accepts:
     *   - bare 11-char ID:        dQw4w9WgXcQ
     *   - URL:                    https://www.youtube.com/watch?v=dQw4w9WgXcQ
     *                            https://youtu.be/dQw4w9WgXcQ
     *                            https://www.youtube.com/shorts/dQw4w9WgXcQ
     *                            https://www.youtube.com/embed/dQw4w9WgXcQ
     *
     * @throws \InvalidArgumentException
     */
    public function classify_input( string $raw ): string {
        $raw = trim( $raw );
        if ( '' === $raw ) {
            throw new \InvalidArgumentException( 'Empty video identifier' );
        }

        // URL forms.
        if ( preg_match( '#^https?://(www\.)?youtube\.com/watch\?(.*&)?v=([A-Za-z0-9_-]{11})#', $raw, $m ) ) {
            return $m[3];
        }
        if ( preg_match( '#^https?://(www\.)?youtube\.com/(shorts|embed)/([A-Za-z0-9_-]{11})#', $raw, $m ) ) {
            return $m[3];
        }
        if ( preg_match( '#^https?://youtu\.be/([A-Za-z0-9_-]{11})#', $raw, $m ) ) {
            return $m[1];
        }

        // Bare ID.
        if ( preg_match( '#^[A-Za-z0-9_-]{11}$#', $raw ) ) {
            return $raw;
        }

        throw new \InvalidArgumentException( 'Cannot parse YouTube video identifier: ' . $raw );
    }

    /**
     * Fetch a single video's full resource.
     *
     * @return array<string,mixed>
     */
    public function fetch_one( string $video_id ): array {
        $response = $this->fetch_many( array( $video_id ) );
        $items    = $response['items'] ?? array();
        if ( ! is_array( $items ) || 0 === count( $items ) ) {
            throw new \RuntimeException( 'Video not found: ' . $video_id );
        }
        return $items[0];
    }

    /**
     * Fetch metadata for many video IDs in one request (max 50 per YouTube docs).
     *
     * @param array<int,string> $video_ids
     * @return array<string,mixed> API response with items[]
     */
    public function fetch_many( array $video_ids, array $parts = array(
        'snippet',
        'contentDetails',
        'status',
        'statistics',
        'player',
        'liveStreamingDetails',
    ) ): array {
        $video_ids = array_values( array_filter( array_map( 'trim', $video_ids ) ) );
        if ( 0 === count( $video_ids ) ) {
            return array( 'items' => array() );
        }
        if ( count( $video_ids ) > 50 ) {
            // Phase 2 sync engine will chunk across multiple calls; here we just guard.
            throw new \InvalidArgumentException( 'fetch_many() accepts at most 50 video IDs per call' );
        }

        $this->logger->info( 'Fetching video batch', array( 'count' => count( $video_ids ) ) );

        return $this->api->videos_list( array(
            'part'       => implode( ',', $parts ),
            'id'         => implode( ',', $video_ids ),
            'maxResults' => min( 50, count( $video_ids ) ),
        ) );
    }
}