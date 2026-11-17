<?php
/**
 * Regression suite: Media Audit "Usage Context" references grouped per page.
 *
 * A. Baseline snapshot data (tests/regression/data/reference-grouping-baseline.json, preserved
 *    from the Phase 0 media baseline at commit 17e53e5): the recorded references of
 *    attachments 829–832 (4 detection matches each, all on page #853) and of attachments
 *    with a global reference (#825, #6) are grouped with sba_group_usage_references().
 *    The baseline pages (#853, #852) no longer exist on this site, so stand-ins with the
 *    same IDs and titles are created inside the rolled-back transaction.
 * B. Live scan: content created inside the transaction (one page using one image through
 *    several methods, a second public page, a draft, a private page, a site icon) and the
 *    real media scan AJAX handler.
 * C. Invariants over every scanned attachment.
 *
 * Read-only: everything runs inside a rolled-back transaction.
 *
 * Usage: php test-media-references.php
 *
 * @package Site_Bloat_Auditor\Tests
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require dirname( __DIR__ ) . '/bootstrap.php';

sba_test_login_admin();

/** Rebuild a reference (with detection_method) from a baseline usage_place entry. */
function sba_ref_from_place( array $pl ) {
	$ev = html_entity_decode( (string) $pl['evidence'], ENT_QUOTES );
	if ( 0 === strpos( $ev, 'Gutenberg block in ' ) ) {
		$method = 'gutenberg_block';
	} elseif ( false !== strpos( $ev, 'via wp-image-' ) ) {
		$method = 'content_wp_image_class';
	} elseif ( preg_match( '/ via (.+?) \(/', $ev, $m ) ) {
		$method = $m[1]; // Universal Image Reference Index method string.
	} else {
		$method = 'unknown';
	}
	return [
		'source_id'        => (int) $pl['post_id'],
		'source_title'     => (string) $pl['title'],
		'detection_method' => $method,
		'usage_type'       => (string) $pl['type'],
		'confidence'       => (string) $pl['confidence'],
		'post_status'      => (string) ( $pl['post_status'] ?? 'publish' ),
		'evidence'         => $ev,
	];
}

function sba_method_labels( array $page ) {
	return array_column( $page['methods'], 'label' );
}

/* ================================================== A. Baseline snapshot */
fwrite( STDOUT, "\n-- A. Baseline snapshot: 829–832 / page #853, global references --\n" );
// The recorded references of the Phase 0 baseline (tests/snapshots/baseline/media.json at commit
// 17e53e5), preserved unchanged after the media snapshot became fixture-only.
$baseline = json_decode( (string) file_get_contents( __DIR__ . '/data/reference-grouping-baseline.json' ), true );
$base_by  = [];
foreach ( (array) ( $baseline['items'] ?? [] ) as $it ) {
	$base_by[ (int) $it['id'] ] = $it;
}
SBA_Test_Results::check( 'Preserved baseline data loaded (6 attachments)', 6 === count( $base_by ) );

// Stand-ins for the (since removed) baseline fixture pages, same IDs and titles.
foreach ( [ 853 => '[SBA TEST] Accessibility Page', 852 => '[SBA TEST] Size Page' ] as $pid => $title ) {
	if ( ! get_post( $pid ) ) {
		$new = wp_insert_post( [ 'import_id' => $pid, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => 'stand-in' ] );
		SBA_Test_Results::check( "Stand-in page #$pid created inside the transaction", $pid === (int) $new, "got $new" );
	}
}

foreach ( [ 829, 830, 831, 832 ] as $aid ) {
	$places = (array) ( $base_by[ $aid ]['usage_places'] ?? [] );
	$g      = sba_group_usage_references( array_map( 'sba_ref_from_place', $places ) );
	$page   = $g['pages'][0] ?? [];
	SBA_Test_Results::check( "#$aid: baseline has 4 detection matches", 4 === count( $places ), count( $places ) . ' matches' );
	SBA_Test_Results::check( "#$aid: grouped into exactly 1 unique page", 1 === $g['page_count'] && 1 === count( $g['pages'] ), "page_count={$g['page_count']}" );
	SBA_Test_Results::check( "#$aid: that page is #853 \"[SBA TEST] Accessibility Page\" (Page)", 853 === ( $page['post_id'] ?? 0 ) && '[SBA TEST] Accessibility Page' === ( $page['title'] ?? '' ) && 'Page' === ( $page['post_type_label'] ?? '' ) );
	SBA_Test_Results::check( "#$aid: usage methods = Gutenberg Block, Post Content, General Scan", [ 'Gutenberg Block', 'Post Content', 'General Scan' ] === sba_method_labels( $page ), implode( ', ', sba_method_labels( $page ) ) );
	SBA_Test_Results::check( "#$aid: 4 matches kept on the page, confirmed, frontend URL = permalink", 4 === $page['match_count'] && $page['confirmed'] && get_permalink( 853 ) === $page['view_url'] && 4 === $g['match_count'], $page['view_url'] );
	SBA_Test_Results::check( "#$aid: no other usage locations", 0 === $g['other_count'] );
}

