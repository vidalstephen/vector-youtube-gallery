<?php
/**
 * Phase 10.3 — WooCommerce product CTA integration.
 *
 * Hard rules:
 *   - **No WooCommerce hard dependency.** This class is loaded at boot
 *     and is safe to instantiate even when WooCommerce is not active.
 *   - **No YouTube API calls.** The mapping lives in feed-config JSON
 *     that the operator curated themselves; nothing on render touches
 *     upstream APIs.
 *   - **No hidden costs.** Returning no product URL is always the
 *     safe fallback — buttons render only when a product exists AND
 *     the WooCommerce `product` post-type is registered.
 *
 * @package VectorYT\Gallery\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Integrations\WooCommerce;

defined('ABSPATH') || exit;

final class ProductLink {

    /**
     * @return bool True if WooCommerce is registered (functions + post
     *              type available). The result is cached for the
     *              request.
     */
    /**
     * @return bool True if WooCommerce is registered (functions + post
     *              type available).
     *
     * Note: previously this used a static cache, but tests need to
     * flip conditions mid-suite as they wire Brain Monkey stubs on
     * and off. The function-level check (function_exists + post_type_exists)
     * is fast enough — it's two global lookups — and is_correctness
     * over caching for a seldom-called render-time helper.
     */
    public static function is_active(): bool {
        return (function_exists('wc_get_product') && post_type_exists('product'));
    }

    /**
     * Resolve a product URL for a single video in a feed.
     *
     * @param array<string,mixed> $feed_config The decoded feed config
     *                                         (from FeedRepository::decode_config).
     * @param string              $video_id    The normalized 11-char
     *                                         YouTube video ID.
     * @return string Empty string when no mapping exists OR the product
     *                has been deleted OR WooCommerce is inactive.
     */
    public static function resolve_product_url(array $feed_config, string $video_id): string {
        if (! self::is_active()) {
            return '';
        }
        if ('' === $video_id) {
            return '';
        }
        $mapping = self::mapping_from_config($feed_config);
        if (! isset($mapping[$video_id])) {
            return '';
        }
        $product_id = (int) $mapping[$video_id];
        if ($product_id <= 0) {
            return '';
        }
        $status = get_post_status($product_id);
        if ('publish' !== $status) {
            // Don't show a CTA to a draft/private/trash product.
            return '';
        }
        return (string) get_permalink($product_id);
    }

    /**
     * Pull the {video_id: product_id} mapping out of feed config, with
     * coercion and sanitization.
     *
     * Stored shape (Phase 10.3 convention):
     *   $config['products'] = [
     *       '<11-char-youtube-id>' => <int-product-id>,
     *       ...
     *   ]
     *
     * @return array<string,int>
     */
    public static function mapping_from_config(array $config): array {
        $raw = $config['products'] ?? array();
        if (! is_array($raw)) {
            return array();
        }
        $out = array();
        foreach ($raw as $vid => $pid) {
            if (! is_string($vid) || ! self::is_youtube_id($vid)) {
                continue;
            }
            $pid_int = is_numeric($pid) ? (int) $pid : 0;
            if ($pid_int <= 0) {
                continue;
            }
            $out[$vid] = $pid_int;
        }
        return $out;
    }

    /**
     * Light YouTube-ID validator (11 chars, A-Za-z0-9_-). Same rule
     * applied throughout the codebase (FeedQuery, VideoResolver, etc).
     */
    public static function is_youtube_id(string $id): bool {
        return (bool) preg_match('/^[A-Za-z0-9_-]{11}$/', $id);
    }

    /**
     * Render the CTA button HTML. Empty string when not applicable.
     *
     * @param array<string,mixed> $feed_config
     */
    public static function render_cta(array $feed_config, string $video_id): string {
        $url = self::resolve_product_url($feed_config, $video_id);
        if ('' === $url) {
            return '';
        }
        $label = apply_filters(
            'vyg_wc_cta_label',
            __('View product', 'vector-youtube-gallery'),
            $feed_config,
            $video_id
        );
        $price = self::format_price_for_id(self::mapping_from_config($feed_config)[$video_id] ?? 0);
        $aria  = sprintf('%s%s', esc_html((string) $label), '' !== $price ? ', ' . esc_html($price) : '');
        return sprintf(
            '<a class="vyg-card__cta vyg-card__cta--product" href="%s" rel="nofollow noopener" aria-label="%s" data-vyg-cta-source="product-link">%s%s</a>',
            esc_url($url),
            $aria,
            esc_html((string) $label),
            '' !== $price ? ' <span class="vyg-card__cta-price">' . esc_html($price) . '</span>' : ''
        );
    }

    /**
     * @return string WooCommerce-formatted price (e.g. "$25.00"). Empty
     *                string when not available.
     */
    private static function format_price_for_id(int $product_id): string {
        if ($product_id <= 0) {
            return '';
        }
        $product = wc_get_product($product_id);
        if (! $product) {
            return '';
        }
        $price_html = (string) $product->get_price_html();
        // Strip HTML for the inline CTA — we only want the textual price.
        $price_text = trim((string) preg_replace('/<[^>]+>/', '', $price_html));
        return $price_text;
    }
}
