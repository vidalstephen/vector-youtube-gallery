<?php
/**
 * Server-side renderer for the vectoryt/gallery block.
 *
 * Block editor passes attributes; we render via Renderer (same as shortcode).
 *
 * @package VectorYT\Gallery\Render
 */

defined( 'ABSPATH' ) || exit;

/**
 * @param array<string,mixed> $attributes
 * @return string
 */
function render_block_vectoryt_gallery( array $attributes ): string {
    $container = \VectorYT\Gallery\Plugin::container();
    if ( null === $container ) {
        return '<p>' . esc_html__( 'Vector YouTube Gallery is not active.', 'vector-youtube-gallery' ) . '</p>';
    }
    /** @var \VectorYT\Gallery\Render\Renderer $renderer */
    $renderer  = $container->get( 'render.renderer' );
    /** @var \VectorYT\Gallery\Render\FeedQuery $feed */
    $feed      = $container->get( 'render.feed' );
    /** @var \VectorYT\Gallery\Render\AssetManager $assets */
    $assets    = $container->get( 'render.assets' );

    $source_uuid = (string) ( $attributes['source_uuid'] ?? '' );
    if ( '' === $source_uuid ) {
        return '<p>' . esc_html__( 'Vector YouTube Gallery: select a source in the block settings.', 'vector-youtube-gallery' ) . '</p>';
    }

    $layout_slug = sanitize_key( (string) ( $attributes['layout'] ?? 'grid' ) );
    $assets->enqueue_for_layout( $layout_slug );
    if ( 'load_more' === (string) ( $attributes['pagination'] ?? '' ) ) {
        $assets->enqueue_load_more();
    }

    return $renderer->render( array(
        'source_uuid'  => $source_uuid,
        'layout'       => $layout_slug,
        'content_type' => (string) ( $attributes['content_type'] ?? '' ),
        'orderby'      => sanitize_key( (string) ( $attributes['orderby'] ?? 'published_at' ) ),
        'order'        => sanitize_key( (string) ( $attributes['order'] ?? 'DESC' ) ),
        'per_page'     => isset( $attributes['per_page'] ) ? max( 1, (int) $attributes['per_page'] ) : 12,
        'offset'       => isset( $attributes['offset'] ) ? max( 0, (int) $attributes['offset'] ) : 0,
        'pagination'   => sanitize_key( (string) ( $attributes['pagination'] ?? 'none' ) ),
        'columns'      => isset( $attributes['columns'] ) ? max( 1, (int) $attributes['columns'] ) : 3,
    ) );
}