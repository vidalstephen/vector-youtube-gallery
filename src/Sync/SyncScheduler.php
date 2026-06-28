<?php
/**
 * Sync scheduler abstraction.
 *
 * Implementations:
 *   - WpCronSyncScheduler (default) — uses WP-Cron + Action Scheduler hook names.
 *     Falls back to plain WP-Cron events; will be enhanced when the bundled
 *     Action Scheduler library lands (Phase 2.5).
 *
 * The scheduler only knows about *scheduling* — job execution is owned by
 * SyncJobRunner.
 *
 * @package VectorYT\Gallery\Sync
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Sync;

defined( 'ABSPATH' ) || exit;

interface SyncScheduler {

    /**
     * Queue a one-shot job for the next available worker slot.
     *
     * @param string $hook  The action hook the worker will register.
     * @param array<string,mixed> $args  Args passed to do_action().
     * @param int|null $when Unix timestamp; null = ASAP.
     * @return bool True if scheduled.
     */
    public function schedule_once( string $hook, array $args, ?int $when = null ): bool;

    /**
     * Schedule a recurring event at the given interval.
     */
    public function schedule_recurring( string $hook, array $args, int $interval_seconds ): bool;

    /**
     * Cancel a recurring event by hook+args+timestamp signature.
     */
    public function unschedule_recurring( string $hook, array $args ): bool;

    /**
     * Cancel all pending one-shots for a hook (matching $args subset).
     */
    public function unschedule_all( string $hook, array $args_subset = array() ): int;
}