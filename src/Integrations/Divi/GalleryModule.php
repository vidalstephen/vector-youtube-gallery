<?php
/**
 * Divi Module — Vector YouTube Gallery.
 *
 * Phase 10.2. Symmetric to the Elementor widget: server-render via the
 * existing Renderer, all data from local DB, opt-in integration. Only
 * loaded when Divi's `ET_Builder_Module` class exists.
 *
 * Divi's builder pre-renders PHP modules through a registry called
 * `ET_Builder_Module_Front_Fields_Passthrough`. To register a custom
 * module, the typical approach is the
 * `et_builder_include_modules` filter, but Divi's official "child" theme
 * pattern uses `ET_BUILDER_PLUGIN_DIR` to require template files. We
 * use a simpler stand-alone approach: register via the `divi_modules`
 * action if available, with a defensive fallback to require_once of
 * Divi's API. The class below only declares itself when Divi is active.
 *
 * **Hard rule:** never call external APIs in render — same Phase 0 invariant
 * as Elementor/block/shortcode.
 *
 * @package VectorYT\Gallery\Integrations\Divi
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Integrations\Divi;

defined('ABSPATH') || exit;

if (class_exists('ET_Builder_Module')) {

    final class GalleryModule extends \ET_Builder_Module {

        public $slug       = 'vyg_gallery';
        public $vb_support = 'on';
        protected $module_credits = array(
            'module_uri' => '',
            'author'     => 'Stephen Vidal',
            'author_uri' => '',
        );

        public function init(): void {
            $this->name = esc_html__('YouTube Gallery', 'vector-youtube-gallery');
        }

        /**
         * Divi fields mirror the Elementor controls. Slugs match the
         * Renderer's args one-for-one.
         */
        public function get_fields(): array {
            $layouts = \VectorYT\Gallery\Repository\FeedRepository::allowed_layouts();
            return array(
                'feed_uuid'     => array(
                    'label'           => esc_html__('Saved feed (preferred)', 'vector-youtube-gallery'),
                    'type'            => 'text',
                    'option_category' => 'basic_option',
                    'description'     => esc_html__('Paste a saved feed UUID. Saved feeds take precedence.', 'vector-youtube-gallery'),
                ),
                'source_uuid'   => array(
                    'label'           => esc_html__('Source UUID (legacy)', 'vector-youtube-gallery'),
                    'type'            => 'text',
                    'option_category' => 'basic_option',
                    'description'     => esc_html__('Legacy source UUID. Ignored when feed_uuid is set.', 'vector-youtube-gallery'),
                ),
                'layout'        => array(
                    'label'           => esc_html__('Layout', 'vector-youtube-gallery'),
                    'type'            => 'select',
                    'options'         => array_combine($layouts, array_map(static fn (string $l) => ucfirst($l), $layouts)),
                    'default'         => 'grid',
                    'option_category' => 'basic_option',
                ),
                'columns'       => array(
                    'label'           => esc_html__('Columns', 'vector-youtube-gallery'),
                    'type'            => 'range',
                    'range_settings'  => array(
                        'min'  => 1,
                        'max'  => 6,
                        'step' => 1,
                    ),
                    'default'         => '3',
                    'option_category' => 'basic_option',
                ),
                'per_page'      => array(
                    'label'           => esc_html__('Items per page', 'vector-youtube-gallery'),
                    'type'            => 'range',
                    'range_settings'  => array(
                        'min'  => 1,
                        'max'  => 200,
                        'step' => 1,
                    ),
                    'default'         => '12',
                    'option_category' => 'basic_option',
                ),
                'preset'        => array(
                    'label'           => esc_html__('Style preset', 'vector-youtube-gallery'),
                    'type'            => 'select',
                    'options'         => array_keys(\VectorYT\Gallery\Render\Presets::presets()),
                    'default'         => 'default',
                    'option_category' => 'layout',
                ),
                'schema_enabled' => array(
                    'label'           => esc_html__('Emit Schema.org JSON-LD', 'vector-youtube-gallery'),
                    'type'            => 'yes_no_button',
                    'options'         => array('off', 'on'),
                    'default'         => 'off',
                    'option_category' => 'layout',
                ),
            );
        }

        /**
         * Render the module output. We deliberately skip the Divi
         * `before_render`/`after_render` wrappers — they assume a single
         * shortcode in a wrapper, while the renderer already produces a
         * self-contained wrapper via `Renderer::render()`.
         */
        public function render($attrs, $content = null, $render_slug = ''): string {
            $args = array(
                'layout'         => sanitize_key((string) ($attrs['layout'] ?? 'grid')),
                'columns'        => max(1, (int) ($attrs['columns'] ?? 3)),
                'per_page'       => max(1, (int) ($attrs['per_page'] ?? 12)),
                'preset'         => sanitize_key((string) ($attrs['preset'] ?? 'default')),
                'schema_enabled' => ('on' === ($attrs['schema_enabled'] ?? 'off')),
            );
            $feed_uuid   = sanitize_text_field((string) ($attrs['feed_uuid'] ?? ''));
            $source_uuid = sanitize_text_field((string) ($attrs['source_uuid'] ?? ''));
            if ('' !== $feed_uuid) {
                $args['feed_uuid']   = $feed_uuid;
                $args['public_safe'] = true;
            } elseif ('' !== $source_uuid) {
                $args['source_uuid'] = $source_uuid;
            }

            // Phase 10.3: when a saved feed is selected, load its full
            // config so templates can resolve CTA mappings.
            if ('' !== ($args['feed_uuid'] ?? '')) {
                $args['feed_config'] = $this->load_feed_config((string) $args['feed_uuid']);
            }

            $container = \VectorYT\Gallery\Plugin::container();
            if (null === $container) {
                return '<p>' . esc_html__('Vector YouTube Gallery is not active.', 'vector-youtube-gallery') . '</p>';
            }
            /** @var \VectorYT\Gallery\Render\Renderer $renderer */
            $renderer = $container->get('render.renderer');
            return (string) $renderer->render($args);
        }

        /**
         * @return array<string,mixed>
         */
        private function load_feed_config(string $feed_uuid): array {
            $container = \VectorYT\Gallery\Plugin::container();
            if (null === $container) {
                return array();
            }
            try {
                /** @var \VectorYT\Gallery\Repository\FeedRepository $feeds */
                $feeds = $container->get('repo.feeds');
                $row   = $feeds->find_by_uuid($feed_uuid);
                return is_array($row) ? $row : array();
            } catch (\Throwable $e) {
                return array();
            }
        }
    }
}
