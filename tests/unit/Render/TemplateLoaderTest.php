<?php
/**
 * Unit tests for TemplateLoader.
 *
 * Tests:
 *   - locate() returns the bundled template when no theme override exists
 *   - locate() sanitizes path traversal
 *   - render() outputs the contents
 *
 * @package VectorYT\Gallery\Tests\Unit\Render
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Render\TemplateLoader;
use VectorYT\Gallery\Tests\Support\BrainHelpers;

final class TemplateLoaderTest extends TestCase {

    private TemplateLoader $loader;

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        \VectorYT\Gallery\Tests\Support\BrainHelpers::stubEscapeFunctions();
        $this->loader = new TemplateLoader();
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_locate_finds_bundled_grid_template(): void {
        $path = $this->loader->locate( 'grid' );
        $this->assertNotEmpty( $path );
        $this->assertFileExists( $path );
        $this->assertStringContainsString( 'templates/grid.php', $path );
    }

    public function test_locate_returns_empty_for_unknown_template(): void {
        $this->assertSame( '', $this->loader->locate( 'nonexistent_template_xyz' ) );
    }

    public function test_locate_sanitizes_path_traversal(): void {
        // ../../etc/passwd → sanitized to 'etcpasswd' which doesn't match any template.
        $this->assertSame( '', $this->loader->locate( '../../etc/passwd' ) );
    }

    public function test_locate_sanitizes_slashes(): void {
        $this->assertSame( '', $this->loader->locate( 'foo/bar' ) );
    }

    public function test_render_returns_html_when_template_exists(): void {
        $html = $this->loader->render( 'grid', array(
            'videos'  => array(),
            'source'  => array( 'source_uuid' => 'fake' ),
            'attrs'   => array(),
            'renderer'=> null,
        ) );
        $this->assertNotEmpty( $html );
        $this->assertStringContainsString( 'vyg-feed--empty', $html );
    }

    public function test_render_returns_empty_for_missing_template(): void {
        $this->assertSame( '', $this->loader->render( 'nonexistent' ) );
    }
}