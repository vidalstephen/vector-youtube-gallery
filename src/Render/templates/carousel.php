<?php
/**
 * Carousel layout template.
 *
 * Variables:
 *   $source   — array{id, source_uuid, source_type, title, ...}
 *   $videos   — array<int, array> of normalized video rows
 *   $attrs    — shortcode/block attributes (layout, per_page, columns, ...)
 *   $renderer — VideoRenderer instance
 *
 * Implementation: a horizontally scrollable <ul role="listbox"> with snap-stop
 * CSS. The carousel JS (assets/js/carousel.js) wires prev/next buttons,
 * keyboard navigation, touch swipe, and a "current slide" live region for
 * screen-reader announcements.
 *
 * @var array $source
 * @var array $videos
 * @var array $attrs
 * @var \VectorYT\Gallery\Render\VideoRenderer $renderer
 */

defined('ABSPATH') || exit;

if (empty($videos)) {
    echo '<div class="vyg-feed vyg-feed--empty">';
    echo '<p>' . esc_html__('No videos yet for this source.', 'vector-youtube-gallery') . '</p>';
    echo '</div>';
    return;
}

$per_page    = isset($attrs['per_page']) ? (int) $attrs['per_page'] : 0;
$visible     = isset($attrs['columns']) ? max(1, min(6, (int) $attrs['columns'])) : 3;
$wrapper_id  = isset($attrs['wrapper_id']) ? (string) $attrs['wrapper_id'] : '';
$public_safe = ! empty($attrs['public_safe']);
$has_trust_strip = ! empty( $attrs['trust_strip'] );
$root_attrs  = \VectorYT\Gallery\Render\TemplateAttributes::to_html(
    \VectorYT\Gallery\Render\TemplateAttributes::feed_root($attrs, $source, $public_safe)
);
$width_class = \VectorYT\Gallery\Render\TemplateAttributes::width_class($attrs);
$slide_count = count($videos);
$layout_slug = (string) ($attrs['layout'] ?? 'carousel');
$thumb_settings = is_array( $card_settings ?? null ) ? $card_settings : $attrs;
$thumbnail_style = $renderer->thumbnail_style_attr( $thumb_settings );
$feed_header_partial = __DIR__ . '/partials/feed-header.php';
$trust_strip_partial = __DIR__ . '/partials/trust-strip.php';

/**
 * Center-on-load: the slide that is visually in the middle of the track on
 * first render gets the --active treatment. Default = floor($slide_count / 2)
 * so a 5-slide carousel with 3 visible at a time is centered on the 3rd
 * slide (0-indexed position 2). This matches the prototype's "active"
 * state (purple outline + translateY(-5px)) and the matching dot's
 * pill (purple, 28px). The carousel JS (assets/js/carousel.js) updates
 * the active index on scroll/click/keyboard.
 */
