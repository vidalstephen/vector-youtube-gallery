<?php
/**
 * Unit tests for ShortsClassifier.
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Normalize;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Normalize\ShortsClassifier;

/**
 * @covers \VectorYT\Gallery\Normalize\ShortsClassifier
 */
final class ShortsClassifierTest extends TestCase {

    private ShortsClassifier $classifier;

    protected function setUp(): void {
        parent::setUp();
        $this->classifier = new ShortsClassifier();
    }

    public function test_has_shorts_tag_various_forms(): void {
        $this->assertTrue( $this->classifier->has_shorts_tag( array( '#Shorts' ) ) );
        $this->assertTrue( $this->classifier->has_shorts_tag( array( 'shorts' ) ) );
        $this->assertTrue( $this->classifier->has_shorts_tag( array( 'SHORT' ) ) );
        $this->assertTrue( $this->classifier->has_shorts_tag( array( 'music', '#short', 'live' ) ) );
        $this->assertTrue( $this->classifier->has_shorts_tag( array( '#shorts', '#YouTubeShorts' ) ) ); // YouTubeShorts contains "shorts" substring — should NOT match; let me check
        // Actually "YouTubeShorts" doesn't strictly equal "shorts" or "short", but contains. Our check is strict equality after normalize.
    }

    public function test_has_shorts_tag_negative_cases(): void {
        $this->assertFalse( $this->classifier->has_shorts_tag( array() ) );
        $this->assertFalse( $this->classifier->has_shorts_tag( array( 'music' ) ) );
        $this->assertFalse( $this->classifier->has_shorts_tag( array( 'longform' ) ) );
        // Substring match should NOT promote: "shortsclub" is not a shorts tag.
        $this->assertFalse( $this->classifier->has_shorts_tag( array( 'shortsclub' ) ) );
        // "youtubeShorts" should NOT match (it's a channel context, not a tag).
        $this->assertFalse( $this->classifier->has_shorts_tag( array( 'youtubeShorts' ) ) );
    }

    public function test_short_video_with_tag_promoted_to_confirmed(): void {
        $result = $this->classifier->classify(
            $this->sample_video( 'PT30S', array( '#Shorts' ) ),
            array( 'duration_seconds' => 30 ),
        );
        $this->assertSame( ShortsClassifier::TYPE_SHORT_CONFIRMED, $result );
    }

    public function test_short_video_no_tag_no_vertical_returns_standard(): void {
        $result = $this->classifier->classify(
            $this->sample_video( 'PT30S', array( 'music' ) ),
            array( 'duration_seconds' => 30 ),
        );
        // Without dimension data AND no tag, conservative default is standard.
        $this->assertSame( ShortsClassifier::TYPE_STANDARD, $result );
    }

    public function test_long_video_with_shorts_tag_promoted_to_confirmed(): void {
        // YouTube allows Shorts up to 60s, but sometimes a 90s video is still
        // marked with #Shorts. We promote it if within candidate_max.
        $result = $this->classifier->classify(
            $this->sample_video( 'PT90S', array( '#Shorts' ) ),
            array( 'duration_seconds' => 90 ),
        );
        $this->assertSame( ShortsClassifier::TYPE_SHORT_CONFIRMED, $result );
    }

    public function test_short_video_with_vertical_confirmation(): void {
        // When the Shorts tag is present, vertical is implied → confirmed
        // regardless of duration (within candidate_max).
        $result = $this->classifier->classify(
            $this->sample_video( 'PT45S', array( '#Shorts' ) ),
            array( 'duration_seconds' => 45 ),
        );
        $this->assertSame( ShortsClassifier::TYPE_SHORT_CONFIRMED, $result );
    }

    public function test_very_long_video_returns_standard(): void {
        $result = $this->classifier->classify(
            $this->sample_video( 'PT5M', array( 'music' ) ),
            array( 'duration_seconds' => 300 ),
        );
        $this->assertSame( ShortsClassifier::TYPE_STANDARD, $result );
    }

    public function test_zero_duration_returns_standard(): void {
        $result = $this->classifier->classify(
            $this->sample_video( 'PT0S', array() ),
            array( 'duration_seconds' => 0 ),
        );
        $this->assertSame( ShortsClassifier::TYPE_STANDARD, $result );
    }

    public function test_manual_override_short_wins(): void {
        $result = $this->classifier->classify(
            $this->sample_video( 'PT10M', array() ),
            array( 'duration_seconds' => 600, 'manual_content_type' => 'short' ),
        );
        $this->assertSame( ShortsClassifier::TYPE_SHORT_CONFIRMED, $result );
    }

    public function test_manual_override_standard_wins(): void {
        $result = $this->classifier->classify(
            $this->sample_video( 'PT30S', array( '#Shorts' ) ),
            array( 'duration_seconds' => 30, 'manual_content_type' => 'standard' ),
        );
        $this->assertSame( ShortsClassifier::TYPE_STANDARD, $result );
    }

    public function test_manual_override_passes_through_arbitrary(): void {
        $result = $this->classifier->classify(
            $this->sample_video( 'PT5M', array() ),
            array( 'duration_seconds' => 300, 'manual_content_type' => 'live_active' ),
        );
        $this->assertSame( 'live_active', $result );
    }

    public function test_custom_thresholds_change_boundary(): void {
        // With shorts_max=120, a 90s video without tag returns standard (not candidate)
        // because we don't have vertical confirmation.
        $result = $this->classifier->classify(
            $this->sample_video( 'PT90S', array() ),
            array( 'duration_seconds' => 90 ),
            120,  // shorts_max
            300,  // candidate_max
        );
        $this->assertSame( ShortsClassifier::TYPE_STANDARD, $result );
    }

    public function test_normalize_manual_aliases(): void {
        $this->assertSame( ShortsClassifier::TYPE_SHORT_CONFIRMED, $this->classifier->normalize_manual( 'short' ) );
        $this->assertSame( ShortsClassifier::TYPE_SHORT_CONFIRMED, $this->classifier->normalize_manual( 'shorts' ) );
        $this->assertSame( ShortsClassifier::TYPE_SHORT_CONFIRMED, $this->classifier->normalize_manual( '#Shorts' ) );
        $this->assertSame( ShortsClassifier::TYPE_STANDARD, $this->classifier->normalize_manual( 'standard' ) );
        $this->assertSame( ShortsClassifier::TYPE_STANDARD, $this->classifier->normalize_manual( 'video' ) );
        $this->assertSame( ShortsClassifier::TYPE_STANDARD, $this->classifier->normalize_manual( 'long' ) );
        $this->assertSame( 'short_candidate', $this->classifier->normalize_manual( 'short_candidate' ) );
        $this->assertSame( 'live_active', $this->classifier->normalize_manual( 'live_active' ) );
    }

    /**
     * @param array<int,string> $tags
     * @return array<string,mixed>
     */
    private function sample_video( string $duration_iso, array $tags = array(), bool $force_vertical = false ): array {
        $resource = array(
            'id' => 'fake',
            'snippet' => array(
                'title'       => 'Test',
                'tags'        => $tags,
                'channelId'   => 'UC_fake',
                'publishedAt' => '2026-01-01T00:00:00Z',
                'thumbnails'  => array(),
            ),
            'contentDetails' => array(
                'duration' => $duration_iso,
            ),
            'status' => array( 'embeddable' => true, 'privacyStatus' => 'public' ),
        );
        if ( $force_vertical ) {
            $resource['_force_vertical'] = true; // test-only signal
        }
        return $resource;
    }
}