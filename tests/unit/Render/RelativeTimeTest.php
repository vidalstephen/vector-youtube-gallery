<?php
/**
 * Unit tests for the RelativeTime helper.
 *
 * Pure-logic — no Brain\Monkey stubs required. We inject a fixed "now" so the
 * diff is deterministic.
 *
 * @covers \VectorYT\Gallery\Render\RelativeTime
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Render;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Render\RelativeTime;

final class RelativeTimeTest extends TestCase
{
    /** A fixed "now" so all deltas are deterministic. */
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-06-30 12:00:00 UTC — the "current time" for every test.
        $this->now = new DateTimeImmutable( '2026-06-30 12:00:00', new DateTimeZone( 'UTC' ) );
    }

    public function test_just_now_under_one_minute(): void
    {
        $iso = $this->now->modify( '-30 seconds' )->format( DateTimeImmutable::ATOM );
        $this->assertSame( 'just now', RelativeTime::humanize( $iso, $this->now ) );
    }

    public function test_minutes_ago(): void
    {
        $iso = $this->now->modify( '-5 minutes' )->format( DateTimeImmutable::ATOM );
        $this->assertSame( '5 min ago', RelativeTime::humanize( $iso, $this->now ) );

        $iso = $this->now->modify( '-59 minutes' )->format( DateTimeImmutable::ATOM );
        $this->assertSame( '59 min ago', RelativeTime::humanize( $iso, $this->now ) );
    }

    public function test_one_minute_uses_singular_label(): void
    {
        $iso = $this->now->modify( '-1 minute' )->format( DateTimeImmutable::ATOM );
        $this->assertSame( '1 min ago', RelativeTime::humanize( $iso, $this->now ) );
    }

    public function test_hours_ago(): void
    {
        $iso = $this->now->modify( '-2 hours' )->format( DateTimeImmutable::ATOM );
        $this->assertSame( '2 hours ago', RelativeTime::humanize( $iso, $this->now ) );

        $iso = $this->now->modify( '-23 hours' )->format( DateTimeImmutable::ATOM );
        $this->assertSame( '23 hours ago', RelativeTime::humanize( $iso, $this->now ) );
    }

    public function test_one_hour_uses_singular_label(): void
    {
        $iso = $this->now->modify( '-1 hour' )->format( DateTimeImmutable::ATOM );
        $this->assertSame( '1 hour ago', RelativeTime::humanize( $iso, $this->now ) );
    }

    public function test_days_ago(): void
    {
        $iso = $this->now->modify( '-2 days' )->format( DateTimeImmutable::ATOM );
        $this->assertSame( '2 days ago', RelativeTime::humanize( $iso, $this->now ) );

        $iso = $this->now->modify( '-6 days' )->format( DateTimeImmutable::ATOM );
        $this->assertSame( '6 days ago', RelativeTime::humanize( $iso, $this->now ) );
    }

    public function test_one_day_uses_singular_label(): void
    {
        $iso = $this->now->modify( '-1 day' )->format( DateTimeImmutable::ATOM );
        $this->assertSame( '1 day ago', RelativeTime::humanize( $iso, $this->now ) );
    }

    public function test_weeks_ago(): void
    {
        $iso = $this->now->modify( '-14 days' )->format( DateTimeImmutable::ATOM );
        $this->assertSame( '2 weeks ago', RelativeTime::humanize( $iso, $this->now ) );

        $iso = $this->now->modify( '-29 days' )->format( DateTimeImmutable::ATOM );
        $this->assertSame( '4 weeks ago', RelativeTime::humanize( $iso, $this->now ) );
    }

    public function test_one_week_uses_singular_label(): void
    {
        $iso = $this->now->modify( '-7 days' )->format( DateTimeImmutable::ATOM );
        $this->assertSame( '1 week ago', RelativeTime::humanize( $iso, $this->now ) );
    }

    public function test_months_ago(): void
    {
        $iso = $this->now->modify( '-60 days' )->format( DateTimeImmutable::ATOM );
        $this->assertSame( '2 months ago', RelativeTime::humanize( $iso, $this->now ) );

        $iso = $this->now->modify( '-364 days' )->format( DateTimeImmutable::ATOM );
        // 364 / 30 = 12 months (using floor)
        $this->assertSame( '12 months ago', RelativeTime::humanize( $iso, $this->now ) );
    }

    public function test_one_month_uses_singular_label(): void
    {
        $iso = $this->now->modify( '-30 days' )->format( DateTimeImmutable::ATOM );
        $this->assertSame( '1 month ago', RelativeTime::humanize( $iso, $this->now ) );
    }

    public function test_years_ago(): void
    {
        $iso = $this->now->modify( '-2 years' )->format( DateTimeImmutable::ATOM );
        $this->assertSame( '2 years ago', RelativeTime::humanize( $iso, $this->now ) );

        $iso = $this->now->modify( '-10 years' )->format( DateTimeImmutable::ATOM );
        $this->assertSame( '10 years ago', RelativeTime::humanize( $iso, $this->now ) );
    }

    public function test_one_year_uses_singular_label(): void
    {
        $iso = $this->now->modify( '-1 year' )->format( DateTimeImmutable::ATOM );
        $this->assertSame( '1 year ago', RelativeTime::humanize( $iso, $this->now ) );
    }

    public function test_future_dates_return_empty(): void
    {
        $iso = $this->now->modify( '+1 day' )->format( DateTimeImmutable::ATOM );
        $this->assertSame( '', RelativeTime::humanize( $iso, $this->now ) );

        $iso = $this->now->modify( '+1 hour' )->format( DateTimeImmutable::ATOM );
        $this->assertSame( '', RelativeTime::humanize( $iso, $this->now ) );
    }

    public function test_invalid_iso_returns_empty(): void
    {
        $this->assertSame( '', RelativeTime::humanize( '', $this->now ) );
        $this->assertSame( '', RelativeTime::humanize( 'not-a-date', $this->now ) );
    }

    public function test_null_input_returns_empty(): void
    {
        $this->assertSame( '', RelativeTime::humanize( null, $this->now ) );
    }

    public function test_exact_one_year_uses_year_not_month(): void
    {
        // Boundary: 365 days = 12 months OR 1 year. Per the plan, ≥ 365 days → years.
        $iso = $this->now->modify( '-365 days' )->format( DateTimeImmutable::ATOM );
        $this->assertSame( '1 year ago', RelativeTime::humanize( $iso, $this->now ) );
    }

    public function test_default_now_is_current_time_when_not_provided(): void
    {
        // When the caller omits $now, the helper uses the current time.
        // Result must be one of the expected labels.
        $iso = ( new DateTimeImmutable( '-2 minutes' ) )->format( DateTimeImmutable::ATOM );
        $result = RelativeTime::humanize( $iso );
        $this->assertContains( $result, array( '1 min ago', '2 min ago', '3 min ago' ) );
    }
}
