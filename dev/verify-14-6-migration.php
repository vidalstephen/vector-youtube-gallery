<?php
// Phase 14.6 — verify the tone_color migration + db version.
// Run with: docker exec -u www-data vyg-wp wp eval-file /var/www/html/wp-content/plugins/vector-youtube-gallery/dev/verify-14-6-migration.php --path=/var/www/html

global $wpdb;

$table = $wpdb->prefix . 'vyg_videos';
$cols  = $wpdb->get_results( "DESCRIBE {$table}" );
$tone  = array_values( array_filter( (array) $cols, function ( $c ) { return strpos( $c->Field, 'tone' ) !== false; } ) );

echo "DESCRIBE {$table} — tone-related columns:\n";
if ( empty( $tone ) ) {
    echo "  (none — migration not applied)\n";
} else {
    foreach ( $tone as $c ) {
        echo "  - {$c->Field} | type: {$c->Type} | null: {$c->Null} | default: {$c->Default}\n";
    }
}

echo "db_version: " . get_option( 'vyg_db_version' ) . "\n";
echo "expected:   0.7.0\n";
