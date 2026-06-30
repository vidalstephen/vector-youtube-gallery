<?php
/**
 * Server-side renderer for the vectoryt/gallery block.
 *
 * Block editor passes attributes; we render via Renderer (same as shortcode
 * / REST). Phase 10.4 polish adds:
 *   - feed_uuid takes precedence over legacy source_uuid (Phase 8.4
 *     multi-source path).
 *   - preset attribute forwarded.
 *   - public_safe set when feed_uuid is in use.
 *
 * @package VectorYT\Gallery\Render
 */

defined('ABSPATH') || exit;

/**
 * @param array<string,mixed> $attributes
 * @return string
 */
function render_block_vectoryt_gallery(array $attributes): string {
    $container = \VectorYT\Gallery\Plugin::container();
    if (null === $container) {
        return '<p>' . esc_html__('Vector YouTube Gallery is not active.', 'vector-youtube-gallery') . '</p>';
    }
    /** @var \VectorYT\Gallery\Render\Renderer $renderer */
    $renderer  = $container->get('render.renderer');
    /** @var \VectorYT\Gallery\Render\AssetManager $assets */
    $assets    = $container->get('render.assets');
    /** @var \VectorYT\Gallery\Repository\FeedRepository $feeds */
    $feeds     = $container->get('repo.feeds');

    $feed_uuid   = sanitize_text_field((string) ($attributes['feed_uuid'] ?? ''));
    $source_uuid = sanitize_text_field((string) ($attributes['source_uuid'] ?? ''));

    // Resolve feed_config + source_config (Phase 10.3/10.4) when a saved feed is selected.
    $feed_config = array();
    $source_config = null;
    if ('' !== $feed_uuid) {
        $row = $feeds->find_by_uuid($feed_uuid);
        if (null === $row) {
            // Saved feed was deleted between block creation and render.
            return '<p>' . esc_html__('Saved feed not found.', 'vector-youtube-gallery') . '</p>';
        }
        $feed_config = is_array($row) ? \VectorYT\Gallery\Repository\FeedRepository::decode_config($row) : array();
        $source_config = isset($feed_config['source']) && is_array($feed_config['source']) ? $feed_config['source'] : null;
    } elseif ('' === $source_uuid) {
        return '<p>' . esc_html__('Vector YouTube Gallery: pick a saved feed or paste a source UUID in the block settings.', 'vector-youtube-gallery') . '</p>';
    }

    $layout_slug = sanitize_key((string) ($attributes['layout'] ?? 'grid'));
    $assets->enqueue_for_layout($layout_slug);
    if ('load_more' === (string) ($attributes['pagination'] ?? '')) {
        $assets->enqueue_load_more();
    }

    $args = array(
        'source_uuid'    => ('' !== $feed_uuid) ? '' : $source_uuid,
        'feed_uuid'      => $feed_uuid,
        'feed_config'    => $feed_config,
        'source_config'  => $source_config,
        'layout'         => $layout_slug,
        'content_type'   => (string) ($attributes['content_type'] ?? ''),
        'orderby'        => sanitize_key((string) ($attributes['orderby'] ?? 'published_at')),
        'order'          => sanitize_key((string) ($attributes['order'] ?? 'DESC')),
        'per_page'       => isset($attributes['per_page']) ? max(1, (int) $attributes['per_page']) : 12,
        'offset'         => isset($attributes['offset']) ? max(0, (int) $attributes['offset']) : 0,
        'pagination'     => sanitize_key((string) ($attributes['pagination'] ?? 'none')),
        'columns'        => isset($attributes['columns']) ? max(1, (int) $attributes['columns']) : 3,
        'schema_enabled' => ! empty($attributes['schema_enabled']),
        'preset'         => sanitize_key((string) ($attributes['preset'] ?? 'default')),
        'public_safe'    => '' !== $feed_uuid,
    );

    return $renderer->render($args);
}
