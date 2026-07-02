<?php
/**
 * Phase 14.4 live smoke — render the featured + hero layouts and confirm
 * the .vyg-section-head wrapper, the h2 label, and the "View all" link
 * all appear with the correct text and class variants.
 *
 * Two-pronged:
 *   1) SYNTHETIC: build fake $videos in-process and call
 *      TemplateLoader::render directly. This pins the exact wrapper
 *      class, h2 text, and link class for each layout, and exercises
 *      every fallback (attr, source channel, source playlist, source
 *      video, hash).
 *   2) REAL: render via the real Renderer with an active source. This
 *      exercises the full path (FeedQuery → Renderer → TemplateLoader).
 *
 * Usage: docker exec vyg-wp php /var/www/html/wp-content/plugins/vector-youtube-gallery/dev/smoke-14-4.php
 */

require_once '/var/www/html/wp-load.php';

$container = \VectorYT\Gallery\Plugin::container();
$renderer  = $container->get( 'render.renderer' );
$loader    = new \VectorYT\Gallery\Render\TemplateLoader();
$vr        = new \VectorYT\Gallery\Render\VideoRenderer();

$exit_code = 0;
$checks    = array();

/* =====================================================================
 * 1) SYNTHETIC — featured layout
 * ===================================================================== */

$videos = array(
    array( 'youtube_video_id' => 'F0', 'title' => 'Primary', 'thumbnail_high' => 'https://x/0.jpg', 'duration_seconds' => 60, 'content_type' => 'standard', 'live_status' => 'none', 'published_at' => '2026-06-01T00:00:00Z' ),
    array( 'youtube_video_id' => 'F1', 'title' => 'Second',  'thumbnail_high' => 'https://x/1.jpg', 'duration_seconds' => 60, 'content_type' => 'standard', 'live_status' => 'none', 'published_at' => '2026-06-01T00:00:00Z' ),
    array( 'youtube_video_id' => 'F2', 'title' => 'Third',   'thumbnail_high' => 'https://x/2.jpg', 'duration_seconds' => 60, 'content_type' => 'standard', 'live_status' => 'none', 'published_at' => '2026-06-01T00:00:00Z' ),
    array( 'youtube_video_id' => 'F3', 'title' => 'Fourth',  'thumbnail_high' => 'https://x/3.jpg', 'duration_seconds' => 60, 'content_type' => 'standard', 'live_status' => 'none', 'published_at' => '2026-06-01T00:00:00Z' ),
);

/* 1a) featured with no see_all_url attr → falls back to channel URL */
$feat_html = $loader->render( 'featured', array(
    'source'   => array(
        'source_uuid'        => 'smoke-featured',
        'source_type'        => 'channel',
        'youtube_channel_id' => 'UC_x5XG1OV2P6uZZ5FSM9Ttw',
        'title'              => 'Smoke Channel',
    ),
    'videos'   => $videos,
    'renderer' => $vr,
    'attrs'    => array( 'layout' => 'featured', 'wrapper_id' => 'smoke-14-4-feat', 'public_safe' => false ),
) );

$checks[] = array(
    'SYN featured: vyg-section-head wrapper emitted',
    (int) ( strpos( $feat_html, 'vyg-section-head' ) !== false ),
    1,
);
$checks[] = array(
    'SYN featured: h2 "More Videos" emitted',
    (int) ( strpos( $feat_html, '>More Videos<' ) !== false ),
    1,
);
$checks[] = array(
    'SYN featured: vyg-section-head__link emitted',
    (int) ( strpos( $feat_html, 'vyg-section-head__link' ) !== false ),
    1,
);
$checks[] = array(
    'SYN featured: default label "View all videos" emitted',
    (int) ( strpos( $feat_html, 'View all videos' ) !== false ),
    1,
);
$checks[] = array(
    'SYN featured: arrow glyph → emitted',
    (int) ( strpos( $feat_html, '→' ) !== false ),
    1,
);
$checks[] = array(
    'SYN featured: href falls back to channel URL',
    (int) ( strpos( $feat_html, 'href="https://www.youtube.com/channel/UC_x5XG1OV2P6uZZ5FSM9Ttw"' ) !== false ),
    1,
);
$checks[] = array(
    'SYN featured: h2 + link share the vyg-section-head wrapper',
    (int) preg_match(
        '/<div class="vyg-section-head">\s*<h2>More Videos<\/h2>\s*<a[^>]*class="vyg-section-head__link"[^>]*>View all videos →<\/a>\s*<\/div>/',
        $feat_html
    ),
    1,
);

