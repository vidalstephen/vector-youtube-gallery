<?php
/**
 * Unit tests for OAuthClient.
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\YouTube;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Logging\Logger;
use VectorYT\Gallery\Settings\OAuthTokenRepository;
use VectorYT\Gallery\Tests\Support\BrainHelpers;
use VectorYT\Gallery\Tests\Support\OptionsBag;
use VectorYT\Gallery\YouTube\ApiException;
use VectorYT\Gallery\YouTube\OAuthClient;

/**
 * @covers \VectorYT\Gallery\YouTube\OAuthClient
 */
final class OAuthClientTest extends TestCase {

    private OAuthTokenRepository $tokens;
    private FakeHttp $http;
    private OAuthClient $client;

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        OptionsBag::reset();
        BrainHelpers::stubOptionFunctions();
        BrainHelpers::stubEscapeFunctions();
        Functions\when( 'is_wp_error' )->alias( static fn( $value ): bool => false );

        $this->tokens = new OAuthTokenRepository();
        $this->tokens->set_client_config(
            'client-123456.apps.googleusercontent.com',
            'client-secret-value',
            'https://example.com/wp-admin/admin-post.php?action=vyg_oauth_callback'
        );
        $this->http = new FakeHttp();
        $this->client = new OAuthClient( $this->tokens, new Logger(), $this->http );
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_authorization_url_contains_expected_google_params_and_hashes_state(): void {
        $url = $this->client->authorization_url( 'state-secret' );
        $parts = parse_url( $url );
        parse_str( $parts['query'] ?? '', $query );

        $this->assertSame( 'https', $parts['scheme'] );
        $this->assertSame( 'accounts.google.com', $parts['host'] );
        $this->assertSame( '/o/oauth2/v2/auth', $parts['path'] );
        $this->assertSame( 'client-123456.apps.googleusercontent.com', $query['client_id'] );
        $this->assertSame( 'code', $query['response_type'] );
        $this->assertSame( OAuthClient::SCOPE_YOUTUBE_READONLY, $query['scope'] );
        $this->assertSame( 'offline', $query['access_type'] );
        $this->assertSame( 'consent', $query['prompt'] );
        $this->assertSame( 'state-secret', $query['state'] );

        $raw_state = OptionsBag::get( 'vyg_oauth_state' );
        $this->assertIsArray( $raw_state );
        $this->assertNotSame( 'state-secret', $raw_state['state'] );
        $this->assertTrue( $this->tokens->consume_state( 'state-secret' ) );
    }

    public function test_exchange_auth_code_posts_to_token_endpoint_and_stores_tokens(): void {
        $this->http->queuePost( 200, array(
            'access_token'  => 'new-access',
            'refresh_token' => 'new-refresh',
            'expires_in'    => 3600,
            'scope'         => OAuthClient::SCOPE_YOUTUBE_READONLY,
            'token_type'    => 'Bearer',
        ) );

        $body = $this->client->exchange_auth_code( 'auth-code', array( 'channel_id' => 'UC_TEST' ) );

        $this->assertSame( 'new-access', $body['access_token'] );
        $this->assertCount( 1, $this->http->posts );
        $this->assertSame( 'https://oauth2.googleapis.com/token', $this->http->posts[0]['url'] );
        $this->assertSame( 'authorization_code', $this->http->posts[0]['args']['body']['grant_type'] );
        $this->assertSame( 'auth-code', $this->http->posts[0]['args']['body']['code'] );

        $stored = $this->tokens->get_tokens();
        $this->assertNotNull( $stored );
        $this->assertSame( 'new-access', $stored['access_token'] );
        $this->assertSame( 'new-refresh', $stored['refresh_token'] );
        $this->assertSame( 'UC_TEST', $stored['connected_account']['channel_id'] );
    }

    public function test_youtube_api_request_uses_bearer_token_and_never_key_param(): void {
        $this->tokens->store_tokens( 'access-token', 'refresh-token', 3600 );
        $this->http->queueGet( 200, array( 'items' => array( array( 'id' => 'UC_TEST' ) ) ) );

        $body = $this->client->channels_list( array( 'part' => 'snippet', 'id' => 'UC_TEST', 'key' => 'must-be-removed' ) );

        $this->assertSame( 'UC_TEST', $body['items'][0]['id'] );
        $this->assertCount( 1, $this->http->gets );
        $this->assertStringStartsWith( 'https://www.googleapis.com/youtube/v3/channels?', $this->http->gets[0]['url'] );
        $this->assertStringNotContainsString( 'key=', $this->http->gets[0]['url'] );
        $this->assertSame( 'Bearer access-token', $this->http->gets[0]['args']['headers']['Authorization'] );
    }

