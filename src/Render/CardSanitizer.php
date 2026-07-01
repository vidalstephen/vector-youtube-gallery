<?php
/**
 * CardSanitizer — strict reusable sanitizers for the video-card
 * customization system.
 *
 * Pure PHP. No WordPress globals, no hooks, no esc_* calls. Settings
 * arriving from shortcode attrs, Gutenberg block attrs, Elementor widget
 * instance values, REST requests, or admin forms all funnel through
 * these helpers so the renderer never has to defend itself against
 * arbitrary input.
 *
 * The boolean helper is the load-bearing one: PHP coerces `'false'` to
 * `true` via `(bool)`, so we never use that cast here. We match explicit
 * string tokens case-insensitively.
 *
 * @package VectorYT\Gallery\Render
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Render;

defined( 'ABSPATH' ) || exit;

final class CardSanitizer {

	/**
	 * Tokens that map to boolean true. Matched case-insensitively.
	 *
	 * Note: we intentionally do NOT include 'true' here as a fallback —
	 * the string token list is the source of truth.
	 *
	 * @var string[]
	 */
	private const TRUE_TOKENS = array( 'true', '1', 'yes', 'on' );

	/**
	 * Tokens that map to boolean false. Matched case-insensitively.
	 *
	 * @var string[]
	 */
	private const FALSE_TOKENS = array( 'false', '0', 'no', 'off', '' );

	/**
	 * Coerce an arbitrary value to a boolean.
	 *
	 * True-like values (bool true, int 1, or the strings '1', 'true',
	 * 'yes', 'on' — case-insensitive) return true. Everything else
	 * (including the string 'false', which is `true` under PHP's native
	 * `(bool)` cast) falls through to $default.
	 *
	 * @param mixed $value   Incoming value from shortcode/attr/form/JSON.
	 * @param bool  $default Fallback when the value is not recognizable.
	 */
	public static function bool( mixed $value, bool $default = false ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) ) {
			// 1 → true, 0 → false. Other ints are not "boolean-like" and
			// fall back to the default.
			if ( 1 === $value ) {
				return true;
			}
			if ( 0 === $value ) {
				return false;
			}
			return $default;
		}
		if ( is_string( $value ) ) {
			$normalized = strtolower( trim( $value ) );
			if ( in_array( $normalized, self::TRUE_TOKENS, true ) ) {
				return true;
			}
			if ( in_array( $normalized, self::FALSE_TOKENS, true ) ) {
				return false;
			}
		}
		return $default;
	}

	/**
	 * Coerce an arbitrary value to an allow-listed string.
	 *
	 * If $value (compared as a string) is in $allowed, return it.
	 * Otherwise return $default. If $default itself is not in $allowed,
	 * return the first element of $allowed (or '' if $allowed is empty,
	 * which is itself a configuration error callers should avoid).
	 *
	 * @param mixed    $value   Incoming value.
	 * @param string[] $allowed Allow-list of valid values.
	 * @param string   $default Fallback when $value is not allowed.
	 */
	public static function enum( mixed $value, array $allowed, string $default ): string {
		if ( empty( $allowed ) ) {
			return '';
		}
		if ( is_string( $value ) && in_array( $value, $allowed, true ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			$as_string = (string) $value;
			if ( in_array( $as_string, $allowed, true ) ) {
				return $as_string;
			}
		}
		if ( in_array( $default, $allowed, true ) ) {
			return $default;
		}
		// $default not in $allowed — return the first allowed value.
		return reset( $allowed );
	}

	/**
	 * Coerce an arbitrary value to an int in the closed range [min, max].
	 *
	 * Numeric strings are parsed; floats are truncated to int. Anything
	 * that does not represent a number returns $default, which is itself
	 * clamped to [min, max] for safety.
	 *
	 * @param mixed $value   Incoming value.
	 * @param int   $min     Inclusive lower bound.
	 * @param int   $max     Inclusive upper bound.
	 * @param int   $default Fallback when the value is non-numeric.
	 */
	public static function int( mixed $value, int $min, int $max, int $default ): int {
		// Defensive: ensure the bound pair is well-formed.
		if ( $min > $max ) {
			$min = $max;
		}

		$candidate = self::coerce_to_int( $value );
		if ( null === $candidate ) {
			$candidate = $default;
		}
		return self::clamp_int( $candidate, $min, $max );
	}

	/**
	 * Try to turn $value into an int, or return null if it isn't
	 * something we recognize as numeric.
	 *
	 * Booleans, null, arrays, objects, and non-numeric strings all
	 * return null. Numeric strings are parsed; floats are truncated.
	 */
	private static function coerce_to_int( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_float( $value ) ) {
			return (int) $value;
		}
		if ( is_string( $value ) && '' !== $value && is_numeric( $value ) ) {
			// Cast via float so "5.9" → 5 consistently with float input.
			return (int) (float) $value;
		}
		return null;
	}

	/**
	 * Coerce an arbitrary value to an array of allow-listed strings.
	 *
	 * Accepts either an array of strings (used as-is, filtered, with
	 * order preserved) or a comma-separated string (split, trimmed,
	 * filtered, with order preserved). Null, empty strings, empty
	 * arrays, and other types all return [].
	 *
	 * @param mixed    $value   Incoming value: array, comma-string, or null.
	 * @param string[] $allowed Allow-list of valid entries.
	 * @return string[]
	 */
	public static function list( mixed $value, array $allowed ): array {
		if ( null === $value || '' === $value ) {
			return array();
		}

		if ( is_string( $value ) ) {
			$parts = explode( ',', $value );
		} elseif ( is_array( $value ) ) {
			$parts = $value;
		} else {
			return array();
		}

		$out = array();
		foreach ( $parts as $part ) {
			if ( ! is_string( $part ) ) {
				continue;
			}
			$trimmed = trim( $part );
			if ( '' === $trimmed ) {
				continue;
			}
			if ( in_array( $trimmed, $allowed, true ) ) {
				$out[] = $trimmed;
			}
		}
		return $out;
	}

	/**
	 * Coerce an arbitrary value to a sanitized, length-bounded string.
	 *
	 * Strips HTML/PHP tags, trims surrounding whitespace, and truncates
	 * to $max characters using mb_substr so multibyte sequences (emoji,
	 * CJK) are never split. Non-string and null inputs return ''.
	 *
	 * @param mixed $value Incoming value.
	 * @param int   $max   Maximum length in characters (codepoints), not bytes.
	 */
	public static function text( mixed $value, int $max = 120 ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$stripped = trim( strip_tags( $value ) );
		if ( '' === $stripped ) {
			return '';
		}
		if ( $max <= 0 ) {
			return '';
		}
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $stripped, 0, $max );
		}
		// Defensive fallback: byte-substr is wrong for multibyte but
		// mbstring is essentially universal. This branch is here only
		// for completeness if mbstring were ever disabled.
		return substr( $stripped, 0, $max );
	}

	/**
	 * Clamp an int into the closed range [min, max].
	 */
	private static function clamp_int( int $value, int $min, int $max ): int {
		if ( $value < $min ) {
			return $min;
		}
		if ( $value > $max ) {
			return $max;
		}
		return $value;
	}
}
