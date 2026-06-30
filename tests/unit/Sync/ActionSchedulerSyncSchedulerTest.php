<?php
/**
 * Unit tests for ActionSchedulerSyncScheduler.
 *
 * The AS adapter has a single test seam: the constructor takes an
 * optional `ActionSchedulerInvoker` (a callable) that the production
 * code uses to dispatch every AS function call. Tests pass a
 * recording invoker instead of trying to shim the global AS
 * functions (which Brain\Monkey cannot do across namespaces).
 *
 * The `function_exists()` check that gates the AS-vs-WP-Cron path is
 * controlled per-test by shimming Brain\Monkey's
 * `Functions\when('function_exists')` to return true or false for
 * `as_*` names.
 *
 * @covers \VectorYT\Gallery\Sync\ActionSchedulerSyncScheduler
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Sync;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Sync\ActionSchedulerSyncScheduler;
use VectorYT\Gallery\Sync\SyncScheduler;

final class ActionSchedulerSyncSchedulerTest extends TestCase
{
    /** @var array<int,array{0:string,1:array<int,mixed>}> */
    private array $as_calls = array();

    /** @var mixed */
    private $as_default_return = 1;

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        $this->as_calls         = array();
        $this->as_default_return = 1;
        // Default: AS is "available" (shimmed true). Tests that need
        // the WP-Cron path override this.
        $this->shim_function_exists(true);
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_implements_sync_scheduler_contract(): void {
        $scheduler = new ActionSchedulerSyncScheduler( new RecordingSyncScheduler() );
        $this->assertInstanceOf( SyncScheduler::class, $scheduler );
    }

    public function test_backend_reports_wp_cron_when_as_unavailable(): void {
        $this->shim_function_exists(false);
        $scheduler = new ActionSchedulerSyncScheduler( new RecordingSyncScheduler() );
        $this->assertSame( 'wp_cron', $scheduler->backend() );
        $this->assertFalse( $scheduler->action_scheduler_available() );
    }

    public function test_backend_reports_action_scheduler_when_shimmed_in(): void {
        // Default in setUp: shim returns true.
        $scheduler = new ActionSchedulerSyncScheduler( new RecordingSyncScheduler() );
        $this->assertSame( 'action_scheduler', $scheduler->backend() );
        $this->assertTrue( $scheduler->action_scheduler_available() );
    }

    public function test_schedule_once_routes_to_fallback_when_as_absent(): void {
        $this->shim_function_exists(false);

        $fallback  = new RecordingSyncScheduler();
        $scheduler = new ActionSchedulerSyncScheduler( $fallback );

        $result = $scheduler->schedule_once( 'vyg_test_hook', array( 'vyg_job_id' => 7 ), time() + 30 );
        $this->assertTrue( $result );
        $this->assertCount( 1, $fallback->once_calls );
        $this->assertSame( 'vyg_test_hook', $fallback->once_calls[0]['hook'] );
        $this->assertSame( array( 'vyg_job_id' => 7 ), $fallback->once_calls[0]['args'] );
        $this->assertNotNull( $fallback->once_calls[0]['when'] );
    }

    public function test_schedule_recurring_routes_to_fallback_when_as_absent(): void {
        $this->shim_function_exists(false);

        $fallback  = new RecordingSyncScheduler();
        $scheduler = new ActionSchedulerSyncScheduler( $fallback );

        $result = $scheduler->schedule_recurring( 'vyg_recurring', array(), 3600 );
        $this->assertTrue( $result );
        $this->assertCount( 1, $fallback->recurring_calls );
        $this->assertSame( 'vyg_recurring', $fallback->recurring_calls[0]['hook'] );
        $this->assertSame( 3600, $fallback->recurring_calls[0]['interval'] );
    }

    public function test_schedule_once_dispatches_to_invoker_when_as_available(): void {
        $invoker  = $this->recordingInvoker();
        $fallback = new RecordingSyncScheduler();
        $scheduler = new ActionSchedulerSyncScheduler( $fallback, $invoker );

        $result = $scheduler->schedule_once( 'vyg_as_hook', array( 'vyg_job_id' => 11 ), time() + 60 );
        $this->assertTrue( $result );
        $this->assertCount( 0, $fallback->once_calls, 'Fallback should NOT receive the call when AS is available.' );

        $this->assertCount( 1, $invoker->calls );
        $this->assertSame( 'as_schedule_single_action', $invoker->calls[0][0] );
        $this->assertCount( 4, $invoker->calls[0][1] );
        $this->assertSame( 'vyg_as_hook', $invoker->calls[0][1][1] );     // hook arg
        $this->assertSame( 'vyg', $invoker->calls[0][1][3] );              // group arg
        $this->assertSame( array( 'args' => array( 'vyg_job_id' => 11 ) ), $invoker->calls[0][1][2] );
    }

    public function test_schedule_recurring_dispatches_to_invoker_with_interval(): void {
        $invoker  = $this->recordingInvoker();
        $scheduler = new ActionSchedulerSyncScheduler( new RecordingSyncScheduler(), $invoker );

        $result = $scheduler->schedule_recurring( 'vyg_as_recurring', array( 'k' => 'v' ), 600 );
        $this->assertTrue( $result );

        $this->assertCount( 1, $invoker->calls );
        $this->assertSame( 'as_schedule_recurring_action', $invoker->calls[0][0] );
        $this->assertSame( 'vyg_as_recurring', $invoker->calls[0][1][2] );
        $this->assertSame( 600, $invoker->calls[0][1][1] );              // interval arg
        $this->assertSame( array( 'args' => array( 'k' => 'v' ) ), $invoker->calls[0][1][3] );
        $this->assertSame( 'vyg', $invoker->calls[0][1][4] );              // group arg
    }

    public function test_schedule_once_returns_false_when_invoker_returns_false(): void {
        $invoker = $this->recordingInvoker();
        $invoker->default_return = false;
        $scheduler = new ActionSchedulerSyncScheduler( new RecordingSyncScheduler(), $invoker );

        $result = $scheduler->schedule_once( 'vyg_hook', array(), time() + 1 );
        $this->assertFalse( $result );
    }

    public function test_schedule_once_returns_false_when_invoker_returns_null(): void {
        $invoker = $this->recordingInvoker();
        $invoker->default_return = null;
        $scheduler = new ActionSchedulerSyncScheduler( new RecordingSyncScheduler(), $invoker );

        $result = $scheduler->schedule_once( 'vyg_hook', array(), time() + 1 );
        $this->assertFalse( $result );
    }

    public function test_schedule_recurring_returns_false_when_invoker_returns_false(): void {
        $invoker = $this->recordingInvoker();
        $invoker->default_return = false;
        $scheduler = new ActionSchedulerSyncScheduler( new RecordingSyncScheduler(), $invoker );

        $result = $scheduler->schedule_recurring( 'vyg_hook', array(), 3600 );
        $this->assertFalse( $result );
    }

    public function test_unschedule_recurring_falls_back_when_as_absent(): void {
        $this->shim_function_exists(false);

        $fallback  = new RecordingSyncScheduler();
        $scheduler = new ActionSchedulerSyncScheduler( $fallback );

        $result = $scheduler->unschedule_recurring( 'vyg_recurring', array( 'k' => 'v' ) );
        $this->assertTrue( $result );
        $this->assertCount( 1, $fallback->unschedule_calls );
        $this->assertSame( 'vyg_recurring', $fallback->unschedule_calls[0]['hook'] );
    }

    public function test_unschedule_recurring_with_invoker_returns_false_when_no_pending(): void {
        $invoker = $this->recordingInvoker();
        // The as_get_scheduled_actions lookup returns empty → no pending.
        $invoker->set_return_for( 'as_get_scheduled_actions', array() );

        $scheduler = new ActionSchedulerSyncScheduler( new RecordingSyncScheduler(), $invoker );
        $result = $scheduler->unschedule_recurring( 'vyg_recurring', array() );
        $this->assertFalse( $result );
    }

    public function test_unschedule_recurring_with_invoker_succeeds_when_action_found(): void {
        $invoker = $this->recordingInvoker();
        $invoker->set_return_for( 'as_get_scheduled_actions', array( 42 ) );
        $invoker->set_return_for( 'as_unschedule_action', 1 );

        $scheduler = new ActionSchedulerSyncScheduler( new RecordingSyncScheduler(), $invoker );
        $result = $scheduler->unschedule_recurring( 'vyg_recurring', array( 'k' => 'v' ) );
        $this->assertTrue( $result );

        $unsched_call = $this->find_call( $invoker, 'as_unschedule_action' );
        $this->assertNotNull( $unsched_call, 'as_unschedule_action was not invoked.' );
    }

    public function test_unschedule_all_falls_back_to_fallback_scheduler(): void {
        $this->shim_function_exists(false);

        $fallback  = new RecordingSyncScheduler();
        $scheduler = new ActionSchedulerSyncScheduler( $fallback );

        $scheduler->unschedule_all( 'vyg_test_hook', array( 'k' => 'v' ) );
        $this->assertCount( 1, $fallback->unschedule_all_calls );
        $this->assertSame( 'vyg_test_hook', $fallback->unschedule_all_calls[0]['hook'] );
    }

    public function test_unschedule_all_with_invoker_uses_as_unschedule_all_actions_when_available(): void {
        // Shim as_unschedule_all_actions to also be "available".
        $this->shim_function_exists(true, true);

        $invoker = $this->recordingInvoker();
        $invoker->set_return_for( 'as_unschedule_all_actions', 3 );

        $scheduler = new ActionSchedulerSyncScheduler( new RecordingSyncScheduler(), $invoker );
        $removed = $scheduler->unschedule_all( 'vyg_test_hook', array() );
        $this->assertSame( 1, $removed );
        $this->assertNotNull( $this->find_call( $invoker, 'as_unschedule_all_actions' ) );
    }

    public function test_unschedule_all_with_invoker_falls_back_to_unschedule_action(): void {
        // as_unschedule_all_actions is NOT available → fall back to as_unschedule_action.
        $this->shim_function_exists(true, false);

        $invoker = $this->recordingInvoker();
        $scheduler = new ActionSchedulerSyncScheduler( new RecordingSyncScheduler(), $invoker );
        $removed = $scheduler->unschedule_all( 'vyg_test_hook', array() );
        $this->assertSame( 1, $removed );
        $this->assertNotNull( $this->find_call( $invoker, 'as_unschedule_action' ) );
    }

    public function test_constructor_without_fallback_uses_default_wp_cron(): void {
        $this->shim_function_exists(false);

        $scheduler = new ActionSchedulerSyncScheduler();
        $this->assertSame( 'wp_cron', $scheduler->backend() );
        $this->assertFalse( $scheduler->action_scheduler_available() );
    }

    /**
     * Shim `function_exists()` to return $available for `as_*` names.
     * If $as_unschedule_all is provided, controls that function's
     * availability separately (used by unschedule_all tests).
     */
    private function shim_function_exists(bool $available, ?bool $as_unschedule_all = null): void {
        $as_unschedule_all = $as_unschedule_all ?? $available;
        Functions\when( 'function_exists' )->alias( static function ( string $name ) use ( $available, $as_unschedule_all ): bool {
            if ( 'as_unschedule_all_actions' === $name ) {
                return $as_unschedule_all;
            }
            if ( str_starts_with( $name, 'as_' ) ) {
                return $available;
            }
            return \function_exists( $name );
        } );
    }

    private function recordingInvoker(): object {
        $default = $this->as_default_return;
        return new class( $default ) {
            /** @var array<int,array{0:string,1:array<int,mixed>}> */
            public array $calls = array();
            public mixed $default_return = 1;
            /** @var array<string,mixed> */
            public array $returns = array();

            public function __construct( mixed $default ) {
                $this->default_return = $default;
            }

            public function set_return_for( string $function, mixed $value ): void {
                $this->returns[ $function ] = $value;
            }

            public function __invoke( string $function, array $args ): mixed {
                $this->calls[] = array( $function, $args );
                if ( array_key_exists( $function, $this->returns ) ) {
                    return $this->returns[ $function ];
                }
                return $this->default_return;
            }
        };
    }

    private function find_call( object $invoker, string $name ): ?array {
        foreach ( $invoker->calls as $call ) {
            if ( $call[0] === $name ) {
                return $call;
            }
        }
        return null;
    }
}

