<?php
/**
 * Unit tests for ApiException classification.
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\YouTube;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\YouTube\ApiException;

/**
 * @covers \VectorYT\Gallery\YouTube\ApiException
 */
final class ApiExceptionTest extends TestCase {

    public function test_classify_401_is_auth(): void {
        $this->assertSame(
            ApiException::KIND_AUTH,
            ApiException::classify_youtube_error( 401, null )
        );
    }

    public function test_classify_403_quota(): void {
        $body = array(
            'error' => array(
                'errors' => array(
                    array( 'reason' => 'quotaExceeded' ),
                ),
            ),
        );
        $this->assertSame(
            ApiException::KIND_QUOTA,
            ApiException::classify_youtube_error( 403, $body )
        );
    }

    public function test_classify_403_not_quota_is_forbidden(): void {
        $this->assertSame(
            ApiException::KIND_FORBIDDEN,
            ApiException::classify_youtube_error( 403, null )
        );
    }

    public function test_classify_404_is_not_found(): void {
        $this->assertSame(
            ApiException::KIND_NOT_FOUND,
            ApiException::classify_youtube_error( 404, null )
        );
    }

    public function test_classify_429_is_rate_limit(): void {
        $this->assertSame(
            ApiException::KIND_RATE_LIMIT,
            ApiException::classify_youtube_error( 429, null )
        );
    }

    public function test_classify_5xx_is_transient(): void {
        $this->assertSame(
            ApiException::KIND_TRANSIENT,
            ApiException::classify_youtube_error( 503, null )
        );
    }

    public function test_classify_400_is_bad_request(): void {
        $this->assertSame(
            ApiException::KIND_BAD_REQUEST,
            ApiException::classify_youtube_error( 400, null )
        );
    }

    public function test_is_hard_stop(): void {
        $auth      = new ApiException( 'x', ApiException::KIND_AUTH );
        $quota     = new ApiException( 'x', ApiException::KIND_QUOTA );
        $transient = new ApiException( 'x', ApiException::KIND_TRANSIENT );
        $rate      = new ApiException( 'x', ApiException::KIND_RATE_LIMIT );

        $this->assertTrue( $auth->is_hard_stop() );
        $this->assertTrue( $quota->is_hard_stop() );
        $this->assertFalse( $transient->is_hard_stop() );
        $this->assertFalse( $rate->is_hard_stop() );   // rate limit is soft-retryable
    }

    public function test_construct_carries_context(): void {
        $e = new ApiException(
            'boom',
            ApiException::KIND_AUTH,
            401,
            'invalidApiKey',
            array( 'error' => array( 'message' => 'bad key' ) )
        );

        $this->assertSame( 'boom', $e->getMessage() );
        $this->assertSame( ApiException::KIND_AUTH, $e->kind() );
        $this->assertSame( 401, $e->http_status() );
        $this->assertSame( 'invalidApiKey', $e->api_error_code() );
        $this->assertSame( 'bad key', $e->api_error_response()['error']['message'] );
    }
}