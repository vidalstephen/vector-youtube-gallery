<?php
/**
 * Phase 14.1 — create a single page that stacks all 3 width modes so
 * a single screenshot shows the width classes side-by-side. Run once
 * per Phase 14.1 capture; safe to re-run (idempotent on the page slug).
 *
 * Usage: docker exec -u www-data vyg-wp wp eval-file /var/www/html/wp-content/plugins/vector-youtube-gallery/dev/seed-14-1-page.php
 */

require_once '/var/www/html/wp-load.php';

global $wpdb;
$source_uuid = (string) $wpdb->get_var(
    "SELECT source_uuid FROM {$wpdb->prefix}vyg_sources WHERE status = 'active' LIMIT 1"
);
if ( '' === $source_uuid ) {
    fwrite( STDERR, "no active source; run dev/reseed-phase12.php first\n" );
    exit( 1 );
}

$page_slug  = 'vyg-14-1-width-modes';
$page_title = 'VYG Phase 14.1 — Width Modes (theme / wide / full)';

$existing = get_posts( array(
    'post_type'      => 'page',
    'name'           => $page_slug,
    'post_status'    => 'any',
    'posts_per_page' => 1,
    'fields'         => 'ids',
) );

$content = <<<HTML
<!-- wp:paragraph -->
<p>Phase 14.1 prototype-parity check. The same grid layout rendered in all three width modes. Each block uses the same data source; only the `width` attribute differs.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2>Width: theme (~880px)</h2>
<!-- /wp:heading -->

[youtube_feed source_uuid="$source_uuid" layout="grid" per_page="3" columns="3" width="theme"]

<!-- wp:heading {"level":2} -->
<h2>Width: wide (~1180px, the default)</h2>
<!-- /wp:heading -->

[youtube_feed source_uuid="$source_uuid" layout="grid" per_page="3" columns="3" width="wide"]

<!-- wp:heading {"level":2} -->
<h2>Width: full (100%)</h2>
<!-- /wp:heading -->

[youtube_feed source_uuid="$source_uuid" layout="grid" per_page="3" columns="3" width="full"]
HTML;

if ( ! empty( $existing ) ) {
    wp_update_post( array(
        'ID'           => $existing[0],
        'post_title'   => $page_title,
        'post_content' => $content,
        'post_status'  => 'publish',
    ) );
    echo "updated page id={$existing[0]} slug={$page_slug}\n";
} else {
    $id = wp_insert_post( array(
        'post_title'   => $page_title,
        'post_name'    => $page_slug,
        'post_content' => $content,
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ) );
    echo "created page id={$id} slug={$page_slug}\n";
}
