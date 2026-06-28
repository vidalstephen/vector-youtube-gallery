<?php
/**
 * Importer / Exporter — JSON round-trip for settings + sources.
 *
 * Format (settings): { "version": "0.1.0", "kind": "settings", "values": { ... } }
 * Format (sources):  { "version": "0.1.0", "kind": "sources",  "sources": [ ... ] }
 *
 * Source records include id (UUID), source_type, youtube_*_id, title.
 * Live data (videos, sync_jobs, quota_log) is intentionally NOT exported —
 * it's re-fetched from YouTube on the new install.
 *
 * @package VectorYT\Gallery\Admin
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Admin;

use VectorYT\Gallery\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class ImporterExporter {

    private const EXPORT_VERSION = '0.1.0';

    public function __construct(
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * Build a JSON export of all current settings.
     */
    public function export_settings(): string {
        return wp_json_encode( array(
            'version'      => self::EXPORT_VERSION,
            'kind'         => 'settings',
            'plugin_version' => defined( 'VYG_VERSION' ) ? VYG_VERSION : '0.0.0',
            'exported_at'  => gmdate( 'c' ),
            'values'       => $this->settings->all(),
        ), JSON_PRETTY_PRINT );
    }

    /**
     * Import settings from a JSON string. Returns { ok: bool, imported: int, errors: array }.
     *
     * @return array{ok:bool, imported:int, errors:array<int,string>}
     */
    public function import_settings( string $json ): array {
        $data = json_decode( $json, true );
        if ( ! is_array( $data ) ) {
            return array( 'ok' => false, 'imported' => 0, 'errors' => array( 'Invalid JSON.' ) );
        }
        if ( ( $data['kind'] ?? '' ) !== 'settings' ) {
            return array( 'ok' => false, 'imported' => 0, 'errors' => array( 'JSON kind is not "settings".' ) );
        }
        $values = isset( $data['values'] ) && is_array( $data['values'] ) ? $data['values'] : array();
        $saved  = $this->settings->save_posted( $values );
        return array(
            'ok'       => true,
            'imported' => count( $saved ),
            'errors'   => array(),
        );
    }

    /**
     * Build a JSON export of all sources.
     *
     * @param array<int,array<string,mixed>> $sources
     */
    public function export_sources( array $sources ): string {
        $records = array();
        foreach ( $sources as $s ) {
            $records[] = array(
                'source_uuid'       => (string) ( $s['source_uuid'] ?? '' ),
                'source_type'       => (string) ( $s['source_type'] ?? '' ),
                'youtube_channel_id' => (string) ( $s['youtube_channel_id'] ?? '' ),
                'youtube_playlist_id'=> (string) ( $s['youtube_playlist_id'] ?? '' ),
                'youtube_video_id'  => (string) ( $s['youtube_video_id'] ?? '' ),
                'title'             => (string) ( $s['title'] ?? '' ),
                'thumbnail_url'     => (string) ( $s['thumbnail_url'] ?? '' ),
            );
        }
        return wp_json_encode( array(
            'version'        => self::EXPORT_VERSION,
            'kind'           => 'sources',
            'plugin_version' => defined( 'VYG_VERSION' ) ? VYG_VERSION : '0.0.0',
            'exported_at'    => gmdate( 'c' ),
            'sources'        => $records,
        ), JSON_PRETTY_PRINT );
    }
}