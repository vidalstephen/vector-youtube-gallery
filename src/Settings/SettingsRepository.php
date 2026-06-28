<?php
/**
 * Plugin settings repository — non-secret config.
 *
 * Phase 1: empty defaults; reserved for Phase 2 (sync intervals, retention windows,
 * default layout, etc.). Existence of this class lets the Container inject it now
 * so consumers don't need to null-check.
 *
 * @package VectorYT\Gallery\Settings
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsRepository {

    private const OPTION_KEY = 'vyg_settings';

    /**
     * @return array<string,mixed>
     */
    public function all(): array {
        $val = get_option( self::OPTION_KEY, array() );
        return is_array( $val ) ? $val : array();
    }

    /**
     * @param array<string,mixed> $settings
     */
    public function save( array $settings ): bool {
        $current = $this->all();
        $merged  = array_replace( $current, $settings );
        return (bool) update_option( self::OPTION_KEY, $merged, false );
    }

    public function get( string $key, mixed $default = null ): mixed {
        $all = $this->all();
        return $all[ $key ] ?? $default;
    }

    public function set( string $key, mixed $value ): bool {
        return $this->save( array( $key => $value ) );
    }

    /**
     * Defaults — single source of truth. Phase 1 returns an empty array;
     * Phase 2 will populate sync intervals, retention windows, etc.
     *
     * @return array<string,mixed>
     */
    public function defaults(): array {
        return array();
    }
}