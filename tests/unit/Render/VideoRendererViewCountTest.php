<?php
/**
 * Unit tests for VideoRenderer::format_view_count().
 *
 * Pure-logic helper — no Brain\Monkey stubs required.
 *
 * @covers \VectorYT\Gallery\Render\VideoRenderer
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Render\VideoRenderer;

final class VideoRendererViewCountTest extends TestCase
{
    private VideoRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new VideoRenderer();
    }

    public function test_zero_returns_zero(): void
    {
        $this->assertSame('0', $this->renderer->format_view_count(0));
    }

    public function test_negative_returns_zero(): void
    {
        $this->assertSame('0', $this->renderer->format_view_count(-5));
    }

    public function test_below_thousand_returns_raw_int(): void
    {
        $this->assertSame('0', $this->renderer->format_view_count(0));
        $this->assertSame('1', $this->renderer->format_view_count(1));
        $this->assertSame('999', $this->renderer->format_view_count(999));
    }

    public function test_thousands_use_K_with_one_decimal_for_small(): void
    {
        // 1,000 → "1.0K" (small, one-decimal rule)
        $this->assertSame('1.0K', $this->renderer->format_view_count(1_000));
        // 9,999 → still under 10K, one decimal
        $this->assertSame('10.0K', $this->renderer->format_view_count(9_999));
    }

    public function test_ten_thousand_and_above_drop_the_decimal(): void
    {
        // 10K threshold — 12,345 → "12K"
        $this->assertSame('12K', $this->renderer->format_view_count(12_345));
        // 999,499 → "999K" (still under 1M)
        $this->assertSame('999K', $this->renderer->format_view_count(999_499));
    }

    public function test_millions_use_M(): void
    {
        $this->assertSame('1.0M', $this->renderer->format_view_count(1_000_000));
        $this->assertSame('1.3M', $this->renderer->format_view_count(1_250_000));
        $this->assertSame('125M', $this->renderer->format_view_count(125_000_000));
    }

    public function test_billions_use_B(): void
    {
        $this->assertSame('1.0B', $this->renderer->format_view_count(1_000_000_000));
        $this->assertSame('1.2B', $this->renderer->format_view_count(1_234_567_890));
    }

    public function test_trillions_cap_at_T(): void
    {
        $this->assertSame('1.0T', $this->renderer->format_view_count(1_000_000_000_000));
        $this->assertSame('2.5T', $this->renderer->format_view_count(2_500_000_000_000));
    }
}
