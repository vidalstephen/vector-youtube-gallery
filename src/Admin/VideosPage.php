<?php
/**
 * Videos admin page — list, search, filter, bulk edit, and reclassify indexed videos.
 *
 * @package VectorYT\Gallery\Admin
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Admin;

use VectorYT\Gallery\Logging\Logger;
use VectorYT\Gallery\Settings\SettingsRepository;

use function absint;
use function add_query_arg;
use function admin_url;
use function checked;
use function current_user_can;
use function esc_attr;
use function esc_attr_e;
use function esc_html;
use function esc_html__;
use function esc_url;
use function get_current_user_id;
use function get_option;
use function gmdate;
use function paginate_links;
use function sanitize_key;
use function sanitize_text_field;
use function selected;
use function submit_button;
use function update_option;
use function wp_die;
use function wp_json_encode;
use function wp_nonce_field;
use function wp_safe_redirect;
use function wp_unslash;
use function wp_verify_nonce;
use function __;

defined( 'ABSPATH' ) || exit;

final class VideosPage {

    private const NONCE_ACTION  = 'vyg_videos_action';
    private const PER_PAGE      = 25;
    private const FILTER_OPTION = 'vyg_videos_saved_filters';

    /** @var array<string,string> */
    private const CONTENT_TYPES = array(
        ''                => 'All types',
        'standard'        => 'Standard',
        'short_confirmed' => 'Short (confirmed)',
        'short_candidate' => 'Short (candidate)',
        'live_active'     => 'Live active',
        'live_upcoming'   => 'Live upcoming',
        'live_replay'     => 'Live replay',
    );

    /** @var array<string,string> */
    private const MANUAL_TYPES = array(
        'auto'            => 'Auto',
        'standard'        => 'Standard',
        'short_confirmed' => 'Short confirmed',
        'short_candidate' => 'Short candidate',
        'live_active'     => 'Live active',
        'live_upcoming'   => 'Live upcoming',
        'live_replay'     => 'Live replay',
        ''                => '— clear —',
    );

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly Logger $logger,
    ) {}

    public function render(): void {
        if ( ! current_user_can( AdminMenu::REQUIRED_CAP ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'vector-youtube-gallery' ) );
        }

        if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
            $this->maybe_handle_post();
        }

        $paged  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $filters = $this->collect_filters_from_request();
        $saved = $this->saved_filters();

        $videos = $this->query_videos( $paged, $filters );
        $total  = $this->count_videos( $filters );
        $pages  = (int) ceil( $total / self::PER_PAGE );

        $this->render_html( $videos, $paged, $pages, $filters, $saved );
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
            return;
        }
        if ( 'bulk' === $op ) {
            $this->handle_bulk_action();
            return;
        }
        if ( 'save_filter' === $op ) {
            $this->handle_save_filter();
            return;
        }
        if ( 'delete_filter' === $op ) {
            $this->handle_delete_filter();
            return;
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
        if ( ! $this->is_valid_manual_type( $override ) ) {
            $this->redirect_with_notice( 'invalid_type' );
            return;
        }
        $this->update_video_classification( $video_id, $override, $reason );
        $this->redirect_with_notice( 'reclassified' );
    }

    private function handle_bulk_action(): void {
        $action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
        $ids = isset( $_POST['video_ids'] ) && is_array( $_POST['video_ids'] )
            ? array_values( array_filter( array_map( 'absint', wp_unslash( $_POST['video_ids'] ) ) ) )
            : array();
        if ( array() === $ids ) {
            $this->redirect_with_notice( 'none_selected' );
            return;
        }
        $reason = isset( $_POST['bulk_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['bulk_reason'] ) ) : '';
        $manual = isset( $_POST['bulk_manual_content_type'] ) ? sanitize_text_field( wp_unslash( $_POST['bulk_manual_content_type'] ) ) : '';
        $changed = $this->apply_bulk_action( $ids, $action, $manual, $reason );
        $this->redirect_with_notice( $changed > 0 ? 'bulk_updated' : 'bulk_noop' );
    }

    /**
     * @param array<int,int> $ids
     */
    public function apply_bulk_action( array $ids, string $action, string $manual = '', string $reason = '' ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'vyg_videos';
        $changed = 0;
        foreach ( $ids as $id ) {
            $id = absint( $id );
            if ( $id <= 0 ) {
                continue;
            }
            if ( 'bulk_reclassify' === $action ) {
                if ( ! $this->is_valid_manual_type( $manual ) ) {
                    continue;
                }
                $this->update_video_classification( $id, $manual, $reason );
                ++$changed;
                continue;
            }
            $updates = match ( $action ) {
                'hide'   => array( 'is_hidden' => 1, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ),
                'unhide' => array( 'is_hidden' => 0, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ),
                'pin'    => array( 'is_pinned' => 1, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ),
                'unpin'  => array( 'is_pinned' => 0, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ),
                default  => array(),
            };
            if ( array() === $updates ) {
                continue;
            }
            $wpdb->update( $table, $updates, array( 'id' => $id ) );
            ++$changed;
        }
        if ( $changed > 0 ) {
            $this->logger->info( 'Videos bulk action applied', array(
                'action' => $action,
                'count'  => $changed,
                'admin_user_id' => get_current_user_id(),
            ) );
        }
        return $changed;
    }

    private function handle_save_filter(): void {
        $name = isset( $_POST['filter_name'] ) ? sanitize_text_field( wp_unslash( $_POST['filter_name'] ) ) : '';
        if ( '' === $name ) {
            $this->redirect_with_notice( 'invalid_filter' );
            return;
        }
        $filters = $this->collect_filters_from_array( $_POST );
        $saved = $this->saved_filters();
        $slug = sanitize_key( $name );
        if ( '' === $slug ) {
            $slug = 'filter-' . substr( hash( 'sha256', $name ), 0, 8 );
        }
        $saved[ $slug ] = array(
            'name'    => $name,
            'filters' => $filters,
        );
        update_option( self::FILTER_OPTION, $saved, false );
        $this->redirect_with_notice( 'filter_saved', $filters + array( 'saved_filter' => $slug ) );
    }

    private function handle_delete_filter(): void {
        $slug = isset( $_POST['saved_filter'] ) ? sanitize_key( wp_unslash( $_POST['saved_filter'] ) ) : '';
        $saved = $this->saved_filters();
        if ( isset( $saved[ $slug ] ) ) {
            unset( $saved[ $slug ] );
            update_option( self::FILTER_OPTION, $saved, false );
        }
        $this->redirect_with_notice( 'filter_deleted' );
    }

    /** @return array<string,array{name:string,filters:array<string,string>}> */
    private function saved_filters(): array {
        $saved = get_option( self::FILTER_OPTION, array() );
        return is_array( $saved ) ? $saved : array();
    }

    /** @return array<string,string> */
    private function collect_filters_from_request(): array {
        $filters = $this->collect_filters_from_array( $_GET );
        $saved = $this->saved_filters();
        $slug = isset( $_GET['saved_filter'] ) ? sanitize_key( wp_unslash( $_GET['saved_filter'] ) ) : '';
        if ( $slug && isset( $saved[ $slug ]['filters'] ) && is_array( $saved[ $slug ]['filters'] ) ) {
            $filters = $this->sanitize_filters( $saved[ $slug ]['filters'] );
            $filters['saved_filter'] = $slug;
        }
        return $filters;
    }

    /** @param array<string,mixed> $source @return array<string,string> */
    private function collect_filters_from_array( array $source ): array {
        return $this->sanitize_filters( array(
            's'                   => $source['s'] ?? '',
            'content_type'        => $source['content_type'] ?? '',
            'source_channel'      => $source['source_channel'] ?? '',
            'availability_status' => $source['availability_status'] ?? '',
            'live_status'         => $source['live_status'] ?? '',
            'is_pinned'           => $source['is_pinned'] ?? '',
            'is_hidden'           => $source['is_hidden'] ?? '',
            'published_after'     => $source['published_after'] ?? '',
            'published_before'    => $source['published_before'] ?? '',
            'saved_filter'        => $source['saved_filter'] ?? '',
        ) );
    }

    /** @param array<string,mixed> $filters @return array<string,string> */
    public function sanitize_filters( array $filters ): array {
        $clean = array();
        foreach ( array( 's', 'published_after', 'published_before' ) as $key ) {
            $clean[ $key ] = isset( $filters[ $key ] ) ? sanitize_text_field( wp_unslash( $filters[ $key ] ) ) : '';
        }
        foreach ( array( 'content_type', 'availability_status', 'live_status', 'is_pinned', 'is_hidden', 'saved_filter' ) as $key ) {
            $clean[ $key ] = isset( $filters[ $key ] ) ? sanitize_key( wp_unslash( $filters[ $key ] ) ) : '';
        }
        $clean['source_channel'] = isset( $filters['source_channel'] ) ? sanitize_text_field( wp_unslash( $filters['source_channel'] ) ) : '';
        if ( ! isset( self::CONTENT_TYPES[ $clean['content_type'] ] ) ) {
            $clean['content_type'] = '';
        }
        if ( ! in_array( $clean['availability_status'], array( '', 'available', 'private', 'deleted', 'embed_disabled' ), true ) ) {
            $clean['availability_status'] = '';
        }
        if ( ! in_array( $clean['live_status'], array( '', 'none', 'upcoming', 'live', 'ended' ), true ) ) {
            $clean['live_status'] = '';
        }
        foreach ( array( 'is_pinned', 'is_hidden' ) as $key ) {
            if ( ! in_array( $clean[ $key ], array( '', '0', '1' ), true ) ) {
                $clean[ $key ] = '';
            }
        }
        foreach ( array( 'published_after', 'published_before' ) as $key ) {
            if ( '' !== $clean[ $key ] && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $clean[ $key ] ) ) {
                $clean[ $key ] = '';
            }
        }
        return $clean;
    }

    private function is_valid_manual_type( string $manual ): bool {
        return in_array( $manual, array_keys( self::MANUAL_TYPES ), true );
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
        $this->renormalize_video( $video_id, $manual );
        $this->logger->info( 'Video reclassified', array(
            'video_id'      => $video_id,
            'new_manual'    => $manual,
            'old_manual'    => $existing['manual_content_type'] ?? null,
            'reason'        => $reason,
            'admin_user_id' => $user_id,
        ) );
    }

    private function renormalize_video( int $video_id, string $manual ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'vyg_videos';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $video_id ), ARRAY_A );
        if ( ! $row ) {
            return;
        }
        $resource = array(
            'id'      => $row['youtube_video_id'],
            'snippet' => array(
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
                'uploadStatus'  => $row['upload_status'],
                'privacyStatus' => $row['privacy_status'],
                'embeddable'    => (bool) $row['embeddable'],
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
        $normalizer = \VectorYT\Gallery\Normalize\VideoNormalizer::with_defaults();
        $normalized = $normalizer->normalize(
            $resource,
            array( 'manual_content_type' => $manual ),
            (int) $this->settings->get( 'shorts_max_duration_seconds', 60 ),
            (int) $this->settings->get( 'short_candidate_max_duration', 180 ),
        );
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

    /** @param array<string,string> $filters @return array{0:string,1:array<int,mixed>} */
    private function build_where( array $filters ): array {
        global $wpdb;
        $where = '1=1';
        $params = array();
        if ( '' !== $filters['s'] ) {
            $where .= ' AND (title LIKE %s OR youtube_video_id LIKE %s)';
            $like = '%' . $wpdb->esc_like( $filters['s'] ) . '%';
            $params[] = $like;
            $params[] = $like;
        }
        foreach ( array( 'content_type', 'availability_status', 'live_status' ) as $key ) {
            if ( '' !== $filters[ $key ] ) {
                $where .= " AND {$key} = %s";
                $params[] = $filters[ $key ];
            }
        }
        if ( '' !== $filters['source_channel'] ) {
            $where .= ' AND youtube_channel_id = %s';
            $params[] = $filters['source_channel'];
        }
        foreach ( array( 'is_pinned', 'is_hidden' ) as $key ) {
            if ( '' !== $filters[ $key ] ) {
                $where .= " AND {$key} = %d";
                $params[] = (int) $filters[ $key ];
            }
        }
        if ( '' !== $filters['published_after'] ) {
            $where .= ' AND published_at >= %s';
            $params[] = $filters['published_after'] . ' 00:00:00';
        }
        if ( '' !== $filters['published_before'] ) {
            $where .= ' AND published_at <= %s';
            $params[] = $filters['published_before'] . ' 23:59:59';
        }
        return array( $where, $params );
    }

    /** @param array<string,string> $filters @return array<int,array<string,mixed>> */
    private function query_videos( int $paged, array $filters ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'vyg_videos';
        [ $where, $params ] = $this->build_where( $filters );
        $offset = ( $paged - 1 ) * self::PER_PAGE;
        $params[] = self::PER_PAGE;
        $params[] = $offset;
        $sql = "SELECT id, youtube_video_id, title, youtube_channel_id, content_type, manual_content_type, manual_content_source, manual_reason, availability_status, live_status, duration_seconds, published_at, view_count, is_pinned, is_hidden FROM {$table} WHERE $where ORDER BY is_pinned DESC, id DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    /** @param array<string,string> $filters */
    private function count_videos( array $filters ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'vyg_videos';
        [ $where, $params ] = $this->build_where( $filters );
        $sql = "SELECT COUNT(*) FROM {$table} WHERE $where";
        return (int) $wpdb->get_var( $params ? $wpdb->prepare( $sql, $params ) : $sql );
    }

    /** @param array<string,string> $args */
    private function redirect_with_notice( string $notice, array $args = array() ): void {
        $url = add_query_arg(
            array_merge( array( 'page' => AdminMenu::PARENT_SLUG . '-videos', 'vyg_notice' => $notice ), $args ),
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $url );
        exit;
    }

    /** @param array<int,array<string,mixed>> $videos @param array<string,string> $filters @param array<string,array{name:string,filters:array<string,string>}> $saved */
    private function render_html( array $videos, int $paged, int $total_pages, array $filters, array $saved ): void {
        $notice = isset( $_GET['vyg_notice'] ) ? sanitize_key( wp_unslash( $_GET['vyg_notice'] ) ) : '';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'YouTube Gallery — Videos', 'vector-youtube-gallery' ); ?></h1>
            <?php $this->render_notice( $notice ); ?>

            <?php $this->render_filter_form( $filters, $saved ); ?>

            <?php if ( 0 === count( $videos ) ) : ?>
                <p><?php echo esc_html__( 'No videos match the current filter.', 'vector-youtube-gallery' ); ?></p>
            <?php else : ?>
                <form method="post">
                    <?php wp_nonce_field( self::NONCE_ACTION, 'vyg_videos_nonce' ); ?>
                    <input type="hidden" name="vyg_op" value="bulk" />
                    <?php $this->render_bulk_controls(); ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <td class="manage-column column-cb check-column"><input type="checkbox" onclick="document.querySelectorAll('.vyg-video-cb').forEach(cb=>cb.checked=this.checked);" /></td>
                                <th><?php esc_html_e( 'Title', 'vector-youtube-gallery' ); ?></th>
                                <th><?php esc_html_e( 'Type (auto)', 'vector-youtube-gallery' ); ?></th>
                                <th><?php esc_html_e( 'Manual override', 'vector-youtube-gallery' ); ?></th>
                                <th><?php esc_html_e( 'Availability', 'vector-youtube-gallery' ); ?></th>
                                <th><?php esc_html_e( 'Live status', 'vector-youtube-gallery' ); ?></th>
                                <th><?php esc_html_e( 'Pinned/hidden', 'vector-youtube-gallery' ); ?></th>
                                <th><?php esc_html_e( 'Duration', 'vector-youtube-gallery' ); ?></th>
                                <th><?php esc_html_e( 'Reclassify', 'vector-youtube-gallery' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $videos as $v ) : ?>
                                <tr>
                                    <th scope="row" class="check-column"><input class="vyg-video-cb" type="checkbox" name="video_ids[]" value="<?php echo esc_attr( (string) ( $v['id'] ?? '' ) ); ?>" /></th>
                                    <td><strong><?php echo esc_html( (string) ( $v['title'] ?? '' ) ); ?></strong><br><code style="font-size: 11px;"><?php echo esc_html( (string) ( $v['youtube_video_id'] ?? '' ) ); ?></code></td>
                                    <td><code><?php echo esc_html( (string) ( $v['content_type'] ?? '' ) ); ?></code></td>
                                    <td><?php $this->render_manual_cell( $v ); ?></td>
                                    <td><?php echo esc_html( (string) ( $v['availability_status'] ?? '' ) ); ?></td>
                                    <td><?php echo esc_html( (string) ( $v['live_status'] ?? '' ) ); ?></td>
                                    <td><?php echo ( ! empty( $v['is_pinned'] ) ? esc_html__( 'Pinned', 'vector-youtube-gallery' ) : '—' ); ?> / <?php echo ( ! empty( $v['is_hidden'] ) ? esc_html__( 'Hidden', 'vector-youtube-gallery' ) : '—' ); ?></td>
                                    <td><?php echo esc_html( $this->format_duration( (int) ( $v['duration_seconds'] ?? 0 ) ) ); ?></td>
                                    <td><?php $this->render_reclassify_form( $v ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </form>
                <?php $this->render_pagination( $paged, $total_pages, $filters ); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /** @param array<string,string> $filters @param array<string,array{name:string,filters:array<string,string>}> $saved */
    private function render_filter_form( array $filters, array $saved ): void {
        ?>
        <form method="get" class="vyg-videos-filter" style="margin-bottom:12px;">
            <input type="hidden" name="page" value="<?php echo esc_attr( AdminMenu::PARENT_SLUG . '-videos' ); ?>" />
            <p class="search-box" style="float:none;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input type="search" name="s" value="<?php echo esc_attr( $filters['s'] ); ?>" placeholder="<?php esc_attr_e( 'Title or video ID', 'vector-youtube-gallery' ); ?>" />
                <?php $this->select_field( 'content_type', self::CONTENT_TYPES, $filters['content_type'] ); ?>
                <input type="text" name="source_channel" value="<?php echo esc_attr( $filters['source_channel'] ); ?>" placeholder="<?php esc_attr_e( 'Channel/source ID', 'vector-youtube-gallery' ); ?>" style="width:160px;" />
                <?php $this->select_field( 'availability_status', array( '' => 'All availability', 'available' => 'Available', 'private' => 'Private', 'deleted' => 'Deleted', 'embed_disabled' => 'Embed disabled' ), $filters['availability_status'] ); ?>
                <?php $this->select_field( 'live_status', array( '' => 'All live states', 'none' => 'Not live', 'upcoming' => 'Upcoming', 'live' => 'Live', 'ended' => 'Ended' ), $filters['live_status'] ); ?>
                <?php $this->select_field( 'is_pinned', array( '' => 'Pinned?', '1' => 'Pinned', '0' => 'Not pinned' ), $filters['is_pinned'] ); ?>
                <?php $this->select_field( 'is_hidden', array( '' => 'Hidden?', '1' => 'Hidden', '0' => 'Visible' ), $filters['is_hidden'] ); ?>
                <input type="date" name="published_after" value="<?php echo esc_attr( $filters['published_after'] ); ?>" />
                <input type="date" name="published_before" value="<?php echo esc_attr( $filters['published_before'] ); ?>" />
                <?php submit_button( __( 'Filter', 'vector-youtube-gallery' ), '', '', false ); ?>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminMenu::PARENT_SLUG . '-videos' ) ); ?>"><?php esc_html_e( 'Reset', 'vector-youtube-gallery' ); ?></a>
            </p>
        </form>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:8px 0 16px;">
            <form method="get" style="display:flex;gap:8px;align-items:center;">
                <input type="hidden" name="page" value="<?php echo esc_attr( AdminMenu::PARENT_SLUG . '-videos' ); ?>" />
                <select name="saved_filter">
                    <option value=""><?php esc_html_e( 'Saved filters…', 'vector-youtube-gallery' ); ?></option>
                    <?php foreach ( $saved as $slug => $entry ) : ?>
                        <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, $filters['saved_filter'] ); ?>><?php echo esc_html( (string) ( $entry['name'] ?? $slug ) ); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php submit_button( __( 'Apply saved', 'vector-youtube-gallery' ), '', '', false ); ?>
            </form>
            <form method="post" style="display:flex;gap:8px;align-items:center;">
                <?php wp_nonce_field( self::NONCE_ACTION, 'vyg_videos_nonce' ); ?>
                <input type="hidden" name="vyg_op" value="save_filter" />
                <?php foreach ( $filters as $key => $value ) : if ( 'saved_filter' === $key ) { continue; } ?>
                    <input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>" />
                <?php endforeach; ?>
                <input type="text" name="filter_name" placeholder="<?php esc_attr_e( 'Save current filter as…', 'vector-youtube-gallery' ); ?>" />
                <?php submit_button( __( 'Save filter', 'vector-youtube-gallery' ), '', '', false ); ?>
            </form>
            <?php if ( '' !== $filters['saved_filter'] ) : ?>
                <form method="post">
                    <?php wp_nonce_field( self::NONCE_ACTION, 'vyg_videos_nonce' ); ?>
                    <input type="hidden" name="vyg_op" value="delete_filter" />
                    <input type="hidden" name="saved_filter" value="<?php echo esc_attr( $filters['saved_filter'] ); ?>" />
                    <?php submit_button( __( 'Delete saved', 'vector-youtube-gallery' ), 'delete', '', false ); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /** @param array<string,string> $options */
    private function select_field( string $name, array $options, string $selected ): void {
        echo '<select name="' . esc_attr( $name ) . '">';
        foreach ( $options as $value => $label ) {
            echo '<option value="' . esc_attr( (string) $value ) . '" ' . selected( (string) $value, $selected, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
    }

    private function render_bulk_controls(): void {
        ?>
        <div style="margin:12px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <select name="bulk_action">
                <option value="hide"><?php esc_html_e( 'Hide', 'vector-youtube-gallery' ); ?></option>
                <option value="unhide"><?php esc_html_e( 'Unhide', 'vector-youtube-gallery' ); ?></option>
                <option value="pin"><?php esc_html_e( 'Pin', 'vector-youtube-gallery' ); ?></option>
                <option value="unpin"><?php esc_html_e( 'Unpin', 'vector-youtube-gallery' ); ?></option>
                <option value="bulk_reclassify"><?php esc_html_e( 'Reclassify selected', 'vector-youtube-gallery' ); ?></option>
            </select>
            <?php $this->select_field( 'bulk_manual_content_type', self::MANUAL_TYPES, 'auto' ); ?>
            <input type="text" name="bulk_reason" placeholder="<?php esc_attr_e( 'Reason (optional)', 'vector-youtube-gallery' ); ?>" style="min-width:260px;" />
            <?php submit_button( __( 'Apply bulk action', 'vector-youtube-gallery' ), '', '', false ); ?>
        </div>
        <?php
    }

    /** @param array<string,mixed> $v */
    private function render_manual_cell( array $v ): void {
        if ( ! empty( $v['manual_content_type'] ) ) {
            echo '<code>' . esc_html( (string) $v['manual_content_type'] ) . '</code>';
            if ( ! empty( $v['manual_content_source'] ) ) {
                echo '<br><span style="font-size:11px;color:#666;">' . esc_html( (string) $v['manual_content_source'] ) . '</span>';
            }
            if ( ! empty( $v['manual_reason'] ) ) {
                echo '<br><em style="font-size:11px;">' . esc_html( (string) $v['manual_reason'] ) . '</em>';
            }
            return;
        }
        echo '<em style="color:#999;">—</em>';
    }

    /** @param array<string,mixed> $v */
    private function render_reclassify_form( array $v ): void {
        ?>
        <form method="post" style="display:inline-block; min-width: 220px;">
            <?php wp_nonce_field( self::NONCE_ACTION, 'vyg_videos_nonce' ); ?>
            <input type="hidden" name="vyg_op" value="reclassify" />
            <input type="hidden" name="video_id" value="<?php echo esc_attr( (string) ( $v['id'] ?? '' ) ); ?>" />
            <?php $this->select_field( 'manual_content_type', self::MANUAL_TYPES, (string) ( $v['manual_content_type'] ?? '' ) ); ?>
            <input type="text" name="reason" placeholder="<?php esc_attr_e( 'Reason (optional)', 'vector-youtube-gallery' ); ?>" style="width: 100%;" />
            <button type="submit" class="button button-small"><?php esc_html_e( 'Apply', 'vector-youtube-gallery' ); ?></button>
        </form>
        <?php
    }

    /** @param array<string,string> $filters */
    private function render_pagination( int $paged, int $total_pages, array $filters ): void {
        if ( $total_pages <= 1 ) {
            return;
        }
        $query = array_filter( $filters, static fn( $v ): bool => '' !== $v );
        $query['page'] = AdminMenu::PARENT_SLUG . '-videos';
        $query['paged'] = '%#%';
        echo '<div class="tablenav"><div class="tablenav-pages">';
        echo paginate_links( array(
            'base'      => add_query_arg( $query, admin_url( 'admin.php' ) ),
            'format'    => '',
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
            'total'     => $total_pages,
            'current'   => $paged,
        ) );
        echo '</div></div>';
    }

    private function format_duration( int $seconds ): string {
        if ( $seconds <= 0 ) {
            return '—';
        }
        $h = (int) ( $seconds / 3600 );
        $m = (int) ( ( $seconds % 3600 ) / 60 );
        $s = $seconds % 60;
        return $h > 0 ? sprintf( '%d:%02d:%02d', $h, $m, $s ) : sprintf( '%d:%02d', $m, $s );
    }

    private function render_notice( string $notice ): void {
        $messages = array(
            'reclassified'  => array( __( 'Video reclassified. Manual override applied.', 'vector-youtube-gallery' ), 'success' ),
            'bulk_updated'  => array( __( 'Bulk action applied.', 'vector-youtube-gallery' ), 'success' ),
            'bulk_noop'     => array( __( 'No videos were changed by that bulk action.', 'vector-youtube-gallery' ), 'warning' ),
            'filter_saved'  => array( __( 'Saved filter created.', 'vector-youtube-gallery' ), 'success' ),
            'filter_deleted'=> array( __( 'Saved filter deleted.', 'vector-youtube-gallery' ), 'success' ),
            'none_selected' => array( __( 'Select at least one video.', 'vector-youtube-gallery' ), 'error' ),
            'invalid'       => array( __( 'Invalid video id.', 'vector-youtube-gallery' ), 'error' ),
            'invalid_type'  => array( __( 'Invalid content type.', 'vector-youtube-gallery' ), 'error' ),
            'invalid_filter'=> array( __( 'Enter a name before saving a filter.', 'vector-youtube-gallery' ), 'error' ),
        );
        if ( isset( $messages[ $notice ] ) ) {
            [ $msg, $cls ] = $messages[ $notice ];
            echo '<div class="notice notice-' . esc_attr( $cls ) . '"><p>' . esc_html( $msg ) . '</p></div>';
        }
    }
}
