<?php
/**
 * YouTube Data API v3 client — OAuth mode.
 *
 * Uses stored OAuth access/refresh tokens. Access tokens are refreshed when
 * expired and retried once on 401 auth failures.
 *
 * @package VectorYT\Gallery\YouTube
 */

declare(strict_types=1);

namespace VectorYT\Gallery\YouTube;

use VectorYT\Gallery\Logging\Logger;
use VectorYT\Gallery\Settings\OAuthTokenRepository;

defined( 'ABSPATH' ) || exit;

final class OAuthClient implements ApiClientInterface {

    public const SCOPE_YOUTUBE_READONLY = 'https://www.googleapis.com/auth/youtube.readonly';

    private const API_BASE_URL = 'https://www.googleapis.com/youtube/v3/';
    private const AUTH_URL     = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL    = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URL   = 'https://oauth2.googleapis.com/revoke';

    /**
     * @param object|null $http Optional WP_Http-compatible fake for tests.
     */
    public function __construct(
        private readonly OAuthTokenRepository $tokens,
        private readonly Logger $logger,
        private readonly ?object $http = null,
    ) {}

    public function mode(): string {
        return 'oauth';
    }

    /**
     * Build the Google OAuth authorization URL and persist a one-time state hash.
     *
     * @param array<int,string> $scopes
     */
    public function authorization_url( string $state, array $scopes = array( self::SCOPE_YOUTUBE_READONLY ) ): string {
        $config = $this->require_client_config();
        $this->tokens->set_state( $state );

        return self::AUTH_URL . '?' . http_build_query( array(
            'client_id'               => $config['client_id'],
            'redirect_uri'            => $config['redirect_uri'],
            'response_type'           => 'code',
            'scope'                   => implode( ' ', $scopes ),
            'access_type'             => 'offline',
            'prompt'                  => 'consent',
            'include_granted_scopes'  => 'true',
            'state'                   => $state,
        ), '', '&', PHP_QUERY_RFC3986 );
    }

    /**
     * Exchange an authorization code for tokens and store them sealed.
     *
     * @param array<string,mixed> $account Optional connected-account metadata.
     * @return array<string,mixed> Decoded token response.
     */
    public function exchange_auth_code( string $code, array $account = array() ): array {
        $config = $this->require_client_config();
        $body = $this->token_post( array(
            'client_id'     => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'code'          => $code,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => $config['redirect_uri'],
        ) );

        $this->persist_token_response( $body, $account );
        $this->tokens->clear_refresh_error();
        return $body;
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
        $token = trim( $token );
        if ( '' === $token ) {
            $stored = $this->tokens->get_tokens();
            $token = is_array( $stored ) ? (string) ( $stored['refresh_token'] ?? $stored['access_token'] ?? '' ) : '';
        }
        if ( '' === $token ) {
            return true;
        }

        $start = microtime( true );
        $response = $this->http_post( self::REVOKE_URL, array(
            'body'    => array( 'token' => $token ),
            'timeout' => 15,
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
        ) );
        $elapsed = microtime( true ) - $start;
        $code = (int) ( $response['response']['code'] ?? 0 );

        $this->logger->info( 'OAuth token revoke request completed', array(
            'http_status' => $code,
            'elapsed_ms'  => (int) round( $elapsed * 1000 ),
        ) );

        if ( 200 === $code ) {
            $this->tokens->delete_tokens();
            return true;
        }

        throw new ApiException(
            sprintf( 'OAuth token revoke returned HTTP %d', $code ),
            ApiException::classify_youtube_error( $code, null ),
            $code,
            'oauthRevokeFailed',
            null,
        );
    }

    /**
     * @param array<string,string|int> $params
     * @return array<string,mixed>
     */
    private function call( string $endpoint, array $params, bool $already_retried = false ): array {
        $access_token = $this->access_token();
        unset( $params['key'] );

        $url = self::API_BASE_URL . $endpoint . '?' . http_build_query( $params );
        $start = microtime( true );
        $response = $this->http_get( $url, array(
            'timeout'    => 15,
            'user-agent' => 'VectorYT-Gallery/' . VYG_VERSION . ' (+https://github.com/vidalstephen/vector-youtube-gallery)',
            'headers'    => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Accept'        => 'application/json',
            ),
        ) );
        $elapsed = microtime( true ) - $start;

        $code = (int) ( $response['response']['code'] ?? 0 );
        $body = json_decode( (string) ( $response['body'] ?? '' ), true );

        $this->logger->info( sprintf( 'YouTube OAuth API %s [%d] in %.2fs', $endpoint, $code, $elapsed ), array(
            'endpoint'    => $endpoint,
            'http_status' => $code,
            'elapsed_ms'  => (int) round( $elapsed * 1000 ),
            'param_count' => count( $params ),
        ) );

        if ( 401 === $code && ! $already_retried ) {
            $this->refresh_access_token();
            return $this->call( $endpoint, $params, true );
        }

        if ( $code < 200 || $code >= 300 ) {
            $api_code = null;
            if ( is_array( $body ) && isset( $body['error']['errors'][0]['reason'] ) ) {
                $api_code = (string) $body['error']['errors'][0]['reason'];
            }
            throw new ApiException(
                sprintf( 'YouTube OAuth API %s returned HTTP %d', $endpoint, $code ),
                ApiException::classify_youtube_error( $code, is_array( $body ) ? $body : null ),
                $code,
                $api_code,
                is_array( $body ) ? $body : null,
            );
        }

