<?php
/**
 * Site Bloat Auditor — CLI test bootstrap.
 *
 * NOT part of the plugin release (see .distignore / .gitattributes).
 *
 * Loads WordPress in an admin-ajax-equivalent context so the plugin's real AJAX
 * handlers can be exercised from the command line.
 *
 * Environment / constants a test script may define BEFORE requiring this file:
 *   SBA_TEST_ROLLBACK  (bool, default true)  Wrap all DB work in a transaction that is
 *                                             rolled back at shutdown + verify checksums.
 *   SBA_TEST_WP_LOAD   (string)              Path to wp-load.php (default: 4 levels up).
 *
 * Outbound HTTP is always blocked so results are deterministic and no live service
 * is contacted.
 *
 * @package Site_Bloat_Auditor\Tests
 */

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

error_reporting( E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING );

if ( ! defined( 'SBA_TEST_ROLLBACK' ) ) {
	define( 'SBA_TEST_ROLLBACK', true );
}
if ( ! defined( 'SBA_TEST_WP_LOAD' ) ) {
	define( 'SBA_TEST_WP_LOAD', dirname( __DIR__, 4 ) . '/wp-load.php' );
}
define( 'SBA_TESTS_DIR', __DIR__ );

define( 'WP_USE_THEMES', false );
define( 'DISABLE_WP_CRON', true );
define( 'DOING_AJAX', true );
// Request host for the CLI context: $env:SBA_TEST_HOST, else "localhost". WordPress takes
// home/site URLs from the database, so no site name needs to be hard-coded here.
$sba_test_host              = getenv( 'SBA_TEST_HOST' ) ?: 'localhost';
$_SERVER['HTTP_HOST']       = $_SERVER['HTTP_HOST'] ?? $sba_test_host;
$_SERVER['SERVER_NAME']     = $_SERVER['SERVER_NAME'] ?? $sba_test_host;
$_SERVER['REQUEST_URI']     = '/wp-admin/admin-ajax.php';
$_SERVER['REQUEST_METHOD']  = 'POST';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

/** Thrown by the overridden wp_die() handlers so a handler's JSON output can be captured. */
class SBA_Test_Die extends Exception {
	public $die_message;
	public $die_status;
	public function __construct( $message, $status = null ) {
		$this->die_message = $message;
		$this->die_status  = $status;
		parent::__construct( is_scalar( $message ) ? (string) $message : 'wp_die' );
	}
}

$GLOBALS['sba_test_tx'] = [ 'active' => false, 'before' => [] ];

function sba_test_table_checksums() {
	global $wpdb;
	$out = [];
	foreach ( [ $wpdb->posts, $wpdb->postmeta, $wpdb->options, $wpdb->users, $wpdb->usermeta ] as $t ) {
		$row       = $wpdb->get_row( "CHECKSUM TABLE `$t`", ARRAY_A );
		$out[ $t ] = $row['Checksum'] ?? 'n/a';
	}
	return $out;
}

function sba_test_begin_transaction() {
	global $wpdb;
	if ( ! SBA_TEST_ROLLBACK ) {
		return;
	}
	$GLOBALS['sba_test_tx']['before'] = sba_test_table_checksums();
	$wpdb->query( 'SET autocommit = 0' );
	$wpdb->query( 'START TRANSACTION' );
	$GLOBALS['sba_test_tx']['active'] = true;
}

// Start the transaction before any plugin code runs (pre-initialised hook).
$GLOBALS['wp_filter'] = [
	'muplugins_loaded' => [
		-99999 => [
			'sba_test_begin_transaction' => [ 'function' => 'sba_test_begin_transaction', 'accepted_args' => 0 ],
		],
	],
];

register_shutdown_function( function () {
	global $wpdb;
	if ( empty( $GLOBALS['sba_test_tx']['active'] ) || ! $wpdb ) {
		return;
	}
	$wpdb->query( 'ROLLBACK' );
	$wpdb->query( 'SET autocommit = 1' );
	$after   = sba_test_table_checksums();
	$changed = [];
	foreach ( $GLOBALS['sba_test_tx']['before'] as $t => $c ) {
		if ( (string) $c !== (string) ( $after[ $t ] ?? '' ) ) {
			$changed[] = $t;
		}
	}
	fwrite( STDOUT, "\n[read-only] Transaction rolled back. Table checksums: "
		. ( $changed ? 'CHANGED: ' . implode( ', ', $changed ) . ' (check for concurrent site traffic)' : 'all unchanged' ) . "\n" );
} );

if ( ! file_exists( SBA_TEST_WP_LOAD ) ) {
	fwrite( STDERR, 'wp-load.php not found at ' . SBA_TEST_WP_LOAD . "\n" );
	exit( 2 );
}
require SBA_TEST_WP_LOAD;
require_once ABSPATH . 'wp-admin/includes/admin.php'; // Same includes admin-ajax.php provides.

