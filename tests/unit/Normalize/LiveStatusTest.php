<?php
/**
 * Unit tests for LiveStatus (Phase 5).
 *
 * The LiveStatus helper is the *runtime* live_status field, distinct from
 * LiveClassifier::classify() which produces content_type values.
 *
 * @covers \VectorYT\Gallery\Normalize\LiveStatus
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Normalize;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Normalize\LiveStatus;

final class LiveStatusTest extends TestCase {

    private LiveStatus $helper;

    protected function setUp(): void {
        parent::setUp();
        $this->helper = new LiveStatus();
    }

    public function test_live_when_actual_start_no_end(): void {
        $status = $this->helper->classify_live_status(
            array( 'actualStartTime' => '2024-01-01T12:00:00Z' )
        );
        $this->assertSame( 'live', $status );
    }

    public function test_ended_when_actual_start_and_end_present(): void {
        $status = $this->helper->classify_live_status(
            array(
                'actualStartTime' => '2024-01-01T12:00:00Z',
                'actualEndTime'   => '2024-01-01T13:30:00Z',
            )
        );
        $this->assertSame( 'ended', $status );
    }

    public function test_upcoming_when_only_scheduled_present(): void {
        $status = $this->helper->classify_live_status(
            array( 'scheduledStartTime' => '2024-12-01T15:00:00Z' )
        );
        $this->assertSame( 'upcoming', $status );
    }

    public function test_none_when_no_live_broadcast_details(): void {
        $this->assertSame( 'none', $this->helper->classify_live_status( array() ) );
    }

    public function test_none_when_empty_strings(): void {
        $status = $this->helper->classify_live_status(
            array( 'actualStartTime' => '', 'actualEndTime' => '', 'scheduledStartTime' => '' )
        );
        $this->assertSame( 'none', $status );
    }

    public function test_content_type_live_active(): void {
        $this->assertSame(
            'live_active',
            $this->helper->classify_content_type( array( 'actualStartTime' => '2024-01-01T12:00:00Z' ) )
        );
    }

    public function test_content_type_live_upcoming(): void {
        $this->assertSame(
            'live_upcoming',
            $this->helper->classify_content_type( array( 'scheduledStartTime' => '2024-12-01T15:00:00Z' ) )
        );
    }

    public function test_content_type_live_replay(): void {
        $this->assertSame(
            'live_replay',
            $this->helper->classify_content_type( array(
                'actualStartTime' => '2024-01-01T12:00:00Z',
                'actualEndTime'   => '2024-01-01T13:00:00Z',
            ) )
        );
    }

    public function test_content_type_standard_when_none(): void {
        $this->assertSame( 'standard', $this->helper->classify_content_type( array() ) );
    }
}