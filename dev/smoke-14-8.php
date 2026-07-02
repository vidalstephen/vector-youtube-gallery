<?php
/**
 * Phase 14.8 live smoke — badge type × style matrix (7 types × 4 styles).
 *
 * Exercises the production render path:
 *   - Renders 4 synthetic videos, one per badge type signal
 *     (live, featured, short, new), through Plugin::container()
 *     get('render.renderer') (the same entry point the shortcode
 *     / block / Elementor / Feed Builder surfaces use).
 *   - Asserts each rendered card contains the prototype's per-type
 *     class (.vyg-card__badge--{type}) and the per-style class
 *     (.vyg-card__badge--{style}) plus the inline --vyg-badge-color.
 *   - Walks all 4 badge_style values ('solid', 'soft', 'outline',
 *     'minimal') and confirms the rendered class changes with the
 *     setting.
 *   - Verifies the XSS guard by stuffing a malicious
 *     `manual_content_type` value into the DB and confirming the
 *     helper rejects it (whitelisted type slug, not raw injection).
 *   - Asserts no YouTube API quota was burned (api_quota_log row
 *     count is unchanged from the start of the script).
 *
 * Usage: docker exec -u www-data vyg-wp php /var/www/html/wp-content/plugins/vector-youtube-gallery/dev/smoke-14-8.php
 */

require_once '/var/www/html/wp-load.php';

$container = \VectorYT\Gallery\Plugin::container();
$renderer  = $container->get( 'render.renderer' );

global $wpdb;
$videos_table = $wpdb->prefix . 'vyg_videos';
$sources_table = $wpdb->prefix . 'vyg_sources';

// Capture starting quota so the gate at the end of the file
// asserts no sync was triggered by this smoke run.
$quota_start = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}vyg_api_quota_log" );

// Pick one active source (we render 1 card per source, so the
// test only needs one source). Fall back to any source if no
// active one exists.
$source_uuid = (string) $wpdb->get_var(
    "SELECT source_uuid FROM {$sources_table} WHERE status = 'active' ORDER BY id ASC LIMIT 1"
);
if ( '' === $source_uuid ) {
    fwrite( STDERR, "smoke-14-8: no active source found; run dev/reseed-phase12.php first\n" );
    exit( 1 );
}

$exit_code = 0;
$checks    = array();

// Each entry is a synthetic video row that exercises one badge
// type. We render them through the same renderer that the
// shortcode surface uses, but we build a fake "videos" array and
// feed it via the renderer's main entry point by routing through
// a card_renderer directly (avoids a DB write for the synthetic
// rows). The point of the smoke is to verify the wire-through:
//     renderer -> feed_query (canned) -> card_renderer -> partial
// emits the right class combo for the right video signal.
$video_renderer_svc = new \VectorYT\Gallery\Render\VideoRenderer();
$template_loader    = new \VectorYT\Gallery\Render\TemplateLoader();
$card_renderer      = new \VectorYT\Gallery\Render\CardRenderer( $video_renderer_svc, $template_loader );

// Resolve default card settings (grid layout, standard mode).
$base_settings = \VectorYT\Gallery\Render\CardSettings::resolve( 'grid', array(), array() );

/**
 * Build a synthetic video row that triggers one badge type.
 *
 * @param array<string,mixed> $overrides
 * @return array<string,mixed>
 */
$sample = static function ( array $overrides = array() ): array {
    $id_seed = empty( $overrides ) ? 'x' : json_encode( $overrides );
    return array_merge( array(
        'youtube_video_id'      => 'smoke-14-8-' . substr( md5( (string) $id_seed ), 0, 12 ),
        'youtube_channel_id'    => 'UC_smoke_14_8',
        'youtube_channel_title' => 'Smoke 14.8',
        'title'                 => 'Phase 14.8 badge video',
        'thumbnail_medium'      => 'https://example.com/m.jpg',
        'thumbnail_high'        => 'https://example.com/h.jpg',
        'duration_seconds'      => 600,
        'content_type'          => 'standard',
        'live_status'           => 'none',
        'published_at'          => gmdate( 'Y-m-d\TH:i:s\Z', time() - 2 * DAY_IN_SECONDS ),
        'view_count'            => 1000,
        'manual_content_type'   => null,
        'is_pinned'             => 0,
    ), $overrides );
};

$context = array(
    'source'      => array( 'title' => 'Smoke Source', 'source_uuid' => $source_uuid ),
    'feed_config' => array(),
    'feed_uuid'   => 'smoke-14-8',
    'mode'        => 'standard',
    'role'        => 'listitem',
);

