<?php
/**
 * Masonry layout — responsive waterfall using CSS columns.
 *
 * Strategy: CSS `column-count` + `break-inside: avoid` on cards. This is the
 * CSS-first approach (no JS layout dependency, no FLIP calculations). Trade-off:
 * cards fill columns top-to-bottom rather than left-to-right, but the
 * implementation is robust, fast, and works without JavaScript.
 *
 * Phase 9 introduced this as an explicit alternative to the grid layout. A
 * future iteration can swap in a JS layout engine if needed; the template
 * contract stays identical.
 *
 * Template: src/Render/templates/masonry.php
 *
 * @package VectorYT\Gallery\Render\Layouts
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Render\Layouts;

defined('ABSPATH') || exit;

final class MasonryLayout implements LayoutInterface {

    public static function slug(): string {
        return 'masonry';
    }

    public static function label(): string {
        return __('Masonry (waterfall)', 'vector-youtube-gallery');
    }

    public function render(array $ctx): string {
        return (new \VectorYT\Gallery\Render\TemplateLoader())
            ->render('masonry', array(
                'source'   => $ctx['source'] ?? array(),
                'videos'   => $ctx['videos'] ?? array(),
                'attrs'    => $ctx['attrs'] ?? array(),
                'renderer' => $ctx['renderer'] ?? null,
            ));
    }
}
