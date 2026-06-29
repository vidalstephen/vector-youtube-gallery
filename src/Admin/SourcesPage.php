<?php
/**
 * Sources admin page — list, add, sync-now.
 *
 * Phase 2: backed by SourceRepository (real DB table). Phase 1 draft-option
 * rows migrated by Migrator on activation.
 *
 * @package VectorYT\Gallery\Admin
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Admin;

use VectorYT\Gallery\Logging\Logger;
use VectorYT\Gallery\Repository\SourceRepository;
use VectorYT\Gallery\Repository\SyncLogRepository;
use VectorYT\Gallery\Settings\OAuthTokenRepository;
use VectorYT\Gallery\Settings\SecretsRepository;
use VectorYT\Gallery\Settings\SettingsRepository;
use VectorYT\Gallery\Sync\InitialImportJob;
use VectorYT\Gallery\YouTube\ApiException;
use VectorYT\Gallery\YouTube\ChannelResolver;
use VectorYT\Gallery\YouTube\PlaylistResolver;
use VectorYT\Gallery\YouTube\VideoMetadataFetcher;

defined( 'ABSPATH' ) || exit;

final class SourcesPage {

    private const NONCE_ACTION = 'vyg_sources_action';
    private const RATE_LIMIT_PER_USER_SECONDS = 30;

    public function __construct(
        private readonly ChannelResolver $channels,
        private readonly PlaylistResolver $playlists,
        private readonly VideoMetadataFetcher $videos,
        private readonly SourceRepository $sources,
        private readonly SyncLogRepository $logs,
        private readonly InitialImportJob $initial_import,
        private readonly Logger $logger,
        private readonly SecretsRepository $secrets,
        private readonly OAuthTokenRepository $oauth_tokens,
        private readonly SettingsRepository $settings,
    ) {}

    public function render(): void {
        if ( ! current_user_can( AdminMenu::REQUIRED_CAP ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'vector-youtube-gallery' ) );
        }

        if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
            $this->maybe_handle_post();
        }

        $sources = $this->sources->list( array( 'limit' => 200 ) );
        $this->render_html( $sources );
    }

    private function maybe_handle_post(): void {
        if ( ! isset( $_POST['vyg_sources_nonce'] ) ) {
            return;
        }
        $nonce = sanitize_text_field( wp_unslash( $_POST['vyg_sources_nonce'] ) );
        if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
            wp_die( esc_html__( 'Invalid nonce.', 'vector-youtube-gallery' ) );
        }

        $op = isset( $_POST['vyg_op'] ) ? sanitize_key( wp_unslash( $_POST['vyg_op'] ) ) : '';
        if ( 'add' === $op ) {
            $this->handle_add();
        } elseif ( 'delete' === $op ) {
            $this->handle_delete();
        } elseif ( 'sync' === $op ) {
            $this->handle_sync();
        } elseif ( 'bulk' === $op ) {
            $this->handle_bulk();
        }
    }

    /**
     * Bulk action handler. Operates on all checked source_ids.
     */
    private function handle_bulk(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'vector-youtube-gallery' ) );
        }
        $action = isset( $_POST['vyg_bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['vyg_bulk_action'] ) ) : '';
        if ( ! in_array( $action, array( 'pause', 'resume', 'sync', 'delete' ), true ) ) {
            $this->redirect_with_notice( 'bulk_invalid' );
        }
        $ids = isset( $_POST['vyg_source_ids'] ) && is_array( $_POST['vyg_source_ids'] )
            ? array_map( 'intval', (array) $_POST['vyg_source_ids'] )
            : array();
        $ids = array_values( array_filter( $ids, static fn( $id ) => $id > 0 ) );
        if ( empty( $ids ) ) {
            $this->redirect_with_notice( 'bulk_none' );
        }
        $count = 0;
        foreach ( $ids as $id ) {
            switch ( $action ) {
                case 'pause':
                    $this->sources->update( $id, array( 'status' => 'paused' ) );
                    ++$count;
                    break;
                case 'resume':
                    $this->sources->update( $id, array( 'status' => 'active' ) );
                    ++$count;
                    break;
                case 'sync':
                    $job_id = $this->logs->create_job( 'initial', $id );
                    wp_schedule_single_event( time() + 5, 'vyg_sync_source_initial', array(
                        'vyg_job_id' => $job_id,
                        'source_id'  => $id,
                    ) );
                    ++$count;
                    break;
                case 'delete':
                    $this->sources->delete( $id );
                    ++$count;
                    break;
            }
        }
        $this->redirect_with_notice( sprintf( 'bulk_%s:%d', $action, $count ) );
    }

    private function handle_add(): void {
        if ( ! $this->has_api_access() ) {
            $this->redirect_with_notice( 'no_api_key' );
            return;
        }
        $type = isset( $_POST['source_type'] ) ? sanitize_key( wp_unslash( $_POST['source_type'] ) ) : '';
        $raw  = isset( $_POST['source_value'] ) ? sanitize_text_field( wp_unslash( $_POST['source_value'] ) ) : '';

        try {
            $resource = $this->resolve_source( $type, $raw );
        } catch ( ApiException $e ) {
            $this->logger->warning( 'Source validation failed', array(
                'type'        => $type,
                'input'       => substr( $raw, 0, 80 ),
                'kind'        => $e->kind(),
                'http_status' => $e->http_status(),
            ) );
            $this->redirect_with_notice( 'invalid:' . $e->kind() );
            return;
        } catch ( \InvalidArgumentException $e ) {
            $this->redirect_with_notice( 'parse_error' );
            return;
        } catch ( \RuntimeException $e ) {
            $this->redirect_with_notice( 'not_found' );
            return;
        }

        $youtube_id = $this->extract_youtube_id( $type, $resource );
        $title      = $this->extract_title( $type, $resource );
        $thumbnail  = $this->extract_thumbnail( $type, $resource );

        // Channel sources store the uploads playlist ID; resolve that now too.
        $uploads_playlist_id = null;
        $channel_youtube_id  = null;
        $playlist_youtube_id = null;
        $video_youtube_id    = null;
        $handle              = null;

        if ( 'channel' === $type ) {
            $channel_youtube_id = $youtube_id;
            $uploads_playlist_id = $resource['contentDetails']['relatedPlaylists']['uploads'] ?? null;
            $custom_url = (string) ( $resource['snippet']['customUrl'] ?? '' );
            if ( '' !== $custom_url ) {
                $handle = ltrim( $custom_url, '@' );
            }
        } elseif ( 'playlist' === $type ) {
            $playlist_youtube_id = $youtube_id;
        } elseif ( 'video' === $type ) {
            $video_youtube_id = $youtube_id;
        }

        // De-dupe by the appropriate ID.
        $existing = 'channel' === $type ? $this->sources->find_by_youtube_channel_id( $youtube_id )
            : ( 'playlist' === $type ? $this->sources->find_by_youtube_playlist_id( $youtube_id )
                : $this->sources->find_by_youtube_video_id( $youtube_id ) );
        if ( null !== $existing ) {
            $this->redirect_with_notice( 'duplicate' );
            return;
        }

        $source_id = $this->sources->create( array(
            'source_type'         => $type,
            'auth_mode'           => $this->current_auth_mode(),
            'youtube_channel_id'  => $channel_youtube_id,
            'youtube_playlist_id' => $playlist_youtube_id,
            'youtube_video_id'    => $video_youtube_id,
            'handle'              => $handle,
            'title'               => $title,
            'thumbnail_url'       => $thumbnail,
            'status'              => 'active',
            'sync_interval'       => DAY_IN_SECONDS,
            // Stash uploads playlist on a meta column for Phase 2 sync.
            // (We don't have a separate uploads_pl_id column in Phase 2 schema; resolution
            // re-fetches it on every sync run. That's 1 cheap quota unit per source.)
        ) );

        // Queue an initial import job.
        $job_id = $this->logs->create_job( 'initial_import', $source_id );
        wp_schedule_single_event( time() + 5, InitialImportJob::class === InitialImportJob::class ? 'vyg_sync_source_initial' : 'vyg_sync_source_initial', array(
            'vyg_job_id' => $job_id,
            'source_id'  => $source_id,
        ) );

        $this->logger->info( 'Source created + initial import scheduled', array(
            'source_id' => $source_id,
            'type'      => $type,
            'auth_mode' => $this->current_auth_mode(),
            'job_id'    => $job_id,
            'uploads_pl_id' => $uploads_playlist_id,
        ) );
        $this->redirect_with_notice( 'added' );
    }

    private function handle_delete(): void {
        $id = isset( $_POST['source_id'] ) ? (int) $_POST['source_id'] : 0;
        if ( $id > 0 ) {
            $this->sources->delete( $id );
            $this->logger->info( 'Source deleted', array( 'source_id' => $id ) );
        }
        $this->redirect_with_notice( 'deleted' );
    }

    private function handle_sync(): void {
        $id = isset( $_POST['source_id'] ) ? (int) $_POST['source_id'] : 0;
        if ( $id <= 0 ) {
            $this->redirect_with_notice( 'sync_invalid' );
            return;
        }
        // Rate-limit: per-user cap to prevent button-mash queue floods.
        $user_id = get_current_user_id();
        $last    = (int) get_transient( 'vyg_sync_last_' . $user_id . '_' . $id );
        if ( $last > 0 && ( time() - $last ) < self::RATE_LIMIT_PER_USER_SECONDS ) {
            $this->redirect_with_notice( 'rate_limited' );
            return;
        }
        set_transient( 'vyg_sync_last_' . $user_id . '_' . $id, time(), self::RATE_LIMIT_PER_USER_SECONDS * 2 );

        $job_id = $this->logs->create_job( 'initial_import', $id );
        wp_schedule_single_event( time() + 5, 'vyg_sync_source_initial', array(
            'vyg_job_id' => $job_id,
            'source_id'  => $id,
        ) );
        $this->logger->info( 'Manual sync queued', array( 'source_id' => $id, 'job_id' => $job_id ) );
        $this->redirect_with_notice( 'sync_queued' );
    }

    /**
     * @return array<string,mixed>
     */
    private function resolve_source( string $type, string $raw ): array {
        return match ( $type ) {
            'channel'  => $this->channels->resolve_input( $raw ),
            'playlist' => $this->playlists->resolve_input( $raw ),
            'video'    => $this->videos->fetch_one( $this->videos->classify_input( $raw ) ),
            default    => throw new \InvalidArgumentException( 'Unknown source type: ' . $type ),
        };
    }

    private function extract_youtube_id( string $type, array $resource ): string {
        return match ( $type ) {
            'channel', 'playlist', 'video' => (string) ( $resource['id'] ?? '' ),
            default => '',
        };
    }

    private function extract_title( string $type, array $resource ): string {
        return (string) ( $resource['snippet']['title'] ?? '' );
    }

    private function extract_thumbnail( string $type, array $resource ): string {
        return (string) ( $resource['snippet']['thumbnails']['default']['url'] ?? '' );
    }

    private function current_auth_mode(): string {
        $mode = (string) $this->settings->get( 'api_mode', 'api_key' );
        return 'oauth' === $mode ? 'oauth' : 'api_key';
    }

    private function has_api_access(): bool {
        if ( defined( 'VYG_USE_MOCK' ) && VYG_USE_MOCK ) {
            return true;
        }
        if ( 'oauth' === $this->current_auth_mode() ) {
            return $this->oauth_tokens->status()['connected'];
        }
        return $this->secrets->has_api_key();
    }

    private function redirect_with_notice( string $notice ): void {
        $url = add_query_arg(
            array( 'page' => AdminMenu::PARENT_SLUG, 'vyg_notice' => $notice ),
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $url );
        exit;
    }

    /**
     * @param array<int,array<string,mixed>> $sources
     */
    private function render_html( array $sources ): void {
        $notice = isset( $_GET['vyg_notice'] ) ? sanitize_key( wp_unslash( $_GET['vyg_notice'] ) ) : '';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'YouTube Gallery — Sources', 'vector-youtube-gallery' ); ?></h1>

            <?php $this->render_notice( $notice ); ?>

            <?php if ( ! $this->has_api_access() ) : ?>
                <div class="notice notice-warning">
                    <p>
                        <?php
                        $settings_url = admin_url( 'admin.php?page=' . AdminMenu::PARENT_SLUG . '-settings' . ( 'oauth' === $this->current_auth_mode() ? '&tab=oauth' : '' ) );
                        $message = 'oauth' === $this->current_auth_mode()
                            ? __( 'OAuth mode is selected, but no connected OAuth account is available. Connect YouTube on the <a href="%s">OAuth settings tab</a> first.', 'vector-youtube-gallery' )
                            : __( 'No API key configured. Add one on the <a href="%s">Settings page</a> first.', 'vector-youtube-gallery' );
                        echo wp_kses_post( sprintf( $message, esc_url( $settings_url ) ) );
                        ?>
                    </p>
                </div>
            <?php else : ?>
                <div class="notice notice-info inline">
                    <p><?php echo esc_html( sprintf( __( 'New sources will use %s credential mode.', 'vector-youtube-gallery' ), $this->current_auth_mode() ) ); ?></p>
                </div>
            <?php endif; ?>

            <h2><?php echo esc_html__( 'Add a Source', 'vector-youtube-gallery' ); ?></h2>
            <form method="post" action="">
                <?php wp_nonce_field( self::NONCE_ACTION, 'vyg_sources_nonce' ); ?>
                <input type="hidden" name="vyg_op" value="add" />
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Type', 'vector-youtube-gallery' ); ?></th>
                        <td>
                            <select name="source_type" id="source_type">
                                <option value="channel"><?php echo esc_html__( 'Channel', 'vector-youtube-gallery' ); ?></option>
                                <option value="playlist"><?php echo esc_html__( 'Playlist', 'vector-youtube-gallery' ); ?></option>
                                <option value="video"><?php echo esc_html__( 'Single Video', 'vector-youtube-gallery' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="source_value"><?php echo esc_html__( 'Identifier', 'vector-youtube-gallery' ); ?></label>
                        </th>
                        <td>
                            <input
                                type="text"
                                id="source_value"
                                name="source_value"
                                class="regular-text"
                                placeholder="UC... / @handle / PL... / video URL or 11-char ID"
                                required
                            />
                            <p class="description">
                                <?php echo esc_html__( 'Accepts channel ID, @handle, full URLs, playlist ID, or video URL/ID depending on type.', 'vector-youtube-gallery' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Add Source', 'vector-youtube-gallery' ) ); ?>
            </form>

            <h2>
                <?php echo esc_html__( 'Current Sources', 'vector-youtube-gallery' ); ?>
                <span class="count">(<?php echo count( $sources ); ?>)</span>
            </h2>
            <?php if ( 0 === count( $sources ) ) : ?>
                <p><?php echo esc_html__( 'No sources yet. Add one above.', 'vector-youtube-gallery' ); ?></p>
            <?php else : ?>
                <form method="post" id="vyg-sources-bulk-form">
                    <?php wp_nonce_field( self::NONCE_ACTION, 'vyg_sources_nonce' ); ?>
                    <input type="hidden" name="vyg_op" value="bulk" />
                    <div class="tablenav top">
                        <div class="alignleft actions bulkactions">
                            <label for="vyg-bulk-action" class="screen-reader-text"><?php esc_html_e( 'Select bulk action', 'vector-youtube-gallery' ); ?></label>
                            <select name="vyg_bulk_action" id="vyg-bulk-action">
                                <option value=""><?php esc_html_e( 'Bulk actions', 'vector-youtube-gallery' ); ?></option>
                                <option value="pause"><?php esc_html_e( 'Pause', 'vector-youtube-gallery' ); ?></option>
                                <option value="resume"><?php esc_html_e( 'Resume', 'vector-youtube-gallery' ); ?></option>
                                <option value="sync"><?php esc_html_e( 'Sync now', 'vector-youtube-gallery' ); ?></option>
                                <option value="delete"><?php esc_html_e( 'Delete', 'vector-youtube-gallery' ); ?></option>
                            </select>
                            <button type="submit" class="button action" id="vyg-bulk-apply">
                                <?php esc_html_e( 'Apply', 'vector-youtube-gallery' ); ?>
                            </button>
                        </div>
                        <div class="alignright">
                            <span class="displaying-num"><?php
                                /* translators: %s: source count */
                                echo esc_html( sprintf( _n( '%s source', '%s sources', count( $sources ), 'vector-youtube-gallery' ), number_format_i18n( count( $sources ) ) ) );
                            ?></span>
                        </div>
                        <br class="clear">
                    </div>

                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <td class="manage-column column-cb check-column">
                                    <input type="checkbox" id="vyg-bulk-select-all" />
                                </td>
                                <th><?php echo esc_html__( 'Type', 'vector-youtube-gallery' ); ?></th>
                                <th><?php echo esc_html__( 'Title', 'vector-youtube-gallery' ); ?></th>
                                <th><?php echo esc_html__( 'YouTube ID', 'vector-youtube-gallery' ); ?></th>
                                <th><?php echo esc_html__( 'Status', 'vector-youtube-gallery' ); ?></th>
                                <th><?php echo esc_html__( 'Auth Mode', 'vector-youtube-gallery' ); ?></th>
                                <th><?php echo esc_html__( 'Last Sync', 'vector-youtube-gallery' ); ?></th>
                                <th><?php echo esc_html__( 'Actions', 'vector-youtube-gallery' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $sources as $s ) : ?>
                                <tr>
                                    <th scope="row" class="check-column">
                                        <input type="checkbox" class="vyg-bulk-cb" name="vyg_source_ids[]" value="<?php echo esc_attr( (string) ( $s['id'] ?? '' ) ); ?>" />
                                    </th>
                                    <td><?php echo esc_html( (string) ( $s['source_type'] ?? '' ) ); ?></td>
                                    <td><?php echo esc_html( (string) ( $s['title'] ?? '' ) ); ?></td>
                                    <td><code><?php echo esc_html( (string) ( $s['youtube_channel_id'] ?? $s['youtube_playlist_id'] ?? $s['youtube_video_id'] ?? '' ) ); ?></code></td>
                                    <td>
                                        <span class="vyg-status-badge vyg-status-badge--<?php echo esc_attr( (string) ( $s['status'] ?? 'unknown' ) ); ?>">
                                            <?php echo esc_html( (string) ( $s['status'] ?? 'unknown' ) ); ?>
                                        </span>
                                    </td>
                                    <td><code><?php echo esc_html( (string) ( $s['auth_mode'] ?? 'api_key' ) ); ?></code></td>
                                    <td>
                                        <?php
                                        $last = $s['last_success_at'] ?? null;
                                        echo $last ? esc_html( $last ) : '<em>' . esc_html__( 'never', 'vector-youtube-gallery' ) . '</em>';
                                        ?>
                                    </td>
                                    <td>
                                        <form method="post" style="display:inline">
                                            <?php wp_nonce_field( self::NONCE_ACTION, 'vyg_sources_nonce' ); ?>
                                            <input type="hidden" name="vyg_op" value="sync" />
                                            <input type="hidden" name="source_id" value="<?php echo esc_attr( (string) ( $s['id'] ?? '' ) ); ?>" />
                                            <button type="submit" class="button button-small">
                                                <?php echo esc_html__( 'Sync now', 'vector-youtube-gallery' ); ?>
                                            </button>
                                        </form>
                                        <form method="post" style="display:inline">
                                            <?php wp_nonce_field( self::NONCE_ACTION, 'vyg_sources_nonce' ); ?>
                                            <input type="hidden" name="vyg_op" value="delete" />
                                            <input type="hidden" name="source_id" value="<?php echo esc_attr( (string) ( $s['id'] ?? '' ) ); ?>" />
                                            <button type="submit" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Delete this source?', 'vector-youtube-gallery' ) ); ?>');">
                                                <?php echo esc_html__( 'Delete', 'vector-youtube-gallery' ); ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </form>

                <script>
                (function() {
                    var selectAll = document.getElementById('vyg-bulk-select-all');
                    if (selectAll) {
                        selectAll.addEventListener('change', function() {
                            var boxes = document.querySelectorAll('.vyg-bulk-cb');
                            for (var i = 0; i < boxes.length; i++) {
                                boxes[i].checked = selectAll.checked;
                            }
                        });
                    }
                    var apply = document.getElementById('vyg-bulk-apply');
                    if (apply) {
                        apply.addEventListener('click', function(e) {
                            var sel = document.getElementById('vyg-bulk-action');
                            if (!sel.value) {
                                e.preventDefault();
                                alert('<?php echo esc_js( __( 'Please select a bulk action.', 'vector-youtube-gallery' ) ); ?>');
                                return;
                            }
                            var any = document.querySelectorAll('.vyg-bulk-cb:checked').length > 0;
                            if (!any) {
                                e.preventDefault();
                                alert('<?php echo esc_js( __( 'Please select at least one source.', 'vector-youtube-gallery' ) ); ?>');
                                return;
                            }
                            if (sel.value === 'delete') {
                                if (!confirm('<?php echo esc_js( __( 'Delete the selected sources? This cannot be undone.', 'vector-youtube-gallery' ) ); ?>')) {
                                    e.preventDefault();
                                }
                            }
                        });
                    }
                })();
                </script>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_notice( string $notice ): void {
        $messages = array(
            'added'           => array( __( 'Source added and initial sync queued.', 'vector-youtube-gallery' ), 'success' ),
            'deleted'         => array( __( 'Source deleted.', 'vector-youtube-gallery' ), 'success' ),
            'sync_queued'     => array( __( 'Sync queued.', 'vector-youtube-gallery' ), 'success' ),
            'no_api_key'      => array( __( 'Add an API key first on the Settings page.', 'vector-youtube-gallery' ), 'warning' ),
            'not_found'       => array( __( 'YouTube returned no matching resource for that identifier.', 'vector-youtube-gallery' ), 'error' ),
            'parse_error'     => array( __( 'Could not parse the identifier — check the format.', 'vector-youtube-gallery' ), 'error' ),
            'duplicate'       => array( __( 'A source with that YouTube ID already exists.', 'vector-youtube-gallery' ), 'warning' ),
            'rate_limited'    => array( __( 'Sync was queued too recently. Wait a few seconds.', 'vector-youtube-gallery' ), 'warning' ),
            'sync_invalid'    => array( __( 'Invalid sync request.', 'vector-youtube-gallery' ), 'error' ),
            'bulk_invalid'    => array( __( 'Invalid bulk action.', 'vector-youtube-gallery' ), 'error' ),
            'bulk_none'       => array( __( 'No sources selected for bulk action.', 'vector-youtube-gallery' ), 'warning' ),
        );

        // Handle bulk_<action>:<count> notices.
        if ( str_starts_with( $notice, 'bulk_' ) && str_contains( $notice, ':' ) && ! str_starts_with( $notice, 'bulk_invalid' ) && ! str_starts_with( $notice, 'bulk_none' ) ) {
            [ $verb, $count ] = explode( ':', substr( $notice, 5 ), 2 ) + array( '', '0' );
            $verb_labels = array(
                'pause'  => __( 'paused', 'vector-youtube-gallery' ),
                'resume' => __( 'resumed', 'vector-youtube-gallery' ),
                'sync'   => __( 'queued for sync', 'vector-youtube-gallery' ),
                'delete' => __( 'deleted', 'vector-youtube-gallery' ),
            );
            $verb_label = $verb_labels[ $verb ] ?? $verb;
            /* translators: 1: count, 2: verb label */
            $msg = sprintf( _n( '%1$d source %2$s.', '%1$d sources %2$s.', (int) $count, 'vector-youtube-gallery' ), (int) $count, $verb_label );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
            return;
        }

        if ( str_starts_with( $notice, 'invalid:' ) ) {
            $kind = substr( $notice, strlen( 'invalid:' ) );
            $kind_label = array(
                'auth'        => __( 'API key rejected (invalid or revoked).', 'vector-youtube-gallery' ),
                'quota'       => __( 'YouTube API quota exceeded.', 'vector-youtube-gallery' ),
                'forbidden'   => __( 'YouTube API forbidden this request.', 'vector-youtube-gallery' ),
                'not_found'   => __( 'YouTube returned 404 for this resource.', 'vector-youtube-gallery' ),
                'rate_limit'  => __( 'YouTube rate-limited this request.', 'vector-youtube-gallery' ),
                'transient'   => __( 'Transient YouTube API error — try again.', 'vector-youtube-gallery' ),
                'bad_request' => __( 'Invalid request to YouTube API.', 'vector-youtube-gallery' ),
                'unknown'     => __( 'Unknown YouTube API error.', 'vector-youtube-gallery' ),
            );
            $msg = $kind_label[ $kind ] ?? __( 'Unknown error.', 'vector-youtube-gallery' );
            echo '<div class="notice notice-error"><p>' . esc_html( $msg ) . '</p></div>';
            return;
        }

        if ( isset( $messages[ $notice ] ) ) {
            [ $msg, $cls ] = $messages[ $notice ];
            echo '<div class="notice notice-' . esc_attr( $cls ) . '"><p>';
            echo esc_html( $msg );
            echo '</p></div>';
        }
    }
}