<?php
/**
 * Elementor Widget — Vector YouTube Gallery.
 *
 * Phase 10.1. Integration goal: a server-rendered Elementor widget that
 * delegates to the existing `Renderer::render()`. Hard-rule: never call the
 * YouTube API in render — all data comes from local DB (FeedQuery), and the
 * Elementor preview is rendered server-side the same way the front-end is.
 *
 * **Elementor guard.** Widget code references `\Elementor\Widget_Base`,
 * `\Elementor\Controls_Manager`, etc. These classes only exist when the
 * Elementor plugin is loaded. The widget's `register_hooks()` checks
 * `did_action('elementor/loaded')` before adding any Elementor hooks, and
 * the class file itself is only `require_once`'d when Elementor is active.
 * If Elementor is not installed, this code is a no-op.
 *
 * @package VectorYT\Gallery\Integrations\Elementor
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Integrations\Elementor;

defined('ABSPATH') || exit;

/**
 * Elementor widget class. Only loads when \Elementor\Widget_Base exists.
 */
if (class_exists('\Elementor\Widget_Base')) {

    final class GalleryWidget extends \Elementor\Widget_Base {

        /**
         * Cached widget slug identifier. Used by Elementor for the JSON file
         * name and the selector.
         */
        public function get_name(): string {
            return 'vyg_gallery';
        }

        public function get_title(): string {
            return __('YouTube Gallery', 'vector-youtube-gallery');
        }

        public function get_icon(): string {
            return 'eicon-youtube';
        }

        public function get_categories(): array {
            return array('general');
        }

        public function get_keywords(): array {
            return array('youtube', 'gallery', 'video', 'feed');
        }

        public function get_script_depends(): array {
            // Front-end loader handles lightbox/load-more/carousel JS when
            // the layout renders. We still declare a dependency on the
            // lightbox JS so the Elementor editor preview loads it cleanly.
            return array('vyg-lightbox');
        }

        public function get_style_depends(): array {
            return array('vyg');
        }

        /**
         * Register all the controls the operator sees in the Elementor editor
         * sidebar. Each control mirrors a Renderer arg from Phase 4/8/9:
         *
         *   - feed selector (saved feed_uuid OR legacy source_uuid, but never
         *     both — feeds take precedence)
         *   - layout (every layout from FeedRepository::allowed_layouts())
         *   - columns (1..6)
         *   - per_page (1..200)
         *   - orderby (published_at, title, view_count, last_refreshed_at)
         *   - order (ASC, DESC)
         *   - content_type (free-form — keeps the renderer's existing filter
         *     semantics, no whitespace list to drift over time)
         *   - pagination (none, load_more)
         *   - preset (default, minimal, cinema, pastel, developer)
         *   - schema_enabled (toggle)
         *   - wrapper_id (text field, defaults empty / auto-generated)
         *
         * **Hard rule** — never persist an API key/token in any control's
         * default; controls take operator input only.
         */
        protected function register_controls(): void {
            $this->start_controls_section(
                'section_vyg_source',
                array(
                    'label' => __('Source', 'vector-youtube-gallery'),
                )
            );

            // Saved-feed selector. Source dropdown is reserved for legacy
            // operators (those using [youtube_feed source_uuid="..."]).
            $this->add_control(
                'feed_uuid',
                array(
                    'label'       => __('Saved feed (preferred)', 'vector-youtube-gallery'),
                    'type'        => \Elementor\Controls_Manager::SELECT2,
                    'options'     => $this->get_feed_options(),
                    'label_block' => true,
                    'description' => __('Pick a saved feed from Vector Gallery → Feeds. Saved feeds are portable and can be exported.', 'vector-youtube-gallery'),
                )
            );
            $this->add_control(
                'source_uuid',
                array(
                    'label'       => __('Source UUID (legacy)', 'vector-youtube-gallery'),
                    'type'        => \Elementor\Controls_Manager::TEXT,
                    'description' => __('If you are not using saved feeds, paste the source UUID here. Ignored when a saved feed is selected above.', 'vector-youtube-gallery'),
                    'label_block' => true,
                )
            );

            $this->end_controls_section();

            $this->start_controls_section(
                'section_vyg_layout',
                array(
                    'label' => __('Layout', 'vector-youtube-gallery'),
                )
            );
            $this->add_control(
                'layout',
                array(
                    'label'   => __('Layout', 'vector-youtube-gallery'),
                    'type'    => \Elementor\Controls_Manager::SELECT,
                    'default' => 'grid',
                    'options' => array_combine(
                        \VectorYT\Gallery\Repository\FeedRepository::allowed_layouts(),
                        array_map(static fn (string $s) => ucfirst($s), \VectorYT\Gallery\Repository\FeedRepository::allowed_layouts())
                    ),
                )
            );
            $this->add_control(
                'columns',
                array(
                    'label'   => __('Columns', 'vector-youtube-gallery'),
                    'type'    => \Elementor\Controls_Manager::NUMBER,
                    'min'     => 1,
                    'max'     => 6,
                    'step'    => 1,
                    'default' => 3,
                )
            );
            $this->add_control(
                'per_page',
                array(
                    'label'   => __('Items per page', 'vector-youtube-gallery'),
                    'type'    => \Elementor\Controls_Manager::NUMBER,
                    'min'     => 1,
                    'max'     => 200,
                    'step'    => 1,
                    'default' => 12,
                )
            );
            $this->add_control(
                'wrapper_id',
                array(
                    'label'       => __('Wrapper CSS ID (optional)', 'vector-youtube-gallery'),
                    'type'        => \Elementor\Controls_Manager::TEXT,
                    'description' => __('Used to scope custom CSS. Leave blank to auto-generate.', 'vector-youtube-gallery'),
                    'label_block' => true,
                )
            );

            $this->end_controls_section();

            $this->start_controls_section(
                'section_vyg_filter',
                array(
                    'label' => __('Filter & Sort', 'vector-youtube-gallery'),
                )
            );
            $this->add_control(
                'content_type',
                array(
                    'label'       => __('Content type filter', 'vector-youtube-gallery'),
                    'type'        => \Elementor\Controls_Manager::TEXT,
                    'description' => __('Comma-separated content_type values: standard, short_confirmed, live_active, etc.', 'vector-youtube-gallery'),
                    'label_block' => true,
                )
            );
            $this->add_control(
                'orderby',
                array(
                    'label'   => __('Order by', 'vector-youtube-gallery'),
                    'type'    => \Elementor\Controls_Manager::SELECT,
                    'default' => 'published_at',
                    'options' => array(
                        'published_at'      => __('Published date', 'vector-youtube-gallery'),
                        'title'             => __('Title', 'vector-youtube-gallery'),
                        'view_count'        => __('View count', 'vector-youtube-gallery'),
                        'last_refreshed_at' => __('Last refreshed', 'vector-youtube-gallery'),
                    ),
                )
            );
            $this->add_control(
                'order',
                array(
                    'label'   => __('Order', 'vector-youtube-gallery'),
                    'type'    => \Elementor\Controls_Manager::SELECT,
                    'default' => 'DESC',
                    'options' => array(
                        'DESC' => __('Descending', 'vector-youtube-gallery'),
                        'ASC'  => __('Ascending', 'vector-youtube-gallery'),
                    ),
                )
            );
            $this->add_control(
                'pagination',
                array(
                    'label'   => __('Pagination', 'vector-youtube-gallery'),
                    'type'    => \Elementor\Controls_Manager::SELECT,
                    'default' => 'none',
                    'options' => array(
                        'none'      => __('None', 'vector-youtube-gallery'),
                        'load_more' => __('Load more (JS button)', 'vector-youtube-gallery'),
                    ),
                )
            );
            $this->end_controls_section();

            $this->start_controls_section(
                'section_vyg_style',
                array(
                    'label' => __('Style & SEO', 'vector-youtube-gallery'),
                    'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
                )
            );
            $this->add_control(
                'preset',
                array(
                    'label'   => __('Style preset', 'vector-youtube-gallery'),
                    'type'    => \Elementor\Controls_Manager::SELECT,
                    'default' => 'default',
                    'options' => $this->get_preset_options(),
                )
            );
            $this->add_control(
                'schema_enabled',
                array(
                    'label'        => __('Emit Schema.org JSON-LD', 'vector-youtube-gallery'),
                    'type'         => \Elementor\Controls_Manager::SWITCHER,
                    'label_on'     => __('Yes', 'vector-youtube-gallery'),
                    'label_off'    => __('No', 'vector-youtube-gallery'),
                    'return_value' => 'yes',
                    'default'      => '',
                )
            );
            $this->end_controls_section();
        }

        /**
         * Server-side render. Delegates to the same Renderer the shortcode
         * and block use — no double rendering paths. The output is identical
         * markup; CSS classes + ARIA + lightbox wiring all stay in step.
         */
        protected function render(): void {
            $settings = $this->get_settings_for_display();
            $args     = array(
                'layout'         => sanitize_key((string) ($settings['layout'] ?? 'grid')),
                'columns'        => max(1, (int) ($settings['columns'] ?? 3)),
                'per_page'       => max(1, (int) ($settings['per_page'] ?? 12)),
                'wrapper_id'     => sanitize_text_field((string) ($settings['wrapper_id'] ?? '')),
                'content_type'   => sanitize_text_field((string) ($settings['content_type'] ?? '')),
                'orderby'        => sanitize_key((string) ($settings['orderby'] ?? 'published_at')),
                'order'          => sanitize_key((string) ($settings['order'] ?? 'DESC')),
                'pagination'     => sanitize_key((string) ($settings['pagination'] ?? 'none')),
                'preset'         => sanitize_key((string) ($settings['preset'] ?? 'default')),
                'schema_enabled' => ('yes' === ($settings['schema_enabled'] ?? '')),
            );
            $feed_uuid   = sanitize_text_field((string) ($settings['feed_uuid'] ?? ''));
            $source_uuid = sanitize_text_field((string) ($settings['source_uuid'] ?? ''));

            if ('' !== $feed_uuid) {
                $args['feed_uuid'] = $feed_uuid;
            } elseif ('' !== $source_uuid) {
                $args['source_uuid'] = $source_uuid;
            }

            // Public-safe by default — Phase 8.4 invariant: feed-by-uuid
            // rendering must not leak internal source UUIDs in the markup.
            if ('' !== ($args['feed_uuid'] ?? '')) {
                $args['public_safe'] = true;
            }

            // Phase 10.3: load the full saved-feed config so templates can
            // resolve WooCommerce CTA mappings (Phase 10.3.1) without the
            // widget having to re-read the feed row.
            if ('' !== ($args['feed_uuid'] ?? '')) {
                $args['feed_config'] = $this->load_feed_config((string) $args['feed_uuid']);
            }

            $container = \VectorYT\Gallery\Plugin::container();
            if (null === $container) {
                echo '<p>' . esc_html__('Vector YouTube Gallery is not active.', 'vector-youtube-gallery') . '</p>';
                return;
            }
            /** @var \VectorYT\Gallery\Render\Renderer $renderer */
            $renderer = $container->get('render.renderer');
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — Renderer output is sanitized at template boundaries.
            echo $renderer->render($args);
        }

        /**
         * Plain-template render for the Elementor editor preview. Identical
         * to render() — kept separate so future Elementor versions can
         * override behaviour without touching the production path.
         */
        protected function content_template(): void {
            echo '<div class="vyg-elementor-editor-placeholder" data-vyg-widget-placeholder="1">';
            echo '<p>' . esc_html__('YouTube Gallery — preview renders on the live page (server-side, no API calls).', 'vector-youtube-gallery') . '</p>';
            echo '<p class="vyg-elementor-editor-placeholder__hint">' . esc_html__('Click "Apply" then preview to see the gallery.', 'vector-youtube-gallery') . '</p>';
            echo '</div>';
        }

        /**
         * @return array<string,string>
         */
        private function get_feed_options(): array {
            $options = array('' => __('— Pick a saved feed —', 'vector-youtube-gallery'));
            $container = \VectorYT\Gallery\Plugin::container();
            if (null === $container) {
                return $options;
            }
            try {
                /** @var \VectorYT\Gallery\Repository\FeedRepository $feeds */
                $feeds   = $container->get('repo.feeds');
                $records = $feeds->list();
                foreach ($records as $f) {
                    $name = (string) ($f['name'] ?? '(unnamed)');
                    if (mb_strlen($name) > 60) {
                        $name = mb_substr($name, 0, 57) . '…';
                    }
                    $options[(string) $f['feed_uuid']] = $name . ' (' . (string) ($f['layout'] ?? 'grid') . ')';
                }
            } catch (\Throwable $e) {
                // Repository failure → fall back to empty selector. The
                // widget stays renderable with a placeholder.
                return $options;
            }
            return $options;
        }

        /**
         * @return array<string,string>
         */
        private function get_preset_options(): array {
            $out = array();
            foreach (\VectorYT\Gallery\Render\Presets::presets() as $slug => $_tokens) {
                $out[$slug] = ucfirst($slug);
            }
            return $out;
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
