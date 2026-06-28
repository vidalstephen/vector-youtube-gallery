<?php
/**
 * YouTube Data API v3 — client contract.
 *
 * Implementations:
 *   - ApiKeyClient (live, uses stored API key)
 *   - MockApiClient (dev/test, returns fixtures from tests/fixtures/)
 *
 * @package VectorYT\Gallery\YouTube
 */

declare(strict_types=1);

namespace VectorYT\Gallery\YouTube;

defined( 'ABSPATH' ) || exit;

interface ApiClientInterface {

    /**
     * channels.list — resolve one or more channels by ID, forUsername, or forHandle.
     *
     * @param array<string,string|int> $params Query params (id|forHandle|forUsername, part, etc.)
     * @return array<string,mixed> Decoded API response (items[], pageInfo, etc.)
     * @throws ApiException On transport or API errors.
     */
    public function channels_list( array $params ): array;

    /**
     * playlists.list — resolve playlists by ID.
     *
     * @return array<string,mixed>
     * @throws ApiException
     */
    public function playlists_list( array $params ): array;

    /**
     * playlistItems.list — paginated; pass pageToken for continuation.
     * Max 50 results per page per YouTube API docs.
     *
     * @return array<string,mixed>
     * @throws ApiException
     */
    public function playlist_items_list( array $params ): array;

    /**
     * videos.list — full metadata for one or more video IDs.
     * Pass comma-separated IDs (max 50 per request).
     *
     * @return array<string,mixed>
     * @throws ApiException
     */
    public function videos_list( array $params ): array;

    /**
     * Revoke an OAuth token. Used by DisconnectManager.
     * API key mode: no-op (returns true).
     *
     * @return bool True on success.
     * @throws ApiException
     */
    public function revoke_token( string $token ): bool;

    /**
     * Identifier for logging/diagnostics: "live" or "mock" or "api_key" / "oauth".
     * Used to confirm which implementation is wired in dev.
     */
    public function mode(): string;
}