<?php
/**
 * Security suite: every sba_* AJAX endpoint must verify the nonce and require
 * manage_options before doing any work.
 *
 * Step 1 (static): the handler's own source must call check_ajax_referer() and
 *        current_user_can() before any other statement that does work.
 * Step 2 (runtime, only for handlers that passed step 1):
 *        a) administrator WITHOUT a nonce   -> rejected by check_ajax_referer (-1 / 403)
 *        b) subscriber WITH a valid nonce   -> JSON error (capability check)
 *        Requests carry no IDs, so even a regression cannot touch real data.
 * Step 3: no wp_ajax_nopriv_sba_* endpoints may exist.
 *
 * Read-only: runs inside a rolled-back transaction (the subscriber is temporary).
 *
 * @package Site_Bloat_Auditor\Tests
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require dirname( __DIR__ ) . '/bootstrap.php';

global $wp_filter;

$actions = [];
foreach ( array_keys( $wp_filter ) as $hook ) {
	if ( 0 === strpos( $hook, 'wp_ajax_sba_' ) ) {
		$actions[ substr( $hook, 8 ) ] = $wp_filter[ $hook ];
	}
	if ( 0 === strpos( $hook, 'wp_ajax_nopriv_sba_' ) ) {
		SBA_Test_Results::check( "no public endpoint $hook", false, 'nopriv handler registered' );
	}
}
ksort( $actions );
fwrite( STDOUT, count( $actions ) . " sba_* AJAX actions registered\n" );

/** Return the callbacks attached to an action, plus the function source. */
function sba_sec_callbacks( WP_Hook $hook ) {
	$out = [];
	foreach ( $hook->callbacks as $prio => $cbs ) {
		foreach ( $cbs as $cb ) {
			$fn = $cb['function'];
			if ( is_string( $fn ) && function_exists( $fn ) ) {
				$rf    = new ReflectionFunction( $fn );
				$lines = file( $rf->getFileName() );
				$body  = implode( '', array_slice( $lines, $rf->getStartLine() - 1, $rf->getEndLine() - $rf->getStartLine() + 1 ) );
				$out[] = [ 'name' => $fn, 'body' => $body, 'prio' => $prio ];
			} else {
				$out[] = [ 'name' => is_string( $fn ) ? $fn : 'closure/method', 'body' => '', 'prio' => $prio ];
			}
		}
	}
	return $out;
}

/** Statement-level check: nonce + capability come before any $wpdb / filesystem / option writes. */
function sba_sec_static_ok( $body, &$why ) {
	// A wrapper that only delegates to another sba_ajax_* handler inherits its checks.
	if ( preg_match( '/\{\s*(sba_ajax_\w+)\s*\(\s*\)\s*;\s*\}\s*$/s', $body, $m ) && function_exists( $m[1] ) ) {
		$rf    = new ReflectionFunction( $m[1] );
		$lines = file( $rf->getFileName() );
		$body  = implode( '', array_slice( $lines, $rf->getStartLine() - 1, $rf->getEndLine() - $rf->getStartLine() + 1 ) );
		$why   = "delegates to {$m[1]}; ";
	}
	// Ignore the signature line (function names such as sba_ajax_bulk_deactivate_plugins
	// would otherwise match the "work" needles below).
	$body  = substr( $body, (int) strpos( $body, '{' ) );
	$nonce = strpos( $body, 'check_ajax_referer' );
	$cap   = strpos( $body, "current_user_can( 'manage_options' )" );
	if ( false === $cap ) {
		$cap = strpos( $body, 'current_user_can(' );
	}
	if ( false === $nonce || false === $cap ) {
		$why .= 'missing ' . ( false === $nonce ? 'check_ajax_referer ' : '' ) . ( false === $cap ? 'current_user_can' : '' );
		return false;
	}
	$first_work = PHP_INT_MAX;
	// Needles are calls / superglobal reads, so capability strings like 'delete_plugins' don't match.
	foreach ( [ '$wpdb->', 'update_option(', 'delete_option(', 'unlink(', 'rename(', 'wp_delete_', 'deactivate_plugins(', 'delete_plugins(', '$_POST', '$_GET', '$_REQUEST' ] as $needle ) {
		$p = strpos( $body, $needle );
		if ( false !== $p ) {
			$first_work = min( $first_work, $p );
		}
	}
	if ( $nonce > $first_work || $cap > $first_work ) {
		$why .= 'nonce/capability check appears after request handling or writes';
		return false;
	}
	$why .= 'nonce@' . $nonce . ' cap@' . $cap . ' before first work@' . ( PHP_INT_MAX === $first_work ? '-' : $first_work );
	return true;
}

$admin_id = sba_test_login_admin();
$sub_id   = wp_insert_user( [ 'user_login' => 'sba_sec_sub_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ] );
if ( is_wp_error( $sub_id ) ) {
	fwrite( STDERR, 'Could not create temporary subscriber: ' . $sub_id->get_error_message() . "\n" );
	exit( 2 );
}

foreach ( $actions as $action => $hook ) {
	fwrite( STDOUT, "\n$action\n" );
	$cbs = sba_sec_callbacks( $hook );
	if ( count( $cbs ) > 1 ) {
		fwrite( STDOUT, '  NOTE: ' . count( $cbs ) . ' callbacks registered: ' . implode( ', ', array_column( $cbs, 'name' ) ) . "\n" );
	}
	$static_ok = true;
	foreach ( $cbs as $cb ) {
		$why = '';
		$ok  = $cb['body'] && sba_sec_static_ok( $cb['body'], $why );
		SBA_Test_Results::check( "$action: {$cb['name']} checks nonce + capability before doing work", $ok, $why );
		$static_ok = $static_ok && $ok;
	}
	if ( ! $static_ok ) {
		fwrite( STDOUT, "  (runtime calls skipped for safety)\n" );
		continue;
	}

	wp_set_current_user( $admin_id );
	$r = sba_test_ajax( $action, [], false );
	SBA_Test_Results::check( "$action: admin without nonce is rejected", null !== $r['die'] && ( -1 === $r['die'] || '-1' === (string) $r['die'] ) && empty( $r['json']['success'] ), 'die=' . wp_json_encode( $r['die'] ) . ' raw=' . substr( $r['raw'], 0, 120 ) . $r['exception'] );

	wp_set_current_user( $sub_id );
	$r = sba_test_ajax( $action, [], true );
	// Refusal may be a JSON error or a plain wp_die() (download endpoints such as the DB export).
	$json_refusal = is_array( $r['json'] ) && false === $r['json']['success'];
	$die_refusal  = ! is_array( $r['json'] ) && null !== $r['die'] && '' === trim( $r['raw'] ) && '' === $r['exception'];
	SBA_Test_Results::check( "$action: subscriber with valid nonce is refused", $json_refusal || $die_refusal, 'raw=' . substr( $r['raw'], 0, 160 ) . ' die=' . wp_json_encode( $r['die'] ) . $r['exception'] );
	if ( $die_refusal ) {
		fwrite( STDOUT, '  NOTE: refused via wp_die(' . wp_json_encode( $r['die'] ) . ") rather than a JSON error\n" );
	}
}

wp_set_current_user( $admin_id );
exit( SBA_Test_Results::summary( 'AJAX security' ) );
