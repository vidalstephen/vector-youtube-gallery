<?php
/**
 * Retry policy — exponential backoff for sync jobs.
 *
 * Per plan §6:
 *   1st retry: 5 minutes
 *   2nd retry: 15 minutes
 *   3rd retry: 1 hour
 *   4th retry: 6 hours
 *   5th retry: 24 hours
 *   Hard-stop: invalid API key, quota exceeded, revoked OAuth, forbidden source,
 *              playlist not found, channel not found.
 *   Soft retry: network timeout, 5xx, transient WP HTTP error, temporary API downtime.
 *
 * Rate-limit (429) is soft-retryable but uses a separate shorter ladder (1m, 5m, 15m).
 *
 * @package VectorYT\Gallery\Sync
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Sync;

use VectorYT\Gallery\YouTube\ApiException;

defined( 'ABSPATH' ) || exit;

final class RetryPolicy {

    /** Default backoff ladder (seconds). */
    public const LADDER = array(
        1 => 5 * MINUTE_IN_SECONDS,
        2 => 15 * MINUTE_IN_SECONDS,
        3 => HOUR_IN_SECONDS,
        4 => 6 * HOUR_IN_SECONDS,
        5 => 24 * HOUR_IN_SECONDS,
    );

    public const RATE_LIMIT_LADDER = array(
        1 => MINUTE_IN_SECONDS,
        2 => 5 * MINUTE_IN_SECONDS,
        3 => 15 * MINUTE_IN_SECONDS,
    );

    public const MAX_ATTEMPTS = 6;  // ladder has 5 entries; one retry per slot.

    /**
     * Return the next attempt Unix timestamp, or null if we should stop retrying.
     *
     * @param \Throwable $last_error The error from the previous attempt.
     * @param int        $attempt    The attempt number that just failed (1-indexed).
     * @return int|null  Next attempt time (Unix ts), or null when hard-stopping.
     */
    public function next_attempt_at( \Throwable $last_error, int $attempt ): ?int {
        if ( ! $this->should_retry( $last_error, $attempt ) ) {
            return null;
        }
        $delay = $this->backoff_seconds( $last_error, $attempt );
        return time() + $delay;
    }

    public function should_retry( \Throwable $error, int $attempt ): bool {
        if ( $attempt >= self::MAX_ATTEMPTS ) {
            return false;
        }
        if ( $error instanceof ApiException && $error->is_hard_stop() ) {
            return false;
        }
        // Non-API exceptions (PHP fatals, parse errors) — assume transient up to attempt 3.
        return true;
    }

    public function backoff_seconds( \Throwable $error, int $attempt ): int {
        if ( $error instanceof ApiException && ApiException::KIND_RATE_LIMIT === $error->kind() ) {
            return self::RATE_LIMIT_LADDER[ $attempt ] ?? end( self::RATE_LIMIT_LADDER );
        }
        return self::LADDER[ $attempt ] ?? end( self::LADDER );
    }

    /**
     * Persist the next attempt on a job row.
     */
    public function schedule_retry( \Throwable $error, int $attempt, int $job_id, \VectorYT\Gallery\Repository\SyncLogRepository $logs ): void {
        $next = $this->next_attempt_at( $error, $attempt );
        if ( null === $next ) {
            $logs->fail_job( $job_id, 'hard_stop', $error->getMessage(), null );
            $logs->record( 'error', 'retry_exhausted', $error->getMessage(), $job_id, null, array(
                'attempt' => $attempt,
                'kind'    => $error instanceof ApiException ? $error->kind() : 'unknown',
            ) );
            return;
        }
        $logs->fail_job( $job_id, 'retry_scheduled', $error->getMessage(), $next );
        $logs->record( 'warning', 'retry_scheduled', $error->getMessage(), $job_id, null, array(
            'attempt'        => $attempt,
            'next_attempt_at'=> gmdate( 'c', $next ),
            'kind'           => $error instanceof ApiException ? $error->kind() : 'unknown',
        ) );
    }
}