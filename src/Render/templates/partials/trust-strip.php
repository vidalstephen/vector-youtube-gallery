<?php
/**
 * Trust strip partial — shared by grid, masonry, and carousel layouts
 * (Phase 14.10).
 *
 * Variables in scope (set by the calling template's extract()):
 *   $attrs       — shortcode/block attribute array. The strip is gated
 *                  on `trust_strip` being truthy. An optional
 *                  `trust_strip_items` array of associative items
 *                  (each with `slug`, `icon_svg`, `text`) overrides
 *                  the 4 default items derived from the plugin's
 *                  product framing (matching the prototype's
 *                  "Lazy loaded / Privacy safe / Accessible /
 *                  Builder ready" row).
 *   $source      — array{id, source_uuid, source_type, ...} or null
 *                  (currently unused; reserved for Phase 13.2
 *                  channel-metadata-driven defaults when those keys
 *                  land — the partial already reads `$source` so a
 *                  future caller can pass per-source data without
 *                  changing the include contract).
 *
 * Output: emits a <ul class="vyg-trust-strip vyg-grid__trust-strip">
 * block (the `vyg-grid__trust-strip` class is the legacy 14.x grid
 * alias kept for CSS back-compat and for the existing
 * GridTemplateTest::test_trust_strip_renders_when_enabled assertion).
 * Each item is a <li class="vyg-trust-strip__item"> with an inline SVG
 * icon and a text label. The wrapper has aria-label="Trust badges"
 * for screen readers.
 *
 * Visibility: this partial always emits the strip when included; the
 * calling template is responsible for gating on `$has_trust_strip`
 * (mirrors the 14.9 feed-header pattern where the partial assumes the
 * caller decided to include it). This keeps the include site simple
 * and explicit: each layout's template decides once whether to render
 * the strip, and the partial does not re-evaluate the gate.
 *
 * XSS: all dynamic text flows through esc_html(); custom SVGs pass
 * through wp_kses() with a strict allow-list of SVG/Path attributes
 * (viewBox, width, height, focusable, fill, d, aria-*). The
 * defaults are static strings defined in the plugin's i18n catalog
 * and need no escaping beyond esc_html_e() / esc_attr_e().
 *
 * @package VectorYT\Gallery\Render
 */

defined( 'ABSPATH' ) || exit;

// --- Default items (prototype's 4 trust badges) ---------------------------
//
// Order matches the prototype's left-to-right rendering and the
// existing 14.x grid trust-strip implementation. The `slug` field
// drives the per-item icon CSS class (vyg-trust-strip__icon--{slug})
// so themes can override individual icons without touching markup.
//
// Icons are inline SVGs with currentColor fill so they pick up the
// trust-strip's accent color from the surrounding context (matches
// the existing grid.css rules using --vyg-feed-accent).
$default_items = array(
	array(
		'slug'    => 'lazy',
		/* translators: Trust badge — the gallery uses lazy-loaded thumbnails for fast rendering. */
		'text'    => __( 'Lazy Loaded', 'vector-youtube-gallery' ),
		'icon_svg' => '<svg viewBox="0 0 24 24" width="20" height="20" focusable="false" aria-hidden="true"><path fill="currentColor" d="M12 4a8 8 0 1 0 8 8 8 8 0 0 0-8-8zm0 14a6 6 0 1 1 6-6 6 6 0 0 1-6 6zm1-7.6V6h-2v6l5.2 3.1 1-1.7z"/></svg>',
	),
	array(
		'slug'    => 'privacy',
		/* translators: Trust badge — no front-end YouTube API calls. */
		'text'    => __( 'Privacy Safe', 'vector-youtube-gallery' ),
		'icon_svg' => '<svg viewBox="0 0 24 24" width="20" height="20" focusable="false" aria-hidden="true"><path fill="currentColor" d="M12 1 3 5v6c0 5.6 3.8 10.7 9 12 5.2-1.3 9-6.4 9-12V5l-9-4zm0 10.99h7c-.5 4.5-3.5 8.6-7 9.93V12H5V6.3l7-3.11v8.8z"/></svg>',
	),
	array(
		'slug'    => 'accessible',
		/* translators: Trust badge — keyboard-friendly, ARIA-labelled. */
		'text'    => __( 'Accessible', 'vector-youtube-gallery' ),
		'icon_svg' => '<svg viewBox="0 0 24 24" width="20" height="20" focusable="false" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm0 18a8 8 0 1 1 8-8 8 8 0 0 1-8 8zm-1-13h2v6h-2zm0 8h2v2h-2z"/></svg>',
	),
	array(
		'slug'    => 'builder',
		/* translators: Trust badge — works with admin Feed Builder, Gutenberg, Elementor. */
		'text'    => __( 'Builder Ready', 'vector-youtube-gallery' ),
		'icon_svg' => '<svg viewBox="0 0 24 24" width="20" height="20" focusable="false" aria-hidden="true"><path fill="currentColor" d="M3 5h18v2H3zm0 6h18v2H3zm0 6h12v2H3z"/></svg>',
	),
);