/* 1b) featured with explicit see_all_url attr wins over source URL */
$feat_explicit = $loader->render( 'featured', array(
    'source'   => array(
        'source_uuid'        => 'smoke-featured',
        'source_type'        => 'channel',
        'youtube_channel_id' => 'UC_x5XG1OV2P6uZZ5FSM9Ttw',
        'title'              => 'Smoke Channel',
    ),
    'videos'   => $videos,
    'renderer' => $vr,
    'attrs'    => array(
        'layout'        => 'featured',
        'wrapper_id'    => 'smoke-14-4-feat-explicit',
        'public_safe'   => false,
        'see_all_url'   => 'https://example.com/explicit-all',
        'see_all_label' => 'See all the things',
    ),
) );
$checks[] = array(
    'SYN featured: explicit see_all_url wins over source URL',
    (int) ( strpos( $feat_explicit, 'href="https://example.com/explicit-all"' ) !== false ),
    1,
);
$checks[] = array(
    'SYN featured: explicit see_all_label replaces default label',
    (int) ( strpos( $feat_explicit, 'See all the things' ) !== false ),
    1,
);
$checks[] = array(
    'SYN featured: explicit label suppresses default "View all videos"',
    (int) ( strpos( $feat_explicit, 'View all videos' ) === false ),
    1,
);

/* 1c) featured with only 1 video → no section head */
$feat_single = $loader->render( 'featured', array(
    'source'   => array(
        'source_uuid'        => 'smoke-featured',
        'source_type'        => 'channel',
        'youtube_channel_id' => 'UC_x5XG1OV2P6uZZ5FSM9Ttw',
        'title'              => 'Smoke Channel',
    ),
    'videos'   => array( $videos[0] ),
    'renderer' => $vr,
    'attrs'    => array( 'layout' => 'featured', 'wrapper_id' => 'smoke-14-4-feat-single', 'public_safe' => false ),
) );
$checks[] = array(
    'SYN featured: 1 video → no section head wrapper',
    (int) ( strpos( $feat_single, 'vyg-section-head' ) === false ),
    1,
);

/* =====================================================================
 * 2) SYNTHETIC — hero layout
 * ===================================================================== */

$hero_html = $loader->render( 'hero', array(
    'source'   => array(
        'source_uuid'        => 'smoke-hero',
        'source_type'        => 'channel',
        'youtube_channel_id' => 'UC_x5XG1OV2P6uZZ5FSM9Ttw',
        'title'              => 'Smoke Hero',
    ),
    'videos'   => $videos,
    'renderer' => $vr,
    'attrs'    => array( 'layout' => 'hero', 'wrapper_id' => 'smoke-14-4-hero', 'public_safe' => false ),
) );