/**
 * Test double for SyncScheduler that records every call.
 */
final class RecordingSyncScheduler implements SyncScheduler {
    /** @var array<int,array{hook:string,args:array,when:?int}> */
    public array $once_calls = array();
    /** @var array<int,array{hook:string,args:array,interval:int}> */
    public array $recurring_calls = array();
    /** @var array<int,array{hook:string,args:array}> */
    public array $unschedule_calls = array();
    /** @var array<int,array{hook:string,args:array}> */
    public array $unschedule_all_calls = array();

    public function schedule_once( string $hook, array $args, ?int $when = null ): bool {
        $this->once_calls[] = array( 'hook' => $hook, 'args' => $args, 'when' => $when );
        return true;
    }

    public function schedule_recurring( string $hook, array $args, int $interval_seconds ): bool {
        $this->recurring_calls[] = array( 'hook' => $hook, 'args' => $args, 'interval' => $interval_seconds );
        return true;
    }

    public function unschedule_recurring( string $hook, array $args ): bool {
        $this->unschedule_calls[] = array( 'hook' => $hook, 'args' => $args );
        return true;
    }

    public function unschedule_all( string $hook, array $args_subset = array() ): int {
        $this->unschedule_all_calls[] = array( 'hook' => $hook, 'args' => $args_subset );
        return 0;
    }
}
