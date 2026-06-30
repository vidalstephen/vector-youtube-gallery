<?php
/**
 * Phase 9.6 unit tests — Presets.
 *
 * - sanitize_slug collapses unknown values to 'default'.
 * - emit_css returns '' for default; non-empty for non-default.
 * - emit_css never touches layout (no `column-count` or other layout primitives).
 *
 * @package VectorYT\Gallery\Tests\Unit\Render
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Render\Presets;

final class PresetsTest extends TestCase {

    public function test_sanitize_slug_falls_back_to_default_for_unknown(): void {
        $this->assertSame('default', Presets::sanitize_slug('not-a-real-preset'));
        $this->assertSame('default', Presets::sanitize_slug('../../etc/passwd'));
        $this->assertSame('default', Presets::sanitize_slug('<script>alert(1)</script>'));
    }

    public function test_sanitize_slug_keeps_known_values(): void {
        $this->assertSame('cinema', Presets::sanitize_slug('CINEMA'));
        $this->assertSame('minimal', Presets::sanitize_slug('minimal'));
        $this->assertSame('developer', Presets::sanitize_slug('developer'));
    }

    public function test_default_preset_emits_empty_css(): void {
        $this->assertSame('', Presets::emit_css('default'));
        $this->assertSame('', Presets::emit_css('anything-else'));
    }

    public function test_cinema_preset_emits_css_with_scope(): void {
        $css = Presets::emit_css('cinema');
        $this->assertStringContainsString('[data-vyg-preset="cinema"]', $css);
        $this->assertStringContainsString('--vyg-card-radius: 0;', $css);
        // No layout primitives should appear.
        $this->assertStringNotContainsString('column-count', $css);
        $this->assertStringNotContainsString('flex-direction', $css);
    }

    public function test_all_presets_have_consistent_keys(): void {
        $expected = array_keys(Presets::presets()['minimal']);
        foreach (Presets::presets() as $name => $tokens) {
            if (empty($tokens)) {
                continue;
            }
            $missing = array_diff($expected, array_keys($tokens));
            $this->assertEmpty($missing, "Preset '{$name}' is missing keys: " . implode(', ', $missing));
        }
    }

    public function test_preset_emits_only_css_variables_no_html(): void {
        $css = Presets::emit_css('pastel');
        $this->assertStringNotContainsString('<', $css);
        $this->assertStringNotContainsString('>', $css);
        $this->assertStringNotContainsString('script', $css);
    }
}
