<?php
/**
 * Phase 9.8 unit tests — Phase 9 templates render without errors and emit
 * the expected class hooks. Validates that masonry, carousel, hero
 * templates each render the expected wrapper classes without calling the
 * YouTube API or breaking on missing videos.
 *
 * @package VectorYT\Gallery\Tests\Unit\Render
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Render\TemplateLoader;
use VectorYT\Gallery\Render\VideoRenderer;
use VectorYT\Gallery\Tests\Support\BrainHelpers;

final class LayoutTemplatesTest extends TestCase {

    private TemplateLoader $loader;
    private VideoRenderer $renderer;

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        BrainHelpers::stubEscapeFunctions();
        BrainHelpers::stubOptionFunctions();
        $this->loader = new TemplateLoader();
        $this->renderer = new VideoRenderer();
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_masonry_template_renders_columns_class(): void {
        $html = $this->loader->render('masonry', $this->ctx('masonry', 4));
        $this->assertStringContainsString('vyg-masonry--cols-4', $html);
        $this->assertStringContainsString('vyg-feed--masonry', $html);
    }

    public function test_carousel_template_renders_per_view_class_and_role(): void {
        $html = $this->loader->render('carousel', $this->ctx('carousel', 3));
        $this->assertStringContainsString('vyg-carousel--per-3', $html);
        $this->assertStringContainsString('role="region"', $html);
        $this->assertStringContainsString('aria-roledescription="carousel"', $html);
    }

    public function test_carousel_template_renders_aria_selected_on_first_slide(): void {
        $html = $this->loader->render('carousel', $this->ctx('carousel', 3));
        $this->assertStringContainsString('aria-selected="true"', $html);
    }

    public function test_hero_template_renders_hero_class(): void {
        $html = $this->loader->render('hero', $this->ctx('hero', 3));
        $this->assertStringContainsString('vyg-feed--hero', $html);
        $this->assertStringContainsString('vyg-hero__primary', $html);
    }

    public function test_masonry_handles_empty_videos(): void {
        // Match the grid test pattern: pass 'renderer' => null because the
        // empty-state path doesn't reference it.
        $html = $this->loader->render('masonry', array(
            'source'   => array('title' => 'X'),
            'videos'   => array(),
            'attrs'    => array(),
            'renderer' => null,
        ));
        $this->assertStringContainsString('vyg-feed--empty', $html);
    }

    public function test_carousel_handles_empty_videos(): void {
        $html = $this->loader->render('carousel', array(
            'source'   => array('title' => 'X'),
            'videos'   => array(),
            'attrs'    => array(),
            'renderer' => null,
        ));
        $this->assertStringContainsString('vyg-feed--empty', $html);
    }

    public function test_hero_handles_empty_videos(): void {
        $html = $this->loader->render('hero', array(
            'source'   => array('title' => 'X'),
            'videos'   => array(),
            'attrs'    => array(),
            'renderer' => null,
        ));
        $this->assertStringContainsString('vyg-feed--empty', $html);
    }

    private function ctx(string $layout, int $count): array {
        $videos = array();
        for ($i = 0; $i < $count; $i++) {
            $videos[] = array(
                'youtube_video_id' => 'ID_' . $i,
                'title'            => 'Video ' . $i,
                'thumbnail_high'   => 'https://example.com/' . $i . '.jpg',
                'duration_seconds' => 60 + $i * 30,
                'content_type'     => 'standard',
                'live_status'      => 'none',
                'published_at'     => '2026-06-01T00:00:00Z',
            );
        }
        return array(
            'source'   => array('title' => 'Test Source', 'source_uuid' => 'fake-uuid'),
            'videos'   => $videos,
            'attrs'    => array(
                'layout'      => $layout,
                'columns'     => $count,
                'wrapper_id'  => 'vyg-test-' . $layout,
                'public_safe' => false,
            ),
            'renderer' => $this->renderer,
        );
    }
}
