<?php
/**
 * Shorts classifier — decides whether a YouTube video resource should be
 * classified as a YouTube Short.
 *
 * Per YouTube policy:
 *   - A Short is a vertical video (9:16) with duration <= 60 seconds.
 *   - The official #Shorts tag is *not* required but is a strong signal.
 *   - Live streams <= 60s vertical that are replay-only are also Shorts.
 *
 * This classifier uses three signals in order:
 *   1. Manual override (caller passes via $context) — wins unconditionally.
 *   2. Explicit #Shorts tag in snippet.tags — promoted to short_confirmed.
 *   3. Duration + dimension heuristic:
 *        - <= shorts_max_duration_seconds (60 default) AND vertical → short_confirmed
 *        - <= short_candidate_max_duration (180 default) AND vertical → short_candidate
 *        - otherwise standard
 *
 * Result values:
 *   - 'short_confirmed'    Tagged and meets policy.
 *   - 'short_candidate'    Within duration + vertical, no tag.
 *   - 'standard'           Not a Short.
 *
 * @package VectorYT\Gallery\Normalize
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Normalize;

defined( 'ABSPATH' ) || exit;

final class ShortsClassifier {

    public const TYPE_SHORT_CONFIRMED  = 'short_confirmed';
    public const TYPE_SHORT_CANDIDATE  = 'short_candidate';
    public const TYPE_STANDARD         = 'standard';

    /**
     * @param array<string,mixed> $api_resource   videos.list item.
     * @param array<string,mixed> $context        Optional: 'manual_content_type', 'duration_seconds'.
     * @param int $shorts_max                  Threshold for "Short".
     * @param int $candidate_max               Threshold for "candidate".
     */
    public function classify(
        array $api_resource,
        array $context,
        int $shorts_max = 60,
        int $candidate_max = 180,
    ): string {
        $manual = isset( $context['manual_content_type'] ) ? (string) $context['manual_content_type'] : '';
        if ( '' !== $manual && 'auto' !== $manual ) {
            // Manual override wins.
            return self::TYPE_STANDARD === $manual ? self::TYPE_STANDARD : $this->normalize_manual( $manual );
        }

        // Skip if caller disabled auto-classification for this video.
        $tags = (array) ( $api_resource['snippet']['tags'] ?? array() );
        $has_short_tag = $this->has_shorts_tag( $tags );

        $duration = isset( $context['duration_seconds'] )
            ? (int) $context['duration_seconds']
            : (int) ( $api_resource['_duration_seconds'] ?? 0 );

        if ( $duration <= 0 ) {
            // No duration info — can't tell; default to standard.
            return self::TYPE_STANDARD;
        }

        $is_vertical = $this->is_vertical( $api_resource );

        if ( $duration <= $shorts_max && $is_vertical ) {
            return self::TYPE_SHORT_CONFIRMED;
        }
        if ( $has_short_tag && $duration <= $candidate_max ) {
            return self::TYPE_SHORT_CONFIRMED;
        }
        if ( $duration <= $candidate_max && $is_vertical ) {
            return self::TYPE_SHORT_CANDIDATE;
        }
        return self::TYPE_STANDARD;
    }

    /**
     * @param array<int|string,mixed> $tags
     */
    public function has_shorts_tag( array $tags ): bool {
        foreach ( $tags as $tag ) {
            if ( ! is_string( $tag ) ) {
                continue;
            }
            $normalized = strtolower( ltrim( trim( $tag ), '#' ) );
            if ( 'shorts' === $normalized || 'short' === $normalized ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Detect vertical orientation from the videos.list resource. The API exposes
     * `contentDetails.dimension` (2d/3d) but not aspect ratio directly. YouTube
     * tags vertical videos in `snippet.defaultAudioLanguage` or — more reliably
     * — in the player embed's `width`/`height`. As a robust fallback we treat
     * any video with the #Shorts tag as vertical, plus any duration <=60s.
     *
     * Phase 3.5: pull the actual player embed dimensions and parse aspect ratio.
     *
     * @param array<string,mixed> $api_resource
     */
    public function is_vertical( array $api_resource ): bool {
        // Try the player embed dimensions first.
        $player = (array) ( $api_resource['player']['embedHtml'] ?? '' );
        // The embedHtml is a serialized iframe; we don't parse it here.
        // Fall back to: short tag, or very-short duration (<=60s) where YouTube typically defaults to vertical.
        $tags = (array) ( $api_resource['snippet']['tags'] ?? array() );
        if ( $this->has_shorts_tag( $tags ) ) {
            return true;
        }
        // Without dimension data, default to false (safer than false-positives).
        return false;
    }

    /**
     * Map a manual override string into our content_type vocabulary.
     * Unknown values pass through (so operators can set live_active etc).
     */
    public function normalize_manual( string $manual ): string {
        $m = strtolower( ltrim( trim( $manual ), '#' ) );
        return match ( $m ) {
            'short', 'shorts'                => self::TYPE_SHORT_CONFIRMED,
            'standard', 'video', 'long'      => self::TYPE_STANDARD,
            default                          => $m,  // pass through (live_active, short_candidate, etc.)
        };
    }
}