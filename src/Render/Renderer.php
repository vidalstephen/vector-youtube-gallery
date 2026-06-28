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

    public function __construct(
        private readonly FeedQuery $feed,
        private readonly VideoRenderer $video_renderer,
        private readonly TemplateLoader $templates,
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

        // Layout context.
        $ctx = array(
            'source'   => $source,
            'videos'   => $videos,
            'renderer' => $this->video_renderer,
            'attrs'    => array_merge( $args, array(
                'layout'   => $layout_slug,
                'offset'   => $offset,
                'total'    => $total,
            ) ),
        );

        /** @var LayoutInterface $layout_class */
        $layout_class = self::LAYOUTS[ $layout_slug ];
        $layout = new $layout_class();
        return $layout->render( $ctx );
    }
}