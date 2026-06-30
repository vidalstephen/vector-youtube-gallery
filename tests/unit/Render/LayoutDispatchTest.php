<?php
/**
 * Phase 9.8 unit tests — Layout dispatch + new layouts' slug/label contract.
 *
 * Each LayoutInterface implementation must declare a non-empty `slug()`,
 * non-empty `label()`, and be in the Renderer's LAYOUTS map. These tests
 * catch drift if someone removes an entry or renames a slug.
 *
 * @package VectorYT\Gallery\Tests\Unit\Render
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Render\Layouts\CarouselLayout;
use VectorYT\Gallery\Render\Layouts\FeaturedLayout;
use VectorYT\Gallery\Render\Layouts\GridLayout;
use VectorYT\Gallery\Render\Layouts\HeroLayout;
use VectorYT\Gallery\Render\Layouts\LayoutInterface;
use VectorYT\Gallery\Render\Layouts\ListLayout;
use VectorYT\Gallery\Render\Layouts\LiveLayout;
use VectorYT\Gallery\Render\Layouts\MasonryLayout;
use VectorYT\Gallery\Render\Layouts\ShortsLayout;
use VectorYT\Gallery\Render\Renderer;
use VectorYT\Gallery\Repository\FeedRepository;
use VectorYT\Gallery\Tests\Support\BrainHelpers;

final class LayoutDispatchTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        BrainHelpers::stubEscapeFunctions();
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_each_layout_implements_interface(): void {
        $layouts = array(
            GridLayout::class,
            ListLayout::class,
            FeaturedLayout::class,
            ShortsLayout::class,
            LiveLayout::class,
            MasonryLayout::class,
            CarouselLayout::class,
            HeroLayout::class,
        );
        foreach ($layouts as $class) {
            $this->assertContains(
                LayoutInterface::class,
                class_implements($class) ?: array(),
                "{$class} must implement " . LayoutInterface::class
            );
        }
    }

    public function test_each_layout_has_unique_slug_and_label(): void {
        $slugs = array();
        $labels = array();
        $classes = array(
            GridLayout::class,
            ListLayout::class,
            FeaturedLayout::class,
            ShortsLayout::class,
            LiveLayout::class,
            MasonryLayout::class,
            CarouselLayout::class,
            HeroLayout::class,
        );
        foreach ($classes as $class) {
            $slug = $class::slug();
            $label = $class::label();
            $this->assertNotEmpty($slug, "{$class} slug() must not be empty");
            $this->assertNotEmpty($label, "{$class} label() must not be empty");
            $this->assertNotContains($slug, $slugs, "Duplicate layout slug: {$slug}");
            $this->assertNotContains($label, $labels, "Duplicate layout label: {$label}");
            $slugs[] = $slug;
            $labels[] = $label;
        }
    }

    public function test_renderer_layouts_map_matches_implementations(): void {
        $implemented = array(
            'grid'     => GridLayout::class,
            'list'     => ListLayout::class,
            'featured' => FeaturedLayout::class,
            'shorts'   => ShortsLayout::class,
            'live'     => LiveLayout::class,
            'masonry'  => MasonryLayout::class,
            'carousel' => CarouselLayout::class,
            'hero'     => HeroLayout::class,
        );
        foreach ($implemented as $slug => $class) {
            $this->assertArrayHasKey($slug, Renderer::LAYOUTS, "Renderer::LAYOUTS missing slug '{$slug}'");
            $this->assertSame($class, Renderer::LAYOUTS[$slug]);
        }
    }

    public function test_feed_repository_allowed_layouts_includes_phase_9_layouts(): void {
        $allowed = FeedRepository::allowed_layouts();
        $this->assertContains('masonry', $allowed);
        $this->assertContains('carousel', $allowed);
        $this->assertContains('hero', $allowed);
    }

    public function test_shortcode_accepts_phase_9_layouts_without_fallback(): void {
        // The LAYOUTS map acts as the shortcode whitelist via Renderer's
        // sanitize_key + isset() fallback. This test pins down that all three
        // new layouts survive dispatch and don't get silently downgraded to grid.
        $expected = array(
            'masonry'  => MasonryLayout::class,
            'carousel' => CarouselLayout::class,
            'hero'     => HeroLayout::class,
        );
        foreach ($expected as $slug => $class) {
            $this->assertArrayHasKey($slug, Renderer::LAYOUTS);
            $this->assertSame($class, Renderer::LAYOUTS[$slug]);
        }
    }
}
