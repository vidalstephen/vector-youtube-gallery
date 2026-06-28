<?php
/**
 * Plugin bootstrap.
 *
 * `boot()` is the single entry point called on `plugins_loaded`.
 * It wires the Container, registers WordPress hooks, and is the only place
 * where subsystems know about each other.
 *
 * @package VectorYT\Gallery
 */

declare(strict_types=1);

namespace VectorYT\Gallery;

use VectorYT\Gallery\Admin\AdminMenu;
use VectorYT\Gallery\Admin\DiagnosticsPage;
use VectorYT\Gallery\Admin\SettingsPage;
use VectorYT\Gallery\Admin\SourcesPage;
use VectorYT\Gallery\Logging\Logger;
use VectorYT\Gallery\Settings\SecretsRepository;
use VectorYT\Gallery\Settings\SettingsRepository;
use VectorYT\Gallery\YouTube\ApiClientInterface;
use VectorYT\Gallery\YouTube\ApiKeyClient;
use VectorYT\Gallery\YouTube\ChannelResolver;
use VectorYT\Gallery\YouTube\MockApiClient;
use VectorYT\Gallery\YouTube\PlaylistResolver;
use VectorYT\Gallery\YouTube\QuotaTracker;
use VectorYT\Gallery\YouTube\VideoMetadataFetcher;

defined( 'ABSPATH' ) || exit;

final class Plugin {

    private static ?Container $container = null;

    public static function boot(): void {
        if ( self::$container !== null ) {
            return;
        }

        if ( ! self::meets_requirements() ) {
            add_action( 'admin_notices', array( self::class, 'maybe_render_requirements_notice' ) );
            return;
        }

        self::$container = new Container();
        self::register_services( self::$container );
        self::register_hooks();

        do_action( 'vyg_plugin_loaded', self::$container );
    }

    public static function container(): ?Container {
        return self::$container;
    }

    /**
     * Reset the container — only for tests.
     */
    public static function reset_container(): void {
        self::$container = null;
    }

    public static function on_activate(): void {
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
        // Phase 6 will clear scheduled events. No data deletion here.
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

    private static function register_services( Container $c ): void {
        // --- Core ---
        $c->set( 'logger',   static fn(): Logger => new Logger() );
        $c->set( 'secrets',  static fn(): SecretsRepository => new SecretsRepository() );
        $c->set( 'settings', static fn(): SettingsRepository => new SettingsRepository() );
        $c->set( 'quota',    static fn(): QuotaTracker => new QuotaTracker() );

        // --- YouTube API client (mock when VYG_USE_MOCK=1) ---
        $c->set(
            'youtube.api',
            static function ( Container $c ): ApiClientInterface {
                $logger = $c->get( 'logger' );
                if ( VYG_USE_MOCK ) {
                    $fixtures_dir = VYG_PLUGIN_DIR . 'tests/fixtures';
                    return new MockApiClient(
                        $logger,
                        $fixtures_dir,
                        self::load_mock_handlers(),
                    );
                }
                return new ApiKeyClient( $c->get( 'secrets' ), $logger );
            }
        );

        // --- Resolvers ---
        $c->set(
            'youtube.channels',
            static fn( Container $c ): ChannelResolver => new ChannelResolver( $c->get( 'youtube.api' ), $c->get( 'logger' ) )
        );
        $c->set(
            'youtube.playlists',
            static fn( Container $c ): PlaylistResolver => new PlaylistResolver( $c->get( 'youtube.api' ), $c->get( 'logger' ) )
        );
        $c->set(
            'youtube.videos',
            static fn( Container $c ): VideoMetadataFetcher => new VideoMetadataFetcher( $c->get( 'youtube.api' ), $c->get( 'logger' ) )
        );

        // --- Admin pages ---
        $c->set(
            'admin.settings',
            static fn( Container $c ): SettingsPage => new SettingsPage(
                $c->get( 'secrets' ),
                $c->get( 'settings' ),
                $c->get( 'youtube.api' ),
                $c->get( 'logger' )
            )
        );
        $c->set(
            'admin.sources',
            static fn( Container $c ): SourcesPage => new SourcesPage(
                $c->get( 'youtube.channels' ),
                $c->get( 'youtube.playlists' ),
                $c->get( 'youtube.videos' ),
                $c->get( 'secrets' ),
                $c->get( 'logger' )
            )
        );
        $c->set(
            'admin.diagnostics',
            static fn( Container $c ): DiagnosticsPage => new DiagnosticsPage(
                $c->get( 'secrets' ),
                $c->get( 'youtube.api' ),
                $c->get( 'quota' )
            )
        );
        $c->set(
            'admin.menu',
            static fn( Container $c ): AdminMenu => new AdminMenu(
                $c->get( 'settings' ),
                $c->get( 'secrets' ),
                $c->get( 'admin.settings' ),
                $c->get( 'admin.sources' ),
                $c->get( 'admin.diagnostics' )
            )
        );
    }

    /**
     * Load in-memory mock handlers (no fixtures needed for common queries).
     *
     * @return array<string,callable>
     */
    private static function load_mock_handlers(): array {
        // Channel fixtures are loaded from tests/fixtures/channels__*.json
        // by MockApiClient's fixture resolver. Handlers here are reserved
        // for dynamic content (e.g. tests that want a specific channel).
        return array();
    }

    private static function register_hooks(): void {
        $c = self::$container;

        add_action(
            'admin_menu',
            static fn() => ( $c->get( 'admin.menu' ) )->register()
        );

        // Future phases will add:
        //   add_action( 'rest_api_init', ... )
        //   add_shortcode( 'vyg_feed', ... )
        //   add_action( 'init', ... ) for block registration
    }
}