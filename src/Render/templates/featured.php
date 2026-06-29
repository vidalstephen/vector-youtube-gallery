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
$hero_thumb = $renderer->best_thumbnail( $hero, 'high' );
$public_safe = ! empty( $attrs['public_safe'] );
$root_attrs = \VectorYT\Gallery\Render\TemplateAttributes::to_html(
    \VectorYT\Gallery\Render\TemplateAttributes::feed_root( $attrs, $source, $public_safe )
);
?>
<div class="vyg-feed vyg-feed--featured vyg-featured"
     <?php echo $root_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
    <article class="vyg-featured__hero"
             data-video-id="<?php echo esc_attr( (string) ( $hero['youtube_video_id'] ?? '' ) ); ?>">
        <a class="vyg-featured__link" href="<?php echo esc_url( $renderer->watch_url( $hero ) ); ?>"
           data-vyg-lightbox="<?php echo esc_attr( $renderer->embed_url( $hero ) ); ?>"
           data-vyg-title="<?php echo esc_attr( (string) ( $hero['title'] ?? '' ) ); ?>">
            <img class="vyg-featured__hero-thumb"
                 src="<?php echo esc_url( $hero_thumb ); ?>"
                 alt="<?php echo esc_attr( (string) ( $hero['title'] ?? '' ) ); ?>"
                 loading="eager" decoding="async" />
            <h3 class="vyg-featured__hero-title"><?php echo esc_html( (string) ( $hero['title'] ?? '' ) ); ?></h3>
        </a>
    </article>

    <?php if ( ! empty( $rest ) ) : ?>
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
                                 src="<?php echo esc_url( $renderer->best_thumbnail( $video ) ); ?>"
                                 alt="<?php echo esc_attr( (string) ( $video['title'] ?? '' ) ); ?>"
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