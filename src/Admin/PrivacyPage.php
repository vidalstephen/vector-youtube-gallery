<?php
/**
 * PrivacyPage — Phase 6 admin UI for privacy, compliance, retention, disconnect.
 *
 * Sections (in render order):
 *  1. Stored data overview (counts per table)
 *  2. Retention manager (preview + run sweep)
 *  3. Clean uninstall toggle (admin setting; uninstall.php reads it)
 *  4. Disconnect (revoke API key + flip sources to disconnected)
 *  5. Export / Import settings JSON (via ImporterExporter)
 *  6. Privacy policy generator (suggested text)
 *  7. GDPR links (WP's built-in Export/Erase Personal Data tools)
 *
 * @package VectorYT\Gallery\Admin
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Admin;

use VectorYT\Gallery\Settings\SettingsRepository;
use VectorYT\Gallery\Compliance\DataRetentionManager;
use VectorYT\Gallery\Compliance\DisconnectManager;
use VectorYT\Gallery\Compliance\PrivacyPolicyGenerator;
use VectorYT\Gallery\Admin\ImporterExporter;
use VectorYT\Gallery\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class PrivacyPage {

    private const NONCE_ACTION = 'vyg_privacy_action';
    private const NONCE_FIELD  = '_vyg_privacy_nonce';
    private const SETTINGS_KEY_RETENTION = 'data_retention_days';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly DataRetentionManager $retention,
        private readonly DisconnectManager $disconnector,
        private readonly PrivacyPolicyGenerator $policy_generator,
        private readonly ImporterExporter $importer_exporter,
        private readonly Logger $logger,
    ) {}

    public function render(): void {
        if ( ! current_user_can( AdminMenu::REQUIRED_CAP ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'vector-youtube-gallery' ) );
        }

        $msg = '';
        if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
            $msg = $this->handle_post();
        }

        $this->render_page( $msg );
    }

    /**
     * @return string Admin notice to display.
     */
    private function handle_post(): string {
        if ( ! current_user_can( AdminMenu::REQUIRED_CAP ) ) {
            return __( 'Insufficient permissions.', 'vector-youtube-gallery' );
        }
        $nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
            return __( 'Nonce check failed.', 'vector-youtube-gallery' );
        }

        $op = isset( $_POST['vyg_privacy_op'] ) ? sanitize_key( wp_unslash( $_POST['vyg_privacy_op'] ) ) : '';

        if ( 'run_retention' === $op ) {
            $stats = $this->retention->run_sweep();
            return sprintf(
                /* translators: 1: marked expired, 2: hard-deleted videos, 3: deleted logs, 4: deleted previous streams */
                __( 'Retention sweep complete: %1$d videos marked expired, %2$d hard-deleted, %3$d sync logs deleted, %4$d previous streams deleted.', 'vector-youtube-gallery' ),
                (int) $stats['videos_marked_expired'],
                (int) $stats['videos_hard_deleted'],
                (int) $stats['sync_logs_deleted'],
                (int) $stats['previous_streams_deleted']
            );
        }

        if ( 'save_retention' === $op ) {
            $days = isset( $_POST['data_retention_days'] ) ? max( 1, min( 365, absint( wp_unslash( $_POST['data_retention_days'] ) ) ) ) : 90;
            $this->settings->update( self::SETTINGS_KEY_RETENTION, $days );
            return __( 'Retention setting saved.', 'vector-youtube-gallery' );
        }

        if ( 'save_clean_uninstall' === $op ) {
            $enabled = ! empty( $_POST['clean_uninstall'] ) ? '1' : '0';
            update_option( 'vyg_clean_uninstall', $enabled, false );
            return __( 'Clean-uninstall preference saved.', 'vector-youtube-gallery' );
        }

        if ( 'disconnect' === $op ) {
            $result = $this->disconnector->disconnect_all();
            return sprintf(
                /* translators: 1: revoked bool, 2: source count */
                __( 'Disconnected: API key %1$s, %2$d sources flipped to disconnected.', 'vector-youtube-gallery' ),
                $result['revoked'] ? __( 'revoked', 'vector-youtube-gallery' ) : __( 'deletion attempted (no revoke endpoint in API-key mode)', 'vector-youtube-gallery' ),
                (int) $result['sources_disconnected']
            );
        }

        if ( 'import_settings' === $op ) {
            $raw = isset( $_POST['import_json'] ) ? (string) wp_unslash( $_POST['import_json'] ) : '';
            $result = $this->importer_exporter->import_settings( $raw );
            if ( $result['ok'] ) {
                return sprintf(
                    /* translators: %d: count */
                    __( 'Imported %d settings successfully.', 'vector-youtube-gallery' ),
                    (int) $result['imported']
                );
            }
            return __( 'Import failed: ', 'vector-youtube-gallery' ) . implode( '; ', $result['errors'] );
        }

        return __( 'Unknown action.', 'vector-youtube-gallery' );
    }

    private function render_page( string $msg ): void {
        $counts           = $this->table_counts();
        $retention_days   = (int) $this->settings->get( self::SETTINGS_KEY_RETENTION, 90 );
        $retention_prev   = $this->retention->preview();
        $clean_uninstall  = (string) get_option( 'vyg_clean_uninstall', '0' );
        $export_json      = $this->importer_exporter->export_settings();
        $policy_text      = $this->policy_generator->generate( array( 'retention_days' => $retention_days ) );

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'YouTube Gallery — Privacy & Compliance', 'vector-youtube-gallery' ); ?></h1>

            <?php if ( $msg ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
            <?php endif; ?>

            <h2><?php echo esc_html__( 'Stored data', 'vector-youtube-gallery' ); ?></h2>
            <table class="widefat striped" style="max-width: 700px;">
                <tbody>
                    <?php foreach ( $counts as $label => $count ) : ?>
                        <tr>
                            <th><?php echo esc_html( $label ); ?></th>
                            <td><?php echo number_format_i18n( (int) $count ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h2><?php echo esc_html__( 'Retention manager', 'vector-youtube-gallery' ); ?></h2>
            <form method="post">
                <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                <input type="hidden" name="vyg_privacy_op" value="save_retention" />
                <p>
                    <label for="data_retention_days"><?php echo esc_html__( 'Retention window (days):', 'vector-youtube-gallery' ); ?></label>
                    <input name="data_retention_days" id="data_retention_days" type="number" min="1" max="365" value="<?php echo (int) $retention_days; ?>" />
                    <button type="submit" class="button"><?php echo esc_html__( 'Save', 'vector-youtube-gallery' ); ?></button>
                </p>
            </form>

            <p><strong><?php echo esc_html__( 'Preview — what the next sweep would do:', 'vector-youtube-gallery' ); ?></strong></p>
            <ul style="list-style: disc; margin-left: 2em;">
                <li><?php
                    echo esc_html( sprintf(
                        /* translators: %d: count */
                        _n( '%d video would be marked expired (not refreshed within retention window).', '%d videos would be marked expired.', (int) $retention_prev['videos_marked_expired'], 'vector-youtube-gallery' ),
                        (int) $retention_prev['videos_marked_expired']
                    ) );
                ?></li>
                <li><?php
                    echo esc_html( sprintf(
                        _n( '%d video would be hard-deleted.', '%d videos would be hard-deleted.', (int) $retention_prev['videos_hard_deleted'], 'vector-youtube-gallery' ),
                        (int) $retention_prev['videos_hard_deleted']
                    ) );
                ?></li>
                <li><?php
                    echo esc_html( sprintf(
                        _n( '%d sync log would be deleted.', '%d sync logs would be deleted.', (int) $retention_prev['sync_logs_deleted'], 'vector-youtube-gallery' ),
                        (int) $retention_prev['sync_logs_deleted']
                    ) );
                ?></li>
                <li><?php
                    echo esc_html( sprintf(
                        _n( '%d previous stream would be deleted.', '%d previous streams would be deleted.', (int) $retention_prev['previous_streams_deleted'], 'vector-youtube-gallery' ),
                        (int) $retention_prev['previous_streams_deleted']
                    ) );
                ?></li>
            </ul>

            <form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Run retention sweep now? This will delete rows above.', 'vector-youtube-gallery' ) ); ?>');">
                <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                <input type="hidden" name="vyg_privacy_op" value="run_retention" />
                <button type="submit" class="button button-primary"><?php echo esc_html__( 'Run retention sweep now', 'vector-youtube-gallery' ); ?></button>
            </form>

            <h2><?php echo esc_html__( 'Clean uninstall', 'vector-youtube-gallery' ); ?></h2>
            <form method="post">
                <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                <input type="hidden" name="vyg_privacy_op" value="save_clean_uninstall" />
                <label>
                    <input type="checkbox" name="clean_uninstall" value="1" <?php checked( $clean_uninstall, '1' ); ?> />
                    <?php echo esc_html__( 'When this plugin is deleted, remove ALL stored data (tables, options, transients, cron events). Default is OFF — data persists on plugin deletion so a reinstall restores everything.', 'vector-youtube-gallery' ); ?>
                </label>
                <p><button type="submit" class="button"><?php echo esc_html__( 'Save preference', 'vector-youtube-gallery' ); ?></button></p>
            </form>

            <h2><?php echo esc_html__( 'Disconnect from YouTube', 'vector-youtube-gallery' ); ?></h2>
            <p><?php echo esc_html__( 'Revokes the connected OAuth token when present, removes stored API/OAuth credentials, marks all sources as disconnected, and keeps local video metadata in the database.', 'vector-youtube-gallery' ); ?></p>
            <form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Disconnect from YouTube?', 'vector-youtube-gallery' ) ); ?>');">
                <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                <input type="hidden" name="vyg_privacy_op" value="disconnect" />
                <button type="submit" class="button button-secondary"><?php echo esc_html__( 'Disconnect all sources', 'vector-youtube-gallery' ); ?></button>
            </form>

            <h2><?php echo esc_html__( 'Export / Import settings', 'vector-youtube-gallery' ); ?></h2>
            <p><?php echo esc_html__( 'Settings (sync intervals, classification thresholds, etc.) — not videos or sources.', 'vector-youtube-gallery' ); ?></p>
            <p><a href="<?php echo esc_url( admin_url( 'admin-post.php?action=vyg_export_settings&_wpnonce=' . wp_create_nonce( 'vyg_export_settings' ) ) ); ?>" class="button"><?php echo esc_html__( 'Download settings JSON', 'vector-youtube-gallery' ); ?></a></p>
            <form method="post">
                <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                <input type="hidden" name="vyg_privacy_op" value="import_settings" />
                <textarea name="import_json" rows="6" class="large-text code" placeholder="<?php echo esc_attr__( 'Paste settings JSON here…', 'vector-youtube-gallery' ); ?>"></textarea>
                <p><button type="submit" class="button"><?php echo esc_html__( 'Import settings JSON', 'vector-youtube-gallery' ); ?></button></p>
            </form>

            <h2><?php echo esc_html__( 'Suggested privacy policy text', 'vector-youtube-gallery' ); ?></h2>
            <p><?php echo esc_html__( 'Copy this into your site\'s privacy policy. It explains the data the plugin stores and why.', 'vector-youtube-gallery' ); ?></p>
            <textarea readonly rows="14" class="large-text" style="font-family: sans-serif;"><?php echo esc_textarea( $policy_text ); ?></textarea>

            <h2><?php echo esc_html__( 'Personal data tools (GDPR/CCPA)', 'vector-youtube-gallery' ); ?></h2>
            <p><?php echo esc_html__( 'The plugin registers as a personal-data exporter and eraser. Use the WP admin tools below to respond to visitor requests.', 'vector-youtube-gallery' ); ?></p>
            <p>
                <a href="<?php echo esc_url( admin_url( 'export-personal-data.php' ) ); ?>" class="button"><?php echo esc_html__( 'Export Personal Data', 'vector-youtube-gallery' ); ?></a>
                <a href="<?php echo admin_url( 'erase-personal-data.php' ); ?>" class="button"><?php echo esc_html__( 'Erase Personal Data', 'vector-youtube-gallery' ); ?></a>
            </p>
        </div>
        <?php
    }

    /**
     * @return array<string,int>
     */
    private function table_counts(): array {
        global $wpdb;
        $out = array();
        $tables = array(
            __( 'Sources', 'vector-youtube-gallery' )     => 'vyg_sources',
            __( 'Videos', 'vector-youtube-gallery' )      => 'vyg_videos',
            __( 'Playlists', 'vector-youtube-gallery' )   => 'vyg_playlists',
            __( 'Playlist→Video map', 'vector-youtube-gallery' ) => 'vyg_playlist_video_map',
            __( 'Feeds', 'vector-youtube-gallery' )       => 'vyg_feeds',
            __( 'Feed video overrides', 'vector-youtube-gallery' ) => 'vyg_feed_video_overrides',
            __( 'Sync jobs', 'vector-youtube-gallery' )    => 'vyg_sync_jobs',
            __( 'Sync logs', 'vector-youtube-gallery' )    => 'vyg_sync_logs',
            __( 'API quota log', 'vector-youtube-gallery' )=> 'vyg_api_quota_log',
            __( 'Previous streams', 'vector-youtube-gallery' ) => 'vyg_previous_streams',
        );
        foreach ( $tables as $label => $short ) {
            $out[ $label ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}{$short}" );
        }
        return $out;
    }
}