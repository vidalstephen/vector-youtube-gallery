<?php
/**
 * Re-seed dev/curl-test data after a destructive `wp vyg site-cleanup
 * --yes` (Phase 12.4) wiped the local tables. Idempotent — re-running
 * after the data is back is safe and will skip inserts.
 *
 * Run via:
 *     docker exec -u www-data vyg-wp \
 *         wp eval-file /var/www/html/wp-content/plugins/vector-youtube-gallery/dev/reseed-phase12.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

// Two demo sources, two feeds, a handful of videos — enough to drive
// `wp vyg network-diagnostics` and the cache smoke through realistic
// counts without touching YouTube.
$sources = array(
    'a1b2c3d4-0001-4000-8000-000000000001' => array(
        'source_type'       => 'channel',
        'auth_mode'         => 'api_key',
        'youtube_channel_id' => 'UC_demo_channel',
        'handle'            => '@demochannel',
        'title'             => 'Demo Channel',
        'thumbnail_url'     => 'https://example.test/democh.jpg',
    ),
    'a1b2c3d4-0001-4000-8000-000000000002' => array(
        'source_type'        => 'playlist',
        'auth_mode'          => 'api_key',
        'youtube_playlist_id' => 'PL_demo_playlist',
        'handle'             => '@demoplaylist',
        'title'              => 'Demo Playlist',
        'thumbnail_url'      => 'https://example.test/demopl.jpg',
    ),
);

foreach ( $sources as $uuid => $data ) {
    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}vyg_sources WHERE source_uuid = %s",
        $uuid
    ) );
    if ( $existing ) {
        continue;
    }
    $wpdb->insert(
        $wpdb->prefix . 'vyg_sources',
        array_merge(
            array(
                'source_uuid' => $uuid,
                'status'      => 'active',
                'created_at'  => current_time( 'mysql', true ),
                'updated_at'  => current_time( 'mysql', true ),
            ),
            $data
        ),
        array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
    );
}

$feeds = array(
    array(
        'feed_uuid' => 'feed_demo_one',
        'name'      => 'Demo Feed One',
        'feed_type' => 'mixed',
        'layout'    => 'grid',
    ),
    array(
        'feed_uuid' => 'feed_demo_two',
        'name'      => 'Demo Feed Two',
        'feed_type' => 'mixed',
        'layout'    => 'masonry',
    ),
);

foreach ( $feeds as $f ) {
    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}vyg_feeds WHERE feed_uuid = %s",
        $f['feed_uuid']
    ) );
    if ( $existing ) {
        continue;
    }
    $wpdb->insert(
        $wpdb->prefix . 'vyg_feeds',
        array(
            'feed_uuid' => $f['feed_uuid'],
            'name'      => $f['name'],
            'feed_type' => $f['feed_type'],
            'layout'    => $f['layout'],
            'status'    => 'active',
            'created_at' => current_time( 'mysql', true ),
            'updated_at' => current_time( 'mysql', true ),
        ),
        array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
    );
}

$source_ids = $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}vyg_sources ORDER BY id ASC" );

$videos_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}vyg_videos" );
if ( $videos_count < 50 && $source_ids ) {
    // Seed 50 demo videos attached to the first source.
    for ( $i = 1; $i <= 50; $i++ ) {
        $youtube_id = 'demo_vid_' . str_pad( (string) $i, 3, '0', STR_PAD_LEFT );
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}vyg_videos WHERE youtube_video_id = %s",
            $youtube_id
        ) );
        if ( $exists ) {
            continue;
        }
        $wpdb->insert(
            $wpdb->prefix . 'vyg_videos',
            array(
                'youtube_video_id'    => $youtube_id,
                'youtube_channel_id'  => 'UC_demo_channel',
                'title'               => 'Demo Video ' . $i,
                'description_excerpt' => 'Seeded by dev/reseed-phase12.php',
                'content_type'        => 'video',
                'availability_status' => 'available',
                'privacy_status'      => 'public',
                'upload_status'       => 'processed',
                'embeddable'          => 1,
                'duration_iso'        => 'PT' . ( 60 + $i ) . 'S',
                'duration_seconds'    => 60 + $i,
                'view_count'          => 1000 + $i,
                'published_at'        => gmdate( 'Y-m-d H:i:s', time() - ( 86400 * $i ) ),
                'created_at'          => current_time( 'mysql', true ),
                'updated_at'          => current_time( 'mysql', true ),
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s' )
        );
    }
}

echo "sources=" . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}vyg_sources" ) . PHP_EOL;
echo "feeds="   . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}vyg_feeds" ) . PHP_EOL;
echo "videos="  . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}vyg_videos" ) . PHP_EOL;
echo "smoke_status=ok" . PHP_EOL;