    public function test_expired_access_token_refreshes_before_request(): void {
        $this->tokens->store_tokens( 'old-access', 'refresh-token', -10, array( OAuthClient::SCOPE_YOUTUBE_READONLY ) );
        $this->http->queuePost( 200, array(
            'access_token' => 'refreshed-access',
            'expires_in'   => 3600,
            'token_type'   => 'Bearer',
        ) );
        $this->http->queueGet( 200, array( 'items' => array() ) );

        $this->client->videos_list( array( 'part' => 'snippet', 'id' => 'abc123' ) );

        $this->assertCount( 1, $this->http->posts );
        $this->assertSame( 'refresh_token', $this->http->posts[0]['args']['body']['grant_type'] );
        $this->assertSame( 'refresh-token', $this->http->posts[0]['args']['body']['refresh_token'] );
        $this->assertSame( 'Bearer refreshed-access', $this->http->gets[0]['args']['headers']['Authorization'] );
        $this->assertSame( 'refresh-token', $this->tokens->get_tokens()['refresh_token'] );
    }

    public function test_401_response_refreshes_and_retries_once(): void {
        $this->tokens->store_tokens( 'old-access', 'refresh-token', 3600 );
        $this->http->queueGet( 401, array( 'error' => array( 'errors' => array( array( 'reason' => 'authError' ) ) ) ) );
        $this->http->queuePost( 200, array(
            'access_token' => 'new-access',
            'expires_in'   => 3600,
            'token_type'   => 'Bearer',
        ) );
        $this->http->queueGet( 200, array( 'items' => array( array( 'id' => 'video1' ) ) ) );

        $body = $this->client->videos_list( array( 'part' => 'snippet', 'id' => 'video1' ) );

        $this->assertSame( 'video1', $body['items'][0]['id'] );
        $this->assertCount( 2, $this->http->gets );
        $this->assertSame( 'Bearer old-access', $this->http->gets[0]['args']['headers']['Authorization'] );
        $this->assertSame( 'Bearer new-access', $this->http->gets[1]['args']['headers']['Authorization'] );
    }

    public function test_refresh_failure_marks_repository_error(): void {
        $this->tokens->store_tokens( 'old-access', 'refresh-token', -10 );
        $this->http->queuePost( 400, array(
            'error' => 'invalid_grant',
            'error_description' => 'Token has been revoked',
        ) );

        try {
            $this->client->videos_list( array( 'part' => 'snippet', 'id' => 'video1' ) );
            $this->fail( 'Expected ApiException' );
        } catch ( ApiException $e ) {
            $this->assertSame( 'invalid_grant', $e->api_error_code() );
        }

        $status = $this->tokens->status();
        $this->assertNotNull( $status['last_refresh_error'] );
        $this->assertSame( 'invalid_grant', $status['last_refresh_error']['code'] );
        $this->assertSame( 'Token has been revoked', $status['last_refresh_error']['message'] );
    }

    public function test_revoke_token_posts_to_revoke_endpoint_and_deletes_tokens(): void {
        $this->tokens->store_tokens( 'access-token', 'refresh-token', 3600 );
        $this->http->queuePost( 200, array() );

        $this->assertTrue( $this->client->revoke_token( '' ) );

        $this->assertSame( 'https://oauth2.googleapis.com/revoke', $this->http->posts[0]['url'] );
        $this->assertSame( 'refresh-token', $this->http->posts[0]['args']['body']['token'] );
        $this->assertNull( $this->tokens->get_tokens() );
    }

    public function test_missing_tokens_throw_auth_exception(): void {
        $this->expectException( ApiException::class );
        $this->expectExceptionMessage( 'OAuth access token not configured' );

        $this->client->channels_list( array( 'part' => 'snippet', 'id' => 'UC_TEST' ) );
    }
}

final class FakeHttp {
    /** @var array<int,array{url:string,args:array<string,mixed>}> */
    public array $gets = array();

    /** @var array<int,array{url:string,args:array<string,mixed>}> */
    public array $posts = array();

    /** @var array<int,array{response:array{code:int},body:string}> */
    private array $get_queue = array();

    /** @var array<int,array{response:array{code:int},body:string}> */
    private array $post_queue = array();

    /** @param array<string,mixed> $body */
    public function queueGet( int $code, array $body ): void {
        $this->get_queue[] = array( 'response' => array( 'code' => $code ), 'body' => wp_json_encode( $body ) );
    }

    /** @param array<string,mixed> $body */
    public function queuePost( int $code, array $body ): void {
        $this->post_queue[] = array( 'response' => array( 'code' => $code ), 'body' => wp_json_encode( $body ) );
    }

    /** @param array<string,mixed> $args */
    public function get( string $url, array $args ): array {
        $this->gets[] = array( 'url' => $url, 'args' => $args );
        return array_shift( $this->get_queue ) ?? array( 'response' => array( 'code' => 500 ), 'body' => '{}' );
    }

    /** @param array<string,mixed> $args */
    public function post( string $url, array $args ): array {
        $this->posts[] = array( 'url' => $url, 'args' => $args );
        return array_shift( $this->post_queue ) ?? array( 'response' => array( 'code' => 500 ), 'body' => '{}' );
    }
}
