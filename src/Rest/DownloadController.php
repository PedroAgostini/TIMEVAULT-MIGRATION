<?php
/**
 * Authenticated backup download with short-lived signed tokens.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Rest;

use Timevault\Plugin;
use Timevault\Storage\LocalAdapter;
use Timevault\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * Backups are NEVER exposed as direct public links. Downloading takes two
 * steps:
 *
 *   1. POST /timevault/v1/backups/{uuid}/download-token - capability-gated;
 *      issues an HMAC-signed token valid for 5 minutes, bound to one backup.
 *   2. GET  /timevault/v1/download?token=... - authenticated BY the token
 *      itself (constant-time signature check + expiry + backup binding).
 *
 * The permission callback on step 2 is a real validator, not __return_true:
 * possession of a fresh, unforgeable token issued to a capability holder IS
 * the credential. Both steps are audited.
 */
final class DownloadController extends AbstractController {

	/**
	 * Token lifetime in seconds.
	 */
	private const TOKEN_TTL = 300;

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
			'/backups/(?P<uuid>[a-f0-9\-]{36})/download-token',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'issue_token' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/download',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'stream' ),
				'permission_callback' => array( $this, 'validate_token_request' ),
				'args'                => array(
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * POST /backups/{uuid}/download-token
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function issue_token( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$uuid = (string) $request['uuid'];
		$row  = $this->plugin->backup_repository()->get( $uuid );

		if ( null === $row || 'completed' !== (string) $row['status'] ) {
			return new \WP_Error( 'timevault_not_found', __( 'Backup not found or not completed.', 'timevault' ), array( 'status' => 404 ) );
		}

		$expires = time() + self::TOKEN_TTL;
		$payload = $uuid . '|' . $expires;
		$token   = rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' ) . '.' . hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe token encoding.

		$this->plugin->audit_log()->record( 'download_token_issued', array( 'expires_at' => gmdate( 'c', $expires ) ), 'backup', $uuid );

		return rest_ensure_response(
			array(
				'token'      => $token,
				'expires_at' => gmdate( 'c', $expires ),
				'url'        => add_query_arg( 'token', rawurlencode( $token ), rest_url( self::ROUTE_NAMESPACE . '/download' ) ),
			)
		);
	}

	/**
	 * Permission callback of GET /download: real token validation.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function validate_token_request( \WP_REST_Request $request ): bool|\WP_Error {
		$parsed = $this->parse_token( (string) $request['token'] );

		return is_wp_error( $parsed ) ? $parsed : true;
	}

	/**
	 * GET /download - streams the backup and exits.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_Error Only on failure (success streams and exits).
	 */
	public function stream( \WP_REST_Request $request ): \WP_Error {
		$parsed = $this->parse_token( (string) $request['token'] );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$uuid = $parsed['uuid'];
		$row  = $this->plugin->backup_repository()->get( $uuid );

		if ( null === $row || 'completed' !== (string) $row['status'] ) {
			return new \WP_Error( 'timevault_not_found', __( 'Backup not found or not completed.', 'timevault' ), array( 'status' => 404 ) );
		}

		$adapters = $this->plugin->storage_adapters();
		$adapter  = $adapters[ (string) $row['storage'] ] ?? null;

		if ( null === $adapter ) {
			return new \WP_Error( 'timevault_unknown_storage', __( 'Storage destination is no longer available.', 'timevault' ), array( 'status' => 500 ) );
		}

		$remote_id = (string) ( $row['meta']['remote_id'] ?? $row['file_name'] );
		$cleanup   = null;

		if ( $adapter instanceof LocalAdapter ) {
			// Local shortcut: stream in place, no duplicate copy of a large file.
			$path = $adapter->local_path( $remote_id );
		} else {
			$workdir = Paths::ensure_working_dir( $uuid );
			$path    = $workdir . '/download.bin';
			$result  = $adapter->retrieve( $remote_id, $path );

			if ( is_wp_error( $result ) ) {
				Paths::delete_tree( $workdir );

				return $result;
			}

			$cleanup = $workdir;
		}

		if ( is_wp_error( $path ) ) {
			return $path;
		}

		// Integrity gate: the stored artifact must still match the checksum
		// recorded at backup time before it is handed to anyone.
		$checksum = hash_file( 'sha256', $path );

		if ( false === $checksum || ! hash_equals( (string) $row['checksum_sha256'], $checksum ) ) {
			if ( null !== $cleanup ) {
				Paths::delete_tree( $cleanup );
			}

			$this->plugin->audit_log()->record( 'download_checksum_mismatch', array(), 'backup', $uuid );

			return new \WP_Error( 'timevault_checksum_mismatch', __( 'Stored backup failed integrity verification.', 'timevault' ), array( 'status' => 409 ) );
		}

		$this->plugin->audit_log()->record( 'backup_downloaded', array( 'file_name' => (string) $row['file_name'] ), 'backup', $uuid );

		// phpcs:disable WordPress.Security.EscapeOutput, WordPress.PHP.NoSilencedErrors -- Binary streaming endpoint.
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( (string) $row['file_name'] ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming download.
		// phpcs:enable

		if ( null !== $cleanup ) {
			Paths::delete_tree( $cleanup );
		}

		exit; // Binary response already sent; REST serialization must not run.
	}

	/**
	 * Parses and verifies a download token (constant-time comparison).
	 *
	 * @param string $token Raw token.
	 * @return array{uuid: string}|\WP_Error
	 */
	private function parse_token( string $token ): array|\WP_Error {
		$invalid = new \WP_Error( 'timevault_invalid_token', __( 'Invalid or expired download token.', 'timevault' ), array( 'status' => 401 ) );
		$parts   = explode( '.', $token );

		if ( 2 !== count( $parts ) ) {
			return $invalid;
		}

		$payload = base64_decode( strtr( $parts[0], '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Token decoding.

		if ( false === $payload ) {
			return $invalid;
		}

		if ( ! hash_equals( hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) ), $parts[1] ) ) {
			return $invalid;
		}

		$fields = explode( '|', $payload );

		if ( 2 !== count( $fields ) || time() > (int) $fields[1] ) {
			return $invalid;
		}

		return array( 'uuid' => $fields[0] );
	}
}
