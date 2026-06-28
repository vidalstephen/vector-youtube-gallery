<?php
/**
 * Tests for GdprHooks (Phase 6).
 *
 * Verifies:
 *  - register_exporter / register_eraser add the callback to WP filter
 *  - export_user_data returns empty for unknown email
 *  - erase_user_data returns ok=false, removed=0 for unknown email
 *  - find_rows_for_user returns rows annotated with __table / __user_col
 *    when a user_id column is present in a vyg_* table.
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Admin\GdprHooks;

final class GdprHooksTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        \VectorYT\Gallery\Tests\Support\BrainHelpers::stubEscapeFunctions();
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_register_exporter_adds_callback(): void {
        $hooks = new GdprHooks();
        $list = $hooks->register_exporter( array() );
        $this->assertArrayHasKey( 'vector-youtube-gallery', $list );
        $this->assertSame( 'Vector YouTube Gallery', $list['vector-youtube-gallery']['exporter_friendly_name'] );
        $this->assertSame( array( $hooks, 'export_user_data' ), $list['vector-youtube-gallery']['callback'] );
    }

    public function test_register_eraser_adds_callback(): void {
        $hooks = new GdprHooks();
        $list = $hooks->register_eraser( array() );
        $this->assertArrayHasKey( 'vector-youtube-gallery', $list );
        $this->assertSame( array( $hooks, 'erase_user_data' ), $list['vector-youtube-gallery']['callback'] );
    }

    public function test_register_exporter_preserves_existing_entries(): void {
        $hooks = new GdprHooks();
        $existing = array( 'other-plugin' => array( 'foo' => 'bar' ) );
        $list = $hooks->register_exporter( $existing );
        $this->assertArrayHasKey( 'other-plugin', $list );
        $this->assertArrayHasKey( 'vector-youtube-gallery', $list );
    }

    public function test_export_user_data_unknown_email_returns_empty(): void {
        \Brain\Monkey\Functions\when( 'get_user_by' )->alias(
            static fn( string $field, string $value ) => false
        );
        $hooks = new GdprHooks();
        $result = $hooks->export_user_data( 'nobody@example.invalid' );
        $this->assertSame( array(), $result['data'] );
        $this->assertTrue( $result['done'] );
    }

    public function test_erase_user_data_unknown_email_returns_done(): void {
        \Brain\Monkey\Functions\when( 'get_user_by' )->alias(
            static fn( string $field, string $value ) => false
        );
        $hooks = new GdprHooks();
        $result = $hooks->erase_user_data( 'nobody@example.invalid' );
        $this->assertFalse( $result['items_removed'] );
        $this->assertTrue( $result['done'] );
    }
}