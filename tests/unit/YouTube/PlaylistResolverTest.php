<?php
/**
 * Unit tests for PlaylistResolver — input parsing.
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\YouTube;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Logging\Logger;
use VectorYT\Gallery\YouTube\ApiClientInterface;
use VectorYT\Gallery\YouTube\PlaylistResolver;

/**
 * @covers \VectorYT\Gallery\YouTube\PlaylistResolver
 */
final class PlaylistResolverTest extends TestCase {

    private PlaylistResolver $resolver;

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        $api = $this->createMock( ApiClientInterface::class );
        $logger = new Logger();  // use real instance (final, can't be mocked)
        $this->resolver = new PlaylistResolver( $api, $logger );
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_classify_bare_playlist_id(): void {
        $this->assertSame( 'PLBCF2DAC6FFB574DE', $this->resolver->classify_input( 'PLBCF2DAC6FFB574DE' ) );
    }

    public function test_classify_uploads_prefix(): void {
        $this->assertSame( 'UU_x5XG1OV2P6uZZ5FSM9Ttw', $this->resolver->classify_input( 'UU_x5XG1OV2P6uZZ5FSM9Ttw' ) );
    }

    public function test_classify_url(): void {
        $url = 'https://www.youtube.com/playlist?list=PLBCF2DAC6FFB574DE';
        $this->assertSame( 'PLBCF2DAC6FFB574DE', $this->resolver->classify_input( $url ) );
    }

    public function test_classify_rejects_bad_prefix(): void {
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessageMatches( '/Invalid playlist ID prefix/' );
        $this->resolver->classify_input( 'XX_abc123' );
    }

    public function test_classify_rejects_too_short(): void {
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessageMatches( '/suspicious length/' );
        $this->resolver->classify_input( 'PL' );
    }

    public function test_classify_rejects_url_without_list_param(): void {
        $this->expectException( \InvalidArgumentException::class );
        $this->resolver->classify_input( 'https://www.youtube.com/feed/trending' );
    }
}