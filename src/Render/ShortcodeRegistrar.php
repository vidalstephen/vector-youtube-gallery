<?php
/**
 * Shortcode registrar — exposes [youtube_feed] to the front-end.
 *
 * Two modes:
 *  - source_uuid (legacy / direct):  [youtube_feed source_uuid="..." layout="grid"]
 *  - feed_uuid (Phase 6 named feed): [youtube_feed feed_uuid="..."]
 *
 * The feed_uuid path loads the saved configuration from vyg_feeds and overlays
 * any inline attributes the operator passed. Inline attributes take precedence.
 *
 * Attributes (all sanitized via shortcode_atts):
 *   - feed_uuid    — UUID of a vyg_feeds row (Phase 6).
 *   - source_uuid  — UUID of a vyg_sources row (legacy; required if no feed_uuid).
 *   - layout       — grid|list|featured|shorts|live. Default grid.
 *   - per_page     — int, 1..200. Default 12.
 *   - columns      — int, 1..6 (used by grid/featured/shorts). Default 3.
 *   - orderby      — published_at|view_count|duration_seconds. Default published_at.
 *   - order        — ASC|DESC. Default DESC.
 *   - content_type — comma-separated list of content_types to include.
 *   - pagination   — none|load_more. Default none.
 *   - offset       — int (used by load_more; normally set by JS).
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

use VectorYT\Gallery\Repository\FeedRepository;

defined( 'ABSPATH' ) || exit;

final class ShortcodeRegistrar {

    public const TAG = 'youtube_feed';

    public function __construct(
        private readonly Renderer $renderer,
        private readonly FeedQuery $feed,
        private readonly AssetManager $assets,
        private readonly FeedRepository $feeds_repo,
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
                'feed_uuid'    => '',
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
        $feed_uuid   = sanitize_text_field( (string) $atts['feed_uuid'] );
        $layout_slug = sanitize_key( (string) $atts['layout'] );

        // If feed_uuid provided, overlay saved config onto inline attributes.
        $inline_override = ''; // CSS wrapper id override
        $feed_record = null;
        if ( '' !== $feed_uuid ) {
            $feed_record = $this->feeds_repo->find_by_uuid( $feed_uuid );
            if ( ! $feed_record ) {
                return '<p>' . esc_html__( 'Vector YouTube Gallery: feed not found.', 'vector-youtube-gallery' ) . '</p>';
            }
            if ( 'archived' === (string) ( $feed_record['status'] ?? '' ) ) {
                return '<p>' . esc_html__( 'Vector YouTube Gallery: this feed is archived.', 'vector-youtube-gallery' ) . '</p>';
            }
            $config = FeedRepository::decode_config( $feed_record );
            if ( '' === $source_uuid ) {
                // Phase 8: legacy single-source config stores source_uuid at the
                // top level; normalized multi-source form puts it in sources[].
                if ( ! empty( $config['source']['source_uuid'] ) ) {
                    $source_uuid = (string) $config['source']['source_uuid'];
                } elseif ( ! empty( $config['source']['sources'][0]['source_uuid'] ) ) {
                    $source_uuid = (string) $config['source']['sources'][0]['source_uuid'];
                }
            }
            // Inline attributes override saved config.
            if ( 'grid' === $layout_slug && ! empty( $feed_record['layout'] ) ) {
                $layout_slug = sanitize_key( (string) $feed_record['layout'] );
            }
            if ( 12 === (int) $atts['per_page'] && ! empty( $config['display']['per_page'] ) ) {
                $atts['per_page'] = (int) $config['display']['per_page'];
            }
            if ( 3 === (int) $atts['columns'] && ! empty( $config['display']['columns'] ) ) {
                $atts['columns'] = (int) $config['display']['columns'];
            }
            if ( 'published_at' === (string) $atts['orderby'] && ! empty( $config['sort']['orderby'] ) ) {
                $atts['orderby'] = (string) $config['sort']['orderby'];
            }
            if ( 'DESC' === (string) $atts['order'] && ! empty( $config['sort']['order'] ) ) {
                $atts['order'] = (string) $config['sort']['order'];
            }
            if ( '' === (string) $atts['content_type'] && ! empty( $config['filter']['content_type'] ) ) {
                $atts['content_type'] = (string) $config['filter']['content_type'];
            }
            if ( 'none' === (string) $atts['pagination'] && ! empty( $config['display']['load_more'] ) ) {
                $atts['pagination'] = 'load_more';
            }
            $inline_override = (string) ( $feed_record['custom_css'] ?? '' );
        }

        if ( '' === $source_uuid ) {
            return '<p>' . esc_html__( 'Vector YouTube Gallery: missing source_uuid/feed_uuid attribute.', 'vector-youtube-gallery' ) . '</p>';
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
        $this->assets->enqueue_for_layout( $layout_slug );
        if ( 'load_more' === (string) $atts['pagination'] ) {
            $this->assets->enqueue_load_more();
        }

        // Resolve wrapper_id: feed_uuid if no override provided.
        $wrapper_id = sanitize_text_field( (string) $atts['wrapper_id'] );
        if ( '' === $wrapper_id && $feed_record ) {
            $wrapper_id = 'vyg-feed-' . (string) ( $feed_record['feed_uuid'] ?? '' );
        }

        // Phase 8.8: public-safe attribute — strip internal source_uuid from the
        // rendered HTML on PUBLIC pages. Saved mixed-source feeds (feed_uuid
        // set) always render via REST, which only needs feed_uuid; the legacy
        // source_uuid shortcode path keeps source_uuid so its inline
        // load-more.js can still find the legacy endpoint.
        $public_safe = '' !== $feed_uuid;

        return $this->renderer->render( array(
            'source_uuid'   => $source_uuid,
            'source_config' => isset( $feed_record ) ? ( $config['source'] ?? array() ) : null,
            'feed_uuid'     => (string) ( $feed_record['feed_uuid'] ?? '' ),
            'layout'        => $layout_slug,
            'content_type'  => (string) $atts['content_type'],
            'public_safe'   => $public_safe,
            'orderby'       => sanitize_key( (string) $atts['orderby'] ),
            'order'         => sanitize_key( (string) $atts['order'] ),
            'per_page'      => max( 1, (int) $atts['per_page'] ),
            'offset'        => max( 0, (int) $atts['offset'] ),
            'pagination'    => sanitize_key( (string) $atts['pagination'] ),
            'columns'       => max( 1, (int) $atts['columns'] ),
            'wrapper_id'    => $wrapper_id,
            'custom_css'    => $inline_override,
        ) );
    }
}