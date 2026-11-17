<?php
/**
 * Create the isolated media-audit fixture scenario on the LOCAL test site.
 *
 * - All files go to uploads/sba-test-fixtures/ (never into real year/month folders).
 * - Every created post/attachment gets post meta _sba_test_fixture = 1.
 * - A manifest (uploads/sba-test-fixtures/manifest.json) records every ID and file.
 * - Files are generated locally (GD images, minimal PDFs, placeholders): no external URLs.
 *
 * Usage:
 *   php create-fixtures.php            Create the whole scenario (refuses if a manifest exists).
 *   php create-fixtures.php --extend   Keep every existing fixture untouched; create only the
 *                                      fixtures that are missing (new scenarios, or fixtures
 *                                      deleted outside the test tools) and re-link only the
 *                                      fixture posts that reference a re-created fixture.
 *   php create-fixtures.php --plan     Print what would be created; write nothing.
 *
 * @package Site_Bloat_Auditor\Tests
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

define( 'SBA_TEST_ROLLBACK', false ); // Fixtures must persist.
define( 'SBA_ALLOW_TEST_FIXTURE_MEDIA_ACTIONS', true ); // Fixture lifecycle script (see SBA_Media_Safety).
require dirname( __DIR__ ) . '/bootstrap.php';
require __DIR__ . '/fixture-definitions.php';

$uploads  = wp_upload_dir( null, false );
$base_dir = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . SBA_FIXTURE_DIR );
$manifest = $base_dir . '/manifest.json';
$extend   = in_array( '--extend', $argv, true );

// --plan: print everything that would be created, then exit before any write.
if ( in_array( '--plan', $argv, true ) ) {
	$atts  = sba_fixture_attachments();
	$posts = sba_fixture_posts();
	fwrite( STDOUT, "PLAN ONLY — nothing is written.\nTarget directory: $base_dir (exists: " . ( is_dir( $base_dir ) ? 'YES — full creation would abort; use --extend' : 'no' ) . ")\n\n" );
	fwrite( STDOUT, 'ATTACHMENTS (' . count( $atts ) . ") — wp_posts type=attachment, title \"" . SBA_FIXTURE_TITLE . "<key>\", meta " . SBA_FIXTURE_META_KEY . "=1, _wp_attached_file, _wp_attachment_metadata, _wp_attachment_image_alt (images)\n" );
	foreach ( $atts as $k => $a ) {
		$how = ! empty( $a['copy_of'] ) ? "byte copy of {$atts[$a['copy_of']]['file']}" : ( isset( $a['raw'] ) ? $a['raw'] : sprintf( '%dx%d %s', $a['spec'][0], $a['spec'][1], $a['spec'][2] ?? 'plain' ) );
		$extra = [];
		if ( ! empty( $a['parent'] ) ) { $extra[] = "post_parent={$a['parent']}"; }
		if ( ! empty( $a['optimize'] ) ) { $extra[] = 'RUN PLUGIN OPTIMIZER (.sba-orig + .webp + sba_optimized_images record)'; }
		if ( ! empty( $a['remove_file'] ) ) { $extra[] = 'original file removed after metadata (thumbnails kept)'; }
		if ( ! empty( $a['setup'] ) ) { $extra[] = "setting {$a['setup']} applied only inside the test transaction"; }
		if ( array_key_exists( 'alt', $a ) ) { $extra[] = 'alt=' . var_export( $a['alt'], true ); }
		fwrite( STDOUT, sprintf( "  %-22s %-26s %-28s expect %-7s %s\n", $k, $a['file'], $how, $a['expect']['usage'], implode( '; ', $extra ) ) );
	}
	fwrite( STDOUT, "\nPOSTS (" . count( $posts ) . ") — meta " . SBA_FIXTURE_META_KEY . "=1\n" );
	foreach ( $posts as $k => $p ) {
		$extra = [];
		if ( is_array( $p['content'] ) && count( $p['content'] ) > 1 ) { $extra[] = 'content updated once (creates revision with old reference)'; }
		if ( ! empty( $p['thumbnail'] ) ) { $extra[] = "_thumbnail_id={$p['thumbnail']}"; }
		if ( ! empty( $p['meta'] ) ) { $extra[] = 'meta: ' . implode( ', ', array_keys( $p['meta'] ) ); }
		if ( ! empty( $p['elementor'] ) ) { $extra[] = '_elementor_edit_mode, _elementor_data, _elementor_element_cache + 1 revision post with _elementor_data'; }
		fwrite( STDOUT, sprintf( "  %-17s %-8s %-8s /%s/ \"%s%s\" %s\n", $k, $p['post_type'], $p['post_status'], $p['post_name'], SBA_FIXTURE_TITLE, $p['title'], $extra ? '— ' . implode( '; ', $extra ) : '' ) );
	}
	fwrite( STDOUT, "\nOTHER WRITES\n  - manifest.json + README.txt in $base_dir\n  - generated thumbnails, -scaled copies, .sba-orig backup and .webp companion: all inside $base_dir\n  - one NEW key (fixture ID) added to option sba_optimized_images by the plugin optimizer; existing keys untouched\n  - WordPress core: default category term relationships + term count for fixture posts; revisions; trash meta on the trashed fixture\n" );
	exit( 0 );
}

$existing = null;
if ( file_exists( $manifest ) ) {
	if ( ! $extend ) {
		fwrite( STDERR, "Fixtures already exist ($manifest). Use --extend to add missing fixtures, or run cleanup-fixtures.php --confirm first.\n" );
		exit( 1 );
	}
	$existing = json_decode( (string) file_get_contents( $manifest ), true );
	if ( ! is_array( $existing ) ) {
		fwrite( STDERR, "Manifest is not valid JSON; refusing to extend.\n" );
		exit( 1 );
	}
} elseif ( $extend ) {
	fwrite( STDOUT, "No manifest yet: --extend creates the whole scenario.\n" );
}

// Publishing posts would otherwise schedule a core "do_pings" cron event (external pings).
remove_action( 'publish_post', '_publish_post_hook', 5 );

$admin_id = sba_test_login_admin(); // Needed so post content keeps style attributes (unfiltered_html).
if ( ! current_user_can( 'unfiltered_html' ) ) {
	fwrite( STDERR, "Admin user lacks unfiltered_html; fixture content would be altered.\n" );
	exit( 1 );
}
if ( ! wp_mkdir_p( $base_dir ) ) {
	fwrite( STDERR, "Cannot create $base_dir\n" );
	exit( 1 );
}
@file_put_contents( $base_dir . '/README.txt', "Site Bloat Auditor TEST FIXTURES ONLY.\nCreated by tests/fixtures/create-fixtures.php. Remove with cleanup-fixtures.php --confirm.\n" );

/* ------------------------------------------------------------ file writers */

