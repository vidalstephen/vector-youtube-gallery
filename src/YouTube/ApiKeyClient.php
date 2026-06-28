<?php
/**
 * Live YouTube Data API client — API key mode.
 *
 * Signs every request with `key=<api_key>`. Pure read-only operations.
 * For OAuth (Phase 2+), use OAuthClient instead.
 *
 * @package VectorYT\Gallery\YouTube
 */

declare(strict_types=1);

namespace VectorYT\Gallery\YouTube;

use VectorYT\Gallery\Logging\Logger;
use VectorYT\Gallery\Settings\SecretsRepository;

defined( 'ABSPATH' ) || exit;

final class ApiKeyClient implements ApiClientInterface {

    private const BASE_URL = 'https://www.googleapis.com/youtube/v3/';

    public function __construct(
        private readonly SecretsRepository $secrets,
        private readonly Logger $logger,
        private readonly ?\WP_Http $http = null,  // injected for tests
    ) {}

    public function mode(): string {
        return 'api_key';
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
        // API key mode: no token to revoke. Phase 2 OAuth client overrides.
        return true;
    }

    /**
     * @param array<string,string|int> $params
     * @return array<string,mixed>
     */
    private function call( string $endpoint, array $params ): array {
        $key = $this->secrets->get_api_key();
        if ( null === $key || '' === $key ) {
            throw new ApiException(
                'YouTube API key not configured. Set one in Settings → YouTube Gallery.',
                ApiException::KIND_AUTH,
                null,
                'missingApiKey',
                null,
            );
        }

        // YouTube API requires `key` parameter; we'll always set it last.
        unset( $params['key'] );
        $params['key'] = $key;

        $url = self::BASE_URL . $endpoint . '?' . http_build_query( $params );

        $start    = microtime( true );
        $response = $this->http_request( $url );
        $elapsed  = microtime( true ) - $start;

        $code = (int) ( $response['response']['code'] ?? 0 );
        $body = json_decode( (string) ( $response['body'] ?? '' ), true );

        // Surface diagnostics (no secrets in the log line — only the endpoint + status).
        $this->logger->info(
            sprintf( 'YouTube API %s [%d] in %.2fs', $endpoint, $code, $elapsed ),
            array(
                'endpoint'      => $endpoint,
                'http_status'   => $code,
                'elapsed_ms'    => (int) round( $elapsed * 1000 ),
                'param_count'   => count( $params ),
            )
        );

        if ( $code < 200 || $code >= 300 ) {
            $kind = ApiException::classify_youtube_error( $code, is_array( $body ) ? $body : null );
            $api_code = null;
            if ( is_array( $body ) && isset( $body['error']['errors'][0]['reason'] ) ) {
                $api_code = (string) $body['error']['errors'][0]['reason'];
            }
            throw new ApiException(
                sprintf( 'YouTube API %s returned HTTP %d', $endpoint, $code ),
                $kind,
                $code,
                $api_code,
                is_array( $body ) ? $body : null,
            );
        }

        return is_array( $body ) ? $body : array();
    }

    /**
     * Issue a GET request. Returns the raw wp_remote_get-style response array.
     *
     * @return array{response:array{code?:int}, body?:string}
     */
    private function http_request( string $url ): array {
        $http = $this->http ?? new \WP_Http();
        $response = $http->get( $url, array(
            'timeout'    => 15,
            'user-agent' => 'VectorYT-Gallery/' . VYG_VERSION . ' (+https://github.com/vidalstephen/vector-youtube-gallery)',
        ) );

        if ( is_wp_error( $response ) ) {
            throw new ApiException(
                'YouTube API request failed: ' . $response->get_error_message(),
                ApiException::KIND_TRANSIENT,
                null,
                'httpError',
                null,
            );
        }

        // WP_Http returns array with 'response' (code/message/headers) and 'body'.
        // Brain\Monkey tests inject a fake that already returns the right shape.
        return is_array( $response ) ? $response : array( 'response' => array( 'code' => 0 ), 'body' => '' );
    }
}