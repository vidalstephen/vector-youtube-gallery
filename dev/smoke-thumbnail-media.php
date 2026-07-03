<?php
/**
 * Smoke test: responsive thumbnail media wells + operator override URL.
 *
 * Verifies that the real Way Of Holiness source renders all public layouts
 * with object-fit/object-position styles and that thumbnail_override_url
 * replaces synced YouTube thumbnails without triggering API quota.
 */

declare(strict_types=1);

use VectorYT\Gallery\Plugin;

require_once dirname(__DIR__, 4) . '/wp-load.php';

$container = Plugin::container();
if (null === $container) {
    fwrite(STDERR, "Plugin container unavailable\n");
    exit(1);
}

$renderer = $container->get('render.renderer');
$templates = $container->get('render.templates');
global $wpdb;

$source_uuid = (string) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT source_uuid FROM {$wpdb->prefix}vyg_sources WHERE youtube_channel_id = %s AND status = 'active' LIMIT 1",
        'UCETTSWoXxA-oEbwxqpbVf-w'
    )
);

if ('' === $source_uuid) {
    fwrite(STDERR, "Way Of Holiness source not found\n");
    exit(1);
}

$before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}vyg_api_quota_log");
$ok = 0;
$fail = 0;

$check = static function (string $label, bool $condition, string $detail = '') use (&$ok, &$fail): void {
    if ($condition) {
        ++$ok;
        echo "PASS: {$label}\n";
        return;
    }
    ++$fail;
    echo "FAIL: {$label}" . ('' !== $detail ? " — {$detail}" : '') . "\n";
};

$source = (array) $wpdb->get_row(
    $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}vyg_sources WHERE source_uuid = %s LIMIT 1",
        $source_uuid
    ),
    ARRAY_A
);
$synthetic_videos = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}vyg_videos WHERE youtube_channel_id = %s AND thumbnail_medium <> '' ORDER BY published_at DESC LIMIT 8",
        'UCETTSWoXxA-oEbwxqpbVf-w'
    ),
    ARRAY_A
);

$layouts = array('grid', 'list', 'featured', 'hero', 'shorts', 'masonry', 'carousel', 'live');
foreach ($layouts as $layout) {
    $args = array(
        'source_uuid'        => $source_uuid,
        'layout'             => $layout,
        'per_page'           => 8,
        'columns'            => 3,
        'thumbnail_fit'      => 'cover',
        'thumbnail_position' => 'top_center',
        'show_h1'            => true,
        'show_pill'          => true,
    );
    if ('shorts' === $layout) {
        $html = $templates->render('shorts', array(
            'source'        => $source,
            'videos'        => $synthetic_videos,
            'attrs'         => array_merge($args, array('public_safe' => false)),
            'renderer'      => $container->get('render.video'),
            'card_settings' => $args,
        ));
        // For shorts, the auto-zoom path overrides object-position to
        // `center 18%` so the meaningful content stays visible. Verify
        // that explicitly here, separately from the non-shorts layouts.
        $check("{$layout} portrait object-position center 18%", false !== strpos($html, 'object-position:center 18%'), substr($html, 0, 120));
        $check("{$layout} emits object-fit cover", false !== strpos($html, 'object-fit:cover'), substr($html, 0, 120));
    } else {
        $html = $renderer->render($args);
        $check("{$layout} emits object-fit cover", false !== strpos($html, 'object-fit:cover'), substr($html, 0, 120));
        $check("{$layout} emits top-center object-position", false !== strpos($html, 'object-position:top center'), substr($html, 0, 120));
    }
}

$override = 'https://cdn.example.com/operator-poster.jpg';
$html = $renderer->render(array(
    'source_uuid'             => $source_uuid,
    'layout'                  => 'grid',
    'per_page'                => 3,
    'thumbnail_override_url'  => $override,
    'thumbnail_fit'           => 'contain',
    'thumbnail_position'      => 'bottom_center',
));
$check('override URL appears in rendered grid', false !== strpos($html, $override));
$check('override render emits contain fit', false !== strpos($html, 'object-fit:contain'));
$check('override render emits bottom-center position', false !== strpos($html, 'object-position:bottom center'));

// Portrait (shorts) auto-zoom path.
$shorts_html = $templates->render('shorts', array(
    'source'        => $source,
    'videos'        => $synthetic_videos,
    'attrs'         => array_merge($args, array(
        'layout'      => 'shorts',
        'public_safe' => false,
    )),
    'renderer'      => $container->get('render.video'),
    'card_settings' => array(),
));
$check('shorts auto-zoom applies transform:scale', false !== strpos($shorts_html, 'transform:scale(1.45)'), substr($shorts_html, 0, 200));

$after = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}vyg_api_quota_log");
$check('api_quota_delta=0', 0 === ($after - $before), 'delta=' . (string) ($after - $before));

echo "=== Summary: {$ok} OK, {$fail} FAIL ===\n";
exit(0 === $fail ? 0 : 1);
