<?php
/**
 * Phase 14.12 — create 8 deterministic front-end pages (one per
 * layout) so the parity contact-sheet capture can iterate over them
 * with stable URLs. Idempotent on the per-layout slug.
 *
 * The pages embed the prototype's control settings (Phase 14.9 shared
 * header: feed_kicker / feed_title / feed_intro / feed_cta_label,
 * trust_strip, width=wide) so the contact sheet reflects the shipped
 * Phase 14 default panel, not an old default.
 *
 * Usage:
 *   docker exec -u www-data vyg-wp wp eval-file \
 *     /var/www/html/wp-content/plugins/vector-youtube-gallery/dev/seed-14-12-pages.php
 *
 * @package VectorYT\Gallery
 */

require_once '/var/www/html/wp-load.php';

global $wpdb;

// Use the real Way Of Holiness channel source for the parity sheet,
// not the first active demo source. This source is already synced in
// the dev DB and has 52 videos with real YouTube thumbnails.
$way_channel_id = 'UCETTSWoXxA-oEbwxqpbVf-w';
$source_uuid = (string) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT source_uuid FROM {$wpdb->prefix}vyg_sources WHERE status = 'active' AND youtube_channel_id = %s LIMIT 1",
		$way_channel_id
	)
);
if ( '' === $source_uuid ) {
	$source_uuid = (string) $wpdb->get_var(
		"SELECT source_uuid FROM {$wpdb->prefix}vyg_sources WHERE status = 'active' LIMIT 1"
	);
}
if ( '' === $source_uuid ) {
	fwrite( STDERR, "no active source; run dev/reseed-phase12.php first\n" );
	exit( 1 );
}

// Same per-layout content_type / column count as scripts/capture-way-of-holiness-layouts.sh
// so the parity sheet is comparable to the prototype's WOH captures
// in screenshots/way-of-holiness/layouts/.
$layouts = array(
	array(
		'layout'       => 'grid',
		'columns'      => 3,
		'content_type' => 'live_replay',
		'per_page'     => 12,
	),
	array(
		'layout'       => 'list',
		'columns'      => 3,
		'content_type' => 'live_replay',
		'per_page'     => 12,
	),
	array(
		'layout'       => 'featured',
		'columns'      => 3,
		'content_type' => 'live_replay',
		'per_page'     => 12,
	),
	array(
		'layout'       => 'hero',
		'columns'      => 3,
		'content_type' => 'live_replay',
		'per_page'     => 12,
	),
	array(
		'layout'       => 'shorts',
		'columns'      => 4,
		'content_type' => 'live_replay',
		'per_page'     => 12,
	),
	array(
		'layout'       => 'masonry',
		'columns'      => 3,
		'content_type' => 'live_replay',
		'per_page'     => 12,
	),
	array(
		'layout'       => 'carousel',
		'columns'      => 3,
		'content_type' => 'live_replay',
		'per_page'     => 12,
	),
	array(
		'layout'       => 'live',
		'columns'      => 3,
		'content_type' => 'live_active,live_upcoming,live_replay',
		'per_page'     => 12,
	),
);

$created = 0;
$updated = 0;
$ids     = array();

foreach ( $layouts as $row ) {
	$layout       = $row['layout'];
	$columns      = (int) $row['columns'];
	$content_type = $row['content_type'];
	$per_page     = (int) $row['per_page'];

	$slug  = 'vyg-14-12-' . $layout;
	$title = sprintf( 'VYG Phase 14.12 — %s layout (parity capture)', ucfirst( $layout ) );

	$shortcode = sprintf(
		'[youtube_feed source_uuid="%s" layout="%s" columns="%d" per_page="%d" content_type="%s" orderby="published_at" order="DESC" pagination="none" width="wide" feed_kicker="Parity" feed_title="VYG %s layout" feed_intro="Captured from the dev source for the Phase 14.12 final parity contact sheet." feed_cta_label="Watch" trust_strip="1"]',
		$source_uuid,
		$layout,
		$columns,
		$per_page,
		$content_type,
		$layout
	);

	$body  = "<!-- wp:paragraph -->\n";
	$body .= "<p>Phase 14.12 prototype-parity contact-sheet capture. Layout: <code>{$layout}</code>. Source: <code>{$source_uuid}</code>. content_type=<code>{$content_type}</code>, columns={$columns}, per_page={$per_page}.</p>\n";
	$body .= "<!-- /wp:paragraph -->\n\n";
	$body .= $shortcode;

	$existing = get_posts(
		array(
			'post_type'      => 'page',
			'name'           => $slug,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);

	if ( ! empty( $existing ) ) {
		wp_update_post(
			array(
				'ID'           => $existing[0],
				'post_title'   => $title,
				'post_content' => $body,
				'post_status'  => 'publish',
			)
		);
		$ids[] = (int) $existing[0];
		++$updated;
		echo "updated page id={$existing[0]} slug={$slug}\n";
	} else {
		$id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => $body,
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);
		$ids[] = (int) $id;
		++$created;
		echo "created page id={$id} slug={$slug}\n";
	}
}

echo "\nphase-14.12: created={$created} updated={$updated} total=" . count( $ids ) . "\n";
echo "page_ids=" . implode( ',', $ids ) . "\n";
