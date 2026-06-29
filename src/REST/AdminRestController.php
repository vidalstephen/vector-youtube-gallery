<?php
/**
 * AdminRestController — Phase 6 admin REST endpoints.
 *
 * All endpoints under vyg/v1/admin/*, gated by:
 *  - permission_callback: manage_options capability
 *  - nonce verification (X-WP-Nonce header)
 *
 * Endpoints:
 *  GET  /vyg/v1/admin/stats         → counts + freshness snapshot
 *  POST /vyg/v1/admin/feeds        → create feed (JSON body)
 *  POST /vyg/v1/admin/feeds/(uuid) → update feed by uuid
 *  DEL  /vyg/v1/admin/feeds/(uuid) → delete feed by uuid
 *  GET  /vyg/v1/admin/feeds        → list feeds
 *  POST /vyg/v1/admin/disconnect   → DisconnectManager::disconnect_all
 *  POST /vyg/v1/admin/retention/run → DataRetentionManager::run_sweep
 *  POST /vyg/v1/admin/import-settings → ImporterExporter::import_settings
 *
 * Public reads are served by FeedController (no nonce, no cap).
 *
 * @package VectorYT\Gallery\REST
 */

declare(strict_types=1);

namespace VectorYT\Gallery\REST;

use VectorYT\Gallery\Repository\FeedRepository;
use VectorYT\Gallery\Repository\SourceRepository;
use VectorYT\Gallery\Compliance\DataRetentionManager;
use VectorYT\Gallery\Compliance\DisconnectManager;
use VectorYT\Gallery\Admin\ImporterExporter;
use VectorYT\Gallery\Admin\DashboardStats;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class AdminRestController {

    public const NAMESPACE_V1 = 'vyg/v1';

    public function __construct(
        private readonly FeedRepository $feeds,
        private readonly SourceRepository $sources,
        private readonly DataRetentionManager $retention,
        private readonly DisconnectManager $disconnector,
        private readonly ImporterExporter $importer_exporter,
        private readonly DashboardStats $stats,
        private readonly ?\VectorYT\Gallery\Repository\ImportLogRepository $import_log = null,
    ) {}

    public function register_routes(): void {
        add_action( 'rest_api_init', array( $this, 'do_register' ) );
    }

    public function do_register(): void {
        $cap = 'manage_options';

        register_rest_route( self::NAMESPACE_V1, '/admin/stats', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_stats' ),
            'permission_callback' => $this->cap_and_nonce( $cap ),
        ) );

        register_rest_route( self::NAMESPACE_V1, '/admin/feeds', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'list_feeds' ),
                'permission_callback' => $this->cap_and_nonce( $cap ),
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'create_feed' ),
                'permission_callback' => $this->cap_and_nonce( $cap ),
            ),
        ) );

        register_rest_route( self::NAMESPACE_V1, '/admin/feeds/(?P<uuid>[a-f0-9-]+)', array(
            array(
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => array( $this, 'update_feed' ),
                'permission_callback' => $this->cap_and_nonce( $cap ),
            ),
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => array( $this, 'delete_feed' ),
                'permission_callback' => $this->cap_and_nonce( $cap ),
            ),
        ) );

        register_rest_route( self::NAMESPACE_V1, '/admin/disconnect', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'disconnect' ),
            'permission_callback' => $this->cap_and_nonce( $cap ),
        ) );

        register_rest_route( self::NAMESPACE_V1, '/admin/retention/run', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'run_retention' ),
            'permission_callback' => $this->cap_and_nonce( $cap ),
        ) );

        register_rest_route( self::NAMESPACE_V1, '/admin/import-settings', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'import_settings' ),
            'permission_callback' => $this->cap_and_nonce( $cap ),
        ) );

        // Phase 8.5: feed export/import with conflict handling.
        register_rest_route( self::NAMESPACE_V1, '/admin/feeds/export', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'export_feeds' ),
            'permission_callback' => $this->cap_and_nonce( $cap ),
        ) );
        register_rest_route( self::NAMESPACE_V1, '/admin/feeds/import', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'import_feeds' ),
            'permission_callback' => $this->cap_and_nonce( $cap ),
        ) );

        // Phase 8.6: list recent import/export audit rows.
        register_rest_route( self::NAMESPACE_V1, '/admin/import-log', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'list_import_log' ),
            'permission_callback' => $this->cap_and_nonce( $cap ),
        ) );
        register_rest_route( self::NAMESPACE_V1, '/admin/import-log/(?P<id>\d+)', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_import_log' ),
            'permission_callback' => $this->cap_and_nonce( $cap ),
        ) );
    }

    /**
     * Build a permission_callback that requires both a capability AND a valid nonce.
     */
    private function cap_and_nonce( string $cap ): callable {
        return static function ( WP_REST_Request $request ) use ( $cap ) {
            if ( ! current_user_can( $cap ) ) {
                return new WP_Error( 'vyg_forbidden', __( 'Insufficient permissions.', 'vector-youtube-gallery' ), array( 'status' => 403 ) );
            }
            $nonce = $request->get_header( 'x_wp_nonce' );
            if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
                return new WP_Error( 'vyg_bad_nonce', __( 'Nonce check failed.', 'vector-youtube-gallery' ), array( 'status' => 403 ) );
            }
            return true;
        };
    }

    public function get_stats(): WP_REST_Response {
        return new WP_REST_Response( $this->stats->collect(), 200 );
    }

    public function list_feeds(): WP_REST_Response {
        return new WP_REST_Response( array(
            'feeds' => array_map( static function ( array $f ): array {
                $config = FeedRepository::decode_config( $f );
                return array(
                    'id'           => (int) $f['id'],
                    'feed_uuid'    => (string) $f['feed_uuid'],
                    'name'         => (string) $f['name'],
                    'feed_type'    => (string) $f['feed_type'],
                    'layout'       => (string) $f['layout'],
                    'status'       => (string) $f['status'],
                    'config'       => $config,
                    'created_at'   => (string) $f['created_at'],
                    'updated_at'   => (string) $f['updated_at'],
                );
            }, $this->feeds->list() ),
        ), 200 );
    }

    public function create_feed( WP_REST_Request $request ): WP_REST_Response {
        $body = (array) $request->get_json_params();
        $id   = $this->feeds->create( $this->sanitize_feed_body( $body ) );
        if ( $id <= 0 ) {
            return new WP_REST_Response( array( 'error' => 'create_failed' ), 500 );
        }
        $created = $this->feeds->find( $id );
        return new WP_REST_Response( array(
            'id'        => $id,
            'feed_uuid' => (string) ( $created['feed_uuid'] ?? '' ),
        ), 201 );
    }

    public function update_feed( WP_REST_Request $request ): WP_REST_Response {
        $uuid = (string) $request->get_param( 'uuid' );
        $feed = $this->feeds->find_by_uuid( $uuid );
        if ( ! $feed ) {
            return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
        }
        $body = (array) $request->get_json_params();
        $ok   = $this->feeds->update( (int) $feed['id'], $this->sanitize_feed_body( $body ) );
        return new WP_REST_Response( array( 'ok' => $ok ), $ok ? 200 : 400 );
    }

    public function delete_feed( WP_REST_Request $request ): WP_REST_Response {
        $uuid = (string) $request->get_param( 'uuid' );
        $feed = $this->feeds->find_by_uuid( $uuid );
        if ( ! $feed ) {
            return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
        }
        $ok = $this->feeds->delete( (int) $feed['id'] );
        return new WP_REST_Response( array( 'ok' => $ok ), $ok ? 200 : 400 );
    }

    public function disconnect(): WP_REST_Response {
        $result = $this->disconnector->disconnect_all();
        return new WP_REST_Response( $result, 200 );
    }

    public function run_retention(): WP_REST_Response {
        return new WP_REST_Response( $this->retention->run_sweep(), 200 );
    }

    public function import_settings( WP_REST_Request $request ): WP_REST_Response {
        $body   = (array) $request->get_json_params();
        $json   = (string) ( $body['json'] ?? '' );
        $result = $this->importer_exporter->import_settings( $json );
        $status = $result['ok'] ? 200 : 400;
        return new WP_REST_Response( $result, $status );
    }

    /**
     * Phase 8.5: export selected (or all) feeds as JSON.
     *
     * Body: { feed_uuids: [string,...] }  // empty = all feeds
     *
     * Returns: { json: string, count: int, kind: 'feeds', version: string }
     */
    public function export_feeds( WP_REST_Request $request ): WP_REST_Response {
        $body = (array) $request->get_json_params();
        $uuids = isset( $body['feed_uuids'] ) && is_array( $body['feed_uuids'] )
            ? array_values( array_filter( array_map( 'strval', $body['feed_uuids'] ) ) )
            : array();

        if ( empty( $uuids ) ) {
            $feeds = $this->feeds->list();
        } else {
            $feeds = array();
            foreach ( $uuids as $uuid ) {
                $row = $this->feeds->find_by_uuid( $uuid );
                if ( null !== $row ) {
                    $feeds[] = $row;
                }
            }
        }

        $json  = $this->importer_exporter->export_feeds( $feeds );
        $decoded = json_decode( $json, true );
        $version = is_array( $decoded ) ? (string) ( $decoded['version'] ?? '' ) : '';

        return new WP_REST_Response( array(
            'json'    => $json,
            'count'   => count( $feeds ),
            'kind'    => 'feeds',
            'version' => $version,
        ), 200 );
    }

    /**
     * Phase 8.5: import feeds from JSON with conflict handling.
     *
     * Body: { json: string, conflict: 'replace'|'duplicate'|'skip', force?: bool }
     *
     * Returns: { ok, imported, replaced, duplicated, skipped, errors, warnings }
     */
    public function import_feeds( WP_REST_Request $request ): WP_REST_Response {
        $body     = (array) $request->get_json_params();
        $json     = (string) ( $body['json']     ?? '' );
        $conflict = (string) ( $body['conflict'] ?? 'skip' );
        $force    = ! empty( $body['force'] );

        // Sanitize the conflict value to the known set; fall back to 'skip'.
        $allowed = array( 'replace', 'duplicate', 'skip' );
        if ( ! in_array( $conflict, $allowed, true ) ) {
            $conflict = 'skip';
        }

        // Phase 8.6: enforce size cap with HTTP 413 before we touch the parser.
        $size_cap = (int) apply_filters(
            'vyg_import_size_cap_bytes',
            ImporterExporter::DEFAULT_IMPORT_SIZE_CAP_BYTES
        );
        if ( strlen( $json ) > $size_cap ) {
            return new WP_REST_Response( array(
                'ok'      => false,
                'errors'  => array( sprintf(
                    'Payload too large: %d bytes (cap %d bytes).',
                    strlen( $json ),
                    $size_cap
                ) ),
                'warnings'=> array(),
            ), 413 );
        }

        $result = $this->importer_exporter->import_feeds( $json, array(
            'conflict'       => $conflict,
            'force'          => $force,
            'size_cap_bytes' => $size_cap,
        ) );
        $status = empty( $result['errors'] ) ? 200 : 400;
        return new WP_REST_Response( $result, $status );
    }

    /**
     * Phase 8.6: list recent import/export audit rows.
     *
     * Query params: per_page (default 25, max 200), page (default 1),
     *               op (import|export), kind (feeds).
     *
     * Returns: { items: [...], page, per_page, total }.
     */
    public function list_import_log( WP_REST_Request $request ): WP_REST_Response {
        if ( null === $this->import_log ) {
            return new WP_REST_Response( array(
                'items'    => array(),
                'page'     => 1,
                'per_page' => 25,
                'total'    => 0,
            ), 200 );
        }

        $per_page = (int) $request->get_param( 'per_page' );
        if ( $per_page < 1 ) {
            $per_page = 25;
        }
        $page = max( 1, (int) $request->get_param( 'page' ) );
        $op   = (string) $request->get_param( 'op' );

        $items = $this->import_log->list_recent( array(
            'per_page' => $per_page,
            'page'     => $page,
            'op'       => $op,
            'kind'     => 'feeds',
        ) );
        $total = $this->import_log->count( array( 'op' => $op, 'kind' => 'feeds' ) );

        return new WP_REST_Response( array(
            'items'    => $items,
            'page'     => $page,
            'per_page' => $per_page,
            'total'    => $total,
        ), 200 );
    }

    /**
     * Phase 8.6: get a single audit row by id.
     */
    public function get_import_log( WP_REST_Request $request ): WP_REST_Response {
        if ( null === $this->import_log ) {
            return new WP_REST_Response( array( 'error' => 'ImportLogRepository unavailable.' ), 503 );
        }
        $id  = (int) $request->get_param( 'id' );
        $row = $this->import_log->find( $id );
        if ( null === $row ) {
            return new WP_REST_Response( array( 'error' => 'Audit row not found.' ), 404 );
        }
        // Decode stored errors/warnings JSON for human-readable response.
        $row['errors_list']   = json_decode( (string) ( $row['errors_json']   ?? '[]' ), true ) ?: array();
        $row['warnings_list'] = json_decode( (string) ( $row['warnings_json'] ?? '[]' ), true ) ?: array();
        unset( $row['errors_json'], $row['warnings_json'] );
        return new WP_REST_Response( $row, 200 );
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function sanitize_feed_body( array $body ): array {
        $out = array();
        if ( isset( $body['name'] ) )         { $out['name']         = sanitize_text_field( (string) $body['name'] ); }
        if ( isset( $body['feed_type'] ) )    { $out['feed_type']    = sanitize_key( (string) $body['feed_type'] ); }
        if ( isset( $body['layout'] ) )       { $out['layout']       = sanitize_key( (string) $body['layout'] ); }
        if ( isset( $body['status'] ) )       { $out['status']       = sanitize_key( (string) $body['status'] ); }
        if ( isset( $body['custom_css'] ) )   { $out['custom_css']   = (string) $body['custom_css']; }
        if ( isset( $body['source_config'] ) && is_array( $body['source_config'] ) ) {
            $out['source_config_json'] = $body['source_config'];
        }
        if ( isset( $body['display_config'] ) && is_array( $body['display_config'] ) ) {
            $out['display_config_json'] = $body['display_config'];
        }
        if ( isset( $body['filter_config'] ) && is_array( $body['filter_config'] ) ) {
            $out['filter_config_json'] = $body['filter_config'];
        }
        if ( isset( $body['sort_config'] ) && is_array( $body['sort_config'] ) ) {
            $out['sort_config_json'] = $body['sort_config'];
        }
        return $out;
    }
}