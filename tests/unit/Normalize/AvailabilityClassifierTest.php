<?php
/**
 * Unit tests for AvailabilityClassifier.
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Normalize;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Normalize\AvailabilityClassifier;

/**
 * @covers \VectorYT\Gallery\Normalize\AvailabilityClassifier
 */
final class AvailabilityClassifierTest extends TestCase {

    private AvailabilityClassifier $classifier;

    protected function setUp(): void {
        parent::setUp();
        $this->classifier = new AvailabilityClassifier();
    }

    public function test_classify_available_for_public_embeddable(): void {
        $resource = $this->sample( array(
            'uploadStatus'   => 'processed',
            'privacyStatus'  => 'public',
            'embeddable'     => true,
        ) );
        $this->assertSame( AvailabilityClassifier::STATE_AVAILABLE, $this->classifier->classify( $resource ) );
    }

    public function test_classify_private(): void {
        $resource = $this->sample( array(
            'privacyStatus' => 'private',
        ) );
        $this->assertSame( AvailabilityClassifier::STATE_PRIVATE, $this->classifier->classify( $resource ) );
    }

    public function test_classify_deleted(): void {
        $resource = $this->sample( array(
            'uploadStatus'  => 'deleted',
            'privacyStatus' => 'public',
            'embeddable'    => true,
        ) );
        $this->assertSame( AvailabilityClassifier::STATE_DELETED, $this->classifier->classify( $resource ) );
    }

    public function test_classify_deleted_takes_priority(): void {
        // Even if it's also private, deleted wins (you can never resurrect it).
        $resource = $this->sample( array(
            'uploadStatus'  => 'deleted',
            'privacyStatus' => 'private',
        ) );
        $this->assertSame( AvailabilityClassifier::STATE_DELETED, $this->classifier->classify( $resource ) );
    }

    public function test_classify_embed_disabled(): void {
        $resource = $this->sample( array(
            'privacyStatus' => 'public',
            'embeddable'    => false,
        ) );
        $this->assertSame( AvailabilityClassifier::STATE_EMBED_DISABLED, $this->classifier->classify( $resource ) );
    }

    public function test_classify_restricted_by_region_block(): void {
        $resource = $this->sample( array(
            'privacyStatus'  => 'public',
            'embeddable'     => true,
        ) );
        $resource['contentDetails']['regionRestriction'] = array(
            'blocked' => array( 'US', 'CA' ),
        );
        $this->assertSame( AvailabilityClassifier::STATE_RESTRICTED, $this->classifier->classify( $resource ) );
    }

    public function test_unlisted_treated_as_available(): void {
        $resource = $this->sample( array(
            'privacyStatus' => 'unlisted',
            'embeddable'    => true,
        ) );
        $this->assertSame( AvailabilityClassifier::STATE_AVAILABLE, $this->classifier->classify( $resource ) );
    }

    public function test_empty_resource_returns_available(): void {
        $resource = $this->sample( array() );
        $this->assertSame( AvailabilityClassifier::STATE_AVAILABLE, $this->classifier->classify( $resource ) );
    }

    /**
     * @param array<string,mixed> $status
     * @return array<string,mixed>
     */
    private function sample( array $status ): array {
        return array(
            'id' => 'fake',
            'snippet' => array(
                'title'      => 'Test',
                'channelId'  => 'UC_fake',
                'thumbnails' => array(),
            ),
            'contentDetails' => array(),
            'status'         => $status,
        );
    }
}