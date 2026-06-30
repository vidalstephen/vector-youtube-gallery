<?php
/**
 * RelativeTime — human-friendly "X minutes ago" labels for the grid card meta
 * row. Pure function, no WordPress dependencies, fully testable.
 *
 * Labels are plain English. Callers in templates wrap the return value in
 * `esc_html()` and `sprintf( '%s', … )` so the i18n surface is the template,
 * not this class.
 *
 * Buckets (per Phase 13.1 design):
 *   < 1 min    → "just now"
 *   1-59 min   → "N min ago"          (abbreviation, no plural)
 *   1 hour     → "1 hour ago"         (singular)
 *   2-23 hours → "N hours ago"
 *   1 day      → "1 day ago"          (singular)
 *   2-6 days   → "N days ago"
 *   1 week     → "1 week ago"         (singular)
 *   2-4 weeks  → "N weeks ago"
 *   1 month    → "1 month ago"        (singular)
 *   2-12 months → "N months ago"
 *   1 year     → "1 year ago"         (singular)
 *   2+ years   → "N years ago"
 *   future or invalid → ""
 *
 * @package VectorYT\Gallery\Render
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Render;

defined( 'ABSPATH' ) || exit;

final class RelativeTime {

    /**
     * Convert an ISO-8601 timestamp into a "N units ago" label.
     *
     * @param string|null            $iso  ISO-8601 timestamp (e.g. "2026-06-28T12:00:00+00:00").
     *                                     Null, empty, or unparseable values return "".
     * @param \DateTimeImmutable|null $now Reference "now". Defaults to the current
     *                                      time at call. Inject for deterministic tests.
     * @return string
     */
    public static function humanize( ?string $iso, ?\DateTimeImmutable $now = null ): string {
        if ( null === $iso || '' === $iso ) {
            return '';
        }
        try {
            $then = new \DateTimeImmutable( $iso );
        } catch ( \Exception $e ) {
            return '';
        }
        $now = $now ?? new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );

        $diff_seconds = $now->getTimestamp() - $then->getTimestamp();
        if ( $diff_seconds < 0 ) {
            return '';
        }
        if ( $diff_seconds < 60 ) {
            return 'just now';
        }
        if ( $diff_seconds < 3600 ) {
            $n = (int) floor( $diff_seconds / 60 );
            // "min" is an abbreviation that stays unpluralized (matches
            // YouTube's own UI: "1 min ago", "5 min ago", "59 min ago").
            return $n . ' min ago';
        }
        if ( $diff_seconds < 86400 ) {
            $n = (int) floor( $diff_seconds / 3600 );
            return self::label( $n, 'hour' );
        }
        if ( $diff_seconds < 7 * 86400 ) {
            $n = (int) floor( $diff_seconds / 86400 );
            return self::label( $n, 'day' );
        }
        if ( $diff_seconds < 30 * 86400 ) {
            $n = (int) floor( $diff_seconds / ( 7 * 86400 ) );
            return self::label( $n, 'week' );
        }
        if ( $diff_seconds < 365 * 86400 ) {
            $n = (int) floor( $diff_seconds / ( 30 * 86400 ) );
            return self::label( $n, 'month' );
        }
        $n = (int) floor( $diff_seconds / ( 365 * 86400 ) );
        return self::label( $n, 'year' );
    }

    /**
     * Return "N unit ago" with correct singular/plural.
     *
     * @param int    $n
     * @param string $unit Singular form (e.g. "day", "month").
     */
    private static function label( int $n, string $unit ): string {
        $word = ( 1 === $n ) ? $unit : $unit . 's';
        return $n . ' ' . $word . ' ago';
    }
}