foreach ( [ 825 => 852, 6 => 2 ] as $aid => $pid ) {
	$places = (array) ( $base_by[ $aid ]['usage_places'] ?? [] );
	$g      = sba_group_usage_references( array_map( 'sba_ref_from_place', $places ) );
	SBA_Test_Results::check( "#$aid: " . count( $places ) . " baseline matches → 1 page (#$pid) + 1 other location", 1 === $g['page_count'] && $pid === ( $g['pages'][0]['post_id'] ?? 0 ) && 1 === $g['other_count'], "pages={$g['page_count']} other={$g['other_count']}" );
	$o = $g['other'][0] ?? [];
	SBA_Test_Results::check( "#$aid: the option reference is global, with no page link, and not counted as a page", 'global' === ( $o['scope'] ?? '' ) && ! isset( $o['view_url'] ) && false !== strpos( (string) ( $o['title'] ?? '' ), 'sba_optimized_images' ), wp_json_encode( $o ) );
}

/* ================================================== Unit: statuses, potential, missing */
fwrite( STDOUT, "\n-- Statuses, potential references, missing content --\n" );
$draft   = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'ZZ SBA Draft', 'post_content' => 'x' ] );
$private = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'private', 'post_title' => 'ZZ SBA Private', 'post_content' => 'x' ] );
$trash   = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'ZZ SBA Trash', 'post_content' => 'x' ] );
wp_trash_post( $trash );
$g = sba_group_usage_references( [
	[ 'source_id' => $draft, 'detection_method' => 'gutenberg_block', 'usage_type' => 'Content', 'confidence' => 'high' ],
	[ 'source_id' => $private, 'detection_method' => 'meta_thumbnail_id', 'usage_type' => 'Featured', 'confidence' => 'high' ],
	[ 'source_id' => $trash, 'detection_method' => 'content_wp_image_class', 'usage_type' => 'Content', 'confidence' => 'high' ],
	[ 'source_id' => 853, 'detection_method' => 'content_filename_match', 'usage_type' => 'Content', 'confidence' => 'medium' ],
	[ 'source_id' => 853, 'detection_method' => 'attached_parent', 'usage_type' => 'Attached Parent', 'confidence' => 'medium' ],
	[ 'source_id' => 999999, 'source_title' => 'Gone', 'detection_method' => 'gutenberg_block', 'usage_type' => 'Content', 'confidence' => 'high' ],
	[ 'source_id' => 0, 'source_title' => 'Site Icon', 'detection_method' => 'option_site_icon', 'usage_type' => 'Site Icon', 'confidence' => 'high' ],
	[ 'source_id' => 0, 'source_title' => 'Site Icon', 'detection_method' => 'option_site_icon', 'usage_type' => 'Site Icon', 'confidence' => 'high' ],
] );
$by = array_column( $g['pages'], null, 'post_id' );
foreach ( [ $draft => 'draft', $private => 'private', $trash => 'trash' ] as $pid => $st ) {
	$p = $by[ $pid ] ?? [];
	SBA_Test_Results::check( "$st page: status shown, no public link", $st === ( $p['post_status'] ?? '' ) && '' === $p['view_url'] && false === $p['is_public'] && '' !== $p['status_label'], wp_json_encode( [ $p['post_status'] ?? '', $p['status_label'] ?? '', $p['view_url'] ?? '' ] ) );
}
SBA_Test_Results::check( 'Page found only by medium-confidence methods is "potential", listed once with both methods', isset( $by[853] ) && false === $by[853]['confirmed'] && 2 === count( $by[853]['methods'] ) );
SBA_Test_Results::check( 'Counts: 4 pages = 3 confirmed + 1 potential', 4 === $g['page_count'] && 3 === $g['confirmed_count'] && 1 === $g['potential_count'] );
SBA_Test_Results::check( 'Deleted content and the site icon go to Other Usage Locations (duplicates merged), not pages', 2 === $g['other_count'] && ! isset( $by[999999] ), wp_json_encode( array_column( $g['other'], 'label' ) ) );
SBA_Test_Results::check( 'Missing content is labelled "missing", the site icon "global"', [ 'missing', 'global' ] === array_column( $g['other'], 'scope' ) );
SBA_Test_Results::check( 'Confirmed pages are listed before potential ones', false === end( $g['pages'] )['confirmed'] );

