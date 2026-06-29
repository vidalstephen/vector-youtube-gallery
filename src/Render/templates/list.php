<?php
/**
 * List layout template — single-column rows.
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
$public_safe = ! empty( $attrs['public_safe'] );
$root_attrs = \VectorYT\Gallery\Render\TemplateAttributes::to_html(
    \VectorYT\Gallery\Render\TemplateAttributes::feed_root( $attrs, $source, $public_safe )
);
?>
<div class="vyg-feed vyg-feed--list vyg-list"
     <?php echo $root_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
    <?php foreach ( $videos as $video ) : ?>
        <?php
        $embed_url = $renderer->embed_url( $video );
        $watch_url = $renderer->watch_url( $video );
        $thumb     = $renderer->best_thumbnail( $video );
        $duration  = $renderer->format_duration( (int) ( $video['duration_seconds'] ?? 0 ) );
        ?>
        <article class="vyg-row"
                 data-video-id="<?php echo esc_attr( (string) ( $video['youtube_video_id'] ?? '' ) ); ?>">
            <a class="vyg-row__link" href="<?php echo esc_url( $watch_url ); ?>"
               data-vyg-lightbox="<?php echo esc_attr( $embed_url ); ?>"
               data-vyg-title="<?php echo esc_attr( (string) ( $video['title'] ?? '' ) ); ?>">
                <div class="vyg-row__thumb-wrap">
                    <img class="vyg-row__thumb"
                         src="<?php echo esc_url( $thumb ); ?>"
                         alt="<?php echo esc_attr( (string) ( $video['title'] ?? '' ) ); ?>"
                         loading="lazy" decoding="async" />
                    <?php if ( '' !== $duration ) : ?>
                        <span class="vyg-row__duration"><?php echo esc_html( $duration ); ?></span>
                    <?php endif; ?>
                </div>
                <div class="vyg-row__meta">
                    <h3 class="vyg-row__title"><?php echo esc_html( (string) ( $video['title'] ?? '' ) ); ?></h3>
                    <p class="vyg-row__channel"><?php echo esc_html( (string) ( $video['youtube_channel_id'] ?? '' ) ); ?></p>
                    <?php if ( ! empty( $video['view_count'] ) ) : ?>
                        <p class="vyg-row__views">
                            <?php
                            /* translators: %s: formatted view count */
                            echo esc_html( sprintf( _n( '%s view', '%s views', (int) $video['view_count'], 'vector-youtube-gallery' ), number_format_i18n( (int) $video['view_count'] ) ) );
                            ?>
                        </p>
                    <?php endif; ?>
                </div>
            </a>
        </article>
    <?php endforeach; ?>
</div>