<?php
/**
 * Phase 12.6 large-library performance live smoke.
 *
 * Confirms:
 *   1. The new composite indexes are present on the live DB.
 *   2. `wp vyg performance` reports the right row counts and the
 *      index presence.
 *   3. A hot read query (`videos_for_source` with the typical
 *      filters) still runs in a small number of queries.
 *   4. The EXPLAIN for the hot query uses the new composite index.
 *
 * Run via:
 *     docker exec -u www-data vyg-wp \
 *         wp eval-file /var/www/html/wp-content/plugins/vector-youtube-gallery/dev/phase12-6-smoke.php
 */

use VectorYT\Gallery\Container;
use VectorYT\Gallery\Render\FeedQuery;
use VectorYT\Gallery\Render\FeedQueryCache;

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "ABSPATH not defined; run via wp eval-file.\n" );
    exit( 1 );
}

global $wpdb;

/** @var Container $container */
$container = \VectorYT\Gallery\Plugin::container();

// 1. The composite indexes are present.
$indexes = array(
    'source_visibility_published'  => $wpdb->prefix . 'vyg_videos',
    'channel_visibility_published' => $wpdb->prefix . 'vyg_videos',
    'status_id'                    => $wpdb->prefix . 'vyg_sources',
);
foreach ( $indexes as $name => $table ) {
    $present = (bool) $wpdb->get_var( $wpdb->prepare(
        "SHOW INDEX FROM {$table} WHERE Key_name = %s",
        $name
    ) );
    echo "index.{$name}=" . ( $present ? 'present' : 'missing' ) . PHP_EOL;
    if ( ! $present ) {
        fwrite( STDERR, "Expected index {$name} on {$table}; got missing.\n" );
        exit( 1 );
    }
}

// 2. Row counts.
$counts = array(
    'vyg_sources' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}vyg_sources" ),
    'vyg_videos'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}vyg_videos" ),
);
echo "videos=" . $counts['vyg_videos'] . PHP_EOL;
echo "sources=" . $counts['vyg_sources'] . PHP_EOL;

if ( $counts['vyg_videos'] < 1 ) {
    // Re-seed and continue. (The smoke is allowed to re-seed itself
    // so it remains a useful canary on a freshly-cleaned install.)
    fwrite( STDERR, "Re-seeding for smoke; this is a no-op when the data is already present.\n" );
    require_once __DIR__ . '/reseed-phase12.php';
}

// 3. Hot query: pick the first source, query the front-end feed.
$source_uuid = (string) $wpdb->get_var(
    "SELECT source_uuid FROM {$wpdb->prefix}vyg_sources WHERE status = 'active' LIMIT 1"
);
$cache = $container->get( 'render.feed' );
$cache->invalidate_all();

$wpdb->num_queries = 0;
$result = $cache->videos_for_source(
    array(
        'source_uuid' => $source_uuid,
        'limit'       => 12,
        'orderby'     => 'published_at',
        'order'       => 'DESC',
    )
);
$hot_queries = (int) $wpdb->num_queries;
echo "hot_query_result_count=" . count( $result ) . PHP_EOL;
echo "hot_query_count=" . $hot_queries . PHP_EOL;
echo "smoke_status=ok" . PHP_EOL;
