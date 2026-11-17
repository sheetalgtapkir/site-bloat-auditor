<?php
/**
 * Deletion safeguards (SBA_Media_Safety) exercised through the real AJAX handlers.
 *
 * Read-only: runs inside a rolled-back transaction. Uses only temporary attachments whose
 * stored file does not exist, so no file on disk can be touched even if a guard failed.
 * Real media is never targeted; one existing test fixture is targeted only to prove that the
 * request is refused.
 *
 * Usage: php test-media-safety.php
 *
 * @package Site_Bloat_Auditor\Tests
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require dirname( __DIR__ ) . '/bootstrap.php';
require dirname( __DIR__ ) . '/fixtures/fixture-definitions.php';

sba_test_login_admin();

/** Temporary attachment record without a file on disk. */
function sba_ms_attachment( $label ) {
	$id = wp_insert_attachment( [ 'post_mime_type' => 'image/jpeg', 'post_title' => "ZZ safety $label", 'post_status' => 'inherit' ], false, 0, true );
	update_post_meta( $id, '_wp_attached_file', 'sba-safety-test-nonexistent/' . sanitize_title( $label ) . '.jpg' );
	return (int) $id;
}
function sba_ms_post( $status, $content ) {
	return (int) wp_insert_post( [ 'post_type' => 'post', 'post_status' => $status, 'post_title' => 'ZZ safety post', 'post_content' => $content ] );
}
function sba_ms_exists( $id ) {
	return (bool) get_post( (int) $id );
}
function sba_ms_msg( $res ) {
	$j = $res['json'] ?? [];
	$d = $j['data'] ?? '';
	return is_string( $d ) ? $d : wp_json_encode( $d );
}
function sba_ms_call( $action, array $post ) {
	SBA_Media_Safety::release_mutex(); // Each call behaves like a separate request.
	return sba_test_ajax( $action, $post );
}

$used    = sba_ms_attachment( 'used' );
$review  = sba_ms_attachment( 'review' );
$unused  = sba_ms_attachment( 'unused' );
$unused2 = sba_ms_attachment( 'unused-2' );
sba_ms_post( 'publish', '<p><img class="wp-image-' . $used . '" src="x.jpg"/></p>' );
sba_ms_post( 'draft', '<p><img class="wp-image-' . $review . '" src="y.jpg"/></p>' );

fwrite( STDOUT, "\n-- Emergency lock --\n" );
SBA_Media_Safety::set_lock( true, 'test' );
$r = sba_ms_call( 'sba_bulk_delete_media', [ 'attachment_id' => $unused, 'confirmed' => 'yes' ] );
SBA_Test_Results::check( 'Locked: permanent delete refused', empty( $r['json']['success'] ) && sba_ms_exists( $unused ) && false !== stripos( sba_ms_msg( $r ), 'temporarily disabled' ), sba_ms_msg( $r ) );
$r = sba_ms_call( 'sba_quarantine_media', [ 'attachment_id' => $unused ] );
SBA_Test_Results::check( 'Locked: quarantine refused', empty( $r['json']['success'] ) && sba_ms_exists( $unused ), sba_ms_msg( $r ) );
$r = sba_ms_call( 'sba_empty_quarantine', [ 'confirmed' => 'yes' ] );
SBA_Test_Results::check( 'Locked: empty quarantine refused (or nothing to empty)', empty( $r['json']['success'] ) || 0 === (int) ( $r['json']['data']['deleted_count'] ?? 0 ), sba_ms_msg( $r ) );
$r = sba_ms_call( 'sba_delete_single_thumbnail_subsize', [ 'attachment_id' => $unused, 'size_key' => 'thumbnail' ] );
SBA_Test_Results::check( 'Locked: thumbnail pruning refused', empty( $r['json']['success'] ) && false !== stripos( sba_ms_msg( $r ), 'temporarily disabled' ), sba_ms_msg( $r ) );
$scan = sba_test_ajax( 'sba_scan_media_files' );
SBA_Test_Results::check( 'Locked: media scan / reporting still works', ! empty( $scan['json']['success'] ) );

