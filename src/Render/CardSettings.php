<?php
/**
 * CardSettings — resolve the final normalized card settings from a
 * six-layer precedence chain.
 *
 * Every card surface (shortcode, Gutenberg block, Elementor widget,
 * admin Feed Builder) writes into the same normalized settings array,
 * and every layout consumes the same resolved output. This class is
 * the chokepoint where:
 *
 *   1. inline (shortcode / block / Elementor) instance overrides
 *   2. saved feed `card_settings.layouts[$layout]`
 *   3. saved feed `card_settings.global`
 *   4. legacy Phase 13.1 display keys (back-compat)
 *   5. layout / card-mode profile defaults (CardProfiles::get)
 *   6. plugin-global defaults
 *
 * are merged (highest wins) and the final array is sanitized exactly
 * once via CardSanitizer.
 *
 * Pure PHP. No WordPress globals, no hooks, no esc_* calls. Composes
 * CardSanitizer and CardProfiles but touches nothing else.
 *
 * @package VectorYT\Gallery\Render
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Render;

defined( 'ABSPATH' ) || exit;

final class CardSettings {

	/**
	 * The complete MVP allow-list of valid card-setting keys.
	 *
	 * Tests assert that this array has exactly 36 entries and that
	 * `defaults()` populates every one of them.
	 *
	 * @var string[]
	 */
	private const ALLOWED_KEYS = array(
		// Style envelope
		'card_preset',
		'density',
		'card_style',

		// Thumbnail
		'show_thumbnail',
		'thumbnail_ratio',
		'thumbnail_fit',
		'thumbnail_radius',
		// Phase 14.5 — prototype play icon + gradient overlay on the
		// thumb. Both are visual-only (no admin / REST leak risk) so
		// they are pure-public CardSettings keys.
		'show_play_icon',
		'thumbnail_overlay',

		// Duration badge
		'show_duration',
		'duration_position',
		'duration_style',

		// Status badge
		'show_status_badge',
		'enabled_badges',
		'badge_position',
		'badge_style',

		// Title
		'show_title',
		'title_lines',
		'title_size',
		'title_weight',

		// Channel
		'show_channel',
		'show_channel_avatar',
		'show_channel_name',
		'show_verified_badge',
		'show_subscriber_count',

		// Metadata
		'show_metadata',
		'metadata_fields',
		'metadata_style',
		'metadata_separator',
		'date_format',

		// Description
		'show_description',
		'description_lines',

		// CTA
		'show_cta',
		'cta_type',
		'cta_label',
		'cta_style',
		'cta_position',

		// Actions
		'show_actions',
		'actions',
		'actions_style',
		'actions_position',

		// Mobile
		'compact_mobile',
		'hide_description_mobile',
		'hide_metadata_mobile',

		// Phase 14.9 — shared feed header (kicker + h1 + intro + pill + CTA).
		// These are text/show controls that the shared feed-header partial
		// reads off $attrs. The legacy `header_*` aliases are kept inside
		// the partial (read $attrs['header_title'] as a fallback), so
		// those don't need to be here — only the modern names.
		'feed_kicker',
		'feed_title',
		'feed_intro',
		'feed_cta_label',
		'feed_cta_url',
		'show_kicker',
		'show_h1',
		'show_intro',
		'show_pill',
		'show_channel_cta',
		'hide_channel_mobile',
	);

	/**
	 * Layouts that may have per-layout overrides under
	 * `card_settings.layouts[$layout]`. The `featured` and `hero`
	 * layouts support role variants (primary / secondary / rail) but
	 * the storage key is just the layout name — role is per-card.
	 *
	 * @var string[]
	 */
	private const ALLOWED_LAYOUTS = array(
		'grid',
		'list',
		'shorts',
		'live',
		'carousel',
		'masonry',
		'featured',
		'hero',
	);

	/**
	 * Boolean keys — sanitized via CardSanitizer::bool().
	 *
	 * @var string[]
	 */
	private const BOOL_KEYS = array(
		'show_thumbnail',
		'show_duration',
		'show_status_badge',
		'show_title',
		'show_channel',
		'show_channel_avatar',
		'show_channel_name',
		'show_verified_badge',
		'show_subscriber_count',
		'show_metadata',
		'show_description',
		'show_actions',
		// Phase 14.5 — prototype play icon + gradient overlay.
		'show_play_icon',
		'thumbnail_overlay',
		'compact_mobile',
		'hide_description_mobile',
		'hide_metadata_mobile',
		'hide_channel_mobile',
		// Phase 14.9 — shared feed header visibility booleans.
		// (text slots feed_kicker/title/intro/cta_label/cta_url go in TEXT_SPECS)
		'show_kicker',
		'show_h1',
		'show_intro',
		'show_pill',
		'show_channel_cta',
	);

	/**
	 * Tri-state key (`show_cta`).
	 *
	 * The 'mapped_only' string MUST be preserved verbatim through the
	 * sanitizer — it is not equivalent to bool true (§16.3). Booleans
	 * and boolean-like strings ('true' / 'false' / '1' / '0' / etc.)
	 * are coerced to actual bools.
	 *
	 * @var string[]
	 */
	private const TRI_STATE_KEYS = array(
		'show_cta',
	);

	/**
	 * Integer keys, with [min, max, default] bounds.
	 *
	 * @var array<string,array{int,int,int}>
	 */
	private const INT_SPECS = array(
		'title_lines'       => array( 1, 3, 2 ),
		'description_lines' => array( 1, 5, 2 ),
	);

	/**
	 * Plain-text keys (CardSanitizer::text), with max length.
	 *
	 * @var array<string,int>
	 */
	private const TEXT_SPECS = array(
		'cta_label'           => 80,
		// Phase 14.9 — shared feed header text slots.
		'feed_kicker'         => 60,
		'feed_title'          => 120,
		'feed_intro'          => 500,
		'feed_cta_label'      => 60,
		'feed_cta_url'        => 500,
	);

	/**
	 * Enum keys (CardSanitizer::enum), with allow-list and default.
	 *
	 * @var array<string,array{string[],string}>
	 */
	private const ENUM_SPECS = array(
		'card_preset'        => array(
			array( 'minimal', 'elevated', 'bordered', 'editorial', 'commerce', 'compact' ),
			'minimal',
		),
		'density'            => array(
			array( 'compact', 'comfortable', 'editorial' ),
			'comfortable',
		),
		'card_style'         => array(
			array( 'minimal', 'bordered', 'elevated', 'flat', 'commerce' ),
			'bordered',
		),
		'thumbnail_ratio'    => array(
			array( '16_9', '4_3', '1_1', '9_16', 'auto' ),
			'16_9',
		),
		'thumbnail_fit'      => array(
			array( 'cover', 'contain' ),
			'cover',
		),
		'thumbnail_radius'   => array(
			array( 'none', 'small', 'medium', 'large', 'inherit' ),
			'medium',
		),
		'duration_position'  => array(
			array( 'bottom_right', 'bottom_left', 'top_right', 'top_left' ),
			'bottom_right',
		),
		'duration_style'     => array(
			array( 'dark', 'light', 'pill', 'minimal' ),
			'dark',
		),
		'badge_position'     => array(
			array( 'top_left', 'top_right', 'bottom_left', 'bottom_right' ),
			'top_left',
		),
		'badge_style'        => array(
			array( 'solid', 'soft', 'outline', 'minimal' ),
			'solid',
		),
		'title_size'         => array(
			array( 'small', 'medium', 'large', 'inherit' ),
			'medium',
		),
		'title_weight'       => array(
			array( 'normal', 'medium', 'semibold', 'bold', 'inherit' ),
			'semibold',
		),
		'metadata_style'     => array(
			array( 'inline', 'stacked', 'icons', 'text_only', 'hidden' ),
			'inline',
		),
		'metadata_separator' => array(
			array( 'dot', 'slash', 'pipe', 'none' ),
			'dot',
		),
		'date_format'        => array(
			array( 'relative', 'absolute', 'wordpress' ),
			'relative',
		),
		'cta_type'           => array(
			array( 'product', 'youtube', 'custom_url', 'download', 'booking', 'none' ),
			'product',
		),
		'cta_style'          => array(
			array( 'primary', 'secondary', 'outline', 'ghost', 'icon_only' ),
			'primary',
		),
		'cta_position'       => array(
			array( 'footer', 'inline_meta', 'overlay', 'right' ),
			'footer',
		),
		'actions_style'      => array(
			array( 'icons', 'text', 'menu' ),
			'icons',
		),
		'actions_position'   => array(
			array( 'footer_right', 'top_right', 'menu_only' ),
			'footer_right',
		),
	);

	/**
	 * List keys (CardSanitizer::list), with allow-list.
	 *
	 * @var array<string,string[]>
	 */
	private const LIST_SPECS = array(
		'enabled_badges'  => array( 'live', 'upcoming', 'replay', 'featured', 'short', 'product', 'new' ),
		'metadata_fields' => array( 'views', 'published_date' ),
		'actions'         => array( 'watch', 'youtube', 'share', 'more' ),
	);

	/**
	 * Phase 13.1 legacy display keys → list of new card-settings keys
	 * the legacy value should be exploded into.
	 *
	 * Each legacy key maps to one or more new card-setting keys. The
	 * legacy value's truthiness is preserved onto each new key (e.g.
	 * `show_channel_name=false` sets BOTH `show_channel=false` and
	 * `show_channel_name=false`). `card_radius` is intentionally
	 * absent: we are not yet wiring style tokens in A3, so dropping
	 * it is the safe default.
	 *
	 * @var array<string,string[]>
	 */
	private const LEGACY_MAP = array(
		'show_channel_name'   => array( 'show_channel', 'show_channel_name' ),
		'show_views_and_time' => array( 'show_metadata' ),
		'product_cta_visible' => array( 'show_cta' ),
	);

	// ---------------------------------------------------------------------
	// Public API — class metadata
	// ---------------------------------------------------------------------

	/**
	 * Plugin-global defaults — every allowed key populated.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			// Style envelope
			'card_preset'        => 'minimal',
			'density'            => 'comfortable',
			'card_style'         => 'bordered',

			// Thumbnail
			'show_thumbnail'     => true,
			'thumbnail_ratio'    => '16_9',
			'thumbnail_fit'      => 'cover',
			'thumbnail_radius'   => 'medium',
			// Phase 14.5 — default the prototype's two visual signals ON.
			// Mirrors the shortcode defaults in ShortcodeRegistrar so the
			// admin Feed Builder and the shortcode/block/Elementor
			// surfaces agree on the baseline.
			'show_play_icon'     => true,
			'thumbnail_overlay'  => true,

			// Duration
			'show_duration'      => true,
			'duration_position'  => 'bottom_right',
			'duration_style'     => 'dark',

			// Status badge
			'show_status_badge'  => false,
			'enabled_badges'     => array( 'live', 'upcoming', 'replay' ),
			'badge_position'     => 'top_left',
			'badge_style'        => 'solid',

			// Title
			'show_title'         => true,
			'title_lines'        => 2,
			'title_size'         => 'medium',
			'title_weight'       => 'semibold',

			// Channel
			'show_channel'         => true,
			'show_channel_avatar'  => false,
			'show_channel_name'    => true,
			'show_verified_badge'  => false,
			'show_subscriber_count'=> false,

			// Metadata
			'show_metadata'       => true,
			'metadata_fields'     => array( 'views', 'published_date' ),
			'metadata_style'      => 'inline',
			'metadata_separator'  => 'dot',
			'date_format'         => 'relative',

			// Description
			'show_description'    => false,
			'description_lines'   => 2,

			// CTA — tri-state default is the literal string 'mapped_only'
			// (§16.3). It is NOT bool true; it means "show only when a
			// real product / custom mapping exists".
			'show_cta'            => 'mapped_only',
			'cta_type'            => 'product',
			'cta_label'           => 'View Product',
			'cta_style'           => 'primary',
			'cta_position'        => 'footer',

			// Actions
			'show_actions'        => false,
			'actions'             => array( 'watch', 'youtube', 'share', 'more' ),
			'actions_style'       => 'icons',
			'actions_position'    => 'footer_right',

			// Mobile
			'compact_mobile'         => true,
			'hide_description_mobile'=> true,
			'hide_metadata_mobile'   => false,
			'hide_channel_mobile'    => false,

			// Phase 14.9 — shared feed header (kicker + h1 + intro + pill
			// + CTA). Text slots default to empty; show_* booleans default
			// to true (so the h1 + pill render out of the box). The
			// feed-header partial auto-fills the h1 with a layout-specific
			// default when feed_title is empty.
			'feed_kicker'            => '',
			'feed_title'             => '',
			'feed_intro'             => '',
			'feed_cta_label'         => '',
			'feed_cta_url'           => '',
			'show_kicker'            => true,
			'show_h1'                => true,
			'show_intro'             => false,
			'show_pill'              => true,
			'show_channel_cta'       => false,
		);
	}

	/**
	 * The list of valid card-setting keys.
	 *
	 * @return string[]
	 */
	public static function allowed_keys(): array {
		return self::ALLOWED_KEYS;
	}

	/**
	 * The list of layouts that may have per-layout overrides.
	 *
	 * @return string[]
	 */
	public static function allowed_layouts(): array {
		return self::ALLOWED_LAYOUTS;
	}

	// ---------------------------------------------------------------------
	// Public API — the resolver
	// ---------------------------------------------------------------------

	/**
	 * Resolve the final normalized settings for one card in one layout.
	 *
	 * Precedence (highest wins), all merged via array_merge (later
	 * overwrites scalar, replaces arrays wholesale — NOT deep-merged):
	 *
	 *   1. $inline
	 *   2. saved `card_settings.layouts[$layout]`
	 *   3. saved `card_settings.global`
	 *   4. legacy display keys (legacy_display_to_card_settings)
	 *   5. layout/card-mode profile (CardProfiles::get)
	 *   6. plugin-global defaults
	 *
	 * The returned array is sanitized via sanitize_flat() and contains
	 * every key from allowed_keys().
	 *
	 * @param string $layout         Layout name (e.g. 'grid', 'shorts', 'hero').
	 * @param array  $saved_display  Raw saved display config (may be missing
	 *                               or contain non-array 'card_settings').
	 * @param array  $inline         Inline instance overrides (shortcode attrs,
	 *                               block attrs, Elementor widget instance).
	 * @param string $role           Role variant (e.g. 'primary', 'rail',
	 *                               'secondary'). Only used for layouts
	 *                               that have role variants.
	 * @return array<string,mixed>
	 */
	public static function resolve(
		string $layout,
		array $saved_display = array(),
		array $inline = array(),
		string $role = 'default'
	): array {
		// Layer 6: plugin-global defaults.
		$merged = self::defaults();

		// Layer 5: mode profile (CardProfiles::get returns a fresh array).
		$mode     = CardProfiles::mode_for_layout( $layout, $role );
		$profile  = CardProfiles::get( $mode );
		$merged   = array_merge( $merged, $profile );

		// Layers 3 + 2: saved `card_settings.global` and
		// `card_settings.layouts[$layout]`. The `card_settings` key may
		// be missing or non-array — treat it as empty.
		$card_settings = array();
		if ( isset( $saved_display['card_settings'] ) && is_array( $saved_display['card_settings'] ) ) {
			$card_settings = $saved_display['card_settings'];
		}

		// Layer 3: global.
		if ( isset( $card_settings['global'] ) && is_array( $card_settings['global'] ) ) {
			$merged = array_merge( $merged, $card_settings['global'] );
		}

		// Layer 2: per-layout override (only for known layouts).
		if (
			isset( $card_settings['layouts'][ $layout ] )
			&& is_array( $card_settings['layouts'][ $layout ] )
			&& in_array( $layout, self::ALLOWED_LAYOUTS, true )
		) {
			$merged = array_merge( $merged, $card_settings['layouts'][ $layout ] );
		}

		// Layer 4: legacy Phase 13.1 display keys (back-compat).
		$legacy = self::legacy_display_to_card_settings( $saved_display );
		if ( ! empty( $legacy ) ) {
			$merged = array_merge( $merged, $legacy );
		}

		// Layer 1: inline (highest precedence).
		if ( ! empty( $inline ) ) {
			$merged = array_merge( $merged, $inline );
		}

		// Sanitize the final result. This is the ONE place every value
		// passes through CardSanitizer on its way to the renderer.
		return self::sanitize_flat( $merged );
	}

	// ---------------------------------------------------------------------
	// Public API — sanitizers
	// ---------------------------------------------------------------------

	/**
	 * Sanitize the saved-storage shape:
	 *
	 *   ['global' => [...], 'layouts' => ['grid' => [...], ...]]
	 *
	 * Drops unknown top-level keys, unknown layouts, and unknown
	 * setting keys; sanitizes every setting value via the per-key
	 * spec. Used by FeedRepository (A4) when persisting saved
	 * display_config_json.
	 *
	 * @param array $raw Raw saved shape.
	 * @return array{global:array<string,mixed>,layouts:array<string,array<string,mixed>>}
	 */
	public static function sanitize_storage( array $raw ): array {
		$global  = ( isset( $raw['global'] ) && is_array( $raw['global'] ) ) ? $raw['global'] : array();
		$layouts = ( isset( $raw['layouts'] ) && is_array( $raw['layouts'] ) ) ? $raw['layouts'] : array();

		$out_global = self::sanitize_flat( $global );

		$out_layouts = array();
		foreach ( $layouts as $layout => $settings ) {
			// Drop unknown layout names (e.g. typo'd 'gridd').
			if ( ! in_array( $layout, self::ALLOWED_LAYOUTS, true ) ) {
				continue;
			}
			// Drop non-array layout overrides silently.
			if ( ! is_array( $settings ) ) {
				continue;
			}
			$out_layouts[ $layout ] = self::sanitize_flat( $settings );
		}

		return array(
			'global'  => $out_global,
			'layouts' => $out_layouts,
		);
	}

	/**
	 * Sanitize a flat key→value array of card settings.
	 *
	 * Filters unknown keys (dropped) and sanitizes each value via
	 * CardSanitizer. Used by resolve() for the final output and by
	 * sanitize_storage() for `global` and per-layout overrides.
	 *
	 * @param array $raw Flat settings array.
	 * @return array<string,mixed>
	 */
	public static function sanitize_flat( array $raw ): array {
		$out = array();
		foreach ( $raw as $key => $value ) {
			// Drop unknown keys.
			if ( ! is_string( $key ) || ! in_array( $key, self::ALLOWED_KEYS, true ) ) {
				continue;
			}
			$out[ $key ] = self::apply_sanitizer( $key, $value );
		}
		return $out;
	}

	/**
	 * Map Phase 13.1 top-level display keys into the new card
	 * settings shape. Unknown legacy keys are dropped (we only know
	 * the 36 card-settings keys). Some legacy keys explode into
	 * multiple new settings; see LEGACY_MAP.
	 *
	 * This function does NOT sanitize values — that's the resolver's
	 * job once the merge is complete. It does, however, preserve
	 * truthiness for `product_cta_visible` (true → true, false →
	 * false) so the merge carries the user's intent through.
	 *
	 * @param array $display Raw legacy display array.
	 * @return array<string,mixed>
	 */
	public static function legacy_display_to_card_settings( array $display ): array {
		if ( empty( $display ) ) {
			return array();
		}

		$out = array();

		foreach ( $display as $key => $value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}

			// Known legacy → new mapping (explode or collapse). The
			// legacy value's truthiness is preserved onto each new
			// key — we do NOT sanitize here, the resolver does that
			// once the merge is complete.
			if ( isset( self::LEGACY_MAP[ $key ] ) ) {
				$truthy = self::is_truthy( $value );
				foreach ( self::LEGACY_MAP[ $key ] as $new_key ) {
					$out[ $new_key ] = $truthy;
				}
				continue;
			}

			// Pass-through: if the key happens to be a valid card
			// setting already (e.g. 'density' or 'card_style' from a
			// pre-A3 display array), carry it through unsanitized.
			// The resolver will sanitize it on the way through.
			if ( in_array( $key, self::ALLOWED_KEYS, true ) ) {
				$out[ $key ] = $value;
			}

			// Unknown legacy keys (e.g. 'card_radius', 'foo') are
			// intentionally dropped.
		}

		return $out;
	}

	// ---------------------------------------------------------------------
	// Internal — per-key sanitizer dispatch
	// ---------------------------------------------------------------------

	/**
	 * Dispatch a single value through the right CardSanitizer call
	 * based on the per-key spec table.
	 *
	 * @param string $key   Card-setting key.
	 * @param mixed  $value Incoming value (string, bool, int, array, …).
	 * @return mixed Sanitized value.
	 */
	private static function apply_sanitizer( string $key, mixed $value ): mixed {
		// Tri-state: show_cta accepts the literal 'mapped_only' string
		// OR a coerced boolean. Anything else falls back to the
		// tri-state default 'mapped_only' (§16.3).
		if ( in_array( $key, self::TRI_STATE_KEYS, true ) ) {
			if ( 'mapped_only' === $value ) {
				return 'mapped_only';
			}
			// Booleans: pass through.
			if ( is_bool( $value ) ) {
				return $value;
			}
			// Strings / ints: try the boolean coercion. If the input
			// is recognizable (string 'true'/'false', int 1/0, etc.)
			// use the coerced bool. Otherwise fall back to
			// 'mapped_only' (the tri-state default).
			if ( is_string( $value ) ) {
				$normalized = strtolower( trim( $value ) );
				if ( in_array( $normalized, array( 'true', '1', 'yes', 'on', 'false', '0', 'no', 'off' ), true ) ) {
					return CardSanitizer::bool( $value, false );
				}
			} elseif ( is_int( $value ) && ( 0 === $value || 1 === $value ) ) {
				return CardSanitizer::bool( $value, false );
			}
			return 'mapped_only';
		}

		// Plain bool.
		if ( in_array( $key, self::BOOL_KEYS, true ) ) {
			return CardSanitizer::bool( $value, false );
		}

		// Enum.
		if ( isset( self::ENUM_SPECS[ $key ] ) ) {
			[ $allowed, $default ] = self::ENUM_SPECS[ $key ];
			return CardSanitizer::enum( $value, $allowed, $default );
		}

		// List.
		if ( isset( self::LIST_SPECS[ $key ] ) ) {
			return CardSanitizer::list( $value, self::LIST_SPECS[ $key ] );
		}

		// Int.
		if ( isset( self::INT_SPECS[ $key ] ) ) {
			[ $min, $max, $default ] = self::INT_SPECS[ $key ];
			return CardSanitizer::int( $value, $min, $max, $default );
		}

		// Text.
		if ( isset( self::TEXT_SPECS[ $key ] ) ) {
			return CardSanitizer::text( $value, self::TEXT_SPECS[ $key ] );
		}

		// Unknown key (caller should have filtered already, but be
		// defensive) — return null so the caller can drop it.
		return null;
	}

	/**
	 * Coerce a value to a boolean using CardSanitizer::bool() with
	 * $default=false. Used by legacy_display_to_card_settings() to
	 * preserve truthiness when exploding legacy keys.
	 */
	private static function is_truthy( mixed $value ): bool {
		return CardSanitizer::bool( $value, false );
	}
}
