<?php
/**
 * Availability classifier — determines whether a YouTube video is viewable
 * in the gallery.
 *
 * Output values (content_type-agnostic):
 *   - 'available'       Public, embeddable.
 *   - 'private'         Privacy status = private. Cannot be displayed.
 *   - 'embed_disabled'  Public but the owner disabled embedding.
 *   - 'deleted'         uploadStatus = deleted.
 *   - 'restricted'      Region/age restriction detected. Display with notice.
 *
 * @package VectorYT\Gallery\Normalize
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Normalize;

defined( 'ABSPATH' ) || exit;

final class AvailabilityClassifier {

    public const STATE_AVAILABLE      = 'available';
    public const STATE_PRIVATE        = 'private';
    public const STATE_EMBED_DISABLED = 'embed_disabled';
    public const STATE_DELETED        = 'deleted';
    public const STATE_RESTRICTED     = 'restricted';
    public const STATE_UNKNOWN        = 'unknown';

    /**
     * @param array<string,mixed> $api_resource videos.list item.
     */
    public function classify( array $api_resource ): string {
        $status = (array) ( $api_resource['status'] ?? array() );
        $content = (array) ( $api_resource['contentDetails'] ?? array() );

        // Deleted upload (uploader removed it).
        if ( isset( $status['uploadStatus'] ) && 'deleted' === $status['uploadStatus'] ) {
            return self::STATE_DELETED;
        }

        // Private (owner-only).
        $privacy = (string) ( $status['privacyStatus'] ?? '' );
        if ( 'private' === $privacy || 'unlisted' === $privacy ) {
            // unlisted is technically playable with a direct link — Phase 3 treats
            // it as available unless operator marked otherwise.
            if ( 'private' === $privacy ) {
                return self::STATE_PRIVATE;
            }
        }

        // Embed disabled.
        if ( isset( $status['embeddable'] ) && false === $status['embeddable'] ) {
            return self::STATE_EMBED_DISABLED;
        }

        // Region restriction (allowed/blocked lists in contentDetails).
        if ( isset( $content['regionRestriction'] ) && is_array( $content['regionRestriction'] ) ) {
            $rr = $content['regionRestriction'];
            // If there's a 'blocked' list and it's non-empty, the video is restricted everywhere
            // represented. We can't tell from one call if *this* server is blocked — Phase 3
            // leaves the operator a manual override.
            if ( ! empty( $rr['blocked'] ) && is_array( $rr['blocked'] ) ) {
                return self::STATE_RESTRICTED;
            }
        }

        // Public + embeddable + not deleted → available.
        return self::STATE_AVAILABLE;
    }
}