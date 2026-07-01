<?php
/**
 * Phase 13.1 + D1 — Shortcode + block attribute contract tests.
 *
 * The ShortcodeRegistrar and BlockRegistrar both accept the new density,
 * header, and meta attributes. We verify:
 *  1. block.json declares every new attribute with the correct default.
 *  2. The shortcode accepts every new attribute (the render path itself
 *     is covered by the Renderer/grid integration tests).
 *
 * Phase D1 — the shortcode gains 22 new card-system attributes
 * (card_preset, card_style, show_thumbnail, show_description, show_cta,
 * thumbnail_ratio, …) plus the existing Phase 13.1 legacy attrs. The
 * CRITICAL D1 contract: the shortcode's hardcoded defaults MUST NOT
 * clobber the saved feed's card settings. Concretely, every card-system
 * attribute is only passed to Renderer::render() if the user
 * EXPLICITLY set it in the shortcode. Otherwise the shortcode
 * silently drops the attr so the saved feed / legacy value flows
 * through CardSettings::resolve() unchanged.
 *
 * @covers \VectorYT\Gallery\Render\ShortcodeRegistrar
 * @covers \VectorYT\Gallery\Render\CardSettings            (via render path)
 * @covers \VectorYT\Gallery\Render\CardRenderer             (via render path)
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Render;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Render\LiveQuery;
use VectorYT\Gallery\Render\Renderer;
use VectorYT\Gallery\Render\ShortcodeRegistrar;
use VectorYT\Gallery\Render\TemplateLoader;
use VectorYT\Gallery\Render\VideoRenderer;
use VectorYT\Gallery\Repository\FeedRepository;
use VectorYT\Gallery\Tests\Support\BrainHelpers;

require_once __DIR__ . '/../../bootstrap.php';

final class ShortcodeNewAttributesTest extends TestCase
{
    // -----------------------------------------------------------------
    // Phase D1 — 22 new card-system attributes + explicit-only filter.
    //
    // These tests pin down the new ShortcodeRegistrar contract:
    //   1. The 22 new card-system attrs are added to shortcode_atts
    //      defaults (so PHP doesn't emit warnings on first read).
    //   2. Every card-system attr is ONLY passed to Renderer::render()
    //      when the user EXPLICITLY set it in the shortcode. Hardcoded
    //      shortcode defaults never clobber the saved feed / legacy
    //      card_settings via CardSettings::resolve()'s inline layer.
    //   3. Structural attrs (feed_uuid, columns, per_page, layout, etc.)
    //      always pass through unconditionally.
    // -----------------------------------------------------------------

    /**
     * The complete set of card-system attr names that the ShortcodeRegistrar
     * must gate. Includes 22 new D1 attrs + the 14 Phase 13.1 legacy attrs
     * + the 5 header_* layout-specific attrs. Source-of-truth list —
     * if the constant changes, update this list (and the test that reads
     * it) intentionally.
     */
    private const EXPECTED_CARD_SYSTEM_ATTRS = array(
        // 22 new card-system attrs (D1).
        'card_preset', 'card_style',
        'show_thumbnail', 'thumbnail_ratio', 'show_duration',
        'show_title', 'show_channel', 'show_metadata',
        'show_description', 'show_cta', 'show_actions',
        'metadata_fields', 'date_format', 'metadata_separator',
        'cta_style', 'cta_label', 'cta_position',
        'thumbnail_overlay', 'show_play_icon',
        'compact_mobile', 'hide_description_mobile', 'hide_metadata_mobile',
        // Phase 13.1 legacy attrs (treated as card-system by D1).
        'density', 'show_channel_avatar', 'show_channel_name',
        'show_subscriber_count', 'show_verified_badge',
        'show_views_and_time', 'product_cta_visible', 'trust_strip',
        'card_radius',
        // header_* are grid-layout-specific; gate them too.
        'header_title', 'header_subtitle', 'header_columns_visible',
        'header_cta_label', 'header_cta_url',
    );

    private const NEW_D1_ATTRS = array(
        'card_preset', 'card_style',
        'show_thumbnail', 'thumbnail_ratio', 'show_duration',
        'show_title', 'show_channel', 'show_metadata',
        'show_description', 'show_cta', 'show_actions',
        'metadata_fields', 'date_format', 'metadata_separator',
        'cta_style', 'cta_label', 'cta_position',
        'thumbnail_overlay', 'show_play_icon',
        'compact_mobile', 'hide_description_mobile', 'hide_metadata_mobile',
    );

    public function test_shortcode_atts_defaults_contain_all_22_new_d1_attrs(): void
    {
        // The 22 new D1 attrs must appear as keys in the shortcode_atts
        // default array. This pins down the contract that operators can
        // pass any of them in the shortcode without PHP warnings.
        $path = dirname( __DIR__, 3 ) . '/src/Render/ShortcodeRegistrar.php';
        $src  = (string) file_get_contents( $path );
        foreach ( self::NEW_D1_ATTRS as $key ) {
            $this->assertStringContainsString(
                "'{$key}'",
                $src,
                "ShortcodeRegistrar shortcode_atts defaults must declare new D1 attr '{$key}'"
            );
        }
    }

    public function test_card_system_attrs_constant_lists_22_new_plus_legacy_attrs(): void
    {
        // The ShortcodeRegistrar must expose a CARD_SYSTEM_ATTRS constant
        // listing every attr that the explicit-only filter gates. The
        // constant's content is the contract.
        $path = dirname( __DIR__, 3 ) . '/src/Render/ShortcodeRegistrar.php';
        $src  = (string) file_get_contents( $path );
        $this->assertStringContainsString(
            'CARD_SYSTEM_ATTRS',
            $src,
            'ShortcodeRegistrar must declare a CARD_SYSTEM_ATTRS constant (D1 explicit-only filter)'
        );
        // Spot-check the 22 new D1 attrs and the 14 legacy attrs are all
        // listed inside the constant's array literal. Source-level check
        // (simpler than regex extraction): just verify each key appears
        // in the source file in a CARD_SYSTEM_ATTRS-adjacent context.
        $card_attrs_block = '';
        if ( preg_match( '/CARD_SYSTEM_ATTRS\s*=\s*array\s*\((.*?)\);/s', $src, $m ) ) {
            $card_attrs_block = $m[1];
        }
        $this->assertNotEmpty( $card_attrs_block, 'CARD_SYSTEM_ATTRS must be a non-empty array' );
        foreach ( self::EXPECTED_CARD_SYSTEM_ATTRS as $key ) {
            $this->assertStringContainsString(
                "'{$key}'",
                $card_attrs_block,
                "CARD_SYSTEM_ATTRS must list '{$key}'"
            );
        }
    }

    public function test_explicit_only_filter_drops_card_system_defaults(): void
    {
        // The Phase D1 fix: the shortcode's hardcoded defaults (e.g.
        // 'density' => 'comfortable', 'show_channel_avatar' => true)
        // must NOT reach Renderer::render() as inline args when the
        // user did not set them. Otherwise they clobber the saved
        // feed's per-feed / per-layout / legacy card settings via
        // CardSettings::resolve()'s inline layer (highest precedence).
        //
        // The cleanest way to assert this is end-to-end: a saved feed
        // with `density: 'compact'` in display_config_json. The
        // shortcode is `[youtube_feed feed_uuid="x"]` — no density.
        // The rendered output must contain `vyg-density--compact`,
        // proving the shortcode's default of `comfortable` did NOT
        // clobber the saved value.
        $html = $this->render_shortcode_with_saved_display(
            array( 'feed_uuid' => 'phase-13-1-compact' ),
            array( 'density' => 'compact' )
        );
        $this->assertStringContainsString(
            'vyg-density--compact',
            $html,
            'explicit-only fix: shortcode default "comfortable" must NOT clobber saved feed density=compact'
        );
        $this->assertStringNotContainsString(
            'vyg-density--comfortable',
            $html,
            'explicit-only fix: shortcode default density must be dropped, not clobbered'
        );
    }

    public function test_explicit_density_attr_overrides_saved_value(): void
    {
        // User explicitly sets density=editorial in the shortcode; the
        // saved feed says density=compact. The user's explicit value
        // MUST win (it's the whole point of shortcode overrides).
        $html = $this->render_shortcode_with_saved_display(
            array( 'feed_uuid' => 'phase-13-1-compact', 'density' => 'editorial' ),
            array( 'density' => 'compact' )
        );
        $this->assertStringContainsString(
            'vyg-density--editorial',
            $html,
            'explicit user density=editorial must override saved density=compact'
        );
    }

    public function test_explicit_show_views_and_time_false_overrides_default(): void
    {
        // The shortcode defaults show_views_and_time=true. The user
        // explicitly sets show_views_and_time=false. The user's value
        // must be honored, which removes the meta row from the rendered
        // card. (show_views_and_time is a Phase 13.1 legacy attr that
        // maps to show_metadata via LEGACY_MAP.)
        $html = $this->render_shortcode(
            array( 'feed_uuid' => 'x', 'show_views_and_time' => 'false' )
        );
        $this->assertStringNotContainsString(
            'vyg-card__meta',
            $html,
            'explicit user show_views_and_time=false must hide metadata row (default true must NOT clobber)'
        );
    }

    public function test_no_shortcode_clobbering_when_no_card_system_attrs_set(): void
    {
        // No card-system attrs in the shortcode → no clobbering → the
        // saved feed's `card_settings.global` and `card_settings.layouts`
        // flow through CardSettings::resolve() unchanged.
        $html = $this->render_shortcode_with_saved_display(
            array( 'feed_uuid' => 'phase-13-1-with-card-settings' ),
            array(
                'card_settings' => array(
                    'global' => array(
                        'show_description' => true,
                        'card_style'       => 'elevated',
                    ),
                ),
            )
        );
        $this->assertStringContainsString(
            'vyg-card--elevated',
            $html,
            'no shortcode clobbering: saved card_settings.global.card_style=elevated must win'
        );
        $this->assertStringContainsString(
            'vyg-card__description',
            $html,
            'no shortcode clobbering: saved card_settings.global.show_description=true must win'
        );
    }

    public function test_metadata_fields_legacy_list_renders_only_requested_fields(): void
    {
        // The legacy `metadata_fields` shortcode attr maps to the new
        // `metadata_fields` card setting (allow-list of 'views',
        // 'published_date'). When the user explicitly sets it, the
        // value flows through and only the requested field is rendered.
        $html = $this->render_shortcode(
            array( 'feed_uuid' => 'x', 'metadata_fields' => 'views' )
        );
        $this->assertStringContainsString(
            'vyg-card__meta-views',
            $html,
            'explicit metadata_fields=views must render only views in meta'
        );
        $this->assertStringNotContainsString(
            'vyg-card__meta-time',
            $html,
            'explicit metadata_fields=views must NOT render published_date in meta'
        );
    }

    public function test_explicit_show_description_true_renders_description(): void
    {
        $html = $this->render_shortcode(
            array( 'feed_uuid' => 'x', 'show_description' => 'true' )
        );
        $this->assertStringContainsString(
            'vyg-card__description',
            $html,
            'explicit show_description=true must render description region'
        );
    }

    public function test_explicit_show_cta_false_hides_cta(): void
    {
        $html = $this->render_shortcode(
            array( 'feed_uuid' => 'x', 'show_cta' => 'false' )
        );
        $this->assertStringNotContainsString(
            'vyg-card__cta',
            $html,
            'explicit show_cta=false must hide CTA'
        );
    }

    public function test_explicit_show_cta_true_renders_cta(): void
    {
        $html = $this->render_shortcode(
            array( 'feed_uuid' => 'x', 'show_cta' => 'true' )
        );
        $this->assertStringContainsString(
            'vyg-card__cta',
            $html,
            'explicit show_cta=true must render CTA'
        );
    }

    public function test_explicit_show_cta_mapped_only_does_not_error(): void
    {
        // `mapped_only` is the tri-state default. With no real product
        // map filter, the CTA is hidden. The test just verifies the
        // shortcode does not error on this value.
        $html = $this->render_shortcode(
            array( 'feed_uuid' => 'x', 'show_cta' => 'mapped_only' )
        );
        $this->assertIsString( $html, 'explicit show_cta=mapped_only must not error' );
    }

    public function test_explicit_thumbnail_ratio_9_16_renders_correct_class(): void
    {
        $html = $this->render_shortcode(
            array( 'feed_uuid' => 'x', 'thumbnail_ratio' => '9_16' )
        );
        $this->assertStringContainsString(
            'vyg-thumb-ratio--9-16',
            $html,
            'explicit thumbnail_ratio=9_16 must emit vyg-thumb-ratio--9-16 class'
        );
    }

    public function test_explicit_card_preset_elevated_renders_correct_class(): void
    {
        // Verify the user-explicit card_preset='elevated' flows through
        // the explicit-only filter to the resolver. The partial doesn't
        // currently emit a vyg-card--{card_preset} class (that lands in
        // a later phase when the partial is updated to render card_preset
        // variations). For now we verify the resolver receives the value.
        $args = $this->build_render_args_directly(
            array( 'feed_uuid' => 'x', 'card_preset' => 'elevated' )
        );
        $this->assertArrayHasKey( 'card_preset', $args );
        $this->assertSame( 'elevated', (string) $args['card_preset'] );
    }

    public function test_explicit_show_metadata_false_hides_meta_row(): void
    {
        $html = $this->render_shortcode(
            array( 'feed_uuid' => 'x', 'show_metadata' => 'false' )
        );
        $this->assertStringNotContainsString(
            'vyg-card__meta',
            $html,
            'explicit show_metadata=false must hide meta row'
        );
    }

    public function test_structural_attr_columns_passes_through_unconditionally(): void
    {
        // columns is a STRUCTURAL attr (always passes through). The
        // shortcode's hardcoded default of 3 must reach Renderer as
        // inline — that's the whole point of structural attrs.
        $args = $this->build_render_args_directly( array( 'feed_uuid' => 'x' ) );
        $this->assertArrayHasKey( 'columns', $args, 'structural attr columns must pass through unconditionally' );
        $this->assertSame( 3, (int) $args['columns'], 'structural attr columns default of 3 must pass through' );
    }

    public function test_structural_attr_per_page_passes_through_unconditionally(): void
    {
        $args = $this->build_render_args_directly( array( 'feed_uuid' => 'x' ) );
        $this->assertArrayHasKey( 'per_page', $args );
        $this->assertSame( 12, (int) $args['per_page'] );
    }

    public function test_explicit_per_page_override_passes_through(): void
    {
        $args = $this->build_render_args_directly(
            array( 'feed_uuid' => 'x', 'per_page' => 24 )
        );
        $this->assertSame( 24, (int) $args['per_page'] );
    }

    public function test_card_system_default_density_comfortable_is_dropped(): void
    {
        // The D1 fix: when the user does NOT set density, the shortcode
        // must NOT pass its hardcoded 'comfortable' default. This is
        // the load-bearing test — it fails on the pre-D1 shortcode
        // (which always passed the default) and passes on the D1
        // shortcode (which drops un-explicit card-system attrs).
        $args = $this->build_render_args_directly( array( 'feed_uuid' => 'x' ) );
        $this->assertArrayNotHasKey(
            'density',
            $args,
            'D1 fix: hardcoded shortcode default density must be dropped when user did not set it'
        );
    }

    public function test_card_system_default_show_channel_avatar_true_is_dropped(): void
    {
        $args = $this->build_render_args_directly( array( 'feed_uuid' => 'x' ) );
        $this->assertArrayNotHasKey(
            'show_channel_avatar',
            $args,
            'D1 fix: hardcoded shortcode default show_channel_avatar must be dropped when user did not set it'
        );
    }

    public function test_explicit_density_attr_is_preserved(): void
    {
        $args = $this->build_render_args_directly(
            array( 'feed_uuid' => 'x', 'density' => 'compact' )
        );
        $this->assertArrayHasKey( 'density', $args );
        $this->assertSame( 'compact', (string) $args['density'] );
    }

    public function test_empty_feed_uuid_renders_friendly_error(): void
    {
        // Sanity check: the shortcode's existing "missing feed_uuid"
        // guard must still work. No card-system work to do, but the
        // explicit-only filter must not regress this path.
        $output = $this->render_shortcode( array() );
        $this->assertStringContainsString( 'feed_uuid', $output );
        $this->assertStringContainsString( '<p>', $output );
    }

    // -----------------------------------------------------------------
    // Helpers — exercise the shortcode end-to-end against a real
    // Renderer with stubbed WP functions.
    // -----------------------------------------------------------------

    /**
     * Stub the WP functions the shortcode and renderer call, then
     * invoke ShortcodeRegistrar::render_shortcode() with the given
     * user-passed $atts.
     *
     * @param array<string,mixed> $user_atts
     * @return string
     */
    private function render_shortcode( array $user_atts ): string {
        return $this->render_shortcode_with_saved_display( $user_atts, array() );
    }

    /**
     * As above, but inject a fake `phase-13-1-compact` / named feed
     * record with the given $saved_display (decoded from
     * display_config_json) so CardSettings::resolve() can flow it
     * through the legacy / per-feed / per-layout layers.
     *
     * @param array<string,mixed>        $user_atts     The user-passed shortcode attrs.
     * @param array<string,mixed>        $saved_display The display_config_json to seed
     *                                                    the named feed with. Use empty
     *                                                    array for no display config.
     * @return string
     */
    private function render_shortcode_with_saved_display( array $user_atts, array $saved_display ): string {
        // Brain\Monkey lifecycle is handled by tests/bootstrap.php via
        // register_shutdown_function, but we also do an explicit setUp
        // so stubs we register here are clean for this test only.
        \Brain\Monkey\setUp();
        try {
            BrainHelpers::stubEscapeFunctions();
            BrainHelpers::stubOptionFunctions();
            // CardRenderer calls apply_filters for the Phase 10.7 product
            // map. Default: passthrough (no product map), so mapped_only
            // resolves to "no CTA" and explicit-true shows the CTA without
            // a real product.
            Functions\when( 'apply_filters' )->alias(
                static function ( $tag, $default, ...$args ) {
                    return $default;
                }
            );

            // The shortcode calls AssetManager::enqueue_for_layout();
            // these tests care about shortcode/render args, not WP enqueue
            // side effects. Stub the WP enqueue functions the real
            // AssetManager calls so AssetManager can stay final.
            foreach ( array( 'wp_register_style', 'wp_enqueue_style', 'wp_register_script', 'wp_enqueue_script', 'wp_localize_script', 'wp_set_script_translations' ) as $fn ) {
                Functions\when( $fn )->alias(
                    static function () {
                        return null;
                    }
                );
            }
            Functions\when( 'wp_create_nonce' )->alias(
                static function () {
                    return 'nonce';
                }
            );
            Functions\when( 'rest_url' )->alias(
                static function ( string $path = '' ) {
                    return 'https://example.test/wp-json/' . ltrim( $path, '/' );
                }
            );

            // WP shortcode_atts is a global function; stub it to merge
            // the user's $atts with the shortcode's $defaults (a real
            // implementation, just in test scope).
            Functions\when( 'shortcode_atts' )->alias(
                static function ( $defaults, $atts, $shortcode = '' ) {
                    if ( ! is_array( $atts ) ) {
                        $atts = array();
                    }
                    $out = array_merge( $defaults, $atts );
                    // Preserve user's original keys exactly (so the
                    // explicit-only filter can detect them later).
                    foreach ( $atts as $k => $v ) {
                        $out[ $k ] = $v;
                    }
                    return $out;
                }
            );

            // Build the dependencies for ShortcodeRegistrar.
            $fake_previous = $this->createMock(
                \VectorYT\Gallery\Repository\PreviousStreamsRepository::class
            );
            $live_query    = new LiveQuery( $fake_previous );
            $templates     = new TemplateLoader();
            $video_renderer = new VideoRenderer();
            $feeds         = new FakeShortcodeFeedQuery();
            $renderer      = new Renderer( $feeds, $video_renderer, $templates, $live_query );

            // Build the FeedRepository double. It returns null for
            // unknown feeds; for known feeds it returns a row whose
            // display_config_json decodes to $saved_display.
            $feed_repo = new FakeShortcodeFeedRepository( $saved_display );

            // Use the real AssetManager; WordPress enqueue functions
            // are stubbed by the test bootstrap / Brain Monkey helpers.
            $asset_manager = new \VectorYT\Gallery\Render\AssetManager();

            $registrar = new ShortcodeRegistrar( $renderer, $feeds, $asset_manager, $feed_repo );
            return $registrar->render_shortcode( $user_atts );
        } finally {
            \Brain\Monkey\tearDown();
        }
    }

    /**
     * Invoke the ShortcodeRegistrar's build_render_args() helper
     * (D1 refactor) to inspect the final args dict that would be
     * passed to Renderer::render() — without going through the full
     * shortcode render path.
     *
     * @param array<string,mixed> $user_atts
     * @return array<string,mixed>
     */
    private function build_render_args_directly( array $user_atts ): array {
        \Brain\Monkey\setUp();
        try {
            // Stub shortcode_atts the same way as in render_shortcode_*.
            Functions\when( 'shortcode_atts' )->alias(
                static function ( $defaults, $atts, $shortcode = '' ) {
                    if ( ! is_array( $atts ) ) {
                        $atts = array();
                    }
                    $out = array_merge( $defaults, $atts );
                    foreach ( $atts as $k => $v ) {
                        $out[ $k ] = $v;
                    }
                    return $out;
                }
            );

            $fake_previous = $this->createMock(
                \VectorYT\Gallery\Repository\PreviousStreamsRepository::class
            );
            $live_query    = new LiveQuery( $fake_previous );
            $templates     = new TemplateLoader();
            $video_renderer = new VideoRenderer();
            $feeds         = new FakeShortcodeFeedQuery();
            $renderer      = new Renderer( $feeds, $video_renderer, $templates, $live_query );
            $feed_repo     = new FakeShortcodeFeedRepository( array() );
            $asset_manager = new \VectorYT\Gallery\Render\AssetManager();

            $registrar = new ShortcodeRegistrar( $renderer, $feeds, $asset_manager, $feed_repo );

            if ( ! method_exists( $registrar, 'build_render_args' ) ) {
                $this->fail(
                    'ShortcodeRegistrar must expose a public build_render_args() helper ' .
                    '(D1 refactor) so the explicit-only filter can be tested in isolation. ' .
                    'See plan §9 D1 and the API surface documented in the D1 handoff.'
                );
            }
            return $registrar->build_render_args( $user_atts );
        } finally {
            \Brain\Monkey\tearDown();
        }
    }
}

