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
     *     @type string  $source_uuid    Required.
     *     @type string  $layout         One of: grid, list, featured, shorts, live. Default 'grid'.
     *     @type string  $content_type   Optional filter: 'short_confirmed,live_active' etc.
     *     @type string  $orderby        Optional: published_at, view_count, duration_seconds.
     *     @type string  $order          Optional: ASC or DESC. Default DESC.
     *     @type int     $per_page       Default 12.
     *     @type int     $offset         Default 0.
     *     @type string  $pagination     'none' | 'load_more'. Default 'none'.
     * }
     * @return string HTML.
     */
    public function render( array $args ): string {
        $source_uuid = (string) ( $args['source_uuid'] ?? '' );
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

        $wrapper_id = sanitize_text_field( (string) ( $args['wrapper_id'] ?? '' ) );
        if ( '' === $wrapper_id ) {
            $wrapper_id = 'vyg-feed-' . substr( md5( $source_uuid . '|' . $layout_slug ), 0, 8 );
        }
        $feed_uuid  = sanitize_text_field( (string) ( $args['feed_uuid'] ?? '' ) );
        $custom_css = (string) ( $args['custom_css'] ?? '' );

        $ctx = array(
            'source'     => $source,
            'videos'     => $videos,
            'renderer'   => $this->video_renderer,
            'wrapper_id' => $wrapper_id,
            'feed_uuid'  => $feed_uuid,
            'attrs'      => array_merge( $args, array(
                'layout'     => $layout_slug,
                'offset'     => $offset,
                'total'      => $total,
                'wrapper_id' => $wrapper_id,
                'feed_uuid'  => $feed_uuid,
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

        return $css_block . $layout_html;
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