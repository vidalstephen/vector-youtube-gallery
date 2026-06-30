<?php
/**
 * Front-end render helpers exposed as global functions so templates can
 * invoke them without dragging in a long namespace.
 *
 * @package VectorYT\Gallery
 */

declare(strict_types=1);

use VectorYT\Gallery\Integrations\WooCommerce\ProductLink;

defined('ABSPATH') || exit;

if (! function_exists('vyg_render_product_cta')) {
    /**
     * Render a WooCommerce CTA button for a video card, if applicable.
     *
     * @param array<string,mixed> $feed_config Decoded feed config
     * @param string              $video_id    11-char YouTube video ID
     */
    function vyg_render_product_cta(array $feed_config, string $video_id): string {
        return ProductLink::render_cta($feed_config, $video_id);
    }
}

if (! function_exists('vyg_product_url')) {
    /**
     * Resolve a WooCommerce product URL for a video card.
     *
     * @param array<string,mixed> $feed_config
     */
    function vyg_product_url(array $feed_config, string $video_id): string {
        return ProductLink::resolve_product_url($feed_config, $video_id);
    }
}
