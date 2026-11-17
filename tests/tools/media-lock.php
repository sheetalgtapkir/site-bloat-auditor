<?php
/**
 * Engage, lift or inspect the emergency lock on destructive media actions.
 *
 *   php media-lock.php status
 *   php media-lock.php on "reason"     Refuse quarantine / permanent delete / thumbnail pruning.
 *   php media-lock.php off             Lift the lock (the per-item safety checks stay active).
 *
 * Writes only the option sba_destructive_media_lock.
 *
 * @package Site_Bloat_Auditor\Tests
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

$mode = $argv[1] ?? 'status';
if ( 'status' !== $mode ) {
	define( 'SBA_TEST_ROLLBACK', false ); // The lock must persist.
}
require dirname( __DIR__ ) . '/bootstrap.php';

if ( 'on' === $mode ) {
	SBA_Media_Safety::set_lock( true, $argv[2] ?? 'Locked by the development team.' );
} elseif ( 'off' === $mode ) {
	SBA_Media_Safety::set_lock( false );
} elseif ( 'status' !== $mode ) {
	fwrite( STDERR, "Usage: php media-lock.php status|on [reason]|off\n" );
	exit( 2 );
}

$lock = get_option( SBA_Media_Safety::LOCK_OPTION, [] );
fwrite( STDOUT, 'Destructive media actions: ' . ( SBA_Media_Safety::is_locked() ? 'LOCKED' : 'allowed (per-item safety checks active)' ) .
	( ! empty( $lock['since'] ) ? ' since ' . gmdate( 'Y-m-d H:i:s', (int) $lock['since'] ) . ' UTC' : '' ) .
	( ! empty( $lock['reason'] ) ? ' — ' . $lock['reason'] : '' ) . "\n" );
exit( 0 );
