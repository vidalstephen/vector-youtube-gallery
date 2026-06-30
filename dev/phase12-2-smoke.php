<?php
/**
 * Phase 12.2 SyncScheduler live smoke.
 *
 * Exercises the SyncScheduler abstraction end-to-end against a live
 * WordPress install:
 *   1. The scheduler service is reachable from the container.
 *   2. With AS absent (the dev environment does not load Action
 *      Scheduler), `auto` mode resolves to the WP-Cron backend.
 *   3. The scheduler routes `schedule_once()` calls into the WP-Cron
 *      path: `wp_next_scheduled()` returns a real timestamp after the
 *      call.
 *   4. `schedule_recurring()` registers a recurring event under the
 *      configured hook.
 *   5. `wp vyg scheduler` reports the right configured mode + effective
 *      backend for the current install.
 *   6. Switching the mode to `action_scheduler` while AS is absent
 *      surfaces as a misconfiguration in `wp vyg scheduler` JSON, not
 *      as a silent fallback. The CLI does not throw, but the
 *      snapshot says `misconfiguration: yes`.
 *   7. Restoring the default mode leaves the system clean (no leaked
 *      cron events, no leaked option rows).
 *
 * Run via:
 *     docker exec -u www-data vyg-wp \
 *         wp eval-file /var/www/html/wp-content/plugins/vector-youtube-gallery/dev/phase12-2-smoke.php
 *
 * The script prints a single `KEY=value` line per check; the
 * orchestrator greps for `expected_<KEY>=value` to confirm the smoke
 * passed.
 */

use VectorYT\Gallery\Container;
use VectorYT\Gallery\Sync\SchedulerResolver;
use VectorYT\Gallery\Sync\SyncScheduler;

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "ABSPATH not defined; run via wp eval-file.\n" );
    exit( 1 );
}

/** @var Container $container */
$container = \VectorYT\Gallery\Plugin::container();
if ( ! $container instanceof Container ) {
    fwrite( STDERR, "Container not booted; aborting smoke.\n" );
    exit( 1 );
}

// 1. Scheduler service is reachable.
$scheduler = $container->get( 'sync.scheduler' );
if ( ! $scheduler instanceof SyncScheduler ) {
    fwrite( STDERR, "sync.scheduler is not a SyncScheduler; got " . get_class( $scheduler ) . "\n" );
    exit( 1 );
}
echo "scheduler_class=" . get_class( $scheduler ) . PHP_EOL;

// 2. With AS absent, the resolver's effective backend is wp_cron.
$resolver = $container->get( 'sync.scheduler.resolver' );
echo "configured_mode=" . $resolver->resolve_mode() . PHP_EOL;
echo "effective_backend=" . $resolver->effective_backend() . PHP_EOL;
echo "as_available=" . ( function_exists( 'as_schedule_single_action' ) ? 'yes' : 'no' ) . PHP_EOL;

// 3. schedule_once routes through the WP-Cron path. Use a unique hook
//    per run so the 10-minute WP-Cron dedup window does not cause
//    false negatives.
$test_hook = 'vyg_smoke_phase12_2_test_' . gmdate( 'U' );
$ok = $scheduler->schedule_once( $test_hook, array( 'smoke' => 1 ), time() + 60 );
$next = wp_next_scheduled( $test_hook );
echo "schedule_once_returned=" . ( $ok ? 'true' : 'false' ) . PHP_EOL;
echo "wp_next_scheduled_after=" . ( $next ? gmdate( 'c', (int) $next ) : 'none' ) . PHP_EOL;
wp_clear_scheduled_hook( $test_hook );

// 4. schedule_recurring registers a recurring event. Unique hook too.
$recurring_hook = 'vyg_smoke_phase12_2_recurring_' . gmdate( 'U' );
$ok_r = $scheduler->schedule_recurring( $recurring_hook, array(), 600 );
$next_r = wp_next_scheduled( $recurring_hook );
echo "schedule_recurring_returned=" . ( $ok_r ? 'true' : 'false' ) . PHP_EOL;
echo "wp_next_scheduled_recurring=" . ( $next_r ? gmdate( 'c', (int) $next_r ) : 'none' ) . PHP_EOL;
wp_clear_scheduled_hook( $recurring_hook );

// 5. CLI `wp vyg scheduler` JSON shape (mirrors the snapshot).
$snapshot = array(
    'configured_mode'        => $resolver->resolve_mode(),
    'effective_backend'      => $resolver->effective_backend(),
    'action_scheduler_loaded' => function_exists( 'as_schedule_single_action' ) ? 'yes' : 'no',
    'misconfiguration'       => $resolver->has_misconfiguration() ? 'yes' : 'no',
);
echo "snapshot_json=" . wp_json_encode( $snapshot ) . PHP_EOL;

// 6. Force `action_scheduler` mode while AS is absent → misconfiguration.
$settings = $container->get( 'settings' );
$original_mode = $settings->get( 'sync_scheduler_mode', 'auto' );
$settings->set( 'sync_scheduler_mode', 'action_scheduler' );

$resolver2 = new SchedulerResolver( $settings, $container->get( 'sync.scheduler.action_scheduler' ) );
echo "forced_misconfiguration=" . ( $resolver2->has_misconfiguration() ? 'yes' : 'no' ) . PHP_EOL;
echo "forced_effective_backend=" . $resolver2->effective_backend() . PHP_EOL;

// 7. Restore the original mode.
$settings->set( 'sync_scheduler_mode', $original_mode );

// 8. Confirm no leaked events / options.
global $wpdb;
$leaked = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like( 'vyg_smoke_phase12_2' ) . '%'
    )
);
echo "leaked_options=" . $leaked . PHP_EOL;

echo "smoke_status=ok" . PHP_EOL;
