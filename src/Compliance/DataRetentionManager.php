<?php
/**
 * DataRetentionManager — applies retention policies from settings.
 *
 * Settings (from SettingsRepository::DEFAULTS):
 *  - video_metadata_retention_days  (default 90): videos not refreshed in N days
 *                                             are marked availability=available=false
 *                                             with reason "expired_retention"
 *  - deleted_video_retention_days  (default 30): videos with availability_status IN
 *                                             ('deleted','private','embed_disabled')
 *                                             for N days are HARD-DELETED
 *  - sync_log_retention_days       (default 30): sync_logs older than N days are
 *                                             hard-deleted (vyg_sync_logs only)
 *  - previous_streams_retention    (default 50): max rows per source (already in
 *                                             PreviousStreamsRepository::prune_to_limit)
 *  - replay_retention_days         (default 14): previous_streams with ended_at
 *                                             older than N days are hard-deleted
 *
 * The manager is run by the vyg_cron_data_retention WP-Cron event (registered
 * by Plugin::register_hooks). It can also be invoked manually from
 * PrivacyPage for an immediate sweep.
 *
 * All deletes are HARD (true SQL DELETE) — there is no soft-delete in this plugin.
 * Source rows are NEVER touched by retention.
 *
 * @package VectorYT\Gallery\Compliance
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Compliance;

use VectorYT\Gallery\Settings\SettingsRepository;
use VectorYT\Gallery\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class DataRetentionManager {

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly Logger $logger,
    ) {}

    /**
     * Run a full retention sweep.
     *
     * @return array<string,int> Map of operation → count affected.
     */
    public function run_sweep(): array {
        global $wpdb;

        $now = current_time( 'mysql', true ); // GMT
        $stats = array(
            'videos_marked_expired' => 0,
            'videos_hard_deleted'   => 0,
            'sync_logs_deleted'     => 0,
            'previous_streams_deleted' => 0,
        );

        $video_retention_days = (int) $this->settings->get( 'video_metadata_retention_days', 90 );
        $deleted_retention_days = (int) $this->settings->get( 'deleted_video_retention_days', 30 );
        $log_retention_days = (int) $this->settings->get( 'sync_log_retention_days', 30 );
        $replay_retention_days = (int) $this->settings->get( 'live_replay_retention_days', 14 );

        // 1. Mark videos expired if not refreshed within video_retention_days.
        // Uses last_success_at (when the metadata was last fetched from YouTube).
        $expired_threshold = gmdate( 'Y-m-d H:i:s', time() - ( $video_retention_days * DAY_IN_SECONDS ) );
        $stats['videos_marked_expired'] = (int) $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}vyg_videos
             SET availability_status='unavailable', updated_at=%s
             WHERE availability_status='available'
               AND last_success_at IS NOT NULL
               AND last_success_at < %s",
            $now,
            $expired_threshold
        ) );

        // 2. Hard-delete videos whose unavailable status has persisted >deleted_retention_days.
        $del_threshold = gmdate( 'Y-m-d H:i:s', time() - ( $deleted_retention_days * DAY_IN_SECONDS ) );
        $stats['videos_hard_deleted'] = (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}vyg_videos
             WHERE availability_status IN ('deleted','private','embed_disabled','unavailable')
               AND updated_at IS NOT NULL
               AND updated_at < %s",
            $del_threshold
        ) );

        // 3. Delete old sync_logs.
        $log_threshold = gmdate( 'Y-m-d H:i:s', time() - ( $log_retention_days * DAY_IN_SECONDS ) );
        $stats['sync_logs_deleted'] = (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}vyg_sync_logs WHERE created_at < %s",
            $log_threshold
        ) );

        // 4. Delete old previous_streams beyond replay_retention_days.
        $replay_threshold = gmdate( 'Y-m-d H:i:s', time() - ( $replay_retention_days * DAY_IN_SECONDS ) );
        $stats['previous_streams_deleted'] = (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}vyg_previous_streams WHERE ended_at IS NOT NULL AND ended_at < %s",
            $replay_threshold
        ) );

        $this->logger->info( 'DataRetentionManager: sweep complete', array( 'stats' => $stats ) );
        return $stats;
    }

    /**
     * Count rows that would be affected by the next sweep, for the PrivacyPage preview.
     *
     * @return array<string,int>
     */
    public function preview(): array {
        global $wpdb;
        $video_retention_days = (int) $this->settings->get( 'video_metadata_retention_days', 90 );
        $deleted_retention_days = (int) $this->settings->get( 'deleted_video_retention_days', 30 );
        $log_retention_days = (int) $this->settings->get( 'sync_log_retention_days', 30 );
        $replay_retention_days = (int) $this->settings->get( 'live_replay_retention_days', 14 );

        $expired_threshold = gmdate( 'Y-m-d H:i:s', time() - ( $video_retention_days * DAY_IN_SECONDS ) );
        $del_threshold     = gmdate( 'Y-m-d H:i:s', time() - ( $deleted_retention_days * DAY_IN_SECONDS ) );
        $log_threshold     = gmdate( 'Y-m-d H:i:s', time() - ( $log_retention_days * DAY_IN_SECONDS ) );
        $replay_threshold  = gmdate( 'Y-m-d H:i:s', time() - ( $replay_retention_days * DAY_IN_SECONDS ) );

        return array(
            'videos_marked_expired'    => (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}vyg_videos
                 WHERE availability_status='available'
                   AND last_success_at IS NOT NULL
                   AND last_success_at < %s",
                $expired_threshold
            ) ),
            'videos_hard_deleted'      => (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}vyg_videos
                 WHERE availability_status IN ('deleted','private','embed_disabled','unavailable')
                   AND updated_at IS NOT NULL
                   AND updated_at < %s",
                $del_threshold
            ) ),
            'sync_logs_deleted'        => (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}vyg_sync_logs WHERE created_at < %s",
                $log_threshold
            ) ),
            'previous_streams_deleted' => (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}vyg_previous_streams WHERE ended_at IS NOT NULL AND ended_at < %s",
                $replay_threshold
            ) ),
        );
    }
}