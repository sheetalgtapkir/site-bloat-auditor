<?php
/**
 * Regression suite: media audit behaviour against the fixture scenario.
 *
 * Asserts the TARGET behaviour (see fixtures/fixture-definitions.php). Against the
 * unmodified 1.1.0 code many checks are expected to FAIL — those failures are the
 * documented defects that Phase 1 and Phase 2 must turn green.
 *
 * Read-only: runs inside a rolled-back transaction.
 *
 * Usage: php test-media-audit.php [--report=path.md]
 *
 * @package Site_Bloat_Auditor\Tests
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require dirname( __DIR__ ) . '/bootstrap.php';
require dirname( __DIR__ ) . '/fixtures/fixture-definitions.php';

$report_path = '';
foreach ( $argv as $a ) {
	if ( 0 === strpos( $a, '--report=' ) ) {
		$report_path = substr( $a, 9 );
	}
}

$uploads  = wp_upload_dir( null, false );
$manifest = wp_normalize_path( $uploads['basedir'] ) . '/' . SBA_FIXTURE_DIR . '/manifest.json';
if ( ! file_exists( $manifest ) ) {
	fwrite( STDERR, "Fixtures not found. Run tests/fixtures/create-fixtures.php first.\n" );
	exit( 2 );
}
$mf = json_decode( (string) file_get_contents( $manifest ), true );

sba_test_login_admin();
$res = sba_test_ajax( 'sba_scan_media_files' );
if ( empty( $res['json']['success'] ) ) {
	fwrite( STDERR, 'Media scan failed: ' . substr( $res['raw'] . $res['exception'], 0, 500 ) . "\n" );
	exit( 2 );
}
$d = $res['json']['data'];

/* -------------------------------------------------- index the response */
$by_id = [];
foreach ( (array) $d['all_items'] as $it ) {
	$by_id[ (int) $it['id'] ] = $it;
}
$filter_of = [];
foreach ( [ 'unused_items' => 'unused', 'oversized_items' => 'oversized', 'optimized_items' => 'optimized' ] as $list => $label ) {
	foreach ( (array) $d[ $list ] as $it ) {
		$filter_of[ (int) $it['id'] ][] = $label;
	}
}
$a11y_issues = [];
foreach ( (array) ( $d['accessibility']['items'] ?? [] ) as $it ) {
	$a11y_issues[ (int) $it['id'] ] = (array) $it['issues'];
}
$missing_ids = array_map( 'intval', array_column( (array) ( $d['discrepancies']['missing_originals'] ?? [] ), 'attachment_id' ) );
$post_title  = [];
foreach ( (array) $mf['posts'] as $pk => $p ) {
	$post_title[ $pk ] = $p['title'];
}

/** Map the current response to the target vocabulary. Updated when Phase 2 adds explicit fields. */
function sba_actual_usage( $item ) {
	if ( ! $item ) {
		return 'absent';
	}
	if ( isset( $item['usage_status'] ) ) {
		return $item['usage_status'];
	}
	if ( 'unused' === $item['category'] ) {
		return 'unused';
	}
	if ( in_array( $item['classification'] ?? '', [ 'requires_manual_review' ], true ) ) {
		return 'review';
	}
	return 'used';
}
function sba_actual_condition( $item, $filters ) {
	if ( ! $item ) {
		return 'absent';
	}
	if ( isset( $item['condition'] ) ) {
		return $item['condition'];
	}
	return implode( '+', $filters ?: [ 'none' ] ); // Current UI: which filter tab shows it.
}

$a11y_map = [ 'missing_alt' => 'missing_alt_attr', 'empty_alt' => 'empty_alt', 'unhelpful_alt' => 'unhelpful_alt', 'undescriptive_filename' => 'undescriptive_filename' ];
$rows     = [];

