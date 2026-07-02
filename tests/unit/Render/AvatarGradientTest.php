<?php
/**
 * Phase 14.7 — Unit tests for the per-channel avatar gradient helper
 * and the `--aa` / `--ab` style attributes on the shared card partial.
 *
 * Background: the prototype uses a per-channel "avatar[0]" mid-tone +
 * "avatar[1]" dark anchor to drive a 30px circle gradient. The
 * plugin's `VideoRenderer::avatar_colors($video)` is the 3-tier
 * fallback counterpart of `tone_color()` from 14.6:
 *
 *   1. Stored `avatar_color_a` + `avatar_color_b` on the video row
 *      (operator override, future schema) — both must be a valid
 *      7-char `#RRGGBB`.
 *   2. Deterministic SHA-256 hash pair of the channel id (bytes
 *      3..5 for color A, bytes 6..8 for color B — deliberately
 *      non-overlapping slices so the pair is visually distinct).
 *   3. `DEFAULT_AVATAR_COLOR_A` / `DEFAULT_AVATAR_COLOR_B` pair
 *      (`#64748b` / `#0f172a`).
 *
 * XSS contract: the helper REJECTS any stored value that isn't a
 * strict 7-char `#RRGGBB` and falls through to the next tier.
 * `card-14-7-xss` exercises an explicit injection attempt to pin
 * this contract.
 *
 * The tests below pin the helper contract AND the partial contract:
 *
 *   - Helper tier 1 (stored pair honored when both valid).
 *   - Helper tier 1 (stored pair rejected when either is invalid).
 *   - Helper tier 2 (channel-id hash is deterministic + distinct pair).
 *   - Helper tier 2 (no channel id → falls through to default).
 *   - Partial emits `style="--aa: #…; --ab: #…"` on the avatar img.
 *   - Partial absent when `show_channel_avatar` is false.
 *   - Public-safe mode does not strip the avatar style.
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

final class AvatarGradientTest extends TestCase
{
    private VideoRenderer $video_renderer;
    private TemplateLoader $templates;
    private CardRenderer $renderer;

    /**
     * Build the default resolved settings for 'grid' (standard mode).
     * `show_channel_avatar` is OFF by default in CardSettings — the
     * render tests below force it on per-call.
     *
     * @return array<string,mixed>
     */
    private function default_settings(): array {
        return CardSettings::resolve( 'grid', array(), array() );
    }

    /**
     * Canonical video row. Matches the ToneColorTest::sample_video
     * shape so the per-channel and per-video colors are easy to
     * compare side-by-side.
     *
     * @return array<string,mixed>
     */
    private function sample_video(): array {
        return array(
            'youtube_video_id'      => 'abc123',
            'youtube_channel_id'    => 'UC_test',
            'youtube_channel_title' => 'Test Channel',
            'title'                 => 'Phase 14.7 Test Video',
            'thumbnail_medium'      => 'https://example.com/medium.jpg',
            'thumbnail_high'        => 'https://example.com/high.jpg',
            'duration_seconds'      => 765,
            'content_type'          => 'standard',
            'live_status'           => 'none',
            'published_at'          => '2026-06-25T12:00:00+00:00',
            'view_count'            => 125000,
            'channel_avatar_url'    => 'https://example.com/avatar.jpg',
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
    // VideoRenderer::avatar_colors — the 3-tier fallback
    // -----------------------------------------------------------------

    public function test_avatar_colors_returns_two_hex_strings(): void {
        $pair = $this->video_renderer->avatar_colors( array() );

        $this->assertIsArray( $pair );
        $this->assertCount( 2, $pair );
        foreach ( $pair as $hex ) {
            $this->assertIsString( $hex );
            $this->assertSame( 7, strlen( $hex ), 'avatar color must be 7 chars (#RRGGBB)' );
            $this->assertStringStartsWith( '#', $hex, 'avatar color must start with #' );
            $this->assertMatchesRegularExpression(
                '/^#[0-9a-f]{6}$/',
                $hex,
                'avatar color must be lowercase 6-digit hex'
            );
        }
    }

    public function test_avatar_colors_honors_stored_pair_when_both_valid(): void {
        $video = array(
            'avatar_color_a' => '#0f766e',
            'avatar_color_b' => '#111827',
        );
        $pair = $this->video_renderer->avatar_colors( $video );
        $this->assertSame( array( '#0f766e', '#111827' ), $pair );
    }

    public function test_avatar_colors_normalizes_stored_pair_to_lowercase(): void {
        $video = array(
            'avatar_color_a' => '#0F766E',
            'avatar_color_b' => '#ABCDEF',
        );
        $pair = $this->video_renderer->avatar_colors( $video );
        $this->assertSame( array( '#0f766e', '#abcdef' ), $pair );
    }

    public function test_avatar_colors_rejects_stored_pair_when_one_is_invalid(): void {
        // Color A is a valid 7-char hex, but color B is the 3-digit
        // shorthand. Tier 1 must require BOTH to be valid — otherwise
        // we'd emit a half-broken pair that breaks the gradient. The
        // helper falls through to the channel-id tier (or default).
        $video = array(
            'avatar_color_a'       => '#0f766e',
            'avatar_color_b'       => '#fff',
            'youtube_channel_id'   => 'UC_14_7_tier_fallback',
        );
        $pair = $this->video_renderer->avatar_colors( $video );

        // The channel-id tier kicked in — neither stored value is
        // present in the output.
        $this->assertNotSame( '#0f766e', $pair[0] );
        $this->assertNotSame( '#fff', $pair[1] );
        $this->assertSame(
            VideoRenderer::channel_id_to_avatar_colors( 'UC_14_7_tier_fallback' ),
            $pair,
            'half-valid stored pair must fall through to channel-id hash'
        );
    }

    public function test_avatar_colors_rejects_xss_attempt_in_stored_pair(): void {
        // The stored value is an HTML injection. The strict regex
        // rejects it, so the helper falls through to the channel-id
        // tier (or default if no channel id either).
        $video = array(
            'avatar_color_a'     => '#"><script>alert(1)</script>',
            'avatar_color_b'     => '#111827',
            'youtube_channel_id' => 'UC_14_7_xss_fallback',
        );
        $pair = $this->video_renderer->avatar_colors( $video );

        $this->assertStringNotContainsString( '<', $pair[0] );
        $this->assertStringNotContainsString( '>', $pair[0] );
        $this->assertStringNotContainsString( 'script', strtolower( $pair[0] ) );
        $this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', $pair[0] );
        $this->assertSame(
            VideoRenderer::channel_id_to_avatar_colors( 'UC_14_7_xss_fallback' ),
            $pair,
            'xss value must be rejected and the channel-id tier used'
        );
    }

    public function test_avatar_colors_derives_from_channel_id_when_missing(): void {
        $video = array( 'youtube_channel_id' => 'UC_x5XG1OV2P6uZZ5FSM9Ttw' );
        $pair  = $this->video_renderer->avatar_colors( $video );

        $this->assertIsArray( $pair );
        $this->assertCount( 2, $pair );
        foreach ( $pair as $hex ) {
            $this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', $hex );
        }
    }

    public function test_avatar_colors_pair_is_deterministic_for_same_channel_id(): void {
        $video = array( 'youtube_channel_id' => 'UC_deterministic_14_7' );
        $first  = $this->video_renderer->avatar_colors( $video );
        $second = $this->video_renderer->avatar_colors( $video );
        $third  = $this->video_renderer->avatar_colors( $video );

        $this->assertSame( $first, $second );
        $this->assertSame( $first, $third );
    }

    public function test_avatar_colors_pair_distinguishes_different_channel_ids(): void {
        $a = $this->video_renderer->avatar_colors( array( 'youtube_channel_id' => 'UC_14_7_alpha' ) );
        $b = $this->video_renderer->avatar_colors( array( 'youtube_channel_id' => 'UC_14_7_bravo' ) );
        $c = $this->video_renderer->avatar_colors( array( 'youtube_channel_id' => 'UC_14_7_charlie' ) );

        $this->assertNotSame( $a, $b, 'distinct channel ids must produce distinct pairs' );
        $this->assertNotSame( $b, $c, 'distinct channel ids must produce distinct pairs' );
        $this->assertNotSame( $a, $c, 'distinct channel ids must produce distinct pairs' );
    }

    public function test_avatar_colors_avatar_pair_is_visually_distinct_from_thumb_tone(): void {
        // The brief explicitly warns: "Don't just hash the channel ID
        // twice". The avatar pair and the thumb tone are derived from
        // non-overlapping slices of the same SHA-256, so the same
        // channel produces a clearly different thumb tone vs avatar
        // gradient.
        $channel = 'UC_14_7_distinct_slices';
        $tone    = VideoRenderer::channel_id_to_tone( $channel );
        $pair    = VideoRenderer::channel_id_to_avatar_colors( $channel );

        // Color A (first avatar hex) must NOT equal the thumb tone.
        $this->assertNotSame( $tone, $pair[0], 'avatar color A must differ from the thumb tone' );
        $this->assertNotSame( $tone, $pair[1], 'avatar color B must differ from the thumb tone' );
        // Color A and color B must also be distinct from each other.
        $this->assertNotSame( $pair[0], $pair[1], 'avatar pair must be a distinct pair, not the same color twice' );
    }

    public function test_avatar_colors_falls_back_to_default_when_no_channel_id_and_no_stored(): void {
        $pair = $this->video_renderer->avatar_colors( array() );
        $this->assertSame( array( '#64748b', '#0f172a' ), $pair );

        $pair2 = $this->video_renderer->avatar_colors( array( 'youtube_channel_id' => '' ) );
        $this->assertSame( array( '#64748b', '#0f172a' ), $pair2 );

        $pair3 = $this->video_renderer->avatar_colors( array(
            'avatar_color_a' => '',
            'avatar_color_b' => '',
        ) );
        $this->assertSame( array( '#64748b', '#0f172a' ), $pair3 );
    }

    public function test_channel_id_to_avatar_colors_returns_null_for_empty_input(): void {
        $this->assertNull( VideoRenderer::channel_id_to_avatar_colors( '' ) );
    }

    public function test_channel_id_to_avatar_colors_is_deterministic_and_well_formed(): void {
        $a = VideoRenderer::channel_id_to_avatar_colors( 'UC_alpha_14_7' );
        $b = VideoRenderer::channel_id_to_avatar_colors( 'UC_alpha_14_7' );
        $c = VideoRenderer::channel_id_to_avatar_colors( 'UC_bravo_14_7' );

        $this->assertIsArray( $a );
        $this->assertCount( 2, $a );
        $this->assertSame( $a, $b, 'same channel id must produce the same pair' );
        $this->assertNotSame( $a, $c, 'different channel ids must produce different pairs' );
        $this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', $a[0] );
        $this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', $a[1] );
    }

    public function test_avatar_colors_stored_pair_wins_over_channel_id(): void {
        // Tier 1 must always beat tier 2.
        $video = array(
            'avatar_color_a'      => '#abcdef',
            'avatar_color_b'      => '#123456',
            'youtube_channel_id'  => 'UC_14_7_tier1_wins',
        );
        $pair = $this->video_renderer->avatar_colors( $video );
        $this->assertSame( array( '#abcdef', '#123456' ), $pair );
    }

    // -----------------------------------------------------------------
    // CardRenderer — the partial emits `style="--aa: #…; --ab: #…"`
    // -----------------------------------------------------------------

    public function test_video_card_emits_aa_and_ab_style_on_avatar_when_show_channel_avatar_true(): void {
        $html = $this->render( array( 'show_channel_avatar' => true ) );

        // The avatar img must carry both `--aa` and `--ab` CSS
        // custom properties. The exact prototype element:
        //   <img class="…vyg-card__channel-avatar…"
        //        style="--aa:#…;--ab:#…">
        $this->assertMatchesRegularExpression(
            '/<img[^>]*class="[^"]*vyg-card__channel-avatar\b[^"]*"[^>]*style="[^"]*--aa:\s*#[0-9a-f]{6}[^"]*--ab:\s*#[0-9a-f]{6}[^"]*"[^>]*>/',
            $html,
            'avatar img must carry --aa and --ab style attributes with two 7-char hex values'
        );
    }

    public function test_video_card_emits_default_avatar_pair_when_no_video_data(): void {
        // The avatar element only renders when BOTH the
        // `show_channel_avatar` setting AND a real `channel_avatar_url`
        // exist on the video row (the §16.4 contract). We satisfy
        // the real-data branch with a dummy URL but strip the
        // channel id, so the helper falls through to the default
        // slate-500 / slate-900 pair.
        $html = $this->render(
            array( 'show_channel_avatar' => true ),
            array(
                'title'              => 'Empty video',
                'channel_avatar_url' => 'https://example.com/avatar.jpg',
            )
        );

        $this->assertMatchesRegularExpression(
            '/<img[^>]*class="[^"]*vyg-card__channel-avatar\b[^"]*"[^>]*style="[^"]*--aa:\s*#64748b[^"]*--ab:\s*#0f172a[^"]*"[^>]*>/',
            $html,
            'default avatar pair must be #64748b / #0f172a when no data exists'
        );
    }

    public function test_video_card_emits_distinct_avatar_pairs_for_distinct_channel_ids(): void {
        // Two different channel ids → two different gradient pairs.
        // This pins the prototype's "varied card backgrounds" effect
        // for the avatar (in addition to the 14.6 thumb tone).
        $channel_a = $this->render(
            array( 'show_channel_avatar' => true ),
            array_merge( $this->sample_video(), array( 'youtube_channel_id' => 'UC_14_7_avatar_alpha' ) )
        );
        $channel_b = $this->render(
            array( 'show_channel_avatar' => true ),
            array_merge( $this->sample_video(), array( 'youtube_channel_id' => 'UC_14_7_avatar_bravo' ) )
        );

        preg_match(
            '/<img[^>]*class="[^"]*vyg-card__channel-avatar\b[^"]*"[^>]*style="[^"]*--aa:\s*(#[0-9a-f]{6})[^"]*--ab:\s*(#[0-9a-f]{6})/',
            $channel_a,
            $m_a
        );
        preg_match(
            '/<img[^>]*class="[^"]*vyg-card__channel-avatar\b[^"]*"[^>]*style="[^"]*--aa:\s*(#[0-9a-f]{6})[^"]*--ab:\s*(#[0-9a-f]{6})/',
            $channel_b,
            $m_b
        );

        $this->assertNotEmpty( $m_a, 'channel A avatar pair must be present' );
        $this->assertNotEmpty( $m_b, 'channel B avatar pair must be present' );
        $this->assertNotSame(
            $m_a[1] . '|' . $m_a[2],
            $m_b[1] . '|' . $m_b[2],
            'distinct channel ids must produce distinct --aa/--ab pairs'
        );
    }

    public function test_video_card_omits_avatar_style_when_show_channel_avatar_false(): void {
        // The brief: "The avatar is INSIDE a channel block; respect
        // `show_channel_avatar` to gate the entire emission." When
        // the setting is off, no avatar <img> is rendered and no
        // --aa/--ab style should appear anywhere.
        $html = $this->render( array( 'show_channel_avatar' => false ) );

        $this->assertStringNotContainsString(
            'vyg-card__channel-avatar',
            $html,
            'avatar element must not be emitted when show_channel_avatar is false'
        );
        $this->assertStringNotContainsString( '--aa:', $html );
        $this->assertStringNotContainsString( '--ab:', $html );
    }

    public function test_video_card_avatar_strips_xss_attempt_in_stored_pair(): void {
        // Even if the DB row is somehow populated with malicious
        // HTML, the partial + helper chain must NOT emit it.
        $video = array_merge( $this->sample_video(), array(
            'avatar_color_a'      => '#"><script>alert(1)</script>',
            'avatar_color_b'      => '#111827',
            'youtube_channel_id'  => 'UC_14_7_partial_xss',
        ) );
        $html = $this->render( array( 'show_channel_avatar' => true ), $video );

        $this->assertStringNotContainsString( '<script', $html, 'no raw script tag in output' );
        $this->assertStringNotContainsString( 'alert(1)', $html, 'no raw alert in output' );
        // The helper fell through to the channel-id tier, which is
        // always a clean pair of 7-char hex.
        $this->assertMatchesRegularExpression(
            '/<img[^>]*class="[^"]*vyg-card__channel-avatar\b[^"]*"[^>]*style="[^"]*--aa:\s*#[0-9a-f]{6}[^"]*--ab:\s*#[0-9a-f]{6}[^"]*"[^>]*>/',
            $html,
            'a safe pair must be present (from the channel-id tier)'
        );
    }

    public function test_video_card_avatar_stored_pair_wins_over_channel_id_in_partial(): void {
        // Tier 1 must always beat tier 2 in the rendered HTML, just
        // like in the helper.
        $video = array_merge( $this->sample_video(), array(
            'avatar_color_a'      => '#abcdef',
            'avatar_color_b'      => '#123456',
            'youtube_channel_id'  => 'UC_14_7_partial_tier1',
        ) );
        $html = $this->render( array( 'show_channel_avatar' => true ), $video );

        $this->assertMatchesRegularExpression(
            '/style="[^"]*--aa:\s*#abcdef[^"]*--ab:\s*#123456[^"]*"/',
            $html,
            'stored avatar_color_a/b must win over channel-id hash in the rendered partial'
        );
        // The channel-id hash pair must NOT be present.
        $channel_pair = VideoRenderer::channel_id_to_avatar_colors( 'UC_14_7_partial_tier1' );
        $this->assertStringNotContainsString(
            '--aa:' . $channel_pair[0],
            $html,
            'channel-id hash pair must not appear when stored pair is present'
        );
    }
}
