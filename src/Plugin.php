<?php
/**
 * Plugin bootstrap.
 *
 * @package VectorYT\Gallery
 */

declare(strict_types=1);

namespace VectorYT\Gallery;

use VectorYT\Gallery\Admin\AdminMenu;
use VectorYT\Gallery\Admin\DashboardStats;
use VectorYT\Gallery\Admin\DashboardWidget;
use VectorYT\Gallery\Admin\DiagnosticsPage;
use VectorYT\Gallery\Admin\FeedsPage;
use VectorYT\Gallery\Admin\GdprHooks;
use VectorYT\Gallery\Admin\ImporterExporter;
use VectorYT\Gallery\Admin\PrivacyPage;
use VectorYT\Gallery\Admin\SettingsPage;
use VectorYT\Gallery\Admin\SourcesPage;
use VectorYT\Gallery\Admin\SystemInfoPage;
use VectorYT\Gallery\Admin\VideosPage;
use VectorYT\Gallery\Compliance\DataRetentionManager;
use VectorYT\Gallery\Compliance\DisconnectManager;
use VectorYT\Gallery\Compliance\PrivacyPolicyGenerator;
use VectorYT\Gallery\Database\Installer;
use VectorYT\Gallery\Database\Migrator;
use VectorYT\Gallery\Logging\Logger;
use VectorYT\Gallery\Render\AssetManager;
use VectorYT\Gallery\Render\BlockRegistrar;
use VectorYT\Gallery\Render\FeedQuery;
use VectorYT\Gallery\Render\LiveQuery;
use VectorYT\Gallery\Render\Renderer;
use VectorYT\Gallery\Render\ShortcodeRegistrar;
use VectorYT\Gallery\Render\TemplateLoader;
use VectorYT\Gallery\Render\VideoRenderer;
use VectorYT\Gallery\Repository\PlaylistRepository;
use VectorYT\Gallery\Repository\FeedRepository;
use VectorYT\Gallery\Repository\PreviousStreamsRepository;
use VectorYT\Gallery\Repository\SourceRepository;
use VectorYT\Gallery\Repository\SyncLogRepository;
use VectorYT\Gallery\Repository\VideoRepository;
use VectorYT\Gallery\REST\AdminRestController;
use VectorYT\Gallery\REST\FeedController;
use VectorYT\Gallery\Settings\OAuthTokenRepository;
use VectorYT\Gallery\Settings\SecretsRepository;
use VectorYT\Gallery\Settings\SettingsRepository;
use VectorYT\Gallery\Sync\DeletedVideoDetector;
use VectorYT\Gallery\Sync\IncrementalSyncJob;
use VectorYT\Gallery\Sync\InitialImportJob;
use VectorYT\Gallery\Sync\LiveStatusPollJob;
use VectorYT\Gallery\Sync\MetadataRefreshJob;
use VectorYT\Gallery\Sync\RetryPolicy;
use VectorYT\Gallery\Sync\WpCronSyncScheduler;
use VectorYT\Gallery\YouTube\ApiClientInterface;
use VectorYT\Gallery\YouTube\ApiKeyClient;
use VectorYT\Gallery\YouTube\ChannelResolver;
use VectorYT\Gallery\YouTube\MockApiClient;
use VectorYT\Gallery\YouTube\OAuthClient;
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
        // Phase 5 — live status poll runs every 5 minutes by default.
        if ( ! wp_next_scheduled( 'vyg_cron_live_poll' ) ) {
            wp_schedule_event( time() + MINUTE_IN_SECONDS, 'vyg_five_minutes', 'vyg_cron_live_poll' );
        }
        // Phase 6 — daily retention sweep.
        if ( ! wp_next_scheduled( 'vyg_cron_data_retention' ) ) {
            wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'vyg_cron_data_retention' );
        }
    }

    public static function on_deactivate(): void {
        // Clear scheduled events. NO data deletion here.
        wp_clear_scheduled_hook( 'vyg_cron_incremental_all' );
        wp_clear_scheduled_hook( 'vyg_cron_metadata_refresh' );
        wp_clear_scheduled_hook( 'vyg_cron_live_poll' );
        wp_clear_scheduled_hook( 'vyg_cron_data_retention' );
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
        $c->set( 'oauth.tokens', static fn(): OAuthTokenRepository => new OAuthTokenRepository() );
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
        $c->set( 'repo.previous', static fn(): PreviousStreamsRepository => new PreviousStreamsRepository() );
        $c->set( 'repo.feeds',    static fn(): FeedRepository => new FeedRepository() );

        // --- YouTube API client (mock when VYG_USE_MOCK=1) ---
        $c->set( 'youtube.oauth_api', static fn( Container $c ): OAuthClient => new OAuthClient( $c->get( 'oauth.tokens' ), $c->get( 'logger' ) ) );
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
        $c->set(
            'sync.live_poll',
            static fn( Container $c ): LiveStatusPollJob => new LiveStatusPollJob(
                $c->get( 'youtube.api' ),
                $c->get( 'repo.videos' ),
                $c->get( 'repo.previous' ),
                $c->get( 'repo.logs' ),
                $c->get( 'quota' ),
                $c->get( 'logger' ),
                $c->get( 'settings' )
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
                $c->get( 'admin.videos' ),
                $c->get( 'admin.system_info' ),
                $c->get( 'admin.feeds' ),
                $c->get( 'admin.privacy' ),
                $c->get( 'logger' )
            )
        );

        $c->set( 'admin.dashboard_stats', static fn(): DashboardStats => new DashboardStats() );
        $c->set(
            'admin.dashboard_widget',
            static fn( Container $c ): DashboardWidget => new DashboardWidget( $c->get( 'admin.dashboard_stats' ) )
        );
        $c->set( 'admin.importer_exporter', static fn( Container $c ): ImporterExporter => new ImporterExporter( $c->get( 'settings' ) ) );
        $c->set( 'admin.gdpr', static fn(): GdprHooks => new GdprHooks() );
        $c->set( 'admin.system_info', static fn( Container $c ): SystemInfoPage => new SystemInfoPage( $c->get( 'admin.dashboard_stats' ) ) );
        $c->set(
            'admin.feeds',
            static fn( Container $c ): FeedsPage => new FeedsPage(
                $c->get( 'repo.feeds' ),
                $c->get( 'repo.sources' ),
                $c->get( 'logger' )
            )
        );
        $c->set( 'compliance.retention', static fn( Container $c ): DataRetentionManager => new DataRetentionManager( $c->get( 'settings' ), $c->get( 'logger' ) ) );
        $c->set( 'compliance.disconnect', static fn( Container $c ): DisconnectManager => new DisconnectManager( $c->get( 'secrets' ), $c->get( 'youtube.api' ), $c->get( 'logger' ) ) );
        $c->set( 'compliance.policy', static fn(): PrivacyPolicyGenerator => new PrivacyPolicyGenerator() );
        $c->set(
            'admin.privacy',
            static fn( Container $c ): PrivacyPage => new PrivacyPage(
                $c->get( 'settings' ),
                $c->get( 'compliance.retention' ),
                $c->get( 'compliance.disconnect' ),
                $c->get( 'compliance.policy' ),
                $c->get( 'admin.importer_exporter' ),
                $c->get( 'logger' )
            )
        );

        // --- Rendering ---
        $c->set( 'render.feed',     static fn(): FeedQuery => new FeedQuery() );
        $c->set( 'render.live',     static fn( Container $c ): LiveQuery => new LiveQuery( $c->get( 'repo.previous' ) ) );
        $c->set( 'render.video',    static fn(): VideoRenderer => new VideoRenderer() );
        $c->set( 'render.templates',static fn(): TemplateLoader => new TemplateLoader() );
        $c->set( 'render.assets',   static fn(): AssetManager => new AssetManager() );
        $c->set(
            'render.renderer',
            static fn( Container $c ): Renderer => new Renderer(
                $c->get( 'render.feed' ),
                $c->get( 'render.video' ),
                $c->get( 'render.templates' ),
                $c->get( 'render.live' )
            )
        );
        $c->set(
            'render.shortcode',
            static fn( Container $c ): ShortcodeRegistrar => new ShortcodeRegistrar(
                $c->get( 'render.renderer' ),
                $c->get( 'render.feed' ),
                $c->get( 'render.assets' ),
                $c->get( 'repo.feeds' )
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
        $c->set(
            'rest.admin',
            static fn( Container $c ): AdminRestController => new AdminRestController(
                $c->get( 'repo.feeds' ),
                $c->get( 'repo.sources' ),
                $c->get( 'compliance.retention' ),
                $c->get( 'compliance.disconnect' ),
                $c->get( 'admin.importer_exporter' ),
                $c->get( 'admin.dashboard_stats' )
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
        add_action( 'wp_dashboard_setup', static function () use ( $c ): void {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }
            wp_add_dashboard_widget(
                'vyg_dashboard_widget',
                __( 'Vector YouTube Gallery — Status', 'vector-youtube-gallery' ),
                array( $c->get( 'admin.dashboard_widget' ), 'render' )
            );
        } );
        add_filter( 'wp_privacy_personal_data_exporters', static fn( array $exporters ) => $c->get( 'admin.gdpr' )->register_exporter( $exporters ) );
        add_filter( 'wp_privacy_personal_data_erasers',   static fn( array $erasers )   => $c->get( 'admin.gdpr' )->register_eraser( $erasers ) );

        // Sync job hooks (called by WP-Cron).
        add_action( 'vyg_sync_source_initial',     static fn( $args ) => $c->get( 'sync.initial' )->handle( $args ) );
        add_action( 'vyg_sync_source_incremental', static fn( $args ) => $c->get( 'sync.incremental' )->handle( $args ) );
        add_action( 'vyg_refresh_video_batch',     static fn( $args ) => $c->get( 'sync.refresh' )->handle( $args ) );

        // Front-end render.
        $c->get( 'render.assets' )->register();
        $c->get( 'render.shortcode' )->register();
        $c->get( 'render.block' )->register();
        $c->get( 'rest.feed' )->register_routes();
        $c->get( 'rest.admin' )->register_routes();

        // Phase 6: settings export admin-post handler.
        add_action( 'admin_post_vyg_export_settings', static function () use ( $c ): void {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Insufficient permissions.', 'vector-youtube-gallery' ) );
            }
            $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
            if ( ! wp_verify_nonce( $nonce, 'vyg_export_settings' ) ) {
                wp_die( esc_html__( 'Nonce check failed.', 'vector-youtube-gallery' ) );
            }
            $json = $c->get( 'admin.importer_exporter' )->export_settings();
            nocache_headers();
            header( 'Content-Type: application/json; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename="vyg-settings-' . gmdate( 'Ymd-His' ) . '.json"' );
            echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON export
            exit;
        } );

        // Phase 6: daily retention sweep via WP-Cron.
        add_action( 'vyg_cron_data_retention', static function () use ( $c ): void {
            $c->get( 'compliance.retention' )->run_sweep();
        } );

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

        // Cron tick: Phase 5 live-status poll.
        add_action( 'vyg_cron_live_poll', static function () use ( $c ): void {
            /** @var LiveStatusPollJob $job */
            $job = $c->get( 'sync.live_poll' );
            $job->handle( array() );
        } );

        // Register a 5-minute cron interval for the live poll.
        add_filter( 'cron_schedules', static function ( array $schedules ): array {
            if ( ! isset( $schedules['vyg_five_minutes'] ) ) {
                $schedules['vyg_five_minutes'] = array(
                    'interval' => 5 * MINUTE_IN_SECONDS,
                    'display'  => __( 'Every 5 Minutes (Vector YouTube Gallery Live Poll)', 'vector-youtube-gallery' ),
                );
            }
            return $schedules;
        } );
    }
}