// Deterministic, offline: block every outbound HTTP request.
add_filter( 'pre_http_request', function () {
	return new WP_Error( 'sba_test_http_blocked', 'Outbound HTTP blocked by SBA test harness.' );
}, PHP_INT_MAX );

// Capture wp_die() from wp_send_json*() and check_ajax_referer().
$sba_die_handler = function () {
	return function ( $message = '', $title = '', $args = [] ) {
		$status = is_array( $args ) && isset( $args['response'] ) ? $args['response'] : ( is_int( $title ) ? $title : null );
		throw new SBA_Test_Die( $message, $status );
	};
};
add_filter( 'wp_die_ajax_handler', $sba_die_handler, PHP_INT_MAX );
add_filter( 'wp_die_handler', $sba_die_handler, PHP_INT_MAX );

if ( ! defined( 'SBA_VERSION' ) ) {
	fwrite( STDERR, "Site Bloat Auditor is not active on this site.\n" );
	exit( 2 );
}
if ( SBA_TEST_ROLLBACK && empty( $GLOBALS['sba_test_tx']['active'] ) ) {
	fwrite( STDERR, "Could not start the safety transaction; aborting.\n" );
	exit( 2 );
}

// Environment banner: proves which install, database and plugin copy this run uses.
fwrite( STDOUT, sprintf(
	"[env] site=%s | ABSPATH=%s | db=%s@%s:%s | plugin=%s (v%s) | mode=%s\n",
	home_url(),
	wp_normalize_path( ABSPATH ),
	DB_NAME,
	DB_HOST,
	$GLOBALS['wpdb']->get_var( 'SELECT @@port' ),
	wp_normalize_path( SBA_PLUGIN_DIR ),
	SBA_VERSION,
	SBA_TEST_ROLLBACK ? 'READ-ONLY (rollback)' : 'WRITE'
) );
if ( 0 !== stripos( wp_normalize_path( SBA_PLUGIN_DIR ), wp_normalize_path( dirname( __DIR__ ) ) ) ) {
	fwrite( STDERR, "Loaded plugin copy is not the one containing these tests; aborting.\n" );
	exit( 2 );
}

/* ------------------------------------------------------------------ helpers */

/** Log in as the first administrator (or a given user ID). */
function sba_test_login_admin( $user_id = 0 ) {
	if ( ! $user_id ) {
		$admins  = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID' ] );
		$user_id = (int) ( $admins[0] ?? 0 );
	}
	wp_set_current_user( $user_id );
	return $user_id;
}

/**
 * Call a registered wp_ajax_ action exactly like admin-ajax.php would.
 *
 * @param string      $action Action name without the wp_ajax_ prefix.
 * @param array       $post   POST fields.
 * @param bool|string $nonce  true = valid sba_nonce, false = none, string = literal value.
 * @return array { raw: string, json: array|null, die: mixed, status: mixed, exception: string }
 */
function sba_test_ajax( $action, array $post = [], $nonce = true ) {
	if ( true === $nonce ) {
		$post['_ajax_nonce'] = wp_create_nonce( 'sba_nonce' );
	} elseif ( is_string( $nonce ) ) {
		$post['_ajax_nonce'] = $nonce;
	}
	$post['action'] = $action;
	$_POST          = $post;
	$_GET           = [];
	$_REQUEST       = $post;

	$result = [ 'raw' => '', 'json' => null, 'die' => null, 'status' => null, 'exception' => '' ];
	$level  = ob_get_level();
	ob_start();
	try {
		if ( ! has_action( 'wp_ajax_' . $action ) ) {
			throw new RuntimeException( "Action wp_ajax_{$action} is not registered." );
		}
		do_action( 'wp_ajax_' . $action );
	} catch ( SBA_Test_Die $e ) {
		$result['die']    = $e->die_message;
		$result['status'] = $e->die_status;
	} catch ( Throwable $t ) {
		$result['exception'] = get_class( $t ) . ': ' . $t->getMessage();
	}
	$out = '';
	while ( ob_get_level() > $level ) {
		$out = ob_get_clean() . $out;
	}
	$result['raw']  = $out;
	$result['json'] = json_decode( $out, true );
	return $result;
}

/** Minimal assertion collector shared by all suites. */
class SBA_Test_Results {
	public static $pass = 0;
	public static $fail = 0;
	public static $failures = [];

	public static function check( $name, $condition, $detail = '' ) {
		if ( $condition ) {
			self::$pass++;
			fwrite( STDOUT, "  [PASS] $name\n" );
		} else {
			self::$fail++;
			self::$failures[] = $name . ( $detail ? " — $detail" : '' );
			fwrite( STDOUT, "  [FAIL] $name" . ( $detail ? "\n         $detail" : '' ) . "\n" );
		}
		return (bool) $condition;
	}

	public static function summary( $suite ) {
		$total = self::$pass + self::$fail;
		fwrite( STDOUT, "\n== $suite: " . self::$pass . "/$total passed" . ( self::$fail ? ', ' . self::$fail . ' FAILED' : '' ) . " ==\n" );
		return 0 === self::$fail ? 0 : 1;
	}
}
