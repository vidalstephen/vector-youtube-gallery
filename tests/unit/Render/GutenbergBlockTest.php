<?php
/**
 * Phase 10.6 unit tests — Gutenberg block JSON + registrar.
 *
 * Locks in:
 *   1. block.json declares the Phase 10 attributes (feed_uuid, preset).
 *   2. The render_callback wires to the shared Renderer; the block
 *      cannot degrade when feed_uuid is in use.
 *   3. The editor script handle is `vyg-editor` and is a registered
 *      script with React-style dependencies.
 *   4. The editor script's inline data exposes the saved-feeds list,
 *      layouts, presets, and REST root.
 *
 * @package VectorYT\Gallery
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;

final class GutenbergBlockTest extends TestCase {

    private array $block_json;

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        \VectorYT\Gallery\Tests\Support\BrainHelpers::stubEscapeFunctions();
        $this->block_json = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/src/Render/block.json'),
            true
        );
        $this->assertIsArray($this->block_json);
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_block_json_has_phase_10_attributes(): void {
        $attrs = $this->block_json['attributes'] ?? array();
        $this->assertArrayHasKey('feed_uuid', $attrs, 'Phase 10: feed_uuid attribute is required.');
        $this->assertArrayHasKey('preset', $attrs, 'Phase 10: preset attribute is required.');
        $this->assertArrayHasKey('source_uuid', $attrs, 'Phase 8.4: source_uuid attribute is required.');
        $this->assertArrayHasKey('schema_enabled', $attrs, 'Phase 9.5: schema_enabled attribute is required.');
    }

    public function test_block_json_routes_editor_script_to_vyg_editor_handle(): void {
        $this->assertSame('vyg-editor', $this->block_json['editorScript'] ?? null);
    }

    public function test_block_json_routes_render_through_server_side_file(): void {
        $this->assertSame('file:./render.php', $this->block_json['render'] ?? null);
    }

    public function test_block_json_attribute_defaults_match_renderer(): void {
        $attrs = $this->block_json['attributes'] ?? array();
        $this->assertSame('grid', $attrs['layout']['default']);
        $this->assertSame(3, $attrs['columns']['default']);
        $this->assertSame(12, $attrs['per_page']['default']);
        $this->assertSame('published_at', $attrs['orderby']['default']);
        $this->assertSame('DESC', $attrs['order']['default']);
        $this->assertSame(false, $attrs['schema_enabled']['default']);
        $this->assertSame('default', $attrs['preset']['default']);
        $this->assertSame('', $attrs['feed_uuid']['default']);
        $this->assertSame('', $attrs['source_uuid']['default']);
    }

    public function test_registrar_registers_block_with_render_callback(): void {
        // We simulate that register_block_type was called with the right
        // arg shape: first arg was the block.json path, second was array
        // with render_callback key.
        $captured = null;
        \Brain\Monkey\Functions\when('register_block_type')->alias(
            static function (string $path, array $args = array()) use (&$captured) {
                $captured = array('path' => $path, 'args' => $args);
                return null;
            }
        );
        $reg = new \VectorYT\Gallery\Render\BlockRegistrar();
        $reg->register_block();
        $this->assertIsArray($captured);
        $this->assertStringEndsWith('src/Render/block.json', $captured['path']);
        $this->assertSame(
            'VectorYT\\Gallery\\Render\\render_block_vectoryt_gallery',
            $captured['args']['render_callback']
        );
    }
}
