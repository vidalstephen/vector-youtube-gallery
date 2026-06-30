<?php
/**
 * Unit tests for NetworkPolicy.
 *
 * The multisite policy is heavy on `function_exists` / global WP
 * functions. We shim the few we need with Brain\Monkey; everything
 * else falls through to the real (undefined) global.
 *
 * @covers \VectorYT\Gallery\Multisite\NetworkPolicy
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Multisite;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Multisite\NetworkPolicy;
use VectorYT\Gallery\Tests\Support\BrainHelpers;

final class NetworkPolicyTest extends TestCase
{
    /** @var array<int,int> */
    private array $site_ids_under_test = array();

    /** @var array<int,string> */
    private array $site_urls_under_test = array();

    /** @var int */
    private int $current_blog_id = 1;

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        BrainHelpers::stubOptionFunctions();
        BrainHelpers::stubEscapeFunctions();
        $this->site_ids_under_test   = array();
        $this->site_urls_under_test  = array();
        $this->current_blog_id       = 1;

        // Shim is_multisite so it defaults to true for these tests.
        Functions\when( 'is_multisite' )->alias( static fn(): bool => true );
        Functions\when( 'get_current_blog_id' )->alias( function (): int { return $this->current_blog_id; } );
        Functions\when( 'home_url' )->alias( function (): string { return $this->site_urls_under_test[ $this->current_blog_id ] ?? 'http://example.test'; } );
        Functions\when( 'switch_to_blog' )->alias( function ( int $id ): void { $this->current_blog_id = $id; } );
        Functions\when( 'restore_current_blog' )->alias( function (): void { $this->current_blog_id = 1; } );
        Functions\when( 'get_sites' )->alias( function ( array $args = array() ) {
            return $this->site_ids_under_test;
        } );
        Functions\when( 'is_plugin_active_for_network' )->alias( static fn( string $p ): bool => true );
        Functions\when( 'is_plugin_active' )->alias( static fn( string $p ): bool => true );
        Functions\when( 'plugin_basename' )->alias( static fn( string $p ): string => 'vector-youtube-gallery/vector-youtube-gallery.php' );

        // The Plugin::on_activate call inside on_network_activate
        // walks the installer. The installer touches $wpdb and
        // requires the option to exist. We shim the option call and
        // trust the installer to fail fast — but the policy wraps
        // it in try/catch so the failure does not break the test.

        // Shim a minimal $wpdb shim so site_uninstall() can run.
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public string $options = 'wp_options';
            public function prepare( $sql, ...$args ) { return $sql; }
            public function esc_like( $str ) { return addcslashes( (string) $str, '_%\\' ); }
            public function get_results( $sql = null, $output = null ) { return array(); }
            public function get_var( $sql = null ) { return 0; }
            public function query( $sql ) { return 0; }
        };
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_is_multisite_returns_true_when_shimmed(): void {
        $this->assertTrue( NetworkPolicy::is_multisite() );
    }

    public function test_is_multisite_returns_false_when_unavailable(): void {
        // Replace the per-test shim with one that returns false.
        Functions\when( 'is_multisite' )->alias( static fn(): bool => false );
        $this->assertFalse( NetworkPolicy::is_multisite() );
    }

    public function test_is_network_active_returns_true_when_shimmed(): void {
        $this->assertTrue( NetworkPolicy::is_network_active() );
    }

    public function test_is_network_active_returns_false_on_single_site(): void {
        Functions\when( 'is_multisite' )->alias( static fn(): bool => false );
        $this->assertFalse( NetworkPolicy::is_network_active() );
    }

    public function test_on_network_activate_no_op_when_no_sites(): void {
        $this->site_ids_under_test = array();
        // Should not throw, should not call switch_to_blog.
        NetworkPolicy::on_network_activate();
        $this->addToAssertionCount( 1 ); // no throw = pass
    }

    public function test_network_diagnostics_returns_one_row_per_site(): void {
        $this->site_ids_under_test = array( 1, 2, 3 );
        $this->site_urls_under_test = array(
            1 => 'http://one.test',
            2 => 'http://two.test',
            3 => 'http://three.test',
        );
        $rows = NetworkPolicy::network_diagnostics();
        $this->assertCount( 3, $rows );
        $this->assertSame( 1, $rows[0]['site_id'] );
        $this->assertSame( 2, $rows[1]['site_id'] );
        $this->assertSame( 3, $rows[2]['site_id'] );
    }

    public function test_network_diagnostics_handles_single_site(): void {
        Functions\when( 'is_multisite' )->alias( static fn(): bool => false );
        $this->current_blog_id = 1;
        $rows = NetworkPolicy::network_diagnostics();
        $this->assertCount( 1, $rows );
        $this->assertSame( 1, $rows[0]['site_id'] );
    }

    public function test_network_diagnostics_site_uninstall_returns_count(): void {
        // The site_uninstall method drops tables; on a non-multisite
        // install with no tables, it returns 0.
        Functions\when( 'is_multisite' )->alias( static fn(): bool => false );
        $count = NetworkPolicy::site_uninstall();
        $this->assertIsInt( $count );
        $this->assertGreaterThanOrEqual( 0, $count );
    }
}
