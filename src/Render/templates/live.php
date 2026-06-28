<?php
/**
 * Live layout template — sectioned by status (active / upcoming / replay).
 *
 * Phase 5: receives a $buckets array from LiveQuery (live, upcoming, replay).
 * Phase 4's flat $videos array is no longer used; kept as fallback for
 * backwards-compat with callers that still pass $videos.
 */

defined( 'ABSPATH' ) || exit;

/** @var array $source */
/** @var array $attrs */
/** @var \VectorYT\Gallery\Render\VideoRenderer $renderer */

/** @var array{live:array,upcoming:array,replay:array} $buckets */
if ( ! isset( $buckets ) ) {
    // Fallback: empty buckets.
    $buckets = array( 'live' => array(), 'upcoming' => array(), 'replay' => array() );
}

$total_count = count( $buckets['live'] ) + count( $buckets['upcoming'] ) + count( $buckets['replay'] );
if ( 0 === $total_count ) {
    echo '<div class="vyg-feed vyg-feed--empty"><p>' . esc_html__( 'No live or recent streams.', 'vector-youtube-gallery' ) . '</p></div>';
    return;
}
?>
<div class="vyg-feed vyg-feed--live vyg-live"
     data-source-uuid="<?php echo esc_attr( (string) ( $source['source_uuid'] ?? '' ) ); ?>"
     data-layout="live">
    <?php if ( ! empty( $buckets['live'] ) ) : ?>
        <section class="vyg-live__section vyg-live__section--active">
            <h2 class="vyg-live__heading"><?php esc_html_e( 'Live now', 'vector-youtube-gallery' ); ?></h2>
            <div class="vyg-live__grid">
                <?php foreach ( $buckets['live'] as $video ) : ?>
                    <?php vyg_render_live_card( $video, $renderer, 'live' ); ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
    <?php if ( ! empty( $buckets['upcoming'] ) ) : ?>
        <section class="vyg-live__section vyg-live__section--upcoming">
            <h2 class="vyg-live__heading"><?php esc_html_e( 'Upcoming', 'vector-youtube-gallery' ); ?></h2>
            <div class="vyg-live__grid">
                <?php foreach ( $buckets['upcoming'] as $video ) : ?>
                    <?php vyg_render_live_card( $video, $renderer, 'upcoming' ); ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
    <?php if ( ! empty( $buckets['replay'] ) ) : ?>
        <section class="vyg-live__section vyg-live__section--replay">
            <h2 class="vyg-live__heading"><?php esc_html_e( 'Recent streams', 'vector-youtube-gallery' ); ?></h2>
            <div class="vyg-live__grid">
                <?php foreach ( $buckets['replay'] as $video ) : ?>
                    <?php vyg_render_live_card( $video, $renderer, 'ended' ); ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</div>

<?php
/**
 * Render a single live card. Phase 5 introduces concurrent viewers for live,
 * scheduled time for upcoming, and ended_at + duration for ended.
 *
 * @param array<string,mixed> $video
 */
function vyg_render_live_card( array $video, \VectorYT\Gallery\Render\VideoRenderer $renderer, string $status ): void {
    $watch_url = $renderer->watch_url( $video );
    $thumb     = $renderer->best_thumbnail( $video );
    $embed_url = $renderer->embed_url( $video, array( 'autoplay' => '1' ) );
    $viewers   = isset( $video['concurrent_viewers'] ) ? (int) $video['concurrent_viewers'] : 0;
    ?>
    <article class="vyg-live__card"
             data-video-id="<?php echo esc_attr( (string) ( $video['youtube_video_id'] ?? '' ) ); ?>"
             data-live-status="<?php echo esc_attr( $status ); ?>">
        <a class="vyg-live__link" href="<?php echo esc_url( $watch_url ); ?>"
           data-vyg-lightbox="<?php echo esc_attr( $embed_url ); ?>"
           data-vyg-title="<?php echo esc_attr( (string) ( $video['title'] ?? '' ) ); ?>">
            <img class="vyg-live__thumb"
                 src="<?php echo esc_url( $thumb ); ?>"
                 alt="<?php echo esc_attr( (string) ( $video['title'] ?? '' ) ); ?>"
                 loading="lazy" decoding="async" />
            <span class="vyg-live__badge">
                <?php
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
            <?php if ( 'live' === $status && $viewers > 0 ) : ?>
                <p class="vyg-live__viewers">
                    <?php
                    /* translators: %s: number of concurrent viewers */
                    echo esc_html( sprintf( _n( '%s watching', '%s watching', $viewers, 'vector-youtube-gallery' ), number_format( $viewers ) ) );
                    ?>
                </p>
            <?php elseif ( 'upcoming' === $status && ! empty( $video['scheduled_start_at'] ) ) : ?>
                <p class="vyg-live__scheduled">
                    <?php
                    /* translators: %s: scheduled start time */
                    $when = mysql2date( get_option( 'time_format' ), (string) $video['scheduled_start_at'] );
                    echo esc_html( sprintf( __( 'Starts at %s', 'vector-youtube-gallery' ), $when ) );
                    ?>
                </p>
            <?php elseif ( 'ended' === $status && ! empty( $video['ended_at'] ) ) : ?>
                <p class="vyg-live__ended">
                    <?php
                    $when = mysql2date( get_option( 'date_format' ), (string) $video['ended_at'] );
                    echo esc_html( sprintf( __( 'Ended %s', 'vector-youtube-gallery' ), $when ) );
                    ?>
                </p>
            <?php endif; ?>
        </a>
    </article>
    <?php
}