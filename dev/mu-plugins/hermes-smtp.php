<?php
/**
 * Plugin Name: Hermes SMTP (user)
 * Description: Use the user SMTP bundle from /run/secrets/smtp-config.
 *              The bundle is loaded by the host, not by this file.
 *              If the bundle is missing or still contains the placeholder,
 *              this mu-plugin stays inert (refuses to register a transport).
 */

if ( ! defined( 'ABSPATH' ) ) {
    return;
}

$smtp_secret = '/run/secrets/smtp-config';

if ( ! is_readable( $smtp_secret ) ) {
    return;
}

// Plain key=value parser. Handles unquoted values and values wrapped in
// "double" or 'single' quotes. Strips comments. No INI quirks.
$creds = array();
$lines = file( $smtp_secret, FILE_IGNORE_NEW_LINES );
if ( ! is_array( $lines ) ) {
    return;
}
foreach ( $lines as $line ) {
    $line = trim( $line );
    if ( $line === '' || $line[0] === '#' ) {
        continue;
    }
    if ( strpos( $line, '=' ) === false ) {
        continue;
    }
    list( $k, $v ) = explode( '=', $line, 2 );
    $k = trim( $k );
    $v = trim( $v );
    if ( strlen( $v ) >= 2 && ( ( $v[0] === '"' && substr( $v, -1 ) === '"' ) || ( $v[0] === "'" && substr( $v, -1 ) === "'" ) ) ) {
        $v = substr( $v, 1, -1 );
    }
    $creds[ $k ] = $v;
}

$password = isset( $creds['HERMES_SMTP_PASSWORD'] ) ? (string) $creds['HERMES_SMTP_PASSWORD'] : '';
if ( $password === '' || strpos( $password, '__INJECT' ) === 0 ) {
    return;
}

add_action( 'phpmailer_init', static function ( $phpmailer ) use ( $creds ) {
    if ( ! is_object( $phpmailer ) || ! method_exists( $phpmailer, 'isSMTP' ) ) {
        return;
    }
    $phpmailer->isSMTP();
    $phpmailer->Host       = (string) ( $creds['HERMES_SMTP_HOST']       ?? 'smtp.gmail.com' );
    $phpmailer->Port       = (int)    ( $creds['HERMES_SMTP_PORT']       ?? 587 );
    $phpmailer->SMTPSecure = (string) ( $creds['HERMES_SMTP_ENCRYPTION'] ?? 'tls' );
    $phpmailer->SMTPAuth   = true;
    $phpmailer->Username   = (string) ( $creds['HERMES_SMTP_USERNAME']   ?? '' );
    $phpmailer->Password   = (string) ( $creds['HERMES_SMTP_PASSWORD']   ?? '' );
    $phpmailer->From       = (string) ( $creds['HERMES_SMTP_FROM']       ?? $phpmailer->Username );
    $phpmailer->FromName   = (string) ( $creds['HERMES_SMTP_FROM_NAME']   ?? 'WordPress' );
} );
