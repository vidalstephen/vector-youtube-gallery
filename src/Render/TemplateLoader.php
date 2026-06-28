<?php
/**
 * Template loader — allow themes to override the bundled templates.
 *
 * Theme override path: <theme>/vector-youtube-gallery/<template>.php
 * If no override exists, fall back to the bundled templates under
 * `src/Render/templates/`.
 *
 * Phase 4 ships 5 layout templates: grid, list, featured, shorts, live.
 * Phase 5+ adds: single, masonry (Phase 6.5), carousel (Phase 6.5).
 *
 * @package VectorYT\Gallery\Render
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Render;

defined( 'ABSPATH' ) || exit;

final class TemplateLoader {

    /**
     * Locate a template path. Returns the most-specific available path.
     *
     * Search order:
     *   1. <theme>/vector-youtube-gallery/<name>.php
     *   2. <stylesheet>/vector-youtube-gallery/<name>.php
     *   3. <plugin>/src/Render/templates/<name>.php
     */
    public function locate( string $name ): string {
        if ( '' === $name ) {
            return '';
        }
        // Sanitize: no traversal.
        $name = str_replace( array( '..', '/', '\\' ), '', $name );

        $theme_root  = get_template_directory();
        $child_root  = get_stylesheet_directory();
        $plugin_tpl  = VYG_PLUGIN_DIR . 'src/Render/templates/' . $name . '.php';

        $candidates = array(
            $child_root  . '/vector-youtube-gallery/' . $name . '.php',
            $theme_root  . '/vector-youtube-gallery/' . $name . '.php',
            $plugin_tpl,
        );

        foreach ( $candidates as $path ) {
            if ( is_readable( $path ) ) {
                return $path;
            }
        }
        return '';
    }

    /**
     * Render a template with $context as the variable scope.
     *
     * @param string $name    Template slug (without .php).
     * @param array<string,mixed> $context Variables exposed to the template.
     * @param bool $echo If true, prints; if false, returns string.
     * @return string The rendered HTML (empty if template not found).
     */
    public function render( string $name, array $context = array(), bool $echo = false ): string {
        $path = $this->locate( $name );
        if ( '' === $path ) {
            return '';
        }
        // Phases 6+ will define esc_attr, etc. here for templates that need them.
        // The bundled templates already escape inline, but we'll harden in 6.5.
        if ( ! $echo ) {
            ob_start();
            // phpcs:ignore WordPress.PHP.DontExtract -- intentional template scope.
            extract( $context, EXTR_SKIP );
            include $path;
            return (string) ob_get_clean();
        }
        // phpcs:ignore WordPress.PHP.DontExtract -- intentional template scope.
        extract( $context, EXTR_SKIP );
        include $path;
        return '';
    }
}