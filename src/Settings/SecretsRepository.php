<?php
/**
 * Secrets repository — stores API keys, OAuth tokens, and other secrets.
 *
 * Design constraints (per plan §13):
 *   - Stored in wp_options with `autoload=no` so they don't ship in every request
 *   - Accessor methods that NEVER echo the raw value
 *   - Mask-aware: callers can ask "is this set?" without seeing the value
 *   - All read/write/delete methods are capability-checked where relevant
 *
 * Phase 1: API key only. Phase 2+ will add OAuth refresh/access tokens (encrypted).
 *
 * @package VectorYT\Gallery\Settings
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Settings;

defined( 'ABSPATH' ) || exit;

final class SecretsRepository {

    private const OPTION_KEY_API = 'vyg_api_key';
    private const OPTION_KEY_API_VALIDATED_AT = 'vyg_api_key_validated_at';
    private const OPTION_KEY_API_LAST_ERROR = 'vyg_api_key_last_error';

    /**
     * Store the YouTube Data API key. Overwrites any previous value.
     * Whitespace is trimmed; empty string deletes the option.
     */
    public function set_api_key( string $key ): bool {
        $key = trim( $key );
        if ( '' === $key ) {
            return $this->delete_api_key();
        }
        // Update validated_at to null — a new key needs re-validation.
        update_option( self::OPTION_KEY_API_VALIDATED_AT, null, false );
        return (bool) update_option(
            self::OPTION_KEY_API,
            $key,
            false  // autoload=no
        );
    }

    public function has_api_key(): bool {
        return false !== get_option( self::OPTION_KEY_API, false );
    }

    /**
     * Return the raw API key. Internal use only — never echo, never log, never
     * send to the browser. Caller's responsibility.
     */
    public function get_api_key(): ?string {
        $val = get_option( self::OPTION_KEY_API, null );
        if ( ! is_string( $val ) || '' === $val ) {
            return null;
        }
        return $val;
    }

    public function delete_api_key(): bool {
        delete_option( self::OPTION_KEY_API_VALIDATED_AT );
        delete_option( self::OPTION_KEY_API_LAST_ERROR );
        return (bool) delete_option( self::OPTION_KEY_API );
    }

    public function mark_api_key_validated(): void {
        update_option( self::OPTION_KEY_API_VALIDATED_AT, gmdate( 'c' ), false );
        delete_option( self::OPTION_KEY_API_LAST_ERROR );
    }

    public function mark_api_key_invalid( string $error_code, string $error_message ): void {
        update_option(
            self::OPTION_KEY_API_LAST_ERROR,
            array(
                'code'    => sanitize_key( $error_code ),
                'message' => sanitize_text_field( $error_message ),
                'at'      => gmdate( 'c' ),
            ),
            false
        );
    }

    /**
     * @return array{code:string,message:string,at:string}|null
     */
    public function get_api_key_last_error(): ?array {
        $val = get_option( self::OPTION_KEY_API_LAST_ERROR, null );
        return is_array( $val ) ? $val : null;
    }

    /**
     * Last successful validation timestamp, ISO 8601, or null.
     */
    public function get_api_key_validated_at(): ?string {
        $val = get_option( self::OPTION_KEY_API_VALIDATED_AT, null );
        return is_string( $val ) ? $val : null;
    }

    /**
     * Mask the key for display: first 4 + "***" + last 4.
     * Returns '' for empty keys, '***' for very short keys.
     */
    public static function mask( ?string $key ): string {
        if ( null === $key || '' === $key ) {
            return '';
        }
        if ( strlen( $key ) <= 8 ) {
            return '***';
        }
        return substr( $key, 0, 4 ) . '***' . substr( $key, -4 );
    }
}