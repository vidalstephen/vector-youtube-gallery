<?php
/**
 * Unit tests for LiveClassifier.
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Normalize;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Normalize\LiveClassifier;

/**
 * @covers \VectorYT\Gallery\Normalize\LiveClassifier
 */
final class LiveClassifierTest extends TestCase {

    private LiveClassifier $classifier;

    protected function setUp(): void {
        parent::setUp();
        $this->classifier = new LiveClassifier();
    }

    public function test_classify_active_when_live_broadcast_content_live(): void {
        $resource = $this->sample( 'live', array() );
        $this->assertSame( LiveClassifier::CONTENT_LIVE_ACTIVE, $this->classifier->classify( $resource ) );
    }

    public function test_classify_active_when_started_no_end(): void {
        $resource = $this->sample( 'none', array(
            'actualStartTime' => '2026-06-28T18:00:00Z',
        ) );
        $this->assertSame( LiveClassifier::CONTENT_LIVE_ACTIVE, $this->classifier->classify( $resource ) );
    }

    public function test_classify_upcoming_when_broadcast_content_upcoming(): void {
        $resource = $this->sample( 'upcoming', array() );
        $this->assertSame( LiveClassifier::CONTENT_LIVE_UPCOMING, $this->classifier->classify( $resource ) );
    }

    public function test_classify_upcoming_when_scheduled_not_started(): void {
        $resource = $this->sample( 'none', array(
            'scheduledStartTime' => '2026-06-29T18:00:00Z',
        ) );
        $this->assertSame( LiveClassifier::CONTENT_LIVE_UPCOMING, $this->classifier->classify( $resource ) );
    }

    public function test_classify_replay_when_started_and_ended(): void {
        $resource = $this->sample( 'none', array(
            'actualStartTime' => '2026-06-25T18:00:00Z',
            'actualEndTime'   => '2026-06-25T20:00:00Z',
        ) );
        $this->assertSame( LiveClassifier::CONTENT_LIVE_REPLAY, $this->classifier->classify( $resource ) );
    }

    public function test_classify_standard_no_live_signals(): void {
        $resource = $this->sample( 'none', array() );
        $this->assertSame( LiveClassifier::CONTENT_STANDARD, $this->classifier->classify( $resource ) );
    }

    public function test_active_takes_priority_over_upcoming(): void {
        // Both signals present: actualStart set, broadcastContent=upcoming — active wins
        // because the live stream is technically running.
        $resource = $this->sample( 'upcoming', array(
            'actualStartTime' => '2026-06-28T18:00:00Z',
        ) );
        $this->assertSame( LiveClassifier::CONTENT_LIVE_ACTIVE, $this->classifier->classify( $resource ) );
    }

    public function test_classify_full_returns_status_live(): void {
        $resource = $this->sample( 'live', array() );
        $full = $this->classifier->classify_full( $resource );
        $this->assertSame( LiveClassifier::CONTENT_LIVE_ACTIVE, $full['content_type'] );
        $this->assertSame( LiveClassifier::STATUS_LIVE, $full['live_status'] );
    }

    public function test_classify_full_returns_status_upcoming(): void {
        $resource = $this->sample( 'none', array(
            'scheduledStartTime' => '2026-06-29T18:00:00Z',
        ) );
        $full = $this->classifier->classify_full( $resource );
        $this->assertSame( LiveClassifier::CONTENT_LIVE_UPCOMING, $full['content_type'] );
        $this->assertSame( LiveClassifier::STATUS_UPCOMING, $full['live_status'] );
    }

    public function test_classify_full_returns_status_ended(): void {
        $resource = $this->sample( 'none', array(
            'actualStartTime' => '2026-06-25T18:00:00Z',
            'actualEndTime'   => '2026-06-25T20:00:00Z',
        ) );
        $full = $this->classifier->classify_full( $resource );
        $this->assertSame( LiveClassifier::CONTENT_LIVE_REPLAY, $full['content_type'] );
        $this->assertSame( LiveClassifier::STATUS_ENDED, $full['live_status'] );
    }

    public function test_classify_full_returns_status_none(): void {
        $resource = $this->sample( 'none', array() );
        $full = $this->classifier->classify_full( $resource );
        $this->assertSame( LiveClassifier::CONTENT_STANDARD, $full['content_type'] );
        $this->assertSame( LiveClassifier::STATUS_NONE, $full['live_status'] );
    }

    /**
     * @param array<string,mixed> $live_details
     * @return array<string,mixed>
     */
    private function sample( string $broadcast_content, array $live_details ): array {
        return array(
            'id' => 'fake',
            'snippet' => array(
                'title'                => 'Test',
                'channelId'            => 'UC_fake',
                'publishedAt'          => '2026-01-01T00:00:00Z',
                'liveBroadcastContent' => $broadcast_content,
                'thumbnails'           => array(),
            ),
            'contentDetails' => array( 'duration' => 'PT5M' ),
            'status'         => array( 'embeddable' => true, 'privacyStatus' => 'public' ),
            'liveStreamingDetails' => $live_details,
        );
    }
}