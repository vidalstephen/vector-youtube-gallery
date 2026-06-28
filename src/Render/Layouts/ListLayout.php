<?php
/**
 * List layout — single-column list with title, channel, duration, view count.
 *
 * @package VectorYT\Gallery\Render\Layouts
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Render\Layouts;

defined( 'ABSPATH' ) || exit;

final class ListLayout implements LayoutInterface {

    public static function slug(): string {
        return 'list';
    }

    public static function label(): string {
        return __( 'List', 'vector-youtube-gallery' );
    }

    public function render( array $ctx ): string {
        return ( new \VectorYT\Gallery\Render\TemplateLoader() )
            ->render( 'list', array(
                'source'   => $ctx['source'] ?? array(),
                'videos'   => $ctx['videos'] ?? array(),
                'attrs'    => $ctx['attrs'] ?? array(),
                'renderer' => $ctx['renderer'] ?? null,
            ) );
    }
}