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
?>
<div class="vyg-feed vyg-feed--shorts vyg-shorts"
     data-source-uuid="<?php echo esc_attr( (string) ( $source['source_uuid'] ?? '' ) ); ?>"
     data-layout="shorts">
    <?php foreach ( $videos as $video ) : ?>
        <article class="vyg-shorts__card"
                 data-video-id="<?php echo esc_attr( (string) ( $video['youtube_video_id'] ?? '' ) ); ?>"
                 data-content-type="<?php echo esc_attr( (string) ( $video['content_type'] ?? 'short_candidate' ) ); ?>">
            <a class="vyg-shorts__link" href="<?php echo esc_url( $renderer->watch_url( $video ) ); ?>"
               data-vyg-lightbox="<?php echo esc_attr( $renderer->embed_url( $video ) ); ?>"
               data-vyg-title="<?php echo esc_attr( (string) ( $video['title'] ?? '' ) ); ?>">
                <div class="vyg-shorts__thumb-wrap">
                    <img class="vyg-shorts__thumb"
                         src="<?php echo esc_url( $renderer->best_thumbnail( $video, 'medium' ) ); ?>"
                         alt="<?php echo esc_attr( (string) ( $video['title'] ?? '' ) ); ?>"
                         loading="lazy" decoding="async" />
                    <span class="vyg-shorts__duration"><?php echo esc_html( $renderer->format_duration( (int) ( $video['duration_seconds'] ?? 0 ) ) ); ?></span>
                </div>
                <h3 class="vyg-shorts__title"><?php echo esc_html( (string) ( $video['title'] ?? '' ) ); ?></h3>
            </a>
        </article>
    <?php endforeach; ?>
</div>