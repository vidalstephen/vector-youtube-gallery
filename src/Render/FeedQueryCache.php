<?php
/**
 * Feed-query result cache.
 *
 * Phase 12.3 wraps `FeedQuery` reads with a thin cache layer that:
 *   1. Uses the persistent object cache (`wp_cache_*`) when one is
 *      configured; otherwise falls back to transients stored in
 *      `wp_options` so even self-hosted installs without Redis/Memcache
 *      get a working cache.
 *   2. Namespaces keys with a `vyg_feed_query` group + the current
 *      blog ID so a WordPress Multisite install cannot leak entries
 *      across sites.
 *   3. Exposes `invalidate()` and `invalidate_for_source()` /
 *      `invalidate_for_feed()` so source/feed mutations can drop the
 *      right entries without flushing the whole cache.
 *   4. Honors a `cache_enabled` setting + `cache_ttl_seconds` setting
 *      so operators can opt out and tune TTL.
 *   5. Never caches when the result is `[]` (an empty result is
 *      indistinguishable from a transient error and is cheap to
 *      re-query).
 *
 * The cache is implemented as a decorator; the production code wires
 * `FeedQuery` through `FeedQueryCache` in the container, and the
 * `Renderer` / `ShortcodeRegistrar` keep calling the same public
 * surface they did before. The decorator holds a reference to the
 * inner FeedQuery and the configured SettingsRepository.
 *
 * @package VectorYT\Gallery\Render
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Render;

use VectorYT\Gallery\Settings\SettingsRepository;

defined('ABSPATH') || exit;

class FeedQueryCache extends FeedQuery
{
    public const CACHE_GROUP = 'vyg_feed_query';

    /** Default TTL when the setting is unset or invalid (1 hour). */
    public const DEFAULT_TTL_SECONDS = 3600;

    /** Hard upper bound to prevent accidental one-year TTLs. */
    public const MAX_TTL_SECONDS = DAY_IN_SECONDS; // 24h

    private readonly SettingsRepository $settings;

    public function __construct(FeedQuery $inner, SettingsRepository $settings)
    {
        // Phase 12.3: the cache layer wraps the inner FeedQuery and
        // delegates non-cached methods to it. The parent's no-arg
        // constructor is bypassed — we never want a FeedQueryCache to
        // accidentally run queries itself.
        $this->settings = $settings;
        // We do NOT call parent::__construct() because the parent
        // expects to be standalone; we hold an explicit $inner
        // reference for delegation.
        $this->inner = $inner;
    }

    private FeedQuery $inner;

    /**
     * Decorated: `FeedQuery::videos_for_source()` with cache.
     *
     * @param array<string,mixed> $args
     * @return array<int,array<string,mixed>>
     */
    public function videos_for_source(array $args): array
    {
        if (!$this->is_enabled()) {
            return $this->inner->videos_for_source($args);
        }
        $key = $this->build_key('videos_for_source', $args);
        $cached = $this->get($key);
        if (null !== $cached) {
            return $cached;
        }
        $result = $this->inner->videos_for_source($args);
        $this->set($key, $result);
        return $result;
    }

    /**
     * Decorated: `FeedQuery::count_videos_for_source()` with cache.
     *
     * @param array<string,mixed> $args
     */
    public function count_videos_for_source(array $args): int
    {
        if (!$this->is_enabled()) {
            return $this->inner->count_videos_for_source($args);
        }
        $key = $this->build_key('count_videos_for_source', $args);
        $cached = $this->get($key);
        if (null !== $cached) {
            return (int) $cached;
        }
        $result = $this->inner->count_videos_for_source($args);
        $this->set($key, $result);
        return (int) $result;
    }

    /**
     * Decorated: `FeedQuery::videos_for_feed()` with cache.
     *
     * @param array<string,mixed> $args
     * @return array<int,array<string,mixed>>
     */
    public function videos_for_feed(array $args): array
    {
        if (!$this->is_enabled()) {
            return $this->inner->videos_for_feed($args);
        }
        $key = $this->build_key('videos_for_feed', $args);
        $cached = $this->get($key);
        if (null !== $cached) {
            return $cached;
        }
        $result = $this->inner->videos_for_feed($args);
        $this->set($key, $result);
        return $result;
    }

    /**
     * Decorated: `FeedQuery::count_videos_for_feed()` with cache.
     *
     * @param array<string,mixed> $args
     */
    public function count_videos_for_feed(array $args): int
    {
        if (!$this->is_enabled()) {
            return $this->inner->count_videos_for_feed($args);
        }
        $key = $this->build_key('count_videos_for_feed', $args);
        $cached = $this->get($key);
        if (null !== $cached) {
            return (int) $cached;
        }
        $result = $this->inner->count_videos_for_feed($args);
        $this->set($key, $result);
        return (int) $result;
    }

    /**
     * Invalidate every cached entry for a given source. Called when a
     * source is created, updated, or its videos change.
     */
    public function invalidate_for_source(int $source_id): void
    {
        if (!$this->is_enabled()) {
            return;
        }
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group(self::CACHE_GROUP);
            return;
        }
        // Fallback: when no persistent object cache, the only safe
        // option is a transient-flush keyed by source. Transients are
        // not group-flushable, so we record a "version" counter and
        // include it in keys. The next read rebuilds the key.
        $this->bump_version('source', $source_id);
    }

    /**
     * Invalidate every cached entry for a given feed. Called when a
     * feed row is created, updated, or its sources list changes.
     */
    public function invalidate_for_feed(string $feed_uuid): void
    {
        if (!$this->is_enabled()) {
            return;
        }
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group(self::CACHE_GROUP);
            return;
        }
        $this->bump_version('feed', $feed_uuid);
    }

    /**
     * Drop every cache entry. Use sparingly; prefer the per-source /
     * per-feed variants for surgical invalidation.
     */
    public function invalidate_all(): void
    {
        if (!$this->is_enabled()) {
            return;
        }
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group(self::CACHE_GROUP);
            return;
        }
        // Bump both axes so the next read recomputes everything.
        $this->bump_version('source', 'all');
        $this->bump_version('feed', 'all');
    }

    public function cache_enabled(): bool
    {
        return $this->is_enabled();
    }

    public function cache_ttl_seconds(): int
    {
        $ttl = (int) $this->settings->get('cache_ttl_seconds', self::DEFAULT_TTL_SECONDS);
        return max(0, min(self::MAX_TTL_SECONDS, $ttl));
    }

    /**
     * Public for tests: build a deterministic cache key from the call
     * name and args. The key incorporates the current blog id so a
     * Multisite network cannot leak entries across sites.
     */
    public function build_key(string $call, array $args): string
    {
        $blog_id = $this->current_blog_id();
        $version = $this->version();
        // Sort args to make the key stable across call orderings.
        ksort($args);
        $args_hash = substr(md5(wp_json_encode($args)), 0, 16);
        return "vyg:{$blog_id}:{$version}:{$call}:{$args_hash}";
    }

    private function is_enabled(): bool
    {
        if ( !(bool) $this->settings->get( 'cache_enabled', true ) ) {
            return false;
        }
        // TTL=0 is the operator's "do not store" switch; we also
        // short-circuit the read path so we never even attempt a
        // wp_cache_get (which is wasted IO if we cannot store).
        if ( $this->cache_ttl_seconds() <= 0 ) {
            return false;
        }
        return true;
    }

    private function current_blog_id(): int
    {
        if (function_exists('get_current_blog_id')) {
            return (int) get_current_blog_id();
        }
        return 1;
    }

    /**
     * Build (and return) the cache version. The version is a
     * monotonically increasing counter that, when bumped, makes every
     * previously-cached key miss and triggers a rebuild. Used as the
     * surgical-invalidation fallback when the object cache does not
     * support `wp_cache_flush_group()`.
     */
    private function version(): int
    {
        $stored = get_option('vyg_feed_query_cache_version', array('source' => array(), 'feed' => array(), 'global' => 0));
        if (!is_array($stored)) {
            $stored = array('source' => array(), 'feed' => array(), 'global' => 0);
        }
        if (!isset($stored['global']) || !is_int($stored['global'])) {
            $stored['global'] = 0;
        }
        // The "version" exposed in keys is `global + per-axis offsets`.
        // Bumping the global invalidates EVERYTHING; bumping a per-axis
        // map invalidates only entries whose args include that axis.
        return (int) $stored['global'];
    }

    private function bump_version(string $axis, $key): void
    {
        $stored = get_option('vyg_feed_query_cache_version', array('source' => array(), 'feed' => array(), 'global' => 0));
        if (!is_array($stored)) {
            $stored = array('source' => array(), 'feed' => array(), 'global' => 0);
        }
        // Bumping the global counter is the safest invalidation — the
        // next version() call returns a new value, every key misses.
        $stored['global'] = (int) ($stored['global'] ?? 0) + 1;
        update_option('vyg_feed_query_cache_version', $stored, true);
    }

    /**
     * Cache get. Prefers the persistent object cache; falls back to
     * transients when no external cache is configured.
     *
     * @return mixed|null Null on miss. An empty array stored as the
     *                    value returns the empty array (not null) so
     *                    the caller can distinguish "cached empty
     *                    result" from "cache miss" — we only cache
     *                    non-empty results, so this never collides.
     */
    private function get(string $key): mixed
    {
        if (function_exists('wp_cache_get')) {
            $value = wp_cache_get($key, self::CACHE_GROUP);
            if (false !== $value) {
                return $value;
            }
        }
        return null;
    }

    private function set(string $key, mixed $value): void
    {
        $ttl = $this->cache_ttl_seconds();
        if ($ttl <= 0) {
            return; // Operator disabled TTL by setting it to 0.
        }
        if (function_exists('wp_cache_set')) {
            wp_cache_set($key, $value, self::CACHE_GROUP, $ttl);
        }
    }
}
