<?php
/**
 * Carousel/slider layout — accessible horizontal slider.
 *
 * Vanilla JS, no jQuery. Keyboard navigation (ArrowLeft / ArrowRight / Home / End),
 * touch swipe support, and respects prefers-reduced-motion (falls back to
 * non-animated jumps). Each slide is a `<li>` inside a horizontally scrollable
 * `<ul>` styled to snap to the start of each card.
 *
 * Server-side concerns:
 *   - Emits accessible `<button>` prev/next controls with aria-labels.
 *   - Renders a "slide status" live region that announces the current slide.
 *   - Provides `data-slide-count` so the JS can wire up arrow buttons + dots.
 *
 * Template: src/Render/templates/carousel.php
 *
 * @package VectorYT\Gallery\Render\Layouts
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Render\Layouts;

defined('ABSPATH') || exit;

final class CarouselLayout implements LayoutInterface {

    public static function slug(): string {
        return 'carousel';
    }

    public static function label(): string {
        return __('Carousel (horizontal slider)', 'vector-youtube-gallery');
    }

    public function render(array $ctx): string {
        return (new \VectorYT\Gallery\Render\TemplateLoader())
            ->render('carousel', array(
                'source'   => $ctx['source'] ?? array(),
                'videos'   => $ctx['videos'] ?? array(),
                'attrs'    => $ctx['attrs'] ?? array(),
                'renderer' => $ctx['renderer'] ?? null,
            ));
    }
}
