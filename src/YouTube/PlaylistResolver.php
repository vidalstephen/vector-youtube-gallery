<?php
/**
 * Normalizer + resolver for YouTube playlist identifiers.
 *
 * Accepts:
 *   - bare playlist ID: PLxxxxxx (PL + 16 base64-ish chars; OR UU/LL/FL/OL prefixes for special)
 *   - full URL:         https://www.youtube.com/playlist?list=PL...
 *
 * Note: a "uploads playlist" (UU-prefix) is NOT passed to this resolver — it's
 * returned automatically by channels.list as contentDetails.relatedPlaylists.uploads
 * and stored on the source. Use this resolver only when the user adds a playlist
 * explicitly.
 *
 * @package VectorYT\Gallery\YouTube
 */

declare(strict_types=1);

namespace VectorYT\Gallery\YouTube;

use VectorYT\Gallery\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class PlaylistResolver {

    /**
     * Valid YouTube playlist ID prefixes per docs.
     *   PL = user-created
     *   UU = uploads (auto, returned by channel)
     *   LL = liked (auto, owner-only)
     *   FL = favorites (auto, owner-only)
     *   OL = music (older)
     */
    private const VALID_PREFIXES = array( 'PL', 'UU', 'LL', 'FL', 'OL', 'PU' );

    public function __construct(
        private readonly ApiClientInterface $api,
        private readonly Logger $logger,
    ) {}

    /**
     * Parse user input into a normalized playlist ID.
     *
     * @throws \InvalidArgumentException
     */
    public function classify_input( string $raw ): string {
        $raw = trim( $raw );
        if ( '' === $raw ) {
            throw new \InvalidArgumentException( 'Empty playlist identifier' );
        }

        // URL form: ?list=PL...
        if ( preg_match( '#^https?://(www\.)?youtube\.com/#i', $raw ) ) {
            if ( preg_match( '#[?&]list=([A-Za-z0-9_-]+)#', $raw, $m ) ) {
                return $this->validate_id( $m[1] );
            }
            throw new \InvalidArgumentException( 'YouTube URL did not contain a list parameter' );
        }

        // Bare playlist ID.
        return $this->validate_id( $raw );
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function validate_id( string $id ): string {
        $prefix = substr( $id, 0, 2 );
        if ( ! in_array( $prefix, self::VALID_PREFIXES, true ) ) {
            throw new \InvalidArgumentException(
                sprintf( 'Invalid playlist ID prefix "%s" (expected one of %s)', $prefix, implode( ',', self::VALID_PREFIXES ) )
            );
        }
        // Total length varies: PL + ~32 chars typical, but YouTube accepts shorter.
        if ( strlen( $id ) < 13 || strlen( $id ) > 64 ) {
            throw new \InvalidArgumentException(
                sprintf( 'Playlist ID "%s" has suspicious length (%d)', $id, strlen( $id ) )
            );
        }
        if ( ! preg_match( '#^[A-Za-z0-9_-]+$#', $id ) ) {
            throw new \InvalidArgumentException( 'Playlist ID contains invalid characters: ' . $id );
        }
        return $id;
    }

    /**
     * Resolve a playlist ID to its resource via playlists.list.
     *
     * @return array<string,mixed>
     * @throws ApiException
     * @throws \RuntimeException On zero matches.
     */
    public function resolve( string $playlist_id, array $parts = array( 'snippet', 'contentDetails', 'status' ) ): array {
        $response = $this->api->playlists_list( array(
            'part'       => implode( ',', $parts ),
            'id'         => $playlist_id,
            'maxResults' => 1,
        ) );

        $items = $response['items'] ?? array();
        if ( ! is_array( $items ) || 0 === count( $items ) ) {
            throw new \RuntimeException( 'Playlist not found: ' . $playlist_id );
        }

        $playlist = $items[0];
        $this->logger->info( 'Resolved playlist', array(
            'id'        => $playlist['id'] ?? '?',
            'title'     => $playlist['snippet']['title'] ?? '?',
            'item_count'=> $playlist['contentDetails']['itemCount'] ?? null,
        ) );
        return $playlist;
    }

    /**
     * @return array<string,mixed>
     */
    public function resolve_input( string $raw, array $parts = array( 'snippet', 'contentDetails', 'status' ) ): array {
        return $this->resolve( $this->classify_input( $raw ), $parts );
    }
}