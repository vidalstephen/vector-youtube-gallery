<?php
/**
 * Tests for ImporterExporter (Phase 6 + Phase 8.5).
 *
 * Round-trips a settings array through export → import and confirms
 * the keys survive. Adds Phase 8.5 feed export/import tests using
 * stub FeedRepository + SourceRepository subclasses.
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Admin\ImporterExporter;
use VectorYT\Gallery\Repository\FeedRepository;
use VectorYT\Gallery\Repository\ImportLogRepository;
use VectorYT\Gallery\Repository\SourceRepository;
use VectorYT\Gallery\Settings\SettingsRepository;

final class ImporterExporterTest extends TestCase {

    private ImporterExporter $ie;

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        \VectorYT\Gallery\Tests\Support\BrainHelpers::stubEscapeFunctions();
        \VectorYT\Gallery\Tests\Support\BrainHelpers::stubOptionFunctions();
        $this->ie = new ImporterExporter(
            new SettingsRepository(),
            new FakeFeedRepository(),
            new FakeSourceRepository()
        );
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

    // ----- Phase 8.5: feed export/import -----

    public function test_export_feeds_shape(): void {
        $feeds = array(
            array(
                'id'                   => 1,
                'feed_uuid'            => 'feed-a',
                'name'                 => 'Feed A',
                'feed_type'            => 'source',
                'layout'               => 'grid',
                'status'               => 'published',
                'source_config_json'   => wp_json_encode( array(
                    'sources'          => array(
                        array( 'source_uuid' => 'src-x', 'weight' => 1.0, 'pinned' => false, 'label' => '' ),
                    ),
                    'manual_video_ids' => array(),
                    'exclude_video_ids'=> array(),
                    'include_query'    => 'any',
                ) ),
                'display_config_json'  => '{}',
                'filter_config_json'   => '{}',
                'sort_config_json'     => '{}',
                'custom_css'           => '',
                'created_at'           => '2026-01-01 00:00:00',
                'updated_at'           => '2026-01-02 00:00:00',
            ),
        );
        $json = $this->ie->export_feeds( $feeds );
        $data = json_decode( $json, true );
        $this->assertSame( 'feeds', $data['kind'] );
        $this->assertCount( 1, $data['feeds'] );
        $this->assertSame( 'feed-a', $data['feeds'][0]['feed_uuid'] );
        $this->assertSame( 'Feed A', $data['feeds'][0]['name'] );
        $this->assertSame( 'grid', $data['feeds'][0]['layout'] );
        $this->assertArrayHasKey( 'source_refs', $data );
    }

    public function test_import_feeds_rejects_invalid_json(): void {
        $result = $this->ie->import_feeds( 'not json' );
        $this->assertFalse( $result['ok'] );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'JSON', $result['errors'][0] );
    }

    public function test_import_feeds_rejects_wrong_kind(): void {
        $json = wp_json_encode( array( 'kind' => 'sources', 'feeds' => array() ) );
        $result = $this->ie->import_feeds( $json );
        $this->assertFalse( $result['ok'] );
        $this->assertStringContainsString( 'kind', $result['errors'][0] );
    }

    public function test_import_feeds_rejects_newer_version_unless_forced(): void {
        $json = wp_json_encode( array(
            'version' => '99.0.0',
            'kind'    => 'feeds',
            'feeds'   => array(),
        ) );
        $result = $this->ie->import_feeds( $json );
        $this->assertFalse( $result['ok'] );
        $this->assertStringContainsString( 'version', $result['errors'][0] );

        $forced = $this->ie->import_feeds( $json, array( 'force' => true ) );
        $this->assertTrue( $forced['ok'] );
    }

    public function test_import_feeds_rejects_invalid_conflict_mode(): void {
        $json = wp_json_encode( array( 'kind' => 'feeds', 'feeds' => array() ) );
        $result = $this->ie->import_feeds( $json, array( 'conflict' => 'explode' ) );
        $this->assertFalse( $result['ok'] );
        $this->assertStringContainsString( 'conflict', $result['errors'][0] );
    }

    public function test_import_feeds_creates_new_rows(): void {
        $json = wp_json_encode( array(
            'version' => '0.2.0',
            'kind'    => 'feeds',
            'feeds'   => array(
                array(
                    'feed_uuid'           => 'new-feed-uuid-1',
                    'name'                => 'New Feed',
                    'feed_type'           => 'source',
                    'layout'              => 'grid',
                    'status'              => 'published',
                    'source_config_json'  => array(
                        'sources'           => array(),
                        'manual_video_ids'  => array( 'abcDEF12345' ),
                        'exclude_video_ids' => array(),
                        'include_query'     => 'any',
                    ),
                    'display_config_json' => array( 'per_page' => 8 ),
                    'filter_config_json'  => array(),
                    'sort_config_json'    => array(),
                    'custom_css'          => '',
                ),
            ),
        ) );
        $result = $this->ie->import_feeds( $json );
        $this->assertTrue( $result['ok'] );
        $this->assertSame( 1, $result['imported'] );
        $this->assertSame( 0, $result['replaced'] );
        $this->assertSame( 0, $result['duplicated'] );
        $this->assertSame( 0, $result['skipped'] );
    }

    public function test_import_feeds_skip_mode_preserves_existing(): void {
        $existing_uuid = 'existing-feed-uuid';

        $json = wp_json_encode( array(
            'version' => '0.2.0',
            'kind'    => 'feeds',
            'feeds'   => array(
                array(
                    'feed_uuid'           => $existing_uuid,
                    'name'                => 'Existing Feed (changed in export)',
                    'layout'              => 'list',
                    'status'              => 'archived',
                    'source_config_json'  => array(),
                    'display_config_json' => array(),
                    'filter_config_json'  => array(),
                    'sort_config_json'    => array(),
                    'custom_css'          => '',
                ),
            ),
        ) );

        // First import: creates the row.
        $first  = $this->ie->import_feeds( $json );
        $this->assertSame( 1, $first['imported'] );
        // Second import with default 'skip' conflict: leaves the row alone.
        $second = $this->ie->import_feeds( $json );
        $this->assertSame( 0, $second['imported'] );
        $this->assertSame( 1, $second['skipped'] );
        $this->assertTrue( $second['ok'] ); // skipped is also 'ok'.
    }

    public function test_import_feeds_replace_mode_overwrites(): void {
        $uuid = 'replaceable-feed-uuid';

        $first_json = wp_json_encode( array(
            'version' => '0.2.0',
            'kind'    => 'feeds',
            'feeds'   => array(
                array(
                    'feed_uuid'           => $uuid,
                    'name'                => 'Original Name',
                    'layout'              => 'grid',
                    'status'              => 'draft',
                    'source_config_json'  => array(),
                    'display_config_json' => array(),
                    'filter_config_json'  => array(),
                    'sort_config_json'    => array(),
                    'custom_css'          => '',
                ),
            ),
        ) );
        $second_json = wp_json_encode( array(
            'version' => '0.2.0',
            'kind'    => 'feeds',
            'feeds'   => array(
                array(
                    'feed_uuid'           => $uuid,
                    'name'                => 'Replaced Name',
                    'layout'              => 'list',
                    'status'              => 'published',
                    'source_config_json'  => array(),
                    'display_config_json' => array(),
                    'filter_config_json'  => array(),
                    'sort_config_json'    => array(),
                    'custom_css'          => '',
                ),
            ),
        ) );

        $this->ie->import_feeds( $first_json );
        $result = $this->ie->import_feeds( $second_json, array( 'conflict' => 'replace' ) );
        $this->assertSame( 1, $result['replaced'] );
        $this->assertSame( 1, $result['imported'] );
    }

    public function test_import_feeds_duplicate_mode_creates_copy(): void {
        $uuid = 'duplicate-me-uuid';

        $json = wp_json_encode( array(
            'version' => '0.2.0',
            'kind'    => 'feeds',
            'feeds'   => array(
                array(
                    'feed_uuid'           => $uuid,
                    'name'                => 'Original Name',
                    'layout'              => 'grid',
                    'status'              => 'published',
                    'source_config_json'  => array(),
                    'display_config_json' => array(),
                    'filter_config_json'  => array(),
                    'sort_config_json'    => array(),
                    'custom_css'          => '',
                ),
            ),
        ) );

        $this->ie->import_feeds( $json );
        $result = $this->ie->import_feeds( $json, array( 'conflict' => 'duplicate' ) );
        $this->assertSame( 1, $result['duplicated'] );
        $this->assertSame( 1, $result['imported'] );
    }

    public function test_import_feeds_remaps_source_uuids_by_youtube_id(): void {
        // First import creates a feed with a source_uuid that does not exist
        // locally. The remapper should drop the missing source, log a warning,
        // and still create the feed.
        $json = wp_json_encode( array(
            'version'      => '0.2.0',
            'kind'         => 'feeds',
            'source_refs'  => array(
                'orphaned-uuid' => array(
                    'youtube_channel_id'  => 'UCmystery',
                    'youtube_playlist_id' => '',
                    'youtube_video_id'    => '',
                ),
            ),
            'feeds'        => array(
                array(
                    'feed_uuid'           => 'feed-with-orphan-source',
                    'name'                => 'Has Orphan Source',
                    'layout'              => 'grid',
                    'status'              => 'published',
                    'source_config_json'  => array(
                        'sources' => array(
                            array( 'source_uuid' => 'orphaned-uuid', 'weight' => 1.0, 'pinned' => false, 'label' => '' ),
                        ),
                        'manual_video_ids'  => array(),
                        'exclude_video_ids' => array(),
                        'include_query'     => 'any',
                    ),
                    'display_config_json' => array(),
                    'filter_config_json'  => array(),
                    'sort_config_json'    => array(),
                    'custom_css'          => '',
                ),
            ),
        ) );

        $result = $this->ie->import_feeds( $json );
        $this->assertTrue( $result['ok'] );
        $this->assertSame( 1, $result['imported'] );
        $this->assertNotEmpty( $result['warnings'] );
        $this->assertStringContainsString( 'orphaned-uuid', $result['warnings'][0] );
    }

    // ----- Phase 8.6: large-payload + audit + malformed-JSON hardening -----

    public function test_import_feeds_rejects_oversized_payload(): void {
        $big = str_repeat( 'x', ImporterExporter::DEFAULT_IMPORT_SIZE_CAP_BYTES + 1 );
        $result = $this->ie->import_feeds( $big );
        $this->assertFalse( $result['ok'] );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'too large', $result['errors'][0] );
    }

    public function test_import_feeds_rejects_empty_payload(): void {
        $result = $this->ie->import_feeds( '' );
        $this->assertFalse( $result['ok'] );
        $this->assertStringContainsString( 'Empty', $result['errors'][0] );
    }

    public function test_import_feeds_reports_specific_json_error(): void {
        // Truncated JSON: closing brace missing.
        $result = $this->ie->import_feeds( '{"kind":"feeds","version":"0.2.0","feeds":[' );
        $this->assertFalse( $result['ok'] );
        $this->assertStringContainsString( 'Invalid JSON', $result['errors'][0] );
        // Should not just be a generic 'Invalid JSON.' — should mention the specific error.
        $this->assertStringNotContainsString( 'Invalid JSON.', $result['errors'][0] );
    }

    public function test_import_feeds_rejects_top_level_non_object(): void {
        // JSON string at top level (not an object/array).
        $result = $this->ie->import_feeds( '"hello"' );
        $this->assertFalse( $result['ok'] );
        $this->assertStringContainsString( 'not an object', $result['errors'][0] );
    }

    public function test_audit_emits_row_on_success(): void {
        $log    = new FakeImportLogRepository();
        $ie     = new ImporterExporter(
            new SettingsRepository(),
            new FakeFeedRepository(),
            new FakeSourceRepository(),
            $log
        );
        $json = wp_json_encode( array(
            'version' => '0.2.0',
            'kind'    => 'feeds',
            'feeds'   => array(
                array(
                    'feed_uuid'           => 'audited-feed-uuid',
                    'name'                => 'Audited Feed',
                    'layout'              => 'grid',
                    'status'              => 'published',
                    'source_config_json'  => array(),
                    'display_config_json' => array(),
                    'filter_config_json'  => array(),
                    'sort_config_json'    => array(),
                    'custom_css'          => '',
                ),
            ),
        ) );
        $result = $ie->import_feeds( $json, array( 'conflict' => 'skip' ) );
        $this->assertTrue( $result['ok'] );
        $this->assertCount( 1, $log->rows );
        $row = $log->rows[0];
        $this->assertSame( 'import', $row['op'] );
        $this->assertSame( 'feeds', $row['kind'] );
        $this->assertSame( 'skip', $row['conflict_mode'] );
        $this->assertSame( 1, $row['imported_count'] );
        $this->assertNotEmpty( $row['payload_hash'] );
        $this->assertSame( strlen( $json ), $row['payload_bytes'] );
        $this->assertGreaterThanOrEqual( 0, $row['duration_ms'] );
    }

    public function test_audit_emits_row_on_error(): void {
        $log = new FakeImportLogRepository();
        $ie  = new ImporterExporter(
            new SettingsRepository(),
            new FakeFeedRepository(),
            new FakeSourceRepository(),
            $log
        );
        $ie->import_feeds( 'not json' );
        $this->assertCount( 1, $log->rows );
        $this->assertSame( 0, $log->rows[0]['ok'] );
        $this->assertSame( 1, $log->rows[0]['errors_count'] );
    }

    public function test_audit_emits_row_on_export(): void {
        $log = new FakeImportLogRepository();
        $ie  = new ImporterExporter(
            new SettingsRepository(),
            new FakeFeedRepository(),
            new FakeSourceRepository(),
            $log
        );
        $ie->export_feeds( array(
            array(
                'feed_uuid'           => 'exported-feed',
                'name'                => 'Exported',
                'feed_type'           => 'source',
                'layout'              => 'grid',
                'status'              => 'published',
                'source_config_json'  => '{}',
                'display_config_json' => '{}',
                'filter_config_json'  => '{}',
                'sort_config_json'    => '{}',
                'custom_css'          => '',
            ),
        ) );
        $this->assertCount( 1, $log->rows );
        $this->assertSame( 'export', $log->rows[0]['op'] );
        $this->assertSame( 1, $log->rows[0]['ok'] );
    }

    // ----- Phase 8.7: round-trip + remap + version + mixed conflict -----

    public function test_export_then_import_round_trip_preserves_all_fields(): void {
        $sources = new FakeSourceRepository();
        $sources->seed( 'src-A', array(
            'youtube_channel_id'  => 'UC_A',
            'youtube_playlist_id' => '',
            'title'               => 'Channel A',
        ) );
        $sources->seed( 'src-B', array(
            'youtube_playlist_id' => 'PL_B',
            'youtube_channel_id'  => '',
            'title'               => 'Playlist B',
        ) );

        $feed_orig_json = wp_json_encode( array(
            'sources'          => array(
                array(
                    'source_uuid' => 'src-A',
                    'weight'      => 1.5,
                    'pinned'      => true,
                    'label'       => 'Featured A',
                ),
                array( 'source_uuid' => 'src-B' ),
            ),
            'manual_video_ids'  => array( 'm1' ),
            'exclude_video_ids' => array( 'x1' ),
            'include_query'     => 'all',
        ) );

        $export_payload = wp_json_encode( array(
            'version' => '0.2.0',
            'kind'    => 'feeds',
            'source_refs' => array(
                'src-A' => array( 'youtube_channel_id'  => 'UC_A', 'youtube_playlist_id' => '', 'youtube_video_id' => '', 'title' => 'Channel A' ),
                'src-B' => array( 'youtube_channel_id'  => '',     'youtube_playlist_id' => 'PL_B', 'youtube_video_id' => '', 'title' => 'Playlist B' ),
            ),
            'feeds' => array(
                array(
                    'feed_uuid'  => 'feed-original',
                    'name'       => 'Round Trip Feed',
                    'feed_type'  => 'source',
                    'layout'     => 'list',
                    'status'     => 'published',
                    'source_config_json'  => $feed_orig_json,
                    'display_config_json' => wp_json_encode( array( 'columns' => 4 ) ),
                    'filter_config_json'  => wp_json_encode( array( 'min_duration' => 60 ) ),
                    'sort_config_json'    => wp_json_encode( array( 'orderby' => 'view_count' ) ),
                    'custom_css'          => '.vyg { color: blue; }',
                ),
            ),
        ) );

        $clean_repo = new FakeFeedRepository();
        $ie         = new ImporterExporter(
            new SettingsRepository(),
            $clean_repo,
            $sources
        );
        $result = $ie->import_feeds( $export_payload, array( 'conflict' => 'replace' ) );
        $this->assertTrue( $result['ok'] );
        $this->assertSame( 1, $result['imported'] );

        $re_imported = $clean_repo->find_by_uuid( 'feed-original' );
        $this->assertNotNull( $re_imported );
        $this->assertSame( 'Round Trip Feed', $re_imported['name'] );
        $this->assertSame( 'list',             $re_imported['layout'] );
        $this->assertSame( 'published',        $re_imported['status'] );

        $src_cfg = json_decode( $re_imported['source_config_json'], true );
        $this->assertCount( 2, $src_cfg['sources'] );
        $this->assertSame( 'src-A', $src_cfg['sources'][0]['source_uuid'] );
        $this->assertSame( 1.5, $src_cfg['sources'][0]['weight'] );
        $this->assertTrue(    $src_cfg['sources'][0]['pinned'] );
        $this->assertSame( 'Featured A', $src_cfg['sources'][0]['label'] );
        $this->assertSame( array( 'm1' ), $src_cfg['manual_video_ids'] );
        $this->assertSame( array( 'x1' ), $src_cfg['exclude_video_ids'] );
        $this->assertSame( 'all', $src_cfg['include_query'] );

        $display = json_decode( $re_imported['display_config_json'], true );
        $this->assertSame( array( 'columns' => 4 ), $display );
        $this->assertSame( '.vyg { color: blue; }', $re_imported['custom_css'] );

        $exported = $ie->export_feeds( array( $re_imported ) );
        $this->assertIsString( $exported );
        $roundtrip = json_decode( $exported, true );
        $this->assertSame( 'feeds', $roundtrip['kind'] );
        $this->assertCount( 1, $roundtrip['feeds'] );
        // export_feeds emits source_config_json as an object (decoded array),
        // not as a re-encoded JSON string. Access it directly.
        $rt_src = $roundtrip['feeds'][0]['source_config_json'];
        $this->assertSame( 'src-A', $rt_src['sources'][0]['source_uuid'] );
        $this->assertSame( 1.5, $rt_src['sources'][0]['weight'] );
    }

    public function test_import_remaps_source_uuid_to_local_via_youtube_channel_id(): void {
        $local_sources = new FakeSourceRepository();
        $local_sources->seed( 'LOCAL-src-A', array(
            'youtube_channel_id'  => 'UC_AAA',
            'youtube_playlist_id' => '',
            'title'               => 'Channel A (local)',
        ) );
        $feed_repo = new FakeFeedRepository();
        $ie        = new ImporterExporter(
            new SettingsRepository(),
            $feed_repo,
            $local_sources
        );
        $export_json = wp_json_encode( array(
            'version' => '0.2.0',
            'kind'    => 'feeds',
            'source_refs' => array(
                'REMOTE-src-A' => array(
                    'youtube_channel_id'  => 'UC_AAA',
                    'youtube_playlist_id' => '',
                    'youtube_video_id'    => '',
                    'title'               => 'Channel A (remote)',
                ),
            ),
            'feeds' => array(
                array(
                    'feed_uuid'  => 'remixed-feed',
                    'name'       => 'Remixed',
                    'feed_type'  => 'source',
                    'layout'     => 'grid',
                    'status'     => 'published',
                    'source_config_json' => wp_json_encode( array(
                        'sources' => array(
                            array(
                                'source_uuid' => 'REMOTE-src-A',
                                'weight'      => 1.5,
                                'pinned'      => true,
                                'label'       => '',
                            ),
                        ),
                    ) ),
                    'display_config_json' => '{}',
                    'filter_config_json'  => '{}',
                    'sort_config_json'    => '{}',
                    'custom_css'          => '',
                ),
            ),
        ) );

        $result = $ie->import_feeds( $export_json, array( 'conflict' => 'replace' ) );
        $this->assertTrue( $result['ok'], 'result ok; warnings=' . wp_json_encode( $result['warnings'] ) );
        $this->assertSame( 1, $result['imported'] );
        $this->assertEmpty( $result['warnings'] );

        $row = $feed_repo->find_by_uuid( 'remixed-feed' );
        $this->assertNotNull( $row );
        $src_cfg = json_decode( $row['source_config_json'], true );
        $this->assertCount( 1, $src_cfg['sources'] );
        $this->assertSame( 'LOCAL-src-A',         $src_cfg['sources'][0]['source_uuid'] );
        $this->assertSame( 1.5,                   $src_cfg['sources'][0]['weight'] );
        $this->assertTrue(                        $src_cfg['sources'][0]['pinned'] );
        $this->assertSame( 'Channel A (local)',   $src_cfg['sources'][0]['label'] );
    }

    public function test_import_warns_when_no_local_source_match(): void {
        $feed_repo = new FakeFeedRepository();
        $ie        = new ImporterExporter(
            new SettingsRepository(),
            $feed_repo,
            new FakeSourceRepository()
        );
        $export_json = wp_json_encode( array(
            'version' => '0.2.0',
            'kind'    => 'feeds',
            'source_refs' => array(
                'REMOTE-orphan' => array(
                    'youtube_channel_id'  => 'UC_XXX',
                    'youtube_playlist_id' => '',
                    'youtube_video_id'    => '',
                ),
            ),
            'feeds' => array(
                array(
                    'feed_uuid'  => 'orphan-feed',
                    'name'       => 'Orphan',
                    'feed_type'  => 'source',
                    'layout'     => 'grid',
                    'status'     => 'published',
                    'source_config_json' => wp_json_encode( array(
                        'sources' => array(
                            array( 'source_uuid' => 'REMOTE-orphan' ),
                        ),
                    ) ),
                    'display_config_json' => '{}',
                    'filter_config_json'  => '{}',
                    'sort_config_json'    => '{}',
                    'custom_css'          => '',
                ),
            ),
        ) );
        $result = $ie->import_feeds( $export_json, array( 'conflict' => 'replace' ) );
        // The feed is still created, just with empty sources after the orphan
        // was dropped. Result reflects: ok=true (feed created with no errors),
        // no imports counted since the source was dropped, and a warning fired.
        $this->assertTrue( $result['ok'] );
        $this->assertSame( 1, $result['imported'] );
        $this->assertNotEmpty( $result['warnings'] );
        $this->assertStringContainsString( 'REMOTE-orphan', $result['warnings'][0] );
    }

    public function test_import_skips_collisions_in_skip_mode(): void {
        $feeds_repo = new FakeFeedRepository();
        $feeds_repo->create( array(
            'feed_uuid'           => 'feed-A',
            'name'                => 'Original A',
            'feed_type'           => 'source',
            'layout'              => 'grid',
            'status'              => 'draft',
            'source_config_json'  => '{}',
            'display_config_json' => '{}',
            'filter_config_json'  => '{}',
            'sort_config_json'    => '{}',
            'custom_css'          => '',
        ) );
        $ie = new ImporterExporter(
            new SettingsRepository(),
            $feeds_repo,
            new FakeSourceRepository()
        );
        $export_json = wp_json_encode( array(
            'version' => '0.2.0',
            'kind'    => 'feeds',
            'source_refs' => array(),
            'feeds' => array(
                array(
                    'feed_uuid'  => 'feed-A',
                    'name'       => 'Replaced A',
                    'feed_type'  => 'source',
                    'layout'     => 'list',
                    'status'     => 'published',
                    'source_config_json'  => '{}',
                    'display_config_json' => '{}',
                    'filter_config_json'  => '{}',
                    'sort_config_json'    => '{}',
                    'custom_css'          => '',
                ),
            ),
        ) );
        $first = $ie->import_feeds( $export_json, array( 'conflict' => 'replace' ) );
        $this->assertSame( 1, $first['imported'] );
        $this->assertSame( 0, $first['skipped'] );

        $second = $ie->import_feeds( $export_json, array( 'conflict' => 'skip' ) );
        $this->assertSame( 0, $second['imported'] );
        $this->assertSame( 1, $second['skipped'] );

        $row = $feeds_repo->find_by_uuid( 'feed-A' );
        $this->assertSame( 'Replaced A', $row['name'] );
        $this->assertSame( 'list',       $row['layout'] );
    }

    public function test_import_rejects_newer_major_version_without_force(): void {
        $feed_repo = new FakeFeedRepository();
        $ie        = new ImporterExporter(
            new SettingsRepository(),
            $feed_repo,
            new FakeSourceRepository()
        );
        // The current EXPORT_VERSION is 0.2.0; sending 1.0.0 (major=1) is rejected
        // unless force=true.
        $export_json = wp_json_encode( array(
            'version' => '1.0.0',
            'kind'    => 'feeds',
            'source_refs' => array(),
            'feeds' => array(),
        ) );
        $result = $ie->import_feeds( $export_json );
        $this->assertFalse( $result['ok'] );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( '1.0.0', $result['errors'][0] );
    }

    public function test_import_accepts_newer_major_version_with_force_flag(): void {
        $feed_repo = new FakeFeedRepository();
        $ie        = new ImporterExporter(
            new SettingsRepository(),
            $feed_repo,
            new FakeSourceRepository()
        );
        $export_json = wp_json_encode( array(
            'version' => '1.0.0',
            'kind'    => 'feeds',
            'source_refs' => array(),
            'feeds' => array(),
        ) );
        $result = $ie->import_feeds( $export_json, array( 'force' => true ) );
        $this->assertTrue( $result['ok'] );
        $this->assertEmpty( $result['errors'] );
    }
}

/**
 * Stub FeedRepository for unit tests — implements only the methods ImporterExporter uses.
 */
