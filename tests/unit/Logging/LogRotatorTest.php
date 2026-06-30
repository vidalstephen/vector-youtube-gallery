<?php
/**
 * Unit tests for LogRotator.
 *
 * The rotator is a pure file-system class; we point it at a temp
 * directory and exercise the rename / delete / skip logic with real
 * files. No Brain\Monkey shims needed.
 *
 * @covers \VectorYT\Gallery\Logging\LogRotator
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Logging;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Logging\LogRotator;
use VectorYT\Gallery\Settings\SettingsRepository;
use VectorYT\Gallery\Tests\Support\BrainHelpers;
use VectorYT\Gallery\Tests\Support\OptionsBag;

final class LogRotatorTest extends TestCase
{
    private string $tmpdir = '';

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        OptionsBag::reset();
        BrainHelpers::stubOptionFunctions();
        BrainHelpers::stubEscapeFunctions();
        $this->tmpdir = sys_get_temp_dir() . '/vyg-rotator-' . bin2hex( random_bytes( 4 ) );
        mkdir( $this->tmpdir, 0700, true );
        mkdir( $this->tmpdir . '/segments', 0700, true );
    }

    protected function tearDown(): void {
        $this->rmrf( $this->tmpdir );
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_rotate_no_op_when_file_does_not_exist(): void {
        $rotator = new LogRotator( new SettingsRepository(), $this->tmpdir . '/debug.log', $this->tmpdir . '/segments' );
        $this->assertSame( 0, $rotator->rotate() );
    }

    public function test_rotate_no_op_when_file_is_below_threshold(): void {
        $this->write_log( 'small log entry' );
        $rotator = new LogRotator( new SettingsRepository(), $this->tmpdir . '/debug.log', $this->tmpdir . '/segments' );
        $this->assertSame( 0, $rotator->rotate() );
    }

    public function test_rotate_moves_active_to_segment_1_and_creates_new_active(): void {
        $this->write_log( str_repeat( 'X', 6 * 1024 * 1024 ) ); // 6 MB > default 5 MB
        $rotator = new LogRotator( new SettingsRepository(), $this->tmpdir . '/debug.log', $this->tmpdir . '/segments' );

        $this->assertSame( 1, $rotator->rotate() );
        $this->assertFileExists( $this->tmpdir . '/segments/debug.1.log' );
        $this->assertFileExists( $this->tmpdir . '/debug.log' );
        $this->assertSame( 0, (int) filesize( $this->tmpdir . '/debug.log' ) );
    }

    public function test_rotate_caps_at_max_files(): void {
        $settings = new SettingsRepository();
        $settings->set( 'log_max_size_mb', 1 );
        $settings->set( 'log_max_files', 3 );
        $rotator = new LogRotator( $settings, $this->tmpdir . '/debug.log', $this->tmpdir . '/segments' );

        // Do 5 rotations. With max_files=3, the oldest 2 should be
        // dropped each time.
        for ( $i = 1; $i <= 5; $i++ ) {
            $this->write_log( str_repeat( 'Y', 2 * 1024 * 1024 ) );
            $rotator->rotate();
        }
        // Expect at most 3 segments + the active log.
        $segments = $rotator->segments();
        $this->assertLessThanOrEqual( 3, count( $segments ) );
    }

    public function test_rotate_with_zero_max_files_does_not_delete_active(): void {
        $settings = new SettingsRepository();
        $settings->set( 'log_max_size_mb', 1 );
        $settings->set( 'log_max_files', 0 );
        $rotator = new LogRotator( $settings, $this->tmpdir . '/debug.log', $this->tmpdir . '/segments' );

        $this->write_log( str_repeat( 'Z', 2 * 1024 * 1024 ) );
        $rotator->rotate();
        $this->assertFileExists( $this->tmpdir . '/debug.log' );
        $this->assertSame( 0, count( $rotator->segments() ) );
    }

    public function test_max_size_bytes_clamps_above_ceiling(): void {
        $settings = new SettingsRepository();
        $settings->set( 'log_max_size_mb', 9999 );
        $rotator = new LogRotator( $settings, $this->tmpdir . '/debug.log', $this->tmpdir . '/segments' );
        $this->assertSame( LogRotator::MAX_SIZE_MB_CEILING * 1024 * 1024, $rotator->max_size_bytes() );
    }

    public function test_max_size_bytes_clamps_below_floor(): void {
        $settings = new SettingsRepository();
        $settings->set( 'log_max_size_mb', 0 );
        $rotator = new LogRotator( $settings, $this->tmpdir . '/debug.log', $this->tmpdir . '/segments' );
        $this->assertSame( 1 * 1024 * 1024, $rotator->max_size_bytes() );
    }

    public function test_max_files_clamps_above_ceiling(): void {
        $settings = new SettingsRepository();
        $settings->set( 'log_max_files', 9999 );
        $rotator = new LogRotator( $settings, $this->tmpdir . '/debug.log', $this->tmpdir . '/segments' );
        $this->assertSame( LogRotator::MAX_FILES_CEILING, $rotator->max_files() );
    }

    public function test_max_files_clamps_below_floor(): void {
        $settings = new SettingsRepository();
        $settings->set( 'log_max_files', -3 );
        $rotator = new LogRotator( $settings, $this->tmpdir . '/debug.log', $this->tmpdir . '/segments' );
        $this->assertSame( 0, $rotator->max_files() );
    }

    public function test_segments_returns_array_with_required_keys(): void {
        $rotator = new LogRotator( new SettingsRepository(), $this->tmpdir . '/debug.log', $this->tmpdir . '/segments' );
        // Empty segments_dir.
        $this->assertSame( array(), $rotator->segments() );

        // After a rotation, we should have at least one segment.
        $this->write_log( str_repeat( 'A', 6 * 1024 * 1024 ) );
        $rotator->rotate();
        $segments = $rotator->segments();
        $this->assertCount( 1, $segments );
        $this->assertArrayHasKey( 'name', $segments[0] );
        $this->assertArrayHasKey( 'path', $segments[0] );
        $this->assertArrayHasKey( 'size', $segments[0] );
        $this->assertArrayHasKey( 'mtime', $segments[0] );
    }

    public function test_log_file_path_is_returned(): void {
        $rotator = new LogRotator( new SettingsRepository(), $this->tmpdir . '/debug.log', $this->tmpdir . '/segments' );
        $this->assertSame( $this->tmpdir . '/debug.log', $rotator->log_file_path() );
    }

    public function test_segments_dir_is_returned(): void {
        $rotator = new LogRotator( new SettingsRepository(), $this->tmpdir . '/debug.log', $this->tmpdir . '/segments' );
        $this->assertSame( $this->tmpdir . '/segments', $rotator->segments_dir() );
    }

    private function write_log( string $contents ): void {
        file_put_contents( $this->tmpdir . '/debug.log', $contents );
    }

    private function rmrf( string $dir ): void {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $iterator as $entry ) {
            if ( $entry->isDir() ) {
                @rmdir( $entry->getPathname() );
            } else {
                @unlink( $entry->getPathname() );
            }
        }
        @rmdir( $dir );
    }
}
