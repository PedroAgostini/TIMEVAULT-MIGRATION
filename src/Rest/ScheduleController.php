<?php
/**
 * Automatic-backup schedule REST endpoints.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Rest;

use Timevault\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * GET/POST /timevault/v1/schedule — read and update the automatic backup
 * schedule (frequency + how many to keep). Capability-gated.
 */
final class ScheduleController extends AbstractController {

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
			'/schedule',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_schedule' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'set_schedule' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array(
						'enabled'   => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'frequency' => array(
							'type'    => 'string',
							'enum'    => array( 'daily', 'weekly', 'monthly' ),
							'default' => 'weekly',
						),
						'keep'      => array(
							'type'    => 'integer',
							'default' => 6,
							'minimum' => 1,
							'maximum' => 60,
						),
					),
				),
			)
		);
	}

	/**
	 * GET /schedule
	 */
	public function get_schedule(): \WP_REST_Response {
		return rest_ensure_response( $this->plugin->schedule()->get_schedule() );
	}

	/**
	 * POST /schedule
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function set_schedule( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response(
			$this->plugin->schedule()->set_schedule(
				array(
					'enabled'   => (bool) $request['enabled'],
					'frequency' => (string) $request['frequency'],
					'keep'      => (int) $request['keep'],
				)
			)
		);
	}
}
