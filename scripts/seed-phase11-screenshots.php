<?php
/**
 * Seed deterministic local-only data for Phase 11 screenshots.
 *
 * This script is run via wp eval-file before Dockerized Playwright captures.
 * It only writes local plugin rows; it never calls YouTube or external APIs.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;
$events = $wpdb->prefix . 'vyg_events';
$videos = $wpdb->prefix . 'vyg_videos';
$session = hash( 'sha256', 'phase11-playwright' );
$now = current_time( 'mysql', true );
$expired = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );

// Remove previous screenshot seed rows/events so captures are deterministic.
$wpdb->delete( $events, array( 'session_hash' => $session ), array( '%s' ) );

$video_id = 'dQw4w9WgXcQ';
$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$videos} WHERE youtube_video_id = %s", $video_id ) );
if ( $existing ) {
    $wpdb->update(
        $videos,
        array(
            'title'              => 'Rick Astley - Never Gonna Give You Up (screenshot seed)',
            'availability_status'=> 'available',
            'privacy_status'     => 'public',
            'embeddable'         => 1,
            'is_hidden'          => 0,
            'moderation_status'  => 'manual_review',
            'moderation_reason'  => 'Phase 11 screenshot seed',
            'api_data_expires_at'=> $expired,
            'updated_at'         => $now,
        ),
        array( 'youtube_video_id' => $video_id ),
        array( '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' ),
        array( '%s' )
    );
}

foreach ( array(
    array( 'impression', $video_id ),
    array( 'impression', $video_id ),
    array( 'impression', $video_id ),
    array( 'play', $video_id ),
    array( 'lightbox_open', $video_id ),
    array( 'load_more_click', null ),
) as $event ) {
    $wpdb->insert(
        $events,
        array(
            'event_type'      => $event[0],
            'youtube_video_id'=> $event[1],
            'feed_uuid'       => '11111111-1111-4111-8111-111111111111',
            'wrapper_id'      => 'vyg-feed-playwright',
            'session_hash'    => $session,
            'ip_hash'         => hash( 'sha256', '127.0.0.1' ),
            'user_agent_hash' => hash( 'sha256', 'PlaywrightScreenshot' ),
            'created_at'      => $now,
        ),
        array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
    );
}

echo "seeded_phase11_screenshots=1\n";
