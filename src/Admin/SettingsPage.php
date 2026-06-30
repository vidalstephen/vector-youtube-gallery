<?php
/**
 * Settings page — API key entry + non-secret plugin settings.
 *
 * Phase 1: API key field only. SettingsRepository is read-only here.
 * Phase 2 will add sync intervals, retention windows, default layout, etc.
 *
 * Security:
 *   - nonce check (vyg_settings_nonce)
 *   - capability check (manage_options)
 *   - sanitize_text_field on text input
 *   - API key stored via SecretsRepository (autoload=no, no echo)
 *
 * @package VectorYT\Gallery\Admin
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Admin;

use VectorYT\Gallery\Logging\Logger;
use VectorYT\Gallery\Settings\OAuthTokenRepository;
use VectorYT\Gallery\Settings\SecretsRepository;
use VectorYT\Gallery\Settings\SettingsRepository;
use VectorYT\Gallery\YouTube\ApiClientInterface;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

    private const NONCE_ACTION = 'vyg_settings_save';

    public function __construct(
        private readonly SecretsRepository $secrets,
        private readonly OAuthTokenRepository $oauth_tokens,
        private readonly SettingsRepository $settings,
        private readonly ApiClientInterface $api,
        private readonly Logger $logger,
    ) {}

    public function render(): void {
        if ( ! current_user_can( AdminMenu::REQUIRED_CAP ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'vector-youtube-gallery' ) );
        }

        // Handle save.
        if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
            // WP 7.0 update banner can echo HTML before our handler runs,
            // breaking wp_safe_redirect() with "headers already sent". Capture
            // any stray output so the redirect works cleanly.
            ob_start();
            $this->maybe_handle_save();
            $this->maybe_handle_oauth_save();
            $this->maybe_handle_settings_save();
            ob_end_clean();
        }

        $has_key              = $this->secrets->has_api_key();
        $masked               = SecretsRepository::mask( $this->secrets->get_api_key() );
        $validated_at         = $this->secrets->get_api_key_validated_at();
        $last_error           = $this->secrets->get_api_key_last_error();
        $api_mode             = $this->api->mode();
        $oauth_status         = $this->oauth_tokens->status();
        $oauth_config         = $this->oauth_tokens->get_client_config();

        $this->render_html( $has_key, $masked, $validated_at, $last_error, $api_mode, $oauth_status, $oauth_config );
    }

    private function maybe_handle_save(): void {
        if ( ! isset( $_POST['vyg_settings_nonce'] ) ) {
            return;
        }
        $nonce = sanitize_text_field( wp_unslash( $_POST['vyg_settings_nonce'] ) );
        if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
            wp_die( esc_html__( 'Invalid nonce.', 'vector-youtube-gallery' ) );
        }
        if ( ! current_user_can( AdminMenu::REQUIRED_CAP ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'vector-youtube-gallery' ) );
        }

        $raw_key = isset( $_POST['vyg_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['vyg_api_key'] ) ) : '';
        $action  = isset( $_POST['vyg_api_key_action'] ) ? sanitize_key( wp_unslash( $_POST['vyg_api_key_action'] ) ) : '';

        if ( 'delete' === $action ) {
            $this->secrets->delete_api_key();
            $this->logger->info( 'API key deleted via admin' );
            $this->redirect_with_notice( 'deleted' );
            return;
        }

        if ( 'save' === $action ) {
            if ( '' === $raw_key ) {
                $this->secrets->delete_api_key();
            } else {
                $this->secrets->set_api_key( $raw_key );
            }
            $this->logger->info( 'API key saved via admin' );
            $this->redirect_with_notice( 'saved' );
            return;
        }
    }

    private function maybe_handle_oauth_save(): void {
        $op = isset( $_POST['vyg_op'] ) ? sanitize_key( wp_unslash( $_POST['vyg_op'] ) ) : '';
        if ( 'save_oauth' !== $op ) {
            return;
        }

        if ( ! isset( $_POST['vyg_settings_nonce'] ) ) {
            return;
        }
        $nonce = sanitize_text_field( wp_unslash( $_POST['vyg_settings_nonce'] ) );
        if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
            wp_die( esc_html__( 'Invalid nonce.', 'vector-youtube-gallery' ) );
        }
        if ( ! current_user_can( AdminMenu::REQUIRED_CAP ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'vector-youtube-gallery' ) );
        }

        $action = isset( $_POST['vyg_oauth_action'] ) ? sanitize_key( wp_unslash( $_POST['vyg_oauth_action'] ) ) : 'save';

        if ( 'delete_config' === $action ) {
            $this->oauth_tokens->delete_tokens();
            $this->oauth_tokens->delete_client_config();
            $this->logger->info( 'OAuth client configuration deleted via admin' );
            $this->redirect_with_notice( 'oauth_deleted', 'oauth' );
            return;
        }

        if ( 'disconnect_local' === $action ) {
            $this->oauth_tokens->delete_tokens();
            $this->logger->info( 'OAuth local token state deleted via admin' );
            $this->redirect_with_notice( 'oauth_disconnected', 'oauth' );
            return;
        }

        $posted = wp_unslash( $_POST );
        $this->settings->save_posted( $posted );

        $existing     = $this->oauth_tokens->get_client_config();
        $client_id    = isset( $posted['vyg_oauth_client_id'] ) ? sanitize_text_field( (string) $posted['vyg_oauth_client_id'] ) : '';
        $client_secret= isset( $posted['vyg_oauth_client_secret'] ) ? trim( (string) $posted['vyg_oauth_client_secret'] ) : '';
        $redirect_uri = isset( $posted['vyg_oauth_redirect_uri'] ) ? esc_url_raw( trim( (string) $posted['vyg_oauth_redirect_uri'] ) ) : $this->oauth_callback_url();

        if ( '' === $client_secret && null !== $existing ) {
            $client_secret = $existing['client_secret'];
        }

        if ( '' !== $client_id && '' !== $client_secret && '' !== $redirect_uri ) {
            $this->oauth_tokens->set_client_config( $client_id, $client_secret, $redirect_uri );
        }

        $this->logger->info( 'OAuth settings saved via admin', array(
            'api_mode' => $this->settings->get( 'api_mode', 'api_key' ),
            'client_configured' => $this->oauth_tokens->has_client_config(),
        ) );
        $this->redirect_with_notice( 'oauth_saved', 'oauth' );
    }

    private function oauth_callback_url(): string {
        return admin_url( 'admin-post.php?action=vyg_oauth_callback' );
    }

    private function redirect_with_notice( string $notice, string $tab = '' ): void {
        $args = array( 'page' => AdminMenu::PARENT_SLUG . '-settings', 'vyg_notice' => $notice );
        if ( '' !== $tab ) {
            $args['tab'] = $tab;
        }
        $url = add_query_arg(
            $args,
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $url );
        exit;
    }

    /**
     * Handle save of classification settings. Reached when the page form
     * posts with `vyg_op = save_settings`. Uses the same nonce as the API key form.
     */
    private function maybe_handle_settings_save(): void {
        $op = isset( $_POST['vyg_op'] ) ? sanitize_key( wp_unslash( $_POST['vyg_op'] ) ) : '';
        if ( 'save_settings' !== $op ) {
            return;
        }
        $posted = wp_unslash( $_POST );
        $this->settings->save_posted( $posted );
        $this->logger->info( 'Classification settings saved', array(
            'keys' => array_keys( $this->settings->all() ),
        ) );
        $this->redirect_with_notice( 'settings_saved' );
    }

    private function render_html(
        bool $has_key,
        string $masked,
        ?string $validated_at,
        ?array $last_error,
        string $api_mode,
        array $oauth_status,
        ?array $oauth_config,
    ): void {
        $notice = isset( $_GET['vyg_notice'] ) ? sanitize_key( wp_unslash( $_GET['vyg_notice'] ) ) : '';
        $s = $this->settings->all();
        $current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'api';
        $valid_tabs = array( 'api', 'oauth', 'classification', 'sync', 'live', 'privacy' );
        if ( ! in_array( $current_tab, $valid_tabs, true ) ) {
            $current_tab = 'api';
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'YouTube Gallery — Settings', 'vector-youtube-gallery' ); ?></h1>

            <nav class="nav-tab-wrapper">
                <?php
                $tab_labels = array(
                    'api'           => __( 'API Key', 'vector-youtube-gallery' ),
                    'oauth'         => __( 'OAuth', 'vector-youtube-gallery' ),
                    'classification'=> __( 'Classification', 'vector-youtube-gallery' ),
                    'sync'          => __( 'Sync', 'vector-youtube-gallery' ),
                    'live'          => __( 'Live', 'vector-youtube-gallery' ),
                    'privacy'       => __( 'Privacy & Data', 'vector-youtube-gallery' ),
                );
                foreach ( $tab_labels as $slug => $label ) :
                    $url = add_query_arg( array( 'page' => AdminMenu::PARENT_SLUG . '-settings', 'tab' => $slug ), admin_url( 'admin.php' ) );
                    $cls = $slug === $current_tab ? 'nav-tab nav-tab-active' : 'nav-tab';
                    ?>
                    <a href="<?php echo esc_url( $url ); ?>" class="<?php echo esc_attr( $cls ); ?>"><?php echo esc_html( $label ); ?></a>
                <?php endforeach; ?>
            </nav>

            <?php if ( 'settings_saved' === $notice ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html__( 'Settings saved.', 'vector-youtube-gallery' ); ?></p>
                </div>
            <?php elseif ( 'oauth_saved' === $notice ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'OAuth settings saved.', 'vector-youtube-gallery' ); ?></p></div>
            <?php elseif ( 'oauth_deleted' === $notice ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'OAuth client configuration and local tokens deleted.', 'vector-youtube-gallery' ); ?></p></div>
            <?php elseif ( 'oauth_disconnected' === $notice ) : ?>
                <div class="notice notice-warning is-dismissible"><p><?php echo esc_html__( 'Local OAuth token state deleted. Google token revocation was attempted but did not confirm success.', 'vector-youtube-gallery' ); ?></p></div>
            <?php elseif ( 'oauth_revoked' === $notice ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Google OAuth token revoked and local token state deleted.', 'vector-youtube-gallery' ); ?></p></div>
            <?php elseif ( 'oauth_connected' === $notice ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'YouTube OAuth account connected.', 'vector-youtube-gallery' ); ?></p></div>
            <?php elseif ( 'oauth_error' === $notice ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html__( 'OAuth connection failed. Check the error code below and verify your Google OAuth client settings.', 'vector-youtube-gallery' ); ?></p></div>
            <?php endif; ?>

            <?php if ( 'saved' === $notice ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html__( 'API key saved.', 'vector-youtube-gallery' ); ?></p>
                </div>
            <?php elseif ( 'deleted' === $notice ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html__( 'API key deleted.', 'vector-youtube-gallery' ); ?></p>
                </div>
            <?php endif; ?>

            <p>
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: %s: api mode (api_key / mock / oauth) */
                        __( 'API client mode: %s', 'vector-youtube-gallery' ),
                        $api_mode
                    )
                );
                ?>
            </p>

            <?php if ( 'api' === $current_tab ) : ?>
            <form method="post" action="">
                <?php wp_nonce_field( self::NONCE_ACTION, 'vyg_settings_nonce' ); ?>

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="vyg_api_key"><?php echo esc_html__( 'YouTube Data API Key', 'vector-youtube-gallery' ); ?></label>
                            </th>
                            <td>
                                <?php if ( $has_key ) : ?>
                                    <p>
                                        <code><?php echo esc_html( $masked ); ?></code>
                                        <span class="description"><?php echo esc_html__( 'Currently stored.', 'vector-youtube-gallery' ); ?></span>
                                    </p>
                                    <p>
                                        <input
                                            type="password"
                                            id="vyg_api_key"
                                            name="vyg_api_key"
                                            class="regular-text"
                                            value=""
                                            placeholder="<?php echo esc_attr__( 'Paste new key to replace', 'vector-youtube-gallery' ); ?>"
                                            autocomplete="off"
                                        />
                                    </p>
                                    <p class="description">
                                        <?php echo esc_html__( 'Keys are stored in wp_options with autoload=no and are never sent to the browser.', 'vector-youtube-gallery' ); ?>
                                    </p>
                                    <?php if ( null !== $validated_at ) : ?>
                                        <p class="description">
                                            <?php
                                            echo esc_html(
                                                sprintf(
                                                    /* translators: %s: ISO 8601 timestamp */
                                                    __( 'Last validated: %s', 'vector-youtube-gallery' ),
                                                    $validated_at
                                                )
                                            );
                                            ?>
                                        </p>
                                    <?php endif; ?>
                                    <?php if ( null !== $last_error ) : ?>
                                        <div class="notice notice-error inline">
                                            <p>
                                                <?php
                                                echo esc_html(
                                                    sprintf(
                                                        /* translators: 1: error code, 2: error message */
                                                        __( 'Last validation error: [%1$s] %2$s', 'vector-youtube-gallery' ),
                                                        $last_error['code'],
                                                        $last_error['message']
                                                    )
                                                );
                                                ?>
                                            </p>
                                        </div>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <input
                                        type="password"
                                        id="vyg_api_key"
                                        name="vyg_api_key"
                                        class="regular-text"
                                        value=""
                                        autocomplete="off"
                                    />
                                    <p class="description">
                                        <?php echo esc_html__( 'Get one from Google Cloud Console → APIs & Services → Credentials.', 'vector-youtube-gallery' ); ?>
                                    </p>
                                <?php endif; ?>

                                <p>
                                    <button type="submit" name="vyg_api_key_action" value="save" class="button button-primary">
                                        <?php echo esc_html__( 'Save API Key', 'vector-youtube-gallery' ); ?>
                                    </button>
                                    <?php if ( $has_key ) : ?>
                                        <button
                                            type="submit"
                                            name="vyg_api_key_action"
                                            value="delete"
                                            class="button button-link-delete"
                                            onclick="return confirm('<?php echo esc_js( __( 'Delete the API key?', 'vector-youtube-gallery' ) ); ?>');"
                                        >
                                            <?php echo esc_html__( 'Delete API Key', 'vector-youtube-gallery' ); ?>
                                        </button>
                                    <?php endif; ?>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </form>
            <?php endif; // end api tab ?>

            <?php if ( 'oauth' === $current_tab ) : ?>
            <h2><?php echo esc_html__( 'OAuth Account Connection', 'vector-youtube-gallery' ); ?></h2>
            <p class="description"><?php echo esc_html__( 'OAuth mode lets a site operator connect a YouTube account instead of relying on a public API key. The callback handler validates state, exchanges the authorization code, stores sealed tokens, and returns here with connection status.', 'vector-youtube-gallery' ); ?></p>

            <table class="widefat striped" style="max-width: 960px; margin: 1em 0;">
                <tbody>
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Configured client', 'vector-youtube-gallery' ); ?></th>
                        <td><?php echo $oauth_status['client_configured'] ? '<code>' . esc_html( $oauth_status['client_id_masked'] ) . '</code>' : esc_html__( 'Not configured', 'vector-youtube-gallery' ); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Connection status', 'vector-youtube-gallery' ); ?></th>
                        <td><?php echo $oauth_status['connected'] ? esc_html__( 'Connected token stored locally', 'vector-youtube-gallery' ) : esc_html__( 'Not connected', 'vector-youtube-gallery' ); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Callback URL', 'vector-youtube-gallery' ); ?></th>
                        <td><code><?php echo esc_html( $this->oauth_callback_url() ); ?></code></td>
                    </tr>
                    <?php if ( ! empty( $oauth_status['expires_at'] ) ) : ?>
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Access token expires', 'vector-youtube-gallery' ); ?></th>
                        <td><?php echo esc_html( $oauth_status['expires_at'] ); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ( ! empty( $oauth_status['last_refresh_error'] ) ) : ?>
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Last refresh error', 'vector-youtube-gallery' ); ?></th>
                        <td><code><?php echo esc_html( $oauth_status['last_refresh_error']['code'] ); ?></code> <?php echo esc_html( $oauth_status['last_refresh_error']['message'] ); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ( 'oauth_error' === $notice && ! empty( $_GET['vyg_oauth_error'] ) ) : ?>
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Connection error', 'vector-youtube-gallery' ); ?></th>
                        <td><code><?php echo esc_html( sanitize_key( wp_unslash( $_GET['vyg_oauth_error'] ) ) ); ?></code></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <form method="post" action="">
                <?php wp_nonce_field( self::NONCE_ACTION, 'vyg_settings_nonce' ); ?>
                <input type="hidden" name="vyg_op" value="save_oauth" />

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'API credential mode', 'vector-youtube-gallery' ); ?></th>
                        <td>
                            <label><input type="radio" name="api_mode" value="api_key" <?php checked( 'api_key', (string) $s['api_mode'] ); ?> /> <?php echo esc_html__( 'API key mode', 'vector-youtube-gallery' ); ?></label><br />
                            <label><input type="radio" name="api_mode" value="oauth" <?php checked( 'oauth', (string) $s['api_mode'] ); ?> /> <?php echo esc_html__( 'OAuth mode', 'vector-youtube-gallery' ); ?></label>
                            <p class="description"><?php echo esc_html__( 'Development mock mode still takes precedence when VYG_USE_MOCK is enabled.', 'vector-youtube-gallery' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="vyg_oauth_client_id"><?php echo esc_html__( 'OAuth client ID', 'vector-youtube-gallery' ); ?></label></th>
                        <td><input type="text" id="vyg_oauth_client_id" name="vyg_oauth_client_id" class="regular-text" value="<?php echo esc_attr( $oauth_config['client_id'] ?? '' ); ?>" autocomplete="off" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="vyg_oauth_client_secret"><?php echo esc_html__( 'OAuth client secret', 'vector-youtube-gallery' ); ?></label></th>
                        <td>
                            <input type="password" id="vyg_oauth_client_secret" name="vyg_oauth_client_secret" class="regular-text" value="" autocomplete="off" placeholder="<?php echo esc_attr( $oauth_config ? __( 'Leave blank to keep existing secret', 'vector-youtube-gallery' ) : __( 'Paste client secret', 'vector-youtube-gallery' ) ); ?>" />
                            <p class="description"><?php echo esc_html__( 'Stored sealed with autoload=no. Never shown again after save.', 'vector-youtube-gallery' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="vyg_oauth_redirect_uri"><?php echo esc_html__( 'Authorized redirect URI', 'vector-youtube-gallery' ); ?></label></th>
                        <td>
                            <input type="url" id="vyg_oauth_redirect_uri" name="vyg_oauth_redirect_uri" class="large-text code" value="<?php echo esc_attr( $oauth_config['redirect_uri'] ?? $this->oauth_callback_url() ); ?>" />
                            <p class="description"><?php echo esc_html__( 'Copy this exact URI into Google Cloud Console → OAuth Client → Authorized redirect URIs.', 'vector-youtube-gallery' ); ?></p>
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="submit" name="vyg_oauth_action" value="save" class="button button-primary"><?php echo esc_html__( 'Save OAuth Settings', 'vector-youtube-gallery' ); ?></button>
                    <?php if ( $oauth_status['client_configured'] ) : ?>
                        <a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'vyg_oauth_connect' ), admin_url( 'admin-post.php' ) ), 'vyg_oauth_connect' ) ); ?>"><?php echo esc_html__( 'Connect / Reconnect YouTube', 'vector-youtube-gallery' ); ?></a>
                        <button type="submit" name="vyg_oauth_action" value="delete_config" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Delete OAuth client configuration and local OAuth tokens?', 'vector-youtube-gallery' ) ); ?>');"><?php echo esc_html__( 'Delete OAuth Config', 'vector-youtube-gallery' ); ?></button>
                    <?php endif; ?>
                    <?php if ( $oauth_status['connected'] ) : ?>
                        <a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'vyg_oauth_disconnect' ), admin_url( 'admin-post.php' ) ), 'vyg_oauth_disconnect' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Revoke the Google OAuth token and delete local OAuth token state?', 'vector-youtube-gallery' ) ); ?>');"><?php echo esc_html__( 'Disconnect OAuth Account', 'vector-youtube-gallery' ); ?></a>
                    <?php endif; ?>
                </p>
            </form>
            <?php endif; // end oauth tab ?>

            <?php if ( 'classification' === $current_tab || 'sync' === $current_tab || 'live' === $current_tab || 'privacy' === $current_tab ) : ?>
            <form method="post" action="">
                <?php wp_nonce_field( self::NONCE_ACTION, 'vyg_settings_nonce' ); ?>
                <input type="hidden" name="vyg_op" value="save_settings" />
                <input type="hidden" name="vyg_settings_tab" value="<?php echo esc_attr( $current_tab ); ?>" />

                <?php if ( 'classification' === $current_tab ) : ?>
                <h2><?php echo esc_html__( 'Classification Thresholds', 'vector-youtube-gallery' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Adjust how videos are categorized. Manual overrides take precedence over auto-classification.', 'vector-youtube-gallery' ); ?></p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="shorts_max_duration_seconds"><?php echo esc_html__( 'Shorts max duration (seconds)', 'vector-youtube-gallery' ); ?></label></th>
                        <td><input name="shorts_max_duration_seconds" id="shorts_max_duration_seconds" type="number" min="30" max="180" value="<?php echo (int) $s['shorts_max_duration_seconds']; ?>" class="small-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="short_candidate_max_duration"><?php echo esc_html__( 'Short candidate max duration (seconds)', 'vector-youtube-gallery' ); ?></label></th>
                        <td><input name="short_candidate_max_duration" id="short_candidate_max_duration" type="number" min="60" max="600" value="<?php echo (int) $s['short_candidate_max_duration']; ?>" class="small-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Auto-classify', 'vector-youtube-gallery' ); ?></th>
                        <td>
                            <label><input type="checkbox" name="auto_classify_shorts" value="1" <?php checked( ! empty( $s['auto_classify_shorts'] ) ); ?> /> <?php esc_html_e( 'Shorts', 'vector-youtube-gallery' ); ?></label><br />
                            <label><input type="checkbox" name="auto_classify_live" value="1" <?php checked( ! empty( $s['auto_classify_live'] ) ); ?> /> <?php esc_html_e( 'Live broadcasts', 'vector-youtube-gallery' ); ?></label><br />
                            <label><input type="checkbox" name="respect_manual_overrides" value="1" <?php checked( ! empty( $s['respect_manual_overrides'] ) ); ?> /> <?php esc_html_e( 'Respect manual operator overrides', 'vector-youtube-gallery' ); ?></label>
                        </td>
                    </tr>
                </table>
                <?php elseif ( 'sync' === $current_tab ) : ?>
                <h2><?php echo esc_html__( 'Sync Intervals & Retention', 'vector-youtube-gallery' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="sync_scheduler_mode"><?php echo esc_html__( 'Scheduler backend', 'vector-youtube-gallery' ); ?></label></th>
                        <td>
                            <select name="sync_scheduler_mode" id="sync_scheduler_mode">
                                <option value="auto" <?php selected( 'auto', (string) $s['sync_scheduler_mode'] ); ?>><?php echo esc_html__( 'Auto (use Action Scheduler when available, otherwise WP-Cron)', 'vector-youtube-gallery' ); ?></option>
                                <option value="wp_cron" <?php selected( 'wp_cron', (string) $s['sync_scheduler_mode'] ); ?>><?php echo esc_html__( 'WP-Cron only', 'vector-youtube-gallery' ); ?></option>
                                <option value="action_scheduler" <?php selected( 'action_scheduler', (string) $s['sync_scheduler_mode'] ); ?>><?php echo esc_html__( 'Action Scheduler only (requires the library)', 'vector-youtube-gallery' ); ?></option>
                            </select>
                            <p class="description">
                                <?php echo esc_html__( 'Phase 12.2: choose where scheduled sync jobs run. Action Scheduler is shipped with WooCommerce and several popular plugins; if it is loaded, "Auto" uses it for richer queue management, retries, and observability. WP-Cron remains the default fallback for self-hosted installs.', 'vector-youtube-gallery' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="default_sync_interval_seconds"><?php echo esc_html__( 'Default source sync interval (seconds)', 'vector-youtube-gallery' ); ?></label></th>
                        <td><input name="default_sync_interval_seconds" id="default_sync_interval_seconds" type="number" min="3600" max="2592000" value="<?php echo (int) $s['default_sync_interval_seconds']; ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="metadata_refresh_batch_size"><?php echo esc_html__( 'Metadata refresh batch size', 'vector-youtube-gallery' ); ?></label></th>
                        <td><input name="metadata_refresh_batch_size" id="metadata_refresh_batch_size" type="number" min="10" max="500" value="<?php echo (int) $s['metadata_refresh_batch_size']; ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="data_refresh_interval_days"><?php echo esc_html__( 'Data refresh interval (days)', 'vector-youtube-gallery' ); ?></label></th>
                        <td><input name="data_refresh_interval_days" id="data_refresh_interval_days" type="number" min="1" max="90" value="<?php echo (int) $s['data_refresh_interval_days']; ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="data_ttl_days"><?php echo esc_html__( 'Data TTL (days)', 'vector-youtube-gallery' ); ?></label></th>
                        <td><input name="data_ttl_days" id="data_ttl_days" type="number" min="30" max="365" value="<?php echo (int) $s['data_ttl_days']; ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="data_hard_delete_after_days"><?php echo esc_html__( 'Hard-delete data after (days)', 'vector-youtube-gallery' ); ?></label></th>
                        <td><input name="data_hard_delete_after_days" id="data_hard_delete_after_days" type="number" min="90" max="1825" value="<?php echo (int) $s['data_hard_delete_after_days']; ?>" /></td>
                    </tr>
                </table>
                <?php elseif ( 'live' === $current_tab ) : ?>
                <h2><?php echo esc_html__( 'Live Broadcast Polling', 'vector-youtube-gallery' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Live broadcasts require frequent metadata refresh. YouTube quota usage scales with these intervals.', 'vector-youtube-gallery' ); ?></p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="live_poll_interval_seconds"><?php echo esc_html__( 'Active live poll interval (seconds)', 'vector-youtube-gallery' ); ?></label></th>
                        <td><input name="live_poll_interval_seconds" id="live_poll_interval_seconds" type="number" min="60" max="3600" value="<?php echo (int) $s['live_poll_interval_seconds']; ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="live_upcoming_poll_seconds"><?php echo esc_html_e( $s['live_upcoming_poll_seconds'] ); ?></label></th>
                        <td><input name="live_upcoming_poll_seconds" id="live_upcoming_poll_seconds" type="number" min="300" max="7200" value="<?php echo (int) $s['live_upcoming_poll_seconds']; ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="live_recently_ended_seconds"><?php echo esc_html__( 'Recently-ended poll interval (seconds)', 'vector-youtube-gallery' ); ?></label></th>
                        <td><input name="live_recently_ended_seconds" id="live_recently_ended_seconds" type="number" min="300" max="7200" value="<?php echo (int) $s['live_recently_ended_seconds']; ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="live_previous_streams_retention"><?php echo esc_html__( 'Previous streams kept per source', 'vector-youtube-gallery' ); ?></label></th>
                        <td><input name="live_previous_streams_retention" id="live_previous_streams_retention" type="number" min="5" max="500" value="<?php echo (int) $s['live_previous_streams_retention']; ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="live_replay_retention_days"><?php echo esc_html__( 'Replay data retention (days)', 'vector-youtube-gallery' ); ?></label></th>
                        <td><input name="live_replay_retention_days" id="live_replay_retention_days" type="number" min="1" max="365" value="<?php echo (int) $s['live_replay_retention_days']; ?>" /></td>
                    </tr>
                </table>
                <?php elseif ( 'privacy' === $current_tab ) : ?>
                <h2><?php echo esc_html__( 'Privacy & Data Export', 'vector-youtube-gallery' ); ?></h2>
                <p><?php esc_html_e( 'YouTube IDs and metadata stored by this plugin are public information from the YouTube Data API. No personal data is collected unless you associate videos with user accounts.', 'vector-youtube-gallery' ); ?></p>
                <h3><?php esc_html_e( 'GDPR Tools', 'vector-youtube-gallery' ); ?></h3>
                <p><?php esc_html_e( 'WordPress exposes per-user "Export Personal Data" and "Erase Personal Data" tools under Tools → Export/Privacy. The plugin registers filters that scan vyg_* tables for any rows containing a WP user ID. Use WP\'s built-in tools to invoke them.', 'vector-youtube-gallery' ); ?></p>
                <p>
                    <a href="<?php echo esc_url( admin_url( 'tools.php?page=export_personal_data' ) ); ?>" class="button"><?php esc_html_e( 'WP Export Personal Data', 'vector-youtube-gallery' ); ?></a>
                    <a href="<?php echo esc_url( admin_url( 'tools.php?page=remove_personal_data' ) ); ?>" class="button"><?php esc_html_e( 'WP Erase Personal Data', 'vector-youtube-gallery' ); ?></a>
                </p>
                <h3><?php esc_html_e( 'Settings import/export', 'vector-youtube-gallery' ); ?></h3>
                <p>
                    <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => AdminMenu::PARENT_SLUG . '-settings', 'vyg_op' => 'export_settings' ), admin_url( 'admin.php' ) ), 'vyg_export_settings' ) ); ?>" class="button"><?php esc_html_e( 'Download settings (JSON)', 'vector-youtube-gallery' ); ?></a>
                    <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => AdminMenu::PARENT_SLUG . '-settings', 'vyg_op' => 'export_sources' ), admin_url( 'admin.php' ) ), 'vyg_export_sources' ) ); ?>" class="button"><?php esc_html_e( 'Download sources (JSON)', 'vector-youtube-gallery' ); ?></a>
                </p>
                <?php endif; ?>

                <?php if ( 'privacy' !== $current_tab ) : ?>
                    <?php submit_button( __( 'Save changes', 'vector-youtube-gallery' ) ); ?>
                <?php endif; ?>
            </form>
            <?php endif; ?>
        </div>
        <?php
    }
}