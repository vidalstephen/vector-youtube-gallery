<?php
/**
 * Unit tests for the Logger level filter + sink dispatch.
 *
 * The Phase 12.5 hardening adds:
 *   - A configurable minimum log level (drops entries below it).
 *   - An extensible list of `LogSink` closures that receive every
 *     post-filter entry (e.g. for shipping to a centralized store).
 *   - A guarantee that a misbehaving sink never breaks the main
 *     write path.
 *
 * The file write itself goes through `error_log`; we shim it
 * through Brain\Monkey so the test does not pollute the real log
 * file.
 *
 * @covers \VectorYT\Gallery\Logging\Logger
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Logging;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Logging\Logger;

final class LoggerTest extends TestCase
{
    /** @var array<int,string> */
    private array $error_log_calls = array();

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        $this->error_log_calls = array();
        Functions\when( 'wp_json_encode' )->alias(
            static function ( $value, $options = 0, $depth = 512 ) {
                return json_encode( $value, $options, $depth );
            }
        );
        Functions\when( 'error_log' )->alias( function ( $message ) {
            $this->error_log_calls[] = (string) $message;
        } );
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_default_level_is_unset_so_every_entry_passes(): void {
        $logger = new Logger();
        $this->assertNull( $logger->min_level() );
        $this->assertTrue( $logger->is_enabled( Logger::LEVEL_INFO ) );
        $this->assertTrue( $logger->is_enabled( Logger::LEVEL_ERROR ) );
    }

    public function test_set_min_level_filters_by_priority(): void {
        $logger = new Logger();
        $logger->set_min_level( Logger::LEVEL_WARNING );
        $this->assertTrue( $logger->is_enabled( Logger::LEVEL_WARNING ) );
        $this->assertTrue( $logger->is_enabled( Logger::LEVEL_ERROR ) );
        $this->assertFalse( $logger->is_enabled( Logger::LEVEL_INFO ) );
        $this->assertFalse( $logger->is_enabled( Logger::LEVEL_DEBUG ) );
    }

    public function test_set_min_level_rejects_unknown_level(): void {
        $logger = new Logger();
        $logger->set_min_level( 'nonsense' );
        $this->assertNull( $logger->min_level() );
    }

    public function test_set_min_level_to_empty_string_disables_filter(): void {
        $logger = new Logger();
        $logger->set_min_level( Logger::LEVEL_ERROR );
        $logger->set_min_level( '' );
        $this->assertNull( $logger->min_level() );
    }

    public function test_info_entry_written_by_default(): void {
        $logger = new Logger();
        $logger->info( 'hello' );
        $this->assertCount( 1, $this->error_log_calls );
    }

    public function test_info_entry_dropped_when_min_is_warning(): void {
        $logger = new Logger();
        $logger->set_min_level( Logger::LEVEL_WARNING );
        $logger->info( 'should be dropped' );
        $logger->warning( 'should be kept' );
        $this->assertCount( 1, $this->error_log_calls );
        $this->assertStringContainsString( 'should be kept', $this->error_log_calls[0] );
    }

    public function test_sink_receives_structured_entry(): void {
        $logger = new Logger();
        $captured = array();
        $logger->add_sink( function ( array $entry ) use ( &$captured ): void {
            $captured[] = $entry;
        } );
        $logger->info( 'payload test', array( 'k' => 'v' ) );
        $this->assertCount( 1, $captured );
        $this->assertSame( 'info', $captured[0]['level'] );
        $this->assertSame( 'payload test', $captured[0]['message'] );
        $this->assertSame( array( 'k' => 'v' ), $captured[0]['context'] );
    }

    public function test_sink_does_not_receive_filtered_out_entry(): void {
        $logger = new Logger();
        $logger->set_min_level( Logger::LEVEL_ERROR );
        $captured = array();
        $logger->add_sink( function ( array $entry ) use ( &$captured ): void {
            $captured[] = $entry;
        } );
        $logger->info( 'dropped' );
        $this->assertCount( 0, $captured );
    }

    public function test_misbehaving_sink_does_not_break_logging(): void {
        $logger = new Logger();
        $logger->add_sink( static function (): void {
            throw new \RuntimeException( 'sink broken' );
        } );
        $captured = array();
        $logger->add_sink( function ( array $entry ) use ( &$captured ): void {
            $captured[] = $entry;
        } );
        $logger->info( 'still writes' );
        // error_log_calls contains the original entry + the sink-error
        // fallback message. We assert that the FIRST entry is the
        // payload, which proves the main write path survived.
        $this->assertGreaterThanOrEqual( 1, count( $this->error_log_calls ) );
        $this->assertStringContainsString( 'still writes', $this->error_log_calls[0] );
        // The good sink still ran after the throwing one.
        $this->assertCount( 1, $captured, 'Subsequent sinks still ran.' );
    }

    public function test_level_priority_ordering(): void {
        $this->assertLessThan( Logger::LEVEL_PRIORITY[ Logger::LEVEL_INFO ],    Logger::LEVEL_PRIORITY[ Logger::LEVEL_DEBUG ] );
        $this->assertLessThan( Logger::LEVEL_PRIORITY[ Logger::LEVEL_WARNING ], Logger::LEVEL_PRIORITY[ Logger::LEVEL_INFO ] );
        $this->assertLessThan( Logger::LEVEL_PRIORITY[ Logger::LEVEL_ERROR ],   Logger::LEVEL_PRIORITY[ Logger::LEVEL_WARNING ] );
    }

    public function test_redaction_still_runs_through_sink(): void {
        $logger = new Logger();
        $captured = array();
        $logger->add_sink( function ( array $entry ) use ( &$captured ): void {
            $captured[] = $entry;
        } );
        $logger->info( 'auth attempt', array( 'api_key' => 'sk-1234567890', 'user' => 'alice' ) );
        $this->assertCount( 1, $captured );
        $this->assertSame( '***', $captured[0]['context']['api_key'] );
        $this->assertSame( 'alice', $captured[0]['context']['user'] );
    }
}
