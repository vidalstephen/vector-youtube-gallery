<?php
/**
 * Sync job runner — generic wrapper that handles job lifecycle for any job class.
 *
 * Responsibilities:
 *   - Mark the job row 'running' at start
 *   - Capture exceptions, classify via RetryPolicy, schedule retry or hard-stop
 *   - Mark the job 'success' or 'failed' on completion
 *   - Record a SyncLog entry for every state transition
 *
 * Subclasses implement `run(array $args, int $job_id): void` — the contract
 * is "do work, throw on failure".
 *
 * @package VectorYT\Gallery\Sync
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Sync;

use VectorYT\Gallery\Logging\Logger;
use VectorYT\Gallery\Repository\SyncLogRepository;
use VectorYT\Gallery\YouTube\QuotaTracker;

defined( 'ABSPATH' ) || exit;

abstract class SyncJobRunner {

    /** WP action hook name (also the WP-Cron hook). Subclasses set this. */
    protected string $hook = '';

    public function __construct(
        protected readonly SyncLogRepository $logs,
        protected readonly RetryPolicy $retry,
        protected readonly QuotaTracker $quota,
        protected readonly Logger $logger,
    ) {}

    /**
     * WP-Cron / Action Scheduler entry point. Reads job_id from args, runs the job.
     */
    public function handle( $args ): void {
        $args   = is_array( $args ) ? $args : array();
        $job_id = isset( $args['vyg_job_id'] ) ? (int) $args['vyg_job_id'] : 0;
        if ( $job_id <= 0 ) {
            $this->logger->error( $this->hook . ': missing job_id in args' );
            return;
        }
        $this->run_with_lifecycle( $job_id, $args );
    }

    /**
     * Run the job with full lifecycle handling. Public so unit tests can invoke directly.
     */
    public function run_with_lifecycle( int $job_id, array $args = array() ): void {
        $job = $this->logs->find_job( $job_id );
        if ( null === $job ) {
            $this->logger->error( $this->hook . ': job ' . $job_id . ' not found' );
            return;
        }

        $attempt = (int) ( $job['attempts'] ?? 0 ) + 1;
        $this->logs->start_job( $job_id );
        $this->logs->record( 'info', 'job_started', $this->hook, $job_id, (int) ( $job['source_id'] ?? null ), array(
            'attempt' => $attempt,
        ) );

        $start = microtime( true );
        try {
            $this->run( $args, $job_id );
            $this->logs->complete_job( $job_id );
            $this->logs->record( 'info', 'job_completed', $this->hook, $job_id, (int) ( $job['source_id'] ?? null ), array(
                'duration_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
            ) );
        } catch ( \Throwable $e ) {
            $this->logs->record( 'error', 'job_failed', $e->getMessage(), $job_id, (int) ( $job['source_id'] ?? null ), array(
                'attempt'     => $attempt,
                'class'       => get_class( $e ),
                'duration_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
            ) );
            $this->retry->schedule_retry( $e, $attempt, $job_id, $this->logs );
        }
    }

    /**
     * Subclass work. Throw on failure (RetryPolicy classifies).
     *
     * @param array<string,mixed> $args
     */
    abstract protected function run( array $args, int $job_id ): void;
}