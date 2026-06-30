<?php
/**
 * Phase 13.1 — Shortcode + block attribute contract tests.
 *
 * The ShortcodeRegistrar and BlockRegistrar both accept the new density,
 * header, and meta attributes. We verify:
 *  1. block.json declares every new attribute with the correct default.
 *  2. The shortcode accepts every new attribute (the render path itself
 *     is covered by the Renderer/grid integration tests).
 *
 * @covers \VectorYT\Gallery\Render\ShortcodeRegistrar
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../bootstrap.php';

final class ShortcodeNewAttributesTest extends TestCase
{
    public function test_block_json_declares_all_new_grid_attributes(): void
    {
        $path = dirname( __DIR__, 3 ) . '/src/Render/block.json';
        $this->assertFileExists( $path, 'block.json must exist' );
        $json = json_decode( (string) file_get_contents( $path ), true );
        $this->assertIsArray( $json );
        $attrs = $json['attributes'] ?? array();
        $expected = array(
            'layout'                => array( 'type' => 'string', 'default' => 'grid' ),
            'columns'               => array( 'type' => 'number', 'default' => 3 ),
            'per_page'              => array( 'type' => 'number', 'default' => 12 ),
            'density'               => array( 'type' => 'string', 'default' => 'comfortable' ),
            'header_title'          => array( 'type' => 'string', 'default' => '' ),
            'header_subtitle'       => array( 'type' => 'string', 'default' => '' ),
            'header_columns_visible'=> array( 'type' => 'boolean', 'default' => true ),
            'header_cta_label'      => array( 'type' => 'string', 'default' => '' ),
            'header_cta_url'        => array( 'type' => 'string', 'default' => '' ),
            'show_channel_avatar'   => array( 'type' => 'boolean', 'default' => true ),
            'show_channel_name'     => array( 'type' => 'boolean', 'default' => true ),
            'show_subscriber_count' => array( 'type' => 'boolean', 'default' => false ),
            'show_verified_badge'   => array( 'type' => 'boolean', 'default' => false ),
            'show_views_and_time'   => array( 'type' => 'boolean', 'default' => true ),
            'product_cta_visible'   => array( 'type' => 'boolean', 'default' => true ),
            'trust_strip'           => array( 'type' => 'boolean', 'default' => false ),
            'card_radius'           => array( 'type' => 'string', 'default' => '12px' ),
        );
        foreach ( $expected as $name => $shape ) {
            $this->assertArrayHasKey( $name, $attrs, "block.json must declare '{$name}'" );
            $this->assertSame( $shape['type'], $attrs[ $name ]['type'], "{$name} type mismatch" );
            $this->assertSame( $shape['default'], $attrs[ $name ]['default'], "{$name} default mismatch" );
        }
    }

    public function test_shortcode_registrar_source_includes_new_attribute_keys(): void
    {
        // Read the ShortcodeRegistrar source and check the shortcode_atts
        // default map includes every new key. This pins down the contract
        // for any future caller that introspects the defaults.
        $path = dirname( __DIR__, 3 ) . '/src/Render/ShortcodeRegistrar.php';
        $this->assertFileExists( $path );
        $src = (string) file_get_contents( $path );
        $expected_keys = array(
            'density',
            'header_title',
            'header_subtitle',
            'header_columns_visible',
            'header_cta_label',
            'header_cta_url',
            'show_channel_avatar',
            'show_channel_name',
            'show_subscriber_count',
            'show_verified_badge',
            'show_views_and_time',
            'product_cta_visible',
            'trust_strip',
            'card_radius',
        );
        foreach ( $expected_keys as $key ) {
            $this->assertStringContainsString( "'{$key}'", $src, "ShortcodeRegistrar must declare '{$key}' in shortcode_atts" );
        }
    }

    public function test_block_index_js_exposes_new_inspector_panels(): void
    {
        // The editor script declares Inspector panels per section. We check
        // that the new "Header", "Card", and "Footer" PanelBody titles are
        // present (operators can find them in the Gutenberg sidebar).
        $path = dirname( __DIR__, 3 ) . '/src/Render/index.js';
        $this->assertFileExists( $path );
        $src = (string) file_get_contents( $path );
        $this->assertStringContainsString( 'Header', $src );
        $this->assertStringContainsString( 'Card', $src );
        $this->assertStringContainsString( 'Footer', $src );
        // The new attribute keys are wired up as setAttrs() targets.
        foreach ( array( 'header_title', 'density', 'trust_strip', 'card_radius' ) as $key ) {
            $this->assertStringContainsString( $key, $src, "index.js must reference '{$key}'" );
        }
    }
}
