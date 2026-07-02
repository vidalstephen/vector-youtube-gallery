<?php
/**
 * Live card partial — renders a single live-layout <article> card.
 *
 * Lives in its own file so `live.php` can include it via include_once
 * and the helper function `vyg_render_live_card` is defined exactly
 * once per request. Putting the declaration in the same file as the
 * template body created a "Cannot redeclare" fatal on the second
 * include of `live.php` (e.g. unit tests that render the template
 * repeatedly, or page builders that shortcode-loop).
 *
 * Phase 5: introduces concurrent viewers for live, scheduled time
 * for upcoming, and ended_at + duration for ended.
 *
 * @package VectorYT\Gallery\Render
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render a single live card.
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
