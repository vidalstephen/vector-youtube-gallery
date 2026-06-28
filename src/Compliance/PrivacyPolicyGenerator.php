<?php
/**
 * PrivacyPolicyGenerator — produces suggested privacy-policy text for the operator.
 *
 * Per plan §21 ("Privacy Policy Helper"):
 *   - The plugin stores a local copy of YouTube video metadata (title, description,
 *     thumbnail URL, view counts, etc.) on the operator's WP database.
 *   - It never collects personal data from the operator's site visitors.
 *   - Visitors may submit YouTube IDs that the plugin resolves to public metadata
 *     (this only happens if the operator uses the manual source-add UI).
 *
 * This class returns a fully-formed English text block the operator can paste
 * into their site privacy policy.
 *
 * @package VectorYT\Gallery\Compliance
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Compliance;

defined( 'ABSPATH' ) || exit;

final class PrivacyPolicyGenerator {

    /**
     * @param array<string,mixed> $context Optional overrides: site_name, contact_email, retention_days.
     * @return string HTML-escaped text (caller is responsible for display).
     */
    public function generate( array $context = array() ): string {
        $site_name = (string) ( $context['site_name'] ?? get_bloginfo( 'name' ) );
        $contact   = (string) ( $context['contact_email'] ?? get_option( 'admin_email' ) );
        $retention = (int) ( $context['retention_days'] ?? 90 );

        $sections = array();

        $sections[] = sprintf(
            /* translators: %s: site name */
            __( '%s uses the Vector YouTube Gallery plugin to display YouTube videos and channels on this website.', 'vector-youtube-gallery' ),
            $site_name
        );

        $sections[] = __( 'What data the plugin collects:', 'vector-youtube-gallery' );
        $sections[] = __( 'The plugin stores a local copy of publicly-available YouTube video metadata in this site\'s WordPress database. This includes video titles, descriptions, publication dates, view counts, and thumbnail image URLs. No data is collected from this site\'s visitors.', 'vector-youtube-gallery' );

        $sections[] = __( 'How the data is used:', 'vector-youtube-gallery' );
        $sections[] = __( 'Stored metadata is used solely to render YouTube galleries on this site without making network requests to YouTube on every page load. YouTube embeds shown to visitors are loaded directly from youtube.com.', 'vector-youtube-gallery' );

        $sections[] = __( 'Data retention:', 'vector-youtube-gallery' );
        $sections[] = sprintf(
            /* translators: %d: retention days */
            _n( 'Video metadata is refreshed from YouTube periodically and entries older than %d day are removed from the local database.', 'Video metadata is refreshed from YouTube periodically and entries older than %d days are removed from the local database.', $retention, 'vector-youtube-gallery' ),
            $retention
        );

        $sections[] = __( 'Third-party services:', 'vector-youtube-gallery' );
        $sections[] = __( 'This plugin does not embed tracking pixels or analytics. When a visitor plays an embedded YouTube video, YouTube may collect data as described in Google\'s Privacy Policy (policies.google.com/privacy).', 'vector-youtube-gallery' );

        $sections[] = __( 'Your rights:', 'vector-youtube-gallery' );
        $sections[] = __( 'Site administrators can export or delete all stored data at any time via the YouTube Gallery → Privacy & Compliance admin page.', 'vector-youtube-gallery' );

        $sections[] = sprintf(
            /* translators: %s: contact email */
            __( 'Contact: %s', 'vector-youtube-gallery' ),
            $contact
        );

        return implode( "\n\n", $sections );
    }
}