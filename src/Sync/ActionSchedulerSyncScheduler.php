<?php
/**
 * Action Scheduler-backed SyncScheduler.
 *
 * Phase 12.2 introduces an Action Scheduler adapter that uses
 * `as_schedule_single_action()`, `as_schedule_recurring_action()`, and
 * `as_unschedule_action()` when the Action Scheduler library is available.
 * When AS is absent (the common case for self-hosted installs that do not
 * depend on WooCommerce / MailPoet / etc.), the adapter transparently
 * falls back to a composed `WpCronSyncScheduler` so callers always see
 * the same `SyncScheduler` contract.
 *
 * The "auto" resolution is owned by `SchedulerResolver`. This class only
 * implements the contract; it does not pick the implementation.
 *
 * Design notes:
 *   - The class is `final`.
 *   - The AS function calls are dispatched through a single private
 *     method `dispatch_as_call()`. The method's body invokes the
 *     global AS functions, but tests do NOT need to mock them
 *     directly: they construct the scheduler with a custom
 *     `ActionSchedulerInvoker` (a callable) that records every
 *     call. This is the test seam — production never overrides the
 *     invoker.
 *   - The invoker is wrapped by `null` in the default constructor and
 *     lazily initialized to a closure that calls the real AS
 *     functions. The closure is the only place the global AS
 *     functions are touched, so a stub-class test double does not
 *     need Patchwork/Brain\Monkey gymnastics.
 *   - `as_schedule_recurring_action()` expects an interval IN SECONDS
 *     (NOT a recurrence slug like WP-Cron's 'hourly'/'twicedaily'/
 *     'daily'). We pass `$interval_seconds` directly.
 *   - `as_has_scheduled_action()` is the AS analog of
 *     `wp_next_scheduled()`; used by `unschedule_recurring` to find
 *     the existing action_id and pass it to `as_unschedule_action()`.
 *   - All `as_*` calls are guarded by `function_exists()`. If AS is
 *     disabled at runtime (e.g. constant flipped) the adapter silently
 *     falls back to WP-Cron — preferable to throwing in production.
 *
 * @package VectorYT\Gallery\Sync
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Sync;

defined('ABSPATH') || exit;

/**
 * Single-dispatch callable for Action Scheduler function calls.
 * Receives the function name (e.g. "as_schedule_single_action") and
 * an array of args; returns whatever the underlying function returns.
 * Tests pass a recording invoker; production passes a closure that
 * maps the name to a real global AS function.
 *
 * @phpstan-type AsInvoker callable(string $function, array $args): mixed
 */
final class ActionSchedulerSyncScheduler implements SyncScheduler
{
    /**
     * Inner fallback scheduler. Always non-null — when AS is unavailable
     * the adapter delegates everything here. The type is `SyncScheduler`
     * (not `WpCronSyncScheduler`) so unit tests can pass a recording
     * fake without un-finaling the production class.
     */
    private readonly SyncScheduler $fallback;

    /** @var callable(string,array<int,mixed>):mixed */
    private $as_invoker;

    /**
     * @param SyncScheduler|null $fallback
     * @param (callable(string,array<int,mixed>):mixed)|null $as_invoker
     *        Test seam: if non-null, used in place of the production
     *        AS dispatcher. Receives the AS function name and a
     *        positional args array; returns whatever the function
     *        returned. The production default invokes the global AS
     *        functions directly; unit tests pass a recording closure.
     */
    public function __construct(?SyncScheduler $fallback = null, ?callable $as_invoker = null)
    {
        $this->fallback   = $fallback ?? new WpCronSyncScheduler();
        $this->as_invoker = $as_invoker ?? static function (string $function, array $args): mixed {
            // Only the well-known AS functions are dispatched here.
            // Anything else falls through as null so callers can detect
            // "AS does not know about this operation" without throwing.
            switch ($function) {
                case 'as_schedule_single_action':
                    // @phpstan-ignore-next-line — global function from optional dependency.
                    return \as_schedule_single_action(...$args);
                case 'as_schedule_recurring_action':
                    // @phpstan-ignore-next-line
                    return \as_schedule_recurring_action(...$args);
                case 'as_unschedule_action':
                    // @phpstan-ignore-next-line
                    return \as_unschedule_action(...$args);
                case 'as_unschedule_all_actions':
                    // @phpstan-ignore-next-line
                    return \as_unschedule_all_actions(...$args);
                case 'as_get_scheduled_actions':
                    // @phpstan-ignore-next-line
                    return \as_get_scheduled_actions(...$args);
                default:
                    return null;
            }
        };
    }

