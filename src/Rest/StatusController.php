<?php
/**
 * Status endpoint — health snapshot for the dashboard.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Rest;

use Timevault\Plugin;
use Timevault\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * GET /wp-json/timevault/v1/status
 *
 * Returns environment health only — never paths, credentials or other
 * information useful to an attacker.
 */
final class StatusController extends AbstractController {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Service container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Registers the /status route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);
	}

	/**
	 * Health snapshot used by the dashboard (polled during long jobs later).
	 */
	public function get_status(): \WP_REST_Response {
		return rest_ensure_response(
			array(
				'version'               => TIMEVAULT_VERSION,
				'schema_version'        => (string) get_option( 'timevault_schema_version', '' ),
				'encryption_configured' => $this->plugin->encryption()->is_configured(),
				'queue_available'       => $this->plugin->queue()->is_available(),
				'backup_dir_protected'  => Paths::is_hardened(),
			)
		);
	}
}
