<?php
/**
 * Makes snapshot data portable: machine-specific values are replaced by placeholders so
 * baselines contain no local usernames, paths or site URLs and compare across machines.
 *
 *   WordPress root (ABSPATH, any slash style)  -> {WP_ROOT}/
 *   Site URL (home_url / site_url origin)        -> https://example.test
 *
 * Applied to captured output and to the current output before comparing, so a baseline
 * captured anywhere compares like-for-like with a run anywhere else.
 *
 * @package Site_Bloat_Auditor\Tests
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

define( 'SBA_PORTABLE_ROOT', '{WP_ROOT}/' );
define( 'SBA_PORTABLE_URL', 'https://example.test' );

/** [ search => replace ] pairs for this installation, longest first. */
function sba_portable_map() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}
	$map  = [];
	$root = rtrim( ABSPATH, '/\\' );
	foreach ( [ $root, wp_normalize_path( $root ), str_replace( '/', '\\', $root ) ] as $variant ) {
		$map[ $variant . '/' ]  = SBA_PORTABLE_ROOT;
		$map[ $variant . '\\' ] = SBA_PORTABLE_ROOT;
	}
	foreach ( [ home_url(), site_url() ] as $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$port = wp_parse_url( $url, PHP_URL_PORT );
		if ( $host ) {
			foreach ( [ 'http', 'https' ] as $scheme ) {
				$map[ $scheme . '://' . $host . ( $port ? ':' . $port : '' ) ] = SBA_PORTABLE_URL;
			}
		}
	}
	uksort( $map, function ( $a, $b ) {
		return strlen( $b ) - strlen( $a );
	} );
	return $map;
}

/** Recursively replace machine-specific values in strings (keys are left unchanged). */
function sba_portable( $value ) {
	if ( is_array( $value ) ) {
		foreach ( $value as $k => $v ) {
			$value[ $k ] = sba_portable( $v );
		}
		return $value;
	}
	if ( is_string( $value ) && '' !== $value ) {
		$map = sba_portable_map();
		return str_ireplace( array_keys( $map ), array_values( $map ), $value );
	}
	return $value;
}
