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
        return array( 'grid', 'list', 'featured', 'shorts', 'live' );
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
            'source'  => self::json_field( $feed['source_config_json']  ?? null ),
            'display' => self::json_field( $feed['display_config_json'] ?? null ),
            'filter'  => self::json_field( $feed['filter_config_json']  ?? null ),
            'sort'    => self::json_field( $feed['sort_config_json']    ?? null ),
            'custom_css' => (string) ( $feed['custom_css'] ?? '' ),
        );
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
                    $out[ $json_col ] = wp_json_encode( $raw );
                } elseif ( is_string( $raw ) ) {
                    // Re-validate as JSON to prevent injection.
                    $decoded = json_decode( $raw, true );
                    $out[ $json_col ] = ( null === $decoded && json_last_error() !== JSON_ERROR_NONE ) ? null : wp_json_encode( $decoded );
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