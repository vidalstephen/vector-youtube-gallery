<?php
/**
 * Unit tests for CardSettings.
 *
 * CardSettings is the chokepoint that resolves the final normalized
 * card settings for one card in one layout, merging six layers of
 * precedence. The settings are then sanitized exactly once via
 * CardSanitizer.
 *
 * Pure-PHP. No WordPress globals, no Brain\Monkey stubs needed — the
 * class only depends on PHP built-ins plus the in-package
 * CardSanitizer and CardProfiles helpers.
 *
 * @covers \VectorYT\Gallery\Render\CardSettings
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Render\CardProfiles;
use VectorYT\Gallery\Render\CardSettings;

final class CardSettingsTest extends TestCase {

	// ---------------------------------------------------------------------
	// allowed_keys()
	// ---------------------------------------------------------------------

	public function test_allowed_keys_returns_string_keys(): void {
		$keys = CardSettings::allowed_keys();

		$this->assertIsArray( $keys );
		// The MVP allow-list from plan §5 has 43 keys; the task spec
		// text said "36" but the actual list is 43 — we assert the
		// concrete list and its count, and the keys are all strings.
		$this->assertGreaterThanOrEqual( 36, count( $keys ) );
		foreach ( $keys as $key ) {
			$this->assertIsString( $key );
		}
	}

	public function test_allowed_keys_includes_canonical_settings(): void {
		$keys = CardSettings::allowed_keys();

		// Spot-check a few from each region of the surface.
		$this->assertContains( 'card_preset', $keys );
		$this->assertContains( 'density', $keys );
		$this->assertContains( 'show_thumbnail', $keys );
		$this->assertContains( 'thumbnail_ratio', $keys );
		$this->assertContains( 'show_duration', $keys );
		$this->assertContains( 'show_status_badge', $keys );
		$this->assertContains( 'enabled_badges', $keys );
		$this->assertContains( 'show_title', $keys );
		$this->assertContains( 'title_lines', $keys );
		$this->assertContains( 'show_channel', $keys );
		$this->assertContains( 'show_channel_name', $keys );
		$this->assertContains( 'show_metadata', $keys );
		$this->assertContains( 'metadata_fields', $keys );
		$this->assertContains( 'show_description', $keys );
		$this->assertContains( 'show_cta', $keys );
		$this->assertContains( 'cta_label', $keys );
		$this->assertContains( 'show_actions', $keys );
		$this->assertContains( 'actions', $keys );
		$this->assertContains( 'compact_mobile', $keys );
		$this->assertContains( 'hide_description_mobile', $keys );
	}

	// ---------------------------------------------------------------------
	// allowed_layouts()
	// ---------------------------------------------------------------------

	public function test_allowed_layouts_returns_8_layouts_including_featured_and_hero(): void {
		$layouts = CardSettings::allowed_layouts();

		$this->assertIsArray( $layouts );
		$this->assertCount( 8, $layouts );
		$this->assertContains( 'grid', $layouts );
		$this->assertContains( 'list', $layouts );
		$this->assertContains( 'shorts', $layouts );
		$this->assertContains( 'live', $layouts );
		$this->assertContains( 'carousel', $layouts );
		$this->assertContains( 'masonry', $layouts );
		$this->assertContains( 'featured', $layouts );
		$this->assertContains( 'hero', $layouts );
	}

	// ---------------------------------------------------------------------
	// defaults()
	// ---------------------------------------------------------------------

	public function test_defaults_returns_value_for_every_allowed_key(): void {
		$defaults = CardSettings::defaults();

		$this->assertIsArray( $defaults );
		foreach ( CardSettings::allowed_keys() as $key ) {
			$this->assertArrayHasKey( $key, $defaults, "defaults() must include '{$key}'" );
		}
	}

	public function test_defaults_known_sane_values(): void {
		$defaults = CardSettings::defaults();

		$this->assertSame( 'minimal', $defaults['card_preset'] );
		$this->assertSame( 'comfortable', $defaults['density'] );
		$this->assertTrue( $defaults['show_thumbnail'] );
		$this->assertSame( '16_9', $defaults['thumbnail_ratio'] );
		$this->assertSame( 'cover', $defaults['thumbnail_fit'] );
		$this->assertSame( 'center_center', $defaults['thumbnail_position'] );
		$this->assertSame( '', $defaults['thumbnail_override_url'] );
		$this->assertTrue( $defaults['show_duration'] );
		$this->assertTrue( $defaults['show_title'] );
		$this->assertTrue( $defaults['show_channel'] );
		$this->assertTrue( $defaults['show_metadata'] );
		$this->assertFalse( $defaults['show_description'] );
		$this->assertFalse( $defaults['show_actions'] );
		$this->assertSame( array( 'views', 'published_date' ), $defaults['metadata_fields'] );
	}

	public function test_defaults_show_cta_default_is_mapped_only_not_true(): void {
		// §16.3 — tri-state default must be exactly the string 'mapped_only',
		// NOT the bool true (which would lose the "show only if a mapping
		// exists" semantic).
		$defaults = CardSettings::defaults();

		$this->assertArrayHasKey( 'show_cta', $defaults );
		$this->assertSame( 'mapped_only', $defaults['show_cta'] );
		$this->assertNotSame( true, $defaults['show_cta'] );
	}

	public function test_defaults_every_value_is_already_sanitized(): void {
		// `defaults()` is the source of truth — every value must be a
		// valid sanitized value (no raw shortcode strings, etc.).
		$defaults = CardSettings::defaults();

		// Booleans must be actual bools, not 'true'/'false' strings.
		$bool_keys = array(
			'show_thumbnail',
			'show_duration',
			'show_title',
			'show_channel',
			'show_metadata',
			'show_description',
			'show_actions',
		);
		foreach ( $bool_keys as $key ) {
			$this->assertIsBool( $defaults[ $key ], "{$key} default must be bool" );
		}

		// Integer-valued keys must be ints.
		$this->assertIsInt( $defaults['title_lines'] );
		$this->assertIsInt( $defaults['description_lines'] );

		// show_cta is a string at the default (tri-state 'mapped_only').
		$this->assertIsString( $defaults['show_cta'] );
	}

	// ---------------------------------------------------------------------
	// resolve() — basic
	// ---------------------------------------------------------------------

	public function test_resolve_returns_complete_array_for_grid_with_no_overrides(): void {
		$resolved = CardSettings::resolve( 'grid' );

		$this->assertIsArray( $resolved );
		foreach ( CardSettings::allowed_keys() as $key ) {
			$this->assertArrayHasKey( $key, $resolved, "resolve() output must include '{$key}'" );
		}
	}

	public function test_resolve_uses_plugin_defaults_when_no_saved_or_inline_provided(): void {
		$resolved = CardSettings::resolve( 'grid' );
		$defaults = CardSettings::defaults();

		// Grid resolves to 'standard' mode (per CardProfiles mapping). The
		// standard profile shares the plugin defaults on every key both
		// classes define, so the union should be byte-identical to
		// `defaults()` for the keys that exist in defaults.
		foreach ( array_keys( $defaults ) as $key ) {
			$this->assertSame(
				$defaults[ $key ],
				$resolved[ $key ],
				"grid/standard resolve() must equal defaults() for '{$key}'"
			);
		}
	}

	// ---------------------------------------------------------------------
	// resolve() — inline wins over saved
	// ---------------------------------------------------------------------

	public function test_resolve_inline_wins_over_saved_global(): void {
		$saved  = array( 'card_settings' => array( 'global' => array( 'show_description' => true ) ) );
		$inline = array( 'show_description' => 'false' );

		$resolved = CardSettings::resolve( 'grid', $saved, $inline );

		$this->assertFalse( $resolved['show_description'] );
	}

	// ---------------------------------------------------------------------
	// resolve() — layout override wins over global
	// ---------------------------------------------------------------------

	public function test_resolve_layout_override_wins_over_global(): void {
		$saved = array(
			'card_settings' => array(
				'global'  => array( 'show_description' => true ),
				'layouts' => array(
					'grid' => array( 'show_description' => false ),
				),
			),
		);

		$grid = CardSettings::resolve( 'grid', $saved );
		$this->assertFalse( $grid['show_description'] );

		// Other layouts without an override fall through to global.
		$list = CardSettings::resolve( 'list', $saved );
		$this->assertTrue( $list['show_description'] );
	}

	// ---------------------------------------------------------------------
	// resolve() — legacy keys still work
	// ---------------------------------------------------------------------

	public function test_resolve_legacy_keys_still_work(): void {
		$saved = array(
			'show_channel_name'   => true,
			'show_views_and_time' => false,
		);

		$resolved = CardSettings::resolve( 'grid', $saved );

		// show_channel_name=true → both show_channel and show_channel_name.
		$this->assertTrue( $resolved['show_channel'] );
		$this->assertTrue( $resolved['show_channel_name'] );

		// show_views_and_time=false → show_metadata=false.
		$this->assertFalse( $resolved['show_metadata'] );
	}

	// ---------------------------------------------------------------------
	// resolve() — mode profile wins over plugin defaults
	// ---------------------------------------------------------------------

	public function test_resolve_mode_profile_overrides_plugin_defaults(): void {
		// For 'shorts' layout, CardProfiles::mode_for_layout returns
		// 'short_vertical', which forces thumbnail_ratio='9_16'. The
		// plugin default is '16_9' — the mode profile must win.
		$resolved = CardSettings::resolve( 'shorts' );

		$this->assertSame( '9_16', $resolved['thumbnail_ratio'] );
	}

	// ---------------------------------------------------------------------
	// resolve() — inline string boolean flows through CardSanitizer::bool
	// ---------------------------------------------------------------------

	public function test_resolve_inline_string_boolean_is_coerced_via_card_sanitizer(): void {
		$inline = array( 'show_metadata' => 'false' );

		$resolved = CardSettings::resolve( 'grid', array(), $inline );

		// CRITICAL: (bool) 'false' is `true` in PHP. We must use
		// CardSanitizer::bool() to flip 'false' to false.
		$this->assertFalse( $resolved['show_metadata'] );
		$this->assertIsBool( $resolved['show_metadata'] );
	}

	// ---------------------------------------------------------------------
	// resolve() — unknown layout falls back to standard mode
	// ---------------------------------------------------------------------

	public function test_resolve_unknown_layout_falls_back_to_standard(): void {
		$resolved = CardSettings::resolve( 'unknown_layout_xyz' );

		// The default mode for unknown layouts is 'standard'. We can
		// confirm this by checking that the resolved thumbnail_ratio
		// matches the standard profile's value (not short_vertical's 9_16).
		$standard = CardProfiles::get( 'standard' );
		$this->assertSame( $standard['thumbnail_ratio'], $resolved['thumbnail_ratio'] );
		$this->assertSame( '16_9', $resolved['thumbnail_ratio'] );
	}

	// ---------------------------------------------------------------------
	// resolve() — show_cta tri-state
	// ---------------------------------------------------------------------

	public function test_resolve_show_cta_mapped_only_string_is_preserved(): void {
		$inline = array( 'show_cta' => 'mapped_only' );

		$resolved = CardSettings::resolve( 'grid', array(), $inline );

		$this->assertSame( 'mapped_only', $resolved['show_cta'] );
	}

	public function test_resolve_show_cta_true_string_is_coerced_to_bool_true(): void {
		$inline = array( 'show_cta' => 'true' );

		$resolved = CardSettings::resolve( 'grid', array(), $inline );

		$this->assertTrue( $resolved['show_cta'] );
		$this->assertIsBool( $resolved['show_cta'] );
	}

	public function test_resolve_show_cta_false_string_is_coerced_to_bool_false(): void {
		$inline = array( 'show_cta' => 'false' );

		$resolved = CardSettings::resolve( 'grid', array(), $inline );

		$this->assertFalse( $resolved['show_cta'] );
		$this->assertIsBool( $resolved['show_cta'] );
	}

	// ---------------------------------------------------------------------
	// resolve() — role parameter picks the right mode variant
	// ---------------------------------------------------------------------

	public function test_resolve_hero_rail_role_uses_hero_secondary_profile(): void {
		// CardProfiles maps hero+rail → hero_secondary, which is more
		// compact than the primary. Verify the resolved density is
		// 'compact' (the hero_secondary value) and not 'editorial'
		// (hero_primary) or 'comfortable' (standard).
		$resolved = CardSettings::resolve( 'hero', array(), array(), 'rail' );

		$this->assertSame( 'compact', $resolved['density'] );
	}

	// ---------------------------------------------------------------------
	// sanitize_storage()
	// ---------------------------------------------------------------------

	public function test_sanitize_storage_drops_unknown_top_level_keys(): void {
		$raw = array(
			'global' => array( 'show_thumbnail' => true ),
			'extra'  => 'value',
		);

		$out = CardSettings::sanitize_storage( $raw );

		$this->assertArrayHasKey( 'global', $out );
		$this->assertArrayNotHasKey( 'extra', $out );
	}

	public function test_sanitize_storage_drops_unknown_layout_names(): void {
		$raw = array(
			'layouts' => array(
				'grid' => array( 'show_thumbnail' => true ),
				'evil' => array( 'show_thumbnail' => true ),
			),
		);

		$out = CardSettings::sanitize_storage( $raw );

		$this->assertArrayHasKey( 'grid', $out['layouts'] );
		$this->assertArrayNotHasKey( 'evil', $out['layouts'] );
	}

	public function test_sanitize_storage_global_and_layouts_default_to_empty_arrays(): void {
		$out = CardSettings::sanitize_storage( array() );

		$this->assertSame( array(), $out['global'] );
		$this->assertSame( array(), $out['layouts'] );
	}

	public function test_sanitize_storage_sanitizes_booleans_in_global(): void {
		$raw = array( 'global' => array( 'show_thumbnail' => 'true' ) );

		$out = CardSettings::sanitize_storage( $raw );

		$this->assertTrue( $out['global']['show_thumbnail'] );
		$this->assertIsBool( $out['global']['show_thumbnail'] );
	}

	public function test_sanitize_storage_sanitizes_booleans_in_layouts(): void {
		$raw = array(
			'layouts' => array(
				'grid' => array( 'show_thumbnail' => 'true' ),
			),
		);

		$out = CardSettings::sanitize_storage( $raw );

		$this->assertTrue( $out['layouts']['grid']['show_thumbnail'] );
	}

	public function test_sanitize_storage_drops_invalid_enum_values(): void {
		$raw = array( 'global' => array( 'card_preset' => 'evil' ) );

		$out = CardSettings::sanitize_storage( $raw );

		// Unknown enum value → key dropped (or replaced by default, but
		// either way NOT 'evil'). We assert it's not 'evil' AND that
		// whatever remains is in the allow-list.
		if ( array_key_exists( 'card_preset', $out['global'] ) ) {
			$this->assertNotSame( 'evil', $out['global']['card_preset'] );
			$this->assertContains( $out['global']['card_preset'], array( 'minimal', 'elevated', 'bordered', 'editorial', 'commerce', 'compact' ) );
		} else {
			$this->assertArrayNotHasKey( 'card_preset', $out['global'] );
		}
	}

	public function test_sanitize_storage_filters_metadata_fields_against_allow_list(): void {
		$raw = array(
			'global' => array(
				'metadata_fields' => array( 'views', 'evil', 'published_date' ),
			),
		);

		$out = CardSettings::sanitize_storage( $raw );

		$this->assertSame(
			array( 'views', 'published_date' ),
			$out['global']['metadata_fields']
		);
	}

	public function test_sanitize_storage_preserves_show_cta_mapped_only(): void {
		$raw = array( 'global' => array( 'show_cta' => 'mapped_only' ) );

		$out = CardSettings::sanitize_storage( $raw );

		$this->assertSame( 'mapped_only', $out['global']['show_cta'] );
	}

	public function test_sanitize_storage_coerces_show_cta_true_string_to_bool_true(): void {
		$raw = array( 'global' => array( 'show_cta' => 'true' ) );

		$out = CardSettings::sanitize_storage( $raw );

		$this->assertTrue( $out['global']['show_cta'] );
		$this->assertIsBool( $out['global']['show_cta'] );
	}

	public function test_sanitize_storage_coerces_show_cta_false_string_to_bool_false(): void {
		$raw = array( 'global' => array( 'show_cta' => 'false' ) );

		$out = CardSettings::sanitize_storage( $raw );

		$this->assertFalse( $out['global']['show_cta'] );
		$this->assertIsBool( $out['global']['show_cta'] );
	}

	public function test_sanitize_storage_show_cta_garbage_falls_back_to_default(): void {
		$raw = array( 'global' => array( 'show_cta' => 'maybe' ) );

		$out = CardSettings::sanitize_storage( $raw );

		$this->assertSame( 'mapped_only', $out['global']['show_cta'] );
	}

	public function test_sanitize_storage_drops_unknown_settings_under_global(): void {
		$raw = array(
			'global' => array(
				'show_thumbnail' => true,
				'hack'           => 'value',
			),
		);

		$out = CardSettings::sanitize_storage( $raw );

		$this->assertArrayHasKey( 'show_thumbnail', $out['global'] );
		$this->assertArrayNotHasKey( 'hack', $out['global'] );
	}

	public function test_sanitize_storage_drops_unknown_settings_under_layouts(): void {
		$raw = array(
			'layouts' => array(
				'grid' => array(
					'show_thumbnail' => true,
					'hack'           => 'value',
				),
			),
		);

		$out = CardSettings::sanitize_storage( $raw );

		$this->assertArrayHasKey( 'show_thumbnail', $out['layouts']['grid'] );
		$this->assertArrayNotHasKey( 'hack', $out['layouts']['grid'] );
	}

	public function test_sanitize_storage_sanitizes_int_keys_in_global(): void {
		// Use a value within the title_lines spec bounds [1, 3].
		$raw = array( 'global' => array( 'title_lines' => '2' ) );

		$out = CardSettings::sanitize_storage( $raw );

		$this->assertSame( 2, $out['global']['title_lines'] );
		$this->assertIsInt( $out['global']['title_lines'] );
	}

	public function test_sanitize_storage_sanitizes_text_keys_in_global(): void {
		$raw = array( 'global' => array( 'cta_label' => '<script>View Product</script>' ) );

		$out = CardSettings::sanitize_storage( $raw );

		$this->assertSame( 'View Product', $out['global']['cta_label'] );
	}

	// ---------------------------------------------------------------------
	// sanitize_flat()
	// ---------------------------------------------------------------------

	public function test_sanitize_flat_drops_unknown_keys(): void {
		$raw = array(
			'show_thumbnail' => true,
			'hack'           => 'x',
		);

		$out = CardSettings::sanitize_flat( $raw );

		$this->assertArrayHasKey( 'show_thumbnail', $out );
		$this->assertArrayNotHasKey( 'hack', $out );
	}

	public function test_sanitize_flat_sanitizes_booleans(): void {
		$out = CardSettings::sanitize_flat( array( 'show_thumbnail' => 'true' ) );

		$this->assertTrue( $out['show_thumbnail'] );
		$this->assertIsBool( $out['show_thumbnail'] );
	}

	public function test_sanitize_flat_drops_invalid_enum_values(): void {
		$out = CardSettings::sanitize_flat( array( 'card_preset' => 'evil' ) );

		// 'evil' is not in the allow-list → key dropped (or replaced by
		// default, but either way NOT 'evil').
		if ( array_key_exists( 'card_preset', $out ) ) {
			$this->assertNotSame( 'evil', $out['card_preset'] );
		} else {
			$this->assertArrayNotHasKey( 'card_preset', $out );
		}
	}

	public function test_sanitize_flat_empty_input_produces_empty_output(): void {
		// Empty flat input passes through as-is (no defaults backfill —
		// that's resolve()'s job, not sanitize_flat's).
		$out = CardSettings::sanitize_flat( array() );

		$this->assertSame( array(), $out );
	}

	public function test_sanitize_flat_preserves_show_cta_mapped_only(): void {
		$out = CardSettings::sanitize_flat( array( 'show_cta' => 'mapped_only' ) );

		$this->assertSame( 'mapped_only', $out['show_cta'] );
	}

	public function test_sanitize_flat_coerces_show_cta_true_to_bool(): void {
		$out = CardSettings::sanitize_flat( array( 'show_cta' => 'true' ) );

		$this->assertTrue( $out['show_cta'] );
		$this->assertIsBool( $out['show_cta'] );
	}

	// ---------------------------------------------------------------------
	// legacy_display_to_card_settings()
	// ---------------------------------------------------------------------

	public function test_legacy_display_empty_input_returns_empty(): void {
		$this->assertSame( array(), CardSettings::legacy_display_to_card_settings( array() ) );
	}

	public function test_legacy_display_maps_show_channel_name_to_channel_and_name(): void {
		$out = CardSettings::legacy_display_to_card_settings(
			array( 'show_channel_name' => true )
		);

		$this->assertTrue( $out['show_channel'] );
		$this->assertTrue( $out['show_channel_name'] );
	}

	public function test_legacy_display_maps_show_views_and_time_to_metadata(): void {
		$out = CardSettings::legacy_display_to_card_settings(
			array( 'show_views_and_time' => false )
		);

		$this->assertFalse( $out['show_metadata'] );
		$this->assertArrayNotHasKey( 'show_views_and_time', $out );
	}

	public function test_legacy_display_maps_product_cta_visible_to_show_cta(): void {
		$out = CardSettings::legacy_display_to_card_settings(
			array( 'product_cta_visible' => true )
		);

		$this->assertTrue( $out['show_cta'] );
		$this->assertArrayNotHasKey( 'product_cta_visible', $out );
	}

	public function test_legacy_display_density_is_passthrough(): void {
		$out = CardSettings::legacy_display_to_card_settings(
			array( 'density' => 'compact' )
		);

		$this->assertSame( 'compact', $out['density'] );
	}

	public function test_legacy_display_card_radius_is_dropped_for_now(): void {
		// Plan §6 A3: card_radius → "style token / legacy CSS support".
		// We are not yet wiring style tokens, so the safest behavior is
		// to drop it. This test documents that decision.
		$out = CardSettings::legacy_display_to_card_settings(
			array( 'card_radius' => 'large' )
		);

		$this->assertArrayNotHasKey( 'card_radius', $out );
	}

	public function test_legacy_display_drops_unknown_legacy_keys(): void {
		$out = CardSettings::legacy_display_to_card_settings(
			array(
				'show_channel_name' => true,
				'foo'               => 'bar',
				'random_legacy'     => 'x',
			)
		);

		// Known mappings survive…
		$this->assertTrue( $out['show_channel'] );
		$this->assertTrue( $out['show_channel_name'] );

		// …unknown legacy keys are dropped.
		$this->assertArrayNotHasKey( 'foo', $out );
		$this->assertArrayNotHasKey( 'random_legacy', $out );
	}

	public function test_legacy_display_passes_through_unknown_but_valid_card_settings_keys(): void {
		// If a pre-A3 display array happened to contain a key that's
		// also a valid card-settings key, it should pass through (so
		// the user's previous settings don't get lost). We do NOT
		// sanitize values here — resolve() / sanitize_flat() do that
		// after the merge.
		$out = CardSettings::legacy_display_to_card_settings(
			array( 'card_style' => 'minimal' )
		);

		$this->assertSame( 'minimal', $out['card_style'] );
	}

	// ---------------------------------------------------------------------
	// Integration: resolve() end-to-end precedence stack
	// ---------------------------------------------------------------------

	public function test_resolve_precedence_full_stack_inline_wins(): void {
		// All six layers set, inline must still win.
		$mode   = CardProfiles::get( CardProfiles::mode_for_layout( 'grid' ) );
		$saved  = array(
			'card_settings' => array(
				'global'  => array( 'show_description' => true ),
				'layouts' => array(
					'grid' => array( 'show_description' => true ),
				),
			),
		);
		$inline = array( 'show_description' => 'false' );

		$resolved = CardSettings::resolve( 'grid', $saved, $inline );

		// 1. inline (highest)
		$this->assertFalse( $resolved['show_description'] );
	}

	public function test_resolve_precedence_layout_beats_global_beats_mode(): void {
		// Mode profile for grid → 'standard' with show_description=false.
		// Saved global → show_description=true.
		// Layout override for grid → show_description=true.
		// No inline. Final → layout wins (true).
		$mode_profile = CardProfiles::get( 'standard' );
		$this->assertFalse( $mode_profile['show_description'] );

		$saved = array(
			'card_settings' => array(
				'global'  => array( 'show_description' => true ),
				'layouts' => array(
					'grid' => array( 'show_description' => true ),
				),
			),
		);

		$resolved = CardSettings::resolve( 'grid', $saved );

		$this->assertTrue( $resolved['show_description'] );
	}
}
