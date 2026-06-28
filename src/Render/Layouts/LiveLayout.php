<?php
/**
 * Live layout — special rendering for live broadcasts (active + replay).
 *
 * Phase 4 stub: shows live_status='live' videos first, then recently-ended.
 * Phase 5 will wire the LiveStatusPollJob; for now the layout just renders
 * whatever the FeedQuery returns.
 *
 * @package VectorYT\Gallery\Render\Layouts
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Render\Layouts;

defined( 'ABSPATH' ) || exit;

final class LiveLayout implements LayoutInterface {

    public static function slug(): string {
        return 'live';
    }

    public static function label(): string {
        return __( 'Live (active + replay)', 'vector-youtube-gallery' );
    }

    public function render( array $ctx ): string {
        return ( new \VectorYT\Gallery\Render\TemplateLoader() )
            ->render( 'live', array(
                'source'   => $ctx['source'] ?? array(),
                'videos'   => $ctx['videos'] ?? array(),
                'attrs'    => $ctx['attrs'] ?? array(),
                'renderer' => $ctx['renderer'] ?? null,
            ) );
    }
}