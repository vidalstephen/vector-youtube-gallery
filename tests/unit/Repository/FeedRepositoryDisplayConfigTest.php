<?php
/**
 * Phase 13.1 — FeedRepository allows the new display_config_json keys for the
 * grid redesign and drops any unknown key. Existing keys (columns, per_page,
 * preset, lightbox, load_more, schema_enabled) keep their semantics.
 *
 * @covers \VectorYT\Gallery\Repository\FeedRepository
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Repository;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Tests\Support\BrainHelpers;
use VectorYT\Gallery\Repository\FeedRepository;

require_once __DIR__ . '/../../bootstrap.php';

final class FeedRepositoryDisplayConfigTest extends \PHPUnit\Framework\TestCase
{
    /** @var object */
    private $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        BrainHelpers::stubEscapeFunctions();
        Functions\when( 'wp_generate_uuid4' )->justReturn( 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee' );
        Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );

        $this->wpdb = new class {
            public string $prefix = 'wp_';
            public int $insert_id = 1;
            public function insert( $table, $data, $format = null ) {
                $GLOBALS['__last_insert'] = array( 'table' => $table, 'data' => $data, 'format' => $format );
                $GLOBALS['__last_insert_id'] = ( $GLOBALS['__last_insert_id'] ?? 0 ) + 1;
                $this->insert_id = $GLOBALS['__last_insert_id'];
                return 1;
            }
            public function update( $table, $data, $where, $format = null, $where_format = null ) { return 1; }
            public function delete( $table, $where, $where_format = null ) { return 1; }
            public function get_row( $sql = null, $output = null ) { return null; }
            public function get_results( $sql = null, $output = null ) { return array(); }
            public function get_var( $sql = null ) { return 0; }
            public function prepare( $sql, ...$args ) { return $sql; }
            public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4'; }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
        $GLOBALS['__last_insert'] = array();
        $GLOBALS['__last_insert_id'] = 0;
    }

    protected function tearDown(): void
    {
        unset( $GLOBALS['wpdb'], $GLOBALS['__last_insert'], $GLOBALS['__last_insert_id'] );
        parent::tearDown();
    }

    private function last_insert(): array
    {
        return $GLOBALS['__last_insert'] ?? array();
    }

    public function test_display_config_keeps_new_grid_redesign_keys(): void
    {
        $repo = new FeedRepository();
        $repo->create( array(
            'display_config_json' => array(
                'columns'                   => 3,
                'per_page'                  => 12,
                'density'                   => 'compact',
                'header_title'              => 'My Channel',
                'header_subtitle'           => 'Latest uploads',
                'header_columns_visible'    => true,
                'header_cta_label'          => 'Visit',
                'header_cta_url'            => 'https://example.com',
                'show_channel_avatar'       => false,
                'show_channel_name'         => true,
                'show_subscriber_count'     => false,
                'show_verified_badge'       => false,
                'show_views_and_time'       => true,
                'product_cta_visible'       => true,
                'trust_strip'               => true,
                'card_radius'               => '12px',
            ),
        ) );
        $stored = json_decode( $this->last_insert()['data']['display_config_json'] ?? '', true );
        $this->assertSame( 'compact', $stored['density'] );
        $this->assertSame( 'My Channel', $stored['header_title'] );
        $this->assertSame( 'Latest uploads', $stored['header_subtitle'] );
        $this->assertTrue( $stored['header_columns_visible'] );
        $this->assertSame( 'Visit', $stored['header_cta_label'] );
        $this->assertSame( 'https://example.com', $stored['header_cta_url'] );
        $this->assertFalse( $stored['show_channel_avatar'] );
        $this->assertTrue( $stored['show_channel_name'] );
        $this->assertFalse( $stored['show_subscriber_count'] );
        $this->assertFalse( $stored['show_verified_badge'] );
        $this->assertTrue( $stored['show_views_and_time'] );
        $this->assertTrue( $stored['product_cta_visible'] );
        $this->assertTrue( $stored['trust_strip'] );
        $this->assertSame( '12px', $stored['card_radius'] );
    }

    public function test_display_config_drops_unknown_keys(): void
    {
        $repo = new FeedRepository();
        $repo->create( array(
            'display_config_json' => array(
                'columns'        => 3,
                'density'        => 'comfortable',
                'header_title'   => 'Hi',
                'unknown_key'    => 'should be dropped',
                'injection'      => array( 'evil' => true ),
            ),
        ) );
        $stored = json_decode( $this->last_insert()['data']['display_config_json'] ?? '', true );
        $this->assertArrayHasKey( 'columns', $stored );
        $this->assertArrayHasKey( 'density', $stored );
        $this->assertArrayHasKey( 'header_title', $stored );
        $this->assertArrayNotHasKey( 'unknown_key', $stored );
        $this->assertArrayNotHasKey( 'injection', $stored );
    }

    public function test_display_config_clamps_density_to_known_values(): void
    {
        $repo = new FeedRepository();
        $repo->create( array(
            'display_config_json' => array( 'density' => 'whoknows' ),
        ) );
        $stored = json_decode( $this->last_insert()['data']['display_config_json'] ?? '', true );
        $this->assertSame( 'comfortable', $stored['density'] );
    }

    public function test_display_config_clamps_header_cta_url_to_esc_url_raw(): void
    {
        Functions\when( 'esc_url_raw' )->alias( static function ( string $s ): string {
            // Real esc_url_raw would drop javascript: schemes etc; for the
            // test, we just verify the function is invoked.
            if ( strpos( $s, 'javascript:' ) === 0 ) {
                return '';
            }
            return $s;
        } );
        $repo = new FeedRepository();
        $repo->create( array(
            'display_config_json' => array(
                'header_cta_url' => 'javascript:alert(1)',
            ),
        ) );
        $stored = json_decode( $this->last_insert()['data']['display_config_json'] ?? '', true );
        $this->assertSame( '', $stored['header_cta_url'] );
    }

    public function test_display_config_booleans_are_coerced(): void
    {
        $repo = new FeedRepository();
        $repo->create( array(
            'display_config_json' => array(
                'trust_strip'         => 1,
                'show_channel_name'   => 0,
                'product_cta_visible' => 'yes',
            ),
        ) );
        $stored = json_decode( $this->last_insert()['data']['display_config_json'] ?? '', true );
        $this->assertTrue( $stored['trust_strip'] );
        $this->assertFalse( $stored['show_channel_name'] );
        $this->assertTrue( $stored['product_cta_visible'] );
    }
}