function sba_fx_make_image( $path, $w, $h, $style, $quality, $seed ) {
	$im = imagecreatetruecolor( $w, $h );
	mt_srand( crc32( $seed ) );
	if ( 'noise' === $style ) {
		for ( $y = 0; $y < $h; $y++ ) {
			for ( $x = 0; $x < $w; $x++ ) {
				imagesetpixel( $im, $x, $y, mt_rand( 0, 0xFFFFFF ) );
			}
		}
	} else {
		$bg = imagecolorallocate( $im, mt_rand( 40, 220 ), mt_rand( 40, 220 ), mt_rand( 40, 220 ) );
		imagefilledrectangle( $im, 0, 0, $w, $h, $bg );
		for ( $i = 0; $i < 12; $i++ ) {
			$c = imagecolorallocate( $im, mt_rand( 0, 255 ), mt_rand( 0, 255 ), mt_rand( 0, 255 ) );
			imagefilledellipse( $im, mt_rand( 0, $w ), mt_rand( 0, $h ), mt_rand( 20, (int) ( $w / 3 ) ), mt_rand( 20, (int) ( $h / 3 ) ), $c );
		}
	}
	$white = imagecolorallocate( $im, 255, 255, 255 );
	imagestring( $im, 5, 10, 10, 'SBA TEST ' . $seed, $white );

	$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	if ( 'png' === $ext ) {
		$ok = imagepng( $im, $path );
	} elseif ( 'webp' === $ext ) {
		$ok = imagewebp( $im, $path, 80 );
	} else {
		$ok = imagejpeg( $im, $path, $quality );
	}
	imagedestroy( $im );
	return $ok;
}

