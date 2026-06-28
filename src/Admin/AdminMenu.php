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

        // Phase 6 placeholders — show as disabled rows so users see what's coming.
        $this->register_phase6_placeholders();
    }

    private function register_phase6_placeholders(): void {
        $upcoming = array(
            'dashboard'  => __( 'Dashboard', 'vector-youtube-gallery' ),
            'feeds'      => __( 'Feeds', 'vector-youtube-gallery' ),
            'live'       => __( 'Live Display', 'vector-youtube-gallery' ),
            'sync-queue' => __( 'Sync Queue', 'vector-youtube-gallery' ),
            'privacy'    => __( 'Privacy & Compliance', 'vector-youtube-gallery' ),
        );
        foreach ( $upcoming as $slug => $label ) {
            add_submenu_page(
                self::PARENT_SLUG,
                $label,
                $label,
                self::REQUIRED_CAP,
                self::PARENT_SLUG . '-' . $slug,
                static function () use ( $label ): void {
                    echo '<div class="wrap"><h1>' . esc_html( $label ) . '</h1>';
                    echo '<p>' . esc_html__( 'Coming in a later phase. See DEV-CHECKLIST.md.', 'vector-youtube-gallery' ) . '</p>';
                    echo '</div>';
                }
            );
        }
    }
}