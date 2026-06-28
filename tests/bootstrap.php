<?php
/**
 * PHPUnit bootstrap.
 *
 * Sets up Brain\Monkey so WP functions can be stubbed without booting WordPress.
 * Autoloads the plugin + dev dependencies.
 */

declare(strict_types=1);

// 0. Patchwork (loaded by Brain\Monkey autoload) reads patchwork.json from cwd.
//    chdir to the plugin root BEFORE requiring autoload so redefinition works.
$plugin_root = dirname( __DIR__ );
chdir( $plugin_root );

// Path to this file's directory.
$tests_dir = __DIR__;

// 1. Composer autoloader (from inside the container: /var/www/html/wp-content/plugins/vector-youtube-gallery/).
$autoload = $tests_dir . '/../vendor/autoload.php';
if ( ! is_file( $autoload ) ) {
    fwrite( STDERR, "vendor/autoload.php not found. Run `composer install` first.\n" );
    exit( 1 );
}
require_once $autoload;

// 2. Brain\Monkey bootstrap.
\Brain\Monkey\setUp();
register_shutdown_function( static function (): void {
    \Brain\Monkey\tearDown();
} );

// 3. ABSPATH guard for plugin files.
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', $tests_dir . '/../wordpress-stub/' );
}

// 4. Plugin constants (normally defined by the main plugin file).
if ( ! defined( 'VYG_VERSION' ) ) {
    define( 'VYG_VERSION', '0.1.0-test' );
}
if ( ! defined( 'VYG_PLUGIN_DIR' ) ) {
    define( 'VYG_PLUGIN_DIR', $tests_dir . '/../' );
}
if ( ! defined( 'VYG_PLUGIN_FILE' ) ) {
    define( 'VYG_PLUGIN_FILE', VYG_PLUGIN_DIR . 'vector-youtube-gallery.php' );
}
if ( ! defined( 'VYG_USE_MOCK' ) ) {
    define( 'VYG_USE_MOCK', true );
}
if ( ! defined( 'VYG_DB_VERSION' ) ) {
    define( 'VYG_DB_VERSION', '0.1.0' );
}
if ( ! defined( 'VYG_MIN_PHP' ) ) {
    define( 'VYG_MIN_PHP', '8.1' );
}
if ( ! defined( 'VYG_MIN_WP' ) ) {
    define( 'VYG_MIN_WP', '6.4' );
}

// WordPress time constants (used by QuotaTracker etc.)
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
    define( 'MINUTE_IN_SECONDS', 60 );
}