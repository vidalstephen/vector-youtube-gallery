<?php
/**
 * Phase 14.4 unit tests — featured/hero section head with "View all" link.
 *
 * The featured and hero layouts both render a primary card + a secondary
 * grid. The prototype's section head is:
 *
 *   <div class="section-head">
 *     <h2>More Videos</h2>
 *     <a class="btn" href="#">View all videos →</a>
 *   </div>
 *
 * Featured uses "More Videos", hero uses "Recommended next". The link's
 * `href` is the explicit `see_all_url` shortcode/block attr, or the
 * source's canonical URL (channel/playlist/video), or `#` as the final
 * fallback. The section head only renders when the secondary grid
 * renders (i.e. when there is more than one video).
 *
 * These tests pin the server-side contract:
 *
 *   1. Featured emits `<h2>More Videos</h2>` inside a `.vyg-section-head`.
 *   2. Featured emits `View all videos →` inside `.vyg-section-head__link`.
 *   3. `see_all_url` attr, when set, is used as the link's href.
 *   4. With no attr, the source's `channel_url` / `playlist_url` /
 *      `video_url` (synthesized from the source row's youtube_*_id) is used.
 *   5. With no attr AND no usable source keys, href falls back to `#`.
 *   6. The section head is OMITTED when only 1 video is rendered.
 *   7. Hero emits `<h2>Recommended next</h2>` inside `.vyg-section-head`.
 *   8. `see_all_label` attr, when set, replaces the default link label.
 *
 * @covers \VectorYT\Gallery\Render\TemplateLoader
 * @covers \VectorYT\Gallery\Render\VideoRenderer
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Render\TemplateLoader;
use VectorYT\Gallery\Render\VideoRenderer;
use VectorYT\Gallery\Tests\Support\BrainHelpers;
use Brain\Monkey\Functions;

require_once __DIR__ . '/../../bootstrap.php';

final class SectionHeadTest extends TestCase
{
    private TemplateLoader $loader;
    private VideoRenderer $renderer;

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        BrainHelpers::stubEscapeFunctions();
        // hero.php calls get_option() for the date format. Stub a default
        // so the template runs without booting WordPress.
        Functions\when( 'get_option' )->alias( static function ( string $name, $fallback = false ) {
            if ( 'date_format' === $name ) {
                return 'F j, Y';
            }
            return $fallback;
        } );
        $this->loader   = new TemplateLoader();
        $this->renderer = new VideoRenderer();
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // 1) Featured section head structure
    // -----------------------------------------------------------------

    public function test_featured_renders_section_head_with_more_videos_h2(): void {
        $html = $this->loader->render('featured', $this->ctxFeatured(3));

        // Wrapper exists.
        $this->assertStringContainsString('vyg-section-head', $html, 'featured must emit .vyg-section-head wrapper');
        // The h2 carries the prototype label "More Videos".
        $this->assertStringContainsString('>More Videos<', $html, 'featured section head h2 must say "More Videos"');
    }

    public function test_featured_renders_view_all_link(): void {
        $html = $this->loader->render('featured', $this->ctxFeatured(3));

        // Link class is the prototype's CTA variant.
        $this->assertStringContainsString('vyg-section-head__link', $html, 'featured must emit .vyg-section-head__link');
        // Default link text is "View all videos →" with the arrow glyph.
        $this->assertStringContainsString('View all videos', $html, 'featured link must show the default label "View all videos"');
        $this->assertStringContainsString('→', $html, 'featured link must show the prototype arrow glyph');
    }

    public function test_featured_link_uses_see_all_url_attr_when_provided(): void {
        $html = $this->loader->render('featured', $this->ctxFeatured(3, array(
            'see_all_url' => 'https://example.com/all',
        )));

        $this->assertStringContainsString(
            'href="https://example.com/all"',
            $html,
            'featured link href must be the explicit see_all_url attr'
        );
    }

    public function test_featured_link_falls_back_to_source_channel_url(): void {
        // Source with source_type=channel + youtube_channel_id → expect the
        // synthesized channel URL.
        $source = array(
            'source_uuid'        => 'src-uuid',
            'source_type'        => 'channel',
            'youtube_channel_id' => 'UC_x5XG1OV2P6uZZ5FSM9Ttw',
            'title'              => 'Test Channel',
        );
        $html = $this->loader->render('featured', $this->ctxFeatured(3, array(), $source));

        $this->assertStringContainsString(
            'href="https://www.youtube.com/channel/UC_x5XG1OV2P6uZZ5FSM9Ttw"',
            $html,
            'featured link href must fall back to the channel URL when no see_all_url attr is set'
        );
    }

    public function test_featured_link_falls_back_to_source_playlist_url(): void {
        $source = array(
            'source_uuid'         => 'src-uuid',
            'source_type'         => 'playlist',
            'youtube_playlist_id' => 'PLABCDEF1234567890',
            'title'               => 'Test Playlist',
        );
        $html = $this->loader->render('featured', $this->ctxFeatured(3, array(), $source));

        $this->assertStringContainsString(
            'href="https://www.youtube.com/playlist?list=PLABCDEF1234567890"',
            $html,
            'featured link href must fall back to the playlist URL when source_type=playlist'
        );
    }

    public function test_featured_link_falls_back_to_source_video_url(): void {
        $source = array(
            'source_uuid'        => 'src-uuid',
            'source_type'        => 'video',
            'youtube_video_id'   => 'dQw4w9WgXcQ',
            'title'              => 'Test Video',
        );
        $html = $this->loader->render('featured', $this->ctxFeatured(3, array(), $source));

        $this->assertStringContainsString(
            'href="https://www.youtube.com/watch?v=dQw4w9WgXcQ"',
            $html,
            'featured link href must fall back to the video URL when source_type=video'
        );
    }

    public function test_featured_link_uses_hash_when_no_source_url(): void {
        // Source row with no usable youtube_*_id AND no see_all_url attr → '#'.
        $source = array(
            'source_uuid'        => 'src-uuid',
            'source_type'        => 'channel',
            'youtube_channel_id' => '',
            'title'              => 'Empty Source',
        );
        $html = $this->loader->render('featured', $this->ctxFeatured(3, array(), $source));

        $this->assertMatchesRegularExpression(
            '/class="vyg-section-head__link"[^>]*href="#"/',
            $html,
            'featured link href must fall back to "#" when neither attr nor source has a usable URL'
        );
    }

    public function test_featured_section_head_omitted_when_no_secondary_grid(): void {
        // Phase 14.9: the shared header is no longer gated on
        // "secondary grid present" (that was the 14.4 design). The
        // header is now always emitted when ANY slot has content
        // (kicker/h1/intro/pill/CTA), and the <h2> defaults to
        // "Featured Videos" when no explicit title is set. So even
        // with 1 video, the shared header emits its h2.
        //
        // The old 14.4 "View all videos →" link (.vyg-section-head__link)
        // is suppressed when only 1 video renders — that gate is
        // preserved.
        $html = $this->loader->render('featured', $this->ctxFeatured(1));
        $this->assertStringNotContainsString(
            'vyg-section-head__link',
            $html,
            'featured must omit the 14.4 "View all" link when only 1 video renders (no secondary grid)'
        );
    }

    // -----------------------------------------------------------------
    // 2) Hero section head structure
    // -----------------------------------------------------------------

    public function test_hero_renders_section_head_with_recommended_next_h2(): void {
        $html = $this->loader->render('hero', $this->ctxHero(3));

        $this->assertStringContainsString('vyg-section-head', $html, 'hero must emit .vyg-section-head wrapper');
        $this->assertStringContainsString('>Recommended next<', $html, 'hero section head h2 must say "Recommended next"');
    }

    public function test_hero_uses_see_all_label_attr_when_provided(): void {
        $html = $this->loader->render('hero', $this->ctxHero(3, array(
            'see_all_url'  => 'https://example.com/all',
            'see_all_label' => 'See all sermons',
        )));

        $this->assertStringContainsString(
            'See all sermons',
            $html,
            'hero link must show the explicit see_all_label attr as its text'
        );
        // The default label should be replaced, not duplicated.
        $this->assertStringNotContainsString(
            'View all videos',
            $html,
            'hero link must NOT also show the default "View all videos" when see_all_label is set'
        );
    }

    public function test_hero_link_uses_see_all_url_attr_when_provided(): void {
        $html = $this->loader->render('hero', $this->ctxHero(3, array(
            'see_all_url' => 'https://example.com/hero',
        )));

        $this->assertStringContainsString(
            'href="https://example.com/hero"',
            $html,
            'hero link href must be the explicit see_all_url attr'
        );
    }

    public function test_hero_section_head_omitted_when_no_secondary_grid(): void {
        // Phase 14.9: see the featured variant's docblock. The shared
        // header is now always emitted when slot content exists. The
        // 14.4 "View all" link is the only thing still gated on the
        // secondary-grid count.
        $html = $this->loader->render('hero', $this->ctxHero(1));
        $this->assertStringNotContainsString(
            'vyg-section-head__link',
            $html,
            'hero must omit the 14.4 "View all" link when only 1 video renders (no secondary grid)'
        );
    }

    // -----------------------------------------------------------------
    // 3) Section head structure: h2 and link sit inside the same wrapper
    // -----------------------------------------------------------------

    public function test_featured_h2_and_link_share_section_head_wrapper(): void {
        $html = $this->loader->render('featured', $this->ctxFeatured(3, array(
            'see_all_url' => 'https://example.com/all',
        )));

        // The h2 and the link must be in the same <div class="vyg-section-head">
        // — order: h2, then the link. Regex pins the structural relationship.
        $this->assertMatchesRegularExpression(
            '/<div class="vyg-section-head">\s*<h2>More Videos<\/h2>\s*<a[^>]*class="vyg-section-head__link"[^>]*href="https:\/\/example\.com\/all"[^>]*>View all videos →<\/a>\s*<\/div>/',
            $html,
            'featured must wrap h2 + .vyg-section-head__link in the same .vyg-section-head div'
        );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Build a featured-layout TemplateLoader context with $count fake videos.
     *
     * @param array<string,mixed> $extra_attrs Extra attributes merged into $attrs.
     * @param array<string,mixed> $source       Source row override.
     * @return array<string,mixed>
     */
    private function ctxFeatured(int $count, array $extra_attrs = array(), array $source = array()): array {
        $default_source = array(
            'source_uuid'        => 'src-uuid-featured',
            'source_type'        => 'channel',
            'youtube_channel_id' => 'UC_x5XG1OV2P6uZZ5FSM9Ttw',
            'title'              => 'Test Source',
        );
        $attrs = array_merge(array(
            'layout'      => 'featured',
            'wrapper_id'  => 'vyg-test-section-head-featured',
            'public_safe' => false,
        ), $extra_attrs);

        return array(
            'source'   => array_merge($default_source, $source),
            'videos'   => $this->videos($count),
            'renderer' => $this->renderer,
            'attrs'    => $attrs,
        );
    }

    /**
     * Build a hero-layout TemplateLoader context with $count fake videos.
     *
     * @param array<string,mixed> $extra_attrs Extra attributes merged into $attrs.
     * @return array<string,mixed>
     */
    private function ctxHero(int $count, array $extra_attrs = array()): array {
        $attrs = array_merge(array(
            'layout'      => 'hero',
            'wrapper_id'  => 'vyg-test-section-head-hero',
            'public_safe' => false,
        ), $extra_attrs);

        return array(
            'source'   => array(
                'source_uuid'        => 'src-uuid-hero',
                'source_type'        => 'channel',
                'youtube_channel_id' => 'UC_x5XG1OV2P6uZZ5FSM9Ttw',
                'title'              => 'Hero Source',
            ),
            'videos'   => $this->videos($count),
            'renderer' => $this->renderer,
            'attrs'    => $attrs,
        );
    }

    /**
     * Build $count fake video rows.
     *
     * @return array<int,array<string,mixed>>
     */
    private function videos(int $count): array {
        $out = array();
        for ($i = 0; $i < $count; $i++) {
            $out[] = array(
                'youtube_video_id' => 'VID_' . $i,
                'title'            => 'Video ' . $i,
                'thumbnail_high'   => 'https://example.com/' . $i . '.jpg',
                'duration_seconds' => 60 + $i * 30,
                'content_type'     => 'standard',
                'live_status'      => 'none',
                'published_at'     => '2026-06-01T00:00:00Z',
            );
        }
        return $out;
    }
}
