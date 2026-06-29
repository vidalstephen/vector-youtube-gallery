<?php
/**
 * DisconnectManager — revokes the YouTube Data API key and disconnects all sources.
 *
 * Per plan §21 ("Disconnect & Reset"):
 *   - Operator clicks "Disconnect all sources"
 *   - Plugin calls youtube.api.revoke_token() (Phase 1 stub — for API-key mode,
 *     revoking simply means deleting the key from options)
 *   - All sources.status='active' are flipped to status='disconnected'
 *   - All vyg_videos rows are kept (they belong to the operator's copy of the data);
 *     they will simply not be refreshed until a new key is added
 *
 * @package VectorYT\Gallery\Compliance
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Compliance;

use VectorYT\Gallery\Settings\OAuthTokenRepository;
use VectorYT\Gallery\Settings\SecretsRepository;
use VectorYT\Gallery\Settings\SettingsRepository;
use VectorYT\Gallery\YouTube\ApiClientInterface;
use VectorYT\Gallery\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class DisconnectManager {

    public function __construct(
        private readonly SecretsRepository $secrets,
        private readonly ApiClientInterface $api,
        private readonly Logger $logger,
        private readonly OAuthTokenRepository $oauth_tokens,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * Disconnect: revoke API key + flip all sources to disconnected.
     *
     * @return array{revoked:bool, oauth_tokens_deleted:bool, sources_disconnected:int}
     */
    public function disconnect_all(): array {
        global $wpdb;

        // 1. Best-effort revoke at the API (Phase 1: no-op for API-key mode).
        $revoked = false;
        try {
            $revoked = (bool) $this->api->revoke_token();
        } catch ( \Throwable $e ) {
            $this->logger->warning( 'DisconnectManager: revoke_token threw', array( 'error' => $e->getMessage() ) );
        }

        // 2. Delete locally-stored credentials. OAuth revoke is best-effort;
        // local disconnect must still clear token material if the remote revoke
        // endpoint is unavailable or returns an error.
        $this->secrets->delete_api_key();
        $oauth_tokens_deleted = $this->oauth_tokens->delete_tokens();
        $this->settings->set( 'api_mode', 'api_key' );

        // 3. Mark every source as disconnected.
        $error_payload = wp_json_encode( array(
            'code' => 'disconnected',
            'message' => 'Manually disconnected from admin',
            'at' => gmdate( 'c' ),
        ) );
        $count = (int) $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}vyg_sources
             SET status='disconnected', last_error_code=%s, last_error_message=%s, updated_at=%s
             WHERE status <> 'disconnected'",
            'disconnected',
            $error_payload,
            gmdate( 'Y-m-d H:i:s' )
        ) );

        $this->logger->warning( 'DisconnectManager: disconnected', array(
            'revoked' => $revoked,
            'oauth_tokens_deleted' => $oauth_tokens_deleted,
            'sources' => $count,
        ) );

        return array(
            'revoked'              => $revoked,
            'oauth_tokens_deleted' => $oauth_tokens_deleted,
            'sources_disconnected' => $count,
        );
    }
}