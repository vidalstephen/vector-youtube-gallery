<?php
/**
 * Template attribute helper — used by front-end templates to render the
 * shared data-* attributes (uuid, layout, offset) consistently for both
 * legacy single-source feeds and Phase 8 mixed-source feeds.
 *
 * Phase 8.4:
 *  - Templates emit `data-feed-uuid` when a saved feed renders (the feed
 *    has a feed_uuid); the load-more.js routes through /wp-json/vyg/v1/feed/<uuid>
 *    which never leaks internal source IDs.
 *  - Templates fall back to `data-source-uuid` for legacy `[youtube_feed source_uuid=…]`
 *    shortcodes that have no feed record.
 *
 * @package VectorYT\Gallery\Render
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Render;

defined( 'ABSPATH' ) || exit;

final class TemplateAttributes {

    /**
     * Phase 14.1 — width modes allow-list. The prototype's panel exposes
     * three preset widths; the shortcode / block / Elementor surfaces
     * accept these values verbatim. Anything outside the list falls back
     * to 'wide' so the rendered CSS class is always a known token.
     *
     * @var string[]
     */
    public const WIDTH_MODES = array( 'theme', 'wide', 'full' );

    /**
     * Build the shared attribute map for the root feed <div>.
     *
     * When $public_safe is true, internal source_uuid is omitted from the
     * rendered attributes. This is the Phase 8.4 public REST endpoint's
     * mode: the response payload must not leak any internal IDs that the
     * front-end doesn't need to call back into the system.
     *
     * Phase 14.1 also emits `data-vyg-width` so the consumer (CSS, JS,
     * inspection) can read the width from a single attribute. The
     * matching CSS class (.vyg-theme / .vyg-wide / .vyg-full) is
     * provided separately by `width_class()` and is appended to the
     * layout template's own class string.
     *
     * @param array<string,mixed> $attrs
     * @param array<string,mixed>|null $source
     * @param bool $public_safe
     * @return array<string,string> Attributes to interpolate into the root element.
     */
    public static function feed_root( array $attrs, ?array $source, bool $public_safe = false ): array {
        $out = array(
            'data-layout'      => sanitize_key( (string) ( $attrs['layout'] ?? 'grid' ) ),
            'data-vyg-width'   => self::sanitize_width( $attrs ),
        );
        $feed_uuid = (string) ( $attrs['feed_uuid'] ?? '' );
        if ( '' !== $feed_uuid ) {
            $out['data-feed-uuid'] = sanitize_text_field( $feed_uuid );
        }
        if ( ! $public_safe ) {
            $source_uuid = (string) ( $source['source_uuid'] ?? '' );
            if ( '' !== $source_uuid ) {
                $out['data-source-uuid'] = sanitize_text_field( $source_uuid );
            }
        }
        return $out;
    }

    /**
     * Phase 14.1 — return the matching CSS class fragment for the
     * width mode on $attrs. Layout templates append this to their own
     * root-class string:
     *
     *   $classes = 'vyg-feed vyg-feed--grid vyg-grid vyg-grid--cols-3 ' .
     *              TemplateAttributes::width_class( $attrs );
     *
     * Unknown / missing values fall back to 'vyg-wide' so a stray value
     * never produces a non-existent CSS class.
     *
     * @param array<string,mixed> $attrs
     * @return string E.g. 'vyg-theme', 'vyg-wide', 'vyg-full'.
     */
    public static function width_class( array $attrs ): string {
        return 'vyg-' . self::sanitize_width( $attrs );
    }

    /**
     * Phase 14.1 — normalize the width attr to a known mode.
     *
     * @param array<string,mixed> $attrs
     * @return string One of 'theme', 'wide', 'full'.
     */
    private static function sanitize_width( array $attrs ): string {
        $candidate = (string) ( $attrs['width'] ?? '' );
        if ( in_array( $candidate, self::WIDTH_MODES, true ) ) {
            return $candidate;
        }
        return 'wide';
    }

    /**
     * Build the shared attribute map for the load-more <button>.
     *
     * Phase 8.4 public-safe mode: when public_safe is true and a feed_uuid
     * is present, omit the source_uuid attribute (the load-more endpoint
     * routes via feed_uuid, not source_uuid).
     *
     * @param array<string,mixed> $attrs
     * @param array<string,mixed>|null $source
     * @param int $next_offset
     * @param bool $public_safe
     * @return array<string,string>
     */
    public static function load_more( array $attrs, ?array $source, int $next_offset, bool $public_safe = false ): array {
        $out = array(
            'data-offset' => (string) max( 0, $next_offset ),
            'data-layout' => sanitize_key( (string) ( $attrs['layout'] ?? 'grid' ) ),
            'data-nonce'  => wp_create_nonce( 'vyg_load_more' ),
        );
        $feed_uuid = (string) ( $attrs['feed_uuid'] ?? '' );
        if ( '' !== $feed_uuid ) {
            $out['data-feed-uuid'] = sanitize_text_field( $feed_uuid );
            if ( $public_safe ) {
                return $out;
            }
        }
        if ( ! $public_safe ) {
            $source_uuid = (string) ( $source['source_uuid'] ?? '' );
            if ( '' !== $source_uuid ) {
                $out['data-source-uuid'] = sanitize_text_field( $source_uuid );
            }
        }
        return $out;
    }

    /**
     * Render an attribute list as a string suitable for inline HTML.
     *
     * @param array<string,string> $attrs
     * @return string
     */
    public static function to_html( array $attrs ): string {
        $parts = array();
        foreach ( $attrs as $name => $value ) {
            $parts[] = esc_attr( (string) $name ) . '="' . esc_attr( (string) $value ) . '"';
        }
        return implode( ' ', $parts );
    }
}