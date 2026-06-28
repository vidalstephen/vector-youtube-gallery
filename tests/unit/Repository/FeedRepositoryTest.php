<?php
/**
 * Tests for FeedRepository CRUD + sanitization.
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Repository;

use Brain\Monkey;
use Brain\Monkey\Functions;
use VectorYT\Gallery\Tests\Support\BrainHelpers;
use VectorYT\Gallery\Repository\FeedRepository;

require_once __DIR__ . '/../../bootstrap.php';

final class FeedRepositoryTest extends \PHPUnit\Framework\TestCase {

    /** @var object Anonymous wpdb stub. */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        BrainHelpers::stubEscapeFunctions();
        BrainHelpers::stubOptionFunctions();
        Functions\when( 'wp_generate_uuid4' )->justReturn( 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee' );
        Functions\when( 'sanitize_text_field' )->alias( static fn( $s ) => trim( (string) $s ) );
        Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
        Functions\when( 'esc_sql' )->alias( static fn( $s ) => addslashes( (string) $s ) );

        // Anonymous wpdb stub — captures insert/update calls, returns 1.
        $this->wpdb = new class {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            public function insert( $table, $data, $format = null ) {
                $GLOBALS['__last_insert'] = array( 'table' => $table, 'data' => $data, 'format' => $format );
                $GLOBALS['__last_insert_id'] = ( $GLOBALS['__last_insert_id'] ?? 0 ) + 1;
                $this->insert_id = $GLOBALS['__last_insert_id'];
                return 1;
            }
            public function update( $table, $data, $where, $format = null, $where_format = null ) {
                $GLOBALS['__last_update'] = array( 'table' => $table, 'data' => $data, 'where' => $where );
                $val = $GLOBALS['__update_returns'] ?? 1;
                return $val;
            }
            public function delete( $table, $where, $where_format = null ) { return 1; }
            public function get_row( $sql = null, $output = null ) { return null; }
            public function get_results( $sql = null, $output = null ) { return array(); }
            public function get_var( $sql = null ) { return 0; }
            public function prepare( $sql, ...$args ) { return $sql; }
            public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4'; }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
        $GLOBALS['__last_insert_id'] = 0;
    }

    protected function tearDown(): void {
        unset( $GLOBALS['__last_insert'], $GLOBALS['__last_update'], $GLOBALS['__last_insert_id'], $GLOBALS['__update_returns'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    private function last_insert_data(): ?array {
        return $GLOBALS['__last_insert']['data'] ?? null;
    }

    public function test_create_inserts_row_with_defaults(): void {
        $repo = new FeedRepository();
        $id   = $repo->create( array( 'name' => 'My Feed' ) );
        $this->assertGreaterThan( 0, $id );

        $data = $this->last_insert_data();
        $this->assertNotNull( $data );
        $this->assertSame( 'My Feed', $data['name'] );
        $this->assertSame( 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $data['feed_uuid'] );
        $this->assertSame( 'source', $data['feed_type'] );
        $this->assertSame( 'grid', $data['layout'] );
        $this->assertSame( 'draft', $data['status'] );
    }

    public function test_create_validates_feed_type(): void {
        $repo = new FeedRepository();
        $repo->create( array( 'feed_type' => 'evil' ) );
        $this->assertSame( 'source', $this->last_insert_data()['feed_type'] );
    }

    public function test_create_validates_layout(): void {
        $repo = new FeedRepository();
        $repo->create( array( 'layout' => 'magic' ) );
        $this->assertSame( 'grid', $this->last_insert_data()['layout'] );
    }

    public function test_create_strips_html_from_custom_css(): void {
        $repo = new FeedRepository();
        $repo->create( array(
            'custom_css' => '.foo { color: red; } <script>alert(1)</script>',
        ) );
        $css = $this->last_insert_data()['custom_css'];
        $this->assertStringNotContainsString( '<script>', $css );
        $this->assertStringContainsString( '.foo', $css );
    }

    public function test_create_encodes_json_columns(): void {
        $repo = new FeedRepository();
        $repo->create( array(
            'display_config_json' => array( 'columns' => 3, 'per_page' => 12 ),
        ) );
        $decoded = json_decode( $this->last_insert_data()['display_config_json'], true );
        $this->assertSame( array( 'columns' => 3, 'per_page' => 12 ), $decoded );
    }

    public function test_create_status_validation(): void {
        $repo = new FeedRepository();
        $repo->create( array( 'status' => 'bogus' ) );
        $this->assertSame( 'draft', $this->last_insert_data()['status'] );
    }

    public function test_update_returns_true_on_success(): void {
        $GLOBALS['__update_returns'] = 1;
        $repo = new FeedRepository();
        $this->assertTrue( $repo->update( 5, array( 'name' => 'Renamed' ) ) );
    }

    public function test_update_returns_false_on_db_failure(): void {
        $GLOBALS['__update_returns'] = false;
        $repo = new FeedRepository();
        $this->assertFalse( $repo->update( 5, array( 'name' => 'X' ) ) );
    }

    public function test_decode_config_returns_empty_arrays_for_null_json(): void {
        $repo = new FeedRepository();
        $cfg = $repo::decode_config( array(
            'source_config_json' => null,
            'display_config_json' => '',
        ) );
        $this->assertSame( array(), $cfg['source'] );
        $this->assertSame( array(), $cfg['display'] );
    }

    public function test_decode_config_parses_valid_json(): void {
        $repo = new FeedRepository();
        $cfg = $repo::decode_config( array(
            'source_config_json' => '{"source_uuid":"abc"}',
            'display_config_json' => '{"columns":4}',
        ) );
        $this->assertSame( 'abc', $cfg['source']['source_uuid'] );
        $this->assertSame( 4, $cfg['display']['columns'] );
    }

    public function test_allowed_feed_types_and_layouts(): void {
        $this->assertContains( 'source', FeedRepository::allowed_feed_types() );
        $this->assertContains( 'grid', FeedRepository::allowed_layouts() );
        $this->assertContains( 'live', FeedRepository::allowed_layouts() );
        $this->assertContains( 'shorts', FeedRepository::allowed_layouts() );
    }
}