<?php
/**
 * CardRenderer — render the shared video card anatomy.
 *
 * The single entry point that turns a video row + resolved settings
 * (output of CardSettings::resolve) + a small context array into the
 * HTML for one card. Every layout (grid, list, shorts, hero, etc.)
 * calls this method; no layout owns its own card markup.
 *
 * Responsibilities:
 *
 *   - Build the outer article class list from the resolved settings
 *     (defensively, via CardSanitizer::enum — see §16.1).
 *   - Delegate field-level rendering to VideoRenderer (thumbnail URL,
 *     watch URL, channel URL, duration format, view count format).
 *   - Delegate date formatting to RelativeTime.
 *   - Render every region conditionally based on the show_* settings
 *     (settings already pass through CardSanitizer::bool, so a real
 *     boolean is what the renderer sees).
 *   - For CTA tri-state, check the Phase 10.7 product-map filter
 *     when show_cta === 'mapped_only' (§16.3).
 *   - Never fake avatar/verified/subscriber data — only render those
 *     regions when the video array actually has the real field (§16.4).
 *   - Escape every interpolated value via esc_html / esc_attr / esc_url.
 *   - Icon-only buttons carry aria-label; SVG icons are aria-hidden
 *     (§16.9).
 *
 * The HTML itself is produced by a small partial at
 * src/Render/templates/partials/video-card.php. The renderer passes
 * the resolved variables into a scope (`extract`) and includes the
 * partial via the same ob_start / include / ob_get_clean pattern that
 * TemplateLoader::render() uses. The TemplateLoader dependency is
 * preserved on the constructor per the plan's API contract; in B1 it
 * is not invoked (the partial lives under a subdirectory that the
 * loader's sanitization does not allow), but the seam is in place for
 * a future partial-discoverability enhancement (e.g. theme overrides).
 *
 * @package VectorYT\Gallery\Render
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Render;

defined( 'ABSPATH' ) || exit;

final class CardRenderer {

    /**
     * Filter tag for the per-feed product map (Phase 10.7 contract).
     * When show_cta === 'mapped_only' the renderer calls this filter
     * to discover whether a product/custom mapping exists for the
     * video id; the CTA is rendered only when the returned array
     * contains the video id.
     */
    private const PRODUCT_MAP_FILTER = 'vyg_phase10_7_product_map_for_feed';

    /**
     * Path to the bundled partial. The partial is namespaced under a
     * subdirectory (`partials/`) which the existing TemplateLoader
     * sanitization does not support, so we resolve the path directly
     * and use the same ob_start / extract / include pattern that
     * TemplateLoader::render() uses. The TemplateLoader is still
     * injected for future extensibility.
     */
    private const PARTIAL_PATH = VYG_PLUGIN_DIR . 'src/Render/templates/partials/video-card.php';

    public function __construct(
        private readonly VideoRenderer $video_renderer,
        private readonly TemplateLoader $templates,
    ) {}

    /**
     * Render a single card.
     *
     * @param array<string,mixed> $video    One normalized video row.
     * @param array<string,mixed> $settings Resolved card settings (output
     *                                      of CardSettings::resolve).
     * @param array<string,mixed> $context  {
     *     @type array|null $source       Source row (channel title, etc.).
     *     @type array      $feed_config  Saved feed config (used for
     *                                    legacy/CTA mapping).
     *     @type string     $feed_uuid    The feed uuid (for filter args).
     *     @type string     $mode         Card mode (from CardProfiles).
     *     @type string     $role         ARIA role for the outer element.
     * }
     */
    public function render( array $video, array $settings, array $context = array() ): string {
        $scope = $this->build_scope( $video, $settings, $context );
        return $this->render_partial( $scope );
    }

    // -----------------------------------------------------------------
    // Scope building — the pipeline: outer attrs → show_* → per-region
    // data → derived data. Each step is a small private method.
    // -----------------------------------------------------------------

    /**
     * @param array<string,mixed> $video
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function build_scope( array $video, array $settings, array $context ): array {
        return array_merge(
            $this->build_outer_attrs( $settings, $context ),
            $this->build_region_visibility( $settings, $video, $context ),
            $this->build_field_values( $video, $settings, $context ),
            $this->build_icons()
        );
    }

    /**
     * Outer <article> attributes: class composition (defensive) and role.
     *
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function build_outer_attrs( array $settings, array $context ): array {
        $mode       = (string) ( $context['mode'] ?? 'standard' );
        $card_style = CardSanitizer::enum(
            (string) ( $settings['card_style'] ?? 'bordered' ),
            array( 'minimal', 'bordered', 'elevated', 'flat', 'commerce' ),
            'bordered'
        );
        $density    = CardSanitizer::enum(
            (string) ( $settings['density'] ?? 'comfortable' ),
            array( 'compact', 'comfortable', 'editorial' ),
            'comfortable'
        );
        $role = (string) ( $context['role'] ?? 'listitem' );

        return array(
            'classes'  => sprintf(
                'vyg-card vyg-card--%s vyg-card--%s vyg-density--%s',
                self::slug_to_class( $mode ),
                $card_style,
                $density
            ),
            'role'     => $role,
            'context'  => $context,
            'settings' => $settings,
        );
    }

    /**
     * Region visibility — every show_* flag plus the derived "show this
     * region's real data" signals. All the "do we render this?" answers
     * live here so the partial stays a flat presentation layer.
     *
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $video
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function build_region_visibility( array $settings, array $video, array $context ): array {
        $show_thumbnail    = (bool) ( $settings['show_thumbnail'] ?? true );
        $show_play_icon    = (bool) ( $settings['show_play_icon'] ?? true );
        $thumbnail_overlay = (bool) ( $settings['thumbnail_overlay'] ?? true );
        $show_duration     = (bool) ( $settings['show_duration'] ?? true );
        $show_status_badge = (bool) ( $settings['show_status_badge'] ?? false );
        $show_title        = (bool) ( $settings['show_title'] ?? true );
        $show_channel      = (bool) ( $settings['show_channel'] ?? true );
        $show_metadata     = (bool) ( $settings['show_metadata'] ?? true );
        $show_description  = (bool) ( $settings['show_description'] ?? false );
        $show_actions      = (bool) ( $settings['show_actions'] ?? false );
        $show_cta_setting  = $settings['show_cta'] ?? 'mapped_only';

        // §16.4 — avatar/verified only when real data exists. The flag
        // alone is insufficient; we need both the flag AND a non-empty
        // (and string-typed) field on the video row.
        $has_avatar_real   = ! empty( $video['channel_avatar_url'] )
            && is_string( $video['channel_avatar_url'] )
            && '' !== (string) $video['channel_avatar_url'];
        $has_verified_real = ! empty( $video['channel_verified'] );
        $show_avatar       = (bool) ( $settings['show_channel_avatar'] ?? false ) && $has_avatar_real;
        $show_verified     = (bool) ( $settings['show_verified_badge'] ?? false ) && $has_verified_real;

        // CTA tri-state (§16.3): real bool or 'mapped_only'.
        $show_cta_now      = $this->resolve_cta( $show_cta_setting, $context, $video );

        // Actions list — only render the wrapper when at least one
        // action is configured.
        $actions           = isset( $settings['actions'] ) && is_array( $settings['actions'] )
            ? $settings['actions']
            : array();
        $show_actions_now  = $show_actions && ! empty( $actions );

        // Footer shows when CTA OR actions are visible.
        $show_footer       = $show_cta_now || $show_actions_now;

        return array(
            'show_thumbnail'    => $show_thumbnail,
            // Phase 14.5 — prototype play icon + gradient overlay. Both
            // are visual-only, always on by default; the partial uses
            // them to emit a centered <span class="vyg-card__play"> on
            // the thumb and to add the vyg-card__thumb-wrap--overlay
            // class (which triggers the slate gradient ::after).
            'show_play_icon'    => $show_play_icon,
            'thumbnail_overlay' => $thumbnail_overlay,
            'show_duration'     => $show_duration,
            'show_status_badge' => $show_status_badge,
            'show_title'        => $show_title,
            'show_channel'      => $show_channel,
            'show_metadata'     => $show_metadata,
            'show_description'  => $show_description,
            'show_cta'          => $show_cta_now,
            'show_actions'      => $show_actions_now,
            'show_footer'       => $show_footer,
            'show_avatar'       => $show_avatar,
            'show_verified'     => $show_verified,
            'actions'           => $actions,
        );
    }

    /**
     * Field-level values — every piece of content the partial reads.
     * Pulls YouTube watch URL, thumbnail, formatted duration / view
     * count via VideoRenderer and the relative-time label via
     * RelativeTime. Computes position classes, badge text, and
     * metadata field allow-list.
     *
     * @param array<string,mixed> $video
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function build_field_values( array $video, array $settings, array $context ): array {
        // --- Channel name fallback (source title when video has none) ---
        $channel_name = (string) ( $video['youtube_channel_title'] ?? '' );
        if ( '' === $channel_name && isset( $context['source']['title'] ) ) {
            $channel_name = (string) $context['source']['title'];
        }

        // --- Sanitized enum / slug values for class output ---
        $thumbnail_ratio    = CardSanitizer::enum(
            (string) ( $settings['thumbnail_ratio'] ?? '16_9' ),
            array( '16_9', '4_3', '1_1', '9_16', 'auto' ),
            '16_9'
        );
        $duration_position  = self::slug_to_class( (string) ( $settings['duration_position'] ?? 'bottom_right' ) );
        $badge_position     = self::slug_to_class( (string) ( $settings['badge_position'] ?? 'top_left' ) );
        $cta_style          = self::slug_to_class( (string) ( $settings['cta_style'] ?? 'primary' ) );

        // --- Status badge (Phase 14.8 — 7 types × 4 styles) ---
        // The `live_status` field only drives the live/upcoming/replay
        // types. The remaining 4 (featured, short, new, product) come
        // from `is_pinned` / `content_type` / `published_at` /
        // `manual_content_type` and are derived in
        // `VideoRenderer::badge_type_for()`. The label and the per-type
        // color are also resolved here so the partial can render the
        // full class combo + inline style with a single scope read.
        $live_status        = (string) ( $video['live_status'] ?? 'none' );
        $enabled_badges     = isset( $settings['enabled_badges'] ) && is_array( $settings['enabled_badges'] )
            ? $settings['enabled_badges']
            : array();
        $badge_type         = $this->video_renderer->badge_type_for( $video );
        $badge_label        = $this->video_renderer->badge_type_label( $badge_type );
        $badge_color        = $this->video_renderer->badge_type_color( $badge_type );
        // The legacy `enabled_badges` allow-list (Phase 10.x) still
        // gates the live/upcoming/replay types. For the 4 newer
        // types (featured, short, new, product) we always allow them
        // when the show_status_badge setting is on — operators
        // disable the badge wholesale via `show_status_badge=false`,
        // not via per-type toggles.
        $status_badge       = $this->resolve_status_badge( $live_status, $enabled_badges );

        // --- Phase 14.7 — per-channel avatar gradient pair ---
        // Resolved once here so the partial has a stable shape (always
        // two 7-char lowercase `#RRGGBB` strings). The helper applies
        // the same XSS guard as `tone_color()`.
        $avatar_colors      = $this->video_renderer->avatar_colors( $video );

        return array(
            'video'             => $video,
            'video_id'          => (string) ( $video['youtube_video_id'] ?? '' ),
            'watch_url'         => $this->video_renderer->watch_url( $video ),
            'thumb_url'         => $this->video_renderer->best_thumbnail( $video ),
            'duration_label'    => $this->video_renderer->format_duration( (int) ( $video['duration_seconds'] ?? 0 ) ),
            'views_label'       => $this->video_renderer->format_view_count( (int) ( $video['view_count'] ?? 0 ) ),
            'time_label'        => RelativeTime::humanize(
                isset( $video['published_at'] ) ? (string) $video['published_at'] : null
            ),
            'title'             => (string) ( $video['title'] ?? '' ),
            'description'       => (string) ( $video['description'] ?? '' ),
            'channel_name'      => $channel_name,
            'thumbnail_ratio'   => $thumbnail_ratio,
            'title_lines'       => (int) ( $settings['title_lines'] ?? 2 ),
            'duration_position' => $duration_position,
            'badge_position'    => $badge_position,
            // Phase 14.8 — badge type / style / color resolved by the
            // VideoRenderer helpers. The partial reads these to emit
            // `<span class="vyg-card__badge vyg-card__badge--{type}
            //  vyg-card__badge--{style} vyg-card__badge--{position}"
            //  style="--vyg-badge-color:{hex}">{label}</span>`. All
            // three are guaranteed safe by the helpers (whitelisted
            // type slug, whitelisted hex, whitelisted label).
            'badge_type'        => $badge_type,
            'badge_label'       => $badge_label,
            'badge_color'       => $badge_color,
            // The style + position class pieces the partial needs
            // (the position slug has already been converted to dash
            // form above; the style is the raw enum value).
            'badge_style'       => (string) ( $settings['badge_style'] ?? 'solid' ),
            'status_badge'      => $status_badge,
            'metadata_fields'   => $this->metadata_fields_for( $settings ),
            'cta_style'         => $cta_style,
            'cta_label'         => (string) ( $settings['cta_label'] ?? 'View Product' ),
            // Phase 14.6 — per-video tone (channel brand gradient).
            // The helper returns a safe lowercase `#RRGGBB` (3-tier
            // fallback: stored → channel-id hash → default slate), so
            // the partial can interpolate it into a `style="--tone:…"`
            // attribute after one `esc_attr` for HTML attribute safety.
            'tone_color'        => $this->video_renderer->tone_color( $video ),
            // Phase 14.7 — per-channel avatar gradient (`--aa` / `--ab`).
            // The helper returns a 2-element pair of safe lowercase
            // `#RRGGBB` strings (3-tier fallback: stored pair → channel-id
            // hash pair → default slate pair). The partial interpolates
            // both into a `style="--aa:…; --ab:…"` attribute. The two
            // colors are guaranteed to be valid 7-char hex by
            // `is_valid_hex_color()` so the only required safety is the
            // single `esc_attr` on the composed string.
            'avatar_color_a'    => $avatar_colors[0],
            'avatar_color_b'    => $avatar_colors[1],
        );
    }

    /**
     * Inline SVG icons for the action buttons. All carry aria-hidden —
     * the surrounding <button> supplies the accessible name.
     *
     * @return array<string,string>
     */
    private function build_icons(): array {
        return array(
            'icon_watch'   => $this->icon_watch(),
            'icon_youtube' => $this->icon_youtube(),
            'icon_share'   => $this->icon_share(),
            'icon_more'    => $this->icon_more(),
        );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Coerce the metadata_fields setting to the allow-list.
     *
     * @param array<string,mixed> $settings
     * @return string[]
     */
    private function metadata_fields_for( array $settings ): array {
        $allowed = array( 'views', 'published_date' );
        $raw     = isset( $settings['metadata_fields'] ) && is_array( $settings['metadata_fields'] )
            ? $settings['metadata_fields']
            : array();
        $out     = array();
        foreach ( $raw as $f ) {
            if ( is_string( $f ) && in_array( $f, $allowed, true ) ) {
                $out[] = $f;
            }
        }
        return $out;
    }

    /**
     * Resolve the status badge text to show (or '' if none).
     *
     * Mapping (Phase 10.x alignment):
     *   live     → 'LIVE'
     *   upcoming → 'UPCOMING'
     *   replay   → 'REPLAY'
     *
     * The mapping is gated on enabled_badges, so an operator can
     * disable a particular label without changing the badge position.
     *
     * @param string[] $enabled_badges
     * @return string
     */
    private function resolve_status_badge( string $live_status, array $enabled_badges ): string {
        $map = array(
            'live'     => 'LIVE',
            'upcoming' => 'UPCOMING',
            'replay'   => 'REPLAY',
        );
        if ( isset( $map[ $live_status ] ) && in_array( $live_status, $enabled_badges, true ) ) {
            return $map[ $live_status ];
        }
        return '';
    }

    /**
     * Resolve the show_cta tri-state.
     *
     * §16.3 — `mapped_only` is NOT equivalent to bool true. It means
     * "show only if a real product / custom mapping exists for this
     * video id".
     *
     * @param mixed $show_cta
     * @param array<string,mixed> $context
     * @param array<string,mixed> $video
     */
    private function resolve_cta( $show_cta, array $context, array $video ): bool {
        if ( true === $show_cta ) {
            return true;
        }
        if ( false === $show_cta || null === $show_cta ) {
            return false;
        }
        // Anything else, including the literal 'mapped_only' string,
        // is treated as mapped_only semantics.
        $feed_config = isset( $context['feed_config'] ) && is_array( $context['feed_config'] )
            ? $context['feed_config']
            : array();
        $feed_uuid   = (string) ( $context['feed_uuid'] ?? '' );
        $video_id    = (string) ( $video['youtube_video_id'] ?? '' );

        // The filter is called with the saved feed_config as the
        // context (in case a custom map needs it) and the feed uuid
        // + video id as additional args. The contract: return an
        // array; if it contains the video id, render the CTA.
        $map = apply_filters( self::PRODUCT_MAP_FILTER, array(), $feed_config, $feed_uuid, $video_id );
        if ( ! is_array( $map ) || empty( $map ) ) {
            return false;
        }
        if ( '' === $video_id ) {
            return false;
        }
        return isset( $map[ $video_id ] );
    }

    /**
     * Convert an internal mode / position / style slug to its
     * public CSS class form. The mode names use underscores
     * (e.g. "short_vertical") but the public CSS class form uses
     * dashes (e.g. "vyg-card--short-vertical").
     */
    private static function slug_to_class( string $slug ): string {
        return strtolower( str_replace( '_', '-', $slug ) );
    }

    /**
     * Render the partial using the same ob_start / extract / include
     * pattern as TemplateLoader::render(). The TemplateLoader is
     * unused here (its sanitize() strips path separators and the
     * partial lives under a subdirectory) but is preserved on the
     * constructor for future extensibility.
     *
     * @param array<string,mixed> $scope
     */
    private function render_partial( array $scope ): string {
        $path = self::PARTIAL_PATH;
        if ( ! is_readable( $path ) ) {
            return '';
        }
        ob_start();
        // phpcs:ignore WordPress.PHP.DontExtract -- intentional template scope.
        extract( $scope, EXTR_SKIP );
        include $path;
        return (string) ob_get_clean();
    }

    // -----------------------------------------------------------------
    // Inline SVG icons (small, reusable). All carry aria-hidden.
    // -----------------------------------------------------------------

    private function icon_watch(): string {
        return '<svg class="vyg-icon" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M8 5v14l11-7z"/></svg>';
    }

    private function icon_youtube(): string {
        return '<svg class="vyg-icon" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M23 12s0-3.6-.5-5.3a2.7 2.7 0 0 0-1.9-1.9C18.9 4.3 12 4.3 12 4.3s-6.9 0-8.6.5A2.7 2.7 0 0 0 1.5 6.7C1 8.4 1 12 1 12s0 3.6.5 5.3a2.7 2.7 0 0 0 1.9 1.9c1.7.5 8.6.5 8.6.5s6.9 0 8.6-.5a2.7 2.7 0 0 0 1.9-1.9C23 15.6 23 12 23 12zM9.8 15.4V8.6L15.5 12l-5.7 3.4z"/></svg>';
    }

    private function icon_share(): string {
        return '<svg class="vyg-icon" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M18 16.1c-.8 0-1.5.3-2 .8L8.9 13a3 3 0 0 0 0-2L16 7.1a3 3 0 1 0-1-2.3L7.9 8.7a3 3 0 1 0 0 6.6L15 18.5a3 3 0 1 0 3-2.4z"/></svg>';
    }

    private function icon_more(): string {
        return '<svg class="vyg-icon" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 8a2 2 0 1 0-2-2 2 2 0 0 0 2 2zm0 2a2 2 0 1 0 2 2 2 2 0 0 0-2-2zm0 6a2 2 0 1 0 2 2 2 2 0 0 0-2-2z"/></svg>';
    }
}
