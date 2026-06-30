<?php
/**
 * Divi Bootstrap — registers the Vector YouTube Gallery Divi module.
 *
 * @package VectorYT\Gallery\Integrations\Divi
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Integrations\Divi;

defined('ABSPATH') || exit;

final class Bootstrap {

    public function register_hooks(): void {
        // Divi modules are loaded via the action `et_builder_modules_loaded`
        // for some integrations; the older mechanism is `divi_modules`.
        // We try both.
        add_action('et_builder_modules_loaded', array($this, 'register_module'));
        add_action('divi_modules', array($this, 'register_module'));
    }

    public function register_module(): void {
        if (! class_exists('ET_Builder_Module')) {
            return;
        }
        require_once __DIR__ . '/GalleryModule.php';
        if (! class_exists(__NAMESPACE__ . '\\GalleryModule')) {
            return;
        }
        new GalleryModule();
    }
}