function sba_fx_make_pdf( $path, $label ) {
	$text    = 'SBA TEST ' . $label;
	$stream  = "BT /F1 18 Tf 72 720 Td ($text) Tj ET";
	$objects = [
		'<< /Type /Catalog /Pages 2 0 R >>',
		'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
		'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
		'<< /Length ' . strlen( $stream ) . " >>\nstream\n$stream\nendstream",
		'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
	];
	$pdf     = "%PDF-1.4\n";
	$offsets = [];
	foreach ( $objects as $i => $obj ) {
		$offsets[] = strlen( $pdf );
		$pdf      .= ( $i + 1 ) . " 0 obj\n$obj\nendobj\n";
	}
	$xref = strlen( $pdf );
	$pdf .= "xref\n0 " . ( count( $objects ) + 1 ) . "\n0000000000 65535 f \n";
	foreach ( $offsets as $o ) {
		$pdf .= sprintf( "%010d 00000 n \n", $o );
	}
	$pdf .= 'trailer << /Size ' . ( count( $objects ) + 1 ) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
	return false !== file_put_contents( $path, $pdf );
}

/** A manifest entry still points at a tagged fixture post that exists. */
function sba_fx_alive( $id ) {
	return (int) $id > 0 && get_post( (int) $id ) && '1' === (string) get_post_meta( (int) $id, SBA_FIXTURE_META_KEY, true );
}

/* ------------------------------------------------------------ placeholders */

function sba_fx_resolve( $text, array $ids, array $post_ids = [] ) {
	return preg_replace_callback( '/\{(id|url|file|post):([a-z0-9_-]+)(?::([a-z0-9_-]+))?\}/i', function ( $m ) use ( $ids, $post_ids ) {
		if ( 'post' === $m[1] ) {
			if ( empty( $post_ids[ $m[2] ] ) ) {
				throw new RuntimeException( "Unknown fixture post key {$m[2]}" );
			}
			return (string) $post_ids[ $m[2] ];
		}
		$aid = $ids[ $m[2] ] ?? 0;
		if ( ! $aid ) {
			throw new RuntimeException( "Unknown fixture key {$m[2]}" );
		}
		if ( 'id' === $m[1] ) {
			return (string) $aid;
		}
		if ( 'file' === $m[1] ) {
			return wp_basename( get_attached_file( $aid ) );
		}
		if ( ! empty( $m[3] ) ) {
			$src = wp_get_attachment_image_src( $aid, $m[3] );
			return $src ? $src[0] : wp_get_attachment_url( $aid );
		}
		return wp_get_attachment_url( $aid );
	}, $text );
}

/** Resolve placeholders in a meta value (string or nested array). */
function sba_fx_resolve_value( $value, array $ids, array $post_ids ) {
	if ( is_array( $value ) ) {
		foreach ( $value as $k => $v ) {
			$value[ $k ] = sba_fx_resolve_value( $v, $ids, $post_ids );
		}
		return $value;
	}
	return is_string( $value ) ? sba_fx_resolve( $value, $ids, $post_ids ) : $value;
}

/** Attachment keys and post keys a post definition depends on. */
function sba_fx_post_dependencies( array $p ) {
	$blob = wp_json_encode( [ $p['content'] ?? '', $p['meta'] ?? [], $p['elementor'] ?? [] ] );
	preg_match_all( '/\{(id|url|file):([a-z0-9-]+)/i', $blob, $a );
	preg_match_all( '/\{post:([a-z0-9_]+)\}/i', $blob, $b );
	$atts = $a[2];
	if ( ! empty( $p['thumbnail'] ) ) {
		$atts[] = $p['thumbnail'];
	}
	return [ array_unique( $atts ), array_unique( $b[1] ) ];
}

/* ------------------------------------------------------------ attachments */

$defs     = sba_fixture_attachments();
$ids      = [];
$created  = [];
$result   = $existing ?: [ 'attachments' => [], 'posts' => [], 'revisions' => [], 'optimized' => [], 'notes' => [] ];
$repaired = [];

