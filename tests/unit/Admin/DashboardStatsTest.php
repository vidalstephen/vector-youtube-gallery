<?php
/**
 * Tests for DashboardStats (Phase 6).
 *
 * We verify the method exists and returns a structure with the expected
 * keys. Full integration with $wpdb requires the WP test framework, which
 * we don't load here — so we stub $wpdb with a minimal in-memory mock that
 * returns 0/count-like values for every call.
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Admin\DashboardStats;

final class DashboardStatsTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        \VectorYT\Gallery\Tests\Support\BrainHelpers::stubEscapeFunctions();
        if ( ! defined( 'ARRAY_A' ) ) {
            define( 'ARRAY_A', 'ARRAY_A' );
        }
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_collect_returns_expected_top_level_keys(): void {
        // Stub $wpdb.
        global $wpdb;
        $wpdb = new class {
            public string $prefix = 'wp_';
            public function get_var( $sql = null, $x = null ) { return 0; }
            public function get_results( $sql = null, $output = 'OBJECT' ) { return array(); }
            public function prepare( $sql, ...$args ) { return $sql; }
        };

        $stats = ( new DashboardStats() )->collect();
        $this->assertIsArray( $stats );
        $this->assertArrayHasKey( 'sources', $stats );
        $this->assertArrayHasKey( 'videos', $stats );
        $this->assertArrayHasKey( 'quota', $stats );
        $this->assertArrayHasKey( 'recent_jobs', $stats );
        $this->assertSame( 10000, $stats['quota']['limit'] );
    }

    public function test_quota_percent_calculation(): void {
        global $wpdb;
        $wpdb = new class {
            public string $prefix = 'wp_';
            public array $returns = array();
            public function get_var( $sql = null, $x = null ) {
                foreach ( $this->returns as $token => $val ) {
                    if ( str_contains( (string) $sql, $token ) ) {
                        return $val;
                    }
                }
                return 0;
            }
            public function get_results( $sql = null, $output = 'OBJECT' ) { return array(); }
            public function prepare( $sql, ...$args ) { return $sql; }
        };
        // 5000 of 10000 quota used → 50%.
        $wpdb->returns['vyg_api_quota_log'] = 5000;
        $stats = ( new DashboardStats() )->collect();
        $this->assertSame( 50, $stats['quota']['percent'] );
    }
}