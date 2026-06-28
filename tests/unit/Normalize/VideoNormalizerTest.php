<?php
/**
 * Unit tests for VideoNormalizer.
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Normalize;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Normalize\VideoNormalizer;
use VectorYT\Gallery\Tests\Support\BrainHelpers;

/**
 * @covers \VectorYT\Gallery\Normalize\VideoNormalizer
 */
final class VideoNormalizerTest extends TestCase {

    private VideoNormalizer $normalizer;

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        BrainHelpers::stubEscapeFunctions();
        $this->normalizer = VideoNormalizer::with_defaults();
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_parse_iso8601_duration_various_shapes(): void {
        $n = $this->normalizer;
        $this->assertSame( 213, $n->parse_iso8601_duration_to_seconds( 'PT3M33S' ) );  // Rick Astley
        $this->assertSame( 253, $n->parse_iso8601_duration_to_seconds( 'PT4M13S' ) );
        $this->assertSame( 3600, $n->parse_iso8601_duration_to_seconds( 'PT1H' ) );
        $this->assertSame( 3660, $n->parse_iso8601_duration_to_seconds( 'PT1H1M' ) );
        $this->assertSame( 3725, $n->parse_iso8601_duration_to_seconds( 'PT1H2M5S' ) );
        $this->assertSame( 0, $n->parse_iso8601_duration_to_seconds( 'PT0S' ) );
        $this->assertNull( $n->parse_iso8601_duration_to_seconds( null ) );
        $this->assertNull( $n->parse_iso8601_duration_to_seconds( '' ) );
        $this->assertNull( $n->parse_iso8601_duration_to_seconds( 'garbage' ) );
    }

    public function test_normalize_basic_fields(): void {
        $row = $this->normalizer->normalize( $this->sample_standard_video() );

        $this->assertSame( 'dQw4w9WgXcQ', $row['youtube_video_id'] );
        $this->assertSame( 'UC_x5XG1OV2P6uZZ5FSM9Ttw', $row['youtube_channel_id'] );
        $this->assertSame( 213, $row['duration_seconds'] );
        $this->assertSame( 'PT3M33S', $row['duration_iso'] );
        $this->assertSame( 'public', $row['privacy_status'] );
        $this->assertSame( 1, $row['embeddable'] );
        $this->assertSame( 'available', $row['availability_status'] );
        $this->assertSame( 'standard', $row['content_type'] );
        $this->assertSame( 'none', $row['live_status'] );
        $this->assertSame( '1000000', (string) $row['view_count'] );
        $this->assertNotEmpty( $row['raw_api_hash'] );
    }

    public function test_short_video_classified_as_short_candidate(): void {
        $v = $this->sample_standard_video();
        $v['contentDetails']['duration'] = 'PT45S';
        // Without #Shorts tag AND without vertical confirmation (Phase 3.5 will
        // add player-embed dimension parsing), the classifier conservatively
        // returns 'standard' — a 45s video could be a horizontal short clip.
        $row = $this->normalizer->normalize( $v );
        $this->assertSame( 'standard', $row['content_type'] );
        $this->assertSame( 45, $row['duration_seconds'] );
    }

    public function test_short_video_with_shorts_tag_promoted_to_short_confirmed(): void {
        $v = $this->sample_standard_video();
        $v['contentDetails']['duration'] = 'PT45S';
        $v['snippet']['tags'] = array( 'music', '#Shorts' );
        $row = $this->normalizer->normalize( $v );
        $this->assertSame( 'short_confirmed', $row['content_type'] );
    }

    public function test_live_active_classification(): void {
        $v = $this->sample_standard_video();
        $v['snippet']['liveBroadcastContent'] = 'live';
        $v['liveStreamingDetails'] = array(
            'actualStartTime' => '2026-06-28T18:00:00Z',
        );
        $row = $this->normalizer->normalize( $v );
        $this->assertSame( 'live_active', $row['content_type'] );
        $this->assertSame( 'live', $row['live_status'] );
    }

    public function test_live_upcoming_classification(): void {
        $v = $this->sample_standard_video();
        $v['snippet']['liveBroadcastContent'] = 'upcoming';
        $v['liveStreamingDetails'] = array(
            'scheduledStartTime' => '2026-06-29T18:00:00Z',
        );
        $row = $this->normalizer->normalize( $v );
        $this->assertSame( 'live_upcoming', $row['content_type'] );
        $this->assertSame( 'upcoming', $row['live_status'] );
    }

    public function test_live_replay_classification(): void {
        $v = $this->sample_standard_video();
        $v['snippet']['liveBroadcastContent'] = 'none';
        $v['liveStreamingDetails'] = array(
            'actualStartTime' => '2026-06-25T18:00:00Z',
            'actualEndTime'   => '2026-06-25T20:00:00Z',
        );
        $row = $this->normalizer->normalize( $v );
        $this->assertSame( 'live_replay', $row['content_type'] );
        $this->assertSame( 'ended', $row['live_status'] );
    }

