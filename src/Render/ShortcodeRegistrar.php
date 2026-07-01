<?php
/**
 * Shortcode registrar — exposes [youtube_feed] to the front-end.
 *
 * Two modes:
 *  - source_uuid (legacy / direct):  [youtube_feed source_uuid="..." layout="grid"]
 *  - feed_uuid (Phase 6 named feed): [youtube_feed feed_uuid="..."]
 *
 * The feed_uuid path loads the saved configuration from vyg_feeds and overlays
 * any inline attributes the operator passed. Inline attributes take precedence.
 *
 * Attributes (all sanitized via shortcode_atts):
 *   - feed_uuid    — UUID of a vyg_feeds row (Phase 6).
 *   - source_uuid  — UUID of a vyg_sources row (legacy; required if no feed_uuid).
 *   - layout       — grid|list|featured|shorts|live. Default grid.
 *   - per_page     — int, 1..200. Default 12.
 *   - columns      — int, 1..6 (used by grid/featured/shorts). Default 3.
 *   - orderby      — published_at|view_count|duration_seconds. Default published_at.
 *   - order        — ASC|DESC. Default DESC.
 *   - content_type — comma-separated list of content_types to include.
 *   - pagination   — none|load_more. Default none.
 *   - offset       — int (used by load_more; normally set by JS).
 *
 * Phase D1: 22 new card-system attrs (card_preset, card_style, show_thumbnail,
 * thumbnail_ratio, show_duration, show_title, show_channel, show_metadata,
 * show_description, show_cta, show_actions, metadata_fields, date_format,
 * metadata_separator, cta_style, cta_label, cta_position, thumbnail_overlay,
 * show_play_icon, compact_mobile, hide_description_mobile, hide_metadata_mobile)
 * map directly to CardSettings::resolve() keys. Together with the Phase 13.1
 * legacy attrs (density, show_channel_*, show_views_and_time, etc.), these are
 * "card-system attrs" — they only reach Renderer::render() if the user
 * explicitly set them in the shortcode. The hardcoded shortcode defaults do
 * NOT pass through, so saved-feed / legacy settings remain the source of
 * truth (fixes the C1 bug documented in commit 9dc9418).
 *
 * Security:
 *   - All attrs are sanitized.
 *   - Source must be 'active' (not 'paused' / 'error').
 *   - Reads only happen via FeedQuery (no API calls).
 *
 * @package VectorYT\Gallery\Render
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Render;

use VectorYT\Gallery\Repository\FeedRepository;

defined( 'ABSPATH' ) || exit;

final class ShortcodeRegistrar {

    public const TAG = 'youtube_feed';

    /**
     * Phase D1 — list of card-system attr names that must only pass
     * through to Renderer if the user explicitly set them in the
     * shortcode. These override saved-feed settings, so the shortcode
     * must NOT send its hardcoded default values as inline args
     * (that would clobber the saved feed's density, channel row,
     * metadata, CTA, etc. settings — see C1 commit 9dc9418).
     */
    public const CARD_SYSTEM_ATTRS = array(
        // New D1 attrs (per plan §9 D1).
        'card_preset', 'card_style',
        'show_thumbnail', 'thumbnail_ratio', 'show_duration',
        'show_title', 'show_channel', 'show_metadata',
        'show_description', 'show_cta', 'show_actions',
        'metadata_fields', 'date_format', 'metadata_separator',
        'cta_style', 'cta_label', 'cta_position',
        'thumbnail_overlay', 'show_play_icon',
        'compact_mobile', 'hide_description_mobile', 'hide_metadata_mobile',
        // Phase 13.1 legacy attrs (now treated as card-system attrs).
        'density', 'show_channel_avatar', 'show_channel_name',
        'show_subscriber_count', 'show_verified_badge',
        'show_views_and_time', 'product_cta_visible', 'trust_strip',
        'card_radius',
        // Header block is grid-layout-specific — gate it too.
        'header_title', 'header_subtitle', 'header_columns_visible',
        'header_cta_label', 'header_cta_url',
    );

    /**
     * Phase D1 — structural attrs that always pass through with their
     * shortcode defaults (not subject to the explicit-only filter).
     */
    private const STRUCTURAL_ATTRS = array(
        'feed_uuid', 'source_uuid', 'layout', 'per_page', 'columns',
        'orderby', 'order', 'content_type', 'pagination', 'offset',
        'wrapper_id', 'schema_enabled', 'preset', 'custom_css',
    );

    public function __construct(
        private readonly Renderer $renderer,
        private readonly FeedQuery $feed,
        private readonly AssetManager $assets,
        private readonly FeedRepository $feeds_repo,
    ) {}

    public function register(): void {
        add_shortcode( self::TAG, array( $this, 'render_shortcode' ) );
    }

    /**
     * Phase D1 — public test seam. Given a raw user `$atts` array,
     * runs `shortcode_atts()` against the full default set and
     * returns the final args array that would be passed to
     * `Renderer::render()`.
     *
     * The "explicit-only" filter is the load-bearing logic: any
     * card-system attr (see CARD_SYSTEM_ATTRS) that the user did NOT
     * explicitly set in `$atts` is dropped from the returned array.
     * The saved feed's legacy / new card_settings shape becomes the
     * source of truth for those values (Layer 4 of
     * CardSettings::resolve), instead of the shortcode's hardcoded
     * defaults clobbering it (Layer 1 / inline).
     *
     * @param array<string,mixed> $user_atts Raw user-passed attrs (before shortcode_atts merges defaults).
     * @return array<string,mixed> Final args array for Renderer::render().
     */
    public function build_render_args( array $user_atts ): array {
        $defaults = $this->shortcode_defaults();
        $merged   = shortcode_atts( $defaults, $user_atts, self::TAG );

        // Explicitly-set keys: any key present in the original user attrs.
        // array_intersect_key() preserves the order and values of the first
        // arg, so the resulting set is exactly the keys the user passed.
        $explicit_keys = array_intersect_key( $user_atts, $merged );

        // Drop card-system attrs the user did not explicitly set, so
        // the shortcode's hardcoded defaults don't clobber saved-feed
        // / legacy settings at the CardSettings::resolve() Layer 1.
        foreach ( self::CARD_SYSTEM_ATTRS as $attr ) {
            if ( ! array_key_exists( $attr, $explicit_keys ) ) {
                unset( $merged[ $attr ] );
            }
        }

        return $merged;
    }

    /**
     * @param array<string,mixed> $atts
     * @param string|null $content
     * @return string
     */
    public function render_shortcode( $atts, $content = null ): string {
        $user_atts = is_array( $atts ) ? $atts : array();
        $atts      = $this->build_render_args( $user_atts );

        $source_uuid = sanitize_text_field( (string ) ( $atts['source_uuid'] ?? '' ) );
        $feed_uuid   = sanitize_text_field( (string ) ( $atts['feed_uuid']   ?? '' ) );
        $layout_slug = sanitize_key(    (string ) ( $atts['layout']      ?? 'grid' ) );

        // If feed_uuid provided, overlay saved config onto inline attributes.
        $inline_override = ''; // CSS wrapper id override
        $feed_record = null;
        if ( '' !== $feed_uuid ) {
            $feed_record = $this->feeds_repo->find_by_uuid( $feed_uuid );
            if ( ! $feed_record ) {
                return '<p>' . esc_html__( 'Vector YouTube Gallery: feed not found.', 'vector-youtube-gallery' ) . '</p>';
            }
            if ( 'archived' === (string) ( $feed_record['status'] ?? '' ) ) {
                return '<p>' . esc_html__( 'Vector YouTube Gallery: this feed is archived.', 'vector-youtube-gallery' ) . '</p>';
            }
            $config = FeedRepository::decode_config( $feed_record );
            if ( '' === $source_uuid ) {
                // Phase 8: legacy single-source config stores source_uuid at the
                // top level; normalized multi-source form puts it in sources[].
                if ( ! empty( $config['source']['source_uuid'] ) ) {
                    $source_uuid = (string) $config['source']['source_uuid'];
                } elseif ( ! empty( $config['source']['sources'][0]['source_uuid'] ) ) {
                    $source_uuid = (string) $config['source']['sources'][0]['source_uuid'];
                }
            }
            // Inline attributes override saved config.
            if ( 'grid' === $layout_slug && ! empty( $feed_record['layout'] ) ) {
                $layout_slug = sanitize_key( (string) $feed_record['layout'] );
            }
            if ( 12 === (int) ( $atts['per_page'] ?? 12 ) && ! empty( $config['display']['per_page'] ) ) {
                $atts['per_page'] = (int) $config['display']['per_page'];
            }
            if ( 3 === (int) ( $atts['columns'] ?? 3 ) && ! empty( $config['display']['columns'] ) ) {
                $atts['columns'] = (int) $config['display']['columns'];
            }
            if ( 'published_at' === (string) ( $atts['orderby'] ?? 'published_at' ) && ! empty( $config['sort']['orderby'] ) ) {
                $atts['orderby'] = (string) $config['sort']['orderby'];
            }
            if ( 'DESC' === (string) ( $atts['order'] ?? 'DESC' ) && ! empty( $config['sort']['order'] ) ) {
                $atts['order'] = (string) $config['sort']['order'];
            }
            if ( '' === (string) ( $atts['content_type'] ?? '' ) && ! empty( $config['filter']['content_type'] ) ) {
                $atts['content_type'] = (string) $config['filter']['content_type'];
            }
            if ( 'none' === (string) ( $atts['pagination'] ?? 'none' ) && ! empty( $config['display']['load_more'] ) ) {
                $atts['pagination'] = 'load_more';
            }
            if ( empty( $atts['schema_enabled'] ) && ! empty( $config['schema']['enabled'] ) ) {
                $atts['schema_enabled'] = (bool) $config['schema']['enabled'];
            }
            if ( 'default' === (string) ( $atts['preset'] ?? 'default' ) && ! empty( $config['display']['preset'] ) ) {
                $atts['preset'] = sanitize_key( (string) $config['display']['preset'] );
            }
            $inline_override = (string) ( $feed_record['custom_css'] ?? '' );
        }

        if ( '' === $source_uuid ) {
            return '<p>' . esc_html__( 'Vector YouTube Gallery: missing source_uuid/feed_uuid attribute.', 'vector-youtube-gallery' ) . '</p>';
        }

        // Confirm source exists and is active.
        $source = $this->feed->find_source_by_uuid( $source_uuid );
        if ( null === $source ) {
            return '<p>' . esc_html__( 'Vector YouTube Gallery: source not found.', 'vector-youtube-gallery' ) . '</p>';
        }
        if ( 'active' !== (string) ( $source['status'] ?? '' ) ) {
            return '<p>' . esc_html__( 'Vector YouTube Gallery: this source is not active.', 'vector-youtube-gallery' ) . '</p>';
        }

        // Enqueue assets (lightbox + CSS).
        $this->assets->enqueue_for_layout( $layout_slug );
        if ( 'load_more' === (string) ( $atts['pagination'] ?? 'none' ) ) {
            $this->assets->enqueue_load_more();
        }

        // Resolve wrapper_id: feed_uuid if no override provided.
        $wrapper_id = sanitize_text_field( (string) ( $atts['wrapper_id'] ?? '' ) );
        if ( '' === $wrapper_id && $feed_record ) {
            $wrapper_id = 'vyg-feed-' . (string) ( $feed_record['feed_uuid'] ?? '' );
        }

        // Phase 8.8: public-safe attribute — strip internal source_uuid from the
        // rendered HTML on PUBLIC pages. Saved mixed-source feeds (feed_uuid
        // set) always render via REST, which only needs feed_uuid; the legacy
        // source_uuid shortcode path keeps source_uuid so its inline
        // load-more.js can still find the legacy endpoint.
        $public_safe = '' !== $feed_uuid;

        $render_args = array(
            'source_uuid'    => $source_uuid,
            'source_config'  => isset( $feed_record ) ? ( $config['source'] ?? array() ) : null,
            'feed_uuid'      => (string) ( $feed_record['feed_uuid'] ?? '' ),
            'layout'         => $layout_slug,
            'content_type'   => (string) ( $atts['content_type'] ?? '' ),
            'public_safe'    => $public_safe,
            'orderby'        => sanitize_key( (string) ( $atts['orderby'] ?? 'published_at' ) ),
            'order'          => sanitize_key( (string) ( $atts['order']   ?? 'DESC' ) ),
            'per_page'       => max( 1, (int) ( $atts['per_page'] ?? 12 ) ),
            'offset'         => max( 0, (int) ( $atts['offset']   ?? 0 ) ),
            'pagination'     => sanitize_key( (string) ( $atts['pagination'] ?? 'none' ) ),
            'columns'        => max( 1, (int) ( $atts['columns']  ?? 3 ) ),
            'wrapper_id'     => $wrapper_id,
            'custom_css'     => $inline_override,
            'schema_enabled' => ! empty( $atts['schema_enabled'] ),
            'preset'         => sanitize_key( (string) ( $atts['preset'] ?? 'default' ) ),
            'feed_config'    => is_array( $config ?? null ) ? $config : array(),
        );

        // Phase D1 — pass through card-system attrs only if the user
        // explicitly set them. The build_render_args() filter has
        // already dropped non-explicit card-system attrs, so this
        // loop just preserves whatever's left. Structural attrs are
        // already in $render_args above.
        //
        // For Phase 13.1 legacy attrs (show_views_and_time,
        // product_cta_visible, show_channel_avatar / _name / _subs /
        // _verified) we translate them to the new card-system keys
        // via the same LEGACY_MAP that CardSettings applies to saved
        // display. The inline layer needs the same translation or
        // the user's legacy attr never reaches the renderer.
        $legacy_inline_map = array(
            'show_views_and_time'  => array( 'show_metadata' ),
            'product_cta_visible'  => array( 'show_cta' ),
            'show_channel_name'    => array( 'show_channel', 'show_channel_name' ),
        );
        $inline_legacy_translations = array();
        foreach ( self::CARD_SYSTEM_ATTRS as $attr ) {
            if ( ! array_key_exists( $attr, $atts ) ) {
                continue;
            }
            // If this attr has a legacy translation, hold it for
            // after the loop so the new keys win.
            if ( isset( $legacy_inline_map[ $attr ] ) ) {
                $inline_legacy_translations[ $attr ] = $legacy_inline_map[ $attr ];
                continue;
            }
            $value = $atts[ $attr ];
            $render_args[ $attr ] = $this->sanitize_shortcode_value( $attr, $value );
        }
        // Apply legacy translations LAST so the new keys (which may
        // also be in $atts) win over the legacy-derived defaults.
        foreach ( $inline_legacy_translations as $legacy_attr => $new_keys ) {
            $value = $this->sanitize_shortcode_value( $legacy_attr, $atts[ $legacy_attr ] );
            foreach ( $new_keys as $new_key ) {
                // Only translate if the user did NOT also set the new key.
                if ( ! array_key_exists( $new_key, $atts ) ) {
                    $render_args[ $new_key ] = $value;
                }
            }
        }

        return $this->renderer->render( $render_args );
    }

    /**
     * Phase D1 — the canonical shortcode defaults array. Includes
     * the original structural + Phase 13.1 attrs AND the 22 new
     * card-system attrs. Card-system defaults are kept here so
     * `shortcode_atts()` doesn't emit "no default" warnings, but
     * they are FILTERED OUT in build_render_args() unless the user
     * explicitly set them.
     *
     * @return array<string,mixed>
     */
    private function shortcode_defaults(): array {
        return array(
            // Structural attrs (always pass through).
            'feed_uuid'      => '',
            'source_uuid'    => '',
            'layout'         => 'grid',
            'per_page'       => 12,
            'columns'        => 3,
            'orderby'        => 'published_at',
            'order'          => 'DESC',
            'content_type'   => '',
            'pagination'     => 'none',
            'offset'         => 0,
            'wrapper_id'     => '',
            'schema_enabled' => false,
            'preset'         => 'default',

            // Phase 13.1 grid redesign attrs (card-system).
            'density'                => 'comfortable',
            'header_title'           => '',
            'header_subtitle'        => '',
            'header_columns_visible' => true,
            'header_cta_label'       => '',
            'header_cta_url'         => '',
            'show_channel_avatar'    => true,
            'show_channel_name'      => true,
            'show_subscriber_count'  => false,
            'show_verified_badge'    => false,
            'show_views_and_time'    => true,
            'product_cta_visible'    => true,
            'trust_strip'            => false,
            'card_radius'            => '12px',

            // New Phase D1 card-system attrs (per plan §9 D1).
            'card_preset'            => 'default',
            'card_style'             => 'bordered',
            'show_thumbnail'         => true,
            'thumbnail_ratio'        => '16_9',
            'show_duration'          => true,
            'show_title'             => true,
            'show_channel'           => true,
            'show_metadata'          => true,
            'show_description'       => false,
            'show_cta'               => 'mapped_only',
            'show_actions'           => false,
            'metadata_fields'        => 'views,published_at',
            'date_format'            => 'relative',
            'metadata_separator'     => 'dot',
            'cta_style'              => 'pill',
            'cta_label'              => '',
            'cta_position'           => 'footer',
            'thumbnail_overlay'      => true,
            'show_play_icon'         => true,
            'compact_mobile'         => false,
            'hide_description_mobile'=> false,
            'hide_metadata_mobile'   => false,
        );
    }

    /**
     * Phase D1 — sanitize a single shortcode attr value per its kind.
     * Mirrors the per-key spec that CardSettings::resolve() applies
     * to the resolved array, but does so on the way out of the
     * shortcode so the resolver sees the correct typed value.
     *
     * @param string $attr  The shortcode attr name.
     * @param mixed  $value The user-passed value.
     * @return mixed        Sanitized value (bool / string / int / array).
     */
    private function sanitize_shortcode_value( string $attr, mixed $value ): mixed {
        // Enum-like attrs (sanitize_key preserves underscores + dashes).
        $enum_attrs = array(
            'density', 'card_preset', 'card_style', 'thumbnail_ratio',
            'metadata_separator', 'cta_style', 'cta_position', 'date_format',
        );
        if ( in_array( $attr, $enum_attrs, true ) ) {
            return sanitize_key( (string) $value );
        }
        // Tri-state show_cta: preserve 'mapped_only' literally.
        if ( 'show_cta' === $attr ) {
            if ( 'mapped_only' === $value ) {
                return 'mapped_only';
            }
            return \VectorYT\Gallery\Render\CardSanitizer::bool( $value, false );
        }
        // Bool attrs (most card-system visibility flags). Use
        // CardSanitizer::bool() — PHP's ! empty( 'false' ) is `true`
        // because the string 'false' is non-empty (plan §16.2).
        $bool_attrs = array(
            'show_thumbnail', 'show_duration', 'show_title', 'show_channel',
            'show_metadata', 'show_description', 'show_actions',
            'show_channel_avatar', 'show_channel_name', 'show_subscriber_count',
            'show_verified_badge', 'show_views_and_time', 'product_cta_visible',
            'trust_strip', 'header_columns_visible', 'thumbnail_overlay',
            'show_play_icon', 'compact_mobile', 'hide_description_mobile',
            'hide_metadata_mobile',
        );
        if ( in_array( $attr, $bool_attrs, true ) ) {
            return \VectorYT\Gallery\Render\CardSanitizer::bool( $value, false );
        }
        // Text attrs.
        $text_attrs = array(
            'header_title', 'header_subtitle', 'header_cta_label', 'cta_label', 'card_radius',
        );
        if ( in_array( $attr, $text_attrs, true ) ) {
            return sanitize_text_field( (string) $value );
        }
        // URL attrs.
        if ( 'header_cta_url' === $attr ) {
            return esc_url_raw( (string) $value );
        }
        // List attrs (comma-string or array).
        if ( 'metadata_fields' === $attr ) {
            return is_array( $value ) ? $value : (string) $value;
        }
        // Default: pass through.
        return $value;
    }
}
