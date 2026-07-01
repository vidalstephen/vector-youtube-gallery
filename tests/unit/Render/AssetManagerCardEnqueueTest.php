<?php
/**
 * Phase C1 — AssetManager enqueues the shared card stylesheet.
 *
 * The card system is shared across all 8 layouts (grid, list, featured,
 * shorts, live, masonry, carousel, hero). Every layout that uses shared
 * cards must enqueue the `vyg-card` handle (registered against
 * `assets/css/card.css`) so the shared `.vyg-card*`, `.vyg-density*`,
 * `.vyg-thumb-ratio*` selectors are available on the page.
 *
 * Contract:
 *
 *   1. After AssetManager::enqueue_for_layout($layout), the `vyg-card`
 *      handle is registered (wp_style_is( 'vyg-card', 'registered' )).
 *   2. After enqueue_for_layout($layout), the `vyg-card` handle is
 *      enqueued (wp_style_is( 'vyg-card', 'enqueued' )).
 *   3. The same is true for every layout in the AssetManager's map
 *      (8 layouts: grid, list, featured, shorts, live, masonry,
 *      carousel, hero).
 *   4. The `vyg-card` handle depends on the `vyg` (base) handle, so the
 *      `deps` array passed to wp_register_style('vyg-card', ...) contains
 *      'vyg'.
 *
 * The new public method under test is AssetManager::enqueue_card_assets().
 * It is called from enqueue_for_layout() for every layout that uses the
 * shared card system.
 *
 * @package VectorYT\Gallery\Tests\Unit\Render
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Render;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Render\AssetManager;
use VectorYT\Gallery\Tests\Support\BrainHelpers;

require_once __DIR__ . '/../../bootstrap.php';

/**
 * Test-scoped holder for the fake WP_Styles state.
 *
 * We can't reuse $this inside Brain Monkey closure stubs (the closures
 * are bound to a different scope), so we keep the state in a static
 * holder that's reset by setUp().
 */
final class CardEnqueueFakeStyles {

    /** @var array<string,array{deps:array<int,string>}> */
    public static array $registered = array();

    /** @var array<string,bool> */
    public static array $enqueued = array();

    public static function reset(): void {
        self::$registered = array();
        self::$enqueued   = array();
    }

    public static function is_registered( string $handle ): bool {
        return isset( self::$registered[ $handle ] );
    }

    public static function is_enqueued( string $handle ): bool {
        return isset( self::$enqueued[ $handle ] );
    }

    public static function deps( string $handle ): array {
        return self::$registered[ $handle ]['deps'] ?? array();
    }
}

final class AssetManagerCardEnqueueTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        BrainHelpers::stubEscapeFunctions();
        BrainHelpers::stubOptionFunctions();
        CardEnqueueFakeStyles::reset();

        // Analytics uses get_option('vyg_analytics_enabled', ...) to gate
        // the analytics script. We want analytics disabled so
        // AssetManager::enqueue_analytics() is a no-op (otherwise it
        // would try to call wp_register_script / wp_localize_script,
        // which we DO stub, but keeping the path short is cleaner).
        Functions\when( 'get_option' )->alias(
            static function ( string $key, $default = false ) {
                if ( 'vyg_analytics_enabled' === $key ) {
                    return false;
                }
                return $default;
            }
        );

        // Stub WP enqueue / register functions to record into our
        // static fake. These closures are bound to Brain\Monkey's
        // scope (not $this), so we use a static holder.
        Functions\when( 'wp_register_style' )->alias(
            static function ( string $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
                CardEnqueueFakeStyles::$registered[ $handle ] = array(
                    'deps' => is_array( $deps ) ? $deps : array(),
                );
            }
        );
        Functions\when( 'wp_enqueue_style' )->alias(
            static function ( $handle ) {
                if ( is_array( $handle ) ) {
                    foreach ( $handle as $h ) {
                        CardEnqueueFakeStyles::$enqueued[ (string) $h ] = true;
                    }
                    return;
                }
                CardEnqueueFakeStyles::$enqueued[ (string) $handle ] = true;
            }
        );

        // wp_style_is() is called by tests AND by the AssetManager path
        // in some cases. We make it return based on the recorded state.
        Functions\when( 'wp_style_is' )->alias(
            static function ( string $handle, string $list = 'enqueued' ) {
                if ( 'registered' === $list ) {
                    return CardEnqueueFakeStyles::is_registered( $handle );
                }
                if ( 'enqueued' === $list || 'queue' === $list ) {
                    return CardEnqueueFakeStyles::is_enqueued( $handle );
                }
                if ( 'done' === $list ) {
                    return false;
                }
                return false;
            }
        );

        // The analytics, lightbox, and carousel enqueue paths call a
        // handful of other WP functions. Stub them to no-ops.
        Functions\when( 'wp_register_script' )->alias( static function (): void {} );
        Functions\when( 'wp_enqueue_script' )->alias( static function (): void {} );
        Functions\when( 'wp_localize_script' )->alias( static function (): void {} );
        Functions\when( 'wp_set_script_translations' )->alias( static function (): void {} );
        Functions\when( 'rest_url' )->alias( static fn( string $path = '' ): string => 'https://example.test/wp-json/' . ltrim( $path, '/' ) );
        Functions\when( 'wp_create_nonce' )->alias( static fn( string $action ): string => 'nonce-' . md5( $action ) );
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function layoutProvider(): array {
        return array(
            'grid'     => array( 'grid' ),
            'list'     => array( 'list' ),
            'featured' => array( 'featured' ),
            'shorts'   => array( 'shorts' ),
            'live'     => array( 'live' ),
            'masonry'  => array( 'masonry' ),
            'carousel' => array( 'carousel' ),
            'hero'     => array( 'hero' ),
        );
    }

    public function test_vyg_card_handle_is_registered_after_enqueue_for_layout_grid(): void {
        $am = new AssetManager();
        $am->enqueue_for_layout( 'grid' );
        $this->assertTrue(
            CardEnqueueFakeStyles::is_registered( 'vyg-card' ),
            'vyg-card handle must be registered after enqueue_for_layout(grid)'
        );
    }

    public function test_vyg_card_handle_is_enqueued_after_enqueue_for_layout_grid(): void {
        $am = new AssetManager();
        $am->enqueue_for_layout( 'grid' );
        $this->assertTrue(
            CardEnqueueFakeStyles::is_enqueued( 'vyg-card' ),
            'vyg-card handle must be enqueued after enqueue_for_layout(grid)'
        );
    }

    /**
     * @dataProvider layoutProvider
     */
    public function test_vyg_card_handle_is_registered_for_every_layout( string $layout ): void {
        $am = new AssetManager();
        $am->enqueue_for_layout( $layout );
        $this->assertTrue(
            CardEnqueueFakeStyles::is_registered( 'vyg-card' ),
            "vyg-card handle must be registered after enqueue_for_layout({$layout})"
        );
    }

    /**
     * @dataProvider layoutProvider
     */
    public function test_vyg_card_handle_is_enqueued_for_every_layout( string $layout ): void {
        $am = new AssetManager();
        $am->enqueue_for_layout( $layout );
        $this->assertTrue(
            CardEnqueueFakeStyles::is_enqueued( 'vyg-card' ),
            "vyg-card handle must be enqueued after enqueue_for_layout({$layout})"
        );
    }

    public function test_vyg_card_handle_depends_on_vyg_base_handle(): void {
        $am = new AssetManager();
        $am->enqueue_for_layout( 'grid' );
        $this->assertTrue(
            CardEnqueueFakeStyles::is_registered( 'vyg-card' ),
            'vyg-card must be registered'
        );
        $this->assertContains(
            'vyg',
            CardEnqueueFakeStyles::deps( 'vyg-card' ),
            'vyg-card must depend on the base vyg handle so card.css loads after base.css'
        );
    }

    public function test_enqueue_card_assets_method_exists_and_is_public(): void {
        $am = new AssetManager();
        $this->assertTrue(
            method_exists( $am, 'enqueue_card_assets' ),
            'AssetManager must expose public enqueue_card_assets() method'
        );
    }

    public function test_enqueue_card_assets_can_be_called_directly_and_is_idempotent(): void {
        $am = new AssetManager();
        // First call registers + enqueues.
        $am->enqueue_card_assets();
        $this->assertTrue(
            CardEnqueueFakeStyles::is_registered( 'vyg-card' ),
            'vyg-card must be registered after direct enqueue_card_assets() call'
        );
        $this->assertTrue(
            CardEnqueueFakeStyles::is_enqueued( 'vyg-card' ),
            'vyg-card must be enqueued after direct enqueue_card_assets() call'
        );
        // Second call must remain enqueued (idempotent — no exception,
        // state remains valid).
        $am->enqueue_card_assets();
        $this->assertTrue(
            CardEnqueueFakeStyles::is_enqueued( 'vyg-card' ),
            'second enqueue_card_assets() call must remain enqueued (idempotent)'
        );
    }
}
