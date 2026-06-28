<?php
/**
 * WP-Cron-backed SyncScheduler.
 *
 * Phase 2 default. Phase 2.5 (or whenever Action Scheduler is bundled) will
 * swap this for an AS-backed implementation without changing the interface.
 *
 * @package VectorYT\Gallery\Sync
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Sync;

defined( 'ABSPATH' ) || exit;

final class WpCronSyncScheduler implements SyncScheduler {

    public function schedule_once( string $hook, array $args, ?int $when = null ): bool {
        if ( ! function_exists( 'wp_schedule_single_event' ) ) {
            return false;
        }
        $timestamp = $when ?? ( time() + MINUTE_IN_SECONDS );
        return false !== wp_schedule_single_event( $timestamp, $hook, $args );
    }

    public function schedule_recurring( string $hook, array $args, int $interval_seconds ): bool {
        if ( ! function_exists( 'wp_schedule_event' ) ) {
            return false;
        }
        // wp_schedule_event signature: timestamp, recurrence, hook, args.
        // We pass args via closure-style: wp_schedule_event accepts an args array since 5.1.
        return false !== wp_schedule_event( time() + $interval_seconds, $this->recurrence_label( $interval_seconds ), $hook, $args );
    }

    public function unschedule_recurring( string $hook, array $args ): bool {
        if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
            return false;
        }
        // wp_clear_scheduled_hook removes ALL pending runs for that hook+args combo.
        return false !== wp_clear_scheduled_hook( $hook, $args );
    }

    public function unschedule_all( string $hook, array $args_subset = array() ): int {
        // WP-Cron doesn't expose per-arg filtering; clear the entire hook.
        $crons = _get_cron_array();
        if ( ! is_array( $crons ) ) {
            return 0;
        }
        $removed = 0;
        foreach ( $crons as $timestamp => $hooks ) {
            if ( ! isset( $hooks[ $hook ] ) ) {
                continue;
            }
            foreach ( $hooks[ $hook ] as $key => $event ) {
                if ( $this->args_match_subset( (array) ( $event['args'] ?? array() ), $args_subset ) ) {
                    unset( $crons[ $timestamp ][ $hook ][ $key ] );
                    $removed++;
                    wp_unschedule_event( $timestamp, $hook, $event['args'] );
                }
            }
            if ( empty( $hooks[ $hook ] ) ) {
                unset( $crons[ $timestamp ][ $hook ] );
            }
        }
        return $removed;
    }

    /**
     * Map seconds to a WP-Cron recurrence label. WP only ships hourly, twicedaily, daily;
     * anything finer uses a custom interval registered via cron_schedules (Phase 2.5).
     */
    private function recurrence_label( int $interval_seconds ): string {
        if ( $interval_seconds <= HOUR_IN_SECONDS ) {
            return 'hourly';
        }
        if ( $interval_seconds <= 12 * HOUR_IN_SECONDS ) {
            return 'twicedaily';
        }
        return 'daily';
    }

    private function args_match_subset( array $args, array $subset ): bool {
        foreach ( $subset as $k => $v ) {
            if ( ! array_key_exists( $k, $args ) || $args[ $k ] !== $v ) {
                return false;
            }
        }
        return true;
    }
}