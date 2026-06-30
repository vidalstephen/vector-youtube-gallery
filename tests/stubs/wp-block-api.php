<?php
/**
 * WP block pattern API stubs for unit tests.
 *
 * Loaded by PatternsRegistrarTest when Brain\Monkey isn't enough.
 */
declare(strict_types=1);

if (! function_exists('register_block_pattern')) {
    function register_block_pattern(string $slug, array $args): bool {
        global $vyg_registered_block_patterns;
        $vyg_registered_block_patterns[$slug] = $args;
        return true;
    }
    function register_block_pattern_category(string $slug, array $args): bool {
        global $vyg_registered_block_pattern_categories;
        $vyg_registered_block_pattern_categories[$slug] = $args;
        return true;
    }
}
