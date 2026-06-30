<?php
/**
 * Phase 10.7 — product map MU-plugin.
 *
 * Drop into wp-content/mu-plugins/ before the Playwright capture
 * runs, then removed by scripts/run-phase10-7-playwright.sh.
 *
 * Registers a `vyg_phase10_7_product_map_for_feed` filter that
 * returns the seeded Phase 10.3 product mapping for the
 * `phase-10-7-feed` feed. Without this MU-plugin the seed data
 * can write the WC product and the source row, but the front-end
 * has no way to know that the WC product corresponds to the seed
 * video (the feed's JSON columns don't have a `products` top-level
 * slot — see Renderer::emit_html for the filter hook that reads
 * this).
 *
 * Idempotent: if the filter is already registered, this file is
 * a no-op.
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'vyg_phase10_7_product_map_for_feed', static function ( $current, $feed_uuid ) {
    if ( 'phase-10-7-feed' !== $feed_uuid ) {
        return $current;
    }
    $product_id = (int) get_option( 'vyg_phase10_7_product_id', 0 );
    if ( $product_id <= 0 ) {
        return $current;
    }
    return array( '9bZkp7q19f0' => $product_id );
}, 10, 2 );
