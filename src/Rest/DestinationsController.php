<?php
/**
 * Storage destinations REST endpoints.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Rest;

use Timevault\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Endpoints (capability-gated by AbstractController):
 *
 *   GET    /timevault/v1/destinations       — configured destinations overview
 *   POST   /timevault/v1/destinations/{id}  — configure/enable a destination
 *   DELETE /timevault/v1/destinations/{id}  — remove a destination
 *
 * Credentials are WRITE-ONLY through this API: they are accepted on POST,
 * encrypted at rest, and never returned by any endpoint.
 */
final class DestinationsController extends AbstractController {

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
			'/destinations',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_destinations' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/destinations/(?P<id>s3|gdrive)',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_destination' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array(
						'enabled'     => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'label'       => array( 'type' => 'string' ),
						'region'      => array( 'type' => 'string' ),
						'bucket'      => array( 'type' => 'string' ),
						'prefix'      => array( 'type' => 'string' ),
						'endpoint'    => array( 'type' => 'string' ),
						'folder_id'   => array( 'type' => 'string' ),
						'credentials' => array( 'type' => 'object' ),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_destination' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
			)
		);
	}

	/**
	 * GET /destinations
	 */
	public function list_destinations(): \WP_REST_Response {
		$settings = $this->plugin->destination_settings();
		$items    = array(
			array(
				'id'            => 'local',
				'label'         => __( 'Local (server disk)', 'timevault' ),
				'region'        => null,
				'enabled'       => true,
				'configured'    => true,
				'sdk_available' => true,
			),
		);

		$sdk = array(
			's3'     => class_exists( '\\Aws\\S3\\S3Client' ),
			'gdrive' => class_exists( '\\Google\\Client' ),
		);

		foreach ( array( 's3', 'gdrive' ) as $id ) {
			$config = $settings->get( $id );

			$items[] = array(
				'id'            => $id,
				'label'         => (string) ( $config['label'] ?? '' ),
				'region'        => (string) ( $config['region'] ?? '' ),
				'enabled'       => ! empty( $config['enabled'] ),
				'configured'    => '' !== (string) ( $config['credentials'] ?? '' ),
				'sdk_available' => $sdk[ $id ],
			);
		}

		return rest_ensure_response( $items );
	}

	/**
	 * POST /destinations/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_destination( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id          = (string) $request['id'];
		$credentials = $request['credentials'];

		$result = $this->plugin->destination_settings()->save(
			$id,
			array(
				'enabled'   => (bool) $request['enabled'],
				'label'     => (string) ( $request['label'] ?? '' ),
				'region'    => (string) ( $request['region'] ?? '' ),
				'bucket'    => (string) ( $request['bucket'] ?? '' ),
				'prefix'    => (string) ( $request['prefix'] ?? '' ),
				'endpoint'  => (string) ( $request['endpoint'] ?? '' ),
				'folder_id' => (string) ( $request['folder_id'] ?? '' ),
			),
			is_array( $credentials ) ? $credentials : null
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$config = $this->plugin->destination_settings()->get( $id );

		return rest_ensure_response(
			array(
				'id'         => $id,
				'enabled'    => ! empty( $config['enabled'] ),
				'region'     => (string) ( $config['region'] ?? '' ),
				'configured' => '' !== (string) ( $config['credentials'] ?? '' ),
			)
		);
	}

	/**
	 * DELETE /destinations/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function delete_destination( \WP_REST_Request $request ): \WP_REST_Response {
		$this->plugin->destination_settings()->delete( (string) $request['id'] );

		return rest_ensure_response( array( 'deleted' => true ) );
	}
}
