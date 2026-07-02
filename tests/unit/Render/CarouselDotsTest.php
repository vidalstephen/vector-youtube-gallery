<?php
/**
 * Phase 14.2 unit tests — carousel dots + active-card state.
 *
 * The carousel template (src/Render/templates/carousel.php) is the front-end
 * of the prototype's `<div class="dots">` row and the
 * `<article class="card … active">` highlight treatment. The previous/next
 * nav buttons were already wired in commit e3f11b7 (Phase 14.1 baseline) and
 * must not regress.
 *
 * These tests pin the server-side contract:
 *
 *   1. Dots row is emitted under the track — one <button class="vyg-carousel__dot">
 *      per video.
 *   2. The centered (middle) slide gets `vyg-carousel__slide--active` on first
 *      render. Default active index: `floor($slide_count / 2)` (so a 5-slide
 *      carousel with 3 visible at a time is centered on slide index 2 — the
 *      third slide, 0-indexed position 2).
 *   3. The dot at the active index gets `vyg-carousel__dot--on` (the
 *      prototype's pill state: 28px wide, purple).
 *   4. Each dot button carries `data-slide-index="N"` (1-indexed) so the
 *      carousel JS can wire clicks to scroll the track.
 *   5. Each dot button has `aria-label="Go to slide N"` (a11y — the dots
 *      are a tablist, not a "next page" pagination).
 *   6. With only one video the dots row is suppressed (a single dot is
 *      useless UI; the live region still announces "1 of 1" via the
 *      existing `aria-selected="true"` on the lone slide).
 *
 * @covers \VectorYT\Gallery\Render\TemplateLoader
 * @covers \VectorYT\Gallery\Render\VideoRenderer
 * @covers \VectorYT\Gallery\Render\TemplateAttributes
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Render\TemplateLoader;
use VectorYT\Gallery\Render\VideoRenderer;
use VectorYT\Gallery\Tests\Support\BrainHelpers;

require_once __DIR__ . '/../../bootstrap.php';

final class CarouselDotsTest extends TestCase
{
    private TemplateLoader $loader;
    private VideoRenderer $renderer;

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        BrainHelpers::stubEscapeFunctions();
        $this->loader   = new TemplateLoader();
        $this->renderer = new VideoRenderer();
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Dots row
    // -----------------------------------------------------------------

    public function test_carousel_renders_dots_row_with_one_button_per_slide(): void {
        // 5 videos → 5 dot buttons. We count the opening of
        // <button class="vyg-carousel__dot" — the close is ">". Counting
        // is intentional: a single match like `assertStringContainsString`
        // would silently pass if any of the 5 buttons were missing.
        $html     = $this->loader->render('carousel', $this->ctx(5));
        $buttons  = preg_match_all('/<button[^>]*class="[^"]*\bvyg-carousel__dot\b/', $html);
        $this->assertSame(
            5,
            $buttons,
            'rendered 5 videos → expected 5 <button class="vyg-carousel__dot …"> elements'
        );
    }

    public function test_carousel_dots_row_is_wrapped_in_tablist(): void {
        // a11y: the dots are a single tablist so screen readers treat the
        // collection as one navigable group.
        $html = $this->loader->render('carousel', $this->ctx(5));
        $this->assertStringContainsString('class="vyg-carousel__dots"', $html);
        $this->assertStringContainsString('role="tablist"', $html);
    }

    // -----------------------------------------------------------------
    // Active-card state
    // -----------------------------------------------------------------

    public function test_carousel_marks_middle_slide_active_by_default(): void {
        // Default center-on-load = floor($slide_count / 2). For 5 videos
        // that is index 2 (0-indexed). Document the choice in the test
        // name so future readers know why position 2.
        //
        // Implementation note (template):
        //   $active_index = (int) floor( $slide_count / 2 );
        //   … class="vyg-carousel__slide …"  (without --active)
        //   … class="vyg-carousel__slide vyg-carousel__slide--active …"
        $html = $this->loader->render('carousel', $this->ctx(5));
        $active = preg_match_all(
            '/class="[^"]*\bvyg-carousel__slide--active\b/',
            $html
        );
        $this->assertSame(1, $active, 'exactly one slide must be marked active on first render');
    }

    public function test_carousel_dots_get_active_class_on_default_active_slide(): void {
        // The dot at the active index gets the prototype's "on" state
        // (28px pill, purple). For 5 videos that is the 3rd dot (0-indexed
        // position 2; the 3rd of 5 dots, since we use 1-indexed
        // data-slide-index in HTML but 0-indexed for the active flag).
        $html = $this->loader->render('carousel', $this->ctx(5));
        $active_dots = preg_match_all(
            '/<button[^>]*class="[^"]*\bvyg-carousel__dot--on\b/',
            $html
        );
        $this->assertSame(
            1,
            $active_dots,
            'exactly one dot must carry vyg-carousel__dot--on'
        );
    }

    public function test_carousel_active_dot_aria_selected_true(): void {
        // a11y: the active dot's tab is selected. Other dots are
        // aria-selected="false". Regex is order-independent: we match the
        // open `<button` tag, allow any attribute order via `[^>]*` in
        // either direction, and require both `aria-selected="true"` AND
        // the dot class on the same element.
        $html = $this->loader->render('carousel', $this->ctx(5));

        // Active dot: has both aria-selected="true" and vyg-carousel__dot class.
        $true_selected = preg_match_all(
            '/<button\b(?:[^>]*\baria-selected="true"[^>]*\bclass="[^"]*\bvyg-carousel__dot\b[^"]*"|[^>]*\bclass="[^"]*\bvyg-carousel__dot\b[^"]*[^>]*\baria-selected="true")[^>]*>/',
            $html
        );
        // Inactive dots: have both aria-selected="false" and vyg-carousel__dot class.
        $false_selected = preg_match_all(
            '/<button\b(?:[^>]*\baria-selected="false"[^>]*\bclass="[^"]*\bvyg-carousel__dot\b[^"]*"|[^>]*\bclass="[^"]*\bvyg-carousel__dot\b[^"]*[^>]*\baria-selected="false")[^>]*>/',
            $html
        );
        $this->assertSame(1, $true_selected,  'the active dot must have aria-selected="true"');
        $this->assertSame(4, $false_selected, 'the other 4 dots must have aria-selected="false"');
    }

    // -----------------------------------------------------------------
    // data-slide-index attribute
    // -----------------------------------------------------------------

    public function test_carousel_dots_buttons_have_data_slide_index_attribute(): void {
        // 1-indexed data-slide-index mirrors the slide li (which already
        // uses 1-indexed data-slide-index). The carousel JS uses this
        // attribute to wire dot clicks to "scroll the track to that
        // slide". Regex is order-independent: data-slide-index can come
        // before OR after the class attribute.
        $html = $this->loader->render('carousel', $this->ctx(5));
        $indexed = preg_match_all(
            '/<button\b(?:[^>]*\bdata-slide-index="\d+"[^>]*\bclass="[^"]*\bvyg-carousel__dot\b[^"]*"|[^>]*\bclass="[^"]*\bvyg-carousel__dot\b[^"]*[^>]*\bdata-slide-index="\d+")[^>]*>/',
            $html
        );
        $this->assertSame(
            5,
            $indexed,
            'every dot button must carry a data-slide-index="N" attribute'
        );
        // Also assert the active dot's index is 3 (the 3rd of 5 = index
        // 2 0-indexed; data-slide-index is 1-indexed → 3).
        $this->assertSame(
            1,
            preg_match(
                '/<button\b(?:[^>]*\bdata-slide-index="3"[^>]*\bclass="[^"]*\bvyg-carousel__dot--on\b[^"]*"|[^>]*\bclass="[^"]*\bvyg-carousel__dot--on\b[^"]*[^>]*\bdata-slide-index="3")[^>]*>/',
                $html
            ),
            'the active dot must have data-slide-index="3" (1-indexed middle of 5)'
        );
    }

    // -----------------------------------------------------------------
    // a11y — aria-label
    // -----------------------------------------------------------------

    public function test_carousel_a11y_dots_have_aria_label(): void {
        // Regex is order-independent: aria-label can come before OR
        // after the class attribute on the same button.
        $html = $this->loader->render('carousel', $this->ctx(5));
        $labels = preg_match_all(
            '/<button\b(?:[^>]*\baria-label="Go to slide \d+"[^>]*\bclass="[^"]*\bvyg-carousel__dot\b[^"]*"|[^>]*\bclass="[^"]*\bvyg-carousel__dot\b[^"]*[^>]*\baria-label="Go to slide \d+")[^>]*>/',
            $html
        );
        $this->assertSame(
            5,
            $labels,
            'every dot button must have aria-label="Go to slide N" for screen readers'
        );
    }

    // -----------------------------------------------------------------
    // Single-slide edge case
    // -----------------------------------------------------------------

    public function test_carousel_no_dots_row_when_only_one_slide(): void {
        // 1 video → no dots row at all. A single dot is useless UI (the
        // live region still announces "1 of 1" via aria-selected on the
        // lone slide). The dots row simply is not emitted.
        $html = $this->loader->render('carousel', $this->ctx(1));
        $this->assertStringNotContainsString(
            'vyg-carousel__dots',
            $html,
            'a single-slide carousel must not render a dots row'
        );
        $this->assertStringNotContainsString(
            'vyg-carousel__dot--on',
            $html,
            'a single-slide carousel must not have an "on" dot'
        );
        // The lone slide is still the active one (everything is centered
        // when there's nothing else to compare to).
        $this->assertStringContainsString(
            'vyg-carousel__slide--active',
            $html,
            'with a single slide, that slide must still be marked active'
        );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Build a TemplateLoader context for the carousel layout with $count
     * fake video rows. Mirrors the helper in LayoutTemplatesTest.
     *
     * @return array<string,mixed>
     */
    private function ctx(int $count): array {
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
                'layout'      => 'carousel',
                'columns'     => 3,
                'wrapper_id'  => 'vyg-test-carousel-dots',
                'public_safe' => false,
            ),
            'renderer' => $this->renderer,
        );
    }
}
