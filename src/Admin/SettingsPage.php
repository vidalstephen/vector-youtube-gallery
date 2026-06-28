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
use VectorYT\Gallery\Settings\SecretsRepository;
use VectorYT\Gallery\Settings\SettingsRepository;
use VectorYT\Gallery\YouTube\ApiClientInterface;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

    private const NONCE_ACTION = 'vyg_settings_save';

    public function __construct(
        private readonly SecretsRepository $secrets,
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
            $this->maybe_handle_save();
            $this->maybe_handle_settings_save();
        }

        $has_key              = $this->secrets->has_api_key();
        $masked               = SecretsRepository::mask( $this->secrets->get_api_key() );
        $validated_at         = $this->secrets->get_api_key_validated_at();
        $last_error           = $this->secrets->get_api_key_last_error();
        $api_mode             = $this->api->mode();

        $this->render_html( $has_key, $masked, $validated_at, $last_error, $api_mode );
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

    private function redirect_with_notice( string $notice ): void {
        $url = add_query_arg(
            array( 'page' => AdminMenu::PARENT_SLUG . '-settings', 'vyg_notice' => $notice ),
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
    ): void {
        $notice = isset( $_GET['vyg_notice'] ) ? sanitize_key( wp_unslash( $_GET['vyg_notice'] ) ) : '';
        $s = $this->settings->all();
        $current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'api';
        $valid_tabs = array( 'api', 'classification', 'sync', 'live', 'privacy' );
        if ( ! in_array( $current_tab, $valid_tabs, true ) ) {
            $current_tab = 'api';
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'YouTube Gallery — Settings', 'vector-youtube-gallery' ); ?></h1>

            <nav class="nav-tab-wrapper">
                <?php
                $tab_labels = array(
                    'api'           => __( 'API', 'vector-youtube-gallery' ),
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