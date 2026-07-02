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

    // -----------------------------------------------------------------
    // Phase 14.7 — per-channel avatar gradient (`--aa` / `--ab`)
    // -----------------------------------------------------------------

    /**
     * Default mid-tone for the avatar gradient. Used when no channel id
     * is available AND no operator override is stored. Same slate-500
     * default as `DEFAULT_TONE_COLOR` so the avatar gradient is visually
     * compatible with the thumb gradient from 14.6.
     */
    public const DEFAULT_AVATAR_COLOR_A = '#64748b';

    /**
     * Default dark anchor for the avatar gradient. Paired with the
     * mid-tone above it forms a visually distinct 135deg gradient that
     * reads correctly even on white card backgrounds.
     */
    public const DEFAULT_AVATAR_COLOR_B = '#0f172a';

    /**
     * Resolve the two-color avatar gradient pair for a video card.
     *
     * Phase 14.7 — per-channel avatar gradient. The prototype pairs a
     * mid-tone (avatar[0]) with a dark anchor (avatar[1]) to form a
     * 135deg linear gradient on a 30px circle. The two colors come
     * from the operator's persisted overrides on the video row, then
     * fall back to a deterministic SHA-256 hash of the channel id
     * (two non-overlapping slices of the hash), then to the
     * slate-500 / slate-900 pair as a last resort.
     *
     * Schema reality: as of Phase 14.7 the `vyg_sources` and
     * `vyg_videos` tables do NOT yet persist `brand_color_*` /
     * `avatar_color_*` columns. When they are added (likely in
     * 14.13+ or in a later channel-metadata-sync phase), the helper
     * will pick them up via the tier 1 read on `$video`. The tier 2
     * hash is stable across page loads so the visual remains
     * consistent today.
     *
     * XSS contract: same as `tone_color()`. Stored values are
     * validated against the strict 7-char `#RRGGBB` regex and
     * rejected when they don't match — falls through to the hash
     * tier. The hash output is always 7 lowercase chars by
     * construction.
     *
     * @param array<string,mixed> $video
     * @return array{0:string,1:string} [color_a, color_b] — both 7-char `#RRGGBB`.
     */
    public function avatar_colors( array $video ): array {
        // Tier 1: stored operator override (future schema). Two keys:
        // `avatar_color_a` (mid-tone) and `avatar_color_b` (dark). The
        // pair must BOTH be valid — if one is missing or invalid, fall
        // through to the hash tier rather than emit a half-broken
        // pair.
        $stored_a = (string) ( $video['avatar_color_a'] ?? '' );
        $stored_b = (string) ( $video['avatar_color_b'] ?? '' );
        if ( '' !== $stored_a && '' !== $stored_b
            && self::is_valid_hex_color( $stored_a )
            && self::is_valid_hex_color( $stored_b )
        ) {
            return array( strtolower( $stored_a ), strtolower( $stored_b ) );
        }

        // Tier 2: deterministic hash of the channel id. The two
        // colors are deliberately derived from NON-OVERLAPPING bytes
        // of the SHA-256 digest (bytes 3..5 vs bytes 6..8) so the
        // pair is visually distinct with high probability.
        $channel_id = (string) ( $video['youtube_channel_id'] ?? '' );
        if ( '' !== $channel_id ) {
            $pair = self::channel_id_to_avatar_colors( $channel_id );
            if ( null !== $pair ) {
                return $pair;
            }
        }

        // Tier 3: slate-500 / slate-900 default pair.
        return array( self::DEFAULT_AVATAR_COLOR_A, self::DEFAULT_AVATAR_COLOR_B );
    }

    // -----------------------------------------------------------------
    // Phase 14.8 — badge type / style system (7 types × 4 styles)
    // -----------------------------------------------------------------

    /**
     * Window during which a freshly-published, non-live standard
     * video still earns a "new" badge. The prototype surfaces any
     * video published in the last week with a green NEW pill; beyond
     * that the badge disappears and the video just renders as a
     * standard row. 7 days is the same window YouTube Studio's own
     * "new" treatment uses.
     */
    public const NEW_BADGE_WINDOW_SECONDS = 7 * DAY_IN_SECONDS;

    /**
     * The seven badge types from the prototype panel — each maps to
     * a CSS class (`.vyg-card__badge--{slug}`), a human label, and a
     * per-type color. The order is the prototype's display order in
     * the Badge type select (featured, live, upcoming, replay, short,
     * new, product). The "standard" row is NOT a badge — it's the
     * default state with no badge rendered.
     *
     * @var array<string,array{label:string,color:string}>
     */
    private const BADGE_TYPES = array(
        'featured' => array(
            'label' => 'Featured',
            'color' => '#6d28d9',
        ),
        'live'     => array(
            'label' => 'Live',
            'color' => '#ef233c',
        ),
        'upcoming' => array(
            'label' => 'Upcoming',
            'color' => '#7c3aed',
        ),
        'replay'   => array(
            'label' => 'Replay',
            'color' => '#2563eb',
        ),
        'short'    => array(
            'label' => 'Short',
            'color' => '#0ea5e9',
        ),
        'new'      => array(
            'label' => 'New',
            'color' => '#16a34a',
        ),
        'product'  => array(
            'label' => 'Product',
            'color' => '#f97316',
        ),
    );

    /**
     * Derive the badge type for a video row.
     *
     * Phase 14.8 — prototype badge panel. The 7 types map to specific
     * signals on the row, in this priority order (highest first):
     *
     *   1. manual_content_type = 'product'           → 'product'
     *      (operator override always wins; also matches the
     *      shortcode-attr path for "promote this to a product card".)
     *   2. live_status = 'live'                      → 'live'
     *   3. live_status = 'upcoming' + scheduled in
     *      the future                                → 'upcoming'
     *      (a stale 'upcoming' row whose scheduled_start_at has
     *      already passed gets no badge rather than misleading the
     *      visitor with a "starts in N days" label that has elapsed.)
     *   4. live_status = 'replay'                    → 'replay'
     *   5. content_type = 'short_confirmed' |
     *      'short_candidate'                         → 'short'
     *   6. is_pinned = 1 (and not live)              → 'featured'
     *   7. published within the last 7 days, no
     *      live/pinned/short signal, content_type =
     *      'standard'                                → 'new'
     *   8. otherwise                                 → '' (no badge)
     *
     * The function is pure: no DB calls, no WordPress globals, no
     * filters. Callers (CardRenderer) can suppress the badge via
     * `show_status_badge` or the `enabled_badges` allow-list in
     * CardSettings — this helper only decides WHICH type the row
     * qualifies for, never whether to render it.
     *
     * @param array<string,mixed> $video
     * @return string One of the 7 slugs above, or '' for "no badge".
     */
    public function badge_type_for( array $video ): string {
        // Tier 1 — manual override (operator-pinned product tag).
        $manual = strtolower( (string) ( $video['manual_content_type'] ?? '' ) );
        if ( 'product' === $manual ) {
            return 'product';
        }

        // Tier 2 — live signal. Live always wins over Shorts (matches
        // VideoNormalizer's content_type priority).
        $live_status = (string) ( $video['live_status'] ?? 'none' );
        if ( 'live' === $live_status ) {
            return 'live';
        }

        // Tier 3 — upcoming, but only when the scheduled start is
        // actually still in the future. A 'upcoming' row whose
        // scheduled_start_at has elapsed falls through (a stale
        // "upcoming" pill is worse than no pill).
        if ( 'upcoming' === $live_status && $this->is_scheduled_in_future( $video ) ) {
            return 'upcoming';
        }

        // Tier 4 — replay.
        if ( 'replay' === $live_status ) {
            return 'replay';
        }

        // Tier 5 — shorts (confirmed or candidate).
        $content_type = (string) ( $video['content_type'] ?? '' );
        if ( 'short_confirmed' === $content_type || 'short_candidate' === $content_type ) {
            return 'short';
        }

        // Tier 6 — pinned (featured) — only when nothing more
        // specific (live/upcoming/replay/short) already matched.
        if ( ! empty( $video['is_pinned'] ) ) {
            return 'featured';
        }

        // Tier 7 — "new" for recently-published standard videos.
        // We intentionally exclude live/shorts here so a freshly
        // published live broadcast still renders a LIVE pill, not a
        // NEW pill stacked on top of it.
        if ( 'standard' === $content_type && $this->is_recently_published( $video ) ) {
            return 'new';
        }

        return '';
    }

    /**
     * Human label for a badge type slug. Returns '' for unknown
     * slugs so the caller can decide whether to render an empty
     * span or suppress the badge entirely.
     *
     * @param string $type One of the 7 prototype types.
     * @return string The label (e.g. "Live") or '' for unknown.
     */
    public function badge_type_label( string $type ): string {
        if ( ! isset( self::BADGE_TYPES[ $type ] ) ) {
            return '';
        }
        return (string) self::BADGE_TYPES[ $type ]['label'];
    }

    /**
     * Prototype hex color for a badge type slug. Returns '' for
     * unknown slugs. The color is a hard-coded 7-char lowercase hex
     * from the BADGE_TYPES table — no XSS surface (the caller still
     * passes it through esc_attr when interpolating into a style
     * attribute, but the value itself is whitelisted).
     *
     * @param string $type One of the 7 prototype types.
     * @return string 7-char lowercase hex like '#0ea5e9' or ''.
     */
    public function badge_type_color( string $type ): string {
        if ( ! isset( self::BADGE_TYPES[ $type ] ) ) {
            return '';
        }
        return (string) self::BADGE_TYPES[ $type ]['color'];
    }

    /**
     * The full set of supported badge type slugs. Used by the
     * CardSettings LIST_SPECS so the operator panel can offer
     * "enable / disable per type" toggles against the same allow-list
     * the renderer reads.
     *
     * @return string[]
     */
    public function badge_type_slugs(): array {
        return array_keys( self::BADGE_TYPES );
    }

    /**
     * Helper for badge_type_for(): was this video scheduled to start
     * in the future relative to "now"? Returns false for any row
     * that lacks a parseable scheduled_start_at.
     *
     * @param array<string,mixed> $video
     */
    private function is_scheduled_in_future( array $video ): bool {
        $raw = (string) ( $video['scheduled_start_at'] ?? '' );
        if ( '' === $raw ) {
            // The VideoNormalizer stores scheduled_start_at as
            // 'Y-m-d H:i:s' (UTC) or null. Without a value we
            // treat the row as "indeterminate" — not eligible for
            // the upcoming badge.
            return false;
        }
        $ts = strtotime( $raw . ' UTC' );
        if ( false === $ts ) {
            return false;
        }
        return $ts > time();
    }

    /**
     * Helper for badge_type_for(): was this video published within
     * the NEW_BADGE_WINDOW? The window is 7 days, matching the
     * prototype's green "NEW" pill. Returns false when the row has
     * no parseable published_at.
     *
     * @param array<string,mixed> $video
     */
    private function is_recently_published( array $video ): bool {
        $raw = (string) ( $video['published_at'] ?? '' );
        if ( '' === $raw ) {
            return false;
        }
        $ts = strtotime( $raw . ' UTC' );
        if ( false === $ts ) {
            return false;
        }
        return ( time() - $ts ) <= self::NEW_BADGE_WINDOW_SECONDS;
    }

    /**
     * Derive a deterministic two-color avatar pair from a YouTube
     * channel id.
     *
     * Algorithm: SHA-256 the channel id, take bytes 3..5 for color A
     * (mid-tone) and bytes 6..8 for color B (dark anchor). The two
     * slices are non-overlapping so the pair is visually distinct
     * with high probability. Same input → same output (stable across
     * page loads). Always returns two 7-char lowercase hex strings
     * when given a non-empty channel id.
     *
     * @param string $channel_id Non-empty YouTube channel id.
     * @return array{0:string,1:string}|null Null only if $channel_id is empty.
     */
    public static function channel_id_to_avatar_colors( string $channel_id ): ?array {
        if ( '' === $channel_id ) {
            return null;
        }
        $hash = hash( 'sha256', $channel_id, true );
        // Bytes 3..5 → color A. Skipping the first 3 bytes (which
        // `channel_id_to_tone()` already uses for the thumb gradient)
        // keeps the avatar pair from being identical to the thumb
        // tone on the same card.
        $r1 = ord( $hash[3] );
        $g1 = ord( $hash[4] );
        $b1 = ord( $hash[5] );
        // Bytes 6..8 → color B. Another non-overlapping slice.
        $r2 = ord( $hash[6] );
        $g2 = ord( $hash[7] );
        $b2 = ord( $hash[8] );
        return array(
            sprintf( '#%02x%02x%02x', $r1, $g1, $b1 ),
            sprintf( '#%02x%02x%02x', $r2, $g2, $b2 ),
        );
    }
}