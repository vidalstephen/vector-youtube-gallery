<?php
/**
 * Phase 11.2 — Analytics dashboard.
 *
 * Read-only admin page backed entirely by local plugin tables. It never calls
 * YouTube or other external services; quota trend data comes from
 * wp_vyg_api_quota_log, and interaction metrics come from wp_vyg_events.
 *
 * @package VectorYT\Gallery\Admin
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Admin;

defined('ABSPATH') || exit;

final class AnalyticsPage {

    private const DEFAULT_DAYS = 30;
    private const MAX_DAYS     = 365;

    public function render(): void {
        if (! current_user_can(AdminMenu::REQUIRED_CAP)) {
            wp_die(esc_html__('Insufficient permissions.', 'vector-youtube-gallery'));
        }

        $days  = $this->days_from_request();
        $stats = $this->collect($days);

        echo '<div class="wrap vyg-analytics-dashboard">';
        echo '<h1>' . esc_html__('YouTube Gallery — Analytics', 'vector-youtube-gallery') . '</h1>';
        echo '<p>' . esc_html__('Local, privacy-first analytics from your WordPress database. No external tracking and no YouTube API calls happen on this page.', 'vector-youtube-gallery') . '</p>';

        if (! (bool) get_option('vyg_analytics_enabled', false)) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Local analytics is currently disabled. Enable it under Privacy & Compliance to start collecting events.', 'vector-youtube-gallery') . '</p></div>';
        }

        $this->render_filters($days);
        $this->render_summary_cards($stats);
        $this->render_top_videos($stats['top_videos']);
        $this->render_feed_views($stats['feed_views']);
        $this->render_quota_trends($stats['quota_trends']);
        $this->render_sync_health($stats['sync_health']);
        echo '</div>';
    }

    private function days_from_request(): int {
        $raw = isset($_GET['days']) ? absint(wp_unslash($_GET['days'])) : self::DEFAULT_DAYS;
        return max(1, min(self::MAX_DAYS, $raw));
    }

    /**
     * @return array<string,mixed>
     */
    public function collect(int $days): array {
        global $wpdb;
        $days   = max(1, min(self::MAX_DAYS, $days));
        $prefix = $wpdb->prefix;
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

        $event_counts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT event_type, COUNT(*) AS total
                   FROM {$prefix}vyg_events
                  WHERE created_at >= %s
                  GROUP BY event_type",
                $cutoff
            ),
            ARRAY_A
        );
        $counts = array('impression' => 0, 'play' => 0, 'lightbox_open' => 0, 'load_more_click' => 0);
        foreach ((array) $event_counts as $row) {
            $type = (string) ($row['event_type'] ?? '');
            if (array_key_exists($type, $counts)) {
                $counts[$type] = (int) $row['total'];
            }
        }

        $top_videos = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT e.youtube_video_id,
                        MAX(v.title) AS title,
                        SUM(CASE WHEN e.event_type='impression' THEN 1 ELSE 0 END) AS impressions,
                        SUM(CASE WHEN e.event_type IN ('play','lightbox_open') THEN 1 ELSE 0 END) AS plays
                   FROM {$prefix}vyg_events e
              LEFT JOIN {$prefix}vyg_videos v ON v.youtube_video_id = e.youtube_video_id
                  WHERE e.created_at >= %s
                    AND e.youtube_video_id IS NOT NULL
                  GROUP BY e.youtube_video_id
                  ORDER BY plays DESC, impressions DESC
                  LIMIT 10",
                $cutoff
            ),
            ARRAY_A
        );

        $feed_views = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT feed_uuid,
                        SUM(CASE WHEN event_type='impression' THEN 1 ELSE 0 END) AS impressions,
                        SUM(CASE WHEN event_type IN ('play','lightbox_open') THEN 1 ELSE 0 END) AS plays,
                        COUNT(DISTINCT session_hash) AS unique_sessions
                   FROM {$prefix}vyg_events
                  WHERE created_at >= %s
                    AND feed_uuid IS NOT NULL
                  GROUP BY feed_uuid
                  ORDER BY impressions DESC
                  LIMIT 10",
                $cutoff
            ),
            ARRAY_A
        );

        $quota_trends = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(created_at) AS day,
                        COALESCE(SUM(quota_units),0) AS quota_units,
                        COUNT(*) AS requests
                   FROM {$prefix}vyg_api_quota_log
                  WHERE created_at >= %s
                  GROUP BY DATE(created_at)
                  ORDER BY day DESC
                  LIMIT 14",
                $cutoff
            ),
            ARRAY_A
        );

        $stale_cutoff = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
        $sync_health = array(
            'sources_total'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}vyg_sources"),
            'sources_active' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}vyg_sources WHERE status='active'"),
            'sources_error'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}vyg_sources WHERE status='error'"),
            'stale_sources'  => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}vyg_sources WHERE status='active' AND (last_success_at IS NULL OR last_success_at < %s)", $stale_cutoff)),
            'last_sync'      => (string) $wpdb->get_var("SELECT MAX(last_success_at) FROM {$prefix}vyg_sources"),
            'jobs'           => (array) $wpdb->get_results("SELECT status, COUNT(*) AS total FROM {$prefix}vyg_sync_jobs GROUP BY status ORDER BY status", ARRAY_A),
        );

        $total_plays = $counts['play'] + $counts['lightbox_open'];
        $click_rate  = $counts['impression'] > 0 ? round(($total_plays / $counts['impression']) * 100, 2) : 0.0;

        return array(
            'days'         => $days,
            'cutoff'       => $cutoff,
            'summary'      => array(
                'impressions'     => $counts['impression'],
                'plays'           => $total_plays,
                'load_more_clicks'=> $counts['load_more_click'],
                'click_rate'      => $click_rate,
            ),
            'top_videos'   => is_array($top_videos) ? $top_videos : array(),
            'feed_views'   => is_array($feed_views) ? $feed_views : array(),
            'quota_trends' => is_array($quota_trends) ? $quota_trends : array(),
            'sync_health'  => $sync_health,
        );
    }

    private function render_filters(int $days): void {
        echo '<form method="get" style="margin:1em 0;">';
        echo '<input type="hidden" name="page" value="' . esc_attr(AdminMenu::PARENT_SLUG . '-analytics') . '" />';
        echo '<label for="vyg-analytics-days">' . esc_html__('Date range:', 'vector-youtube-gallery') . '</label> ';
        echo '<select id="vyg-analytics-days" name="days">';
        foreach (array(7, 14, 30, 90, 365) as $option) {
            echo '<option value="' . esc_attr((string) $option) . '" ' . selected($days, $option, false) . '>' . esc_html(sprintf(_n('%d day', '%d days', $option, 'vector-youtube-gallery'), $option)) . '</option>';
        }
        echo '</select> ';
        submit_button(__('Apply', 'vector-youtube-gallery'), 'secondary', '', false);
        echo '</form>';
    }

    /** @param array<string,mixed> $stats */
    private function render_summary_cards(array $stats): void {
        $summary = (array) $stats['summary'];
        echo '<div class="vyg-dashboard-cards" style="display:grid;grid-template-columns:repeat(4,minmax(140px,1fr));gap:12px;margin:16px 0;">';
        foreach (array(
            __('Impressions', 'vector-youtube-gallery') => number_format_i18n((int) $summary['impressions']),
            __('Plays / opens', 'vector-youtube-gallery') => number_format_i18n((int) $summary['plays']),
            __('Click rate', 'vector-youtube-gallery') => esc_html((string) $summary['click_rate']) . '%',
            __('Load more clicks', 'vector-youtube-gallery') => number_format_i18n((int) $summary['load_more_clicks']),
        ) as $label => $value) {
            echo '<div class="postbox" style="padding:12px;"><strong style="display:block;font-size:20px;">' . esc_html((string) $value) . '</strong><span>' . esc_html((string) $label) . '</span></div>';
        }
        echo '</div>';
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function render_top_videos(array $rows): void {
        echo '<h2>' . esc_html__('Top videos', 'vector-youtube-gallery') . '</h2>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Video', 'vector-youtube-gallery') . '</th><th>' . esc_html__('Impressions', 'vector-youtube-gallery') . '</th><th>' . esc_html__('Plays/opens', 'vector-youtube-gallery') . '</th><th>' . esc_html__('Rate', 'vector-youtube-gallery') . '</th></tr></thead><tbody>';
        if (empty($rows)) {
            echo '<tr><td colspan="4">' . esc_html__('No analytics events in this date range yet.', 'vector-youtube-gallery') . '</td></tr>';
        }
        foreach ($rows as $row) {
            $impressions = (int) ($row['impressions'] ?? 0);
            $plays       = (int) ($row['plays'] ?? 0);
            $rate        = $impressions > 0 ? round(($plays / $impressions) * 100, 2) : 0;
            $title       = (string) ($row['title'] ?: $row['youtube_video_id']);
            echo '<tr><td><code>' . esc_html((string) $row['youtube_video_id']) . '</code><br>' . esc_html($title) . '</td><td>' . esc_html(number_format_i18n($impressions)) . '</td><td>' . esc_html(number_format_i18n($plays)) . '</td><td>' . esc_html((string) $rate) . '%</td></tr>';
        }
        echo '</tbody></table>';
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function render_feed_views(array $rows): void {
        echo '<h2>' . esc_html__('Feed views', 'vector-youtube-gallery') . '</h2>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Feed UUID', 'vector-youtube-gallery') . '</th><th>' . esc_html__('Impressions', 'vector-youtube-gallery') . '</th><th>' . esc_html__('Plays/opens', 'vector-youtube-gallery') . '</th><th>' . esc_html__('Unique sessions', 'vector-youtube-gallery') . '</th></tr></thead><tbody>';
        if (empty($rows)) {
            echo '<tr><td colspan="4">' . esc_html__('No feed-specific events in this date range yet.', 'vector-youtube-gallery') . '</td></tr>';
        }
        foreach ($rows as $row) {
            echo '<tr><td><code>' . esc_html((string) ($row['feed_uuid'] ?? '')) . '</code></td><td>' . esc_html(number_format_i18n((int) ($row['impressions'] ?? 0))) . '</td><td>' . esc_html(number_format_i18n((int) ($row['plays'] ?? 0))) . '</td><td>' . esc_html(number_format_i18n((int) ($row['unique_sessions'] ?? 0))) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function render_quota_trends(array $rows): void {
        echo '<h2>' . esc_html__('Quota usage trends', 'vector-youtube-gallery') . '</h2>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Day', 'vector-youtube-gallery') . '</th><th>' . esc_html__('Requests', 'vector-youtube-gallery') . '</th><th>' . esc_html__('Quota units', 'vector-youtube-gallery') . '</th></tr></thead><tbody>';
        if (empty($rows)) {
            echo '<tr><td colspan="3">' . esc_html__('No quota log rows in this date range.', 'vector-youtube-gallery') . '</td></tr>';
        }
        foreach ($rows as $row) {
            echo '<tr><td>' . esc_html((string) ($row['day'] ?? '')) . '</td><td>' . esc_html(number_format_i18n((int) ($row['requests'] ?? 0))) . '</td><td>' . esc_html(number_format_i18n((int) ($row['quota_units'] ?? 0))) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /** @param array<string,mixed> $health */
    private function render_sync_health(array $health): void {
        echo '<h2>' . esc_html__('Sync health', 'vector-youtube-gallery') . '</h2>';
        echo '<ul class="ul-disc">';
        echo '<li>' . esc_html(sprintf(__('Sources: %1$d total, %2$d active, %3$d error, %4$d stale', 'vector-youtube-gallery'), (int) $health['sources_total'], (int) $health['sources_active'], (int) $health['sources_error'], (int) $health['stale_sources'])) . '</li>';
        echo '<li>' . esc_html(sprintf(__('Last successful sync: %s', 'vector-youtube-gallery'), (string) ($health['last_sync'] ?: __('never', 'vector-youtube-gallery')))) . '</li>';
        echo '</ul>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Job status', 'vector-youtube-gallery') . '</th><th>' . esc_html__('Count', 'vector-youtube-gallery') . '</th></tr></thead><tbody>';
        $jobs = (array) $health['jobs'];
        if (empty($jobs)) {
            echo '<tr><td colspan="2">' . esc_html__('No sync jobs yet.', 'vector-youtube-gallery') . '</td></tr>';
        }
        foreach ($jobs as $row) {
            echo '<tr><td>' . esc_html((string) ($row['status'] ?? '')) . '</td><td>' . esc_html(number_format_i18n((int) ($row['total'] ?? 0))) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
}
