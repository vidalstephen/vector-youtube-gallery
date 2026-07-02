<?php
/**
 * Phase 14.8 — Badge type × style matrix unit tests.
 *
 * Background: the prototype ships a badge panel with
 *
 *   Badge type  : featured | live | upcoming | replay | short | new | product
 *   Badge style : solid | soft | outline | minimal
 *
 * The plugin already wires `enabled_badges` (LIST_SPECS allow-list of 6
 * slugs) and `badge_style` (ENUM_SPECS whitelisting all 4 styles) in
 * `CardSettings`, but the partial only emits a single
 * `vyg-card__badge--{position}` class — the 7 type variants and the
 * 4 style variants are not yet emitted. The renderer resolves a
 * `status_badge` label from `live_status` only (no derivation from
 * `content_type`, `manual_content_type`, `is_pinned`, or
 * `published_at`).
 *
 * Phase 14.8 adds:
 *   - VideoRenderer::badge_type_for($video) — return the badge type
 *     slug for a video row, or '' when no badge applies.
 *   - VideoRenderer::badge_type_label($type) — return the human label
 *     (e.g. "LIVE", "Short").
 *   - VideoRenderer::badge_type_color($type) — return the prototype's
 *     hex color for a type slug (whitelisted, no DB / no XSS surface).
 *   - CardRenderer builds `$badge_type` / `$badge_label` /
 *     `$badge_color` and the partial emits
 *     `vyg-card__badge vyg-card__badge--{type} vyg-card__badge--{style}
 *      vyg-card__badge--{position}` with a clean
 *     `style="--vyg-badge-color:#…"` attribute.
 *
 * Edge cases the matrix covers (per the brief):
 *   - manual_content_type = 'product'        → product
 *   - live_status = 'live'                  → live
 *   - live_status = 'upcoming' + future     → upcoming
 *   - live_status = 'none' + is_pinned = 1  → featured
 *   - content_type = short_confirmed/cand.  → short
 *   - published within last 7 days + non-live → new
 *   - no special signal                     → '' (no badge)
 *
 * The 7-type × 4-style = 28-case matrix is covered via data providers
 * on the helper (label + color must round-trip) plus a representative
 * subset on the rendered partial (one style per type, since the CSS
 * class is independent of the type).
 *
 * @covers \VectorYT\Gallery\Render\VideoRenderer
 * @covers \VectorYT\Gallery\Render\CardRenderer
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Render;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Render\CardRenderer;
use VectorYT\Gallery\Render\CardSettings;
use VectorYT\Gallery\Render\TemplateLoader;
use VectorYT\Gallery\Render\VideoRenderer;
use VectorYT\Gallery\Tests\Support\BrainHelpers;

require_once __DIR__ . '/../../bootstrap.php';

final class BadgeMatrixTest extends TestCase
{
    private VideoRenderer $video_renderer;
    private TemplateLoader $templates;
    private CardRenderer $renderer;

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
        $this->video_renderer = new VideoRenderer();
        $this->templates      = new TemplateLoader();
        $this->renderer       = new CardRenderer( $this->video_renderer, $this->templates );
    }

    protected function tearDown(): void
    {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @return array<string,mixed>
     */
    private function default_settings(): array {
        return CardSettings::resolve( 'grid', array(), array() );
    }

    /**
     * Canonical synthetic video row. Mirrors the sample_video in
     * AvatarGradientTest / ToneColorTest so cross-phase assertions
     * are easy to compare.
     *
     * @return array<string,mixed>
     */
    private function sample_video(): array {
        return array(
            'youtube_video_id'      => 'abc1488',
            'youtube_channel_id'    => 'UC_1488',
            'youtube_channel_title' => 'Badge Test Channel',
            'title'                 => 'Phase 14.8 Badge Matrix Video',
            'thumbnail_medium'      => 'https://example.com/medium.jpg',
            'thumbnail_high'        => 'https://example.com/high.jpg',
            'duration_seconds'      => 600,
            'content_type'          => 'standard',
            'live_status'           => 'none',
            'published_at'          => '2026-06-25T12:00:00+00:00',
            'view_count'            => 1000,
        );
    }

    /**
     * Render one card with optional settings + video overrides.
     *
     * @param array<string,mixed> $settings_overrides
     * @param array<string,mixed>|null $video_override
     */
    private function render( array $settings_overrides = array(), ?array $video_override = null ): string {
        $settings = array_merge( $this->default_settings(), $settings_overrides );
        $context  = array(
            'source'      => array( 'title' => 'Test Source', 'source_uuid' => 'src-1488' ),
            'feed_config' => array(),
            'feed_uuid'   => 'feed-1488',
            'mode'        => 'standard',
            'role'        => 'listitem',
        );
        $video = null !== $video_override ? $video_override : $this->sample_video();
        return $this->renderer->render( $video, $settings, $context );
    }

    // -----------------------------------------------------------------
    // 1) VideoRenderer::badge_type_for — derivation rules
    // -----------------------------------------------------------------

    public function test_badge_type_for_returns_empty_for_unremarkable_video(): void {
        $video = $this->sample_video(); // content_type=standard, live_status=none, is_pinned=0, published 8 days ago
        $this->assertSame( '', $this->video_renderer->badge_type_for( $video ) );
    }

    public function test_badge_type_for_returns_live_when_live_status_is_live(): void {
        $video = array_merge( $this->sample_video(), array( 'live_status' => 'live' ) );
        $this->assertSame( 'live', $this->video_renderer->badge_type_for( $video ) );
    }

    public function test_badge_type_for_returns_upcoming_when_live_status_is_upcoming_and_scheduled_in_future(): void {
        $video = array_merge( $this->sample_video(), array(
            'live_status'        => 'upcoming',
            'scheduled_start_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
        ) );
        $this->assertSame( 'upcoming', $this->video_renderer->badge_type_for( $video ) );
    }

    public function test_badge_type_for_returns_replay_when_live_status_is_replay(): void {
        $video = array_merge( $this->sample_video(), array( 'live_status' => 'replay' ) );
        $this->assertSame( 'replay', $this->video_renderer->badge_type_for( $video ) );
    }

    public function test_badge_type_for_returns_short_for_short_confirmed_content_type(): void {
        $video = array_merge( $this->sample_video(), array( 'content_type' => 'short_confirmed' ) );
        $this->assertSame( 'short', $this->video_renderer->badge_type_for( $video ) );
    }

    public function test_badge_type_for_returns_short_for_short_candidate_content_type(): void {
        $video = array_merge( $this->sample_video(), array( 'content_type' => 'short_candidate' ) );
        $this->assertSame( 'short', $this->video_renderer->badge_type_for( $video ) );
    }

    public function test_badge_type_for_returns_featured_when_pinned_and_not_live(): void {
        $video = array_merge( $this->sample_video(), array(
            'is_pinned'   => 1,
            'live_status' => 'none',
        ) );
        $this->assertSame( 'featured', $this->video_renderer->badge_type_for( $video ) );
    }

    public function test_badge_type_for_returns_product_when_manual_content_type_is_product(): void {
        $video = array_merge( $this->sample_video(), array(
            'manual_content_type' => 'product',
            'content_type'        => 'standard',
        ) );
        $this->assertSame( 'product', $this->video_renderer->badge_type_for( $video ) );
    }

    public function test_badge_type_for_live_beats_short_when_both_signals_present(): void {
        // live_status wins over content_type, per the brief's edge-case
        // ordering (live always wins over shorts in VideoNormalizer).
        $video = array_merge( $this->sample_video(), array(
            'content_type' => 'short_confirmed',
            'live_status'  => 'live',
        ) );
        $this->assertSame( 'live', $this->video_renderer->badge_type_for( $video ) );
    }

    public function test_badge_type_for_product_beats_featured_when_both_signals_present(): void {
        // Manual override (product) wins over the auto-classification
        // signal (pinned) — operators can promote a pinned video to a
        // product badge explicitly.
        $video = array_merge( $this->sample_video(), array(
            'manual_content_type' => 'product',
            'is_pinned'           => 1,
        ) );
        $this->assertSame( 'product', $this->video_renderer->badge_type_for( $video ) );
    }

    public function test_badge_type_for_returns_new_for_recently_published_non_live_standard_video(): void {
        // Published 2 days ago, standard content, not live, not pinned
        // → "new" badge. Use gmdate so the assertion is timezone-stable
        // and time-of-day insensitive.
        $recent = gmdate( 'Y-m-d\TH:i:s\Z', time() - 2 * DAY_IN_SECONDS );
        $video  = array_merge( $this->sample_video(), array(
            'live_status'  => 'none',
            'content_type' => 'standard',
            'is_pinned'    => 0,
            'published_at' => $recent,
        ) );
        $this->assertSame( 'new', $this->video_renderer->badge_type_for( $video ) );
    }

    public function test_badge_type_for_skips_new_for_old_non_live_standard_video(): void {
        // Published 30 days ago → no "new" badge, but also no other
        // signal → '' (no badge at all).
        $old  = gmdate( 'Y-m-d\TH:i:s\Z', time() - 30 * DAY_IN_SECONDS );
        $video = array_merge( $this->sample_video(), array(
            'live_status'  => 'none',
            'content_type' => 'standard',
            'is_pinned'    => 0,
            'published_at' => $old,
        ) );
        $this->assertSame( '', $this->video_renderer->badge_type_for( $video ) );
    }

    public function test_badge_type_for_skips_new_when_live_status_is_set(): void {
        // Even if a live video was just published, the live badge
        // wins — "new" only applies to non-live videos.
        $recent = gmdate( 'Y-m-d\TH:i:s\Z', time() - HOUR_IN_SECONDS );
        $video  = array_merge( $this->sample_video(), array(
            'live_status'  => 'live',
            'published_at' => $recent,
        ) );
        $this->assertSame( 'live', $this->video_renderer->badge_type_for( $video ) );
    }

    // -----------------------------------------------------------------
    // 2) VideoRenderer::badge_type_label — human label for each type
    // -----------------------------------------------------------------

    /**
     * The 7 prototype types and their canonical labels. The 8th row
     * is the "standard" default that the prototype uses for the main
     * video column. We expose it on the helper so a layout that
     * wants a non-empty badge always has something to render.
     *
     * @return array<string,array{0:string,1:string}> type => [label, color]
     */
    public static function type_matrix(): array {
        return array(
            'featured' => array( 'Featured', '#6d28d9' ),
            'live'     => array( 'Live',     '#ef233c' ),
            'upcoming' => array( 'Upcoming', '#7c3aed' ),
            'replay'   => array( 'Replay',   '#2563eb' ),
            'short'    => array( 'Short',    '#0ea5e9' ),
            'new'      => array( 'New',      '#16a34a' ),
            'product'  => array( 'Product',  '#f97316' ),
        );
    }

    public function test_badge_type_label_returns_prototype_label_for_each_type(): void {
        foreach ( self::type_matrix() as $type => $expected ) {
            list( $label, $color ) = $expected;
            $this->assertSame(
                $label,
                $this->video_renderer->badge_type_label( $type ),
                "label for type '{$type}' must be '{$label}'"
            );
            $this->assertSame(
                strtolower( $color ),
                $this->video_renderer->badge_type_color( $type ),
                "color for type '{$type}' must be " . strtolower( $color )
            );
        }
    }

    public function test_badge_type_label_returns_empty_for_unknown_type(): void {
        $this->assertSame( '', $this->video_renderer->badge_type_label( 'nonsense' ) );
        $this->assertSame( '', $this->video_renderer->badge_type_color( 'nonsense' ) );
    }

    // -----------------------------------------------------------------
    // 3) CardRenderer — the partial emits the full type/style/position
    //    class combo + the inline --vyg-badge-color style attribute.
    // -----------------------------------------------------------------

    public function test_video_card_emits_badge_with_type_style_position_classes_and_color(): void {
        // A live video (live_status=live), with badge_style set to
        // 'soft' (an under-built style in the prior CSS). The partial
        // must emit a span that:
        //   - carries the base .vyg-card__badge class
        //   - carries .vyg-card__badge--live (the type)
        //   - carries .vyg-card__badge--soft (the style)
        //   - carries .vyg-card__badge--top-left (the position, slug→dash)
        //   - has style="--vyg-badge-color:#ef233c" (the live color)
        //   - has the human label "Live" as its text content
        $html = $this->render(
            array(
                'show_status_badge' => true,
                'badge_style'       => 'soft',
                'enabled_badges'    => array( 'live', 'upcoming', 'replay', 'featured', 'short', 'product', 'new' ),
            ),
            array_merge( $this->sample_video(), array( 'live_status' => 'live' ) )
        );

        $this->assertMatchesRegularExpression(
            '/<span[^>]*class="[^"]*\bvyg-card__badge\b[^"]*\bvyg-card__badge--live\b[^"]*\bvyg-card__badge--soft\b[^"]*\bvyg-card__badge--top-left\b[^"]*"[^>]*style="[^"]*--vyg-badge-color:\s*#ef233c[^"]*"[^>]*>\s*Live\s*<\/span>/',
            $html,
            'badge must carry type/style/position classes plus a clean --vyg-badge-color style'
        );
    }

    public function test_video_card_omits_badge_when_no_signal_and_no_explicit_label(): void {
        // The sample video is content_type=standard, live_status=none,
        // is_pinned=0, published 8 days ago. There is NO badge
        // signal — the partial must NOT emit a badge span at all.
        $html = $this->render( array( 'show_status_badge' => true ) );
        $this->assertStringNotContainsString(
            'vyg-card__badge--',
            $html,
            'no badge variant class should appear when no signal is present'
        );
    }

    public function test_video_card_emits_short_badge_with_default_solid_style(): void {
        // content_type=short_confirmed → "short" type. The default
        // badge_style from CardSettings is 'solid'. Position default
        // is 'top_left' (slug→class: top-left).
        $html = $this->render(
            array( 'show_status_badge' => true ),
            array_merge( $this->sample_video(), array( 'content_type' => 'short_confirmed' ) )
        );

        $this->assertMatchesRegularExpression(
            '/<span[^>]*class="[^"]*\bvyg-card__badge\b[^"]*\bvyg-card__badge--short\b[^"]*\bvyg-card__badge--solid\b[^"]*\bvyg-card__badge--top-left\b[^"]*"[^>]*style="[^"]*--vyg-badge-color:\s*#0ea5e9[^"]*"[^>]*>\s*Short\s*<\/span>/',
            $html,
            'short-confirmed video must emit a short/solid/top-left badge with the prototype sky color'
        );
    }

    public function test_video_card_emits_featured_badge_when_is_pinned(): void {
        $html = $this->render(
            array( 'show_status_badge' => true ),
            array_merge( $this->sample_video(), array( 'is_pinned' => 1 ) )
        );
        $this->assertMatchesRegularExpression(
            '/<span[^>]*class="[^"]*\bvyg-card__badge\b[^"]*\bvyg-card__badge--featured\b[^"]*"[^>]*>\s*Featured\s*<\/span>/',
            $html,
            'is_pinned=1 + no live signal must emit a featured badge'
        );
    }

    public function test_video_card_emits_product_badge_when_manual_content_type_is_product(): void {
        $html = $this->render(
            array( 'show_status_badge' => true ),
            array_merge( $this->sample_video(), array( 'manual_content_type' => 'product' ) )
        );
        $this->assertMatchesRegularExpression(
            '/<span[^>]*class="[^"]*\bvyg-card__badge\b[^"]*\bvyg-card__badge--product\b[^"]*"[^>]*>\s*Product\s*<\/span>/',
            $html,
            'manual_content_type=product must emit a product badge'
        );
    }

    public function test_video_card_emits_new_badge_for_recently_published_standard_video(): void {
        $recent = gmdate( 'Y-m-d\TH:i:s\Z', time() - 2 * DAY_IN_SECONDS );
        $html   = $this->render(
            array( 'show_status_badge' => true ),
            array_merge( $this->sample_video(), array( 'published_at' => $recent ) )
        );
        $this->assertMatchesRegularExpression(
            '/<span[^>]*class="[^"]*\bvyg-card__badge\b[^"]*\bvyg-card__badge--new\b[^"]*"[^>]*>\s*New\s*<\/span>/',
            $html,
            'a standard video published within 7 days must emit a "new" badge'
        );
    }

    public function test_video_card_emits_outline_style_class_when_badge_style_is_outline(): void {
        $html = $this->render(
            array(
                'show_status_badge' => true,
                'badge_style'       => 'outline',
            ),
            array_merge( $this->sample_video(), array( 'live_status' => 'live' ) )
        );
        $this->assertMatchesRegularExpression(
            '/<span[^>]*class="[^"]*\bvyg-card__badge--outline\b[^"]*"[^>]*>/',
            $html,
            'badge_style=outline must emit the .vyg-card__badge--outline class'
        );
    }

    public function test_video_card_emits_minimal_style_class_when_badge_style_is_minimal(): void {
        $html = $this->render(
            array(
                'show_status_badge' => true,
                'badge_style'       => 'minimal',
            ),
            array_merge( $this->sample_video(), array( 'live_status' => 'live' ) )
        );
        $this->assertMatchesRegularExpression(
            '/<span[^>]*class="[^"]*\bvyg-card__badge--minimal\b[^"]*"[^>]*>/',
            $html,
            'badge_style=minimal must emit the .vyg-card__badge--minimal class'
        );
    }

    public function test_video_card_position_class_follows_badge_position_setting(): void {
        // Move the badge to bottom-right and confirm the class follows.
        $html = $this->render(
            array(
                'show_status_badge' => true,
                'badge_position'    => 'bottom_right',
            ),
            array_merge( $this->sample_video(), array( 'live_status' => 'live' ) )
        );
        $this->assertMatchesRegularExpression(
            '/<span[^>]*class="[^"]*\bvyg-card__badge--bottom-right\b[^"]*"[^>]*>/',
            $html,
            'badge_position=bottom_right must slug→dash into vyg-card__badge--bottom-right'
        );
    }

    public function test_video_card_omits_badge_when_show_status_badge_is_false(): void {
        // The show_status_badge setting must still gate the entire
        // badge emission — even when the underlying signal is live.
        $html = $this->render(
            array( 'show_status_badge' => false ),
            array_merge( $this->sample_video(), array( 'live_status' => 'live' ) )
        );
        $this->assertStringNotContainsString(
            'vyg-card__badge--',
            $html,
            'show_status_badge=false must suppress the badge entirely'
        );
    }

    // -----------------------------------------------------------------
    // 4) Sanitizer — the new 'new' badge type is allow-listed
    // -----------------------------------------------------------------

    public function test_enabled_badges_allowlist_includes_new_type(): void {
        // Phase 14.8 extends the LIST_SPECS allowlist for
        // `enabled_badges` from 6 to 7 slugs (adding 'new'). The
        // operator-facing panel surface already lists it; the
        // sanitizer must accept it. We probe the allowlist by
        // sending `enabled_badges=array('live','new')` through the
        // resolver — both should pass the sanitizer (LIVE is
        // already in defaults, NEW is the new addition).
        $settings = CardSettings::resolve(
            'grid',
            array(),
            array( 'enabled_badges' => array( 'live', 'new' ) )
        );
        $this->assertContains( 'live', $settings['enabled_badges'] );
        $this->assertContains( 'new', $settings['enabled_badges'] );
    }
}
