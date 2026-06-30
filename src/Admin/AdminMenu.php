<?php
/**
 * Top-level admin menu shell.
 *
 * Registers the YouTube Gallery parent menu and the submenu slugs.
 * Phase 1 wires Settings + Sources + Diagnostics. Phase 6 adds Dashboard,
 * Feeds, Live Display, Sync Queue, Privacy & Compliance.
 *
 * Each page's render method is a stub that prints "Coming in Phase N" until
 * the owning page class is ready.
 *
 * @package VectorYT\Gallery\Admin
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Admin;

use VectorYT\Gallery\Settings\SettingsRepository;
use VectorYT\Gallery\Settings\SecretsRepository;
use VectorYT\Gallery\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class AdminMenu {

    public const PARENT_SLUG = 'vector-youtube-gallery';
    public const CAPABILITY  = 'manage_options';

    /** Minimum WP capability to touch anything in the plugin admin. */
    public const REQUIRED_CAP = 'manage_options';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly SecretsRepository $secrets,
        private readonly SettingsPage $settings_page,
        private readonly SourcesPage $sources_page,
        private readonly DiagnosticsPage $diagnostics_page,
        private readonly VideosPage $videos_page,
        private readonly AnalyticsPage $analytics_page,
        private readonly SystemInfoPage $system_info_page,
        private readonly FeedsPage $feeds_page,
        private readonly PrivacyPage $privacy_page,
        private readonly Logger $logger,
    ) {}

    public function register(): void {
        // Top-level menu — icon is the YouTube-ish SVG dashicon "video-alt3".
        add_menu_page(
            __( 'YouTube Gallery', 'vector-youtube-gallery' ),
            __( 'YouTube Gallery', 'vector-youtube-gallery' ),
            self::REQUIRED_CAP,
            self::PARENT_SLUG,
            array( $this->sources_page, 'render' ),
            'dashicons-video-alt3',
            58  // position: below Comments (25), above Appearance (60)
        );

        // Submenu: Sources (first submenu replaces the parent default).
        add_submenu_page(
            self::PARENT_SLUG,
            __( 'Sources', 'vector-youtube-gallery' ),
            __( 'Sources', 'vector-youtube-gallery' ),
            self::REQUIRED_CAP,
            self::PARENT_SLUG,
            array( $this->sources_page, 'render' )
        );

        add_submenu_page(
            self::PARENT_SLUG,
            __( 'Settings', 'vector-youtube-gallery' ),
            __( 'Settings', 'vector-youtube-gallery' ),
            self::REQUIRED_CAP,
            self::PARENT_SLUG . '-settings',
            array( $this->settings_page, 'render' )
        );

        add_submenu_page(
            self::PARENT_SLUG,
            __( 'Diagnostics', 'vector-youtube-gallery' ),
            __( 'Diagnostics', 'vector-youtube-gallery' ),
            self::REQUIRED_CAP,
            self::PARENT_SLUG . '-diagnostics',
            array( $this->diagnostics_page, 'render' )
        );

        add_submenu_page(
            self::PARENT_SLUG,
            __( 'Videos', 'vector-youtube-gallery' ),
            __( 'Videos', 'vector-youtube-gallery' ),
            self::REQUIRED_CAP,
            self::PARENT_SLUG . '-videos',
            array( $this->videos_page, 'render' )
        );

        add_submenu_page(
            self::PARENT_SLUG,
            __( 'Analytics', 'vector-youtube-gallery' ),
            __( 'Analytics', 'vector-youtube-gallery' ),
            self::REQUIRED_CAP,
            self::PARENT_SLUG . '-analytics',
            array( $this->analytics_page, 'render' )
        );

        // Live submenu (Phase 6: real, opens a live-broadcasts list using LiveQuery).
        add_submenu_page(
            self::PARENT_SLUG,
            __( 'Live', 'vector-youtube-gallery' ),
            __( 'Live', 'vector-youtube-gallery' ),
            self::REQUIRED_CAP,
            self::PARENT_SLUG . '-live',
            array( $this, 'render_live_placeholder' )
        );

        add_submenu_page(
            self::PARENT_SLUG,
            __( 'Feeds', 'vector-youtube-gallery' ),
            __( 'Feeds', 'vector-youtube-gallery' ),
            self::REQUIRED_CAP,
            self::PARENT_SLUG . '-feeds',
            array( $this->feeds_page, 'render' )
        );

        add_submenu_page(
            self::PARENT_SLUG,
            __( 'Privacy & Compliance', 'vector-youtube-gallery' ),
            __( 'Privacy & Compliance', 'vector-youtube-gallery' ),
            self::REQUIRED_CAP,
            self::PARENT_SLUG . '-privacy',
            array( $this->privacy_page, 'render' )
        );

        add_submenu_page(
            self::PARENT_SLUG,
            __( 'System Info', 'vector-youtube-gallery' ),
            __( 'System Info', 'vector-youtube-gallery' ),
            self::REQUIRED_CAP,
            self::PARENT_SLUG . '-system-info',
            array( $this->system_info_page, 'render' )
        );
    }

    /**
     * Placeholder for the "Live Display" admin page.
     * Live broadcasts are primarily a front-end concept (LiveLayout); the
     * admin page for monitoring them is scheduled for a post-1.0 phase.
     */
    public function render_live_placeholder(): void {
        echo '<div class="wrap"><h1>' . esc_html__( 'YouTube Gallery — Live', 'vector-youtube-gallery' ) . '</h1>';
        echo '<p>' . esc_html__( 'Live broadcasts are surfaced via the Live layout on the front-end and updated by the vyg_cron_live_poll job every 5 minutes.', 'vector-youtube-gallery' ) . '</p>';
        echo '</div>';
    }
}