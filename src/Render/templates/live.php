<?php
/**
 * Live layout template — active live first, then recently ended.
 */

defined( 'ABSPATH' ) || exit;

/** @var array $source */
/** @var array $videos */
/** @var array $attrs */
/** @var \VectorYT\Gallery\Render\VideoRenderer $renderer */

if ( empty( $videos ) ) {
    echo '<div class="vyg-feed vyg-feed--empty"><p>' . esc_html__( 'No live or recent streams.', 'vector-youtube-gallery' ) . '</p></div>';
    return;
}

// Re-order: live first, then upcoming, then ended, then standard.
$buckets = array(
    'live'     => array(),
    'upcoming' => array(),
    'ended'    => array(),
    'none'     => array(),
);
foreach ( $videos as $video ) {
    $key = (string) ( $video['live_status'] ?? 'none' );
    if ( ! isset( $buckets[ $key ] ) ) {
        $key = 'none';
    }
    $buckets[ $key ][] = $video;
}
?>
<div class="vyg-feed vyg-feed--live vyg-live"
     data-source-uuid="<?php echo esc_attr( (string) ( $source['source_uuid'] ?? '' ) ); ?>"
     data-layout="live">
    <?php if ( ! empty( $buckets['live'] ) ) : ?>
        <section class="vyg-live__section vyg-live__section--active">
            <h2 class="vyg-live__heading"><?php esc_html_e( 'Live now', 'vector-youtube-gallery' ); ?></h2>
            <?php foreach ( $buckets['live'] as $video ) : ?>
                <?php $this_live( $video, $renderer ); ?>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
    <?php if ( ! empty( $buckets['upcoming'] ) ) : ?>
        <section class="vyg-live__section vyg-live__section--upcoming">
            <h2 class="vyg-live__heading"><?php esc_html_e( 'Upcoming', 'vector-youtube-gallery' ); ?></h2>
            <?php foreach ( $buckets['upcoming'] as $video ) : ?>
                <?php $this_live( $video, $renderer ); ?>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
    <?php if ( ! empty( $buckets['ended'] ) ) : ?>
        <section class="vyg-live__section vyg-live__section--replay">
            <h2 class="vyg-live__heading"><?php esc_html_e( 'Recent streams', 'vector-youtube-gallery' ); ?></h2>
            <?php foreach ( $buckets['ended'] as $video ) : ?>
                <?php $this_live( $video, $renderer ); ?>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</div>

<?php
/**
 * Helper local to this template.
 *
 * @param array<string,mixed> $video
 */
function this_live( array $video, \VectorYT\Gallery\Render\VideoRenderer $renderer ): void {
    $watch_url = $renderer->watch_url( $video );
    $thumb     = $renderer->best_thumbnail( $video );
    ?>
    <article class="vyg-live__card"
             data-video-id="<?php echo esc_attr( (string) ( $video['youtube_video_id'] ?? '' ) ); ?>">
        <a class="vyg-live__link" href="<?php echo esc_url( $watch_url ); ?>"
           data-vyg-lightbox="<?php echo esc_attr( $renderer->embed_url( $video ) ); ?>"
           data-vyg-title="<?php echo esc_attr( (string) ( $video['title'] ?? '' ) ); ?>">
            <img class="vyg-live__thumb"
                 src="<?php echo esc_url( $thumb ); ?>"
                 alt="<?php echo esc_attr( (string) ( $video['title'] ?? '' ) ); ?>"
                 loading="lazy" decoding="async" />
            <span class="vyg-live__badge">
                <?php
                $status = (string) ( $video['live_status'] ?? '' );
                if ( 'live' === $status ) {
                    esc_html_e( 'LIVE', 'vector-youtube-gallery' );
                } elseif ( 'upcoming' === $status ) {
                    esc_html_e( 'UPCOMING', 'vector-youtube-gallery' );
                } else {
                    esc_html_e( 'REPLAY', 'vector-youtube-gallery' );
                }
                ?>
            </span>
            <h3 class="vyg-live__title"><?php echo esc_html( (string) ( $video['title'] ?? '' ) ); ?></h3>
        </a>
    </article>
    <?php
}