<?php
/**
 * Elementor stubs — minimal subset to let unit tests instantiate a fake
 * Widget_Base class hierarchy without loading the real Elementor plugin.
 *
 * Loaded on demand by PageBuilderIntegrationTest via `require_once`.
 */
declare(strict_types=1);

if (! class_exists('\\Elementor\\Widget_Base')) {
    /**
     * Minimal Widget_Base stub: provides get_settings_for_display() and a
     * hook-side-effect-free constructor so the widget subclass we test
     * can instantiate without fataling.
     */
    class Elementor_Widget_Base_Stub {
        protected array $settings = array();
        public function get_settings_for_display(): array { return $this->settings; }
    }
    class_alias('Elementor_Widget_Base_Stub', '\\Elementor\\Widget_Base');
}

if (! class_exists('\\Elementor\\Plugin')) {
    /**
     * Minimal stub for \Elementor\Plugin::instance() so the bootstrap
     * doesn't fatal during integration test runs.
     */
    final class Elementor_Plugin_Stub {
        public object $widgets_manager;
        public function __construct() {
            $this->widgets_manager = new class {
                public array $registered = array();
                public function register($widget): bool {
                    $this->registered[] = $widget;
                    return true;
                }
            };
        }
        public static function instance(): self {
            static $self = null;
            return $self ?? ($self = new self());
        }
    }
    class_alias('Elementor_Plugin_Stub', '\\Elementor\\Plugin');
}
