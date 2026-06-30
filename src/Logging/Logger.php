<?php
/**
 * File-based logger.
 *
 * Phase 0 stub: writes single-line entries to wp-content/debug.log
 * (the same file WP_DEBUG_LOG uses, configured in docker-compose.yml).
 *
 * Production hardening (later phases):
 *   - Log rotation
 *   - Sensitive data redaction (API keys, OAuth tokens, custom_user data)
 *   - Levels configurable via admin setting
 *   - Async shipping to a centralized log store
 *
 * @package VectorYT\Gallery
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Logging;

defined( 'ABSPATH' ) || exit;

final class Logger {

    public const LEVEL_DEBUG   = 'debug';
    public const LEVEL_INFO    = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR   = 'error';

    /**
     * Numeric severity for each level. Used by the level filter to
     * decide whether a given entry should be persisted.
     */
    public const LEVEL_PRIORITY = array(
        self::LEVEL_DEBUG   => 10,
        self::LEVEL_INFO    => 20,
        self::LEVEL_WARNING => 30,
        self::LEVEL_ERROR   => 40,
    );

    /** Optional level filter. When non-null, entries with a lower
     * priority are dropped before they are written or shipped. The
     * Plugin's container wires this in from `log_level` setting. */
    private ?string $min_level = null;

    /** Optional list of log sinks called after a successful write.
     * Each sink is a callable accepting the structured entry array.
     * Tests + Phase 12.5 ship hook use this for centralized shipping. */
    /** @var array<int,\Closure(array<string,mixed>):void> */
    private array $sinks = array();

    public function set_min_level(?string $level): void
    {
        if (null === $level || '' === $level) {
            $this->min_level = null;
            return;
        }
        if (isset(self::LEVEL_PRIORITY[$level])) {
            $this->min_level = $level;
        }
    }

    public function min_level(): ?string
    {
        return $this->min_level;
    }

    public function add_sink(\Closure $sink): void
    {
        $this->sinks[] = $sink;
    }

    public function is_enabled(string $level): bool
    {
        if (null === $this->min_level) {
            return true;
        }
        $min = self::LEVEL_PRIORITY[$this->min_level] ?? 0;
        $cur = self::LEVEL_PRIORITY[$level] ?? 0;
        return $cur >= $min;
    }

    /**
     * Keys whose values are always redacted from the context array.
     * Match these against any nested key (case-insensitive, partial-match).
     */
    private const REDACT_KEYS = array(
        'api_key',
        'apikey',
        'authorization',
        'access_token',
        'refresh_token',
        'oauth_token',
        'secret',
        'password',
        'pwd',
        'token',
    );

    /**
     * @param string               $level   One of self::LEVEL_*.
     * @param string               $message Human-readable message (no secrets).
     * @param array<string, mixed> $context Optional structured context.
     */
    public function log( string $level, string $message, array $context = array() ): void {
        // Phase 12.5: drop entries below the configured min level.
        if ( ! $this->is_enabled( $level ) ) {
            return;
        }
        $entry = array(
            'ts'      => gmdate( 'c' ),
            'level'   => $level,
            'plugin'  => 'vyg',
            'message' => $message,
            'context' => $this->redact( $context ),
        );

        $line = wp_json_encode( $entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( $line === false ) {
            $line = sprintf( '[vyg] %s %s', $level, $message );
        }

        // WP's default handler writes to wp-content/debug.log when WP_DEBUG_LOG is true.
        // In docker-compose we bind-mount dev/logs/ to that path.
        error_log( $line );

        // Phase 12.5: dispatch to any registered sinks. Sinks see
        // the structured $entry (post-redaction) so a centralized
        // shipping hook never sees raw secrets.
        foreach ( $this->sinks as $sink ) {
            try {
                $sink( $entry );
            } catch ( \Throwable $e ) {
                // A misbehaving sink must never break logging.
                error_log( sprintf( '[vyg][logging] sink error: %s', $e->getMessage() ) );
            }
        }
    }

    public function info( string $message, array $context = array() ): void {
        $this->log( self::LEVEL_INFO, $message, $context );
    }

    public function warning( string $message, array $context = array() ): void {
        $this->log( self::LEVEL_WARNING, $message, $context );
    }

    public function error( string $message, array $context = array() ): void {
        $this->log( self::LEVEL_ERROR, $message, $context );
    }

    /**
     * Walk an array and replace any value whose key matches a redact pattern with '***'.
     */
    private function redact( array $context ): array {
        foreach ( $context as $key => $value ) {
            if ( is_string( $key ) && $this->is_sensitive_key( $key ) ) {
                $context[ $key ] = '***';
                continue;
            }
            if ( is_array( $value ) ) {
                $context[ $key ] = $this->redact( $value );
            }
        }
        return $context;
    }

    private function is_sensitive_key( string $key ): bool {
        $needle = strtolower( $key );
        foreach ( self::REDACT_KEYS as $pattern ) {
            if ( str_contains( $needle, $pattern ) ) {
                return true;
            }
        }
        return false;
    }
}