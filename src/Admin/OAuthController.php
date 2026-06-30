<?php
/**
 * OAuth admin-post controller.
 *
 * Handles the operator-initiated Google OAuth connection lifecycle without
 * requiring public/shared test credentials. The public helper methods are
 * intentionally testable without exiting; hook handlers only validate caps and
 * redirect.
 *
 * @package VectorYT\Gallery\Admin
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Admin;

use VectorYT\Gallery\Logging\Logger;
use VectorYT\Gallery\Settings\OAuthTokenRepository;
use VectorYT\Gallery\Settings\SettingsRepository;
use VectorYT\Gallery\YouTube\ApiException;
use VectorYT\Gallery\YouTube\OAuthClient;

use function defined;
use function hash_equals;
use function sanitize_text_field;
use function sanitize_key;
use function wp_unslash;

defined( 'ABSPATH' ) || exit;

final class OAuthController {

    public const NONCE_ACTION_CONNECT = 'vyg_oauth_connect';
    public const NONCE_ACTION_DISCONNECT = 'vyg_oauth_disconnect';

    public function __construct(
        private readonly OAuthClient $oauth,
        private readonly OAuthTokenRepository $tokens,
        private readonly SettingsRepository $settings,
        private readonly Logger $logger,
    ) {}

    public function connect_url(): string {
        return wp_nonce_url(
            add_query_arg(
                array( 'action' => 'vyg_oauth_connect' ),
                admin_url( 'admin-post.php' )
            ),
            self::NONCE_ACTION_CONNECT
        );
    }

    public function disconnect_url(): string {
        return wp_nonce_url(
            add_query_arg(
                array( 'action' => 'vyg_oauth_disconnect' ),
                admin_url( 'admin-post.php' )
            ),
            self::NONCE_ACTION_DISCONNECT
        );
    }

    public function settings_url( string $notice = '', ?string $error = null ): string {
        $args = array(
            'page' => AdminMenu::PARENT_SLUG . '-settings',
            'tab'  => 'oauth',
        );
        if ( '' !== $notice ) {
            $args['vyg_notice'] = $notice;
        }
        if ( null !== $error && '' !== $error ) {
            $args['vyg_oauth_error'] = sanitize_key( $error );
        }
        return add_query_arg( $args, admin_url( 'admin.php' ) );
    }

    /**
     * Build the Google authorization URL and store the one-time state hash.
     */
    public function authorization_redirect_url(): string {
        $state = wp_generate_password( 32, false, false );
        return $this->oauth->authorization_url( $state );
    }

    /**
     * Allow the external Google OAuth authorization host for the connect redirect.
     *
     * WordPress's wp_safe_redirect() intentionally rejects hosts outside the
     * current site. The OAuth connect step must leave wp-admin and go to
     * accounts.google.com, so the connect handler installs this narrow
     * allowlist filter immediately before redirecting.
     *
     * @param array<int,string> $hosts
     * @return array<int,string>
     */
    public function allow_google_oauth_redirect_host( array $hosts ): array {
        $hosts[] = 'accounts.google.com';
        return array_values( array_unique( $hosts ) );
    }

    /**
     * Process sanitized callback params and return the admin redirect URL.
     *
     * @param array<string,mixed> $query Usually wp_unslash($_GET).
     */
    public function callback_redirect_url( array $query ): string {
        $state = isset( $query['state'] ) ? sanitize_text_field( (string) $query['state'] ) : '';
        $code  = isset( $query['code'] ) ? sanitize_text_field( (string) $query['code'] ) : '';
        $error = isset( $query['error'] ) ? sanitize_key( (string) $query['error'] ) : '';

        if ( '' !== $error ) {
            $this->logger->warning( 'OAuth callback returned error', array( 'error' => $error ) );
            return $this->settings_url( 'oauth_error', $error );
        }

        if ( '' === $state || ! $this->tokens->consume_state( $state ) ) {
            $this->logger->warning( 'OAuth callback rejected invalid state' );
            return $this->settings_url( 'oauth_error', 'invalid_state' );
        }

        if ( '' === $code ) {
            $this->logger->warning( 'OAuth callback missing authorization code' );
            return $this->settings_url( 'oauth_error', 'missing_code' );
        }

        try {
            $this->oauth->exchange_auth_code( $code );
            $account = $this->fetch_connected_account();
            if ( array() !== $account ) {
                $this->tokens->update_connected_account( $account );
            }
            $this->settings->set( 'api_mode', 'oauth' );
            $this->logger->info( 'OAuth callback completed', array(
                'channel_id' => $account['channel_id'] ?? '',
            ) );
            return $this->settings_url( 'oauth_connected' );
        } catch ( ApiException $e ) {
            $this->logger->error( 'OAuth callback failed', array(
                'api_error_code' => $e->api_error_code(),
                'message'        => $e->getMessage(),
            ) );
            return $this->settings_url( 'oauth_error', $e->api_error_code() ?? 'oauth_callback_failed' );
        }
    }


    /**
     * Revoke the Google token if possible, then always remove local token state.
     */
    public function disconnect_redirect_url(): string {
        $revoked = false;
        try {
            $revoked = $this->oauth->revoke_token( '' );
        } catch ( ApiException $e ) {
            $this->logger->warning( 'OAuth disconnect revoke failed; deleting local token state anyway', array(
                'api_error_code' => $e->api_error_code(),
                'message'        => $e->getMessage(),
            ) );
        }

        $this->tokens->delete_tokens();
        $this->settings->set( 'api_mode', 'api_key' );
        $this->logger->info( 'OAuth disconnected via admin', array( 'revoked' => $revoked ) );

        return $this->settings_url( $revoked ? 'oauth_revoked' : 'oauth_disconnected' );
    }

    public function handle_connect(): void {
        if ( ! current_user_can( AdminMenu::REQUIRED_CAP ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'vector-youtube-gallery' ) );
        }
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION_CONNECT ) ) {
            wp_die( esc_html__( 'Nonce check failed.', 'vector-youtube-gallery' ) );
        }

        try {
            add_filter( 'allowed_redirect_hosts', array( $this, 'allow_google_oauth_redirect_host' ) );
            wp_safe_redirect( $this->authorization_redirect_url() );
        } catch ( ApiException $e ) {
            $this->logger->error( 'OAuth connect failed before redirect', array(
                'api_error_code' => $e->api_error_code(),
                'message'        => $e->getMessage(),
            ) );
            wp_safe_redirect( $this->settings_url( 'oauth_error', $e->api_error_code() ?? 'oauth_connect_failed' ) );
        }
        exit;
    }

    public function handle_callback(): void {
        if ( ! current_user_can( AdminMenu::REQUIRED_CAP ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'vector-youtube-gallery' ) );
        }
        wp_safe_redirect( $this->callback_redirect_url( wp_unslash( $_GET ) ) );
        exit;
    }

    public function handle_disconnect(): void {
        if ( ! current_user_can( AdminMenu::REQUIRED_CAP ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'vector-youtube-gallery' ) );
        }
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION_DISCONNECT ) ) {
            wp_die( esc_html__( 'Nonce check failed.', 'vector-youtube-gallery' ) );
        }
        wp_safe_redirect( $this->disconnect_redirect_url() );
        exit;
    }

    /**
     * @return array<string,string>
     */
    private function fetch_connected_account(): array {
        $body = $this->oauth->channels_list( array(
            'part' => 'id,snippet',
            'mine' => 'true',
            'maxResults' => '1',
        ) );
        $item = $body['items'][0] ?? null;
        if ( ! is_array( $item ) ) {
            return array();
        }
        $snippet = isset( $item['snippet'] ) && is_array( $item['snippet'] ) ? $item['snippet'] : array();
        return array(
            'channel_id'    => isset( $item['id'] ) ? (string) $item['id'] : '',
            'channel_title' => isset( $snippet['title'] ) ? (string) $snippet['title'] : '',
        );
    }
}
