<?php
/**
 * Plugin Name:       Vector YouTube Gallery
 * Plugin URI:        https://github.com/yourname/vector-youtube-gallery
 * Description:       Local-indexed YouTube gallery system. Fast, compliant, refreshable.
 * Version:           0.1.0-alpha
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Stephen Vidal
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vector-youtube-gallery
 * Domain Path:       /languages
 *
 * @package VectorYT\Gallery
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Plugin constants.
 *
 * Naming: VYG_* for runtime, VYG_DIR/VYG_URL for filesystem paths.
 * Keep this block boring and synchronous — no logic, no side effects.
 */
define( 'VYG_VERSION', '0.1.0-alpha' );
define( 'VYG_PLUGIN_FILE', __FILE__ );
define( 'VYG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );      // /path/to/wp-content/plugins/vector-youtube-gallery/
define( 'VYG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );       // https://site/wp-content/plugins/vector-youtube-gallery/
define( 'VYG_PLUGIN_BASENAME', plugin_basename( __FILE__ ) ); // vector-youtube-gallery/vector-youtube-gallery.php
define( 'VYG_DB_VERSION', '0.6.0' ); // Phase 12.6 — composite indexes for hot read path
define( 'VYG_MIN_PHP', '8.1' );
define( 'VYG_MIN_WP', '6.4' );
define( 'VYG_API_NAMESPACE', 'vyg/v1' );

/**
 * Mock-mode switch — used by ApiKeyClient to choose between live and mock clients.
 * Mirrors dev/.env VYG_USE_MOCK. Default: 0 (live). Dev should override via:
 *   putenv('VYG_USE_MOCK=1');  // in dev/.env loaded into the container
 * Or wp-config.php: define('VYG_USE_MOCK', true);
 */
if ( ! defined( 'VYG_USE_MOCK' ) ) {
    $vyg_mock = getenv( 'VYG_USE_MOCK' );
    define( 'VYG_USE_MOCK', in_array( $vyg_mock, array( '1', 'true', 'yes' ), true ) );
}

/**
 * Bootstrap the plugin on `plugins_loaded`.
 * Kept as a static method call so activation/deactivation/uninstall hooks
 * can resolve the class without instantiation timing issues.
 *
 * Autoloading: prefer Composer's PSR-4 autoloader (vendor/autoload.php) when
 * present. Fall back to a minimal manual require so the plugin still loads
 * if Composer install hasn't been run yet (common in fresh checkouts).
 */
$autoload = __DIR__ . '/vendor/autoload.php';
if ( is_file( $autoload ) ) {
    require_once $autoload;
    // Composer's PSR-4 autoloader doesn't load free-function files
    // (compat.php exposes global helpers like vyg_render_product_cta
    // and vyg_product_url). Require it explicitly so the front-end
    // grid.php template can call the helper regardless of whether
    // the autoloader is present.
    require_once __DIR__ . '/src/compat.php';
} else {
    // Minimal fallback — require the bootstrap classes by hand.
    require_once __DIR__ . '/src/Container.php';
    require_once __DIR__ . '/src/Plugin.php';
    require_once __DIR__ . '/src/Integrations/WooCommerce/ProductLink.php'; // Phase 10.3
    require_once __DIR__ . '/src/compat.php'; // Phase 10.3 — global helpers
}

add_action( 'plugins_loaded', array( \VectorYT\Gallery\Plugin::class, 'boot' ) );

/**
 * Activation hook — runs once on activation (not on every load).
 * Creates DB tables, sets default options, schedules events.
 */
register_activation_hook( __FILE__, array( \VectorYT\Gallery\Plugin::class, 'on_activate' ) );

/**
 * Deactivation hook — clears scheduled events but does NOT delete data
 * (data removal is a separate, explicit user action via Privacy & Compliance).
 */
register_deactivation_hook( __FILE__, array( \VectorYT\Gallery\Plugin::class, 'on_deactivate' ) );

/**
 * Phase 12.4: when the plugin is network-activated, WordPress does not
 * fire the per-site activation hook. We register a separate callback
 * that walks every site and runs `Plugin::on_activate()` against it.
 * On a single-site install this is a no-op.
 */
if ( function_exists( 'is_multisite' ) && is_multisite() ) {
    add_action( 'activate_' . plugin_basename( __FILE__ ), array( \VectorYT\Gallery\Multisite\NetworkPolicy::class, 'on_network_activate' ), 20 );
}