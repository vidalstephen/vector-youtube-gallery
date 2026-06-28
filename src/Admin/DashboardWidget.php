<?php
/**
 * DashboardWidget — registers the wp-admin dashboard widget.
 *
 * Renders: 4 stat cards (sources/videos/quota/errors), 1 mini gauge for quota,
 * recent sync jobs list, live-poll freshness indicator. Pure read — no side effects.
 *
 * @package VectorYT\Gallery\Admin
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Admin;

defined( 'ABSPATH' ) || exit;

final class DashboardWidget {

    public function __construct(
        private readonly DashboardStats $stats,
    ) {}

    public function register(): void {
        add_action( 'wp_dashboard_setup', array( $this, 'add_widget' ) );
    }

    public function add_widget(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        wp_add_dashboard_widget(
            'vyg_dashboard_widget',
            __( 'Vector YouTube Gallery — Status', 'vector-youtube-gallery' ),
            array( $this, 'render' )
        );
    }

    public function render(): void {
        $s = $this->stats->collect();
        $quota_pct = (int) ( $s['quota']['percent'] ?? 0 );
        $quota_color = $quota_pct >= 90 ? '#d00' : ( $quota_pct >= 70 ? '#f0a020' : '#0a0' );
        ?>
        <div class="vyg-dashboard">
            <div class="vyg-dash-row">
                <div class="vyg-dash-card">
                    <div class="vyg-dash-card__label"><?php esc_html_e( 'Sources', 'vector-youtube-gallery' ); ?></div>
                    <div class="vyg-dash-card__value"><?php echo (int) $s['sources']['total']; ?></div>
                    <div class="vyg-dash-card__sub">
                        <?php
                        /* translators: 1: active, 2: paused, 3: error */
                        echo esc_html( sprintf( __( '%1$d active · %2$d paused · %3$d error', 'vector-youtube-gallery' ), (int) $s['sources']['active'], (int) $s['sources']['paused'], (int) $s['sources']['error'] ) );
                        ?>
                    </div>
                </div>
                <div class="vyg-dash-card">
                    <div class="vyg-dash-card__label"><?php esc_html_e( 'Videos', 'vector-youtube-gallery' ); ?></div>
                    <div class="vyg-dash-card__value"><?php echo (int) $s['videos']['total']; ?></div>
                    <div class="vyg-dash-card__sub">
                        <?php
                        /* translators: 1: live, 2: available */
                        echo esc_html( sprintf( __( '%1$d live · %2$d available', 'vector-youtube-gallery' ), (int) $s['videos']['live'], (int) $s['videos']['available'] ) );
                        ?>
                    </div>
                </div>
                <div class="vyg-dash-card">
                    <div class="vyg-dash-card__label"><?php esc_html_e( 'Quota today', 'vector-youtube-gallery' ); ?></div>
                    <div class="vyg-dash-card__value"><?php echo (int) $s['quota']['used']; ?> / <?php echo (int) $s['quota']['limit']; ?></div>
                    <div class="vyg-dash-card__gauge">
                        <div class="vyg-dash-card__gauge-bar" style="width: <?php echo (int) $quota_pct; ?>%; background: <?php echo esc_attr( $quota_color ); ?>;"></div>
                    </div>
                    <div class="vyg-dash-card__sub"><?php echo (int) $quota_pct; ?>%</div>
                </div>
                <div class="vyg-dash-card">
                    <div class="vyg-dash-card__label"><?php esc_html_e( 'Errors (24h)', 'vector-youtube-gallery' ); ?></div>
                    <div class="vyg-dash-card__value" style="color: <?php echo (int) $s['errors_24h'] > 0 ? '#d00' : '#0a0'; ?>">
                        <?php echo (int) $s['errors_24h']; ?>
                    </div>
                    <div class="vyg-dash-card__sub"><?php esc_html_e( 'sync_logs level=error', 'vector-youtube-gallery' ); ?></div>
                </div>
            </div>

            <h3 style="margin-top:1em; margin-bottom:0.5em;"><?php esc_html_e( 'Recent sync jobs', 'vector-youtube-gallery' ); ?></h3>
            <table class="widefat striped" style="font-size: 0.9em;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'ID', 'vector-youtube-gallery' ); ?></th>
                        <th><?php esc_html_e( 'Type', 'vector-youtube-gallery' ); ?></th>
                        <th><?php esc_html_e( 'Source', 'vector-youtube-gallery' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'vector-youtube-gallery' ); ?></th>
                        <th><?php esc_html_e( 'Attempts', 'vector-youtube-gallery' ); ?></th>
                        <th><?php esc_html_e( 'Completed', 'vector-youtube-gallery' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $s['recent_jobs'] ) ) : ?>
                        <tr><td colspan="6" style="text-align: center; color: #777;"><?php esc_html_e( 'No sync jobs yet.', 'vector-youtube-gallery' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $s['recent_jobs'] as $job ) : ?>
                            <tr>
                                <td>#<?php echo (int) $job['id']; ?></td>
                                <td><?php echo esc_html( (string) $job['job_type'] ); ?></td>
                                <td><?php echo $job['source_id'] ? '#' . (int) $job['source_id'] : '—'; ?></td>
                                <td>
                                    <?php
                                    $status = (string) $job['status'];
                                    $color  = 'done' === $status ? '#0a0' : ( 'failed' === $status ? '#d00' : ( 'running' === $status ? '#f0a020' : '#777' ) );
                                    ?>
                                    <span style="color: <?php echo esc_attr( $color ); ?>; font-weight: 600;"><?php echo esc_html( $status ); ?></span>
                                </td>
                                <td><?php echo (int) $job['attempts']; ?></td>
                                <td><?php echo $job['completed_at'] ? esc_html( (string) $job['completed_at'] ) : '—'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <p style="margin-top: 1em; color: #777; font-size: 0.85em;">
                <?php
                $last_sync = $s['last_sync'] ? esc_html( (string) $s['last_sync'] ) : '—';
                $last_live = $s['last_live_poll'] ? esc_html( (string) $s['last_live_poll'] ) : '—';
                /* translators: 1: last sync, 2: last live poll */
                echo esc_html( sprintf( __( 'Last sync: %1$s · Last live poll: %2$s', 'vector-youtube-gallery' ), $last_sync, $last_live ) );
                ?>
            </p>
        </div>
        <?php
    }
}