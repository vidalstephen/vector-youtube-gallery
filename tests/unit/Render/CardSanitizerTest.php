<?php
/**
 * Unit tests for CardSanitizer.
 *
 * Pure-PHP sanitizer helper. No WordPress globals, no Brain\Monkey stubs
 * needed — the class only depends on PHP built-ins.
 *
 * @covers \VectorYT\Gallery\Render\CardSanitizer
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Render\CardSanitizer;

final class CardSanitizerTest extends TestCase {

	// ---------------------------------------------------------------------
	// bool()
	// ---------------------------------------------------------------------

	public function test_bool_true_like_values_return_true(): void {
		$this->assertTrue( CardSanitizer::bool( true ) );
		$this->assertTrue( CardSanitizer::bool( 'true' ) );
		$this->assertTrue( CardSanitizer::bool( '1' ) );
		$this->assertTrue( CardSanitizer::bool( 1 ) );
		$this->assertTrue( CardSanitizer::bool( 'yes' ) );
		$this->assertTrue( CardSanitizer::bool( 'on' ) );
	}

	public function test_bool_false_like_values_return_false(): void {
		$this->assertFalse( CardSanitizer::bool( false ) );
		// CRITICAL: (bool) 'false' is `true` in PHP — must use explicit matching.
		$this->assertFalse( CardSanitizer::bool( 'false' ) );
		$this->assertFalse( CardSanitizer::bool( '0' ) );
		$this->assertFalse( CardSanitizer::bool( 0 ) );
		$this->assertFalse( CardSanitizer::bool( 'no' ) );
		$this->assertFalse( CardSanitizer::bool( 'off' ) );
		$this->assertFalse( CardSanitizer::bool( '' ) );
		$this->assertFalse( CardSanitizer::bool( null ) );
	}

	public function test_bool_unknown_values_fall_back_to_default(): void {
		$this->assertTrue( CardSanitizer::bool( 'maybe', true ) );
		$this->assertFalse( CardSanitizer::bool( 'maybe', false ) );
		$this->assertTrue( CardSanitizer::bool( 42, true ) );
		$this->assertFalse( CardSanitizer::bool( 42, false ) );
		$this->assertTrue( CardSanitizer::bool( array( 'x' ), true ) );
		// Default is `false` when omitted.
		$this->assertFalse( CardSanitizer::bool( 'unknown' ) );
	}

	public function test_bool_is_case_insensitive_for_string_tokens(): void {
		$this->assertTrue( CardSanitizer::bool( 'TRUE' ) );
		$this->assertTrue( CardSanitizer::bool( 'Yes' ) );
		$this->assertTrue( CardSanitizer::bool( 'ON' ) );
		$this->assertFalse( CardSanitizer::bool( 'FALSE' ) );
		$this->assertFalse( CardSanitizer::bool( 'No' ) );
	}

	// ---------------------------------------------------------------------
	// enum()
	// ---------------------------------------------------------------------

	public function test_enum_returns_value_when_allowed(): void {
		$allowed = array( 'grid', 'list', 'shorts' );
		$this->assertSame( 'grid', CardSanitizer::enum( 'grid', $allowed, 'list' ) );
		$this->assertSame( 'list', CardSanitizer::enum( 'list', $allowed, 'grid' ) );
		$this->assertSame( 'shorts', CardSanitizer::enum( 'shorts', $allowed, 'grid' ) );
	}

	public function test_enum_returns_default_when_value_not_allowed(): void {
		$allowed = array( 'grid', 'list', 'shorts' );
		$this->assertSame( 'list', CardSanitizer::enum( 'unknown', $allowed, 'list' ) );
		$this->assertSame( 'list', CardSanitizer::enum( '', $allowed, 'list' ) );
		$this->assertSame( 'list', CardSanitizer::enum( null, $allowed, 'list' ) );
		$this->assertSame( 'list', CardSanitizer::enum( 123, $allowed, 'list' ) );
	}

	public function test_enum_falls_back_to_first_allowed_when_default_invalid(): void {
		$allowed = array( 'grid', 'list', 'shorts' );
		// Default 'mystery' is not in the list; must return first allowed.
		$this->assertSame( 'grid', CardSanitizer::enum( 'whatever', $allowed, 'mystery' ) );
	}

	public function test_enum_returns_default_when_value_equals_default(): void {
		$allowed = array( 'a', 'b', 'c' );
		$this->assertSame( 'b', CardSanitizer::enum( 'b', $allowed, 'b' ) );
	}

	// ---------------------------------------------------------------------
	// int()
	// ---------------------------------------------------------------------

	public function test_int_within_range_returns_value(): void {
		$this->assertSame( 5, CardSanitizer::int( 5, 0, 10, 1 ) );
		$this->assertSame( 0, CardSanitizer::int( 0, 0, 10, 5 ) );
		$this->assertSame( 10, CardSanitizer::int( 10, 0, 10, 5 ) );
	}

	public function test_int_below_min_clamps_to_min(): void {
		$this->assertSame( 0, CardSanitizer::int( -5, 0, 10, 1 ) );
		$this->assertSame( 1, CardSanitizer::int( 0, 1, 10, 5 ) );
	}

	public function test_int_above_max_clamps_to_max(): void {
		$this->assertSame( 10, CardSanitizer::int( 999, 0, 10, 1 ) );
		$this->assertSame( 3, CardSanitizer::int( 5, 0, 3, 1 ) );
	}

	public function test_int_non_numeric_returns_default(): void {
		$this->assertSame( 7, CardSanitizer::int( 'abc', 0, 10, 7 ) );
		$this->assertSame( 7, CardSanitizer::int( null, 0, 10, 7 ) );
		$this->assertSame( 7, CardSanitizer::int( array( 1 ), 0, 10, 7 ) );
		$this->assertSame( 7, CardSanitizer::int( '', 0, 10, 7 ) );
		// PHP loose-cast: bool true is 1, bool false is 0; not "non-numeric" per se.
		// We treat numeric strings + booleans as numeric but anything else as default.
		$this->assertSame( 7, CardSanitizer::int( new \stdClass(), 0, 10, 7 ) );
	}

	public function test_int_accepts_numeric_strings(): void {
		$this->assertSame( 5, CardSanitizer::int( '5', 0, 10, 1 ) );
		$this->assertSame( 5, CardSanitizer::int( '5.9', 0, 10, 1 ) );
		// Numeric string above max → clamp to max.
		$this->assertSame( 10, CardSanitizer::int( '999', 0, 10, 1 ) );
		// Negative numeric string below min → clamp to min.
		$this->assertSame( 0, CardSanitizer::int( '-5', 0, 10, 1 ) );
	}

	public function test_int_truncates_floats_to_int(): void {
		$this->assertSame( 3, CardSanitizer::int( 3.7, 0, 10, 1 ) );
		$this->assertSame( 3, CardSanitizer::int( 3.2, 0, 10, 1 ) );
		// Float above max → clamp to max.
		$this->assertSame( 10, CardSanitizer::int( 12.9, 0, 10, 1 ) );
	}

	// ---------------------------------------------------------------------
	// list()
	// ---------------------------------------------------------------------

	public function test_list_accepts_array_and_filters_to_allowed(): void {
		$allowed = array( 'live', 'upcoming', 'replay' );
		$this->assertSame(
			array( 'live', 'replay' ),
			CardSanitizer::list( array( 'live', 'evil', 'replay' ), $allowed )
		);
	}

	public function test_list_accepts_comma_separated_string(): void {
		$allowed = array( 'watch', 'youtube', 'share', 'more' );
		$this->assertSame(
			array( 'watch', 'share' ),
			CardSanitizer::list( 'watch,evil,share', $allowed )
		);
		// Tolerates whitespace around commas.
		$this->assertSame(
			array( 'watch', 'youtube' ),
			CardSanitizer::list( ' watch , youtube ', $allowed )
		);
		// Empty string in the middle is dropped.
		$this->assertSame(
			array( 'watch' ),
			CardSanitizer::list( 'watch,,', $allowed )
		);
	}

	public function test_list_preserves_input_order(): void {
		$allowed = array( 'a', 'b', 'c', 'd' );
		$this->assertSame(
			array( 'c', 'a', 'b' ),
			CardSanitizer::list( array( 'c', 'a', 'b' ), $allowed )
		);
		// Reordering the allowed list does NOT change result order.
		$this->assertSame(
			array( 'a', 'b' ),
			CardSanitizer::list( array( 'a', 'b' ), array( 'b', 'a', 'c' ) )
		);
	}

	public function test_list_drops_unknown_entries(): void {
		$allowed = array( 'x', 'y' );
		$this->assertSame(
			array( 'x' ),
			CardSanitizer::list( array( 'x', 'unknown1', 'unknown2' ), $allowed )
		);
		$this->assertSame(
			array(),
			CardSanitizer::list( array( 'a', 'b', 'c' ), $allowed )
		);
	}

	public function test_list_returns_empty_for_null_or_empty(): void {
		$allowed = array( 'a', 'b' );
		$this->assertSame( array(), CardSanitizer::list( null, $allowed ) );
		$this->assertSame( array(), CardSanitizer::list( '', $allowed ) );
		$this->assertSame( array(), CardSanitizer::list( array(), $allowed ) );
	}

	public function test_list_with_non_string_non_array_returns_empty(): void {
		$allowed = array( 'a', 'b' );
		$this->assertSame( array(), CardSanitizer::list( 123, $allowed ) );
		$this->assertSame( array(), CardSanitizer::list( true, $allowed ) );
		$this->assertSame( array(), CardSanitizer::list( new \stdClass(), $allowed ) );
	}

	// ---------------------------------------------------------------------
	// text()
	// ---------------------------------------------------------------------

	public function test_text_strips_html_tags(): void {
		// PHP's strip_tags() removes the tags but keeps the inner text of
		// the stripped tag (e.g. the "x" inside <script> survives). This
		// test asserts the tags themselves are gone and surrounding text
		// is trimmed; the script-content handling is not the security
		// guarantee — that lives in the renderer's esc_html() pass.
		$this->assertSame(
			'hello xworld',
			CardSanitizer::text( '<b>hello</b> <script>x</script>world' )
		);
		$this->assertSame(
			'evil',
			CardSanitizer::text( '<img src=x onerror=alert(1)>evil' )
		);
		// Pure-tag input → no surviving text.
		$this->assertSame(
			'wrapped',
			CardSanitizer::text( '<a href="x">wrapped</a>' )
		);
		// Mixed nested tags.
		$this->assertSame(
			'bold and italic',
			CardSanitizer::text( '<div><b>bold</b> and <i>italic</i></div>' )
		);
	}

	public function test_text_trims_whitespace(): void {
		$this->assertSame( 'hello', CardSanitizer::text( '   hello   ' ) );
		$this->assertSame( 'hello world', CardSanitizer::text( "\n\thello world \n" ) );
	}

	public function test_text_enforces_max_length_multibyte_safe(): void {
		// 5 ASCII chars from 10.
		$this->assertSame( 'hello', CardSanitizer::text( 'hello world', 5 ) );
		// Default max is 120; longer strings truncated.
		$long = str_repeat( 'a', 200 );
		$this->assertSame( str_repeat( 'a', 120 ), CardSanitizer::text( $long ) );
	}

	public function test_text_multibyte_truncation_does_not_split_codepoints(): void {
		// 5 emoji = 5 codepoints, but each is 4 bytes in UTF-8.
		$emoji = '😀😁😂🤣😃';
		$this->assertSame( $emoji, CardSanitizer::text( $emoji, 5 ) );
		// Truncate to 3 emoji — must not break the codepoint boundary.
		$this->assertSame( '😀😁😂', CardSanitizer::text( $emoji . 'extra', 3 ) );
		// Truncate to 4.
		$this->assertSame( '😀😁😂🤣', CardSanitizer::text( $emoji, 4 ) );
	}

	public function test_text_returns_empty_for_null_or_non_string(): void {
		$this->assertSame( '', CardSanitizer::text( null ) );
		$this->assertSame( '', CardSanitizer::text( 123 ) );
		$this->assertSame( '', CardSanitizer::text( array( 'a' ) ) );
		$this->assertSame( '', CardSanitizer::text( true ) );
		$this->assertSame( '', CardSanitizer::text( new \stdClass() ) );
	}

	public function test_text_returns_empty_for_empty_string(): void {
		$this->assertSame( '', CardSanitizer::text( '' ) );
		$this->assertSame( '', CardSanitizer::text( '   ' ) );
		$this->assertSame( '', CardSanitizer::text( '<b></b>' ) );
	}
}
