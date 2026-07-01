<?php
/**
 * Phase B1 — Unit tests for the shared CardRenderer.
 *
 * Pins down the contract for the shared card anatomy partial:
 *   - outer article class composition (vyg-card vyg-card--{mode}
 *     vyg-card--{card_style} vyg-density--{density})
 *   - role attribute from context
 *   - conditional rendering of every region by show_* settings
 *   - metadata field filtering (views vs published_date)
 *   - tri-state show_cta: true / false / mapped_only (filter stubbed)
 *   - secondary actions with aria-labels
 *   - defensive class sanitization — no unsanitized user value leaks
 *   - data integrity — avatar / verified / subscriber only when real
 *     fields exist in the video array; never faked
 *   - graceful handling of empty / missing video fields
 *
 * @covers \VectorYT\Gallery\Render\CardRenderer
 * @covers \VectorYT\Gallery\Render\CardSanitizer  (only via the renderer's defensive calls)
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Render;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Render\CardProfiles;
use VectorYT\Gallery\Render\CardRenderer;
use VectorYT\Gallery\Render\CardSettings;
use VectorYT\Gallery\Render\TemplateLoader;
use VectorYT\Gallery\Render\VideoRenderer;
use VectorYT\Gallery\Tests\Support\BrainHelpers;

require_once __DIR__ . '/../../bootstrap.php';

final class CardRendererTest extends TestCase
{
    private VideoRenderer $video_renderer;
    private TemplateLoader $templates;
    private CardRenderer $renderer;

    /**
     * A "default" settings array derived from the standard card profile
     * via CardSettings::resolve, so tests can mutate one key per test
     * without having to re-state every key.
     *
     * @return array<string,mixed>
     */
    private function default_settings(): array {
        return CardSettings::resolve( 'grid', array(), array() );
    }

    /**
     * A canonical video row used as the baseline for assertion.
     *
     * @return array<string,mixed>
     */
    private function sample_video(): array {
        return array(
            'youtube_video_id'      => 'abc123',
            'youtube_channel_id'    => 'UC_test',
            'youtube_channel_title' => 'Test Channel',
            'title'                 => 'Test Video Title',
            'thumbnail_medium'      => 'https://example.com/medium.jpg',
            'thumbnail_high'        => 'https://example.com/high.jpg',
            'duration_seconds'      => 765,
            'content_type'          => 'standard',
            'live_status'           => 'none',
            'published_at'          => '2026-06-25T12:00:00+00:00',
            'view_count'            => 125000,
        );
    }

    /**
     * Render the standard card with optional settings and context overrides.
     *
     * @param array<string,mixed> $settings_overrides
     * @param array<string,mixed> $context_overrides
     * @param array<string,mixed>|null $video_override
     */
    private function render(
        array $settings_overrides = array(),
        array $context_overrides = array(),
        ?array $video_override = null
    ): string {
        $settings = array_merge( $this->default_settings(), $settings_overrides );
        $context  = array_merge(
            array(
                'source'      => array( 'title' => 'Test Source', 'source_uuid' => 'src-x' ),
                'feed_config' => array(),
                'feed_uuid'   => 'feed-x',
                'mode'        => 'standard',
                'role'        => 'listitem',
            ),
            $context_overrides
        );
        $video = null !== $video_override ? $video_override : $this->sample_video();
        return $this->renderer->render( $video, $settings, $context );
    }

    protected function setUp(): void
    {
        parent::setUp();
        \Brain\Monkey\setUp();
        BrainHelpers::stubEscapeFunctions();
        BrainHelpers::stubOptionFunctions();
        // Default: apply_filters returns the unmodified default value. The
        // mapped_only test re-stubs with an explicit product map.
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

    // -----------------------------------------------------------------
    // Outer element + class composition
    // -----------------------------------------------------------------

    public function test_outer_element_is_article_with_role_and_base_classes(): void
    {
        $html = $this->render();
        $this->assertStringContainsString( '<article', $html );
        $this->assertStringContainsString( 'role="listitem"', $html );
        $this->assertStringContainsString( 'vyg-card', $html );
        $this->assertStringContainsString( 'vyg-card--standard', $html );
        $this->assertStringContainsString( 'vyg-card--bordered', $html );
        $this->assertStringContainsString( 'vyg-density--comfortable', $html );
    }

    public function test_role_from_context_is_emitted_on_outer_element(): void
    {
        $html = $this->render( array(), array( 'role' => 'article' ) );
        $this->assertStringContainsString( 'role="article"', $html );
        $this->assertStringNotContainsString( 'role="listitem"', $html );
    }

    public function test_mode_from_context_produces_mode_class(): void
    {
        $html = $this->render( array(), array( 'mode' => 'short_vertical' ) );
        $this->assertStringContainsString( 'vyg-card--short-vertical', $html );
    }

    public function test_resolved_card_style_emitted_as_class(): void
    {
        $html = $this->render( array( 'card_style' => 'elevated' ) );
        $this->assertStringContainsString( 'vyg-card--elevated', $html );
        $this->assertStringNotContainsString( 'vyg-card--bordered', $html );
    }

    public function test_resolved_density_emitted_as_class(): void
    {
        $html = $this->render( array( 'density' => 'compact' ) );
        $this->assertStringContainsString( 'vyg-density--compact', $html );
        $this->assertStringNotContainsString( 'vyg-density--comfortable', $html );
    }

    public function test_thumbnail_ratio_emitted_as_ratio_class(): void
    {
        // B1 contract test: the renderer honors the resolved settings.
        // (The "short_vertical forces 9_16" precedence rule is a B3 test;
        // here we just confirm the ratio passes through to a class.)
        $html = $this->render( array( 'thumbnail_ratio' => '9_16' ) );
        $this->assertStringContainsString( 'vyg-thumb-ratio--9-16', $html );
    }

    public function test_unsanitized_user_value_does_not_leak_into_classes(): void
    {
        // If a setting value is invalid, the renderer uses a safe default
        // class — not a value derived from the user input.
        $html = $this->render( array( 'card_style' => '../../etc/passwd' ) );
        $this->assertStringNotContainsString( '../../etc/passwd', $html );
        $this->assertStringNotContainsString( 'passwd', $html );
        // Falls back to one of the allowed values (the default).
        $this->assertMatchesRegularExpression(
            '/class="[^"]*\bvyg-card--(?:minimal|bordered|elevated|flat|commerce)\b[^"]*"/',
            $html
        );
    }

    // -----------------------------------------------------------------
    // Default-rendering assertions (toggled-on by default)
    // -----------------------------------------------------------------

    public function test_default_render_contains_thumbnail_title_channel_meta(): void
    {
        $html = $this->render();
        $this->assertStringContainsString( 'vyg-card__media', $html );
        $this->assertStringContainsString( 'vyg-card__title', $html );
        $this->assertStringContainsString( 'vyg-card__channel', $html );
        $this->assertStringContainsString( 'vyg-card__meta', $html );
    }

    // -----------------------------------------------------------------
    // Region toggles — show_* settings
    // -----------------------------------------------------------------

    public function test_show_thumbnail_false_removes_media_wrapper(): void
    {
        $html = $this->render( array( 'show_thumbnail' => false ) );
        $this->assertStringNotContainsString( 'vyg-card__media', $html );
    }

    public function test_show_duration_false_removes_duration_badge(): void
    {
        $html = $this->render( array( 'show_duration' => false ) );
        $this->assertStringNotContainsString( 'vyg-card__duration', $html );
    }

    public function test_show_title_false_removes_title(): void
    {
        $html = $this->render( array( 'show_title' => false ) );
        $this->assertStringNotContainsString( 'vyg-card__title', $html );
    }

    public function test_show_channel_false_removes_channel_row(): void
    {
        $html = $this->render( array( 'show_channel' => false ) );
        $this->assertStringNotContainsString( 'vyg-card__channel', $html );
    }

    public function test_show_metadata_false_removes_metadata_row(): void
    {
        $html = $this->render( array( 'show_metadata' => false ) );
        $this->assertStringNotContainsString( 'vyg-card__meta', $html );
    }

    public function test_show_description_true_renders_description(): void
    {
        $video = array_merge( $this->sample_video(), array(
            'description' => 'A short description with no markup.',
        ) );
        $html = $this->render(
            array( 'show_description' => true ),
            array(),
            $video
        );
        $this->assertStringContainsString( 'vyg-card__description', $html );
        $this->assertStringContainsString( 'A short description with no markup.', $html );
    }

    public function test_show_description_false_omits_description(): void
    {
        $video = array_merge( $this->sample_video(), array(
            'description' => 'Should not appear.',
        ) );
        $html = $this->render(
            array( 'show_description' => false ),
            array(),
            $video
        );
        $this->assertStringNotContainsString( 'vyg-card__description', $html );
    }

    // -----------------------------------------------------------------
    // Metadata field filtering
    // -----------------------------------------------------------------

    public function test_metadata_fields_views_only_shows_views_not_date(): void
    {
        $html = $this->render( array( 'metadata_fields' => array( 'views' ) ) );
        $this->assertStringContainsString( 'vyg-card__meta-views', $html );
        $this->assertStringNotContainsString( 'vyg-card__meta-time', $html );
    }

    public function test_metadata_fields_published_date_only_shows_date_not_views(): void
    {
        $html = $this->render( array( 'metadata_fields' => array( 'published_date' ) ) );
        $this->assertStringContainsString( 'vyg-card__meta-time', $html );
        $this->assertStringNotContainsString( 'vyg-card__meta-views', $html );
    }

    public function test_metadata_views_includes_formatted_view_count(): void
    {
        $html = $this->render();
        // 125000 → "125K" via VideoRenderer::format_view_count (>= 10K, no decimal).
        $this->assertStringContainsString( '125K', $html );
    }

    // -----------------------------------------------------------------
    // CTA tri-state semantics
    // -----------------------------------------------------------------

    public function test_show_cta_true_always_renders_cta(): void
    {
        $html = $this->render( array( 'show_cta' => true ) );
        $this->assertStringContainsString( 'vyg-card__cta', $html );
        $this->assertStringContainsString( 'View Product', $html );
    }

    public function test_show_cta_false_never_renders_cta(): void
    {
        $html = $this->render( array( 'show_cta' => false ) );
        $this->assertStringNotContainsString( 'vyg-card__cta', $html );
    }

    public function test_show_cta_mapped_only_renders_when_product_map_exists(): void
    {
        // Re-stub apply_filters to inject a product map for this video.
        Functions\when( 'apply_filters' )->alias(
            static function ( $tag, $default, ...$args ) {
                if ( 'vyg_phase10_7_product_map_for_feed' === $tag ) {
                    return array( 'abc123' => 42 ); // product id 42 for this video
                }
                return $default;
            }
        );

        $html = $this->render( array( 'show_cta' => 'mapped_only' ) );
        $this->assertStringContainsString( 'vyg-card__cta', $html );
    }

    public function test_show_cta_mapped_only_omits_cta_when_product_map_missing(): void
    {
        // apply_filters returns the default [] (no product map injected).
        $html = $this->render( array( 'show_cta' => 'mapped_only' ) );
        $this->assertStringNotContainsString( 'vyg-card__cta', $html );
    }

    public function test_show_cta_mapped_only_omits_cta_when_video_id_not_in_map(): void
    {
        Functions\when( 'apply_filters' )->alias(
            static function ( $tag, $default, ...$args ) {
                if ( 'vyg_phase10_7_product_map_for_feed' === $tag ) {
                    return array( 'some_other_video' => 99 );
                }
                return $default;
            }
        );
        $html = $this->render( array( 'show_cta' => 'mapped_only' ) );
        $this->assertStringNotContainsString( 'vyg-card__cta', $html );
    }

    public function test_cta_style_class_is_emitted(): void
    {
        $html = $this->render( array(
            'show_cta'  => true,
            'cta_style' => 'secondary',
        ) );
        $this->assertStringContainsString( 'vyg-card__cta--secondary', $html );
    }

    public function test_cta_label_uses_resolved_label(): void
    {
        $html = $this->render( array(
            'show_cta'   => true,
            'cta_label'  => 'Buy Now',
        ) );
        $this->assertStringContainsString( 'Buy Now', $html );
    }

    // -----------------------------------------------------------------
    // Secondary actions
    // -----------------------------------------------------------------

    public function test_show_actions_true_renders_action_buttons_with_aria_labels(): void
    {
        $html = $this->render( array( 'show_actions' => true ) );
        $this->assertStringContainsString( 'vyg-card__actions', $html );
        // At least one of the canonical action labels is present.
        $this->assertMatchesRegularExpression(
            '/aria-label="(?:Watch on YouTube|Open in YouTube|Share|Copy link|More actions)"/',
            $html
        );
    }

    public function test_show_actions_true_renders_one_button_per_action(): void
    {
        $html = $this->render( array(
            'show_actions' => true,
            'actions'      => array( 'watch', 'youtube', 'share' ),
        ) );
        // Three action buttons → at least three buttons inside the actions wrapper.
        $actions_block = $this->extract_region( $html, 'vyg-card__actions' );
        $this->assertNotEmpty( $actions_block, 'actions region not found' );
        $this->assertSame( 3, substr_count( $actions_block, '<button' ) );
    }

    public function test_show_actions_false_does_not_render_action_buttons(): void
    {
        $html = $this->render( array( 'show_actions' => false ) );
        $this->assertStringNotContainsString( 'vyg-card__actions', $html );
    }

    public function test_action_svgs_are_aria_hidden(): void
    {
        $html = $this->render( array( 'show_actions' => true ) );
        // Every action button has an aria-label, and its SVG is aria-hidden.
        $this->assertMatchesRegularExpression(
            '/<button[^>]*aria-label="[^"]+"[^>]*>.*?aria-hidden="true".*?<\/button>/s',
            $html
        );
    }

    // -----------------------------------------------------------------
    // Channel avatar / verified — must be backed by real data
    // -----------------------------------------------------------------

    public function test_avatar_rendered_only_when_both_flag_and_field_present(): void
    {
        $video = array_merge( $this->sample_video(), array(
            'channel_avatar_url' => 'https://example.com/avatar.jpg',
        ) );
        $html = $this->render(
            array( 'show_channel_avatar' => true ),
            array(),
            $video
        );
        $this->assertStringContainsString( 'vyg-card__channel-avatar', $html );
    }

    public function test_avatar_not_rendered_when_flag_off_even_with_field(): void
    {
        $video = array_merge( $this->sample_video(), array(
            'channel_avatar_url' => 'https://example.com/avatar.jpg',
        ) );
        $html = $this->render(
            array( 'show_channel_avatar' => false ),
            array(),
            $video
        );
        $this->assertStringNotContainsString( 'vyg-card__channel-avatar', $html );
    }

    public function test_avatar_not_rendered_when_field_missing_even_with_flag_on(): void
    {
        $html = $this->render( array( 'show_channel_avatar' => true ) );
        $this->assertStringNotContainsString( 'vyg-card__channel-avatar', $html );
    }

    public function test_verified_badge_only_when_both_flag_and_field_present(): void
    {
        $video = array_merge( $this->sample_video(), array(
            'channel_verified' => true,
        ) );
        $html = $this->render(
            array( 'show_verified_badge' => true ),
            array(),
            $video
        );
        $this->assertStringContainsString( 'vyg-card__verified', $html );
    }

    public function test_verified_badge_not_rendered_when_field_missing(): void
    {
        $html = $this->render( array( 'show_verified_badge' => true ) );
        $this->assertStringNotContainsString( 'vyg-card__verified', $html );
    }

    public function test_subscriber_count_is_never_rendered(): void
    {
        // The plugin does not have a real subscriber count field. We must
        // never render a fake "1.2M" or similar. Setting the show flag on
        // and including the field in the video array still must not
        // produce a fake fallback.
        $video = array_merge( $this->sample_video(), array(
            'subscriber_count' => 1200000,
        ) );
        $html = $this->render(
            array( 'show_subscriber_count' => true ),
            array(),
            $video
        );
        $this->assertStringNotContainsString( '1.2M', $html );
        $this->assertStringNotContainsString( 'vyg-card__channel-subs', $html );
    }

    // -----------------------------------------------------------------
    // Status badge
    // -----------------------------------------------------------------

    public function test_status_badge_not_rendered_when_disabled(): void
    {
        $video = array_merge( $this->sample_video(), array(
            'live_status' => 'live',
        ) );
        $html = $this->render(
            array( 'show_status_badge' => false ),
            array(),
            $video
        );
        $this->assertStringNotContainsString( 'vyg-card__badge--top-left', $html );
    }

    public function test_status_badge_positioned_top_left_by_default(): void
    {
        $video = array_merge( $this->sample_video(), array(
            'live_status' => 'live',
        ) );
        $html = $this->render(
            array( 'show_status_badge' => true ),
            array(),
            $video
        );
        $this->assertStringContainsString( 'vyg-card__badge--top-left', $html );
    }

    // -----------------------------------------------------------------
    // Duration badge position
    // -----------------------------------------------------------------

    public function test_duration_badge_positioned_bottom_right_by_default(): void
    {
        $html = $this->render();
        $this->assertStringContainsString( 'vyg-card__duration--bottom-right', $html );
    }

    // -----------------------------------------------------------------
    // Defensive: empty / missing video fields
    // -----------------------------------------------------------------

    public function test_missing_title_renders_empty_string_gracefully(): void
    {
        $video = $this->sample_video();
        unset( $video['title'] );
        $html = $this->render( array(), array(), $video );
        // No exception, no PHP notice, the title region is still emitted
        // (so layout doesn't shift) but it's effectively empty. The h3
        // exists with the title class, with no anchor inside.
        $this->assertStringContainsString( 'vyg-card__title', $html );
        $this->assertMatchesRegularExpression(
            '#<h3 class="vyg-card__title[^"]*">\s*</h3>#',
            $html
        );
    }

    public function test_missing_view_count_renders_zero_or_omitted(): void
    {
        $video = $this->sample_video();
        unset( $video['view_count'] );
        $html = $this->render( array(), array(), $video );
        // No exception; the meta row still exists.
        $this->assertStringContainsString( 'vyg-card__meta', $html );
    }

    public function test_missing_thumbnail_does_not_crash(): void
    {
        $video = $this->sample_video();
        unset( $video['thumbnail_medium'], $video['thumbnail_high'], $video['thumbnail_standard'], $video['thumbnail_maxres'] );
        $html = $this->render( array(), array(), $video );
        // The media wrapper is still emitted (so layout is stable), but the
        // img src is empty.
        $this->assertStringContainsString( 'vyg-card__media', $html );
    }

    public function test_missing_published_at_renders_empty_date(): void
    {
        $video = $this->sample_video();
        unset( $video['published_at'] );
        $html = $this->render( array(), array(), $video );
        // Meta row is still emitted but contains no relative-time label.
        $this->assertStringContainsString( 'vyg-card__meta', $html );
    }

    // -----------------------------------------------------------------
    // Title link + YouTube URL delegation
    // -----------------------------------------------------------------

    public function test_title_links_to_youtube_watch_url(): void
    {
        $html = $this->render();
        $this->assertStringContainsString( 'href="https://www.youtube.com/watch?v=abc123"', $html );
    }

    public function test_thumbnail_src_uses_best_available_thumbnail(): void
    {
        $html = $this->render();
        // medium preferred → thumbnail_medium URL.
        $this->assertStringContainsString( 'src="https://example.com/medium.jpg"', $html );
    }

    public function test_thumbnail_alt_uses_title(): void
    {
        $html = $this->render();
        $this->assertStringContainsString( 'alt="Test Video Title"', $html );
    }

    // -----------------------------------------------------------------
    // Title line-clamp class
    // -----------------------------------------------------------------

    public function test_title_line_clamp_class_matches_title_lines_setting(): void
    {
        $html = $this->render( array( 'title_lines' => 3 ) );
        $this->assertStringContainsString( 'vyg-card__title--3', $html );
    }

    // -----------------------------------------------------------------
    // Sanity: render is idempotent
    // -----------------------------------------------------------------

    public function test_two_renders_produce_identical_output(): void
    {
        $a = $this->render();
        $b = $this->render();
        $this->assertSame( $a, $b );
    }

    // -----------------------------------------------------------------
    // Helper — extract a region by class for focused assertions.
    // -----------------------------------------------------------------

    /**
     * @return string Substring between the first opening class tag and
     *                the matching closing tag of the nearest wrapper.
     */
    private function extract_region( string $html, string $class_token ): string {
        $idx = strpos( $html, $class_token );
        if ( false === $idx ) {
            return '';
        }
        // Find the start of the wrapping element (<div class="…<token>…).
        $start = strrpos( substr( $html, 0, $idx ), '<' );
        if ( false === $start ) {
            return '';
        }
        // Greedy slice: take up to 4 KB after the opening tag.
        return substr( $html, $start, 4096 );
    }
}
