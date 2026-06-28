<?php
/**
 * Unit tests for RetryPolicy.
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Sync;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Logging\Logger;
use VectorYT\Gallery\Repository\SyncLogRepository;
use VectorYT\Gallery\Sync\RetryPolicy;
use VectorYT\Gallery\YouTube\ApiException;

/**
 * @covers \VectorYT\Gallery\Sync\RetryPolicy
 */
final class RetryPolicyTest extends TestCase {

    private RetryPolicy $policy;

    protected function setUp(): void {
        parent::setUp();
        $this->policy = new RetryPolicy();
    }

    public function test_first_retry_is_5_minutes(): void {
        $err = new ApiException( 'transient', ApiException::KIND_TRANSIENT );
        $next = $this->policy->next_attempt_at( $err, 1 );
        $this->assertNotNull( $next );
        $expected = time() + 5 * MINUTE_IN_SECONDS;
        $this->assertEqualsWithDelta( $expected, $next, 2 );
    }

    public function test_full_ladder(): void {
        $err = new ApiException( 'transient', ApiException::KIND_TRANSIENT );
        $expected = array(
            1 => 5 * MINUTE_IN_SECONDS,
            2 => 15 * MINUTE_IN_SECONDS,
            3 => HOUR_IN_SECONDS,
            4 => 6 * HOUR_IN_SECONDS,
            5 => 24 * HOUR_IN_SECONDS,
        );
        foreach ( $expected as $attempt => $delay ) {
            $next = $this->policy->next_attempt_at( $err, $attempt );
            $this->assertNotNull( $next, "attempt $attempt should return a next time" );
            $this->assertEqualsWithDelta( time() + $delay, $next, 2 );
        }
    }

    public function test_hard_stop_returns_null(): void {
        $auth    = new ApiException( 'bad key', ApiException::KIND_AUTH );
        $quota   = new ApiException( 'quota',   ApiException::KIND_QUOTA );
        $forbid  = new ApiException( 'forbidden', ApiException::KIND_FORBIDDEN );
        $nf      = new ApiException( 'nf',       ApiException::KIND_NOT_FOUND );
        $badreq  = new ApiException( 'badreq',   ApiException::KIND_BAD_REQUEST );

        foreach ( array( $auth, $quota, $forbid, $nf, $badreq ) as $err ) {
            $this->assertNull( $this->policy->next_attempt_at( $err, 1 ), get_class( $err ) );
        }
    }

    public function test_attempt_6_stops(): void {
        $err = new ApiException( 'transient', ApiException::KIND_TRANSIENT );
        $this->assertNull( $this->policy->next_attempt_at( $err, 6 ) );
    }

    public function test_rate_limit_uses_shorter_ladder(): void {
        $err = new ApiException( 'slow down', ApiException::KIND_RATE_LIMIT );
        $next1 = $this->policy->next_attempt_at( $err, 1 );
        $next2 = $this->policy->next_attempt_at( $err, 2 );
        $next3 = $this->policy->next_attempt_at( $err, 3 );
        $this->assertNotNull( $next1 );
        $this->assertNotNull( $next2 );
        $this->assertNotNull( $next3 );

        $this->assertEqualsWithDelta( time() + MINUTE_IN_SECONDS, $next1, 2 );
        $this->assertEqualsWithDelta( time() + 5 * MINUTE_IN_SECONDS, $next2, 2 );
        $this->assertEqualsWithDelta( time() + 15 * MINUTE_IN_SECONDS, $next3, 2 );
    }

    public function test_schedule_retry_writes_to_logs(): void {
        // Build a fake SyncLogRepository that records the calls we expect.
        $repo = $this->createMock( SyncLogRepository::class );
        $repo->expects( $this->once() )
             ->method( 'fail_job' )
             ->with(
                 $this->equalTo( 42 ),
                 $this->equalTo( 'retry_scheduled' ),
                 $this->isType( 'string' ),
                 $this->isType( 'integer' )
             );
        $repo->expects( $this->once() )
             ->method( 'record' )
             ->with(
                 $this->equalTo( 'warning' ),
                 $this->equalTo( 'retry_scheduled' ),
                 $this->isType( 'string' ),
                 $this->equalTo( 42 ),
                 $this->isNull(),
                 $this->callback( function ( array $ctx ): bool {
                     return isset( $ctx['attempt'] ) && 1 === $ctx['attempt'] && isset( $ctx['next_attempt_at'] );
                 } )
             );

        $err = new ApiException( 'transient', ApiException::KIND_TRANSIENT );
        $this->policy->schedule_retry( $err, 1, 42, $repo );
    }

    public function test_schedule_retry_hard_stop_does_not_retry(): void {
        $repo = $this->createMock( SyncLogRepository::class );
        $repo->expects( $this->once() )
             ->method( 'fail_job' )
             ->with(
                 $this->equalTo( 42 ),
                 $this->equalTo( 'hard_stop' ),
                 $this->isType( 'string' ),
                 $this->isNull()
             );
        $repo->expects( $this->once() )
             ->method( 'record' )
             ->with(
                 $this->equalTo( 'error' ),
                 $this->equalTo( 'retry_exhausted' ),
                 $this->isType( 'string' ),
                 $this->equalTo( 42 ),
                 $this->isNull(),
                 $this->callback( function ( array $ctx ): bool {
                     return isset( $ctx['kind'] ) && 'auth' === $ctx['kind'];
                 } )
             );

        $err = new ApiException( 'bad key', ApiException::KIND_AUTH );
        $this->policy->schedule_retry( $err, 1, 42, $repo );
    }
}