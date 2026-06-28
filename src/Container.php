<?php
/**
 * Minimal service-locator for the Vector YouTube Gallery plugin.
 *
 * Why a container instead of \VectorYT\Gallery\Plugin::get_*() everywhere?
 *   1. Testability — swap implementations in tests via set().
 *   2. Lazy instantiation — services aren't constructed unless asked for.
 *   3. Single resolution point — easier to add caching/memoization later.
 *
 * Phase 0: this is a stub. Phase 1+ will register real services here.
 *
 * @package VectorYT\Gallery
 */

declare(strict_types=1);

namespace VectorYT\Gallery;

defined( 'ABSPATH' ) || exit;

final class Container {

    /**
     * @var array<string, callable>
     */
    private array $factories = array();

    /**
     * @var array<string, object>
     */
    private array $instances = array();

    /**
     * Register a factory under an id. Factory runs once on first get().
     *
     * @param string   $id      Service id, e.g. 'logger'.
     * @param callable $factory Zero-arg factory returning the service object.
     */
    public function set( string $id, callable $factory ): void {
        $this->factories[ $id ] = $factory;
        unset( $this->instances[ $id ] ); // invalidate any cached instance
    }

    /**
     * Resolve a service by id. Returns null if not registered (Phase 0 behavior).
     * In later phases this should throw instead of returning null, to surface
     * missing dependencies loudly during development.
     */
    public function get( string $id ): ?object {
        if ( isset( $this->instances[ $id ] ) ) {
            return $this->instances[ $id ];
        }
        if ( ! isset( $this->factories[ $id ] ) ) {
            return null;
        }
        $obj = ( $this->factories[ $id ] )();
        $this->instances[ $id ] = $obj;
        return $obj;
    }

    /**
     * Test helper — drop all registered services.
     */
    public function reset(): void {
        $this->factories = array();
        $this->instances = array();
    }
}