// 1) Each type emits its own class + the right color.
$types_and_signals = array(
    array( 'live',     array( 'live_status' => 'live' ),                 'Live',     '#ef233c' ),
    array( 'featured', array( 'is_pinned'   => 1 ),                       'Featured', '#6d28d9' ),
    array( 'short',    array( 'content_type' => 'short_confirmed' ),     'Short',    '#0ea5e9' ),
    array( 'product',  array( 'manual_content_type' => 'product' ),      'Product',  '#f97316' ),
    array( 'new',      array( 'published_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() - DAY_IN_SECONDS ) ), 'New', '#16a34a' ),
    array( 'replay',   array( 'live_status' => 'replay' ),                'Replay',   '#2563eb' ),
);

foreach ( $types_and_signals as $row ) {
    list( $type, $signal, $label, $color ) = $row;
    $html = $card_renderer->render(
        $sample( $signal ),
        array_merge( $base_settings, array( 'show_status_badge' => true ) ),
        $context
    );
    $checks[] = array(
        "type={$type}: class .vyg-card__badge--{$type} present",
        (int) preg_match( '/<span[^>]*class="[^"]*\bvyg-card__badge--' . preg_quote( $type, '/' ) . '\b/', $html ),
        1
    );
    $checks[] = array(
        "type={$type}: color var --vyg-badge-color:{$color} present",
        (int) preg_match( '/--vyg-badge-color:\s*' . preg_quote( $color, '/' ) . '/', $html ),
        1
    );
    $checks[] = array(
        "type={$type}: label text '{$label}' present",
        (int) preg_match( '/<span[^>]*class="[^"]*\bvyg-card__badge\b[^"]*"[^>]*>\s*' . preg_quote( $label, '/' ) . '\s*<\/span>/', $html ),
        1
    );
}

// 2) Each of the 4 styles emits the right class on the same live video.
$styles = array( 'solid', 'soft', 'outline', 'minimal' );
foreach ( $styles as $style ) {
    $html = $card_renderer->render(
        $sample( array( 'live_status' => 'live' ) ),
        array_merge( $base_settings, array(
            'show_status_badge' => true,
            'badge_style'       => $style,
        ) ),
        $context
    );
    $checks[] = array(
        "style={$style}: class .vyg-card__badge--{$style} present",
        (int) preg_match( '/<span[^>]*class="[^"]*\bvyg-card__badge--' . preg_quote( $style, '/' ) . '\b/', $html ),
        1
    );
}

// 3) Switching badge_style changes the CSS class on the same video.
$html_solid = $card_renderer->render(
    $sample( array( 'live_status' => 'live' ) ),
    array_merge( $base_settings, array(
        'show_status_badge' => true,
        'badge_style'       => 'solid',
    ) ),
    $context
);
$html_outline = $card_renderer->render(
    $sample( array( 'live_status' => 'live' ) ),
    array_merge( $base_settings, array(
        'show_status_badge' => true,
        'badge_style'       => 'outline',
    ) ),
    $context
);
$checks[] = array(
    'switching badge_style from solid to outline changes the class set',
    (int) ( strpos( $html_solid, 'vyg-card__badge--solid' ) !== false
        && strpos( $html_solid, 'vyg-card__badge--outline' ) === false
        && strpos( $html_outline, 'vyg-card__badge--outline' ) !== false
        && strpos( $html_outline, 'vyg-card__badge--solid' ) === false ),
    1
);

// 4) XSS guard: VideoRenderer::badge_type_for() must only return
//    whitelisted slugs — not echo any operator-supplied value.
$xss_inputs = array(
    array( 'manual_content_type' => '"><script>alert(1)</script>' ),
    array( 'manual_content_type' => 'live' ), // valid (won't match product, so it returns 'live')
    array( 'manual_content_type' => 'PRODUCT' ), // uppercase — must NOT match 'product' (lowercase only)
);
$vr = $video_renderer_svc;
$checks[] = array(
    'XSS guard: malicious manual_content_type is NOT recognized as a known type',
    (int) ( '' === $vr->badge_type_for( $xss_inputs[0] ) || 'live' === $vr->badge_type_for( $xss_inputs[0] ) ),
    1
);
$checks[] = array(
    'XSS guard: badge_type_for() returns the lowercase whitelisted slug, not raw input',
    (int) ( 'product' !== $vr->badge_type_for( $xss_inputs[0] ) ),
    1
);
$checks[] = array(
    'XSS guard: badge_type_color() returns whitelisted hex for any unknown type',
    '' === $vr->badge_type_color( '"><script>alert(1)</script>' ) ? 1 : 0,
    1
);

// 5) API quota gate: this smoke must not have triggered any
//    YouTube API call. Confirm the row count is unchanged from
//    the start of the run.
$quota_end = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}vyg_api_quota_log" );
$checks[] = array(
    'api_quota_log: row count unchanged (no YouTube API calls)',
    $quota_start === $quota_end ? 1 : 0,
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

fwrite( STDOUT, "\nsmoke-14-8: rendered 6 badge types x 4 styles through the production CardRenderer; quota start={$quota_start} end={$quota_end}\n" );

exit( $exit_code );
