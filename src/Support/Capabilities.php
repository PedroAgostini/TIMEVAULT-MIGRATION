<?php
/**
 * Dedicated capability management.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Timevault uses its own capability instead of piggybacking on the generic
 * `manage_options`, so access can be granted/revoked per role without
 * handing out full admin powers (least privilege).
 */
final class Capabilities {

	public const MANAGE = 'manage_timevault';

	/**
	 * Grants the capability to administrators on activation.
	 */
	public static function grant(): void {
		$role = get_role( 'administrator' );

		if ( $role instanceof \WP_Role && ! $role->has_cap( self::MANAGE ) ) {
			$role->add_cap( self::MANAGE );
		}
	}

	/**
	 * Removes the capability from every role (used by uninstall only -
	 * deactivation intentionally keeps it).
	 */
	public static function revoke(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			if ( $role->has_cap( self::MANAGE ) ) {
				$role->remove_cap( self::MANAGE );
			}
		}
	}
}
