<?php
/**
 * YouTube API client exception.
 *
 * Standardized error shape so callers (resolvers, sync jobs) can decide:
 *   - hard-stop retry (auth/quota/forbidden)
 *   - soft retry (transient)
 *   - mark unavailable (deleted/private/embed-disabled)
 *
 * @package VectorYT\Gallery\YouTube
 */

declare(strict_types=1);

namespace VectorYT\Gallery\YouTube;

defined( 'ABSPATH' ) || exit;

final class ApiException extends \RuntimeException {

    public const KIND_AUTH        = 'auth';           // 401/403 invalid key, revoked token
    public const KIND_QUOTA       = 'quota';          // 403 quotaExceeded
    public const KIND_FORBIDDEN   = 'forbidden';      // 403 forbidden
    public const KIND_NOT_FOUND   = 'not_found';      // 404
    public const KIND_RATE_LIMIT  = 'rate_limit';     // 429
    public const KIND_TRANSIENT   = 'transient';      // 5xx, network
    public const KIND_BAD_REQUEST = 'bad_request';    // 400 invalid params
    public const KIND_UNKNOWN     = 'unknown';

    public function __construct(
        string $message,
        private string $kind = self::KIND_UNKNOWN,
        private ?int $http_status = null,
        private ?string $api_error_code = null,
        private ?array $api_error_response = null,
    ) {
        parent::__construct( $message, $http_status ?? 0 );
    }

    public function kind(): string {
        return $this->kind;
    }

    public function http_status(): ?int {
        return $this->http_status;
    }

    public function api_error_code(): ?string {
        return $this->api_error_code;
    }

    public function api_error_response(): ?array {
        return $this->api_error_response;
    }

    public function is_hard_stop(): bool {
        return in_array( $this->kind, array(
            self::KIND_AUTH,
            self::KIND_QUOTA,
            self::KIND_FORBIDDEN,
            self::KIND_NOT_FOUND,
            self::KIND_BAD_REQUEST,
        ), true );
    }

    /**
     * Classify a YouTube API error response (decoded JSON) into a KIND_*.
     *
     * @param array<string,mixed>|null $body
     */
    public static function classify_youtube_error( int $http_status, ?array $body ): string {
        // Quota errors come back as 403 with reason "quotaExceeded".
        if ( 403 === $http_status && is_array( $body ) ) {
            $err = self::extract_youtube_error( $body );
            if ( null !== $err && 'quotaExceeded' === $err['reason'] ) {
                return self::KIND_QUOTA;
            }
        }
        if ( 401 === $http_status ) {
            return self::KIND_AUTH;
        }
        if ( 403 === $http_status ) {
            return self::KIND_FORBIDDEN;
        }
        if ( 404 === $http_status ) {
            return self::KIND_NOT_FOUND;
        }
        if ( 429 === $http_status ) {
            return self::KIND_RATE_LIMIT;
        }
        if ( $http_status >= 500 ) {
            return self::KIND_TRANSIENT;
        }
        if ( $http_status >= 400 ) {
            return self::KIND_BAD_REQUEST;
        }
        return self::KIND_UNKNOWN;
    }

    /**
     * @param array<string,mixed> $body
     * @return array{reason:string,message:string}|null
     */
    private static function extract_youtube_error( array $body ): ?array {
        if ( ! isset( $body['error'] ) || ! is_array( $body['error'] ) ) {
            return null;
        }
        $err = $body['error'];
        $reason  = isset( $err['errors'][0]['reason'] ) && is_string( $err['errors'][0]['reason'] )
            ? $err['errors'][0]['reason']
            : ( is_string( $err['reason'] ?? null ) ? $err['reason'] : '' );
        $message = is_string( $err['message'] ?? null ) ? $err['message'] : '';
        if ( '' === $reason ) {
            return null;
        }
        return array( 'reason' => $reason, 'message' => $message );
    }
}