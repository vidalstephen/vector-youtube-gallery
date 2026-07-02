<?php
/**
 * Feed header partial — shared by all 8 layout templates (Phase 14.9).
 *
 * Variables in scope (set by the calling template's extract()):
 *   $attrs       — shortcode/block attribute array (or array_merge of
 *                  saved display + inline + layout defaults). Contains
 *                  the show_* booleans and the text slots:
 *                    feed_kicker | header_kicker
 *                    feed_title  | header_title
 *                    feed_intro  | header_subtitle
 *                    feed_cta_label | header_cta_label
 *                    feed_cta_url   | header_cta_url
 *                    show_kicker, show_h1, show_intro, show_pill,
 *                    show_channel_cta
 *                    see_all_url, see_all_label (Phase 14.4 link)
 *   $source      — array{id, source_uuid, source_type, ...} or null
 *   $layout_slug — string (grid|list|featured|hero|shorts|live|masonry|carousel)
 *
 * Output: emits the 5-slot header block at the top of a layout. Each
 * slot is independent — the caller controls visibility via the show_*
 * booleans AND the text-driven check (empty text → element omitted).
 *
 * Defaults (booleans are checked in the calling template so the
 * wrapper element can also be omitted when the layout decides to
 * render nothing; this partial always emits a <header> when invoked):
 *   show_kicker       true  (text-driven: only emits when text set)
 *   show_h1           true  (auto-fills with layout default when empty)
 *   show_intro        false (text-driven: only emits when text set)
 *   show_pill         true  (always emits the layout name)
 *   show_channel_cta  false (URL-driven: only emits when URL is non-empty
 *                            and not '#')
 *
 * H1 fallback: when feed_title (or its legacy alias header_title) is
 * empty, the partial emits a layout-specific default matching the
 * prototype's per-layout copy (grid/list → "Latest Videos", featured
 * → "Featured Videos", etc.). This keeps the header useful even when
 * the operator passes no title.
 *
 * Channel CTA URL precedence:
 *   1. feed_cta_url (or header_cta_url alias) — explicit operator override
 *   2. source's canonical URL synthesized from source_type + youtube_*_id
 *   3. '#' as the final fallback
 *
 * "View all videos →" link (Phase 14.4): the inner "see all" link is
 * NOT emitted by this partial — that lives inside the layout's own
 * section head (currently featured + hero above the rest grid). This
 * partial is the *top* shared header. Layouts that need both compose
 * this partial at the top and their own inner section head below.
 *
 * @package VectorYT\Gallery\Render
 */

defined( 'ABSPATH' ) || exit;

// --- Resolve text slots (text_driven: empty string when not set) ----
$feed_kicker    = (string) ( $attrs['feed_kicker'] ?? $attrs['header_kicker'] ?? '' );
$feed_title_raw = (string) ( $attrs['feed_title']  ?? $attrs['header_title']  ?? '' );
$feed_intro     = (string) ( $attrs['feed_intro']  ?? $attrs['header_subtitle'] ?? $attrs['header_intro'] ?? '' );
$feed_cta_label = (string) ( $attrs['feed_cta_label'] ?? $attrs['header_cta_label'] ?? '' );
$feed_cta_url   = (string) ( $attrs['feed_cta_url']   ?? $attrs['header_cta_url']   ?? '' );

// --- Visibility flags (per-slot) -------------------------------------
$show_kicker      = ! isset( $attrs['show_kicker'] )      || ! empty( $attrs['show_kicker'] );
$show_h1          = ! isset( $attrs['show_h1'] )          || ! empty( $attrs['show_h1'] );
// show_intro is text-driven: the intro paragraph renders whenever
// the text is non-empty AND show_intro isn't explicitly set to false.
// When show_intro is unset/true, the text wins; only an explicit
// show_intro=false suppresses it.
$show_intro       = ( isset( $attrs['show_intro'] ) && false === $attrs['show_intro'] ) ? false : true;
$show_pill        = ! isset( $attrs['show_pill'] )        || ! empty( $attrs['show_pill'] );
// Back-compat: when an operator set the legacy `header_cta_url` /
// `header_cta_label` keys (Phase 13.1/14.x grid header), they
// expected the CTA to render unconditionally. Treat an explicit
// `show_channel_cta` flag as the override; otherwise auto-enable
// when a CTA URL is set and not '#'. This keeps existing grid
// feeds rendering their CTA without forcing every operator to add
// `show_channel_cta="1"` to their saved config.
$has_explicit_show_cta = isset( $attrs['show_channel_cta'] );
$show_channel_cta = $has_explicit_show_cta
    ? ! empty( $attrs['show_channel_cta'] )
    : ( '' !== $feed_cta_url && '#' !== $feed_cta_url );