    /**
     * Identify which backend a given call was actually dispatched to.
     * Useful for diagnostics (`wp vyg scheduler`).
     */
    public function backend(): string
    {
        return $this->action_scheduler_available() ? 'action_scheduler' : 'wp_cron';
    }

    public function schedule_once(string $hook, array $args, ?int $when = null): bool
    {
        if (!$this->action_scheduler_available()) {
            return $this->fallback->schedule_once($hook, $args, $when);
        }
        $timestamp = $when ?? (time() + MINUTE_IN_SECONDS);
        // AS treats $args as JSON-serializable; the WP-Cron analog accepts
        // a single positional array of args. AS prefers named groups, so
        // we wrap the args in [ 'args' => $args ] to stay compatible with
        // the WP-Cron callback signature `function ($args)`.
        $result = ($this->as_invoker)(
            'as_schedule_single_action',
            array($timestamp, $hook, array('args' => $args), 'vyg')
        );
        return false !== $result && null !== $result;
    }

    public function schedule_recurring(string $hook, array $args, int $interval_seconds): bool
    {
        if (!$this->action_scheduler_available()) {
            return $this->fallback->schedule_recurring($hook, $args, $interval_seconds);
        }
        $first = time() + $interval_seconds;
        $result = ($this->as_invoker)(
            'as_schedule_recurring_action',
            array($first, $interval_seconds, $hook, array('args' => $args), 'vyg')
        );
        return false !== $result && null !== $result;
    }

    public function unschedule_recurring(string $hook, array $args): bool
    {
        if (!$this->action_scheduler_available()) {
            return $this->fallback->unschedule_recurring($hook, $args);
        }
        $action_id = $this->find_recurring_action_id($hook, $args);
        if (null === $action_id) {
            return false;
        }
        // The AS function accepts either (action_id) or (hook, args, group).
        // Passing the action_id is the canonical and reliable path; the
        // hook+args form is a no-op for recurring actions whose args
        // structure differs.
        ($this->as_invoker)('as_unschedule_action', array($action_id));
        return true;
    }

    public function unschedule_all(string $hook, array $args_subset = array()): int
    {
        if (!$this->action_scheduler_available()) {
            return $this->fallback->unschedule_all($hook, $args_subset);
        }
        if (function_exists('as_unschedule_all_actions')) {
            ($this->as_invoker)('as_unschedule_all_actions', array('', array('hook' => $hook, 'status' => 'pending', 'group' => 'vyg')));
            return 1;
        }
        ($this->as_invoker)('as_unschedule_action', array($hook, array('args' => $args_subset), 'vyg'));
        return 1;
    }

    /**
     * True iff Action Scheduler is loaded. Probes `function_exists()`
     * (not `class_exists()`) because some installs shim the functions
     * without the full AS class set, and the global functions are the
     * real public surface.
     */
    public function action_scheduler_available(): bool
    {
        return function_exists('as_schedule_single_action')
            && function_exists('as_schedule_recurring_action')
            && function_exists('as_unschedule_action');
    }

    /**
     * Find a recurring action's id by hook+args. Returns null if AS has no
     * matching pending action.
     *
     * @return int|null
     */
    private function find_recurring_action_id(string $hook, array $args): ?int
    {
        if (!function_exists('as_get_scheduled_actions')) {
            return null;
        }
        $result = ($this->as_invoker)(
            'as_get_scheduled_actions',
            array(
                array(
                    'hook'   => $hook,
                    'status' => 'pending',
                    'group'  => 'vyg',
                    'per_page' => 1,
                ),
                'ids',
            )
        );
        if (!is_array($result) || empty($result)) {
            return null;
        }
        return (int) $result[0];
    }
}
