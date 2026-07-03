<?php
/**
 * Shared video-card partial — Phase B1.
 *
 * Receives a scope of pre-resolved variables from CardRenderer:
 *
 *   $classes, $role                 — outer <article> attributes
 *   $video, $settings, $context     — full input triple
 *   $video_id                       — youtube_video_id (string, possibly '')
 *   $watch_url                      — YouTube watch URL (built by VideoRenderer)
 *   $thumb_url                      — best thumbnail URL
 *   $duration_label                 — formatted duration (e.g. "12:45")
 *   $views_label                    — formatted view count (e.g. "125K")
 *   $time_label                     — relative time label (e.g. "5 days ago")
 *   $title, $description, $channel_name
 *   $show_thumbnail, $show_duration, $show_status_badge, $show_title,
 *   $show_channel, $show_metadata, $show_description, $show_cta,
 *   $show_actions, $show_footer
 *   $show_play_icon, $thumbnail_overlay                          — Phase 14.5
 *   $show_avatar, $show_verified    — only true when BOTH the show_* flag
 *                                     AND a real field exist on $video
 *   $thumbnail_ratio                — sanitized enum
 *   $title_lines                    — sanitized int (1..3)
 *   $duration_position              — sanitized key (default 'bottom_right')
 *   $badge_position                 — sanitized key (default 'top_left')
 *   $status_badge                   — label like 'LIVE' or ''
 *   $metadata_fields                — filtered list ('views', 'published_date')
 *   $cta_style, $cta_label
 *   $actions                        — filtered list ('watch', 'youtube', 'share', 'more')
 *   $icon_watch, $icon_youtube, $icon_share, $icon_more — inline SVGs
 *
 * The partial is written as a plain PHP file (no escaped SVG strings) per
 * the plan's §16.14 tip — using write_file rather than incremental patch
 * avoids the inline-SVG corruption that the existing Grid template
 * carries from earlier patch-tool runs.
 *
 * @package VectorYT\Gallery\Render
 */

defined( 'ABSPATH' ) || exit;

// Helper for the title line-clamp class. Plan §7 maps:
$title_clamp_class = 'unlimited' === (string) $title_lines
    ? 'vyg-card__title--unlimited'
    : 'vyg-card__title--' . max( 1, (int) $title_lines );

// Helper for the metadata separator character (§16.12 keeps this simple).
$meta_separator = '.';
if ( isset( $settings['metadata_separator'] ) ) {
    $sep = (string) $settings['metadata_separator'];
    $meta_separator = ( 'dot' === $sep || '' === $sep ) ? '·'
        : ( 'slash' === $sep ? '/' : ( 'pipe' === $sep ? '|' : '·' ) );
}

// Action label resolution (canonical, per the plan §11 MVP list).
$action_label = static function ( string $slug ): string {
    switch ( $slug ) {
        case 'watch':
            return __( 'Watch on YouTube', 'vector-youtube-gallery' );
        case 'youtube':
            return __( 'Open in YouTube', 'vector-youtube-gallery' );
        case 'share':
            return __( 'Share', 'vector-youtube-gallery' );
        case 'more':
            return __( 'More actions', 'vector-youtube-gallery' );
    }
    return ucfirst( $slug );
};
?>

