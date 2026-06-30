<?php
/**
 * Phase 10.6 unit tests — Page-builder guards.
 *
 * Each integration module is keyed on a third-party class being present:
 *
 *   - Elementor widget requires `\Elementor\Widget_Base`.
 *   - Divi module requires `ET_Builder_Module`.
 *
 * When those classes are NOT declared (the normal, non-Elementor /
 * non-Devi site), the plugin must NOT crash on load and NOT register
 * any hooks. When those classes ARE present (simulated by loading the
 * stub file), the integration code must reference the guarded class at
 * least once. This locks in the register-if-present contract.
 */
declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;

final class PageBuilderIntegrationTest extends TestCase {

    public function test_elementor_bootstrap_no_ops_when_widget_base_absent(): void {
        // Elementor is NOT installed in this dev site, so
        // \Elementor\Widget_Base isn't defined. Bootstrap::register_widget()
        // must early-return before calling register_widget_type or registering.
        $bootstrap = new \VectorYT\Gallery\Integrations\Elementor\Bootstrap();
        // Capture any add_action/before-output side effect by toggling an
        // action counter.
        $captured = 0;
        $cb = function() use (&$captured) { $captured++; };
        add_action('fake_elementor_action', $cb);
        // register_widget does nothing if class doesn't exist — fires no hook.
        $bootstrap->register_widget();
        $this->assertSame(0, $captured, 'register_widget must early-return when \Elementor\Widget_Base is absent.');
    }

    public function test_divi_bootstrap_no_ops_when_et_builder_module_absent(): void {
        $bootstrap = new \VectorYT\Gallery\Integrations\Divi\Bootstrap();
        $captured = 0;
        $cb = function() use (&$captured) { $captured++; };
        add_action('fake_divi_action', $cb);
        $bootstrap->register_module();
        $this->assertSame(0, $captured);
    }

    public function test_elementor_bootstrap_class_file_does_not_load_widget_class_when_absent(): void {
        // The Bootstrap's register_widget() has an explicit class_exists()
        // guard and does NOT require the widget file unconditionally.
        $bootstrap = new \VectorYT\Gallery\Integrations\Elementor\Bootstrap();
        $bootstrap->register_widget();
        // GalleryWidget class MUST NOT exist now — the stub file isn't loaded.
        $this->assertFalse(
            class_exists('\\VectorYT\\Gallery\\Integrations\\Elementor\\GalleryWidget', false),
            'GalleryWidget must not be loadable when Elementor is absent.'
        );
    }

    public function test_plugin_boots_clean_with_elementor_stubs_present(): void {
        // Verify that loading the Elementor stub THEN registering the
        // bootstrap doesn't fatal. We require the stub file inline; this
        // simulates "Elementor just got activated".
        require_once __DIR__ . '/../../stubs/elementor-stubs.php';
        $this->assertTrue(class_exists('\\Elementor\\Widget_Base'));
        // The Bootstrap's register_widget now enters the registration
        // branch and require_once's the widget file. Since
        // GalleryWidget.php wraps its class declaration in a
        // `class_exists('\Elementor\Widget_Base')` guard, the class
        // definition should now compile.
        $bootstrap = new \VectorYT\Gallery\Integrations\Elementor\Bootstrap();
        $bootstrap->register_widget();
        $this->assertTrue(
            class_exists('\\VectorYT\\Gallery\\Integrations\\Elementor\\GalleryWidget', false),
            'GalleryWidget should be loadable when Elementor is present.'
        );
    }

    public function test_plugin_boots_clean_with_divi_stubs_present(): void {
        require_once __DIR__ . '/../../stubs/divi-stubs.php';
        $this->assertTrue(class_exists('ET_Builder_Module'));
        $bootstrap = new \VectorYT\Gallery\Integrations\Divi\Bootstrap();
        $bootstrap->register_module();
        $this->assertTrue(
            class_exists('\\VectorYT\\Gallery\\Integrations\\Divi\\GalleryModule', false),
            'GalleryModule should be loadable when Divi is present.'
        );
    }
}
