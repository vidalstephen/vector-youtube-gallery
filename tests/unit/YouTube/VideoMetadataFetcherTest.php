<?php
/**
 * Unit tests for VideoMetadataFetcher — input parsing + size guards.
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\YouTube;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Logging\Logger;
use VectorYT\Gallery\YouTube\ApiClientInterface;
use VectorYT\Gallery\YouTube\VideoMetadataFetcher;

/**
 * @covers \VectorYT\Gallery\YouTube\VideoMetadataFetcher
 */
final class VideoMetadataFetcherTest extends TestCase {

    private VideoMetadataFetcher $fetcher;

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        $api = $this->createMock( ApiClientInterface::class );
        $logger = new Logger();
        $this->fetcher = new VideoMetadataFetcher( $api, $logger );
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_classify_bare_id(): void {
        $this->assertSame( 'dQw4w9WgXcQ', $this->fetcher->classify_input( 'dQw4w9WgXcQ' ) );
    }

    public function test_classify_watch_url(): void {
        $url = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=42';
        $this->assertSame( 'dQw4w9WgXcQ', $this->fetcher->classify_input( $url ) );
    }

    public function test_classify_short_url(): void {
        $this->assertSame( 'dQw4w9WgXcQ', $this->fetcher->classify_input( 'https://youtu.be/dQw4w9WgXcQ' ) );
    }

    public function test_classify_shorts_url(): void {
        $url = 'https://www.youtube.com/shorts/dQw4w9WgXcQ';
        $this->assertSame( 'dQw4w9WgXcQ', $this->fetcher->classify_input( $url ) );
    }

    public function test_classify_embed_url(): void {
        $url = 'https://www.youtube.com/embed/dQw4w9WgXcQ';
        $this->assertSame( 'dQw4w9WgXcQ', $this->fetcher->classify_input( $url ) );
    }

    public function test_classify_rejects_invalid_length(): void {
        $this->expectException( \InvalidArgumentException::class );
        $this->fetcher->classify_input( 'too-short' );
    }

    public function test_fetch_many_rejects_too_many_ids(): void {
        $api = $this->createMock( ApiClientInterface::class );
        $logger = new Logger();
        $f = new VideoMetadataFetcher( $api, $logger );

        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessageMatches( '/at most 50/' );

        $ids = array_fill( 0, 51, 'dQw4w9WgXcQ' );
        $f->fetch_many( $ids );
    }

    public function test_fetch_many_empty_returns_empty(): void {
        $api = $this->createMock( ApiClientInterface::class );
        $api->expects( $this->never() )->method( 'videos_list' );
        $logger = new Logger();
        $f = new VideoMetadataFetcher( $api, $logger );

        $response = $f->fetch_many( array() );
        $this->assertSame( array( 'items' => array() ), $response );
    }
}