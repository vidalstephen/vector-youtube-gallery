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
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'YouTube Gallery — Settings', 'vector-youtube-gallery' ); ?></h1>

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

            <hr style="margin: 2em 0;" />

            <h2><?php echo esc_html__( 'Classification & Sync Settings', 'vector-youtube-gallery' ); ?></h2>
            <form method="post" action="">
                <?php wp_nonce_field( self::NONCE_ACTION, 'vyg_settings_nonce' ); ?>
                <input type="hidden" name="vyg_op" value="save_settings" />
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="shorts_max_duration_seconds"><?php echo esc_html__( 'Shorts max duration (seconds)', 'vector-youtube-gallery' ); ?></label>
                        </th>
                        <td>
                            <input type="number" min="1" max="300" id="shorts_max_duration_seconds" name="shorts_max_duration_seconds" value="<?php echo esc_attr( (string) $s['shorts_max_duration_seconds'] ); ?>" class="small-text" />
                            <p class="description"><?php echo esc_html__( 'YouTube policy is 60s. Anything at or below this duration is automatically promoted to short_confirmed if vertical.', 'vector-youtube-gallery' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="short_candidate_max_duration"><?php echo esc_html__( 'Short candidate max duration (seconds)', 'vector-youtube-gallery' ); ?></label>
                        </th>
                        <td>
                            <input type="number" min="60" max="600" id="short_candidate_max_duration" name="short_candidate_max_duration" value="<?php echo esc_attr( (string) $s['short_candidate_max_duration'] ); ?>" class="small-text" />
                            <p class="description"><?php echo esc_html__( 'Upper bound for ambiguous short videos (default 180s = 3 min).', 'vector-youtube-gallery' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="live_poll_interval_seconds"><?php echo esc_html__( 'Live active poll interval (seconds)', 'vector-youtube-gallery' ); ?></label>
                        </th>
                        <td>
                            <input type="number" min="60" max="3600" id="live_poll_interval_seconds" name="live_poll_interval_seconds" value="<?php echo esc_attr( (string) $s['live_poll_interval_seconds'] ); ?>" class="small-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="data_refresh_interval_days"><?php echo esc_html__( 'Data refresh interval (days)', 'vector-youtube-gallery' ); ?></label>
                        </th>
                        <td>
                            <input type="number" min="1" max="90" id="data_refresh_interval_days" name="data_refresh_interval_days" value="<?php echo esc_attr( (string) $s['data_refresh_interval_days'] ); ?>" class="small-text" />
                            <p class="description"><?php echo esc_html__( 'YouTube API Services policy recommends refreshing at least every 30 days.', 'vector-youtube-gallery' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="data_ttl_days"><?php echo esc_html__( 'Data TTL (days)', 'vector-youtube-gallery' ); ?></label>
                        </th>
                        <td>
                            <input type="number" min="30" max="365" id="data_ttl_days" name="data_ttl_days" value="<?php echo esc_attr( (string) $s['data_ttl_days'] ); ?>" class="small-text" />
                            <p class="description"><?php echo esc_html__( 'Stop serving stale data after this many days without successful refresh.', 'vector-youtube-gallery' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="data_hard_delete_after_days"><?php echo esc_html__( 'Hard delete after (days)', 'vector-youtube-gallery' ); ?></label>
                        </th>
                        <td>
                            <input type="number" min="180" max="730" id="data_hard_delete_after_days" name="data_hard_delete_after_days" value="<?php echo esc_attr( (string) $s['data_hard_delete_after_days'] ); ?>" class="small-text" />
                            <p class="description"><?php echo esc_html__( 'YouTube policy recommends permanently deleting data 365 days after the source video is deleted.', 'vector-youtube-gallery' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Auto-classify', 'vector-youtube-gallery' ); ?></th>
                        <td>
                            <label><input type="checkbox" name="auto_classify_shorts" value="1" <?php checked( $s['auto_classify_shorts'] ); ?> /> <?php echo esc_html__( 'Shorts (duration + tag)', 'vector-youtube-gallery' ); ?></label><br>
                            <label><input type="checkbox" name="auto_classify_live" value="1" <?php checked( $s['auto_classify_live'] ); ?> /> <?php echo esc_html__( 'Live (liveBroadcastContent + liveStreamingDetails)', 'vector-youtube-gallery' ); ?></label><br>
                            <label><input type="checkbox" name="respect_manual_overrides" value="1" <?php checked( $s['respect_manual_overrides'] ); ?> /> <?php echo esc_html__( 'Respect manual overrides', 'vector-youtube-gallery' ); ?></label>
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Save Settings', 'vector-youtube-gallery' ) ); ?>
            </form>
        </div>
        <?php
    }
}