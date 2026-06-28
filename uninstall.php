<?php
/**
 * Uninstall handler — runs ONLY when the user deletes the plugin from WP admin
 * (not on simple deactivation).
 *
 * By design, this is the ONLY code path that deletes plugin-owned data.
 * Deactivation keeps data intact (per plan §21).
 *
 * Phase 6: full implementation.
 *   - Drop all vyg_* tables
 *   - Delete all vyg_* options + transients
 *   - Clear all vyg_cron_* scheduled hooks
 *
 * @package VectorYT\Gallery
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$prefix = $wpdb->prefix;

// Phase 6: clean_uninstall toggle. When OFF (default), uninstall is a no-op for
// tables/options so a reinstall restores everything. When ON, drop everything.
$clean_uninstall = (string) get_option( 'vyg_clean_uninstall', '0' );
if ( '1' !== $clean_uninstall ) {
    // Mark uninstall as observed but keep all data intact.
    if ( ! get_option( 'vyg_uninstalled_at' ) ) {
        add_option( 'vyg_uninstalled_at', gmdate( 'c' ), '', 'no' );
    }
    return;
}

// 1. Drop all vyg_* tables.
$vyg_tables = array(
    'vyg_sources',
    'vyg_videos',
    'vyg_playlists',
    'vyg_playlist_video_map',
    'vyg_feeds',
    'vyg_feed_video_overrides',
    'vyg_sync_jobs',
    'vyg_sync_logs',
    'vyg_api_quota_log',
    'vyg_previous_streams',
);
foreach ( $vyg_tables as $short ) {
    $full = $prefix . $short;
    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) );
    if ( $exists ) {
        $wpdb->query( "DROP TABLE IF EXISTS {$full}" );
    }
}

// 2. Delete all vyg_* options (both single-site + multisite patterns).
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'vyg\\_%' ESCAPE '\\\\'" );

// 3. Delete all vyg_* transients.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\\_vyg\\_%' ESCAPE '\\\\'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\\_timeout\\_vyg\\_%' ESCAPE '\\\\'" );

// 4. Clear all vyg_* scheduled cron hooks.
$cron_hooks = array(
    'vyg_cron_metadata_refresh',
    'vyg_cron_incremental_all',
    'vyg_cron_live_poll',
    'vyg_sync_source_initial',
    'vyg_sync_source_incremental',
    'vyg_refresh_video_batch',
);
foreach ( $cron_hooks as $hook ) {
    $timestamp = wp_next_scheduled( $hook );
    while ( $timestamp ) {
        wp_unschedule_event( $timestamp, $hook );
        $timestamp = wp_next_scheduled( $hook );
    }
    wp_clear_scheduled_hook( $hook );
}

// 5. Clear custom cron schedules added by Plugin::on_activation.
$option = get_option( 'vyg_uninstalled_at' );
if ( ! $option ) {
    add_option( 'vyg_uninstalled_at', gmdate( 'c' ), '', 'no' );
}