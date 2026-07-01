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
use VectorYT\Gallery\Render\CardSettings;

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

    // ---------------------------------------------------------------------
    // Phase A4 — nested `card_settings` is sanitized via CardSettings.
    //
    // The new card-customization system stores its settings under a
    // single `card_settings` key whose value is a nested array
    // (['global' => [...], 'layouts' => [...]]). FeedRepository must
    // route that nested shape through CardSettings::sanitize_storage()
    // — never sanitize_text_field() — and keep every existing Phase
    // 13.1 top-level key backward-compatible.
    // ---------------------------------------------------------------------

    public function test_allowed_display_keys_includes_card_settings(): void
    {
        $this->assertContains( 'card_settings', FeedRepository::allowed_display_keys() );
    }

    public function test_card_settings_global_card_preset_survives_sanitization(): void
    {
        $repo = new FeedRepository();
        $repo->create( array(
            'display_config_json' => array(
                'card_settings' => array(
                    'global' => array(
                        'card_preset' => 'minimal',
                    ),
                ),
            ),
        ) );
        $stored = json_decode( $this->last_insert()['data']['display_config_json'] ?? '', true );
        $this->assertArrayHasKey( 'card_settings', $stored );
        $this->assertSame( 'minimal', $stored['card_settings']['global']['card_preset'] );
    }

    public function test_card_settings_global_unknown_key_is_dropped(): void
    {
        $repo = new FeedRepository();
        $repo->create( array(
            'display_config_json' => array(
                'card_settings' => array(
                    'global' => array(
                        'card_preset' => 'minimal',
                        'hack'        => 'should be dropped',
                    ),
                ),
            ),
        ) );
        $stored = json_decode( $this->last_insert()['data']['display_config_json'] ?? '', true );
        $this->assertArrayHasKey( 'card_preset', $stored['card_settings']['global'] );
        $this->assertArrayNotHasKey( 'hack', $stored['card_settings']['global'] );
    }

    public function test_card_settings_layouts_grid_thumbnail_ratio_survives(): void
    {
        $repo = new FeedRepository();
        $repo->create( array(
            'display_config_json' => array(
                'card_settings' => array(
                    'layouts' => array(
                        'grid' => array(
                            'thumbnail_ratio' => '16_9',
                        ),
                    ),
                ),
            ),
        ) );
        $stored = json_decode( $this->last_insert()['data']['display_config_json'] ?? '', true );
        $this->assertArrayHasKey( 'layouts', $stored['card_settings'] );
        $this->assertArrayHasKey( 'grid', $stored['card_settings']['layouts'] );
        $this->assertSame( '16_9', $stored['card_settings']['layouts']['grid']['thumbnail_ratio'] );
    }

    public function test_card_settings_unknown_layout_is_dropped(): void
    {
        $repo = new FeedRepository();
        $repo->create( array(
            'display_config_json' => array(
                'card_settings' => array(
                    'layouts' => array(
                        'grid' => array( 'card_preset' => 'minimal' ),
                        'evil' => array( 'card_preset' => 'minimal' ),
                    ),
                ),
            ),
        ) );
        $stored = json_decode( $this->last_insert()['data']['display_config_json'] ?? '', true );
        $this->assertArrayHasKey( 'grid', $stored['card_settings']['layouts'] );
        $this->assertArrayNotHasKey( 'evil', $stored['card_settings']['layouts'] );
    }

    public function test_card_settings_non_array_value_is_coerced_to_empty(): void
    {
        // Defensive: $raw['card_settings'] might arrive as a string,
        // null, or anything. FeedRepository must not call
        // CardSettings::sanitize_storage() on a non-array (it would
        // type-error). Instead, treat it as empty and skip.
        $repo = new FeedRepository();
        $repo->create( array(
            'display_config_json' => array(
                'card_settings' => 'oops, not an array',
            ),
        ) );
        $stored = json_decode( $this->last_insert()['data']['display_config_json'] ?? '', true );
        // Implementation choice: non-array card_settings is dropped
        // entirely. Document the choice via the assertion.
        $this->assertArrayNotHasKey( 'card_settings', $stored );
    }

    public function test_card_settings_null_value_is_dropped(): void
    {
        $repo = new FeedRepository();
        $repo->create( array(
            'display_config_json' => array(
                'card_settings' => null,
            ),
        ) );
        $stored = json_decode( $this->last_insert()['data']['display_config_json'] ?? '', true );
        $this->assertArrayNotHasKey( 'card_settings', $stored );
    }

    public function test_phase_13_1_legacy_keys_remain_at_top_level(): void
    {
        // Backward-compat: pre-A4 display configs had top-level keys
        // like density, show_channel_name, show_views_and_time,
        // product_cta_visible, card_radius. They must continue to be
        // preserved (NOT dropped, NOT nested under card_settings).
        // The renderer remaps them via
        // CardSettings::legacy_display_to_card_settings() at render
        // time; FeedRepository is the storage layer and must not
        // touch that mapping.
        $repo = new FeedRepository();
        $repo->create( array(
            'display_config_json' => array(
                'density'             => 'compact',
                'show_channel_name'   => true,
                'show_views_and_time' => true,
                'product_cta_visible' => false,
                'card_radius'         => '12px',
            ),
        ) );
        $stored = json_decode( $this->last_insert()['data']['display_config_json'] ?? '', true );
        $this->assertSame( 'compact', $stored['density'] );
        $this->assertTrue( $stored['show_channel_name'] );
        $this->assertTrue( $stored['show_views_and_time'] );
        $this->assertFalse( $stored['product_cta_visible'] );
        $this->assertSame( '12px', $stored['card_radius'] );
        // They stay at the top, not nested under card_settings.
        $this->assertArrayNotHasKey( 'density', $stored['card_settings'] ?? array() );
    }

    public function test_card_settings_booleans_are_coerced_via_card_sanitizer(): void
    {
        // PHP's (bool) 'false' returns true, so we explicitly route
        // nested booleans through CardSanitizer::bool() to handle
        // 'true' / 'false' / '1' / '0' / 'yes' / 'no' / 1 / 0
        // predictably.
        $repo = new FeedRepository();
        $repo->create( array(
            'display_config_json' => array(
                'card_settings' => array(
                    'global' => array(
                        'show_thumbnail' => 'true',
                    ),
                ),
            ),
        ) );
        $stored = json_decode( $this->last_insert()['data']['display_config_json'] ?? '', true );
        $this->assertTrue( $stored['card_settings']['global']['show_thumbnail'] );
    }

    public function test_card_settings_evil_enum_value_is_dropped_or_replaced(): void
    {
        // card_preset is an enum. 'evil' is not a known value, so
        // CardSanitizer::enum() either drops it or replaces it with
        // the enum default. Either is acceptable — the contract is
        // that the stored value is NEVER 'evil'.
        $repo = new FeedRepository();
        $repo->create( array(
            'display_config_json' => array(
                'card_settings' => array(
                    'global' => array(
                        'card_preset' => 'evil',
                    ),
                ),
            ),
        ) );
        $stored = json_decode( $this->last_insert()['data']['display_config_json'] ?? '', true );
        $stored_value = $stored['card_settings']['global']['card_preset'] ?? null;
        $this->assertNotSame( 'evil', $stored_value );
        // The default is one of the known enum values.
        $this->assertContains( $stored_value, array( 'standard', 'minimal', 'editorial' ) );
    }

    public function test_card_settings_show_cta_mapped_only_preserved(): void
    {
        // show_cta is tri-state. 'mapped_only' must survive
        // storage as a literal string, not be coerced to a bool.
        $repo = new FeedRepository();
        $repo->create( array(
            'display_config_json' => array(
                'card_settings' => array(
                    'global' => array(
                        'show_cta' => 'mapped_only',
                    ),
                ),
            ),
        ) );
        $stored = json_decode( $this->last_insert()['data']['display_config_json'] ?? '', true );
        $this->assertSame( 'mapped_only', $stored['card_settings']['global']['show_cta'] );
    }

    public function test_card_settings_combined_with_legacy_keys(): void
    {
        // Real-world payload: a feed row that has both Phase 13.1
        // legacy keys at the top level AND a new `card_settings`
        // nested shape. Both must survive sanitization.
        $repo = new FeedRepository();
        $repo->create( array(
            'display_config_json' => array(
                'density'             => 'comfortable',
                'show_channel_name'   => true,
                'card_radius'         => '8px',
                'card_settings'       => array(
                    'global' => array(
                        'card_preset'    => 'minimal',
                        'show_thumbnail' => true,
                    ),
                    'layouts' => array(
                        'grid' => array(
                            'thumbnail_ratio' => '4_3',
                        ),
                    ),
                ),
                'FooBar' => 'should be dropped',
            ),
        ) );
        $stored = json_decode( $this->last_insert()['data']['display_config_json'] ?? '', true );

        // Phase 13.1 legacy keys still present.
        $this->assertSame( 'comfortable', $stored['density'] );
        $this->assertTrue( $stored['show_channel_name'] );
        $this->assertSame( '8px', $stored['card_radius'] );

        // Nested card_settings survived.
        $this->assertSame( 'minimal', $stored['card_settings']['global']['card_preset'] );
        $this->assertTrue( $stored['card_settings']['global']['show_thumbnail'] );
        $this->assertSame( '4_3', $stored['card_settings']['layouts']['grid']['thumbnail_ratio'] );

        // Unknown top-level key dropped.
        $this->assertArrayNotHasKey( 'FooBar', $stored );
    }

    public function test_sanitize_display_config_pure_helper_routes_card_settings(): void
    {
        // Sanity check on the static helper, in case the integration
        // test above were ever to change: the helper itself, called
        // directly, must also route card_settings through
        // CardSettings::sanitize_storage().
        $out = FeedRepository::sanitize_display_config( array(
            'card_settings' => array(
                'global' => array( 'card_preset' => 'minimal' ),
            ),
            'columns'       => 3,
            'unknown'       => 'dropped',
        ) );
        $this->assertSame( 'minimal', $out['card_settings']['global']['card_preset'] );
        $this->assertSame( 3, $out['columns'] );
        $this->assertArrayNotHasKey( 'unknown', $out );
    }
}