fwrite( STDOUT, "\n-- Per-fixture expectations --\n" );
foreach ( sba_fixture_attachments() as $key => $def ) {
	$exp  = $def['expect'];
	$aid  = (int) ( $mf['attachments'][ $key ]['id'] ?? 0 );
	$item = $by_id[ $aid ] ?? null;
	$filt = $filter_of[ $aid ] ?? [];
	$au   = sba_actual_usage( $item );
	$ac   = sba_actual_condition( $item, $filt );
	$places = [];
	foreach ( (array) ( $item['usage_places'] ?? [] ) as $pl ) {
		$places[] = $pl['title'] . ' [' . $pl['type'] . '; ' . $pl['confidence'] . ']';
	}
	$results = [];

	$results['usage'] = SBA_Test_Results::check( "$key: usage = {$exp['usage']}", $au === $exp['usage'], "actual: $au" . ( $places ? ' via ' . implode( ' | ', array_unique( $places ) ) : '' ) );

	if ( 'used' === $exp['usage'] && ! empty( $exp['source'] ) ) {
		$want = $post_title[ $exp['source'] ];
		$hit  = false;
		foreach ( (array) ( $item['usage_places'] ?? [] ) as $pl ) {
			$hit = $hit || ( $pl['title'] === $want );
		}
		$results['source'] = SBA_Test_Results::check( "$key: usage location includes \"$want\"", $hit, 'actual: ' . ( $places ? implode( ' | ', array_unique( $places ) ) : 'none' ) );
	}

	$check_condition = 'used' === $exp['usage'] || in_array( $exp['condition'], [ 'oversized', 'optimized', 'n/a' ], true );
	if ( $check_condition && empty( $exp['missing'] ) ) {
		if ( 'n/a' === $exp['condition'] ) {
			$ok = ! in_array( 'optimized', $filt, true ) && ! in_array( 'oversized', $filt, true ) && ( ! isset( $item['condition'] ) || 'n/a' === $item['condition'] );
			$results['condition'] = SBA_Test_Results::check( "$key: non-image is not classified as optimized/oversized", $ok, "actual: $ac" );
		} else {
			$ok = isset( $item['condition'] ) ? $item['condition'] === $exp['condition'] : ( $exp['condition'] === $ac );
			$results['condition'] = SBA_Test_Results::check( "$key: condition = {$exp['condition']}", $ok, "actual: $ac" );
		}
	}

	if ( ! empty( $exp['duplicate'] ) ) {
		$results['duplicate'] = SBA_Test_Results::check( "$key: flagged as SHA-256 duplicate", ! empty( $item['is_duplicate'] ), 'is_duplicate=' . var_export( $item['is_duplicate'] ?? null, true ) );
	}
	if ( ! empty( $exp['a11y'] ) ) {
		$code = $a11y_map[ $exp['a11y'] ];
		$results['a11y'] = SBA_Test_Results::check( "$key: accessibility issue {$exp['a11y']}", in_array( $code, $a11y_issues[ $aid ] ?? [], true ), 'issues: ' . implode( ',', $a11y_issues[ $aid ] ?? [] ) );
	}
	if ( ! empty( $exp['missing'] ) ) {
		$results['missing'] = SBA_Test_Results::check( "$key: listed as missing original", in_array( $aid, $missing_ids, true ) );
	}

	$rows[] = [
		'key'      => $key,
		'file'     => $mf['attachments'][ $key ]['file'] ?? '',
		'expected' => $exp['usage'] . ' / ' . $exp['condition'],
		'actual'   => $au . ' / ' . $ac,
		'class'    => $item['classification'] ?? '-',
		'places'   => $places ? implode( '<br>', array_unique( $places ) ) : '—',
		'ok'       => ! in_array( false, $results, true ),
	];
}

fwrite( STDOUT, "\n-- Filter & metric expectations --\n" );
$unused_fx = 0;
foreach ( sba_fixture_attachments() as $key => $def ) {
	$unused_fx += ( 'unused' === $def['expect']['usage'] ) ? 1 : 0;
}
SBA_Test_Results::check( 'Potentially Reclaimable > 0 MB when unreferenced fixtures exist', (float) $d['potentially_reclaimable_mb'] > 0, "potentially_reclaimable_mb = {$d['potentially_reclaimable_mb']} ($unused_fx fixtures expected unused)" );

