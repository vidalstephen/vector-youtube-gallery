<?php
/**
 * Grid layout template — Phase 13.1 redesign + Phase B3 migration.
 *
 * Per-card anatomy (media, status, duration, body, title, channel,
 * metadata, description, footer, CTA, actions) is owned by the shared
 * CardRenderer — this template delegates each video to
 * $card_renderer->render(). The grid template owns the structural
 * wrapper only: the feed root classes, the optional section header
 * (title / subtitle / column indicator / CTA), the trust strip, and
 * the load-more pagination button.
 *
 * Variables (extracted from $context by TemplateLoader):
 *   $source        — array{id, source_uuid, source_type, title, ...}
 *   $videos        — array<int, array> of normalized video rows
 *   $attrs         — shortcode/block attributes (layout, density, ...)
 *   $renderer      — VideoRenderer instance (legacy; unused by B3, kept
 *                    for sub-partials that still reference it)
 *   $card_renderer — shared CardRenderer instance (Phase B2)
 *   $card_settings — resolved 43-key card settings (Phase B2)
 *   $feed_config   — saved feed config (used for legacy/CTA mapping)
 *   $feed_uuid     — feed uuid string
 *
 * The template is fully self-contained: every section is gated on an
 * explicit attribute so the operator can dial the look up or down
 * per feed.
 *
 * @var array  $source
 * @var array  $videos
 * @var array  $attrs
 * @var \VectorYT\Gallery\Render\VideoRenderer $renderer
 * @var \VectorYT\Gallery\Render\CardRenderer  $card_renderer
 * @var array  $card_settings
 * @var array  $feed_config
 * @var string $feed_uuid
 */

defined( 'ABSPATH' ) || exit;

// --- Local short-hand -------------------------------------------------------
$columns           = isset( $attrs['columns'] ) ? max( 1, min( 6, (int) $attrs['columns'] ) ) : 3;
$wrapper_id        = isset( $attrs['wrapper_id'] ) ? (string) $attrs['wrapper_id'] : '';
$density           = isset( $attrs['density'] ) ? (string) $attrs['density'] : 'comfortable';
$public_safe       = ! empty( $attrs['public_safe'] );
$has_header        = ! empty( $attrs['header_title'] );
$has_trust_strip   = ! empty( $attrs['trust_strip'] );

$root_attrs = \VectorYT\Gallery\Render\TemplateAttributes::to_html(
    \VectorYT\Gallery\Render\TemplateAttributes::feed_root( $attrs, $source, $public_safe )
);
$root_classes = sprintf(
    'vyg-feed vyg-feed--grid vyg-grid vyg-grid--cols-%1$d vyg-grid--density-%2$s',
    $columns,
    sanitize_key( $density )
);

if ( empty( $videos ) ) {
    echo '<div class="' . esc_attr( $root_classes ) . ' vyg-feed--empty" ';
    if ( '' !== $wrapper_id ) {
        echo 'id="' . esc_attr( $wrapper_id ) . '" ';
    }
    echo $root_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — TemplateAttributes::to_html escapes each attribute.
    echo '>';
    echo '<p>' . esc_html__( 'No videos yet for this source.', 'vector-youtube-gallery' ) . '</p>';
    echo '</div>';
    return;
}
?>

