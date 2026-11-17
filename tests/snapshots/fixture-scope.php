<?php
/**
 * Fixture-only media snapshot (deterministic across runs and machines).
 *
 * The media scan reports the whole library; its snapshot is reduced to the controlled test
 * fixtures listed in uploads/sba-test-fixtures/manifest.json:
 *   - only fixture attachments are kept (real uploads never enter the snapshot);
 *   - attachment and post IDs (auto-increment, different on every fixture rebuild) become
 *     stable fixture keys: {fx:gold-loan}, {fxpost:gold_page}; IDs of anything that is not a
 *     fixture become {other}. Applied to ID fields and to IDs embedded in text
 *     (post=123, wp-image-123, ID #123, #123);
 *   - lists are sorted by fixture key;
 *   - site-wide totals (which include real uploads) are replaced by counts computed from the
 *     fixture items; the names of the excluded totals are listed.
 * Classification, usage, condition, sizes and every other field are kept as reported.
 *
 * @package Site_Bloat_Auditor\Tests
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require_once dirname( __DIR__ ) . '/fixtures/fixture-definitions.php';

/** [ attachment id => key ], [ post id => key ] from the fixture manifest. */
function sba_fixture_scope_maps() {
	static $maps = null;
	if ( null !== $maps ) {
		return $maps;
	}
	$up = wp_upload_dir( null, false );
	$mf = json_decode( (string) @file_get_contents( wp_normalize_path( $up['basedir'] ) . '/' . SBA_FIXTURE_DIR . '/manifest.json' ), true );
	if ( ! is_array( $mf ) ) {
		throw new RuntimeException( 'Fixture manifest not found: run tests/run.ps1 -Fixtures extend first.' );
	}
	$att = [];
	foreach ( (array) $mf['attachments'] as $k => $a ) {
		$att[ (int) $a['id'] ] = $k;
	}
	$posts = [];
	foreach ( (array) $mf['posts'] as $k => $p ) {
		$posts[ (int) $p['id'] ] = $k;
	}
	return $maps = [ $att, $posts ];
}

function sba_fixture_scope_id( $id ) {
	[ $att, $posts ] = sba_fixture_scope_maps();
	$id = (int) $id;
	if ( isset( $att[ $id ] ) ) {
		return '{fx:' . $att[ $id ] . '}';
	}
	if ( isset( $posts[ $id ] ) ) {
		return '{fxpost:' . $posts[ $id ] . '}';
	}
	return $id > 0 ? '{other}' : $id;
}

/** Replace IDs in ID fields and inside text, recursively. */
function sba_fixture_scope_ids( $value, $key = '' ) {
	if ( is_array( $value ) ) {
		foreach ( $value as $k => $v ) {
			$value[ $k ] = sba_fixture_scope_ids( $v, (string) $k );
		}
		return $value;
	}
	$id_keys = [ 'id', 'attachment_id', 'post_id', 'source_id', 'keeper_id', 'parent_id' ];
	if ( in_array( $key, $id_keys, true ) && ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) ) {
		return sba_fixture_scope_id( $value );
	}
	if ( is_string( $value ) ) {
		// "#123" is an ID; "&#039;" (an HTML entity) is not.
		return preg_replace_callback( '/(post=|wp-image-|ID #|(?<!&)#)(\d{2,})\b/', function ( $m ) {
			return $m[1] . sba_fixture_scope_id( $m[2] );
		}, $value );
	}
	return $value;
}

/**
 * Reduce a normalized media-scan response to the fixture scenario.
 *
 * @param array $json Normalized response ({ success, data }).
 * @return array
 */
function sba_fixture_scope_media( array $json ) {
	[ $att ] = sba_fixture_scope_maps();
	$data     = (array) ( $json['data'] ?? [] );
	$is_fx    = function ( $it ) use ( $att ) {
		return isset( $att[ (int) ( $it['id'] ?? $it['attachment_id'] ?? 0 ) ] );
	};
	$key_of   = function ( $it ) use ( $att ) {
		return (string) ( $att[ (int) ( $it['id'] ?? $it['attachment_id'] ?? 0 ) ] ?? '' );
	};
	$sort     = function ( array $items ) use ( $key_of ) {
		usort( $items, function ( $a, $b ) use ( $key_of ) {
			return strcmp( $key_of( $a ), $key_of( $b ) );
		} );
		return $items;
	};

	$out    = [];
	$counts = [];
	foreach ( [ 'all_items', 'unused_items', 'oversized_items', 'optimized_items', 'quarantined_items' ] as $list ) {
		if ( isset( $data[ $list ] ) && is_array( $data[ $list ] ) ) {
			$out[ $list ]    = $sort( array_values( array_filter( $data[ $list ], $is_fx ) ) );
			$counts[ $list ] = count( $out[ $list ] );
		}
	}

	$groups = [];
	foreach ( (array) ( $data['duplicates'] ?? [] ) as $g ) {
		$items = array_values( array_filter( (array) ( $g['items'] ?? [] ), $is_fx ) );
		if ( $items ) {
			$g['items'] = $sort( $items );
			$groups[]   = $g;
		}
	}
	usort( $groups, function ( $a, $b ) use ( $key_of ) {
		return strcmp( $key_of( $a['items'][0] ), $key_of( $b['items'][0] ) );
	} );
	$out['duplicates']    = $groups;
	$counts['duplicates'] = count( $groups );

	$a11y                    = (array) ( $data['accessibility'] ?? [] );
	$out['accessibility']    = [ 'items' => $sort( array_values( array_filter( (array) ( $a11y['items'] ?? [] ), $is_fx ) ) ) ];
	$counts['accessibility'] = count( $out['accessibility']['items'] );

	$disc = [];
	foreach ( (array) ( $data['discrepancies'] ?? [] ) as $type => $rows ) {
		$disc[ $type ] = array_values( array_filter( (array) $rows, function ( $r ) use ( $is_fx ) {
			if ( isset( $r['attachment_id'] ) ) {
				return $is_fx( $r );
			}
			return false !== strpos( (string) ( $r['relative_path'] ?? '' ), SBA_FIXTURE_DIR . '/' );
		} ) );
	}
	$out['discrepancies'] = $disc;
	$out['fixture_counts'] = $counts;

	$excluded = [];
	foreach ( $data as $k => $v ) {
		if ( ! array_key_exists( $k, $out ) && ! is_array( $v ) ) {
			$excluded[] = $k; // Site-wide totals include real uploads: not part of the fixture snapshot.
		} elseif ( ! array_key_exists( $k, $out ) ) {
			$out[ $k ] = $v; // e.g. env_support.
		}
	}
	sort( $excluded );
	$out['site_wide_totals_excluded'] = $excluded;
	ksort( $out );

	return [ 'success' => $json['success'] ?? null, 'data' => sba_fixture_scope_ids( $out ) ];
}
