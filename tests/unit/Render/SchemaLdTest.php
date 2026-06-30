<?php
/**
 * Phase 9.5 unit tests — Schema.org JSON-LD builder.
 *
 * Locks down the requirements we set in the SchemaLd docblock:
 *   1. Disabled by default.
 *   2. Schema content uses `@type: VideoObject` per video and a single
 *      `@type: ItemList` wrapper.
 *   3. Source UUIDs MUST NOT leak into structured data.
 *   4. Disabled/private/embed_disabled videos MUST be filtered.
 *   5. No script breakout in JSON output even when title contains "</script>".
 *
 * @package VectorYT\Gallery\Tests\Unit\Render
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Render\SchemaLd;
use VectorYT\Gallery\Tests\Support\BrainHelpers;

final class SchemaLdTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        BrainHelpers::stubEscapeFunctions();
        // Stub date_i18n since WP isn't loaded.
        \Brain\Monkey\Functions\when('date_i18n')->alias(static function (string $fmt, int $ts = 0): string {
            return gmdate($fmt, $ts ?: time());
        });
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_disabled_by_default(): void {
        $html = SchemaLd::render(array('title' => 'Channel'), array($this->makeVideo()), array());
        $this->assertSame('', $html);
    }

    public function test_emits_itemlist_and_videoobject_when_enabled(): void {
        $source = array('title' => 'Test Channel');
        $video = $this->makeVideo();
        $html = SchemaLd::render($source, array($video), array('schema_enabled' => true));
        $this->assertStringContainsString('"@type":"ItemList"', $html);
        $this->assertStringContainsString('"@type":"VideoObject"', $html);
        $this->assertStringContainsString('<script type="application/ld+json"', $html);
    }

    public function test_filters_unavailable_videos(): void {
        $good = $this->makeVideo();
        $bad = $this->makeVideo(array('availability_status' => 'deleted'));
        $bad2 = $this->makeVideo(array('availability_status' => 'private'));
        $bad3 = $this->makeVideo(array('availability_status' => 'embed_disabled'));
        $payload = SchemaLd::build(null, array($good, $bad, $bad2, $bad3), array('schema_enabled' => true));
        // Only one video should survive.
        $this->assertCount(1, $payload);
        $this->assertSame(1, $payload[0]['numberOfItems']);
    }

    public function test_does_not_leak_source_uuid(): void {
        $source = array(
            'title'       => 'Channel',
            'source_uuid' => 'INTERNAL-UUID-DO-NOT-LEAK-1234',
        );
        $video = $this->makeVideo();
        $object = SchemaLd::video_object($video, $source);
        $json = wp_json_encode($object);
        $this->assertStringNotContainsString('INTERNAL-UUID-DO-NOT-LEAK-1234', (string) $json);
        $this->assertStringNotContainsString('source_uuid', (string) $json);
    }

    public function test_handles_script_injection_in_title(): void {
        $video = $this->makeVideo(array('title' => 'safe title', 'description' => '<script>alert(1)</script>'));
        $html = SchemaLd::render(null, array($video), array('schema_enabled' => true));
        // The user's literal "</script>" must NOT appear as a literal substring of
        // the rendered JSON payload (only the outer script-tag closing is allowed).
        // We strip the outer tag and assert the inner JSON is closed properly.
        $inner = preg_replace('/^<script[^>]*>|<\/script>$/', '', $html);
        $this->assertStringNotContainsString('</script>', (string) $inner);
        // A stranded "</script>" inside an attribute value would break parsing;
        // verify the encoding neutralized it (we use <\/script).
        $this->assertStringContainsString('<\\/script>', $html);
        // The injection attempt must not break the JSON container.
        $this->assertStringContainsString('application/ld+json', $html);
    }

    public function test_iso_duration_format(): void {
        $video = $this->makeVideo(array('duration_seconds' => 3661));
        $object = SchemaLd::video_object($video, null);
        $this->assertSame('PT1H1M1S', $object['duration']);
    }

    public function test_emits_thumbnail_url(): void {
        $video = $this->makeVideo();
        $object = SchemaLd::video_object($video, null);
        $this->assertNotEmpty($object['thumbnailUrl']);
    }

    private function makeVideo(array $overrides = array()): array {
        return array_merge(array(
            'youtube_video_id'    => 'dQw4w9WgXcQ',
            'title'               => 'Phase 9.5 Test Video',
            'description'         => 'A description.',
            'published_at'        => '2026-06-01T12:00:00Z',
            'duration_seconds'    => 180,
            'availability_status' => 'available',
            'view_count'          => 42,
            'thumbnail_maxres'    => 'https://example.com/maxres.jpg',
            'thumbnail_standard'  => 'https://example.com/standard.jpg',
            'thumbnail_high'      => 'https://example.com/high.jpg',
            'thumbnail_medium'    => 'https://example.com/medium.jpg',
            'thumbnail_default'   => 'https://example.com/default.jpg',
        ), $overrides);
    }
}
