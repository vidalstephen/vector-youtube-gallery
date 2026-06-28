<?php
/**
 * Block registrar — registers the vectoryt/gallery block.
 *
 * Strategy: server-side render only (no client JSX needed). The block.json
 * declares a `render` field pointing at render.php, which delegates to the
 * shared Renderer.
 *
 * @package VectorYT\Gallery\Render
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Render;

defined( 'ABSPATH' ) || exit;

final class BlockRegistrar {

    public function register(): void {
        add_action( 'init', array( $this, 'register_block' ) );
    }

    public function register_block(): void {
        // Block.json declares "render": "file:./render.php" — but WP requires
        // the actual function to exist; we just register the block type here.
        register_block_type( VYG_PLUGIN_DIR . 'src/Render/block.json', array(
            'render_callback' => 'VectorYT\Gallery\Render\render_block_vectoryt_gallery',
        ) );
    }
}