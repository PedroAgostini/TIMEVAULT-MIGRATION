<?php
/**
 * Restore REST endpoints (double confirmation + rate limiting).
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Rest;

use Timevault\Plugin;
use Timevault\Restore\RateLimiter;

defined( 'ABSPATH' ) || exit;

/**
 * Restore is destructive, so the flow requires TWO authenticated steps:
 *
 *   1. POST /restore/prepare {backup_uuid}
 *        Rate-limited. Validates the package (checksum + full entry
 *        inspection, no writes) and returns a summary plus a short-lived,
 *        HMAC-signed confirmation token bound to that backup.
 *   2. POST /restore/confirm {token, confirm:"RESTORE", restore_files}
 *        Rate-limited. Requires the exact confirmation phrase AND the token
 *        from step 1. Only then is the restore scheduled.
 *
 *   GET /restores, GET /restores/{uuid} - history and status polling.
 *
 * Every step is capability-gated (AbstractController) and audited.
 */
final class RestoreController extends AbstractController {

	/**
	 * Confirmation token lifetime (seconds).
	 */
	private const TOKEN_TTL = 600;

	/**
	 * Exact phrase the client must echo to confirm the destructive action.
	 */
	private const CONFIRM_PHRASE = 'RESTORE';

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Service container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Registers routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/restore/prepare',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'prepare' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'backup_uuid' => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[a-f0-9\-]{36}$',
					),
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/restore/confirm',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'confirm' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'token'         => array(
						'type'     => 'string',
						'required' => true,
					),
					'confirm'       => array(
						'type'     => 'string',
						'required' => true,
					),
					'restore_files' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/restore/relogin/(?P<uuid>[a-f0-9\-]{36})',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'relogin_after_restore' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/restores',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_restores' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/restores/(?P<uuid>[a-f0-9\-]{36})',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_restore' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'advance_restore' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
			)
		);
	}

	/**
	 * POST /restore/prepare - validate + issue confirmation token.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function prepare( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$limited = ( new RateLimiter() )->hit( 'restore_prepare', 10, 600 );

		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$backup_uuid = (string) $request['backup_uuid'];
		$summary     = $this->plugin->imports()->validate_package( $backup_uuid );

		if ( is_wp_error( $summary ) ) {
			return $summary;
		}

		$this->plugin->audit_log()->record( 'restore_prepared', array( 'source_backup' => $backup_uuid ), 'restore', $backup_uuid );

		return rest_ensure_response(
			array(
				'summary'        => $summary,
				'confirm_token'  => $this->issue_token( $backup_uuid ),
				'confirm_phrase' => self::CONFIRM_PHRASE,
				'warning'        => __( 'Restoring overwrites the current site. A full safety backup is taken automatically before anything is changed.', 'timevault' ),
			)
		);
	}

	/**
	 * POST /restore/confirm - verify confirmation, schedule restore.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function confirm( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$limited = ( new RateLimiter() )->hit( 'restore_confirm', 3, 600 );

		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		if ( ! hash_equals( self::CONFIRM_PHRASE, (string) $request['confirm'] ) ) {
			return new \WP_Error( 'timevault_restore_unconfirmed', __( 'The confirmation phrase does not match.', 'timevault' ), array( 'status' => 400 ) );
		}

		$backup_uuid = $this->verify_token( (string) $request['token'] );

		if ( is_wp_error( $backup_uuid ) ) {
			return $backup_uuid;
		}

		$uuid = $this->plugin->imports()->schedule_restore(
			$backup_uuid,
			array(
				'restore_files'  => (bool) $request['restore_files'],
				'preserve_admin' => true,
			)
		);

		if ( is_wp_error( $uuid ) ) {
			return $uuid;
		}

		$response = rest_ensure_response(
			array(
				'restore_uuid' => $uuid,
				'status'       => 'pending',
			)
		);
		$response->set_status( 202 );

		return $response;
	}

	/**
	 * GET /restore/relogin/{uuid} - exchanges a one-time signed import token
	 * for a fresh WordPress auth cookie after the database has been replaced.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function relogin_after_restore( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$uuid  = (string) $request['uuid'];
		$token = (string) $request['token'];
		$row   = $this->plugin->restore_repository()->get( $uuid );

		if ( null === $row ) {
			return new \WP_Error( 'timevault_not_found', __( 'Restore not found.', 'timevault' ), array( 'status' => 404 ) );
		}

		$options = (array) ( $row['meta']['options'] ?? array() );
		$hash    = (string) ( $options['admin_relogin_hash'] ?? '' );
		$login   = (string) ( $options['preserve_admin_login'] ?? '' );

		if ( '' === $token || '' === $hash || '' === $login ) {
			return new \WP_Error( 'timevault_relogin_unavailable', __( 'Relogin is not available for this restore.', 'timevault' ), array( 'status' => 403 ) );
		}

		$expected = hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );

		if ( ! hash_equals( $hash, $expected ) ) {
			return new \WP_Error( 'timevault_relogin_bad_token', __( 'The relogin token is invalid.', 'timevault' ), array( 'status' => 403 ) );
		}

		$user = get_user_by( 'login', $login );

		if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
			return new \WP_Error( 'timevault_relogin_user_missing', __( 'The preserved administrator was not found.', 'timevault' ), array( 'status' => 404 ) );
		}

		wp_set_current_user( (int) $user->ID );
		wp_set_auth_cookie( (int) $user->ID, true, is_ssl() );

		unset( $options['admin_relogin_hash'] );
		$meta            = (array) $row['meta'];
		$meta['options'] = $options;
		$this->plugin->restore_repository()->update( $uuid, array( 'meta' => $meta ) );

		return rest_ensure_response(
			array(
				'ok'        => true,
				'admin_url' => admin_url(),
			)
		);
	}

	/**
	 * GET /restores
	 */
	public function list_restores(): \WP_REST_Response {
		$rows = $this->plugin->restore_repository()->list_restores( 20 );

		return rest_ensure_response( array_map( array( $this, 'prepare_row' ), $rows ) );
	}

	/**
	 * GET /restores/{uuid}
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_restore( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$row = $this->plugin->restore_repository()->get( (string) $request['uuid'] );

		if ( null === $row ) {
			return new \WP_Error( 'timevault_not_found', __( 'Restore not found.', 'timevault' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $this->prepare_row( $row ) );
	}

	/**
	 * POST /restores/{uuid} - advances one restore step without relying on cron.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function advance_restore( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$uuid = (string) $request['uuid'];
		$row  = $this->plugin->restore_repository()->get( $uuid );

		if ( null === $row ) {
			return new \WP_Error( 'timevault_not_found', __( 'Restore not found.', 'timevault' ), array( 'status' => 404 ) );
		}

		if ( in_array( (string) $row['status'], array( 'completed', 'failed' ), true ) ) {
			return rest_ensure_response( $this->prepare_row( $row ) );
		}

		$lock = $this->runner_lock_key( $uuid );

		if ( ! get_transient( $lock ) ) {
			set_transient( $lock, 1, 30 * MINUTE_IN_SECONDS );

			$step = '' !== (string) $row['step'] ? (string) $row['step'] : 'safety_backup';
			$this->plugin->restore_repository()->update(
				$uuid,
				array(
					'status' => 'running',
					'step'   => $step,
				)
			);

			$this->run_restore_step_after_response( $uuid, $lock );
		}

		$current  = $this->plugin->restore_repository()->get( $uuid ) ?? $row;
		$response = rest_ensure_response( $this->prepare_row( $current ) );
		$response->set_status( 202 );

		return $response;
	}

	/**
	 * Issues an HMAC-signed confirmation token bound to a backup.
	 *
	 * @param string $backup_uuid Backup identifier.
	 */
	private function issue_token( string $backup_uuid ): string {
		$payload = $backup_uuid . '|' . ( time() + self::TOKEN_TTL ) . '|' . wp_generate_password( 12, false );

		return rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' ) . '.' . hash_hmac( 'sha256', $payload, wp_salt( 'nonce' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe token encoding.
	}

	/**
	 * Verifies a confirmation token (constant-time), returns the bound backup.
	 *
	 * @param string $token Raw token.
	 * @return string|\WP_Error Backup UUID or error.
	 */
	private function verify_token( string $token ): string|\WP_Error {
		$invalid = new \WP_Error( 'timevault_restore_bad_token', __( 'Invalid or expired confirmation token. Start the restore again.', 'timevault' ), array( 'status' => 400 ) );
		$parts   = explode( '.', $token );

		if ( 2 !== count( $parts ) ) {
			return $invalid;
		}

		$payload = base64_decode( strtr( $parts[0], '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Token decoding.

		if ( false === $payload || ! hash_equals( hash_hmac( 'sha256', $payload, wp_salt( 'nonce' ) ), $parts[1] ) ) {
			return $invalid;
		}

		$fields = explode( '|', $payload );

		if ( 3 !== count( $fields ) || time() > (int) $fields[1] ) {
			return $invalid;
		}

		return $fields[0];
	}

	/**
	 * Lets a manually advanced restore step finish even on large sites.
	 */
	private function prepare_long_restore_runtime(): void {
		wp_raise_memory_limit( 'admin' );

		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- User-triggered restore step for large migrations.
			@set_time_limit( 0 );
		}
	}

	/**
	 * Runs the heavy restore work after the REST response is flushed.
	 *
	 * @param string $uuid Restore UUID.
	 * @param string $lock Transient lock key.
	 */
	private function run_restore_step_after_response( string $uuid, string $lock ): void {
		register_shutdown_function(
			function () use ( $uuid, $lock ): void {
				if ( function_exists( 'ignore_user_abort' ) ) {
					ignore_user_abort( true );
				}

				if ( function_exists( 'fastcgi_finish_request' ) ) {
					fastcgi_finish_request();
				}

				$this->prepare_long_restore_runtime();

				try {
					$this->plugin->imports()->advance_restore( $uuid );
				} finally {
					delete_transient( $lock );
				}
			}
		);
	}

	/**
	 * Transient key for a background restore runner.
	 *
	 * @param string $uuid Restore UUID.
	 */
	private function runner_lock_key( string $uuid ): string {
		return 'timevault_restore_runner_' . hash( 'sha256', $uuid );
	}

	/**
	 * Public shape of a restore row (no internal working paths).
	 *
	 * @param array<string, mixed> $row Restore row.
	 * @return array<string, mixed>
	 */
	private function prepare_row( array $row ): array {
		return array(
			'uuid'          => (string) $row['restore_uuid'],
			'source_backup' => (string) $row['source_backup_uuid'],
			'safety_backup' => (string) $row['safety_backup_uuid'],
			'status'        => (string) $row['status'],
			'step'          => (string) $row['step'],
			'created_at'    => (string) $row['created_at'],
			'completed_at'  => (string) ( $row['completed_at'] ?? '' ),
			'db'            => $row['meta']['db'] ?? null,
			'files_copied'  => $row['meta']['files_copied'] ?? null,
			'error'         => isset( $row['meta']['error'] ) ? (string) $row['meta']['error'] : null,
		);
	}
}
