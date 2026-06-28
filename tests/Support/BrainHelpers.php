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
        Functions\when( 'esc_attr' )->alias( static fn( string $s ): string => $s );
        Functions\when( 'wp_unslash' )->alias( static fn( $v ) => $v );
        Functions\when( 'wp_trim_words' )->alias( static function ( string $text, int $num_words = 55, $more = null ): string {
            $words = preg_split( '/\s+/', trim( $text ) );
            if ( count( $words ) <= $num_words ) {
                return $text;
            }
            return implode( ' ', array_slice( $words, 0, $num_words ) );
        } );
        Functions\when( 'wp_json_encode' )->alias( static fn( $data, $flags = 0, $depth = 512 ): string => json_encode( $data, $flags, $depth ) );
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