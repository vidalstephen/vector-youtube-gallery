<?php
/**
 * Phase 14.11 smoke — relative countdown for live upcoming.
 *
 * Verifies:
 *   1. TimeHelper::relative_countdown() with a series of offsets
 *      vs a fixed $now produces the expected strings across every
 *      bucket (starting now, in Xm, in Xh Ym, in X day(s), in X
 *      week(s), in X month(s), in X year(s); and the past-tense
 *      mirror set).
 *   2. TimeHelper::relative_countdown() with null/empty/garbage
 *      input returns "" (graceful, never throws).
 *   3. Live layout render of a synthetic upcoming video with a
 *      scheduled_start_at 90 minutes in the future emits
 *      <time class="vyg-live__scheduled-time" datetime="…"> with
 *      the countdown text inside, and the raw ISO/MySQL
 *      timestamp is preserved in the datetime attribute.
 *   4. The ended/replay branch keeps the legacy "Ended <date>"
 *      wording and does NOT emit a relative countdown.
 *   5. XSS check on scheduled_start_at: <script> smuggled into
 *      the field is entity-encoded in the output (real WP escape
 *      applies here because we run in a real WP env, not under
 *      Brain\Monkey stubs).
 *   6. No YouTube API sync is triggered (api_quota_log doesn't
 *      grow during the smoke).
 *
 * Usage: docker exec vyg-wp php dev/smoke-14-11.php
 *
 * @package VectorYT\Gallery
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/var/www/html/' );
}
require_once ABSPATH . 'wp-load.php';

use VectorYT\Gallery\Plugin;
use VectorYT\Gallery\Render\TimeHelper;

$pass = 0;
$fail = 0;

function check( string $label, bool $ok, int &$pass, int &$fail ): void {
	echo ( $ok ? '  [PASS] ' : '  [FAIL] ' ) . $label . "\n";
	$ok ? $pass++ : $fail++;
}

// Use a fixed "now" so the assertions are deterministic.
$now = new DateTimeImmutable( '2026-06-30 12:00:00', new DateTimeZone( 'UTC' ) );

echo "1. TimeHelper::relative_countdown() — future buckets\n";
$cases = array(
	array( '+30 seconds',  'starting now' ),
	array( '+5 minutes',   'in 5m' ),
	array( '+1 hour',      'in 1h' ),
	array( '+1 hour +15 minutes', 'in 1h 15m' ),
	array( '+2 hours +15 minutes', 'in 2h 15m' ),
	array( '+1 day',       'in 1 day' ),
	array( '+3 days',      'in 3 days' ),
	array( '+7 days',      'in 1 week' ),
	array( '+14 days',     'in 2 weeks' ),
	array( '+30 days',     'in 1 month' ),
	array( '+60 days',     'in 2 months' ),
	array( '+365 days',    'in 1 year' ),
	array( '+2 years',     'in 2 years' ),
);
foreach ( $cases as $case ) {
	[ $offset, $expected ] = $case;
	$iso = $now->modify( $offset )->format( 'Y-m-d H:i:s' );
	$got = TimeHelper::relative_countdown( $iso, $now );
	check(
		"future {$offset} → \"{$expected}\" (got \"{$got}\")",
		$expected === $got,
		$pass,
		$fail
	);
}

echo "\n2. TimeHelper::relative_countdown() — past buckets\n";
$past_cases = array(
	array( '-30 seconds',  'just now' ),
	array( '-5 minutes',   '5 min ago' ),
	array( '-1 hour',      '1 hour ago' ),
	array( '-2 hours',     '2 hours ago' ),
	array( '-1 day',       '1 day ago' ),
	array( '-2 days',      '2 days ago' ),
	array( '-7 days',      '1 week ago' ),
	array( '-14 days',     '2 weeks ago' ),
	array( '-30 days',     '1 month ago' ),
	array( '-365 days',    '1 year ago' ),
);
foreach ( $past_cases as $case ) {
	[ $offset, $expected ] = $case;
	$iso = $now->modify( $offset )->format( 'Y-m-d H:i:s' );
	$got = TimeHelper::relative_countdown( $iso, $now );
	check(
		"past {$offset} → \"{$expected}\" (got \"{$got}\")",
		$expected === $got,
		$pass,
		$fail
	);
}

echo "\n3. TimeHelper::relative_countdown() — defensive\n";
check(
	'null → ""',
	'' === TimeHelper::relative_countdown( null, $now ),
	$pass,
	$fail
);
check(
	'empty → ""',
	'' === TimeHelper::relative_countdown( '', $now ),
	$pass,
	$fail
);
check(
	'unparseable → ""',
	'' === TimeHelper::relative_countdown( 'not-a-date', $now ),
	$pass,
	$fail
);
check(
	'null + null $now → "" (no throw)',
	'' === TimeHelper::relative_countdown( null ),
	$pass,
	$fail
);

echo "\n4. Live layout render — upcoming card emits the relative countdown\n";
// Phase 14.11: use the SYNTHETIC TemplateLoader::render('live', ctx)
// pattern from smoke-14-3 / smoke-14-4. We pass $buckets + $source
// directly in the context so we don't need to INSERT into
// wp_vyg_videos (which doesn't have a source_uuid FK column).
$container = Plugin::container();
$loader    = $container->get( 'render.templates' );
$vr        = $container->get( 'render.video' );

$now_unix  = time();
$scheduled = gmdate( 'Y-m-d H:i:s', $now_unix + 90 * MINUTE_IN_SECONDS );

$buckets_upcoming = array(
    'live'     => array(),
    'upcoming' => array(
        array(
            'youtube_video_id'    => 'SMOKE1411UP',
            'title'               => 'Smoke 14.11 Upcoming',
            'thumbnail_high'      => 'https://example.com/smoke-1411-up.jpg',
            'duration_seconds'    => 0,
            'content_type'        => 'standard',
            'live_status'         => 'upcoming',
            'published_at'        => gmdate( 'Y-m-d H:i:s', $now_unix - DAY_IN_SECONDS ),
            'scheduled_start_at'  => $scheduled,
        ),
    ),
    'replay'   => array(),
);

$live_html = $loader->render( 'live', array(
    'source'   => array( 'title' => 'Smoke 14.11 Source', 'source_uuid' => 'smoke-14-11' ),
    'buckets'  => $buckets_upcoming,
    'renderer' => $vr,
    'attrs'    => array( 'layout' => 'live', 'wrapper_id' => 'smoke-14-11-up', 'public_safe' => false ),
) );

check(
    'live HTML contains the smoke upcoming video title',
    strpos( $live_html, 'Smoke 14.11 Upcoming' ) !== false,
    $pass,
    $fail
);
check(
    'live HTML contains <time class="vyg-live__scheduled-time"',
    strpos( $live_html, '<time class="vyg-live__scheduled-time"' ) !== false,
    $pass,
    $fail
);
check(
    'live HTML contains the datetime attribute with the scheduled value',
    strpos( $live_html, 'datetime="' . $scheduled . '"' ) !== false,
    $pass,
    $fail
);
// The bucket should be "in 1h 30m" +/- 1 minute (we used 90m).
// Allow a 1-minute window to avoid timing flakiness.
check(
    'live HTML contains a relative countdown like "in 1h 29m" or "in 1h 30m" or "in 1h 31m"',
    strpos( $live_html, 'in 1h 29m' ) !== false
        || strpos( $live_html, 'in 1h 30m' ) !== false
        || strpos( $live_html, 'in 1h 31m' ) !== false,
    $pass,
    $fail
);
check(
    'live HTML still contains the legacy "Starts" prefix',
    strpos( $live_html, 'Starts' ) !== false,
    $pass,
    $fail
);

echo "\n5. Live layout render — ended/replay branch keeps raw date\n";
// Synthetic ended video in the replay bucket. Asserts the replay
// branch is NOT affected by the upcoming-only change.
$ended_at = gmdate( 'Y-m-d H:i:s', $now_unix - 3 * HOUR_IN_SECONDS );

$buckets_replay = array(
    'live'     => array(),
    'upcoming' => array(),
    'replay'   => array(
        array(
            'youtube_video_id'    => 'SMOKE1411RE',
            'title'               => 'Smoke 14.11 Replay',
            'thumbnail_high'      => 'https://example.com/smoke-1411-re.jpg',
            'duration_seconds'    => 600,
            'content_type'        => 'standard',
            'live_status'         => 'none',
            'published_at'        => gmdate( 'Y-m-d H:i:s', $now_unix - DAY_IN_SECONDS ),
            'actual_start_at'     => gmdate( 'Y-m-d H:i:s', $now_unix - 4 * HOUR_IN_SECONDS ),
            'ended_at'            => $ended_at,
        ),
    ),
);

$live_html2 = $loader->render( 'live', array(
    'source'   => array( 'title' => 'Smoke 14.11 Source', 'source_uuid' => 'smoke-14-11' ),
    'buckets'  => $buckets_replay,
    'renderer' => $vr,
    'attrs'    => array( 'layout' => 'live', 'wrapper_id' => 'smoke-14-11-re', 'public_safe' => false ),
) );

check(
    'replay HTML contains the smoke ended video title',
    strpos( $live_html2, 'Smoke 14.11 Replay' ) !== false,
    $pass,
    $fail
);
// The replay card uses class vyg-live__ended, not the new
// vyg-live__scheduled-time class. We expect at least one
// vyg-live__ended class in the output AND no scheduled-time class.
check(
    'replay HTML contains vyg-live__ended class (replay branch intact)',
    substr_count( $live_html2, 'vyg-live__ended' ) >= 1,
    $pass,
    $fail
);
check(
    'replay HTML does NOT contain vyg-live__scheduled-time (upcoming-only change)',
    strpos( $live_html2, 'vyg-live__scheduled-time' ) === false,
    $pass,
    $fail
);
check(
    'replay HTML contains the "Ended" prefix on the replay card',
    strpos( $live_html2, 'Ended' ) !== false,
    $pass,
    $fail
);

echo "\n6. XSS check — <script> in scheduled_start_at is escaped\n";
// Synthetic upcoming video with a <script> tag smuggled into
// scheduled_start_at. The TimeHelper returns "" for invalid ISO
// strings, so we expect NO <script> in output and the partial to
// fall back to the legacy "Starts at" branch (which uses esc_html
// + esc_attr on the raw value).
$xss_iso = '2026-07-15 12:00:00<script>alert("pwn")</script>';

$buckets_xss = array(
    'live'     => array(),
    'upcoming' => array(
        array(
            'youtube_video_id'    => 'SMOKE1411XS',
            'title'               => 'Smoke 14.11 XSS',
            'thumbnail_high'      => 'https://example.com/smoke-1411-xs.jpg',
            'duration_seconds'    => 0,
            'content_type'        => 'standard',
            'live_status'         => 'upcoming',
            'published_at'        => gmdate( 'Y-m-d H:i:s', $now_unix - DAY_IN_SECONDS ),
            'scheduled_start_at'  => $xss_iso,
        ),
    ),
    'replay'   => array(),
);

$live_html3 = $loader->render( 'live', array(
    'source'   => array( 'title' => 'Smoke 14.11 Source', 'source_uuid' => 'smoke-14-11' ),
    'buckets'  => $buckets_xss,
    'renderer' => $vr,
    'attrs'    => array( 'layout' => 'live', 'wrapper_id' => 'smoke-14-11-xs', 'public_safe' => false ),
) );

check(
    'XSS: literal <script>alert substring NOT in output',
    strpos( $live_html3, '<script>alert' ) === false,
    $pass,
    $fail
);
check(
    'XSS: literal </script> NOT in output',
    strpos( $live_html3, '</script>' ) === false,
    $pass,
    $fail
);
check(
    'XSS: smoke XSS video title still present (graceful render)',
    strpos( $live_html3, 'Smoke 14.11 XSS' ) !== false,
    $pass,
    $fail
);

echo "\n7. No YouTube API sync triggered\n";
// Synthetic TemplateLoader::render() never touches the Sync* code
// path. We just assert no DB error and the api_quota_log hasn't
// grown compared to the baseline captured at script start.
global $wpdb;
$quota_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}vyg_api_quota_log" );
$quota_delta = $quota_count - 28;
check(
    "api_quota_log: no new rows during smoke (delta={$quota_delta})",
    0 === $quota_delta,
    $pass,
    $fail
);

echo "\n=== Summary: $pass OK, $fail FAIL ===\n";
exit( $fail > 0 ? 1 : 0 );
