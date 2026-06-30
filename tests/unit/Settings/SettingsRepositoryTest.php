<?php
/**
 * Unit tests for SettingsRepository.
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Settings\SettingsRepository;
use VectorYT\Gallery\Tests\Support\BrainHelpers;
use VectorYT\Gallery\Tests\Support\OptionsBag;

/**
 * @covers \VectorYT\Gallery\Settings\SettingsRepository
 */
final class SettingsRepositoryTest extends TestCase {

    private SettingsRepository $repo;

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        OptionsBag::reset();
        BrainHelpers::stubOptionFunctions();
        BrainHelpers::stubEscapeFunctions();
        $this->repo = new SettingsRepository();
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_defaults_when_nothing_stored(): void {
        $values = $this->repo->all();
        $this->assertSame( 60, $values['shorts_max_duration_seconds'] );
        $this->assertSame( 180, $values['short_candidate_max_duration'] );
        $this->assertSame( 300, $values['live_poll_interval_seconds'] );
        $this->assertSame( 30, $values['data_refresh_interval_days'] );
        $this->assertSame( 90, $values['data_ttl_days'] );
        $this->assertSame( 365, $values['data_hard_delete_after_days'] );
        $this->assertTrue( $values['auto_classify_shorts'] );
        $this->assertTrue( $values['auto_classify_live'] );
        $this->assertTrue( $values['respect_manual_overrides'] );
        $this->assertSame( 'api_key', $values['api_mode'] );
    }

    public function test_get_with_default(): void {
        $this->assertSame( 60, $this->repo->get( 'shorts_max_duration_seconds' ) );
        $this->assertSame( 'fallback', $this->repo->get( 'unknown_key', 'fallback' ) );
    }

    public function test_set_persists_value(): void {
        $this->repo->set( 'shorts_max_duration_seconds', 45 );
        $this->assertSame( 45, $this->repo->get( 'shorts_max_duration_seconds' ) );
        $this->assertSame( 45, OptionsBag::get( 'vyg_settings' )['shorts_max_duration_seconds'] );
    }

    public function test_save_posted_coerces_integers(): void {
        $this->repo->save_posted( array(
            'shorts_max_duration_seconds'  => '90',
            'live_poll_interval_seconds'   => '120',
        ) );
        $this->assertSame( 90, $this->repo->get( 'shorts_max_duration_seconds' ) );
        $this->assertSame( 120, $this->repo->get( 'live_poll_interval_seconds' ) );
    }

    public function test_save_posted_coerces_booleans(): void {
        $this->repo->save_posted( array(
            'auto_classify_shorts' => '1',
            'auto_classify_live'   => '1',
            // respect_manual_overrides intentionally omitted → false
        ) );
        $this->assertTrue( $this->repo->get( 'auto_classify_shorts' ) );
        $this->assertTrue( $this->repo->get( 'auto_classify_live' ) );
        $this->assertFalse( $this->repo->get( 'respect_manual_overrides' ) );
    }

    public function test_save_posted_drops_unknown_keys(): void {
        $this->repo->save_posted( array(
            'shorts_max_duration_seconds' => 90,
            'injection_attempt'           => '<script>alert(1)</script>',
        ) );
        $this->assertSame( 90, $this->repo->get( 'shorts_max_duration_seconds' ) );
        $this->assertNull( $this->repo->get( 'injection_attempt' ) );
    }

    public function test_save_posted_negative_integers_clamp_to_zero(): void {
        $this->repo->save_posted( array(
            'shorts_max_duration_seconds' => '-5',
        ) );
        $this->assertSame( 0, $this->repo->get( 'shorts_max_duration_seconds' ) );
    }

    public function test_save_posted_accepts_known_api_mode(): void {
        $this->repo->save_posted( array( 'api_mode' => 'oauth' ) );
        $this->assertSame( 'oauth', $this->repo->get( 'api_mode' ) );
    }

    public function test_save_posted_rejects_unknown_api_mode(): void {
        $this->repo->save_posted( array( 'api_mode' => 'not-real' ) );
        $this->assertSame( 'api_key', $this->repo->get( 'api_mode' ) );
    }

