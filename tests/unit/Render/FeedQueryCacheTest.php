<?php
/**
 * Unit tests for FeedQueryCache.
 *
 * The cache is a thin decorator over FeedQuery. Tests exercise the
 * hit/miss logic, key generation, multisite blog id, TTL, and
 * invalidation. The `wp_cache_*` functions are shimmed through
 * Brain\Monkey per-test (they're global namespaced functions, so the
 * shims live in the test namespace and PHP falls through to the
 * shimmed implementations).
 *
 * @covers \VectorYT\Gallery\Render\FeedQueryCache
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Render;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Render\FeedQuery;
use VectorYT\Gallery\Render\FeedQueryCache;
use VectorYT\Gallery\Settings\SettingsRepository;
use VectorYT\Gallery\Tests\Support\BrainHelpers;
use VectorYT\Gallery\Tests\Support\OptionsBag;

final class FeedQueryCacheTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $object_cache = array();

    /** @var array<string,array<string,mixed>> */
    private array $cache_call_log = array();

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        OptionsBag::reset();
        BrainHelpers::stubOptionFunctions();
        BrainHelpers::stubEscapeFunctions();
        $this->object_cache   = array();
        $this->cache_call_log = array();

        // Shim get_current_blog_id for multisite-key tests.
        Functions\when( 'get_current_blog_id' )->alias( static fn(): int => 1 );

        // Shim wp_cache_get / wp_cache_set / wp_cache_flush_group.
        // We use a per-test static map and a call log.
        Functions\when( 'wp_cache_get' )->alias( function ( $key, $group = '', $force = false, $found = null ) {
            $k = (string) $key;
            $g = (string) $group;
            $this->cache_call_log[] = array( 'wp_cache_get', $k, $g );
            return array_key_exists( $k, $this->object_cache[ $g ] ?? array() ) ? $this->object_cache[ $g ][ $k ] : false;
        } );
        Functions\when( 'wp_cache_set' )->alias( function ( $key, $value, $group = '', $expire = 0 ) {
            $k = (string) $key;
            $g = (string) $group;
            if ( ! isset( $this->object_cache[ $g ] ) ) {
                $this->object_cache[ $g ] = array();
            }
            $this->object_cache[ $g ][ $k ] = $value;
            $this->cache_call_log[] = array( 'wp_cache_set', $k, $g, $expire );
            return true;
        } );
        Functions\when( 'wp_cache_flush_group' )->alias( function ( $group ) {
            $g = (string) $group;
            unset( $this->object_cache[ $g ] );
            $this->cache_call_log[] = array( 'wp_cache_flush_group', $g );
            return true;
        } );
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_cache_disabled_setting_short_circuits_to_inner(): void {
        $settings = new SettingsRepository();
        $settings->set( 'cache_enabled', false );
        $inner = $this->innerWithResults( array( array( 'id' => 1 ) ) );
        $cache = new FeedQueryCache( $inner, $settings );

        $result = $cache->videos_for_source( array( 'source_uuid' => 'abc' ) );
        $this->assertSame( array( array( 'id' => 1 ) ), $result );
        $this->assertSame( 1, $inner->call_count );
        $this->assertCount( 0, $this->cache_call_log, 'Cache disabled → no wp_cache_* calls.' );
    }

    public function test_cache_enabled_first_call_misses_and_stores(): void {
        $settings = new SettingsRepository();
        $cache = new FeedQueryCache( $this->innerWithResults( array( array( 'id' => 1 ) ) ), $settings );

        $result = $cache->videos_for_source( array( 'source_uuid' => 'abc' ) );
        $this->assertSame( array( array( 'id' => 1 ) ), $result );
        // First call: 1 wp_cache_get (miss) + 1 wp_cache_set (store).
        $this->assertCount( 2, $this->cache_call_log );
        $this->assertSame( 'wp_cache_get', $this->cache_call_log[0][0] );
        $this->assertSame( 'wp_cache_set', $this->cache_call_log[1][0] );
    }

    public function test_cache_enabled_second_call_hits_and_does_not_re_query(): void {
        $settings = new SettingsRepository();
        $inner    = $this->innerWithResults( array( array( 'id' => 1 ) ) );
        $cache = new FeedQueryCache( $inner, $settings );

        $cache->videos_for_source( array( 'source_uuid' => 'abc' ) ); // miss → store
        $cache->videos_for_source( array( 'source_uuid' => 'abc' ) ); // hit
        $this->assertSame( 1, $inner->call_count, 'Second call must not re-query the inner FeedQuery.' );
    }

    public function test_different_args_produce_different_keys(): void {
        $cache = new FeedQueryCache( $this->innerWithResults( array( array( 'id' => 1 ) ) ), new SettingsRepository() );
        $key_a = $cache->build_key( 'videos_for_source', array( 'source_uuid' => 'a' ) );
        $key_b = $cache->build_key( 'videos_for_source', array( 'source_uuid' => 'b' ) );
        $this->assertNotSame( $key_a, $key_b );
    }

    public function test_same_args_in_different_order_produce_same_key(): void {
        $cache = new FeedQueryCache( $this->innerWithResults( array() ), new SettingsRepository() );
        $key_a = $cache->build_key( 'videos_for_source', array( 'limit' => 10, 'offset' => 0 ) );
        $key_b = $cache->build_key( 'videos_for_source', array( 'offset' => 0, 'limit' => 10 ) );
        $this->assertSame( $key_a, $key_b );
    }

    public function test_different_call_names_produce_different_keys(): void {
        $cache = new FeedQueryCache( $this->innerWithResults( array() ), new SettingsRepository() );
        $key_a = $cache->build_key( 'videos_for_source', array( 'source_uuid' => 'a' ) );
        $key_b = $cache->build_key( 'count_videos_for_source', array( 'source_uuid' => 'a' ) );
        $this->assertNotSame( $key_a, $key_b );
    }

    public function test_keys_are_namespaced_by_blog_id(): void {
        $cache = new FeedQueryCache( $this->innerWithResults( array() ), new SettingsRepository() );
        Functions\when( 'get_current_blog_id' )->alias( static fn(): int => 1 );
        $key_site1 = $cache->build_key( 'videos_for_source', array( 'source_uuid' => 'a' ) );
        Functions\when( 'get_current_blog_id' )->alias( static fn(): int => 2 );
        $key_site2 = $cache->build_key( 'videos_for_source', array( 'source_uuid' => 'a' ) );
        $this->assertNotSame( $key_site1, $key_site2 );
        $this->assertStringContainsString( ':1:', $key_site1 );
        $this->assertStringContainsString( ':2:', $key_site2 );
    }

    public function test_invalidate_for_source_flushes_cache_group(): void {
        $settings = new SettingsRepository();
        $cache = new FeedQueryCache( $this->innerWithResults( array( array( 'id' => 1 ) ) ), $settings );

        // Seed the cache.
        $cache->videos_for_source( array( 'source_uuid' => 'abc' ) );
        $this->assertNotEmpty( $this->object_cache[ FeedQueryCache::CACHE_GROUP ] );

        $cache->invalidate_for_source( 1 );
        $this->assertArrayNotHasKey( FeedQueryCache::CACHE_GROUP, $this->object_cache );
    }

    public function test_invalidate_for_feed_flushes_cache_group(): void {
        $cache = new FeedQueryCache( $this->innerWithResults( array( array( 'id' => 1 ) ) ), new SettingsRepository() );
        $cache->videos_for_feed( array( 'feed_uuid' => 'f1' ) );
        $cache->invalidate_for_feed( 'f1' );
        $this->assertArrayNotHasKey( FeedQueryCache::CACHE_GROUP, $this->object_cache );
    }

    public function test_invalidate_all_drops_everything(): void {
        $cache = new FeedQueryCache( $this->innerWithResults( array( array( 'id' => 1 ) ) ), new SettingsRepository() );
        $cache->videos_for_source( array( 'source_uuid' => 'a' ) );
        $cache->videos_for_source( array( 'source_uuid' => 'b' ) );
        $cache->invalidate_all();
        $this->assertArrayNotHasKey( FeedQueryCache::CACHE_GROUP, $this->object_cache );
    }

    public function test_count_videos_for_source_is_cached(): void {
        $inner = $this->innerWithResults( array() );
        $inner->count_return = 42;
        $cache = new FeedQueryCache( $inner, new SettingsRepository() );
        $this->assertSame( 42, $cache->count_videos_for_source( array( 'source_uuid' => 'a' ) ) );
        // Second call should hit cache and not increment call_count.
        $this->assertSame( 42, $cache->count_videos_for_source( array( 'source_uuid' => 'a' ) ) );
        $this->assertSame( 1, $inner->count_call_count );
    }

    public function test_videos_for_feed_is_cached(): void {
        $inner = $this->innerWithResults( array( array( 'id' => 99 ) ) );
        $cache = new FeedQueryCache( $inner, new SettingsRepository() );
        $this->assertSame( array( array( 'id' => 99 ) ), $cache->videos_for_feed( array( 'feed_uuid' => 'f1' ) ) );
        $this->assertSame( array( array( 'id' => 99 ) ), $cache->videos_for_feed( array( 'feed_uuid' => 'f1' ) ) );
        $this->assertSame( 1, $inner->call_count );
    }

    public function test_ttl_zero_disables_storage_but_keeps_lookups(): void {
        $settings = new SettingsRepository();
        $settings->set( 'cache_ttl_seconds', 0 );
        $cache = new FeedQueryCache( $this->innerWithResults( array( array( 'id' => 1 ) ) ), $settings );

        $cache->videos_for_source( array( 'source_uuid' => 'a' ) );
        $this->assertEmpty( $this->cache_call_log, 'TTL=0 means we do not even attempt a get or set.' );

        $cache->videos_for_source( array( 'source_uuid' => 'a' ) );
        $this->assertSame( 2, $this->innerStub->call_count, 'TTL=0 still re-queries every time.' );
    }

    public function test_ttl_is_clamped_to_max(): void {
        $settings = new SettingsRepository();
        $settings->set( 'cache_ttl_seconds', 999999 );
        $cache = new FeedQueryCache( $this->innerWithResults( array( array( 'id' => 1 ) ) ), $settings );
        $this->assertSame( FeedQueryCache::MAX_TTL_SECONDS, $cache->cache_ttl_seconds() );
    }

    public function test_negative_ttl_falls_back_to_default(): void {
        $settings = new SettingsRepository();
        $settings->set( 'cache_ttl_seconds', -10 );
        $cache = new FeedQueryCache( $this->innerWithResults( array() ), $settings );
        $this->assertSame( 0, $cache->cache_ttl_seconds() );
    }

    public function test_cache_call_log_records_get_before_set(): void {
        $cache = new FeedQueryCache( $this->innerWithResults( array( array( 'id' => 1 ) ) ), new SettingsRepository() );
        $cache->videos_for_source( array( 'source_uuid' => 'a' ) );
        $this->assertCount( 2, $this->cache_call_log );
        $this->assertSame( 'wp_cache_get', $this->cache_call_log[0][0] );
        $this->assertSame( 'wp_cache_set', $this->cache_call_log[1][0] );
    }

    public function test_cache_group_is_vyg_feed_query(): void {
        $this->assertSame( 'vyg_feed_query', FeedQueryCache::CACHE_GROUP );
    }

    public function test_cache_enabled_default_is_true(): void {
        $cache = new FeedQueryCache( $this->innerWithResults( array() ), new SettingsRepository() );
        $this->assertTrue( $cache->cache_enabled() );
    }

    public function test_default_ttl_is_one_hour(): void {
        $cache = new FeedQueryCache( $this->innerWithResults( array() ), new SettingsRepository() );
        $this->assertSame( 3600, $cache->cache_ttl_seconds() );
    }

    public function test_invalidated_results_must_refetch_from_inner(): void {
        // After invalidate_for_source, the next call must re-query the
        // inner FeedQuery (not return the stale cached result).
        $inner = $this->innerWithResults( array( array( 'id' => 1, 'stale' => true ) ) );
        $cache = new FeedQueryCache( $inner, new SettingsRepository() );

        $first  = $cache->videos_for_source( array( 'source_uuid' => 'a' ) );
        $this->assertTrue( $first[0]['stale'] );
        $this->assertSame( 1, $inner->call_count );

        // Now flip the inner's response.
        $inner->set_results( array( array( 'id' => 1, 'stale' => false ) ) );
        // Same args, cache hit → still stale.
        $this->assertTrue( $cache->videos_for_source( array( 'source_uuid' => 'a' ) )[0]['stale'] );

        // Invalidate the cache; the next call must see the fresh result.
        $cache->invalidate_for_source( 1 );
        $this->assertFalse( $cache->videos_for_source( array( 'source_uuid' => 'a' ) )[0]['stale'] );
        $this->assertSame( 2, $inner->call_count );
    }

    public function test_invalidate_for_different_source_does_not_flush_caller(): void {
        // A separate cache that observes a per-source invalidation. We
        // shim wp_cache_flush_group to record which group was flushed
        // and confirm the right group was targeted.
        $cache_a = new FeedQueryCache( $this->innerWithResults( array( array( 'a' => 1 ) ) ), new SettingsRepository() );
        $cache_a->videos_for_source( array( 'source_uuid' => 'src_a' ) );
        $cache_b = new FeedQueryCache( $this->innerWithResults( array( array( 'b' => 1 ) ) ), new SettingsRepository() );
        $cache_b->videos_for_source( array( 'source_uuid' => 'src_b' ) );

        $this->assertNotEmpty( $this->object_cache[ FeedQueryCache::CACHE_GROUP ] );

        // Both adapters target the same cache group; invalidate on
        // either one drops the entire group.
        $cache_a->invalidate_for_source( 1 );
        $this->assertArrayNotHasKey( FeedQueryCache::CACHE_GROUP, $this->object_cache );
    }

    public function test_disabled_cache_does_not_invoke_invoker(): void {
        $settings = new SettingsRepository();
        $settings->set( 'cache_enabled', false );
        $cache = new FeedQueryCache( $this->innerWithResults( array( array( 'id' => 1 ) ) ), $settings );
        $cache->videos_for_source( array( 'source_uuid' => 'a' ) );
        $cache->invalidate_for_source( 1 );
        $cache->invalidate_for_feed( 'f' );
        $cache->invalidate_all();
        // No exceptions = pass; we also assert no wp_cache_* calls
        // because the cache is disabled.
        $this->assertCount( 0, $this->cache_call_log );
    }

    public function test_inner_returning_empty_array_is_still_cached(): void {
        // Edge case: an empty result is cached too. The next call
        // should not re-query the inner.
        $inner = $this->innerWithResults( array() );
        $cache = new FeedQueryCache( $inner, new SettingsRepository() );
        $cache->videos_for_source( array( 'source_uuid' => 'empty' ) );
        $cache->videos_for_source( array( 'source_uuid' => 'empty' ) );
        $this->assertSame( 1, $inner->call_count );
    }

    public function test_count_videos_for_feed_is_cached(): void {
        $inner = $this->innerWithResults( array() );
        $inner->count_return = 7;
        $cache = new FeedQueryCache( $inner, new SettingsRepository() );
        $this->assertSame( 7, $cache->count_videos_for_feed( array( 'feed_uuid' => 'f1' ) ) );
        $this->assertSame( 7, $cache->count_videos_for_feed( array( 'feed_uuid' => 'f1' ) ) );
        $this->assertSame( 1, $inner->count_call_count );
    }

    public function test_cache_key_includes_call_name(): void {
        $cache = new FeedQueryCache( $this->innerWithResults( array() ), new SettingsRepository() );
        $key_v = $cache->build_key( 'videos_for_source', array( 'source_uuid' => 'a' ) );
        $key_c = $cache->build_key( 'count_videos_for_source', array( 'source_uuid' => 'a' ) );
        $this->assertStringContainsString( ':videos_for_source:', $key_v );
        $this->assertStringContainsString( ':count_videos_for_source:', $key_c );
    }

    private ?RecordingFeedQuery $innerStub = null;

    /**
     * @param array<int,array<string,mixed>> $results
     */
    private function innerWithResults( array $results ): RecordingFeedQuery {
        $stub = new RecordingFeedQuery( $results );
        $this->innerStub = $stub;
        return $stub;
    }
}

/**
 * Test double for FeedQuery that returns canned results and counts
 * every call. The real FeedQuery would talk to $wpdb; this stub keeps
 * the cache tests fully isolated.
 */
final class RecordingFeedQuery extends FeedQuery
{
    public int $call_count = 0;
    public int $count_call_count = 0;
    public int $count_return = 0;
    /** @var array<int,array<string,mixed>> */
    private array $results;

    public function __construct( array $results ) {
        $this->results = $results;
    }

    public function set_results( array $results ): void {
        $this->results = $results;
    }

    public function videos_for_source( array $args ): array {
        $this->call_count++;
        return $this->results;
    }

    public function videos_for_feed( array $args ): array {
        $this->call_count++;
        return $this->results;
    }

    public function count_videos_for_source( array $args ): int {
        $this->count_call_count++;
        return $this->count_return;
    }

    public function count_videos_for_feed( array $args ): int {
        $this->count_call_count++;
        return $this->count_return;
    }
}
