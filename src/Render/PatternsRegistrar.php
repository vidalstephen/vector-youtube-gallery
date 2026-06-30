<?php
/**
 * Block patterns — prebuilt patterns for common galleries.
 *
 * Phase 9 shipped 4 prebuilt patterns to lower the entry-bar for new installs.
 * Each pattern registers a category (`vyg-patterns`) plus a pattern that uses
 * the existing `vectoryt/gallery` block with realistic default attributes.
 * The pattern's source UUID is the literal placeholder `<uuid-source>` — WordPress
 * swaps the source UUID at insertion time via a `--vyg:insert` callback for
 * `site-editor-canvas-only` (not currently shipped; operators select a source
 * through the block inspector at insert time).
 *
 * Hard rule: patterns must NEVER include secrets (no API keys, OAuth tokens,
 * client IDs). Patterns include layout, columns, content_type, and pagination
 * defaults only — the source comes from the block's source selector.
 *
 * @package VectorYT\Gallery\Render
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Render;

defined('ABSPATH') || exit;

final class PatternsRegistrar {

    public const CATEGORY_SLUG = 'vyg-patterns';

    public function register(): void {
        add_action('init', array($this, 'register_pattern_category'), 9);
        add_action('init', array($this, 'register_patterns'), 10);
    }

    public function register_pattern_category(): void {
        // Skip if block-pattern API isn't loaded (older WP or third-party disable).
        if (! function_exists('register_block_pattern_category')) {
            return;
        }
        register_block_pattern_category(self::CATEGORY_SLUG, array(
            'label' => __('Vector YouTube Gallery', 'vector-youtube-gallery'),
        ));
    }

    public function register_patterns(): void {
        if (! function_exists('register_block_pattern')) {
            return;
        }

        // 1. Channel grid — most common pattern: channel source as a 3-col grid.
        register_block_pattern('vyg/channel-grid', array(
            'title'       => __('YouTube: Channel Grid', 'vector-youtube-gallery'),
            'description' => __('A 3-column responsive grid of the latest videos from a channel.', 'vector-youtube-gallery'),
            'categories'  => array(self::CATEGORY_SLUG),
            'keywords'    => array('youtube', 'channel', 'grid', 'gallery'),
            'content'     => '<!-- wp:vectoryt/gallery {"source_uuid":"","layout":"grid","columns":3,"per_page":12,"orderby":"published_at","order":"DESC"} /-->',
        ));

        // 2. Shorts wall — vertical collection of #Shorts only.
        register_block_pattern('vyg/shorts-wall', array(
            'title'       => __('YouTube: Shorts Wall', 'vector-youtube-gallery'),
            'description' => __('A 4-column grid of Shorts videos only.', 'vector-youtube-gallery'),
            'categories'  => array(self::CATEGORY_SLUG),
            'keywords'    => array('youtube', 'shorts', 'vertical'),
            'content'     => '<!-- wp:vectoryt/gallery {"source_uuid":"","layout":"shorts","columns":4,"per_page":24,"content_type":"short_confirmed,short_candidate"} /-->',
        ));

        // 3. Live/Upcoming/Replay hub.
        register_block_pattern('vyg/live-hub', array(
            'title'       => __('YouTube: Live & Replay Hub', 'vector-youtube-gallery'),
            'description' => __('Sectioned layout (live now, upcoming, replay) showing everything in flight.', 'vector-youtube-gallery'),
            'categories'  => array(self::CATEGORY_SLUG),
            'keywords'    => array('youtube', 'live', 'replay', 'upcoming'),
            'content'     => '<!-- wp:vectoryt/gallery {"source_uuid":"","layout":"live","per_page":20,"orderby":"published_at","order":"DESC"} /-->',
        ));

        // 4. Featured landing — hero layout, latest video + gallery.
        register_block_pattern('vyg/featured-landing', array(
            'title'       => __('YouTube: Featured Landing Section', 'vector-youtube-gallery'),
            'description' => __('Hero card featuring the latest video followed by a small gallery.', 'vector-youtube-gallery'),
            'categories'  => array(self::CATEGORY_SLUG),
            'keywords'    => array('youtube', 'hero', 'featured', 'landing'),
            'content'     => '<!-- wp:vectoryt/gallery {"source_uuid":"","layout":"hero","per_page":9,"columns":3} /-->',
        ));
    }
}
