<?php
/**
 * TimeHelper — relative countdown formatter for live-upcoming cards.
 *
 * Phase 14.11 (prototype parity): the live layout's upcoming cards
 * previously rendered the raw scheduled time ("7:30 PM") via
 * `mysql2date( get_option( 'time_format' ), … )`. The prototype
 * instead shows a human-friendly relative countdown like "in 2h 15m"
 * or "in 3 days" so visitors can scan when a stream starts at a
 * glance, without doing the date math themselves.
 *
 * Buckets (future direction):
 *   <  0  s   → "started 1h ago" (negative diff, used for replay fallback)
 *   < 60  s   → "starting now"
 *   < 60  m   → "in Nm"          (1m, 5m, 59m)
 *   < 24  h   → "in Nh Mm" or "in Nh" (minutes omitted when 0)
 *   <  7  d   → "in N day(s)"   or "in N days" (singular/plural)
 *   < 30  d   → "in N week(s)"  (4 weeks, 5 weeks, etc.)
 *   < 12 mo   → "in N month(s)"
 *   else      → "in N year(s)"
 *
 * Past direction mirrors the future labels with the "ago" suffix.
 * Used by tests; the live-card partial only renders the upcoming
 * (future) branch — the ended/replay branch keeps its raw date.
 *
 * Pure logic, no WordPress dependencies, fully testable. Labels are
 * plain English; templates wrap the return value in `esc_html()` and
 * `sprintf( %s, … )` so the i18n surface is the template, not this
 * class (matches the convention established by RelativeTime).
 *
 * @package VectorYT\Gallery\Render
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Render;

defined( 'ABSPATH' ) || exit;

final class TimeHelper {

	/**
	 * Format a scheduled start time as a human-friendly relative
	 * countdown. The input is a MySQL-style datetime string (UTC,
	 * "Y-m-d H:i:s") produced by VideoNormalizer::parse_mysql_datetime()
	 * via gmdate(). ISO-8601 strings (with a "T" separator and a "Z"
	 * or offset suffix) are also accepted — DateTimeImmutable parses
	 * both.
	 *
	 * @param string|null            $mysql_or_iso  UTC datetime / ISO-8601.
	 *                                              Null, empty, or unparseable
	 *                                              values return "".
	 * @param \DateTimeImmutable|null $now           Reference "now" (UTC).
	 *                                              Defaults to the current
	 *                                              time at call. Inject for
	 *                                              deterministic tests.
	 * @return string  e.g. "in 2h 15m", "in 3 days", "starting now", "5m ago".
	 */
	public static function relative_countdown( ?string $mysql_or_iso, ?\DateTimeImmutable $now = null ): string {
		if ( null === $mysql_or_iso || '' === $mysql_or_iso ) {
			return '';
		}
		try {
			$then = new \DateTimeImmutable( $mysql_or_iso, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return '';
		}
		$now = $now ?? new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );

		$diff_seconds = $then->getTimestamp() - $now->getTimestamp();

		// Past / now.
		if ( $diff_seconds < 0 ) {
			$diff_seconds = abs( $diff_seconds );
			// Same buckets as the future direction, but with the
			// "ago" suffix. The "starting now" bucket is shared
			// (in the past it reads as "started 1m ago" — see below).
			if ( $diff_seconds < 60 ) {
				return 'just now';
			}
			if ( $diff_seconds < 3600 ) {
				$n = (int) floor( $diff_seconds / 60 );
				return $n . ' min ago';
			}
			if ( $diff_seconds < 86400 ) {
				$n = (int) floor( $diff_seconds / 3600 );
				return self::label( $n, 'hour', 'ago' );
			}
			if ( $diff_seconds < 7 * 86400 ) {
				$n = (int) floor( $diff_seconds / 86400 );
				return self::label( $n, 'day', 'ago' );
			}
			if ( $diff_seconds < 30 * 86400 ) {
				$n = (int) floor( $diff_seconds / ( 7 * 86400 ) );
				return self::label( $n, 'week', 'ago' );
			}
			if ( $diff_seconds < 365 * 86400 ) {
				$n = (int) floor( $diff_seconds / ( 30 * 86400 ) );
				return self::label( $n, 'month', 'ago' );
			}
			$n = (int) floor( $diff_seconds / ( 365 * 86400 ) );
			return self::label( $n, 'year', 'ago' );
		}

		// Future.
		if ( $diff_seconds < 60 ) {
			return 'starting now';
		}
		if ( $diff_seconds < 3600 ) {
			$n = (int) floor( $diff_seconds / 60 );
			// "in 5m" — minutes only, no plural (matches YouTube UI).
			return 'in ' . $n . 'm';
		}
		if ( $diff_seconds < 86400 ) {
			$h = (int) floor( $diff_seconds / 3600 );
			$m = (int) floor( ( $diff_seconds % 3600 ) / 60 );
			// Per the prototype: "in 2h 15m" with minutes omitted when 0
			// → "in 2h". The "in" prefix stays.
			if ( 0 === $m ) {
				return 'in ' . $h . 'h';
			}
			return 'in ' . $h . 'h ' . $m . 'm';
		}
		if ( $diff_seconds < 7 * 86400 ) {
			$n = (int) floor( $diff_seconds / 86400 );
			// "in 1 day" / "in 3 days" — spelled-out, matches the
			// prototype's larger-bucket vocabulary.
			return 'in ' . $n . ' ' . ( 1 === $n ? 'day' : 'days' );
		}
		if ( $diff_seconds < 30 * 86400 ) {
			$n = (int) floor( $diff_seconds / ( 7 * 86400 ) );
			return 'in ' . $n . ' ' . ( 1 === $n ? 'week' : 'weeks' );
		}
		if ( $diff_seconds < 365 * 86400 ) {
			$n = (int) floor( $diff_seconds / ( 30 * 86400 ) );
			return 'in ' . $n . ' ' . ( 1 === $n ? 'month' : 'months' );
		}
		$n = (int) floor( $diff_seconds / ( 365 * 86400 ) );
		return 'in ' . $n . ' ' . ( 1 === $n ? 'year' : 'years' );
	}

	/**
	 * Build an "N unit ago" string with correct singular/plural.
	 *
	 * @param int    $n
	 * @param string $unit Singular form (e.g. "day", "month").
	 * @param string $suffix Suffix appended after the unit ("ago", or "" for future).
	 */
	private static function label( int $n, string $unit, string $suffix ): string {
		$word = ( 1 === $n ) ? $unit : $unit . 's';
		$s    = '' === $suffix ? '' : ' ' . $suffix;
		return $n . ' ' . $word . $s;
	}
}
