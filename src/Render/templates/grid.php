<?php
/**
 * Grid layout template — Phase 13.1 redesign.
 *
 * Variables (extracted from $context by TemplateLoader):
 *   $source  — array{id, source_uuid, source_type, title, ...}
 *   $videos  — array<int, array> of normalized video rows
 *   $attrs   — shortcode/block attributes (layout, density, header_title, ...)
 *   $renderer — VideoRenderer instance (helper for embed URLs, durations, view counts)
 *
 * The template is fully self-contained: every section is gated on an explicit
 * attribute so the operator can dial the look up or down per feed.
 *
 * @var array $source
 * @var array $videos
 * @var array $attrs
 * @var \VectorYT\Gallery\Render\VideoRenderer $renderer
 */

defined( 'ABSPATH' ) || exit;

// --- Local short-hand -------------------------------------------------------
$columns           = isset( $attrs['columns'] ) ? max( 1, min( 6, (int) $attrs['columns'] ) ) : 3;
$wrapper_id        = isset( $attrs['wrapper_id'] ) ? (string) $attrs['wrapper_id'] : '';
$density           = isset( $attrs['density'] ) ? (string) $attrs['density'] : 'comfortable';
$public_safe       = ! empty( $attrs['public_safe'] );
$has_header        = ! empty( $attrs['header_title'] );
$has_trust_strip   = ! empty( $attrs['trust_strip'] );
$show_channel_name = ! isset( $attrs['show_channel_name'] ) || ! empty( $attrs['show_channel_name'] );
$show_views_time   = ! isset( $attrs['show_views_and_time'] ) || ! empty( $attrs['show_views_and_time'] );
$product_cta_vis   = ! isset( $attrs['product_cta_visible'] ) || ! empty( $attrs['product_cta_visible'] );

$root_attrs = \VectorYT\Gallery\Render\TemplateAttributes::to_html(
    \VectorYT\Gallery\Render\TemplateAttributes::feed_root( $attrs, $source, $public_safe )
);
$root_classes = sprintf(
    'vyg-feed vyg-feed--grid vyg-grid vyg-grid--cols-%1$d vyg-grid--density-%2$s',
    $columns,
    sanitize_key( $density )
);

if ( empty( $videos ) ) {
    echo '<div class="' . esc_attr( $root_classes ) . ' vyg-feed--empty" ';
    if ( '' !== $wrapper_id ) {
        echo 'id="' . esc_attr( $wrapper_id ) . '" ';
    }
    echo $root_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — TemplateAttributes::to_html escapes each attribute.
    echo '>';
    echo '<p>' . esc_html__( 'No videos yet for this source.', 'vector-youtube-gallery' ) . '</p>';
    echo '</div>';
    return;
}
?>

