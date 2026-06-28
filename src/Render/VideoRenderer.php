<?php
/**
 * VideoRenderer — helper used inside templates for embed URLs, watch URLs,
 * thumbnail selection, and duration formatting.
 *
 * Pure utility class — no API calls. The class methods are exposed to all
 * layout templates via $ctx['renderer'].
 *
 * @package VectorYT\Gallery\Render
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Render;

defined( 'ABSPATH' ) || exit;

final class VideoRenderer {

    /**
     * Build the official YouTube embed URL with sensible defaults.
     *
     * @param array<string,mixed> $video
     * @param array<string,mixed> $args Optional overrides: autoplay, rel, modestbranding, start.
     * @return string
     */
    public function embed_url( array $video, array $args = array() ): string {
        $id = (string) ( $video['youtube_video_id'] ?? '' );
        if ( '' === $id ) {
            return '';
        }
        $defaults = array(
            'autoplay'      => '1',
            'rel'           => '0',
            'modestbranding'=> '1',
        );
        $args = array_merge( $defaults, $args );
        return add_query_arg( $args, 'https://www.youtube.com/embed/' . rawurlencode( $id ) );
    }

    /**
     * Build the canonical YouTube watch URL.
     */
    public function watch_url( array $video ): string {
        $id = (string) ( $video['youtube_video_id'] ?? '' );
        if ( '' === $id ) {
            return '';
        }
        return 'https://www.youtube.com/watch?v=' . rawurlencode( $id );
    }

    /**
     * Choose the best available thumbnail for a given preference.
     *
     * @param array<string,mixed> $video
     * @param string $preferred One of: maxres, standard, high, medium, default.
     * @return string URL or ''.
     */
    public function best_thumbnail( array $video, string $preferred = 'medium' ): string {
        $order = array( 'maxres', 'standard', 'high', 'medium', 'default' );
        // Move $preferred to the front if valid.
        if ( in_array( $preferred, $order, true ) ) {
            $order = array_merge( array( $preferred ), array_values( array_diff( $order, array( $preferred ) ) ) );
        }
        foreach ( $order as $key ) {
            $col = 'thumbnail_' . $key;
            $url = (string) ( $video[ $col ] ?? '' );
            if ( '' !== $url ) {
                return $url;
            }
        }
        return '';
    }

    /**
     * Format ISO seconds as M:SS or H:MM:SS.
     */
    public function format_duration( int $seconds ): string {
        if ( $seconds <= 0 ) {
            return '';
        }
        $h = (int) floor( $seconds / 3600 );
        $m = (int) floor( ( $seconds % 3600 ) / 60 );
        $s = $seconds % 60;
        if ( $h > 0 ) {
            return sprintf( '%d:%02d:%02d', $h, $m, $s );
        }
        return sprintf( '%d:%02d', $m, $s );
    }
}