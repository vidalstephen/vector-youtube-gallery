<?php
/**
 * Sync log repository — append-only entries in vyg_sync_logs.
 *
 * Phase 2: also drives vyg_sync_jobs (create/update job state for retries).
 *
 * @package VectorYT\Gallery\Repository
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Repository;

use VectorYT\Gallery\Database\Schema;

defined( 'ABSPATH' ) || exit;

class SyncLogRepository {

    public function log_table(): string {
        return Schema::table( 'vyg_sync_logs' );
    }

    public function jobs_table(): string {
        return Schema::table( 'vyg_sync_jobs' );
    }

    public function record(
        string $level,
        string $event,
        string $message,
        ?int $job_id = null,
        ?int $source_id = null,
        array $context = array(),
    ): int {
        global $wpdb;
        $wpdb->insert(
            $this->log_table(),
            array(
                'job_id'       => $job_id,
                'source_id'    => $source_id,
                'level'        => $level,
                'event'        => substr( $event, 0, 128 ),
                'message'      => $message,
                'context_json' => wp_json_encode( $context ),
                'created_at'   => gmdate( 'Y-m-d H:i:s' ),
            ),
            array( '%d','%d','%s','%s','%s','%s','%s' )
        );
        return (int) $wpdb->insert_id;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function recent_for_source( int $source_id, int $limit = 50 ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->log_table()} WHERE source_id = %d ORDER BY id DESC LIMIT %d",
            $source_id,
            $limit
        ), ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function recent_for_job( int $job_id, int $limit = 50 ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->log_table()} WHERE job_id = %d ORDER BY id DESC LIMIT %d",
            $job_id,
            $limit
        ), ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    // ---- Jobs ----

    public function create_job( string $job_type, ?int $source_id = null, ?array $cursor = null ): int {
        global $wpdb;
        $wpdb->insert(
            $this->jobs_table(),
            array(
                'job_uuid'       => wp_generate_uuid4(),
                'source_id'      => $source_id,
                'job_type'       => $job_type,
                'status'         => 'queued',
                'cursor_json'    => $cursor ? wp_json_encode( $cursor ) : null,
                'attempts'       => 0,
                'created_at'     => gmdate( 'Y-m-d H:i:s' ),
                'updated_at'     => gmdate( 'Y-m-d H:i:s' ),
            ),
            array( '%s','%d','%s','%s','%s','%d','%s','%s' )
        );
        return (int) $wpdb->insert_id;
    }

    public function start_job( int $job_id ): void {
        global $wpdb;
        $wpdb->update(
            $this->jobs_table(),
            array(
                'status'     => 'running',
                'started_at' => gmdate( 'Y-m-d H:i:s' ),
                'attempts'   => 1,
                'updated_at' => gmdate( 'Y-m-d H:i:s' ),
            ),
            array( 'id' => $job_id ),
            array( '%s','%s','%d','%s' ),
            array( '%d' )
        );
    }

    public function complete_job( int $job_id, ?array $cursor = null ): void {
        global $wpdb;
        $wpdb->update(
            $this->jobs_table(),
            array(
                'status'       => 'success',
                'completed_at' => gmdate( 'Y-m-d H:i:s' ),
                'cursor_json'  => $cursor ? wp_json_encode( $cursor ) : null,
                'error_code'   => null,
                'error_message'=> null,
                'updated_at'   => gmdate( 'Y-m-d H:i:s' ),
            ),
            array( 'id' => $job_id ),
            array( '%s','%s','%s','%s','%s','%s' ),
            array( '%d' )
        );
    }

    public function fail_job( int $job_id, string $error_code, string $error_message, ?int $next_attempt_at = null ): void {
        global $wpdb;
        $wpdb->update(
            $this->jobs_table(),
            array(
                'status'         => 'failed',
                'completed_at'   => gmdate( 'Y-m-d H:i:s' ),
                'error_code'     => substr( $error_code, 0, 128 ),
                'error_message'  => $error_message,
                'next_attempt_at'=> $next_attempt_at ? gmdate( 'Y-m-d H:i:s', $next_attempt_at ) : null,
                'updated_at'     => gmdate( 'Y-m-d H:i:s' ),
            ),
            array( 'id' => $job_id ),
            array( '%s','%s','%s','%s','%s','%s' ),
            array( '%d' )
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find_job( int $job_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->jobs_table()} WHERE id = %d",
            $job_id
        ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function jobs_for_source( int $source_id, int $limit = 20 ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->jobs_table()} WHERE source_id = %d ORDER BY id DESC LIMIT %d",
            $source_id,
            $limit
        ), ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }
}