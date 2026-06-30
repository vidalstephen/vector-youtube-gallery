<?php
/**
 * Unit tests for FeedQuery::videos_for_source() — channel title LEFT JOIN.
 *
 * Phase 13.1: the grid card needs the channel name (from `vyg_sources.title`),
 * so videos_for_source() must LEFT JOIN the sources table on youtube_channel_id
 * and expose the result as `youtube_channel_title`. When no source row matches,
 * the field is an empty string (LEFT JOIN, not INNER).
 *
 * We use a small wpdb stub that captures the SQL and returns prepared rows
 * keyed by the JOINed query. This is enough to validate the SQL shape + the
 * column projection without booting a real database.
 *
 * @covers \VectorYT\Gallery\Render\FeedQuery
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Render\FeedQuery;
use VectorYT\Gallery\Tests\Support\BrainHelpers;

require_once __DIR__ . '/../../bootstrap.php';

final class FeedQueryChannelTitleTest extends TestCase
{
    /** @var object In-memory wpdb stub. */
    private $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        BrainHelpers::stubEscapeFunctions();

        // In-memory wpdb stub. We use one table-shaped fixture: each "table"
        // is keyed by name; get_row / get_results look up by the table name in
        // the most recent prepare() call's surrounding SQL.
        $this->wpdb = new class {
            public string $prefix = 'wp_';
            public array $sources_table = array(
                array(
                    'id'                  => 1,
                    'source_uuid'         => 'src-1',
                    'source_type'         => 'channel',
                    'youtube_channel_id'  => 'UC_channel_1',
                    'youtube_playlist_id' => null,
                    'youtube_video_id'    => null,
                ),
                array(
                    'id'                  => 2,
                    'source_uuid'         => 'src-2',
                    'source_type'         => 'channel',
                    'youtube_channel_id'  => 'UC_channel_2',
                    'youtube_playlist_id' => null,
                    'youtube_video_id'    => null,
                ),
            );
            public array $videos_table = array(
                array(
                    'id'                  => 10,
                    'youtube_video_id'    => 'vid-1',
                    'youtube_channel_id'  => 'UC_channel_1',
                    'title'               => 'Video from channel 1',
                    'duration_seconds'    => 600,
                    'duration_iso'        => 'PT10M',
                    'thumbnail_default'   => 'd',
                    'thumbnail_medium'    => 'm',
                    'thumbnail_high'      => 'h',
                    'thumbnail_standard'  => 's',
                    'thumbnail_maxres'    => 'x',
                    'content_type'        => 'standard',
                    'live_status'         => 'none',
                    'availability_status' => 'available',
                    'published_at'        => '2026-06-01 00:00:00',
                    'view_count'          => 1234,
                    'actual_start_at'     => null,
                    'actual_end_at'       => null,
                    'scheduled_start_at'  => null,
                ),
                array(
                    'id'                  => 11,
                    'youtube_video_id'    => 'vid-2',
                    'youtube_channel_id'  => 'UC_NO_SOURCE', // no matching source
                    'title'               => 'Orphan video',
                    'duration_seconds'    => 300,
                    'duration_iso'        => 'PT5M',
                    'thumbnail_default'   => 'd',
                    'thumbnail_medium'    => 'm',
                    'thumbnail_high'      => 'h',
                    'thumbnail_standard'  => 's',
                    'thumbnail_maxres'    => 'x',
                    'content_type'        => 'standard',
                    'live_status'         => 'none',
                    'availability_status' => 'available',
                    'published_at'        => '2026-05-15 00:00:00',
                    'view_count'          => 5678,
                    'actual_start_at'     => null,
                    'actual_end_at'       => null,
                    'scheduled_start_at'  => null,
                ),
            );
            public array $channel_titles = array(
                'UC_channel_1' => 'Wander More',
                'UC_channel_2' => 'Coastal Vibes',
            );
            public array $captured_sql = array();

            public function prepare( $sql, ...$args ) {
                // WordPress's $wpdb->prepare() accepts either a variadic list
                // of args or a single array of args. Flatten so the %s / %d
                // substitution below sees them all in order.
                if ( count( $args ) === 1 && is_array( $args[0] ) ) {
                    $args = $args[0];
                }
                $i = 0;
                $sql = preg_replace_callback( '/%[sd]/', static function ( $m ) use ( $args, &$i ) {
                    if ( ! isset( $args[ $i ] ) ) {
                        return $m[0];
                    }
                    $v = $args[ $i++ ];
                    if ( is_int( $v ) || is_float( $v ) ) {
                        return (string) $v;
                    }
                    return "'" . addslashes( (string) $v ) . "'";
                }, $sql );
                $this->captured_sql[] = $sql;
                return $sql;
            }

            public function get_row( $sql = null, $output = null ) {
                $sql = (string) $sql;
                if ( strpos( $sql, $this->prefix . 'vyg_sources' ) !== false ) {
                    // Match by source_uuid in the rendered SQL.
                    foreach ( $this->sources_table as $row ) {
                        if ( strpos( $sql, "'" . $row['source_uuid'] . "'" ) !== false ) {
                            return $row;
                        }
                    }
                    return null;
                }
                return null;
            }

            public function get_results( $sql = null, $output = null ) {
                $sql = (string) $sql;
                if ( strpos( $sql, $this->prefix . 'vyg_videos' ) !== false ) {
                    // Filter videos by youtube_channel_id (channel source).
                    if ( preg_match( "/v\.youtube_channel_id = '([^']+)'/", $sql, $m ) ) {
                        $chan = $m[1];
                        $rows = array_values( array_filter(
                            $this->videos_table,
                            static fn( $r ) => (string) $r['youtube_channel_id'] === $chan
                        ) );
                    } else {
                        $rows = $this->videos_table;
                    }
                    // If the SQL references LEFT JOIN ... vyg_sources, hydrate
                    // the channel_title field from the in-memory map (or '' if
                    // no source row matches).
                    $merging_sources = strpos( $sql, $this->prefix . 'vyg_sources' ) !== false;
                    if ( $merging_sources ) {
                        foreach ( $rows as &$r ) {
                            $chan_id = (string) ( $r['youtube_channel_id'] ?? '' );
                            $r['youtube_channel_title'] = $this->channel_titles[ $chan_id ] ?? '';
                        }
                        unset( $r );
                    }
                    return $rows;
                }
                return array();
            }

            public function get_var( $sql = null ) { return 0; }
            public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4'; }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void
    {
        unset( $GLOBALS['wpdb'] );
        parent::tearDown();
    }

    public function test_videos_for_source_uses_left_join_on_vyg_sources(): void
    {
        $fq = new FeedQuery();
        $fq->videos_for_source( array(
            'source_uuid' => 'src-1',
        ) );
        // The captured SQL must include a LEFT JOIN to the sources table.
        $sql = implode( "\n", $this->wpdb->captured_sql );
        $this->assertStringContainsString( 'LEFT JOIN', $sql );
        $this->assertStringContainsString( "{$this->wpdb->prefix}vyg_sources", $sql );
        // And the column projection must include s.title aliased.
        $this->assertStringContainsString( 's.title', $sql );
    }

    public function test_videos_for_source_includes_channel_title_when_source_matches(): void
    {
        $fq = new FeedQuery();
        $rows = $fq->videos_for_source( array(
            'source_uuid' => 'src-1',
        ) );
        $this->assertNotEmpty( $rows );
        $first = $rows[0];
        $this->assertArrayHasKey( 'youtube_channel_title', $first );
        $this->assertSame( 'Wander More', $first['youtube_channel_title'] );
    }

    public function test_videos_for_source_returns_empty_channel_title_when_no_source_row(): void
    {
        // Set up: a third source whose youtube_channel_id has no matching
        // source.title in the channel_titles map. The orphan video with
        // channel_id=UC_NO_SOURCE is the only one matching. The LEFT JOIN
        // should still return that video, but with an empty channel title.
        $this->wpdb->sources_table[] = array(
            'id'                  => 3,
            'source_uuid'         => 'src-orphan',
            'source_type'         => 'channel',
            'youtube_channel_id'  => 'UC_NO_SOURCE',
            'youtube_playlist_id' => null,
            'youtube_video_id'    => null,
        );
        $fq = new FeedQuery();
        $rows = $fq->videos_for_source( array(
            'source_uuid' => 'src-orphan',
        ) );
        $this->assertNotEmpty( $rows );
        $first = $rows[0];
        $this->assertArrayHasKey( 'youtube_channel_title', $first );
        $this->assertSame( '', $first['youtube_channel_title'] );
    }
}
