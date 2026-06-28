<?php
/**
 * Unit tests for QuotaTracker.
 *
 * Phase 2: uses $wpdb directly. We stub it via a tiny in-memory mock.
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\YouTube;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\YouTube\QuotaTracker;

/**
 * @covers \VectorYT\Gallery\YouTube\QuotaTracker
 */
final class QuotaTrackerTest extends TestCase {

    private QuotaTracker $tracker;
    /** @var \wpdb|\stdClass */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();

        // Tiny wpdb stand-in: collects inserts and supports SUM on quota_units.
        $this->wpdb = new class {
            public string $prefix = 'wp_';
            public ?int $insert_id = null;
            /** @var array<int,array<string,mixed>> */
            public array $rows = array();
            public function insert( string $table, array $data, array $formats = null ) {
                $this->rows[] = $data;
                $this->insert_id = count( $this->rows );
                return 1;
            }
            public function prepare( string $sql, ...$args ): string {
                // wpdb->prepare() is no-op for our stub — we read args differently.
                return $sql;
            }
            public function get_var( string $sql ) {
                if ( false !== stripos( $sql, 'SUM(quota_units)' ) ) {
                    $sum = 0;
                    foreach ( $this->rows as $r ) {
                        if ( $this->row_in_window( $r ) ) {
                            $sum += (int) ( $r['quota_units'] ?? 0 );
                        }
                    }
                    return (string) $sum;
                }
                return '0';
            }
            public function get_results( string $sql, string $output = ARRAY_A ) {
                return array_slice( array_reverse( $this->rows ), 0, 20 );
            }
            public function query( string $sql ) {
                if ( false !== stripos( $sql, 'TRUNCATE' ) ) {
                    $this->rows = array();
                }
                return 1;
            }
            private function row_in_window( array $row ): bool {
                $since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
                return ( $row['created_at'] ?? '' ) >= $since;
            }
        };

        // Make the global $wpdb available where the production code expects it.
        $GLOBALS['wpdb'] = $this->wpdb;
        $this->tracker = new QuotaTracker();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wpdb'] );
        parent::tearDown();
    }

    public function test_record_stores_entry(): void {
        $this->tracker->record( 'channels', 200, 1 );
        $this->assertCount( 1, $this->wpdb->rows );
        $this->assertSame( 'channels', $this->wpdb->rows[0]['method'] );
        $this->assertSame( 1, $this->wpdb->rows[0]['quota_units'] );
        $this->assertSame( 200, $this->wpdb->rows[0]['response_code'] );
        $this->assertSame( 1, (int) $this->wpdb->rows[0]['source_id'] );
    }

    public function test_unknown_endpoint_costs_one_unit(): void {
        $this->tracker->record( 'unknownEndpoint', 200, null );
        $this->assertSame( 1, $this->wpdb->rows[0]['quota_units'] );
    }

    public function test_search_endpoint_costs_100_units(): void {
        $this->tracker->record( 'search', 200, null );
        $this->assertSame( 100, $this->wpdb->rows[0]['quota_units'] );
    }

    public function test_last_24h_sums_recent(): void {
        $this->tracker->record( 'channels', 200, 1 );    // 1
        $this->tracker->record( 'search', 200, 1 );      // 100
        $this->tracker->record( 'videos', 200, 1 );      // 1

        $this->assertSame( 102, $this->tracker->last_24h_units() );
        $this->assertSame( 9898, $this->tracker->remaining_estimate() );
    }

    public function test_reset_clears_entries(): void {
        $this->tracker->record( 'channels', 200, 1 );
        $this->assertCount( 1, $this->wpdb->rows );
        $this->tracker->reset();
        $this->assertCount( 0, $this->wpdb->rows );
    }
}