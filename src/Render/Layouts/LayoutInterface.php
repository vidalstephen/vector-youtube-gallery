<?php
/**
 * Layout interface — every layout produces HTML from a normalized input.
 *
 * The VideoRenderer calls LayoutInterface::render($ctx) with a $ctx array
 * containing: source, videos, attributes (shortcode/block attrs), and a
 * TemplateLoader for theme overrides.
 *
 * @package VectorYT\Gallery\Render\Layouts
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Render\Layouts;

defined( 'ABSPATH' ) || exit;

interface LayoutInterface {

    /**
     * @param array<string,mixed> $ctx
     * @return string HTML
     */
    public function render( array $ctx ): string;

    /**
     * Return the slug used to select this layout (matches shortcode/block attr).
     */
    public static function slug(): string;

    /**
     * Human-readable label for the block inspector dropdown.
     */
    public static function label(): string;
}