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
}