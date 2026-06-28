<?php
/**
 * Shorts layout — vertical-friendly grid optimized for Shorts content.
 *
 * Phase 4 stub: renders videos with content_type short_confirmed or
 * short_candidate in a tight 2-column (mobile) / 5-column (desktop) grid.
 *
 * @package VectorYT\Gallery\Render\Layouts
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Render\Layouts;

defined( 'ABSPATH' ) || exit;

final class ShortsLayout implements LayoutInterface {

    public static function slug(): string {
        return 'shorts';
    }

    public static function label(): string {
        return __( 'Shorts (vertical)', 'vector-youtube-gallery' );
    }

    public function render( array $ctx ): string {
        return ( new \VectorYT\Gallery\Render\TemplateLoader() )
            ->render( 'shorts', array(
                'source'   => $ctx['source'] ?? array(),
                'videos'   => $ctx['videos'] ?? array(),
                'attrs'    => $ctx['attrs'] ?? array(),
                'renderer' => $ctx['renderer'] ?? null,
            ) );
    }
}