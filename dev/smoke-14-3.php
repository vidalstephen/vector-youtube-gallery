<?php
/**
 * Phase 14.3 live smoke — render the live layout and confirm the
 * three pill counters appear with the correct text and class variants.
 *
 * Two-pronged:
 *   1) SYNTHETIC: build $buckets in-process and call TemplateLoader::render
 *      directly. This pins the exact text "2 live" / "3 upcoming" /
 *      "Recent replays" + the prototype color-palette class variants.
 *   2) REAL: render via the real Renderer with an active source. This
 *      exercises the full path (LiveQuery → TemplateLoader). The real
 *      data may not have 2 live + 3 upcoming + N replay, so we just
 *      assert the live layout's HTML wraps a vyg-live__head div around
 *      the first present section's h2 (i.e. the wrap exists when the
 *      live layout actually runs end-to-end).
 *
 * Usage: docker exec vyg-wp php /var/www/html/wp-content/plugins/vector-youtube-gallery/dev/smoke-14-3.php
 */

require_once '/var/www/html/wp-load.php';

$container = \VectorYT\Gallery\Plugin::container();
$renderer  = $container->get( 'render.renderer' );
$loader    = new \VectorYT\Gallery\Render\TemplateLoader();
$vr        = new \VectorYT\Gallery\Render\VideoRenderer();

$exit_code = 0;
$checks    = array();

/* =====================================================================
 * 1) SYNTHETIC — pin exact text + class variants via direct template call.
 * ===================================================================== */

$buckets = array(
    'live'     => array(
        array( 'youtube_video_id' => 'L1', 'title' => 'Live 1', 'thumbnail_high' => 'https://x/1.jpg', 'duration_seconds' => 60, 'content_type' => 'standard', 'live_status' => 'live', 'published_at' => '2026-06-01T00:00:00Z', 'concurrent_viewers' => 100 ),
        array( 'youtube_video_id' => 'L2', 'title' => 'Live 2', 'thumbnail_high' => 'https://x/2.jpg', 'duration_seconds' => 60, 'content_type' => 'standard', 'live_status' => 'live', 'published_at' => '2026-06-01T00:00:00Z', 'concurrent_viewers' => 50 ),
    ),
    'upcoming' => array(
        array( 'youtube_video_id' => 'U1', 'title' => 'Up 1', 'thumbnail_high' => 'https://x/u1.jpg', 'duration_seconds' => 0, 'content_type' => 'standard', 'live_status' => 'upcoming', 'published_at' => '2026-06-01T00:00:00Z', 'scheduled_start_at' => '2026-07-15T12:00:00Z' ),
        array( 'youtube_video_id' => 'U2', 'title' => 'Up 2', 'thumbnail_high' => 'https://x/u2.jpg', 'duration_seconds' => 0, 'content_type' => 'standard', 'live_status' => 'upcoming', 'published_at' => '2026-06-01T00:00:00Z', 'scheduled_start_at' => '2026-07-16T12:00:00Z' ),
        array( 'youtube_video_id' => 'U3', 'title' => 'Up 3', 'thumbnail_high' => 'https://x/u3.jpg', 'duration_seconds' => 0, 'content_type' => 'standard', 'live_status' => 'upcoming', 'published_at' => '2026-06-01T00:00:00Z', 'scheduled_start_at' => '2026-07-17T12:00:00Z' ),
    ),
    'replay'   => array(
        array( 'youtube_video_id' => 'R1', 'title' => 'Rep 1', 'thumbnail_high' => 'https://x/r1.jpg', 'duration_seconds' => 600, 'content_type' => 'standard', 'live_status' => 'none', 'published_at' => '2026-06-01T00:00:00Z', 'ended_at' => '2026-06-30T18:00:00Z' ),
        array( 'youtube_video_id' => 'R2', 'title' => 'Rep 2', 'thumbnail_high' => 'https://x/r2.jpg', 'duration_seconds' => 600, 'content_type' => 'standard', 'live_status' => 'none', 'published_at' => '2026-06-02T00:00:00Z', 'ended_at' => '2026-06-29T18:00:00Z' ),
        array( 'youtube_video_id' => 'R3', 'title' => 'Rep 3', 'thumbnail_high' => 'https://x/r3.jpg', 'duration_seconds' => 600, 'content_type' => 'standard', 'live_status' => 'none', 'published_at' => '2026-06-03T00:00:00Z', 'ended_at' => '2026-06-28T18:00:00Z' ),
        array( 'youtube_video_id' => 'R4', 'title' => 'Rep 4', 'thumbnail_high' => 'https://x/r4.jpg', 'duration_seconds' => 600, 'content_type' => 'standard', 'live_status' => 'none', 'published_at' => '2026-06-04T00:00:00Z', 'ended_at' => '2026-06-27T18:00:00Z' ),
        array( 'youtube_video_id' => 'R5', 'title' => 'Rep 5', 'thumbnail_high' => 'https://x/r5.jpg', 'duration_seconds' => 600, 'content_type' => 'standard', 'live_status' => 'none', 'published_at' => '2026-06-05T00:00:00Z', 'ended_at' => '2026-06-26T18:00:00Z' ),
    ),
);

$synthetic_html = $loader->render( 'live', array(
    'source'   => array( 'title' => 'Smoke Source', 'source_uuid' => 'smoke-14-3' ),
    'buckets'  => $buckets,
    'renderer' => $vr,
    'attrs'    => array( 'layout' => 'live', 'wrapper_id' => 'smoke-14-3-synth', 'public_safe' => false ),
) );

