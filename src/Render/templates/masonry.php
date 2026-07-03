<?php
/**
 * Masonry layout template.
 *
 * Variables:
 *   $source   — array{id, source_uuid, source_type, title, ...}
 *   $videos   — array<int, array> of normalized video rows
 *   $attrs    — shortcode/block attributes (layout, per_page, columns, ...)
 *   $renderer — VideoRenderer instance
 *
 * Implementation: CSS `column-count` + `break-inside: avoid` on cards. Pure CSS,
 * no JS. Columns are responsive: $columns on desktop, 2 on tablet, 1 on mobile.
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

$columns    = isset($attrs['columns']) ? max(1, min(6, (int) $attrs['columns'])) : 3;
$wrapper_id = isset($attrs['wrapper_id']) ? (string) $attrs['wrapper_id'] : '';
$public_safe = ! empty($attrs['public_safe']);
$has_trust_strip = ! empty( $attrs['trust_strip'] );
$root_attrs = \VectorYT\Gallery\Render\TemplateAttributes::to_html(
    \VectorYT\Gallery\Render\TemplateAttributes::feed_root($attrs, $source, $public_safe)
);
$width_class = \VectorYT\Gallery\Render\TemplateAttributes::width_class($attrs);
$layout_slug = (string) ($attrs['layout'] ?? 'masonry');
$thumb_settings = is_array( $card_settings ?? null ) ? $card_settings : $attrs;
$thumbnail_style = $renderer->thumbnail_style_attr( $thumb_settings );
$feed_header_partial = __DIR__ . '/partials/feed-header.php';
$has_feed_header     = ! empty( $attrs['show_feed_header'] );
$trust_strip_partial = __DIR__ . '/partials/trust-strip.php';
?>
<div class="vyg-feed vyg-feed--masonry vyg-masonry vyg-masonry--cols-<?php echo (int) $columns; ?> <?php echo esc_attr($width_class); ?>"
     <?php if ('' !== $wrapper_id) : ?>id="<?php echo esc_attr($wrapper_id); ?>"<?php endif; ?>
     <?php echo $root_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — TemplateAttributes::to_html escapes each attribute. ?>>
    <?php
    // Phase 14.9 — shared top header (kicker + h1 + intro + pill + CTA).
    if ( $has_feed_header && file_exists( $feed_header_partial ) ) {
        // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
        include $feed_header_partial;
    }
    ?>
    <?php foreach ($videos as $video) : ?>
        <?php
        $embed_url = $renderer->embed_url($video);
        $watch_url = $renderer->watch_url($video);
        $thumb     = $renderer->thumbnail_url($video, $thumb_settings);
        $duration  = $renderer->format_duration((int) ($video['duration_seconds'] ?? 0));
        $is_live   = 'live' === ($video['live_status'] ?? '');
        ?>
        <article class="vyg-card"
                 data-video-id="<?php echo esc_attr((string) ($video['youtube_video_id'] ?? '')); ?>"
                 data-content-type="<?php echo esc_attr((string) ($video['content_type'] ?? 'standard')); ?>"
                 data-live-status="<?php echo esc_attr((string) ($video['live_status'] ?? 'none')); ?>">
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
                <h3 class="vyg-card__title vyg-card__title--2"><?php echo esc_html((string) ($video['title'] ?? '')); ?></h3>
            </a>
        </article>
    <?php endforeach; ?>
    <?php
    // Phase 14.10 — shared trust strip. Mirrors the grid layout's
    // existing 14.x inline strip, extracted to a partial so masonry
    // and carousel can share the same 4-item trust row. Gated on the
    // same `trust_strip` boolean.
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
