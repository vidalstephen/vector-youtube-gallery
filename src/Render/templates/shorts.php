<?php
/**
 * Shorts layout template — vertical-oriented 9:16 cards.
 */

defined( 'ABSPATH' ) || exit;

/** @var array $source */
/** @var array $videos */
/** @var array $attrs */
/** @var \VectorYT\Gallery\Render\VideoRenderer $renderer */

if ( empty( $videos ) ) {
    echo '<div class="vyg-feed vyg-feed--empty"><p>' . esc_html__( 'No shorts yet.', 'vector-youtube-gallery' ) . '</p></div>';
    return;
}
$public_safe = ! empty( $attrs['public_safe'] );
$root_attrs = \VectorYT\Gallery\Render\TemplateAttributes::to_html(
    \VectorYT\Gallery\Render\TemplateAttributes::feed_root( $attrs, $source, $public_safe )
);
$width_class = \VectorYT\Gallery\Render\TemplateAttributes::width_class( $attrs );
$layout_slug = (string) ( $attrs['layout'] ?? 'shorts' );
$thumb_settings = is_array( $card_settings ?? null ) ? $card_settings : $attrs;
if ( 'shorts' === $layout_slug && empty( $thumb_settings['thumbnail_ratio'] ) ) {
    $thumb_settings['thumbnail_ratio'] = '9_16';
}
$thumbnail_style = $renderer->thumbnail_style_attr( $thumb_settings );
$feed_header_partial = __DIR__ . '/partials/feed-header.php';
?>
<div class="vyg-feed vyg-feed--shorts vyg-shorts <?php echo esc_attr( $width_class ); ?>"
     <?php echo $root_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
    <?php
    // Phase 14.9 — shared top header (kicker + h1 + intro + pill + CTA).
    if ( file_exists( $feed_header_partial ) ) {
        // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
        include $feed_header_partial;
    }
    ?>
    <?php foreach ( $videos as $video ) : ?>
        <article class="vyg-shorts__card"
                 data-video-id="<?php echo esc_attr( (string) ( $video['youtube_video_id'] ?? '' ) ); ?>"
                 data-content-type="<?php echo esc_attr( (string) ( $video['content_type'] ?? 'short_candidate' ) ); ?>">
            <a class="vyg-shorts__link" href="<?php echo esc_url( $renderer->watch_url( $video ) ); ?>"
               data-vyg-lightbox="<?php echo esc_attr( $renderer->embed_url( $video ) ); ?>"
               data-vyg-title="<?php echo esc_attr( (string) ( $video['title'] ?? '' ) ); ?>">
                <div class="vyg-shorts__thumb-wrap">
                    <img class="vyg-shorts__thumb"
                         src="<?php echo esc_url( $renderer->thumbnail_url( $video, $thumb_settings, 'medium' ) ); ?>"
                         alt="<?php echo esc_attr( (string) ( $video['title'] ?? '' ) ); ?>"
                         style="<?php echo esc_attr( $thumbnail_style ); ?>"
                         loading="lazy" decoding="async" />
                    <span class="vyg-shorts__duration"><?php echo esc_html( $renderer->format_duration( (int) ( $video['duration_seconds'] ?? 0 ) ) ); ?></span>
                </div>
                <h3 class="vyg-shorts__title"><?php echo esc_html( (string) ( $video['title'] ?? '' ) ); ?></h3>
            </a>
        </article>
    <?php endforeach; ?>
</div>