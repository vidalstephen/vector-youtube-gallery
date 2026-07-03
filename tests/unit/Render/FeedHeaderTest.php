<?php
/**
 * Phase 14.9 — Shared header (kicker + h1 + intro + pill + channel CTA).
 *
 * Background: every layout (grid, list, featured, hero, shorts, live,
 * masonry, carousel) currently has either its own bespoke header
 * (grid, featured, hero) or no header at all (list, shorts, live,
 * masonry, carousel). The prototype's design is a single shared header
 * component with 5 slots, each optional:
 *
 *   - kicker       small uppercase eyebrow text (e.g. "FROM THE CHANNEL")
 *   - h1           the section title (layout-specific default if unset)
 *   - intro        short paragraph, 1-2 sentences
 *   - layout pill  e.g. "Grid", "Carousel", "Live" (auto from layout slug)
 *   - channel CTA  small "Watch on YouTube →" link to the source's
 *                  canonical channel URL
 *
 * The header partial is a standalone template at
 * `src/Render/templates/partials/feed-header.php`. It reads its
 * inputs from $scope ($attrs, $source, $layout_slug) and emits the
 * 5-slot structure. Layout templates include it once at the top of
 * their <div class="vyg-feed ..."> body.
 *
 * Visibility defaults (the 5 booleans):
 *   - show_kicker       default true   (only emits when text is set)
 *   - show_h1           default true   (auto-fills with layout default
 *                                       when feed_title/header_title is empty)
 *   - show_intro        default false  (must be explicitly enabled)
 *   - show_pill         default true   (always emits the layout name)
 *   - show_channel_cta  default false  (opt-in; suppresses when no URL)
 *
 * The h1 + intro + kicker + CTA are text-driven: if their text is
 * empty, the corresponding slot is omitted entirely (the visibility
 * flag is a meta-control on top of the empty-text check). This keeps
 * the existing Phase 14.4 "View all videos →" link on featured + hero
 * intact (that link lives in a different inner section head, not the
 * top shared header).
 *
 * The test matrix below is the spec the partial must honor.
 *
 * @covers \VectorYT\Gallery\Render\TemplateAttributes
 * @covers \VectorYT\Gallery\Render\Renderer
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Render;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Render\LiveQuery;
use VectorYT\Gallery\Render\Renderer;
use VectorYT\Gallery\Render\TemplateLoader;
use VectorYT\Gallery\Render\VideoRenderer;
use VectorYT\Gallery\Tests\Support\BrainHelpers;

require_once __DIR__ . '/../../bootstrap.php';

final class FeedHeaderTest extends TestCase
{
    private TemplateLoader $templates;
    private VideoRenderer $video_renderer;
    private LiveQuery $live_query;
    private \VectorYT\Gallery\Render\FeedQuery $feeds;

    protected function setUp(): void
    {
        parent::setUp();
        \Brain\Monkey\setUp();
        BrainHelpers::stubEscapeFunctions();
        BrainHelpers::stubOptionFunctions();
        Functions\when( 'apply_filters' )->alias(
            static function ( $tag, $default, ...$args ) {
                return $default;
            }
        );
        Functions\when( 'home_url' )->alias( static fn( string $p = '' ): string => 'https://example.test' . $p );
        // The live layout's LiveQuery reads $wpdb->prefix and then
        // runs $wpdb->get_results() for live/upcoming/replay buckets.
        // Brain\Monkey doesn't bootstrap a real $wpdb. Install a tiny
        // double on the global that satisfies the live query's read
        // paths (returns [] for every query — the unit test cares about
        // header shape, not live bucket content).
        $wpdb_double = new class {
            public string $prefix = 'wp_';
            /** @return array<int,mixed> */
            public function get_results( $sql = null, $output = ARRAY_A ) { return array(); }
            /** @return int */
            public function get_var( $sql = null ) { return 0; }
            /** @return string */
            public function prepare( $sql, ...$args ) { return $sql; }
        };
        $GLOBALS['wpdb'] = $wpdb_double;
        $this->templates      = new TemplateLoader();
        $this->video_renderer = new VideoRenderer();
        $this->live_query     = new LiveHeaderFakeLiveQuery( $this->createMock( \VectorYT\Gallery\Repository\PreviousStreamsRepository::class ) );
        $this->feeds          = new FeedHeaderFakeFeedQuery();
    }

    protected function tearDown(): void
    {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Render one layout with optional $attr_overrides through the
     * production Renderer::render() entry point. Returns the full HTML.
     *
     * @param string $layout_slug
     * @param array<string,mixed> $attr_overrides
     * @param array<string,mixed>|null $source
     * @param array<int,array<string,mixed>>|null $videos Optional canned videos (3+ required to exercise
     *             featured/hero's inner "View all" section head, which is gated on $rest being non-empty).
     */
    private function render_layout( string $layout_slug, array $attr_overrides = array(), ?array $source = null, ?array $videos = null ): string {
        if ( null !== $videos ) {
            $this->feeds->videos = $videos;
        }
        $renderer = new Renderer( $this->feeds, $this->video_renderer, $this->templates, $this->live_query );
        $args = array_merge(
            array(
                'source_uuid' => 'src-149-' . $layout_slug,
                'layout'      => $layout_slug,
                'per_page'    => 3,
            ),
            $attr_overrides
        );
        return $renderer->render( $args );
    }

    /**
     * Build 3 fake video rows for layouts that need $rest to be
     * non-empty (featured, hero). Reused by the 14.4 "view all"
     * regression tests.
     *
     * @return array<int,array<string,mixed>>
     */
    private function fake_videos(): array {
        $videos = array();
        for ( $i = 1; $i <= 3; $i++ ) {
            $videos[] = array(
                'youtube_video_id'      => 'vid-149-' . $i,
                'youtube_channel_id'    => 'UC_149',
                'youtube_channel_title' => 'Channel 149',
                'title'                 => 'Header Test Video ' . $i,
                'thumbnail_medium'      => 'https://example.com/m' . $i . '.jpg',
                'thumbnail_high'        => 'https://example.com/h' . $i . '.jpg',
                'duration_seconds'      => 120 + $i * 30,
                'content_type'          => 'standard',
                'live_status'           => 'none',
                'published_at'          => '2026-06-25T12:00:00+00:00',
                'view_count'            => 1000 * $i,
            );
        }
        return $videos;
    }

    /**
     * The 8 layouts that must all share the new feed header.
     *
     * @return array<string,array{0:string}>
     */
    public static function layout_slugs(): array {
        return array(
            'grid'     => array( 'grid' ),
            'list'     => array( 'list' ),
            'featured' => array( 'featured' ),
            'hero'     => array( 'hero' ),
            'shorts'   => array( 'shorts' ),
            'live'     => array( 'live' ),
            'masonry'  => array( 'masonry' ),
            'carousel' => array( 'carousel' ),
        );
    }

    // -----------------------------------------------------------------
    // 1) Per-layout: every layout emits the shared .vyg-section-head
    //    block at the top of its body.
    // -----------------------------------------------------------------

    /**
     * @dataProvider layout_slugs
     */
    public function test_feed_header_renders_for_layout( string $layout_slug ): void {
        // Phase 15.7 — show_feed_header must be explicitly enabled
        // (it defaults to false) for the shared header to render.
        $html = $this->render_layout( $layout_slug, array(
            'feed_title'       => 'Test Title',
            'show_feed_header' => true,
        ) );
        $this->assertStringContainsString(
            'vyg-section-head',
            $html,
            "layout '{$layout_slug}' must emit the shared .vyg-section-head block when show_feed_header=true"
        );
        $this->assertStringContainsString(
            'vyg-section-head__title',
            $html,
            "layout '{$layout_slug}' must wrap the h1 in .vyg-section-head__title"
        );
    }

    /**
     * @dataProvider layout_slugs
     */
    public function test_feed_header_pill_text_matches_layout_slug( string $layout_slug ): void {
        // Phase 15.7 — show_pill defaults to false (was true in 14.x).
        // To get the pill text assertion to fire we need both gates
        // enabled.
        $html = $this->render_layout( $layout_slug, array(
            'feed_title'       => 'Test Title',
            'show_feed_header' => true,
            'show_pill'        => true,
        ) );
        // The pill is e.g. "Grid", "List", "Featured", "Hero", "Shorts",
        // "Live", "Masonry", "Carousel" — first-letter-uppercased slug.
        $expected_pill = ucfirst( $layout_slug );
        $this->assertMatchesRegularExpression(
            '/<span[^>]*class="[^"]*\\bvyg-section-head__pill\\b[^"]*"[^>]*>\\s*' . preg_quote( $expected_pill, '/' ) . '\\s*<\/span>/',
            $html,
            "layout '{$layout_slug}' must emit a pill reading '{$expected_pill}'"
        );
    }

    // -----------------------------------------------------------------
    // 2) Slot omission rules — empty text or show_* off => element gone.
    // -----------------------------------------------------------------

    public function test_feed_header_omits_kicker_when_text_empty(): void {
        $html = $this->render_layout( 'grid', array( 'feed_title' => 'Has h1' ) );
        $this->assertStringNotContainsString(
            'vyg-section-head__kicker',
            $html,
            'no .vyg-section-head__kicker element when feed_kicker is empty'
        );
    }

    public function test_feed_header_emits_kicker_when_text_set(): void {
        $html = $this->render_layout( 'grid', array(
            'feed_title'  => 'Has h1',
            'feed_kicker' => 'FROM THE CHANNEL',
            'show_feed_header' => true,
        ) );
        $this->assertMatchesRegularExpression(
            '/<span[^>]*class="[^"]*\\bvyg-section-head__kicker\\b[^"]*"[^>]*>\\s*FROM THE CHANNEL\\s*<\\/span>/',
            $html,
            'feed_kicker= set must emit a .vyg-section-head__kicker with the text'
        );
    }

    public function test_feed_header_kicker_respects_show_kicker_false(): void {
        $html = $this->render_layout( 'grid', array(
            'feed_title'  => 'Has h1',
            'feed_kicker' => 'FROM THE CHANNEL',
            'show_kicker' => false,
        ) );
        $this->assertStringNotContainsString(
            'vyg-section-head__kicker',
            $html,
            'show_kicker=false must suppress the kicker even when text is set'
        );
    }

    public function test_feed_header_emits_h1_when_feed_title_set(): void {
        $html = $this->render_layout( 'list', array(
            'feed_title'       => 'Latest Videos',
            'show_feed_header' => true,
        ) );
        $this->assertMatchesRegularExpression(
            '/<h2[^>]*class="[^"]*\\bvyg-section-head__title\\b[^"]*"[^>]*>\\s*Latest Videos\\s*<\\/h2>/',
            $html,
            'feed_title= set must emit an h2 with that text'
        );
    }

    public function test_feed_header_omits_h1_when_show_h1_false(): void {
        $html = $this->render_layout( 'grid', array(
            'feed_title' => 'Should not appear',
            'show_h1'    => false,
        ) );
        $this->assertStringNotContainsString(
            'vyg-section-head__title',
            $html,
            'show_h1=false must suppress the h2 slot entirely'
        );
    }

    public function test_feed_header_omits_intro_when_text_empty(): void {
        $html = $this->render_layout( 'grid', array(
            'feed_title' => 'Has h1',
            'show_intro' => true,
        ) );
        $this->assertStringNotContainsString(
            'vyg-section-head__intro',
            $html,
            'no .vyg-section-head__intro element when feed_intro is empty'
        );
    }

    public function test_feed_header_emits_intro_when_show_intro_true_and_text_set(): void {
        $html = $this->render_layout( 'grid', array(
            'feed_title' => 'Has h1',
            'show_intro' => true,
            'feed_intro' => 'A polished responsive grid with strong thumbnails.',
            'show_feed_header' => true,
        ) );
        $this->assertMatchesRegularExpression(
            '/<p[^>]*class="[^"]*\\bvyg-section-head__intro\\b[^"]*"[^>]*>\\s*A polished responsive grid[^<]*<\\/p>/',
            $html,
            'show_intro=true with feed_intro text must emit .vyg-section-head__intro'
        );
    }

    public function test_feed_header_omits_intro_when_show_intro_false(): void {
        $html = $this->render_layout( 'grid', array(
            'feed_title' => 'Has h1',
            'show_intro' => false,
            'feed_intro' => 'Should not appear',
        ) );
        $this->assertStringNotContainsString(
            'vyg-section-head__intro',
            $html,
            'show_intro=false must suppress the intro even when text is set'
        );
    }

    // -----------------------------------------------------------------
    // 3) Layout pill — controlled by show_pill only (text is auto).
    // -----------------------------------------------------------------

    public function test_feed_header_layout_pill_hidden_when_disabled(): void {
        $html = $this->render_layout( 'grid', array(
            'feed_title' => 'Has h1',
            'show_pill'  => false,
        ) );
        $this->assertStringNotContainsString(
            'vyg-section-head__pill',
            $html,
            'show_pill=false must omit the .vyg-section-head__pill element'
        );
    }

    public function test_feed_header_layout_pill_hidden_by_default(): void {
        // Phase 15.7 — the layout pill was redundant product chrome
        // (the operator already chose the layout via shortcode/block
        // attribute). Default flipped to false so a clean front-end
        // feed shows no "Grid"/"Masonry" pill. Operators who want
        // the pill back can set show_pill="1" inline.
        $html = $this->render_layout( 'carousel', array( 'feed_title' => 'Has h1' ) );
        $this->assertStringNotContainsString(
            'vyg-section-head__pill',
            $html,
            'show_pill default (false) must omit the layout pill'
        );
    }

    public function test_feed_header_pill_visible_when_show_pill_true(): void {
        $html = $this->render_layout( 'carousel', array(
            'feed_title' => 'Has h1',
            'show_pill'  => true,
            'show_feed_header' => true,
        ) );
        $this->assertStringContainsString(
            'vyg-section-head__pill',
            $html,
            'show_pill=true must emit the layout pill on the carousel layout'
        );
    }

    // -----------------------------------------------------------------
    // 3b) Phase 15.7 — master gate `show_feed_header`. When false
    // (the default), the entire .vyg-section-head block is omitted
    // from output. This is the user's "video gallery header stating
    // the type of layout etc" critique. The header is opt-in.
    // -----------------------------------------------------------------

    public function test_feed_header_omitted_by_default(): void {
        $html = $this->render_layout( 'grid', array( 'feed_title' => 'Has h1' ) );
        $this->assertStringNotContainsString(
            'vyg-section-head',
            $html,
            'show_feed_header default (false) must omit the entire .vyg-section-head block'
        );
    }

    public function test_feed_header_emitted_when_show_feed_header_true(): void {
        $html = $this->render_layout( 'grid', array(
            'feed_title'       => 'Has h1',
            'show_feed_header' => true,
        ) );
        $this->assertStringContainsString(
            'vyg-section-head',
            $html,
            'show_feed_header=true must emit the .vyg-section-head block'
        );
        $this->assertStringContainsString(
            'vyg-section-head__title',
            $html,
            'show_feed_header=true must wrap the h1 in .vyg-section-head__title'
        );
    }

    public function test_feed_header_gate_applies_to_all_layouts(): void {
        // Even with feed_title set, the top shared feed header should
        // not render by default on any layout. The user said: "I want
        // them configured with a toggle and have them toggled off by
        // default".
        //
        // We assert on `vyg-section-head__title` (the shared partial's
        // h2 slot) rather than the bare class `vyg-section-head`
        // because the featured + hero layouts have a separate inner
        // "More Videos" section head (Phase 14.4) that uses the same
        // class but is NOT gated by show_feed_header. That inner head
        // is a "see all" link, not a "type of layout" indicator, and
        // is out of scope for this toggle.
        $layouts = array( 'grid', 'list', 'featured', 'hero', 'shorts', 'live', 'masonry', 'carousel' );
        foreach ( $layouts as $slug ) {
            $html = $this->render_layout( $slug, array( 'feed_title' => 'Should not appear' ) );
            $this->assertStringNotContainsString(
                'vyg-section-head__title',
                $html,
                "layout '{$slug}' must omit the shared feed header h2 by default"
            );
        }
    }

    // -----------------------------------------------------------------
    // 4) Channel CTA — controlled by show_channel_cta + URL fallback.
    // -----------------------------------------------------------------

    public function test_feed_header_channel_cta_hidden_by_default(): void {
        $html = $this->render_layout( 'grid', array( 'feed_title' => 'Has h1' ) );
        $this->assertStringNotContainsString(
            'vyg-section-head__cta',
            $html,
            'show_channel_cta default (false) must omit the .vyg-section-head__cta element'
        );
    }

    public function test_feed_header_channel_cta_hidden_when_url_is_hash(): void {
        $html = $this->render_layout( 'grid', array(
            'feed_title'        => 'Has h1',
            'show_channel_cta'  => true,
            'feed_cta_url'      => '#',
        ) );
        $this->assertStringNotContainsString(
            'vyg-section-head__cta',
            $html,
            'feed_cta_url="#" must suppress the CTA even when show_channel_cta=true'
        );
    }

    public function test_feed_header_channel_cta_uses_watch_on_youtube_label_by_default(): void {
        $html = $this->render_layout( 'grid', array(
            'feed_title'       => 'Has h1',
            'show_channel_cta' => true,
            'feed_cta_url'     => 'https://www.youtube.com/channel/UC_149',
            'show_feed_header' => true,
        ) );
        $this->assertMatchesRegularExpression(
            '/<a[^>]*class="[^"]*\\bvyg-section-head__cta\\b[^"]*"[^>]*href="[^"]*youtube\\.com\\/channel\\/UC_149[^"]*"[^>]*>\\s*Watch on YouTube[^<]*<\\/a>/',
            $html,
            'default feed_cta_label must be "Watch on YouTube →"'
        );
    }

    public function test_feed_header_channel_cta_uses_custom_label_when_set(): void {
        $html = $this->render_layout( 'grid', array(
            'feed_title'       => 'Has h1',
            'show_channel_cta' => true,
            'feed_cta_label'   => 'Visit the channel',
            'feed_cta_url'     => 'https://www.youtube.com/@wayofholiness',
            'show_feed_header' => true,
        ) );
        $this->assertMatchesRegularExpression(
            '/<a[^>]*class="[^"]*\\bvyg-section-head__cta\\b[^"]*"[^>]*>\\s*Visit the channel[^<]*<\\/a>/',
            $html,
            'feed_cta_label override must replace the default label'
        );
    }

    // -----------------------------------------------------------------
    // 5) Backwards compat: existing header_* keys still flow through.
    // -----------------------------------------------------------------

    public function test_feed_header_legacy_header_title_still_renders_h1(): void {
        // Phase 13.1 / 14.x feeds saved header_title/header_subtitle in
        // their display config. The new partial must still consume
        // those (alias for feed_title/feed_intro) so the grid does not
        // regress for the existing 482-test operator base. The h1 slot
        // defaults to visible, the intro slot defaults to hidden — so
        // we explicitly opt in to the intro for this test (the same
        // as any operator who saved a header_subtitle would do).
        //
        // Phase 15.7: show_feed_header defaults to false. The legacy
        // header_title/header_subtitle flow still works once the
        // master gate is opted in.
        $html = $this->render_layout( 'grid', array(
            'header_title'    => 'Legacy Header Title',
            'show_intro'      => true,
            'header_subtitle' => 'Legacy subtitle text',
            'show_feed_header' => true,
        ) );
        $this->assertStringContainsString( 'Legacy Header Title', $html );
        $this->assertStringContainsString( 'Legacy subtitle text', $html );
    }

    public function test_feed_header_legacy_header_cta_url_still_renders(): void {
        $html = $this->render_layout( 'grid', array(
            'header_title'      => 'Has h1',
            'header_cta_label'  => 'Visit Us',
            'header_cta_url'    => 'https://example.com/legacy',
            'show_feed_header'  => true,
        ) );
        $this->assertStringContainsString( 'Visit Us', $html );
        $this->assertStringContainsString( 'https://example.com/legacy', $html );
    }

    public function test_feed_header_inherits_view_all_link_from_14_4_on_featured(): void {
        // Phase 14.4 added the "View all videos →" link on featured's
        // *inner* section head (above the rest grid). Phase 14.9 adds
        // the *top* shared header but must NOT regress the inner one.
        // The inner head is gated on $rest (videos after the hero) being
        // non-empty, so we feed 3 videos into the layout.
        $html = $this->render_layout( 'featured', array( 'feed_title' => 'Featured Headline' ), null, $this->fake_videos() );
        $this->assertStringContainsString(
            'View all videos',
            $html,
            'featured must still emit the 14.4 "View all videos →" inner link'
        );
    }

    public function test_feed_header_inherits_view_all_link_from_14_4_on_hero(): void {
        $html = $this->render_layout( 'hero', array( 'feed_title' => 'Hero Headline' ), null, $this->fake_videos() );
        $this->assertStringContainsString(
            'View all videos',
            $html,
            'hero must still emit the 14.4 "View all videos →" inner link'
        );
    }

    // -----------------------------------------------------------------
    // 6) Layout-specific default h1 text when feed_title is empty.
    // -----------------------------------------------------------------

    public function test_feed_header_uses_layout_specific_default_h1_when_feed_title_empty(): void {
        // Per the prototype, each layout has a default h1 text:
        //   grid:     "Latest Videos"
        //   list:     "Latest Videos"
        //   featured: "Featured Videos"
        //   hero:     "Editorial Video Showcase"
        //   shorts:   "Latest Shorts"
        //   live:     "Live & Upcoming"
        //   masonry:  "Masonry Gallery"
        //   carousel: "Featured Video Carousel"
        //
        // Phase 15.7: the header is opt-in via show_feed_header=true.
        $defaults = array(
            'grid'     => 'Latest Videos',
            'list'     => 'Latest Videos',
            'featured' => 'Featured Videos',
            'hero'     => 'Editorial Video Showcase',
            'shorts'   => 'Latest Shorts',
            'live'     => 'Live & Upcoming',
            'masonry'  => 'Masonry Gallery',
            'carousel' => 'Featured Video Carousel',
        );
        foreach ( $defaults as $slug => $expected_h1 ) {
            $html = $this->render_layout( $slug, array( 'show_feed_header' => true ) );
            $this->assertMatchesRegularExpression(
                '/<h2[^>]*class="[^"]*\\bvyg-section-head__title\\b[^"]*"[^>]*>\\s*' . preg_quote( $expected_h1, '/' ) . '\\s*<\/h2>/',
                $html,
                "layout '{$slug}' with no feed_title must emit default h1 '{$expected_h1}' when show_feed_header=true"
            );
        }
    }
}

/**
 * A minimal FeedQuery double that returns canned data per layout slug.
 * Different layouts (featured, hero, shorts, live) need different
 * data shapes, so we accept a closure-style source.
 */
class FeedHeaderFakeFeedQuery extends \VectorYT\Gallery\Render\FeedQuery {
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
                'youtube_video_id'      => 'vid-149-1',
                'youtube_channel_id'    => 'UC_149',
                'youtube_channel_title' => 'Channel 149',
                'title'                 => 'Header Test Video 1',
                'thumbnail_medium'      => 'https://example.com/m1.jpg',
                'thumbnail_high'        => 'https://example.com/h1.jpg',
                'duration_seconds'      => 120,
                'content_type'          => 'standard',
                'live_status'           => 'none',
                'published_at'          => '2026-06-25T12:00:00+00:00',
                'view_count'            => 1000,
            ),
            array(
                'youtube_video_id'      => 'vid-149-2',
                'youtube_channel_id'    => 'UC_149',
                'youtube_channel_title' => 'Channel 149',
                'title'                 => 'Header Test Video 2',
                'thumbnail_medium'      => 'https://example.com/m2.jpg',
                'thumbnail_high'        => 'https://example.com/h2.jpg',
                'duration_seconds'      => 180,
                'content_type'          => 'standard',
                'live_status'           => 'none',
                'published_at'          => '2026-06-26T12:00:00+00:00',
                'view_count'            => 2000,
            ),
            array(
                'youtube_video_id'      => 'vid-149-3',
                'youtube_channel_id'    => 'UC_149',
                'youtube_channel_title' => 'Channel 149',
                'title'                 => 'Header Test Video 3',
                'thumbnail_medium'      => 'https://example.com/m3.jpg',
                'thumbnail_high'        => 'https://example.com/h3.jpg',
                'duration_seconds'      => 240,
                'content_type'          => 'standard',
                'live_status'           => 'none',
                'published_at'          => '2026-06-27T12:00:00+00:00',
                'view_count'            => 3000,
            ),
        );
    }

    public function count_videos_for_source( array $args ): int {
        return 3;
    }
}

/**
 * A LiveQuery double that returns canned buckets with one video each
 * so the live layout's body renders (without it, the layout shows the
 * "No live or recent streams" empty state and the test's header
 * assertions can't fire). The unit test cares about header shape,
 * not the bucketing logic.
 */
class LiveHeaderFakeLiveQuery extends \VectorYT\Gallery\Render\LiveQuery {
    public function buckets_for_source( array $source ): array {
        $row = array(
            'youtube_video_id'      => 'live-149-1',
            'youtube_channel_id'    => (string) ( $source['youtube_channel_id'] ?? 'UC_149' ),
            'youtube_channel_title' => (string) ( $source['title'] ?? 'Channel 149' ),
            'title'                 => 'Live Header Test Stream',
            'thumbnail_medium'      => 'https://example.com/live1.jpg',
            'thumbnail_high'        => 'https://example.com/live1.jpg',
            'duration_seconds'      => 0,
            'content_type'          => 'live_active',
            'live_status'           => 'live',
            'published_at'          => '2026-06-25T12:00:00+00:00',
            'view_count'            => 5000,
        );
        return array(
            'live'     => array( $row ),
            'upcoming' => array(),
            'replay'   => array(),
        );
    }
}
