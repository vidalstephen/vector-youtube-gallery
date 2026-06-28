<?php
/**
 * Tests for PrivacyPolicyGenerator.
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Compliance;

use Brain\Monkey;
use Brain\Monkey\Functions;
use VectorYT\Gallery\Compliance\PrivacyPolicyGenerator;

require_once __DIR__ . '/../../bootstrap.php';

final class PrivacyPolicyGeneratorTest extends \PHPUnit\Framework\TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
        Functions\when( 'get_option' )->alias( static fn( $k, $d = '' ) => 'admin@example.com' );
        Functions\when( '__' )->alias( static fn( $s, $d = '' ) => $s );
        Functions\when( '_n' )->alias( static function ( $single, $plural, $n, $d = '' ) {
            return $n === 1 ? $single : $plural;
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_generate_includes_site_name(): void {
        $text = ( new PrivacyPolicyGenerator() )->generate();
        $this->assertStringContainsString( 'Test Site', $text );
    }

    public function test_generate_includes_retention_days(): void {
        $text = ( new PrivacyPolicyGenerator() )->generate( array( 'retention_days' => 60 ) );
        $this->assertStringContainsString( '60', $text );
    }

    public function test_generate_explains_third_party_services(): void {
        $text = ( new PrivacyPolicyGenerator() )->generate();
        $this->assertStringContainsString( 'YouTube', $text );
        $this->assertStringContainsString( 'Privacy Policy', $text );
    }

    public function test_generate_includes_contact_email(): void {
        $text = ( new PrivacyPolicyGenerator() )->generate();
        $this->assertStringContainsString( 'admin@example.com', $text );
    }
}