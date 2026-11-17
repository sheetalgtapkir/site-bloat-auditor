<?php
/**
 * Remove the media-audit fixture scenario from the LOCAL test site.
 *
 * Safety rules:
 *  - Dry run by default; pass --confirm to actually remove anything.
 *  - A post/attachment is removed only if it is listed in the manifest AND carries
 *    post meta _sba_test_fixture = 1 (both must be true).
 *  - Files are removed only if their real path is inside uploads/sba-test-fixtures/
 *    or uploads/sba-quarantine/sba-test-fixtures/.
 *  - Plugin option records (sba_optimized_images, sba_quarantined_files) are pruned
 *    only for fixture attachment IDs; all other records are left untouched.
 *
 * Usage: php cleanup-fixtures.php [--confirm]
 *
 * @package Site_Bloat_Auditor\Tests
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

define( 'SBA_TEST_ROLLBACK', false );
require dirname( __DIR__ ) . '/bootstrap.php';
require __DIR__ . '/fixture-definitions.php';

$confirm  = in_array( '--confirm', $argv, true );
$uploads  = wp_upload_dir( null, false );
$basedir  = wp_normalize_path( $uploads['basedir'] );
$fx_dir   = $basedir . '/' . SBA_FIXTURE_DIR;
$q_fx_dir = $basedir . '/sba-quarantine/' . SBA_FIXTURE_DIR;
$manifest = $fx_dir . '/manifest.json';

fwrite( STDOUT, $confirm ? "MODE: EXECUTE (--confirm)\n" : "MODE: DRY RUN (nothing will be removed; pass --confirm)\n" );

$data = file_exists( $manifest ) ? json_decode( (string) file_get_contents( $manifest ), true ) : null;
if ( ! is_array( $data ) ) {
	fwrite( STDOUT, "No manifest found at $manifest.\n" );
	$data = [ 'attachments' => [], 'posts' => [], 'revisions' => [] ];
}

sba_test_login_admin();

$is_tagged = function ( $id ) {
	return (int) $id > 0 && '1' === (string) get_post_meta( (int) $id, SBA_FIXTURE_META_KEY, true );
};
$inside = function ( $path, $dir ) {
	$real = realpath( $path );
	$rdir = realpath( $dir );
	return $real && $rdir && 0 === strpos( wp_normalize_path( $real ), trailingslashit( wp_normalize_path( $rdir ) ) );
};

$att_ids  = array_map( 'intval', array_column( (array) $data['attachments'], 'id' ) );
$post_ids = array_map( 'intval', array_column( (array) $data['posts'], 'id' ) );
$rev_ids  = array_map( 'intval', (array) ( $data['revisions'] ?? [] ) );

// Tagged items that are missing from the manifest are reported, never removed.
global $wpdb;
$tagged = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '1'", SBA_FIXTURE_META_KEY ) ) );
$untracked = array_diff( $tagged, $att_ids, $post_ids, $rev_ids );
if ( $untracked ) {
	fwrite( STDOUT, 'NOTE: tagged but not in manifest (left untouched): #' . implode( ', #', $untracked ) . "\n" );
}

// 1. Plugin option records for fixture attachments.
foreach ( [ 'sba_optimized_images', 'sba_quarantined_files' ] as $opt ) {
	$records = (array) get_option( $opt, [] );
	$hits    = array_intersect( array_map( 'intval', array_keys( $records ) ), $att_ids );
	foreach ( $hits as $hid ) {
		fwrite( STDOUT, "  option $opt: remove record for fixture #$hid\n" );
		if ( $confirm ) {
			unset( $records[ $hid ] );
		}
	}
	if ( $confirm && $hits ) {
		update_option( $opt, $records, false );
	}
}

// 2. Quarantined fixture files (if a test quarantined any) and attachment status.
if ( is_dir( $q_fx_dir ) ) {
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $q_fx_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $f ) {
		if ( $f->isFile() && $inside( $f->getPathname(), $q_fx_dir ) ) {
			fwrite( STDOUT, '  quarantine file: ' . $f->getFilename() . "\n" );
			if ( $confirm ) {
				unlink( $f->getPathname() );
			}
		} elseif ( $f->isDir() && $confirm ) {
			@rmdir( $f->getPathname() );
		}
	}
	if ( $confirm ) {
		@rmdir( $q_fx_dir );
	}
}

// 3. Attachments (wp_delete_attachment removes original, thumbnails and metadata).
foreach ( $att_ids as $aid ) {
	if ( ! $is_tagged( $aid ) ) {
		fwrite( STDOUT, "  SKIP attachment #$aid (not tagged as fixture)\n" );
		continue;
	}
	// Judge location by the raw stored value: either uploads-relative "sba-test-fixtures/…"
	// or an absolute path inside the fixture directory.
	$raw     = wp_normalize_path( (string) get_post_meta( $aid, '_wp_attached_file', true ) );
	$rel_ok  = 0 === strpos( $raw, SBA_FIXTURE_DIR . '/' ) && false === strpos( $raw, '..' );
	$abs_ok  = 0 === stripos( $raw, trailingslashit( $fx_dir ) ) && false === strpos( $raw, '..' );
	if ( '' !== $raw && ! $rel_ok && ! $abs_ok ) {
		fwrite( STDOUT, "  SKIP attachment #$aid (file outside fixture dir: $raw)\n" );
		continue;
	}
	fwrite( STDOUT, "  attachment #$aid " . wp_basename( $raw ) . ( $abs_ok ? ' (absolute path record)' : '' ) . "\n" );
	if ( $confirm ) {
		// Files are removed by step 5 (directory sweep); for absolute-path records WordPress
		// cannot resolve the file, and we never let it unlink anything outside the fixture dir.
		if ( $abs_ok ) {
			delete_post_meta( $aid, '_wp_attached_file' );
			delete_post_meta( $aid, '_wp_attachment_metadata' );
		}
		wp_delete_attachment( $aid, true );
	}
}

// 4. Revisions, then posts.
foreach ( array_unique( array_merge( $rev_ids, $post_ids ) ) as $pid ) {
	$post = get_post( $pid );
	if ( ! $post ) {
		continue;
	}
	$parent_tagged = 'revision' === $post->post_type && $is_tagged( $post->post_parent );
	if ( ! $is_tagged( $pid ) && ! $parent_tagged ) {
		fwrite( STDOUT, "  SKIP post #$pid (not tagged as fixture)\n" );
		continue;
	}
	fwrite( STDOUT, "  {$post->post_type} #$pid {$post->post_title}\n" );
	if ( $confirm ) {
		wp_delete_post( $pid, true );
	}
}

// 5. Remaining fixture files (backups, webp companions, manifest) and the directory.
if ( is_dir( $fx_dir ) ) {
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $fx_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $f ) {
		if ( $f->isFile() && $inside( $f->getPathname(), $fx_dir ) ) {
			if ( ! $confirm ) {
				continue; // Most are removed by step 3; only listed after a real run.
			}
			unlink( $f->getPathname() );
		} elseif ( $f->isDir() && $confirm ) {
			@rmdir( $f->getPathname() );
		}
	}
	if ( $confirm ) {
		@rmdir( $fx_dir );
	}
}

fwrite( STDOUT, $confirm ? "\nCleanup complete.\n" : "\nDry run complete. Re-run with --confirm to remove the items above.\n" );
exit( 0 );
