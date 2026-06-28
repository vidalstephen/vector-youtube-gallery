<?php
/**
 * Videos admin page — list, search, and reclassify indexed videos.
 *
 * Phase 3 goals:
 *   - Show all indexed videos (paginated)
 *   - Show effective content_type + manual_content_type + source of classification
 *   - Allow operator to override content_type via a dropdown + reason
 *   - Records operator id + timestamp + reason in vyg_videos for audit trail
 *
 * Phase 6 will add: bulk actions, hide/pin toggles, delete.
 *
 * @package VectorYT\Gallery\Admin
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Admin;

use VectorYT\Gallery\Logging\Logger;
use VectorYT\Gallery\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class VideosPage {

    private const NONCE_ACTION = 'vyg_videos_action';
    private const PER_PAGE     = 25;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly Logger $logger,
    ) {}

    public function render(): void {
        if ( ! current_user_can( AdminMenu::REQUIRED_CAP ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'vector-youtube-gallery' ) );
        }

        if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
            $this->maybe_handle_post();
        }

        $paged   = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $type_f  = isset( $_GET['content_type'] ) ? sanitize_key( wp_unslash( $_GET['content_type'] ) ) : '';

        $videos  = $this->query_videos( $paged, $search, $type_f );
        $total   = $this->count_videos( $search, $type_f );
        $pages   = (int) ceil( $total / self::PER_PAGE );

        $this->render_html( $videos, $paged, $pages, $search, $type_f );
    }

    private function maybe_handle_post(): void {
        if ( ! isset( $_POST['vyg_videos_nonce'] ) ) {
            return;
        }
        $nonce = sanitize_text_field( wp_unslash( $_POST['vyg_videos_nonce'] ) );
        if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
            wp_die( esc_html__( 'Invalid nonce.', 'vector-youtube-gallery' ) );
        }
        $op = isset( $_POST['vyg_op'] ) ? sanitize_key( wp_unslash( $_POST['vyg_op'] ) ) : '';
        if ( 'reclassify' === $op ) {
            $this->handle_reclassify();
        }
    }

    private function handle_reclassify(): void {
        $video_id = isset( $_POST['video_id'] ) ? (int) $_POST['video_id'] : 0;
        $override = isset( $_POST['manual_content_type'] ) ? sanitize_text_field( wp_unslash( $_POST['manual_content_type'] ) ) : '';
        $reason   = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
        if ( $video_id <= 0 ) {
            $this->redirect_with_notice( 'invalid' );
            return;
        }
        $valid = array( '', 'auto', 'standard', 'short_confirmed', 'short_candidate', 'live_active', 'live_upcoming', 'live_replay' );
        if ( ! in_array( $override, $valid, true ) ) {
            $this->redirect_with_notice( 'invalid_type' );
            return;
        }
        $this->update_video_classification( $video_id, $override, $reason );
        $this->redirect_with_notice( 'reclassified' );
    }

    private function update_video_classification( int $video_id, string $manual, string $reason ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'vyg_videos';
        $user_id = get_current_user_id();
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, manual_content_type, manual_content_source FROM {$table} WHERE id = %d",
            $video_id
        ), ARRAY_A );
        if ( ! $existing ) {
            return;
        }
        $source = '' === $manual ? null : sprintf( 'admin:%d:%s', $user_id, gmdate( 'c' ) );
        $wpdb->update(
            $table,
            array(
                'manual_content_type'   => '' === $manual ? null : $manual,
                'manual_content_source' => $source,
                'manual_reason'         => '' === $reason ? null : $reason,
                'updated_at'            => gmdate( 'Y-m-d H:i:s' ),
            ),
            array( 'id' => $video_id ),
            array( '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );
        // Re-apply classification: clear → set → re-normalize.
        $this->renormalize_video( $video_id, $manual );
        $this->logger->info( 'Video reclassified', array(
            'video_id'             => $video_id,
            'new_manual'           => $manual,
            'old_manual'           => $existing['manual_content_type'] ?? null,
            'reason'               => $reason,
            'admin_user_id'        => $user_id,
        ) );
    }

    /**
     * Re-run the normalizer for one video, using the new manual override.
     */
    private function renormalize_video( int $video_id, string $manual ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'vyg_videos';
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $video_id
        ), ARRAY_A );
        if ( ! $row ) {
            return;
        }
        // We don't have the raw API resource anymore; reconstruct a minimal one from the row.
        $resource = array(
            'id'             => $row['youtube_video_id'],
            'snippet'        => array(
                'title'                => $row['title'],
                'channelId'            => $row['youtube_channel_id'],
                'publishedAt'          => $row['published_at'],
                'liveBroadcastContent' => $this->broadcast_content_from_live_status( $row['live_status'] ),
                'thumbnails'           => array_filter( array(
                    'default'  => array( 'url' => $row['thumbnail_default'] ),
                    'medium'   => array( 'url' => $row['thumbnail_medium'] ),
                    'high'     => array( 'url' => $row['thumbnail_high'] ),
                    'standard' => array( 'url' => $row['thumbnail_standard'] ),
                    'maxres'   => array( 'url' => $row['thumbnail_maxres'] ),
                ) ),
                'tags'                 => $row['tags_json'] ? json_decode( $row['tags_json'], true ) : array(),
            ),
            'contentDetails' => array( 'duration' => $row['duration_iso'] ),
            'status'         => array(
                'uploadStatus'   => $row['upload_status'],
                'privacyStatus'  => $row['privacy_status'],
                'embeddable'     => (bool) $row['embeddable'],
            ),
            'statistics'     => array(
                'viewCount'    => $row['view_count'],
                'commentCount' => $row['comment_count'],
            ),
            'liveStreamingDetails' => array_filter( array(
                'scheduledStartTime' => $row['scheduled_start_at'],
                'actualStartTime'    => $row['actual_start_at'],
                'actualEndTime'      => $row['actual_end_at'],
            ) ),
        );
        // Use the new manual override; build normalizer with current settings.
        $normalizer = \VectorYT\Gallery\Normalize\VideoNormalizer::with_defaults();
        $normalized = $normalizer->normalize(
            $resource,
            array( 'manual_content_type' => $manual ),
            (int) $this->settings->get( 'shorts_max_duration_seconds', 60 ),
            (int) $this->settings->get( 'short_candidate_max_duration', 180 ),
        );
        // Only update content_type + live_status + availability_status from the re-run;
        // leave titles, tags, thumbnails, etc. alone (they came from the original API call).
        $wpdb->update(
            $table,
            array(
                'content_type'        => $normalized['content_type'],
                'live_status'         => $normalized['live_status'],
                'availability_status' => $normalized['availability_status'],
                'updated_at'          => gmdate( 'Y-m-d H:i:s' ),
            ),
            array( 'id' => $video_id ),
            array( '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );
    }

    private function broadcast_content_from_live_status( string $live_status ): string {
        return match ( $live_status ) {
            'live'     => 'live',
            'upcoming' => 'upcoming',
            default    => 'none',
        };
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function query_videos( int $paged, string $search, string $type_filter ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'vyg_videos';
        $where = '1=1';
        $params = array();
        if ( '' !== $search ) {
            $where .= ' AND (title LIKE %s OR youtube_video_id LIKE %s)';
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if ( '' !== $type_filter ) {
            $where .= ' AND content_type = %s';
            $params[] = $type_filter;
        }
        $offset = ( $paged - 1 ) * self::PER_PAGE;
        $params[] = self::PER_PAGE;
        $params[] = $offset;
        $sql = "SELECT id, youtube_video_id, title, content_type, manual_content_type, manual_content_source, manual_reason, availability_status, live_status, duration_seconds, published_at, view_count FROM {$table} WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d";
        $prepared = $wpdb->prepare( $sql, $params );
        $rows = $wpdb->get_results( $prepared, ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    private function count_videos( string $search, string $type_filter ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'vyg_videos';
        $where = '1=1';
        $params = array();
        if ( '' !== $search ) {
            $where .= ' AND (title LIKE %s OR youtube_video_id LIKE %s)';
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if ( '' !== $type_filter ) {
            $where .= ' AND content_type = %s';
            $params[] = $type_filter;
        }
        $sql = "SELECT COUNT(*) FROM {$table} WHERE $where";
        $prepared = $params ? $wpdb->prepare( $sql, $params ) : $sql;
        return (int) $wpdb->get_var( $prepared );
    }

    private function redirect_with_notice( string $notice ): void {
        $url = add_query_arg(
            array( 'page' => AdminMenu::PARENT_SLUG . '-videos', 'vyg_notice' => $notice ),
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $url );
        exit;
    }

    /**
     * @param array<int,array<string,mixed>> $videos
     */
    private function render_html( array $videos, int $paged, int $total_pages, string $search, string $type_filter ): void {
        $notice = isset( $_GET['vyg_notice'] ) ? sanitize_key( wp_unslash( $_GET['vyg_notice'] ) ) : '';
        $all_types = array(
            ''               => __( 'All types', 'vector-youtube-gallery' ),
            'standard'       => __( 'Standard', 'vector-youtube-gallery' ),
            'short_confirmed'=> __( 'Short (confirmed)', 'vector-youtube-gallery' ),
            'short_candidate'=> __( 'Short (candidate)', 'vector-youtube-gallery' ),
            'live_active'    => __( 'Live active', 'vector-youtube-gallery' ),
            'live_upcoming'  => __( 'Live upcoming', 'vector-youtube-gallery' ),
            'live_replay'    => __( 'Live replay', 'vector-youtube-gallery' ),
        );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'YouTube Gallery — Videos', 'vector-youtube-gallery' ); ?></h1>
            <?php $this->render_notice( $notice ); ?>

            <form method="get" class="vyg-videos-filter">
                <input type="hidden" name="page" value="<?php echo esc_attr( AdminMenu::PARENT_SLUG . '-videos' ); ?>" />
                <p class="search-box">
                    <label class="screen-reader-text" for="vyg-video-search"><?php esc_html_e( 'Search videos', 'vector-youtube-gallery' ); ?>:</label>
                    <input type="search" id="vyg-video-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Title or video ID', 'vector-youtube-gallery' ); ?>" />
                    <select name="content_type">
                        <?php foreach ( $all_types as $val => $label ) : ?>
                            <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $val, $type_filter ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php submit_button( __( 'Filter', 'vector-youtube-gallery' ), '', '', false, array( 'id' => 'vyg-search-submit' ) ); ?>
                </p>
            </form>

            <?php if ( 0 === count( $videos ) ) : ?>
                <p><?php echo esc_html__( 'No videos match the current filter.', 'vector-youtube-gallery' ); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Title', 'vector-youtube-gallery' ); ?></th>
                            <th><?php esc_html_e( 'Type (auto)', 'vector-youtube-gallery' ); ?></th>
                            <th><?php esc_html_e( 'Manual override', 'vector-youtube-gallery' ); ?></th>
                            <th><?php esc_html_e( 'Availability', 'vector-youtube-gallery' ); ?></th>
                            <th><?php esc_html_e( 'Live status', 'vector-youtube-gallery' ); ?></th>
                            <th><?php esc_html_e( 'Duration', 'vector-youtube-gallery' ); ?></th>
                            <th><?php esc_html_e( 'Reclassify', 'vector-youtube-gallery' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $videos as $v ) : ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html( (string) ( $v['title'] ?? '' ) ); ?></strong><br>
                                    <code style="font-size: 11px;"><?php echo esc_html( (string) ( $v['youtube_video_id'] ?? '' ) ); ?></code>
                                </td>
                                <td>
                                    <code><?php echo esc_html( (string) ( $v['content_type'] ?? '' ) ); ?></code>
                                </td>
                                <td>
                                    <?php if ( ! empty( $v['manual_content_type'] ) ) : ?>
                                        <code><?php echo esc_html( (string) $v['manual_content_type'] ); ?></code>
                                        <?php if ( ! empty( $v['manual_content_source'] ) ) : ?>
                                            <br><span style="font-size: 11px; color: #666;"><?php echo esc_html( (string) $v['manual_content_source'] ); ?></span>
                                        <?php endif; ?>
                                        <?php if ( ! empty( $v['manual_reason'] ) ) : ?>
                                            <br><em style="font-size: 11px;"><?php echo esc_html( (string) $v['manual_reason'] ); ?></em>
                                        <?php endif; ?>
                                    <?php else : ?>
                                        <em style="color: #999;">—</em>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( (string) ( $v['availability_status'] ?? '' ) ); ?></td>
                                <td><?php echo esc_html( (string) ( $v['live_status'] ?? '' ) ); ?></td>
                                <td><?php echo esc_html( $this->format_duration( (int) ( $v['duration_seconds'] ?? 0 ) ) ); ?></td>
                                <td>
                                    <form method="post" style="display:inline-block; min-width: 220px;">
                                        <?php wp_nonce_field( self::NONCE_ACTION, 'vyg_videos_nonce' ); ?>
                                        <input type="hidden" name="vyg_op" value="reclassify" />
                                        <input type="hidden" name="video_id" value="<?php echo esc_attr( (string) ( $v['id'] ?? '' ) ); ?>" />
                                        <select name="manual_content_type">
                                            <?php foreach ( $all_types as $val => $label ) : ?>
                                                <?php if ( '' === $val ) continue; ?>
                                                <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $val, (string) ( $v['manual_content_type'] ?? '' ) ); ?>>
                                                    <?php echo esc_html( $label ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                            <option value="" <?php selected( '', (string) ( $v['manual_content_type'] ?? '' ) ); ?>><?php esc_html_e( '— clear —', 'vector-youtube-gallery' ); ?></option>
                                        </select>
                                        <input type="text" name="reason" placeholder="<?php esc_attr_e( 'Reason (optional)', 'vector-youtube-gallery' ); ?>" style="width: 100%;" />
                                        <button type="submit" class="button button-small"><?php esc_html_e( 'Apply', 'vector-youtube-gallery' ); ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ( $total_pages > 1 ) : ?>
                    <div class="tablenav">
                        <div class="tablenav-pages">
                            <?php
                            $base = add_query_arg( array( 'paged' => '%#%' ), admin_url( 'admin.php?page=' . AdminMenu::PARENT_SLUG . '-videos' ) );
                            echo paginate_links( array(
                                'base'      => $base,
                                'format'    => '',
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                                'total'     => $total_pages,
                                'current'   => $paged,
                            ) );
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function format_duration( int $seconds ): string {
        if ( $seconds <= 0 ) {
            return '—';
        }
        $h = (int) ( $seconds / 3600 );
        $m = (int) ( ( $seconds % 3600 ) / 60 );
        $s = $seconds % 60;
        if ( $h > 0 ) {
            return sprintf( '%d:%02d:%02d', $h, $m, $s );
        }
        return sprintf( '%d:%02d', $m, $s );
    }

    private function render_notice( string $notice ): void {
        $messages = array(
            'reclassified' => array( __( 'Video reclassified. Manual override applied.', 'vector-youtube-gallery' ), 'success' ),
            'invalid'      => array( __( 'Invalid video id.', 'vector-youtube-gallery' ), 'error' ),
            'invalid_type' => array( __( 'Invalid content type.', 'vector-youtube-gallery' ), 'error' ),
        );
        if ( isset( $messages[ $notice ] ) ) {
            [ $msg, $cls ] = $messages[ $notice ];
            echo '<div class="notice notice-' . esc_attr( $cls ) . '"><p>' . esc_html( $msg ) . '</p></div>';
        }
    }
}