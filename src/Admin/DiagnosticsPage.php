<?php
/**
 * Diagnostics page — Phase 6 enriched.
 *
 * Sections:
 *  1. API Status (mode, masked key, validated_at, last error)
 *  2. Quota usage (24h + recent entries)
 *  3. Sync job health (recent jobs, failures)
 *  4. Source health (per-source last_success_at, status, staleness)
 *  5. Stale-data warnings (videos not refreshed in >N days)
 *  6. Recent errors from sync_logs (last 24h, deduped by message)
 *
 * Pure read — no side effects.
 *
 * @package VectorYT\Gallery\Admin
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Admin;

use VectorYT\Gallery\Settings\OAuthTokenRepository;
use VectorYT\Gallery\Settings\SecretsRepository;
use VectorYT\Gallery\YouTube\ApiClientInterface;
use VectorYT\Gallery\YouTube\QuotaTracker;

defined( 'ABSPATH' ) || exit;

final class DiagnosticsPage {

    /** Days since last successful sync = "stale". */
    private const STALE_DAYS = 7;

    public function __construct(
        private readonly SecretsRepository $secrets,
        private readonly ApiClientInterface $api,
        private readonly QuotaTracker $quota,
        private readonly OAuthTokenRepository $oauth_tokens,
    ) {}

    public function render(): void {
        if ( ! current_user_can( AdminMenu::REQUIRED_CAP ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'vector-youtube-gallery' ) );
        }

        $api_mode      = $this->api->mode();
        $masked        = SecretsRepository::mask( $this->secrets->get_api_key() );
        $validated_at  = $this->secrets->get_api_key_validated_at();
        $last_error    = $this->secrets->get_api_key_last_error();
        $used_24h      = $this->quota->last_24h_units();
        $remaining     = $this->quota->remaining_estimate();
        $entries       = array_slice( array_reverse( $this->quota->entries() ), 0, 20 );
        $oauth_status  = $this->oauth_tokens->diagnostics_status();

        $sync_jobs     = $this->recent_sync_jobs();
        $source_health = $this->source_health();
        $stale_videos  = $this->stale_videos();
        $recent_errors = $this->recent_errors();

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'YouTube Gallery — Diagnostics', 'vector-youtube-gallery' ); ?></h1>

            <h2><?php echo esc_html__( 'API Status', 'vector-youtube-gallery' ); ?></h2>
            <table class="widefat striped" style="max-width: 900px;">
                <tbody>
                    <tr>
                        <th style="width: 220px;"><?php echo esc_html__( 'Client mode', 'vector-youtube-gallery' ); ?></th>
                        <td><code><?php echo esc_html( $api_mode ); ?></code></td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__( 'API key stored', 'vector-youtube-gallery' ); ?></th>
                        <td>
                            <?php if ( $this->secrets->has_api_key() ) : ?>
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
                        <th><?php echo esc_html__( 'Last API error', 'vector-youtube-gallery' ); ?></th>
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

            <h2><?php echo esc_html__( 'OAuth Health', 'vector-youtube-gallery' ); ?></h2>
            <table class="widefat striped" style="max-width: 900px;">
                <tbody>
                    <tr>
                        <th style="width: 220px;"><?php echo esc_html__( 'Client configured', 'vector-youtube-gallery' ); ?></th>
                        <td><?php echo $oauth_status['client_configured'] ? '<span style="color:#0a0;">✓</span> ' . esc_html__( 'Yes', 'vector-youtube-gallery' ) : '<em>' . esc_html__( 'No', 'vector-youtube-gallery' ) . '</em>'; ?></td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__( 'Client ID', 'vector-youtube-gallery' ); ?></th>
                        <td><?php echo '' !== $oauth_status['client_id_masked'] ? '<code>' . esc_html( $oauth_status['client_id_masked'] ) . '</code>' : '<em>' . esc_html__( 'Not set', 'vector-youtube-gallery' ) . '</em>'; ?></td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__( 'Connected', 'vector-youtube-gallery' ); ?></th>
                        <td><?php echo $oauth_status['connected'] ? '<span style="color:#0a0;">✓</span> ' . esc_html__( 'Yes', 'vector-youtube-gallery' ) : '<em>' . esc_html__( 'No', 'vector-youtube-gallery' ) . '</em>'; ?></td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__( 'Connected account', 'vector-youtube-gallery' ); ?></th>
                        <td>
                            <?php if ( ! empty( $oauth_status['connected_account'] ) ) : ?>
                                <?php foreach ( $oauth_status['connected_account'] as $key => $value ) : ?>
                                    <div><code><?php echo esc_html( (string) $key ); ?></code>: <?php echo esc_html( (string) $value ); ?></div>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <em><?php echo esc_html__( 'Not available', 'vector-youtube-gallery' ); ?></em>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__( 'Token metadata', 'vector-youtube-gallery' ); ?></th>
                        <td>
                            <div><?php echo esc_html__( 'Token type', 'vector-youtube-gallery' ); ?>: <code><?php echo esc_html( (string) ( $oauth_status['token_type'] ?? '—' ) ); ?></code></div>
                            <div><?php echo esc_html__( 'Refresh token stored', 'vector-youtube-gallery' ); ?>: <?php echo $oauth_status['has_refresh_token'] ? esc_html__( 'Yes', 'vector-youtube-gallery' ) : esc_html__( 'No', 'vector-youtube-gallery' ); ?></div>
                            <div><?php echo esc_html__( 'Created', 'vector-youtube-gallery' ); ?>: <?php echo $oauth_status['created_at'] ? esc_html( (string) $oauth_status['created_at'] ) : '—'; ?></div>
                            <div><?php echo esc_html__( 'Updated', 'vector-youtube-gallery' ); ?>: <?php echo $oauth_status['updated_at'] ? esc_html( (string) $oauth_status['updated_at'] ) : '—'; ?></div>
                            <div><?php echo esc_html__( 'Token age', 'vector-youtube-gallery' ); ?>: <?php echo null === $oauth_status['token_age_seconds'] ? '—' : esc_html( human_time_diff( time() - (int) $oauth_status['token_age_seconds'], time() ) ); ?></div>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__( 'Expiry', 'vector-youtube-gallery' ); ?></th>
                        <td>
                            <?php if ( $oauth_status['expires_at'] ) : ?>
                                <?php echo esc_html( (string) $oauth_status['expires_at'] ); ?>
                                <?php if ( $oauth_status['expired'] ) : ?>
                                    <strong style="color:#d00;"> <?php echo esc_html__( 'Expired', 'vector-youtube-gallery' ); ?></strong>
                                <?php else : ?>
                                    <span style="color:#777;">(<?php echo esc_html( human_time_diff( time(), time() + (int) $oauth_status['seconds_to_expiry'] ) ); ?> <?php echo esc_html__( 'remaining', 'vector-youtube-gallery' ); ?>)</span>
                                <?php endif; ?>
                            <?php else : ?>
                                <em><?php echo esc_html__( 'Unknown', 'vector-youtube-gallery' ); ?></em>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__( 'Scopes', 'vector-youtube-gallery' ); ?></th>
                        <td>
                            <?php if ( ! empty( $oauth_status['scopes'] ) ) : ?>
                                <?php foreach ( $oauth_status['scopes'] as $scope ) : ?>
                                    <code style="display:inline-block;margin:0 4px 4px 0;"><?php echo esc_html( (string) $scope ); ?></code>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <em><?php echo esc_html__( 'None recorded', 'vector-youtube-gallery' ); ?></em>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__( 'Last refresh error', 'vector-youtube-gallery' ); ?></th>
                        <td>
                            <?php if ( ! empty( $oauth_status['last_refresh_error'] ) ) : ?>
                                <code>[<?php echo esc_html( (string) $oauth_status['last_refresh_error']['code'] ); ?>]</code>
                                <?php echo esc_html( (string) $oauth_status['last_refresh_error']['message'] ); ?>
                                <span class="description">(<?php echo esc_html( (string) $oauth_status['last_refresh_error']['at'] ); ?>)</span>
                            <?php else : ?>
                                <em><?php echo esc_html__( 'None', 'vector-youtube-gallery' ); ?></em>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <h2><?php echo esc_html__( 'Quota Usage (last 24h)', 'vector-youtube-gallery' ); ?></h2>
            <p>
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: 1: used units, 2: remaining units */
                        __( 'Used: %1$d units. Remaining estimate: %2$d units (against default 10,000/day).', 'vector-youtube-gallery' ),
                        $used_24h,
                        $remaining
                    )
                );
                ?>
            </p>
            <?php if ( count( $entries ) > 0 ) : ?>
                <table class="widefat striped" style="max-width: 900px;">
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

            <h2><?php echo esc_html__( 'Sync job health', 'vector-youtube-gallery' ); ?></h2>
            <?php if ( empty( $sync_jobs ) ) : ?>
                <p><em><?php echo esc_html__( 'No sync jobs yet.', 'vector-youtube-gallery' ); ?></em></p>
            <?php else : ?>
                <table class="widefat striped" style="max-width: 900px;">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__( 'ID', 'vector-youtube-gallery' ); ?></th>
                            <th><?php echo esc_html__( 'Type', 'vector-youtube-gallery' ); ?></th>
                            <th><?php echo esc_html__( 'Source', 'vector-youtube-gallery' ); ?></th>
                            <th><?php echo esc_html__( 'Status', 'vector-youtube-gallery' ); ?></th>
                            <th><?php echo esc_html__( 'Attempts', 'vector-youtube-gallery' ); ?></th>
                            <th><?php echo esc_html__( 'Last update', 'vector-youtube-gallery' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $sync_jobs as $job ) :
                            $status  = (string) ( $job['status'] ?? '' );
                            $color   = 'done' === $status ? '#0a0' : ( 'failed' === $status ? '#d00' : ( 'running' === $status ? '#f0a020' : '#777' ) );
                            $updated = $job['completed_at'] ?? $job['started_at'] ?? '';
                        ?>
                            <tr>
                                <td>#<?php echo (int) $job['id']; ?></td>
                                <td><?php echo esc_html( (string) ( $job['job_type'] ?? '' ) ); ?></td>
                                <td><?php echo ! empty( $job['source_id'] ) ? '#' . (int) $job['source_id'] : '—'; ?></td>
                                <td><span style="color: <?php echo esc_attr( $color ); ?>; font-weight: 600;"><?php echo esc_html( $status ); ?></span></td>
                                <td><?php echo (int) ( $job['attempts'] ?? 0 ); ?></td>
                                <td><?php echo $updated ? esc_html( (string) $updated ) : '—'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2><?php echo esc_html__( 'Source health', 'vector-youtube-gallery' ); ?></h2>
            <?php if ( empty( $source_health ) ) : ?>
                <p><em><?php echo esc_html__( 'No sources configured.', 'vector-youtube-gallery' ); ?></em></p>
            <?php else : ?>
                <table class="widefat striped" style="max-width: 900px;">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__( 'Source', 'vector-youtube-gallery' ); ?></th>
                            <th><?php echo esc_html__( 'Status', 'vector-youtube-gallery' ); ?></th>
                            <th><?php echo esc_html__( 'Last success', 'vector-youtube-gallery' ); ?></th>
                            <th><?php echo esc_html__( 'Last error', 'vector-youtube-gallery' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $source_health as $s ) : ?>
                            <tr>
                                <td><?php echo esc_html( (string) ( $s['title'] ?? '(untitled)' ) ); ?> <code style="color:#777;"><?php echo esc_html( (string) ( $s['source_type'] ?? '' ) ); ?></code></td>
                                <td><span class="vyg-status-badge vyg-status-badge--<?php echo esc_attr( (string) ( $s['status'] ?? 'unknown' ) ); ?>"><?php echo esc_html( (string) ( $s['status'] ?? 'unknown' ) ); ?></span></td>
                                <td>
                                    <?php
                                    $last = $s['last_success_at'] ?? null;
                                    if ( $last ) {
                                        $ago = human_time_diff( strtotime( (string) $last ) );
                                        echo esc_html( (string) $last ) . ' <span style="color:#777;">(' . esc_html( $ago ) . ' ago)</span>';
                                    } else {
                                        echo '<em style="color:#d00;">' . esc_html__( 'never', 'vector-youtube-gallery' ) . '</em>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php if ( ! empty( $s['last_error'] ) ) : ?>
                                        <code><?php echo esc_html( wp_json_encode( $s['last_error'] ) ); ?></code>
                                    <?php else : ?>
                                        <span style="color:#0a0;">✓</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2><?php echo esc_html__( 'Stale videos (>' . (string) self::STALE_DAYS . ' days since refresh)', 'vector-youtube-gallery' ); ?></h2>
            <?php if ( $stale_videos === 0 ) : ?>
                <p><span style="color:#0a0;">✓</span> <?php echo esc_html__( 'No stale data.', 'vector-youtube-gallery' ); ?></p>
            <?php else : ?>
                <p style="color:#f0a020;">⚠ <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %d: count */
                            _n( '%d video has not been refreshed in over %d days.', '%d videos have not been refreshed in over %d days.', $stale_videos, 'vector-youtube-gallery' ),
                            $stale_videos,
                            self::STALE_DAYS
                        )
                    );
                ?></p>
            <?php endif; ?>

            <h2><?php echo esc_html__( 'Recent errors (24h)', 'vector-youtube-gallery' ); ?></h2>
            <?php if ( empty( $recent_errors ) ) : ?>
                <p><span style="color:#0a0;">✓</span> <?php echo esc_html__( 'No errors logged in the last 24 hours.', 'vector-youtube-gallery' ); ?></p>
            <?php else : ?>
                <table class="widefat striped" style="max-width: 900px;">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__( 'Time', 'vector-youtube-gallery' ); ?></th>
                            <th><?php echo esc_html__( 'Source', 'vector-youtube-gallery' ); ?></th>
                            <th><?php echo esc_html__( 'Message', 'vector-youtube-gallery' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $recent_errors as $err ) : ?>
                            <tr>
                                <td><?php echo esc_html( (string) ( $err['created_at'] ?? '' ) ); ?></td>
                                <td><?php echo ! empty( $err['source_id'] ) ? '#' . (int) $err['source_id'] : '—'; ?></td>
                                <td><code><?php echo esc_html( (string) ( $err['message'] ?? '' ) ); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function recent_sync_jobs(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, job_type, source_id, status, attempts, started_at, completed_at
             FROM {$wpdb->prefix}vyg_sync_jobs
             ORDER BY id DESC LIMIT 10",
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function source_health(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, source_type, title, status, last_success_at, last_error_code, last_error_message
             FROM {$wpdb->prefix}vyg_sources
             ORDER BY id DESC",
            ARRAY_A
        );
        if ( ! is_array( $rows ) ) {
            return array();
        }
        return array_map( static function ( array $row ): array {
            $code = isset( $row['last_error_code'] ) ? (string) $row['last_error_code'] : '';
            $message = isset( $row['last_error_message'] ) ? (string) $row['last_error_message'] : '';
            $row['last_error'] = '' === $code && '' === $message ? null : array(
                'code'    => $code,
                'message' => $message,
            );
            return $row;
        }, $rows );
    }

    private function stale_videos(): int {
        global $wpdb;
        $threshold = gmdate( 'Y-m-d H:i:s', time() - ( self::STALE_DAYS * DAY_IN_SECONDS ) );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}vyg_videos
             WHERE availability_status='available'
               AND ( last_success_at IS NULL OR last_success_at < %s )",
            $threshold
        ) );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function recent_errors(): array {
        global $wpdb;
        $threshold = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT created_at, source_id, message
             FROM {$wpdb->prefix}vyg_sync_logs
             WHERE level='error' AND created_at >= %s
             ORDER BY id DESC LIMIT 20",
            $threshold
        ), ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }
}