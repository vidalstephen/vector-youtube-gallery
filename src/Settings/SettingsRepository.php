<?php
/**
 * Plugin settings repository — non-secret, configurable behavior.
 *
 * Settings are stored in a single autoload=yes option (`vyg_settings`) as
 * an associative array. Each key has a default value defined here.
 *
 * @package VectorYT\Gallery\Settings
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Settings;

defined( 'ABSPATH' ) || exit;

class SettingsRepository {

    private const OPTION_KEY = 'vyg_settings';

    /**
     * Default values. Every key here is considered "registered" — saving with
     * unknown keys strips them.
     */
    public const DEFAULTS = array(
        // Classification thresholds.
        'shorts_max_duration_seconds'   => 60,           // YouTube policy: <=60s vertical = Short
        'short_candidate_max_duration'  => 180,          // >60s but <=3min: ambiguous; needs tag check

        // Live state polling (Phase 5 wires the LiveStatusPollJob; Phase 3 just stores the interval).
        'live_poll_interval_seconds'    => 300,          // 5 min default for active live
        'live_upcoming_poll_seconds'    => 900,          // 15 min default for upcoming
        'live_recently_ended_seconds'   => 900,          // 15 min default for recently ended

        // Phase 5 — previous-streams retention (per source).
        'live_previous_streams_retention' => 50,          // keep last 50 ended streams per source
        'live_replay_retention_days'    => 14,          // drop ended streams from DB after 14 days

        // Retention windows (per YouTube API Services Developer Policies §9).
        'data_refresh_interval_days'    => 30,
        'data_ttl_days'                 => 90,
        'data_hard_delete_after_days'   => 365,

        // Behavior toggles.
        'auto_classify_shorts'          => true,
        'auto_classify_live'            => true,
        'respect_manual_overrides'      => true,

        // Sync defaults (per-source overrides take precedence).
        'default_sync_interval_seconds' => 86400,        // 1 day
        'metadata_refresh_batch_size'   => 100,
    );

    /** @var array<string,mixed>|null Cached values. */
    private ?array $cache = null;

    /**
     * @return array<string,mixed>
     */
    public function all(): array {
        if ( null === $this->cache ) {
            $stored  = get_option( self::OPTION_KEY, array() );
            $stored  = is_array( $stored ) ? $stored : array();
            // Merge stored values over defaults (so newly-introduced keys default sensibly).
            $this->cache = array_merge( self::DEFAULTS, $stored );
        }
        return $this->cache;
    }

    public function get( string $key, $default = null ) {
        $values = $this->all();
        return array_key_exists( $key, $values ) ? $values[ $key ] : $default;
    }

    public function set( string $key, $value ): void {
        $values = $this->all();
        $values[ $key ] = $value;
        $this->save( $values );
    }

    /**
     * Bulk-save from POST. Filters to known keys; coerces known types.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed> The values that were actually persisted.
     */
    public function save_posted( array $input ): array {
        $values = $this->all();

        // Integers (intval handles "60" -> 60 and rejects junk).
        foreach ( array(
            'shorts_max_duration_seconds',
            'short_candidate_max_duration',
            'live_poll_interval_seconds',
            'live_upcoming_poll_seconds',
            'live_recently_ended_seconds',
            'data_refresh_interval_days',
            'data_ttl_days',
            'data_hard_delete_after_days',
            'default_sync_interval_seconds',
            'metadata_refresh_batch_size',
        ) as $int_key ) {
            if ( array_key_exists( $int_key, $input ) ) {
                $values[ $int_key ] = max( 0, (int) $input[ $int_key ] );
            }
        }

        // Booleans (checked checkboxes: '1' when on, absent when off).
        foreach ( array(
            'auto_classify_shorts',
            'auto_classify_live',
            'respect_manual_overrides',
        ) as $bool_key ) {
            $values[ $bool_key ] = ! empty( $input[ $bool_key ] );
        }

        $this->save( $values );
        return $values;
    }

    /**
     * Persist to the option table. Drops unknown keys (defense in depth).
     *
     * @param array<string,mixed> $values
     */
    private function save( array $values ): void {
        $sanitized = array_intersect_key( $values, self::DEFAULTS );
        update_option( self::OPTION_KEY, $sanitized, true ); // autoload=yes
        $this->cache = $sanitized;
    }

    public function reset_defaults(): void {
        delete_option( self::OPTION_KEY );
        $this->cache = null;
    }
}