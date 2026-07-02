<?php
/**
 * Phase 14.1 — width modes (theme/wide/full) wrapper class.
 *
 * Width is a container-level visual concern, not a per-card concern, so
 * it lives at the layout root level rather than in the per-card
 * CardSettings 36-key system. The prototype defines three widths:
 *   - theme: constrains to theme container width (default ~880px)
 *   - wide:  default at 1180px (the prototype's baseline)
 *   - full:  100% of the parent container
 *
 * Each width is materialized as:
 *   - A CSS class on the .vyg-feed root <div>: .vyg-theme / .vyg-wide / .vyg-full
 *   - A data-vyg-width attribute on the root <div> for JS / inspection
 *   - A --vyg-max CSS variable token (defined in assets/css/base.css)
 *
 * The shortcode / block / Elementor surfaces all accept `width="theme"`,
 * `width="wide"`, or `width="full"`. Default is "wide" to match the
 * prototype's baseline panel.
 *
 * @covers \VectorYT\Gallery\Render\TemplateAttributes
 * @covers \VectorYT\Gallery\Render\ShortcodeRegistrar
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Render;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Render\TemplateAttributes;
use VectorYT\Gallery\Tests\Support\BrainHelpers;

require_once __DIR__ . '/../../bootstrap.php';

final class WidthModesTest extends TestCase
{
    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        BrainHelpers::stubEscapeFunctions();
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // TemplateAttributes::feed_root() — root <div> data attribute.
    // -----------------------------------------------------------------

    public function test_feed_root_emits_data_vyg_width_attribute(): void {
        $attrs = TemplateAttributes::feed_root(
            array( 'layout' => 'grid', 'width' => 'theme' ),
            array( 'source_uuid' => 'src-1' ),
            false
        );
        $this->assertArrayHasKey( 'data-vyg-width', $attrs );
        $this->assertSame( 'theme', $attrs['data-vyg-width'] );
    }

    public function test_feed_root_default_width_is_wide(): void {
        // When width is absent, the prototype's default is 'wide'.
        $attrs = TemplateAttributes::feed_root(
            array( 'layout' => 'grid' ),
            array( 'source_uuid' => 'src-1' ),
            false
        );
        $this->assertSame( 'wide', $attrs['data-vyg-width'] );
    }

    public function test_feed_root_normalizes_invalid_width_to_wide(): void {
        // Defense in depth: an unknown value falls back to 'wide' rather
        // than emitting a CSS class that doesn't exist.
        $attrs = TemplateAttributes::feed_root(
            array( 'layout' => 'grid', 'width' => 'extralarge' ),
            array( 'source_uuid' => 'src-1' ),
            false
        );
        $this->assertSame( 'wide', $attrs['data-vyg-width'] );
    }

    public function test_feed_root_accepts_all_three_width_modes(): void {
        foreach ( array( 'theme', 'wide', 'full' ) as $mode ) {
            $attrs = TemplateAttributes::feed_root(
                array( 'layout' => 'grid', 'width' => $mode ),
                array( 'source_uuid' => 'src-1' ),
                false
            );
            $this->assertSame(
                $mode,
                $attrs['data-vyg-width'],
                "width={$mode} must round-trip through feed_root()"
            );
        }
    }

    public function test_feed_root_width_class_helper_returns_correct_class(): void {
        $this->assertSame( 'vyg-theme', TemplateAttributes::width_class( array( 'width' => 'theme' ) ) );
        $this->assertSame( 'vyg-wide',  TemplateAttributes::width_class( array( 'width' => 'wide' ) ) );
        $this->assertSame( 'vyg-full',  TemplateAttributes::width_class( array( 'width' => 'full' ) ) );
        $this->assertSame( 'vyg-wide',  TemplateAttributes::width_class( array() ) ); // default
        $this->assertSame( 'vyg-wide',  TemplateAttributes::width_class( array( 'width' => 'garbage' ) ) );
    }

    public function test_feed_root_width_survives_public_safe_mode(): void {
        // Width is purely visual — it must be emitted in BOTH the
        // public-safe REST response and the legacy shortcode render.
        $attrs = TemplateAttributes::feed_root(
            array( 'layout' => 'grid', 'width' => 'full', 'feed_uuid' => 'f-1' ),
            array( 'source_uuid' => 'src-1' ),
            true
        );
        $this->assertSame( 'full', $attrs['data-vyg-width'], 'public-safe must not strip the width attribute' );
    }
}
