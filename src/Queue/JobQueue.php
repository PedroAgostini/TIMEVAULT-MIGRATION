<?php
/**
 * Job queue wrapper around Action Scheduler.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around Action Scheduler (same library WooCommerce uses).
 * Long-running work (backup, export, restore) must always go through here —
 * never synchronous blocking execution inside a web request.
 */
final class JobQueue {

	/**
	 * Action Scheduler group for all Timevault jobs.
	 */
	public const GROUP = 'timevault';

	/**
	 * Whether Action Scheduler is loaded (bundled via Composer).
	 */
	public function is_available(): bool {
		return function_exists( 'as_enqueue_async_action' );
	}

	/**
	 * Dispatches a one-off async job.
	 *
	 * @param string               $hook Action hook that performs the work.
	 * @param array<string, mixed> $args Arguments passed to the hook.
	 * @return int|\WP_Error Action id on success.
	 */
	public function dispatch( string $hook, array $args = array() ): int|\WP_Error {
		if ( ! $this->is_available() ) {
			return $this->unavailable_error();
		}

		return as_enqueue_async_action( $hook, $args, self::GROUP );
	}

	/**
	 * Schedules a recurring job (e.g. scheduled backups, retention sweeps).
	 * Deduplicates: does nothing if the hook is already scheduled.
	 *
	 * @param int                  $interval_seconds Recurrence interval.
	 * @param string               $hook             Action hook that performs the work.
	 * @param array<string, mixed> $args             Arguments passed to the hook.
	 * @return int|\WP_Error Action id on success (0 when already scheduled).
	 */
	public function schedule_recurring( int $interval_seconds, string $hook, array $args = array() ): int|\WP_Error {
		if ( ! $this->is_available() ) {
			return $this->unavailable_error();
		}

		if ( as_next_scheduled_action( $hook, $args, self::GROUP ) ) {
			return 0;
		}

		return as_schedule_recurring_action( time() + $interval_seconds, $interval_seconds, $hook, $args, self::GROUP );
	}

	/**
	 * Unschedules pending Timevault jobs (used on deactivation).
	 *
	 * @param string|null $hook Specific hook, or null for every job in the group.
	 */
	public function unschedule_all( ?string $hook = null ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook ?? '', array(), self::GROUP );
		}
	}

	/**
	 * Shared error for environments missing Action Scheduler.
	 */
	private function unavailable_error(): \WP_Error {
		return new \WP_Error(
			'timevault_queue_unavailable',
			__( 'Action Scheduler is not available. Run "composer install" inside the plugin directory.', 'timevault' )
		);
	}
}
