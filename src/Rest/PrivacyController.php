<?php
/**
 * Privacy / LGPD REST endpoints.
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
 *   GET  /timevault/v1/privacy/processing-record — the processing-activities record
 *   GET  /timevault/v1/privacy/retention          — current retention policy
 *   POST /timevault/v1/privacy/retention          — update retention policy
 *   POST /timevault/v1/privacy/retention/run       — run the retention sweep now
 */
final class PrivacyController extends AbstractController {

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
			'/privacy/processing-record',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'processing_record' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/privacy/retention',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_retention' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'set_retention' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array(
						'enabled'      => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'max_age_days' => array(
							'type'    => 'integer',
							'default' => 0,
							'minimum' => 0,
						),
						'max_count'    => array(
							'type'    => 'integer',
							'default' => 0,
							'minimum' => 0,
						),
						'min_keep'     => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
					),
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/privacy/retention/run',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run_retention' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);
	}

	/**
	 * GET /privacy/processing-record
	 */
	public function processing_record(): \WP_REST_Response {
		return rest_ensure_response( $this->plugin->privacy()->get_processing_record() );
	}

	/**
	 * GET /privacy/retention
	 */
	public function get_retention(): \WP_REST_Response {
		return rest_ensure_response( $this->plugin->privacy()->get_retention_policy() );
	}

	/**
	 * POST /privacy/retention
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function set_retention( \WP_REST_Request $request ): \WP_REST_Response {
		$this->plugin->privacy()->set_retention_policy(
			array(
				'enabled'      => (bool) $request['enabled'],
				'max_age_days' => (int) $request['max_age_days'],
				'max_count'    => (int) $request['max_count'],
				'min_keep'     => (int) $request['min_keep'],
			)
		);

		return rest_ensure_response( $this->plugin->privacy()->get_retention_policy() );
	}

	/**
	 * POST /privacy/retention/run
	 */
	public function run_retention(): \WP_REST_Response {
		$expired = $this->plugin->privacy()->apply_retention_policy();

		return rest_ensure_response( array( 'expired' => $expired ) );
	}
}
