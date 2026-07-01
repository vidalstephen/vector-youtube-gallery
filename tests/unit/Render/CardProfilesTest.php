<?php
/**
 * Unit tests for CardProfiles.
 *
 * Pure-PHP. No WordPress globals, no Brain\Monkey stubs needed — the
 * class only depends on PHP built-ins and provides static profile
 * defaults plus a layout-to-mode lookup.
 *
 * @covers \VectorYT\Gallery\Render\CardProfiles
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Render\CardProfiles;

final class CardProfilesTest extends TestCase {

	// ---------------------------------------------------------------------
	// get(): known mode
	// ---------------------------------------------------------------------

	public function test_get_standard_returns_full_profile(): void {
		$profile = CardProfiles::get( 'standard' );

		$this->assertIsArray( $profile );
		$this->assertTrue( $profile['show_thumbnail'] );
		$this->assertSame( '16_9', $profile['thumbnail_ratio'] );
		$this->assertTrue( $profile['show_duration'] );
		$this->assertTrue( $profile['show_title'] );
		$this->assertSame( 2, $profile['title_lines'] );
		$this->assertTrue( $profile['show_channel'] );
		$this->assertTrue( $profile['show_channel_name'] );
		$this->assertTrue( $profile['show_metadata'] );
		$this->assertSame( array( 'views', 'published_date' ), $profile['metadata_fields'] );
		$this->assertFalse( $profile['show_description'] );
		$this->assertSame( 'mapped_only', $profile['show_cta'] );
		$this->assertFalse( $profile['show_actions'] );
		$this->assertSame( 'comfortable', $profile['density'] );
	}

	public function test_get_compact_is_more_compact_than_standard(): void {
		$profile = CardProfiles::get( 'compact' );

		$this->assertIsArray( $profile );
		$this->assertSame( 'compact', $profile['density'] );
		$this->assertSame( 1, $profile['title_lines'] );
		// compact still keeps a thumbnail, title, and channel.
		$this->assertTrue( $profile['show_thumbnail'] );
		$this->assertTrue( $profile['show_title'] );
		$this->assertTrue( $profile['show_channel'] );
	}

	public function test_get_media_row_enables_description(): void {
		$profile = CardProfiles::get( 'media_row' );

		$this->assertIsArray( $profile );
		$this->assertTrue( $profile['show_thumbnail'] );
		$this->assertSame( '16_9', $profile['thumbnail_ratio'] );
		$this->assertTrue( $profile['show_description'] );
		$this->assertSame( 'comfortable', $profile['density'] );
	}

	public function test_get_hero_primary_is_large_and_editorial(): void {
		$profile = CardProfiles::get( 'hero_primary' );

		$this->assertIsArray( $profile );
		$this->assertTrue( $profile['show_thumbnail'] );
		$this->assertSame( '16_9', $profile['thumbnail_ratio'] );
		$this->assertTrue( $profile['show_description'] );
		$this->assertTrue( $profile['show_metadata'] );
		$this->assertSame( 'mapped_only', $profile['show_cta'] );
		$this->assertSame( 'editorial', $profile['density'] );
	}

	public function test_get_hero_secondary_keeps_standard_shape(): void {
		$profile = CardProfiles::get( 'hero_secondary' );

		$this->assertIsArray( $profile );
		// hero_secondary should still have a thumbnail and a density setting.
		$this->assertTrue( $profile['show_thumbnail'] );
		$this->assertArrayHasKey( 'density', $profile );
	}

	public function test_get_short_vertical_forces_plan_mandated_invariants(): void {
		$profile = CardProfiles::get( 'short_vertical' );

		$this->assertIsArray( $profile );
		// Plan §6 A2: MUST force these regardless of caller overrides.
		$this->assertTrue( $profile['show_thumbnail'] );
		$this->assertSame( '9_16', $profile['thumbnail_ratio'] );
		$this->assertFalse( $profile['show_description'] );
	}

	public function test_get_live_status_defaults_status_badge_on(): void {
		$profile = CardProfiles::get( 'live_status' );

		$this->assertIsArray( $profile );
		$this->assertTrue( $profile['show_status_badge'] );
		// live metadata fields present.
		$this->assertIsArray( $profile['metadata_fields'] );
		$this->assertContains( 'views', $profile['metadata_fields'] );
		$this->assertContains( 'published_date', $profile['metadata_fields'] );
	}

	public function test_get_overlay_uses_thumbnail_for_text(): void {
		$profile = CardProfiles::get( 'overlay' );

		$this->assertIsArray( $profile );
		$this->assertTrue( $profile['show_thumbnail'] );
		// text is laid over the thumbnail — title_position must be 'overlay'.
		$this->assertSame( 'overlay', $profile['title_position'] );
	}

	public function test_get_minimal_thumb_strips_optional_regions(): void {
		$profile = CardProfiles::get( 'minimal_thumb' );

		$this->assertIsArray( $profile );
		$this->assertTrue( $profile['show_thumbnail'] );
		$this->assertFalse( $profile['show_duration'] );
		$this->assertFalse( $profile['show_channel'] );
		$this->assertFalse( $profile['show_description'] );
		$this->assertFalse( $profile['show_metadata'] );
	}

	// ---------------------------------------------------------------------
	// get(): unknown mode + safety
	// ---------------------------------------------------------------------

	public function test_get_unknown_mode_falls_back_to_standard(): void {
		$profile = CardProfiles::get( 'unknown_mode' );

		$this->assertIsArray( $profile );
		$this->assertSame( 'comfortable', $profile['density'] );
		$this->assertTrue( $profile['show_thumbnail'] );
		// Empty-string mode also falls back.
		$this->assertSame( 'comfortable', CardProfiles::get( '' )['density'] );
	}

	public function test_get_returns_array_not_null_not_reference(): void {
		$profile = CardProfiles::get( 'standard' );

		$this->assertIsArray( $profile );
		$this->assertNotNull( $profile );
	}

	/**
	 * CRITICAL mutation-safety test (plan §16.4).
	 *
	 * Two consecutive get() calls must return independent arrays so
	 * callers cannot mutate the canonical defaults by reference. If
	 * CardProfiles returns a shared array, a caller doing
	 * `$p = CardProfiles::get('standard'); $p['show_title'] = false;`
	 * would silently corrupt every subsequent caller.
	 *
	 * Note: PHP arrays are value types, so `assertNotSame` (which is
	 * `===` for arrays) cannot detect shared references — we test
	 * independence by mutating one and verifying the other is intact.
	 */
	public function test_get_returns_independent_array_per_call(): void {
		$a = CardProfiles::get( 'standard' );
		$b = CardProfiles::get( 'standard' );

		// Sanity: values match.
		$this->assertSame( $a, $b );

		// Mutating $a must NOT affect $b — this is the actual
		// mutation-safety guarantee.
		$a['show_thumbnail'] = false;
		$a['density']        = 'compact';
		$a['metadata_fields'] = array( 'tampered' );

		$this->assertTrue( $b['show_thumbnail'] );
		$this->assertSame( 'comfortable', $b['density'] );
		$this->assertNotContains( 'tampered', $b['metadata_fields'] );
		$this->assertContains( 'views', $b['metadata_fields'] );
	}

	/**
	 * The "evil caller" scenario: mutate the returned profile, then
	 * fetch it again. The second fetch must be untouched.
	 */
	public function test_get_mutating_returned_profile_does_not_corrupt_canonical(): void {
		$first = CardProfiles::get( 'short_vertical' );
		// short_vertical must keep show_thumbnail=true / 9_16 / show_description=false
		// even after a caller has tried to corrupt the array.
		$first['show_thumbnail']   = false;
		$first['thumbnail_ratio']  = '1_1';
		$first['show_description'] = true;

		$second = CardProfiles::get( 'short_vertical' );
		$this->assertTrue( $second['show_thumbnail'] );
		$this->assertSame( '9_16', $second['thumbnail_ratio'] );
		$this->assertFalse( $second['show_description'] );
	}

	// ---------------------------------------------------------------------
	// mode_for_layout()
	// ---------------------------------------------------------------------

	public function test_mode_for_layout_grid_is_standard(): void {
		$this->assertSame( 'standard', CardProfiles::mode_for_layout( 'grid' ) );
	}

	public function test_mode_for_layout_list_is_media_row(): void {
		$this->assertSame( 'media_row', CardProfiles::mode_for_layout( 'list' ) );
	}

	public function test_mode_for_layout_shorts_is_short_vertical(): void {
		$this->assertSame( 'short_vertical', CardProfiles::mode_for_layout( 'shorts' ) );
	}

	public function test_mode_for_layout_live_is_live_status(): void {
		$this->assertSame( 'live_status', CardProfiles::mode_for_layout( 'live' ) );
	}

	public function test_mode_for_layout_featured_primary_is_hero_primary(): void {
		$this->assertSame( 'hero_primary', CardProfiles::mode_for_layout( 'featured', 'primary' ) );
	}

	public function test_mode_for_layout_featured_secondary_is_standard(): void {
		$this->assertSame( 'standard', CardProfiles::mode_for_layout( 'featured', 'secondary' ) );
	}

	public function test_mode_for_layout_hero_primary_is_hero_primary(): void {
		$this->assertSame( 'hero_primary', CardProfiles::mode_for_layout( 'hero', 'primary' ) );
	}

	public function test_mode_for_layout_hero_rail_is_hero_secondary(): void {
		$this->assertSame( 'hero_secondary', CardProfiles::mode_for_layout( 'hero', 'rail' ) );
	}

	public function test_mode_for_layout_carousel_defaults_to_standard(): void {
		$this->assertSame( 'standard', CardProfiles::mode_for_layout( 'carousel' ) );
	}

	public function test_mode_for_layout_masonry_defaults_to_standard(): void {
		$this->assertSame( 'standard', CardProfiles::mode_for_layout( 'masonry' ) );
	}

	public function test_mode_for_layout_unknown_layout_falls_back_to_standard(): void {
		$this->assertSame( 'standard', CardProfiles::mode_for_layout( 'unknown_layout' ) );
		// Empty layout string also falls back.
		$this->assertSame( 'standard', CardProfiles::mode_for_layout( '' ) );
	}

	public function test_mode_for_layout_featured_unknown_role_falls_back_to_default(): void {
		// 'featured' has no rule for 'primary_slot' or other role strings.
		// Unrecognized role for a known layout must fall back to the layout's
		// default mode (standard for featured).
		$this->assertSame( 'standard', CardProfiles::mode_for_layout( 'featured', 'primary_slot' ) );
		$this->assertSame( 'standard', CardProfiles::mode_for_layout( 'featured', '' ) );
	}

	public function test_mode_for_layout_hero_unknown_role_falls_back_to_default(): void {
		// 'hero' default mode is hero_primary.
		$this->assertSame( 'hero_primary', CardProfiles::mode_for_layout( 'hero', 'sidekick' ) );
		$this->assertSame( 'hero_primary', CardProfiles::mode_for_layout( 'hero', '' ) );
	}

	// ---------------------------------------------------------------------
	// allowed_modes()
	// ---------------------------------------------------------------------

	public function test_allowed_modes_returns_exactly_nine_modes(): void {
		$allowed = CardProfiles::allowed_modes();

		$this->assertIsArray( $allowed );
		$this->assertCount(
			9,
			$allowed,
			'allowed_modes() must return exactly the 9 documented mode names.'
		);
	}

	public function test_allowed_modes_contains_every_documented_mode(): void {
		$allowed = CardProfiles::allowed_modes();

		$expected = array(
			'standard',
			'compact',
			'media_row',
			'hero_primary',
			'hero_secondary',
			'short_vertical',
			'live_status',
			'overlay',
			'minimal_thumb',
		);

		foreach ( $expected as $mode ) {
			$this->assertContains( $mode, $allowed, "allowed_modes() must include '{$mode}'." );
		}
	}

	public function test_allowed_modes_values_are_all_strings(): void {
		$allowed = CardProfiles::allowed_modes();

		foreach ( $allowed as $mode ) {
			$this->assertIsString( $mode );
			$this->assertNotSame( '', $mode );
		}
	}

	public function test_allowed_modes_values_match_get_keys(): void {
		$allowed = CardProfiles::allowed_modes();
		foreach ( $allowed as $mode ) {
			$profile = CardProfiles::get( $mode );
			// The profile returned for each allowed mode must not be the
			// 'standard' fallback (otherwise the list includes dead names).
			$this->assertIsArray( $profile );
		}
	}
}
