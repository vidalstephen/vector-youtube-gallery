<?php
/**
 * Phase 14.7 live smoke — per-channel avatar gradient.
 *
 * Renders 3 different sources and asserts each card has a distinct
 * --aa/--ab style attribute pair on its .vyg-card__channel-avatar
 * element (when show_channel_avatar is enabled). Also verifies the
 * XSS guard by feeding a malicious pair into the helper directly.
 *
 * Usage: docker exec -u www-data vyg-wp php /var/www/html/wp-content/plugins/vector-youtube-gallery/dev/smoke-14-7.php
 */

require_once '/var/www/html/wp-load.php';

$container = \VectorYT\Gallery\Plugin::container();
$renderer  = $container->get( 'render.renderer' );

global $wpdb;

// Audit reality: the dev install's `vyg_videos` row does not yet
// have a `channel_avatar_url` column. The `show_avatar` flag in
// CardRenderer is gated on BOTH the setting AND a real
// `channel_avatar_url` value. To exercise the avatar gradient we
// add the column in-process (no-op if already present), seed a
// sample avatar URL on a few rows, render, then roll back the URL
// values (column stays — it is a TEXT NULL so it is harmless to
// other code paths).
$videos_table = $wpdb->prefix . 'vyg_videos';
$has_col = $wpdb->get_results( "SHOW COLUMNS FROM {$videos_table} LIKE 'channel_avatar_url'", ARRAY_A );
if ( empty( $has_col ) ) {
    $wpdb->query( "ALTER TABLE {$videos_table} ADD COLUMN channel_avatar_url TEXT DEFAULT NULL AFTER tone_color" );
    fwrite( STDOUT, "smoke-14-7: added temporary channel_avatar_url column for the smoke run\n" );
}

// Pick up to 3 distinct channel ids from the seeded data.
$channels = $wpdb->get_results(
    "SELECT DISTINCT youtube_channel_id FROM {$videos_table} WHERE youtube_channel_id IS NOT NULL AND youtube_channel_id <> '' LIMIT 3",
    ARRAY_A
);
if ( count( $channels ) < 2 ) {
    fwrite( STDERR, "smoke-14-7: need at least 2 distinct channel ids in the dev install; got " . count( $channels ) . "\n" );
    exit( 1 );
}

// Stash a sample avatar URL on one row per channel. We only touch
// the first matching row per channel; the gradient is a per-channel
// property, so one row is enough to exercise it.
$sample_url = 'https://example.com/avatar-' . md5( 'smoke-14-7' ) . '.jpg';
$touched_video_ids = array();
foreach ( $channels as $row ) {
    $ch = (string) $row['youtube_channel_id'];
    $vid = $wpdb->get_var( $wpdb->prepare(
        "SELECT youtube_video_id FROM {$videos_table} WHERE youtube_channel_id = %s LIMIT 1",
        $ch
    ) );
    if ( null === $vid ) {
        continue;
    }
    $wpdb->update(
        $videos_table,
        array( 'channel_avatar_url' => $sample_url ),
        array( 'youtube_video_id' => (string) $vid ),
        array( '%s' ),
        array( '%s' )
    );
    $touched_video_ids[] = (string) $vid;
}

$exit_code = 0;
$checks    = array();

// Render each source with the avatar enabled. We pass the saved
// display config via the `source_config` key — the renderer's main
// entry point picks it up and routes through the feed query →
// card renderer → partial chain, so the final HTML reflects the
// production code path.
$pairs = array();
foreach ( $channels as $row ) {
    $ch = (string) $row['youtube_channel_id'];
    $source_uuid = (string) $wpdb->get_var( $wpdb->prepare(
        "SELECT source_uuid FROM {$wpdb->prefix}vyg_sources WHERE youtube_channel_id = %s LIMIT 1",
        $ch
    ) );
    if ( '' === $source_uuid ) {
        continue;
    }

    $args = array(
        'source_uuid' => $source_uuid,
        'layout'      => 'grid',
        'per_page'    => 1,
        'wrapper_id'  => 'smoke-14-7-' . substr( md5( $ch ), 0, 8 ),
        // CardRenderer is invoked directly by Renderer::render(); the
        // shortcode defaults don't apply unless we go through
        // ShortcodeRegistrar. Pass the show_avatar flag as a top-level
        // arg so CardSettings::resolve() picks it up.
        'show_channel_avatar' => true,
        'source_config' => array(
            'card_settings' => array(
                'global' => array( 'show_channel_avatar' => true ),
            ),
        ),
    );
    $html = $renderer->render( $args );

    if ( preg_match( '/<img[^>]*class="[^"]*vyg-card__channel-avatar\b[^"]*"[^>]*style="[^"]*--aa:\s*(#[0-9a-fA-F]{6})[^"]*--ab:\s*(#[0-9a-fA-F]{6})/', $html, $m ) ) {
        $pairs[ $ch ] = strtolower( $m[1] ) . '|' . strtolower( $m[2] );
    } else {
        $pairs[ $ch ] = null;
    }
}

// Roll back the avatar URLs (column itself stays — see note above).
foreach ( $touched_video_ids as $vid ) {
    $wpdb->update(
        $videos_table,
        array( 'channel_avatar_url' => null ),
        array( 'youtube_video_id' => $vid ),
        array( '%s' ),
        array( '%s' )
    );
}

$rendered = array_filter( $pairs );
$checks[] = array(
    'every source with a video produces a --aa/--ab pair on its avatar',
    count( $rendered ),
    count( $pairs )
);
$distinct_pairs = array_values( array_unique( $rendered ) );
$checks[] = array(
    'at least 2 distinct --aa/--ab pairs across the seeded channels',
    count( $distinct_pairs ) >= 2 ? 1 : 0,
    1
);

// XSS guard: feed a malicious pair into the helper and confirm it
// rejects the injection (returns a clean hex pair from the
// channel-id tier).
$malicious = '#"><script>alert(1)</script>';
$vr = new \VectorYT\Gallery\Render\VideoRenderer();
$res = $vr->avatar_colors( array(
    'avatar_color_a'      => $malicious,
    'avatar_color_b'      => $malicious,
    'youtube_channel_id'  => 'UC_smoke_14_7_xss_helper',
) );
$res_has_script = ( strpos( $res[0], '<' ) !== false ) || ( strpos( $res[1], '<' ) !== false );
$res_has_clean = (bool) preg_match( '/^#[0-9a-f]{6}$/', $res[0] ) && (bool) preg_match( '/^#[0-9a-f]{6}$/', $res[1] );
$checks[] = array(
    'XSS guard: avatar_colors() rejects malicious pair and returns clean channel-id hash',
    ( ! $res_has_script && $res_has_clean ) ? 1 : 0,
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

if ( ! empty( $pairs ) ) {
    fwrite( STDOUT, "\nAvatar pairs captured across " . count( $pairs ) . " channels:\n" );
    foreach ( $pairs as $ch => $p ) {
        fwrite( STDOUT, "  - {$ch} → " . ( null === $p ? '(no avatar rendered)' : $p ) . "\n" );
    }
}

exit( $exit_code );