final class FakeFeedRepository extends FeedRepository {
    /** @var array<string,array<string,mixed>> */
    private array $by_uuid = array();
    private int $next_id = 0;

    public function find_by_uuid( string $uuid ): ?array {
        return $this->by_uuid[ $uuid ] ?? null;
    }

    public function create( array $data ): int {
        $uuid = $data['feed_uuid'] ?? sprintf( 'auto-%s-%d', wp_generate_uuid4(), $this->next_id );
        $this->next_id++;
        // Mirror the production behavior: JSON-encode the *_json columns to a string.
        foreach ( array( 'source_config_json', 'display_config_json', 'filter_config_json', 'sort_config_json' ) as $col ) {
            if ( isset( $data[ $col ] ) && is_array( $data[ $col ] ) ) {
                $data[ $col ] = wp_json_encode( $data[ $col ] );
            }
            if ( ! isset( $data[ $col ] ) || ! is_string( $data[ $col ] ) ) {
                $data[ $col ] = '{}';
            }
        }
        $this->by_uuid[ $uuid ] = array_merge(
            array(
                'id'        => $this->next_id,
                'feed_uuid' => $uuid,
                'name'      => '',
                'layout'    => 'grid',
                'status'    => 'draft',
            ),
            $data,
            array( 'id' => $this->next_id, 'feed_uuid' => $uuid )
        );
        return $this->next_id;
    }

