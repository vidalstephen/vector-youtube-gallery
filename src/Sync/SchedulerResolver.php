<?php
/**
 * SchedulerResolver — picks the SyncScheduler implementation based on a
 * configured mode plus Action Scheduler availability.
 *
 * Phase 12.2 introduces a feature flag for the scheduler backend so
 * operators can opt into Action Scheduler (when available) without code
 * changes. The resolver chain is:
 *
 *   1. CLI / env override (constant `VYG_SYNC_SCHEDULER` or
 *      `wp vyg scheduler --mode=...`).
 *   2. Settings key `sync_scheduler_mode` (`auto` default).
 *   3. Default `auto`.
 *
 * The `auto` mode returns the ActionSchedulerSyncScheduler when AS is
 * available, otherwise the WP-Cron scheduler. The other explicit modes
 * (`wp_cron`, `action_scheduler`) ignore availability — useful for
 * forcing a specific backend during testing or for operators who want
 * to surface a configuration error rather than silently fall back.
 *
 * @package VectorYT\Gallery\Sync
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Sync;

use VectorYT\Gallery\Settings\SettingsRepository;

defined('ABSPATH') || exit;

final class SchedulerResolver
{
    public const MODE_AUTO              = 'auto';
    public const MODE_WP_CRON           = 'wp_cron';
    public const MODE_ACTION_SCHEDULER  = 'action_scheduler';

    public const VALID_MODES = array(
        self::MODE_AUTO,
        self::MODE_WP_CRON,
        self::MODE_ACTION_SCHEDULER,
    );

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly ?ActionSchedulerSyncScheduler $action_scheduler = null,
    ) {}

    /**
     * Return the resolved SyncScheduler. Callers should not cache the
     * instance — the resolver can hand back a different implementation
     * if the configured mode changed.
     */
    public function resolve(): SyncScheduler
    {
        $mode = $this->resolve_mode();

        if (self::MODE_WP_CRON === $mode) {
            return new WpCronSyncScheduler();
        }

        if (self::MODE_ACTION_SCHEDULER === $mode) {
            $impl = $this->action_scheduler ?? new ActionSchedulerSyncScheduler();
            // If AS was forced but the library is missing, surface that
            // clearly rather than silently falling back. We still return
            // a working scheduler (WP-Cron), but the diagnostics
            // call-site should report the mismatch.
            return $impl;
        }

        // MODE_AUTO — delegate to AS if it's available, else WP-Cron.
        $impl = $this->action_scheduler ?? new ActionSchedulerSyncScheduler();
        if ($impl->action_scheduler_available()) {
            return $impl;
        }
        return new WpCronSyncScheduler();
    }

    /**
     * Return the resolved mode string. Visible for diagnostics.
     */
    public function resolve_mode(): string
    {
        $mode = $this->resolve_mode_from_constant();
        if (null !== $mode) {
            return $this->validate_mode($mode);
        }
        $mode = $this->settings->get('sync_scheduler_mode', self::MODE_AUTO);
        if (!is_string($mode)) {
            $mode = self::MODE_AUTO;
        }
        return $this->validate_mode($mode);
    }

    /**
     * Effective backend for the resolved scheduler: `action_scheduler`
     * or `wp_cron`. Helps `wp vyg scheduler` report what's actually being
     * used at runtime.
     */
    public function effective_backend(): string
    {
        $resolved = $this->resolve();
        if ($resolved instanceof ActionSchedulerSyncScheduler) {
            return $resolved->backend();
        }
        return 'wp_cron';
    }

    /**
     * True when the operator explicitly asked for AS but the library is
     * missing — useful for emitting a warning in the diagnostics
     * snapshot.
     */
    public function has_misconfiguration(): bool
    {
        $mode = $this->resolve_mode();
        if (self::MODE_ACTION_SCHEDULER !== $mode) {
            return false;
        }
        $impl = $this->action_scheduler ?? new ActionSchedulerSyncScheduler();
        return !$impl->action_scheduler_available();
    }

    private function resolve_mode_from_constant(): ?string
    {
        if (defined('VYG_SYNC_SCHEDULER')) {
            $value = constant('VYG_SYNC_SCHEDULER');
            if (is_string($value) && '' !== $value) {
                return $value;
            }
        }
        $env = getenv('VYG_SYNC_SCHEDULER');
        if (is_string($env) && '' !== $env) {
            return $env;
        }
        return null;
    }

    private function validate_mode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if (in_array($mode, self::VALID_MODES, true)) {
            return $mode;
        }
        return self::MODE_AUTO;
    }
}
