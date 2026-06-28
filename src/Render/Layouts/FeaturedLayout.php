<?php
/**
 * Featured layout — first video large, rest in a grid.
 *
 * @package VectorYT\Gallery\Render\Layouts
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Render\Layouts;

defined( 'ABSPATH' ) || exit;

final class FeaturedLayout implements LayoutInterface {

    public static function slug(): string {
        return 'featured';
    }

    public static function label(): string {
        return __( 'Featured (hero + grid)', 'vector-youtube-gallery' );
    }

    public function render( array $ctx ): string {
        return ( new \VectorYT\Gallery\Render\TemplateLoader() )
            ->render( 'featured', array(
                'source'   => $ctx['source'] ?? array(),
                'videos'   => $ctx['videos'] ?? array(),
                'attrs'    => $ctx['attrs'] ?? array(),
                'renderer' => $ctx['renderer'] ?? null,
            ) );
    }
}