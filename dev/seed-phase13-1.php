<?php
/**
 * Phase 13.1 — seed data for grid redesign visual verification.
 *
 * Creates a source with 12 video rows (varied view counts and published_at
 * times) so the redesigned grid layout has enough cards to demonstrate
 * responsive column count, density presets, and the trust strip. Idempotent:
 * re-running upserts the source and replaces its video rows.
 *
 * Run with:  docker exec -u www-data vyg-wp wp eval-file \
 *              /var/www/html/wp-content/plugins/vector-youtube-gallery/dev/seed-phase13-1.php \
 *              --path=/var/www/html --allow-root
 *
 * @package VectorYT\Gallery
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;
$prefix = $wpdb->prefix;

// ---------------------------------------------------------------------------
// 1. Source.
// ---------------------------------------------------------------------------
$source_uuid = 'phase-13-1-source';
$source_row  = $wpdb->get_row( $wpdb->prepare(
    "SELECT id FROM {$prefix}vyg_sources WHERE source_uuid = %s",
    $source_uuid
), ARRAY_A );
$source_id   = $source_row ? (int) $source_row['id'] : 0;

$source_data = array(
    'source_uuid'        => $source_uuid,
    'source_type'        => 'channel',
    'youtube_channel_id' => 'UC_phase_13_1',
    'handle'             => '@phase-13-1-demo',
    'title'              => 'Phase 13.1 — Demo channel',
    'status'             => 'active',
    'last_success_at'    => current_time( 'mysql' ),
);

if ( $source_id ) {
    $wpdb->update( $prefix . 'vyg_sources', $source_data, array( 'id' => $source_id ) );
} else {
    $wpdb->insert( $prefix . 'vyg_sources', $source_data );
    $source_id = (int) $wpdb->insert_id;
}

// ---------------------------------------------------------------------------
// 2. Videos (12 rows, deterministic).
// ---------------------------------------------------------------------------
// We re-seed by deleting prior phase-13-1 rows. Real plugins never do this;
// the seed is a one-shot fixture for the screenshot pipeline.
$wpdb->query( $wpdb->prepare(
    "DELETE FROM {$prefix}vyg_videos WHERE youtube_channel_id = %s",
    'UC_phase_13_1'
) );

$videos = array(
    array( 'vid-1',  'Exploring the Canadian Rockies (4K Scenic Adventure)',           '2026-06-28', 125000 ),
    array( 'vid-2',  'Coastal Vibes: 10 Hours of Relaxing Ocean Sounds',                '2026-06-25',  89000 ),
    array( 'vid-3',  'How We Built a 1.2M Subscriber Channel (Behind the Scenes)',     '2026-06-22', 312000 ),
    array( 'vid-4',  'Minimalist Desk Setup for Under $500',                            '2026-06-20', 47500 ),
    array( 'vid-5',  'Travel Vlog: Tokyo at Night',                                     '2026-06-15', 642000 ),
    array( 'vid-6',  'I Tried Coding for 30 Days Straight — Here\'s What Happened',     '2026-06-10', 184000 ),
    array( 'vid-7',  'Mountain Biking the Whole Coast of British Columbia',             '2026-06-05',  32700 ),
    array( 'vid-8',  'The Truth About Productivity Apps (Honest Review)',                '2026-05-30', 95500 ),
    array( 'vid-9',  'Forest Bathing: A Beginner\'s Guide to Shinrin-yoku',               '2026-05-25',  12200 ),
    array( 'vid-10', 'Best Camera Gear Under $1,000 in 2026',                           '2026-05-20', 278000 ),
    array( 'vid-11', 'Why I Quit Social Media for a Year',                               '2026-05-15',  84000 ),
    array( 'vid-12', 'A Quiet Day in the Studio',                                       '2026-05-10',   4500 ),
);

foreach ( $videos as $row ) {
    [ $yid, $title, $date, $views ] = $row;
    $wpdb->insert( $prefix . 'vyg_videos', array(
        'youtube_video_id'   => $yid,
        'youtube_channel_id' => 'UC_phase_13_1',
        'title'              => $title,
        'published_at'       => $date . ' 12:00:00',
        'duration_seconds'   => 765,
        'availability_status'=> 'available',
        'content_type'       => 'standard',
        'view_count'         => $views,
        'thumbnail_high'     => 'https://i.ytimg.com/vi/' . $yid . '/hqdefault.jpg',
        'thumbnail_maxres'   => 'https://i.ytimg.com/vi/' . $yid . '/maxresdefault.jpg',
    ) );
}

// ---------------------------------------------------------------------------
// 3. Feeds — one per density preset + one for trust strip + one for header CTA.
// ---------------------------------------------------------------------------
$feeds = array(
    array(
        'uuid'  => 'phase-13-1-comfortable',
        'name'  => 'Phase 13.1 — Comfortable (default)',
        'display' => array(
            'layout'      => 'grid',
            'columns'     => 3,
            'per_page'    => 12,
            'density'     => 'comfortable',
            'preset'      => 'default',
            'lightbox'    => true,
            'load_more'   => false,
        ),
    ),
    array(
        'uuid'  => 'phase-13-1-compact',
        'name'  => 'Phase 13.1 — Compact',
        'display' => array(
            'layout'      => 'grid',
            'columns'     => 4,
            'per_page'    => 12,
            'density'     => 'compact',
            'preset'      => 'default',
            'lightbox'    => true,
            'load_more'   => false,
        ),
    ),
    array(
        'uuid'  => 'phase-13-1-editorial',
        'name'  => 'Phase 13.1 — Editorial',
        'display' => array(
            'layout'      => 'grid',
            'columns'     => 2,
            'per_page'    => 12,
            'density'     => 'editorial',
            'preset'      => 'minimal',
            'lightbox'    => true,
            'load_more'   => false,
        ),
    ),
    array(
        'uuid'  => 'phase-13-1-with-header',
        'name'  => 'Phase 13.1 — With section header + CTA + trust strip',
        'display' => array(
            'layout'                => 'grid',
            'columns'               => 3,
            'per_page'              => 12,
            'density'               => 'comfortable',
            'preset'                => 'default',
            'lightbox'              => true,
            'load_more'             => false,
            'header_title'          => 'YouTube Feed — Grid Layout',
            'header_subtitle'       => 'Latest uploads from the demo channel',
            'header_columns_visible'=> true,
            'header_cta_label'      => 'View more on YouTube',
            'header_cta_url'        => 'https://www.youtube.com/',
            'trust_strip'           => true,
        ),
    ),
);

foreach ( $feeds as $feed_def ) {
    $feed_uuid   = $feed_def['uuid'];
    $feed_name   = $feed_def['name'];
    $display     = $feed_def['display'];
    $source_cfg  = array( 'source_uuid' => $source_uuid );
    $sort_cfg    = array( 'orderby' => 'published_at', 'order' => 'DESC' );

    $existing = $wpdb->get_row( $wpdb->prepare(
        "SELECT id FROM {$prefix}vyg_feeds WHERE feed_uuid = %s",
        $feed_uuid
    ) );
    $data = array(
        'feed_uuid'           => $feed_uuid,
        'name'                => $feed_name,
        'feed_type'           => 'source',
        'layout'              => $display['layout'],
        'source_config_json'  => wp_json_encode( $source_cfg ),
        'display_config_json' => wp_json_encode( $display ),
        'filter_config_json'  => wp_json_encode( array() ),
        'sort_config_json'    => wp_json_encode( $sort_cfg ),
        'status'              => 'active',
    );
    if ( $existing ) {
        $wpdb->update( $prefix . 'vyg_feeds', $data, array( 'id' => (int) $existing->id ) );
    } else {
        $wpdb->insert( $prefix . 'vyg_feeds', $data );
    }
}

// ---------------------------------------------------------------------------
// 4. Pages — one per feed so the screenshot script can target each by URL.
// ---------------------------------------------------------------------------
$page_mapping = array(
    'phase-13-1-comfortable' => 'Phase 13.1 — Comfortable',
    'phase-13-1-compact'     => 'Phase 13.1 — Compact',
    'phase-13-1-editorial'   => 'Phase 13.1 — Editorial',
    'phase-13-1-with-header' => 'Phase 13.1 — With header + trust strip',
);
foreach ( $page_mapping as $feed_uuid => $page_title ) {
    $page = get_page_by_path( $feed_uuid, OBJECT, 'page' );
    $content = '[youtube_feed feed_uuid="' . $feed_uuid . '"]';
    if ( $page ) {
        wp_update_post( array(
            'ID'           => (int) $page->ID,
            'post_title'   => $page_title,
            'post_status'  => 'publish',
            'post_content' => $content,
        ) );
    } else {
        wp_insert_post( array(
            'post_title'  => $page_title,
            'post_name'   => $feed_uuid,
            'post_status' => 'publish',
            'post_type'   => 'page',
            'post_content'=> $content,
        ) );
    }
}

echo 'source_uuid=' . $source_uuid . PHP_EOL;
echo 'video_count=' . count( $videos ) . PHP_EOL;
echo 'feed_count=' . count( $feeds ) . PHP_EOL;
echo 'smoke_status=ok' . PHP_EOL;
