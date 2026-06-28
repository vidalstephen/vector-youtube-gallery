<?php
/**
 * Plugin bootstrap.
 *
 * @package VectorYT\Gallery
 */

declare(strict_types=1);

namespace VectorYT\Gallery;

use VectorYT\Gallery\Admin\AdminMenu;
use VectorYT\Gallery\Admin\DiagnosticsPage;
use VectorYT\Gallery\Admin\SettingsPage;
use VectorYT\Gallery\Admin\SourcesPage;
use VectorYT\Gallery\Admin\VideosPage;
use VectorYT\Gallery\Database\Installer;
use VectorYT\Gallery\Database\Migrator;
use VectorYT\Gallery\Logging\Logger;
use VectorYT\Gallery\Render\AssetManager;
use VectorYT\Gallery\Render\BlockRegistrar;
use VectorYT\Gallery\Render\FeedQuery;
use VectorYT\Gallery\Render\Renderer;
use VectorYT\Gallery\Render\ShortcodeRegistrar;
use VectorYT\Gallery\Render\TemplateLoader;
use VectorYT\Gallery\Render\VideoRenderer;
use VectorYT\Gallery\Repository\PlaylistRepository;
use VectorYT\Gallery\Repository\SourceRepository;
use VectorYT\Gallery\Repository\SyncLogRepository;
use VectorYT\Gallery\Repository\VideoRepository;
use VectorYT\Gallery\REST\FeedController;
use VectorYT\Gallery\Settings\SecretsRepository;
use VectorYT\Gallery\Settings\SettingsRepository;
use VectorYT\Gallery\Sync\DeletedVideoDetector;
use VectorYT\Gallery\Sync\IncrementalSyncJob;
use VectorYT\Gallery\Sync\InitialImportJob;
use VectorYT\Gallery\Sync\MetadataRefreshJob;
use VectorYT\Gallery\Sync\RetryPolicy;
use VectorYT\Gallery\Sync\WpCronSyncScheduler;
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

        // Boot the container so Installer can resolve dependencies.
        self::boot();
        /** @var Installer $installer */
        $installer = self::$container->get( 'installer' );
        $installer->install();

        // Schedule default cron events (idempotent — uses wp_schedule_event which no-ops on dupe).
        if ( ! wp_next_scheduled( 'vyg_cron_incremental_all' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'vyg_cron_incremental_all' );
        }
        if ( ! wp_next_scheduled( 'vyg_cron_metadata_refresh' ) ) {
            wp_schedule_event( time() + DAY_IN_SECONDS, 'twicedaily', 'vyg_cron_metadata_refresh' );
        }
    }

    public static function on_deactivate(): void {
        // Clear scheduled events. NO data deletion here.
        wp_clear_scheduled_hook( 'vyg_cron_incremental_all' );
        wp_clear_scheduled_hook( 'vyg_cron_metadata_refresh' );
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
        $c->set( 'retry',    static fn(): RetryPolicy => new RetryPolicy() );

        // --- Database ---
        $c->set( 'migrator',  static fn(): Migrator => new Migrator( new Logger() ) );
        $c->set( 'installer', static function ( Container $c ): Installer {
            return new Installer( $c->get( 'migrator' ), $c->get( 'logger' ) );
        } );

        // --- Repositories ---
        $c->set( 'repo.sources',  static fn(): SourceRepository => new SourceRepository() );
        $c->set( 'repo.videos',   static fn(): VideoRepository => new VideoRepository() );
        $c->set( 'repo.playlists',static fn(): PlaylistRepository => new PlaylistRepository() );
        $c->set( 'repo.logs',     static fn(): SyncLogRepository => new SyncLogRepository() );

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
        $c->set( 'youtube.channels', static fn( Container $c ): ChannelResolver => new ChannelResolver( $c->get( 'youtube.api' ), $c->get( 'logger' ) ) );
        $c->set( 'youtube.playlists', static fn( Container $c ): PlaylistResolver => new PlaylistResolver( $c->get( 'youtube.api' ), $c->get( 'logger' ) ) );
        $c->set( 'youtube.videos',  static fn( Container $c ): VideoMetadataFetcher => new VideoMetadataFetcher( $c->get( 'youtube.api' ), $c->get( 'logger' ) ) );

        // --- Sync jobs ---
        $c->set( 'sync.deleted_detector', static fn(): DeletedVideoDetector => new DeletedVideoDetector() );
        $c->set(
            'sync.initial',
            static fn( Container $c ): InitialImportJob => new InitialImportJob(
                $c->get( 'repo.logs' ),
                $c->get( 'retry' ),
                $c->get( 'quota' ),
                $c->get( 'logger' ),
                $c->get( 'repo.sources' ),
                $c->get( 'repo.videos' ),
                $c->get( 'repo.playlists' ),
                $c->get( 'youtube.api' )
            )
        );
        $c->set(
            'sync.incremental',
            static fn( Container $c ): IncrementalSyncJob => new IncrementalSyncJob(
                $c->get( 'repo.logs' ),
                $c->get( 'retry' ),
                $c->get( 'quota' ),
                $c->get( 'logger' ),
                $c->get( 'repo.sources' ),
                $c->get( 'repo.videos' ),
                $c->get( 'repo.playlists' ),
                $c->get( 'youtube.api' )
            )
        );
        $c->set(
            'sync.refresh',
            static fn( Container $c ): MetadataRefreshJob => new MetadataRefreshJob(
                $c->get( 'repo.logs' ),
                $c->get( 'retry' ),
                $c->get( 'quota' ),
                $c->get( 'logger' ),
                $c->get( 'repo.videos' ),
                $c->get( 'youtube.api' ),
                $c->get( 'sync.deleted_detector' )
            )
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
                $c->get( 'repo.sources' ),
                $c->get( 'repo.logs' ),
                $c->get( 'sync.initial' ),
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
            'admin.videos',
            static fn( Container $c ): VideosPage => new VideosPage(
                $c->get( 'settings' ),
                $c->get( 'logger' )
            )
        );
        $c->set(
            'admin.menu',
            static fn( Container $c ): AdminMenu => new AdminMenu(
                $c->get( 'settings' ),
                $c->get( 'secrets' ),
                $c->get( 'admin.settings' ),
                $c->get( 'admin.sources' ),
                $c->get( 'admin.diagnostics' ),
                $c->get( 'admin.videos' )
            )
        );

        // --- Rendering ---
        $c->set( 'render.feed',     static fn(): FeedQuery => new FeedQuery() );
        $c->set( 'render.video',    static fn(): VideoRenderer => new VideoRenderer() );
        $c->set( 'render.templates',static fn(): TemplateLoader => new TemplateLoader() );
        $c->set( 'render.assets',   static fn(): AssetManager => new AssetManager() );
        $c->set(
            'render.renderer',
            static fn( Container $c ): Renderer => new Renderer(
                $c->get( 'render.feed' ),
                $c->get( 'render.video' ),
                $c->get( 'render.templates' )
            )
        );
        $c->set(
            'render.shortcode',
            static fn( Container $c ): ShortcodeRegistrar => new ShortcodeRegistrar(
                $c->get( 'render.renderer' ),
                $c->get( 'render.feed' ),
                $c->get( 'render.assets' )
            )
        );
        $c->set(
            'render.block',
            static fn(): BlockRegistrar => new BlockRegistrar()
        );
        $c->set(
            'rest.feed',
            static fn( Container $c ): FeedController => new FeedController(
                $c->get( 'render.renderer' )
            )
        );
    }

    /**
     * @return array<string,callable>
     */
    private static function load_mock_handlers(): array {
        return array();
    }

    private static function register_hooks(): void {
        $c = self::$container;

        add_action( 'admin_menu', static fn() => $c->get( 'admin.menu' )->register() );

        // Sync job hooks (called by WP-Cron).
        add_action( 'vyg_sync_source_initial',     static fn( $args ) => $c->get( 'sync.initial' )->handle( $args ) );
        add_action( 'vyg_sync_source_incremental', static fn( $args ) => $c->get( 'sync.incremental' )->handle( $args ) );
        add_action( 'vyg_refresh_video_batch',     static fn( $args ) => $c->get( 'sync.refresh' )->handle( $args ) );

        // Front-end render.
        $c->get( 'render.assets' )->register();
        $c->get( 'render.shortcode' )->register();
        $c->get( 'render.block' )->register();
        $c->get( 'rest.feed' )->register_routes();

        // Cron tick: queue incremental syncs for every active source.
        add_action( 'vyg_cron_incremental_all', static function () use ( $c ): void {
            /** @var SourceRepository $sources */
            $sources = $c->get( 'repo.sources' );
            /** @var SyncLogRepository $logs */
            $logs = $c->get( 'repo.logs' );
            foreach ( $sources->list( array( 'status' => 'active' ) ) as $source ) {
                $job_id = $logs->create_job( 'incremental', (int) $source['id'] );
                wp_schedule_single_event( time() + 60, 'vyg_sync_source_incremental', array(
                    'vyg_job_id' => $job_id,
                    'source_id'  => (int) $source['id'],
                ) );
            }
        } );

        // Cron tick: kick a single metadata refresh job.
        add_action( 'vyg_cron_metadata_refresh', static function () use ( $c ): void {
            /** @var SyncLogRepository $logs */
            $logs = $c->get( 'repo.logs' );
            $job_id = $logs->create_job( 'metadata_refresh' );
            wp_schedule_single_event( time() + 60, 'vyg_refresh_video_batch', array(
                'vyg_job_id'  => $job_id,
                'max_videos'  => 100,
            ) );
        } );
    }
}