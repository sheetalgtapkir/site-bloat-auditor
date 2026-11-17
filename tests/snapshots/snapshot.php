<?php
/**
 * Golden-master snapshots of the plugin's read-only scan endpoints.
 *
 * Runs inside a rolled-back transaction with outbound HTTP blocked.
 *
 * Usage:
 *   php snapshot.php capture [suite...]   Save tests/snapshots/baseline/<suite>.json
 *   php snapshot.php compare [suite...]   Diff current output against the baseline (exit 1 on diff)
 *   php snapshot.php sanitize [suite...]  Make existing baselines portable (no re-scan; see portable.php)
 * Suites: media database plugins (default: all)
 *
 * Volatile values (timestamps, database byte counts that change with normal site
 * activity) are masked so diffs show behaviour changes, not noise. Machine-specific
 * values (WordPress root path, site URL) are replaced by placeholders (portable.php).
 *
 * @package Site_Bloat_Auditor\Tests
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

$mode   = $argv[1] ?? '';
$suites = array_slice( $argv, 2 ) ?: [ 'media', 'database', 'plugins' ];
if ( ! in_array( $mode, [ 'capture', 'compare', 'sanitize' ], true ) ) {
	fwrite( STDERR, "Usage: php snapshot.php capture|compare|sanitize [media] [database] [plugins]\n" );
	exit( 2 );
}

// Each suite runs in its own PHP process, like each admin AJAX request does: a scan that runs
// shortcodes (media) would otherwise register scripts that change the plugin audit's evidence.
if ( count( $suites ) > 1 && 'sanitize' !== $mode ) {
	$exit = 0;
	foreach ( $suites as $suite ) {
		$cmd = escapeshellarg( PHP_BINARY )
			. ( php_ini_loaded_file() ? ' -c ' . escapeshellarg( php_ini_loaded_file() ) : '' )
			. ( ini_get( 'mysqli.default_port' ) ? ' -d mysqli.default_port=' . (int) ini_get( 'mysqli.default_port' ) : '' )
			. ' -d display_startup_errors=0 ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $mode ) . ' ' . escapeshellarg( $suite );
		passthru( $cmd, $code );
		$exit = max( $exit, (int) $code );
	}
	exit( $exit );
}

require dirname( __DIR__ ) . '/bootstrap.php';
require __DIR__ . '/portable.php';
require __DIR__ . '/fixture-scope.php';

// Rewrite existing baselines with portable values only (data otherwise unchanged).
if ( 'sanitize' === $mode ) {
	$exit = 0;
	foreach ( $suites as $suite ) {
		$file = __DIR__ . "/baseline/$suite.json";
		if ( ! file_exists( $file ) ) {
			fwrite( STDOUT, "[$suite] no baseline\n" );
			continue;
		}
		$before = (string) file_get_contents( $file );
		$data   = json_decode( $before, true );
		if ( null === $data ) {
			fwrite( STDOUT, "[$suite] invalid JSON, left unchanged\n" );
			$exit = 1;
			continue;
		}
		$after = wp_json_encode( sba_portable( $data ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
		if ( $after === $before ) {
			fwrite( STDOUT, "[$suite] already portable\n" );
			continue;
		}
		file_put_contents( $file, $after );
		fwrite( STDOUT, "[$suite] sanitized -> $suite.json\n" );
	}
	exit( $exit );
}

$config = [
	'media'    => [ 'action' => 'sba_scan_media_files', 'mask' => '/(^|_)(time|date|timestamp|modified_time|quarantined_time)$/i' ],
	'database' => [ 'action' => 'sba_scan_database', 'mask' => '/(time|date|timestamp|size|bytes|_mb$|_kb$|kb$|mb$|fmt|formatted|rows|overhead|count|total|free|recoverable|length|autoload)/i' ],
	'plugins'  => [ 'action' => 'sba_scan_plugins_audit', 'mask' => '/(time|date|timestamp|size|bytes|_mb$|_kb$|kb$|mb$|fmt|formatted|rows|footprint|last_updated|days)/i' ],
];

function sba_snapshot_normalize( $value, $mask, $key = '' ) {
	if ( is_array( $value ) ) {
		$out = [];
		foreach ( $value as $k => $v ) {
			$out[ $k ] = sba_snapshot_normalize( $v, $mask, (string) $k );
		}
		return $out;
	}
	if ( '' !== $key && ! is_numeric( $key ) && preg_match( $mask, $key ) ) {
		return '<masked>';
	}
	return $value;
}

function sba_snapshot_diff( $a, $b, $path = '', array &$out = [] ) {
	if ( count( $out ) >= 40 ) {
		return $out;
	}
	if ( is_array( $a ) && is_array( $b ) ) {
		foreach ( array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) ) as $k ) {
			$p = '' === $path ? (string) $k : "$path.$k";
			if ( ! array_key_exists( $k, $a ) ) {
				$out[] = "+ $p (new)";
			} elseif ( ! array_key_exists( $k, $b ) ) {
				$out[] = "- $p (removed)";
			} else {
				sba_snapshot_diff( $a[ $k ], $b[ $k ], $p, $out );
			}
		}
	} elseif ( $a !== $b ) {
		$out[] = "~ $path: " . wp_json_encode( $a ) . ' => ' . wp_json_encode( $b );
	}
	return $out;
}

sba_test_login_admin();
$dir = __DIR__ . '/baseline';
if ( ! is_dir( $dir ) ) {
	mkdir( $dir, 0755, true );
}

$exit = 0;
foreach ( $suites as $suite ) {
	if ( ! isset( $config[ $suite ] ) ) {
		fwrite( STDERR, "Unknown suite $suite\n" );
		$exit = 2;
		continue;
	}
	$res = sba_test_ajax( $config[ $suite ]['action'] );
	if ( empty( $res['json']['success'] ) ) {
		fwrite( STDOUT, "[$suite] endpoint did not return success: " . substr( $res['raw'] . $res['exception'], 0, 300 ) . "\n" );
		$exit = 1;
		continue;
	}
	$norm = sba_portable( sba_snapshot_normalize( $res['json'], $config[ $suite ]['mask'] ) );
	if ( 'media' === $suite ) {
		$norm = sba_fixture_scope_media( $norm ); // Controlled fixtures only, stable fixture keys.
	}
	// The database scan orders tables by size; MySQL size estimates drift between runs,
	// so compare the table set in a stable (name) order.
	if ( 'database' === $suite && isset( $norm['data']['tables'] ) && is_array( $norm['data']['tables'] ) ) {
		usort( $norm['data']['tables'], function ( $a, $b ) {
			return strcmp( (string) ( $a['name'] ?? '' ), (string) ( $b['name'] ?? '' ) );
		} );
	}
	// Plugin lists are compared by identity, not by position (order follows size estimates).
	foreach ( [ 'database', 'plugins' ] as $s ) {
		if ( $s === $suite && isset( $norm['data']['plugins'] ) && is_array( $norm['data']['plugins'] ) ) {
			usort( $norm['data']['plugins'], function ( $a, $b ) {
				return strcmp( (string) ( $a['plugin_file'] ?? '' ), (string) ( $b['plugin_file'] ?? '' ) );
			} );
		}
	}
	$file = "$dir/$suite.json";

	if ( 'capture' === $mode ) {
		file_put_contents( $file, wp_json_encode( $norm, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
		fwrite( STDOUT, "[$suite] captured " . strlen( $res['raw'] ) . " bytes -> $file\n" );
		continue;
	}

	if ( ! file_exists( $file ) ) {
		fwrite( STDOUT, "[$suite] no baseline; run capture first\n" );
		$exit = 1;
		continue;
	}
	$base = json_decode( (string) file_get_contents( $file ), true );
	$diff = sba_snapshot_diff( $base, json_decode( wp_json_encode( $norm ), true ) );
	if ( $diff ) {
		$exit = 1;
		fwrite( STDOUT, "[$suite] DIFFERS from baseline (" . count( $diff ) . " shown, max 40):\n  " . implode( "\n  ", $diff ) . "\n" );
	} else {
		fwrite( STDOUT, "[$suite] identical to baseline\n" );
	}
}
exit( $exit );
