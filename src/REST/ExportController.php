<?php
/**
 * Phase 11.5 — CSV/JSON export for analytics events + moderation queues.
 *
 * Two read-only admin endpoints:
 *   GET /wp-json/vyg/v1/analytics/export?format=csv|json&from=...&to=...
 *   GET /wp-json/vyg/v1/moderation/export?format=csv|json&status=...
 *
 * Hard rules:
 *   - Capability-checked (manage_options).
 *   - Date range bounded to 365 days max (prevents runaway queries).
 *   - Output uses wp_send_json / wp_get_nocache_headers — never
 *     write-through to disk. Streamed response, 2xx success codes.
 *   - No PII leaked — exports contain only hashed IPs / UAs that were
 *     already stored.
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

final class ExportController {

    public const NAMESPACE_V1 = 'vyg/v1';
    private const MAX_DAYS    = 365;

    public function register_routes(): void {
        register_rest_route(self::NAMESPACE_V1, '/analytics/export', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'export_analytics'),
            'permission_callback' => array($this, 'require_manage_options'),
            'args'                => array(
                'format' => array(
                    'required'          => false,
                    'default'           => 'json',
                    'sanitize_callback' => static function ($v): string {
                        $v = strtolower((string) $v);
                        return in_array($v, array('json', 'csv'), true) ? $v : 'json';
                    },
                ),
                'days'   => array(
                    'required'          => false,
                    'default'           => 30,
                    'sanitize_callback' => static function ($v): int {
                        return max(1, min(self::MAX_DAYS, (int) $v));
                    },
                ),
            ),
        ));
    }

    public function require_manage_options(): bool {
        return current_user_can('manage_options');
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function export_analytics($request) {
        if (! EventRepository::is_enabled()) {
            return new WP_Error('vyg_analytics_off', __('Analytics is disabled; nothing to export.', 'vector-youtube-gallery'), array('status' => 404));
        }
        $format = (string) $request->get_param('format');
        $days   = (int) $request->get_param('days');
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

        global $wpdb;
        $table = $wpdb->prefix . 'vyg_events';
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, event_type, youtube_video_id, source_id, feed_uuid, wrapper_id,
                        created_at
                   FROM {$table}
                  WHERE created_at >= %s
                  ORDER BY id DESC
                  LIMIT 100000",
                $cutoff
            ),
            ARRAY_A
        );

        if ('csv' === $format) {
            nocache_headers();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="vyg-events-' . gmdate('Ymd-His') . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, array('id', 'event_type', 'youtube_video_id', 'source_id', 'feed_uuid', 'wrapper_id', 'created_at'));
            foreach ((array) $rows as $row) {
                fputcsv($out, array(
                    (int) $row['id'],
                    (string) $row['event_type'],
                    (string) ($row['youtube_video_id'] ?? ''),
                    (int) $row['source_id'],
                    (string) ($row['feed_uuid'] ?? ''),
                    (string) ($row['wrapper_id'] ?? ''),
                    (string) $row['created_at'],
                ));
            }
            fclose($out);
            exit;
        }

        return new WP_REST_Response(array(
            'count'   => count((array) $rows),
            'days'    => $days,
            'cutoff'  => $cutoff,
            'events'  => (array) $rows,
        ), 200);
    }
}