<?php
/**
 * Phase 13.1 — Renderer wiring tests.
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
 * @covers \VectorYT\Gallery\Render\Renderer
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
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
        $this->feeds->source_row = array(
            'source_uuid' => 'src-cta',
            'source_type' => 'channel',
            'youtube_channel_id' => 'UC_cta',
            'title'       => 'CTA Source',
            'status'      => 'active',
        );
        // Define a function that would normally render a CTA, so we can
        // verify the template short-circuits when product_cta_visible=false.
        if ( ! function_exists( 'vyg_render_product_cta' ) ) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
            eval( 'function vyg_render_product_cta( $feed_cfg, $video_id ) { return "<button class=\"vyg-card__cta\">BUY</button>"; }' );
        }
        $renderer = new Renderer( $this->feeds, $this->video_renderer, $this->templates, $this->live_query );
        $html_on  = $renderer->render( array(
            'source_uuid'       => 'src-cta',
            'source_config'     => array(
                'sources' => array(
                    array( 'source_uuid' => 'src-cta', 'weight' => 1.0, 'pinned' => false, 'label' => '' ),
                ),
                'manual_video_ids' => array(),
            ),
            'product_cta_visible' => true,
        ) );
        $html_off = $renderer->render( array(
            'source_uuid'       => 'src-cta',
            'source_config'     => array(
                'sources' => array(
                    array( 'source_uuid' => 'src-cta', 'weight' => 1.0, 'pinned' => false, 'label' => '' ),
                ),
                'manual_video_ids' => array(),
            ),
            'product_cta_visible' => false,
        ) );
        $this->assertStringContainsString( 'vyg-card__cta-wrap', $html_on );
        $this->assertStringNotContainsString( 'vyg-card__cta-wrap', $html_off );
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
