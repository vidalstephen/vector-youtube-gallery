<?php
/**
 * Unit tests for QuotaTracker.
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\YouTube;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Tests\Support\OptionsBag;
use VectorYT\Gallery\YouTube\QuotaTracker;

/**
 * @covers \VectorYT\Gallery\YouTube\QuotaTracker
 */
final class QuotaTrackerTest extends TestCase {

    private QuotaTracker $tracker;

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        OptionsBag::reset();

        Functions\when( 'get_option' )->alias( static fn( string $key, $default = false ) => OptionsBag::get( $key, $default ) );
        Functions\when( 'update_option' )->alias( static fn( string $key, $value, $autoload = null ) => OptionsBag::update( $key, $value, $autoload ) );
        Functions\when( 'delete_option' )->alias( static fn( string $key ) => OptionsBag::delete( $key ) );

        $this->tracker = new QuotaTracker();
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_record_stores_entry(): void {
        $this->tracker->record( 'channels', 200 );
        $entries = $this->tracker->entries();
        $this->assertCount( 1, $entries );
        $this->assertSame( 'channels', $entries[0]['endpoint'] );
        $this->assertSame( 1, $entries[0]['quota_units'] );
        $this->assertSame( 200, $entries[0]['response_code'] );
    }

    public function test_unknown_endpoint_costs_one_unit(): void {
        $this->tracker->record( 'unknownEndpoint' );
        $entries = $this->tracker->entries();
        $this->assertSame( 1, $entries[0]['quota_units'] );
    }

    public function test_search_endpoint_costs_100_units(): void {
        $this->tracker->record( 'search' );
        $entries = $this->tracker->entries();
        $this->assertSame( 100, $entries[0]['quota_units'] );
    }

    public function test_last_24h_sums_recent(): void {
        $this->tracker->record( 'channels' );    // 1 unit
        $this->tracker->record( 'search' );      // 100 units
        $this->tracker->record( 'videos' );      // 1 unit

        $this->assertSame( 102, $this->tracker->last_24h_units() );
        $this->assertSame( 9898, $this->tracker->remaining_estimate() );
    }

    public function test_reset_clears_entries(): void {
        $this->tracker->record( 'channels' );
        $this->assertCount( 1, $this->tracker->entries() );
        $this->tracker->reset();
        $this->assertCount( 0, $this->tracker->entries() );
    }
}