<?php
/**
 * Phase 13.1 — FeedsPage form field tests.
 *
 * The FeedsPage `collect_posted()` method (private) is the seam between the
 * HTML form and the FeedRepository. We verify it picks up every new
 * display_config_json key and applies the same type coercion that the
 * repository sanitizer does — so a form POST produces a display_config_json
 * that round-trips correctly.
 *
 * @covers \VectorYT\Gallery\Admin\FeedsPage
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Admin\FeedsPage;
use VectorYT\Gallery\Tests\Support\BrainHelpers;
use VectorYT\Gallery\Repository\FeedRepository;

require_once __DIR__ . '/../../bootstrap.php';

final class FeedsPageDisplayConfigTest extends TestCase
{
    /** @var object */
    private $wpdb;

    /** @var FeedRepository */
    private $feeds_repo;

    protected function setUp(): void
    {
        parent::setUp();
        BrainHelpers::stubEscapeFunctions();

        Functions\when( 'wp_generate_uuid4' )->justReturn( 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee' );
        Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
        Functions\when( 'absint' )->alias( static fn( $v ) => (int) $v );
        Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
        Functions\when( 'sanitize_key' )->alias( static fn( $v ) => strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ) ) );
        Functions\when( 'wp_unslash' )->alias( static fn( $v ) => is_string( $v ) ? stripslashes( $v ) : $v );
        Functions\when( 'esc_url_raw' )->alias( static fn( $v ) => (string) $v );

        $this->wpdb = new class {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
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
        unset( $GLOBALS['wpdb'], $GLOBALS['__last_insert'], $GLOBALS['__last_insert_id'], $GLOBALS['_POST'] );
        parent::tearDown();
    }

    /**
     * Drive FeedsPage::collect_posted() via reflection with a simulated POST
     * payload, then round-trip through FeedRepository to verify the
     * display_config_json shape is correct.
     *
     * @param array<string,mixed> $post
     * @return array<string,mixed> The sanitized display_config_json.
     */
    private function post_display_config( array $post ): array {
        // Build a FeedsPage. We pass real repositories so the data flow is
        // observable, but no actual DB writes happen because $post is empty
        // for everything we don't care about.
        $source_repo = $this->createMock( \VectorYT\Gallery\Repository\SourceRepository::class );
        $logger      = new \VectorYT\Gallery\Logging\Logger();
        $feeds_repo  = new FeedRepository();
        $page        = new FeedsPage( $feeds_repo, $source_repo, $logger );

        // Reset the captured insert, then POST.
        $GLOBALS['__last_insert'] = array();
        $GLOBALS['_POST']         = $post;

        $ref = new \ReflectionClass( $page );
        $m   = $ref->getMethod( 'collect_posted' );
        $m->setAccessible( true );
        $data = $m->invoke( $page );

        // Now create the feed in the stub wpdb to drive the sanitizer.
        $feeds_repo->create( $data );

        $inserted = $GLOBALS['__last_insert']['data'] ?? array();
        $this->assertArrayHasKey( 'display_config_json', $inserted );
        return json_decode( $inserted['display_config_json'], true );
    }

    public function test_form_post_with_all_new_keys_round_trips(): void
    {
        $stored = $this->post_display_config( array(
            'feed_name'                => 'Test Feed',
            'feed_type'                => 'source',
            'status'                   => 'draft',
            'layout'                   => 'grid',
            'columns'                  => 3,
            'per_page'                 => 12,
            'preset'                   => 'default',
            'density'                  => 'compact',
            'header_title'             => 'My Channel',
            'header_subtitle'          => 'Latest uploads',
            'header_columns_visible'   => '1',
            'header_cta_label'         => 'Visit',
            'header_cta_url'           => 'https://example.com',
            'show_channel_avatar'      => '0',
            'show_channel_name'        => '1',
            'show_subscriber_count'    => '',
            'show_verified_badge'      => '',
            'show_views_and_time'      => '1',
            'product_cta_visible'      => '1',
            'trust_strip'              => '1',
            'card_radius'              => '14px',
        ) );
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
        $this->assertSame( '14px', $stored['card_radius'] );
    }

    public function test_form_post_with_missing_keys_uses_sane_defaults(): void
    {
        // The form should not blow up if a future operator removes a field
        // (or if a feed was created before the redesign). Unchecked boxes
        // mean the field is missing from POST; booleans default to false.
        $stored = $this->post_display_config( array(
            'feed_name' => 'Bare Feed',
            'columns'   => 3,
            'per_page'  => 12,
        ) );
        $this->assertSame( 'comfortable', $stored['density'] );
        $this->assertSame( '', $stored['header_title'] );
        $this->assertFalse( $stored['show_views_and_time'] );
        $this->assertFalse( $stored['product_cta_visible'] );
        $this->assertFalse( $stored['trust_strip'] );
    }
}
