<?php
/**
 * Phase 11.2 unit tests — Analytics admin dashboard shell.
 *
 * The heavy SQL aggregation is covered by live wp-cli smoke because it needs
 * real plugin tables. These tests lock down the public shell and date-range
 * boundaries so the page stays registered and bounded.
 *
 * @package VectorYT\Gallery\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Admin\AnalyticsPage;

final class AnalyticsPageTest extends TestCase {

    public function test_page_class_loads(): void {
        $page = new AnalyticsPage();
        $this->assertInstanceOf(AnalyticsPage::class, $page);
    }

    public function test_date_range_bounds_are_stable(): void {
        $ref = new \ReflectionClass(AnalyticsPage::class);
        $this->assertSame(30, $ref->getConstant('DEFAULT_DAYS'));
        $this->assertSame(365, $ref->getConstant('MAX_DAYS'));
    }

    public function test_collect_method_is_public_for_smoke_and_future_widget_reuse(): void {
        $method = new \ReflectionMethod(AnalyticsPage::class, 'collect');
        $this->assertTrue($method->isPublic());
    }
}
