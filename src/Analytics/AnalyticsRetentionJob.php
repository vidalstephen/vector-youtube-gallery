<?php
/**
 * Phase 11.1 — analytics retention cron handler.
 *
 * Runs daily (via the plugin's cron schedule system). Prunes events
 * older than the configured `vyg_analytics_retention_days`.
 *
 * Hard rule: if analytics is OFF, this job MUST be a complete no-op.
 *
 * @package VectorYT\Gallery\Analytics
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Analytics;

defined('ABSPATH') || exit;

final class AnalyticsRetentionJob {

    /**
     * Hook this from cron. Idempotent.
     *
     * @return array{deleted:int, ran:bool}
     */
    public function handle(): array {
        if (! EventRepository::is_enabled()) {
            return array('deleted' => 0, 'ran' => false);
        }
        $deleted = EventRepository::prune();
        return array('deleted' => $deleted, 'ran' => true);
    }
}