<?php
/**
 * Phase 13.1 + Phase B3 — Integration tests for the redesigned grid template.
 *
 * Phase 13.1 contract: validates the structural HTML of the grid wrapper
 * (header, density class, column count, trust strip, pagination) and the
 * legacy per-card anatomy (channel row, views+time meta).
 *
 * Phase B3 contract: the per-card anatomy is no longer emitted inline
 * by grid.php. The template delegates to the shared CardRenderer, and
 * every assertion below that exercises a card region must be expressed
 * in terms of the shared renderer's output (vyg-card / vyg-card--* /
 * vyg-card__* classes from the shared partial at
 * src/Render/templates/partials/video-card.php).
 *
 * The Renderer-level wiring tests live in RendererWiringTest, GridHeaderRenderingTest,
 * GridDensityTest, and GridProductCtaVisibilityTest; this file focuses on the
 * template output.
 *
 * @covers \VectorYT\Gallery\Render\Layouts\GridLayout
 * @covers \VectorYT\Gallery\Render\TemplateLoader
 * @covers \VectorYT\Gallery\Render\CardRenderer
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Render\CardRenderer;
use VectorYT\Gallery\Render\CardSettings;
use VectorYT\Gallery\Render\TemplateLoader;
use VectorYT\Gallery\Render\VideoRenderer;
use VectorYT\Gallery\Render\RelativeTime;
use VectorYT\Gallery\Tests\Support\BrainHelpers;

final class GridTemplateTest extends TestCase
{
    private TemplateLoader $loader;
    private VideoRenderer $renderer;
    private CardRenderer $card_renderer;

    protected function setUp(): void
    {
        parent::setUp();
        \Brain\Monkey\setUp();
        BrainHelpers::stubEscapeFunctions();
        BrainHelpers::stubOptionFunctions();
        $this->loader         = new TemplateLoader();
        $this->renderer       = new VideoRenderer();
        $this->card_renderer  = new CardRenderer( $this->renderer, $this->loader );
    }

    protected function tearDown(): void
    {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Phase 13.1 — grid wrapper structure
    // -----------------------------------------------------------------

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
        $html = $this->loader->render('grid', $this->empty_ctx( array(
            'header_title' => 'My Channel',
            'trust_strip'  => true,
        ) ) );
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

    // -----------------------------------------------------------------
    // Phase B3 — shared CardRenderer integration contract
    //
    // The grid template delegates per-card rendering to the shared
    // CardRenderer. These tests assert the layout-integration contract:
    //
    //   1. The grid wrapper still emits its structural hooks.
    //   2. Each card region comes from the shared renderer's output.
    //   3. card_settings flow through to the renderer (e.g. show_metadata,
    //      show_description, thumbnail_ratio).
    //   4. The number of card articles in the output matches the number
    //      of videos passed in.
    // -----------------------------------------------------------------

    public function test_renders_one_vyg_card_per_video(): void
    {
        // 3 videos in → 3 vyg-card <article>s out.
        $videos = $this->three_videos();
        $html   = $this->loader->render('grid', $this->ctx_with_videos( $videos ) );
        $this->assertSame( 3, substr_count( $html, '<article class="vyg-card ' ) );
    }

    public function test_renders_twelve_cards_from_seed_shape(): void
    {
        // Mirrors dev/seed-phase13-1.php: 12 standard videos in one feed.
        $videos = $this->twelve_seed_videos();
        $html   = $this->loader->render('grid', $this->ctx_with_videos( $videos ) );
        $this->assertSame( 12, substr_count( $html, '<article class="vyg-card ' ) );
    }

    public function test_grid_cards_use_shared_renderer_standard_mode(): void
    {
        $html = $this->loader->render('grid', $this->ctx() );
        // Every card carries the shared-renderer class composition:
        //   vyg-card vyg-card--standard vyg-card--bordered vyg-density--comfortable
        $this->assertStringContainsString( 'vyg-card vyg-card--standard', $html );
        $this->assertStringContainsString( 'vyg-card--bordered', $html );
        $this->assertStringContainsString( 'vyg-density--comfortable', $html );
    }

    public function test_grid_cards_carry_listitem_role(): void
    {
        // The shared partial emits role="listitem" on the article; the
        // grid template must not emit its own role on the article.
        $html = $this->loader->render('grid', $this->ctx() );
        $this->assertStringContainsString( 'role="listitem"', $html );
    }

    public function test_grid_cards_omit_meta_when_show_metadata_false(): void
    {
        $html = $this->loader->render('grid', $this->ctx( array(
            'show_views_and_time' => false, // legacy attr → show_metadata=false
        ) ) );
        // The shared partial's meta block uses the vyg-card__meta-views /
        // vyg-card__meta-time classes inside the vyg-card__meta wrapper.
        $this->assertStringNotContainsString( 'vyg-card__meta-views', $html );
        $this->assertStringNotContainsString( 'vyg-card__meta-time', $html );
        $this->assertStringNotContainsString( 'class="vyg-card__meta"', $html );
    }

    public function test_grid_cards_render_description_when_show_description_true(): void
    {
        $html = $this->loader->render('grid', $this->ctx( array(
            'show_description' => true,
        ) ) );
        // The shared partial emits the description inside
        // <p class="vyg-card__description"> ... </p> when the video has a
        // non-empty description.
        $this->assertStringContainsString( 'vyg-card__description', $html );
        $this->assertStringContainsString( 'Test video description', $html );
    }

    public function test_grid_cards_omit_description_when_show_description_false(): void
    {
        $html = $this->loader->render('grid', $this->ctx( array(
            'show_description' => false,
        ) ) );
        $this->assertStringNotContainsString( 'vyg-card__description', $html );
    }

    public function test_grid_cards_apply_thumbnail_ratio_9_16_class(): void
    {
        $html = $this->loader->render('grid', $this->ctx( array(
            'thumbnail_ratio' => '9_16',
        ) ) );
        // The shared partial maps the 9_16 ratio to vyg-thumb-ratio--9-16
        // (underscore → dash). The class must appear on the media wrapper.
        $this->assertStringContainsString( 'vyg-thumb-ratio--9-16', $html );
    }

    public function test_grid_cards_apply_thumbnail_ratio_1_1_class(): void
    {
        $html = $this->loader->render('grid', $this->ctx( array(
            'thumbnail_ratio' => '1_1',
        ) ) );
        $this->assertStringContainsString( 'vyg-thumb-ratio--1-1', $html );
    }

    public function test_grid_cards_have_channel_row_when_show_channel_name_true(): void
    {
        $html = $this->loader->render('grid', $this->ctx( array(
            'show_channel_name' => true, // legacy → show_channel=true
        ) ) );
        // Shared partial renders vyg-card__channel-name inside the
        // vyg-card__channel wrapper when show_channel=true.
        $this->assertStringContainsString( 'vyg-card__channel-name', $html );
        $this->assertStringContainsString( 'Test Channel', $html );
    }

    public function test_grid_cards_omit_channel_row_when_show_channel_name_false(): void
    {
        $html = $this->loader->render('grid', $this->ctx( array(
            'show_channel_name' => false, // legacy → show_channel=false
        ) ) );
        $this->assertStringNotContainsString( 'vyg-card__channel-name', $html );
    }

    public function test_grid_cards_render_views_and_time_by_default(): void
    {
        // show_views_and_time defaults to true. The shared partial renders
        // views (vyg-card__meta-views) and relative time (vyg-card__meta-time).
        $html = $this->loader->render('grid', $this->ctx() );
        $this->assertStringContainsString( 'vyg-card__meta-views', $html );
        $this->assertStringContainsString( 'vyg-card__meta-time', $html );
        $this->assertStringContainsString( '125K', $html ); // 125,000 → 125K
    }

    public function test_grid_cards_render_relative_time_label(): void
    {
        // The shared partial's time label is built by RelativeTime::humanize
        // and wrapped in vyg-card__meta-time. The actual label depends on
        // the wall clock at render time, so we assert on the class only.
        $html = $this->loader->render('grid', $this->ctx() );
        $this->assertStringContainsString( 'vyg-card__meta-time', $html );
    }

    public function test_empty_videos_array_renders_no_card_articles(): void
    {
        $html = $this->loader->render('grid', $this->empty_ctx() );
        $this->assertStringNotContainsString( 'vyg-card vyg-card--standard', $html );
    }

    public function test_grid_cards_carry_data_video_id_attribute(): void
    {
        $html = $this->loader->render('grid', $this->ctx() );
        // The shared partial emits data-video-id from the video row.
        $this->assertStringContainsString( 'data-video-id="abc123"', $html );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Build a render context with one video row and the standard Phase 13.1
     * defaults. Pass overrides in $attr_overrides.
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
            'show_description'       => false,
            'card_radius'            => '12px',
        ), $attr_overrides );

        // The Renderer passes the flat saved display array as the second
        // arg to CardSettings::resolve; the legacy Phase 13.1 keys
        // (show_channel_name, show_views_and_time, product_cta_visible)
        // are read off that array. We mirror that here so the resolved
        // card_settings reflect the same precedence the production path
        // produces.
        $card_settings = CardSettings::resolve( 'grid', $attrs, $attrs );

        return array(
            'source'        => array(
                'title'       => 'Test Source',
                'source_uuid' => 'fake-uuid',
            ),
            'videos'        => array(
                array(
                    'youtube_video_id'      => 'abc123',
                    'youtube_channel_id'    => 'UC_test',
                    'title'                 => 'Test Video Title',
                    'description'           => 'Test video description — what a great clip.',
                    'thumbnail_high'        => 'https://example.com/thumb.jpg',
                    'duration_seconds'      => 765,
                    'content_type'          => 'standard',
                    'live_status'           => 'none',
                    'published_at'          => '2026-06-25T12:00:00+00:00',
                    'view_count'            => 125000,
                    'youtube_channel_title' => 'Test Channel',
                ),
            ),
            'attrs'         => $attrs,
            'renderer'      => $this->renderer,
            // Phase B2: required by the new shared-renderer path.
            'card_renderer' => $this->card_renderer,
            'card_settings' => $card_settings,
            'feed_uuid'     => '',
            'feed_config'   => array(),
        );
    }

    /**
     * Build a render context with the supplied video rows + the standard
     * attribute set. Used by the "12 cards from seed" test.
     *
     * @param array<int,array<string,mixed>> $videos
     * @param array<string,mixed> $attr_overrides
     * @return array<string,mixed>
     */
    private function ctx_with_videos( array $videos, array $attr_overrides = array() ): array {
        $ctx = $this->ctx( $attr_overrides );
        $ctx['videos'] = $videos;
        return $ctx;
    }

    /**
     * Build a render context with no videos (empty-state path). The
     * grid template short-circuits with vyg-feed--empty in this case.
     *
     * @param array<string,mixed> $attr_overrides
     * @return array<string,mixed>
     */
    private function empty_ctx( array $attr_overrides = array() ): array {
        $ctx = $this->ctx( $attr_overrides );
        $ctx['videos'] = array();
        return $ctx;
    }

    /**
     * Three simple standard-mode videos for the count test.
     *
     * @return array<int,array<string,mixed>>
     */
    private function three_videos(): array {
        return array(
            $this->make_video( 'v-1', 'Video One' ),
            $this->make_video( 'v-2', 'Video Two' ),
            $this->make_video( 'v-3', 'Video Three' ),
        );
    }

    /**
     * Twelve videos mirroring dev/seed-phase13-1.php (deterministic titles
     * and view counts so the count assertion is robust).
     *
     * @return array<int,array<string,mixed>>
     */
    private function twelve_seed_videos(): array {
        $titles = array(
            'Exploring the Canadian Rockies (4K Scenic Adventure)',
            'Coastal Vibes: 10 Hours of Relaxing Ocean Sounds',
            'How We Built a 1.2M Subscriber Channel (Behind the Scenes)',
            'Minimalist Desk Setup for Under $500',
            'Travel Vlog: Tokyo at Night',
            "I Tried Coding for 30 Days Straight — Here's What Happened",
            'Mountain Biking the Whole Coast of British Columbia',
            'The Truth About Productivity Apps (Honest Review)',
            "Forest Bathing: A Beginner's Guide to Shinrin-yoku",
            'Best Camera Gear Under $1,000 in 2026',
            'Why I Quit Social Media for a Year',
            'A Quiet Day in the Studio',
        );
        $out = array();
        foreach ( $titles as $i => $title ) {
            $out[] = $this->make_video( 'seed-' . ( $i + 1 ), $title );
        }
        return $out;
    }

    /**
     * Build one normalized video row.
     *
     * @return array<string,mixed>
     */
    private function make_video( string $id, string $title ): array {
        return array(
            'youtube_video_id'      => $id,
            'youtube_channel_id'    => 'UC_test',
            'title'                 => $title,
            'description'           => '',
            'thumbnail_high'        => 'https://example.com/' . $id . '.jpg',
            'duration_seconds'      => 765,
            'content_type'          => 'standard',
            'live_status'           => 'none',
            'published_at'          => '2026-06-25T12:00:00+00:00',
            'view_count'            => 125000,
            'youtube_channel_title' => 'Test Channel',
        );
    }
}
