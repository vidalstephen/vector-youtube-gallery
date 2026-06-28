<?php
/**
 * GdprHooks — registers personal-data exporters and erasers for WP's
 * "Export Personal Data" + "Erase Personal Data" tools.
 *
 * Scans all vyg_* tables for any column named *user_id* (created_by_user_id,
 * last_edited_by_user_id, etc.) and returns matching rows for the requested
 * WP user ID. No personal data is ever collected automatically by the plugin
 * (YouTube IDs + metadata are public), but this allows operators to scrub
 * any operator-attributed rows on demand.
 *
 * @package VectorYT\Gallery\Admin
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Admin;

defined( 'ABSPATH' ) || exit;

final class GdprHooks {

    private const CALLBACK_ID = 'vector-youtube-gallery';

    /**
     * Add our exporter to WP's personal data export pipeline.
     *
     * @param array<string,array<string,mixed>> $exporters
     * @return array<string,array<string,mixed>>
     */
    public function register_exporter( array $exporters ): array {
        $exporters[ self::CALLBACK_ID ] = array(
            'exporter_friendly_name' => __( 'Vector YouTube Gallery', 'vector-youtube-gallery' ),
            'callback'               => array( $this, 'export_user_data' ),
        );
        return $exporters;
    }

    /**
     * Add our eraser to WP's personal data erasure pipeline.
     *
     * @param array<string,array<string,mixed>> $erasers
     * @return array<string,array<string,mixed>>
     */
    public function register_eraser( array $erasers ): array {
        $erasers[ self::CALLBACK_ID ] = array(
            'eraser_friendly_name' => __( 'Vector YouTube Gallery', 'vector-youtube-gallery' ),
            'callback'             => array( $this, 'erase_user_data' ),
        );
        return $erasers;
    }

    /**
     * Export callback for WP_Privacy_Data_Exporter.
     *
     * @param string $email_address User email (we look up the WP user).
     * @param int    $page          1-indexed page number.
     * @return array<string,mixed>
     */
    public function export_user_data( string $email_address, int $page = 1 ): array {
        $user = get_user_by( 'email', $email_address );
        if ( ! $user ) {
            return array(
                'data' => array(),
                'done' => true,
            );
        }
        $user_id = (int) $user->ID;
        $rows = $this->find_rows_for_user( $user_id );

        $export_items = array();
        foreach ( $rows as $row ) {
            $table = $row['__table'] ?? '(unknown)';
            unset( $row['__table'] );
            $export_items[] = array(
                'group_id'    => 'vyg_rows',
                'group_label' => __( 'YouTube Gallery Data', 'vector-youtube-gallery' ),
                'item_id'     => self::CALLBACK_ID . ':' . $table . ':' . md5( serialize( $row ) ),
                'data'        => array(
                    array(
                        'name'  => sprintf( /* translators: %s: table name */ __( 'Row in table %s', 'vector-youtube-gallery' ), $table ),
                        'value' => wp_json_encode( $row ),
                    ),
                ),
            );
        }
        return array(
            'data' => $export_items,
            'done' => true,
        );
    }

    /**
     * Eraser callback for WP_Privacy_Data_Eraser.
     *
     * @param string $email_address
     * @param int    $page
     * @return array<string,mixed>
     */
    public function erase_user_data( string $email_address, int $page = 1 ): array {
        $user = get_user_by( 'email', $email_address );
        if ( ! $user ) {
            return array(
                'items_removed'  => false,
                'items_retained' => false,
                'messages'       => array(),
                'done'           => true,
            );
        }
        $user_id = (int) $user->ID;
        $rows    = $this->find_rows_for_user( $user_id );
        $removed = 0;
        foreach ( $rows as $row ) {
            $table = $row['__table'] ?? '';
            $user_col = $row['__user_col'] ?? '';
            unset( $row['__table'], $row['__user_col'] );
            if ( ! $table || ! $user_col ) {
                continue;
            }
            $primary_col = $row['__primary_col'] ?? 'id';
            if ( ! isset( $row[ $primary_col ] ) ) {
                continue;
            }
            global $wpdb;
            $full_table = $wpdb->prefix . $table;
            $deleted = $wpdb->update(
                $full_table,
                array( $user_col => null ),
                array( $primary_col => $row[ $primary_col ] ),
                array( '%s' ),
                array( '%d' )
            );
            if ( false !== $deleted && $deleted > 0 ) {
                ++$removed;
            }
        }
        return array(
            'items_removed'  => $removed > 0,
            'items_retained' => false,
            'messages'       => array( sprintf( _n( 'Removed %d VYG row.', 'Removed %d VYG rows.', $removed, 'vector-youtube-gallery' ), $removed ) ),
            'done'           => true,
        );
    }

    /**
     * Scan all vyg_* tables for columns named *user_id and return rows
     * that match the given WP user ID. Each row is annotated with __table,
     * __user_col, __primary_col so the eraser can scrub them.
     *
     * @return array<int,array<string,mixed>>
     */
    private function find_rows_for_user( int $user_id ): array {
        global $wpdb;
        $out = array();
        $tables = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}vyg\\_%'" );
        foreach ( $tables as $full_table ) {
            $short = substr( $full_table, strlen( $wpdb->prefix ) );
            $columns = $wpdb->get_results( "SHOW COLUMNS FROM {$full_table}", ARRAY_A );
            $user_cols = array();
            $primary_col = 'id';
            foreach ( $columns as $col ) {
                $fname = (string) ( $col['Field'] ?? '' );
                if ( str_contains( strtolower( $fname ), 'user_id' ) ) {
                    $user_cols[] = $fname;
                }
                if ( 'id' === strtolower( $fname ) ) {
                    $primary_col = $fname;
                }
            }
            if ( empty( $user_cols ) ) {
                continue;
            }
            foreach ( $user_cols as $user_col ) {
                $rows = $wpdb->get_results(
                    $wpdb->prepare( "SELECT * FROM {$full_table} WHERE {$user_col} = %d", $user_id ),
                    ARRAY_A
                );
                foreach ( $rows as $row ) {
                    $row['__table']       = $short;
                    $row['__user_col']    = $user_col;
                    $row['__primary_col'] = $primary_col;
                    $out[] = $row;
                }
            }
        }
        return $out;
    }
}