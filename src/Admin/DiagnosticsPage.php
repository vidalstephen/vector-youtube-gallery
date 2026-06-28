<?php
/**
 * Diagnostics page — show API health, recent errors, quota usage.
 *
 * Phase 1: read-only view of SecretsRepository + QuotaTracker state.
 * Phase 2 will add sync_logs, sync_jobs status, last-sync timestamps.
 *
 * @package VectorYT\Gallery\Admin
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Admin;

use VectorYT\Gallery\Settings\SecretsRepository;
use VectorYT\Gallery\YouTube\ApiClientInterface;
use VectorYT\Gallery\YouTube\QuotaTracker;

defined( 'ABSPATH' ) || exit;

final class DiagnosticsPage {

    public function __construct(
        private readonly SecretsRepository $secrets,
        private readonly ApiClientInterface $api,
        private readonly QuotaTracker $quota,
    ) {}

    public function render(): void {
        if ( ! current_user_can( AdminMenu::REQUIRED_CAP ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'vector-youtube-gallery' ) );
        }

        $has_key       = $this->secrets->has_api_key();
        $masked        = SecretsRepository::mask( $this->secrets->get_api_key() );
        $validated_at  = $this->secrets->get_api_key_validated_at();
        $last_error    = $this->secrets->get_api_key_last_error();
        $api_mode      = $this->api->mode();
        $used_24h      = $this->quota->last_24h_units();
        $remaining     = $this->quota->remaining_estimate();
        $entries       = array_slice( array_reverse( $this->quota->entries() ), 0, 20 );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'YouTube Gallery — Diagnostics', 'vector-youtube-gallery' ); ?></h1>

            <h2><?php echo esc_html__( 'API Status', 'vector-youtube-gallery' ); ?></h2>
            <table class="widefat striped">
                <tbody>
                    <tr>
                        <th><?php echo esc_html__( 'Client mode', 'vector-youtube-gallery' ); ?></th>
                        <td><code><?php echo esc_html( $api_mode ); ?></code></td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__( 'API key stored', 'vector-youtube-gallery' ); ?></th>
                        <td>
                            <?php if ( $has_key ) : ?>
                                <code><?php echo esc_html( $masked ); ?></code>
                            <?php else : ?>
                                <em><?php echo esc_html__( 'Not set', 'vector-youtube-gallery' ); ?></em>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__( 'Last validated', 'vector-youtube-gallery' ); ?></th>
                        <td>
                            <?php echo null === $validated_at
                                ? '<em>' . esc_html__( 'Never', 'vector-youtube-gallery' ) . '</em>'
                                : esc_html( $validated_at ); ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__( 'Last error', 'vector-youtube-gallery' ); ?></th>
                        <td>
                            <?php if ( null === $last_error ) : ?>
                                <em><?php echo esc_html__( 'None', 'vector-youtube-gallery' ); ?></em>
                            <?php else : ?>
                                <code>[<?php echo esc_html( $last_error['code'] ); ?>]</code>
                                <?php echo esc_html( $last_error['message'] ); ?>
                                <span class="description">(<?php echo esc_html( $last_error['at'] ); ?>)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <h2><?php echo esc_html__( 'Quota Usage (estimated)', 'vector-youtube-gallery' ); ?></h2>
            <p>
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: 1: used units, 2: remaining units */
                        __( 'Used in last 24h: %1$d units. Remaining estimate: %2$d units (against default 10,000/day).', 'vector-youtube-gallery' ),
                        $used_24h,
                        $remaining
                    )
                );
                ?>
            </p>
            <?php if ( count( $entries ) > 0 ) : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__( 'Time', 'vector-youtube-gallery' ); ?></th>
                            <th><?php echo esc_html__( 'Endpoint', 'vector-youtube-gallery' ); ?></th>
                            <th><?php echo esc_html__( 'Units', 'vector-youtube-gallery' ); ?></th>
                            <th><?php echo esc_html__( 'HTTP', 'vector-youtube-gallery' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $entries as $e ) : ?>
                            <tr>
                                <td><?php echo esc_html( (string) ( $e['at'] ?? '' ) ); ?></td>
                                <td><code><?php echo esc_html( (string) ( $e['endpoint'] ?? '' ) ); ?></code></td>
                                <td><?php echo (int) ( $e['quota_units'] ?? 0 ); ?></td>
                                <td><?php echo esc_html( (string) ( $e['response_code'] ?? '—' ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <p class="description" style="margin-top:1em">
                <?php echo esc_html__( 'Phase 1 diagnostics. Phase 2 will add sync job state, last-sync timestamps, and detailed per-source health.', 'vector-youtube-gallery' ); ?>
            </p>
        </div>
        <?php
    }
}