// --- Layout-specific default h1 (prototype's per-layout copy) -------
$layout_defaults = array(
    'grid'     => __( 'Latest Videos', 'vector-youtube-gallery' ),
    'list'     => __( 'Latest Videos', 'vector-youtube-gallery' ),
    'featured' => __( 'Featured Videos', 'vector-youtube-gallery' ),
    'hero'     => __( 'Editorial Video Showcase', 'vector-youtube-gallery' ),
    'shorts'   => __( 'Latest Shorts', 'vector-youtube-gallery' ),
    'live'     => __( 'Live & Upcoming', 'vector-youtube-gallery' ),
    'masonry'  => __( 'Masonry Gallery', 'vector-youtube-gallery' ),
    'carousel' => __( 'Featured Video Carousel', 'vector-youtube-gallery' ),
);
$default_h1 = $layout_defaults[ $layout_slug ] ?? __( 'Latest Videos', 'vector-youtube-gallery' );
$h1_text    = ( '' !== $feed_title_raw ) ? $feed_title_raw : $default_h1;

// --- Channel CTA URL fallback (mirror 14.4 source-canonical synthesis)
if ( '' === $feed_cta_url && is_array( $source ) ) {
    $source_type = (string) ( $source['source_type'] ?? '' );
    if ( 'channel' === $source_type && ! empty( $source['youtube_channel_id'] ) ) {
        $feed_cta_url = 'https://www.youtube.com/channel/' . rawurlencode( (string) $source['youtube_channel_id'] );
    } elseif ( 'playlist' === $source_type && ! empty( $source['youtube_playlist_id'] ) ) {
        $feed_cta_url = 'https://www.youtube.com/playlist?list=' . rawurlencode( (string) $source['youtube_playlist_id'] );
    } elseif ( 'video' === $source_type && ! empty( $source['youtube_video_id'] ) ) {
        $feed_cta_url = 'https://www.youtube.com/watch?v=' . rawurlencode( (string) $source['youtube_video_id'] );
    }
}
if ( '' === $feed_cta_url ) {
    $feed_cta_url = '#';
}
$feed_cta_label = ( '' !== $feed_cta_label ) ? $feed_cta_label : __( 'Watch on YouTube →', 'vector-youtube-gallery' );

// --- Pill text = layout slug, first letter uppercased ---------------
$pill_text = ucfirst( (string) $layout_slug );

// --- "View all" link (Phase 14.4) — pulled in only on featured/hero
//     when the layout itself emits it. By default this partial does
//     not include the inner see-all link; layouts that want it (the
//     14.4 callers) include it in their own inner section head and
//     the partial does NOT duplicate it here. This keeps the contract
//     simple: shared header on top, per-layout inner heads (with the
//     14.4 "view all" link when applicable) below.
?>
<header class="vyg-section-head" role="banner">
    <?php if ( $show_kicker && '' !== $feed_kicker ) : ?>
        <span class="vyg-section-head__kicker"><?php echo esc_html( $feed_kicker ); ?></span>
    <?php endif; ?>

    <div class="vyg-section-head__row">
        <?php if ( $show_h1 ) : ?>
            <h2 class="vyg-section-head__title"><?php echo esc_html( $h1_text ); ?></h2>
        <?php endif; ?>

        <div class="vyg-section-head__actions">
            <?php if ( $show_pill ) : ?>
                <span class="vyg-section-head__pill"><?php echo esc_html( $pill_text ); ?></span>
            <?php endif; ?>

            <?php if ( $show_channel_cta && '#' !== $feed_cta_url ) : ?>
                <a class="vyg-section-head__cta" href="<?php echo esc_url( $feed_cta_url ); ?>" target="_blank" rel="noopener noreferrer">
                    <?php echo esc_html( $feed_cta_label ); ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ( $show_intro && '' !== $feed_intro ) : ?>
        <p class="vyg-section-head__intro"><?php echo esc_html( $feed_intro ); ?></p>
    <?php endif; ?>
</header>
