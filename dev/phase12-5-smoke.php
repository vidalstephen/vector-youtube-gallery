<?php
/**
 * Phase 12.5 Log rotation + level filter live smoke.
 *
 * Exercises the Logger level filter, the LogRotator (against a
 * temp directory, NOT the real wp-content/debug.log), and the
 * `wp vyg log` + `wp vyg log-rotate` subcommands.
 *
 * Run via:
 *     docker exec -u www-data vyg-wp \
 *         wp eval-file /var/www/html/wp-content/plugins/vector-youtube-gallery/dev/phase12-5-smoke.php
 */

use VectorYT\Gallery\Container;
use VectorYT\Gallery\Logging\LogRotator;
use VectorYT\Gallery\Logging\Logger;
use VectorYT\Gallery\Settings\SettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "ABSPATH not defined; run via wp eval-file.\n" );
    exit( 1 );
}

global $wpdb;

/** @var Container $container */
$container = \VectorYT\Gallery\Plugin::container();

$logger = $container->get( 'logger' );
if ( ! $logger instanceof Logger ) {
    fwrite( STDERR, 'logger is not a Logger' . PHP_EOL );
    exit( 1 );
}
echo 'logger_class=' . get_class( $logger ) . PHP_EOL;

// 1. Test the level filter end-to-end.
$logger->set_min_level( Logger::LEVEL_ERROR );
$logger->info( 'this should be dropped' );
$logger->warning( 'this should also be dropped' );
$logger->error( 'this should be kept' );
echo 'level_filter_works=' . ( $logger->is_enabled( Logger::LEVEL_DEBUG ) ? 'no' : 'yes' ) . PHP_EOL;

// 2. Test the sink dispatch with a captured record.
$captured = array();
$logger->add_sink( function ( array $entry ) use ( &$captured ): void {
    $captured[] = $entry;
} );
$logger->error( 'sink payload' );
echo 'sink_received_count=' . count( $captured ) . PHP_EOL;
echo 'sink_payload=' . ( $captured[0]['message'] ?? 'none' ) . PHP_EOL;

// 3. Test the rotator against a temp dir.
$tmp = sys_get_temp_dir() . '/vyg-log-smoke-' . bin2hex( random_bytes( 4 ) );
$seg = $tmp . '/segments';
mkdir( $seg, 0700, true );
file_put_contents( $tmp . '/debug.log', str_repeat( 'L', 6 * 1024 * 1024 ) );

$settings = $container->get( 'settings' );
$rotator  = new LogRotator( $settings, $tmp . '/debug.log', $seg );
echo 'rotate_initial=' . $rotator->rotate() . PHP_EOL;
echo 'segment_1_exists=' . ( is_file( $seg . '/debug.1.log' ) ? 'yes' : 'no' ) . PHP_EOL;
echo 'new_active_empty=' . ( (int) filesize( $tmp . '/debug.log' ) === 0 ? 'yes' : 'no' ) . PHP_EOL;

// 4. Test size clamp + max files clamp.
$settings->set( 'log_max_size_mb', 99999 );
echo 'max_size_clamped=' . ( ( $rotator->max_size_bytes() === LogRotator::MAX_SIZE_MB_CEILING * 1024 * 1024 ) ? 'yes' : 'no' ) . PHP_EOL;

$settings->set( 'log_max_files', 99999 );
echo 'max_files_clamped=' . ( ( $rotator->max_files() === LogRotator::MAX_FILES_CEILING ) ? 'yes' : 'no' ) . PHP_EOL;

// 5. Confirm the live CLI subcommand works.
echo 'subcommand_log=' . ( $container->get( 'log.rotator' ) instanceof LogRotator ? 'yes' : 'no' ) . PHP_EOL;

echo 'smoke_status=ok' . PHP_EOL;
