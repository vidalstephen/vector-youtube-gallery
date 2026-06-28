<?php
/**
 * Normalizer + resolver for YouTube channel identifiers.
 *
 * Accepts any of:
 *   - bare channel ID:    UC_x5XG1OV2P6uZZ5FSM9Ttw
 *   - @handle:            @GoogleDevelopers  (with or without @)
 *   - legacy username:    GoogleDevelopers
 *   - full URL:           https://www.youtube.com/channel/UC_x5XG1OV2P6uZZ5FSM9Ttw
 *                        https://www.youtube.com/@GoogleDevelopers
 *                        https://www.youtube.com/user/GoogleDevelopers
 *                        https://www.youtube.com/c/GoogleDevelopers
 *
 * Output: a normalized struct the channel sync job can consume:
 *   - id:           channel ID (UC...) — resolved from handle/username via API
 *   - handle:       handle (no @) or null
 *   - username:     legacy username or null
 *   - uploads_playlist_id: 'UU...' — from contentDetails.relatedPlaylists.uploads
 *
 * @package VectorYT\Gallery\YouTube
 */

declare(strict_types=1);

namespace VectorYT\Gallery\YouTube;

use VectorYT\Gallery\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class ChannelResolver {

    public function __construct(
        private readonly ApiClientInterface $api,
        private readonly Logger $logger,
    ) {}

    /**
     * Normalize user input to one of three forms: ['id' => 'UC...'] | ['handle' => 'foo'] | ['username' => 'foo'].
     *
     * @return array{id?:string,handle?:string,username?:string}
     * @throws \InvalidArgumentException On unparseable input.
     */
    public function classify_input( string $raw ): array {
        $raw = trim( $raw );
        if ( '' === $raw ) {
            throw new \InvalidArgumentException( 'Empty channel identifier' );
        }

        // URL forms — pull out the tail.
        if ( preg_match( '#^https?://(www\.)?youtube\.com/#i', $raw ) ) {
            // /channel/UC...
            if ( preg_match( '#/channel/(UC[A-Za-z0-9_-]{22})#', $raw, $m ) ) {
                return array( 'id' => $m[1] );
            }
            // /@handle
            if ( preg_match( '#/@([A-Za-z0-9._-]{3,30})#', $raw, $m ) ) {
                return array( 'handle' => $m[1] );
            }
            // /user/username or /c/customname
            if ( preg_match( '#/(user|c)/([A-Za-z0-9._-]{3,30})#', $raw, $m ) ) {
                return array( 'username' => $m[2] );
            }
            throw new \InvalidArgumentException( 'Unrecognized YouTube channel URL: ' . $raw );
        }

        // Bare @handle.
        if ( str_starts_with( $raw, '@' ) ) {
            $handle = substr( $raw, 1 );
            $handle = trim( $handle );
            if ( '' === $handle || ! preg_match( '#^[A-Za-z0-9._-]{3,30}$#', $handle ) ) {
                throw new \InvalidArgumentException( 'Invalid handle: ' . $raw );
            }
            return array( 'handle' => $handle );
        }

        // Bare channel ID (UC...).
        if ( preg_match( '#^UC[A-Za-z0-9_-]{22}$#', $raw ) ) {
            return array( 'id' => $raw );
        }

        // Bare legacy username (fallback — try as username first, can also resolve as handle).
        if ( preg_match( '#^[A-Za-z0-9._-]{3,30}$#', $raw ) ) {
            // Try handle first (modern), fall back to username in resolve().
            return array( 'handle' => $raw );
        }

        throw new \InvalidArgumentException( 'Cannot parse channel identifier: ' . $raw );
    }

    /**
     * Resolve a normalized input to a full channel record via YouTube API.
     *
     * @param array{id?:string,handle?:string,username?:string} $classified
     * @return array<string,mixed> Channel resource (snippet, contentDetails, etc.)
     * @throws ApiException On API errors.
     * @throws \RuntimeException If API returns zero matches.
     */
    public function resolve( array $classified, array $parts = array( 'snippet', 'contentDetails', 'status' ) ): array {
        $part_str = implode( ',', $parts );
        $params   = array( 'part' => $part_str, 'maxResults' => 1 );

        if ( isset( $classified['id'] ) ) {
            $params['id'] = $classified['id'];
        } elseif ( isset( $classified['handle'] ) ) {
            // YouTube API: forHandle accepts the value with or without '@'.
            $params['forHandle'] = '@' . $classified['handle'];
        } elseif ( isset( $classified['username'] ) ) {
            $params['forUsername'] = $classified['username'];
        } else {
            throw new \InvalidArgumentException( 'classify_input() output missing id/handle/username' );
        }

        $response = $this->api->channels_list( $params );

        $items = $response['items'] ?? array();
        if ( ! is_array( $items ) || 0 === count( $items ) ) {
            throw new \RuntimeException(
                sprintf( 'YouTube returned no channels for params: %s', wp_json_encode( $params ) )
            );
        }

        $channel = $items[0];

        // Promote handle from snippet.customUrl if forHandle wasn't set.
        if ( ! isset( $classified['handle'] ) && isset( $channel['snippet']['customUrl'] ) ) {
            $classified['handle'] = ltrim( (string) $channel['snippet']['customUrl'], '@' );
        }

        $this->logger->info( 'Resolved channel', array(
            'id'             => $channel['id'] ?? '?',
            'title'          => $channel['snippet']['title'] ?? '?',
            'uploads_pl_id'  => $channel['contentDetails']['relatedPlaylists']['uploads'] ?? null,
        ) );

        return $channel;
    }

    /**
     * Convenience: parse + resolve in one shot. Returns the channel resource.
     *
     * @return array<string,mixed>
     */
    public function resolve_input( string $raw, array $parts = array( 'snippet', 'contentDetails', 'status' ) ): array {
        return $this->resolve( $this->classify_input( $raw ), $parts );
    }
}