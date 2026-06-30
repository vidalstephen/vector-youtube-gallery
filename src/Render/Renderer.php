<?php
/**
 * Renderer — top-level orchestrator for the front-end.
 *
 * Reads from FeedQuery, picks the right Layout, and outputs HTML.
 * Used by ShortcodeRegistrar and BlockRegistrar (Phase 4) and by the REST
 * endpoint (Phase 4.6).
 *
 * Hard constraint: this class NEVER calls the YouTube API. All data is
 * served from local DB. Front-end latency is bounded by DB query time.
 *
 * @package VectorYT\Gallery\Render
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Render;

use VectorYT\Gallery\Render\Layouts\LayoutInterface;

defined( 'ABSPATH' ) || exit;

final class Renderer {

    /** Map of layout slug → layout class. */
    public const LAYOUTS = array(
        'grid'         => \VectorYT\Gallery\Render\Layouts\GridLayout::class,
        'list'         => \VectorYT\Gallery\Render\Layouts\ListLayout::class,
        'featured'     => \VectorYT\Gallery\Render\Layouts\FeaturedLayout::class,
        'shorts'       => \VectorYT\Gallery\Render\Layouts\ShortsLayout::class,
        'live'         => \VectorYT\Gallery\Render\Layouts\LiveLayout::class,
        'masonry'      => \VectorYT\Gallery\Render\Layouts\MasonryLayout::class,
        'carousel'     => \VectorYT\Gallery\Render\Layouts\CarouselLayout::class,
        'hero'         => \VectorYT\Gallery\Render\Layouts\HeroLayout::class,
    );

    /**
     * Layouts that need a LiveQuery dependency injected.
     *
     * @var array<string,bool>
     */
    private const LAYOUTS_NEEDING_LIVE_QUERY = array(
        'live' => true,
    );

    public function __construct(
        private readonly FeedQuery $feed,
        private readonly VideoRenderer $video_renderer,
        private readonly TemplateLoader $templates,
        private readonly LiveQuery $live_query,
    ) {}

    /**
     * Render a feed.
     *
     * @param array<string,mixed> $args {
     *     @type string  $source_uuid    Required for legacy single-source feeds.
     *     @type array<string,mixed> $source_config Optional canonical multi-source config (Phase 8).
     *     @type string  $layout         One of: grid, list, featured, shorts, live, masonry, carousel, hero. Default 'grid'.
     *     @type string  $content_type   Optional filter: 'short_confirmed,live_active' etc.
     *     @type string  $orderby        Optional: published_at, view_count, duration_seconds.
     *     @type string  $order          Optional: ASC or DESC. Default DESC.
     *     @type int     $per_page       Default 12.
     *     @type int     $offset         Default 0.
     *     @type string  $pagination     'none' | 'load_more'. Default 'none'.
     *     @type bool    $schema_enabled Emit JSON-LD schema.org markup. Default false.
     * }
     * @return string HTML.
     */
    public function render( array $args ): string {
        $source_uuid = (string) ( $args['source_uuid'] ?? '' );
        $source_cfg  = isset( $args['source_config'] ) && is_array( $args['source_config'] ) ? $args['source_config'] : null;

        // Multi-source path (Phase 8): when source_config has any sources or
        // manual_video_ids, route through FeedQuery::videos_for_feed.
        if ( null !== $source_cfg ) {
            $has_multi = ! empty( $source_cfg['sources'] ) || ! empty( $source_cfg['manual_video_ids'] );
            if ( $has_multi ) {
                return $this->render_multi_source( $args, $source_cfg );
            }
            // Fall through: legacy single-source config under the new key.
            if ( '' !== $source_uuid ) {
                $args['source_uuid'] = $source_uuid;
            } elseif ( ! empty( $source_cfg['sources'][0]['source_uuid'] ) ) {
                $args['source_uuid'] = (string) $source_cfg['sources'][0]['source_uuid'];
            }
        }

        if ( '' === $source_uuid ) {
            return '<p>' . esc_html__( 'Missing source_uuid.', 'vector-youtube-gallery' ) . '</p>';
        }

        $layout_slug = sanitize_key( (string) ( $args['layout'] ?? 'grid' ) );
        if ( ! isset( self::LAYOUTS[ $layout_slug ] ) ) {
            $layout_slug = 'grid';
        }

        // Live layout: also auto-restrict to live content (live + upcoming + recently ended).
        if ( 'live' === $layout_slug && empty( $args['content_type'] ) ) {
            $args['content_type'] = 'live_active,live_upcoming,live_replay';
        }
        // Shorts layout: restrict to short content.
        if ( 'shorts' === $layout_slug && empty( $args['content_type'] ) ) {
            $args['content_type'] = 'short_confirmed,short_candidate';
        }

        $per_page = isset( $args['per_page'] ) ? max( 1, min( 200, (int) $args['per_page'] ) ) : 12;
        $offset   = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

        $source = $this->feed->find_source_by_uuid( $source_uuid );
        if ( null === $source ) {
            return '<p>' . esc_html__( 'Source not found.', 'vector-youtube-gallery' ) . '</p>';
        }

        $videos = $this->feed->videos_for_source( array(
            'source_uuid'  => $source_uuid,
            'content_type' => (string) ( $args['content_type'] ?? '' ),
            'orderby'      => (string) ( $args['orderby'] ?? 'published_at' ),
            'order'        => (string) ( $args['order'] ?? 'DESC' ),
            'limit'        => $per_page,
            'offset'       => $offset,
        ) );

        $total = $this->feed->count_videos_for_source( array(
            'source_uuid'  => $source_uuid,
            'content_type' => (string) ( $args['content_type'] ?? '' ),
        ) );

        return $this->emit_html( $args, $source, $videos, $total, $layout_slug, $per_page, $offset );
    }

    /**
     * Render a feed that uses multiple sources + manual video IDs.
     *
     * @param array<string,mixed> $args
     * @param array<string,mixed> $source_config
     */
    private function render_multi_source( array $args, array $source_config ): string {
        $layout_slug = sanitize_key( (string) ( $args['layout'] ?? 'grid' ) );
        if ( ! isset( self::LAYOUTS[ $layout_slug ] ) ) {
            $layout_slug = 'grid';
        }
        if ( 'live' === $layout_slug && empty( $args['content_type'] ) ) {
            $args['content_type'] = 'live_active,live_upcoming,live_replay';
        }
        if ( 'shorts' === $layout_slug && empty( $args['content_type'] ) ) {
            $args['content_type'] = 'short_confirmed,short_candidate';
        }

        $per_page = isset( $args['per_page'] ) ? max( 1, min( 200, (int) $args['per_page'] ) ) : 12;
        $offset   = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

        $videos = $this->feed->videos_for_feed( array(
            'source'             => $source_config,
            'content_type'       => (string) ( $args['content_type'] ?? '' ),
            'orderby'            => (string) ( $args['orderby'] ?? 'published_at' ),
            'order'              => (string) ( $args['order'] ?? 'DESC' ),
            'limit'              => $per_page,
            'offset'             => $offset,
            'include_unavailable' => ! empty( $args['include_unavailable'] ),
            'include_hidden'      => ! empty( $args['include_hidden'] ),
            'include_manual'      => ! isset( $args['include_manual'] ) || (bool) $args['include_manual'],
        ) );

        $total = $this->feed->count_videos_for_feed( array(
            'source'             => $source_config,
            'content_type'       => (string) ( $args['content_type'] ?? '' ),
            'include_unavailable' => ! empty( $args['include_unavailable'] ),
            'include_hidden'      => ! empty( $args['include_hidden'] ),
            'include_manual'      => ! isset( $args['include_manual'] ) || (bool) $args['include_manual'],
        ) );

        // Render context: pick the first source for legacy display attrs
        // (templates consume $source for channel title, badge, etc.).
        $first_source = null;
        if ( ! empty( $source_config['sources'] ) && ! empty( $source_config['sources'][0]['source_uuid'] ) ) {
            $first_source = $this->feed->find_source_by_uuid( (string) $source_config['sources'][0]['source_uuid'] );
        }
        // Fallback "source" object so templates don't blow up when there are
        // only manual_video_ids and no resolved source.
        if ( null === $first_source ) {
            $first_source = array(
                'source_uuid' => '',
                'source_type' => 'manual',
                'title'       => __( 'Curated videos', 'vector-youtube-gallery' ),
            );
        }
        return $this->emit_html( $args, $first_source, $videos, $total, $layout_slug, $per_page, $offset );
    }

    /**
     * Common HTML emit path for both single-source and multi-source render.
     *
     * @param array<string,mixed>        $args
     * @param array<string,mixed>|null   $source
     * @param array<int,array<string,mixed>> $videos
     */
    private function emit_html( array $args, ?array $source, array $videos, int $total, string $layout_slug, int $per_page, int $offset ): string {
        $wrapper_id = sanitize_text_field( (string) ( $args['wrapper_id'] ?? '' ) );
        if ( '' === $wrapper_id ) {
            $source_uuid = (string) ( $source['source_uuid'] ?? '' );
            $wrapper_id = 'vyg-feed-' . substr( md5( $source_uuid . '|' . $layout_slug ), 0, 8 );
        }
        $feed_uuid  = sanitize_text_field( (string) ( $args['feed_uuid'] ?? '' ) );
        $custom_css = (string) ( $args['custom_css'] ?? '' );
        $public_safe = ! empty( $args['public_safe'] );
        $preset     = \VectorYT\Gallery\Render\Presets::sanitize_slug( (string) ( $args['preset'] ?? 'default' ) );

        $ctx = array(
            'source'       => $source,
            'videos'       => $videos,
            'renderer'     => $this->video_renderer,
            'wrapper_id'   => $wrapper_id,
            'feed_uuid'    => $feed_uuid,
            'feed_config'  => (array) ( $args['feed_config'] ?? array() ),
            'preset'       => $preset,
            'attrs'        => array_merge( $args, array(
                'layout'      => $layout_slug,
                'offset'      => $offset,
                'total'       => $total,
                'per_page'    => $per_page,
                'wrapper_id'  => $wrapper_id,
                'feed_uuid'   => $feed_uuid,
                'public_safe' => $public_safe,
                'preset'      => $preset,
            ) ),
        );

        /** @var LayoutInterface $layout_class */
        $layout_class = self::LAYOUTS[ $layout_slug ];
        if ( isset( self::LAYOUTS_NEEDING_LIVE_QUERY[ $layout_slug ] ) ) {
            $layout = new $layout_class( $this->live_query );
        } else {
            $layout = new $layout_class();
        }
        $layout_html = $layout->render( $ctx );

        // Emit scoped custom CSS (Phase 6.3). The CSS is validated upstream
        // (FeedRepository::sanitize) to strip tags; we additionally scope it
        // to the wrapper_id selector so a feed cannot bleed styles into other
        // galleries on the page.
        $css_block = '';
        if ( '' !== $custom_css ) {
            // Defense in depth: even if upstream sanitization missed something,
            // strip any remaining < / > characters before emitting into a
            // <style> block. This prevents a stored XSS via custom CSS that
            // somehow retained an HTML tag (e.g. via direct DB write).
            $safe_css = str_replace( array( '<', '>' ), '', $custom_css );
            $css_block = "<style id=\"vyg-custom-css-{$wrapper_id}\">\n"
                . self::scope_css( $safe_css, "#{$wrapper_id}" )
                . "\n</style>\n";
        }

        $preset_css = '';
        if ( 'default' !== $preset ) {
            $preset_css = "<style id=\"vyg-preset-{$wrapper_id}\">\n"
                . \VectorYT\Gallery\Render\Presets::emit_css( $preset )
                . "\n</style>\n";
        }
        return $preset_css . $css_block . $layout_html . \VectorYT\Gallery\Render\SchemaLd::render( $source, $videos, $args );
    }

    /**
     * Scope every selector in a CSS string to a parent selector prefix.
     * Strategy: rewrite top-level selectors (those starting the rule, before
     * the first "{") so they're prefixed with "#wrapper_id ". Media queries
     * and @keyframes are passed through verbatim.
     */
    public static function scope_css( string $css, string $parent_selector ): string {
        // Strip CDATA / comments defensively.
        $css = preg_replace( '/\/\*.*?\*\//s', '', $css ) ?? $css;
        // Tokenize: rules + at-rules.
        $tokens = preg_split( '/([}{])/', $css, -1, PREG_SPLIT_DELIM_CAPTURE );
        $out = '';
        $depth = 0;
        $buffer_selector = '';
        foreach ( $tokens as $tok ) {
            if ( '{' === $tok ) {
                $selector = trim( $buffer_selector );
                if ( str_starts_with( $selector, '@' ) ) {
                    // @media, @supports, @keyframes — pass through unchanged.
                    $out .= $selector . '{';
                } else {
                    $out .= $parent_selector . ' ' . $selector . ' {';
                }
                $buffer_selector = '';
                ++$depth;
            } elseif ( '}' === $tok ) {
                $out .= '}';
                --$depth;
            } else {
                if ( 0 === $depth ) {
                    $buffer_selector .= $tok;
                } else {
                    $out .= $tok;
                }
            }
        }
        return trim( $out );
    }
}