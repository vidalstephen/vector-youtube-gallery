<?php
/**
 * Phase 13.1 capture preflight — verifies each of the seeded feeds renders
 * the redesigned grid template with at least 6 cards, the correct density
 * class, and (where configured) the section header and trust strip.
 *
 * Exits 0 on success, non-zero on any failure. Output is plain
 * "feed_uuid cards=N header=yes/no trust=yes/no density=X" lines, one per
 * feed, for easy tee+grep consumption by the wrapper script.
 *
 * Run with:  wp eval-file /var/www/html/wp-content/plugins/vector-youtube-gallery/dev/preflight-phase13-1.php --path=/var/www/html --allow-root
 *
 * @package VectorYT\Gallery
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$feeds = array(
    'phase-13-1-comfortable',
    'phase-13-1-compact',
    'phase-13-1-editorial',
    'phase-13-1-with-header',
);

foreach ( $feeds as $feed_uuid ) {
    $row = ( new \VectorYT\Gallery\Repository\FeedRepository() )->find_by_uuid( $feed_uuid );
    if ( ! $row ) {
        fwrite( STDERR, "missing-feed " . $feed_uuid . PHP_EOL );
        exit( 2 );
    }
    $config = \VectorYT\Gallery\Repository\FeedRepository::decode_config( $row );
    $html   = do_shortcode( '[youtube_feed feed_uuid="' . $feed_uuid . '"]' );
    $cards  = substr_count( $html, 'vyg-card__body' );
    $header = strpos( $html, 'vyg-grid__header' ) !== false ? 'yes' : 'no';
    $trust  = strpos( $html, 'vyg-grid__trust-strip' ) !== false ? 'yes' : 'no';
    $density = $config['display']['density'] ?? '?';
    echo $feed_uuid . ' cards=' . $cards . ' header=' . $header . ' trust=' . $trust . ' density=' . $density . PHP_EOL;
    if ( $cards < 6 ) {
        fwrite( STDERR, 'FAIL: too few cards for ' . $feed_uuid . PHP_EOL );
        exit( 3 );
    }
}

exit( 0 );
