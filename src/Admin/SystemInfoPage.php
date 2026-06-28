<?php
/**
 * SystemInfoPage — admin submenu with plugin + WP environment info for
 * support requests. Includes a copy-to-clipboard button (vanilla JS).
 *
 * @package VectorYT\Gallery\Admin
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Admin;

defined( 'ABSPATH' ) || exit;

final class SystemInfoPage {

    public function __construct(
        private readonly DashboardStats $stats,
    ) {}

    public function render(): void {
        $info = $this->stats->system_info();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'YouTube Gallery — System Info', 'vector-youtube-gallery' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Copy this block and paste it into support tickets. It contains your plugin version, DB version, WP/PHP versions, table row counts, and active cron events.', 'vector-youtube-gallery' ); ?>
            </p>
            <p>
                <button type="button" class="button button-primary" id="vyg-copy-sysinfo">
                    <?php esc_html_e( 'Copy to clipboard', 'vector-youtube-gallery' ); ?>
                </button>
                <span id="vyg-copy-confirm" style="margin-left: 1em; color: #0a0; display: none;">
                    <?php esc_html_e( 'Copied.', 'vector-youtube-gallery' ); ?>
                </span>
            </p>
            <textarea id="vyg-sysinfo-textarea" readonly style="width: 100%; min-height: 480px; font-family: monospace; font-size: 12px; background: #f6f7f7; padding: 1em;"><?php echo esc_textarea( wp_json_encode( $info, JSON_PRETTY_PRINT ) ); ?></textarea>

            <h2 style="margin-top: 2em;"><?php echo esc_html__( 'Human-readable breakdown', 'vector-youtube-gallery' ); ?></h2>
            <table class="widefat striped" style="max-width: 800px;">
                <tr>
                    <th><?php esc_html_e( 'Plugin version', 'vector-youtube-gallery' ); ?></th>
                    <td><code><?php echo esc_html( (string) $info['plugin_version'] ); ?></code></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'DB schema version', 'vector-youtube-gallery' ); ?></th>
                    <td><code><?php echo esc_html( (string) $info['db_version'] ); ?></code></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'WordPress version', 'vector-youtube-gallery' ); ?></th>
                    <td><code><?php echo esc_html( (string) $info['wp_version'] ); ?></code></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'PHP version', 'vector-youtube-gallery' ); ?></th>
                    <td><code><?php echo esc_html( (string) $info['php_version'] ); ?></code></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'MySQL version', 'vector-youtube-gallery' ); ?></th>
                    <td><code><?php echo esc_html( (string) $info['mysql_version'] ); ?></code></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Table prefix', 'vector-youtube-gallery' ); ?></th>
                    <td><code><?php echo esc_html( (string) $info['table_prefix'] ); ?></code></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Memory limit', 'vector-youtube-gallery' ); ?></th>
                    <td><code><?php echo esc_html( (string) $info['memory_limit'] ); ?></code></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Max execution time', 'vector-youtube-gallery' ); ?></th>
                    <td><code><?php echo (int) $info['max_execution_time']; ?>s</code></td>
                </tr>
            </table>

            <h3 style="margin-top: 2em;"><?php echo esc_html__( 'Tables', 'vector-youtube-gallery' ); ?></h3>
            <table class="widefat striped" style="max-width: 800px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Table', 'vector-youtube-gallery' ); ?></th>
                        <th><?php esc_html_e( 'Exists', 'vector-youtube-gallery' ); ?></th>
                        <th><?php esc_html_e( 'Row count', 'vector-youtube-gallery' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $info['tables'] as $short => $t ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( (string) $info['table_prefix'] . (string) $short ); ?></code></td>
                            <td><?php echo $t['exists'] ? '✅' : '❌'; ?></td>
                            <td><?php echo number_format_i18n( (int) $t['row_count'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h3 style="margin-top: 2em;"><?php echo esc_html__( 'Cron events', 'vector-youtube-gallery' ); ?></h3>
            <?php if ( empty( $info['cron_events'] ) ) : ?>
                <p><?php esc_html_e( 'No vyg_* cron events scheduled.', 'vector-youtube-gallery' ); ?></p>
            <?php else : ?>
                <table class="widefat striped" style="max-width: 800px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Hook', 'vector-youtube-gallery' ); ?></th>
                            <th><?php esc_html_e( 'Scheduled count', 'vector-youtube-gallery' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $info['cron_events'] as $hook => $count ) : ?>
                            <tr>
                                <td><code><?php echo esc_html( (string) $hook ); ?></code></td>
                                <td><?php echo (int) $count; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <script>
        (function() {
            var btn = document.getElementById('vyg-copy-sysinfo');
            var ta  = document.getElementById('vyg-sysinfo-textarea');
            var confirm = document.getElementById('vyg-copy-confirm');
            if (!btn || !ta) return;
            btn.addEventListener('click', function() {
                ta.select();
                try {
                    document.execCommand('copy');
                    confirm.style.display = 'inline';
                    setTimeout(function() { confirm.style.display = 'none'; }, 2000);
                } catch (e) {
                    alert('Copy failed — please select the text manually.');
                }
            });
        })();
        </script>
        <?php
    }
}