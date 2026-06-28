<?php
/**
 * Unit tests for LiveStatusPollJob (Phase 5).
 *
 * Uses an in-memory WP-style $wpdb stub (via the test class's `wpdb`-shaped
 * recorder) plus a fake ApiClientInterface that returns preset videos.list
 * responses. We don't boot WordPress; the job's dependencies are
 * constructor-injected.
 *
 * @covers \VectorYT\Gallery\Sync\LiveStatusPollJob
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Sync;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Logging\Logger;
use VectorYT\Gallery\Sync\LiveStatusPollJob;
use VectorYT\Gallery\YouTube\ApiClientInterface;
use VectorYT\Gallery\YouTube\ApiException;

/**
 * Minimal stand-in: implements only the methods LiveStatusPollJob touches.
 */
final class FakeWpdb {
    public string $prefix = 'wp_';
    /** @var array<string,array<string,mixed>> */
    public array $rows = array();
    /** @var array<int,array<string,mixed>> */
    public array $updates = array();

    public function prepare( string $sql, ...$args ): string {
        // No-op — we never actually execute the SQL.
        return $sql;
    }
    public function get_results( $sql, $output = 'OBJECT' ): array {
        // Return the test's seed rows.
        return array_values( $this->rows );
    }
    public function get_row( $sql, $output = 'OBJECT' ) {
        // Return the first row matching the source_id filter (used by find_source_id_for_video).
        $rows = array_values( $this->rows );
        return $rows[0] ?? null;
    }
    public function get_col( $sql ) {
        return array();
    }
    public function get_var( $sql ) {
        return 0;
    }
    public function update( $table, $data, $where, $format = null, $where_format = null ) {
        $this->updates[] = array( 'table' => $table, 'data' => $data, 'where' => $where );
        return 1;
    }
    public function insert_id() { return 0; }
    public function query( $sql ) { return 0; }
}

/**
 * Fake API client that returns preset videos.list responses.
 */
final class FakeApiClient implements ApiClientInterface {

    /** @var array<string,array<string,mixed>> */
    public array $by_id = array();
    public int $calls = 0;

    public function videos_list( array $params ): array {
        ++$this->calls;
        $ids = isset( $params['id'] ) ? explode( ',', (string) $params['id'] ) : array();
        $items = array();
        foreach ( $ids as $id ) {
            if ( isset( $this->by_id[ trim( $id ) ] ) ) {
                $items[] = $this->by_id[ trim( $id ) ];
            }
        }
        return array( 'items' => $items );
    }
    public function channels_list( array $params ): array { return array( 'items' => array() ); }
    public function playlists_list( array $params ): array { return array( 'items' => array() ); }
    public function playlist_items_list( array $params ): array { return array( 'items' => array(), 'nextPageToken' => null ); }
    public function revoke_token( string $token ): bool { return true; }
    public function mode(): string { return 'fake'; }
}

/**
 * Fake VideoRepository that records update_by_id calls and returns no rows.
 */
final class FakeVideoRepository extends \VectorYT\Gallery\Repository\VideoRepository {
    /** @var array<int,array<string,mixed>> */
    public array $updates = array();

    public function find_live_videos_stub(): array {
        // not used — we override find_live_videos via a different approach
        return array();
    }
    public function update_by_id( int $id, array $updates ): int {
        $this->updates[] = array( 'id' => $id, 'updates' => $updates );
        return count( $updates );
    }
}

/**
 * Fake repositories to satisfy dependencies.
 */
final class FakePreviousRepo extends \VectorYT\Gallery\Repository\PreviousStreamsRepository {
    /** @var array<int,array<string,mixed>> */
    public array $upserts = array();
    public int $prune_calls = 0;
    public function upsert( array $stream ): int {
        $this->upserts[] = $stream;
        return count( $this->upserts );
    }
    public function prune_to_limit( int $source_id, int $limit = 50 ): int {
        ++$this->prune_calls;
        return 0;
    }
}

final class FakeSyncLogRepository extends \VectorYT\Gallery\Repository\SyncLogRepository {
    public function create_job( string $job_type, ?int $source_id = null, ?array $cursor = null ): int {
        return 1;
    }
}

final class FakeQuotaTracker extends \VectorYT\Gallery\YouTube\QuotaTracker {
    public int $recorded = 0;
    public function record( string $endpoint, ?int $response_code = null, ?int $source_id = null ): int {
        ++$this->recorded;
        return 1;
    }
}

final class FakeSettingsRepository extends \VectorYT\Gallery\Settings\SettingsRepository {
    public function __construct() {}
    public function get( string $key, $default = null ) {
        return 50;
    }
}