        return is_array( $body ) ? $body : array();
    }

    private function access_token(): string {
        $stored = $this->tokens->get_tokens();
        if ( null === $stored || '' === $stored['access_token'] ) {
            throw new ApiException(
                'OAuth access token not configured. Connect YouTube in Settings → YouTube Gallery.',
                ApiException::KIND_AUTH,
                null,
                'missingOAuthToken',
                null,
            );
        }

        if ( $this->is_expired_or_expiring( $stored['expires_at'] ) ) {
            $stored = $this->refresh_access_token();
        }

        return $stored['access_token'];
    }

    /**
     * @return array{access_token:string,refresh_token:?string,token_type:string,scope:array<int,string>,expires_at:string,created_at:string|null,updated_at:string|null,connected_account:array<string,string>,last_refresh_error:?array{code:string,message:string,at:string}}
     */
    public function refresh_access_token(): array {
        $stored = $this->tokens->get_tokens();
        if ( null === $stored || empty( $stored['refresh_token'] ) ) {
            throw new ApiException(
                'OAuth refresh token missing. Reconnect YouTube in Settings → YouTube Gallery.',
                ApiException::KIND_AUTH,
                null,
                'missingRefreshToken',
                null,
            );
        }
        $config = $this->require_client_config();

        try {
            $body = $this->token_post( array(
                'client_id'     => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'grant_type'    => 'refresh_token',
                'refresh_token' => $stored['refresh_token'],
            ) );
            if ( empty( $body['refresh_token'] ) ) {
                $body['refresh_token'] = $stored['refresh_token'];
            }
            if ( empty( $body['scope'] ) ) {
                $body['scope'] = implode( ' ', $stored['scope'] );
            }
            $this->persist_token_response( $body, $stored['connected_account'] );
            $this->tokens->clear_refresh_error();
            $refreshed = $this->tokens->get_tokens();
            if ( null === $refreshed ) {
                throw new ApiException( 'OAuth token refresh did not persist tokens.', ApiException::KIND_AUTH, null, 'tokenPersistFailed', null );
            }
            return $refreshed;
        } catch ( ApiException $e ) {
            $this->tokens->mark_refresh_error( $e->api_error_code() ?? 'oauth_refresh_failed', $e->getMessage() );
            throw $e;
        }
    }

    /**
     * @param array<string,string> $params
     * @return array<string,mixed>
     */
    private function token_post( array $params ): array {
        $response = $this->http_post( self::TOKEN_URL, array(
            'timeout' => 15,
            'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
            'body'    => $params,
        ) );
        $code = (int) ( $response['response']['code'] ?? 0 );
        $body = json_decode( (string) ( $response['body'] ?? '' ), true );

        if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
            $api_code = is_array( $body ) && is_string( $body['error'] ?? null ) ? $body['error'] : 'oauthTokenError';
            $message = is_array( $body ) && is_string( $body['error_description'] ?? null ) ? $body['error_description'] : sprintf( 'OAuth token endpoint returned HTTP %d', $code );
            throw new ApiException(
                $message,
                ApiException::classify_youtube_error( $code, null ),
                $code,
                $api_code,
                is_array( $body ) ? $body : null,
            );
        }

        if ( empty( $body['access_token'] ) || ! is_string( $body['access_token'] ) ) {
            throw new ApiException( 'OAuth token endpoint did not return an access token.', ApiException::KIND_AUTH, $code, 'missingAccessToken', is_array( $body ) ? $body : null );
        }

        return $body;
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed> $account
     */
    private function persist_token_response( array $body, array $account = array() ): void {
        $scope = array();
        if ( isset( $body['scope'] ) && is_string( $body['scope'] ) ) {
            $scope = array_values( array_filter( preg_split( '/\s+/', trim( $body['scope'] ) ) ?: array() ) );
        }

        $this->tokens->store_tokens(
            (string) $body['access_token'],
            isset( $body['refresh_token'] ) && is_string( $body['refresh_token'] ) ? $body['refresh_token'] : null,
            isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600,
            $scope,
            isset( $body['token_type'] ) && is_string( $body['token_type'] ) ? $body['token_type'] : 'Bearer',
            $account
        );
    }

    /**
     * @return array{client_id:string,client_secret:string,redirect_uri:string,updated_at:string|null}
     */
    private function require_client_config(): array {
        $config = $this->tokens->get_client_config();
        if ( null === $config || '' === $config['client_id'] || '' === $config['client_secret'] || '' === $config['redirect_uri'] ) {
            throw new ApiException(
                'OAuth client credentials are not configured.',
                ApiException::KIND_AUTH,
                null,
                'missingOAuthClientConfig',
                null,
            );
        }
        return $config;
    }

    private function is_expired_or_expiring( string $expires_at ): bool {
        $ts = strtotime( $expires_at );
        if ( false === $ts ) {
            return true;
        }
        return $ts <= ( time() + 60 );
    }

    /**
     * @return array{response:array{code?:int}, body?:string}
     */
    private function http_get( string $url, array $args ): array {
        $http = $this->http ?? new \WP_Http();
        $response = $http->get( $url, $args );
        if ( is_wp_error( $response ) ) {
            throw new ApiException( 'YouTube OAuth request failed: ' . $response->get_error_message(), ApiException::KIND_TRANSIENT, null, 'httpError', null );
        }
        return is_array( $response ) ? $response : array( 'response' => array( 'code' => 0 ), 'body' => '' );
    }

    /**
     * @return array{response:array{code?:int}, body?:string}
     */
    private function http_post( string $url, array $args ): array {
        $http = $this->http ?? new \WP_Http();
        $response = $http->post( $url, $args );
        if ( is_wp_error( $response ) ) {
            throw new ApiException( 'YouTube OAuth request failed: ' . $response->get_error_message(), ApiException::KIND_TRANSIENT, null, 'httpError', null );
        }
        return is_array( $response ) ? $response : array( 'response' => array( 'code' => 0 ), 'body' => '' );
    }
}
