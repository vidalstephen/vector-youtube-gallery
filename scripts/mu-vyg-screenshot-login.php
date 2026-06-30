<?php
/**
 * Temporary one-time screenshot login helper.
 *
 * Copied into wp-content/mu-plugins only while screenshot capture runs, then
 * removed by scripts/run-phase11-playwright.sh. Authenticates a preselected
 * admin user when the request includes a random token whose SHA-256 hash is
 * stored in the non-autoloaded option vyg_screenshot_token_hash.
 */

add_action( 'init', static function (): void {
    if ( empty( $_GET['vyg_screenshot_login'] ) ) {
        return;
    }

    $token = sanitize_text_field( wp_unslash( $_GET['vyg_screenshot_login'] ) );
    $expected = (string) get_option( 'vyg_screenshot_token_hash', '' );
    $actual = hash( 'sha256', $token );

    if ( '' === $expected || ! hash_equals( $expected, $actual ) ) {
        status_header( 403 );
        exit( 'Invalid screenshot login token.' );
    }

    delete_option( 'vyg_screenshot_token_hash' );
    $user_id = (int) get_option( 'vyg_screenshot_user_id', 1 );
    if ( $user_id <= 0 || ! get_user_by( 'id', $user_id ) ) {
        status_header( 403 );
        exit( 'Invalid screenshot user.' );
    }

    wp_set_current_user( $user_id );
    wp_set_auth_cookie( $user_id, false, is_ssl() );

    $redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : admin_url();
    wp_safe_redirect( $redirect ?: admin_url() );
    exit;
} );
