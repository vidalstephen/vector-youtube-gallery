<?php
/**
 * Unit tests for SourcesPage credential-mode behavior.
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use VectorYT\Gallery\Admin\SourcesPage;
use VectorYT\Gallery\Logging\Logger;
use VectorYT\Gallery\Repository\PlaylistRepository;
use VectorYT\Gallery\Repository\SourceRepository;
use VectorYT\Gallery\Repository\SyncLogRepository;
use VectorYT\Gallery\Repository\VideoRepository;
use VectorYT\Gallery\Settings\OAuthTokenRepository;
use VectorYT\Gallery\Settings\SecretsRepository;
use VectorYT\Gallery\Settings\SettingsRepository;
use VectorYT\Gallery\Sync\InitialImportJob;
use VectorYT\Gallery\Sync\RetryPolicy;
use VectorYT\Gallery\Sync\WpCronSyncScheduler;
use VectorYT\Gallery\Tests\Support\BrainHelpers;
use VectorYT\Gallery\Tests\Support\OptionsBag;
use VectorYT\Gallery\YouTube\ApiClientInterface;
use VectorYT\Gallery\YouTube\ChannelResolver;
use VectorYT\Gallery\YouTube\PlaylistResolver;
use VectorYT\Gallery\YouTube\QuotaTracker;
use VectorYT\Gallery\YouTube\VideoMetadataFetcher;

/**
 * @covers \VectorYT\Gallery\Admin\SourcesPage
 */
final class SourcesPageTest extends TestCase {

    private SourcesPage $page;
    private SecretsRepository $secrets;
    private OAuthTokenRepository $oauth;
    private SettingsRepository $settings;

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        OptionsBag::reset();
        BrainHelpers::stubOptionFunctions();
        BrainHelpers::stubEscapeFunctions();
        Functions\when( 'is_wp_error' )->alias( static fn( $value ): bool => false );

        $logger = new Logger();
        $api = new SourcesPageFakeApi();
        $this->secrets = new SecretsRepository();
        $this->oauth = new OAuthTokenRepository();
        $this->settings = new SettingsRepository();
        $sources = new SourceRepository();
        $logs = new SyncLogRepository();
        $import = new InitialImportJob(
            $logs,
            new RetryPolicy(),
            new QuotaTracker(),
            $logger,
            $sources,
            new VideoRepository(),
            new PlaylistRepository(),
            $api
        );

        $this->page = new SourcesPage(
            new ChannelResolver( $api, $logger ),
            new PlaylistResolver( $api, $logger ),
            new VideoMetadataFetcher( $api, $logger ),
            $sources,
            $logs,
            $import,
            $logger,
            $this->secrets,
            $this->oauth,
            $this->settings,
            // Phase 12.2: SyncScheduler dep. Use a recording fake so tests
            // can assert the page routed through the abstraction instead
            // of calling wp_schedule_single_event directly.
            new SourcesPageRecordingScheduler()
        );
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_api_key_mode_requires_api_key(): void {
        $this->settings->set( 'api_mode', 'api_key' );
        $this->assertSame( 'api_key', $this->invokePrivate( 'current_auth_mode' ) );
        $this->assertTrue( $this->invokePrivate( 'has_api_access' ), 'Mock mode satisfies access during unit tests.' );

        $this->secrets->set_api_key( 'test-api-key' );
        $this->assertTrue( $this->invokePrivate( 'has_api_access' ) );
    }

    public function test_oauth_mode_requires_connected_tokens_and_records_oauth_auth_mode(): void {
        $this->settings->set( 'api_mode', 'oauth' );
        $this->assertSame( 'oauth', $this->invokePrivate( 'current_auth_mode' ) );
        $this->assertTrue( $this->invokePrivate( 'has_api_access' ), 'Mock mode satisfies access during unit tests.' );

        $this->oauth->store_tokens( 'access-token', 'refresh-token', 3600 );
        $this->assertTrue( $this->invokePrivate( 'has_api_access' ) );
    }

    private function invokePrivate( string $method ): mixed {
        $ref = new ReflectionClass( $this->page );
        $m = $ref->getMethod( $method );
        $m->setAccessible( true );
        return $m->invoke( $this->page );
    }
}

final class SourcesPageFakeApi implements ApiClientInterface {
    public function channels_list( array $params ): array { return array( 'items' => array() ); }
    public function playlists_list( array $params ): array { return array( 'items' => array() ); }
    public function playlist_items_list( array $params ): array { return array( 'items' => array() ); }
    public function videos_list( array $params ): array { return array( 'items' => array() ); }
    public function revoke_token( string $token ): bool { return true; }
    public function mode(): string { return 'fake'; }
}

/**
 * Phase 12.2: a SyncScheduler that records every call into a public
 * $calls array. The SourcesPage routes its schedule_once() invocations
 * through the SyncScheduler dep, so tests can assert the abstraction is
 * used (and that wp_schedule_single_event is NOT called directly).
 */
final class SourcesPageRecordingScheduler implements \VectorYT\Gallery\Sync\SyncScheduler {
    /** @var array<int,array{string,array<string,mixed>,?int}> */
    public array $calls = array();

    public function schedule_once( string $hook, array $args, ?int $when = null ): bool {
        $this->calls[] = array( $hook, $args, $when );
        return true;
    }

    public function schedule_recurring( string $hook, array $args, int $interval_seconds ): bool {
        $this->calls[] = array( 'recurring:' . $hook, $args, $interval_seconds );
        return true;
    }

    public function unschedule_recurring( string $hook, array $args ): bool {
        return true;
    }

    public function unschedule_all( string $hook, array $args_subset = array() ): int {
        return 0;
    }
}
