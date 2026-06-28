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

    public function test_reset_defaults(): void {
        $this->repo->set( 'shorts_max_duration_seconds', 90 );
        $this->assertSame( 90, $this->repo->get( 'shorts_max_duration_seconds' ) );
        $this->repo->reset_defaults();
        $this->assertSame( 60, $this->repo->get( 'shorts_max_duration_seconds' ) );
    }
}