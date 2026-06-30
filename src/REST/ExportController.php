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

        register_rest_route(self::NAMESPACE_V1, '/moderation/export', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'export_moderation'),
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
                'queue'  => array(
                    'required'          => false,
                    'default'           => 'needs_review',
                    'sanitize_callback' => 'sanitize_key',
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

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function export_moderation($request) {
        $format = (string) $request->get_param('format');
        $queue  = (string) $request->get_param('queue');
        $where  = $this->moderation_where($queue);

        global $wpdb;
        $table = $wpdb->prefix . 'vyg_videos';
        $rows = $wpdb->get_results(
            "SELECT id, youtube_video_id, title, content_type, manual_content_type,
                    availability_status, privacy_status, embeddable, is_hidden,
                    moderation_status, moderation_reason, moderated_by, moderated_at,
                    last_success_at, api_data_expires_at, updated_at
               FROM {$table}
              WHERE {$where}
              ORDER BY updated_at DESC, id DESC
              LIMIT 100000",
            ARRAY_A
        );

        if ('csv' === $format) {
            nocache_headers();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="vyg-moderation-' . gmdate('Ymd-His') . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, array('id', 'youtube_video_id', 'title', 'content_type', 'manual_content_type', 'availability_status', 'privacy_status', 'embeddable', 'is_hidden', 'moderation_status', 'moderation_reason', 'moderated_by', 'moderated_at', 'last_success_at', 'api_data_expires_at', 'updated_at'));
            foreach ((array) $rows as $row) {
                fputcsv($out, array(
                    (int) $row['id'],
                    (string) $row['youtube_video_id'],
                    (string) $row['title'],
                    (string) $row['content_type'],
                    (string) ($row['manual_content_type'] ?? ''),
                    (string) $row['availability_status'],
                    (string) $row['privacy_status'],
                    (int) $row['embeddable'],
                    (int) $row['is_hidden'],
                    (string) $row['moderation_status'],
                    (string) ($row['moderation_reason'] ?? ''),
                    (int) $row['moderated_by'],
                    (string) ($row['moderated_at'] ?? ''),
                    (string) ($row['last_success_at'] ?? ''),
                    (string) ($row['api_data_expires_at'] ?? ''),
                    (string) $row['updated_at'],
                ));
            }
            fclose($out);
            exit;
        }

        return new WP_REST_Response(array(
            'count' => count((array) $rows),
            'queue' => $queue,
            'rows'  => (array) $rows,
        ), 200);
    }

    private function moderation_where(string $queue): string {
        $now = esc_sql(gmdate('Y-m-d H:i:s'));
        $stale = "(api_data_expires_at IS NULL OR api_data_expires_at < '{$now}' OR last_success_at IS NULL)";
        $unavailable = "(availability_status <> 'available' OR privacy_status IN ('private','deleted') OR embeddable = 0)";
        return match ($queue) {
            'manual_review' => "moderation_status = 'manual_review'",
            'unavailable'   => $unavailable,
            'stale'         => $stale,
            'hidden'        => "is_hidden = 1 OR moderation_status = 'hidden'",
            default         => "(moderation_status = 'manual_review' OR {$unavailable} OR {$stale} OR is_hidden = 1)",
        };
    }
}