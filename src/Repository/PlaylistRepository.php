<?php
/**
 * Playlist repository — CRUD over vyg_playlists + vyg_playlist_video_map.
 *
 * @package VectorYT\Gallery\Repository
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Repository;

use VectorYT\Gallery\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class PlaylistRepository {

    public function table(): string {
        return Schema::table( 'vyg_playlists' );
    }

    public function map_table(): string {
        return Schema::table( 'vyg_playlist_video_map' );
    }

    /**
     * Upsert a playlist from an API resource.
     *
     * @param array<string,mixed> $api_resource
     * @return int Internal playlist id.
     */
    public function upsert_from_api( array $api_resource ): int {
        global $wpdb;

        $youtube_id = (string) $api_resource['id'];
        $existing   = $this->find_by_youtube_id( $youtube_id );

        $row = array(
            'youtube_playlist_id' => $youtube_id,
            'youtube_channel_id'  => (string) ( $api_resource['snippet']['channelId'] ?? '' ),
            'title'               => sanitize_text_field( (string) ( $api_resource['snippet']['title'] ?? '' ) ),
            'description_excerpt' => sanitize_text_field( wp_trim_words( (string) ( $api_resource['snippet']['description'] ?? '' ), 50 ) ),
            'thumbnail_url'       => esc_url_raw( (string) ( $api_resource['snippet']['thumbnails']['default']['url'] ?? '' ) ),
            'item_count'          => isset( $api_resource['contentDetails']['itemCount'] ) ? (int) $api_resource['contentDetails']['itemCount'] : null,
            'privacy_status'      => sanitize_key( (string) ( $api_resource['status']['privacyStatus'] ?? '' ) ),
            'last_sync_at'        => gmdate( 'Y-m-d H:i:s' ),
            'updated_at'          => gmdate( 'Y-m-d H:i:s' ),
        );

        if ( null === $existing ) {
            $row['created_at'] = gmdate( 'Y-m-d H:i:s' );
            $wpdb->insert( $this->table(), $row, $this->format( $row ) );
            return (int) $wpdb->insert_id;
        }

        $id = (int) $existing['id'];
        $wpdb->update( $this->table(), $row, array( 'id' => $id ), $this->format( $row ), array( '%d' ) );
        return $id;
    }

    /**
     * Add or update a video→playlist mapping.
     */
    public function map_video( int $playlist_id, int $video_id, string $youtube_playlist_id, string $youtube_video_id, ?int $position = null, ?string $playlist_item_id = null, ?string $added_at = null ): void {
        global $wpdb;
        $table = $this->map_table();
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE playlist_id = %d AND video_id = %d",
            $playlist_id,
            $video_id
        ) );

        $row = array(
            'playlist_id'         => $playlist_id,
            'video_id'            => $video_id,
            'youtube_playlist_id' => $youtube_playlist_id,
            'youtube_video_id'    => $youtube_video_id,
            'position'            => $position,
            'playlist_item_id'    => $playlist_item_id,
            'added_at'            => $added_at,
            'is_removed'          => 0,
            'updated_at'          => gmdate( 'Y-m-d H:i:s' ),
        );

        if ( $existing ) {
            $wpdb->update( $table, $row, array( 'id' => (int) $existing->id ), $this->format( $row ), array( '%d' ) );
        } else {
            $wpdb->insert( $table, $row, $this->format( $row ) );
        }
    }

    /**
     * Mark a video as no longer in this playlist (set is_removed=1).
     */
    public function unmap_video( int $playlist_id, int $video_id ): void {
        global $wpdb;
        $wpdb->update(
            $this->map_table(),
            array( 'is_removed' => 1, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ),
            array( 'playlist_id' => $playlist_id, 'video_id' => $video_id ),
            array( '%d', '%s' ),
            array( '%d', '%d' )
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find_by_youtube_id( string $youtube_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE youtube_playlist_id = %s LIMIT 1",
            $youtube_id
        ), ARRAY_A );
        return is_array( $row ) ? $row : null;
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
     * @param array<string,mixed> $row
     * @return array<int,string>
     */
    private function format( array $row ): array {
        $int_cols  = array( 'item_count' );
        $out = array();
        foreach ( array_keys( $row ) as $col ) {
            $out[] = in_array( $col, $int_cols, true ) ? '%d' : '%s';
        }
        return $out;
    }
}