// Pin: active pill text + class
$checks[] = array(
    'SYN active pill text "2 live"',
    (int) ( strpos( $synthetic_html, '>2 live<' ) !== false ),
    1,
);
$checks[] = array(
    'SYN active pill class vyg-live__pill--active',
    (int) ( strpos( $synthetic_html, 'vyg-live__pill--active' ) !== false ),
    1,
);

// Pin: upcoming pill text + class
$checks[] = array(
    'SYN upcoming pill text "3 upcoming"',
    (int) ( strpos( $synthetic_html, '>3 upcoming<' ) !== false ),
    1,
);
$checks[] = array(
    'SYN upcoming pill class vyg-live__pill--upcoming',
    (int) ( strpos( $synthetic_html, 'vyg-live__pill--upcoming' ) !== false ),
    1,
);

// Pin: replay pill text + class (text-only, no count)
$checks[] = array(
    'SYN replay pill text "Recent replays"',
    (int) ( strpos( $synthetic_html, '>Recent replays<' ) !== false ),
    1,
);
$checks[] = array(
    'SYN replay pill class vyg-live__pill--replay',
    (int) ( strpos( $synthetic_html, 'vyg-live__pill--replay' ) !== false ),
    1,
);
$checks[] = array(
    'SYN replay pill is text-only (no "5 replays" / "5 recent")',
    (int) ( strpos( $synthetic_html, '>5 replays<' ) === false && strpos( $synthetic_html, '>5 recent<' ) === false ),
    1,
);

// Pin: head wrapper exists around each section
$head_count = preg_match_all( '/class="[^"]*\bvyg-live__head\b/', $synthetic_html );
$checks[] = array( 'SYN 3 vyg-live__head wrappers (one per section)', (int) $head_count, 3 );

// Pin: each pill lives inside a head wrapper
$checks[] = array(
    'SYN active pill wrapped by vyg-live__head',
    (int) preg_match(
        '/<div class="vyg-live__head">\s*<h2 class="vyg-live__heading">Live now<\/h2>\s*<span class="vyg-live__pill vyg-live__pill--active">2 live<\/span>\s*<\/div>/',
        $synthetic_html
    ),
    1,
);
$checks[] = array(
    'SYN upcoming pill wrapped by vyg-live__head',
    (int) preg_match(
        '/<div class="vyg-live__head">\s*<h2 class="vyg-live__heading">Upcoming<\/h2>\s*<span class="vyg-live__pill vyg-live__pill--upcoming">3 upcoming<\/span>\s*<\/div>/',
        $synthetic_html
    ),
    1,
);
$checks[] = array(
    'SYN replay pill wrapped by vyg-live__head',
    (int) preg_match(
        '/<div class="vyg-live__head">\s*<h2 class="vyg-live__heading">Recent streams<\/h2>\s*<span class="vyg-live__pill vyg-live__pill--replay">Recent replays<\/span>\s*<\/div>/',
        $synthetic_html
    ),
    1,
);

/* =====================================================================
 * 2) REAL — exercise the full path via Renderer with an active source.
 *    We just need to confirm the head wrapper + at least one pill
 *    class shows up end-to-end. Real data may not have all 3 buckets.
 * ===================================================================== */

global $wpdb;
$source_uuid = (string) $wpdb->get_var(
    "SELECT source_uuid FROM {$wpdb->prefix}vyg_sources WHERE status = 'active' LIMIT 1"
);
if ( '' === $source_uuid ) {
    fwrite( STDERR, "no active source found; run dev/reseed-phase12.php first\n" );
    exit( 1 );
}

$real_html = $renderer->render( array(
    'source_uuid' => $source_uuid,
    'layout'      => 'live',
    'per_page'    => 12,
    'wrapper_id'  => 'smoke-14-3-real',
) );

// Either we got the empty-state or a real render. The empty-state
// path doesn't render any pills, which is correct.
if ( strpos( $real_html, 'vyg-feed--empty' ) !== false ) {
    $checks[] = array( 'REAL empty-state path: no pill rendered (expected when no live data)', 1, 1 );
} else {
    // The real render MUST contain a vyg-live__head wrapper (proves
    // the template change shipped end-to-end) and at least one pill
    // class (proves the conditional branches all flow through).
    $checks[] = array(
        'REAL end-to-end render contains vyg-live__head wrapper',
        (int) ( strpos( $real_html, 'vyg-live__head' ) !== false ),
        1,
    );
    $pill_present = (
        strpos( $real_html, 'vyg-live__pill--active' )   !== false ||
        strpos( $real_html, 'vyg-live__pill--upcoming' ) !== false ||
        strpos( $real_html, 'vyg-live__pill--replay' )   !== false
    );
    $checks[] = array(
        'REAL end-to-end render contains at least one vyg-live__pill variant',
        (int) $pill_present,
        1,
    );
}

/* =====================================================================
 * Report
 * ===================================================================== */

foreach ( $checks as $check ) {
    list( $label, $got, $expected ) = $check;
    $ok = ( $got === $expected );
    if ( ! $ok ) { $exit_code = 1; }
    fwrite( STDOUT, sprintf(
        "[%s] %s — got %d expected %d\n",
        $ok ? 'OK' : 'FAIL',
        $label,
        $got,
        $expected
    ) );
}

exit( $exit_code );
