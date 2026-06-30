<?php
/**
 * Divi stubs — minimal subset so unit tests can detect the Divi guard.
 *
 * The Bootstrap class checks `class_exists('ET_Builder_Module')` before
 * loading GalleryModule.php. To exercise the "Divi absent" branch we
 * simply don't load these stubs; to exercise the "Divi present" branch
 * the test loads `tests/stubs/divi-stubs.php` BEFORE including the module
 * file via inline conditional.
 */
declare(strict_types=1);

if (! class_exists('ET_Builder_Module')) {
    class ET_Builder_Module_Stub {
        public $slug = '';
        public $vb_support = '';
        public $name = '';
        protected $module_credits = array();
        public function init() {}
        public function get_fields(): array { return array(); }
        public function render($attrs, $content = null, $slug = '') { return ''; }
    }
    class_alias('ET_Builder_Module_Stub', 'ET_Builder_Module');
}
