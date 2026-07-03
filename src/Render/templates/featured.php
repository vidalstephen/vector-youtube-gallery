<?php
/**
 * Featured layout template — first video hero + remaining grid.
 */

defined( 'ABSPATH' ) || exit;

/** @var array $source */
/** @var array $videos */
/** @var array $attrs */
/** @var \VectorYT\Gallery\Render\VideoRenderer $renderer */

if ( empty( $videos ) ) {
    echo '<div class="vyg-feed vyg-feed--empty"><p>' . esc_html__( 'No videos yet.', 'vector-youtube-gallery' ) . '</p></div>';
    return;
}

$hero    = $videos[0];
$rest    = array_slice( $videos, 1 );
$columns = isset( $attrs['columns'] ) ? max( 2, min( 5, (int) $attrs['columns'] ) ) : 3;
$hero_thumb = $renderer->thumbnail_url( $hero, $card_settings ?? $attrs, 'high' );
$public_safe = ! empty( $attrs['public_safe'] );
$root_attrs = \VectorYT\Gallery\Render\TemplateAttributes::to_html(
    \VectorYT\Gallery\Render\TemplateAttributes::feed_root( $attrs, $source, $public_safe )
);
$width_class = \VectorYT\Gallery\Render\TemplateAttributes::width_class( $attrs );
$layout_slug = (string) ( $attrs['layout'] ?? 'featured' );
$thumb_settings = is_array( $card_settings ?? null ) ? $card_settings : $attrs;
$thumbnail_style = $renderer->thumbnail_style_attr( $thumb_settings );
$feed_header_partial = __DIR__ . '/partials/feed-header.php';

// Phase 14.4 — section head "View all videos" link. The href precedence:
//   1. see_all_url attr (explicit shortcode / block override)
//   2. source's canonical URL synthesized from source_type + youtube_*_id
//   3. '#' as the final fallback
$see_all_url   = (string) ( $attrs['see_all_url'] ?? '' );
$see_all_label = (string) ( $attrs['see_all_label'] ?? '' );
if ( '' === $see_all_url && is_array( $source ) ) {
    $source_type = (string) ( $source['source_type'] ?? '' );
    if ( 'channel' === $source_type && ! empty( $source['youtube_channel_id'] ) ) {
        $see_all_url = 'https://www.youtube.com/channel/' . rawurlencode( (string) $source['youtube_channel_id'] );
    } elseif ( 'playlist' === $source_type && ! empty( $source['youtube_playlist_id'] ) ) {
        $see_all_url = 'https://www.youtube.com/playlist?list=' . rawurlencode( (string) $source['youtube_playlist_id'] );
    } elseif ( 'video' === $source_type && ! empty( $source['youtube_video_id'] ) ) {
        $see_all_url = 'https://www.youtube.com/watch?v=' . rawurlencode( (string) $source['youtube_video_id'] );
    }
}
if ( '' === $see_all_url ) {
    $see_all_url = '#';
}
$see_all_label = ( '' !== $see_all_label ) ? $see_all_label : __( 'View all videos →', 'vector-youtube-gallery' );
?>
<div class="vyg-feed vyg-feed--featured vyg-featured <?php echo esc_attr( $width_class ); ?>"
     <?php echo $root_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
    <?php
    // Phase 14.9 — shared top header (kicker + h1 + intro + pill + CTA).
    // The 14.4 "View all videos →" inner section head (above the rest
    // grid) is preserved below — this is the *top* shared header, not
    // a replacement for the inner one.
    if ( file_exists( $feed_header_partial ) ) {
        // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
        include $feed_header_partial;
    }
    ?>
    <article class="vyg-featured__hero"
             data-video-id="<?php echo esc_attr( (string) ( $hero['youtube_video_id'] ?? '' ) ); ?>">
        <a class="vyg-featured__link" href="<?php echo esc_url( $renderer->watch_url( $hero ) ); ?>"
           data-vyg-lightbox="<?php echo esc_attr( $renderer->embed_url( $hero ) ); ?>"
           data-vyg-title="<?php echo esc_attr( (string) ( $hero['title'] ?? '' ) ); ?>">
            <img class="vyg-featured__hero-thumb"
                 src="<?php echo esc_url( $hero_thumb ); ?>"
                 alt="<?php echo esc_attr( (string) ( $hero['title'] ?? '' ) ); ?>"
                 style="<?php echo esc_attr( $thumbnail_style ); ?>"
                 loading="eager" decoding="async" />
            <h3 class="vyg-featured__hero-title"><?php echo esc_html( (string) ( $hero['title'] ?? '' ) ); ?></h3>
        </a>
    </article>

    <?php if ( ! empty( $rest ) ) : ?>
        <div class="vyg-section-head">
            <h2><?php esc_html_e( 'More Videos', 'vector-youtube-gallery' ); ?></h2>
            <a class="vyg-section-head__link" href="<?php echo esc_url( $see_all_url ); ?>"><?php echo esc_html( $see_all_label ); ?></a>
        </div>
        <div class="vyg-featured__rest vyg-grid vyg-grid--cols-<?php echo (int) $columns; ?>">
            <?php foreach ( $rest as $video ) : ?>
                <article class="vyg-card"
                         data-video-id="<?php echo esc_attr( (string) ( $video['youtube_video_id'] ?? '' ) ); ?>"
                         data-content-type="<?php echo esc_attr( (string) ( $video['content_type'] ?? 'standard' ) ); ?>">
                    <a class="vyg-card__link" href="<?php echo esc_url( $renderer->watch_url( $video ) ); ?>"
                       data-vyg-lightbox="<?php echo esc_attr( $renderer->embed_url( $video ) ); ?>"
                       data-vyg-title="<?php echo esc_attr( (string) ( $video['title'] ?? '' ) ); ?>">
                        <div class="vyg-card__thumb-wrap">
                            <img class="vyg-card__thumb"
                                 src="<?php echo esc_url( $renderer->thumbnail_url( $video, $thumb_settings ) ); ?>"
                                 alt="<?php echo esc_attr( (string) ( $video['title'] ?? '' ) ); ?>"
                                 style="<?php echo esc_attr( $thumbnail_style ); ?>"
                                 loading="lazy" decoding="async" />
                            <span class="vyg-card__duration"><?php echo esc_html( $renderer->format_duration( (int) ( $video['duration_seconds'] ?? 0 ) ) ); ?></span>
                        </div>
                        <h3 class="vyg-card__title"><?php echo esc_html( (string) ( $video['title'] ?? '' ) ); ?></h3>
                    </a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>