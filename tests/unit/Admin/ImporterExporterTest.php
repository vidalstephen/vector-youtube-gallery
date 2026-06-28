<?php
/**
 * Tests for ImporterExporter (Phase 6).
 *
 * Round-trips a settings array through export → import and confirms
 * that source export produces the documented JSON shape.
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Admin\ImporterExporter;
use VectorYT\Gallery\Settings\SettingsRepository;

final class ImporterExporterTest extends TestCase {

    private ImporterExporter $ie;

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        \VectorYT\Gallery\Tests\Support\BrainHelpers::stubEscapeFunctions();
        \VectorYT\Gallery\Tests\Support\BrainHelpers::stubOptionFunctions();
        $this->ie = new ImporterExporter( new SettingsRepository() );
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_export_settings_has_required_keys(): void {
        $json = $this->ie->export_settings();
        $data = json_decode( $json, true );
        $this->assertIsArray( $data );
        $this->assertSame( 'settings', $data['kind'] );
        $this->assertArrayHasKey( 'version', $data );
        $this->assertArrayHasKey( 'exported_at', $data );
        $this->assertArrayHasKey( 'values', $data );
        $this->assertIsArray( $data['values'] );
        $this->assertArrayHasKey( 'shorts_max_duration_seconds', $data['values'] );
    }

    public function test_import_settings_rejects_invalid_json(): void {
        $result = $this->ie->import_settings( 'not json' );
        $this->assertFalse( $result['ok'] );
        $this->assertSame( 0, $result['imported'] );
        $this->assertNotEmpty( $result['errors'] );
    }

    public function test_import_settings_rejects_wrong_kind(): void {
        $json = wp_json_encode( array( 'kind' => 'something_else', 'values' => array() ) );
        $result = $this->ie->import_settings( $json );
        $this->assertFalse( $result['ok'] );
        $this->assertStringContainsString( 'kind', $result['errors'][0] );
    }

    public function test_round_trip_settings(): void {
        $json = $this->ie->export_settings();
        $result = $this->ie->import_settings( $json );
        $this->assertTrue( $result['ok'] );
        $this->assertGreaterThan( 0, $result['imported'] );
    }

    public function test_export_sources_shape(): void {
        $sources = array(
            array(
                'source_uuid'        => 'uuid-a',
                'source_type'        => 'channel',
                'youtube_channel_id' => 'UCabc',
                'title'              => 'Channel A',
                'thumbnail_url'      => 'https://example.com/a.jpg',
            ),
            array(
                'source_uuid'         => 'uuid-b',
                'source_type'         => 'playlist',
                'youtube_playlist_id' => 'PLxyz',
                'title'               => 'Playlist B',
                'thumbnail_url'       => 'https://example.com/b.jpg',
            ),
        );
        $json = $this->ie->export_sources( $sources );
        $data = json_decode( $json, true );
        $this->assertSame( 'sources', $data['kind'] );
        $this->assertCount( 2, $data['sources'] );
        $this->assertSame( 'uuid-a', $data['sources'][0]['source_uuid'] );
        $this->assertSame( 'UCabc', $data['sources'][0]['youtube_channel_id'] );
        $this->assertSame( 'PLxyz', $data['sources'][1]['youtube_playlist_id'] );
    }

    public function test_export_sources_empty_array(): void {
        $json = $this->ie->export_sources( array() );
        $data = json_decode( $json, true );
        $this->assertSame( array(), $data['sources'] );
    }
}