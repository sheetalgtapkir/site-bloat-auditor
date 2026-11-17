<?php
/**
 * Disposable media for controlled end-to-end tests of quarantine / restore / permanent delete.
 *
 * Unlike the regression fixtures (which SBA_Media_Safety protects from admin actions), these
 * items behave exactly like ordinary media so the real destructive flows can be exercised:
 *   disp-unused  — no references (to quarantine, restore, quarantine again, delete)
 *   disp-in-use  — shown on a disposable published post (deletion must be refused)
 *   disp-review  — referenced only by a disposable draft (deletion needs explicit confirmation)
 *
 * Files: uploads/sba-test-disposable/ (outside the fixture directory). Every record carries
 * post meta _sba_test_disposable = 1 and is listed in uploads/sba-test-disposable/manifest.json.
 * Cleanup removes only records that are BOTH listed and tagged, and only files inside that
 * directory (or the plugin's quarantine copy of them).
 *
 *   php disposable-media.php create
 *   php disposable-media.php status
 *   php disposable-media.php cleanup [--confirm]
 *
 * @package Site_Bloat_Auditor\Tests
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

$mode = $argv[1] ?? 'status';
if ( 'status' !== $mode ) {
	define( 'SBA_TEST_ROLLBACK', false );
}
require dirname( __DIR__ ) . '/bootstrap.php';

const SBA_DISPOSABLE_DIR = 'sba-test-disposable';
const SBA_DISPOSABLE_TAG = '_sba_test_disposable';

$up       = wp_upload_dir( null, false );
$base     = wp_normalize_path( trailingslashit( $up['basedir'] ) . SBA_DISPOSABLE_DIR );
$manifest = $base . '/manifest.json';
$admin_id = sba_test_login_admin();
$data     = file_exists( $manifest ) ? (array) json_decode( (string) file_get_contents( $manifest ), true ) : [];

$tagged = function ( $id ) {
	return (int) $id > 0 && get_post( (int) $id ) && '1' === (string) get_post_meta( (int) $id, SBA_DISPOSABLE_TAG, true );
};

if ( 'create' === $mode ) {
	if ( $data ) {
		fwrite( STDERR, "Disposable media already exists ($manifest). Run cleanup --confirm first.\n" );
		exit( 1 );
	}
	remove_action( 'publish_post', '_publish_post_hook', 5 );
	wp_mkdir_p( $base );
	$data = [ 'attachments' => [], 'posts' => [], 'created_at' => gmdate( 'c' ) ];
	foreach ( [ 'disp-unused' => [ 120, 80, 200 ], 'disp-in-use' => [ 60, 160, 90 ], 'disp-review' => [ 200, 120, 40 ] ] as $key => $rgb ) {
		$path = "$base/$key.jpg";
		$im   = imagecreatetruecolor( 640, 400 );
		imagefilledrectangle( $im, 0, 0, 640, 400, imagecolorallocate( $im, $rgb[0], $rgb[1], $rgb[2] ) );
		imagestring( $im, 5, 10, 10, 'SBA DISPOSABLE ' . $key, imagecolorallocate( $im, 255, 255, 255 ) );
		imagejpeg( $im, $path, 85 );
		imagedestroy( $im );
		$id = wp_insert_attachment( [ 'post_mime_type' => 'image/jpeg', 'post_title' => '[SBA DISPOSABLE] ' . $key, 'post_name' => $key, 'post_status' => 'inherit', 'post_author' => $admin_id ], false, 0, true );
		update_post_meta( $id, SBA_DISPOSABLE_TAG, 1 );
		update_post_meta( $id, '_wp_attached_file', SBA_DISPOSABLE_DIR . "/$key.jpg" );
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, get_attached_file( $id ) ) );
		$data['attachments'][ $key ] = (int) $id;
	}
	$img = function ( $key ) use ( $data ) {
		$id = $data['attachments'][ $key ];
		return '<!-- wp:image {"id":' . $id . '} --><figure class="wp-block-image"><img src="' . esc_url( wp_get_attachment_url( $id ) ) . '" class="wp-image-' . $id . '" alt=""/></figure><!-- /wp:image -->';
	};
	foreach ( [ 'disp-post-published' => [ 'publish', 'disp-in-use' ], 'disp-post-draft' => [ 'draft', 'disp-review' ] ] as $key => [ $status, $att ] ) {
		$pid = wp_insert_post( [ 'post_type' => 'post', 'post_status' => $status, 'post_title' => '[SBA DISPOSABLE] ' . $key, 'post_content' => $img( $att ), 'post_author' => $admin_id ], true );
		update_post_meta( $pid, SBA_DISPOSABLE_TAG, 1 );
		$data['posts'][ $key ] = (int) $pid;
	}
	file_put_contents( $manifest, wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	$mode = 'status';
}

if ( 'cleanup' === $mode ) {
	$confirm = in_array( '--confirm', $argv, true );
	fwrite( STDOUT, $confirm ? "CLEANUP (--confirm)\n" : "CLEANUP DRY RUN (pass --confirm)\n" );
	$q = (array) get_option( 'sba_quarantined_files', [] );
	foreach ( array_merge( (array) ( $data['attachments'] ?? [] ), (array) ( $data['posts'] ?? [] ) ) as $key => $id ) {
		if ( ! $tagged( $id ) ) {
			fwrite( STDOUT, "  skip $key #$id (gone or not tagged disposable)\n" );
			continue;
		}
		$raw = wp_normalize_path( (string) get_post_meta( $id, '_wp_attached_file', true ) );
		if ( 'attachment' === get_post_type( $id ) && 0 !== strpos( $raw, SBA_DISPOSABLE_DIR . '/' ) ) {
			fwrite( STDOUT, "  skip $key #$id (file not in the disposable directory: $raw)\n" );
			continue;
		}
		fwrite( STDOUT, "  remove $key #$id" . ( isset( $q[ $id ] ) ? ' (+ its quarantine record)' : '' ) . "\n" );
		if ( $confirm ) {
			if ( isset( $q[ $id ] ) ) {
				foreach ( (array) ( $q[ $id ]['moved_files'] ?? [] ) as $mf ) {
					$qp = wp_normalize_path( (string) ( $mf['quarantine_path'] ?? '' ) );
					if ( $qp && false !== strpos( $qp, '/sba-quarantine/' ) && false !== strpos( $qp, SBA_DISPOSABLE_DIR ) && file_exists( $qp ) ) {
						unlink( $qp );
					}
				}
				unset( $q[ $id ] );
				update_option( 'sba_quarantined_files', $q, false );
			}
			'attachment' === get_post_type( $id ) ? wp_delete_attachment( $id, true ) : wp_delete_post( $id, true );
		}
	}
	if ( $confirm && is_dir( $base ) ) {
		foreach ( glob( $base . '/*' ) ?: [] as $f ) {
			if ( is_file( $f ) ) {
				unlink( $f );
			}
		}
		@rmdir( $base );
	}
	exit( 0 );
}

// status
fwrite( STDOUT, "Disposable media (" . ( $data ? $manifest : 'none' ) . "):\n" );
$q = (array) get_option( 'sba_quarantined_files', [] );
foreach ( (array) ( $data['attachments'] ?? [] ) as $key => $id ) {
	$file = get_post( $id ) ? get_attached_file( $id ) : '';
	fwrite( STDOUT, sprintf( "  %-12s #%-5d record=%s tagged=%s file=%s quarantined=%s\n", $key, $id, get_post( $id ) ? 'yes' : 'NO', $tagged( $id ) ? 'yes' : 'no', $file ? ( file_exists( $file ) ? 'on disk' : 'not at original path' ) : '-', isset( $q[ $id ] ) ? 'yes' : 'no' ) );
}
foreach ( (array) ( $data['posts'] ?? [] ) as $key => $id ) {
	fwrite( STDOUT, sprintf( "  %-20s #%-5d %s\n", $key, $id, get_post_status( $id ) ?: 'GONE' ) );
}
exit( 0 );
