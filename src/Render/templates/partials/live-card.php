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
function vyg_render_live_card( array $video, \VectorYT\Gallery\Render\VideoRenderer $renderer, string $status, array $thumb_settings = array(), string $thumbnail_style = '' ): void {
    $watch_url = $renderer->watch_url( $video );
    $thumb     = $renderer->thumbnail_url( $video, $thumb_settings );
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
                 style="<?php echo esc_attr( '' !== $thumbnail_style ? $thumbnail_style : $renderer->thumbnail_style_attr( $thumb_settings ) ); ?>"
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
                    // Phase 14.11 — prototype parity: emit a relative
                    // countdown ("in 2h 15m", "in 3 days", "starting now")
                    // instead of the raw site time. The raw ISO/MySQL
                    // timestamp is preserved in the <time datetime="...">
                    // attribute for accessibility / screen readers, and
                    // the helper is a pure function with a deterministic
                    // $now injection for tests.
                    $when = \VectorYT\Gallery\Render\TimeHelper::relative_countdown( (string) $video['scheduled_start_at'] );
                    // The helper returns "" for unparseable input; fall
                    // back to the legacy "Starts at <time>" wording in
                    // that case so the partial never emits an empty
                    // <time> element.
                    $iso = (string) $video['scheduled_start_at'];
                    ?>
                    <time class="vyg-live__scheduled-time" datetime="<?php echo esc_attr( $iso ); ?>">
                        <?php
                        if ( '' !== $when ) {
                            echo esc_html( sprintf( __( 'Starts %s', 'vector-youtube-gallery' ), $when ) );
                        } else {
                            $fallback = mysql2date( get_option( 'time_format' ), $iso );
                            echo esc_html( sprintf( __( 'Starts at %s', 'vector-youtube-gallery' ), $fallback ) );
                        }
                        ?>
                    </time>
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
