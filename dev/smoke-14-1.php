<?php
/**
 * Phase 14.1 live smoke — render the grid layout with each width mode
 * and confirm the rendered HTML carries the right CSS class and
 * data-vyg-width attribute.
 *
 * Usage: docker exec vyg-wp php /var/www/html/wp-content/plugins/vector-youtube-gallery/dev/smoke-14-1.php
 */

require_once '/var/www/html/wp-load.php';

$container = \VectorYT\Gallery\Plugin::container();
$renderer  = $container->get( 'render.renderer' );

// Pick the first active source we can find. The dev install seeds
// at least one source as part of dev/reseed-phase12.php.
global $wpdb;
$source_uuid = (string) $wpdb->get_var(
    "SELECT source_uuid FROM {$wpdb->prefix}vyg_sources WHERE status = 'active' LIMIT 1"
);
if ( '' === $source_uuid ) {
    fwrite( STDERR, "no active source found; run dev/reseed-phase12.php first\n" );
    exit( 1 );
}

$exit_code = 0;
foreach ( array( 'theme', 'wide', 'full' ) as $mode ) {
    $args = array(
        'source_uuid' => $source_uuid,
        'layout'      => 'grid',
        'per_page'    => 1,
        'width'       => $mode,
        'wrapper_id'  => 'smoke-14-1-' . $mode,
    );
    $html = $renderer->render( $args );
    $has_class = (bool) preg_match( '/class="[^"]*\bvyg-' . preg_quote( $mode, '/' ) . '\b/', $html );
    $has_data  = (bool) preg_match( '/\bdata-vyg-width="' . preg_quote( $mode, '/' ) . '"/', $html );

    $status = ( $has_class && $has_data ) ? 'OK' : 'FAIL';
    if ( 'OK' !== $status ) { $exit_code = 1; }
    fwrite( STDOUT, sprintf(
        "[%s] width=%s: class_present=%s data_attr_present=%s bytes=%d\n",
        $status,
        $mode,
        $has_class ? 'yes' : 'no',
        $has_data  ? 'yes' : 'no',
        strlen( $html )
    ) );
}

// Garbage value falls back to 'wide'.
$args_garbage = array(
    'source_uuid' => $source_uuid,
    'layout'      => 'grid',
    'per_page'    => 1,
    'width'       => 'extralarge',
    'wrapper_id'  => 'smoke-14-1-garbage',
);
$html_garbage = $renderer->render( $args_garbage );
$garbage_ok   = (bool) preg_match( '/\bdata-vyg-width="wide"/', $html_garbage );
$status_garbage = $garbage_ok ? 'OK' : 'FAIL';
if ( 'OK' !== $status_garbage ) { $exit_code = 1; }
fwrite( STDOUT, sprintf(
    "[%s] width=extralarge -> normalized to wide: %s\n",
    $status_garbage,
    $garbage_ok ? 'yes' : 'no'
) );

exit( $exit_code );
