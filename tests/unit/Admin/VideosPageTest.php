<?php
/**
 * Phase 11.4 unit tests — VideosPage saved-filter sanitization.
 *
 * @package VectorYT\Gallery\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Admin\VideosPage;
use VectorYT\Gallery\Logging\Logger;
use VectorYT\Gallery\Settings\SettingsRepository;

final class VideosPageTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        \Brain\Monkey\Functions\when('wp_unslash')->alias(static fn($value) => $value);
        \Brain\Monkey\Functions\when('sanitize_text_field')->alias(static function ($value): string {
            return trim(strip_tags((string) $value));
        });
        \Brain\Monkey\Functions\when('sanitize_key')->alias(static function ($value): string {
            return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value));
        });
        \Brain\Monkey\Functions\when('absint')->alias(static fn($value): int => abs((int) $value));
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_sanitize_filters_keeps_valid_phase_11_filter_values(): void {
        $page = new VideosPage(new SettingsRepository(), new Logger());
        $filters = $page->sanitize_filters(array(
            's'                   => '<b>rick</b>',
            'content_type'        => 'live_replay',
            'source_channel'      => 'UC-test-channel',
            'availability_status' => 'available',
            'live_status'         => 'ended',
            'is_pinned'           => '1',
            'is_hidden'           => '0',
            'published_after'     => '2026-01-01',
            'published_before'    => '2026-12-31',
        ));
        $this->assertSame('rick', $filters['s']);
        $this->assertSame('live_replay', $filters['content_type']);
        $this->assertSame('UC-test-channel', $filters['source_channel']);
        $this->assertSame('available', $filters['availability_status']);
        $this->assertSame('ended', $filters['live_status']);
        $this->assertSame('1', $filters['is_pinned']);
        $this->assertSame('0', $filters['is_hidden']);
        $this->assertSame('2026-01-01', $filters['published_after']);
        $this->assertSame('2026-12-31', $filters['published_before']);
    }

    public function test_sanitize_filters_drops_invalid_values(): void {
        $page = new VideosPage(new SettingsRepository(), new Logger());
        $filters = $page->sanitize_filters(array(
            'content_type'        => 'evil',
            'availability_status' => 'unknown',
            'live_status'         => 'broadcasting',
            'is_pinned'           => 'maybe',
            'is_hidden'           => 'nope',
            'published_after'     => '2026/01/01',
            'published_before'    => 'tomorrow',
        ));
        $this->assertSame('', $filters['content_type']);
        $this->assertSame('', $filters['availability_status']);
        $this->assertSame('', $filters['live_status']);
        $this->assertSame('', $filters['is_pinned']);
        $this->assertSame('', $filters['is_hidden']);
        $this->assertSame('', $filters['published_after']);
        $this->assertSame('', $filters['published_before']);
    }
}
