<?php
/**
 * Plugin bootstrap.
 *
 * `boot()` is the single entry point called on `plugins_loaded`.
 * It wires the Container, registers WordPress hooks, and is the only place
 * where subsystems know about each other.
 *
 * Phase 0: registers Container + Logger stub. Later phases register
 * admin pages, REST routes, shortcodes, blocks, sync scheduler, etc.
 *
 * @package VectorYT\Gallery
 */

declare(strict_types=1);

namespace VectorYT\Gallery;

use VectorYT\Gallery\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class Plugin {

    /**
     * Single shared Container instance for the request.
     */
    private static ?Container $container = null;

    /**
     * Wire subsystems. Idempotent — safe to call multiple times in tests.
     */
    public static function boot(): void {
        if ( self::$container !== null ) {
            return;
        }

        if ( ! self::meets_requirements() ) {
            return; // Admin notice will surface via admin_notices hook below.
        }

        self::$container = new Container();

        // Phase 0: register logger only. Each phase appends more registrations.
        self::$container->set(
            'logger',
            static function (): Logger {
                return new Logger();
            }
        );

        // Activation-side checks.
        add_action( 'admin_notices', array( self::class, 'maybe_render_requirements_notice' ) );

        do_action( 'vyg_plugin_loaded', self::$container );
    }

    public static function container(): ?Container {
        return self::$container;
    }

    public static function on_activate(): void {
        // Phase 0 stub. Phase 2 will:
        //   - src/Database/Installer::install()
        //   - schedule vyg_sync_incremental cron (Phase 2)
        //   - set default options
        //   - flush rewrite rules
        if ( ! self::meets_requirements() ) {
            deactivate_plugins( VYG_PLUGIN_BASENAME );
            wp_die(
                esc_html__( 'Vector YouTube Gallery requires PHP 8.1+ and WordPress 6.4+.', 'vector-youtube-gallery' ),
                '',
                array( 'back_link' => true )
            );
        }
    }

    public static function on_deactivate(): void {
        // Phase 0 stub. Phase 6 will:
        //   - wp_clear_scheduled_hook('vyg_sync_incremental')
        //   - do NOT delete data here (per plan §21 uninstall model)
    }

    private static function meets_requirements(): bool {
        return version_compare( PHP_VERSION, VYG_MIN_PHP, '>=' )
            && version_compare( get_bloginfo( 'version' ), VYG_MIN_WP, '>=' );
    }

    public static function maybe_render_requirements_notice(): void {
        if ( self::meets_requirements() ) {
            return;
        }
        echo '<div class="notice notice-error"><p>';
        echo esc_html(
            sprintf(
                /* translators: 1: required PHP, 2: required WP */
                __( 'Vector YouTube Gallery requires PHP %1$s+ and WordPress %2$s+. Current PHP: %3$s.', 'vector-youtube-gallery' ),
                VYG_MIN_PHP,
                VYG_MIN_WP,
                PHP_VERSION
            )
        );
        echo '</p></div>';
    }
}