<?php
/**
 * OAuth token repository.
 *
 * Stores OAuth client config and token material separately from API-key mode.
 * Secret values are sealed before storage and every option uses autoload=no.
 *
 * @package VectorYT\Gallery\Settings
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Settings;

defined( 'ABSPATH' ) || exit;

final class OAuthTokenRepository {

    private const OPTION_CLIENT = 'vyg_oauth_client_config';
    private const OPTION_TOKENS = 'vyg_oauth_tokens';
    private const OPTION_STATE  = 'vyg_oauth_state';

    /**
     * Persist OAuth web-app client configuration.
     */
    public function set_client_config( string $client_id, string $client_secret, string $redirect_uri ): bool {
        $client_id     = trim( sanitize_text_field( $client_id ) );
        $client_secret = trim( $client_secret );
        $redirect_uri  = esc_url_raw( trim( $redirect_uri ) );

        if ( '' === $client_id || '' === $client_secret || '' === $redirect_uri ) {
            return $this->delete_client_config();
        }

        return (bool) update_option(
            self::OPTION_CLIENT,
            array(
                'client_id'     => $client_id,
                'client_secret' => $this->seal( $client_secret ),
                'redirect_uri'  => $redirect_uri,
                'updated_at'    => gmdate( 'c' ),
            ),
            false
        );
    }

    public function has_client_config(): bool {
        $config = $this->get_client_config();
        return null !== $config && '' !== $config['client_id'] && '' !== $config['client_secret'];
    }

    /**
     * @return array{client_id:string,client_secret:string,redirect_uri:string,updated_at:string|null}|null
     */
    public function get_client_config(): ?array {
        $stored = get_option( self::OPTION_CLIENT, null );
        if ( ! is_array( $stored ) ) {
            return null;
        }

        $secret = $this->unseal( (string) ( $stored['client_secret'] ?? '' ) );
        if ( null === $secret ) {
            return null;
        }

        return array(
            'client_id'     => (string) ( $stored['client_id'] ?? '' ),
            'client_secret' => $secret,
            'redirect_uri'  => (string) ( $stored['redirect_uri'] ?? '' ),
            'updated_at'    => isset( $stored['updated_at'] ) ? (string) $stored['updated_at'] : null,
        );
    }

    public function delete_client_config(): bool {
        return (bool) delete_option( self::OPTION_CLIENT );
    }

    /**
     * Store OAuth token material. Access/refresh tokens are sealed; metadata is plain.
     *
     * @param array<int,string> $scopes
     * @param array<string,mixed> $account
     */
    public function store_tokens(
        string $access_token,
        ?string $refresh_token,
        int $expires_in,
        array $scopes = array(),
        string $token_type = 'Bearer',
        array $account = array()
    ): bool {
        $access_token  = trim( $access_token );
        $refresh_token = null === $refresh_token ? null : trim( $refresh_token );
        if ( '' === $access_token ) {
            return false;
        }

        $now        = time();
        $expires_at = gmdate( 'c', $now + max( 0, $expires_in ) );

        return (bool) update_option(
            self::OPTION_TOKENS,
            array(
                'access_token'      => $this->seal( $access_token ),
                'refresh_token'     => null === $refresh_token || '' === $refresh_token ? null : $this->seal( $refresh_token ),
                'token_type'        => sanitize_text_field( $token_type ),
                'scope'             => array_values( array_map( 'sanitize_text_field', $scopes ) ),
                'expires_at'        => $expires_at,
                'created_at'        => gmdate( 'c', $now ),
                'updated_at'        => gmdate( 'c', $now ),
                'connected_account' => $this->sanitize_account( $account ),
                'last_refresh_error'=> null,
            ),
            false
        );
    }

    /**
     * @return array{access_token:string,refresh_token:?string,token_type:string,scope:array<int,string>,expires_at:string,created_at:string|null,updated_at:string|null,connected_account:array<string,string>,last_refresh_error:?array{code:string,message:string,at:string}}|null
     */
    public function get_tokens(): ?array {
        $stored = get_option( self::OPTION_TOKENS, null );
        if ( ! is_array( $stored ) ) {
            return null;
        }

        $access = $this->unseal( (string) ( $stored['access_token'] ?? '' ) );
        if ( null === $access || '' === $access ) {
            return null;
        }

        $refresh = null;
        if ( ! empty( $stored['refresh_token'] ) ) {
            $refresh = $this->unseal( (string) $stored['refresh_token'] );
        }

        return array(
            'access_token'       => $access,
            'refresh_token'      => $refresh,
            'token_type'         => (string) ( $stored['token_type'] ?? 'Bearer' ),
            'scope'              => isset( $stored['scope'] ) && is_array( $stored['scope'] ) ? array_values( $stored['scope'] ) : array(),
            'expires_at'         => (string) ( $stored['expires_at'] ?? '' ),
            'created_at'         => isset( $stored['created_at'] ) ? (string) $stored['created_at'] : null,
            'updated_at'         => isset( $stored['updated_at'] ) ? (string) $stored['updated_at'] : null,
            'connected_account'  => isset( $stored['connected_account'] ) && is_array( $stored['connected_account'] ) ? $stored['connected_account'] : array(),
            'last_refresh_error' => isset( $stored['last_refresh_error'] ) && is_array( $stored['last_refresh_error'] ) ? $stored['last_refresh_error'] : null,
        );
    }

    public function has_tokens(): bool {
        return null !== $this->get_tokens();
    }

    public function delete_tokens(): bool {
        delete_option( self::OPTION_STATE );
        return (bool) delete_option( self::OPTION_TOKENS );
    }

    /**
     * Update connected-account metadata without touching sealed token material.
     *
     * @param array<string,mixed> $account
     */
    public function update_connected_account( array $account ): bool {
        $stored = get_option( self::OPTION_TOKENS, null );
        if ( ! is_array( $stored ) ) {
            return false;
        }
        $stored['connected_account'] = $this->sanitize_account( $account );
        $stored['updated_at'] = gmdate( 'c' );
        return (bool) update_option( self::OPTION_TOKENS, $stored, false );
    }

    /**
     * Safe status for admin UI/diagnostics. Never includes token material.
     *
     * @return array{client_configured:bool,connected:bool,client_id_masked:string,redirect_uri:string|null,expires_at:string|null,scopes:array<int,string>,connected_account:array<string,string>,last_refresh_error:?array{code:string,message:string,at:string}}
     */
    public function status(): array {
        $config = $this->get_client_config();
        $tokens = $this->get_tokens();

        return array(
            'client_configured'  => null !== $config,
            'connected'          => null !== $tokens,
            'client_id_masked'   => null === $config ? '' : self::mask( $config['client_id'] ),
            'redirect_uri'       => null === $config ? null : $config['redirect_uri'],
            'expires_at'         => null === $tokens ? null : $tokens['expires_at'],
            'scopes'             => null === $tokens ? array() : $tokens['scope'],
            'connected_account'  => null === $tokens ? array() : $tokens['connected_account'],
            'last_refresh_error' => null === $tokens ? null : $tokens['last_refresh_error'],
        );
    }

    public function mark_refresh_error( string $code, string $message ): void {
        $stored = get_option( self::OPTION_TOKENS, null );
        if ( ! is_array( $stored ) ) {
            return;
        }
        $stored['last_refresh_error'] = array(
            'code'    => sanitize_key( $code ),
            'message' => sanitize_text_field( $message ),
            'at'      => gmdate( 'c' ),
        );
        update_option( self::OPTION_TOKENS, $stored, false );
    }

    public function clear_refresh_error(): void {
        $stored = get_option( self::OPTION_TOKENS, null );
        if ( ! is_array( $stored ) ) {
            return;
        }
        $stored['last_refresh_error'] = null;
        update_option( self::OPTION_TOKENS, $stored, false );
    }

    public function set_state( string $state, int $ttl_seconds = 600 ): bool {
        $state = trim( $state );
        if ( '' === $state ) {
            return (bool) delete_option( self::OPTION_STATE );
        }
        return (bool) update_option(
            self::OPTION_STATE,
            array(
                'state'      => hash( 'sha256', $state ),
                'expires_at' => time() + max( 60, $ttl_seconds ),
            ),
            false
        );
    }

    public function consume_state( string $state ): bool {
        $stored = get_option( self::OPTION_STATE, null );
        delete_option( self::OPTION_STATE );
        if ( ! is_array( $stored ) || empty( $stored['state'] ) || empty( $stored['expires_at'] ) ) {
            return false;
        }
        if ( time() > (int) $stored['expires_at'] ) {
            return false;
        }
        return hash_equals( (string) $stored['state'], hash( 'sha256', trim( $state ) ) );
    }

    public static function mask( ?string $value ): string {
        if ( null === $value || '' === $value ) {
            return '';
        }
        if ( strlen( $value ) <= 10 ) {
            return '***';
        }
        return substr( $value, 0, 6 ) . '***' . substr( $value, -4 );
    }

    /**
     * @param array<string,mixed> $account
     * @return array<string,string>
     */
    private function sanitize_account( array $account ): array {
        $allowed = array( 'email', 'channel_id', 'channel_title', 'account_id' );
        $out     = array();
        foreach ( $allowed as $key ) {
            if ( isset( $account[ $key ] ) && '' !== (string) $account[ $key ] ) {
                $out[ $key ] = sanitize_text_field( (string) $account[ $key ] );
            }
        }
        return $out;
    }

    private function seal( string $plaintext ): string {
        $iv  = random_bytes( 12 );
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            'vyg-oauth-v1'
        );

        if ( false === $ciphertext ) {
            // Fail closed: do not store plaintext if encryption fails.
            return '';
        }

        return base64_encode( wp_json_encode( array(
            'v'    => 1,
            'alg'  => 'AES-256-GCM',
            'iv'   => base64_encode( $iv ),
            'tag'  => base64_encode( $tag ),
            'data' => base64_encode( $ciphertext ),
        ) ) );
    }

    private function unseal( string $sealed ): ?string {
        if ( '' === $sealed ) {
            return null;
        }
        $decoded = base64_decode( $sealed, true );
        if ( false === $decoded ) {
            return null;
        }
        $payload = json_decode( $decoded, true );
        if ( ! is_array( $payload ) || 1 !== (int) ( $payload['v'] ?? 0 ) ) {
            return null;
        }
        $iv         = base64_decode( (string) ( $payload['iv'] ?? '' ), true );
        $tag        = base64_decode( (string) ( $payload['tag'] ?? '' ), true );
        $ciphertext = base64_decode( (string) ( $payload['data'] ?? '' ), true );
        if ( false === $iv || false === $tag || false === $ciphertext ) {
            return null;
        }
        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            'vyg-oauth-v1'
        );
        return false === $plaintext ? null : $plaintext;
    }

    private function key(): string {
        if ( function_exists( 'wp_salt' ) ) {
            $material = wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' );
        } else {
            $material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'auth' ) . '|' . ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : 'secure_auth' );
        }
        return hash( 'sha256', 'vector-youtube-gallery|oauth|' . $material, true );
    }
}
