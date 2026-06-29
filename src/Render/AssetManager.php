<?php
/**
 * Asset manager — front-end CSS + JS enqueue logic.
 *
 * CSS files live in assets/css/ and JS in assets/js/. Phase 4 ships:
 *   - css/grid.css, list.css, featured.css, shorts.css, live.css
 *   - js/lightbox.js, js/load-more.js
 *
 * Both enqueue are lazy: only loaded when a shortcode/block actually appears
 * on the page, OR when a layout is rendered via REST.
 *
 * @package VectorYT\Gallery\Render
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Render;

defined( 'ABSPATH' ) || exit;

final class AssetManager {

    private const HANDLE_BASE = 'vyg';
    private const VERSION     = '0.1.0';

    private bool $lightbox_enqueued = false;
    private bool $load_more_enqueued = false;
    /** @var array<string,bool> */
    private array $css_enqueued = array();

    public function register(): void {
        // Front-end CSS (base resets + tokens).
        add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_base' ) );

        // REST API: register styles too so /wp-json/vyg/v1/feed responses
        // carry the right handle. (CSS only needed when REST caller is a browser.)
        add_action( 'rest_api_init', array( $this, 'register_rest_assets' ) );

        // Localize REST URL + nonce for load-more JS.
        add_action( 'wp_enqueue_scripts', array( $this, 'localize_front_data' ) );
    }

    public function maybe_enqueue_base(): void {
        // Always register; only enqueue when a shortcode/block actually fires.
        wp_register_style( self::HANDLE_BASE, $this->url( 'css/base.css' ), array(), self::VERSION );
    }

    public function register_rest_assets(): void {
        // No-op for Phase 4; Phase 5 may add server-rendered shortcodes in REST.
    }

    public function localize_front_data(): void {
        // Will be registered against the load-more handle once it enqueues.
    }

    /**
     * Enqueue the CSS for a specific layout. Idempotent.
     */
    public function enqueue_for_layout( string $layout_slug ): void {
        $this->maybe_enqueue_base();
        wp_enqueue_style( self::HANDLE_BASE );

        $map = array(
            'grid'     => 'css/grid.css',
            'list'     => 'css/list.css',
            'featured' => 'css/featured.css',
            'shorts'   => 'css/shorts.css',
            'live'     => 'css/live.css',
        );
        if ( ! isset( $map[ $layout_slug ] ) ) {
            return;
        }
        $handle = self::HANDLE_BASE . '-' . $layout_slug;
        if ( isset( $this->css_enqueued[ $handle ] ) ) {
            return;
        }
        wp_register_style( $handle, $this->url( $map[ $layout_slug ] ), array( self::HANDLE_BASE ), self::VERSION );
        wp_enqueue_style( $handle );
        $this->css_enqueued[ $handle ] = true;

        // Also enqueue the lightbox JS if not yet.
        $this->enqueue_lightbox();
    }

    public function enqueue_lightbox(): void {
        if ( $this->lightbox_enqueued ) {
            return;
        }
        wp_register_script(
            self::HANDLE_BASE . '-lightbox',
            $this->url( 'js/lightbox.js' ),
            array(),
            self::VERSION,
            true
        );
        wp_enqueue_script( self::HANDLE_BASE . '-lightbox' );
        $this->lightbox_enqueued = true;
    }

    public function enqueue_load_more(): void {
        if ( $this->load_more_enqueued ) {
            return;
        }
        $this->enqueue_lightbox();
        wp_register_script(
            self::HANDLE_BASE . '-load-more',
            $this->url( 'js/load-more.js' ),
            array( 'wp-i18n' ),
            self::VERSION,
            true
        );
        wp_set_script_translations( self::HANDLE_BASE . '-load-more', 'vector-youtube-gallery' );
        wp_enqueue_script( self::HANDLE_BASE . '-load-more' );
        wp_localize_script( self::HANDLE_BASE . '-load-more', 'VYG', array(
            'restUrl'       => esc_url_raw( rest_url( 'vyg/v1/feed' ) ),
            // Phase 8.4: feed-by-uuid endpoint. JS replaces {uuid} with the
            // current feed_uuid at click time.
            'feedByUuidUrl' => esc_url_raw( rest_url( 'vyg/v1/feed/{uuid}' ) ),
            'restNonce'     => wp_create_nonce( 'wp_rest' ),
        ) );
        $this->load_more_enqueued = true;
    }

    public function url( string $relative ): string {
        return VYG_PLUGIN_URL . 'assets/' . ltrim( $relative, '/' );
    }

    public function path( string $relative ): string {
        return VYG_PLUGIN_DIR . 'assets/' . ltrim( $relative, '/' );
    }
}