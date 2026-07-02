<?php
/**
 * Phase 14.11 unit tests — relative countdown for live upcoming.
 *
 * Pinned behavior:
 *   1. TimeHelper::relative_countdown() — future-bucket boundaries
 *      (starting now / minutes / hours / days / weeks / months / years).
 *   2. TimeHelper::relative_countdown() — past-bucket boundaries
 *      (just now / min ago / hour / day / week / month / year ago).
 *   3. TimeHelper::relative_countdown() — defensive cases (null,
 *      empty, unparseable input).
 *   4. Live-card partial — the upcoming branch wraps the countdown
 *      text in a <time class="vyg-live__scheduled-time"> element
 *      with the raw ISO/MySQL timestamp in `datetime=`, escaping
 *      both the class-bearing tag and the contents.
 *   5. Live-card partial — the ended/replay branch is unchanged:
 *      still uses the raw date, NOT a relative countdown, and is
 *      not wrapped in a <time> element with vyg-live__scheduled-time.
 *   6. Live-card partial — when scheduled_start_at is missing, the
 *      <time> element is omitted entirely (graceful no-op).
 *   7. Live-card partial — XSS: a <script> tag in scheduled_start_at
 *      is escaped (datetime attribute + visible text both safe).
 *
 * @covers \VectorYT\Gallery\Render\TimeHelper
 * @covers \VectorYT\Gallery\Render\TemplateLoader
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Render;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Render\TimeHelper;
use VectorYT\Gallery\Render\VideoRenderer;
use VectorYT\Gallery\Tests\Support\BrainHelpers;

require_once __DIR__ . '/../../bootstrap.php';

final class RelativeCountdownTest extends TestCase {

	private VideoRenderer   $renderer;
	private DateTimeImmutable $now;

	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		BrainHelpers::stubEscapeFunctions();
		BrainHelpers::stubOptionFunctions();
		$this->renderer = new VideoRenderer();
		// Fixed "now" for deterministic counts.
		$this->now = new DateTimeImmutable( '2026-06-30 12:00:00', new DateTimeZone( 'UTC' ) );
	}

	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	// -----------------------------------------------------------------
	// TimeHelper::relative_countdown() — future buckets
	// -----------------------------------------------------------------

	public function test_future_under_one_minute_returns_starting_now(): void {
		$iso = $this->now->modify( '+30 seconds' )->format( 'Y-m-d H:i:s' );
		$this->assertSame( 'starting now', TimeHelper::relative_countdown( $iso, $this->now ) );

		$iso = $this->now->modify( '+59 seconds' )->format( 'Y-m-d H:i:s' );
		$this->assertSame( 'starting now', TimeHelper::relative_countdown( $iso, $this->now ) );
	}

	public function test_future_under_one_hour_returns_minutes_only(): void {
		$iso = $this->now->modify( '+5 minutes' )->format( 'Y-m-d H:i:s' );
		$this->assertSame( 'in 5m', TimeHelper::relative_countdown( $iso, $this->now ) );

		$iso = $this->now->modify( '+59 minutes' )->format( 'Y-m-d H:i:s' );
		$this->assertSame( 'in 59m', TimeHelper::relative_countdown( $iso, $this->now ) );
	}

	public function test_future_under_24h_returns_hours_and_minutes(): void {
		$iso = $this->now->modify( '+1 hour +15 minutes' )->format( 'Y-m-d H:i:s' );
		$this->assertSame( 'in 1h 15m', TimeHelper::relative_countdown( $iso, $this->now ) );

		$iso = $this->now->modify( '+2 hours +15 minutes' )->format( 'Y-m-d H:i:s' );
		$this->assertSame( 'in 2h 15m', TimeHelper::relative_countdown( $iso, $this->now ) );

		$iso = $this->now->modify( '+23 hours +45 minutes' )->format( 'Y-m-d H:i:s' );
		$this->assertSame( 'in 23h 45m', TimeHelper::relative_countdown( $iso, $this->now ) );
	}

	public function test_future_under_24h_omits_zero_minutes(): void {
		$iso = $this->now->modify( '+2 hours' )->format( 'Y-m-d H:i:s' );
		$this->assertSame( 'in 2h', TimeHelper::relative_countdown( $iso, $this->now ) );

		$iso = $this->now->modify( '+5 hours' )->format( 'Y-m-d H:i:s' );
		$this->assertSame( 'in 5h', TimeHelper::relative_countdown( $iso, $this->now ) );
	}

	public function test_future_under_7d_returns_days(): void {
		$iso = $this->now->modify( '+1 day' )->format( 'Y-m-d H:i:s' );
		$this->assertSame( 'in 1 day', TimeHelper::relative_countdown( $iso, $this->now ) );

		$iso = $this->now->modify( '+3 days' )->format( 'Y-m-d H:i:s' );
		$this->assertSame( 'in 3 days', TimeHelper::relative_countdown( $iso, $this->now ) );

		$iso = $this->now->modify( '+6 days' )->format( 'Y-m-d H:i:s' );
		$this->assertSame( 'in 6 days', TimeHelper::relative_countdown( $iso, $this->now ) );
	}

	public function test_future_under_30d_returns_weeks(): void {
		$iso = $this->now->modify( '+7 days' )->format( 'Y-m-d H:i:s' );
		$this->assertSame( 'in 1 week', TimeHelper::relative_countdown( $iso, $this->now ) );

		$iso = $this->now->modify( '+14 days' )->format( 'Y-m-d H:i:s' );
		$this->assertSame( 'in 2 weeks', TimeHelper::relative_countdown( $iso, $this->now ) );

		$iso = $this->now->modify( '+21 days' )->format( 'Y-m-d H:i:s' );
		// 21/7 = 3 weeks
		$this->assertSame( 'in 3 weeks', TimeHelper::relative_countdown( $iso, $this->now ) );
	}

	public function test_future_under_365d_returns_months(): void {
		$iso = $this->now->modify( '+30 days' )->format( 'Y-m-d H:i:s' );
		$this->assertSame( 'in 1 month', TimeHelper::relative_countdown( $iso, $this->now ) );

		$iso = $this->now->modify( '+60 days' )->format( 'Y-m-d H:i:s' );
		$this->assertSame( 'in 2 months', TimeHelper::relative_countdown( $iso, $this->now ) );

		$iso = $this->now->modify( '+180 days' )->format( 'Y-m-d H:i:s' );
		// 180/30 = 6 months
		$this->assertSame( 'in 6 months', TimeHelper::relative_countdown( $iso, $this->now ) );
	}

	public function test_future_over_365d_returns_years(): void {
		$iso = $this->now->modify( '+365 days' )->format( 'Y-m-d H:i:s' );
		$this->assertSame( 'in 1 year', TimeHelper::relative_countdown( $iso, $this->now ) );

		$iso = $this->now->modify( '+2 years' )->format( 'Y-m-d H:i:s' );
		$this->assertSame( 'in 2 years', TimeHelper::relative_countdown( $iso, $this->now ) );
	}

	// -----------------------------------------------------------------
	// TimeHelper::relative_countdown() — past buckets
	// -----------------------------------------------------------------

	public function test_past_under_one_minute_returns_just_now(): void {
		$iso = $this->now->modify( '-30 seconds' )->format( 'Y-m-d H:i:s' );
		$this->assertSame( 'just now', TimeHelper::relative_countdown( $iso, $this->now ) );
	}

	public function test_past_minutes_returns_min_ago(): void {
		$iso = $this->now->modify( '-5 minutes' )->format( 'Y-m-d H:i:s' );
		$this->assertSame( '5 min ago', TimeHelper::relative_countdown( $iso, $this->now ) );

		$iso = $this->now->modify( '-59 minutes' )->format( 'Y-m-d H:i:s' );
		$this->assertSame( '59 min ago', TimeHelper::relative_countdown( $iso, $this->now ) );
	}

	public function test_past_hours_returns_hours_ago(): void {
		$iso = $this->now->modify( '-1 hour' )->format( 'Y-m-d H:i:s' );
		$this->assertSame( '1 hour ago', TimeHelper::relative_countdown( $iso, $this->now ) );

		$iso = $this->now->modify( '-3 hours' )->format( 'Y-m-d H:i:s' );
		$this->assertSame( '3 hours ago', TimeHelper::relative_countdown( $iso, $this->now ) );
	}

	public function test_past_days_returns_days_ago(): void {
		$iso = $this->now->modify( '-1 day' )->format( 'Y-m-d H:i:s' );
		$this->assertSame( '1 day ago', TimeHelper::relative_countdown( $iso, $this->now ) );

		$iso = $this->now->modify( '-2 days' )->format( 'Y-m-d H:i:s' );
		$this->assertSame( '2 days ago', TimeHelper::relative_countdown( $iso, $this->now ) );
	}

	public function test_past_weeks_returns_weeks_ago(): void {
		$iso = $this->now->modify( '-7 days' )->format( 'Y-m-d H:i:s' );
		$this->assertSame( '1 week ago', TimeHelper::relative_countdown( $iso, $this->now ) );

		$iso = $this->now->modify( '-14 days' )->format( 'Y-m-d H:i:s' );
		$this->assertSame( '2 weeks ago', TimeHelper::relative_countdown( $iso, $this->now ) );
	}

	// -----------------------------------------------------------------
	// TimeHelper::relative_countdown() — defensive cases
	// -----------------------------------------------------------------

	public function test_null_input_returns_empty(): void {
		$this->assertSame( '', TimeHelper::relative_countdown( null, $this->now ) );
	}

	public function test_empty_input_returns_empty(): void {
		$this->assertSame( '', TimeHelper::relative_countdown( '', $this->now ) );
	}

	public function test_unparseable_input_returns_empty(): void {
		$this->assertSame( '', TimeHelper::relative_countdown( 'not-a-date', $this->now ) );
		$this->assertSame( '', TimeHelper::relative_countdown( '2026-13-99 25:99:99', $this->now ) );
	}

	public function test_default_now_uses_current_time(): void {
		// When the caller omits $now, the helper falls through to the
		// current time. A 2-minute-in-future ISO must still bucket
		// into the "in Xm" range (modulo the few seconds between the
		// `time()` calls in the helper and the assertion).
		$iso = ( new DateTimeImmutable( '+2 minutes' ) )->format( 'Y-m-d H:i:s' );
		$out = TimeHelper::relative_countdown( $iso );
		$this->assertMatchesRegularExpression( '/^in [0-9]+m$/', $out );
	}

	// -----------------------------------------------------------------
	// Live-card partial — <time> wrapper + a11y
	// -----------------------------------------------------------------

	/**
	 * Build a minimal "upcoming" video row that the live-card partial
	 * will render. The `scheduled_start_at` is interpreted as UTC by
	 * TimeHelper (the schema stores it via gmdate in
	 * VideoNormalizer::parse_mysql_datetime).
	 *
	 * @param string $scheduled MySQL UTC datetime string.
	 * @return array<string,mixed>
	 */
	private function upcoming_video( string $scheduled ): array {
		return array(
			'youtube_video_id'   => 'UPCOMING_1',
			'title'              => 'Upcoming Live',
			'thumbnail_high'     => 'https://example.com/up.jpg',
			'duration_seconds'   => 0,
			'content_type'       => 'standard',
			'live_status'        => 'upcoming',
			'published_at'       => '2026-06-01T00:00:00Z',
			'scheduled_start_at' => $scheduled,
			'ended_at'           => '',
		);
	}

	public function test_live_card_upcoming_wraps_countdown_in_time_element(): void {
		// 2h15m in the future from a "fixed now" can't be done
		// (Brain\Monkey stubs time() but not the full WP env), so we
		// include the partial directly and call the function. The
		// bucket boundary is proven by the TimeHelper unit tests
		// above; here we only pin the HTML contract: <time> wraps
		// the "Starts in X…" text, datetime attribute carries the
		// raw ISO/MySQL timestamp for a11y.
		$iso   = ( new DateTimeImmutable( '+2 hours +15 minutes' ) )->format( 'Y-m-d H:i:s' );
		$video = $this->upcoming_video( $iso );

		require_once __DIR__ . '/../../../src/Render/templates/partials/live-card.php';
		ob_start();
		vyg_render_live_card( $video, $this->renderer, 'upcoming' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<time class="vyg-live__scheduled-time"', $html );
		$this->assertStringContainsString( 'datetime="' . $iso . '"', $html );
		// Must contain the "Starts" prefix + a relative bucket token.
		$this->assertStringContainsString( 'Starts', $html );
		$this->assertMatchesRegularExpression( '/Starts (starting now|in [0-9]+[hdmwy])/', $html );
	}

	public function test_live_card_ended_branch_keeps_raw_date(): void {
		// Per the plan: "for live upcoming" only — the ended/replay
		// branch must NOT switch to a relative countdown, and must
		// NOT use the new <time class="vyg-live__scheduled-time">
		// wrapper. The raw `date_format` wording remains.
		$video = array(
			'youtube_video_id'   => 'ENDED_1',
			'title'              => 'Replay',
			'thumbnail_high'     => 'https://example.com/r.jpg',
			'duration_seconds'   => 0,
			'content_type'       => 'standard',
			'live_status'        => 'ended',
			'published_at'       => '2026-06-01T00:00:00Z',
			'scheduled_start_at' => '',
			'ended_at'           => '2026-06-30 18:00:00',
		);

		ob_start();
		vyg_render_live_card( $video, $this->renderer, 'ended' );
		$html = (string) ob_get_clean();

		// Replay uses the legacy "Ended <date>" wording and the
		// `vyg-live__ended` class, NOT the upcoming-only
		// `vyg-live__scheduled-time` class.
		$this->assertStringContainsString( 'vyg-live__ended', $html );
		$this->assertStringContainsString( 'Ended', $html );
		$this->assertStringNotContainsString( 'vyg-live__scheduled-time', $html );
		// No relative-bucket token on the replay path.
		$this->assertDoesNotMatchRegularExpression( '/Ended (in [0-9]+[hdmwy]|just now|min ago)/', $html );
	}

	public function test_live_card_upcoming_with_missing_scheduled_omits_time_element(): void {
		// Defensive: when scheduled_start_at is empty, the upcoming
		// branch is skipped entirely (the elseif guard fires). No
		// <time> element, no "Starts" text, just the badge + title.
		$video = array(
			'youtube_video_id'   => 'UPCOMING_BROKEN',
			'title'              => 'Upcoming Without Time',
			'thumbnail_high'     => 'https://example.com/u.jpg',
			'duration_seconds'   => 0,
			'content_type'       => 'standard',
			'live_status'        => 'upcoming',
			'published_at'       => '2026-06-01T00:00:00Z',
			'scheduled_start_at' => '',
			'ended_at'           => '',
		);

		ob_start();
		vyg_render_live_card( $video, $this->renderer, 'upcoming' );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( '<time', $html );
		$this->assertStringNotContainsString( 'vyg-live__scheduled-time', $html );
		$this->assertStringNotContainsString( 'Starts', $html );
	}

	public function test_live_card_upcoming_escapes_xss_in_scheduled(): void {
		// XSS guard: a <script> tag smuggled into scheduled_start_at
		// must be entity-encoded by esc_attr() in the datetime attr
		// and by esc_html() in the visible text. The Brain\Monkey
		// default stub returns the input verbatim (the production
		// esc_attr is the real defense); here we override the stub
		// to use a real attribute-escape for the duration of this
		// test only, so the assertion reflects production behavior.
		\Brain\Monkey\Functions\when( 'esc_attr' )->alias(
			static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' )
		);
		\Brain\Monkey\Functions\when( 'esc_html' )->alias(
			static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' )
		);

		$video = $this->upcoming_video( '2026-07-15 12:00:00' );
		$video['scheduled_start_at'] = '2026-07-15 12:00:00<script>alert("xss")</script>';

		require_once __DIR__ . '/../../../src/Render/templates/partials/live-card.php';
		ob_start();
		vyg_render_live_card( $video, $this->renderer, 'upcoming' );
		$html = (string) ob_get_clean();

		// The raw <script> substring must not survive into the HTML.
		$this->assertStringNotContainsString( '<script>alert', $html );
		// The entity-encoded version must be present in the datetime
		// attribute (or the field is unparseable and the <time>
		// element is omitted — both are safe outcomes; we assert
		// the first to confirm the template is wired up to escape).
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		// The closing </script> must also be escaped.
		$this->assertStringNotContainsString( '</script>', $html );
	}
}