    public function update( int $id, array $data ): bool {
        foreach ( $this->by_uuid as $uuid => $row ) {
            if ( (int) ( $row['id'] ?? 0 ) === $id ) {
                foreach ( array( 'source_config_json', 'display_config_json', 'filter_config_json', 'sort_config_json' ) as $col ) {
                    if ( isset( $data[ $col ] ) && is_array( $data[ $col ] ) ) {
                        $data[ $col ] = wp_json_encode( $data[ $col ] );
                    }
                }
                $this->by_uuid[ $uuid ] = array_merge( $row, $data );
                return true;
            }
        }
        return false;
    }

    // ----- Phase 8.6: large-payload + audit + malformed-JSON hardening -----

    public function test_import_feeds_rejects_oversized_payload(): void {
        $big = str_repeat( 'x', ImporterExporter::DEFAULT_IMPORT_SIZE_CAP_BYTES + 1 );
        $result = $this->ie->import_feeds( $big );
        $this->assertFalse( $result['ok'] );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'too large', $result['errors'][0] );
    }

    public function test_import_feeds_rejects_empty_payload(): void {
        $result = $this->ie->import_feeds( '' );
        $this->assertFalse( $result['ok'] );
        $this->assertStringContainsString( 'Empty', $result['errors'][0] );
    }

    public function test_import_feeds_reports_specific_json_error(): void {
        // Truncated JSON: closing brace missing.
        $result = $this->ie->import_feeds( '{"kind":"feeds","version":"0.2.0","feeds":[' );
        $this->assertFalse( $result['ok'] );
        $this->assertStringContainsString( 'Invalid JSON', $result['errors'][0] );
        // Should not just be a generic 'Invalid JSON.' — should mention the specific error.
        $this->assertStringNotContainsString( 'Invalid JSON.', $result['errors'][0] );
    }

    public function test_import_feeds_rejects_top_level_non_object(): void {
        // JSON string at top level (not an object/array).
        $result = $this->ie->import_feeds( '"hello"' );
        $this->assertFalse( $result['ok'] );
        $this->assertStringContainsString( 'not an object', $result['errors'][0] );
    }

    public function test_audit_emits_row_on_success(): void {
        $log    = new FakeImportLogRepository();
        $ie     = new ImporterExporter(
            new SettingsRepository(),
            new FakeFeedRepository(),
            new FakeSourceRepository(),
            $log
        );
        $json = wp_json_encode( array(
            'version' => '0.2.0',
            'kind'    => 'feeds',
            'feeds'   => array(
                array(
                    'feed_uuid'           => 'audited-feed-uuid',
                    'name'                => 'Audited Feed',
                    'layout'              => 'grid',
                    'status'              => 'published',
                    'source_config_json'  => array(),
                    'display_config_json' => array(),
                    'filter_config_json'  => array(),
                    'sort_config_json'    => array(),
                    'custom_css'          => '',
                ),
            ),
        ) );
        $result = $ie->import_feeds( $json, array( 'conflict' => 'skip' ) );
        $this->assertTrue( $result['ok'] );
        $this->assertCount( 1, $log->rows );
        $row = $log->rows[0];
        $this->assertSame( 'import', $row['op'] );
        $this->assertSame( 'feeds', $row['kind'] );
        $this->assertSame( 'skip', $row['conflict_mode'] );
        $this->assertSame( 1, $row['imported_count'] );
        $this->assertNotEmpty( $row['payload_hash'] );
        $this->assertSame( strlen( $json ), $row['payload_bytes'] );
        $this->assertGreaterThanOrEqual( 0, $row['duration_ms'] );
    }

    public function test_audit_emits_row_on_error(): void {
        $log = new FakeImportLogRepository();
        $ie  = new ImporterExporter(
            new SettingsRepository(),
            new FakeFeedRepository(),
            new FakeSourceRepository(),
            $log
        );
        $ie->import_feeds( 'not json' );
        $this->assertCount( 1, $log->rows );
        $this->assertSame( 0, $log->rows[0]['ok'] );
        $this->assertSame( 1, $log->rows[0]['errors_count'] );
    }

    public function test_audit_emits_row_on_export(): void {
        $log = new FakeImportLogRepository();
        $ie  = new ImporterExporter(
            new SettingsRepository(),
            new FakeFeedRepository(),
            new FakeSourceRepository(),
            $log
        );
        $ie->export_feeds( array(
            array(
                'feed_uuid'           => 'exported-feed',
                'name'                => 'Exported',
                'feed_type'           => 'source',
                'layout'              => 'grid',
                'status'              => 'published',
                'source_config_json'  => '{}',
                'display_config_json' => '{}',
                'filter_config_json'  => '{}',
                'sort_config_json'    => '{}',
                'custom_css'          => '',
            ),
        ) );
        $this->assertCount( 1, $log->rows );
        $this->assertSame( 'export', $log->rows[0]['op'] );
        $this->assertSame( 1, $log->rows[0]['ok'] );
    }

}

