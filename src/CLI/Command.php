<?php
/**
 * WP-CLI command suite for operator automation.
 *
 * @package VectorYT\Gallery\CLI
 */

declare(strict_types=1);

namespace VectorYT\Gallery\CLI;

use VectorYT\Gallery\Admin\ImporterExporter;
use VectorYT\Gallery\Analytics\AnalyticsRetentionJob;
use VectorYT\Gallery\Compliance\DataRetentionManager;
use VectorYT\Gallery\Container;
use VectorYT\Gallery\Repository\FeedRepository;
use VectorYT\Gallery\Repository\SourceRepository;
use VectorYT\Gallery\Repository\SyncLogRepository;
use VectorYT\Gallery\Sync\SchedulerResolver;
use VectorYT\Gallery\Sync\SyncJobRunner;
use VectorYT\Gallery\Sync\SyncScheduler;

use function absint;
use function do_action;
use function file_get_contents;
use function file_put_contents;
use function gmdate;
use function is_array;
use function is_readable;
use function json_encode;
use function sanitize_key;
use function sprintf;
use function strlen;
use function wp_json_encode;

/**
 * Root `wp vyg` command.
 */
final class Command {

    public function __construct( private readonly Container $container ) {}

    /**
     * Print a diagnostics snapshot with counts, recent jobs, cron status, and runtime metadata.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format: table or json. Default: table.
     *
     * ## EXAMPLES
     *
     *     wp vyg diagnostics --format=json
     *
     * @param array<int,string> $args
     * @param array<string,mixed> $assoc_args
     */
    public function diagnostics( array $args, array $assoc_args ): void {
        global $wpdb;
        $snapshot = array(
            'generated_at' => gmdate( 'c' ),
            'plugin_version' => defined( 'VYG_VERSION' ) ? VYG_VERSION : 'unknown',
            'db_version' => defined( 'VYG_DB_VERSION' ) ? VYG_DB_VERSION : 'unknown',
            'site_url' => function_exists( 'home_url' ) ? home_url() : '',
            'scheduler' => $this->scheduler_snapshot(),
            'cache' => $this->cache_snapshot(),
            'counts' => array(
                'sources' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}vyg_sources" ),
                'feeds' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}vyg_feeds" ),
                'videos' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}vyg_videos" ),
                'sync_jobs' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}vyg_sync_jobs" ),
                'sync_logs' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}vyg_sync_logs" ),
                'api_quota_rows' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}vyg_api_quota_log" ),
                'analytics_events' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}vyg_events" ),
            ),
            'cron' => $this->cron_snapshot(),
            'recent_jobs' => $this->recent_jobs( 5 ),
        );

        if ( 'json' === ( $assoc_args['format'] ?? '' ) ) {
            \WP_CLI::line( wp_json_encode( $snapshot, JSON_PRETTY_PRINT ) );
            return;
        }

        $rows = array();
        foreach ( $snapshot['counts'] as $key => $value ) {
            $rows[] = array( 'metric' => $key, 'value' => $value );
        }
        $this->format_items( 'table', $rows, array( 'metric', 'value' ) );
        \WP_CLI::line( 'Scheduler:' );
        $this->format_items( 'table', $snapshot['scheduler'], array( 'metric', 'value' ) );
        \WP_CLI::line( 'Cache:' );
        $this->format_items( 'table', $snapshot['cache'], array( 'metric', 'value' ) );
        \WP_CLI::line( 'Cron:' );
        $this->format_items( 'table', $snapshot['cron'], array( 'hook', 'next_run' ) );
    }

    /**
     * Show the configured sync scheduler backend and its actual runtime
     * mode. Useful for verifying Phase 12.2 routing without poking the
     * container by hand.
     *
     * ## EXAMPLES
     *
     *     wp vyg scheduler
     *
     * @param array<int,string> $args
     * @param array<string,mixed> $assoc_args
     */
    public function scheduler( array $args, array $assoc_args ): void {
        $rows = $this->scheduler_snapshot();

        if ( 'json' === ( $assoc_args['format'] ?? '' ) ) {
            $payload = array();
            foreach ( $rows as $row ) {
                $payload[ $row['metric'] ] = $row['value'];
            }
            \WP_CLI::line( wp_json_encode( $payload, JSON_PRETTY_PRINT ) );
            return;
        }

        $this->format_items( 'table', $rows, array( 'metric', 'value' ) );

        /** @var SchedulerResolver $resolver */
        $resolver = $this->container->get( 'sync.scheduler.resolver' );
        if ( $resolver->has_misconfiguration() ) {
            \WP_CLI::warning( 'sync_scheduler_mode is "action_scheduler" but the Action Scheduler library is not loaded; jobs will fall back to WP-Cron.' );
        }
    }

    /**
     * Show the feed-query cache snapshot.
     *
     * ## EXAMPLES
     *
     *     wp vyg cache
     *     wp vyg cache --format=json
     *
     * @param array<int,string> $args
     * @param array<string,mixed> $assoc_args
     */
    public function cache( array $args, array $assoc_args ): void {
        $rows = $this->cache_snapshot();

        if ( 'json' === ( $assoc_args['format'] ?? '' ) ) {
            $payload = array();
            foreach ( $rows as $row ) {
                $payload[ $row['metric'] ] = $row['value'];
            }
            \WP_CLI::line( wp_json_encode( $payload, JSON_PRETTY_PRINT ) );
            return;
        }

        $this->format_items( 'table', $rows, array( 'metric', 'value' ) );
    }

    /**
     * Drop every cached feed-query entry. Use after a settings import,
     * an operator-driven manual sync, or a suspected data-drift
     * incident.
     *
     * @subcommand cache-flush
     *
     * @param array<int,string> $args
     * @param array<string,mixed> $assoc_args
     */
    public function cache_flush( array $args, array $assoc_args ): void {
        $cache = $this->container->get( 'render.feed' );
        if ( ! method_exists( $cache, 'invalidate_all' ) ) {
            \WP_CLI::warning( 'render.feed does not support cache invalidation (older feed layer).' );
            return;
        }
        $cache->invalidate_all();
        \WP_CLI::success( 'Dropped every feed-query cache entry.' );
    }

    /**
     * Phase 12.4: print a per-site diagnostic summary across the
     * multisite network. On a single-site install returns the same
     * shape as `wp vyg diagnostics` for the current site.
     *
     * ## EXAMPLES
     *
     *     wp vyg network-diagnostics
     *     wp vyg network-diagnostics --format=json
     *
     * @subcommand network-diagnostics
     *
     * @param array<int,string> $args
     * @param array<string,mixed> $assoc_args
     */
    public function network_diagnostics( array $args, array $assoc_args ): void {
        $rows = \VectorYT\Gallery\Multisite\NetworkPolicy::network_diagnostics();
        if ( 'json' === ( $assoc_args['format'] ?? '' ) ) {
            \WP_CLI::line( wp_json_encode( $rows, JSON_PRETTY_PRINT ) );
            return;
        }
        $flat = array();
        foreach ( $rows as $row ) {
            $counts = (array) ( $row['counts'] ?? array() );
            $flat[] = array(
                'site_id'    => (int) ( $row['site_id'] ?? 0 ),
                'site_url'   => (string) ( $row['site_url'] ?? '' ),
                'vyg_active' => ( $row['vyg_active'] ?? false ) ? 'yes' : 'no',
                'sources'    => (int) ( $counts['sources'] ?? 0 ),
                'feeds'      => (int) ( $counts['feeds'] ?? 0 ),
                'videos'     => (int) ( $counts['videos'] ?? 0 ),
                'jobs'       => (int) ( $counts['jobs'] ?? 0 ),
            );
        }
        if ( empty( $flat ) ) {
            \WP_CLI::line( 'No sites reported diagnostics.' );
            return;
        }
        $this->format_items( 'table', $flat, array( 'site_id', 'site_url', 'vyg_active', 'sources', 'feeds', 'videos', 'jobs' ) );
    }

    /**
     * Phase 12.4: drop every VYG table + option + cron event for the
     * current site (or for the site id passed as the first positional
     * arg). Refuses to run unless `--yes` is supplied so an operator
     * cannot accidentally nuke a site's data.
     *
     * ## OPTIONS
     *
     * [--site-id=<id>]
     * : Optional site id to clean up. Defaults to the current site.
     *
     * [--yes]
     * : Skip the safety confirmation.
     *
     * ## EXAMPLES
     *
     *     wp vyg site-cleanup
     *     wp vyg site-cleanup --site-id=2 --yes
     *
     * @subcommand site-cleanup
     *
     * @param array<int,string> $args
     * @param array<string,mixed> $assoc_args
     */
    public function site_cleanup( array $args, array $assoc_args ): void {
        $site_id = isset( $assoc_args['site-id'] ) ? (int) $assoc_args['site-id'] : 0;
        $confirm = isset( $assoc_args['yes'] );

        if ( ! $confirm ) {
            \WP_CLI::error( 'Refusing to run without --yes; this is a destructive operation.' );
            return;
        }

        $original = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
        if ( $site_id > 0 && function_exists( 'switch_to_blog' ) ) {
            switch_to_blog( $site_id );
        }
        try {
            $dropped = \VectorYT\Gallery\Multisite\NetworkPolicy::site_uninstall();
            \WP_CLI::success( sprintf( 'Dropped %d table(s) and cleared VYG options + cron for the site.', $dropped ) );
        } finally {
            if ( $site_id > 0 && $original && function_exists( 'restore_current_blog' ) ) {
                restore_current_blog();
            }
        }
    }

    /**
     * Phase 12.5: show the log rotation snapshot, the active log
     * level, and the segments directory state.
     *
     * ## EXAMPLES
     *
     *     wp vyg log
     *     wp vyg log --format=json
     *
     * @subcommand log
     *
     * @param array<int,string> $args
     * @param array<string,mixed> $assoc_args
     */
    public function log( array $args, array $assoc_args ): void {
        $rotator  = $this->container->get( 'log.rotator' );
        $settings = $this->container->get( 'settings' );

        $rows = array(
            array( 'metric' => 'log_level', 'value' => (string) $settings->get( 'log_level', 'info' ) ),
            array( 'metric' => 'log_max_size_mb', 'value' => (string) $rotator->max_size_bytes() / 1024 / 1024 ),
            array( 'metric' => 'log_max_files', 'value' => (string) $rotator->max_files() ),
            array( 'metric' => 'log_file', 'value' => $rotator->log_file_path() ),
            array( 'metric' => 'segments_dir', 'value' => $rotator->segments_dir() ),
            array( 'metric' => 'segments_count', 'value' => (string) count( $rotator->segments() ) ),
        );
        if ( 'json' === ( $assoc_args['format'] ?? '' ) ) {
            $payload = array();
            foreach ( $rows as $row ) {
                $payload[ $row['metric'] ] = $row['value'];
            }
            \WP_CLI::line( wp_json_encode( $payload, JSON_PRETTY_PRINT ) );
            return;
        }
        $this->format_items( 'table', $rows, array( 'metric', 'value' ) );
    }

    /**
     * Phase 12.5: force a log rotation pass, regardless of file
     * size. The rotator is a no-op when the file is below the
     * configured threshold.
     *
     * @subcommand log-rotate
     *
     * @param array<int,string> $args
     * @param array<string,mixed> $assoc_args
     */
    public function log_rotate( array $args, array $assoc_args ): void {
        $rotator = $this->container->get( 'log.rotator' );
        $rotated = $rotator->rotate();
        if ( $rotated > 0 ) {
            \WP_CLI::success( 'Rotated log file.' );
        } else {
            \WP_CLI::line( 'No rotation needed (file below threshold).' );
        }
    }

    /**
     * List sync jobs.
     *
     * ## OPTIONS
     *
     * [--status=<status>]
     * : Filter by status.
     *
     * [--limit=<n>]
     * : Max rows. Default: 20.
     *
     * [--format=<format>]
     * : table or json. Default: table.
     *
     * @param array<int,string> $args
     * @param array<string,mixed> $assoc_args
     */
    public function jobs( array $args, array $assoc_args ): void {
        global $wpdb;
        $limit = max( 1, min( 200, absint( $assoc_args['limit'] ?? 20 ) ) );
        $status = isset( $assoc_args['status'] ) ? sanitize_key( (string) $assoc_args['status'] ) : '';
        $params = array();
        $where = '1=1';
        if ( '' !== $status ) {
            $where .= ' AND status = %s';
            $params[] = $status;
        }
        $params[] = $limit;
        $sql = "SELECT id, job_uuid, job_type, source_id, status, attempts, started_at, completed_at, next_attempt_at, error_code FROM {$wpdb->prefix}vyg_sync_jobs WHERE {$where} ORDER BY id DESC LIMIT %d";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
        $rows = is_array( $rows ) ? $rows : array();
        $format = (string) ( $assoc_args['format'] ?? 'table' );
        $this->format_items( $format, $rows, array( 'id', 'job_type', 'source_id', 'status', 'attempts', 'started_at', 'completed_at', 'next_attempt_at', 'error_code' ) );
    }

    /**
     * Run retention jobs.
     *
     * ## OPTIONS
     *
     * [--analytics]
     * : Run analytics-event retention.
     *
     * [--data]
     * : Run data/log/video retention.
     *
     * [--format=<format>]
     * : table or json. Default: table.
     *
     * @param array<int,string> $args
     * @param array<string,mixed> $assoc_args
     */
    public function retention( array $args, array $assoc_args ): void {
        $run_analytics = isset( $assoc_args['analytics'] );
        $run_data = isset( $assoc_args['data'] );
        if ( ! $run_analytics && ! $run_data ) {
            $run_analytics = true;
            $run_data = true;
        }
        $result = array();
        if ( $run_data ) {
            /** @var DataRetentionManager $manager */
            $manager = $this->container->get( 'compliance.retention' );
            $result['data'] = $manager->run_sweep();
        }
        if ( $run_analytics ) {
            /** @var AnalyticsRetentionJob $job */
            $job = $this->container->get( 'analytics.retention' );
            $result['analytics'] = $job->handle();
        }
        $this->output_result( $result, (string) ( $assoc_args['format'] ?? 'table' ) );
    }

    /**
     * Run or enqueue sync work.
     *
     * ## OPTIONS
     *
     * <type>
     * : initial, incremental, metadata-refresh, live-poll, or cron-incremental-all.
     *
     * [--source-id=<id>]
     * : Source id for initial/incremental sync.
     *
     * [--job-id=<id>]
     * : Existing job id to run/retry.
     *
     * [--enqueue]
     * : Queue via WP-Cron instead of running immediately.
     *
     * @param array<int,string> $args
     * @param array<string,mixed> $assoc_args
     */
    public function sync( array $args, array $assoc_args ): void {
        $type = sanitize_key( (string) ( $args[0] ?? '' ) );
        if ( '' === $type ) {
            \WP_CLI::error( 'Usage: wp vyg sync <initial|incremental|metadata-refresh|live-poll|cron-incremental-all> [--source-id=<id>] [--job-id=<id>] [--enqueue]' );
        }
        if ( 'cron-incremental-all' === $type ) {
            do_action( 'vyg_cron_incremental_all' );
            \WP_CLI::success( 'Triggered vyg_cron_incremental_all.' );
            return;
        }
        if ( 'live-poll' === $type ) {
            do_action( 'vyg_cron_live_poll' );
            \WP_CLI::success( 'Triggered vyg_cron_live_poll.' );
            return;
        }

        $job_type = str_replace( '-', '_', $type );
        $job_id = isset( $assoc_args['job-id'] ) ? absint( $assoc_args['job-id'] ) : 0;
        $source_id = isset( $assoc_args['source-id'] ) ? absint( $assoc_args['source-id'] ) : null;
        if ( $job_id <= 0 ) {
            if ( in_array( $job_type, array( 'initial', 'initial_import', 'incremental' ), true ) && ( null === $source_id || $source_id <= 0 ) ) {
                \WP_CLI::error( '--source-id is required for this sync type.' );
            }
            /** @var SyncLogRepository $logs */
            $logs = $this->container->get( 'repo.logs' );
            $job_id = $logs->create_job( $job_type, $source_id );
        }

        $hook = match ( $job_type ) {
            'initial', 'initial_import' => 'vyg_sync_source_initial',
            'incremental' => 'vyg_sync_source_incremental',
            'metadata_refresh' => 'vyg_refresh_video_batch',
            default => '',
        };
        if ( '' === $hook ) {
            \WP_CLI::error( 'Unknown sync type: ' . $type );
        }
        $payload = array( 'vyg_job_id' => $job_id );
        if ( null !== $source_id && $source_id > 0 ) {
            $payload['source_id'] = $source_id;
        }
        if ( isset( $assoc_args['enqueue'] ) ) {
            // Phase 12.2: route through the configured SyncScheduler so
            // CLI `wp vyg sync ... --enqueue` honors the operator's
            // scheduler preference.
            $scheduler = $this->container->get( 'sync.scheduler' );
            $scheduler->schedule_once( $hook, $payload, time() + 1 );
            \WP_CLI::success( sprintf( 'Queued %s job #%d on %s.', $job_type, $job_id, $hook ) );
            return;
        }

        $runner = $this->runner_for_job_type( $job_type );
        $runner->run_with_lifecycle( $job_id, $payload );
        \WP_CLI::success( sprintf( 'Ran %s job #%d.', $job_type, $job_id ) );
    }

    /**
     * Retry a failed job immediately.
     *
     * ## OPTIONS
     *
     * <job-id>
     * : Sync job id.
     *
     * @param array<int,string> $args
     * @param array<string,mixed> $assoc_args
     */
    public function retry( array $args, array $assoc_args ): void {
        $job_id = absint( $args[0] ?? 0 );
        if ( $job_id <= 0 ) {
            \WP_CLI::error( 'Job id is required.' );
        }
        /** @var SyncLogRepository $logs */
        $logs = $this->container->get( 'repo.logs' );
        $job = $logs->find_job( $job_id );
        if ( ! is_array( $job ) ) {
            \WP_CLI::error( 'Job not found.' );
        }
        $runner = $this->runner_for_job_type( (string) $job['job_type'] );
        $payload = array( 'vyg_job_id' => $job_id );
        if ( ! empty( $job['source_id'] ) ) {
            $payload['source_id'] = (int) $job['source_id'];
        }
        $runner->run_with_lifecycle( $job_id, $payload );
        \WP_CLI::success( sprintf( 'Retried job #%d.', $job_id ) );
    }

    /**
     * Export feeds to stdout or a file.
     *
     * @subcommand export-feeds
     *
     * ## OPTIONS
     *
     * [--file=<path>]
     * : Write JSON to a file instead of stdout.
     *
     * @param array<int,string> $args
     * @param array<string,mixed> $assoc_args
     */
    public function export_feeds( array $args, array $assoc_args ): void {
        /** @var FeedRepository $feeds */
        $feeds = $this->container->get( 'repo.feeds' );
        /** @var ImporterExporter $exporter */
        $exporter = $this->container->get( 'admin.importer_exporter' );
        $json = $exporter->export_feeds( $feeds->list() );
        if ( isset( $assoc_args['file'] ) ) {
            $file = (string) $assoc_args['file'];
            file_put_contents( $file, $json );
            \WP_CLI::success( sprintf( 'Exported feeds to %s (%d bytes).', $file, strlen( $json ) ) );
            return;
        }
        \WP_CLI::line( $json );
    }

    /**
     * Import feeds from a JSON file.
     *
     * @subcommand import-feeds
     *
     * ## OPTIONS
     *
     * <file>
     * : JSON file path.
     *
     * [--conflict=<mode>]
     * : replace, duplicate, or skip. Default: skip.
     *
     * [--force]
     * : Accept newer export versions.
     *
     * @param array<int,string> $args
     * @param array<string,mixed> $assoc_args
     */
    public function import_feeds( array $args, array $assoc_args ): void {
        $file = (string) ( $args[0] ?? '' );
        if ( '' === $file || ! is_readable( $file ) ) {
            \WP_CLI::error( 'Readable JSON file path is required.' );
        }
        /** @var ImporterExporter $importer */
        $importer = $this->container->get( 'admin.importer_exporter' );
        $result = $importer->import_feeds( (string) file_get_contents( $file ), array(
            'conflict' => (string) ( $assoc_args['conflict'] ?? ImporterExporter::CONFLICT_SKIP ),
            'force' => isset( $assoc_args['force'] ),
        ) );
        if ( ! (bool) ( $result['ok'] ?? false ) ) {
            \WP_CLI::error( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
        }
        \WP_CLI::success( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
    }

    private function runner_for_job_type( string $job_type ): SyncJobRunner {
        $service = match ( $job_type ) {
            'initial', 'initial_import' => 'sync.initial',
            'incremental' => 'sync.incremental',
            'metadata_refresh' => 'sync.refresh',
            default => '',
        };
        if ( '' === $service ) {
            \WP_CLI::error( 'Unsupported job type for direct retry: ' . $job_type );
        }
        /** @var SyncJobRunner $runner */
        $runner = $this->container->get( $service );
        return $runner;
    }

    /** @return array<int,array<string,string>> */
    private function cron_snapshot(): array {
        $hooks = array( 'vyg_cron_incremental_all', 'vyg_cron_metadata_refresh', 'vyg_cron_live_poll', 'vyg_cron_data_retention' );
        $rows = array();
        foreach ( $hooks as $hook ) {
            $next = wp_next_scheduled( $hook );
            $rows[] = array(
                'hook' => $hook,
                'next_run' => $next ? gmdate( 'c', (int) $next ) : 'not scheduled',
            );
        }
        return $rows;
    }

    /**
     * Phase 12.2: snapshot the active scheduler for `wp vyg diagnostics`
     * and `wp vyg scheduler`. Includes the configured mode, the actual
     * backend in use, the AS-availability flag, and a
     * misconfiguration warning so operators don't silently fall back
     * to WP-Cron when they thought they had AS enabled.
     *
     * @return array<int,array<string,string>>
     */
    private function scheduler_snapshot(): array {
        /** @var SchedulerResolver $resolver */
        $resolver = $this->container->get( 'sync.scheduler.resolver' );

        $as_loaded = (bool) (
            function_exists( 'as_schedule_single_action' )
            && function_exists( 'as_schedule_recurring_action' )
            && function_exists( 'as_unschedule_action' )
        );

        return array(
            array(
                'metric' => 'configured_mode',
                'value'  => $resolver->resolve_mode(),
            ),
            array(
                'metric' => 'effective_backend',
                'value'  => $resolver->effective_backend(),
            ),
            array(
                'metric' => 'action_scheduler_loaded',
                'value'  => $as_loaded ? 'yes' : 'no',
            ),
            array(
                'metric' => 'misconfiguration',
                'value'  => $resolver->has_misconfiguration() ? 'yes (forced AS without library)' : 'no',
            ),
            array(
                'metric' => 'scheduler_class',
                'value'  => get_class( $resolver->resolve() ),
            ),
        );
    }

    /**
     * Phase 12.3: snapshot the feed-query cache for `wp vyg diagnostics`
     * and `wp vyg cache`. Reports whether the cache is enabled, the
     * current TTL, and the persistent-object-cache availability
     * (so operators can tell whether wp_cache_* is backed by Redis /
     * Memcache / transients).
     *
     * @return array<int,array<string,string>>
     */
    private function cache_snapshot(): array {
        $settings = $this->container->get( 'settings' );
        $enabled  = (bool) $settings->get( 'cache_enabled', true );
        $ttl      = (int) $settings->get( 'cache_ttl_seconds', 3600 );

        // wp_cache_flush_group exists on most persistent object-cache
        // backends (Redis, Memcache). When it is missing, WP falls back
        // to the built-in transients-backed option cache, which does
        // not support group-flush; in that mode the FeedQueryCache's
        // bump_version() path is what actually invalidates entries.
        $persistent_cache = function_exists( 'wp_cache_flush_group' );

        return array(
            array(
                'metric' => 'cache_enabled',
                'value'  => $enabled ? 'yes' : 'no',
            ),
            array(
                'metric' => 'cache_ttl_seconds',
                'value'  => (string) $ttl,
            ),
            array(
                'metric' => 'persistent_object_cache',
                'value'  => $persistent_cache ? 'yes' : 'no',
            ),
            array(
                'metric' => 'cache_class',
                'value'  => get_class( $this->container->get( 'render.feed' ) ),
            ),
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function recent_jobs( int $limit ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, job_type, source_id, status, attempts, started_at, completed_at FROM {$wpdb->prefix}vyg_sync_jobs ORDER BY id DESC LIMIT %d",
            $limit
        ), ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    /** @param array<string,mixed> $result */
    private function output_result( array $result, string $format ): void {
        if ( 'json' === $format ) {
            \WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
            return;
        }
        $rows = array();
        foreach ( $result as $section => $values ) {
            if ( is_array( $values ) ) {
                foreach ( $values as $key => $value ) {
                    $rows[] = array( 'section' => $section, 'metric' => (string) $key, 'value' => is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value );
                }
            }
        }
        $this->format_items( 'table', $rows, array( 'section', 'metric', 'value' ) );
    }

    /** @param array<int,array<string,mixed>> $items @param array<int,string> $fields */
    private function format_items( string $format, array $items, array $fields ): void {
        if ( function_exists( '\\WP_CLI\\Utils\\format_items' ) ) {
            \WP_CLI\Utils\format_items( $format, $items, $fields );
            return;
        }
        if ( 'json' === $format ) {
            \WP_CLI::line( wp_json_encode( $items, JSON_PRETTY_PRINT ) );
            return;
        }
        foreach ( $items as $item ) {
            \WP_CLI::line( json_encode( $item ) );
        }
    }
}
