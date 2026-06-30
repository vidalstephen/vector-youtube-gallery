<?php
/**
 * Unit tests for SchedulerResolver.
 *
 * The resolver decides which SyncScheduler implementation to return
 * based on the configured mode + the availability of Action
 * Scheduler. The chain is:
 *   1. CLI / env override (constant VYG_SYNC_SCHEDULER).
 *   2. Settings key sync_scheduler_mode.
 *   3. Default `auto`.
 *
 * The "auto" mode picks AS when available, else WP-Cron. Explicit
 * modes (`wp_cron`, `action_scheduler`) ignore availability but the
 * resolver still reports a misconfiguration warning when AS was
 * forced but the library is not loaded.
 *
 * @covers \VectorYT\Gallery\Sync\SchedulerResolver
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Sync;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Settings\SettingsRepository;
use VectorYT\Gallery\Sync\ActionSchedulerSyncScheduler;
use VectorYT\Gallery\Sync\SchedulerResolver;
use VectorYT\Gallery\Sync\SyncScheduler;
use VectorYT\Gallery\Sync\WpCronSyncScheduler;
use VectorYT\Gallery\Tests\Support\BrainHelpers;
use VectorYT\Gallery\Tests\Support\OptionsBag;

final class SchedulerResolverTest extends TestCase
{
    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        OptionsBag::reset();
        BrainHelpers::stubOptionFunctions();
        BrainHelpers::stubEscapeFunctions();
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_default_mode_is_auto(): void {
        // No setting stored, no constant.
        $resolver = new SchedulerResolver( new SettingsRepository() );
        $this->assertSame( 'auto', $resolver->resolve_mode() );
    }

    public function test_auto_with_as_available_returns_as_scheduler(): void {
        $this->shim_function_exists(true);
        $resolver = new SchedulerResolver( new SettingsRepository() );
        $scheduler = $resolver->resolve();
        $this->assertInstanceOf( ActionSchedulerSyncScheduler::class, $scheduler );
        $this->assertSame( 'action_scheduler', $resolver->effective_backend() );
    }

    public function test_auto_with_as_unavailable_returns_wp_cron(): void {
        $this->shim_function_exists(false);
        $resolver = new SchedulerResolver( new SettingsRepository() );
        $scheduler = $resolver->resolve();
        $this->assertInstanceOf( WpCronSyncScheduler::class, $scheduler );
        $this->assertSame( 'wp_cron', $resolver->effective_backend() );
    }

    public function test_explicit_wp_cron_mode_returns_wp_cron_even_if_as_available(): void {
        $this->shim_function_exists(true);
        $settings = new SettingsRepository();
        $settings->set( 'sync_scheduler_mode', 'wp_cron' );
        $resolver = new SchedulerResolver( $settings );
        $this->assertSame( 'wp_cron', $resolver->resolve_mode() );
        $scheduler = $resolver->resolve();
        $this->assertInstanceOf( WpCronSyncScheduler::class, $scheduler );
        $this->assertSame( 'wp_cron', $resolver->effective_backend() );
    }

    public function test_explicit_action_scheduler_mode_returns_as_scheduler(): void {
        $this->shim_function_exists(true);
        $settings = new SettingsRepository();
        $settings->set( 'sync_scheduler_mode', 'action_scheduler' );
        $resolver = new SchedulerResolver( $settings );
        $this->assertSame( 'action_scheduler', $resolver->resolve_mode() );
        $scheduler = $resolver->resolve();
        $this->assertInstanceOf( ActionSchedulerSyncScheduler::class, $scheduler );
        $this->assertSame( 'action_scheduler', $resolver->effective_backend() );
    }

    public function test_explicit_action_scheduler_with_no_library_is_misconfiguration(): void {
        $this->shim_function_exists(false);
        $settings = new SettingsRepository();
        $settings->set( 'sync_scheduler_mode', 'action_scheduler' );
        $resolver = new SchedulerResolver( $settings );
        $this->assertSame( 'action_scheduler', $resolver->resolve_mode() );
        $this->assertTrue( $resolver->has_misconfiguration() );
        $this->assertSame( 'wp_cron', $resolver->effective_backend() );
    }

    public function test_invalid_mode_in_settings_falls_back_to_auto(): void {
        $settings = new SettingsRepository();
        $settings->set( 'sync_scheduler_mode', 'nonsense_value' );
        $resolver = new SchedulerResolver( $settings );
        $this->assertSame( 'auto', $resolver->resolve_mode() );
    }

    public function test_resolver_accepts_injected_as_invoker(): void {
        $this->shim_function_exists(true);
        $invoker = new RecordingInvoker();
        $as = new ActionSchedulerSyncScheduler( null, $invoker );
        $resolver = new SchedulerResolver( new SettingsRepository(), $as );
        $scheduler = $resolver->resolve();
        $this->assertSame( $as, $scheduler );
    }

    public function test_resolver_constructor_without_injected_as_creates_default(): void {
        $this->shim_function_exists(true);
        $resolver = new SchedulerResolver( new SettingsRepository() );
        $scheduler = $resolver->resolve();
        $this->assertInstanceOf( ActionSchedulerSyncScheduler::class, $scheduler );
    }

    public function test_resolver_returns_fresh_instance_each_call(): void {
        // The resolver hands out a new instance every call, so a
        // resolver with a misconfigured state does not poison later
        // calls after the mode is changed.
        $this->shim_function_exists(true);
        $settings = new SettingsRepository();
        $resolver = new SchedulerResolver( $settings );

        $first = $resolver->resolve();
        $settings->set( 'sync_scheduler_mode', 'wp_cron' );
        $second = $resolver->resolve();

        $this->assertInstanceOf( ActionSchedulerSyncScheduler::class, $first );
        $this->assertInstanceOf( WpCronSyncScheduler::class, $second );
    }

    public function test_effective_backend_for_non_as_scheduler(): void {
        $this->shim_function_exists(true);
        $settings = new SettingsRepository();
        $settings->set( 'sync_scheduler_mode', 'wp_cron' );
        $resolver = new SchedulerResolver( $settings );
        $this->assertSame( 'wp_cron', $resolver->effective_backend() );
    }

    public function test_misconfiguration_is_false_when_mode_is_auto_and_no_as(): void {
        $this->shim_function_exists(false);
        $resolver = new SchedulerResolver( new SettingsRepository() );
        $this->assertFalse( $resolver->has_misconfiguration() );
    }

    public function test_misconfiguration_is_false_when_mode_is_auto_and_as_available(): void {
        $this->shim_function_exists(true);
        $resolver = new SchedulerResolver( new SettingsRepository() );
        $this->assertFalse( $resolver->has_misconfiguration() );
    }

    private function shim_function_exists(bool $as_available): void {
        Functions\when( 'function_exists' )->alias( static function ( string $name ) use ( $as_available ): bool {
            if ( str_starts_with( $name, 'as_' ) ) {
                return $as_available;
            }
            return \function_exists( $name );
        } );
    }
}

/**
 * Minimal recording invoker used by SchedulerResolverTest::test_resolver_accepts_injected_as_invoker.
 */
final class RecordingInvoker {
    /** @var array<int,array{0:string,1:array<int,mixed>}> */
    public array $calls = array();
    public function __invoke(string $function, array $args): mixed {
        $this->calls[] = array( $function, $args );
        return 1;
    }
}
