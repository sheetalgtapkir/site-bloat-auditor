<?php
/**
 * Fingerprint of EXISTING site state, used to prove a test step did not modify it.
 *
 *   php state-fingerprint.php capture <file.json>   Record current state
 *   php state-fingerprint.php compare <file.json>   List rows/files changed since capture
 *
 * Covers: every file under uploads/ (path, size, sha256) EXCEPT uploads/sba-test-fixtures/;
 * every row of posts, postmeta, options, term_taxonomy, term_relationships (CRC per row).
 * New rows are reported separately from modified/removed pre-existing rows.
 *
 * Read-only (runs inside a rolled-back transaction).
 *
 * @package Site_Bloat_Auditor\Tests
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require dirname( __DIR__ ) . '/bootstrap.php';

$mode = $argv[1] ?? '';
$file = $argv[2] ?? '';
if ( ! in_array( $mode, [ 'capture', 'compare' ], true ) || ! $file ) {
	fwrite( STDERR, "Usage: php state-fingerprint.php capture|compare <file.json>\n" );
	exit( 2 );
}

function sba_fp_collect() {
	global $wpdb;
	$fp      = [ 'files' => [], 'rows' => [] ];
	$basedir = wp_normalize_path( wp_upload_dir( null, false )['basedir'] );
	$skip    = $basedir . '/sba-test-fixtures/';
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $basedir, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
		$p = wp_normalize_path( $f->getPathname() );
		if ( 0 === strpos( $p, $skip ) ) {
			continue;
		}
		$fp['files'][ substr( $p, strlen( $basedir ) + 1 ) ] = $f->getSize() . ':' . hash_file( 'sha256', $p );
	}
	$tables = [
		$wpdb->posts              => [ 'ID', 'ID' ],
		$wpdb->postmeta           => [ 'meta_id', 'meta_id,post_id,meta_key,meta_value' ],
		$wpdb->options            => [ 'option_name', 'option_name,option_value,autoload' ],
		$wpdb->term_taxonomy      => [ 'term_taxonomy_id', 'term_taxonomy_id,term_id,taxonomy,parent,count' ],
		$wpdb->term_relationships => [ "CONCAT(object_id,'-',term_taxonomy_id)", 'object_id,term_taxonomy_id,term_order' ],
	];
	foreach ( $tables as $t => [ $key, $cols ] ) {
		$crc_cols = $t === $wpdb->posts ? '*' : $cols;
		if ( '*' === $crc_cols ) {
			$colnames = $wpdb->get_col( "SHOW COLUMNS FROM `$t`" );
			$crc_cols = '`' . implode( '`,`', $colnames ) . '`';
		}
		$rows = $wpdb->get_results( "SELECT $key AS k, CRC32(CONCAT_WS('|', $crc_cols)) AS c FROM `$t`", ARRAY_A );
		foreach ( $rows as $r ) {
			$fp['rows'][ $t ][ (string) $r['k'] ] = $r['c'];
		}
	}
	return $fp;
}

$now = sba_fp_collect();

if ( 'capture' === $mode ) {
	file_put_contents( $file, wp_json_encode( $now ) );
	$rows = array_sum( array_map( 'count', $now['rows'] ) );
	fwrite( STDOUT, 'Captured ' . count( $now['files'] ) . " files and $rows rows -> $file\n" );
	exit( 0 );
}

$then = json_decode( (string) file_get_contents( $file ), true );
$bad  = 0;

$changed_files = [];
foreach ( $then['files'] as $p => $sig ) {
	if ( ! isset( $now['files'][ $p ] ) ) {
		$changed_files[] = "REMOVED  $p";
	} elseif ( $now['files'][ $p ] !== $sig ) {
		$changed_files[] = "MODIFIED $p";
	}
}
$new_files = array_diff( array_keys( $now['files'] ), array_keys( $then['files'] ) );
fwrite( STDOUT, 'Existing files outside uploads/sba-test-fixtures/: ' . count( $then['files'] ) . ' — ' . ( $changed_files ? count( $changed_files ) . " changed:\n  " . implode( "\n  ", $changed_files ) : 'all unchanged' ) . "\n" );
fwrite( STDOUT, 'New files outside uploads/sba-test-fixtures/: ' . ( $new_files ? count( $new_files ) . ":\n  " . implode( "\n  ", $new_files ) : 'none' ) . "\n" );
$bad += count( $changed_files ) + count( $new_files );

foreach ( $then['rows'] as $t => $rows ) {
	$mod = [];
	foreach ( $rows as $k => $c ) {
		if ( ! isset( $now['rows'][ $t ][ $k ] ) ) {
			$mod[] = "REMOVED $k";
		} elseif ( (string) $now['rows'][ $t ][ $k ] !== (string) $c ) {
			$mod[] = "MODIFIED $k";
		}
	}
	$added = count( array_diff_key( $now['rows'][ $t ] ?? [], $rows ) );
	fwrite( STDOUT, sprintf( "%-28s existing rows: %6d | changed: %d%s | new rows: %d\n", $t, count( $rows ), count( $mod ), $mod ? ' (' . implode( ', ', array_slice( $mod, 0, 15 ) ) . ')' : '', $added ) );
	$bad += count( $mod );
}
exit( $bad ? 1 : 0 );
