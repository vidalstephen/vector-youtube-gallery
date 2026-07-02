<?php
/**
 * VideoRenderer — helper used inside templates for embed URLs, watch URLs,
 * thumbnail selection, and duration formatting.
 *
 * Pure utility class — no API calls. The class methods are exposed to all
 * layout templates via $ctx['renderer'].
 *
 * @package VectorYT\Gallery\Render
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Render;

defined( 'ABSPATH' ) || exit;

final class VideoRenderer {

    /**
     * Build the official YouTube embed URL with sensible defaults.
     *
     * @param array<string,mixed> $video
     * @param array<string,mixed> $args Optional overrides: autoplay, rel, modestbranding, start.
     * @return string
     */
    public function embed_url( array $video, array $args = array() ): string {
        $id = (string) ( $video['youtube_video_id'] ?? '' );
        if ( '' === $id ) {
            return '';
        }
        $defaults = array(
            'autoplay'      => '1',
            'rel'           => '0',
            'modestbranding'=> '1',
        );
        $args = array_merge( $defaults, $args );
        return add_query_arg( $args, 'https://www.youtube.com/embed/' . rawurlencode( $id ) );
    }

    /**
     * Build the canonical YouTube watch URL.
     */
    public function watch_url( array $video ): string {
        $id = (string) ( $video['youtube_video_id'] ?? '' );
        if ( '' === $id ) {
            return '';
        }
        return 'https://www.youtube.com/watch?v=' . rawurlencode( $id );
    }

    /**
     * Choose the best available thumbnail for a given preference.
     *
     * @param array<string,mixed> $video
     * @param string $preferred One of: maxres, standard, high, medium, default.
     * @return string URL or ''.
     */
    public function best_thumbnail( array $video, string $preferred = 'medium' ): string {
        $order = array( 'maxres', 'standard', 'high', 'medium', 'default' );
        // Move $preferred to the front if valid.
        if ( in_array( $preferred, $order, true ) ) {
            $order = array_merge( array( $preferred ), array_values( array_diff( $order, array( $preferred ) ) ) );
        }
        foreach ( $order as $key ) {
            $col = 'thumbnail_' . $key;
            $url = (string) ( $video[ $col ] ?? '' );
            if ( '' !== $url ) {
                return $url;
            }
        }
        return '';
    }

    /**
     * Format ISO seconds as M:SS or H:MM:SS.
     */
    public function format_duration( int $seconds ): string {
        if ( $seconds <= 0 ) {
            return '';
        }
        $h = (int) floor( $seconds / 3600 );
        $m = (int) floor( ( $seconds % 3600 ) / 60 );
        $s = $seconds % 60;
        if ( $h > 0 ) {
            return sprintf( '%d:%02d:%02d', $h, $m, $s );
        }
        return sprintf( '%d:%02d', $m, $s );
    }

    /**
     * Format a raw view count as a short, human-friendly string.
     *
     * Below 1,000 the raw integer is returned (e.g. "999"). At and above 1,000
     * the number is divided into K (thousand), M (million), B (billion), or
     * T (trillion) buckets. The 10K threshold collapses the decimal so common
     * view counts read as "12K" rather than "12.3K".
     *
     * Examples:
     *   0     → "0"
     *   999   → "999"
     *   1_000 → "1.0K"
     *   12_345 → "12K"
     *   1_250_000 → "1.3M"
     *   1_234_567_890 → "1.2B"
     *   1_000_000_000_000 → "1.0T"
     *
     * @param int $count Non-negative view count.
     * @return string
     */
    public function format_view_count( int $count ): string {
        if ( $count < 1_000 ) {
            return (string) max( 0, $count );
        }
        $units = array(
            1_000_000_000_000 => 'T',
            1_000_000_000     => 'B',
            1_000_000         => 'M',
            1_000             => 'K',
        );
        foreach ( $units as $divisor => $suffix ) {
            if ( $count >= $divisor ) {
                $value = $count / $divisor;
                // 10K threshold: drop the decimal when the value is >= 10.
                $decimals = ( $value >= 10 ) ? 0 : 1;
                return number_format( $value, $decimals ) . $suffix;
            }
        }
        // Unreachable — the 1K branch above already returned.
        return (string) $count;
    }

    /**
     * Default fallback tone (slate-500). Used when no stored tone_color is
     * present AND the channel id is missing/empty. Lowercase hex with a
     * leading #, exactly 7 characters — matches the
     * `varchar(7) NOT NULL DEFAULT ''` column shape and the strict regex
     * the helper validates against.
     */
    public const DEFAULT_TONE_COLOR = '#64748b';

    /**
     * Resolve the tone color for a video card.
     *
     * Phase 14.6 — per-video tone gradient. Three-tier fallback:
     *
     *   1. Stored `tone_color` on the video row (operator override or
     *      normalized from channel branding) — returned as-is when it
     *      matches the strict `#RRGGBB` regex.
     *   2. Deterministic hash of the channel id — same channel always
     *      gets the same tone, so the card backgrounds look stable
     *      across page loads without persisting a row value.
     *   3. `DEFAULT_TONE_COLOR` (`#64748b`) when no channel id exists.
     *
     * XSS protection: the stored value comes from a DB column that
     * operators (or YouTube's branding payload) can populate. The
     * helper REJECTS anything that isn't a strict 7-char `#RRGGBB`
     * hex and falls through to the next tier. The hash output is
     * always 7 lowercase chars by construction.
     *
     * @param array<string,mixed> $video
     * @return string Hex color like '#0f766e'.
     */
    public function tone_color( array $video ): string {
        $stored = (string) ( $video['tone_color'] ?? '' );
        if ( '' !== $stored && self::is_valid_hex_color( $stored ) ) {
            // Normalize to lowercase so emitted style attrs are
            // deterministic and easy to grep in tests.
            return strtolower( $stored );
        }

        $channel_id = (string) ( $video['youtube_channel_id'] ?? '' );
        if ( '' !== $channel_id ) {
            return self::channel_id_to_tone( $channel_id );
        }

        return self::DEFAULT_TONE_COLOR;
    }

    /**
     * Strict `#RRGGBB` hex validator (lowercase OR uppercase). Accepts
     * exactly 7 characters: a leading `#` followed by 6 hex digits.
     * 3-digit shorthand (`#fff`) is rejected — emit the full form
     * to keep the column shape stable.
     */
    public static function is_valid_hex_color( string $value ): bool {
        return (bool) preg_match( '/^#[0-9a-fA-F]{6}$/', $value );
    }

    /**
     * Derive a deterministic hex tone from a YouTube channel id.
     *
     * Algorithm: SHA-256 the channel id, take the first 3 bytes, format
     * them as `#RRGGBB`. Same input → same output (stable across page
     * loads). Different inputs → different outputs with high
     * probability (a 24-bit color space has 16.7M tones, far more
     * than the channel count for any sane install).
     *
     * @param string $channel_id Non-empty YouTube channel id.
     * @return string 7-char lowercase hex like '#0f766e'.
     */
    public static function channel_id_to_tone( string $channel_id ): string {
        $hash = hash( 'sha256', $channel_id, true );
        $r = ord( $hash[0] );
        $g = ord( $hash[1] );
        $b = ord( $hash[2] );
        return sprintf( '#%02x%02x%02x', $r, $g, $b );
    }
}