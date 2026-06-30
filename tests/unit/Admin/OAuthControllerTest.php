<?php
/**
 * Unit tests for OAuthController.
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Admin\OAuthController;
use VectorYT\Gallery\Logging\Logger;
use VectorYT\Gallery\Settings\OAuthTokenRepository;
use VectorYT\Gallery\Settings\SettingsRepository;
use VectorYT\Gallery\Tests\Support\BrainHelpers;
use VectorYT\Gallery\Tests\Support\OptionsBag;
use VectorYT\Gallery\YouTube\OAuthClient;

/**
 * @covers \VectorYT\Gallery\Admin\OAuthController
 */
final class OAuthControllerTest extends TestCase {

    private OAuthTokenRepository $tokens;
    private SettingsRepository $settings;
    private OAuthController $controller;
    private CallbackFakeHttp $http;

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        OptionsBag::reset();
        BrainHelpers::stubOptionFunctions();
        BrainHelpers::stubEscapeFunctions();
        Functions\when( 'is_wp_error' )->alias( static fn( $value ): bool => false );
        Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://example.test/wp-admin/' . ltrim( $path, '/' ) );
        Functions\when( 'wp_generate_password' )->alias( static fn(): string => 'generated-state' );
        Functions\when( 'wp_nonce_url' )->alias( static fn( string $url, string $action ): string => $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . '_wpnonce=nonce-' . $action );

        $this->tokens = new OAuthTokenRepository();
        $this->tokens->set_client_config(
            'client-123456.apps.googleusercontent.com',
            'client-secret-value',
            'https://example.test/wp-admin/admin-post.php?action=vyg_oauth_callback'
        );
        $this->settings = new SettingsRepository();
        $this->http = new CallbackFakeHttp();
        $client = new OAuthClient( $this->tokens, new Logger(), $this->http );
        $this->controller = new OAuthController( $client, $this->tokens, $this->settings, new Logger() );
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_authorization_redirect_url_hashes_state_and_builds_google_url(): void {
        $url = $this->controller->authorization_redirect_url();
        $parts = parse_url( $url );
        parse_str( $parts['query'] ?? '', $query );

        $this->assertSame( 'accounts.google.com', $parts['host'] );
        $this->assertSame( 'generated-state', $query['state'] );
        $this->assertSame( 'client-123456.apps.googleusercontent.com', $query['client_id'] );

        $raw_state = OptionsBag::get( 'vyg_oauth_state' );
        $this->assertIsArray( $raw_state );
        $this->assertNotSame( 'generated-state', $raw_state['state'] );
        $this->assertTrue( $this->tokens->consume_state( 'generated-state' ) );
    }

    public function test_connect_redirect_allows_google_oauth_host_for_wp_safe_redirect(): void {
        $hosts = $this->controller->allow_google_oauth_redirect_host( array( 'example.test' ) );

        $this->assertContains( 'example.test', $hosts );
        $this->assertContains( 'accounts.google.com', $hosts );
        $this->assertSame( $hosts, array_values( array_unique( $hosts ) ) );
    }

    public function test_callback_rejects_invalid_state_before_token_exchange(): void {
        $redirect = $this->controller->callback_redirect_url( array( 'state' => 'bad', 'code' => 'auth-code' ) );

        $this->assertStringContainsString( 'vyg_notice=oauth_error', $redirect );
        $this->assertStringContainsString( 'vyg_oauth_error=invalid_state', $redirect );
        $this->assertCount( 0, $this->http->posts );
        $this->assertNull( $this->tokens->get_tokens() );
    }

    public function test_callback_exchanges_code_fetches_channel_and_sets_oauth_mode(): void {
        $this->tokens->set_state( 'state-ok' );
        $this->http->queuePost( 200, array(
            'access_token'  => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_in'    => 3600,
            'scope'         => OAuthClient::SCOPE_YOUTUBE_READONLY,
            'token_type'    => 'Bearer',
        ) );
        $this->http->queueGet( 200, array(
            'items' => array(
                array(
                    'id'      => 'UC_CONNECTED',
                    'snippet' => array( 'title' => 'Connected Test Channel' ),
                ),
            ),
        ) );

        $redirect = $this->controller->callback_redirect_url( array( 'state' => 'state-ok', 'code' => 'auth-code' ) );

        $this->assertStringContainsString( 'vyg_notice=oauth_connected', $redirect );
        $this->assertCount( 1, $this->http->posts );
        $this->assertSame( 'authorization_code', $this->http->posts[0]['args']['body']['grant_type'] );
        $this->assertSame( 'auth-code', $this->http->posts[0]['args']['body']['code'] );
        $this->assertCount( 1, $this->http->gets );
        $this->assertStringContainsString( 'mine=true', $this->http->gets[0]['url'] );

        $stored = $this->tokens->get_tokens();
        $this->assertNotNull( $stored );
        $this->assertSame( 'access-token', $stored['access_token'] );
        $this->assertSame( 'refresh-token', $stored['refresh_token'] );
        $this->assertSame( 'UC_CONNECTED', $stored['connected_account']['channel_id'] );
        $this->assertSame( 'Connected Test Channel', $stored['connected_account']['channel_title'] );
        $this->assertSame( 'oauth', $this->settings->get( 'api_mode' ) );
    }

    public function test_disconnect_revokes_refresh_token_deletes_local_tokens_and_returns_api_key_mode(): void {
        $this->tokens->store_tokens( 'access-token', 'refresh-token', 3600 );
        $this->settings->set( 'api_mode', 'oauth' );
        $this->http->queuePost( 200, array() );

        $redirect = $this->controller->disconnect_redirect_url();

        $this->assertStringContainsString( 'vyg_notice=oauth_revoked', $redirect );
        $this->assertCount( 1, $this->http->posts );
        $this->assertSame( 'https://oauth2.googleapis.com/revoke', $this->http->posts[0]['url'] );
        $this->assertSame( 'refresh-token', $this->http->posts[0]['args']['body']['token'] );
        $this->assertNull( $this->tokens->get_tokens() );
        $this->assertSame( 'api_key', $this->settings->get( 'api_mode' ) );
    }

    public function test_disconnect_deletes_local_tokens_even_when_revoke_fails(): void {
        $this->tokens->store_tokens( 'access-token', 'refresh-token', 3600 );
        $this->settings->set( 'api_mode', 'oauth' );
        $this->http->queuePost( 500, array( 'error' => 'temporarily_unavailable' ) );

        $redirect = $this->controller->disconnect_redirect_url();

        $this->assertStringContainsString( 'vyg_notice=oauth_disconnected', $redirect );
        $this->assertCount( 1, $this->http->posts );
        $this->assertNull( $this->tokens->get_tokens() );
        $this->assertSame( 'api_key', $this->settings->get( 'api_mode' ) );
    }
}

final class CallbackFakeHttp {
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