foreach ( $defs as $key => $def ) {
	$old_id = (int) ( $existing['attachments'][ $key ]['id'] ?? 0 );
	if ( $old_id && sba_fx_alive( $old_id ) ) {
		$ids[ $key ] = $old_id; // Preserved: never modified.
		continue;
	}
	if ( $old_id ) {
		$repaired[] = $key;
		fwrite( STDOUT, "  attachment $key #$old_id is gone (deleted outside the test tools) — re-creating\n" );
	}

	$path = $base_dir . '/' . $def['file'];
	if ( file_exists( $path ) ) {
		fwrite( STDERR, "Refusing to overwrite existing file $path (not referenced by a live fixture). Remove it with cleanup-fixtures.php first.\n" );
		exit( 1 );
	}
	if ( ! empty( $def['copy_of'] ) ) {
		$src = get_attached_file( $ids[ $def['copy_of'] ] ?? 0 );
		$ok  = $src && file_exists( $src ) && copy( $src, $path );
	} elseif ( ( $def['raw'] ?? '' ) === 'pdf' ) {
		$ok = sba_fx_make_pdf( $path, $key );
	} elseif ( ( $def['raw'] ?? '' ) === 'placeholder' ) {
		$ok = false !== file_put_contents( $path, 'SBA TEST PLACEHOLDER MEDIA ' . $key . "\n" );
	} else {
		[ $w, $h ] = $def['spec'];
		$ok        = sba_fx_make_image( $path, $w, $h, $def['spec'][2] ?? 'plain', $def['quality'] ?? 85, $key );
	}
	if ( ! $ok ) {
		fwrite( STDERR, "Failed to write $path\n" );
		exit( 1 );
	}

	$type   = wp_check_filetype( $def['file'] );
	$att_id = wp_insert_attachment( [
		'post_mime_type' => $type['type'],
		'post_title'     => SBA_FIXTURE_TITLE . $key,
		// Same slug a real upload gets (derived from the filename), e.g. "gold-loan", "img_0001".
		'post_name'      => sanitize_title( pathinfo( $def['file'], PATHINFO_FILENAME ) ),
		'post_status'    => 'inherit',
		'post_author'    => $admin_id,
	], false, 0, true );
	if ( is_wp_error( $att_id ) ) {
		fwrite( STDERR, "wp_insert_attachment failed for $key: " . $att_id->get_error_message() . "\n" );
		exit( 1 );
	}
	update_post_meta( $att_id, SBA_FIXTURE_META_KEY, 1 );
	// Store the uploads-relative path exactly like a real upload does. Passing an absolute
	// path is unreliable on Windows (wp_upload_dir() basedir uses mixed separators).
	update_post_meta( $att_id, '_wp_attached_file', SBA_FIXTURE_DIR . '/' . $def['file'] );
	$native = get_attached_file( $att_id ); // basedir-prefixed, same format WordPress uses.
	if ( ! $native || ! file_exists( $native ) ) {
		fwrite( STDERR, "Attached file for $key does not resolve on disk ($native).\n" );
		exit( 1 );
	}

	$meta = wp_generate_attachment_metadata( $att_id, $native );
	wp_update_attachment_metadata( $att_id, $meta );

	if ( array_key_exists( 'alt', $def ) ) {
		if ( null !== $def['alt'] ) {
			update_post_meta( $att_id, '_wp_attachment_image_alt', $def['alt'] );
		}
	} elseif ( 0 === strpos( (string) $type['type'], 'image/' ) ) {
		update_post_meta( $att_id, '_wp_attachment_image_alt', 'SBA test image ' . str_replace( '-', ' ', $key ) );
	}

	$ids[ $key ]                    = $att_id;
	$created[]                     = $key;
	$result['attachments'][ $key ] = [ 'id' => $att_id, 'file' => get_post_meta( $att_id, '_wp_attached_file', true ) ];
	if ( 0 !== strpos( (string) $result['attachments'][ $key ]['file'], SBA_FIXTURE_DIR . '/' ) ) {
		fwrite( STDERR, "Unexpected _wp_attached_file for $key: {$result['attachments'][ $key ]['file']}\n" );
		exit( 1 );
	}
	fwrite( STDOUT, sprintf( "  attachment %-22s #%d  %s\n", $key, $att_id, $result['attachments'][ $key ]['file'] ) );
}

/* ------------------------------------------------------------ posts */

