<?php
/**
 * Presets — named white-label style presets for front-end feeds.
 *
 * Phase 9.6 introduced the preset concept. Each preset is a small CSS variable
 * bundle. The Renderer emits a wrapper with `data-vyg-preset="<name>"` and the
 * bundled CSS exposes preset-specific variable values via attribute selectors.
 *
 * Operators also can override individual variables inline via a `style` attribute
 * or theme override, since presets only set CSS variables (not hard rules).
 *
 * Hard rule: presets must only affect visual tokens (color, radius, spacing,
 * typography). They must NOT change layout behavior (column count, etc) so
 * the renderer stays a single source of truth for accessible structure.
 *
 * @package VectorYT\Gallery\Render
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Render;

defined('ABSPATH') || exit;

final class Presets {

    /**
     * Sanitize a preset slug. Falls back to the default.
     */
    public static function sanitize_slug(string $name): string {
        $clean = strtolower((string) preg_replace('/[^a-zA-Z0-9_-]+/', '', $name));
        $known = array_keys(self::presets());
        return in_array($clean, $known, true) ? $clean : 'default';
    }

    /**
     * Per-preset CSS variable tokens.
     *
     * Keep each preset's value set SMALL; new variables land here only when
     * an attribute-or-property changes the visual meaningfully. Each preset
     * overrides the same set of variables (overriding non-overridden variables
     * falls back to base.css values).
     *
     * @return array<string, array<string,string>>
     */
    public static function presets(): array {
        return array(
            'default' => array(
                /* Use base.css tokens as-is. */
            ),
            'minimal' => array(
                '--vyg-card-radius'           => '4px',
                '--vyg-card-bg'               => 'transparent',
                '--vyg-card-shadow'           => 'none',
                '--vyg-card-border'           => '1px solid rgba(0,0,0,0.08)',
                '--vyg-card-title-weight'     => '500',
                '--vyg-card-title-size'       => '0.9rem',
                '--vyg-grid-gap'              => '1.25rem',
                '--vyg-duration-bg'           => 'rgba(255,255,255,0.85)',
                '--vyg-duration-color'        => '#222',
            ),
            'cinema' => array(
                '--vyg-card-radius'           => '0',
                '--vyg-card-bg'               => '#000',
                '--vyg-card-shadow'           => '0 18px 36px -16px rgba(0,0,0,0.55)',
                '--vyg-card-border'           => '0 solid transparent',
                '--vyg-card-title-weight'     => '600',
                '--vyg-card-title-size'       => '1rem',
                '--vyg-card-title-color'      => 'inherit',
                '--vyg-grid-gap'              => '1.5rem',
                '--vyg-duration-bg'           => 'rgba(255,255,255,0.92)',
                '--vyg-duration-color'        => '#000',
            ),
            'pastel' => array(
                '--vyg-card-radius'           => '14px',
                '--vyg-card-bg'               => '#fdf6f0',
                '--vyg-card-shadow'           => '0 4px 10px rgba(214, 165, 165, 0.18)',
                '--vyg-card-border'           => '1px solid #f3e6dd',
                '--vyg-card-title-weight'     => '500',
                '--vyg-card-title-size'       => '0.95rem',
                '--vyg-card-title-color'      => '#5a3c2e',
                '--vyg-grid-gap'              => '1rem',
                '--vyg-duration-bg'           => '#5a3c2e',
                '--vyg-duration-color'        => '#fff7ee',
            ),
            'developer' => array(
                '--vyg-card-radius'           => '6px',
                '--vyg-card-bg'               => '#0d1117',
                '--vyg-card-shadow'           => 'none',
                '--vyg-card-border'           => '1px solid #21262d',
                '--vyg-card-title-color'      => '#e6edf3',
                '--vyg-card-title-weight'     => '600',
                '--vyg-card-title-size'       => '0.95em',
                '--vyg-duration-bg'           => '#161b22',
                '--vyg-duration-color'        => '#7d8590',
                '--vyg-grid-gap'              => '0.75rem',
            ),
        );
    }

    /**
     * Render a minimal CSS variable override block for the given preset.
     *
     * Emitted as inline `<style>` near the top of the feed wrapper. Combined
     * with the per-layout attribute selector style in assets/css/presets.css,
     * this gives operators a low-overhead white-label surface area.
     *
     * @return string CSS (empty for default preset).
     */
    public static function emit_css(string $slug): string {
        $slug = self::sanitize_slug($slug);
        $presets = self::presets();
        if ('default' === $slug || empty($presets[$slug])) {
            return '';
        }
        $tokens = $presets[$slug];
        $lines = array();
        foreach ($tokens as $name => $value) {
            $lines[] = $name . ': ' . $value . ';';
        }
        return '[data-vyg-preset="' . $slug . '"] { ' . implode(' ', $lines) . ' }';
    }
}
