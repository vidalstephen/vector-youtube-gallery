<?php
/**
 * Quota tracker — append-only entries in vyg_api_quota_log (replaces Phase 1 option-based log).
 *
 * Per YouTube docs:
 *   - channels.list: 1 unit
 *   - playlists.list: 1 unit
 *   - playlistItems.list: 1 unit
 *   - videos.list: 1 unit
 *   - search.list: 100 units (we don't use it in Phase 1/2)
 *   - All requests cost at least 1 unit, even invalid ones.
 *
 * @package VectorYT\Gallery\YouTube
 */

declare(strict_types=1);

namespace VectorYT\Gallery\YouTube;

use VectorYT\Gallery\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class QuotaTracker {

    private const QUOTA_COST = array(
        'channels'      => 1,
        'playlists'     => 1,
        'playlistItems' => 1,
        'videos'        => 1,
        'search'        => 100,
        'thumbnails'    => 0,
        'captions'      => 400,
    );

    public function record( string $endpoint, ?int $response_code = null, ?int $source_id = null ): int {
        global $wpdb;
        $cost = self::QUOTA_COST[ $endpoint ] ?? 1;
        $wpdb->insert(
            Schema::table( 'vyg_api_quota_log' ),
            array(
                'source_id'     => $source_id,
                'method'        => $endpoint,
                'quota_units'   => $cost,
                'request_hash'  => substr( md5( $endpoint . '|' . microtime( true ) ), 0, 32 ),
                'response_code' => $response_code,
                'created_at'    => gmdate( 'Y-m-d H:i:s' ),
            ),
            array( '%d', '%s', '%d', '%s', '%d', '%s' )
        );
        return (int) $wpdb->insert_id;
    }

    public function last_24h_units(): int {
        global $wpdb;
        $since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
        $sum = $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(quota_units), 0) FROM {$this->table()} WHERE created_at >= %s",
            $since
        ) );
        return (int) $sum;
    }

    public function remaining_estimate( int $daily_cap = 10000 ): int {
        return max( 0, $daily_cap - $this->last_24h_units() );
    }

    public function table(): string {
        return Schema::table( 'vyg_api_quota_log' );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function entries( int $limit = 20 ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table()} ORDER BY id DESC LIMIT %d",
            $limit
        ), ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    public function reset(): void {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$this->table()}" );
    }
}