<?php
/**
 * Unit tests for ChannelResolver — input classification (parsing only).
 *
 * Tests the parse layer without the API. resolve() is covered separately.
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\YouTube;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Logging\Logger;
use VectorYT\Gallery\YouTube\ChannelResolver;

/**
 * @covers \VectorYT\Gallery\YouTube\ChannelResolver
 */
final class ChannelResolverTest extends TestCase {

    private ChannelResolver $resolver;

    public function setUp(): void {
            parent::setUp();
            \Brain\Monkey\setUp();

            // No API calls in classification tests.
            $api = $this->createMock( \VectorYT\Gallery\YouTube\ApiClientInterface::class );
            // Logger is `final`, so use a real instance instead of a mock.
            $logger = new \VectorYT\Gallery\Logging\Logger();

            $this->resolver = new ChannelResolver( $api, $logger );
        }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_classify_bare_channel_id(): void {
        $id = 'UC_x5XG1OV2P6uZZ5FSM9Ttw';
        $this->assertSame( array( 'id' => $id ), $this->resolver->classify_input( $id ) );
    }

    public function test_classify_handle_with_at(): void {
        $this->assertSame(
            array( 'handle' => 'GoogleDevelopers' ),
            $this->resolver->classify_input( '@GoogleDevelopers' )
        );
    }

    public function test_classify_handle_without_at(): void {
        $this->assertSame(
            array( 'handle' => 'GoogleDevelopers' ),
            $this->resolver->classify_input( 'GoogleDevelopers' )
        );
    }

    public function test_classify_channel_url(): void {
        $url = 'https://www.youtube.com/channel/UC_x5XG1OV2P6uZZ5FSM9Ttw';
        $this->assertSame(
            array( 'id' => 'UC_x5XG1OV2P6uZZ5FSM9Ttw' ),
            $this->resolver->classify_input( $url )
        );
    }

    public function test_classify_handle_url(): void {
        $url = 'https://www.youtube.com/@GoogleDevelopers';
        $this->assertSame(
            array( 'handle' => 'GoogleDevelopers' ),
            $this->resolver->classify_input( $url )
        );
    }

    public function test_classify_user_url(): void {
        $url = 'https://www.youtube.com/user/GoogleDevelopers';
        $this->assertSame(
            array( 'username' => 'GoogleDevelopers' ),
            $this->resolver->classify_input( $url )
        );
    }

    public function test_classify_custom_url(): void {
        $url = 'https://www.youtube.com/c/SomeChannel';
        $this->assertSame(
            array( 'username' => 'SomeChannel' ),
            $this->resolver->classify_input( $url )
        );
    }

    public function test_classify_rejects_empty(): void {
        $this->expectException( \InvalidArgumentException::class );
        $this->resolver->classify_input( '   ' );
    }

    public function test_classify_rejects_garbage(): void {
        $this->expectException( \InvalidArgumentException::class );
        $this->resolver->classify_input( '!!!not-a-valid-input!!!' );
    }

    public function test_classify_url_without_recognized_pattern(): void {
        $this->expectException( \InvalidArgumentException::class );
        $this->resolver->classify_input( 'https://www.youtube.com/feed/trending' );
    }
}