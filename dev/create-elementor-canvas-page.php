<?php
/**
 * Create an Elementor "Canvas" page that renders the Phase 13.1 grid with
 * the full-width template (no theme header/footer, just the Elementor canvas).
 *
 * The Elementor Canvas page template is "_elementor_page_layout" => "elementor_canvas".
 */

if ( ! defined( 'ABSPATH' ) ) {
    require '/var/www/html/wp-load.php';
}

// 1. Create the page with the Elementor Canvas template.
$page_id = wp_insert_post(
    [
        'post_title'   => 'Phase 13.1 — Elementor Canvas',
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'post_content' => '', // Elementor manages the content.
        'page_template' => 'elementor_canvas', // WP page template.
    ],
    true
);

if ( is_wp_error( $page_id ) ) {
    fwrite( STDERR, 'Error creating page: ' . $page_id->get_error_message() . "\n" );
    exit( 1 );
}

echo 'page_id=' . $page_id . PHP_EOL;

// 2. Set Elementor edit mode and page layout.
update_post_meta( $page_id, '_elementor_edit_mode', 'builder' );
update_post_meta( $page_id, '_elementor_template_type', 'wp-page' );

// 3. Set the Elementor page layout to "elementor_canvas" (full canvas, no header/footer).
update_post_meta( $page_id, '_elementor_page_layout', 'elementor_canvas' );

// Also set the WP page template (for some themes).
update_post_meta( $page_id, '_wp_page_template', 'elementor_canvas' );

// 4. Build Elementor widget data — use the "with-header" feed with the redesigned grid.
$elementor_data = [
    [
        'id'       => 'vyg13canvas',
        'elType'   => 'container',
        'settings' => [
            'content_width' => 'full',
            'padding'       => [
                'unit'  => 'px',
                'top'   => '40',
                'right' => '40',
                'bottom'=> '40',
                'left'  => '40',
            ],
        ],
        'elements' => [
            [
                'id'          => 'vyg13widget',
                'elType'      => 'widget',
                'widgetType'  => 'vyg_gallery',
                'settings'    => [
                    'feed_uuid'         => 'phase-13-1-with-header',
                    'layout'            => 'grid',
                    'columns'           => 3,
                    'per_page'          => 12,
                    'density'           => 'comfortable',
                    'header_title'      => 'YouTube Feed — Elementor Canvas',
                    'header_subtitle'   => 'Full-width grid in Elementor Canvas template',
                    'header_cta_label'  => 'View on YouTube',
                    'header_cta_url'    => 'https://youtube.com',
                    'header_columns_visible' => 'yes',
                    'trust_strip'       => 'yes',
                    'show_channel_name' => 'yes',
                    'show_views_and_time' => 'yes',
                    'schema_enabled'    => 'yes',
                ],
                'elements'    => [],
            ],
        ],
        'isInner'  => false,
    ],
];

update_post_meta( $page_id, '_elementor_data', wp_slash( wp_json_encode( $elementor_data ) ) );

// 5. Update Elementor CSS.
update_post_meta( $page_id, '_elementor_css', '' );

echo 'elementor_data_set=yes' . PHP_EOL;
echo 'page_layout=elementor_canvas' . PHP_EOL;
echo 'url=' . get_permalink( $page_id ) . PHP_EOL;