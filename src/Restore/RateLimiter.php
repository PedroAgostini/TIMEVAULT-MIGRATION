<?php
/**
 * Simple transient-based rate limiter.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * Fixed-window rate limiter backed by the transients API. Used to throttle
 * the destructive restore endpoints per user.
 */
final class RateLimiter {

	/**
	 * Checks and records an attempt.
	 *
	 * @param string $action_key Logical action (e.g. 'restore_prepare').
	 * @param int    $max        Max attempts allowed within the window.
	 * @param int    $window     Window length in seconds.
	 * @return true|\WP_Error True when allowed; WP_Error (429) when exceeded.
	 */
	public function hit( string $action_key, int $max, int $window ): bool|\WP_Error {
		$key   = 'timevault_rl_' . md5( $action_key . '|' . get_current_user_id() );
		$count = (int) get_transient( $key );

		if ( $count >= $max ) {
			return new \WP_Error(
				'timevault_rate_limited',
				__( 'Too many attempts. Please wait before trying again.', 'timevault' ),
				array( 'status' => 429 )
			);
		}

		set_transient( $key, $count + 1, $window );

		return true;
	}
}
