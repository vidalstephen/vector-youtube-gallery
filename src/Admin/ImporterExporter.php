<?php
/**
 * Importer / Exporter — JSON round-trip for settings, sources, and feeds.
 *
 * Format (settings): { "version": "0.2.0", "kind": "settings", "values": { ... } }
 * Format (sources):  { "version": "0.2.0", "kind": "sources",  "sources": [ ... ] }
 * Format (feeds):    { "version": "0.2.0", "kind": "feeds",    "feeds":   [ ... ] }
 *
 * Source records include id (UUID), source_type, youtube_*_id, title.
 * Feed records include feed_uuid, name, layout, status, source_config_json
 * (with sources[]/manual_video_ids[]/exclude_video_ids[]), display_config_json,
 * filter_config_json, sort_config_json, custom_css.
 *
 * Live data (videos, sync_jobs, quota_log) is intentionally NOT exported —
 * it's re-fetched from YouTube on the new install.
 *
 * Phase 8.5: feeds import/export with conflict handling (replace/duplicate/skip)
 * and source remap by YouTube ID.
 *
 * @package VectorYT\Gallery\Admin
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Admin;

use VectorYT\Gallery\Repository\FeedRepository;
use VectorYT\Gallery\Repository\ImportLogRepository;
use VectorYT\Gallery\Repository\SourceRepository;
use VectorYT\Gallery\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class ImporterExporter {

    /**
     * Bump whenever the export schema changes incompatibly. Older exports
     * still load under the same major version; newer exports with a newer
     * major version refuse to import unless `force=true` is passed.
     */
    private const EXPORT_VERSION = '0.2.0';

    /** Conflict modes accepted by import_feeds(). */
    public const CONFLICT_REPLACE   = 'replace';
    public const CONFLICT_DUPLICATE = 'duplicate';
    public const CONFLICT_SKIP      = 'skip';

    /**
     * Phase 8.6: hard cap on import payload size in bytes. Default 5 MB.
     * Anything larger is rejected with a clear error rather than silently
     * truncated. Operators can raise this through `vyg_import_size_cap_bytes`
     * filter (read by the REST controller, not directly here).
     */
    public const DEFAULT_IMPORT_SIZE_CAP_BYTES = 5_242_880;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly ?FeedRepository $feeds = null,
        private readonly ?SourceRepository $sources = null,
        private readonly ?ImportLogRepository $import_log = null,
    ) {
        // Instance-scoped cache for index_local_sources_by_youtube_id so
        // tests with independent FakeSourceRepository instances don't see
        // each other's data.
        $this->local_index_cache = null;
    }

    /** Cached output of index_local_sources_by_youtube_id(); reset per-instance. */
    private ?array $local_index_cache = null;

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

    /**
     * Build a JSON export of feeds.
     *
     * Each feed record is round-trippable: re-importing the JSON produces a
     * functionally-equivalent feed (after source remap and conflict handling).
     *
     * @param array<int,array<string,mixed>> $feeds
     */
    public function export_feeds( array $feeds ): string {
        $start_ms = (int) ( microtime( true ) * 1000 );
        // Build a lookup map: source_uuid → YouTube identifiers. The import
        // side uses this to remap UUIDs across sites when operators move
        // feeds between installs.
        $source_refs = array();
        if ( null !== $this->sources ) {
            foreach ( $this->sources->list() as $s ) {
                $uuid = (string) ( $s['source_uuid'] ?? '' );
                if ( '' === $uuid ) {
                    continue;
                }
                $source_refs[ $uuid ] = array(
                    'youtube_channel_id'  => (string) ( $s['youtube_channel_id']  ?? '' ),
                    'youtube_playlist_id' => (string) ( $s['youtube_playlist_id'] ?? '' ),
                    'youtube_video_id'    => (string) ( $s['youtube_video_id']    ?? '' ),
                    'title'               => (string) ( $s['title'] ?? '' ),
                );
            }
        }

        $records = array();
        foreach ( $feeds as $f ) {
            $cfg = FeedRepository::decode_config( $f );
            $records[] = array(
                'feed_uuid'           => (string) ( $f['feed_uuid'] ?? '' ),
                'name'                => (string) ( $f['name'] ?? '' ),
                'feed_type'           => (string) ( $f['feed_type'] ?? 'source' ),
                'layout'              => (string) ( $f['layout'] ?? 'grid' ),
                'status'              => (string) ( $f['status'] ?? 'draft' ),
                'source_config_json'  => $cfg['source'],
                'display_config_json' => $cfg['display'],
                'filter_config_json'  => $cfg['filter'],
                'sort_config_json'    => $cfg['sort'],
                'custom_css'          => (string) ( $f['custom_css'] ?? '' ),
                'created_at'          => (string) ( $f['created_at'] ?? '' ),
                'updated_at'          => (string) ( $f['updated_at'] ?? '' ),
            );
        }
        $json = wp_json_encode( array(
            'version'        => self::EXPORT_VERSION,
            'kind'           => 'feeds',
            'plugin_version' => defined( 'VYG_VERSION' ) ? VYG_VERSION : '0.0.0',
            'exported_at'    => gmdate( 'c' ),
            'feeds'          => $records,
            'source_refs'    => $source_refs,
        ), JSON_PRETTY_PRINT );
        $this->audit_export( $records, $start_ms );
        return $json;
    }

    /**
     * Phase 8.6: emit an audit row for an export. Side-effect only.
     */
    private function audit_export( array $records, int $start_ms ): void {
        if ( null === $this->import_log ) {
            return;
        }
        $payload = wp_json_encode( array( 'kind' => 'feeds', 'feeds' => $records ) );
        $user_obj = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
        $user_id  = ( is_object( $user_obj ) && ! empty( $user_obj->ID ) ) ? (int) $user_obj->ID : null;
        $user_log = ( is_object( $user_obj ) && ! empty( $user_obj->ID ) ) ? (string) $user_obj->user_login : null;
        try {
            $this->import_log->record( array(
                'op'             => 'export',
                'kind'           => 'feeds',
                'user_id'        => $user_id,
                'user_login'     => $user_log,
                'payload_bytes'  => strlen( (string) $payload ),
                'payload_hash'   => '' !== $payload ? hash( 'sha256', (string) $payload ) : '',
                'conflict_mode'  => null,
                'force'          => false,
                'ok'             => true,
                'imported_count' => 0,
                'replaced_count' => 0,
                'duplicated_count' => 0,
                'skipped_count'  => 0,
                'errors'         => array(),
                'warnings'       => array(),
                'duration_ms'    => max( 0, (int) ( microtime( true ) * 1000 ) - $start_ms ),
                'ip'             => isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '',
                'user_agent'     => isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '',
            ) );
        } catch ( \Throwable $e ) {
            // Audit failures must never break the operation; swallow.
        }
    }

    /**
     * Import feeds from a JSON string.
     *
     * Conflict handling:
     *  - replace:   overwrite the existing feed with the same feed_uuid.
     *  - duplicate: create a new feed row with a new feed_uuid and `(copy)` suffix.
     *  - skip:      leave the existing feed untouched.
     *
     * Source remap:
     *  The exported feed's source_config references source_uuids that may not
     *  exist on the target site. We resolve each by YouTube identifier
     *  (channel_id / playlist_id / video_id), preferring an exact match in
     *  the target sources table. Missing sources are skipped (logged as a
     *  warning) — the feed is still imported, but with empty source lists.
     *
     * Returns { ok: bool, imported: int, replaced: int, duplicated: int,
     *           skipped: int, errors: array<int,string>, warnings: array<int,string> }.
     *
     * @param array<string,mixed> $options {
     *     @type string $conflict 'replace'|'duplicate'|'skip' (default 'skip')
     *     @type bool   $force    accept newer export versions (default false)
     * }
     * @return array<string,mixed>
     */
    public function import_feeds( string $json, array $options = array() ): array {
        $defaults = array(
            'conflict' => self::CONFLICT_SKIP,
            'force'    => false,
        );
        $options  = array_merge( $defaults, $options );
        $conflict = (string) $options['conflict'];
        $force    = (bool) $options['force'];

        $result = array(
            'ok'        => false,
            'imported'  => 0,
            'replaced'  => 0,
            'duplicated'=> 0,
            'skipped'   => 0,
            'errors'    => array(),
            'warnings'  => array(),
        );

        $start_ms = (int) ( microtime( true ) * 1000 );

        if ( ! in_array( $conflict, array( self::CONFLICT_REPLACE, self::CONFLICT_DUPLICATE, self::CONFLICT_SKIP ), true ) ) {
            $result['errors'][] = 'Invalid conflict mode: ' . $conflict;
            $this->audit( 'import', $json, $conflict, $force, $result, $start_ms );
            return $result;
        }

        // Phase 8.6: enforce size cap defensively. The REST controller already
        // rejects oversized payloads with HTTP 413, but we re-check here so
        // any direct caller (CLI, tests, future API) is also protected.
        $size_cap = (int) ( $options['size_cap_bytes'] ?? self::DEFAULT_IMPORT_SIZE_CAP_BYTES );
        if ( '' === $json ) {
            $result['errors'][] = 'Empty payload.';
            $this->audit( 'import', $json, $conflict, $force, $result, $start_ms );
            return $result;
        }
        if ( strlen( $json ) > $size_cap ) {
            $result['errors'][] = sprintf(
                'Payload too large: %d bytes (cap %d bytes).',
                strlen( $json ),
                $size_cap
            );
            $this->audit( 'import', $json, $conflict, $force, $result, $start_ms );
            return $result;
        }

        // Phase 8.6: defensive JSON decode with a friendly error on truncation.
        $data = json_decode( $json, true );
        if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
            $result['errors'][] = sprintf(
                'Invalid JSON: %s.',
                $this->json_error_message( json_last_error() )
            );
            $this->audit( 'import', $json, $conflict, $force, $result, $start_ms );
            return $result;
        }
        if ( ! is_array( $data ) ) {
            $result['errors'][] = 'Invalid JSON: top-level value is not an object.';
            $this->audit( 'import', $json, $conflict, $force, $result, $start_ms );
            return $result;
        }
        if ( ( $data['kind'] ?? '' ) !== 'feeds' ) {
            $result['errors'][] = 'JSON kind is not "feeds".';
            $this->audit( 'import', $json, $conflict, $force, $result, $start_ms );
            return $result;
        }

        // Version check: refuse newer exports unless force=true.
        $export_version = (string) ( $data['version'] ?? '' );
        if ( '' !== $export_version ) {
            $export_major = (int) ( explode( '.', $export_version )[0] ?? 0 );
            $current_major = (int) ( explode( '.', self::EXPORT_VERSION )[0] ?? 0 );
            if ( $export_major > $current_major && ! $force ) {
                $result['errors'][] = sprintf(
                    'Export version %s is newer than supported version %s. Pass force=true to import anyway.',
                    $export_version,
                    self::EXPORT_VERSION
                );
                $this->audit( 'import', $json, $conflict, $force, $result, $start_ms );
                return $result;
            }
        }

        $records = isset( $data['feeds'] ) && is_array( $data['feeds'] ) ? $data['feeds'] : array();
        if ( empty( $records ) ) {
            // Empty export is a valid no-op; success when no errors are present.
            $result['ok'] = empty( $result['errors'] );
            $this->audit( 'import', $json, $conflict, $force, $result, $start_ms );
            return $result;
        }

        if ( null === $this->feeds ) {
            $result['errors'][] = 'FeedRepository unavailable in this context.';
            $this->audit( 'import', $json, $conflict, $force, $result, $start_ms );
            return $result;
        }

        $source_refs = isset( $data['source_refs'] ) && is_array( $data['source_refs'] ) ? $data['source_refs'] : array();

        foreach ( $records as $idx => $rec ) {
            if ( ! is_array( $rec ) ) {
                $result['warnings'][] = sprintf( 'Skipping record #%d: not an object.', $idx );
                continue;
            }

            $uuid = (string) ( $rec['feed_uuid'] ?? '' );
            if ( '' === $uuid ) {
                $result['warnings'][] = sprintf( 'Skipping record #%d: missing feed_uuid.', $idx );
                continue;
            }

            $existing = $this->feeds->find_by_uuid( $uuid );

            // Source remap: rewrite source_config.source[s].source_uuid to
            // a local source_uuid matched by YouTube ID. The export shape is
            // an object (already-decoded array OR a JSON string); decode if
            // necessary so we get a uniform array.
            $raw_src = $rec['source_config_json'] ?? null;
            if ( is_string( $raw_src ) ) {
                $decoded = json_decode( (string) $raw_src, true );
                $raw_src = is_array( $decoded ) ? $decoded : array();
            } elseif ( ! is_array( $raw_src ) ) {
                $raw_src = array();
            }
            $src_cfg  = $this->remap_sources( $raw_src, $source_refs, $result['warnings'], $idx );

            $row = array(
                'feed_uuid'           => $uuid,
                'name'                => (string) ( $rec['name'] ?? '' ),
                'feed_type'           => (string) ( $rec['feed_type'] ?? 'source' ),
                'layout'              => (string) ( $rec['layout'] ?? 'grid' ),
                'status'              => (string) ( $rec['status'] ?? 'draft' ),
                'source_config_json'  => $src_cfg,
                'display_config_json' => $this->decode_or_array( $rec['display_config_json'] ?? null ),
                'filter_config_json'  => $this->decode_or_array( $rec['filter_config_json']  ?? null ),
                'sort_config_json'    => $this->decode_or_array( $rec['sort_config_json']    ?? null ),
                'custom_css'          => (string) ( $rec['custom_css'] ?? '' ),
            );

            if ( null !== $existing ) {
                if ( self::CONFLICT_SKIP === $conflict ) {
                    $result['skipped']++;
                    continue;
                }
                if ( self::CONFLICT_REPLACE === $conflict ) {
                    $ok = $this->feeds->update( (int) $existing['id'], $row );
                    if ( $ok ) {
                        $result['replaced']++;
                        $result['imported']++;
                    } else {
                        $result['warnings'][] = sprintf( 'Failed to replace feed %s.', $uuid );
                    }
                    continue;
                }
                // duplicate
                $row['name']   = $row['name'] . ' (copy)';
                $new_id = $this->feeds->create( $row );
                if ( $new_id > 0 ) {
                    $result['duplicated']++;
                    $result['imported']++;
                } else {
                    $result['warnings'][] = sprintf( 'Failed to duplicate feed %s.', $uuid );
                }
                continue;
            }

            // No collision — fresh create.
            $new_id = $this->feeds->create( $row );
            if ( $new_id > 0 ) {
                $result['imported']++;
            } else {
                $result['warnings'][] = sprintf( 'Failed to create feed %s.', $uuid );
            }
        }

        $result['ok'] = ( $result['imported'] + $result['replaced'] + $result['duplicated'] ) > 0
            || ( $result['skipped'] > 0 && empty( $result['errors'] ) );

        // Phase 8.6: emit a single audit row covering this whole operation.
        $this->audit( 'import', $json, $conflict, $force, $result, $start_ms );

        return $result;
    }

    /**
     * Rewrite an exported source_config's source_uuid entries to local
     * source UUIDs by matching on YouTube identifiers carried in $source_refs.
     *
     * @param array<string,mixed>     $src_cfg
     * @param array<string,mixed>     $source_refs source_uuid → {channel_id, playlist_id, video_id, title}
     * @param array<int,string>       $warnings
     * @param int                     $idx
     * @return array<string,mixed>
     */
    private function remap_sources( array $src_cfg, array $source_refs, array &$warnings, int $idx ): array {
        $sources = isset( $src_cfg['sources'] ) && is_array( $src_cfg['sources'] ) ? $src_cfg['sources'] : array();
        if ( empty( $sources ) ) {
            return $src_cfg;
        }

        $local_by_youtube = $this->index_local_sources_by_youtube_id();

        $remapped = array();
        foreach ( $sources as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $uuid = (string) ( $entry['source_uuid'] ?? '' );
            if ( '' === $uuid ) {
                continue;
            }
            // Fast path: source_uuid already exists on this site.
            if ( null !== $this->sources && null !== $this->sources->find_by_uuid( $uuid ) ) {
                $remapped[] = $entry;
                continue;
            }
            // Slow path: look up the YouTube identifiers from the export and
            // find a local source that matches.
            $ref = isset( $source_refs[ $uuid ] ) && is_array( $source_refs[ $uuid ] ) ? $source_refs[ $uuid ] : array();
            $matched = null;
            foreach ( array( 'youtube_channel_id', 'youtube_playlist_id', 'youtube_video_id' ) as $col ) {
                $v = (string) ( $ref[ $col ] ?? '' );
                if ( '' !== $v && isset( $local_by_youtube[ $v ] ) ) {
                    $matched = $local_by_youtube[ $v ];
                    break;
                }
            }
            if ( null !== $matched ) {
                $entry['source_uuid'] = (string) ( $matched['source_uuid'] ?? '' );
                if ( '' === (string) ( $entry['label'] ?? '' ) ) {
                    $entry['label'] = (string) ( $matched['title'] ?? '' );
                }
                $remapped[] = $entry;
                continue;
            }
            // No match — drop the source, log a warning so the operator can re-map manually.
            $warnings[] = sprintf(
                'Feed record #%d: source_uuid %s has no local match (channel=%s, playlist=%s, video=%s); removed from feed.',
                $idx,
                $uuid,
                (string) ( $ref['youtube_channel_id']  ?? '' ),
                (string) ( $ref['youtube_playlist_id'] ?? '' ),
                (string) ( $ref['youtube_video_id']    ?? '' )
            );
        }

        $src_cfg['sources'] = $remapped;
        return $src_cfg;
    }

    /**
     * Build a quick lookup map from YouTube channel/playlist/video id →
     * local source row.
     *
     * @return array<string,array<string,mixed>>
     */
    private function index_local_sources_by_youtube_id(): array {
        if ( null !== $this->local_index_cache ) {
            return $this->local_index_cache;
        }
        $cache = array();
        if ( null === $this->sources ) {
            $this->local_index_cache = $cache;
            return $cache;
        }
        foreach ( $this->sources->list() as $row ) {
            foreach ( array( 'youtube_channel_id', 'youtube_playlist_id', 'youtube_video_id' ) as $col ) {
                $v = (string) ( $row[ $col ] ?? '' );
                if ( '' !== $v && ! isset( $cache[ $v ] ) ) {
                    $cache[ $v ] = $row;
                }
            }
        }
        $this->local_index_cache = $cache;
        return $cache;
    }

    /**
     * Decode a value to an array, or return [] on any error.
     */
    private function decode_or_array( $value ): array {
        if ( is_array( $value ) ) {
            return $value;
        }
        if ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            return is_array( $decoded ) ? $decoded : array();
        }
        return array();
    }

    /**
     * Phase 8.6: emit a single audit row.
     *
     * No-op when the ImportLogRepository is not bound (e.g. unit tests that
     * don't care about the audit trail). Failures in the audit write are
     * silently swallowed to keep the user-facing flow working.
     *
     * @param array<string,mixed> $result
     */
    private function audit( string $op, string $payload, string $conflict_mode, bool $force, array $result, int $start_ms ): void {
        if ( null === $this->import_log ) {
            return;
        }
        $user_obj = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
        $user_id  = ( is_object( $user_obj ) && ! empty( $user_obj->ID ) ) ? (int) $user_obj->ID : null;
        $user_log = ( is_object( $user_obj ) && ! empty( $user_obj->ID ) ) ? (string) $user_obj->user_login : null;
        try {
            $this->import_log->record( array(
                'op'               => $op,
                'kind'             => 'feeds',
                'user_id'          => $user_id,
                'user_login'       => $user_log,
                'payload_bytes'    => strlen( $payload ),
                'payload_hash'     => '' !== $payload ? hash( 'sha256', $payload ) : '',
                'conflict_mode'    => $conflict_mode,
                'force'            => $force,
                'ok'               => ! empty( $result['ok'] ),
                'imported_count'   => (int) ( $result['imported']   ?? 0 ),
                'replaced_count'   => (int) ( $result['replaced']   ?? 0 ),
                'duplicated_count' => (int) ( $result['duplicated'] ?? 0 ),
                'skipped_count'    => (int) ( $result['skipped']    ?? 0 ),
                'errors'           => (array) ( $result['errors']   ?? array() ),
                'warnings'         => (array) ( $result['warnings'] ?? array() ),
                'duration_ms'      => max( 0, (int) ( microtime( true ) * 1000 ) - $start_ms ),
                'ip'               => isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '',
                'user_agent'       => isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '',
            ) );
        } catch ( \Throwable $e ) {
            // Audit failures must never break the operation; swallow.
        }
    }

    /**
     * Translate a json_decode error code into a human-friendly message.
     *
     * @return string
     */
    private function json_error_message( int $code ): string {
        switch ( $code ) {
            case JSON_ERROR_DEPTH:            return 'maximum stack depth exceeded';
            case JSON_ERROR_STATE_MISMATCH:   return 'invalid or malformed JSON';
            case JSON_ERROR_CTRL_CHAR:        return 'unexpected control character found';
            case JSON_ERROR_SYNTAX:           return 'syntax error';
            case JSON_ERROR_UTF8:             return 'malformed UTF-8 characters';
            case JSON_ERROR_RECURSION:        return 'recursive reference detected';
            case JSON_ERROR_INF_OR_NAN:       return 'inf or NaN cannot be JSON encoded';
            case JSON_ERROR_UNSUPPORTED_TYPE: return 'unsupported type';
            case JSON_ERROR_INVALID_PROPERTY_NAME: return 'invalid property name';
            case JSON_ERROR_UTF16:            return 'malformed UTF-16 characters';
            default:                          return 'unknown JSON error';
        }
    }
}