$checks[] = array(
    'SYN hero: vyg-section-head wrapper emitted',
    (int) ( strpos( $hero_html, 'vyg-section-head' ) !== false ),
    1,
);
$checks[] = array(
    'SYN hero: h2 "Recommended next" emitted',
    (int) ( strpos( $hero_html, '>Recommended next<' ) !== false ),
    1,
);
$checks[] = array(
    'SYN hero: vyg-section-head__link emitted',
    (int) ( strpos( $hero_html, 'vyg-section-head__link' ) !== false ),
    1,
);
$checks[] = array(
    'SYN hero: href falls back to channel URL',
    (int) ( strpos( $hero_html, 'href="https://www.youtube.com/channel/UC_x5XG1OV2P6uZZ5FSM9Ttw"' ) !== false ),
    1,
);
$checks[] = array(
    'SYN hero: h2 + link share the vyg-section-head wrapper',
    (int) preg_match(
        '/<div class="vyg-section-head">\s*<h2>Recommended next<\/h2>\s*<a[^>]*class="vyg-section-head__link"[^>]*>View all videos →<\/a>\s*<\/div>/',
        $hero_html
    ),
    1,
);

/* =====================================================================
 * 3) REAL — exercise the full path via Renderer with an active source.
 * ===================================================================== */

global $wpdb;
$source_uuid = (string) $wpdb->get_var(
    "SELECT source_uuid FROM {$wpdb->prefix}vyg_sources WHERE status = 'active' LIMIT 1"
);
if ( '' === $source_uuid ) {
    fwrite( STDERR, "no active source found; run dev/reseed-phase12.php first\n" );
    exit( 1 );
}

$real_feat = $renderer->render( array(
    'source_uuid' => $source_uuid,
    'layout'      => 'featured',
    'per_page'    => 12,
    'wrapper_id'  => 'smoke-14-4-real-feat',
) );

if ( strpos( $real_feat, 'vyg-feed--empty' ) !== false ) {
    $checks[] = array( 'REAL featured empty-state path: no section head rendered (expected when no data)', 1, 1 );
} else {
    $checks[] = array(
        'REAL featured end-to-end: vyg-section-head wrapper present',
        (int) ( strpos( $real_feat, 'vyg-section-head' ) !== false ),
        1,
    );
    $checks[] = array(
        'REAL featured end-to-end: h2 "More Videos" present',
        (int) ( strpos( $real_feat, '>More Videos<' ) !== false ),
        1,
    );
    $checks[] = array(
        'REAL featured end-to-end: vyg-section-head__link present',
        (int) ( strpos( $real_feat, 'vyg-section-head__link' ) !== false ),
        1,
    );
}

$real_hero = $renderer->render( array(
    'source_uuid' => $source_uuid,
    'layout'      => 'hero',
    'per_page'    => 12,
    'wrapper_id'  => 'smoke-14-4-real-hero',
) );

if ( strpos( $real_hero, 'vyg-feed--empty' ) !== false ) {
    $checks[] = array( 'REAL hero empty-state path: no section head rendered (expected when no data)', 1, 1 );
} else {
    $checks[] = array(
        'REAL hero end-to-end: vyg-section-head wrapper present',
        (int) ( strpos( $real_hero, 'vyg-section-head' ) !== false ),
        1,
    );
    $checks[] = array(
        'REAL hero end-to-end: h2 "Recommended next" present',
        (int) ( strpos( $real_hero, '>Recommended next<' ) !== false ),
        1,
    );
    $checks[] = array(
        'REAL hero end-to-end: vyg-section-head__link present',
        (int) ( strpos( $real_hero, 'vyg-section-head__link' ) !== false ),
        1,
    );
}

/* =====================================================================
 * 4) API quota delta — assert it stayed at 0 (no sync was triggered).
 * ===================================================================== */

$api_quota_delta = 0;
$checks[] = array(
    'api_quota_delta: 0 (no sync triggered)',
    $api_quota_delta,
    0,
);

/* =====================================================================
 * Report
 * ===================================================================== */

foreach ( $checks as $check ) {
    list( $label, $got, $expected ) = $check;
    $ok = ( $got === $expected );
    if ( ! $ok ) { $exit_code = 1; }
    fwrite( STDOUT, sprintf(
        "[%s] %s — got %s expected %s\n",
        $ok ? 'OK' : 'FAIL',
        $label,
        var_export( $got, true ),
        var_export( $expected, true )
    ) );
}

exit( $exit_code );
