<?php
/**
 * FeedsPage — admin UI for Phase 6 Feed Builder + Phase 8 multi-source.
 *
 * Three views:
 *  - List view (default): table of saved feeds with edit/duplicate/delete actions.
 *  - Edit view (?action=edit&id=N): full form with multi-source configuration fields.
 *
 * Phase 8.3 additions to the edit form:
 *  - Repeater for `sources[]` (add/remove/reorder via JS + remove buttons).
 *  - Manual video IDs textarea (one ID per line).
 *  - Exclude video IDs textarea (one ID per line).
 *  - Per-source badge column on the list view.
 *
 * @package VectorYT\Gallery\Admin
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Admin;

use VectorYT\Gallery\Repository\FeedRepository;
use VectorYT\Gallery\Repository\SourceRepository;
use VectorYT\Gallery\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class FeedsPage {

    private const NONCE_ACTION = 'vyg_feed_action';
    private const NONCE_FIELD  = '_vyg_feed_nonce';

    public function __construct(
        private readonly FeedRepository $feeds,
        private readonly SourceRepository $sources,
        private readonly Logger $logger,
    ) {}

    public function render(): void {
        if ( ! current_user_can( AdminMenu::REQUIRED_CAP ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'vector-youtube-gallery' ) );
        }

        // WP 7.0 update banner can echo HTML before our handler runs, breaking
        // wp_safe_redirect() with "headers already sent". Capture any stray
        // output so the redirect lands cleanly.
        ob_start();

        $action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
        $id     = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;

        // Handle POST first (before any GET view dispatch).
        if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['vyg_feed_op'] ) ) {
            $this->handle_post();
            return;
        }

        if ( 'edit' === $action && $id > 0 ) {
            $this->render_edit( $id );
            ob_end_flush();
            return;
        }

        $this->render_list();
        ob_end_flush();
    }

    private function handle_post(): void {
        if ( ! current_user_can( AdminMenu::REQUIRED_CAP ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'vector-youtube-gallery' ) );
        }
        $nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
            wp_die( esc_html__( 'Nonce check failed.', 'vector-youtube-gallery' ) );
        }

        $op     = sanitize_key( wp_unslash( $_POST['vyg_feed_op'] ) );
        $status = array( 'ok' => false, 'message' => '' );

        if ( 'create' === $op || 'update' === $op ) {
            $data = $this->collect_posted();
            if ( 'create' === $op ) {
                $id = $this->feeds->create( $data );
                $status = array( 'ok' => $id > 0, 'message' => $id > 0 ? __( 'Feed created.', 'vector-youtube-gallery' ) : __( 'Failed to create feed.', 'vector-youtube-gallery' ) );
                if ( $id > 0 ) {
                    wp_safe_redirect( add_query_arg( array( 'page' => AdminMenu::PARENT_SLUG . '-feeds', 'action' => 'edit', 'id' => $id, 'vyg_msg' => rawurlencode( $status['message'] ) ), admin_url( 'admin.php' ) ) );
                    exit;
                }
            } else {
                $id = isset( $_POST['feed_id'] ) ? absint( wp_unslash( $_POST['feed_id'] ) ) : 0;
                if ( $id <= 0 ) {
                    $status['message'] = __( 'Invalid feed id.', 'vector-youtube-gallery' );
                } else {
                    $ok = $this->feeds->update( $id, $data );
                    $status = array( 'ok' => $ok, 'message' => $ok ? __( 'Feed updated.', 'vector-youtube-gallery' ) : __( 'Update failed.', 'vector-youtube-gallery' ) );
                }
            }
        } elseif ( 'delete' === $op ) {
            $id = isset( $_POST['feed_id'] ) ? absint( wp_unslash( $_POST['feed_id'] ) ) : 0;
            if ( $id <= 0 ) {
                $status['message'] = __( 'Invalid feed id.', 'vector-youtube-gallery' ) ;
            } else {
                $ok = $this->feeds->delete( $id );
                $status = array( 'ok' => $ok, 'message' => $ok ? __( 'Feed deleted.', 'vector-youtube-gallery' ) : __( 'Delete failed.', 'vector-youtube-gallery' ) );
            }
        } elseif ( 'duplicate' === $op ) {
            $id = isset( $_POST['feed_id'] ) ? absint( wp_unslash( $_POST['feed_id'] ) ) : 0;
            $existing = $id > 0 ? $this->feeds->find( $id ) : null;
            if ( ! $existing ) {
                $status['message'] = __( 'Source feed not found.', 'vector-youtube-gallery' );
            } else {
                unset( $existing['id'], $existing['feed_uuid'] );
                $existing['name']   = $existing['name'] . ' (copy)';
                $existing['status'] = 'draft';
                $new_id = $this->feeds->create( $existing );
                $status = array( 'ok' => $new_id > 0, 'message' => $new_id > 0 ? __( 'Feed duplicated.', 'vector-youtube-gallery' ) : __( 'Duplicate failed.', 'vector-youtube-gallery' ) );
            }
        }

        $redirect = remove_query_arg( array( 'action', 'id', 'vyg_msg' ) );
        $redirect = add_query_arg( 'vyg_msg', rawurlencode( $status['message'] ), $redirect );
        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * @return array<string,mixed>
     */
    private function collect_posted(): array {
        $sources = array();
        if ( isset( $_POST['sources'] ) && is_array( $_POST['sources'] ) ) {
            foreach ( $_POST['sources'] as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                $uuid = isset( $row['source_uuid'] ) ? sanitize_text_field( wp_unslash( (string) $row['source_uuid'] ) ) : '';
                if ( '' === $uuid ) {
                    continue;
                }
                $weight = isset( $row['weight'] ) ? (float) $row['weight'] : 1.0;
                if ( $weight < 0.0 ) {
                    $weight = 0.0;
                } elseif ( $weight > 10.0 ) {
                    $weight = 10.0;
                }
                $sources[] = array(
                    'source_uuid' => $uuid,
                    'weight'      => $weight,
                    'pinned'      => ! empty( $row['pinned'] ),
                    'label'       => isset( $row['label'] ) ? sanitize_text_field( wp_unslash( (string) $row['label'] ) ) : '',
                );
            }
        }

        // Legacy single-source form: source_uuid (no sources[] submitted).
        if ( empty( $sources ) && ! empty( $_POST['source_uuid'] ) ) {
            $legacy = sanitize_text_field( wp_unslash( (string) $_POST['source_uuid'] ) );
            if ( '' !== $legacy ) {
                $sources[] = array(
                    'source_uuid' => $legacy,
                    'weight'      => 1.0,
                    'pinned'      => false,
                    'label'       => '',
                );
            }
        }

        $manual_ids = $this->parse_id_lines( $_POST['manual_video_ids'] ?? '' );
        $exclude_ids = $this->parse_id_lines( $_POST['exclude_video_ids'] ?? '' );

        $source_config = array(
            'sources'           => $sources,
            'manual_video_ids'  => $manual_ids,
            'exclude_video_ids' => $exclude_ids,
            'include_query'     => 'any',
        );

        $display = array(
            'columns'    => isset( $_POST['columns'] )    ? max( 1, min( 6, absint( wp_unslash( $_POST['columns'] ) ) ) ) : 3,
            'per_page'   => isset( $_POST['per_page'] )   ? max( 1, min( 100, absint( wp_unslash( $_POST['per_page'] ) ) ) ) : 12,
            'lightbox'   => ! empty( $_POST['lightbox'] ),
            'load_more'  => ! empty( $_POST['load_more'] ),
            'pagination' => isset( $_POST['pagination'] ) ? sanitize_key( wp_unslash( $_POST['pagination'] ) ) : 'none',
            'player_mode'=> isset( $_POST['player_mode'] ) ? sanitize_key( wp_unslash( $_POST['player_mode'] ) ) : 'iframe',
        );

        $filter = array(
            'content_type'  => isset( $_POST['content_type'] ) ? sanitize_text_field( wp_unslash( $_POST['content_type'] ) ) : '',
            'exclude_shorts'=> ! empty( $_POST['exclude_shorts'] ),
            'shorts_policy' => isset( $_POST['shorts_policy'] ) ? sanitize_key( wp_unslash( $_POST['shorts_policy'] ) ) : 'include',
            'availability'  => isset( $_POST['availability'] ) ? sanitize_text_field( wp_unslash( $_POST['availability'] ) ) : 'available',
        );

        $sort = array(
            'orderby' => isset( $_POST['orderby'] ) ? sanitize_key( wp_unslash( $_POST['orderby'] ) ) : 'published_at',
            'order'   => isset( $_POST['order'] )   ? sanitize_key( wp_unslash( $_POST['order'] ) )   : 'DESC',
        );

        $custom_css = isset( $_POST['custom_css'] ) ? (string) wp_unslash( $_POST['custom_css'] ) : '';

        return array(
            'name'                  => isset( $_POST['feed_name'] ) ? sanitize_text_field( wp_unslash( $_POST['feed_name'] ) ) : '',
            'feed_type'             => isset( $_POST['feed_type'] ) ? sanitize_key( wp_unslash( $_POST['feed_type'] ) ) : 'source',
            'layout'                => isset( $_POST['layout'] ) ? sanitize_key( wp_unslash( $_POST['layout'] ) ) : 'grid',
            'status'                => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'draft',
            'source_config_json'    => $source_config,
            'display_config_json'   => $display,
            'filter_config_json'    => $filter,
            'sort_config_json'      => $sort,
            'custom_css'            => $custom_css,
        );
    }

    /**
     * Parse a newline-separated list of YouTube video IDs.
     *
     * @param mixed $raw
     * @return array<int,string>
     */
    private function parse_id_lines( $raw ): array {
        if ( ! is_string( $raw ) ) {
            return array();
        }
        $out = array();
        foreach ( preg_split( '/[\r\n]+/', $raw ) ?: array() as $line ) {
            $id = trim( (string) $line );
            if ( '' === $id ) {
                continue;
            }
            if ( preg_match( '/^[A-Za-z0-9_-]{1,32}$/', $id ) ) {
                $out[] = $id;
            }
        }
        return array_values( array_unique( $out ) );
    }

    private function render_list(): void {
        $feeds  = $this->feeds->list();
        $msg    = isset( $_GET['vyg_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['vyg_msg'] ) ) : '';
        $add_url = add_query_arg( array( 'page' => AdminMenu::PARENT_SLUG . '-feeds', 'action' => 'edit', 'id' => 0 ), admin_url( 'admin.php' ) );

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php echo esc_html__( 'YouTube Gallery — Feeds', 'vector-youtube-gallery' ); ?></h1>
            <a href="<?php echo esc_url( $add_url ); ?>" class="page-title-action"><?php echo esc_html__( 'Add New Feed', 'vector-youtube-gallery' ); ?></a>
            <hr class="wp-header-end" />

            <?php if ( $msg ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
            <?php endif; ?>

            <?php if ( empty( $feeds ) ) : ?>
                <p><em><?php echo esc_html__( 'No feeds yet. Click "Add New Feed" to create one.', 'vector-youtube-gallery' ); ?></em></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__( 'Name', 'vector-youtube-gallery' ); ?></th>
                            <th><?php echo esc_html__( 'Type', 'vector-youtube-gallery' ); ?></th>
                            <th><?php echo esc_html__( 'Layout', 'vector-youtube-gallery' ); ?></th>
                            <th><?php echo esc_html__( 'Status', 'vector-youtube-gallery' ); ?></th>
                            <th><?php echo esc_html__( 'Sources', 'vector-youtube-gallery' ); ?></th>
                            <th><?php echo esc_html__( 'UUID', 'vector-youtube-gallery' ); ?></th>
                            <th><?php echo esc_html__( 'Shortcode', 'vector-youtube-gallery' ); ?></th>
                            <th><?php echo esc_html__( 'Actions', 'vector-youtube-gallery' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $feeds as $f ) :
                            $edit_url = add_query_arg( array( 'page' => AdminMenu::PARENT_SLUG . '-feeds', 'action' => 'edit', 'id' => (int) $f['id'] ), admin_url( 'admin.php' ) );
                            $shortcode = '[youtube_feed feed_uuid="' . esc_attr( (string) $f['feed_uuid'] ) . '"]';
                            $f_config = FeedRepository::decode_config( $f );
                            $f_sources = isset( $f_config['source']['sources'] ) ? $f_config['source']['sources'] : array();
                            $f_manual = isset( $f_config['source']['manual_video_ids'] ) ? count( $f_config['source']['manual_video_ids'] ) : 0;
                            $f_excluded = isset( $f_config['source']['exclude_video_ids'] ) ? count( $f_config['source']['exclude_video_ids'] ) : 0;
                            $f_pinned = 0;
                            foreach ( $f_sources as $f_entry ) {
                                if ( ! empty( $f_entry['pinned'] ) ) {
                                    $f_pinned++;
                                }
                            }
                            $source_count = count( $f_sources );
                        ?>
                            <tr>
                                <td><strong><?php echo esc_html( (string) ( $f['name'] ?? '(unnamed)' ) ); ?></strong></td>
                                <td><?php echo esc_html( (string) ( $f['feed_type'] ?? '' ) ); ?></td>
                                <td><?php echo esc_html( (string) ( $f['layout'] ?? '' ) ); ?></td>
                                <td><span class="vyg-status-badge vyg-status-badge--<?php echo esc_attr( (string) ( $f['status'] ?? 'unknown' ) ); ?>"><?php echo esc_html( (string) ( $f['status'] ?? '' ) ); ?></span></td>
                                <td>
                                    <?php if ( $source_count > 0 ) : ?>
                                        <span class="vyg-source-count" style="background:#dff5e1; color:#1d6b2c; padding:2px 8px; border-radius:10px; font-size:0.85em; white-space:nowrap;" title="<?php echo esc_attr( sprintf( _n( '%d source', '%d sources', $source_count, 'vector-youtube-gallery' ), $source_count ) ); ?>">
                                            <?php echo esc_html( sprintf( _n( '%d source', '%d sources', $source_count, 'vector-youtube-gallery' ), $source_count ) ); ?>
                                        </span>
                                    <?php else : ?>
                                        <span class="vyg-source-count vyg-source-count--empty" style="background:#f3f3f3; color:#777; padding:2px 8px; border-radius:10px; font-size:0.85em;"><?php echo esc_html__( 'no sources', 'vector-youtube-gallery' ); ?></span>
                                    <?php endif; ?>
                                    <?php if ( $f_manual > 0 ) : ?>
                                        <span class="vyg-source-badge" style="background:#fff3cd; color:#7a5d00; padding:2px 8px; border-radius:10px; font-size:0.85em; margin-left:0.25rem;" title="<?php echo esc_attr( sprintf( _n( '%d manual video ID', '%d manual video IDs', $f_manual, 'vector-youtube-gallery' ), $f_manual ) ); ?>">
                                            + <?php echo (int) $f_manual; ?> <?php echo esc_html__( 'manual', 'vector-youtube-gallery' ); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ( $f_excluded > 0 ) : ?>
                                        <span class="vyg-source-badge" style="background:#fde2e1; color:#7a1f1d; padding:2px 8px; border-radius:10px; font-size:0.85em; margin-left:0.25rem;" title="<?php echo esc_attr( sprintf( _n( '%d excluded video ID', '%d excluded video IDs', $f_excluded, 'vector-youtube-gallery' ), $f_excluded ) ); ?>">
                                            − <?php echo (int) $f_excluded; ?> <?php echo esc_html__( 'excluded', 'vector-youtube-gallery' ); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ( $f_pinned > 0 ) : ?>
                                        <span class="vyg-source-badge" style="background:#e3e3ff; color:#2a2a7a; padding:2px 8px; border-radius:10px; font-size:0.85em; margin-left:0.25rem;" title="<?php echo esc_attr( sprintf( _n( '%d pinned source', '%d pinned sources', $f_pinned, 'vector-youtube-gallery' ), $f_pinned ) ); ?>">
                                            ★ <?php echo (int) $f_pinned; ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><code style="font-size: 0.85em;"><?php echo esc_html( (string) $f['feed_uuid'] ); ?></code></td>
                                <td><input type="text" readonly value="<?php echo esc_attr( $shortcode ); ?>" style="width: 100%; font-family: monospace; font-size: 0.85em;" onclick="this.select();" /></td>
                                <td>
                                    <a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small"><?php echo esc_html__( 'Edit', 'vector-youtube-gallery' ); ?></a>
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                                        <input type="hidden" name="vyg_feed_op" value="duplicate" />
                                        <input type="hidden" name="feed_id" value="<?php echo (int) $f['id']; ?>" />
                                        <button type="submit" class="button button-small"><?php echo esc_html__( 'Duplicate', 'vector-youtube-gallery' ); ?></button>
                                    </form>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this feed?', 'vector-youtube-gallery' ) ); ?>');">
                                        <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                                        <input type="hidden" name="vyg_feed_op" value="delete" />
                                        <input type="hidden" name="feed_id" value="<?php echo (int) $f['id']; ?>" />
                                        <button type="submit" class="button button-small button-link-delete"><?php echo esc_html__( 'Delete', 'vector-youtube-gallery' ); ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_edit( int $id ): void {
        $is_new = ( $id <= 0 );
        $feed = $is_new ? $this->empty_feed() : $this->feeds->find( $id );
        if ( ! $is_new && ! $feed ) {
            echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'Feed not found.', 'vector-youtube-gallery' ) . '</p></div></div>';
            return;
        }

        $config = FeedRepository::decode_config( $feed );
        $display = wp_parse_args( $config['display'], array(
            'columns' => 3, 'per_page' => 12, 'lightbox' => true, 'load_more' => true,
            'pagination' => 'none', 'player_mode' => 'iframe',
        ) );
        $filter = wp_parse_args( $config['filter'], array(
            'content_type' => '', 'exclude_shorts' => false, 'shorts_policy' => 'include', 'availability' => 'available',
        ) );
        $sort = wp_parse_args( $config['sort'], array( 'orderby' => 'published_at', 'order' => 'DESC' ) );

        $sources = $this->sources->list();
        $source_index = array();
        foreach ( $sources as $s ) {
            $uuid = (string) ( $s['source_uuid'] ?? '' );
            if ( '' !== $uuid ) {
                $source_index[ $uuid ] = $s;
            }
        }

        $feed_sources = isset( $config['source']['sources'] ) ? $config['source']['sources'] : array();
        $manual_ids   = isset( $config['source']['manual_video_ids'] ) ? $config['source']['manual_video_ids'] : array();
        $exclude_ids  = isset( $config['source']['exclude_video_ids'] ) ? $config['source']['exclude_video_ids'] : array();

        ?>
        <div class="wrap">
            <h1><?php echo $is_new ? esc_html__( 'New Feed', 'vector-youtube-gallery' ) : esc_html__( 'Edit Feed', 'vector-youtube-gallery' ); ?></h1>

            <form method="post">
                <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                <input type="hidden" name="vyg_feed_op" value="<?php echo $is_new ? 'create' : 'update'; ?>" />
                <?php if ( ! $is_new ) : ?>
                    <input type="hidden" name="feed_id" value="<?php echo (int) $feed['id']; ?>" />
                <?php endif; ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="feed_name"><?php echo esc_html__( 'Feed name', 'vector-youtube-gallery' ); ?></label></th>
                        <td><input name="feed_name" id="feed_name" type="text" class="regular-text" value="<?php echo esc_attr( (string) ( $feed['name'] ?? '' ) ); ?>" required /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="status"><?php echo esc_html__( 'Status', 'vector-youtube-gallery' ); ?></label></th>
                        <td>
                            <select name="status" id="status">
                                <?php foreach ( array( 'draft', 'published', 'archived' ) as $st ) : ?>
                                    <option value="<?php echo esc_attr( $st ); ?>" <?php selected( (string) ( $feed['status'] ?? 'draft' ), $st ); ?>><?php echo esc_html( ucfirst( $st ) ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="feed_type"><?php echo esc_html__( 'Feed type', 'vector-youtube-gallery' ); ?></label></th>
                        <td>
                            <select name="feed_type" id="feed_type">
                                <option value="source" <?php selected( (string) ( $feed['feed_type'] ?? 'source' ), 'source' ); ?>><?php echo esc_html__( 'Source-based', 'vector-youtube-gallery' ); ?></option>
                                <option value="manual" <?php selected( (string) ( $feed['feed_type'] ?? 'source' ), 'manual' ); ?> disabled><?php echo esc_html__( 'Manual (curated — coming soon)', 'vector-youtube-gallery' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Sources', 'vector-youtube-gallery' ); ?></th>
                        <td>
                            <p class="description"><?php echo esc_html__( 'Add one or more sources. Drag to reorder. Pinned sources always appear first in the rendered feed.', 'vector-youtube-gallery' ); ?></p>
                            <div id="vyg-feed-sources" data-next-index="<?php echo (int) count( $feed_sources ); ?>">
                                <?php foreach ( $feed_sources as $i => $entry ) :
                                    $entry_uuid = (string) ( $entry['source_uuid'] ?? '' );
                                    $entry_label = (string) ( $entry['label'] ?? '' );
                                    $entry_pinned = ! empty( $entry['pinned'] );
                                    $entry_weight = isset( $entry['weight'] ) ? (float) $entry['weight'] : 1.0;
                                    $entry_title = isset( $source_index[ $entry_uuid ] ) ? (string) ( $source_index[ $entry_uuid ]['title'] ?? '' ) : '';
                                ?>
                                    <div class="vyg-source-row" data-source-index="<?php echo (int) $i; ?>" style="display:flex; gap:0.5rem; align-items:center; margin-bottom:0.5rem; padding:0.5rem; background:#f9f9f9; border:1px solid #ddd; border-radius:4px;">
                                        <span class="vyg-source-handle" style="cursor:move; color:#888;" title="<?php echo esc_attr__( 'Drag to reorder', 'vector-youtube-gallery' ); ?>">⇅</span>
                                        <select name="sources[<?php echo (int) $i; ?>][source_uuid]" class="vyg-source-select" style="min-width:240px;">
                                            <option value=""><?php echo esc_html__( '— Select source —', 'vector-youtube-gallery' ); ?></option>
                                            <?php foreach ( $sources as $s ) :
                                                $s_uuid = (string) ( $s['source_uuid'] ?? '' );
                                                $s_title = (string) ( $s['title'] ?? '(untitled)' );
                                                $s_type = (string) ( $s['source_type'] ?? '' );
                                            ?>
                                                <option value="<?php echo esc_attr( $s_uuid ); ?>" <?php selected( $entry_uuid, $s_uuid ); ?>><?php echo esc_html( $s_title ); ?> — <?php echo esc_html( $s_type ); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="number" name="sources[<?php echo (int) $i; ?>][weight]" step="0.1" min="0" max="10" value="<?php echo esc_attr( (string) $entry_weight ); ?>" style="width:5rem;" title="<?php echo esc_attr__( 'Weight', 'vector-youtube-gallery' ); ?>" />
                                        <label style="display:inline-flex; align-items:center; gap:0.25rem;">
                                            <input type="checkbox" name="sources[<?php echo (int) $i; ?>][pinned]" value="1" <?php checked( $entry_pinned ); ?> />
                                            <span><?php echo esc_html__( 'Pin', 'vector-youtube-gallery' ); ?></span>
                                        </label>
                                        <input type="text" name="sources[<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( $entry_label ); ?>" placeholder="<?php echo esc_attr__( 'Label (optional)', 'vector-youtube-gallery' ); ?>" style="flex:1;" />
                                        <?php if ( '' !== $entry_title ) : ?>
                                            <span class="vyg-source-badge" style="background:#e7f3ff; color:#1e3a5f; padding:2px 8px; border-radius:10px; font-size:0.8em;" title="<?php echo esc_attr( $entry_title ); ?>"><?php echo esc_html( $entry_title ); ?></span>
                                        <?php endif; ?>
                                        <button type="button" class="button button-small vyg-source-remove" aria-label="<?php echo esc_attr__( 'Remove source', 'vector-youtube-gallery' ); ?>">×</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <p>
                                <button type="button" class="button" id="vyg-add-source"><?php echo esc_html__( '+ Add another source', 'vector-youtube-gallery' ); ?></button>
                            </p>
                            <noscript>
                                <p class="description"><?php echo esc_html__( 'JavaScript is required to add or remove sources dynamically. Reload after enabling JS.', 'vector-youtube-gallery' ); ?></p>
                            </noscript>
                            <!-- Source row template, cloned by JS -->
                            <template id="vyg-source-row-template">
                                <div class="vyg-source-row" data-source-index="__INDEX__" style="display:flex; gap:0.5rem; align-items:center; margin-bottom:0.5rem; padding:0.5rem; background:#f9f9f9; border:1px solid #ddd; border-radius:4px;">
                                    <span class="vyg-source-handle" style="cursor:move; color:#888;" title="<?php echo esc_attr__( 'Drag to reorder', 'vector-youtube-gallery' ); ?>">⇅</span>
                                    <select name="sources[__INDEX__][source_uuid]" class="vyg-source-select" style="min-width:240px;">
                                        <option value=""><?php echo esc_html__( '— Select source —', 'vector-youtube-gallery' ); ?></option>
                                        <?php foreach ( $sources as $s ) :
                                            $s_uuid = (string) ( $s['source_uuid'] ?? '' );
                                            $s_title = (string) ( $s['title'] ?? '(untitled)' );
                                            $s_type = (string) ( $s['source_type'] ?? '' );
                                        ?>
                                            <option value="<?php echo esc_attr( $s_uuid ); ?>"><?php echo esc_html( $s_title ); ?> — <?php echo esc_html( $s_type ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="number" name="sources[__INDEX__][weight]" step="0.1" min="0" max="10" value="1.0" style="width:5rem;" title="<?php echo esc_attr__( 'Weight', 'vector-youtube-gallery' ); ?>" />
                                    <label style="display:inline-flex; align-items:center; gap:0.25rem;">
                                        <input type="checkbox" name="sources[__INDEX__][pinned]" value="1" />
                                        <span><?php echo esc_html__( 'Pin', 'vector-youtube-gallery' ); ?></span>
                                    </label>
                                    <input type="text" name="sources[__INDEX__][label]" placeholder="<?php echo esc_attr__( 'Label (optional)', 'vector-youtube-gallery' ); ?>" style="flex:1;" />
                                    <button type="button" class="button button-small vyg-source-remove" aria-label="<?php echo esc_attr__( 'Remove source', 'vector-youtube-gallery' ); ?>">×</button>
                                </div>
                            </template>
                            <input type="hidden" name="source_uuid" value="" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="manual_video_ids"><?php echo esc_html__( 'Manual video IDs', 'vector-youtube-gallery' ); ?></label></th>
                        <td>
                            <textarea name="manual_video_ids" id="manual_video_ids" rows="4" class="large-text code" placeholder="<?php echo esc_attr__( 'One YouTube video ID per line (e.g. dQw4w9WgXcQ)', 'vector-youtube-gallery' ); ?>"><?php echo esc_textarea( implode( "\n", array_map( 'strval', $manual_ids ) ) ); ?></textarea>
                            <p class="description"><?php echo esc_html__( 'Always included in this feed, in addition to source videos. One 11-char YouTube ID per line.', 'vector-youtube-gallery' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="exclude_video_ids"><?php echo esc_html__( 'Exclude video IDs', 'vector-youtube-gallery' ); ?></label></th>
                        <td>
                            <textarea name="exclude_video_ids" id="exclude_video_ids" rows="3" class="large-text code" placeholder="<?php echo esc_attr__( 'IDs to omit from this feed', 'vector-youtube-gallery' ); ?>"><?php echo esc_textarea( implode( "\n", array_map( 'strval', $exclude_ids ) ) ); ?></textarea>
                            <p class="description"><?php echo esc_html__( 'One YouTube video ID per line. Excluded videos never appear, even if they come from a source above or are pinned.', 'vector-youtube-gallery' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="layout"><?php echo esc_html__( 'Layout', 'vector-youtube-gallery' ); ?></label></th>
                        <td>
                            <select name="layout" id="layout">
                                <?php foreach ( FeedRepository::allowed_layouts() as $layout ) : ?>
                                    <option value="<?php echo esc_attr( $layout ); ?>" <?php selected( (string) ( $feed['layout'] ?? 'grid' ), $layout ); ?>><?php echo esc_html( ucfirst( $layout ) ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr><th colspan="2"><h2><?php echo esc_html__( 'Display', 'vector-youtube-gallery' ); ?></h2></th></tr>
                    <tr>
                        <th scope="row"><label for="columns"><?php echo esc_html__( 'Columns', 'vector-youtube-gallery' ); ?></label></th>
                        <td><input name="columns" id="columns" type="number" min="1" max="6" value="<?php echo (int) ( $display['columns'] ?? 3 ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="per_page"><?php echo esc_html__( 'Items per page', 'vector-youtube-gallery' ); ?></label></th>
                        <td><input name="per_page" id="per_page" type="number" min="1" max="100" value="<?php echo (int) ( $display['per_page'] ?? 12 ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Player features', 'vector-youtube-gallery' ); ?></th>
                        <td>
                            <label><input type="checkbox" name="lightbox" value="1" <?php checked( ! empty( $display['lightbox'] ) ); ?> /> <?php echo esc_html__( 'Lightbox player', 'vector-youtube-gallery' ); ?></label><br />
                            <label><input type="checkbox" name="load_more" value="1" <?php checked( ! empty( $display['load_more'] ) ); ?> /> <?php echo esc_html__( 'Load-more pagination', 'vector-youtube-gallery' ); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="player_mode"><?php echo esc_html__( 'Player mode', 'vector-youtube-gallery' ); ?></label></th>
                        <td>
                            <select name="player_mode" id="player_mode">
                                <option value="iframe" <?php selected( (string) ( $display['player_mode'] ?? 'iframe' ), 'iframe' ); ?>><?php echo esc_html__( 'YouTube iframe (in-page)', 'vector-youtube-gallery' ); ?></option>
                                <option value="external" <?php selected( (string) ( $display['player_mode'] ?? 'iframe' ), 'external' ); ?>><?php echo esc_html__( 'YouTube.com (new tab)', 'vector-youtube-gallery' ); ?></option>
                            </select>
                        </td>
                    </tr>

                    <tr><th colspan="2"><h2><?php echo esc_html__( 'Filter & Sort', 'vector-youtube-gallery' ); ?></h2></th></tr>
                    <tr>
                        <th scope="row"><label for="content_type"><?php echo esc_html__( 'Content type', 'vector-youtube-gallery' ); ?></label></th>
                        <td>
                            <select name="content_type" id="content_type">
                                <option value="" <?php selected( (string) ( $filter['content_type'] ?? '' ), '' ); ?>><?php echo esc_html__( 'All', 'vector-youtube-gallery' ); ?></option>
                                <option value="standard" <?php selected( (string) ( $filter['content_type'] ?? '' ), 'standard' ); ?>><?php echo esc_html__( 'Standard videos only', 'vector-youtube-gallery' ); ?></option>
                                <option value="short_confirmed" <?php selected( (string) ( $filter['content_type'] ?? '' ), 'short_confirmed' ); ?>><?php echo esc_html__( 'Shorts only', 'vector-youtube-gallery' ); ?></option>
                                <option value="short_candidate" <?php selected( (string) ( $filter['content_type'] ?? '' ), 'short_candidate' ); ?>><?php echo esc_html__( 'Short candidates', 'vector-youtube-gallery' ); ?></option>
                                <option value="live_active" <?php selected( (string) ( $filter['content_type'] ?? '' ), 'live_active' ); ?>><?php echo esc_html__( 'Live (active)', 'vector-youtube-gallery' ); ?></option>
                                <option value="live_upcoming" <?php selected( (string) ( $filter['content_type'] ?? '' ), 'live_upcoming' ); ?>><?php echo esc_html__( 'Live (upcoming)', 'vector-youtube-gallery' ); ?></option>
                                <option value="live_replay" <?php selected( (string) ( $filter['content_type'] ?? '' ), 'live_replay' ); ?>><?php echo esc_html__( 'Live replays', 'vector-youtube-gallery' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="shorts_policy"><?php echo esc_html__( 'Shorts policy', 'vector-youtube-gallery' ); ?></label></th>
                        <td>
                            <select name="shorts_policy" id="shorts_policy">
                                <option value="include" <?php selected( (string) ( $filter['shorts_policy'] ?? 'include' ), 'include' ); ?>><?php echo esc_html__( 'Include Shorts', 'vector-youtube-gallery' ); ?></option>
                                <option value="exclude" <?php selected( (string) ( $filter['shorts_policy'] ?? 'include' ), 'exclude' ); ?>><?php echo esc_html__( 'Exclude Shorts', 'vector-youtube-gallery' ); ?></option>
                                <option value="only" <?php selected( (string) ( $filter['shorts_policy'] ?? 'include' ), 'only' ); ?>><?php echo esc_html__( 'Shorts only', 'vector-youtube-gallery' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Availability', 'vector-youtube-gallery' ); ?></th>
                        <td>
                            <select name="availability">
                                <option value="available" <?php selected( (string) ( $filter['availability'] ?? 'available' ), 'available' ); ?>><?php echo esc_html__( 'Available only', 'vector-youtube-gallery' ); ?></option>
                                <option value="all" <?php selected( (string) ( $filter['availability'] ?? 'available' ), 'all' ); ?>><?php echo esc_html__( 'All (including unavailable)', 'vector-youtube-gallery' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="orderby"><?php echo esc_html__( 'Sort by', 'vector-youtube-gallery' ); ?></label></th>
                        <td>
                            <select name="orderby" id="orderby">
                                <option value="published_at" <?php selected( (string) ( $sort['orderby'] ?? 'published_at' ), 'published_at' ); ?>><?php echo esc_html__( 'Published date', 'vector-youtube-gallery' ); ?></option>
                                <option value="title" <?php selected( (string) ( $sort['orderby'] ?? 'published_at' ), 'title' ); ?>><?php echo esc_html__( 'Title', 'vector-youtube-gallery' ); ?></option>
                                <option value="view_count" <?php selected( (string) ( $sort['orderby'] ?? 'published_at' ), 'view_count' ); ?>><?php echo esc_html__( 'View count', 'vector-youtube-gallery' ); ?></option>
                                <option value="last_refreshed_at" <?php selected( (string) ( $sort['orderby'] ?? 'published_at' ), 'last_refreshed_at' ); ?>><?php echo esc_html__( 'Last refreshed', 'vector-youtube-gallery' ); ?></option>
                            </select>
                            <select name="order">
                                <option value="DESC" <?php selected( (string) ( $sort['order'] ?? 'DESC' ), 'DESC' ); ?>><?php echo esc_html__( 'Descending', 'vector-youtube-gallery' ); ?></option>
                                <option value="ASC" <?php selected( (string) ( $sort['order'] ?? 'DESC' ), 'ASC' ); ?>><?php echo esc_html__( 'Ascending', 'vector-youtube-gallery' ); ?></option>
                            </select>
                        </td>
                    </tr>

                    <tr><th colspan="2"><h2><?php echo esc_html__( 'Custom CSS', 'vector-youtube-gallery' ); ?></h2></th></tr>
                    <tr>
                        <th scope="row"><label for="custom_css"><?php echo esc_html__( 'Scoped CSS', 'vector-youtube-gallery' ); ?></label></th>
                        <td>
                            <textarea name="custom_css" id="custom_css" rows="8" class="large-text code"><?php echo esc_textarea( (string) ( $feed['custom_css'] ?? '' ) ); ?></textarea>
                            <p class="description"><?php echo esc_html__( 'CSS is automatically scoped to this feed via [data-feed-uuid="..."]. Avoid HTML tags or <script>.', 'vector-youtube-gallery' ); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php echo $is_new ? esc_html__( 'Create Feed', 'vector-youtube-gallery' ) : esc_html__( 'Save Changes', 'vector-youtube-gallery' ); ?></button>
                    <a href="<?php echo esc_url( remove_query_arg( array( 'action', 'id' ) ) ); ?>" class="button"><?php echo esc_html__( 'Cancel', 'vector-youtube-gallery' ); ?></a>
                </p>
            </form>
        </div>
        <script>
        (function () {
            var container = document.getElementById('vyg-feed-sources');
            var template = document.getElementById('vyg-source-row-template');
            var addBtn   = document.getElementById('vyg-add-source');
            if (!container || !template || !addBtn) {
                return;
            }

            function nextIndex() {
                var max = -1;
                var rows = container.querySelectorAll('.vyg-source-row');
                for (var i = 0; i < rows.length; i++) {
                    var idx = parseInt(rows[i].getAttribute('data-source-index'), 10);
                    if (!isNaN(idx) && idx > max) {
                        max = idx;
                    }
                }
                return max + 1;
            }

            function reindex() {
                var rows = container.querySelectorAll('.vyg-source-row');
                for (var i = 0; i < rows.length; i++) {
                    rows[i].setAttribute('data-source-index', i);
                    var inputs = rows[i].querySelectorAll('[name]');
                    for (var j = 0; j < inputs.length; j++) {
                        var name = inputs[j].getAttribute('name');
                        if (name) {
                            inputs[j].setAttribute('name', name.replace(/sources\[\d+\]/, 'sources[' + i + ']'));
                        }
                    }
                }
            }

            function attachRemove(row) {
                var btn = row.querySelector('.vyg-source-remove');
                if (!btn) {
                    return;
                }
                btn.addEventListener('click', function () {
                    row.parentNode.removeChild(row);
                    reindex();
                });
            }

            container.addEventListener('click', function (e) {
                var target = e.target;
                if (target && target.classList && target.classList.contains('vyg-source-remove')) {
                    var row = target.closest('.vyg-source-row');
                    if (row && row.parentNode) {
                        row.parentNode.removeChild(row);
                        reindex();
                    }
                }
            });

            addBtn.addEventListener('click', function () {
                var html = template.innerHTML.replace(/__INDEX__/g, String(nextIndex()));
                var wrapper = document.createElement('div');
                wrapper.innerHTML = html;
                var row = wrapper.firstChild;
                container.appendChild(row);
                attachRemove(row);
            });

            var rows = container.querySelectorAll('.vyg-source-row');
            for (var k = 0; k < rows.length; k++) {
                attachRemove(rows[k]);
            }

            // Simple drag-reorder using HTML5 drag API.
            var dragSrc = null;
            container.addEventListener('dragstart', function (e) {
                var row = e.target.closest && e.target.closest('.vyg-source-row');
                if (row) {
                    dragSrc = row;
                    e.dataTransfer.effectAllowed = 'move';
                    try { e.dataTransfer.setData('text/plain', ''); } catch (err) {}
                }
            });
            container.addEventListener('dragover', function (e) {
                if (dragSrc) {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                }
            });
            container.addEventListener('drop', function (e) {
                e.preventDefault();
                if (!dragSrc) {
                    return;
                }
                var target = e.target.closest && e.target.closest('.vyg-source-row');
                if (target && target !== dragSrc) {
                    var parent = dragSrc.parentNode;
                    parent.insertBefore(dragSrc, target.nextSibling);
                    reindex();
                }
                dragSrc = null;
            });
            var handles = container.querySelectorAll('.vyg-source-row');
            for (var h = 0; h < handles.length; h++) {
                handles[h].setAttribute('draggable', 'true');
            }
        })();
        </script>
        <?php
    }

    /**
     * @return array<string,mixed>
     */
    private function empty_feed(): array {
        return array(
            'id' => 0,
            'feed_uuid' => '',
            'name' => '',
            'feed_type' => 'source',
            'layout' => 'grid',
            'status' => 'draft',
            'source_config_json' => '{}',
            'display_config_json' => '{}',
            'filter_config_json' => '{}',
            'sort_config_json' => '{}',
            'custom_css' => '',
        );
    }
}