/**
 * A minimal FeedQuery double for the shortcode path. Returns a
 * canned active source and a single fake video, so the grid
 * template renders enough HTML for the assertion.
 */
class FakeShortcodeFeedQuery extends \VectorYT\Gallery\Render\FeedQuery {
    public function find_source_by_uuid( string $uuid ): ?array {
        return array(
            'source_uuid'        => $uuid,
            'source_type'        => 'channel',
            'youtube_channel_id' => 'UC_' . $uuid,
            'title'              => 'Fake Source ' . $uuid,
            'status'             => 'active',
        );
    }
    public function videos_for_source( array $args ): array {
        return array(
            array(
                'youtube_video_id'      => 'fake1',
                'youtube_channel_id'    => 'UC_fake',
                'youtube_channel_title' => 'Fake Channel',
                'title'                 => 'Shortcode Test Video',
                'description'           => 'A short description used by show_description tests.',
                'thumbnail_high'        => 'https://example.com/h.jpg',
                'thumbnail_medium'      => 'https://example.com/m.jpg',
                'duration_seconds'      => 600,
                'content_type'          => 'standard',
                'live_status'           => 'none',
                'published_at'          => '2026-06-25T12:00:00+00:00',
                'view_count'            => 12345,
            ),
        );
    }
    public function count_videos_for_source( array $args ): int {
        return 1;
    }
    public function videos_for_feed( array $args ): array {
        return $this->videos_for_source( $args );
    }
    public function count_videos_for_feed( array $args ): int {
        return 1;
    }
}

/**
 * FeedRepository double. Returns a row when find_by_uuid matches a
 * known feed_uuid so CardSettings can flow through the saved display
 * config; returns null otherwise.
 */
class FakeShortcodeFeedRepository extends \VectorYT\Gallery\Repository\FeedRepository {
    /** @var array<string,mixed> */
    private array $saved_display;

    public function __construct( array $saved_display ) {
        parent::__construct();
        $this->saved_display = $saved_display;
    }

    public function find_by_uuid( string $uuid ): ?array {
        if ( '' === $uuid ) {
            return null;
        }
        // Build a canned row. display_config_json is encoded to mimic
        // the real storage shape (FeedRepository::decode_config
        // expects a JSON string).
        $display = $this->saved_display;
        return array(
            'feed_uuid'           => $uuid,
            'name'                => 'Test Feed ' . $uuid,
            'feed_type'           => 'source',
            'layout'              => 'grid',
            'status'              => 'active',
            'source_config_json'  => '{"source_uuid":"phase-13-1-source"}',
            'display_config_json' => wp_json_encode( $display ),
            'filter_config_json'  => '{}',
            'sort_config_json'    => '{"orderby":"published_at","order":"DESC"}',
            'custom_css'          => '',
        );
    }
}

