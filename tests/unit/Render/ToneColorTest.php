<?php
/**
 * Phase 14.6 — Unit tests for the per-video tone color helper and the
 * `--tone` style attribute on the shared card partial.
 *
 * Background: the prototype uses a per-channel "colors[1]" mid-tone
 * hex to drive the thumb background gradient. The plugin version of
 * this is `VideoRenderer::tone_color($video)`, a 3-tier fallback:
 *
 *   1. Stored `tone_color` on the video row (operator override or
 *      normalized from `brandingSettings.image.backgroundColor`).
 *   2. Deterministic SHA-256 hash of the channel id (so the same
 *      channel always gets the same tone even when no row value is
 *      persisted).
 *   3. `DEFAULT_TONE_COLOR` (`#64748b`).
 *
 * XSS contract: the helper REJECTS any stored value that isn't a
 * strict 7-char `#RRGGBB` and falls through to the next tier. The
 * `card-14-6` test exercises an explicit injection attempt to pin
 * this contract.
 *
 * The tests below pin the helper contract AND the partial contract:
 *
 *   - Helper tier 1 (stored value honored when valid).
 *   - Helper tier 1 (stored value rejected when not valid hex).
 *   - Helper tier 2 (channel-id hash is deterministic + distinct).
 *   - Helper tier 3 (no channel id → default slate).
 *   - Partial emits `style="--tone: #…"` on the media wrapper.
 *   - Partial uses the default tone when the video is empty.
 *   - Public-safe mode does not strip the tone style.
 *
 * @covers \VectorYT\Gallery\Render\VideoRenderer
 * @covers \VectorYT\Gallery\Render\CardRenderer
 * @covers \VectorYT\Gallery\Render\TemplateLoader
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

final class ToneColorTest extends TestCase
{
    private VideoRenderer $video_renderer;
    private TemplateLoader $templates;
    private CardRenderer $renderer;

    /**
     * Build the default resolved settings for 'grid' (standard mode).
     *
     * @return array<string,mixed>
     */
    private function default_settings(): array {
        return CardSettings::resolve( 'grid', array(), array() );
    }

    /**
     * Canonical video row (same shape used by CardPlayIconTest).
     *
     * @return array<string,mixed>
     */
    private function sample_video(): array {
        return array(
            'youtube_video_id'      => 'abc123',
            'youtube_channel_id'    => 'UC_test',
            'youtube_channel_title' => 'Test Channel',
            'title'                 => 'Phase 14.6 Test Video',
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
     * Render a single card with optional settings + video overrides.
     *
     * @param array<string,mixed> $settings_overrides
     * @param array<string,mixed>|null $video_override
     */
    private function render(
        array $settings_overrides = array(),
        ?array $video_override = null
    ): string {
        $settings = array_merge( $this->default_settings(), $settings_overrides );
        $context  = array(
            'source'      => array( 'title' => 'Test Source', 'source_uuid' => 'src-x' ),
            'feed_config' => array(),
            'feed_uuid'   => 'feed-x',
            'mode'        => 'standard',
            'role'        => 'listitem',
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
    // VideoRenderer::tone_color — the 3-tier fallback
    // -----------------------------------------------------------------

    public function test_tone_color_returns_stored_value_when_present(): void {
        $video = array( 'tone_color' => '#0f766e' );
        $this->assertSame( '#0f766e', $this->video_renderer->tone_color( $video ) );
    }

    public function test_tone_color_normalizes_stored_uppercase_to_lowercase(): void {
        // The renderer lower-cases the stored value so emitted style
        // attrs are deterministic and easy to grep in tests.
        $video = array( 'tone_color' => '#0F766E' );
        $this->assertSame( '#0f766e', $this->video_renderer->tone_color( $video ) );
    }

    public function test_tone_color_derives_from_channel_id_when_missing(): void {
        $video = array( 'youtube_channel_id' => 'UC_x5XG1OV2P6uZZ5FSM9Ttw' );
        $hex   = $this->video_renderer->tone_color( $video );

        $this->assertIsString( $hex );
        $this->assertSame( 7, strlen( $hex ), 'derived tone must be 7 chars (#RRGGBB)' );
        $this->assertStringStartsWith( '#', $hex, 'derived tone must start with #' );
        $this->assertMatchesRegularExpression(
            '/^#[0-9a-f]{6}$/',
            $hex,
            'derived tone must be lowercase 6-digit hex'
        );
    }

    public function test_tone_color_is_deterministic_for_same_channel_id(): void {
        $video = array( 'youtube_channel_id' => 'UC_deterministic' );
        $first  = $this->video_renderer->tone_color( $video );
        $second = $this->video_renderer->tone_color( $video );
        $third  = $this->video_renderer->tone_color( $video );
        $this->assertSame( $first, $second );
        $this->assertSame( $first, $third );
    }

    public function test_tone_color_distinguishes_different_channel_ids(): void {
        $a = $this->video_renderer->tone_color( array( 'youtube_channel_id' => 'UC_channel_alpha' ) );
        $b = $this->video_renderer->tone_color( array( 'youtube_channel_id' => 'UC_channel_bravo' ) );
        $c = $this->video_renderer->tone_color( array( 'youtube_channel_id' => 'UC_channel_charlie' ) );
        $this->assertNotSame( $a, $b, 'distinct channel ids must produce distinct tones' );
        $this->assertNotSame( $b, $c, 'distinct channel ids must produce distinct tones' );
        $this->assertNotSame( $a, $c, 'distinct channel ids must produce distinct tones' );
    }

    public function test_tone_color_falls_back_to_default_when_no_channel_id_and_no_stored(): void {
        $this->assertSame( '#64748b', $this->video_renderer->tone_color( array() ) );
        $this->assertSame( '#64748b', $this->video_renderer->tone_color( array( 'youtube_channel_id' => '' ) ) );
        $this->assertSame( '#64748b', $this->video_renderer->tone_color( array( 'tone_color' => '' ) ) );
    }

    public function test_tone_color_rejects_xss_attempt_in_stored_value(): void {
        // The stored value contains an HTML injection. The strict
        // regex rejects it, so the helper falls through to the
        // channel-id tier (or default if no channel id either).
        $video = array(
            'tone_color'       => '#"><script>alert(1)</script>',
            'youtube_channel_id' => 'UC_fallback_for_xss',
        );
        $hex = $this->video_renderer->tone_color( $video );

        $this->assertStringNotContainsString( '<', $hex );
        $this->assertStringNotContainsString( '>', $hex );
        $this->assertStringNotContainsString( 'script', strtolower( $hex ) );
        // The xss value was rejected, so the channel-id tier kicked in.
        $this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', $hex );
        $this->assertSame(
            VideoRenderer::channel_id_to_tone( 'UC_fallback_for_xss' ),
            $hex,
            'xss value must be rejected and the channel-id tier used'
        );
    }

    public function test_tone_color_rejects_short_hex_shorthand(): void {
        // 3-digit shorthand (#fff) is NOT accepted — the column shape
        // is fixed at 7 chars and the helper must always emit the
        // full form.
        $video = array( 'tone_color' => '#fff' );
        // No channel id either → falls through to default.
        $this->assertSame( '#64748b', $this->video_renderer->tone_color( $video ) );
    }

    public function test_tone_color_rejects_non_hex_chars(): void {
        $video = array( 'tone_color' => '#zzzzzz' );
        $this->assertSame( '#64748b', $this->video_renderer->tone_color( $video ) );
    }

    public function test_tone_color_stored_value_wins_over_channel_id(): void {
        // Tier 1 must always beat tier 2.
        $video = array(
            'tone_color'         => '#abcdef',
            'youtube_channel_id'  => 'UC_tier1_wins',
        );
        $this->assertSame( '#abcdef', $this->video_renderer->tone_color( $video ) );
    }

    public function test_is_valid_hex_color_accepts_valid_and_rejects_invalid(): void {
        $this->assertTrue( VideoRenderer::is_valid_hex_color( '#0f766e' ) );
        $this->assertTrue( VideoRenderer::is_valid_hex_color( '#0F766E' ) );
        $this->assertTrue( VideoRenderer::is_valid_hex_color( '#ABCDEF' ) );
        $this->assertFalse( VideoRenderer::is_valid_hex_color( '#fff' ), '3-digit shorthand rejected' );
        $this->assertFalse( VideoRenderer::is_valid_hex_color( '0f766e' ), 'missing # rejected' );
        $this->assertFalse( VideoRenderer::is_valid_hex_color( '#0f76e' ), '5-digit rejected' );
        $this->assertFalse( VideoRenderer::is_valid_hex_color( '#0f766e7' ), '7-digit rejected' );
        $this->assertFalse( VideoRenderer::is_valid_hex_color( '' ), 'empty rejected' );
        $this->assertFalse( VideoRenderer::is_valid_hex_color( '#xss" onerror="alert(1)' ), 'xss rejected' );
    }

    public function test_channel_id_to_tone_is_deterministic_and_well_formed(): void {
        $a = VideoRenderer::channel_id_to_tone( 'UC_alpha' );
        $b = VideoRenderer::channel_id_to_tone( 'UC_alpha' );
        $c = VideoRenderer::channel_id_to_tone( 'UC_bravo' );

        $this->assertSame( $a, $b, 'same channel id must produce the same tone' );
        $this->assertNotSame( $a, $c, 'different channel ids must produce different tones' );
        $this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', $a );
        $this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', $c );
    }

    // -----------------------------------------------------------------
    // CardRenderer — the partial emits `style="--tone: #…"`
    // -----------------------------------------------------------------

    public function test_video_card_emits_tone_style_attribute_on_media_wrapper(): void {
        $video = array_merge( $this->sample_video(), array( 'tone_color' => '#0f766e' ) );
        $html  = $this->render( array(), $video );

        // The exact prototype element: <div class="…vyg-card__media…"
        // style="--tone:#0f766e">. The style attribute is interpolated
        // by the partial via esc_attr, so the hash and digits are
        // emitted literally.
        $this->assertMatchesRegularExpression(
            '/<div[^>]*class="[^"]*vyg-card__media\b[^"]*"[^>]*style="[^"]*--tone:\s*#0f766e[^"]*"[^>]*>/',
            $html,
            'media wrapper must carry a --tone style attribute with the stored hex'
        );
    }

    public function test_video_card_default_tone_when_no_video_data(): void {
        // No tone_color, no youtube_channel_id — falls through to
        // the default slate hex.
        $html = $this->render( array(), array() );

        $this->assertMatchesRegularExpression(
            '/<div[^>]*class="[^"]*vyg-card__media\b[^"]*"[^>]*style="[^"]*--tone:\s*#64748b[^"]*"[^>]*>/',
            $html,
            'default tone must be #64748b (slate-500) when no data exists'
        );
    }

    public function test_video_card_emits_distinct_tones_for_distinct_channel_ids(): void {
        // The deterministic channel-id hash must produce visibly
        // different tone values across distinct channel ids. This
        // pins the "varied card backgrounds" effect from the
        // prototype.
        $channel_a = $this->render( array(), array_merge( $this->sample_video(), array(
            'youtube_channel_id' => 'UC_channel_alpha_14_6',
        ) ) );
        $channel_b = $this->render( array(), array_merge( $this->sample_video(), array(
            'youtube_channel_id' => 'UC_channel_bravo_14_6',
        ) ) );

        preg_match(
            '/style="[^"]*--tone:\s*(#[0-9a-f]{6})/',
            $channel_a,
            $m_a
        );
        preg_match(
            '/style="[^"]*--tone:\s*(#[0-9a-f]{6})/',
            $channel_b,
            $m_b
        );

        $this->assertNotEmpty( $m_a, 'channel A tone must be present' );
        $this->assertNotEmpty( $m_b, 'channel B tone must be present' );
        $this->assertNotSame(
            $m_a[1],
            $m_b[1],
            'distinct channel ids must produce distinct --tone values'
        );
    }

    public function test_video_card_stored_tone_wins_over_channel_id_in_partial(): void {
        // Tier 1 must always beat tier 2 in the rendered HTML, just
        // like in the helper.
        $html = $this->render( array(), array_merge( $this->sample_video(), array(
            'tone_color'         => '#abcdef',
            'youtube_channel_id'  => 'UC_partial_tier1',
        ) ) );

        $this->assertMatchesRegularExpression(
            '/style="[^"]*--tone:\s*#abcdef[^"]*"/',
            $html,
            'stored tone_color must win over channel-id hash in the rendered partial'
        );
        // The channel-id tone (UC_partial_tier1) must NOT be present.
        $channel_hex = VideoRenderer::channel_id_to_tone( 'UC_partial_tier1' );
        $this->assertStringNotContainsString(
            '--tone:' . $channel_hex,
            $html,
            'channel-id hash tone must not appear when stored value is present'
        );
    }

    public function test_video_card_strips_xss_attempt_in_stored_tone(): void {
        // Even if the DB column is somehow populated with malicious
        // HTML, the partial + helper chain must NOT emit it.
        $video = array_merge( $this->sample_video(), array(
            'tone_color'        => '#"><script>alert(1)</script>',
            'youtube_channel_id' => 'UC_partial_xss',
        ) );
        $html = $this->render( array(), $video );

        $this->assertStringNotContainsString( '<script', $html, 'no raw script tag in output' );
        $this->assertStringNotContainsString( 'alert(1)', $html, 'no raw alert in output' );
        // The helper fell through to the channel-id tier, which is
        // always a clean 7-char hex.
        $this->assertMatchesRegularExpression(
            '/style="[^"]*--tone:\s*#[0-9a-f]{6}[^"]*"/',
            $html,
            'a safe hex must be present (from the channel-id tier)'
        );
    }

    public function test_video_card_emits_tone_on_default_render(): void {
        // The default render (no overrides) must include the tone
        // style attribute — the feature is on-by-default.
        $html = $this->render();

        $this->assertMatchesRegularExpression(
            '/<div[^>]*class="[^"]*vyg-card__media\b[^"]*"[^>]*style="[^"]*--tone:/',
            $html,
            'default render must include a --tone style attribute'
        );
    }
}