<div class="<?php echo esc_attr( $root_classes ); ?>"
    <?php if ( '' !== $wrapper_id ) : ?>id="<?php echo esc_attr( $wrapper_id ); ?>"<?php endif; ?>
    <?php echo $root_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — TemplateAttributes::to_html escapes each attribute. ?>>

    <?php if ( $has_header ) : ?>
        <?php
        $header_title        = (string) ( $attrs['header_title'] ?? '' );
        $header_subtitle     = (string) ( $attrs['header_subtitle'] ?? '' );
        $header_cta_label    = (string) ( $attrs['header_cta_label'] ?? '' );
        $header_cta_url      = (string) ( $attrs['header_cta_url'] ?? '' );
        $header_cols_visible = ! isset( $attrs['header_columns_visible'] ) || ! empty( $attrs['header_columns_visible'] );
        $has_cta             = ( '' !== $header_cta_label && '' !== $header_cta_url );
        ?>
        <header class="vyg-grid__header" role="banner">
            <div class="vyg-grid__header-text">
                <span class="vyg-grid__header-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="22" height="22" focusable="false">
                        <path fill="#ff0000" d="M23 12s0-3.6-.5-5.3a2.7 2.7 0 0 0-1.9-1.9C18.9 4.3 12 4.3 12 4.3s-6.9 0-8.6.5A2.7 2.7 0 0 0 1.5 6.7C1 8.4 1 12 1 12s0 3.6.5 5.3a2.7 2.7 0 0 0 1.9 1.9c1.7.5 8.6.5 8.6.5s6.9 0 8.6-.5a2.7 2.7 0 0 0 1.9-1.9C23 15.6 23 12 23 12zM9.8 15.4V8.6L15.5 12l-5.7 3.4z"/>
                    </svg>
                </span>
                <h2 class="vyg-grid__header-title"><?php echo esc_html( $header_title ); ?></h2>
            </div>

            <div class="vyg-grid__header-controls">
                <?php if ( $header_cols_visible ) : ?>
                    <span class="vyg-grid__header-cols" aria-label="<?php echo esc_attr__( 'Column count', 'vector-youtube-gallery' ); ?>">
                        <?php
                        /* translators: %d: number of columns. */
                        echo esc_html( sprintf( _n( '%d column', '%d columns', $columns, 'vector-youtube-gallery' ), $columns ) );
                        ?>
                    </span>
                <?php endif; ?>

                <?php if ( $has_cta ) : ?>
                    <a class="vyg-grid__header-cta" href="<?php echo esc_url( $header_cta_url ); ?>" target="_blank" rel="noopener noreferrer">
                        <?php echo esc_html( $header_cta_label ); ?>
                        <svg class="vyg-icon" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false">
                            <path fill="currentColor" d="M14 3v2h3.6L9.3 13.3l1.4 1.4L19 6.4V10h2V3h-7zm5 16H5V5h7V3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7h-2v7z"/>
                        </svg>
                    </a>
                <?php endif; ?>
            </div>

            <?php if ( '' !== $header_subtitle ) : ?>
                <p class="vyg-grid__header-subtitle"><?php echo esc_html( $header_subtitle ); ?></p>
            <?php endif; ?>
        </header>
    <?php endif; ?>

    <div class="vyg-grid__cards" role="list">
    <?php foreach ( $videos as $video ) : ?>
        <?php
        $embed_url        = $renderer->embed_url( $video );
        $watch_url        = $renderer->watch_url( $video );
        $thumb            = $renderer->best_thumbnail( $video );
        $duration         = $renderer->format_duration( (int) ( $video['duration_seconds'] ?? 0 ) );
        $is_live          = 'live' === ( $video['live_status'] ?? '' );
        $views_label      = $renderer->format_view_count( (int) ( $video['view_count'] ?? 0 ) );
        $time_label       = \VectorYT\Gallery\Render\RelativeTime::humanize( (string) ( $video['published_at'] ?? '' ) );
        $channel_name     = (string) ( $video['youtube_channel_title'] ?? '' );
        if ( '' === $channel_name && isset( $source['title'] ) ) {
            $channel_name = (string) $source['title'];
        }
        ?>
        <article class="vyg-card"
                 role="listitem"
                 data-video-id="<?php echo esc_attr( (string) ( $video['youtube_video_id'] ?? '' ) ); ?>"
                 data-content-type="<?php echo esc_attr( (string) ( $video['content_type'] ?? 'standard' ) ); ?>"
                 data-live-status="<?php echo esc_attr( (string) ( $video['live_status'] ?? 'none' ) ); ?>">
            <a class="vyg-card__link" href="<?php echo esc_url( $watch_url ); ?>"
               data-vyg-lightbox="<?php echo esc_attr( $embed_url ); ?>"
               data-vyg-title="<?php echo esc_attr( (string) ( $video['title'] ?? '' ) ); ?>"
               aria-label="<?php echo esc_attr( sprintf( __( 'Watch %s', 'vector-youtube-gallery' ), (string) ( $video['title'] ?? '' ) ) ); ?>">
                <div class="vyg-card__thumb-wrap">
                    <img class="vyg-card__thumb"
                         src="<?php echo esc_url( $thumb ); ?>"
                         alt="<?php echo esc_attr( (string) ( $video['title'] ?? '' ) ); ?>"
                         loading="lazy"
                         decoding="async" />
                    <?php if ( '' !== $duration ) : ?>
                        <span class="vyg-card__duration"><?php echo esc_html( $duration ); ?></span>
                    <?php endif; ?>
                    <?php if ( $is_live ) : ?>
                        <span class="vyg-card__badge vyg-card__badge--live"><?php esc_html_e( 'LIVE', 'vector-youtube-gallery' ); ?></span>
                    <?php endif; ?>
                </div>
            </a>

            <div class="vyg-card__body">
                <h3 class="vyg-card__title"><?php echo esc_html( (string) ( $video['title'] ?? '' ) ); ?></h3>

                <?php if ( $show_channel_name && '' !== $channel_name ) : ?>
                    <div class="vyg-card__channel">
                        <span class="vyg-card__channel-name"><?php echo esc_html( $channel_name ); ?></span>
                        <span class="vyg-card__channel-subs"><?php echo esc_html( (string) ( $video['subscriber_count'] ?? '1.2M' ) ); ?></span>
                        <span class="vyg-card__verified" aria-label="<?php esc_attr_e( 'Verified', 'vector-youtube-gallery' ); ?>">✓</span>
                    </div>
                <?php endif; ?>
                <?php if ( $show_views_time ) : ?>
                    <div class="vyg-card__meta">
                        <span class="vyg-card__meta-item vyg-card__meta-views">
                            <?php
                            /* translators: %s: formatted view count (e.g. "125K"). */
                            echo esc_html( sprintf( _n( '%s view', '%s views', (int) ( $video['view_count'] ?? 0 ), 'vector-youtube-gallery' ), $views_label ) );
                            ?>
                        </span>
                        <span class="vyg-card__meta-sep" aria-hidden="true">•</span>
                        <span class="vyg-card__meta-item vyg-card__meta-time">
                            <?php echo esc_html( $time_label ); ?>
                        </span>
                    </div>
                <?php endif; ?>

                <?php
                // Phase 10.3 — WooCommerce product CTA (or other per-card CTA).
                // Only renders when a feed-level mapping exists AND the linked
                // product is still published AND the operator has not turned
                // the CTA off via `product_cta_visible=false`.
                $feed_cfg = isset( $feed_config ) && is_array( $feed_config ) ? $feed_config : array();
                $video_id = (string) ( $video['youtube_video_id'] ?? '' );
                if (
                    $product_cta_vis &&
                    '' !== $video_id &&
                    function_exists( 'vyg_render_product_cta' )
                ) {
                    $cta_html = (string) vyg_render_product_cta( $feed_cfg, $video_id );
                    if ( '' !== $cta_html ) {
                        echo '<div class="vyg-card__cta-wrap">';
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — helper escapes itself.
                        echo $cta_html;
                        echo '</div>';
                    }
                }
                ?>
            </div>
        </article>
    <?php endforeach; ?>
    </div>

    <?php if ( $has_trust_strip ) : ?>
        <ul class="vyg-grid__trust-strip" aria-label="<?php esc_attr_e( 'Trust badges', 'vector-youtube-gallery' ); ?>">
            <li class="vyg-grid__trust-item">
                <span class="vyg-grid__trust-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="20" height="20" focusable="false"><path fill="currentColor" d="M12 4a8 8 0 1 0 8 8 8 8 0 0 0-8-8zm0 14a6 6 0 1 1 6-6 6 6 0 0 1-6 6zm1-7.6V6h-2v6l5.2 3.1 1-1.7z"/></svg>
                </span>
                <span class="vyg-grid__trust-text"><?php esc_html_e( 'Lazy Loaded', 'vector-youtube-gallery' ); ?></span>
            </li>
            <li class="vyg-grid__trust-item">
                <span class="vyg-grid__trust-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="20" height="20" focusable="false"><path fill="currentColor" d="M12 1 3 5v6c0 5.6 3.8 10.7 9 12 5.2-1.3 9-6.4 9-12V5l-9-4zm0 10.99h7c-.5 4.5-3.5 8.6-7 9.93V12H5V6.3l7-3.11v8.8z"/></svg>
                </span>
                <span class="vyg-grid__trust-text"><?php esc_html_e( 'Privacy Safe', 'vector-youtube-gallery' ); ?></span>
            </li>
            <li class="vyg-grid__trust-item">
                <span class="vyg-grid__trust-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="20" height="20" focusable="false"><path fill="currentColor" d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm0 18a8 8 0 1 1 8-8 8 8 0 0 1-8 8zm-1-13h2v6h-2zm0 8h2v2h-2z"/></svg>
                </span>
                <span class="vyg-grid__trust-text"><?php esc_html_e( 'Accessible', 'vector-youtube-gallery' ); ?></span>
            </li>
            <li class="vyg-grid__trust-item">
                <span class="vyg-grid__trust-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="20" height="20" focusable="false"><path fill="currentColor" d="M3 5h18v2H3zm0 6h18v2H3zm0 6h12v2H3z"/></svg>
                </span>
                <span class="vyg-grid__trust-text"><?php esc_html_e( 'Builder Ready', 'vector-youtube-gallery' ); ?></span>
            </li>
        </ul>
    <?php endif; ?>
</div>

<?php
// Pagination: render load-more button if requested.
if ( isset( $attrs['pagination'] ) && 'load_more' === $attrs['pagination'] ) {
    $next_offset = (int) ( $attrs['offset'] ?? 0 ) + count( $videos );
    $remaining   = isset( $attrs['total'] ) ? max( 0, (int) $attrs['total'] - $next_offset ) : 0;
    if ( $remaining > 0 ) {
        $load_more_attrs = \VectorYT\Gallery\Render\TemplateAttributes::to_html(
            \VectorYT\Gallery\Render\TemplateAttributes::load_more( $attrs, $source, $next_offset, $public_safe )
        );
        echo '<div class="vyg-feed__loadmore-wrap">';
        echo '<button type="button" class="vyg-feed__loadmore button" ' . $load_more_attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        esc_html_e( 'Load more videos', 'vector-youtube-gallery' );
        echo ' <svg class="vyg-icon" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false"><path fill="currentColor" d="M7 10l5 5 5-5z"/></svg>';
        echo '</button>';
        echo '<span class="vyg-feed__remaining"> (' . esc_html( (string) $remaining ) . ')</span>';
        echo '</div>';
    }
}
