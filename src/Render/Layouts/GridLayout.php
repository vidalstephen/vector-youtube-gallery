<?php
/**
 * Grid layout — default responsive grid of thumbnails.
 *
 * Template: src/Render/templates/grid.php
 *
 * @package VectorYT\Gallery\Render\Layouts
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Render\Layouts;

defined( 'ABSPATH' ) || exit;

final class GridLayout implements LayoutInterface {

    public static function slug(): string {
        return 'grid';
    }

    public static function label(): string {
        return __( 'Grid', 'vector-youtube-gallery' );
    }

    public function render( array $ctx ): string {
        return ( new \VectorYT\Gallery\Render\TemplateLoader() )
            ->render( 'grid', array(
                'source'    => $ctx['source'] ?? array(),
                'videos'    => $ctx['videos'] ?? array(),
                'attrs'     => $ctx['attrs'] ?? array(),
                'renderer'  => $ctx['renderer'] ?? null,
            ) );
    }
}