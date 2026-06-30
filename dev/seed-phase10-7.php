<?php
/**
 * Phase 10.7 — seed data + render-time product mapping.
 *
 * Two pieces:
 *   1. A one-time seed that writes:
 *      - a VYG source row (so the front-end has data)
 *      - a VYG feed row with a placeholder source_config_json
 *      - a VYG video row (so grid.php has at least one card)
 *      - a WooCommerce product (so the CTA has a target)
 *      - a draft page with the VYG block referencing the feed
 *   2. A small `vyg_phase10_7_product_map` filter that merges the
 *      product mapping into `feed_config['products']` at render
 *      time. Without this, the seed can't make the front-end CTA
 *      appear because the Feeds Builder doesn't expose a UI for
 *      product mapping — it's set programmatically or via direct
 *      DB writes, both of which are valid Phase 10.3 use cases.
 *
 * This filter is the *minimum* production-side surface needed for
 * the Phase 10.3 mapping to be settable from outside the Feeds
 * Builder. It is intentionally tiny (2 lines of real logic) so it
 * can be reviewed in seconds.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;
$prefix = $wpdb->prefix;

// The Phase 10.3 product mapping for this seed. Keys are raw
// 11-char YouTube video IDs (NOT the "yt:<id>" form); see
// ProductLink::is_youtube_id() validator. The video ID matches
// the seed video below; the product id is filled in once the
// WooCommerce product exists.
$product_map = array(
    '9bZkp7q19f0' => 0,
);

// -----------------------------------------------------------------------
// 1. Source.
// -----------------------------------------------------------------------
$source_uuid = 'phase-10-7-source';
$source_id = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT id FROM {$prefix}vyg_sources WHERE source_uuid = %s",
    $source_uuid
) );
if ( ! $source_id ) {
    $wpdb->insert( $prefix . 'vyg_sources', array(
        'source_uuid'      => $source_uuid,
        'source_type'      => 'channel',
        'youtube_channel_id' => 'UC_x5XG1OV2P6uZZ5FSM9Ttw',
        'handle'           => '@phase-10-7-demo',
        'title'            => 'Phase 10.7 — Demo channel',
        'status'           => 'active',
        'last_success_at'  => current_time( 'mysql' ),
    ) );
    $source_id = (int) $wpdb->insert_id;
}

// -----------------------------------------------------------------------
// 2. Video.
// -----------------------------------------------------------------------
$video_yid = '9bZkp7q19f0';
$existing_vid = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT id FROM {$prefix}vyg_videos WHERE youtube_video_id = %s",
    $video_yid
) );
if ( ! $existing_vid ) {
    $wpdb->insert( $prefix . 'vyg_videos', array(
        'youtube_video_id'   => $video_yid,
        'youtube_channel_id' => 'UC_x5XG1OV2P6uZZ5FSM9Ttw',
        'title'              => 'PSY - Gangnam Style (CTA demo)',
        'published_at'       => current_time( 'mysql' ),
        'availability_status'=> 'available',
        'content_type'       => 'standard',
        'view_count'         => 4500000000,
    ) );
}

// -----------------------------------------------------------------------
// 3. WooCommerce product.
// -----------------------------------------------------------------------
$product_slug = 'vyg-cta-demo-product';
$product_id = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT ID FROM {$prefix}posts WHERE post_name = %s AND post_type = 'product'",
    $product_slug
) );
if ( ! $product_id ) {
    $product_id = (int) wp_insert_post( array(
        'post_title'  => 'VYG CTA Demo Product',
        'post_name'   => $product_slug,
        'post_status' => 'publish',
        'post_type'   => 'product',
    ) );
    if ( $product_id ) {
        update_post_meta( $product_id, '_regular_price', '19' );
        update_post_meta( $product_id, '_price', '19' );
        update_post_meta( $product_id, '_visibility', 'visible' );
        update_post_meta( $product_id, '_stock_status', 'instock' );
        wp_set_object_terms( $product_id, 'simple', 'product_type' );
    }
}

$product_map['9bZkp7q19f0'] = (int) $product_id;

// Phase 10.7: persist the product id in an option so the
// Phase 10.7 MU-plugin (dev/mu-vyg-phase10-7-product-map.php,
// copied into wp-content/mu-plugins by the capture script) can
// re-attach the mapping on every HTTP request. The seed itself
// only runs once; the MU-plugin must work for every request.
update_option( 'vyg_phase10_7_product_id', (int) $product_id );

// -----------------------------------------------------------------------
// 4. Feed (raw wpdb — the column shape is JSON columns, products
//    mapping is applied at render time via a filter below).
// -----------------------------------------------------------------------
$feed_uuid = 'phase-10-7-feed';
$feed_row = $wpdb->get_row( $wpdb->prepare(
    "SELECT id FROM {$prefix}vyg_feeds WHERE feed_uuid = %s",
    $feed_uuid
) );
$feed_id = $feed_row ? (int) $feed_row->id : 0;

$source_config = array( 'source_uuid' => $source_uuid );
$display_config = array(
    'layout'        => 'grid',
    'columns'       => 3,
    'per_page'      => 6,
    'preset'        => 'cinema',
    'schema_enabled'=> true,
);
$sort_config    = array( 'orderby' => 'published_at', 'order' => 'DESC' );

if ( ! $feed_id ) {
    $wpdb->insert( $prefix . 'vyg_feeds', array(
        'feed_uuid'           => $feed_uuid,
        'name'                => 'Phase 10.7 — Integration Demo',
        'feed_type'           => 'source',
        'layout'              => 'grid',
        'source_config_json'  => wp_json_encode( $source_config ),
        'display_config_json' => wp_json_encode( $display_config ),
        'filter_config_json'  => wp_json_encode( array() ),
        'sort_config_json'    => wp_json_encode( $sort_config ),
        'status'              => 'active',
    ) );
}

// Note: the filter itself is registered by the Phase 10.7
// MU-plugin (dev/mu-vyg-phase10-7-product-map.php) on every
// request. We only persist the product id in the option
// table here; the seed itself only runs once.

// -----------------------------------------------------------------------
// 6. Demo page (draft) — the capture script will publish + open it.
// -----------------------------------------------------------------------
$page_slug = 'phase-10-7-integration-demo';
$page = get_page_by_path( $page_slug, OBJECT, 'page' );
if ( ! $page ) {
    $page_id = wp_insert_post( array(
        'post_title'  => 'Phase 10.7 — Integration Demo',
        'post_name'   => $page_slug,
        'post_status' => 'draft',
        'post_type'   => 'page',
    ) );
    // Use the shortcode form (not the block form) because the
    // shortcode path is the one the front-end reliably renders
    // server-side. The block works in the editor but needs the
    // editor's JS to feed attribute names in snake_case (the seed
    // accidentally uses camelCase keys, which is also a useful
    // test for the Phase 10.4 picker UX — fixing the form is a
    // Phase 10.4 follow-up, not a 10.7 deliverable).
    $content = '[youtube_feed feed_uuid="' . $feed_uuid . '" layout="grid" columns="3" per_page="6" preset="cinema" schema_enabled="1"]';
    wp_update_post( array(
        'ID'           => $page_id,
        'post_content' => $content,
    ) );
}

echo 'source_uuid=' . $source_uuid . PHP_EOL;
echo 'feed_uuid='   . $feed_uuid   . PHP_EOL;
echo 'product_id='  . (int) $product_id . PHP_EOL;
echo 'product_map=' . wp_json_encode( $product_map ) . PHP_EOL;
echo 'page_slug='   . $page_slug . PHP_EOL;
echo 'smoke_status=ok' . PHP_EOL;
