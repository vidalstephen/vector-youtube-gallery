<?php
/**
 * Sources admin page — list, add, validate.
 *
 * Phase 1: lets admins add Channel / Playlist / Single-video sources.
 * Validation calls the matching resolver; if it returns a resource, we record
 * it (Phase 1 in an option array; Phase 2 in the vyg_sources table).
 *
 * Until the DB schema lands (Phase 2), sources live in an option array
 * `vyg_sources_draft` so the UI can be tested end-to-end without schema.
 *
 * @package VectorYT\Gallery\Admin
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Admin;

use VectorYT\Gallery\Logging\Logger;
use VectorYT\Gallery\Settings\SecretsRepository;
use VectorYT\Gallery\YouTube\ApiException;
use VectorYT\Gallery\YouTube\ChannelResolver;
use VectorYT\Gallery\YouTube\PlaylistResolver;
use VectorYT\Gallery\YouTube\VideoMetadataFetcher;

defined( 'ABSPATH' ) || exit;

final class SourcesPage {

    private const NONCE_ACTION     = 'vyg_sources_action';
    private const DRAFT_OPTION_KEY = 'vyg_sources_draft';

    public function __construct(
        private readonly ChannelResolver $channels,
        private readonly PlaylistResolver $playlists,
        private readonly VideoMetadataFetcher $videos,
        private readonly SecretsRepository $secrets,
        private readonly Logger $logger,
    ) {}

    public function render(): void {
        if ( ! current_user_can( AdminMenu::REQUIRED_CAP ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'vector-youtube-gallery' ) );
        }

        if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
            $this->maybe_handle_post();
        }

        $this->render_html( $this->load_draft_sources() );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function load_draft_sources(): array {
        $val = get_option( self::DRAFT_OPTION_KEY, array() );
        return is_array( $val ) ? array_values( $val ) : array();
    }

    private function save_draft_sources( array $sources ): void {
        update_option( self::DRAFT_OPTION_KEY, array_values( $sources ), false );
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
        }
    }

    private function handle_add(): void {
        if ( ! $this->secrets->has_api_key() && 'mock' !== $this->api_mode() ) {
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

        $sources   = $this->load_draft_sources();
        $sources[] = array(
            'uuid'         => wp_generate_uuid4(),
            'source_type'  => $type,
            'input'        => $raw,
            'youtube_id'   => $this->extract_youtube_id( $type, $resource ),
            'title'        => $this->extract_title( $type, $resource ),
            'thumbnail'    => $this->extract_thumbnail( $type, $resource ),
            'added_at'     => gmdate( 'c' ),
        );
        $this->save_draft_sources( $sources );
        $this->logger->info( 'Source added (draft, pre-schema)', array(
            'type' => $type,
            'id'   => $this->extract_youtube_id( $type, $resource ),
        ) );
        $this->redirect_with_notice( 'added' );
    }

    private function handle_delete(): void {
        $uuid = isset( $_POST['source_uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['source_uuid'] ) ) : '';
        $sources = array_values( array_filter(
            $this->load_draft_sources(),
            static fn( array $s ): bool => $s['uuid'] !== $uuid
        ) );
        $this->save_draft_sources( $sources );
        $this->redirect_with_notice( 'deleted' );
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
            'channel'  => (string) ( $resource['id'] ?? '' ),
            'playlist' => (string) ( $resource['id'] ?? '' ),
            'video'    => (string) ( $resource['id'] ?? '' ),
            default    => '',
        };
    }

    private function extract_title( string $type, array $resource ): string {
        return (string) ( $resource['snippet']['title'] ?? '' );
    }

    private function extract_thumbnail( string $type, array $resource ): string {
        return (string) ( $resource['snippet']['thumbnails']['default']['url'] ?? '' );
    }

    private function api_mode(): string {
        return defined( 'VYG_USE_MOCK' ) && VYG_USE_MOCK ? 'mock' : 'api_key';
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

            <?php if ( ! $this->secrets->has_api_key() && 'mock' !== $this->api_mode() ) : ?>
                <div class="notice notice-warning">
                    <p>
                        <?php
                        echo wp_kses_post(
                            sprintf(
                                /* translators: %s: settings page URL */
                                __( 'No API key configured. Add one on the <a href="%s">Settings page</a> first.', 'vector-youtube-gallery' ),
                                esc_url( admin_url( 'admin.php?page=' . AdminMenu::PARENT_SLUG . '-settings' ) )
                            )
                        );
                        ?>
                    </p>
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

            <h2><?php echo esc_html__( 'Current Sources', 'vector-youtube-gallery' ); ?></h2>
            <?php if ( 0 === count( $sources ) ) : ?>
                <p><?php echo esc_html__( 'No sources yet. Add one above.', 'vector-youtube-gallery' ); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__( 'Type', 'vector-youtube-gallery' ); ?></th>
                            <th><?php echo esc_html__( 'Title', 'vector-youtube-gallery' ); ?></th>
                            <th><?php echo esc_html__( 'YouTube ID', 'vector-youtube-gallery' ); ?></th>
                            <th><?php echo esc_html__( 'Added', 'vector-youtube-gallery' ); ?></th>
                            <th><?php echo esc_html__( 'Actions', 'vector-youtube-gallery' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $sources as $s ) : ?>
                            <tr>
                                <td><?php echo esc_html( (string) ( $s['source_type'] ?? '' ) ); ?></td>
                                <td><?php echo esc_html( (string) ( $s['title'] ?? '' ) ); ?></td>
                                <td><code><?php echo esc_html( (string) ( $s['youtube_id'] ?? '' ) ); ?></code></td>
                                <td><?php echo esc_html( (string) ( $s['added_at'] ?? '' ) ); ?></td>
                                <td>
                                    <form method="post" style="display:inline">
                                        <?php wp_nonce_field( self::NONCE_ACTION, 'vyg_sources_nonce' ); ?>
                                        <input type="hidden" name="vyg_op" value="delete" />
                                        <input type="hidden" name="source_uuid" value="<?php echo esc_attr( (string) ( $s['uuid'] ?? '' ) ); ?>" />
                                        <button type="submit" class="button button-link-delete">
                                            <?php echo esc_html__( 'Delete', 'vector-youtube-gallery' ); ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description">
                    <?php echo esc_html__( 'These sources are stored in a draft option for Phase 1. Phase 2 will move them into the vyg_sources custom table with sync scheduling.', 'vector-youtube-gallery' ); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_notice( string $notice ): void {
        $messages = array(
            'added'           => __( 'Source added successfully.', 'vector-youtube-gallery' ),
            'deleted'         => __( 'Source deleted.', 'vector-youtube-gallery' ),
            'no_api_key'      => __( 'Add an API key first on the Settings page.', 'vector-youtube-gallery' ),
            'not_found'       => __( 'YouTube returned no matching resource for that identifier.', 'vector-youtube-gallery' ),
            'parse_error'     => __( 'Could not parse the identifier — check the format.', 'vector-youtube-gallery' ),
        );
        $classes = array(
            'added'           => 'success',
            'deleted'         => 'success',
            'no_api_key'      => 'warning',
            'not_found'       => 'error',
            'parse_error'     => 'error',
        );

        // Map API error kinds.
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
            $cls = 'error';
            echo '<div class="notice notice-' . esc_attr( $cls ) . '"><p>' . esc_html( $msg ) . '</p></div>';
            return;
        }

        if ( isset( $messages[ $notice ] ) ) {
            echo '<div class="notice notice-' . esc_attr( $classes[ $notice ] ?? 'info' ) . '"><p>';
            echo esc_html( $messages[ $notice ] );
            echo '</p></div>';
        }
    }
}