/** Apply content (with revision scenario), thumbnail, meta and Elementor data to a fixture post. */
function sba_fx_apply_post( $post_id, array $p, array $ids, array $post_ids, $admin_id, $is_new, array &$result ) {
	$contents = (array) $p['content'];
	if ( $is_new && count( $contents ) > 1 ) {
		// Replacement scenario: snapshot the initial content as a revision, then update it,
		// so the old reference lives only in revision history (as on a real edited page).
		wp_save_post_revision( $post_id );
	}
	if ( ! $is_new || count( $contents ) > 1 ) {
		wp_update_post( wp_slash( [ 'ID' => $post_id, 'post_content' => sba_fx_resolve( end( $contents ), $ids, $post_ids ) ] ) );
	}
	if ( ! empty( $p['thumbnail'] ) ) {
		set_post_thumbnail( $post_id, $ids[ $p['thumbnail'] ] );
	}
	foreach ( (array) ( $p['meta'] ?? [] ) as $mk => $mv ) {
		update_post_meta( $post_id, $mk, wp_slash( sba_fx_resolve_value( $mv, $ids, $post_ids ) ) );
	}
	if ( ! empty( $p['elementor'] ) ) {
		$el = $p['elementor'];
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_data', wp_slash( sba_fx_resolve( $el['data'], $ids, $post_ids ) ) );
		update_post_meta( $post_id, '_elementor_element_cache', wp_slash( sba_fx_resolve( $el['stale_cache'], $ids, $post_ids ) ) );
		if ( $is_new ) {
			// An Elementor revision that still holds the replaced image.
			$rev_id = wp_insert_post( [
				'post_type'   => 'revision',
				'post_status' => 'inherit',
				'post_parent' => $post_id,
				'post_name'   => $post_id . '-revision-v1',
				'post_title'  => get_the_title( $post_id ),
				'post_author' => $admin_id,
			], true );
			if ( ! is_wp_error( $rev_id ) ) {
				update_metadata( 'post', $rev_id, '_elementor_data', wp_slash( sba_fx_resolve( $el['revision_data'], $ids, $post_ids ) ) );
				update_metadata( 'post', $rev_id, SBA_FIXTURE_META_KEY, 1 );
				$result['revisions'][] = $rev_id;
			}
		}
	}
}

$post_ids       = [];
$recreated_post = [];
foreach ( sba_fixture_posts() as $pkey => $p ) {
	$old_id = (int) ( $existing['posts'][ $pkey ]['id'] ?? 0 );
	if ( $old_id && sba_fx_alive( $old_id ) ) {
		$post_ids[ $pkey ] = $old_id;
		// A fixture post trashed (or un-trashed) outside the test tools no longer matches its
		// scenario: restore only its status. Content and meta are left as they are.
		$now = get_post_status( $old_id );
		if ( $now !== $p['post_status'] ) {
			if ( 'trash' === $p['post_status'] ) {
				wp_trash_post( $old_id );
			} else {
				if ( 'trash' === $now ) {
					wp_untrash_post( $old_id );
				}
				wp_update_post( [ 'ID' => $old_id, 'post_status' => $p['post_status'] ] );
			}
			$result['restored_status'][] = [ 'post' => $pkey, 'id' => $old_id, 'from' => $now, 'to' => get_post_status( $old_id ), 'at' => gmdate( 'c' ) ];
			fwrite( STDOUT, "  restored   post $pkey #$old_id status $now -> " . get_post_status( $old_id ) . " (changed outside the test tools)\n" );
		}
		continue;
	}
	if ( $old_id ) {
		fwrite( STDOUT, "  post $pkey #$old_id is gone — re-creating\n" );
	}
	$contents = (array) $p['content'];
	$status   = 'trash' === $p['post_status'] ? 'publish' : $p['post_status'];
	$post_id  = wp_insert_post( wp_slash( [
		'post_type'    => $p['post_type'],
		'post_status'  => $status,
		'post_name'    => $p['post_name'],
		'post_title'   => SBA_FIXTURE_TITLE . $p['title'],
		'post_content' => sba_fx_resolve( $contents[0], $ids, $post_ids ),
		'post_author'  => $admin_id,
	] ), true );
	if ( is_wp_error( $post_id ) ) {
		fwrite( STDERR, "wp_insert_post failed for $pkey: " . $post_id->get_error_message() . "\n" );
		exit( 1 );
	}
	update_post_meta( $post_id, SBA_FIXTURE_META_KEY, 1 );
	$post_ids[ $pkey ]  = $post_id;
	$recreated_post[]   = $pkey;
	sba_fx_apply_post( $post_id, $p, $ids, $post_ids, $admin_id, true, $result );

	if ( 'trash' === $p['post_status'] ) {
		wp_trash_post( $post_id );
	}
	foreach ( wp_get_post_revisions( $post_id, [ 'fields' => 'ids' ] ) as $rid ) {
		$result['revisions'][] = (int) $rid;
	}
	$result['posts'][ $pkey ] = [ 'id' => $post_id, 'title' => SBA_FIXTURE_TITLE . $p['title'], 'status' => get_post_status( $post_id ) ];
	fwrite( STDOUT, sprintf( "  post       %-22s #%d  %s\n", $pkey, $post_id, get_post_status( $post_id ) ) );
}