$opt_ids   = array_map( 'intval', array_column( (array) $d['optimized_items'], 'id' ) );
$backups   = (array) get_option( 'sba_optimized_images', [] );
$unverified = array_diff( $opt_ids, array_map( 'intval', array_keys( $backups ) ) );
SBA_Test_Results::check( '"Optimized" filter contains only items with verified optimization records', empty( $unverified ), count( $unverified ) . ' of ' . count( $opt_ids ) . ' optimized_items have no record in sba_optimized_images' );

$non_images = [];
foreach ( (array) $d['optimized_items'] as $it ) {
	if ( 0 !== strpos( (string) $it['mime_type'], 'image/' ) ) {
		$non_images[] = $it['name'];
	}
}
SBA_Test_Results::check( '"Optimized" filter contains no PDFs / audio / video', empty( $non_images ), implode( ', ', $non_images ) );

$a11y_sum = (array) ( $d['accessibility']['summary'] ?? [] );
SBA_Test_Results::check( 'Accessibility response reports how many images were audited', isset( $a11y_sum['total_images_audited'] ), 'total_images_audited=' . ( $a11y_sum['total_images_audited'] ?? 'n/a' ) . ', items with any issue=' . count( (array) ( $d['accessibility']['items'] ?? [] ) ) );

$fx_groups = 0;
foreach ( (array) $d['duplicates'] as $g ) {
	foreach ( (array) $g['items'] as $gi ) {
		if ( in_array( (int) $gi['id'], [ (int) ( $mf['attachments']['dup-original']['id'] ?? 0 ) ], true ) ) {
			$fx_groups++;
		}
	}
}
SBA_Test_Results::check( 'dup-original / dup-copy form exactly one duplicate group', 1 === $fx_groups, "groups containing dup-original: $fx_groups" );

$exit = SBA_Test_Results::summary( 'Media audit regression' );

if ( $report_path ) {
	$md  = "# Media audit behaviour — fixture scenario\n\n";
	$md .= 'Generated ' . gmdate( 'Y-m-d H:i' ) . ' UTC against plugin ' . SBA_VERSION . ' (`' . trim( (string) shell_exec( 'git -C ' . escapeshellarg( dirname( __DIR__, 2 ) ) . ' rev-parse --short HEAD' ) ) . "`).\n\n";
	$md .= 'Checks: **' . SBA_Test_Results::$pass . ' passed, ' . SBA_Test_Results::$fail . " failed**. Expected = target behaviour; Actual = current plugin output (usage / filter tab that shows the item).\n\n";
	$md .= "| | Fixture | File | Expected | Actual | Classification | Reported usage locations |\n|---|---|---|---|---|---|---|\n";
	foreach ( $rows as $r ) {
		$md .= '| ' . ( $r['ok'] ? '✅' : '❌' ) . " | {$r['key']} | `{$r['file']}` | {$r['expected']} | {$r['actual']} | {$r['class']} | {$r['places']} |\n";
	}
	$md .= "\n## Failed checks\n\n";
	foreach ( SBA_Test_Results::$failures as $f ) {
		$md .= '- ' . str_replace( '|', '\\|', $f ) . "\n";
	}
	$md .= "\n## Metrics reported\n\n";
	foreach ( [ 'total_media_size_mb', 'potentially_reclaimable_mb', 'estimated_optimization_savings_mb', 'backup_storage_consumed_mb', 'actual_reclaimed_mb', 'total_scanned', 'unused_count', 'oversized_count', 'optimized_count', 'duplicates_count' ] as $k ) {
		$md .= "- `$k`: " . wp_json_encode( $d[ $k ] ?? null ) . "\n";
	}
	file_put_contents( $report_path, $md );
	fwrite( STDOUT, "Report written to $report_path\n" );
}
exit( $exit );
