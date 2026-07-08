<?php
/**
 * Backups REST endpoints.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Rest;

use Timevault\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Endpoints (all gated by the real permission_check of AbstractController):
 *
 *   GET  /timevault/v1/backups          - paginated history
 *   POST /timevault/v1/backups          - schedule a backup (202 + uuid)
 *   GET  /timevault/v1/backups/{uuid}   - status of one backup (polled by the UI)
 *   POST /timevault/v1/exports          - schedule a selective export
 *
 * Responses expose a safe subset of the registry row: internal meta
 * (working paths, raw options) never leaves the server.
 */
final class BackupsController extends AbstractController {

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
			'/backups',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_backups' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array(
						'per_page' => array(
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 100,
						),
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_backup' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array(
						'type'        => array(
							'type'    => 'string',
							'enum'    => array( 'full', 'db' ),
							'default' => 'full',
						),
						'files_scope' => array(
							'type'    => 'string',
							'enum'    => array( 'wp-content', 'full' ),
							'default' => 'wp-content',
						),
						'storage'     => array(
							'type'    => 'string',
							'default' => 'local',
						),
					),
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/backups/(?P<uuid>[a-f0-9\-]{36})',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_backup' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_backup' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array(
						'display_name' => array(
							'type'        => 'string',
							'required'    => true,
							'maxLength'   => 120,
							'description' => __( 'Friendly name shown in the Timevault dashboard.', 'timevault' ),
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_backup' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/exports/tables',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_tables' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/exports',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_export' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'tables'          => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'string' ),
						'default' => array(),
					),
					'include_uploads' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'anonymize'       => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	/**
	 * GET /backups
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function list_backups( \WP_REST_Request $request ): \WP_REST_Response {
		$per_page = (int) $request['per_page'];
		$page     = (int) $request['page'];

		$rows = $this->plugin->backup_repository()->list_backups( $per_page, ( $page - 1 ) * $per_page );

		return rest_ensure_response( array_map( array( $this, 'prepare_row' ), $rows ) );
	}

	/**
	 * POST /backups
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_backup( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$type = (string) $request['type'];

		$uuid = $this->plugin->backups()->schedule(
			$type,
			array(
				'files_scope' => (string) $request['files_scope'],
				'storage'     => (string) $request['storage'], // Validated against the registered adapters by the manager.
			)
		);

		if ( is_wp_error( $uuid ) ) {
			return $uuid;
		}

		return $this->scheduled_response( $uuid );
	}

	/**
	 * GET /backups/{uuid}
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_backup( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$row = $this->plugin->backup_repository()->get( (string) $request['uuid'] );

		if ( null === $row ) {
			return new \WP_Error( 'timevault_not_found', __( 'Backup not found.', 'timevault' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $this->prepare_row( $row ) );
	}

	/**
	 * PATCH /backups/{uuid} - updates user-facing backup metadata.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_backup( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$uuid = (string) $request['uuid'];
		$row  = $this->plugin->backup_repository()->get( $uuid );

		if ( null === $row ) {
			return new \WP_Error( 'timevault_not_found', __( 'Backup not found.', 'timevault' ), array( 'status' => 404 ) );
		}

		$display_name = trim( sanitize_text_field( wp_unslash( (string) $request['display_name'] ) ) );
		if ( function_exists( 'mb_substr' ) ) {
			$display_name = mb_substr( $display_name, 0, 120 );
		} else {
			$display_name = substr( $display_name, 0, 120 );
		}

		$this->plugin->backup_repository()->merge_meta( $uuid, array( 'display_name' => $display_name ) );
		$this->plugin->audit_log()->record( 'backup_renamed', array( 'display_name' => $display_name ), 'backup', $uuid );

		$updated = $this->plugin->backup_repository()->get( $uuid );

		return rest_ensure_response( $this->prepare_row( $updated ?? $row ) );
	}

	/**
	 * DELETE /backups/{uuid} - removes the stored artifact and the registry row.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_backup( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$uuid = (string) $request['uuid'];
		$row  = $this->plugin->backup_repository()->get( $uuid );

		if ( null === $row ) {
			return new \WP_Error( 'timevault_not_found', __( 'Backup not found.', 'timevault' ), array( 'status' => 404 ) );
		}

		$adapters = $this->plugin->storage_adapters();
		$adapter  = $adapters[ (string) $row['storage'] ] ?? null;

		if ( null !== $adapter ) {
			$remote_id = (string) ( $row['meta']['remote_id'] ?? $row['file_name'] );

			if ( '' !== $remote_id ) {
				$adapter->delete( $remote_id ); // Best-effort; a missing file still removes the record.
			}
		}

		$this->plugin->backup_repository()->delete( $uuid );
		$this->plugin->audit_log()->record( 'backup_deleted', array( 'file_name' => (string) $row['file_name'] ), 'backup', $uuid );

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * GET /exports/tables - tables available for selective export.
	 */
	public function list_tables(): \WP_REST_Response {
		return rest_ensure_response(
			array(
				'tables' => $this->plugin->exports()->available_tables(),
			)
		);
	}

	/**
	 * POST /exports
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_export( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$uuid = $this->plugin->exports()->schedule_export(
			array(
				'tables'          => (array) $request['tables'],
				'include_uploads' => (bool) $request['include_uploads'],
				'anonymize'       => (bool) $request['anonymize'],
			)
		);

		if ( is_wp_error( $uuid ) ) {
			return $uuid;
		}

		return $this->scheduled_response( $uuid );
	}

	/**
	 * 202 Accepted response for a scheduled job.
	 *
	 * @param string $uuid Backup UUID.
	 */
	private function scheduled_response( string $uuid ): \WP_REST_Response {
		$response = rest_ensure_response(
			array(
				'uuid'   => $uuid,
				'status' => 'pending',
			)
		);
		$response->set_status( 202 );

		return $response;
	}

	/**
	 * Maps a registry row to its public shape (no internal meta, no paths).
	 *
	 * @param array<string, mixed> $row Registry row.
	 * @return array<string, mixed>
	 */
	private function prepare_row( array $row ): array {
		return array(
			'uuid'            => (string) $row['backup_uuid'],
			'type'            => (string) $row['type'],
			'status'          => (string) $row['status'],
			'storage'         => (string) $row['storage'],
			'file_name'       => (string) $row['file_name'],
			'size_bytes'      => (int) $row['size_bytes'],
			'checksum_sha256' => (string) $row['checksum_sha256'],
			'is_encrypted'    => (bool) $row['is_encrypted'],
			'display_name'    => isset( $row['meta']['display_name'] ) ? (string) $row['meta']['display_name'] : '',
			'created_at'      => (string) $row['created_at'],
			'completed_at'    => (string) ( $row['completed_at'] ?? '' ),
			'error'           => isset( $row['meta']['error'] ) ? (string) $row['meta']['error'] : null,
		);
	}
}
