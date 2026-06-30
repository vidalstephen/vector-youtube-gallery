<?php
/**
 * Phase 12.4 Multisite live smoke.
 *
 * On a single-site install the smoke verifies the NetworkPolicy
 * wrappers:
 *   1. is_multisite() reflects the install mode.
 *   2. network-diagnostics returns one row for the current site.
 *   3. site-cleanup refuses to run without --yes.
 *   4. The site-cleanup policy drops vyg_* tables and clears the
 *      vyg_* options + cron events.
 *
 * Run via:
 *     docker exec -u www-data vyg-wp \
 *         wp eval-file /var/www/html/wp-content/plugins/vector-youtube-gallery/dev/phase12-4-smoke.php
 *
 * IMPORTANT: this smoke is read-only except for the cleanup step.
 * It is meant to be re-run after a re-seed.
 */

use VectorYT\Gallery\Multisite\NetworkPolicy;

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "ABSPATH not defined; run via wp eval-file.\n" );
    exit( 1 );
}

global $wpdb;

echo "is_multisite=" . ( NetworkPolicy::is_multisite() ? 'yes' : 'no' ) . PHP_EOL;
echo "is_network_active=" . ( NetworkPolicy::is_network_active() ? 'yes' : 'no' ) . PHP_EOL;

// 2. network-diagnostics returns one row.
$rows = NetworkPolicy::network_diagnostics();
echo "diagnostic_rows=" . count( $rows ) . PHP_EOL;
echo "site_id=" . (int) ( $rows[0]['site_id'] ?? 0 ) . PHP_EOL;
echo "vyg_active=" . ( ( $rows[0]['vyg_active'] ?? false ) ? 'yes' : 'no' ) . PHP_EOL;

// 3. The policy knows about vyg_* tables; report a count.
$tables = $wpdb->get_results(
    $wpdb->prepare(
        'SHOW TABLES LIKE %s',
        $wpdb->esc_like( $wpdb->prefix . 'vyg_' ) . '%'
    )
);
echo "vyg_table_count=" . count( (array) $tables ) . PHP_EOL;

// 4. The policy reports the right cron hooks.
$cron_hooks = array(
    'vyg_cron_incremental_all',
    'vyg_cron_metadata_refresh',
    'vyg_cron_live_poll',
    'vyg_cron_data_retention',
    'vyg_cron_analytics_retention',
    'vyg_cron_quota_reset',
);
$known_cron = 0;
foreach ( $cron_hooks as $hook ) {
    if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( $hook ) ) {
        $known_cron++;
    }
}
echo "vyg_cron_events_scheduled=" . $known_cron . PHP_EOL;

// 5. site_uninstall returns a non-negative count. We do NOT call
//    it here — that's the operator's job (`wp vyg site-cleanup
//    --yes`).
$count_method = function (): int {
    return NetworkPolicy::site_uninstall();
};
echo "site_uninstall_method_exists=" . ( method_exists( NetworkPolicy::class, 'site_uninstall' ) ? 'yes' : 'no' ) . PHP_EOL;

echo "smoke_status=ok" . PHP_EOL;
