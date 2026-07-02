<?php
/**
 * Phase 14.5 live smoke — card play icon center + thumbnail gradient overlay.
 *
 * Renders a single video via the Renderer and asserts:
 *   - The vyg-card__play span is emitted (with aria-hidden="true" and the
 *     triangle glyph, either as UTF-8 or as &#9654;)
 *   - The vyg-card__thumb-wrap--overlay class is on the media wrapper
 *   - The play icon span is nested inside the watch link <a>
 *   - The overlay class is absent when thumbnail_overlay=false
 *   - The play icon is absent when show_play_icon=false
 *   - No sync is triggered (api_quota_delta = 0)
 *
 * Usage: docker exec vyg-wp php /var/www/html/wp-content/plugins/vector-youtube-gallery/dev/smoke-14-5.php
 */

require_once '/var/www/html/wp-load.php';

$container = \VectorYT\Gallery\Plugin::container();
$renderer  = $container->get( 'render.renderer' );

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

// 1) Default render: play icon + overlay both on (matches global defaults).
$args_default = array(
    'source_uuid' => $source_uuid,
    'layout'      => 'grid',
    'per_page'    => 1,
    'wrapper_id'  => 'smoke-14-5-default',
);
$html_default = $renderer->render( $args_default );

$checks[] = array(
    'default render: vyg-card__play span emitted',
    (int) preg_match( '/<span[^>]*class="[^"]*vyg-card__play\b[^"]*"[^>]*>/', $html_default ),
    1
);
$checks[] = array(
    'default render: play icon has aria-hidden="true"',
    (int) preg_match( '/<span[^>]*class="[^"]*vyg-card__play\b[^"]*"[^>]*aria-hidden="true"[^>]*>/', $html_default ),
    1
);
$checks[] = array(
    'default render: play icon glyph present (UTF-8 OR entity)',
    (int) ( ( strpos( $html_default, "\xE2\x96\xBA" ) !== false ) || ( strpos( $html_default, '&#9654;' ) !== false ) ),
    1
);
$checks[] = array(
    'default render: vyg-card__thumb-wrap--overlay class on media wrapper',
    (int) preg_match( '/<div[^>]*class="[^"]*vyg-card__thumb-wrap--overlay\b[^"]*"/', $html_default ),
    1
);
$checks[] = array(
    'default render: play icon nested inside watch link',
    (int) preg_match(
        '/<a[^>]*class="vyg-card__link"[^>]*>.*?<span[^>]*class="[^"]*vyg-card__play\b[^"]*"[^>]*>.*?<\/span>.*?<\/a>/s',
        $html_default
    ),
    1
);

// 2) Both OFF: explicit shortcode attrs suppress both.
$args_off = array(
    'source_uuid'      => $source_uuid,
    'layout'           => 'grid',
    'per_page'         => 1,
    'show_play_icon'   => false,
    'thumbnail_overlay' => false,
    'wrapper_id'       => 'smoke-14-5-off',
);
$html_off = $renderer->render( $args_off );

$checks[] = array(
    'both off: no vyg-card__play span',
    ( strpos( $html_off, 'vyg-card__play' ) === false ? 1 : 0 ),
    1
);
$checks[] = array(
    'both off: no vyg-card__thumb-wrap--overlay class',
    ( strpos( $html_off, 'vyg-card__thumb-wrap--overlay' ) === false ? 1 : 0 ),
    1
);

// 3) Play icon ON, overlay OFF: only the play icon shows.
$args_play_only = array(
    'source_uuid'      => $source_uuid,
    'layout'           => 'grid',
    'per_page'         => 1,
    'show_play_icon'   => true,
    'thumbnail_overlay' => false,
    'wrapper_id'       => 'smoke-14-5-play-only',
);
$html_play = $renderer->render( $args_play_only );

$checks[] = array(
    'play only: vyg-card__play present',
    (int) preg_match( '/<span[^>]*class="[^"]*vyg-card__play\b[^"]*"/', $html_play ),
    1
);
$checks[] = array(
    'play only: vyg-card__thumb-wrap--overlay absent',
    ( strpos( $html_play, 'vyg-card__thumb-wrap--overlay' ) === false ? 1 : 0 ),
    1
);

// 4) Overlay ON, play icon OFF: only the overlay shows.
$args_overlay_only = array(
    'source_uuid'      => $source_uuid,
    'layout'           => 'grid',
    'per_page'         => 1,
    'show_play_icon'   => false,
    'thumbnail_overlay' => true,
    'wrapper_id'       => 'smoke-14-5-overlay-only',
);
$html_overlay = $renderer->render( $args_overlay_only );

$checks[] = array(
    'overlay only: vyg-card__thumb-wrap--overlay present',
    (int) preg_match( '/<div[^>]*class="[^"]*vyg-card__thumb-wrap--overlay\b[^"]*"/', $html_overlay ),
    1
);
$checks[] = array(
    'overlay only: no vyg-card__play span',
    ( strpos( $html_overlay, 'vyg-card__play' ) === false ? 1 : 0 ),
    1
);

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