/**
 * Stub ImportLogRepository — in-memory replacement for tests.
 */
final class FakeImportLogRepository extends ImportLogRepository {
    /** @var array<int,array<string,mixed>> */
    public array $rows = array();
    private int $next_id = 0;

    public function record( array $data ): int {
        $this->next_id++;
        // Mirror the format coercion the real repository performs on insert.
        $data['id']                = $this->next_id;
        $data['ok']                = ! empty( $data['ok'] ) ? 1 : 0;
        $data['force']             = ! empty( $data['force'] ) ? 1 : 0;
        $data['payload_bytes']     = (int) ( $data['payload_bytes'] ?? 0 );
        $data['imported_count']    = (int) ( $data['imported_count'] ?? 0 );
        $data['replaced_count']    = (int) ( $data['replaced_count'] ?? 0 );
        $data['duplicated_count']  = (int) ( $data['duplicated_count'] ?? 0 );
        $data['skipped_count']     = (int) ( $data['skipped_count'] ?? 0 );
        $data['errors_count']      = count( (array) ( $data['errors'] ?? array() ) );
        $data['warnings_count']    = count( (array) ( $data['warnings'] ?? array() ) );
        $data['duration_ms']       = (int) ( $data['duration_ms'] ?? 0 );
        $this->rows[] = $data;
        return $this->next_id;
    }

    public function find( int $id ): ?array {
        foreach ( $this->rows as $row ) {
            if ( (int) ( $row['id'] ?? 0 ) === $id ) {
                return $row;
            }
        }
        return null;
    }

    public function list_recent( array $filters = array() ): array {
        return array_reverse( $this->rows );
    }

    public function count( array $filters = array() ): int {
        return count( $this->rows );
    }

    public function prune_older_than( int $retention_days ): int {
        return 0;
    }
}

/**
 * Stub SourceRepository for unit tests — only find_by_uuid + list.
 */
final class FakeSourceRepository extends SourceRepository {
    /** @var array<string,array<string,mixed>> */
    private array $by_uuid = array();

    public function find_by_uuid( string $uuid ): ?array {
        return $this->by_uuid[ $uuid ] ?? null;
    }

    public function list( array $args = array() ): array {
        return array_values( $this->by_uuid );
    }

    public function seed( string $uuid, array $row ): void {
        $this->by_uuid[ $uuid ] = array_merge( array( 'source_uuid' => $uuid ), $row );
    }
}