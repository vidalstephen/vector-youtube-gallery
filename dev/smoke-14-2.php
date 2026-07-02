<?php
/**
 * Phase 14.2 live smoke — render the carousel layout and confirm the
 * rendered HTML carries the dots row + active-card state.
 *
 * Usage: docker exec vyg-wp php /var/www/html/wp-content/plugins/vector-youtube-gallery/dev/smoke-14-2.php
 */

require_once '/var/www/html/wp-load.php';

$container = \VectorYT\Gallery\Plugin::container();
$renderer  = $container->get( 'render.renderer' );

// Pick the first active source we can find. The dev install seeds
// at least one source as part of dev/reseed-phase12.php.
global $wpdb;
$source_uuid = (string) $wpdb->get_var(
    "SELECT source_uuid FROM {$wpdb->prefix}vyg_sources WHERE status = 'active' LIMIT 1"
);
if ( '' === $source_uuid ) {
    fwrite( STDERR, "no active source found; run dev/reseed-phase12.php first\n" );
    exit( 1 );
}

$exit_code = 0;
$checks    = array();

// 1) Render the carousel with 5 videos.
$args = array(
    'source_uuid' => $source_uuid,
    'layout'      => 'carousel',
    'per_page'    => 5,
    'columns'     => 3,
    'wrapper_id'  => 'smoke-14-2',
);
$html = $renderer->render( $args );

// 2) Verify exactly one dots row.
$dots_row = preg_match_all( '/class="vyg-carousel__dots"/', $html );
$checks[] = array( '1 vyg-carousel__dots row', $dots_row, 1 );

// 3) Verify exactly 5 dot buttons.
$dot_btns = preg_match_all(
    '/<button\b(?:[^>]*\bclass="[^"]*\bvyg-carousel__dot\b[^"]*"|[^>]*\bclass="[^"]*\bvyg-carousel__dot--on\b[^"]*")[^>]*>/',
    $html
);
$checks[] = array( '5 vyg-carousel__dot buttons', $dot_btns, 5 );

// 4) Verify exactly 1 --on dot.
$on_dots = preg_match_all(
    '/<button\b(?:[^>]*\bclass="[^"]*\bvyg-carousel__dot--on\b[^"]*"|[^>]*\bclass="[^"]*\bvyg-carousel__dot--on\b[^"]*")[^>]*>/',
    $html
);
$checks[] = array( '1 vyg-carousel__dot--on', $on_dots, 1 );

// 5) Verify exactly 1 --active slide.
$active_slides = preg_match_all(
    '/<li\b[^>]*\bclass="[^"]*\bvyg-carousel__slide--active\b[^"]*"/',
    $html
);
$checks[] = array( '1 vyg-carousel__slide--active', $active_slides, 1 );

// 6) Verify each dot has data-slide-index.
$indexed_dots = preg_match_all(
    '/<button\b(?:[^>]*\bdata-slide-index="\d+"[^>]*\bclass="[^"]*\bvyg-carousel__dot\b[^"]*"|[^>]*\bclass="[^"]*\bvyg-carousel__dot\b[^"]*[^>]*\bdata-slide-index="\d+")[^>]*>/',
    $html
);
$checks[] = array( '5 data-slide-index attrs on dots', $indexed_dots, 5 );

// 7) Verify each dot has aria-label="Go to slide N".
$aria_dots = preg_match_all(
    '/<button\b(?:[^>]*\baria-label="Go to slide \d+"[^>]*\bclass="[^"]*\bvyg-carousel__dot\b[^"]*"|[^>]*\bclass="[^"]*\bvyg-carousel__dot\b[^"]*[^>]*\baria-label="Go to slide \d+")[^>]*>/',
    $html
);
$checks[] = array( '5 aria-label="Go to slide N" on dots', $aria_dots, 5 );

// 8) Verify the active dot's data-slide-index is "3" (the middle of 5).
$active_idx = preg_match(
    '/<button\b(?:[^>]*\bdata-slide-index="3"[^>]*\bclass="[^"]*\bvyg-carousel__dot--on\b[^"]*"|[^>]*\bclass="[^"]*\bvyg-carousel__dot--on\b[^"]*[^>]*\bdata-slide-index="3")[^>]*>/',
    $html
);
$checks[] = array( 'active dot has data-slide-index="3"', $active_idx, 1 );

// 9) Single-slide edge case: dots row should NOT render with 1 video.
$args_one = array(
    'source_uuid' => $source_uuid,
    'layout'      => 'carousel',
    'per_page'    => 1,
    'columns'     => 3,
    'wrapper_id'  => 'smoke-14-2-single',
);
$html_one        = $renderer->render( $args_one );
$no_dots_single = ( strpos( $html_one, 'vyg-carousel__dots' ) === false );
$checks[] = array( 'single-slide suppresses dots row (boolean)', $no_dots_single ? 1 : 0, 1 );

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
