<?php
/**
 * Unit tests for VideoRenderer (pure-logic helper).
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Render\VideoRenderer;
use VectorYT\Gallery\Tests\Support\BrainHelpers;

/**
 * @covers \VectorYT\Gallery\Render\VideoRenderer
 */
final class VideoRendererTest extends TestCase {

    private VideoRenderer $renderer;

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        \VectorYT\Gallery\Tests\Support\BrainHelpers::stubEscapeFunctions();
        $this->renderer = new VideoRenderer();
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_embed_url_with_defaults(): void {
        $url = $this->renderer->embed_url( array( 'youtube_video_id' => 'dQw4w9WgXcQ' ) );
        $this->assertStringContainsString( 'youtube.com/embed/dQw4w9WgXcQ', $url );
        $this->assertStringContainsString( 'autoplay=1', $url );
        $this->assertStringContainsString( 'rel=0', $url );
        $this->assertStringContainsString( 'modestbranding=1', $url );
    }

    public function test_embed_url_with_overrides(): void {
        $url = $this->renderer->embed_url(
            array( 'youtube_video_id' => 'abc123' ),
            array( 'autoplay' => '0', 'start' => '30' )
        );
        $this->assertStringContainsString( 'autoplay=0', $url );
        $this->assertStringContainsString( 'start=30', $url );
    }

    public function test_embed_url_with_empty_id_returns_empty(): void {
        $url = $this->renderer->embed_url( array( 'youtube_video_id' => '' ) );
        $this->assertSame( '', $url );
    }

    public function test_watch_url_format(): void {
        $url = $this->renderer->watch_url( array( 'youtube_video_id' => 'dQw4w9WgXcQ' ) );
        $this->assertSame( 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', $url );
    }

    public function test_watch_url_urlencodes_special_chars(): void {
        $url = $this->renderer->watch_url( array( 'youtube_video_id' => 'abc/123' ) );
        $this->assertStringContainsString( 'abc%2F123', $url );
    }

    public function test_best_thumbnail_preferred_when_present(): void {
        $video = array(
            'thumbnail_default' => 'https://example/d.jpg',
            'thumbnail_medium'  => 'https://example/m.jpg',
            'thumbnail_high'    => 'https://example/h.jpg',
            'thumbnail_maxres'  => 'https://example/x.jpg',
        );
        $this->assertSame( 'https://example/x.jpg', $this->renderer->best_thumbnail( $video, 'maxres' ) );
        $this->assertSame( 'https://example/h.jpg', $this->renderer->best_thumbnail( $video, 'high' ) );
        $this->assertSame( 'https://example/d.jpg', $this->renderer->best_thumbnail( $video, 'default' ) );
    }

    public function test_best_thumbnail_falls_back_when_preferred_missing(): void {
        $video = array(
            'thumbnail_default' => 'https://example/d.jpg',
            'thumbnail_high'    => 'https://example/h.jpg',
        );
        // Preferred maxres missing → fall back to standard (also missing) → high
        $this->assertSame( 'https://example/h.jpg', $this->renderer->best_thumbnail( $video, 'maxres' ) );
        $this->assertSame( 'https://example/h.jpg', $this->renderer->best_thumbnail( $video, 'standard' ) );
    }

    public function test_best_thumbnail_empty_when_no_thumbnails(): void {
        $this->assertSame( '', $this->renderer->best_thumbnail( array() ) );
    }

    public function test_format_duration_various(): void {
        $this->assertSame( '0:45', $this->renderer->format_duration( 45 ) );
        $this->assertSame( '1:00', $this->renderer->format_duration( 60 ) );
        $this->assertSame( '3:33', $this->renderer->format_duration( 213 ) );
        $this->assertSame( '1:00:00', $this->renderer->format_duration( 3600 ) );
        $this->assertSame( '1:01:05', $this->renderer->format_duration( 3665 ) );
        $this->assertSame( '', $this->renderer->format_duration( 0 ) );
        $this->assertSame( '', $this->renderer->format_duration( -5 ) );
    }
}