/* ================================================== B. Live scan */
fwrite( STDOUT, "\n-- B. Live media scan with content created in the transaction --\n" );
$att = 0;
foreach ( get_posts( [ 'post_type' => 'attachment', 'post_mime_type' => 'image', 'post_status' => 'inherit', 'numberposts' => 20, 'orderby' => 'ID', 'order' => 'ASC' ] ) as $a ) {
	if ( file_exists( (string) get_attached_file( $a->ID ) ) ) {
		$att = (int) $a->ID;
		break;
	}
}
SBA_Test_Results::check( 'An image attachment with a file exists to test with', $att > 0, "attachment #$att" );
$url   = wp_get_attachment_url( $att );
$block = '<!-- wp:image {"id":' . $att . '} --><figure class="wp-block-image"><img src="' . esc_url( $url ) . '" class="wp-image-' . $att . '"/></figure><!-- /wp:image -->';

$multi = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'ZZ SBA Multi-method Page', 'post_content' => $block ] );
set_post_thumbnail( $multi, $att );
update_post_meta( $multi, '_elementor_data', wp_slash( wp_json_encode( [ [ 'elType' => 'widget', 'settings' => [ 'image' => [ 'id' => $att, 'url' => $url ] ] ] ] ) ) );
$second  = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'ZZ SBA Second Post', 'post_content' => '<img src="' . esc_url( $url ) . '" class="wp-image-' . $att . '"/>' ] );
$ldraft  = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'ZZ SBA Live Draft', 'post_content' => $block ] );
$lpriv   = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'private', 'post_title' => 'ZZ SBA Live Private', 'post_content' => 'x' ] );
set_post_thumbnail( $lpriv, $att );
update_option( 'site_icon', $att );
SBA_Image_Reference_Index::invalidate_cache();

$res = sba_test_ajax( 'sba_scan_media_files' );
if ( empty( $res['json']['success'] ) ) {
	fwrite( STDERR, 'Media scan failed: ' . substr( $res['raw'] . $res['exception'], 0, 500 ) . "\n" );
	exit( 2 );
}
$items = [];
foreach ( (array) $res['json']['data']['all_items'] as $it ) {
	$items[ (int) $it['id'] ] = $it;
}
$item  = $items[ $att ] ?? [];
$g     = $item['usage_groups'] ?? [ 'pages' => [], 'other' => [] ];
$by    = array_column( (array) $g['pages'], null, 'post_id' );
$mp    = $by[ $multi ] ?? [];
$raw_m = count( array_filter( (array) $item['usage_places'], function ( $p ) use ( $multi ) { return (int) $p['post_id'] === $multi; } ) );
fwrite( STDOUT, "  attachment #$att: " . count( (array) $item['usage_places'] ) . " detection matches → {$g['page_count']} pages, {$g['other_count']} other\n" );
foreach ( (array) $g['pages'] as $p ) {
	fwrite( STDOUT, "     #{$p['post_id']} {$p['title']} [{$p['post_type_label']}, {$p['post_status']}] " . ( $p['view_url'] ?: '(no public link)' ) . ' — ' . implode( ', ', sba_method_labels( $p ) ) . "\n" );
}
foreach ( (array) $g['other'] as $o ) {
	fwrite( STDOUT, "     other: {$o['label']} — {$o['title']} ({$o['scope']})\n" );
}

