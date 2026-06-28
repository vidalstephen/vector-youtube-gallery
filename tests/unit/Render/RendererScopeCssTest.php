<?php
/**
 * Tests for Renderer::scope_css (Phase 6.3 CSS scoping).
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Render;

use VectorYT\Gallery\Render\Renderer;

require_once __DIR__ . '/../../bootstrap.php';

final class RendererScopeCssTest extends \PHPUnit\Framework\TestCase {

    public function test_scopes_simple_selector(): void {
        $css = '.foo { color: red; }';
        $out = Renderer::scope_css( $css, '#wrap' );
        $this->assertStringContainsString( '#wrap .foo', $out );
        $this->assertStringContainsString( 'color: red', $out );
    }

    public function test_scopes_multiple_selectors_in_one_rule(): void {
        $css = '.foo, .bar { color: red; }';
        $out = Renderer::scope_css( $css, '#wrap' );
        $this->assertStringContainsString( '#wrap .foo, .bar', $out );
    }

    public function test_passes_at_media_through_unchanged(): void {
        $css = '@media (max-width: 600px) { .foo { color: blue; } }';
        $out = Renderer::scope_css( $css, '#wrap' );
        // The @media block selector should remain unchanged (we don't recurse into at-rules).
        $this->assertStringContainsString( '@media (max-width: 600px)', $out );
        $this->assertStringContainsString( '.foo', $out );
    }

    public function test_strips_css_comments(): void {
        $css = '/* hi */ .foo { color: red; }';
        $out = Renderer::scope_css( $css, '#wrap' );
        $this->assertStringNotContainsString( '/* hi */', $out );
        $this->assertStringContainsString( '#wrap .foo', $out );
    }

    public function test_handles_nested_rules(): void {
        $css = '.foo { color: red; & .bar { color: blue; } }';
        $out = Renderer::scope_css( $css, '#wrap' );
        $this->assertStringContainsString( '#wrap .foo', $out );
        $this->assertStringContainsString( '& .bar', $out ); // nested passes through
    }

    public function test_empty_input(): void {
        $this->assertSame( '', Renderer::scope_css( '', '#wrap' ) );
    }
}