// Existing fixture posts that reference a re-created attachment/post are re-linked (content,
// thumbnail, meta, Elementor data re-resolved from the definitions). Nothing else is touched.
foreach ( sba_fixture_posts() as $pkey => $p ) {
	if ( in_array( $pkey, $recreated_post, true ) ) {
		continue;
	}
	[ $dep_atts, $dep_posts ] = sba_fx_post_dependencies( $p );
	if ( ! array_intersect( $dep_atts, $repaired ) && ! array_intersect( $dep_posts, $recreated_post ) ) {
		continue;
	}
	$was_trash = 'trash' === get_post_status( $post_ids[ $pkey ] );
	sba_fx_apply_post( $post_ids[ $pkey ], $p, $ids, $post_ids, $admin_id, false, $result );
	if ( $was_trash && 'trash' !== get_post_status( $post_ids[ $pkey ] ) ) {
		wp_trash_post( $post_ids[ $pkey ] );
	}
	fwrite( STDOUT, "  re-linked  post $pkey #{$post_ids[ $pkey ]} to re-created fixtures\n" );
}

// Upload context (post_parent) for created attachments, or when the parent post was re-created.
foreach ( $defs as $key => $def ) {
	if ( ! empty( $def['parent'] ) && ( in_array( $key, $created, true ) || in_array( $def['parent'], $recreated_post, true ) ) ) {
		wp_update_post( [ 'ID' => $ids[ $key ], 'post_parent' => $post_ids[ $def['parent'] ] ] );
	}
}

/* ------------------------------------------------------------ real optimization */

foreach ( $defs as $key => $def ) {
	if ( empty( $def['optimize'] ) || ! in_array( $key, $created, true ) ) {
		continue;
	}
	$opt = ( new SBA_Image_Optimizer() )->optimize_attachment( $ids[ $key ], [ 'max_width' => 1920, 'max_height' => 1080, 'quality' => 82 ] );
	if ( is_wp_error( $opt ) ) {
		$result['notes'][] = "optimize $key failed: " . $opt->get_error_message();
		fwrite( STDOUT, "  optimize   $key FAILED: " . $opt->get_error_message() . "\n" );
	} else {
		$result['optimized'][] = $ids[ $key ];
		fwrite( STDOUT, "  optimize   $key OK: {$opt['original_size_fmt']} -> {$opt['optimized_size_fmt']}, webp {$opt['webp_size_fmt']}\n" );
	}
}

/* ------------------------------------------------------------ missing original */

foreach ( $defs as $key => $def ) {
	if ( ! empty( $def['remove_file'] ) && in_array( $key, $created, true ) ) {
		$orig = get_attached_file( $ids[ $key ] );
		// Our own just-created fixture file; thumbnails are kept so it looks like a real lost original.
		if ( $orig && 0 === stripos( wp_normalize_path( $orig ), $base_dir . '/' ) && file_exists( $orig ) ) {
			unlink( $orig );
			fwrite( STDOUT, "  removed original of $key (missing-file scenario)\n" );
		}
	}
}

// Note: the plugin's sba_image_reference_index transient is deliberately NOT touched; a full
// media scan rebuilds the index itself.

$files = [];
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base_dir, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
	$files[] = ltrim( str_replace( $base_dir, '', wp_normalize_path( $f->getPathname() ) ), '/' );
}
sort( $files );
$result['revisions'] = array_values( array_unique( array_map( 'intval', (array) $result['revisions'] ) ) );
$result['files']     = $files;
if ( $existing ) {
	$result['extended'][] = [ 'at' => gmdate( 'c' ), 'created' => $created, 'repaired' => $repaired, 'posts_created' => $recreated_post ];
} else {
	$result['created_at'] = gmdate( 'c' );
}
$result['site'] = home_url();
file_put_contents( $manifest, wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

fwrite( STDOUT, sprintf(
	"\n%s %d attachments (%d re-created after deletion), %d posts. Total: %d attachments, %d posts, %d files. Manifest: %s\n",
	$existing ? 'Extended: created' : 'Created',
	count( $created ), count( $repaired ), count( $recreated_post ),
	count( $result['attachments'] ), count( $result['posts'] ), count( $files ), $manifest
) );
exit( 0 );
