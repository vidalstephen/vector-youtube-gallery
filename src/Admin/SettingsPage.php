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

    private function render_html(
        bool $has_key,
        string $masked,
        ?string $validated_at,
        ?array $last_error,
        string $api_mode,
    ): void {
        $notice = isset( $_GET['vyg_notice'] ) ? sanitize_key( wp_unslash( $_GET['vyg_notice'] ) ) : '';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'YouTube Gallery — Settings', 'vector-youtube-gallery' ); ?></h1>

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
        </div>
        <?php
    }
}