SBA_Media_Safety::set_lock( false ); // Inside the transaction only (rolled back at the end).

fwrite( STDOUT, "\n-- Fresh usage check before destruction --\n" );
$r = sba_ms_call( 'sba_bulk_delete_media', [ 'attachment_id' => $used, 'confirmed' => 'yes' ] );
SBA_Test_Results::check( 'In Use: permanent delete refused, attachment kept', sba_ms_exists( $used ) && false !== stripos( sba_ms_msg( $r ), 'In Use' ), sba_ms_msg( $r ) );
$r = sba_ms_call( 'sba_bulk_delete_media', [ 'attachment_id' => $used, 'confirmed' => 'yes', 'confirm_review' => 'yes', 'usage_status' => 'unused' ] );
SBA_Test_Results::check( 'In Use: refused even with review confirmation and a forged "unused" status', sba_ms_exists( $used ), sba_ms_msg( $r ) );
$r = sba_ms_call( 'sba_quarantine_media', [ 'attachment_id' => $used ] );
SBA_Test_Results::check( 'In Use: quarantine refused', empty( $r['json']['success'] ) && sba_ms_exists( $used ) && ! isset( ( (array) get_option( 'sba_quarantined_files', [] ) )[ $used ] ), sba_ms_msg( $r ) );

$r = sba_ms_call( 'sba_bulk_delete_media', [ 'attachment_id' => $review, 'confirmed' => 'yes' ] );
SBA_Test_Results::check( 'Review: delete refused without explicit review confirmation', sba_ms_exists( $review ) && false !== stripos( sba_ms_msg( $r ), 'review' ), sba_ms_msg( $r ) );
$r = sba_ms_call( 'sba_bulk_delete_media', [ 'attachment_id' => $review, 'confirmed' => 'yes', 'confirm_review' => 'yes' ] );
SBA_Test_Results::check( 'Review: delete allowed with explicit review confirmation', ! sba_ms_exists( $review ), sba_ms_msg( $r ) );

$r = sba_ms_call( 'sba_bulk_delete_media', [ 'attachment_id' => $unused ] );
SBA_Test_Results::check( 'Unused: delete without explicit confirmation refused', sba_ms_exists( $unused ), sba_ms_msg( $r ) );
$r = sba_ms_call( 'sba_bulk_delete_media', [ 'attachment_id' => $unused, 'confirmed' => 'yes' ] );
SBA_Test_Results::check( 'Unused: delete with explicit confirmation allowed', ! sba_ms_exists( $unused ), sba_ms_msg( $r ) );

$r = sba_ms_call( 'sba_empty_quarantine', [] );
SBA_Test_Results::check( 'Empty quarantine without explicit confirmation refused (or nothing to empty)', empty( $r['json']['success'] ) || 0 === (int) ( $r['json']['data']['deleted_count'] ?? 0 ), sba_ms_msg( $r ) );

fwrite( STDOUT, "\n-- Stale state cannot authorize deletion --\n" );
// The image becomes used AFTER a scan reported it unused: deletion must still be refused.
$scan  = sba_test_ajax( 'sba_scan_media_files' );
$stale = 'unknown';
foreach ( (array) ( $scan['json']['data']['all_items'] ?? [] ) as $it ) {
	if ( (int) $it['id'] === $unused2 ) {
		$stale = $it['usage_status'] ?? '';
	}
}
sba_ms_post( 'publish', '<p><img class="wp-image-' . $unused2 . '" src="z.jpg"/></p>' );
$r = sba_ms_call( 'sba_bulk_delete_media', [ 'attachment_id' => $unused2, 'confirmed' => 'yes' ] );
SBA_Test_Results::check( "Scan said \"$stale\", image used since: delete refused by the fresh check", 'unused' === $stale && sba_ms_exists( $unused2 ) && false !== stripos( sba_ms_msg( $r ), 'In Use' ), sba_ms_msg( $r ) );

