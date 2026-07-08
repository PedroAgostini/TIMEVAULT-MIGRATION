<?php
/**
 * Base REST controller with a real permission callback.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Rest;

use Timevault\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Every Timevault endpoint extends this class so no route can ship with
 * `__return_true` as its permission callback.
 */
abstract class AbstractController {

	protected const ROUTE_NAMESPACE = 'timevault/v1';

	/**
	 * Hooks route registration into rest_api_init.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the controller's routes.
	 */
	abstract public function register_routes(): void;

	/**
	 * Real permission check - capability-based, never `__return_true`.
	 *
	 * Cookie-authenticated requests are additionally covered by core's
	 * X-WP-Nonce (`wp_rest`) validation, which runs before this callback:
	 * without a valid nonce the request is treated as unauthenticated and
	 * fails the capability check below.
	 *
	 * @return true|\WP_Error
	 */
	public function permission_check(): bool|\WP_Error {
		if ( current_user_can( Capabilities::MANAGE ) ) {
			return true;
		}

		return new \WP_Error(
			'timevault_forbidden',
			__( 'You are not allowed to manage Timevault.', 'timevault' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}
}
