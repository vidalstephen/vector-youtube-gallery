<?php
/**
 * Live layout template — sectioned by status (active / upcoming / replay).
 *
 * Phase 5: receives a $buckets array from LiveQuery (live, upcoming, replay).
 * Phase 4's flat $videos array is no longer used; kept as fallback for
 * backwards-compat with callers that still pass $videos.
 *
 * Phase 14.3: each section's <h2> is wrapped in a <div class="vyg-live__head">
 * flex row that also carries a prototype-style pill counter:
 *   - active   : "N live"     red    .vyg-live__pill--active
 *   - upcoming : "N upcoming" purple .vyg-live__pill--upcoming
 *   - replay   : "Recent replays" blue .vyg-live__pill--replay (text-only,
 *                no count, per the prototype)
 *
 * The vyg_render_live_card() helper lives in
 * src/Render/templates/partials/live-card.php and is included once at the
 * top of this template (so the function is defined before the body runs and
 * repeated includes of live.php don't trigger "Cannot redeclare" fatals).
 */

defined( 'ABSPATH' ) || exit;

// Bring in the live-card partial (declares vyg_render_live_card).
$live_card_partial = __DIR__ . '/partials/live-card.php';
if ( file_exists( $live_card_partial ) ) {
    // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
    include_once $live_card_partial;
}

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
$public_safe = ! empty( $attrs['public_safe'] );
$root_attrs = \VectorYT\Gallery\Render\TemplateAttributes::to_html(
    \VectorYT\Gallery\Render\TemplateAttributes::feed_root( $attrs, $source, $public_safe )
);
$layout_slug = (string) ( $attrs['layout'] ?? 'live' );
$thumb_settings = is_array( $card_settings ?? null ) ? $card_settings : $attrs;
$thumbnail_style = $renderer->thumbnail_style_attr( $thumb_settings );
$feed_header_partial = __DIR__ . '/partials/feed-header.php';
$has_feed_header     = ! empty( $attrs['show_feed_header'] );
?>
<div class="vyg-feed vyg-feed--live vyg-live <?php echo esc_attr( \VectorYT\Gallery\Render\TemplateAttributes::width_class( $attrs ) ); ?>"
     <?php echo $root_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
    <?php
    // Phase 14.9 — shared top header (kicker + h1 + intro + pill + CTA).
    if ( $has_feed_header && file_exists( $feed_header_partial ) ) {
        // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
        include $feed_header_partial;
    }
    ?>
    <?php if ( ! empty( $buckets['live'] ) ) : ?>
        <section class="vyg-live__section vyg-live__section--active">
            <div class="vyg-live__head">
                <h2 class="vyg-live__heading"><?php esc_html_e( 'Live now', 'vector-youtube-gallery' ); ?></h2>
                <span class="vyg-live__pill vyg-live__pill--active"><?php echo (int) count( $buckets['live'] ); ?> live</span>
            </div>
            <div class="vyg-live__grid">
                <?php foreach ( $buckets['live'] as $video ) : ?>
                    <?php vyg_render_live_card( $video, $renderer, 'live', $thumb_settings, $thumbnail_style ); ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
    <?php if ( ! empty( $buckets['upcoming'] ) ) : ?>
        <section class="vyg-live__section vyg-live__section--upcoming">
            <div class="vyg-live__head">
                <h2 class="vyg-live__heading"><?php esc_html_e( 'Upcoming', 'vector-youtube-gallery' ); ?></h2>
                <span class="vyg-live__pill vyg-live__pill--upcoming"><?php echo (int) count( $buckets['upcoming'] ); ?> upcoming</span>
            </div>
            <div class="vyg-live__grid">
                <?php foreach ( $buckets['upcoming'] as $video ) : ?>
                    <?php vyg_render_live_card( $video, $renderer, 'upcoming', $thumb_settings, $thumbnail_style ); ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
    <?php if ( ! empty( $buckets['replay'] ) ) : ?>
        <section class="vyg-live__section vyg-live__section--replay">
            <div class="vyg-live__head">
                <h2 class="vyg-live__heading"><?php esc_html_e( 'Recent streams', 'vector-youtube-gallery' ); ?></h2>
                <span class="vyg-live__pill vyg-live__pill--replay">Recent replays</span>
            </div>
            <div class="vyg-live__grid">
                <?php foreach ( $buckets['replay'] as $video ) : ?>
                    <?php vyg_render_live_card( $video, $renderer, 'ended', $thumb_settings, $thumbnail_style ); ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</div>