<article class="<?php echo esc_attr( $classes ); ?>"
         role="<?php echo esc_attr( $role ); ?>"
         data-video-id="<?php echo esc_attr( $video_id ); ?>"
         data-content-type="<?php echo esc_attr( (string) ( $video['content_type'] ?? 'standard' ) ); ?>"
         data-live-status="<?php echo esc_attr( (string) ( $video['live_status'] ?? 'none' ) ); ?>">

    <?php if ( $show_thumbnail ) : ?>
        <?php
        // Phase 14.5 — prototype parity: when thumbnail_overlay is on,
        // add the vyg-card__thumb-wrap--overlay class to the media
        // wrapper so card.css can render the slate-tinted gradient
        // ::after (overriding the default bottom-46% gradient). The
        // class name matches the prototype's .thumb-wrap--overlay
        // convention from the BEM plan, even though the shared
        // partial uses vyg-card__media as the outer thumb container.
        //
        // Phase 14.6 — per-video tone (channel brand gradient). The
        // CardRenderer resolves a 7-char `#RRGGBB` value into
        // $tone_color (3-tier fallback: stored → channel-id hash →
        // default slate). The hex is interpolated into a `style`
        // attribute as `--tone:…` so card.css can build a
        // color-mix() gradient from it (or fall back to the
        // pre-14.6 flat thumb background when the browser is too
        // old to understand `var(--tone)`).
        $media_classes = 'vyg-card__media vyg-thumb-ratio--' . esc_attr( str_replace( '_', '-', $thumbnail_ratio ) );
        if ( $thumbnail_overlay ) {
            $media_classes .= ' vyg-card__thumb-wrap--overlay';
        }
        $media_style = sprintf( '--tone:%s', esc_attr( (string) ( $tone_color ?? '#64748b' ) ) );
        ?>
        <div class="<?php echo $media_classes; // already escaped above. ?>"
             style="<?php echo $media_style; // esc_attr'd in the sprintf above; hex is whitelisted by tone_color(). ?>">
            <a class="vyg-card__link"
               href="<?php echo esc_url( $watch_url ); ?>"
               data-vyg-title="<?php echo esc_attr( $title ); ?>"
               aria-label="<?php echo esc_attr( sprintf( __( 'Watch %s', 'vector-youtube-gallery' ), $title ) ); ?>">
                <img class="vyg-card__thumb"
                     src="<?php echo esc_url( $thumb_url ); ?>"
                     alt="<?php echo esc_attr( $title ); ?>"
                     style="<?php echo esc_attr( (string) ( $thumbnail_style ?? 'object-fit:cover;object-position:center center' ) ); ?>"
                     loading="lazy"
                     decoding="async" />
                <?php if ( $show_play_icon ) : ?>
                    <?php // Phase 14.5 — prototype play icon. aria-hidden because the watch link above supplies the accessible name. ?>
                    <span class="vyg-card__play" aria-hidden="true">&#9654;</span>
                <?php endif; ?>
            </a>

            <?php
            // Phase 14.8 — render a badge when EITHER the legacy
            // status_badge label is non-empty (live/upcoming/replay
            // gated by enabled_badges) OR the new badge_type is set
            // (one of: featured, short, new, product). The four new
            // types aren't gated by the legacy enabled_badges list
            // because they don't have a live_status origin — they
            // come from is_pinned / content_type / manual_content_type
            // / published_at. The show_status_badge setting is the
            // single on/off switch for all 7.
            $has_legacy_badge  = ( '' !== (string) ( $status_badge ?? '' ) );
            $has_new_badge     = ( '' !== (string) ( $badge_type ?? '' ) );
            ?>
            <?php if ( $show_status_badge && ( $has_legacy_badge || $has_new_badge ) ) : ?>
                <?php
                // Phase 14.8 — badge type × style matrix (7 types × 4
                // styles). The renderer now resolves a `$badge_type`
                // (one of: featured, live, upcoming, replay, short,
                // new, product — or '' for no signal), a `$badge_label`
                // (the human text), and a `$badge_color` (the
                // prototype hex). The partial combines the three
                // pieces into one class string and one inline style
                // attribute:
                //   class="vyg-card__badge vyg-card__badge--{type}
                //          vyg-card__badge--{style}
                //          vyg-card__badge--{position}"
                //   style="--vyg-badge-color:#…"
                // The legacy $status_badge label (LIVE/UPCOMING/REPLAY)
                // is still rendered when the allow-list gates it on;
                // the 4 newer types (featured, short, new, product)
                // always show when the show_status_badge setting is on.
                $badge_classes = 'vyg-card__badge';
                if ( '' !== (string) ( $badge_type ?? '' ) ) {
                    $badge_classes .= ' vyg-card__badge--' . esc_attr( (string) $badge_type );
                }
                // The style class is always emitted (the setting has
                // a default of 'solid' so it is never empty).
                $badge_classes .= ' vyg-card__badge--' . esc_attr( (string) ( $badge_style ?? 'solid' ) );
                $badge_classes .= ' vyg-card__badge--' . esc_attr( (string) $badge_position );
                $badge_color_safe = (string) ( $badge_color ?? '' );
                $badge_inline_style = '' !== $badge_color_safe
                    ? '--vyg-badge-color:' . esc_attr( $badge_color_safe )
                    : '';
                ?>
                <span class="<?php echo $badge_classes; ?>"
                      style="<?php echo $badge_inline_style; ?>"
                      aria-label="<?php echo esc_attr( $status_badge ); ?>">
                    <?php
                    // Prefer the new badge_label (covers all 7 types);
                    // fall back to the legacy status_badge for safety.
                    $badge_text = (string) ( $badge_label ?? '' );
                    echo esc_html( '' !== $badge_text ? $badge_text : $status_badge );
                    ?>
                </span>
            <?php endif; ?>

            <?php if ( $show_duration && '' !== $duration_label ) : ?>
                <span class="vyg-card__duration vyg-card__duration--<?php echo esc_attr( $duration_position ); ?>"
                      aria-label="<?php
                          /* translators: %s: duration label. */
                          echo esc_attr( sprintf( __( 'Duration %s', 'vector-youtube-gallery' ), $duration_label ) );
                      ?>">
                    <?php echo esc_html( $duration_label ); ?>
                </span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="vyg-card__body">
        <?php if ( $show_title ) : ?>
            <h3 class="vyg-card__title <?php echo esc_attr( $title_clamp_class ); ?>">
                <?php if ( '' !== $title ) : ?>
                <a class="vyg-card__title-link"
                   href="<?php echo esc_url( $watch_url ); ?>"
                   aria-label="<?php echo esc_attr( sprintf( __( 'Watch %s', 'vector-youtube-gallery' ), $title ) ); ?>">
                    <?php echo esc_html( $title ); ?>
                </a>
                <?php endif; ?>
            </h3>
        <?php endif; ?>

        <?php if ( $show_channel && '' !== $channel_name ) : ?>
            <div class="vyg-card__channel">
                <?php if ( $show_avatar ) : ?>
                    <?php
                    // Phase 14.7 — per-channel avatar gradient (`--aa` / `--ab`).
                    // The CardRenderer resolves a 2-color pair of safe lowercase
                    // `#RRGGBB` strings (3-tier fallback: stored → channel-id hash
                    // pair → default slate) and passes them as `$avatar_color_a` /
                    // `$avatar_color_b`. We interpolate them into a single
                    // `style="--aa:…; --ab:…"` attribute on the avatar element so
                    // card.css can build a `linear-gradient(135deg, var(--aa),
                    // var(--ab))` background. The pair is then hidden under the
                    // real `<img>` when `channel_avatar_url` is populated (the
                    // future Phase 13.2 metadata sync will populate it); on the
                    // current dev install the column doesn't exist yet, so the
                    // gradient is the only visible signal — the channel initial is
                    // supplied by the surrounding `.vyg-card__channel-name` for
                    // now (no text-initial overlay is added in this phase, the
                    // gradient circle stands in for the prototype's text avatar).
                    $avatar_style = sprintf(
                        '--aa:%s;--ab:%s',
                        esc_attr( (string) ( $avatar_color_a ?? '#64748b' ) ),
                        esc_attr( (string) ( $avatar_color_b ?? '#0f172a' ) )
                    );
                    ?>
                    <img class="vyg-card__channel-avatar"
                         src="<?php echo esc_url( (string) $video['channel_avatar_url'] ); ?>"
                         alt="<?php echo esc_attr( $channel_name ); ?>"
                         loading="lazy"
                         decoding="async"
                         width="20"
                         height="20"
                         style="<?php echo $avatar_style; // esc_attr'd in the sprintf above; hex is whitelisted by avatar_colors(). ?>" />
                <?php endif; ?>
                <span class="vyg-card__channel-name"><?php echo esc_html( $channel_name ); ?></span>
                <?php if ( $show_verified ) : ?>
                    <span class="vyg-card__verified" aria-label="<?php esc_attr_e( 'Verified', 'vector-youtube-gallery' ); ?>">
                        <?php echo esc_html( '✓' ); ?>
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ( $show_metadata && ! empty( $metadata_fields ) ) : ?>
            <div class="vyg-card__meta">
                <?php $first = true; ?>
                <?php foreach ( $metadata_fields as $field ) : ?>
                    <?php if ( ! $first ) : ?>
                        <span class="vyg-card__meta-sep" aria-hidden="true"><?php echo esc_html( $meta_separator ); ?></span>
                    <?php endif; ?>
                    <?php if ( 'views' === $field ) : ?>
                        <span class="vyg-card__meta-item vyg-card__meta-views">
                            <?php
                            /* translators: %s: formatted view count (e.g. "125K"). */
                            echo esc_html( sprintf( _n( '%s view', '%s views', (int) ( $video['view_count'] ?? 0 ), 'vector-youtube-gallery' ), $views_label ) );
                            ?>
                        </span>
                    <?php elseif ( 'published_date' === $field ) : ?>
                        <span class="vyg-card__meta-item vyg-card__meta-time">
                            <?php echo esc_html( $time_label ); ?>
                        </span>
                    <?php endif; ?>
                    <?php $first = false; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ( $show_description && '' !== $description ) : ?>
            <p class="vyg-card__description">
                <?php echo esc_html( $description ); ?>
            </p>
        <?php endif; ?>
    </div>

    <?php if ( $show_footer ) : ?>
        <div class="vyg-card__footer">
            <?php if ( $show_cta ) : ?>
                <a class="vyg-card__cta vyg-card__cta--<?php echo esc_attr( $cta_style ); ?>"
                   href="<?php echo esc_url( $watch_url ); ?>">
                    <?php echo esc_html( $cta_label ); ?>
                </a>
            <?php endif; ?>

            <?php if ( $show_actions ) : ?>
                <div class="vyg-card__actions">
                    <?php foreach ( $actions as $slug ) : ?>
                        <?php
                        $icon = '';
                        switch ( $slug ) {
                            case 'watch':   $icon = $icon_watch;   break;
                            case 'youtube': $icon = $icon_youtube; break;
                            case 'share':   $icon = $icon_share;   break;
                            case 'more':    $icon = $icon_more;    break;
                        }
                        $label = $action_label( (string) $slug );
                        ?>
                        <button type="button"
                                class="vyg-card__action vyg-card__action--<?php echo esc_attr( (string) $slug ); ?>"
                                aria-label="<?php echo esc_attr( $label ); ?>">
                            <?php
                            // The SVG already carries aria-hidden="true" — the
                            // surrounding button supplies the accessible name.
                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — SVG is a fixed trusted literal, no interpolated values.
                            echo $icon;
                            ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</article>
