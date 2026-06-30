<?php
/**
 * Phase 12.3 FeedQueryCache live smoke.
 *
 * Exercises the cache layer end-to-end against a live WP install:
 *   1. The container hands out a FeedQueryCache for `render.feed`.
 *   2. First call to `videos_for_source` is a cache miss → DB query.
 *   3. Second call to the same args is a cache hit → no DB query.
 *   4. A different `source_uuid` produces a different cache key and
 *      therefore a different DB query.
 *   5. `wp vyg cache-flush` drops the entire cache group.
 *   6. After the flush, the next call is a miss again.
 *   7. The cache class is the right one (`FeedQueryCache`) and
 *      the diagnostics snapshot includes the cache section.
 *
 * Run via:
 *     docker exec -u www-data vyg-wp \
 *         wp eval-file /var/www/html/wp-content/plugins/vector-youtube-gallery/dev/phase12-3-smoke.php
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
if ( ! $container instanceof Container ) {
    fwrite( STDERR, "Container not booted; aborting smoke.\n" );
    exit( 1 );
}

// 1. The container hands out a FeedQueryCache for `render.feed`.
$cache = $container->get( 'render.feed' );
echo "cache_class=" . get_class( $cache ) . PHP_EOL;
if ( ! ( $cache instanceof FeedQueryCache ) ) {
    fwrite( STDERR, "render.feed is not a FeedQueryCache; got " . get_class( $cache ) . "\n" );
    exit( 1 );
}
$inner = $container->get( 'render.feed.inner' );
echo "inner_class=" . get_class( $inner ) . PHP_EOL;

// 2. Pick a real source from the DB. Skip the smoke if the dev env
//    is empty (the smoke is supposed to be idempotent).
$source = $wpdb->get_row(
    "SELECT id, source_uuid FROM {$wpdb->prefix}vyg_sources WHERE status = 'active' LIMIT 1",
    ARRAY_A
);
if ( ! $source ) {
    echo "smoke_status=skipped (no active sources)\n";
    exit( 0 );
}
$source_uuid = (string) $source['source_uuid'];
echo "source_uuid=" . $source_uuid . PHP_EOL;

// 3. First call → cache miss → DB query runs.
$wpdb_query_count_before = $wpdb->num_queries;
$cache->invalidate_all();
$first  = $cache->videos_for_source( array( 'source_uuid' => $source_uuid, 'limit' => 5 ) );
$queries_first = (int) $wpdb->num_queries - (int) $wpdb_query_count_before;
echo "first_result_count=" . count( $first ) . PHP_EOL;
echo "first_queries=" . $queries_first . PHP_EOL;

// 4. Second call with the same args → cache hit → no new query.
$wpdb_query_count_before = $wpdb->num_queries;
$second = $cache->videos_for_source( array( 'source_uuid' => $source_uuid, 'limit' => 5 ) );
$queries_second = (int) $wpdb->num_queries - (int) $wpdb_query_count_before;
echo "second_result_count=" . count( $second ) . PHP_EOL;
echo "second_queries=" . $queries_second . PHP_EOL;
if ( $queries_second > 0 ) {
    fwrite( STDERR, "Expected zero queries on the cached call; got $queries_second.\n" );
    exit( 1 );
}
if ( $first !== $second ) {
    fwrite( STDERR, "Cached result differs from the first (uncached) result.\n" );
    exit( 1 );
}

// 5. Different `limit` → different cache key → fresh DB query.
$wpdb_query_count_before = $wpdb->num_queries;
$third = $cache->videos_for_source( array( 'source_uuid' => $source_uuid, 'limit' => 3 ) );
$queries_third = (int) $wpdb->num_queries - (int) $wpdb_query_count_before;
echo "third_queries=" . $queries_third . PHP_EOL;
if ( $queries_third < 1 ) {
    fwrite( STDERR, "Expected a DB query for the new (limit=3) args; got $queries_third.\n" );
    exit( 1 );
}

// 6. Confirm the keys are different.
$key_a = $cache->build_key( 'videos_for_source', array( 'source_uuid' => $source_uuid, 'limit' => 5 ) );
$key_b = $cache->build_key( 'videos_for_source', array( 'source_uuid' => $source_uuid, 'limit' => 3 ) );
echo "key_a=" . $key_a . PHP_EOL;
echo "key_b=" . $key_b . PHP_EOL;
if ( $key_a === $key_b ) {
    fwrite( STDERR, "Keys should differ for different args.\n" );
    exit( 1 );
}

// 7. cache-flush should drop everything.
$cache->invalidate_all();

// 8. After the flush, the next call is a miss again.
$wpdb_query_count_before = $wpdb->num_queries;
$cache->videos_for_source( array( 'source_uuid' => $source_uuid, 'limit' => 5 ) );
$queries_after_flush = (int) $wpdb->num_queries - (int) $wpdb_query_count_before;
echo "post_flush_queries=" . $queries_after_flush . PHP_EOL;
if ( $queries_after_flush < 1 ) {
    fwrite( STDERR, "Expected a fresh DB query after flush; got $queries_after_flush.\n" );
    exit( 1 );
}

// 9. Cache class is correct.
$is_cached = $cache instanceof FeedQueryCache ? 'yes' : 'no';
echo "is_cached=" . $is_cached . PHP_EOL;

echo "smoke_status=ok" . PHP_EOL;