    public function test_private_video_marked_unavailable(): void {
        $v = $this->sample_standard_video();
        $v['status']['privacyStatus'] = 'private';
        $row = $this->normalizer->normalize( $v );
        $this->assertSame( 'private', $row['privacy_status'] );
        $this->assertSame( 'private', $row['availability_status'] );
    }

    public function test_deleted_upload_marked_unavailable(): void {
        $v = $this->sample_standard_video();
        $v['status']['uploadStatus'] = 'deleted';
        $row = $this->normalizer->normalize( $v );
        $this->assertSame( 'deleted', $row['availability_status'] );
    }

    public function test_embed_disabled_marked(): void {
        $v = $this->sample_standard_video();
        $v['status']['embeddable'] = false;
        $row = $this->normalizer->normalize( $v );
        $this->assertSame( 0, $row['embeddable'] );
        $this->assertSame( 'embed_disabled', $row['availability_status'] );
    }

    public function test_manual_content_type_override_wins(): void {
        $v = $this->sample_standard_video();
        // 213s wouldn't normally be a Short, but manual override forces it.
        $row = $this->normalizer->normalize( $v, array( 'content_type' => 'short_candidate' ) );
        $this->assertSame( 'short_candidate', $row['content_type'] );
        $this->assertSame( 'short_candidate', $row['manual_content_type'] );
    }

    public function test_tags_persisted_when_present(): void {
        $v = $this->sample_standard_video();
        $v['snippet']['tags'] = array( 'mock', 'test' );
        $row = $this->normalizer->normalize( $v );
        $this->assertNotNull( $row['tags_json'] );
        $decoded = json_decode( $row['tags_json'], true );
        $this->assertSame( array( 'mock', 'test' ), $decoded );
    }

    public function test_thumbnail_variants_extracted(): void {
        $row = $this->normalizer->normalize( $this->sample_standard_video() );
        $this->assertStringContainsString( 'v_default.jpg', $row['thumbnail_default'] );
        $this->assertStringContainsString( 'v_medium.jpg',  $row['thumbnail_medium'] );
        $this->assertStringContainsString( 'v_high.jpg',    $row['thumbnail_high'] );
        $this->assertStringContainsString( 'v_standard.jpg',$row['thumbnail_standard'] );
        $this->assertStringContainsString( 'v_maxres.jpg',  $row['thumbnail_maxres'] );
    }

    public function test_description_excerpt_truncated(): void {
        $v = $this->sample_standard_video();
        $v['snippet']['description'] = str_repeat( 'lorem ipsum dolor sit amet ', 20 );
        $row = $this->normalizer->normalize( $v );
        $this->assertLessThan( strlen( $v['snippet']['description'] ), strlen( $row['description_excerpt'] ) );
    }

    public function test_compliance_window_set_to_30_days(): void {
        $row = $this->normalizer->normalize( $this->sample_standard_video() );
        $expected = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
        $this->assertSame( $expected, $row['api_data_expires_at'] );
    }

    /**
     * @return array<string,mixed>
     */
    private function sample_standard_video(): array {
        return array(
            'id' => 'dQw4w9WgXcQ',
            'snippet' => array(
                'title'        => 'Rick Astley - Never Gonna Give You Up',
                'description'  => 'A long description here for excerpting purposes.',
                'publishedAt'  => '2009-10-25T06:57:33Z',
                'channelId'    => 'UC_x5XG1OV2P6uZZ5FSM9Ttw',
                'channelTitle' => 'Google Developers (mock)',
                'categoryId'   => '10',
                'liveBroadcastContent' => 'none',
                'thumbnails' => array(
                    'default'  => array( 'url' => 'https://example.invalid/v_default.jpg' ),
                    'medium'   => array( 'url' => 'https://example.invalid/v_medium.jpg' ),
                    'high'     => array( 'url' => 'https://example.invalid/v_high.jpg' ),
                    'standard' => array( 'url' => 'https://example.invalid/v_standard.jpg' ),
                    'maxres'   => array( 'url' => 'https://example.invalid/v_maxres.jpg' ),
                ),
            ),
            'contentDetails' => array(
                'duration'   => 'PT3M33S',
                'dimension'  => '2d',
                'definition' => 'hd',
            ),
            'status' => array(
                'uploadStatus'  => 'processed',
                'privacyStatus' => 'public',
                'embeddable'    => true,
                'license'       => 'youtube',
            ),
            'statistics' => array(
                'viewCount'    => '1000000',
                'likeCount'    => '5000',
                'commentCount' => '100',
            ),
            'player' => array(
                'embedHtml' => '<iframe></iframe>',
            ),
        );
    }
}