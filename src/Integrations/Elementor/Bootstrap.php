<?php
/**
 * Elementor Bootstrap — registers the Vector YouTube Gallery widget when
 * Elementor is loaded.
 *
 * Elementor expects widgets to register their class via
 * `elementor/widgets/register` (newer Elementor) or `elementor/widgets/widgets_registered`
 * (older). We hook the modern one. The widget class file is conditionally
 * required (gated on Elementor being active) so the plugin file does not
 * crash when Elementor isn't installed.
 *
 * @package VectorYT\Gallery\Integrations\Elementor
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Integrations\Elementor;

defined('ABSPATH') || exit;

final class Bootstrap {

    public function register_hooks(): void {
        add_action('elementor/widgets/register', array($this, 'register_widget'));
    }

    /**
     * The actual widget file is only loaded if Elementor has been
     * activated. We check via `class_exists` rather than `did_action`
     * because by the time `widgets/register` fires, Elementor's
     * autoloader is reliably present.
     */
    public function register_widget(): void {
        if (! class_exists('\Elementor\Widget_Base')) {
            return;
        }
        require_once __DIR__ . '/GalleryWidget.php';

        if (! class_exists(__NAMESPACE__ . '\\GalleryWidget')) {
            return;
        }
        // Elementor 3.0+ exposes the plugin via \Elementor\Plugin::instance().
        // Some integrations fork Elementor with a different bootstrap, so
        // we defend: if Plugin isn't available, skip registration rather
        // than crash the editor load.
        if (! class_exists('\Elementor\Plugin')) {
            return;
        }
        \Elementor\Plugin::instance()
            ->widgets_manager
            ->register(new GalleryWidget());
    }
}