// --- Custom items override ----------------------------------------------
//
// $attrs['trust_strip_items'] is an optional array of associative
// items. An empty array is treated as "use defaults" (an operator who
// explicitly passes an empty list is asking for the strip with no
// items, which we interpret as the safe default — otherwise the
// strip would render an empty <ul>, which is a worse UX than the
// defaults). An array with at least one entry replaces the defaults.
$custom_items = isset( $attrs['trust_strip_items'] ) && is_array( $attrs['trust_strip_items'] ) && ! empty( $attrs['trust_strip_items'] )
	? $attrs['trust_strip_items']
	: $default_items;

// --- Sanitize each item -------------------------------------------------
//
// Each item must have at minimum a `text` (string) and a `slug`
// (string, used for the icon class). The `icon_svg` is optional —
// when missing, the item renders text-only with the icon CSS class
// still emitted (so themes can attach a background-image or
// font-icon CSS rule to the slug class). When present, the SVG is
// passed through wp_kses() with a strict allow-list.
$allowed_svg_tags = array(
	'svg' => array(
		'viewbox'   => true,
		'width'     => true,
		'height'    => true,
		'focusable' => true,
		'aria-hidden' => true,
		'role'      => true,
	),
	'path' => array(
		'fill' => true,
		'd'    => true,
	),
);
$items = array();
foreach ( $custom_items as $raw_item ) {
	if ( ! is_array( $raw_item ) ) {
		continue;
	}
	$slug = isset( $raw_item['slug'] ) && is_string( $raw_item['slug'] )
		? sanitize_key( $raw_item['slug'] )
		: '';
	$text = isset( $raw_item['text'] ) && is_string( $raw_item['text'] )
		? $raw_item['text']
		: '';
	$icon = '';
	if ( isset( $raw_item['icon_svg'] ) && is_string( $raw_item['icon_svg'] ) && '' !== $raw_item['icon_svg'] ) {
		$icon = wp_kses( $raw_item['icon_svg'], $allowed_svg_tags );
	}
	// Drop empties — the strip should never render a <li> with no
	// text. An operator who set a slug but no text is asking for an
	// icon-only badge, which is a fine UX, so we keep those.
	if ( '' === $slug && '' === $text ) {
		continue;
	}
	$items[] = array(
		'slug'    => $slug,
		'text'    => $text,
		'icon_svg' => $icon,
	);
}

if ( empty( $items ) ) {
	return;
}
?>
<ul class="vyg-trust-strip vyg-grid__trust-strip"
	aria-label="<?php esc_attr_e( 'Trust badges', 'vector-youtube-gallery' ); ?>">
	<?php foreach ( $items as $item ) : ?>
		<?php
		// Per-item icon class: vyg-trust-strip__icon--{slug}. When
		// the item has no slug, the class is still emitted (empty
		// modifier) so the existing <span class="vyg-trust-strip__icon">
		// base rule applies uniformly.
		$icon_classes = 'vyg-trust-strip__icon';
		if ( '' !== $item['slug'] ) {
			$icon_classes .= ' vyg-trust-strip__icon--' . $item['slug'];
		}
		?>
		<li class="vyg-trust-strip__item vyg-grid__trust-item">
			<span class="<?php echo esc_attr( $icon_classes ); ?>" aria-hidden="true">
				<?php
				// Icon SVG is pre-sanitized via wp_kses() above.
				// It is a static, design-controlled string (defaults)
				// or an operator-supplied string that we already
				// filtered. Echoing it unescaped is intentional and
				// safe — see $allowed_svg_tags allow-list.
				echo $item['icon_svg']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — pre-sanitized via wp_kses() above.
				?>
			</span>
			<span class="vyg-trust-strip__text vyg-grid__trust-text"><?php echo esc_html( $item['text'] ); ?></span>
		</li>
	<?php endforeach; ?>
</ul>