SBA_Test_Results::check( 'Multi-method page appears once although detected ' . $raw_m . ' times', $raw_m > 1 && 1 === count( array_filter( (array) $g['pages'], function ( $p ) use ( $multi ) { return $p['post_id'] === $multi; } ) ) );
$labels = $mp ? sba_method_labels( $mp ) : [];
SBA_Test_Results::check( 'Its methods include Featured Image, Gutenberg Block, Post Content and Elementor (each once)', ! array_diff( [ 'Featured Image', 'Gutenberg Block', 'Post Content', 'Elementor' ], $labels ) && count( $labels ) === count( array_unique( $labels ) ), implode( ', ', $labels ) );
SBA_Test_Results::check( 'Multi-method page: content type Page, View URL = permalink', 'Page' === ( $mp['post_type_label'] ?? '' ) && get_permalink( $multi ) === ( $mp['view_url'] ?? '' ) );
SBA_Test_Results::check( 'Second public content (Post) listed with its own permalink', get_permalink( $second ) === ( $by[ $second ]['view_url'] ?? '' ) && 'Post' === ( $by[ $second ]['post_type_label'] ?? '' ) );
SBA_Test_Results::check( 'Draft listed with status, no public link', 'draft' === ( $by[ $ldraft ]['post_status'] ?? '' ) && '' === $by[ $ldraft ]['view_url'] );
SBA_Test_Results::check( 'Private page listed with status, no public link', 'private' === ( $by[ $lpriv ]['post_status'] ?? '' ) && '' === $by[ $lpriv ]['view_url'] );
SBA_Test_Results::check( 'Site icon is an Other Usage Location, not a page', in_array( 'Site Icon', array_column( (array) $g['other'], 'label' ), true ) );
$uniq = array_unique( array_filter( array_map( 'intval', array_column( (array) $item['usage_places'], 'post_id' ) ) ) );
SBA_Test_Results::check( 'page_count = number of distinct existing posts in the raw matches', count( $uniq ) === $g['page_count'], count( $uniq ) . " vs {$g['page_count']}" );
SBA_Test_Results::check( 'Raw usage_places unchanged in shape (still one entry per match)', count( (array) $item['usage_places'] ) === $g['match_count'] && $g['match_count'] > $g['page_count'] + $g['other_count'] );

/* ================================================== C. Invariants */
fwrite( STDOUT, "\n-- C. Invariants over all " . count( $items ) . " scanned attachments --\n" );
$bad = [];
$multi_real = 0;
foreach ( $items as $id => $it ) {
	$places = (array) ( $it['usage_places'] ?? [] );
	$gg     = $it['usage_groups'] ?? null;
	if ( ! $places ) {
		if ( $gg && ( $gg['page_count'] || $gg['other_count'] ) ) {
			$bad[] = "#$id groups without matches";
		}
		continue;
	}
	if ( ! $gg ) {
		$bad[] = "#$id no usage_groups";
		continue;
	}
	$ids = array_column( $gg['pages'], 'post_id' );
	if ( count( $ids ) !== count( array_unique( $ids ) ) ) {
		$bad[] = "#$id page listed twice";
	}
	if ( $gg['page_count'] !== $gg['confirmed_count'] + $gg['potential_count'] || $gg['match_count'] !== count( $places ) ) {
		$bad[] = "#$id counts";
	}
	foreach ( $gg['pages'] as $p ) {
		$post = get_post( $p['post_id'] );
		if ( '' !== $p['view_url'] && ( 'publish' !== $post->post_status || ! is_post_type_viewable( $post->post_type ) || get_permalink( $post ) !== $p['view_url'] ) ) {
			$bad[] = "#$id bad view_url for #{$p['post_id']}";
		}
		if ( '' !== $p['view_url'] && false !== strpos( $p['view_url'], '/wp-admin/' ) ) {
			$bad[] = "#$id view_url is an admin URL";
		}
		if ( ! $p['methods'] ) {
			$bad[] = "#$id page without methods";
		}
	}
	if ( $gg['page_count'] > 1 && 'ZZ' !== substr( (string) $gg['pages'][0]['title'], 0, 2 ) ) {
		$multi_real++;
	}
}
SBA_Test_Results::check( 'Every attachment: no page twice, counts consistent, View URL only for published viewable content (frontend permalink)', ! $bad, implode( '; ', array_slice( $bad, 0, 10 ) ) );
SBA_Test_Results::check( 'Real multi-page attachments present and grouped', $multi_real > 0, "$multi_real attachments used on more than one page" );

exit( SBA_Test_Results::summary( 'Media references grouping' ) );
