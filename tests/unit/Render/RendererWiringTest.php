<?php
/**
 * Renderer wiring tests.
 *
 * The Renderer is the seam between the shortcode/block layer (which receives
 * attribute overrides) and the layout templates (which read $ctx['attrs']).
 * These tests pin down the contract:
 *
 *   1. Inline shortcode attributes flow into the template's $attrs.
 *   2. Saved feed_config['display'] keys flow into the template's $attrs
 *      (so a feed stored with `density=editorial` renders editorial even
 *      when the shortcode omits the attribute).
 *   3. Inline attributes override saved values.
 *   4. product_cta_visible=false hides the CTA even when a product map exists.
 *
 * Phase B2 additions (test_b2_*): the layout-template context also
 * exposes `card_renderer` (a CardRenderer instance) and `card_settings`
 * (the output of CardSettings::resolve — 45 normalized keys, with
 * inline > per-layout > global > legacy > profile > defaults precedence).
 * The test seam is Renderer::build_layout_context(), invoked via
 * Reflection so the protected method stays out of the public API.
 *
 * @covers \VectorYT\Gallery\Render\Renderer
 * @covers \VectorYT\Gallery\Render\CardSettings  (only via Renderer::build_layout_context)
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Render;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Render\CardRenderer;
use VectorYT\Gallery\Render\CardSettings;
use VectorYT\Gallery\Render\LiveQuery;
use VectorYT\Gallery\Render\Renderer;
use VectorYT\Gallery\Render\TemplateLoader;
use VectorYT\Gallery\Render\VideoRenderer;
use VectorYT\Gallery\Tests\Support\BrainHelpers;

require_once __DIR__ . '/../../bootstrap.php';

final class RendererWiringTest extends TestCase
{
    private TemplateLoader $templates;
    private VideoRenderer $video_renderer;
    private LiveQuery $live_query;
    private FakeFeedQuery $feeds;

    protected function setUp(): void
    {
        parent::setUp();
        \Brain\Monkey\setUp();
        BrainHelpers::stubEscapeFunctions();
        BrainHelpers::stubOptionFunctions();
        // The Renderer calls apply_filters('vyg_phase10_7_product_map_for_feed', ...)
        // to merge the Phase 10.7 product map. Default: passthrough (no map).
        Functions\when( 'apply_filters' )->alias(
            static function ( $tag, $default, ...$args ) {
                return $default;
            }
        );
        $this->templates      = new TemplateLoader();
        $this->video_renderer = new VideoRenderer();
        $this->live_query     = new LiveQuery( $this->createMock( \VectorYT\Gallery\Repository\PreviousStreamsRepository::class ) );
        $this->feeds          = new FakeFeedQuery();
    }

    protected function tearDown(): void
    {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_inline_attributes_flow_into_template_attrs(): void
    {
        $renderer = new Renderer( $this->feeds, $this->video_renderer, $this->templates, $this->live_query );
        $html = $renderer->render( array(
            'source_uuid'      => 'src-test',
            'layout'           => 'grid',
            'per_page'         => 5,
            'density'          => 'editorial',
            'header_title'     => 'Inline Header',
            'header_cta_label' => 'Visit',
            'header_cta_url'   => 'https://example.com',
            'trust_strip'      => true,
        ) );
        $this->assertStringContainsString( 'vyg-grid--density-editorial', $html );
        $this->assertStringContainsString( 'Inline Header', $html );
        $this->assertStringContainsString( 'https://example.com', $html );
        $this->assertStringContainsString( 'vyg-grid__trust-strip', $html );
    }

    public function test_saved_display_config_flows_into_template_attrs(): void
    {
        // A saved feed has display_config_json with density + header settings.
        // The shortcode does NOT pass those attributes inline; the Renderer
        // should still pull them from the saved config.
        $this->feeds->source_row = array(
            'source_uuid' => 'src-saved',
            'source_type' => 'channel',
            'youtube_channel_id' => 'UC_saved',
            'title'       => 'Saved Source',
            'status'      => 'active',
        );
        $renderer = new Renderer( $this->feeds, $this->video_renderer, $this->templates, $this->live_query );
        $html = $renderer->render( array(
            'source_uuid'  => 'src-saved',
            'source_config' => array(
                'sources' => array(
                    array( 'source_uuid' => 'src-saved', 'weight' => 1.0, 'pinned' => false, 'label' => '' ),
                ),
                'manual_video_ids' => array(),
            ),
            'feed_config' => array(
                'display' => array(
                    'density'      => 'compact',
                    'header_title' => 'Saved Header',
                    'trust_strip'  => true,
                    'show_views_and_time' => false,
                ),
            ),
        ) );
        $this->assertStringContainsString( 'vyg-grid--density-compact', $html );
        $this->assertStringContainsString( 'Saved Header', $html );
        $this->assertStringContainsString( 'vyg-grid__trust-strip', $html );
    }

    public function test_inline_attributes_override_saved_display_config(): void
    {
        // Saved config says editorial + saved header, inline says compact + no header.
        // Inline should win.
        $this->feeds->source_row = array(
            'source_uuid' => 'src-override',
            'source_type' => 'channel',
            'youtube_channel_id' => 'UC_ovr',
            'title'       => 'Override Source',
            'status'      => 'active',
        );
        $renderer = new Renderer( $this->feeds, $this->video_renderer, $this->templates, $this->live_query );
        $html = $renderer->render( array(
            'source_uuid'  => 'src-override',
            'source_config' => array(
                'sources' => array(
                    array( 'source_uuid' => 'src-override', 'weight' => 1.0, 'pinned' => false, 'label' => '' ),
                ),
                'manual_video_ids' => array(),
            ),
            'density'      => 'compact',
            'header_title' => '',
            'feed_config'  => array(
                'display' => array(
                    'density'      => 'editorial',
                    'header_title' => 'Saved Header',
                ),
            ),
        ) );
        $this->assertStringContainsString( 'vyg-grid--density-compact', $html );
        $this->assertStringNotContainsString( 'Saved Header', $html );
    }

    public function test_product_cta_visible_false_hides_cta_hook(): void
    {
        // Phase B3 — the old per-card CTA hook (`vyg-card__cta-wrap`
        // containing the WooCommerce `vyg_render_product_cta` output)
        // was the grid template's responsibility. After B3 the grid
        // template delegates every per-card region to the shared
        // CardRenderer, which decides whether to render a CTA based on
        // `card_settings['show_cta']`. The legacy `product_cta_visible`
        // boolean still controls the same outcome via the LEGACY_MAP
        // (product_cta_visible → show_cta) but the rendered selector
        // is the shared `vyg-card__cta` (not the legacy
        // `vyg-card__cta-wrap`). The legacy key lives in the saved
        // display config (the path the Phase 13.1 admin UI writes to),
        // so we set it there.
        $this->feeds->source_row = array(
            'source_uuid' => 'src-cta',
            'source_type' => 'channel',
            'youtube_channel_id' => 'UC_cta',
            'title'       => 'CTA Source',
            'status'      => 'active',
        );
        $renderer = new Renderer( $this->feeds, $this->video_renderer, $this->templates, $this->live_query );
        $html_on  = $renderer->render( array(
            'source_uuid'       => 'src-cta',
            'source_config'     => array(
                'sources' => array(
                    array( 'source_uuid' => 'src-cta', 'weight' => 1.0, 'pinned' => false, 'label' => '' ),
                ),
                'manual_video_ids' => array(),
            ),
            'feed_config' => array(
                'display' => array(
                    'product_cta_visible' => true,
                ),
            ),
        ) );
        $html_off = $renderer->render( array(
            'source_uuid'       => 'src-cta',
            'source_config'     => array(
                'sources' => array(
                    array( 'source_uuid' => 'src-cta', 'weight' => 1.0, 'pinned' => false, 'label' => '' ),
                ),
                'manual_video_ids' => array(),
            ),
            'feed_config' => array(
                'display' => array(
                    'product_cta_visible' => false,
                ),
            ),
        ) );
        // product_cta_visible=true (legacy saved display) → legacy map
        // sets show_cta=true → shared CardRenderer emits the CTA in the
        // card footer.
        $this->assertStringContainsString( 'vyg-card__cta', $html_on );
        // product_cta_visible=false (legacy saved display) → show_cta=false
        // → no CTA.
        $this->assertStringNotContainsString( 'vyg-card__cta', $html_off );
    }

    // -----------------------------------------------------------------
    // Phase B2 — CardRenderer + resolved card_settings plumbed into the
    // layout-template context.
    //
    // The tests below verify that Renderer::emit_html() builds a $ctx
    // array that exposes:
    //   - 'card_renderer' => a CardRenderer instance
    //   - 'card_settings' => the output of CardSettings::resolve(...)
    //     (45 normalized keys, with the correct precedence applied)
    //
    // The seam is Renderer::build_layout_context(), a protected method
    // that constructs and returns the ctx array. The tests invoke it via
    // Closure::bind to access the protected scope without making the
    // method part of the public API. (The plan's "DO NOT change the
    // public render() signature" rule is preserved.)
    // -----------------------------------------------------------------

    /**
     * Invoke the protected Renderer::build_layout_context() method using
     * Closure::bind so the test can inspect the ctx that emit_html()
     * passes to the layout. Returns the ctx array.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function capture_layout_context( Renderer $renderer, array $args, ?array $source = null, array $videos = array(), int $total = 0, string $layout_slug = 'grid', int $per_page = 12, int $offset = 0 ): array {
        if ( null === $source ) {
            $source = array(
                'source_uuid' => 'src-test',
                'source_type' => 'channel',
                'title'       => 'Test Source',
                'status'      => 'active',
            );
        }
        // Use Reflection to invoke the protected Renderer::build_layout_context()
        // method. Closure::fromCallable cannot access protected methods even with
        // rebinding, but ReflectionMethod::invoke() bypasses the access check
        // when the method is set accessible.
        $method = new \ReflectionMethod( Renderer::class, 'build_layout_context' );
        $method->setAccessible( true );
        /** @var array<string,mixed> $ctx */
        $ctx = $method->invoke( $renderer, $args, $source, $videos, $total, $layout_slug, $per_page, $offset );
        return $ctx;
    }

    public function test_b2_layout_context_exposes_card_renderer_instance(): void {
        $renderer = new Renderer( $this->feeds, $this->video_renderer, $this->templates, $this->live_query );
        $ctx = $this->capture_layout_context(
            $renderer,
            array(
                'source_uuid' => 'src-b2-1',
                'layout'      => 'grid',
            )
        );
        $this->assertArrayHasKey( 'card_renderer', $ctx );
        $this->assertInstanceOf( CardRenderer::class, $ctx['card_renderer'] );
    }

    public function test_b2_layout_context_exposes_resolved_card_settings_with_all_43_keys(): void {
        $renderer = new Renderer( $this->feeds, $this->video_renderer, $this->templates, $this->live_query );
        $ctx = $this->capture_layout_context(
            $renderer,
            array(
                'source_uuid' => 'src-b2-2',
                'layout'      => 'grid',
            )
        );
        $this->assertArrayHasKey( 'card_settings', $ctx );
        $this->assertIsArray( $ctx['card_settings'] );
        $this->assertNotEmpty( $ctx['card_settings'] );
        $allowed = CardSettings::allowed_keys();
        $this->assertCount( 57, $allowed, 'sanity: CardSettings::allowed_keys() should return 57 keys (Phase 14 thumbnail controls added fit position + override URL)' );
        foreach ( $allowed as $key ) {
            $this->assertArrayHasKey( $key, $ctx['card_settings'], "card_settings must contain key: {$key}" );
        }
    }

    public function test_b2_inline_args_override_saved_global_card_settings(): void {
        // Saved global says show_description=true; inline shortcode says
        // show_description='false' (string, simulating a real shortcode
        // attr). The resolver must coerce the string to bool and the
        // inline value must win (per CardSettings::resolve precedence).
        $renderer = new Renderer( $this->feeds, $this->video_renderer, $this->templates, $this->live_query );
        $ctx = $this->capture_layout_context(
            $renderer,
            array(
                'source_uuid' => 'src-b2-3',
                'layout'      => 'grid',
                'show_description' => 'false', // inline
                'feed_config' => array(
                    'display' => array(
                        'card_settings' => array(
                            'global' => array(
                                'show_description' => true, // saved
                            ),
                        ),
                    ),
                ),
            )
        );
        $this->assertFalse(
            $ctx['card_settings']['show_description'],
            'inline show_description=false must override saved global show_description=true'
        );
    }

    public function test_b2_layout_level_override_beats_saved_global(): void {
        // Saved global says show_description=true; saved layouts.grid
        // says show_description=false. With layout='grid', the layout
        // override must win (per CardSettings::resolve precedence:
        // layouts[$layout] overrides global).
        $renderer = new Renderer( $this->feeds, $this->video_renderer, $this->templates, $this->live_query );
        $ctx = $this->capture_layout_context(
            $renderer,
            array(
                'source_uuid' => 'src-b2-4',
                'layout'      => 'grid',
                'feed_config' => array(
                    'display' => array(
                        'card_settings' => array(
                            'global' => array(
                                'show_description' => true,
                            ),
                            'layouts' => array(
                                'grid' => array(
                                    'show_description' => false,
                                ),
                            ),
                        ),
                    ),
                ),
            )
        );
        $this->assertFalse(
            $ctx['card_settings']['show_description'],
            'layouts.grid.show_description=false must override global.show_description=true'
        );
    }

    public function test_b2_card_renderer_can_render_using_resolved_settings(): void {
        // The wiring is only complete if a layout template can call
        // $card_renderer->render( $video, $card_settings ) and get a
        // string back. We assert this by pulling both vars out of the
        // captured ctx and invoking the renderer directly with a minimal
        // fake video — the same pattern layouts will use in B3.
        $renderer = new Renderer( $this->feeds, $this->video_renderer, $this->templates, $this->live_query );
        $ctx = $this->capture_layout_context(
            $renderer,
            array(
                'source_uuid' => 'src-b2-5',
                'layout'      => 'grid',
            )
        );
        $video = array(
            'youtube_video_id'      => 'b2video',
            'youtube_channel_id'    => 'UC_b2',
            'youtube_channel_title' => 'B2 Channel',
            'title'                 => 'B2 Wiring Test',
            'thumbnail_medium'      => 'https://example.com/m.jpg',
            'thumbnail_high'        => 'https://example.com/h.jpg',
            'duration_seconds'      => 60,
            'content_type'          => 'standard',
            'live_status'           => 'none',
            'published_at'          => '2026-06-25T12:00:00+00:00',
            'view_count'            => 1,
        );
        $html = $ctx['card_renderer']->render( $video, $ctx['card_settings'] );
        $this->assertIsString( $html );
        $this->assertNotSame( '', $html );
        // The standard mode should be reflected in the outer class.
        $this->assertStringContainsString( 'vyg-card', $html );
    }
}

/**
 * A minimal FeedQuery double that returns canned data.
 */
class FakeFeedQuery extends \VectorYT\Gallery\Render\FeedQuery {
    public array $source_row = array();
    public array $videos     = array();

    public function find_source_by_uuid( string $uuid ): ?array {
        return $this->source_row ?: array(
            'source_uuid' => $uuid,
            'source_type' => 'channel',
            'youtube_channel_id' => 'UC_' . $uuid,
            'title'       => 'Test Source',
            'status'      => 'active',
        );
    }

    public function videos_for_source( array $args ): array {
        if ( ! empty( $this->videos ) ) {
            return $this->videos;
        }
        return array(
            array(
                'youtube_video_id'    => 'fake1',
                'youtube_channel_id'  => 'UC_test',
                'title'               => 'Wired Test Video',
                'thumbnail_high'      => 'https://example.com/thumb.jpg',
                'duration_seconds'    => 120,
                'content_type'        => 'standard',
                'live_status'         => 'none',
                'published_at'        => '2026-06-25T12:00:00+00:00',
                'view_count'          => 5000,
                'youtube_channel_title' => 'Test Channel',
            ),
        );
    }

    public function count_videos_for_source( array $args ): int {
        return 1;
    }
}
