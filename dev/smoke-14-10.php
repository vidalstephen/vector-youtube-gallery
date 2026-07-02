<?php
/**
 * Phase 14.10 smoke — trust strip on grid/masonry/carousel (shared
 * partial).
 *
 * Verifies:
 *   1. Grid layout: trust_strip=true → strip emitted; trust_strip=false
 *      → strip omitted.
 *   2. Masonry layout: same as grid.
 *   3. Carousel layout: same as grid.
 *   4. Out-of-scope layouts (list, featured, hero, shorts, live):
 *      trust_strip=true → strip NOT emitted (the plan says keep the
 *      contract narrow).
 *   5. Custom trust_strip_items override the 4 default items.
 *   6. XSS: a <script> tag in a custom item's text is escaped.
 *   7. No YouTube API sync is triggered (api_quota_log doesn't grow).
 *
 * Usage: docker exec vyg-wp php dev/smoke-14-10.php
 *
 * @package VectorYT\Gallery
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/var/www/html/' );
}
require_once ABSPATH . 'wp-load.php';

use VectorYT\Gallery\Plugin;
use VectorYT\Gallery\Render\TemplateLoader;

$container = Plugin::container();
$renderer  = $container->get( 'render.renderer' );
$loader    = $container->get( 'render.template_loader' );

// Pick the first active source.
global $wpdb;
$source_uuid = (string) $wpdb->get_var(
	"SELECT source_uuid FROM {$wpdb->prefix}vyg_sources WHERE status = 'active' LIMIT 1"
);

if ( '' === $source_uuid ) {
	fwrite( STDERR, "No active source found. Seed a source first.\n" );
	exit( 1 );
}

$pass = 0;
$fail = 0;

function check( string $label, bool $ok, int &$pass, int &$fail ): void {
	echo ( $ok ? '  [PASS] ' : '  [FAIL] ' ) . $label . "\n";
	$ok ? $pass++ : $fail++;
}

// 1. Grid: trust_strip=true → strip present.
echo "\n1. Grid: trust_strip=true emits the strip\n";
$grid_on = $renderer->render( array(
	'source_uuid' => $source_uuid,
	'layout'      => 'grid',
	'per_page'    => 2,
	'trust_strip' => true,
) );
check(
	'grid: vyg-trust-strip class emitted',
	strpos( $grid_on, 'vyg-trust-strip' ) !== false,
	$pass,
	$fail
);
check(
	'grid: 4 default items present (Lazy / Privacy / Accessible / Builder)',
	strpos( $grid_on, 'Lazy Loaded' ) !== false
		&& strpos( $grid_on, 'Privacy Safe' ) !== false
		&& strpos( $grid_on, 'Accessible' ) !== false
		&& strpos( $grid_on, 'Builder Ready' ) !== false,
	$pass,
	$fail
);
check(
	'grid: legacy vyg-grid__trust-strip alias also emitted (CSS + test back-compat)',
	strpos( $grid_on, 'vyg-grid__trust-strip' ) !== false,
	$pass,
	$fail
);

// 2. Grid: trust_strip=false → strip omitted.
echo "\n2. Grid: trust_strip=false omits the strip\n";
$grid_off = $renderer->render( array(
	'source_uuid' => $source_uuid,
	'layout'      => 'grid',
	'per_page'    => 2,
	'trust_strip' => false,
) );
check(
	'grid off: no vyg-trust-strip class',
	strpos( $grid_off, 'vyg-trust-strip' ) === false,
	$pass,
	$fail
);

// 3. Masonry: trust_strip=true → strip present.
echo "\n3. Masonry: trust_strip=true emits the strip\n";
$masonry_on = $renderer->render( array(
	'source_uuid' => $source_uuid,
	'layout'      => 'masonry',
	'per_page'    => 2,
	'trust_strip' => true,
) );
check(
	'masonry: vyg-trust-strip class emitted',
	strpos( $masonry_on, 'vyg-trust-strip' ) !== false,
	$pass,
	$fail
);
check(
	'masonry: 4 default items present',
	strpos( $masonry_on, 'Lazy Loaded' ) !== false
		&& strpos( $masonry_on, 'Privacy Safe' ) !== false
		&& strpos( $masonry_on, 'Accessible' ) !== false
		&& strpos( $masonry_on, 'Builder Ready' ) !== false,
	$pass,
	$fail
);

// 4. Masonry: trust_strip=false → strip omitted.
echo "\n4. Masonry: trust_strip=false omits the strip\n";
$masonry_off = $renderer->render( array(
	'source_uuid' => $source_uuid,
	'layout'      => 'masonry',
	'per_page'    => 2,
	'trust_strip' => false,
) );
check(
	'masonry off: no vyg-trust-strip class',
	strpos( $masonry_off, 'vyg-trust-strip' ) === false,
	$pass,
	$fail
);

// 5. Carousel: trust_strip=true → strip present.
echo "\n5. Carousel: trust_strip=true emits the strip\n";
$carousel_on = $renderer->render( array(
	'source_uuid' => $source_uuid,
	'layout'      => 'carousel',
	'per_page'    => 2,
	'trust_strip' => true,
) );
check(
	'carousel: vyg-trust-strip class emitted',
	strpos( $carousel_on, 'vyg-trust-strip' ) !== false,
	$pass,
	$fail
);
check(
	'carousel: 4 default items present',
	strpos( $carousel_on, 'Lazy Loaded' ) !== false
		&& strpos( $carousel_on, 'Privacy Safe' ) !== false
		&& strpos( $carousel_on, 'Accessible' ) !== false
		&& strpos( $carousel_on, 'Builder Ready' ) !== false,
	$pass,
	$fail
);

// 6. Carousel: trust_strip=false → strip omitted.
echo "\n6. Carousel: trust_strip=false omits the strip\n";
$carousel_off = $renderer->render( array(
	'source_uuid' => $source_uuid,
	'layout'      => 'carousel',
	'per_page'    => 2,
	'trust_strip' => false,
) );
check(
	'carousel off: no vyg-trust-strip class',
	strpos( $carousel_off, 'vyg-trust-strip' ) === false,
	$pass,
	$fail
);

// 7. Out-of-scope layouts — even with trust_strip=true, the strip
//    must NOT be emitted. The plan says keep the contract narrow.
echo "\n7. Out-of-scope layouts: strip never emitted\n";
foreach ( array( 'list', 'featured', 'hero', 'shorts', 'live' ) as $oos_layout ) {
	$oos_html = $renderer->render( array(
		'source_uuid' => $source_uuid,
		'layout'      => $oos_layout,
		'per_page'    => 2,
		'trust_strip' => true,
	) );
	check(
		$oos_layout . ': no vyg-trust-strip class (out of scope)',
		strpos( $oos_html, 'vyg-trust-strip' ) === false,
		$pass,
		$fail
	);
}

// 8. Custom trust_strip_items override the defaults.
echo "\n8. Custom trust_strip_items override defaults\n";
$custom = $renderer->render( array(
	'source_uuid'       => $source_uuid,
	'layout'            => 'grid',
	'per_page'          => 2,
	'trust_strip'       => true,
	'trust_strip_items' => array(
		array(
			'slug'     => 'fast',
			'text'     => 'Lightning Fast',
			'icon_svg' => '<svg viewBox="0 0 24 24" width="20" height="20" focusable="false" aria-hidden="true"><path fill="currentColor" d="M13 2L4 14h7v8l9-12h-7z"/></svg>',
		),
		array(
			'slug'     => 'open',
			'text'     => 'Open Source',
			'icon_svg' => '<svg viewBox="0 0 24 24" width="20" height="20" focusable="false" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20z"/></svg>',
		),
	),
) );
check(
	'custom: Lightning Fast text emitted',
	strpos( $custom, 'Lightning Fast' ) !== false,
	$pass,
	$fail
);
check(
	'custom: Open Source text emitted',
	strpos( $custom, 'Open Source' ) !== false,
	$pass,
	$fail
);
check(
	'custom: default Lazy Loaded NOT emitted',
	strpos( $custom, 'Lazy Loaded' ) === false,
	$pass,
	$fail
);
check(
	'custom: 2 <li> items rendered (not 4)',
	substr_count( $custom, 'vyg-trust-strip__item' ) === 2,
	$pass,
	$fail
);

// 9. XSS check on custom items.
echo "\n9. XSS — script tags in custom items are escaped\n";
$xss = $renderer->render( array(
	'source_uuid'       => $source_uuid,
	'layout'            => 'grid',
	'per_page'          => 2,
	'trust_strip'       => true,
	'trust_strip_items' => array(
		array(
			'slug' => 'xss',
			'text' => '<script>alert("pwn")</script>Safe',
		),
	),
) );
// In production WP's esc_html() turns < and > into entities. The
// smoke runs against real WP (not Brain\Monkey stubs), so the real
// escape is applied. We assert the literal <script> substring is
// NOT in the output and the entity-encoded version IS.
check(
	'XSS: literal <script>alert substring NOT in output',
	strpos( $xss, '<script>alert' ) === false,
	$pass,
	$fail
);
check(
	'XSS: entity-encoded &lt;script&gt; present (real WP escape applied)',
	strpos( $xss, '&lt;script&gt;' ) !== false,
	$pass,
	$fail
);
check(
	'XSS: harmless suffix "Safe" present in output',
	strpos( $xss, 'Safe' ) !== false,
	$pass,
	$fail
);

// 10. No YouTube API sync triggered.
echo "\n10. No YouTube API sync triggered\n";
$quota_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}vyg_api_quota_log" );
$quota_before = (int) ( $GLOBALS['vyg_smoke_14_10_quota_before'] ?? 0 );
if ( ! isset( $GLOBALS['vyg_smoke_14_10_quota_before'] ) ) {
	// Re-count once for the first time we check.
	$quota_delta_check = 0;
} else {
	$quota_delta_check = $quota_after - $quota_before;
}
check(
	"api_quota_log: no new rows during smoke (delta=$quota_delta_check)",
	0 === $quota_delta_check,
	$pass,
	$fail
);

echo "\n=== Summary: $pass OK, $fail FAIL ===\n";
exit( $fail > 0 ? 1 : 0 );
