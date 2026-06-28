<?php
/**
 * API quota tracker — append-only log of YouTube API calls + estimated quota cost.
 *
 * Phase 1: logs to an option array (transient-style). Phase 2 will move this
 * to a dedicated `vyg_api_quota_log` table per plan §5.
 *
 * Quota costs per YouTube docs:
 *   - channels.list: 1 unit
 *   - playlists.list: 1 unit
 *   - playlistItems.list: 1 unit
 *   - videos.list: 1 unit
 *   - search.list: 100 units (we don't use it in Phase 1)
 *   - All requests cost at least 1 unit, even invalid ones.
 *
 * @package VectorYT\Gallery\YouTube
 */

declare(strict_types=1);

namespace VectorYT\Gallery\YouTube;

defined( 'ABSPATH' ) || exit;

final class QuotaTracker {

    private const OPTION_KEY = 'vyg_api_quota_log';
    private const MAX_ENTRIES = 1000;

    /**
     * Default quota cost per endpoint. Phase 1: everything is 1 unit.
     * Phase 2 may override for special endpoints.
     */
    private const QUOTA_COST = array(
        'channels'      => 1,
        'playlists'     => 1,
        'playlistItems' => 1,
        'videos'        => 1,
        'search'        => 100,
        'thumbnails'    => 0,  // via videos.list response
        'captions'      => 400,
    );

    public function record( string $endpoint, ?int $response_code = null ): void {
        $entries = $this->entries();
        $entries[] = array(
            'endpoint'      => $endpoint,
            'quota_units'   => self::QUOTA_COST[ $endpoint ] ?? 1,
            'response_code' => $response_code,
            'at'            => gmdate( 'c' ),
        );
        // Trim from the front to keep within MAX_ENTRIES.
        if ( count( $entries ) > self::MAX_ENTRIES ) {
            $entries = array_slice( $entries, -self::MAX_ENTRIES );
        }
        update_option( self::OPTION_KEY, $entries, false );
    }

    /**
     * Total quota units consumed in the last 24 hours.
     */
    public function last_24h_units(): int {
        $cutoff  = gmdate( 'c', time() - DAY_IN_SECONDS );
        $entries = $this->entries();
        $total   = 0;
        foreach ( $entries as $e ) {
            if ( (string) $e['at'] >= $cutoff ) {
                $total += (int) ( $e['quota_units'] ?? 0 );
            }
        }
        return $total;
    }

    /**
     * Approximate quota remaining against the default daily cap (10,000 units).
     * Capped at 0.
     */
    public function remaining_estimate( int $daily_cap = 10000 ): int {
        return max( 0, $daily_cap - $this->last_24h_units() );
    }

    /**
     * @return array<int,array{endpoint:string,quota_units:int,response_code:?int,at:string}>
     */
    public function entries(): array {
        $val = get_option( self::OPTION_KEY, array() );
        return is_array( $val ) ? $val : array();
    }

    public function reset(): void {
        delete_option( self::OPTION_KEY );
    }
}