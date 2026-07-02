<?php
/**
 * Phase 14.3 unit tests — live section pill counters.
 *
 * The live template (src/Render/templates/live.php) renders three sections
 * (active / upcoming / replay) — each section's heading needs a prototype-style
 * pill counter to the right of the <h2>:
 *
 *   - active   : "2 live"     with class vyg-live__pill vyg-live__pill--active   (red)
 *   - upcoming : "3 upcoming" with class vyg-live__pill vyg-live__pill--upcoming (purple)
 *   - replay   : "Recent replays" with class vyg-live__pill vyg-live__pill--replay (blue, no count)
 *
 * The pill lives inside a <div class="vyg-live__head"> wrapper that also
 * contains the <h2>, so the heading + pill sit on a single flex row.
 *
 * These tests pin the server-side contract for the prototype-parity pill
 * counters:
 *
 *   1. Active section pill: text "2 live", class includes --active.
 *   2. Upcoming section pill: text "3 upcoming", class includes --upcoming.
 *   3. Replay section pill: text "Recent replays", class includes --replay.
 *   4. Each pill class string carries its expected CSS variant.
 *   5. Each pill lives inside a <div class="vyg-live__head"> that also
 *      contains the section's <h2>.
 *   6. When a section is empty (e.g. 0 upcoming) the section — and its
 *      pill — is omitted entirely (no empty wrapper, no pill).
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

final class LivePillTest extends TestCase
{
    private TemplateLoader $loader;
    private VideoRenderer $renderer;

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        BrainHelpers::stubEscapeFunctions();
        // The live-card partial calls get_option() for the date/time format
        // and mysql2date() to format scheduled_start_at / ended_at. Both
        // are already stubbed by stubEscapeFunctions (mysql2date) and
        // stubOptionFunctions (get_option).
        BrainHelpers::stubOptionFunctions();
        $this->loader   = new TemplateLoader();
        $this->renderer = new VideoRenderer();
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // 1) Active section — "2 live" red pill
    // -----------------------------------------------------------------

    public function test_live_pill_active_section_contains_live_count_pill(): void {
        // 2 live videos → expected pill text "2 live" with --active variant.
        $html = $this->loader->render('live', $this->ctx(
            array(
                'live'     => $this->videos(2, 'live'),
                'upcoming' => $this->videos(3, 'upcoming'),
                'replay'   => $this->videos(5, 'replay'),
            )
        ));
        $this->assertStringContainsString('vyg-live__pill--active', $html);
        $this->assertStringContainsString('>2 live<', $html);
    }

    public function test_live_pill_active_pill_count_matches_bucket_size(): void {
        // 4 live videos → expected pill text "4 live" (count must reflect
        // the bucket size, not a hard-coded number).
        $html = $this->loader->render('live', $this->ctx(
            array(
                'live'     => $this->videos(4, 'live'),
                'upcoming' => array(),
                'replay'   => array(),
            )
        ));
        $this->assertStringContainsString('>4 live<', $html);
    }

    // -----------------------------------------------------------------
    // 2) Upcoming section — "3 upcoming" purple pill
    // -----------------------------------------------------------------

    public function test_live_pill_upcoming_section_contains_upcoming_count_pill(): void {
        $html = $this->loader->render('live', $this->ctx(
            array(
                'live'     => $this->videos(2, 'live'),
                'upcoming' => $this->videos(3, 'upcoming'),
                'replay'   => $this->videos(5, 'replay'),
            )
        ));
        $this->assertStringContainsString('vyg-live__pill--upcoming', $html);
        $this->assertStringContainsString('>3 upcoming<', $html);
    }

    public function test_live_pill_upcoming_pill_count_matches_bucket_size(): void {
        // 1 upcoming → "1 upcoming" (singular N).
        $html = $this->loader->render('live', $this->ctx(
            array(
                'live'     => array(),
                'upcoming' => $this->videos(1, 'upcoming'),
                'replay'   => array(),
            )
        ));
        $this->assertStringContainsString('>1 upcoming<', $html);
    }

    // -----------------------------------------------------------------
    // 3) Replay section — "Recent replays" blue pill (no count)
    // -----------------------------------------------------------------

    public function test_live_pill_replay_section_contains_recent_replays_pill(): void {
        $html = $this->loader->render('live', $this->ctx(
            array(
                'live'     => $this->videos(2, 'live'),
                'upcoming' => $this->videos(3, 'upcoming'),
                'replay'   => $this->videos(5, 'replay'),
            )
        ));
        $this->assertStringContainsString('vyg-live__pill--replay', $html);
        $this->assertStringContainsString('>Recent replays<', $html);
    }

    public function test_live_pill_replay_pill_is_text_only_no_count(): void {
        // Per the prototype, the replay pill is a fixed label "Recent replays"
        // — NOT a count + label. Even with 7 replays the text stays "Recent
        // replays" (no "7" prepended).
        $html = $this->loader->render('live', $this->ctx(
            array(
                'live'     => array(),
                'upcoming' => array(),
                'replay'   => $this->videos(7, 'replay'),
            )
        ));
        $this->assertStringContainsString('>Recent replays<', $html);
        $this->assertStringNotContainsString('>7 replays<', $html);
        $this->assertStringNotContainsString('>7 recent<', $html);
    }

    // -----------------------------------------------------------------
    // 4) Each pill class string carries its expected CSS variant
    // -----------------------------------------------------------------

    public function test_live_pill_classes_use_prototype_color_palette(): void {
        // All three variant classes must appear in the rendered HTML when
        // all three sections are present. This pins the class-name contract
        // so a future rename to .pill / .up / .re is caught.
        $html = $this->loader->render('live', $this->ctx(
            array(
                'live'     => $this->videos(2, 'live'),
                'upcoming' => $this->videos(3, 'upcoming'),
                'replay'   => $this->videos(5, 'replay'),
            )
        ));
        $this->assertStringContainsString('vyg-live__pill--active',   $html, 'active pill class missing');
        $this->assertStringContainsString('vyg-live__pill--upcoming', $html, 'upcoming pill class missing');
        $this->assertStringContainsString('vyg-live__pill--replay',   $html, 'replay pill class missing');
        // And the base class must also be present on each pill.
        $this->assertStringContainsString('vyg-live__pill',           $html, 'base pill class missing');
    }

    public function test_live_pill_exactly_one_pill_per_visible_section(): void {
        // 3 sections, 3 pills. Count of `vyg-live__pill--` (variant markers)
        // should be exactly 3 — one per visible section. The base
        // `vyg-live__pill` is also emitted 3 times.
        $html = $this->loader->render('live', $this->ctx(
            array(
                'live'     => $this->videos(2, 'live'),
                'upcoming' => $this->videos(3, 'upcoming'),
                'replay'   => $this->videos(5, 'replay'),
            )
        ));
        // `vyg-live__pill--` appears once per variant class (active/upcoming/replay).
        $variant_count = preg_match_all(
            '/class="[^\"]*\bvyg-live__pill--(?:active|upcoming|replay)\b/',
            $html
        );
        $this->assertSame(3, $variant_count, 'expected exactly 3 pill variant markers (one per section)');

        // And the wrapping `vyg-live__head` div appears once per section.
        $head_count = preg_match_all(
            '/class="[^\"]*\bvyg-live__head\b/',
            $html
        );
        $this->assertSame(3, $head_count, 'expected exactly 3 vyg-live__head wrappers (one per section)');
    }

    // -----------------------------------------------------------------
    // 5) Pill lives inside a <div class="vyg-live__head"> that also
    //    contains the section's <h2>
    // -----------------------------------------------------------------

    public function test_live_pill_uses_h2_inside_head_wrapper(): void {
        // For each section, a <div class="vyg-live__head"> wraps the
        // <h2 class="vyg-live__heading"> AND the pill. We assert the
        // structural relationship by checking both class names appear,
        // and the heading text still appears alongside the pill.
        $html = $this->loader->render('live', $this->ctx(
            array(
                'live'     => $this->videos(2, 'live'),
                'upcoming' => $this->videos(3, 'upcoming'),
                'replay'   => $this->videos(5, 'replay'),
            )
        ));

        // The active section's pill + heading live in the same head wrapper.
        // We check the substring appears between the head open tag and its
        // matching close. Simplest assertion: a single head wrapper that
        // contains BOTH the h2 and the pill.
        //
        // The wrapping pattern is roughly:
        //   <div class="vyg-live__head"><h2 class="vyg-live__heading">Live now</h2><span class="vyg-live__pill vyg-live__pill--active">2 live</span></div>
        $this->assertStringContainsString(
            '<div class="vyg-live__head">',
            $html,
            'vyg-live__head wrapper must wrap the h2 + pill'
        );
        // All three original h2 headings must still appear (we didn't
        // remove the headings, just added a wrapper + pill).
        $this->assertStringContainsString('vyg-live__heading', $html);
        $this->assertStringContainsString('>Live now<',         $html);
        $this->assertStringContainsString('>Upcoming<',         $html);
        $this->assertStringContainsString('>Recent streams<',   $html);

        // And the pill + h2 for the active section must appear in the
        // same head wrapper. Assert substring order: the head opens,
        // then the h2, then the pill — and the head closes.
        $this->assertMatchesRegularExpression(
            '/<div class="vyg-live__head">\s*<h2 class="vyg-live__heading">Live now<\/h2>\s*<span class="vyg-live__pill vyg-live__pill--active">2 live<\/span>\s*<\/div>/',
            $html,
            'active section head wrapper must contain h2 + active pill in that order'
        );
        $this->assertMatchesRegularExpression(
            '/<div class="vyg-live__head">\s*<h2 class="vyg-live__heading">Upcoming<\/h2>\s*<span class="vyg-live__pill vyg-live__pill--upcoming">3 upcoming<\/span>\s*<\/div>/',
            $html,
            'upcoming section head wrapper must contain h2 + upcoming pill in that order'
        );
        $this->assertMatchesRegularExpression(
            '/<div class="vyg-live__head">\s*<h2 class="vyg-live__heading">Recent streams<\/h2>\s*<span class="vyg-live__pill vyg-live__pill--replay">Recent replays<\/span>\s*<\/div>/',
            $html,
            'replay section head wrapper must contain h2 + replay pill in that order'
        );
    }

    // -----------------------------------------------------------------
    // 6) Empty section omits pill (and section)
    // -----------------------------------------------------------------

    public function test_live_pill_omitted_when_section_empty(): void {
        // 0 upcoming videos → the upcoming section (and its pill) must
        // not render at all. Only the active and replay pills should
        // appear.
        $html = $this->loader->render('live', $this->ctx(
            array(
                'live'     => $this->videos(2, 'live'),
                'upcoming' => array(),
                'replay'   => $this->videos(5, 'replay'),
            )
        ));

        // No upcoming pill, no upcoming section, no upcoming heading.
        $this->assertStringNotContainsString('vyg-live__pill--upcoming', $html, 'no upcoming pill when 0 upcoming videos');
        $this->assertStringNotContainsString('vyg-live__section--upcoming', $html, 'no upcoming section when 0 upcoming videos');
        $this->assertStringNotContainsString('>Upcoming<', $html, 'no upcoming heading when 0 upcoming videos');

        // The active and replay sections still render.
        $this->assertStringContainsString('vyg-live__pill--active', $html);
        $this->assertStringContainsString('vyg-live__pill--replay', $html);
        // And the variant count is 2, not 3.
        $variant_count = preg_match_all(
            '/class="[^\"]*\bvyg-live__pill--(?:active|upcoming|replay)\b/',
            $html
        );
        $this->assertSame(2, $variant_count, 'expected exactly 2 pill variant markers (active + replay) when upcoming is empty');
    }

    public function test_live_pill_all_empty_renders_empty_state(): void {
        // All 3 buckets empty → existing empty-state path triggers
        // ("No live or recent streams.") and no pills / no sections render.
        $html = $this->loader->render('live', $this->ctx(
            array(
                'live'     => array(),
                'upcoming' => array(),
                'replay'   => array(),
            )
        ));
        $this->assertStringNotContainsString('vyg-live__pill', $html);
        $this->assertStringNotContainsString('vyg-live__section', $html);
        $this->assertStringContainsString('vyg-feed--empty', $html);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Build a TemplateLoader context for the live layout. Mirrors the
     * helper in CarouselDotsTest — but uses $buckets (the new live layout
     * input shape) instead of a flat $videos array.
     *
     * @param array{live:array,upcoming:array,replay:array} $buckets
     * @return array<string,mixed>
     */
    private function ctx(array $buckets): array {
        return array(
            'source'   => array('title' => 'Test Source', 'source_uuid' => 'fake-uuid'),
            'buckets'  => $buckets,
            'attrs'    => array(
                'layout'      => 'live',
                'wrapper_id'  => 'vyg-test-live-pill',
                'public_safe' => false,
            ),
            'renderer' => $this->renderer,
        );
    }

    /**
     * Build $count fake live-layout video rows tagged with a status.
     *
     * @return array<int,array<string,mixed>>
     */
    private function videos(int $count, string $status): array {
        $out = array();
        for ($i = 0; $i < $count; $i++) {
            $out[] = array(
                'youtube_video_id'    => strtoupper($status) . '_' . $i,
                'title'               => ucfirst($status) . ' Video ' . $i,
                'thumbnail_high'      => 'https://example.com/' . $status . '-' . $i . '.jpg',
                'duration_seconds'    => 60 + $i * 30,
                'content_type'        => 'standard',
                'live_status'         => $status,
                'published_at'        => '2026-06-01T00:00:00Z',
                'concurrent_viewers'  => 'live' === $status ? 100 + $i : 0,
                'scheduled_start_at'  => 'upcoming' === $status ? '2026-07-15T12:00:00Z' : '',
                'ended_at'            => 'replay' === $status ? '2026-06-30T18:00:00Z' : '',
            );
        }
        return $out;
    }
}
