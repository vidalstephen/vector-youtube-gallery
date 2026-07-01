<?php
/**
 * CardProfiles — default profile settings for card modes plus the
 * layout-to-mode lookup used by the video-card customization system.
 *
 * Card modes are reusable visual recipes: `standard`, `compact`,
 * `media_row`, `hero_primary`, `hero_secondary`, `short_vertical`,
 * `live_status`, `overlay`, `minimal_thumb`. Each mode has a default
 * profile array describing which regions (thumbnail, duration, title,
 * channel, metadata, description, CTA, actions) are visible and how
 * they are styled.
 *
 * Layouts (grid, list, hero, featured, carousel, etc.) map onto a mode
 * so the renderer can ask "which profile should I use for a card in
 * the `shorts` layout?" without the layout template having to know
 * every mode's default.
 *
 * Pure PHP. No WordPress globals, no hooks, no esc_* calls. Callers
 * (CardSettings, CardRenderer, layout templates) consume the profile
 * arrays directly.
 *
 * @package VectorYT\Gallery\Render
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Render;

defined( 'ABSPATH' ) || exit;

final class CardProfiles {

	/**
	 * Fallback mode when an unknown mode or layout is requested.
	 */
	private const DEFAULT_MODE = 'standard';

	/**
	 * All valid card modes. Order is irrelevant to the API contract;
	 * tests assert membership only.
	 *
	 * @var string[]
	 */
	private const MODES = array(
		'standard',
		'compact',
		'media_row',
		'hero_primary',
		'hero_secondary',
		'short_vertical',
		'live_status',
		'overlay',
		'minimal_thumb',
	);

	/**
	 * Layout → default mode. Plain layouts use 'default' role; layouts
	 * with role variants (featured, hero) resolve via ROLES.
	 *
	 * @var array<string,string>
	 */
	private const LAYOUT_TO_MODE = array(
		'grid'     => 'standard',
		'list'     => 'media_row',
		'featured' => 'standard',
		'hero'     => 'hero_primary',
		'shorts'   => 'short_vertical',
		'live'     => 'live_status',
		'carousel' => 'standard',
		'masonry'  => 'standard',
	);

	/**
	 * Role-specific overrides for layouts that have multiple variants.
	 * Nested map: layout → role → mode. Fallback to the layout's
	 * default mode (in LAYOUT_TO_MODE) when the role is unrecognized.
	 *
	 * @var array<string,array<string,string>>
	 */
	private const ROLES = array(
		'featured' => array(
			'primary'   => 'hero_primary',
			'secondary' => 'standard',
		),
		'hero' => array(
			'primary' => 'hero_primary',
			'rail'    => 'hero_secondary',
		),
	);

	/**
	 * Return the default profile array for a given mode.
	 *
	 * Each call returns a FRESH array — callers can mutate the result
	 * without corrupting subsequent calls or the canonical defaults
	 * (plan §16.4). Unknown modes fall back to `standard`.
	 *
	 * @return array<string,mixed>
	 */
	public static function get( string $mode ): array {
		// array_merge with [] returns a defensive top-level copy. PHP
		// arrays are value types, but the explicit copy is the
		// documented contract for callers that read the source.
		return array_merge( array(), self::canonical_profile( $mode ) );
	}

	/**
	 * Resolve the default mode for a given layout / role combination.
	 *
	 * Unknown layouts fall back to `standard`. Known layouts with an
	 * unrecognized role fall back to that layout's default mode.
	 *
	 * @return string Mode name (one of the values in allowed_modes()).
	 */
	public static function mode_for_layout( string $layout, string $role = 'default' ): string {
		if ( ! isset( self::LAYOUT_TO_MODE[ $layout ] ) ) {
			return self::DEFAULT_MODE;
		}

		// Role-specific lookup. Empty role is treated as 'default' and
		// skips the role map so callers can pass '' or omit it.
		if ( '' !== $role && 'default' !== $role && isset( self::ROLES[ $layout ] ) ) {
			if ( isset( self::ROLES[ $layout ][ $role ] ) ) {
				return self::ROLES[ $layout ][ $role ];
			}
		}

		return self::LAYOUT_TO_MODE[ $layout ];
	}

	/**
	 * Return the list of all valid mode names.
	 *
	 * Used by `CardSanitizer::list()` in A3 to allow-list user-supplied
	 * values (e.g. `card_preset`).
	 *
	 * @return string[]
	 */
	public static function allowed_modes(): array {
		// Return a fresh array; callers (e.g. CardSanitizer::list) MUST
		// not be able to mutate the canonical list.
		return array_merge( array(), self::MODES );
	}

	/**
	 * Build the canonical profile array for a mode.
	 *
	 * Internal helper. Returns a freshly-built array literal so the
	// caller (get()) can either pass it through directly or apply a
	// defensive copy via array_merge( [], $canonical ).
	 *
	 * @return array<string,mixed>
	 */
	private static function canonical_profile( string $mode ): array {
		switch ( $mode ) {
			case 'standard':
				return array(
					'show_thumbnail'    => true,
					'thumbnail_ratio'   => '16_9',
					'thumbnail_fit'     => 'cover',
					'thumbnail_radius'  => 'medium',
					'show_duration'     => true,
					'show_title'        => true,
					'title_lines'       => 2,
					'title_size'        => 'medium',
					'show_channel'      => true,
					'show_channel_name' => true,
					'show_metadata'     => true,
					'metadata_fields'   => array( 'views', 'published_date' ),
					'show_description'  => false,
					'show_cta'          => 'mapped_only',
					'show_actions'      => false,
					'density'           => 'comfortable',
					'card_style'        => 'bordered',
				);

			case 'compact':
				return array(
					'show_thumbnail'    => true,
					'thumbnail_ratio'   => '16_9',
					'thumbnail_fit'     => 'cover',
					'thumbnail_radius'  => 'small',
					'show_duration'     => true,
					'show_title'        => true,
					'title_lines'       => 1,
					'title_size'        => 'small',
					'show_channel'      => true,
					'show_channel_name' => true,
					'show_metadata'     => true,
					'metadata_fields'   => array( 'views' ),
					'show_description'  => false,
					'show_cta'          => 'mapped_only',
					'show_actions'      => false,
					'density'           => 'compact',
					'card_style'        => 'minimal',
				);

			case 'media_row':
				return array(
					'show_thumbnail'    => true,
					'thumbnail_ratio'   => '16_9',
					'thumbnail_fit'     => 'cover',
					'thumbnail_radius'  => 'medium',
					'show_duration'     => true,
					'show_title'        => true,
					'title_lines'       => 2,
					'show_channel'      => true,
					'show_channel_name' => true,
					'show_metadata'     => true,
					'metadata_fields'   => array( 'views', 'published_date' ),
					// Plan §6 A2: media_row enables description with 1-2 lines.
					'show_description'  => true,
					'description_lines' => 2,
					'show_cta'          => 'mapped_only',
					'show_actions'      => false,
					'density'           => 'comfortable',
					'card_style'        => 'bordered',
					'layout_intent'     => 'horizontal',
				);

			case 'hero_primary':
				return array(
					'show_thumbnail'    => true,
					'thumbnail_ratio'   => '16_9',
					'thumbnail_fit'     => 'cover',
					'thumbnail_radius'  => 'large',
					'show_duration'     => true,
					'show_title'        => true,
					'title_lines'       => 3,
					'title_size'        => 'large',
					'show_channel'      => true,
					'show_channel_name' => true,
					'show_metadata'     => true,
					'metadata_fields'   => array( 'views', 'published_date' ),
					// Plan §6 A2: hero_primary shows description (more lines).
					'show_description'  => true,
					'description_lines' => 3,
					'show_cta'          => 'mapped_only',
					'show_actions'      => false,
					'density'           => 'editorial',
					'card_style'        => 'elevated',
				);

			case 'hero_secondary':
				return array(
					'show_thumbnail'    => true,
					'thumbnail_ratio'   => '16_9',
					'thumbnail_fit'     => 'cover',
					'thumbnail_radius'  => 'medium',
					'show_duration'     => true,
					'show_title'        => true,
					'title_lines'       => 2,
					'title_size'        => 'medium',
					'show_channel'      => true,
					'show_channel_name' => true,
					'show_metadata'     => true,
					'metadata_fields'   => array( 'views', 'published_date' ),
					'show_description'  => false,
					'show_cta'          => 'mapped_only',
					'show_actions'      => false,
					// Plan §6 A2: hero_secondary is "slightly more compact" than standard.
					'density'           => 'compact',
					'card_style'        => 'bordered',
				);

			case 'short_vertical':
				return array(
					// Plan §6 A2: MUST force these regardless of caller overrides.
					'show_thumbnail'    => true,
					'thumbnail_ratio'   => '9_16',
					'thumbnail_fit'     => 'cover',
					'thumbnail_radius'  => 'large',
					'show_duration'     => true,
					'show_title'        => true,
					'title_lines'       => 2,
					'title_size'        => 'medium',
					'show_channel'      => true,
					'show_channel_name' => true,
					'show_metadata'     => true,
					'metadata_fields'   => array( 'views' ),
					'show_description'  => false,
					'show_cta'          => 'mapped_only',
					'show_actions'      => false,
					'density'           => 'comfortable',
					'card_style'        => 'elevated',
				);

			case 'live_status':
				return array(
					'show_thumbnail'    => true,
					'thumbnail_ratio'   => '16_9',
					'thumbnail_fit'     => 'cover',
					'thumbnail_radius'  => 'medium',
					'show_duration'     => false,
					'show_title'        => true,
					'title_lines'       => 2,
					'show_channel'      => true,
					'show_channel_name' => true,
					// Plan §6 A2: status badge on by default for live cards.
					'show_status_badge' => true,
					'enabled_badges'    => array( 'live', 'upcoming', 'replay' ),
					'badge_position'    => 'top_left',
					'badge_style'       => 'solid',
					'show_metadata'     => true,
					'metadata_fields'   => array( 'views', 'published_date' ),
					'show_description'  => false,
					'show_cta'          => 'mapped_only',
					'show_actions'      => false,
					'density'           => 'comfortable',
					'card_style'        => 'bordered',
				);

			case 'overlay':
				return array(
					'show_thumbnail'    => true,
					'thumbnail_ratio'   => '16_9',
					'thumbnail_fit'     => 'cover',
					'thumbnail_radius'  => 'medium',
					'show_duration'     => true,
					'show_title'        => true,
					'title_lines'       => 2,
					// Plan §6 A2: title is laid over the thumbnail.
					'title_position'    => 'overlay',
					'show_channel'      => false,
					'show_channel_name' => false,
					'show_metadata'     => true,
					'metadata_fields'   => array( 'views' ),
					'show_description'  => false,
					'show_cta'          => 'mapped_only',
					'show_actions'      => false,
					'density'           => 'comfortable',
					'card_style'        => 'elevated',
				);

			case 'minimal_thumb':
				return array(
					// Plan §6 A2: minimal card — thumbnail only.
					'show_thumbnail'    => true,
					'thumbnail_ratio'   => '16_9',
					'thumbnail_fit'     => 'cover',
					'thumbnail_radius'  => 'small',
					'show_duration'     => false,
					'show_title'        => true,
					'title_lines'       => 1,
					'show_channel'      => false,
					'show_channel_name' => false,
					'show_metadata'     => false,
					'show_description'  => false,
					'show_cta'          => 'mapped_only',
					'show_actions'      => false,
					'density'           => 'compact',
					'card_style'        => 'minimal',
				);

			default:
				// Unknown mode — fall back to standard profile.
				return self::canonical_profile( self::DEFAULT_MODE );
		}
	}
}
