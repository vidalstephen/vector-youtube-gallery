<?php
/**
 * REST API endpoint for front-end load-more pagination.
 *
 * Route: GET /wp-json/vyg/v1/feed
 * Auth:  public_read permission callback (uses nonce via X-WP-Nonce header)
 *
 * @package VectorYT\Gallery\REST
 */

declare(strict_types=1);

namespace VectorYT\Gallery\REST;

use VectorYT\Gallery\Render\Renderer;

defined( 'ABSPATH' ) || exit;

final class FeedController {

    public const NAMESPACE_V1 = 'vyg/v1';

    public function __construct(
        private readonly Renderer $renderer,
    ) {}

    public function register_routes(): void {
        add_action( 'rest_api_init', array( $this, 'register' ) );
    }

    public function register(): void {
        register_rest_route( self::NAMESPACE_V1, '/feed', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_feed' ),
            'permission_callback' => '__return_true',  // nonce-protected via X-WP-Nonce; data is public anyway.
            'args'                => array(
                'source_uuid'  => array(
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'layout'       => array(
                    'type'              => 'string',
                    'default'           => 'grid',
                    'sanitize_callback' => 'sanitize_key',
                ),
                'content_type' => array(
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'orderby'      => array(
                    'type'              => 'string',
                    'default'           => 'published_at',
                    'sanitize_callback' => 'sanitize_key',
                ),
                'order'        => array(
                    'type'              => 'string',
                    'default'           => 'DESC',
                    'sanitize_callback' => 'sanitize_key',
                ),
                'per_page'     => array(
                    'type'              => 'integer',
                    'default'           => 12,
                    'sanitize_callback' => 'absint',
                ),
                'offset'       => array(
                    'type'              => 'integer',
                    'default'           => 0,
                    'sanitize_callback' => 'absint',
                ),
            ),
        ) );

        // Phase 8.4: public feed-by-uuid route. Saves mixed-source feeds and
        // exposes only public-safe fields. Anonymous-readable; nonce-protected
        // via X-WP-Nonce to align with the rest of the plugin.
        register_rest_route( self::NAMESPACE_V1, '/feed/(?P<uuid>[a-f0-9-]+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_feed_by_uuid' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'layout'       => array(
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_key',
                ),
                'content_type' => array(
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'orderby'      => array(
                    'type'              => 'string',
                    'default'           => 'published_at',
                    'sanitize_callback' => 'sanitize_key',
                ),
                'order'        => array(
                    'type'              => 'string',
                    'default'           => 'DESC',
                    'sanitize_callback' => 'sanitize_key',
                ),
                'per_page'     => array(
                    'type'              => 'integer',
                    'default'           => 12,
                    'sanitize_callback' => 'absint',
                ),
                'offset'       => array(
                    'type'              => 'integer',
                    'default'           => 0,
                    'sanitize_callback' => 'absint',
                ),
            ),
        ) );
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function handle_feed( $request ) {
        $source_uuid = (string) $request->get_param( 'source_uuid' );
        $per_page    = max( 1, (int) $request->get_param( 'per_page' ) );
        $offset      = max( 0, (int) $request->get_param( 'offset' ) );

        $html = $this->renderer->render( array(
            'source_uuid'  => $source_uuid,
            'layout'       => (string) $request->get_param( 'layout' ),
            'content_type' => (string) $request->get_param( 'content_type' ),
            'orderby'      => (string) $request->get_param( 'orderby' ),
            'order'        => (string) $request->get_param( 'order' ),
            'per_page'     => $per_page,
            'offset'       => $offset,
        ) );

        // We don't know the exact total from the renderer alone; the front-end
        // derives has_more by checking whether the returned HTML contains more
        // <article> nodes than expected. Simpler: also include the next_offset
        // so the JS can determine whether to keep paging.
        $next_offset = $offset + $per_page;
        return rest_ensure_response( array(
            'html'         => $html,
            'has_more'     => $this->has_more( $source_uuid, $next_offset, (string) $request->get_param( 'content_type' ), $per_page ),
            'next_offset'  => $next_offset,
            'remaining'    => $this->remaining( $source_uuid, $next_offset, (string) $request->get_param( 'content_type' ), $per_page ),
        ) );
    }

    private function has_more( string $source_uuid, int $next_offset, string $content_type, int $per_page ): bool {
        return $this->remaining( $source_uuid, $next_offset, $content_type, $per_page ) > 0;
    }

    private function remaining( string $source_uuid, int $offset, string $content_type, int $per_page ): int {
        $container = \VectorYT\Gallery\Plugin::container();
        if ( null === $container ) {
            return 0;
        }
        /** @var \VectorYT\Gallery\Render\FeedQuery $feed */
        $feed = $container->get( 'render.feed' );
        $total = $feed->count_videos_for_source( array(
            'source_uuid'  => $source_uuid,
            'content_type' => $content_type,
        ) );
        return max( 0, $total - $offset );
    }

    /**
     * Phase 8.4: Resolve a saved mixed-source feed by uuid and return a
     * public-safe render. Internal source IDs, manual video IDs, exclude
     * lists, custom CSS, and admin-only metadata are NEVER included in
     * the response payload.
     *
     * Anonymous-readable; matches the single-source endpoint's auth model.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function handle_feed_by_uuid( $request ) {
        $uuid = (string) $request->get_param( 'uuid' );
        if ( '' === $uuid || ! preg_match( '/^[a-f0-9-]{1,64}$/', $uuid ) ) {
            return new \WP_Error( 'vyg_invalid_uuid', __( 'Invalid feed UUID.', 'vector-youtube-gallery' ), array( 'status' => 400 ) );
        }

        $container = \VectorYT\Gallery\Plugin::container();
        if ( null === $container ) {
            return new \WP_Error( 'vyg_no_container', __( 'Plugin container unavailable.', 'vector-youtube-gallery' ), array( 'status' => 500 ) );
        }

        /** @var \VectorYT\Gallery\Repository\FeedRepository $feeds */
        $feeds = $container->get( 'repo.feeds' );
        $feed_row = $feeds->find_by_uuid( $uuid );
        if ( null === $feed_row ) {
            return new \WP_Error( 'vyg_feed_not_found', __( 'Feed not found.', 'vector-youtube-gallery' ), array( 'status' => 404 ) );
        }
        if ( ! in_array( (string) ( $feed_row['status'] ?? '' ), array( 'published', 'publish' ), true ) ) {
            return new \WP_Error( 'vyg_feed_not_published', __( 'Feed is not published.', 'vector-youtube-gallery' ), array( 'status' => 404 ) );
        }

        $config   = \VectorYT\Gallery\Repository\FeedRepository::decode_config( $feed_row );
        $src_cfg  = isset( $config['source'] ) && is_array( $config['source'] ) ? $config['source'] : array();
        $display  = isset( $config['display'] ) && is_array( $config['display'] ) ? $config['display'] : array();
        $filter   = isset( $config['filter'] )  && is_array( $config['filter'] )  ? $config['filter']  : array();
        $sort     = isset( $config['sort'] )    && is_array( $config['sort'] )    ? $config['sort']    : array();

        // Layout: query param overrides the stored layout (useful for ?layout=list
        // on a feed whose default layout is grid); falls back to the stored value.
        $layout_param = (string) $request->get_param( 'layout' );
        $layout_slug  = '' !== $layout_param
            ? sanitize_key( $layout_param )
            : sanitize_key( (string) ( $feed_row['layout'] ?? 'grid' ) );
        if ( ! isset( \VectorYT\Gallery\Render\Renderer::LAYOUTS[ $layout_slug ] ) ) {
            $layout_slug = sanitize_key( (string) ( $feed_row['layout'] ?? 'grid' ) );
        }

        $per_page = max( 1, min( 200, (int) $request->get_param( 'per_page' ) ) );
        $offset   = max( 0, (int) $request->get_param( 'offset' ) );

        $html = $this->renderer->render( array(
            'source_uuid'   => '',
            'source_config' => $src_cfg,
            'feed_uuid'     => (string) $feed_row['feed_uuid'],
            'layout'        => $layout_slug,
            'content_type'  => (string) $request->get_param( 'content_type' ),
            'orderby'       => (string) $request->get_param( 'orderby' ),
            'order'         => (string) $request->get_param( 'order' ),
            'per_page'      => $per_page,
            'offset'        => $offset,
            'wrapper_id'    => 'vyg-feed-' . substr( (string) $feed_row['feed_uuid'], 0, 8 ),
            // Phase 8.4 public-safe mode: omit internal source_uuid from
            // rendered attributes. The front-end JS only needs feed_uuid to
            // call back into this endpoint.
            'public_safe'   => true,
        ) );

        $next_offset = $offset + $per_page;
        $remaining   = $this->remaining_for_feed( $src_cfg, $next_offset, $display, $filter );

        $public_status = in_array( (string) ( $feed_row['status'] ?? '' ), array( 'published', 'publish' ), true )
            ? 'published'
            : (string) ( $feed_row['status'] ?? '' );

        return rest_ensure_response( array(
            'html'        => $html,
            'has_more'    => $remaining > 0,
            'next_offset' => $next_offset,
            'remaining'   => $remaining,
            'feed'        => array(
                'feed_uuid' => (string) $feed_row['feed_uuid'],
                'name'      => (string) ( $feed_row['name'] ?? '' ),
                'layout'    => $layout_slug,
                'status'    => $public_status,
            ),
        ) );
    }

    /**
     * @param array<string,mixed> $source_config
     * @param array<string,mixed> $display
     * @param array<string,mixed> $filter
     */
    private function remaining_for_feed( array $source_config, int $offset, array $display, array $filter ): int {
        $container = \VectorYT\Gallery\Plugin::container();
        if ( null === $container ) {
            return 0;
        }
        /** @var \VectorYT\Gallery\Render\FeedQuery $feed */
        $feed = $container->get( 'render.feed' );
        $per_page = isset( $display['per_page'] ) ? max( 1, (int) $display['per_page'] ) : 12;
        $content_type = isset( $filter['content_type'] ) ? (string) $filter['content_type'] : '';
        $total = $feed->count_videos_for_feed( array(
            'source'       => $source_config,
            'content_type' => $content_type,
        ) );
        return max( 0, $total - $offset );
    }
}