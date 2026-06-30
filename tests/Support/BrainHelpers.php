<?php
/**
 * Shared Brain\Monkey helpers.
 *
 * Loaded via composer's autoload-dev (tests/Support/) so any test can call
 * \VectorYT\Gallery\Tests\Support\BrainHelpers::stubEscapeFunctions().
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Support;

use Brain\Monkey\Functions;

final class BrainHelpers {

    /**
     * Stub the WP escaping/sanitizing helpers so normalizer + admin tests run
     * without booting WordPress. Real WP uses much more elaborate sanitization;
     * these stubs return the input as-is or perform trivial transforms.
     */
    public static function stubEscapeFunctions(): void {
        Functions\when( 'sanitize_text_field' )->alias( static fn( string $s ): string => trim( strip_tags( $s ) ) );
        Functions\when( 'sanitize_key' )->alias( static fn( string $s ): string => strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $s ) ) ) );
        Functions\when( 'esc_url_raw' )->alias( static fn( string $s ): string => $s );
        Functions\when( 'esc_url' )->alias( static fn( string $s ): string => $s );
        Functions\when( 'esc_html' )->alias( static fn( string $s ): string => $s );
        Functions\when( 'esc_html__' )->alias( static fn( string $s, ?string $domain = null ): string => $s );
        Functions\when( 'esc_html_e' )->alias( static function ( string $s, ?string $domain = null ): void { echo $s; } );
        Functions\when( 'esc_attr' )->alias( static fn( string $s ): string => $s );
        Functions\when( 'esc_attr__' )->alias( static fn( string $s, ?string $domain = null ): string => $s );
        Functions\when( 'esc_attr_e' )->alias( static function ( string $s, ?string $domain = null ): void { echo $s; } );
        Functions\when( 'wp_unslash' )->alias( static fn( $v ) => $v );
        Functions\when( 'wp_trim_words' )->alias( static function ( string $text, int $num_words = 55, $more = null ): string {
            $words = preg_split( '/\s+/', trim( $text ) );
            if ( count( $words ) <= $num_words ) {
                return $text;
            }
            return implode( ' ', array_slice( $words, 0, $num_words ) );
        } );
        Functions\when( 'wp_json_encode' )->alias( static fn( $data, $flags = 0, $depth = 512 ): string => json_encode( $data, $flags, $depth ) );

        // URL builders.
        Functions\when( 'add_query_arg' )->alias( static function ( $args, string $url ): string {
            if ( empty( $args ) ) {
                return $url;
            }
            if ( is_string( $args ) ) {
                // Legacy signature: add_query_arg('key', 'value', $url).
                return $url; // tests don't use this path.
            }
            $sep = strpos( $url, '?' ) === false ? '?' : '&';
            return $url . $sep . http_build_query( $args );
        } );
        // Note: we don't stub rawurlencode — it's a real PHP function. WP's
        // production code calls the global function, which falls through to PHP.

        // Helpers used by load-more / asset logic.
        Functions\when( '__' )->alias( static fn( string $text ): string => $text );
        Functions\when( '_n' )->alias( static fn( string $single, string $plural, int $number ): string => 1 === $number ? $single : $plural );
        // number_format_i18n: production calls this. Tests don't actually need to stub
        // (we don't assert format string), so we let it fall through. But to be safe
        // we provide a passthrough that uses sprintf to avoid the patchwork whitelist.
        Functions\when( 'number_format_i18n' )->alias( static function ( $num, ?int $decimals = null ): string {
            return number_format( (float) $num, $decimals ?? 0, '.', ',' );
        } );

        // Theme helpers used by TemplateLoader.
        Functions\when( 'get_template_directory' )->alias( static fn(): string => '/tmp/fake-theme' );
        Functions\when( 'get_stylesheet_directory' )->alias( static fn(): string => '/tmp/fake-theme' );

        // WP capabilities (no-op default; tests that need to assert gating override).
        Functions\when( 'current_user_can' )->alias( static fn( string $cap ): bool => true );
        Functions\when( 'is_readable' )->alias( static fn( string $path ): bool => file_exists( $path ) );
        Functions\when( 'wp_create_nonce' )->alias( static fn( string $action ): string => 'nonce-' . md5( $action ) );
        Functions\when( 'current_time' )->alias( static fn( string $type = 'mysql', ?int $gmt = null ): string => gmdate( 'Y-m-d H:i:s' ) );
        Functions\when( 'wp_get_current_user' )->alias( static fn() => (object) array( 'ID' => 0, 'user_login' => '' ) );
        Functions\when( 'wp_generate_uuid4' )->alias( static fn(): string => sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int( 0, 0xffff ), random_int( 0, 0xffff ),
            random_int( 0, 0xffff ),
            random_int( 0, 0x0fff ) | 0x4000,
            random_int( 0, 0x3fff ) | 0x8000,
            random_int( 0, 0xffff ), random_int( 0, 0xffff ), random_int( 0, 0xffff )
        ) );
        Functions\when( 'date_i18n' )->alias( static function ( string $fmt, ?int $ts = null ): string {
            return gmdate( $fmt, $ts ?? time() );
        } );
        Functions\when( 'mysql2date' )->alias( static function ( string $format, string $date ): string {
            $ts = strtotime( $date . ' UTC' );
            return $ts ? gmdate( $format, $ts ) : $date;
        } );
    }

    /**
     * Stub the WP option helpers via the OptionsBag.
     */
    public static function stubOptionFunctions(): void {
        Functions\when( 'get_option' )->alias( static fn( string $key, $default = false ) => OptionsBag::get( $key, $default ) );
        Functions\when( 'update_option' )->alias( static fn( string $key, $value, $autoload = null ) => OptionsBag::update( $key, $value, $autoload ) );
        Functions\when( 'delete_option' )->alias( static fn( string $key ) => OptionsBag::delete( $key ) );
    }
}