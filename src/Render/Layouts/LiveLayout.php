<?php
/**
 * Live layout — special rendering for live broadcasts (active + replay).
 *
 * Phase 5 wires LiveQuery so the layout pulls 3 buckets: live, upcoming, ended.
 *
 * @package VectorYT\Gallery\Render\Layouts
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Render\Layouts;

use VectorYT\Gallery\Render\LiveQuery;

defined( 'ABSPATH' ) || exit;

class LiveLayout implements LayoutInterface {

    public function __construct(
        private readonly LiveQuery $live_query,
    ) {}

    public static function slug(): string {
        return 'live';
    }

    public static function label(): string {
        return __( 'Live (active + replay)', 'vector-youtube-gallery' );
    }

    public function render( array $ctx ): string {
        $source = $ctx['source'] ?? array();
        $attrs  = $ctx['attrs'] ?? array();
        $limit  = isset( $attrs['per_page'] ) ? max( 1, (int) $attrs['per_page'] ) : 12;

        $buckets = $this->live_query->buckets_for_source( $source );

        // Truncate upcoming + replay so per_page stays consistent.
        $buckets['upcoming'] = array_slice( $buckets['upcoming'], 0, max( 0, $limit - count( $buckets['live'] ) ) );
        $buckets['replay']   = array_slice( $buckets['replay'],   0, max( 0, $limit - count( $buckets['live'] ) - count( $buckets['upcoming'] ) ) );

        return ( new \VectorYT\Gallery\Render\TemplateLoader() )
            ->render( 'live', array(
                'source'   => $source,
                'buckets'  => $buckets,
                'renderer' => $ctx['renderer'] ?? null,
                'attrs'    => $attrs,
            ) );
    }
}