fwrite( STDOUT, "\n-- Concurrency --\n" );
SBA_Media_Safety::release_mutex();
add_option( SBA_Media_Safety::MUTEX_OPTION, time() + 60, '', 'no' ); // Another request holds the mutex.
$victim = sba_ms_attachment( 'mutex' );
$r      = sba_test_ajax( 'sba_bulk_delete_media', [ 'attachment_id' => $victim, 'confirmed' => 'yes' ] );
SBA_Test_Results::check( 'Concurrent destructive request refused while another holds the mutex', sba_ms_exists( $victim ) && false !== stripos( sba_ms_msg( $r ), 'in progress' ), sba_ms_msg( $r ) );
delete_option( SBA_Media_Safety::MUTEX_OPTION );
update_option( SBA_Media_Safety::MUTEX_OPTION, time() - 5 ); // Stale mutex from a dead request.
$r = sba_test_ajax( 'sba_bulk_delete_media', [ 'attachment_id' => $victim, 'confirmed' => 'yes' ] );
SBA_Test_Results::check( 'Expired mutex is recovered (request proceeds)', ! sba_ms_exists( $victim ), sba_ms_msg( $r ) );
SBA_Media_Safety::release_mutex();

fwrite( STDOUT, "\n-- Test fixtures are protected outside their lifecycle --\n" );
$up = wp_upload_dir( null, false );
$mf = json_decode( (string) @file_get_contents( wp_normalize_path( $up['basedir'] ) . '/' . SBA_FIXTURE_DIR . '/manifest.json' ), true );
$fx = 0;
foreach ( (array) ( $mf['attachments'] ?? [] ) as $a ) {
	if ( get_post( (int) $a['id'] ) && SBA_Media_Safety::is_test_fixture( (int) $a['id'] ) ) {
		$fx = (int) $a['id'];
		break;
	}
}
$fx_post = 0;
foreach ( (array) ( $mf['posts'] ?? [] ) as $p ) {
	if ( 'publish' === get_post_status( (int) $p['id'] ) && SBA_Media_Safety::is_test_fixture( (int) $p['id'] ) ) {
		$fx_post = (int) $p['id'];
		break;
	}
}
if ( $fx ) {
	$r = sba_ms_call( 'sba_bulk_delete_media', [ 'attachment_id' => $fx, 'confirmed' => 'yes', 'confirm_review' => 'yes' ] );
	SBA_Test_Results::check( "Fixture #$fx: plugin delete refused", sba_ms_exists( $fx ) && false !== stripos( sba_ms_msg( $r ), 'fixture' ), sba_ms_msg( $r ) );
	$r = sba_ms_call( 'sba_quarantine_media', [ 'attachment_id' => $fx, 'confirm_review' => 'yes' ] );
	SBA_Test_Results::check( "Fixture #$fx: plugin quarantine refused", sba_ms_exists( $fx ) && empty( $r['json']['success'] ), sba_ms_msg( $r ) );
	$core = wp_delete_attachment( $fx, true );
	SBA_Test_Results::check( "Fixture #$fx: core wp_delete_attachment() refused", false === $core && sba_ms_exists( $fx ) );
} else {
	SBA_Test_Results::check( 'A live fixture attachment exists to test protection', false, 'none found' );
}
if ( $fx_post ) {
	wp_trash_post( $fx_post );
	SBA_Test_Results::check( "Fixture post #$fx_post: core trash refused", 'publish' === get_post_status( $fx_post ) );
	SBA_Test_Results::check( "Fixture post #$fx_post: core delete refused", ! wp_delete_post( $fx_post, true ) && sba_ms_exists( $fx_post ) );
}
$plain = sba_ms_attachment( 'not-a-fixture' );
SBA_Test_Results::check( 'Untagged attachments are not treated as fixtures (core delete works)', false !== wp_delete_attachment( $plain, true ) && ! sba_ms_exists( $plain ) );

exit( SBA_Test_Results::summary( 'Media deletion safeguards' ) );
