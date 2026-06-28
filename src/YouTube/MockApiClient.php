<?php
/**
 * Mock YouTube API client — dev/test only.
 *
 * Returns deterministic responses from fixtures stored in tests/fixtures/.
 * Activated when VYG_USE_MOCK env var is true OR when no API key is set.
 *
 * Usage from dev:
 *   export VYG_USE_MOCK=1
 *   docker compose --env-file dev/.env up -d
 *
 * Fixture file naming convention:
 *   tests/fixtures/<endpoint>__<query_hash>.json
 * where query_hash = substr(md5(ksort($params)), 0, 8)
 *
 * If no fixture matches, throws ApiException(KIND_NOT_FOUND) so tests fail loudly.
 *
 * @package VectorYT\Gallery\YouTube
 */

declare(strict_types=1);

namespace VectorYT\Gallery\YouTube;

use VectorYT\Gallery\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class MockApiClient implements ApiClientInterface {

    /**
     * @param array<string,callable(array<string,mixed>):array<string,mixed>> $handlers
     *   Optional in-memory handler map keyed by endpoint ("channels", "videos", etc.).
     *   Each handler receives the params array and returns the response body.
     *   If set, these take precedence over fixture files.
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly string $fixtures_dir,
        private array $handlers = array(),
    ) {}

    public function mode(): string {
        return 'mock';
    }

    public function channels_list( array $params ): array {
        return $this->call( 'channels', $params );
    }

    public function playlists_list( array $params ): array {
        return $this->call( 'playlists', $params );
    }

    public function playlist_items_list( array $params ): array {
        return $this->call( 'playlistItems', $params );
    }

    public function videos_list( array $params ): array {
        return $this->call( 'videos', $params );
    }

    public function revoke_token( string $token ): bool {
        return true;
    }

    /**
     * Register an in-memory handler for a given endpoint. Used by tests.
     *
     * @param callable(array<string,mixed>):array<string,mixed> $handler
     */
    public function register_handler( string $endpoint, callable $handler ): void {
        $this->handlers[ $endpoint ] = $handler;
    }

    /**
     * @param array<string,string|int> $params
     * @return array<string,mixed>
     */
    private function call( string $endpoint, array $params ): array {
        $this->logger->info( 'Mock API ' . $endpoint, array( 'endpoint' => $endpoint ) );

        if ( isset( $this->handlers[ $endpoint ] ) ) {
            $response = ( $this->handlers[ $endpoint ] )( $params );
            return is_array( $response ) ? $response : array();
        }

        $path = $this->resolve_fixture_path( $endpoint, $params );
        if ( null === $path ) {
            throw new ApiException(
                sprintf( 'No mock fixture registered for %s (params=%s)', $endpoint, wp_json_encode( $params ) ),
                ApiException::KIND_NOT_FOUND,
                404,
                'mockFixtureMissing',
                null,
            );
        }

        $body = file_get_contents( $path );
        if ( false === $body ) {
            throw new ApiException(
                sprintf( 'Failed to read mock fixture %s', $path ),
                ApiException::KIND_TRANSIENT,
                null,
                'mockFixtureUnreadable',
                null,
            );
        }

        $decoded = json_decode( $body, true );
        return is_array( $decoded ) ? $decoded : array();
    }

    /**
     * Look up the fixture file. Falls back to a default fixture if present.
     *
     * @param array<string,string|int> $params
     */
    private function resolve_fixture_path( string $endpoint, array $params ): ?string {
        // Sort params for stable hashing.
        ksort( $params );
        $hash = substr( md5( wp_json_encode( $params ) ), 0, 8 );

        $candidates = array(
            $this->fixtures_dir . '/' . $endpoint . '__' . $hash . '.json',
            $this->fixtures_dir . '/' . $endpoint . '__default.json',
        );
        foreach ( $candidates as $c ) {
            if ( is_file( $c ) && is_readable( $c ) ) {
                return $c;
            }
        }
        return null;
    }
}