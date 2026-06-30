<?php
/**
 * Phase 9 demo page generator. Run inside WP via:
 *   wp eval-file dev/phase9-create-pages.php
 *
 * Creates one page per new layout + preset + schema example.
 */
$source = null;
if (isset($wpdb)) {
    $source = $wpdb->get_var("SELECT source_uuid FROM {$wpdb->prefix}vyg_sources LIMIT 1");
} else {
    global $wpdb;
    $source = $wpdb->get_var("SELECT source_uuid FROM {$wpdb->prefix}vyg_sources LIMIT 1");
}
$source_uuid = is_string($source) ? $source : '';
if (empty($source_uuid)) {
    fwrite(STDERR, "No source found. Aborting.\n");
    exit(1);
}

$pages = array(
    array(
        'title'   => 'Phase 9 — Masonry Layout',
        'slug'    => 'phase-9-masonry',
        'shortcode' => '[youtube_feed source_uuid="' . $source_uuid . '" layout="masonry" columns="3" per_page="9" wrapper_id="vyg-p9-masonry"]',
    ),
    array(
        'title'   => 'Phase 9 — Carousel Layout',
        'slug'    => 'phase-9-carousel',
        'shortcode' => '[youtube_feed source_uuid="' . $source_uuid . '" layout="carousel" columns="3" per_page="9" wrapper_id="vyg-p9-carousel"]',
    ),
    array(
        'title'   => 'Phase 9 — Hero Layout',
        'slug'    => 'phase-9-hero',
        'shortcode' => '[youtube_feed source_uuid="' . $source_uuid . '" layout="hero" columns="3" per_page="9" wrapper_id="vyg-p9-hero"]',
    ),
    array(
        'title'   => 'Phase 9 — Cinema Preset',
        'slug'    => 'phase-9-preset-cinema',
        'shortcode' => '[youtube_feed source_uuid="' . $source_uuid . '" layout="grid" columns="3" per_page="6" preset="cinema" wrapper_id="vyg-p9-cinema"]',
    ),
    array(
        'title'   => 'Phase 9 — Pastel Preset',
        'slug'    => 'phase-9-preset-pastel',
        'shortcode' => '[youtube_feed source_uuid="' . $source_uuid . '" layout="grid" columns="3" per_page="6" preset="pastel" wrapper_id="vyg-p9-pastel"]',
    ),
    array(
        'title'   => 'Phase 9 — Schema.org JSON-LD',
        'slug'    => 'phase-9-schema',
        'shortcode' => '[youtube_feed source_uuid="' . $source_uuid . '" layout="grid" columns="3" per_page="6" schema_enabled="true" wrapper_id="vyg-p9-schema"]',
    ),
);

foreach ($pages as $p) {
    $existing = get_page_by_path($p['slug']);
    if ($existing) {
        wp_delete_post($existing->ID, true);
    }
    $page_id = wp_insert_post(array(
        'post_title'   => $p['title'],
        'post_name'    => $p['slug'],
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'post_content' => $p['shortcode'],
    ));
    echo "Created page {$p['slug']} (id={$page_id})\n";
}
echo "Done.\n";
