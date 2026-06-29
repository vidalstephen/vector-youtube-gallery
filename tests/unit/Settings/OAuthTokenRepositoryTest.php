<?php
/**
 * Unit tests for OAuthTokenRepository.
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Settings\OAuthTokenRepository;
use VectorYT\Gallery\Tests\Support\BrainHelpers;
use VectorYT\Gallery\Tests\Support\OptionsBag;

/**
 * @covers \VectorYT\Gallery\Settings\OAuthTokenRepository
 */
final class OAuthTokenRepositoryTest extends TestCase {

    private OAuthTokenRepository $repo;

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        OptionsBag::reset();
        BrainHelpers::stubOptionFunctions();
        BrainHelpers::stubEscapeFunctions();
        $this->repo = new OAuthTokenRepository();
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_client_config_is_sealed_and_not_autoloaded(): void {
        $this->assertTrue( $this->repo->set_client_config(
            'client-123456.apps.googleusercontent.com',
            'client-secret-value',
            'https://example.com/wp-admin/admin-post.php?action=vyg_oauth_callback'
        ) );

        $raw = OptionsBag::get( 'vyg_oauth_client_config' );
        $this->assertIsArray( $raw );
        $this->assertSame( false, OptionsBag::autoload( 'vyg_oauth_client_config' ) );
        $this->assertSame( 'client-123456.apps.googleusercontent.com', $raw['client_id'] );
        $this->assertNotSame( 'client-secret-value', $raw['client_secret'] );
        $this->assertStringNotContainsString( 'client-secret-value', wp_json_encode( $raw ) );

        $config = $this->repo->get_client_config();
        $this->assertNotNull( $config );
        $this->assertSame( 'client-secret-value', $config['client_secret'] );
        $this->assertTrue( $this->repo->has_client_config() );
    }

    public function test_empty_client_config_deletes_existing_config(): void {
        $this->repo->set_client_config( 'client-id', 'secret', 'https://example.com/callback' );
        $this->assertTrue( $this->repo->has_client_config() );

        $this->repo->set_client_config( '', '', '' );
        $this->assertFalse( $this->repo->has_client_config() );
        $this->assertNull( OptionsBag::get( 'vyg_oauth_client_config', null ) );
    }

    public function test_tokens_are_sealed_not_autoloaded_and_round_trip(): void {
        $this->assertTrue( $this->repo->store_tokens(
            'access-token-value',
            'refresh-token-value',
            3600,
            array( 'https://www.googleapis.com/auth/youtube.readonly' ),
            'Bearer',
            array(
                'email'         => 'owner@example.com',
                'channel_id'    => 'UC123',
                'channel_title' => 'Example Channel',
                'ignored'       => 'must not persist',
            )
        ) );

        $raw = OptionsBag::get( 'vyg_oauth_tokens' );
        $this->assertIsArray( $raw );
        $this->assertSame( false, OptionsBag::autoload( 'vyg_oauth_tokens' ) );
        $encoded_raw = wp_json_encode( $raw );
        $this->assertStringNotContainsString( 'access-token-value', $encoded_raw );
        $this->assertStringNotContainsString( 'refresh-token-value', $encoded_raw );

        $tokens = $this->repo->get_tokens();
        $this->assertNotNull( $tokens );
        $this->assertSame( 'access-token-value', $tokens['access_token'] );
        $this->assertSame( 'refresh-token-value', $tokens['refresh_token'] );
        $this->assertSame( array( 'https://www.googleapis.com/auth/youtube.readonly' ), $tokens['scope'] );
        $this->assertSame( 'owner@example.com', $tokens['connected_account']['email'] );
        $this->assertArrayNotHasKey( 'ignored', $tokens['connected_account'] );
        $this->assertTrue( $this->repo->has_tokens() );
    }

    public function test_status_never_exposes_token_material_or_client_secret(): void {
        $this->repo->set_client_config( 'client-1234567890', 'super-secret', 'https://example.com/callback' );
        $this->repo->store_tokens( 'access-secret', 'refresh-secret', 60, array( 'scope-a' ), 'Bearer', array( 'channel_id' => 'UC123' ) );

        $status = $this->repo->status();
        $encoded = wp_json_encode( $status );

        $this->assertTrue( $status['client_configured'] );
        $this->assertTrue( $status['connected'] );
        $this->assertSame( 'client***7890', $status['client_id_masked'] );
        $this->assertStringNotContainsString( 'super-secret', $encoded );
        $this->assertStringNotContainsString( 'access-secret', $encoded );
        $this->assertStringNotContainsString( 'refresh-secret', $encoded );
        $this->assertSame( array( 'scope-a' ), $status['scopes'] );
    }

    public function test_refresh_error_can_be_marked_and_cleared(): void {
        $this->repo->store_tokens( 'access', 'refresh', 60 );
        $this->repo->mark_refresh_error( 'invalid_grant', 'Refresh token revoked' );

        $status = $this->repo->status();
        $this->assertNotNull( $status['last_refresh_error'] );
        $this->assertSame( 'invalid_grant', $status['last_refresh_error']['code'] );
        $this->assertSame( 'Refresh token revoked', $status['last_refresh_error']['message'] );

        $this->repo->clear_refresh_error();
        $this->assertNull( $this->repo->status()['last_refresh_error'] );
    }

    public function test_state_is_hashed_not_autoloaded_and_one_time_consumed(): void {
        $this->assertTrue( $this->repo->set_state( 'state-secret', 300 ) );

        $raw = OptionsBag::get( 'vyg_oauth_state' );
        $this->assertIsArray( $raw );
        $this->assertSame( false, OptionsBag::autoload( 'vyg_oauth_state' ) );
        $this->assertNotSame( 'state-secret', $raw['state'] );

        $this->assertFalse( $this->repo->consume_state( 'wrong-state' ) );
        $this->assertFalse( $this->repo->consume_state( 'state-secret' ) );

        $this->repo->set_state( 'state-secret', 300 );
        $this->assertTrue( $this->repo->consume_state( 'state-secret' ) );
        $this->assertFalse( $this->repo->consume_state( 'state-secret' ) );
    }

    public function test_delete_tokens_removes_tokens_and_state(): void {
        $this->repo->store_tokens( 'access', 'refresh', 60 );
        $this->repo->set_state( 'state-secret' );

        $this->assertTrue( $this->repo->delete_tokens() );
        $this->assertNull( $this->repo->get_tokens() );
        $this->assertNull( OptionsBag::get( 'vyg_oauth_state', null ) );
    }
}
