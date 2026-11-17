<?php
/**
 * Safety gate for every destructive media action (quarantine, permanent delete, emptying the
 * quarantine, thumbnail pruning).
 *
 * The server never trusts a classification sent by the browser: immediately before a file is
 * moved or deleted, the attachment's usage is re-checked against a freshly rebuilt reference
 * index, inside a site-wide mutex so concurrent requests cannot interleave.
 *
 *   In Use  (confirmed / likely references) -> refused (quarantine and delete).
 *   Review  (only weak evidence)            -> refused unless the request explicitly confirms review.
 *   Unused  (no references)                 -> allowed (the handler's own confirmation still applies).
 *
 * Also:
 *   - Emergency lock (option sba_destructive_media_lock, or constant
 *     SBA_DISABLE_DESTRUCTIVE_MEDIA_ACTIONS): refuses every destructive media action while the
 *     audit and reporting keep working.
 *   - Test fixtures (post meta _sba_test_fixture = 1) cannot be quarantined or deleted from the
 *     admin; they are removed only by tests/fixtures/cleanup-fixtures.php.
 *
 * @package Site_Bloat_Auditor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SBA_Media_Safety {

	const LOCK_OPTION    = 'sba_destructive_media_lock';
	const MUTEX_OPTION   = 'sba_media_operation_mutex';
	const MUTEX_TTL      = 120;
	const FIXTURE_META   = '_sba_test_fixture';

	/** @var bool Whether this request already rebuilt the reference index. */
	private static $index_fresh = false;

	/** @var bool Whether this request holds the mutex. */
	private static $holds_mutex = false;

	/**
	 * Protect regression-test fixtures (posts and attachments tagged _sba_test_fixture) from
	 * being trashed or deleted by anything outside their test lifecycle — including the core
	 * Media Library and Posts screens. tests/fixtures/*.php define
	 * SBA_ALLOW_TEST_FIXTURE_MEDIA_ACTIONS to manage them. Untagged content is never affected.
	 */
	public static function register_hooks() {
		$guard = static function ( $check, $post ) {
			if ( null !== $check || ! ( $post instanceof WP_Post ) ) {
				return $check;
			}
			if ( self::is_test_fixture( $post->ID ) && ! ( defined( 'SBA_ALLOW_TEST_FIXTURE_MEDIA_ACTIONS' ) && SBA_ALLOW_TEST_FIXTURE_MEDIA_ACTIONS ) ) {
				return false; // Short-circuit: not deleted / not trashed.
			}
			return $check;
		};
		add_filter( 'pre_delete_attachment', $guard, 10, 2 );
		add_filter( 'pre_delete_post', $guard, 10, 2 );
		add_filter( 'pre_trash_post', $guard, 10, 2 );
	}

	/**
	 * Whether destructive media actions are disabled site-wide.
	 *
	 * @return bool
	 */
	public static function is_locked() {
		if ( defined( 'SBA_DISABLE_DESTRUCTIVE_MEDIA_ACTIONS' ) && SBA_DISABLE_DESTRUCTIVE_MEDIA_ACTIONS ) {
			return true;
		}
		$lock = get_option( self::LOCK_OPTION, [] );
		return is_array( $lock ) && ! empty( $lock['locked'] );
	}

	/**
	 * Engage or lift the emergency lock.
	 *
	 * @param bool   $locked Lock state.
	 * @param string $reason Why (shown to administrators).
	 */
	public static function set_lock( $locked, $reason = '' ) {
		if ( $locked ) {
			update_option( self::LOCK_OPTION, [ 'locked' => true, 'reason' => sanitize_text_field( $reason ), 'since' => time() ], false );
		} else {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * Begin a destructive media request: refuse when locked, then take the mutex.
	 * Sends a JSON error and exits when the request must not proceed.
	 */
	public static function begin_destructive_request() {
		if ( self::is_locked() ) {
			$lock = (array) get_option( self::LOCK_OPTION, [] );
			wp_send_json_error( sprintf(
				/* translators: %s: reason */
				esc_html__( 'Destructive media actions (quarantine, permanent deletion, thumbnail pruning) are temporarily disabled on this site. %s', 'site-bloat-auditor' ),
				! empty( $lock['reason'] ) ? $lock['reason'] : ''
			) );
		}
		if ( ! self::acquire_mutex() ) {
			wp_send_json_error( esc_html__( 'Another media cleanup operation is in progress. Wait for it to finish, then re-scan and try again.', 'site-bloat-auditor' ) );
		}
		self::$index_fresh = false; // Every destructive request re-checks usage against a newly built index.
	}

	/**
	 * Take the site-wide mutex (atomic: add_option fails when the row exists).
	 *
	 * @return bool
	 */
	public static function acquire_mutex() {
		if ( self::$holds_mutex ) {
			return true;
		}
		if ( ! add_option( self::MUTEX_OPTION, time() + self::MUTEX_TTL, '', 'no' ) ) {
			$expires = (int) get_option( self::MUTEX_OPTION, 0 );
			if ( $expires > time() ) {
				return false;
			}
			// Stale mutex from a request that died: replace it.
			delete_option( self::MUTEX_OPTION );
			if ( ! add_option( self::MUTEX_OPTION, time() + self::MUTEX_TTL, '', 'no' ) ) {
				return false;
			}
		}
		self::$holds_mutex = true;
		register_shutdown_function( [ __CLASS__, 'release_mutex' ] );
		return true;
	}

	/** Release the mutex held by this request. */
	public static function release_mutex() {
		if ( self::$holds_mutex ) {
			delete_option( self::MUTEX_OPTION );
			self::$holds_mutex = false;
		}
	}

	/**
	 * Whether an attachment is a regression-test fixture.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function is_test_fixture( $attachment_id ) {
		return '1' === (string) get_post_meta( (int) $attachment_id, self::FIXTURE_META, true );
	}

	/**
	 * Fresh usage of one attachment, independent of any earlier scan or browser state.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{status:string,classification:string,references:array}
	 */
	public static function fresh_usage( $attachment_id ) {
		$post = get_post( (int) $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return [ 'status' => 'missing', 'classification' => '', 'references' => [] ];
		}
		if ( ! self::$index_fresh && class_exists( 'SBA_Image_Reference_Index' ) ) {
			SBA_Image_Reference_Index::invalidate_cache(); // Rebuilt once per request, never reused from a cache.
			self::$index_fresh = true;
		}
		$detector = new SBA_Usage_Detector();
		$result   = $detector->scan_references( [ $post ] );
		$usage    = $result[ (int) $attachment_id ] ?? [ 'classification' => 'no_references_found', 'references' => [] ];
		$refs     = (array) ( $usage['references'] ?? [] );
		$status   = function_exists( 'sba_media_usage_status' ) ? sba_media_usage_status( (string) $usage['classification'], ! empty( $refs ) ) : 'used';
		return [ 'status' => $status, 'classification' => (string) $usage['classification'], 'references' => $refs ];
	}

	/**
	 * Decide whether a destructive operation may run on an attachment right now.
	 *
	 * @param int    $attachment_id  Attachment ID.
	 * @param string $operation      quarantine | delete | delete_quarantined | prune_thumbnail.
	 * @param bool   $confirm_review Request explicitly confirmed that "Review" items were verified.
	 * @return true|WP_Error
	 */
	public static function check( $attachment_id, $operation, $confirm_review = false ) {
		$attachment_id = (int) $attachment_id;

		if ( self::is_test_fixture( $attachment_id ) && ! ( defined( 'SBA_ALLOW_TEST_FIXTURE_MEDIA_ACTIONS' ) && SBA_ALLOW_TEST_FIXTURE_MEDIA_ACTIONS ) ) {
			return new WP_Error( 'sba_fixture_protected', sprintf(
				/* translators: %d: attachment ID */
				esc_html__( 'Attachment #%d is a Site Bloat Auditor regression-test fixture and can only be removed by the plugin\'s test suite.', 'site-bloat-auditor' ),
				$attachment_id
			) );
		}

		$usage = self::fresh_usage( $attachment_id );
		if ( 'missing' === $usage['status'] ) {
			return true; // Nothing live to protect; the handler reports missing records itself.
		}

		if ( 'used' === $usage['status'] ) {
			$where = [];
			foreach ( $usage['references'] as $ref ) {
				$where[ (string) ( $ref['source_title'] ?? '' ) ] = true;
				if ( count( $where ) >= 3 ) {
					break;
				}
			}
			return new WP_Error( 'sba_in_use', sprintf(
				/* translators: 1: attachment ID, 2: where it is used */
				esc_html__( 'Blocked: attachment #%1$d is In Use (fresh check just now: %2$s). In-use media cannot be quarantined or deleted.', 'site-bloat-auditor' ),
				$attachment_id,
				implode( ', ', array_filter( array_keys( $where ) ) ) ?: esc_html__( 'referenced', 'site-bloat-auditor' )
			) );
		}

		if ( 'review' === $usage['status'] && ! $confirm_review ) {
			return new WP_Error( 'sba_review_unconfirmed', sprintf(
				/* translators: %d: attachment ID */
				esc_html__( 'Blocked: attachment #%d needs review (only unverified, draft or trashed references). Confirm that you verified it before removing it.', 'site-bloat-auditor' ),
				$attachment_id
			) );
		}

		return true;
	}

	/**
	 * Whether the current request explicitly confirms "Review" items.
	 *
	 * @return bool
	 */
	public static function request_confirms_review() {
		return isset( $_POST['confirm_review'] ) && 'yes' === sanitize_text_field( wp_unslash( $_POST['confirm_review'] ) ); // phpcs:ignore WordPress.Security.NonceVerification -- nonce checked by the calling handler.
	}
}
