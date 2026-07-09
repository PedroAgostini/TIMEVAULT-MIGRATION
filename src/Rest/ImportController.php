<?php
/**
 * Package import (upload) REST endpoint.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Rest;

use Timevault\Plugin;
use Timevault\Restore\RateLimiter;

defined( 'ABSPATH' ) || exit;

/**
 * POST /timevault/v1/import - accepts an uploaded backup package (multipart),
 * validates it as hostile input and registers it as a completed backup so it
 * can be restored through the normal double-confirmation flow (migration from
 * another site). The upload is NEVER auto-restored.
 *
 * Capability-gated, rate-limited, and audited by ImportManager.
 */
final class ImportController extends AbstractController {

	/**
	 * Accepted upload extensions.
	 */
	private const ALLOWED_EXT = array( 'zip', 'enc', 'wpress' );

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
			'/import',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);
	}

	/**
	 * POST /import
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$this->prepare_large_import_runtime();

		$limited = ( new RateLimiter() )->hit( 'import_upload', 10, 600 );

		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$files = $request->get_file_params();
		$file  = $files['package'] ?? null;

		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) ) {
			return new \WP_Error( 'timevault_import_no_file', __( 'No package file was uploaded. Send it as the "package" field.', 'timevault' ), array( 'status' => 400 ) );
		}

		if ( isset( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new \WP_Error( 'timevault_import_upload_error', __( 'The upload did not complete (it may exceed the server upload limit).', 'timevault' ), array( 'status' => 400 ) );
		}

		// Security: the path must be a genuine PHP upload, never an arbitrary
		// server path smuggled in through the request.
		if ( ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return new \WP_Error( 'timevault_import_bad_upload', __( 'Invalid file upload.', 'timevault' ), array( 'status' => 400 ) );
		}

		$ext = strtolower( (string) pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );

		if ( ! in_array( $ext, self::ALLOWED_EXT, true ) ) {
			return new \WP_Error( 'timevault_import_bad_type', __( 'Only Timevault, All-in-One WP Migration, or WPvivid packages (.zip, .zip.enc, .wpress) can be imported.', 'timevault' ), array( 'status' => 400 ) );
		}

		$uuid = $this->plugin->imports()->import_uploaded_package( (string) $file['tmp_name'], (string) $file['name'] );

		if ( is_wp_error( $uuid ) ) {
			return $uuid;
		}

		// Optionally apply the migration right away (import = replace the site).
		$restore_uuid = null;
		$relogin_token = null;

		if ( filter_var( $request->get_param( 'apply' ), FILTER_VALIDATE_BOOLEAN ) ) {
			$safety_backup = filter_var( $request->get_param( 'safety_backup' ), FILTER_VALIDATE_BOOLEAN );
			$current_user  = wp_get_current_user();
			$relogin_token = wp_generate_password( 32, false, false );
			$restore = $this->plugin->imports()->schedule_restore(
				(string) $uuid,
				array(
					'restore_files'        => true,
					'manual_runner'        => true,
					'preserve_admin'       => true,
					'preserve_admin_login' => $current_user && $current_user->exists() ? (string) $current_user->user_login : '',
					'admin_relogin_hash'   => hash_hmac( 'sha256', $relogin_token, wp_salt( 'auth' ) ),
					'skip_safety_backup'   => ! $safety_backup,
				)
			);

			if ( is_wp_error( $restore ) ) {
				// The package is registered; only the apply step failed.
				$restore->add_data( array( 'backup_uuid' => $uuid ) );
				return $restore;
			}

			$restore_uuid = $restore;
		}

		$response = rest_ensure_response(
			array(
				'backup_uuid'   => $uuid,
				'restore_uuid'  => $restore_uuid,
				'relogin_token' => $relogin_token,
				'applied'       => null !== $restore_uuid,
				'message'       => null !== $restore_uuid
					? __( 'Package imported. Applying it now.', 'timevault' )
					: __( 'Package imported. You can now restore it from the backup list.', 'timevault' ),
			)
		);
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Gives large migration uploads enough room to be converted and validated.
	 *
	 * Hosts may still enforce web-server/proxy limits, but this prevents PHP's
	 * normal execution limit from killing a large .wpress normalization.
	 */
	private function prepare_large_import_runtime(): void {
		wp_raise_memory_limit( 'admin' );

		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- Long-running import request for large migration packages.
			@set_time_limit( 0 );
		}
	}
}
