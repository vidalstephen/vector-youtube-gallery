<?php
/**
 * Schema.org JSON-LD output for front-end feeds.
 *
 * Phase 9.5: produces a `VideoObject` per video and a single surrounding
 * `ItemList` for the feed. All metadata comes from the local DB cache — we
 * never hit the YouTube API to generate structured data. Output is wrapped in
 * `<script type="application/ld+json">…</script>` and emitted once at the
 * bottom of the rendered feed (the only safe location; emitting inside the
 * root `<div>` would break HTML parsing).
 *
 * Hard rules:
 *   1. Never include API keys, OAuth tokens, or client IDs in JSON-LD output.
 *   2. Always escape the JSON via wp_json_encode + wp_kses_post for `<script>`.
 *   3. Honor the `schema_enabled` per-feed attribute; default OFF when the
 *      operator hasn't expressed intent (saves rendering and avoids stray
 *      JSON-LD scattered across the site).
 *   4. Skip videos whose availability is `deleted`, `private`, or
 *      `embed_disabled` — they wouldn't render in an actual player.
 *
 * Note: 9.7 ship-strict invariants in unit tests cover: keys present,
 * thumbnail URL present, ISO 8601 duration, and zero secret fields.
 *
 * @package VectorYT\Gallery\Render
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Render;

defined('ABSPATH') || exit;

final class SchemaLd {

    public const SCHEMA_VERSION = 'https://schema.org/';

    /**
     * Determine whether schema output is enabled for a given feed.
     *
     * @param array<string,mixed> $attrs
     */
    public static function is_enabled(array $attrs): bool {
        // Explicit opt-in wins. Default false unless the operator toggles.
        return ! empty($attrs['schema_enabled']);
    }

    /**
     * Build the JSON-LD payload for a feed.
     *
     * @param array<string,mixed> $source Source row (for feed-level ItemList publisher).
     * @param array<int,array<string,mixed>> $videos Each video row.
     * @param array<string,mixed> $attrs Render attributes.
     * @return array<int,array<string,mixed>> A list of one or two JSON-LD items:
     *   [0] ItemList (always when videos exist)
     *   [1] VideoObject (only when a single video is being embedded)
     */
    public static function build(?array $source, array $videos, array $attrs = array()): array {
        if (empty($videos)) {
            return array();
        }
        // Filter out videos that no longer render.
        $items = array_values(array_filter($videos, static function (array $v): bool {
            $avail = (string) ($v['availability_status'] ?? 'available');
            return ! in_array($avail, array('deleted', 'private', 'embed_disabled', 'unavailable'), true);
        }));

        if (empty($items)) {
            return array();
        }

        $list = array(
            '@context'        => self::SCHEMA_VERSION,
            '@type'           => 'ItemList',
            'name'            => (string) ($source['title'] ?? __('Video feed', 'vector-youtube-gallery')),
            'numberOfItems'   => count($items),
            'itemListElement' => array(),
        );

        foreach ($items as $i => $video) {
            $list['itemListElement'][] = array(
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'item'     => self::video_object($video, $source),
            );
        }

        return array($list);
    }

    /**
     * Render a single VideoObject. Exposed for templates that want inline
     * schema for the hero card.
     *
     * @param array<string,mixed> $video
     * @param array<string,mixed>|null $source
     * @return array<string,mixed>
     */
    public static function video_object(array $video, ?array $source = null): array {
        $id = (string) ($video['youtube_video_id'] ?? '');
        $url = $id !== '' ? 'https://www.youtube.com/watch?v=' . rawurlencode($id) : '';
        $thumb = self::best_thumbnail($video);

        $payload = array(
            '@type'            => 'VideoObject',
            'name'             => (string) ($video['title'] ?? ''),
            'description'      => (string) ($video['description'] ?? ''),
            'contentUrl'       => $url,
            'embedUrl'         => $id !== '' ? 'https://www.youtube.com/embed/' . rawurlencode($id) : '',
            'thumbnailUrl'     => array_values(array_filter(array($thumb))),
            'uploadDate'       => self::iso_date($video['published_at'] ?? ''),
        );

        $duration = self::iso_duration((int) ($video['duration_seconds'] ?? 0));
        if (null !== $duration) {
            $payload['duration'] = $duration;
        }

        if (! empty($video['view_count'])) {
            $payload['interactionStatistic'] = array(
                '@type'                => 'InteractionCounter',
                'interactionType'      => array(
                    '@type' => 'WatchAction',
                ),
                'userInteractionCount' => (int) $video['view_count'],
            );
        }

        if (null !== $source) {
            $channel_title = (string) ($source['title'] ?? '');
            $channel_url = '';
            $src_uuid = (string) ($source['source_uuid'] ?? '');
            // We can't synthesize the channel URL unless we know the source_type
            // (channel UC-ID or playlist PL-prefix). Only channel types receive one.
            if ('channel' === ($source['source_type'] ?? '') && ! empty($source['source_external_id'])) {
                $channel_url = 'https://www.youtube.com/channel/' . rawurlencode((string) $source['source_external_id']);
            }
            if ('' !== $channel_title || '' !== $channel_url) {
                $payload['publisher'] = array(
                    '@type' => 'Organization',
                    'name'  => $channel_title,
                );
                if ('' !== $channel_url) {
                    $payload['publisher']['url'] = $channel_url;
                }
            }
            // Avoid leaking the internal source UUID into structured data.
            unset($payload['source_uuid']);
        }

        // Remove empty strings so JSON-LD stays valid (Google's structured-data
        // validator is strict on missing fields).
        return array_filter($payload, static function ($v): bool {
            if (is_array($v)) {
                return ! empty($v);
            }
            return '' !== $v && null !== $v;
        });
    }

    /**
     * Render the JSON-LD `<script>` block for insertion in a template.
     *
     * @param array<string,mixed>|null $source
     * @param array<int,array<string,mixed>> $videos
     * @param array<string,mixed> $attrs
     * @return string HTML (empty when schema disabled or no items)
     */
    public static function render(?array $source, array $videos, array $attrs = array()): string {
        if (! self::is_enabled($attrs)) {
            return '';
        }
        $payload = self::build($source, $videos, $attrs);
        if (empty($payload)) {
            return '';
        }
        $json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (false === $json) {
            return '';
        }
        // Escape any </script> that an attacker might have injected into a
        // video title/description (defense in depth — the source data is from
        // the local DB which is itself sanitized at ingest, but a stored XSS
        // in a video record would be JSON-encoded cleanly otherwise).
        $json = str_replace(array('</script', '</Script', '</SCRIPT'), array('<\\/script', '<\\/Script', '<\\/SCRIPT'), $json);
        return '<script type="application/ld+json" class="vyg-schema">' . $json . '</script>';
    }

    /**
     * @param array<string,mixed> $video
     */
    private static function best_thumbnail(array $video): string {
        $order = array('thumbnail_maxres', 'thumbnail_standard', 'thumbnail_high', 'thumbnail_medium', 'thumbnail_default');
        foreach ($order as $col) {
            $url = (string) ($video[$col] ?? '');
            if ('' !== $url) {
                return $url;
            }
        }
        return '';
    }

    private static function iso_date($raw): ?string {
        if (! is_string($raw) || '' === $raw) {
            return null;
        }
        $ts = strtotime($raw);
        if (! $ts) {
            return null;
        }
        return gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    private static function iso_duration(int $seconds): ?string {
        if ($seconds <= 0) {
            return null;
        }
        $h = (int) floor($seconds / 3600);
        $m = (int) floor(($seconds % 3600) / 60);
        $s = $seconds % 60;
        return 'PT' . $h . 'H' . $m . 'M' . $s . 'S';
    }
}