<div class="<?php echo esc_attr( $root_classes ); ?>"
    <?php if ( '' !== $wrapper_id ) : ?>id="<?php echo esc_attr( $wrapper_id ); ?>"<?php endif; ?>
    <?php echo $root_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — TemplateAttributes::to_html escapes each attribute. ?>>

    <?php if ( $has_header ) : ?>
        <?php
        $header_title        = (string) ( $attrs['header_title'] ?? '' );
        $header_subtitle     = (string) ( $attrs['header_subtitle'] ?? '' );
        $header_cta_label    = (string) ( $attrs['header_cta_label'] ?? '' );
        $header_cta_url      = (string) ( $attrs['header_cta_url'] ?? '' );
        $header_cols_visible = ! isset( $attrs['header_columns_visible'] ) || ! empty( $attrs['header_columns_visible'] );
        $has_cta             = ( '' !== $header_cta_label && '' !== $header_cta_url );
        ?>
        <header class="vyg-grid__header" role="banner">
            <div class="vyg-grid__header-text">
                <span class="vyg-grid__header-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="22" height="22" focusable="false">
                        <path fill="#ff0000" d="M23 12s0-3.6-.5-5.3a2.7 2.7 0 0 0-1.9-1.9C18.9 4.3 12 4.3 12 4.3s-6.9 0-8.6.5A2.7 2.7 0 0 0 1.5 6.7C1 8.4 1 12 1 12s0 3.6.5 5.3a2.7 2.7 0 0 0 1.9 1.9c1.7.5 8.6.5 8.6.5s6.9 0 8.6-.5a2.7 2.7 0 0 0 1.9-1.9C23 15.6 23 12 23 12zM9.8 15.4V8.6L15.5 12l-5.7 3.4z"/>
                    </svg>
                </span>
                <h2 class="vyg-grid__header-title"><?php echo esc_html( $header_title ); ?></h2>
            </div>

            <div class="vyg-grid__header-controls">
                <?php if ( $header_cols_visible ) : ?>
                    <span class="vyg-grid__header-cols" aria-label="<?php echo esc_attr__( 'Column count', 'vector-youtube-gallery' ); ?>">
                        <?php
                        /* translators: %d: number of columns. */
                        echo esc_html( sprintf( _n( '%d column', '%d columns', $columns, 'vector-youtube-gallery' ), $columns ) );
                        ?>
                    </span>
                <?php endif; ?>

                <?php if ( $has_cta ) : ?>
                    <a class="vyg-grid__header-cta" href="<?php echo esc_url( $header_cta_url ); ?>" target="_blank" rel="noopener noreferrer">
                        <?php echo esc_html( $header_cta_label ); ?>
                        <svg class="vyg-icon" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false">
                            <path fill="currentColor" d="M14 3v2h3.6L9.3 13.3l1.4 1.4L19 6.4V10h2V3h-7zm5 16H5V5h7V3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7h-2v7z"/>
                        </svg>
                    </a>
                <?php endif; ?>
            </div>

            <?php if ( '' !== $header_subtitle ) : ?>
                <p class="vyg-grid__header-subtitle"><?php echo esc_html( $header_subtitle ); ?></p>
            <?php endif; ?>
        </header>
    <?php endif; ?>

    <div class="vyg-grid__cards" role="list">
        <?php foreach ( $videos as $video ) : ?>
            <?php
            // Phase B3 — every per-card anatomy (media, status, duration,
            // body, title, channel, metadata, description, footer, CTA,
            // actions) is owned by the shared CardRenderer. Grid owns
            // the structural wrapper only. The card-internals loop body
            // is exactly one renderer call.
            echo $card_renderer->render(
                $video,
                $card_settings,
                array(
                    'source'      => $source,
                    'feed_config' => isset( $feed_config ) && is_array( $feed_config ) ? $feed_config : array(),
                    'feed_uuid'   => isset( $feed_uuid ) ? (string) $feed_uuid : '',
                    'mode'        => 'standard',
                    'role'        => 'listitem',
                )
            );
            ?>
        <?php endforeach; ?>
    </div>

    <?php if ( $has_trust_strip ) : ?>
        <ul class="vyg-grid__trust-strip" aria-label="<?php esc_attr_e( 'Trust badges', 'vector-youtube-gallery' ); ?>">
            <li class="vyg-grid__trust-item">
                <span class="vyg-grid__trust-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="20" height="20" focusable="false"><path fill="currentColor" d="M12 4a8 8 0 1 0 8 8 8 8 0 0 0-8-8zm0 14a6 6 0 1 1 6-6 6 6 0 0 1-6 6zm1-7.6V6h-2v6l5.2 3.1 1-1.7z"/></svg>
                </span>
                <span class="vyg-grid__trust-text"><?php esc_html_e( 'Lazy Loaded', 'vector-youtube-gallery' ); ?></span>
            </li>
            <li class="vyg-grid__trust-item">
                <span class="vyg-grid__trust-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="20" height="20" focusable="false"><path fill="currentColor" d="M12 1 3 5v6c0 5.6 3.8 10.7 9 12 5.2-1.3 9-6.4 9-12V5l-9-4zm0 10.99h7c-.5 4.5-3.5 8.6-7 9.93V12H5V6.3l7-3.11v8.8z"/></svg>
                </span>
                <span class="vyg-grid__trust-text"><?php esc_html_e( 'Privacy Safe', 'vector-youtube-gallery' ); ?></span>
            </li>
            <li class="vyg-grid__trust-item">
                <span class="vyg-grid__trust-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="20" height="20" focusable="false"><path fill="currentColor" d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm0 18a8 8 0 1 1 8-8 8 8 0 0 1-8 8zm-1-13h2v6h-2zm0 8h2v2h-2z"/></svg>
                </span>
                <span class="vyg-grid__trust-text"><?php esc_html_e( 'Accessible', 'vector-youtube-gallery' ); ?></span>
            </li>
            <li class="vyg-grid__trust-item">
                <span class="vyg-grid__trust-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="20" height="20" focusable="false"><path fill="currentColor" d="M3 5h18v2H3zm0 6h18v2H3zm0 6h12v2H3z"/></svg>
                </span>
                <span class="vyg-grid__trust-text"><?php esc_html_e( 'Builder Ready', 'vector-youtube-gallery' ); ?></span>
            </li>
        </ul>
    <?php endif; ?>
</div>

<?php
// Pagination: render load-more button if requested.
if ( isset( $attrs['pagination'] ) && 'load_more' === $attrs['pagination'] ) {
    $next_offset = (int) ( $attrs['offset'] ?? 0 ) + count( $videos );
    $remaining   = isset( $attrs['total'] ) ? max( 0, (int) $attrs['total'] - $next_offset ) : 0;
    if ( $remaining > 0 ) {
        $load_more_attrs = \VectorYT\Gallery\Render\TemplateAttributes::to_html(
            \VectorYT\Gallery\Render\TemplateAttributes::load_more( $attrs, $source, $next_offset, $public_safe )
        );
        echo '<div class="vyg-feed__loadmore-wrap">';
        echo '<button type="button" class="vyg-feed__loadmore button" ' . $load_more_attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        esc_html_e( 'Load more videos', 'vector-youtube-gallery' );
        echo ' <svg class="vyg-icon" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false"><path fill="currentColor" d="M7 10l5 5 5-5z"/></svg>';
        echo '</button>';
        echo '<span class="vyg-feed__remaining"> (' . esc_html( (string) $remaining ) . ')</span>';
        echo '</div>';
    }
}
