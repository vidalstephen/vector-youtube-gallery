<?php
/**
 * Helpers for stubbing WordPress functions during unit tests.
 *
 * Provides a tiny shim for get_option/update_option/delete_option so unit tests
 * can run without Brain\Monkey stubs polluting the global state.
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Support;

final class OptionsBag {

    /** @var array<string,mixed> */
    private static array $options = array();

    public static function reset(): void {
        self::$options = array();
    }

    public static function get( string $key, mixed $default = false ): mixed {
        return self::$options[ $key ] ?? $default;
    }

    public static function update( string $key, mixed $value, bool|string $autoload = null ): bool {
        self::$options[ $key ] = $value;
        return true;
    }

    public static function delete( string $key ): bool {
        unset( self::$options[ $key ] );
        return true;
    }

    /**
     * @return array<string,mixed>
     */
    public static function all(): array {
        return self::$options;
    }
}