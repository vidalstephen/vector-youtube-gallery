<?php
/**
 * Hero layout template.
 *
 * Variables:
 *   $source   — array{id, source_uuid, source_type, title, ...}
 *   $videos   — array<int, array> of normalized video rows
 *   $attrs    — shortcode/block attributes
 *   $renderer — VideoRenderer instance
 *
 * Implementation: large hero card for the first video + grid for the rest.
 * Phase 9 chose the commit-to-large-hero aesthetic rather than Phase 4's
 * "featured" which only differs from grid by hero size. The hero emits a
 * 16:9 maxres thumbnail and a generous description excerpt.
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

$hero       = $videos[0];
$rest       = array_slice($videos, 1);
$columns    = isset($attrs['columns']) ? max(1, min(6, (int) $attrs['columns'])) : 3;
$wrapper_id = isset($attrs['wrapper_id']) ? (string) $attrs['wrapper_id'] : '';
$public_safe = ! empty($attrs['public_safe']);
$root_attrs = \VectorYT\Gallery\Render\TemplateAttributes::to_html(
    \VectorYT\Gallery\Render\TemplateAttributes::feed_root($attrs, $source, $public_safe)
);

$hero_thumb = $renderer->best_thumbnail($hero, 'maxres');
if ('' === $hero_thumb) {
    $hero_thumb = $renderer->best_thumbnail($hero);
}
$duration_label = $renderer->format_duration((int) ($hero['duration_seconds'] ?? 0));
$hero_watch = $renderer->watch_url($hero);
$hero_embed = $renderer->embed_url($hero);
$hero_title = (string) ($hero['title'] ?? '');
$hero_description = (string) ($hero['description'] ?? '');
if (mb_strlen($hero_description) > 220) {
    $hero_description = mb_substr($hero_description, 0, 220) . '…';
}
$published_label = '';
if (! empty($hero['published_at'])) {
    $ts = strtotime((string) $hero['published_at']);
    if ($ts) {
        $published_label = date_i18n(get_option('date_format'), $ts);
    }
}
?>
<div class="vyg-feed vyg-feed--hero vyg-hero"
     <?php if ('' !== $wrapper_id) : ?>id="<?php echo esc_attr($wrapper_id); ?>"<?php endif; ?>
     <?php echo $root_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
    <article class="vyg-hero__primary vyg-card"
             data-video-id="<?php echo esc_attr((string) ($hero['youtube_video_id'] ?? '')); ?>"
             data-content-type="<?php echo esc_attr((string) ($hero['content_type'] ?? 'standard')); ?>"
             data-live-status="<?php echo esc_attr((string) ($hero['live_status'] ?? 'none')); ?>">
        <a class="vyg-hero__link" href="<?php echo esc_url($hero_watch); ?>"
           data-vyg-lightbox="<?php echo esc_attr($hero_embed); ?>"
           data-vyg-title="<?php echo esc_attr($hero_title); ?>"
           aria-label="<?php echo esc_attr(sprintf(__('Watch %s', 'vector-youtube-gallery'), $hero_title)); ?>">
            <div class="vyg-hero__thumb-wrap">
                <img class="vyg-hero__thumb"
                     src="<?php echo esc_url($hero_thumb); ?>"
                     alt="<?php echo esc_attr($hero_title); ?>"
                     loading="eager" decoding="async"
                     fetchpriority="high" />
                <?php if ('' !== $duration_label) : ?>
                    <span class="vyg-card__duration"><?php echo esc_html($duration_label); ?></span>
                <?php endif; ?>
            </div>
            <div class="vyg-hero__meta">
                <h2 class="vyg-hero__title"><?php echo esc_html($hero_title); ?></h2>
                <?php if (! empty($source['title'])) : ?>
                    <p class="vyg-hero__channel"><?php echo esc_html((string) $source['title']); ?></p>
                <?php endif; ?>
                <?php if ('' !== $published_label) : ?>
                    <p class="vyg-hero__date"><?php echo esc_html($published_label); ?></p>
                <?php endif; ?>
                <?php if ('' !== $hero_description) : ?>
                    <p class="vyg-hero__desc"><?php echo esc_html($hero_description); ?></p>
                <?php endif; ?>
            </div>
        </a>
    </article>

    <?php if (! empty($rest)) : ?>
        <div class="vyg-hero__rest vyg-grid vyg-grid--cols-<?php echo (int) $columns; ?>">
            <?php foreach ($rest as $video) : ?>
                <article class="vyg-card"
                         data-video-id="<?php echo esc_attr((string) ($video['youtube_video_id'] ?? '')); ?>"
                         data-content-type="<?php echo esc_attr((string) ($video['content_type'] ?? 'standard')); ?>">
                    <a class="vyg-card__link" href="<?php echo esc_url($renderer->watch_url($video)); ?>"
                       data-vyg-lightbox="<?php echo esc_attr($renderer->embed_url($video)); ?>"
                       data-vyg-title="<?php echo esc_attr((string) ($video['title'] ?? '')); ?>">
                        <div class="vyg-card__thumb-wrap">
                            <img class="vyg-card__thumb"
                                 src="<?php echo esc_url($renderer->best_thumbnail($video)); ?>"
                                 alt="<?php echo esc_attr((string) ($video['title'] ?? '')); ?>"
                                 loading="lazy" decoding="async" />
                            <span class="vyg-card__duration"><?php echo esc_html($renderer->format_duration((int) ($video['duration_seconds'] ?? 0))); ?></span>
                        </div>
                        <h3 class="vyg-card__title"><?php echo esc_html((string) ($video['title'] ?? '')); ?></h3>
                    </a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php
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
