<?php
/**
 * Phase 13.1 — Integration tests for the redesigned grid template.
 *
 * Validates the structural contract of the rendered HTML for the new card
 * (header, channel row, views+time meta, optional CTA, optional trust strip)
 * and the density class hook on the root.
 *
 * The Renderer-level wiring tests live in GridHeaderRenderingTest, GridDensityTest,
 * and GridProductCtaVisibilityTest; this file focuses on the template output.
 *
 * @covers \VectorYT\Gallery\Render\Layouts\GridLayout
 * @covers \VectorYT\Gallery\Render\TemplateLoader
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Render\TemplateLoader;
use VectorYT\Gallery\Render\VideoRenderer;
use VectorYT\Gallery\Render\RelativeTime;
use VectorYT\Gallery\Tests\Support\BrainHelpers;

final class GridTemplateTest extends TestCase
{
    private TemplateLoader $loader;
    private VideoRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        \Brain\Monkey\setUp();
        BrainHelpers::stubEscapeFunctions();
        BrainHelpers::stubOptionFunctions();
        $this->loader = new TemplateLoader();
        $this->renderer = new VideoRenderer();
    }

    protected function tearDown(): void
    {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_root_carries_density_class(): void
    {
        $html = $this->loader->render('grid', $this->ctx( array(
            'density' => 'editorial',
        ) ) );
        $this->assertStringContainsString( 'vyg-grid--density-editorial', $html );
    }

    public function test_root_carries_columns_class(): void
    {
        $html = $this->loader->render('grid', $this->ctx( array( 'columns' => 4 ) ) );
        $this->assertStringContainsString( 'vyg-grid--cols-4', $html );
    }

    public function test_empty_state_does_not_render_header_or_trust_strip(): void
    {
        $html = $this->loader->render('grid', array(
            'source'   => array( 'title' => 'X', 'source_uuid' => 'fake' ),
            'videos'   => array(),
            'attrs'    => array(
                'layout'                 => 'grid',
                'columns'                => 3,
                'wrapper_id'             => 'vyg-test',
                'public_safe'            => false,
                'header_title'           => 'My Channel',
                'header_cta_label'       => 'Visit',
                'header_cta_url'         => 'https://example.com',
                'trust_strip'            => true,
                'show_views_and_time'    => true,
                'product_cta_visible'    => true,
                'show_channel_name'      => true,
            ),
            'renderer' => null,
        ) );
        $this->assertStringContainsString( 'vyg-feed--empty', $html );
        $this->assertStringNotContainsString( 'vyg-grid__header', $html );
        $this->assertStringNotContainsString( 'vyg-grid__trust-strip', $html );
    }

    public function test_header_renders_when_title_set(): void
    {
        $html = $this->loader->render('grid', $this->ctx( array(
            'header_title'     => 'My Channel',
            'header_subtitle'  => 'Latest uploads',
        ) ) );
        $this->assertStringContainsString( 'vyg-grid__header', $html );
        $this->assertStringContainsString( 'My Channel', $html );
        $this->assertStringContainsString( 'Latest uploads', $html );
    }

    public function test_header_omitted_when_title_empty(): void
    {
        $html = $this->loader->render('grid', $this->ctx( array(
            'header_title'     => '',
            'header_subtitle'  => 'Latest uploads',
        ) ) );
        $this->assertStringNotContainsString( 'vyg-grid__header', $html );
    }

    public function test_header_renders_cta_label_and_url(): void
    {
        $html = $this->loader->render('grid', $this->ctx( array(
            'header_title'    => 'Channel',
            'header_cta_label' => 'Visit',
            'header_cta_url'  => 'https://example.com',
        ) ) );
        $this->assertStringContainsString( 'vyg-grid__header-cta', $html );
        $this->assertStringContainsString( 'href="https://example.com"', $html );
        $this->assertStringContainsString( 'Visit', $html );
    }

    public function test_header_columns_indicator_when_enabled(): void
    {
        $html = $this->loader->render('grid', $this->ctx( array(
            'header_title'              => 'Channel',
            'header_columns_visible'    => true,
        ) ) );
        $this->assertStringContainsString( 'vyg-grid__header-cols', $html );
    }

    public function test_header_columns_indicator_hidden_when_disabled(): void
    {
        $html = $this->loader->render('grid', $this->ctx( array(
            'header_title'              => 'Channel',
            'header_columns_visible'    => false,
        ) ) );
        $this->assertStringNotContainsString( 'vyg-grid__header-cols', $html );
    }

    public function test_card_renders_channel_name(): void
    {
        $html = $this->loader->render('grid', $this->ctx( array(
            'show_channel_name' => true,
        ) ) );
        $this->assertStringContainsString( 'vyg-card__channel', $html );
        $this->assertStringContainsString( 'Test Channel', $html );
    }

    public function test_card_omits_channel_row_when_disabled(): void
    {
        $html = $this->loader->render('grid', $this->ctx( array(
            'show_channel_name' => false,
        ) ) );
        // vyg-card__channel-name class is only emitted when the row is on.
        $this->assertStringNotContainsString( 'vyg-card__channel-name', $html );
    }

    public function test_card_renders_views_and_time_row(): void
    {
        $html = $this->loader->render('grid', $this->ctx( array(
            'show_views_and_time' => true,
        ) ) );
        $this->assertStringContainsString( 'vyg-card__meta', $html );
        $this->assertStringContainsString( 'vyg-card__meta-views', $html );
        $this->assertStringContainsString( 'vyg-card__meta-time', $html );
        $this->assertStringContainsString( '125K', $html ); // 125,000 → 125K (>= 10K, no decimal)
    }

    public function test_card_omits_views_and_time_when_disabled(): void
    {
        $html = $this->loader->render('grid', $this->ctx( array(
            'show_views_and_time' => false,
        ) ) );
        $this->assertStringNotContainsString( 'vyg-card__meta-views', $html );
        $this->assertStringNotContainsString( 'vyg-card__meta-time', $html );
    }

    public function test_trust_strip_renders_when_enabled(): void
    {
        $html = $this->loader->render('grid', $this->ctx( array(
            'trust_strip' => true,
        ) ) );
        $this->assertStringContainsString( 'vyg-grid__trust-strip', $html );
        $this->assertStringContainsString( 'Lazy Loaded', $html );
        $this->assertStringContainsString( 'Privacy Safe', $html );
        $this->assertStringContainsString( 'Accessible', $html );
        $this->assertStringContainsString( 'Builder Ready', $html );
    }

    public function test_trust_strip_omitted_by_default(): void
    {
        $html = $this->loader->render('grid', $this->ctx() );
        $this->assertStringNotContainsString( 'vyg-grid__trust-strip', $html );
    }

    public function test_relative_time_is_rendered(): void
    {
        // Card 0 has published_at 2026-06-25. The relative-time label should
        // be "5 days ago" given any "now" of 2026-06-30. We verify only the
        // presence of the meta-time class which wraps the formatted string,
        // since the actual label depends on the wall clock at render time.
        $html = $this->loader->render('grid', $this->ctx() );
        $this->assertStringContainsString( 'vyg-card__meta-time', $html );
    }

    /**
     * Build a render context. Defaults match the conventional shortcode
     * defaults; pass overrides in $attr_overrides.
     *
     * @param array<string,mixed> $attr_overrides
     * @return array<string,mixed>
     */
    private function ctx( array $attr_overrides = array() ): array {
        $attrs = array_merge( array(
            'layout'                 => 'grid',
            'columns'                => 3,
            'wrapper_id'             => 'vyg-test',
            'public_safe'            => false,
            'density'                => 'comfortable',
            'header_title'           => '',
            'header_subtitle'        => '',
            'header_columns_visible' => true,
            'header_cta_label'       => '',
            'header_cta_url'         => '',
            'show_channel_avatar'    => false,
            'show_channel_name'      => true,
            'show_subscriber_count'  => false,
            'show_verified_badge'    => false,
            'show_views_and_time'    => true,
            'product_cta_visible'    => true,
            'trust_strip'            => false,
            'card_radius'            => '12px',
        ), $attr_overrides );

        return array(
            'source'   => array(
                'title'       => 'Test Source',
                'source_uuid' => 'fake-uuid',
            ),
            'videos'   => array(
                array(
                    'youtube_video_id'    => 'abc123',
                    'youtube_channel_id'  => 'UC_test',
                    'title'               => 'Test Video Title',
                    'thumbnail_high'      => 'https://example.com/thumb.jpg',
                    'duration_seconds'    => 765,
                    'content_type'        => 'standard',
                    'live_status'         => 'none',
                    'published_at'        => '2026-06-25T12:00:00+00:00',
                    'view_count'          => 125000,
                    'youtube_channel_title' => 'Test Channel',
                ),
            ),
            'attrs'    => $attrs,
            'renderer' => $this->renderer,
        );
    }
}
