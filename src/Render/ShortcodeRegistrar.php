<?php
/**
 * Shortcode registrar — exposes [youtube_feed] to the front-end.
 *
 * Attributes (all sanitized via shortcode_atts):
 *   - source_uuid (required) — UUID of a vyg_sources row.
 *   - layout      — grid|list|featured|shorts|live. Default grid.
 *   - per_page    — int, 1..200. Default 12.
 *   - columns     — int, 1..6 (used by grid/featured/shorts). Default 3.
 *   - orderby     — published_at|view_count|duration_seconds. Default published_at.
 *   - order       — ASC|DESC. Default DESC.
 *   - content_type — comma-separated list of content_types to include.
 *   - pagination  — none|load_more. Default none.
 *   - offset      — int (used by load_more; normally set by JS).
 *
 * Security:
 *   - All attrs are sanitized.
 *   - Source must be 'active' (not 'paused' / 'error').
 *   - Reads only happen via FeedQuery (no API calls).
 *
 * @package VectorYT\Gallery\Render
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Render;

defined( 'ABSPATH' ) || exit;

final class ShortcodeRegistrar {

    public const TAG = 'youtube_feed';

    public function __construct(
        private readonly Renderer $renderer,
        private readonly FeedQuery $feed,
        private readonly AssetManager $assets,
    ) {}

    public function register(): void {
        add_shortcode( self::TAG, array( $this, 'render_shortcode' ) );
    }

    /**
     * @param array<string,mixed> $atts
     * @param string|null $content
     * @return string
     */
    public function render_shortcode( $atts, $content = null ): string {
        $atts = shortcode_atts(
            array(
                'source_uuid'  => '',
                'layout'       => 'grid',
                'per_page'     => 12,
                'columns'      => 3,
                'orderby'      => 'published_at',
                'order'        => 'DESC',
                'content_type' => '',
                'pagination'   => 'none',
                'offset'       => 0,
                'wrapper_id'   => '',
            ),
            $atts,
            self::TAG
        );

        $source_uuid = sanitize_text_field( (string) $atts['source_uuid'] );
        if ( '' === $source_uuid ) {
            return '<p>' . esc_html__( 'Vector YouTube Gallery: missing source_uuid attribute.', 'vector-youtube-gallery' ) . '</p>';
        }

        // Confirm source exists and is active.
        $source = $this->feed->find_source_by_uuid( $source_uuid );
        if ( null === $source ) {
            return '<p>' . esc_html__( 'Vector YouTube Gallery: source not found.', 'vector-youtube-gallery' ) . '</p>';
        }
        if ( 'active' !== (string) ( $source['status'] ?? '' ) ) {
            return '<p>' . esc_html__( 'Vector YouTube Gallery: this source is not active.', 'vector-youtube-gallery' ) . '</p>';
        }

        // Enqueue assets (lightbox + CSS).
        $layout_slug = sanitize_key( (string) $atts['layout'] );
        $this->assets->enqueue_for_layout( $layout_slug );
        if ( 'load_more' === (string) $atts['pagination'] ) {
            $this->assets->enqueue_load_more();
        }

        return $this->renderer->render( array(
            'source_uuid'  => $source_uuid,
            'layout'       => $layout_slug,
            'content_type' => (string) $atts['content_type'],
            'orderby'      => sanitize_key( (string) $atts['orderby'] ),
            'order'        => sanitize_key( (string) $atts['order'] ),
            'per_page'     => max( 1, (int) $atts['per_page'] ),
            'offset'       => max( 0, (int) $atts['offset'] ),
            'pagination'   => sanitize_key( (string) $atts['pagination'] ),
            'columns'      => max( 1, (int) $atts['columns'] ),
            'wrapper_id'   => sanitize_text_field( (string) $atts['wrapper_id'] ),
        ) );
    }
}