<?php
/**
 * Hero layout — large single "featured" video at top + smaller grid below.
 *
 * Distinct from Phase 4's `featured` layout (which is hero + grid with the
 * hero card just slightly larger). The `hero` layout here commits to a wide,
 * large-aspect-ratio primary embed, with secondary metadata (title, channel,
 * published_at, description_excerpt) visible alongside, then below a compact
 * grid of the rest of the feed.
 *
 * Template: src/Render/templates/hero.php
 *
 * @package VectorYT\Gallery\Render\Layouts
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Render\Layouts;

defined('ABSPATH') || exit;

final class HeroLayout implements LayoutInterface {

    public static function slug(): string {
        return 'hero';
    }

    public static function label(): string {
        return __('Hero (featured video + gallery)', 'vector-youtube-gallery');
    }

    public function render(array $ctx): string {
        return (new \VectorYT\Gallery\Render\TemplateLoader())
            ->render('hero', array(
                'source'   => $ctx['source'] ?? array(),
                'videos'   => $ctx['videos'] ?? array(),
                'attrs'    => $ctx['attrs'] ?? array(),
                'renderer' => $ctx['renderer'] ?? null,
            ));
    }
}