    public function test_reset_defaults(): void {
        $this->repo->set( 'shorts_max_duration_seconds', 90 );
        $this->assertSame( 90, $this->repo->get( 'shorts_max_duration_seconds' ) );
        $this->repo->reset_defaults();
        $this->assertSame( 60, $this->repo->get( 'shorts_max_duration_seconds' ) );
    }

    // --- Phase 12.2: sync scheduler mode ---

    public function test_phase12_sync_scheduler_mode_defaults_to_auto(): void {
        $this->assertSame( 'auto', $this->repo->get( 'sync_scheduler_mode' ) );
    }

    public function test_phase12_sync_scheduler_mode_accepts_known_values(): void {
        foreach ( array( 'auto', 'wp_cron', 'action_scheduler' ) as $mode ) {
            $this->repo->save_posted( array( 'sync_scheduler_mode' => $mode ) );
            $this->assertSame( $mode, $this->repo->get( 'sync_scheduler_mode' ) );
        }
    }

    public function test_phase12_sync_scheduler_mode_rejects_unknown_value(): void {
        $this->repo->save_posted( array( 'sync_scheduler_mode' => 'nonsense' ) );
        $this->assertSame( 'auto', $this->repo->get( 'sync_scheduler_mode' ) );
    }

    // --- Phase 12.3: cache ---

    public function test_phase12_cache_enabled_defaults_true(): void {
        $this->assertTrue( $this->repo->get( 'cache_enabled' ) );
    }

    public function test_phase12_cache_ttl_seconds_defaults_3600(): void {
        $this->assertSame( 3600, $this->repo->get( 'cache_ttl_seconds' ) );
    }

    public function test_phase12_cache_ttl_seconds_coerces_string_to_int(): void {
        $this->repo->save_posted( array( 'cache_ttl_seconds' => '1800' ) );
        $this->assertSame( 1800, $this->repo->get( 'cache_ttl_seconds' ) );
    }

    public function test_phase12_cache_ttl_seconds_negative_clamps_to_zero(): void {
        $this->repo->save_posted( array( 'cache_ttl_seconds' => '-1' ) );
        $this->assertSame( 0, $this->repo->get( 'cache_ttl_seconds' ) );
    }

    public function test_phase12_cache_enabled_can_be_disabled(): void {
        $this->repo->save_posted( array( 'cache_enabled' => '0' ) );
        $this->assertFalse( $this->repo->get( 'cache_enabled' ) );
    }

    // --- Phase 12.5: log level + rotation ---

    public function test_phase12_log_level_defaults_to_info(): void {
        $this->assertSame( 'info', $this->repo->get( 'log_level' ) );
    }

    public function test_phase12_log_level_accepts_known_values(): void {
        foreach ( array( 'debug', 'info', 'warning', 'error' ) as $level ) {
            $this->repo->save_posted( array( 'log_level' => $level ) );
            $this->assertSame( $level, $this->repo->get( 'log_level' ) );
        }
    }

    public function test_phase12_log_level_rejects_unknown_value(): void {
        $this->repo->save_posted( array( 'log_level' => 'verbose' ) );
        $this->assertSame( 'info', $this->repo->get( 'log_level' ) );
    }

    public function test_phase12_log_max_size_mb_defaults_5(): void {
        $this->assertSame( 5, $this->repo->get( 'log_max_size_mb' ) );
    }

    public function test_phase12_log_max_files_defaults_5(): void {
        $this->assertSame( 5, $this->repo->get( 'log_max_files' ) );
    }

    public function test_phase12_log_max_size_mb_coerces_string_to_int(): void {
        $this->repo->save_posted( array( 'log_max_size_mb' => '12' ) );
        $this->assertSame( 12, $this->repo->get( 'log_max_size_mb' ) );
    }

    public function test_phase12_log_max_files_coerces_string_to_int(): void {
        $this->repo->save_posted( array( 'log_max_files' => '8' ) );
        $this->assertSame( 8, $this->repo->get( 'log_max_files' ) );
    }

    public function test_phase12_log_max_size_mb_negative_clamps_to_zero(): void {
        $this->repo->save_posted( array( 'log_max_size_mb' => '-5' ) );
        $this->assertSame( 0, $this->repo->get( 'log_max_size_mb' ) );
    }
}