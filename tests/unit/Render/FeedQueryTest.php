<?php
/**
 * Tests for FeedQuery::videos_for_feed() and count_videos_for_feed().
 *
 * Phase 8.7: mixed-source merge/dedupe/sort/exclude behavior, plus pinned
 * priority and manual video ID augmentation.
 *
 * @package VectorYT\Gallery\Tests\Unit\Render
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Render;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Render\FeedQuery;
use VectorYT\Gallery\Tests\Support\BrainHelpers;

require_once __DIR__ . '/../../bootstrap.php';

final class FeedQueryTest extends TestCase {

    /** @var FeedQuery */
    private $fq;

    /** @var StubFeedQuery */
    private $stub;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        BrainHelpers::stubEscapeFunctions();
        BrainHelpers::stubOptionFunctions();
        $this->stub = new StubFeedQuery();
        $this->fq   = $this->stub;
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_videos_for_feed_returns_empty_for_empty_sources(): void {
        $rows = $this->fq->videos_for_feed( array(
            'source' => array(
                'sources'           => array(),
                'manual_video_ids'  => array(),
                'exclude_video_ids' => array(),
            ),
        ) );
        $this->assertSame( array(), $rows );
    }

    public function test_videos_for_feed_merges_rows_from_multiple_sources(): void {
        $this->stub->per_source_rows = array(
            'src-a' => array(
                $this->video( 'vid-a-1', '2026-01-01 00:00:00' ),
                $this->video( 'vid-a-2', '2026-01-02 00:00:00' ),
            ),
            'src-b' => array(
                $this->video( 'vid-b-1', '2026-01-03 00:00:00' ),
            ),
        );
        $rows = $this->fq->videos_for_feed( array(
            'source' => array(
                'sources' => array(
                    array( 'source_uuid' => 'src-a', 'weight' => 1.0, 'pinned' => false, 'label' => '' ),
                    array( 'source_uuid' => 'src-b', 'weight' => 1.0, 'pinned' => false, 'label' => '' ),
                ),
            ),
            'limit' => 12,
        ) );
        $this->assertCount( 3, $rows );
        $ids = array_column( $rows, 'youtube_video_id' );
        // Order is by published_at DESC.
        $this->assertSame( array( 'vid-b-1', 'vid-a-2', 'vid-a-1' ), $ids );
    }

    public function test_videos_for_feed_dedupes_by_youtube_video_id(): void {
        // Same video appears in two sources; dedupe keeps the first occurrence.
        $this->stub->per_source_rows = array(
            'src-a' => array( $this->video( 'shared-1', '2026-01-05 00:00:00' ) ),
            'src-b' => array( $this->video( 'shared-1', '2026-01-10 00:00:00' ) ),
            'src-c' => array( $this->video( 'unique-c', '2026-01-15 00:00:00' ) ),
        );
        $rows = $this->fq->videos_for_feed( array(
            'source' => array(
                'sources' => array(
                    array( 'source_uuid' => 'src-a', 'weight' => 1.0, 'pinned' => false, 'label' => '' ),
                    array( 'source_uuid' => 'src-b', 'weight' => 1.0, 'pinned' => false, 'label' => '' ),
                    array( 'source_uuid' => 'src-c', 'weight' => 1.0, 'pinned' => false, 'label' => '' ),
                ),
            ),
            'limit' => 12,
        ) );
        // Dedupe → 2 rows. Sort by published_at DESC → unique-c first, then shared-1.
        $this->assertCount( 2, $rows );
        $ids = array_column( $rows, 'youtube_video_id' );
        $this->assertSame( array( 'unique-c', 'shared-1' ), $ids );
    }

    public function test_videos_for_feed_pinned_sources_take_dedupe_priority(): void {
        // Same video appears in pinned source first (older date) AND in
        // non-pinned source (newer date). Pinned source's row should win the
        // dedupe; the row kept will carry pinned source's date 2026-01-01.
        $this->stub->per_source_rows = array(
            'src-pinned'  => array( $this->video( 'shared-1', '2026-01-01 00:00:00' ) ),
            'src-unpinned' => array( $this->video( 'shared-1', '2026-01-20 00:00:00' ) ),
        );
        $rows = $this->fq->videos_for_feed( array(
            'source' => array(
                'sources' => array(
                    array( 'source_uuid' => 'src-pinned',  'weight' => 1.0, 'pinned' => true,  'label' => 'Featured' ),
                    array( 'source_uuid' => 'src-unpinned','weight' => 1.0, 'pinned' => false, 'label' => '' ),
                ),
            ),
            'limit' => 12,
        ) );
        $this->assertCount( 1, $rows );
        $this->assertSame( 'shared-1', $rows[0]['youtube_video_id'] );
        $this->assertSame( '2026-01-01 00:00:00', $rows[0]['published_at'] );
    }

    public function test_videos_for_feed_applies_exclude_video_ids(): void {
        $this->stub->per_source_rows = array(
            'src-a' => array(
                $this->video( 'keep-1',   '2026-01-01 00:00:00' ),
                $this->video( 'drop-me',  '2026-01-02 00:00:00' ),
                $this->video( 'keep-2',   '2026-01-03 00:00:00' ),
            ),
        );
        $rows = $this->fq->videos_for_feed( array(
            'source' => array(
                'sources'           => array(
                    array( 'source_uuid' => 'src-a', 'weight' => 1.0, 'pinned' => false, 'label' => '' ),
                ),
                'exclude_video_ids' => array( 'drop-me' ),
            ),
            'limit' => 12,
        ) );
        $ids = array_column( $rows, 'youtube_video_id' );
        $this->assertSame( array( 'keep-2', 'keep-1' ), $ids );
    }

    public function test_videos_for_feed_augments_with_manual_video_ids(): void {
        $this->stub->per_source_rows = array(
            'src-a' => array( $this->video( 'source-1', '2026-01-05 00:00:00' ) ),
        );
        $this->stub->manual_rows = array(
            $this->video( 'manual-1', '2026-01-10 00:00:00' ),
        );
        $rows = $this->fq->videos_for_feed( array(
            'source' => array(
                'sources'           => array(
                    array( 'source_uuid' => 'src-a', 'weight' => 1.0, 'pinned' => false, 'label' => '' ),
                ),
                'manual_video_ids'  => array( 'manual-1' ),
            ),
            'limit' => 12,
        ) );
        $ids = array_column( $rows, 'youtube_video_id' );
        $this->assertContains( 'source-1', $ids );
        $this->assertContains( 'manual-1', $ids );
    }

    public function test_videos_for_feed_respects_limit_and_offset(): void {
        $this->stub->per_source_rows = array(
            'src-a' => array(
                $this->video( 'v1', '2026-01-01 00:00:00' ),
                $this->video( 'v2', '2026-01-02 00:00:00' ),
                $this->video( 'v3', '2026-01-03 00:00:00' ),
                $this->video( 'v4', '2026-01-04 00:00:00' ),
                $this->video( 'v5', '2026-01-05 00:00:00' ),
            ),
        );
        $page1 = $this->fq->videos_for_feed( array(
            'source' => array(
                'sources' => array(
                    array( 'source_uuid' => 'src-a', 'weight' => 1.0, 'pinned' => false, 'label' => '' ),
                ),
            ),
            'limit'  => 2,
            'offset' => 0,
        ) );
        $page2 = $this->fq->videos_for_feed( array(
            'source' => array(
                'sources' => array(
                    array( 'source_uuid' => 'src-a', 'weight' => 1.0, 'pinned' => false, 'label' => '' ),
                ),
            ),
            'limit'  => 2,
            'offset' => 2,
        ) );
        $this->assertSame( array( 'v5', 'v4' ), array_column( $page1, 'youtube_video_id' ) );
        $this->assertSame( array( 'v3', 'v2' ), array_column( $page2, 'youtube_video_id' ) );
    }

    public function test_videos_for_feed_skips_source_entries_without_uuid(): void {
        $this->stub->per_source_rows = array(
            'src-valid' => array( $this->video( 'good', '2026-01-01 00:00:00' ) ),
        );
        $rows = $this->fq->videos_for_feed( array(
            'source' => array(
                'sources' => array(
                    array( 'source_uuid' => '',         'weight' => 1.0, 'pinned' => false, 'label' => '' ),
                    'not-an-array',
                    array( 'source_uuid' => 'src-valid','weight' => 1.0, 'pinned' => false, 'label' => '' ),
                ),
            ),
            'limit'  => 12,
        ) );
        $this->assertCount( 1, $rows );
        $this->assertSame( 'good', $rows[0]['youtube_video_id'] );
    }

    public function test_count_videos_for_feed_counts_after_exclude_filter(): void {
        $this->stub->per_source_rows = array(
            'src-a' => array(
                $this->video( 'v1', '2026-01-01 00:00:00' ),
                $this->video( 'v2', '2026-01-02 00:00:00' ),
                $this->video( 'v3', '2026-01-03 00:00:00' ),
            ),
        );
        $this->stub->manual_rows = array(
            $this->video( 'm1', '2026-01-10 00:00:00' ),
            $this->video( 'm2', '2026-01-11 00:00:00' ),
        );
        $count = $this->fq->count_videos_for_feed( array(
            'source' => array(
                'sources'           => array(
                    array( 'source_uuid' => 'src-a', 'weight' => 1.0, 'pinned' => false, 'label' => '' ),
                ),
                'manual_video_ids'  => array( 'm1', 'm2' ),
                'exclude_video_ids' => array( 'v2' ),
            ),
            'include_manual' => true,
        ) );
        // 3 source - 1 excluded + 2 manual = 4.
        $this->assertSame( 4, $count );
    }

    public function test_videos_for_feed_with_empty_manual_ids_skips_lookup(): void {
        $this->stub->per_source_rows = array(
            'src-a' => array( $this->video( 'v1', '2026-01-01 00:00:00' ) ),
        );
        $rows = $this->fq->videos_for_feed( array(
            'source' => array(
                'sources'           => array(
                    array( 'source_uuid' => 'src-a', 'weight' => 1.0, 'pinned' => false, 'label' => '' ),
                ),
                'manual_video_ids'  => array(),
            ),
        ) );
        $this->assertCount( 1, $rows );
    }

    private function video( string $youtube_id, string $published_at ): array {
        return array(
            'id'               => 1,
            'youtube_video_id' => $youtube_id,
            'title'            => 'Video ' . $youtube_id,
            'published_at'     => $published_at,
            'content_type'     => 'standard',
        );
    }
}

/**
 * Stub FeedQuery that lets us control the result of videos_for_source() and
 * videos_for_ids() without touching the real VideoRepository.
 */
final class StubFeedQuery extends FeedQuery {
    /** @var array<string,array<int,array<string,mixed>>> */
    public array $per_source_rows = array();

    /** @var array<int,array<string,mixed>> */
    public array $manual_rows = array();

    public function videos_for_source( array $args ): array {
        $uuid = (string) ( $args['source_uuid'] ?? '' );
        return $this->per_source_rows[ $uuid ] ?? array();
    }

    public function videos_for_ids( array $ids, array $filters = array() ): array {
        return $this->manual_rows;
    }

    public function find_source_by_uuid( string $uuid ): ?array {
        return array(
            'source_uuid'       => $uuid,
            'source_type'       => 'channel',
            'youtube_channel_id' => 'UC_' . $uuid,
            'status'            => 'active',
        );
    }
}