$active_index = (int) floor( $slide_count / 2 );
?>
<div class="vyg-feed vyg-feed--carousel vyg-carousel vyg-carousel--per-<?php echo (int) $visible; ?> <?php echo esc_attr($width_class); ?>"
     <?php if ('' !== $wrapper_id) : ?>id="<?php echo esc_attr($wrapper_id); ?>"<?php endif; ?>
     <?php echo $root_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — TemplateAttributes::to_html escapes each attribute. ?>
     role="region"
     aria-roledescription="carousel"
     aria-label="<?php echo esc_attr((string) ($source['title'] ?? __('Videos', 'vector-youtube-gallery'))); ?>"
     data-slide-count="<?php echo (int) $slide_count; ?>"
     data-per-view="<?php echo (int) $visible; ?>">
    <?php
    // Phase 14.9 — shared top header (kicker + h1 + intro + pill + CTA).
    if ( file_exists( $feed_header_partial ) ) {
        // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
        include $feed_header_partial;
    }
    ?>
    <button type="button"
            class="vyg-carousel__btn vyg-carousel__btn--prev"
            aria-label="<?php esc_attr_e('Previous slide', 'vector-youtube-gallery'); ?>"
            aria-controls="<?php echo esc_attr($wrapper_id); ?>-track"
            disabled>
        <span aria-hidden="true">&lsaquo;</span>
    </button>

    <ul class="vyg-carousel__track"
        id="<?php echo esc_attr($wrapper_id); ?>-track"
        role="listbox"
        aria-label="<?php esc_attr_e('Video slides', 'vector-youtube-gallery'); ?>"
        tabindex="0">
        <?php foreach ($videos as $i => $video) : ?>
            <?php
            $embed_url   = $renderer->embed_url($video);
            $watch_url   = $renderer->watch_url($video);
            $thumb       = $renderer->thumbnail_url($video, $thumb_settings);
            $duration    = $renderer->format_duration((int) ($video['duration_seconds'] ?? 0));
            $is_live     = 'live' === ($video['live_status'] ?? '');
            $slide_index = $i + 1;
            $is_active   = ( $i === $active_index );
            $slide_classes = $is_active
                ? 'vyg-carousel__slide vyg-carousel__slide--active vyg-card'
                : 'vyg-carousel__slide vyg-card';
            ?>
            <li class="<?php echo esc_attr( $slide_classes ); ?>"
                role="option"
                aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
                aria-posinset="<?php echo (int) $slide_index; ?>"
                aria-setsize="<?php echo (int) $slide_count; ?>"
                data-video-id="<?php echo esc_attr((string) ($video['youtube_video_id'] ?? '')); ?>"
                data-content-type="<?php echo esc_attr((string) ($video['content_type'] ?? 'standard')); ?>"
                data-live-status="<?php echo esc_attr((string) ($video['live_status'] ?? 'none')); ?>"
                data-slide-index="<?php echo (int) $slide_index; ?>">
                <a class="vyg-card__link" href="<?php echo esc_url($watch_url); ?>"
                   data-vyg-lightbox="<?php echo esc_attr($embed_url); ?>"
                   data-vyg-title="<?php echo esc_attr((string) ($video['title'] ?? '')); ?>"
                   aria-label="<?php echo esc_attr(sprintf(__('Watch %s', 'vector-youtube-gallery'), (string) ($video['title'] ?? ''))); ?>">
                    <div class="vyg-card__thumb-wrap">
                        <img class="vyg-card__thumb"
                             src="<?php echo esc_url($thumb); ?>"
                             alt="<?php echo esc_attr((string) ($video['title'] ?? '')); ?>"
                             style="<?php echo esc_attr( $thumbnail_style ); ?>"
                             loading="lazy"
                             decoding="async" />
                        <?php if ('' !== $duration) : ?>
                            <span class="vyg-card__duration"><?php echo esc_html($duration); ?></span>
                        <?php endif; ?>
                        <?php if ($is_live) : ?>
                            <span class="vyg-card__badge vyg-card__badge--live"><?php esc_html_e('LIVE', 'vector-youtube-gallery'); ?></span>
                        <?php endif; ?>
                    </div>
                    <h3 class="vyg-card__title"><?php echo esc_html((string) ($video['title'] ?? '')); ?></h3>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ( $slide_count > 1 ) : ?>
        <div class="vyg-carousel__dots" role="tablist" aria-label="<?php esc_attr_e('Choose slide', 'vector-youtube-gallery'); ?>">
            <?php foreach ( $videos as $i => $video ) : ?>
                <?php
                $is_active_dot = ( $i === $active_index );
                $dot_index     = $i + 1; // 1-indexed for aria + data attr.
                $dot_classes   = $is_active_dot
                    ? 'vyg-carousel__dot vyg-carousel__dot--on'
                    : 'vyg-carousel__dot';
                $dot_label     = sprintf(
                    /* translators: %d: 1-indexed slide number. */
                    __( 'Go to slide %d', 'vector-youtube-gallery' ),
                    $dot_index
                );
                ?>
                <button type="button"
                        class="<?php echo esc_attr( $dot_classes ); ?>"
                        data-slide-index="<?php echo (int) $dot_index; ?>"
                        role="tab"
                        aria-label="<?php echo esc_attr( $dot_label ); ?>"
                        aria-selected="<?php echo $is_active_dot ? 'true' : 'false'; ?>"
                        aria-controls="<?php echo esc_attr( $wrapper_id ); ?>-track"></button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <button type="button"
            class="vyg-carousel__btn vyg-carousel__btn--next"
            aria-label="<?php esc_attr_e('Next slide', 'vector-youtube-gallery'); ?>"
            aria-controls="<?php echo esc_attr($wrapper_id); ?>-track"
            <?php echo $slide_count <= $visible ? 'disabled' : ''; ?>>
        <span aria-hidden="true">&rsaquo;</span>
    </button>

    <div class="vyg-carousel__live" aria-live="polite" aria-atomic="true"></div>
    <?php
    // Phase 14.10 — shared trust strip. Mirrors the grid layout's
    // existing 14.x inline strip, extracted to a partial so grid,
    // masonry, and carousel can all share the same 4-item trust row.
    // Gated on the same `trust_strip` boolean.
    if ( $has_trust_strip && file_exists( $trust_strip_partial ) ) {
        // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
        include $trust_strip_partial;
    }
    ?>
</div>
<?php
// Pagination: render load-more button if requested.
if (isset($attrs['pagination']) && 'load_more' === $attrs['pagination']) {
    $next_offset = (int) ($attrs['offset'] ?? 0) + count($videos);
    $remaining   = isset($attrs['total']) ? max(0, (int) $attrs['total'] - $next_offset) : 0;
    if ($remaining > 0) {
        $load_more_attrs = \VectorYT\Gallery\Render\TemplateAttributes::to_html(
            \VectorYT\Gallery\Render\TemplateAttributes::load_more($attrs, $source, $next_offset, $public_safe)
        );
        echo '<div class="vyg-feed__loadmore-wrap">';
        echo '<button type="button" class="vyg-feed__loadmore button" ' . $load_more_attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        esc_html_e('Load more', 'vector-youtube-gallery');
        echo '</button>';
        echo '<span class="vyg-feed__remaining"> (' . esc_html((string) $remaining) . ')</span>';
        echo '</div>';
    }
}

// Carousel JS is enqueued by AssetManager::enqueue_for_layout when the layout
// is "carousel" (see AssetManager::enqueue_for_layout's `carousel` branch).
