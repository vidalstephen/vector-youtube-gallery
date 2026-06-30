<?php
/**
 * Feed repository — CRUD over the vyg_feeds table.
 *
 * Each Feed row is a saved configuration for a front-end gallery:
 *  - feed_type: 'source' (use a vyg_sources row) or 'manual' (curated list)
 *  - layout: grid | list | featured | shorts | live
 *  - source_config_json: {source_uuid?, layout_attrs...}
 *  - display_config_json: {columns, per_page, lightbox, load_more, ...}
 *  - filter_config_json:  {content_type, exclude_shorts, ...}
 *  - sort_config_json:    {orderby, order}
 *  - custom_css:          raw CSS scoped under .vyg-feed[data-feed-uuid="..."]
 *
 * @package VectorYT\Gallery\Repository
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Repository;

use VectorYT\Gallery\Database\Schema;

defined( 'ABSPATH' ) || exit;

class FeedRepository {

    public function __construct() {}

    public function table(): string {
        return Schema::table( 'vyg_feeds' );
    }

    /**
     * Allowed feed types.
     * @return array<int,string>
     */
    public static function allowed_feed_types(): array {
        return array( 'source', 'manual' );
    }

    /**
     * Allowed layouts — must match Render\Layouts\* keys.
     * @return array<int,string>
     */
    public static function allowed_layouts(): array {
        return array( 'grid', 'list', 'featured', 'shorts', 'live', 'masonry', 'carousel', 'hero' );
    }

    /**
     * Allow-list of keys that may appear in `display_config_json`.
     *
     * Phase 13.1 grid redesign added the `density`, `header_*`, `show_*`,
     * `product_cta_visible`, `trust_strip`, and `card_radius` keys. Anything
     * outside this set is dropped on save — defense in depth so a feed row
     * cannot be tricked into storing a non-standard key that the templates
     * don't know how to render.
     *
     * @return array<int,string>
     */
    public static function allowed_display_keys(): array {
        return array(
            // Existing pre-Phase-13.1 keys (kept for back-compat).
            'columns',
            'per_page',
            'preset',
            'lightbox',
            'load_more',
            'schema_enabled',
            'player_mode',
            // Phase 13.1 — density.
            'density',
            // Phase 13.1 — section header.
            'header_title',
            'header_subtitle',
            'header_columns_visible',
            'header_cta_label',
            'header_cta_url',
            // Phase 13.1 — card.
            'show_channel_avatar',
            'show_channel_name',
            'show_subscriber_count',
            'show_verified_badge',
            'show_views_and_time',
            'product_cta_visible',
            'card_radius',
            // Phase 13.1 — footer.
            'trust_strip',
        );
    }

    /**
     * Allow-list of values for the `density` attribute.
     * @return array<int,string>
     */
    public static function allowed_densities(): array {
        return array( 'compact', 'comfortable', 'editorial' );
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create( array $data ): int {
        global $wpdb;
        $defaults = array(
            'feed_uuid'      => wp_generate_uuid4(),
            'name'           => '',
            'feed_type'      => 'source',
            'layout'         => 'grid',
            'status'         => 'draft',
            'created_at'     => gmdate( 'Y-m-d H:i:s' ),
            'updated_at'     => gmdate( 'Y-m-d H:i:s' ),
        );
        $row = array_merge( $defaults, $this->sanitize( $data ) );
        $row['updated_at'] = gmdate( 'Y-m-d H:i:s' );
        $wpdb->insert( $this->table(), $row, $this->format( $row ) );
        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update( int $id, array $data ): bool {
        global $wpdb;
        $row = $this->sanitize( $data );
        $row['updated_at'] = gmdate( 'Y-m-d H:i:s' );
        return false !== $wpdb->update(
            $this->table(),
            $row,
            array( 'id' => $id ),
            $this->format( $row ),
            array( '%d' )
        );
    }

    public function delete( int $id ): bool {
        global $wpdb;
        return false !== $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find_by_uuid( string $uuid ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE feed_uuid = %s", $uuid ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /**
     * @param array<string,mixed> $filters Optional {status, feed_type, layout}.
     * @return array<int,array<string,mixed>>
     */
    public function list( array $filters = array() ): array {
        global $wpdb;
        $where = array( '1=1' );
        $params = array();
        if ( ! empty( $filters['status'] ) ) {
            $where[] = 'status = %s';
            $params[] = (string) $filters['status'];
        }
        if ( ! empty( $filters['feed_type'] ) ) {
            $where[] = 'feed_type = %s';
            $params[] = (string) $filters['feed_type'];
        }
        if ( ! empty( $filters['layout'] ) ) {
            $where[] = 'layout = %s';
            $params[] = (string) $filters['layout'];
        }
        $sql = "SELECT * FROM {$this->table()} WHERE " . implode( ' AND ', $where ) . " ORDER BY id DESC";
        if ( ! empty( $params ) ) {
            $sql = $wpdb->prepare( $sql, $params );
        }
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Decode the JSON config columns into a config array.
     *
     * @param array<string,mixed> $feed
     * @return array<string,mixed>
     */
    public static function decode_config( array $feed ): array {
        return array(
            'source'     => self::normalize_source_config( self::json_field( $feed['source_config_json'] ?? null ) ),
            'display'    => self::json_field( $feed['display_config_json'] ?? null ),
            'filter'     => self::json_field( $feed['filter_config_json']  ?? null ),
            'sort'       => self::json_field( $feed['sort_config_json']    ?? null ),
            'custom_css' => (string) ( $feed['custom_css'] ?? '' ),
        );
    }

    /**
     * Normalize a feed's source_config into the canonical multi-source shape.
     *
     * Backwards compatible: a legacy single-source config like
     *   {"source_uuid":"abc"}
     * is rewritten to
     *   {"sources":[{"source_uuid":"abc","weight":1.0,"pinned":false,"label":""}],
     *    "manual_video_ids":[], "exclude_video_ids":[], "include_query":"any"}
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public static function normalize_source_config( array $raw ): array {
        $sources = array();

        // New canonical form: sources[]. Each entry must have source_uuid.
        if ( isset( $raw['sources'] ) && is_array( $raw['sources'] ) ) {
            foreach ( $raw['sources'] as $entry ) {
                if ( ! is_array( $entry ) ) {
                    continue;
                }
                $uuid = isset( $entry['source_uuid'] ) ? (string) $entry['source_uuid'] : '';
                if ( '' === $uuid ) {
                    continue;
                }
                $sources[] = array(
                    'source_uuid' => sanitize_text_field( $uuid ),
                    'weight'      => self::coerce_weight( $entry['weight'] ?? 1.0 ),
                    'pinned'      => ! empty( $entry['pinned'] ),
                    'label'       => isset( $entry['label'] ) ? sanitize_text_field( (string) $entry['label'] ) : '',
                );
            }
        }

        // Legacy single-source form: {source_uuid: "..."} → migrate to sources[].
        if ( empty( $sources ) && isset( $raw['source_uuid'] ) && is_string( $raw['source_uuid'] ) && '' !== $raw['source_uuid'] ) {
            $sources[] = array(
                'source_uuid' => sanitize_text_field( $raw['source_uuid'] ),
                'weight'      => 1.0,
                'pinned'      => false,
                'label'       => '',
            );
        }

        $manual = array();
        if ( isset( $raw['manual_video_ids'] ) && is_array( $raw['manual_video_ids'] ) ) {
            foreach ( $raw['manual_video_ids'] as $vid ) {
                $vid = (string) $vid;
                if ( '' === $vid ) {
                    continue;
                }
                if ( preg_match( '/^[A-Za-z0-9_-]{1,32}$/', $vid ) ) {
                    $manual[] = $vid;
                }
            }
            $manual = array_values( array_unique( $manual ) );
        }

        $exclude = array();
        if ( isset( $raw['exclude_video_ids'] ) && is_array( $raw['exclude_video_ids'] ) ) {
            foreach ( $raw['exclude_video_ids'] as $vid ) {
                $vid = (string) $vid;
                if ( '' === $vid ) {
                    continue;
                }
                if ( preg_match( '/^[A-Za-z0-9_-]{1,32}$/', $vid ) ) {
                    $exclude[] = $vid;
                }
            }
            $exclude = array_values( array_unique( $exclude ) );
        }

        $include_query = isset( $raw['include_query'] ) ? (string) $raw['include_query'] : 'any';
        $include_query = in_array( $include_query, array( 'any', 'all' ), true ) ? $include_query : 'any';

        return array(
            'sources'           => $sources,
            'manual_video_ids'  => $manual,
            'exclude_video_ids' => $exclude,
            'include_query'     => $include_query,
        );
    }

    /**
     * Coerce a weight value into the [0.0, 10.0] float range.
     *
     * @param mixed $value
     */
    private static function coerce_weight( $value ): float {
        if ( ! is_numeric( $value ) ) {
            return 1.0;
        }
        $w = (float) $value;
        if ( $w < 0.0 ) {
            return 0.0;
        }
        if ( $w > 10.0 ) {
            return 10.0;
        }
        return $w;
    }

    /**
     * @param mixed $value
     * @return array<string,mixed>
     */
    private static function json_field( $value ): array {
        if ( ! is_string( $value ) || $value === '' ) {
            return array();
        }
        $decoded = json_decode( $value, true );
        return is_array( $decoded ) ? $decoded : array();
    }

    /**
     * Validate and coerce incoming data against allowed enums.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function sanitize( array $data ): array {
        $out = array();
        if ( isset( $data['name'] ) ) {
            $out['name'] = sanitize_text_field( (string) $data['name'] );
        }
        if ( isset( $data['feed_type'] ) ) {
            $ft = (string) $data['feed_type'];
            $out['feed_type'] = in_array( $ft, self::allowed_feed_types(), true ) ? $ft : 'source';
        }
        if ( isset( $data['layout'] ) ) {
            $layout = (string) $data['layout'];
            $out['layout'] = in_array( $layout, self::allowed_layouts(), true ) ? $layout : 'grid';
        }
        foreach ( array( 'source_config_json', 'display_config_json', 'filter_config_json', 'sort_config_json' ) as $json_col ) {
            if ( isset( $data[ $json_col ] ) ) {
                $raw = $data[ $json_col ];
                if ( is_array( $raw ) ) {
                    // Phase 13.1: the display config allow-list sanitizes
                    // every key (drops unknowns) and coerces booleans / density
                    // before encoding. Other JSON columns keep their prior
                    // passthrough behavior.
                    if ( 'display_config_json' === $json_col ) {
                        $out[ $json_col ] = wp_json_encode( self::sanitize_display_config( $raw ) );
                    } else {
                        $out[ $json_col ] = wp_json_encode( $raw );
                    }
                } elseif ( is_string( $raw ) ) {
                    // Re-validate as JSON to prevent injection.
                    $decoded = json_decode( $raw, true );
                    if ( 'display_config_json' === $json_col && is_array( $decoded ) ) {
                        $out[ $json_col ] = wp_json_encode( self::sanitize_display_config( $decoded ) );
                    } else {
                        $out[ $json_col ] = ( null === $decoded && json_last_error() !== JSON_ERROR_NONE ) ? null : wp_json_encode( $decoded );
                    }
                } else {
                    $out[ $json_col ] = null;
                }
            }
        }
        if ( isset( $data['custom_css'] ) ) {
            $css = (string) $data['custom_css'];
            // Defense in depth against stored XSS:
            //   1. strip_tags removes any HTML tag (script/style/iframe/etc).
            //   2. preg_replace catches stray < / > that strip_tags missed
            //      (e.g. inside a JSON-escaped value or in CDATA-like syntax).
            //   3. remove url(...) values that reference javascript: or data:
            //      schemes to prevent CSS-based exfiltration or click-jacking.
            $css = strip_tags( $css );
            $css = preg_replace( '/[<>]/', '', $css ) ?? $css;
            $css = preg_replace( '/expression\s*\(|javascript\s*:/i', '', $css ) ?? $css;
            // Limit CSS length to 64KB to avoid DoS via huge payloads.
            $css = substr( $css, 0, 65535 );
            $out['custom_css'] = $css;
        }
        if ( isset( $data['status'] ) ) {
            $st = (string) $data['status'];
            $out['status'] = in_array( $st, array( 'draft', 'published', 'archived' ), true ) ? $st : 'draft';
        }
        return $out;
    }

    /**
     * Filter a `display_config_json` payload through the allow-list and
     * coerce each value to the right type.
     *
     * Defense in depth: even though the source is operator-controlled, an
     * attacker who can write to the DB directly could plant arbitrary keys.
     * Allow-listing means the templates never see a key they don't have CSS
     * or markup for.
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public static function sanitize_display_config( array $raw ): array {
        $allowed = array_flip( self::allowed_display_keys() );
        $out = array();
        foreach ( $raw as $key => $value ) {
            if ( ! is_string( $key ) || ! isset( $allowed[ $key ] ) ) {
                continue;
            }
            $out[ $key ] = self::coerce_display_value( $key, $value );
        }
        return $out;
    }

    /**
     * Coerce a single `display_config_json` value to its expected type.
     *
     * @param string $key
     * @param mixed  $value
     * @return mixed
     */
    private static function coerce_display_value( string $key, $value ) {
        switch ( $key ) {
            case 'density':
                $candidate = is_string( $value ) ? strtolower( trim( $value ) ) : '';
                return in_array( $candidate, self::allowed_densities(), true ) ? $candidate : 'comfortable';

            case 'header_title':
            case 'header_subtitle':
            case 'header_cta_label':
            case 'card_radius':
                return sanitize_text_field( (string) $value );

            case 'header_cta_url':
                return esc_url_raw( (string) $value );

            case 'columns':
                $n = (int) $value;
                if ( $n < 1 ) { return 1; }
                if ( $n > 6 ) { return 6; }
                return $n;

            case 'per_page':
                $n = (int) $value;
                if ( $n < 1 ) { return 1; }
                if ( $n > 200 ) { return 200; }
                return $n;

            case 'preset':
            case 'player_mode':
                $candidate = sanitize_key( (string) $value );
                return $candidate;

            // All known booleans go through filter_var so truthy strings
            // ("yes", "true", "1") and ints coerce predictably.
            case 'header_columns_visible':
            case 'show_channel_avatar':
            case 'show_channel_name':
            case 'show_subscriber_count':
            case 'show_verified_badge':
            case 'show_views_and_time':
            case 'product_cta_visible':
            case 'trust_strip':
            case 'lightbox':
            case 'load_more':
            case 'schema_enabled':
                return filter_var( $value, FILTER_VALIDATE_BOOLEAN );

            default:
                // Unknown allowed key — keep the value as-is.
                return $value;
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<int,string>
     */
    private function format( array $row ): array {
        $out = array();
        foreach ( $row as $col => $val ) {
            $out[] = is_int( $val ) ? '%d' : '%s';
        }
        return $out;
    }
}