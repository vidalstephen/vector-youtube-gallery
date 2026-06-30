<?php
/**
 * Phase 11.3 — Moderation queues.
 *
 * Local-only admin page for queueing and triaging videos that need operator
 * attention: manual-review rows, unavailable/private/embed-disabled rows,
 * stale metadata rows, and hidden rows. Bulk actions update local DB state
 * only; no YouTube API calls are made.
 *
 * @package VectorYT\Gallery\Admin
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Admin;

use VectorYT\Gallery\Repository\VideoRepository;

defined('ABSPATH') || exit;

final class ModerationPage {

    private const NONCE_ACTION = 'vyg_moderation_action';
    private const PER_PAGE     = 50;

    public function __construct(
        private readonly VideoRepository $videos,
    ) {}

    public function render(): void {
        if (! current_user_can(AdminMenu::REQUIRED_CAP)) {
            wp_die(esc_html__('Insufficient permissions.', 'vector-youtube-gallery'));
        }
        if ('POST' === ($_SERVER['REQUEST_METHOD'] ?? '')) {
            $this->handle_post();
        }

        $queue = isset($_GET['queue']) ? sanitize_key(wp_unslash($_GET['queue'])) : 'needs_review';
        $queue = in_array($queue, $this->queue_slugs(), true) ? $queue : 'needs_review';
        $paged = max(1, (int) ($_GET['paged'] ?? 1));
        $rows  = $this->query_queue($queue, $paged);
        $total = $this->count_queue($queue);
        $pages = (int) ceil($total / self::PER_PAGE);
        $this->render_html($queue, $rows, $paged, $pages, $total);
    }

    /** @return array<int,string> */
    private function queue_slugs(): array {
        return array('needs_review', 'manual_review', 'unavailable', 'stale', 'hidden');
    }

    /** @return array<string,string> */
    private function queue_labels(): array {
        return array(
            'needs_review'  => __('Needs review', 'vector-youtube-gallery'),
            'manual_review' => __('Manual review', 'vector-youtube-gallery'),
            'unavailable'   => __('Unavailable', 'vector-youtube-gallery'),
            'stale'         => __('Stale metadata', 'vector-youtube-gallery'),
            'hidden'        => __('Hidden', 'vector-youtube-gallery'),
        );
    }

    private function handle_post(): void {
        $nonce = isset($_POST['vyg_moderation_nonce']) ? sanitize_text_field(wp_unslash($_POST['vyg_moderation_nonce'])) : '';
        if (! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_die(esc_html__('Invalid nonce.', 'vector-youtube-gallery'));
        }
        $action = isset($_POST['bulk_action']) ? sanitize_key(wp_unslash($_POST['bulk_action'])) : '';
        $ids    = isset($_POST['video_ids']) && is_array($_POST['video_ids'])
            ? array_values(array_filter(array_map('absint', wp_unslash($_POST['video_ids']))))
            : array();
        if (empty($ids)) {
            $this->redirect_with_notice('none');
        }
        $reason = isset($_POST['moderation_reason']) ? sanitize_text_field(wp_unslash($_POST['moderation_reason'])) : '';
        $type   = isset($_POST['manual_content_type']) ? sanitize_key(wp_unslash($_POST['manual_content_type'])) : '';
        $updated = $this->apply_bulk($ids, $action, $reason, $type);
        $this->redirect_with_notice($updated > 0 ? 'updated' : 'none');
    }

    /** @param array<int,int> $ids */
    public function apply_bulk(array $ids, string $action, string $reason = '', string $manual_type = ''): int {
        $updated = 0;
        $now = gmdate('Y-m-d H:i:s');
        $user = get_current_user_id();
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $updates = array(
                'updated_at' => $now,
            );
            if ('approve' === $action) {
                $updates['moderation_status'] = 'approved';
                $updates['moderation_reason'] = '' === $reason ? null : $reason;
                $updates['moderated_by']      = $user;
                $updates['moderated_at']      = $now;
            } elseif ('manual_review' === $action) {
                $updates['moderation_status'] = 'manual_review';
                $updates['moderation_reason'] = '' === $reason ? __('Queued for manual review.', 'vector-youtube-gallery') : $reason;
                $updates['moderated_by']      = $user;
                $updates['moderated_at']      = $now;
            } elseif ('hide' === $action) {
                $updates['is_hidden']         = 1;
                $updates['moderation_status'] = 'hidden';
                $updates['moderation_reason'] = '' === $reason ? __('Hidden from galleries.', 'vector-youtube-gallery') : $reason;
                $updates['moderated_by']      = $user;
                $updates['moderated_at']      = $now;
            } elseif ('unhide' === $action) {
                $updates['is_hidden']         = 0;
                $updates['moderation_status'] = 'approved';
                $updates['moderation_reason'] = '' === $reason ? __('Unhidden by moderator.', 'vector-youtube-gallery') : $reason;
                $updates['moderated_by']      = $user;
                $updates['moderated_at']      = $now;
            } elseif ('classify' === $action) {
                if (! in_array($manual_type, array('standard', 'short_confirmed', 'short_candidate', 'live_active', 'live_upcoming', 'live_replay'), true)) {
                    continue;
                }
                $updates['manual_content_type']   = $manual_type;
                $updates['manual_content_source'] = sprintf('moderation:%d:%s', $user, gmdate('c'));
                $updates['manual_reason']         = '' === $reason ? __('Classified from moderation queue.', 'vector-youtube-gallery') : $reason;
                $updates['moderation_status']     = 'approved';
                $updates['moderation_reason']     = '' === $reason ? __('Classified from moderation queue.', 'vector-youtube-gallery') : $reason;
                $updates['moderated_by']          = $user;
                $updates['moderated_at']          = $now;
            } else {
                continue;
            }
            $updated += $this->videos->update_by_id($id, $updates) > 0 ? 1 : 0;
        }
        return $updated;
    }

    /** @return array<int,array<string,mixed>> */
    private function query_queue(string $queue, int $paged): array {
        global $wpdb;
        $table = $wpdb->prefix . 'vyg_videos';
        $where = $this->where_for_queue($queue);
        $offset = ($paged - 1) * self::PER_PAGE;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, youtube_video_id, title, content_type, manual_content_type, availability_status,
                        privacy_status, embeddable, is_hidden, moderation_status, moderation_reason,
                        last_success_at, api_data_expires_at, updated_at
                   FROM {$table}
                  WHERE {$where}
                  ORDER BY updated_at DESC, id DESC
                  LIMIT %d OFFSET %d",
                self::PER_PAGE,
                $offset
            ),
            ARRAY_A
        );
        return is_array($rows) ? $rows : array();
    }

    private function count_queue(string $queue): int {
        global $wpdb;
        $table = $wpdb->prefix . 'vyg_videos';
        $where = $this->where_for_queue($queue);
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}");
    }

    private function where_for_queue(string $queue): string {
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

    /** @param array<int,array<string,mixed>> $rows */
    private function render_html(string $queue, array $rows, int $paged, int $pages, int $total): void {
        $labels = $this->queue_labels();
        $notice = isset($_GET['vyg_notice']) ? sanitize_key(wp_unslash($_GET['vyg_notice'])) : '';
        echo '<div class="wrap vyg-moderation-page">';
        echo '<h1>' . esc_html__('YouTube Gallery — Moderation', 'vector-youtube-gallery') . '</h1>';
        echo '<p>' . esc_html__('Review videos that are hidden, unavailable, stale, or manually flagged. Bulk actions update only the local WordPress index.', 'vector-youtube-gallery') . '</p>';
        $this->render_notice($notice);
        echo '<nav class="nav-tab-wrapper" style="margin-bottom:16px;">';
        foreach ($labels as $slug => $label) {
            $url = add_query_arg(array('page' => AdminMenu::PARENT_SLUG . '-moderation', 'queue' => $slug), admin_url('admin.php'));
            echo '<a class="nav-tab ' . ($slug === $queue ? 'nav-tab-active' : '') . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
        echo '<p><strong>' . esc_html(sprintf(_n('%d item', '%d items', $total, 'vector-youtube-gallery'), $total)) . '</strong></p>';
        echo '<form method="post">';
        wp_nonce_field(self::NONCE_ACTION, 'vyg_moderation_nonce');
        echo '<div style="margin:12px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';
        echo '<select name="bulk_action"><option value="approve">' . esc_html__('Approve', 'vector-youtube-gallery') . '</option><option value="manual_review">' . esc_html__('Mark manual review', 'vector-youtube-gallery') . '</option><option value="hide">' . esc_html__('Hide', 'vector-youtube-gallery') . '</option><option value="unhide">' . esc_html__('Unhide', 'vector-youtube-gallery') . '</option><option value="classify">' . esc_html__('Classify as…', 'vector-youtube-gallery') . '</option></select>';
        echo '<select name="manual_content_type"><option value="standard">' . esc_html__('Standard', 'vector-youtube-gallery') . '</option><option value="short_confirmed">' . esc_html__('Short confirmed', 'vector-youtube-gallery') . '</option><option value="short_candidate">' . esc_html__('Short candidate', 'vector-youtube-gallery') . '</option><option value="live_active">' . esc_html__('Live active', 'vector-youtube-gallery') . '</option><option value="live_upcoming">' . esc_html__('Live upcoming', 'vector-youtube-gallery') . '</option><option value="live_replay">' . esc_html__('Live replay', 'vector-youtube-gallery') . '</option></select>';
        echo '<input type="text" name="moderation_reason" placeholder="' . esc_attr__('Reason (optional)', 'vector-youtube-gallery') . '" style="min-width:260px;" />';
        submit_button(__('Apply bulk action', 'vector-youtube-gallery'), 'secondary', '', false);
        echo '</div>';
        echo '<table class="widefat striped"><thead><tr><td class="manage-column column-cb check-column"><input type="checkbox" onclick="document.querySelectorAll(\'.vyg-video-cb\').forEach(cb=>cb.checked=this.checked);" /></td><th>' . esc_html__('Video', 'vector-youtube-gallery') . '</th><th>' . esc_html__('Flags', 'vector-youtube-gallery') . '</th><th>' . esc_html__('Moderation', 'vector-youtube-gallery') . '</th><th>' . esc_html__('Freshness', 'vector-youtube-gallery') . '</th></tr></thead><tbody>';
        if (empty($rows)) {
            echo '<tr><td colspan="5">' . esc_html__('No videos in this queue.', 'vector-youtube-gallery') . '</td></tr>';
        }
        foreach ($rows as $row) {
            $flags = $this->flags_for_row($row);
            echo '<tr><th scope="row" class="check-column"><input class="vyg-video-cb" type="checkbox" name="video_ids[]" value="' . esc_attr((string) $row['id']) . '" /></th>';
            echo '<td><strong>' . esc_html((string) ($row['title'] ?? '')) . '</strong><br><code>' . esc_html((string) ($row['youtube_video_id'] ?? '')) . '</code><br><span>' . esc_html((string) ($row['content_type'] ?? '')) . '</span></td>';
            echo '<td>' . esc_html(implode(', ', $flags)) . '</td>';
            echo '<td><code>' . esc_html((string) ($row['moderation_status'] ?? 'approved')) . '</code><br>' . esc_html((string) ($row['moderation_reason'] ?? '')) . '</td>';
            echo '<td>' . esc_html(sprintf(__('Last success: %s', 'vector-youtube-gallery'), (string) ($row['last_success_at'] ?: '—'))) . '<br>' . esc_html(sprintf(__('Expires: %s', 'vector-youtube-gallery'), (string) ($row['api_data_expires_at'] ?: '—'))) . '</td></tr>';
        }
        echo '</tbody></table></form>';
        if ($pages > 1) {
            $base = add_query_arg(array('page' => AdminMenu::PARENT_SLUG . '-moderation', 'queue' => $queue, 'paged' => '%#%'), admin_url('admin.php'));
            echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links(array('base' => $base, 'format' => '', 'total' => $pages, 'current' => $paged)) . '</div></div>';
        }
        echo '</div>';
    }

    /** @param array<string,mixed> $row @return array<int,string> */
    private function flags_for_row(array $row): array {
        $flags = array();
        if ('manual_review' === (string) ($row['moderation_status'] ?? '')) { $flags[] = __('manual review', 'vector-youtube-gallery'); }
        if ('available' !== (string) ($row['availability_status'] ?? '')) { $flags[] = (string) ($row['availability_status'] ?? 'unknown'); }
        if (! (bool) ($row['embeddable'] ?? true)) { $flags[] = __('embed disabled', 'vector-youtube-gallery'); }
        if (! empty($row['is_hidden'])) { $flags[] = __('hidden', 'vector-youtube-gallery'); }
        if (empty($row['last_success_at']) || empty($row['api_data_expires_at']) || strtotime((string) $row['api_data_expires_at']) < time()) { $flags[] = __('stale', 'vector-youtube-gallery'); }
        return array_values(array_unique($flags));
    }

    private function render_notice(string $notice): void {
        $messages = array(
            'updated' => array(__('Moderation changes saved.', 'vector-youtube-gallery'), 'success'),
            'none'    => array(__('No videos were updated.', 'vector-youtube-gallery'), 'warning'),
        );
        if (isset($messages[$notice])) {
            [$msg, $cls] = $messages[$notice];
            echo '<div class="notice notice-' . esc_attr($cls) . '"><p>' . esc_html($msg) . '</p></div>';
        }
    }

    private function redirect_with_notice(string $notice): void {
        wp_safe_redirect(add_query_arg(array('page' => AdminMenu::PARENT_SLUG . '-moderation', 'vyg_notice' => $notice), admin_url('admin.php')));
        exit;
    }
}
