<?php
/**
 * Unit tests for SecretsRepository.
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Settings;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Settings\SecretsRepository;
use VectorYT\Gallery\Tests\Support\OptionsBag;

/**
 * @covers \VectorYT\Gallery\Settings\SecretsRepository
 */
final class SecretsRepositoryTest extends TestCase {

    private SecretsRepository $repo;

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        OptionsBag::reset();

        // Stub the WP option functions.
        Functions\when( 'get_option' )->alias( static fn( string $key, $default = false ) => OptionsBag::get( $key, $default ) );
        Functions\when( 'update_option' )->alias( static fn( string $key, $value, $autoload = null ) => OptionsBag::update( $key, $value, $autoload ) );
        Functions\when( 'delete_option' )->alias( static fn( string $key ) => OptionsBag::delete( $key ) );
        // Stub sanitize_key/sanitize_text_field — these are WP helpers used by SecretsRepository.
        // WP sanitize_key: lowercase, then strip everything except [a-z0-9_\-].
        Functions\when( 'sanitize_key' )->alias( static fn( string $s ): string => strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $s ) ) ) );
        Functions\when( 'sanitize_text_field' )->alias( static fn( string $s ): string => trim( strip_tags( $s ) ) );

        $this->repo = new SecretsRepository();
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_set_api_key_stores_value(): void {
        $this->assertTrue( $this->repo->set_api_key( 'AIzaSyA-test-key-1234567890abcdef' ) );
        $this->assertTrue( $this->repo->has_api_key() );
        $this->assertSame( 'AIzaSyA-test-key-1234567890abcdef', $this->repo->get_api_key() );
    }

    public function test_set_api_key_trims_whitespace(): void {
        $this->repo->set_api_key( "  AIzaSyA-test  \n" );
        $this->assertSame( 'AIzaSyA-test', $this->repo->get_api_key() );
    }

    public function test_empty_string_deletes_key(): void {
        $this->repo->set_api_key( 'initial-key' );
        $this->assertTrue( $this->repo->has_api_key() );

        $result = $this->repo->set_api_key( '' );
        $this->assertTrue( $result );
        $this->assertFalse( $this->repo->has_api_key() );
        $this->assertNull( $this->repo->get_api_key() );
    }

    public function test_delete_api_key_removes_value_and_metadata(): void {
        $this->repo->set_api_key( 'AIza-key' );
        $this->repo->mark_api_key_validated();
        $this->repo->mark_api_key_invalid( 'auth', 'bad key' );

        $this->assertTrue( $this->repo->delete_api_key() );
        $this->assertNull( $this->repo->get_api_key_validated_at() );
        $this->assertNull( $this->repo->get_api_key_last_error() );
    }

    public function test_replace_invalidates_validation(): void {
        $this->repo->set_api_key( 'AIza-old' );
        $this->repo->mark_api_key_validated();
        $this->assertNotNull( $this->repo->get_api_key_validated_at() );

        $this->repo->set_api_key( 'AIza-new' );
        $this->assertNull( $this->repo->get_api_key_validated_at() );
    }

    public function test_mask_short_key_returns_stars(): void {
        $this->assertSame( '', SecretsRepository::mask( null ) );
        $this->assertSame( '', SecretsRepository::mask( '' ) );
        $this->assertSame( '***', SecretsRepository::mask( 'short' ) );   // ≤ 8 chars
    }

    public function test_mask_long_key_shows_edges(): void {
        $masked = SecretsRepository::mask( 'AIzaSyA-test-key-1234567890abcdef' );
        $this->assertStringStartsWith( 'AIza', $masked );
        $this->assertStringEndsWith( 'cdef', $masked );
        $this->assertStringContainsString( '***', $masked );
    }

    public function test_mark_api_key_invalid_persists_error(): void {
        $this->repo->mark_api_key_invalid( 'quotaExceeded', 'daily limit reached' );

        $err = $this->repo->get_api_key_last_error();
        $this->assertNotNull( $err );
        $this->assertSame( 'quotaexceeded', $err['code'] );  // sanitize_key lowercases
        $this->assertSame( 'daily limit reached', $err['message'] );
        $this->assertNotEmpty( $err['at'] );
    }
}