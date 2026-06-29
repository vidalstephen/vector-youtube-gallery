<?php
/**
 * ImportLogRepository — audit log for feed import/export operations.
 *
 * Phase 8.6: records every call to /admin/feeds/export and /admin/feeds/import
 * for operator auditing. Captures user identity, payload size, payload hash
 * (truncated SHA-256), outcome counts, and the first N errors/warnings so an
 * operator can audit past runs without pulling the original JSON.
 *
 * @package VectorYT\Gallery\Repository
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Repository;

use VectorYT\Gallery\Database\Schema;
defined( 'ABSPATH' ) || exit;

class ImportLogRepository {

    /** Cap on per-row errors_json / warnings_json content (preserve ~30 messages). */
    private const MAX_MESSAGES_STORED = 30;

    public function table(): string {
        return Schema::table( 'vyg_import_log' );
    }

    /**
     * Insert a new audit row.
     *
     * @param array<string,mixed> $data {
     *     @type string      $op         'import' | 'export'
     *     @type string      $kind       'feeds'
     *     @type int|null    $user_id
     *     @type string|null $user_login
     *     @type int         $payload_bytes
     *     @type string|null $payload_hash (full SHA-256; truncated to 16 chars on insert)
     *     @type string|null $conflict_mode 'skip'|'duplicate'|'replace'
     *     @type bool        $force
     *     @type bool        $ok
     *     @type int         $imported_count
     *     @type int         $replaced_count
     *     @type int         $duplicated_count
     *     @type int         $skipped_count
     *     @type array<int,string> $errors
     *     @type array<int,string> $warnings
     *     @type int         $duration_ms
     *     @type string|null $ip
     *     @type string|null $user_agent
     * }
     * @return int Inserted row id, or 0 on failure.
     */
    public function record( array $data ): int {
        global $wpdb;

        $errors   = isset( $data['errors'] )   && is_array( $data['errors'] )   ? $data['errors']   : array();
        $warnings = isset( $data['warnings'] ) && is_array( $data['warnings'] ) ? $data['warnings'] : array();

        $row = array(
            'op'              => $this->str_or_default( $data, 'op', 'import' ),
            'kind'            => $this->str_or_default( $data, 'kind', 'feeds' ),
            'user_id'         => isset( $data['user_id'] ) ? (int) $data['user_id'] : null,
            'user_login'      => isset( $data['user_login'] ) ? (string) $data['user_login'] : null,
            'payload_bytes'   => isset( $data['payload_bytes'] ) ? (int) $data['payload_bytes'] : 0,
            'payload_hash'    => $this->truncate_hash( $data['payload_hash'] ?? '' ),
            'conflict_mode'   => isset( $data['conflict_mode'] ) ? (string) $data['conflict_mode'] : null,
            'force_flag'      => ! empty( $data['force'] ) ? 1 : 0,
            'ok_flag'         => ! empty( $data['ok'] ) ? 1 : 0,
            'imported_count'  => (int) ( $data['imported_count']   ?? 0 ),
            'replaced_count'  => (int) ( $data['replaced_count']   ?? 0 ),
            'duplicated_count'=> (int) ( $data['duplicated_count'] ?? 0 ),
            'skipped_count'   => (int) ( $data['skipped_count']    ?? 0 ),
            'errors_count'    => count( $errors ),
            'warnings_count'  => count( $warnings ),
            'errors_json'     => wp_json_encode( array_slice( $errors,   0, self::MAX_MESSAGES_STORED ) ),
            'warnings_json'   => wp_json_encode( array_slice( $warnings, 0, self::MAX_MESSAGES_STORED ) ),
            'duration_ms'     => isset( $data['duration_ms'] ) ? (int) $data['duration_ms'] : 0,
            'ip'              => isset( $data['ip'] ) ? substr( (string) $data['ip'], 0, 64 ) : null,
            'user_agent'      => isset( $data['user_agent'] ) ? substr( (string) $data['user_agent'], 0, 255 ) : null,
            'created_at'      => gmdate( 'Y-m-d H:i:s' ),
        );

        $formats = array( '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s' );

        $ok = $wpdb->insert( $this->table(), $row, $formats );
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * List recent audit rows.
     *
     * @param array<string,mixed> $filters { per_page?: int, page?: int, op?: string, kind?: string }
     * @return array<int,array<string,mixed>>
     */
    public function list_recent( array $filters = array() ): array {
        global $wpdb;
        $per_page = max( 1, min( 200, (int) ( $filters['per_page'] ?? 25 ) ) );
        $page     = max( 1, (int) ( $filters['page'] ?? 1 ) );
        $offset   = ( $page - 1 ) * $per_page;
        $op       = isset( $filters['op'] )   ? sanitize_key( (string) $filters['op'] )   : '';
        $kind     = isset( $filters['kind'] ) ? sanitize_key( (string) $filters['kind'] ) : '';

        $where = '1=1';
        $args  = array();
        if ( '' !== $op ) {
            $where  .= ' AND op = %s';
            $args[] = $op;
        }
        if ( '' !== $kind ) {
            $where  .= ' AND kind = %s';
            $args[] = $kind;
        }
        $args[] = $per_page;
        $args[] = $offset;

        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
            $args
        );
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ),
            ARRAY_A
        );
        return is_array( $row ) ? $row : null;
    }

    /**
     * Count all rows (for pagination meta).
     */
    public function count( array $filters = array() ): int {
        global $wpdb;
        $op   = isset( $filters['op'] )   ? sanitize_key( (string) $filters['op'] )   : '';
        $kind = isset( $filters['kind'] ) ? sanitize_key( (string) $filters['kind'] ) : '';

        $where = '1=1';
        $args  = array();
        if ( '' !== $op ) {
            $where  .= ' AND op = %s';
            $args[] = $op;
        }
        if ( '' !== $kind ) {
            $where  .= ' AND kind = %s';
            $args[] = $kind;
        }

        $sql = empty( $args )
            ? "SELECT COUNT(*) FROM {$this->table()}"
            : $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE {$where}", $args );
        return (int) $wpdb->get_var( $sql );
    }

    /**
     * Hard-delete rows older than $retention_days. Returns affected row count.
     */
    public function prune_older_than( int $retention_days ): int {
        global $wpdb;
        $threshold = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $retention_days ) * DAY_IN_SECONDS ) );
        return (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$this->table()} WHERE created_at < %s",
            $threshold
        ) );
    }

    /**
     * @param array<string,mixed> $data
     */
    private function str_or_default( array $data, string $key, string $default ): string {
        return isset( $data[ $key ] ) && '' !== (string) $data[ $key ] ? (string) $data[ $key ] : $default;
    }

    private function truncate_hash( string $full_hash ): ?string {
        if ( '' === $full_hash ) {
            return null;
        }
        return substr( $full_hash, 0, 16 );
    }
}