final class LiveStatusPollJobTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        \VectorYT\Gallery\Tests\Support\BrainHelpers::stubEscapeFunctions();
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_run_poll_calls_api_and_updates_videos(): void {
        // We need a global $wpdb in scope — LiveStatusPollJob queries it.
        global $wpdb;
        $wpdb = new FakeWpdb();
        $wpdb->rows = array(
            'v1' => array(
                'id' => 1,
                'youtube_video_id' => 'live1',
                'content_type' => 'live_active',
                'live_status'  => 'live',
                'title' => 'Old title',
                'thumbnail_default' => 'https://example/old.jpg',
            ),
        );

        $api = new FakeApiClient();
        $api->by_id['live1'] = array(
            'id' => 'live1',
            'snippet' => array( 'title' => 'Updated title' ),
            'status'  => array( 'uploadStatus' => 'processed' ),
            'liveStreamingDetails' => array(
                'actualStartTime'   => '2024-06-28T12:00:00Z',
                'concurrentViewers' => '1234',
            ),
            'statistics' => array( 'viewCount' => '50000' ),
        );

        $videos = new FakeVideoRepository();
        $previous = new FakePreviousRepo();
        $logs = new FakeSyncLogRepository();
        $quota = new FakeQuotaTracker();
        $logger = new Logger();
        $settings = new FakeSettingsRepository();

        $job = new LiveStatusPollJob( $api, $videos, $previous, $logs, $quota, $logger, $settings );

        $stats = $job->run_poll();

        $this->assertSame( 1, $stats['checked'] );
        $this->assertSame( 1, $stats['updated'] );
        $this->assertSame( 0, $stats['ended'] );
        $this->assertSame( 1, $api->calls, 'should batch videos into one videos.list call' );
        $this->assertSame( 1, $quota->recorded, 'should record quota usage' );
        $this->assertCount( 1, $videos->updates, 'should update exactly one row' );

        $update = $videos->updates[0];
        $this->assertSame( 1, $update['id'] );
        $this->assertSame( 'Updated title', $update['updates']['title'] );
        $this->assertSame( 1234, $update['updates']['concurrent_viewers'] );
        $this->assertSame( 50000, $update['updates']['view_count'] );
        $this->assertSame( 'live', $update['updates']['live_status'] );
    }

    public function test_run_poll_promotes_ended_stream_to_previous(): void {
        global $wpdb;
        $wpdb = new FakeWpdb();
        $wpdb->rows = array(
            'v1' => array(
                'id' => 1,
                'youtube_video_id' => 'live2',
                'content_type' => 'live_active',
                'live_status'  => 'live',
                'title' => 'Live now ending',
                'thumbnail_default' => 'https://example/thumb.jpg',
                'youtube_channel_id' => 'UCabc',
            ),
        );

        $api = new FakeApiClient();
        $api->by_id['live2'] = array(
            'id' => 'live2',
            'snippet' => array( 'title' => 'Live now ending' ),
            'status'  => array(),
            'liveStreamingDetails' => array(
                'actualStartTime' => '2024-06-28T10:00:00Z',
                'actualEndTime'   => '2024-06-28T11:30:00Z',
                'concurrentViewers' => '500',
            ),
            'statistics' => array( 'viewCount' => '9999' ),
        );

        $videos = new FakeVideoRepository();
        $previous = new FakePreviousRepo();
        $logs = new FakeSyncLogRepository();
        $quota = new FakeQuotaTracker();
        $logger = new Logger();
        $settings = new FakeSettingsRepository();

        $job = new LiveStatusPollJob( $api, $videos, $previous, $logs, $quota, $logger, $settings );
        $stats = $job->run_poll();

        $this->assertSame( 1, $stats['ended'] );
        $this->assertCount( 1, $previous->upserts, 'should upsert to previous_streams' );
        $this->assertSame( 1, $previous->prune_calls, 'should prune to limit' );
        $promoted = $previous->upserts[0];
        $this->assertSame( 'live2', $promoted['youtube_video_id'] );
        $this->assertSame( 500, $promoted['peak_concurrent_viewers'] );
        $this->assertSame( 9999, $promoted['view_count'] );
    }

    public function test_run_poll_with_empty_live_set_is_noop(): void {
        global $wpdb;
        $wpdb = new FakeWpdb();
        $wpdb->rows = array();

        $api = new FakeApiClient();
        $videos = new FakeVideoRepository();
        $previous = new FakePreviousRepo();
        $logs = new FakeSyncLogRepository();
        $quota = new FakeQuotaTracker();
        $logger = new Logger();
        $settings = new FakeSettingsRepository();

        $job = new LiveStatusPollJob( $api, $videos, $previous, $logs, $quota, $logger, $settings );
        $stats = $job->run_poll();

        $this->assertSame( 0, $stats['checked'] );
        $this->assertSame( 0, $api->calls );
        $this->assertSame( 0, $quota->recorded );
        $this->assertCount( 0, $videos->updates );
    }

    public function test_run_poll_handles_api_error_gracefully(): void {
        $throwing_api = new class implements ApiClientInterface {
            public function videos_list( array $params ): array {
                throw new ApiException( 'Too many requests', 'rate_limited', 429, null );
            }
            public function channels_list( array $params ): array { return array(); }
            public function playlists_list( array $params ): array { return array(); }
            public function playlist_items_list( array $params ): array { return array(); }
            public function revoke_token( string $token ): bool { return true; }
            public function mode(): string { return 'fake'; }
        };

        global $wpdb;
        $wpdb = new FakeWpdb();
        $wpdb->rows = array(
            'v1' => array( 'id' => 1, 'youtube_video_id' => 'live3', 'content_type' => 'live_active', 'live_status' => 'live', 'title' => '', 'thumbnail_default' => '' ),
        );

        $videos = new FakeVideoRepository();
        $previous = new FakePreviousRepo();
        $logs = new FakeSyncLogRepository();
        $quota = new FakeQuotaTracker();
        $logger = new Logger();
        $settings = new FakeSettingsRepository();

        $job = new LiveStatusPollJob( $throwing_api, $videos, $previous, $logs, $quota, $logger, $settings );
        $stats = $job->run_poll();

        $this->assertSame( 0, $stats['checked'] ); // batch never reached
        $this->assertSame( 1, $stats['errors'] );
        $this->assertCount( 0, $videos->updates );
    }
}