<?php
/**
 * Asset manager — front-end CSS + JS enqueue logic.
 *
 * Phase 4 ships 5 layout CSS files + lightbox/load-more JS.
 * Phase 9 adds: masonry, carousel, hero layouts + presets tokens + carousel JS.
 *
 * All enqueues are lazy: only loaded when a shortcode/block actually fires
 * on the page, OR when a layout is rendered via the REST endpoint.
 *
 * @package VectorYT\Gallery\Render
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Render;

defined('ABSPATH') || exit;

final class AssetManager {

    private const HANDLE_BASE = 'vyg';
    private const VERSION     = '0.1.1';

    private bool $lightbox_enqueued = false;
    private bool $load_more_enqueued = false;
    private bool $carousel_enqueued = false;
    private bool $presets_enqueued = false;
    private bool $analytics_enqueued = false;
    private bool $card_enqueued = false;
    // Phase 14.10 — flag for the shared trust-strip stylesheet, enqueued
    // for grid + masonry + carousel layouts.
    private bool $trust_strip_enqueued = false;
    /** @var array<string,bool> */
    private array $css_enqueued = array();

    public function register(): void {
        add_action('wp_enqueue_scripts', array($this, 'maybe_enqueue_base'));
        add_action('rest_api_init', array($this, 'register_rest_assets'));
        add_action('wp_enqueue_scripts', array($this, 'localize_front_data'));
    }

    public function maybe_enqueue_base(): void {
        wp_register_style(self::HANDLE_BASE, $this->url('css/base.css'), array(), self::VERSION);
    }

    public function register_rest_assets(): void {
        // Reserved for future REST-style CSS enqueue hooks. No-op for Phase 9.
    }

    public function localize_front_data(): void {
        // Will be registered against the load-more handle once it enqueues.
    }

    /**
     * Enqueue the CSS for a specific layout. Idempotent.
     */
    public function enqueue_for_layout(string $layout_slug): void {
        $this->maybe_enqueue_base();
        wp_enqueue_style(self::HANDLE_BASE);

        $map = array(
            'grid'     => 'css/grid.css',
            'list'     => 'css/list.css',
            'featured' => 'css/featured.css',
            'shorts'   => 'css/shorts.css',
            'live'     => 'css/live.css',
            'masonry'  => 'css/masonry.css',
            'carousel' => 'css/carousel.css',
            'hero'     => 'css/hero.css',
        );
        if (! isset($map[$layout_slug])) {
            return;
        }

        // Phase C1 — the shared card system is used by every layout.
        // Register + enqueue the shared `vyg-card` handle once per
        // request so card.css is always available alongside the
        // layout-specific CSS. enqueue_card_assets() is itself
        // idempotent and depends on the base `vyg` handle.
        $this->enqueue_card_assets();

        $handle = self::HANDLE_BASE . '-' . $layout_slug;
        if (isset($this->css_enqueued[$handle])) {
            $this->maybe_enqueue_presets();
            $this->maybe_enqueue_trust_strip();
            return;
        }
        wp_register_style($handle, $this->url($map[$layout_slug]), array(self::HANDLE_BASE, 'vyg-card'), self::VERSION);
        wp_enqueue_style($handle);
        $this->css_enqueued[$handle] = true;

        // Preset tokens are loaded once per request alongside the first layout.
        $this->maybe_enqueue_presets();

        // Phase 14.10 — shared trust-strip CSS for the 3 layouts that
        // emit it (grid, masonry, carousel). Idempotent: enqueued once
        // per request. Loaded after the layout-specific stylesheet so
        // a theme that overrides `.vyg-trust-strip` can win the cascade
        // by simply enqueuing later.
        $this->maybe_enqueue_trust_strip();

        // Lightbox JS handled centrally below.
        $this->enqueue_lightbox();

        if ('carousel' === $layout_slug) {
            $this->enqueue_carousel();
        }

        // Phase 11.1 — analytics capture (no-op when disabled).
        $this->enqueue_analytics();
    }

    /**
     * Phase 15.2 — ensure presets.css loads BEFORE card.css so the
     * higher-specificity `.vyg-feed .vyg-card__title` rules in
     * presets.css don't override the line-clamp rules in card.css.
     *
     * Previously: `vyg-card` depended on `vyg` (base only), so
     * `presets.css` would enqueue after card.css and its
     * `.vyg-feed .vyg-card__title` rules (specificity 0,2,0) would
     * override card.css's `.vyg-card__title` rules (0,1,0). Now we
     * call maybe_enqueue_presets() here first AND re-register the
     * `vyg-card` handle to depend on `vyg-presets` so it cascades
     * after.
     */
    public function enqueue_card_assets(): void {
        if ($this->card_enqueued) {
            return;
        }
        // Load presets first so card.css cascades after.
        $this->maybe_enqueue_presets();
        wp_register_style('vyg-card', $this->url('css/card.css'), array(self::HANDLE_BASE, self::HANDLE_BASE . '-presets'), self::VERSION);
        wp_enqueue_style('vyg-card');
        $this->card_enqueued = true;
    }

    public function maybe_enqueue_presets(): void {
        if ($this->presets_enqueued) {
            return;
        }
        wp_register_style(
            self::HANDLE_BASE . '-presets',
            $this->url('css/presets.css'),
            array(self::HANDLE_BASE),
            self::VERSION
        );
        wp_enqueue_style(self::HANDLE_BASE . '-presets');
        $this->presets_enqueued = true;
    }

    /**
     * Phase 14.10 — enqueue the shared trust-strip stylesheet once
     * per request. The trust strip is rendered by grid, masonry, and
     * carousel only; other layouts do not call this method, so the
     * CSS only loads on pages that actually emit the strip.
     *
     * Idempotent: enqueued once per request via the $trust_strip_enqueued
     * flag, mirroring the maybe_enqueue_presets() pattern.
     */
    public function maybe_enqueue_trust_strip(): void {
        if ($this->trust_strip_enqueued) {
            return;
        }
        wp_register_style(
            self::HANDLE_BASE . '-trust-strip',
            $this->url('css/trust-strip.css'),
            array(self::HANDLE_BASE, 'vyg-card'),
            self::VERSION
        );
        wp_enqueue_style(self::HANDLE_BASE . '-trust-strip');
        $this->trust_strip_enqueued = true;
    }

    public function enqueue_carousel(): void {
        if ($this->carousel_enqueued) {
            return;
        }
        wp_register_script(
            self::HANDLE_BASE . '-carousel',
            $this->url('js/carousel.js'),
            array(),
            self::VERSION,
            true
        );
        wp_enqueue_script(self::HANDLE_BASE . '-carousel');
        $this->carousel_enqueued = true;
    }

    /**
     * Phase 11.1 — enqueue analytics capture script + inline settings.
     * No-op when analytics is disabled (privacy by default).
     */
    public function enqueue_analytics(): void {
        if ($this->analytics_enqueued) {
            return;
        }
        if (! \VectorYT\Gallery\Analytics\EventRepository::is_enabled()) {
            // Mark enqueued so we don't re-check on every feed render.
            $this->analytics_enqueued = true;
            return;
        }
        wp_register_script(
            self::HANDLE_BASE . '-analytics',
            $this->url('js/analytics.js'),
            array(),
            self::VERSION,
            true
        );
        wp_localize_script(
            self::HANDLE_BASE . '-analytics',
            'VYG_ANALYTICS',
            array(
                'enabled'  => true,
                'restRoot' => esc_url_raw(rest_url('vyg/v1/')),
                'nonce'    => wp_create_nonce('wp_rest'),
            )
        );
        wp_enqueue_script(self::HANDLE_BASE . '-analytics');
        $this->analytics_enqueued = true;
    }

    public function enqueue_lightbox(): void {
        if ($this->lightbox_enqueued) {
            return;
        }
        wp_register_script(
            self::HANDLE_BASE . '-lightbox',
            $this->url('js/lightbox.js'),
            array(),
            self::VERSION,
            true
        );
        wp_enqueue_script(self::HANDLE_BASE . '-lightbox');
        $this->lightbox_enqueued = true;
    }

    public function enqueue_load_more(): void {
        if ($this->load_more_enqueued) {
            return;
        }
        $this->enqueue_lightbox();
        wp_register_script(
            self::HANDLE_BASE . '-load-more',
            $this->url('js/load-more.js'),
            array('wp-i18n'),
            self::VERSION,
            true
        );
        wp_set_script_translations(self::HANDLE_BASE . '-load-more', 'vector-youtube-gallery');
        wp_enqueue_script(self::HANDLE_BASE . '-load-more');
        wp_localize_script(self::HANDLE_BASE . '-load-more', 'VYG', array(
            'restUrl'       => esc_url_raw(rest_url('vyg/v1/feed')),
            // Phase 8.4: feed-by-uuid endpoint.
            'feedByUuidUrl' => esc_url_raw(rest_url('vyg/v1/feed/{uuid}')),
            'restNonce'     => wp_create_nonce('wp_rest'),
        ));
        $this->load_more_enqueued = true;
    }

    public function url(string $relative): string {
        return VYG_PLUGIN_URL . 'assets/' . ltrim($relative, '/');
    }

    public function path(string $relative): string {
        return VYG_PLUGIN_DIR . 'assets/' . ltrim($relative, '/');
    }
}
