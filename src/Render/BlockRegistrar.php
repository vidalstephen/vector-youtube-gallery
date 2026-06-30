<?php
/**
 * Block registrar — registers the vectoryt/gallery block.
 *
 * Strategy: server-side render only (no client JSX needed). The block.json
 * declares a `render` field pointing at render.php, which delegates to the
 * shared Renderer.
 *
 * Phase 10.4 — Gutenberg block polish:
 *   - editorScript (./index.js) registers an editor sidebar with feed
 *     picker + Inspector controls mirroring the Feed Builder.
 *   - A small inline script is enqueued with the available feed list so
 *     the picker can show names immediately on first paint and so the
 *     server-rendered block can rerender with the selected feed_uuid.
 *   - Server-side preview states: when the operator picks a feed (or
 *     types a UUID) and "Applies", the block's `data` attributes flow
 *     into render.php which delegates to Renderer. No YouTube calls.
 *
 * @package VectorYT\Gallery\Render
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Render;

defined('ABSPATH') || exit;

final class BlockRegistrar {

    /**
     * Editor-script handle. Registered + enqueued on
     * `enqueue_block_editor_assets`, with inline JSON providing the
     * available feeds/layouts/presets.
     */
    private const EDITOR_HANDLE = 'vyg-editor';

    public function register(): void {
        add_action('init', array($this, 'register_block'));
        add_action('enqueue_block_editor_assets', array($this, 'enqueue_editor_assets'));
    }

    public function register_block(): void {
        register_block_type(VYG_PLUGIN_DIR . 'src/Render/block.json', array(
            'render_callback' => 'VectorYT\\Gallery\\Render\\render_block_vectoryt_gallery',
        ));
    }

    /**
     * Registers and enqueues the editor script with inline data:
     *   - available feeds (for picker)
     *   - allowed layouts (for layout select)
     *   - presets (Phase 9.6)
     *   - REST root URL (for runtime reload from block-editor sidebar)
     */
    public function enqueue_editor_assets(): void {
        $feeds = $this->collect_feeds_for_picker();
        $data  = array(
            'name'     => 'vectoryt/gallery',
            'feeds'    => $feeds,
            'layouts'  => \VectorYT\Gallery\Repository\FeedRepository::allowed_layouts(),
            'presets'  => array_keys(\VectorYT\Gallery\Render\Presets::presets()),
            'restRoot' => esc_url_raw(rest_url('vyg/v1/')),
        );

        wp_register_script(
            self::EDITOR_HANDLE,
            VYG_PLUGIN_URL . 'src/Render/index.js',
            array(
                'wp-blocks',
                'wp-element',
                'wp-block-editor',
                'wp-components',
                'wp-api-fetch',
                'wp-i18n',
            ),
            VYG_VERSION,
            true
        );
        wp_add_inline_script(
            self::EDITOR_HANDLE,
            'window.VYG_BLOCK = ' . wp_json_encode($data) . ';',
            'before'
        );
        wp_enqueue_script(self::EDITOR_HANDLE);
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function collect_feeds_for_picker(): array {
        $rows = array();
        try {
            $container = \VectorYT\Gallery\Plugin::container();
            if (null !== $container && $container->has('repo.feeds')) {
                /** @var \VectorYT\Gallery\Repository\FeedRepository $feeds */
                $feeds = $container->get('repo.feeds');
                $rows  = $feeds->list();
            }
        } catch (\Throwable $e) {
            // Empty row list is fine — picker shows just the placeholder.
        }
        $out = array();
        foreach ($rows as $r) {
            $out[] = array(
                'feed_uuid' => (string) ($r['feed_uuid'] ?? ''),
                'name'      => (string) ($r['name'] ?? $r['feed_uuid'] ?? '(unnamed)'),
                'layout'    => (string) ($r['layout'] ?? 'grid'),
            );
        }
        return $out;
    }
}
