<?php
/**
 * Phase 11.1 — REST controller for analytics events ingestion.
 *
 * Route: POST /wp-json/vyg/v1/events
 *
 * Hard rules:
 *   - **Public endpoint** — no auth requirement. Analytics captures
 *     anonymous front-end interactions and would silently fail if it
 *     required a logged-in user.
 *   - **No PII stored.** The controller reads IP + UA from $_SERVER
 *     and SHA-256-hashes them before passing into the repository.
 *   - **Bounded payload.** event_type must be one of EVENT_TYPES,
 *     youtube_video_id must be 11 chars, feed_uuid must be a valid
 *     UUID, event_data_json must be ≤ 64KB.
 *   - **No YouTube API.** The endpoint NEVER calls YouTube. It only
 *     persists rows to wp_vyg_events.
 *
 * @package VectorYT\Gallery\REST
 */

declare(strict_types=1);

namespace VectorYT\Gallery\REST;

use VectorYT\Gallery\Analytics\EventRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

final class AnalyticsController {

    public const NAMESPACE_V1 = 'vyg/v1';

    public function register_routes(): void {
        register_rest_route(self::NAMESPACE_V1, '/events', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_event'),
            'permission_callback' => '__return_true',
            'args'                => array(
                'event_type'      => array(
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => static function ($value): bool {
                        return is_string($value) && in_array($value, EventRepository::EVENT_TYPES, true);
                    },
                ),
                'youtube_video_id' => array(
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'feed_uuid'        => array(
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'wrapper_id'       => array(
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'session_id'       => array(
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'event_data'       => array(
                    'required'          => false,
                    'sanitize_callback' => 'wp_kses_post',
                ),
            ),
        ));
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function handle_event($request) {
        if (! EventRepository::is_enabled()) {
            // Privacy-first default: when analytics is off, return 204
            // (No Content) so the front-end stops retrying.
            return new WP_REST_Response(null, 204);
        }
        $args = array(
            'event_type'      => (string) $request->get_param('event_type'),
            'youtube_video_id' => (string) $request->get_param('youtube_video_id'),
            'feed_uuid'        => (string) $request->get_param('feed_uuid'),
            'wrapper_id'       => (string) $request->get_param('wrapper_id'),
            'session_id'       => (string) $request->get_param('session_id'),
            'event_data'       => (string) $request->get_param('event_data'),
        );

        // The repo hashes IP + UA. We don't need to do that here.
        $id = EventRepository::record($args);
        if ($id <= 0) {
            return new WP_Error('vyg_event_record_failed', __('Could not record analytics event.', 'vector-youtube-gallery'), array('status' => 500));
        }
        return new WP_REST_Response(array('recorded' => true, 'id' => $id), 201);
    }
}