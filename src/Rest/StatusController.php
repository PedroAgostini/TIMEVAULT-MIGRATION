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

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/overview',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_overview' ),
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

	/**
	 * Aggregated dashboard overview: last backup, totals, space usage, running
	 * jobs and next scheduled maintenance. One call keeps the UI simple.
	 */
	public function get_overview(): \WP_REST_Response {
		$backups = $this->plugin->backup_repository()->list_backups( 100 );

		$total_size = 0;
		$completed  = 0;
		$running    = 0;
		$last       = null;

		foreach ( $backups as $backup ) {
			$status = (string) $backup['status'];

			if ( 'completed' === $status ) {
				++$completed;
				$total_size += (int) $backup['size_bytes'];

				if ( null === $last ) {
					$last = $backup;
				}
			}

			if ( in_array( $status, array( 'pending', 'running' ), true ) ) {
				++$running;
			}
		}

		// Running restores also count as active jobs.
		foreach ( $this->plugin->restore_repository()->list_restores( 20 ) as $restore ) {
			if ( in_array( (string) $restore['status'], array( 'pending', 'running' ), true ) ) {
				++$running;
			}
		}

		$next_sweep = function_exists( 'as_next_scheduled_action' )
			? as_next_scheduled_action( \Timevault\Core\PrivacyService::RETENTION_HOOK, array(), \Timevault\Queue\JobQueue::GROUP )
			: false;

		return rest_ensure_response(
			array(
				'health'            => array(
					'encryption_configured' => $this->plugin->encryption()->is_configured(),
					'queue_available'       => $this->plugin->queue()->is_available(),
					'backup_dir_protected'  => Paths::is_hardened(),
				),
				'backups_completed' => $completed,
				'total_size_bytes'  => $total_size,
				'running_jobs'      => $running,
				'last_backup'       => null === $last ? null : array(
					'uuid'         => (string) $last['backup_uuid'],
					'type'         => (string) $last['type'],
					'size_bytes'   => (int) $last['size_bytes'],
					'is_encrypted' => (bool) $last['is_encrypted'],
					'storage'      => (string) $last['storage'],
					'created_at'   => (string) $last['created_at'],
				),
				'next_maintenance'  => is_int( $next_sweep ) ? gmdate( 'c', $next_sweep ) : null,
				'retention'         => $this->plugin->privacy()->get_retention_policy(),
				'schedule'          => $this->plugin->schedule()->get_schedule(),
			)
		);
	}
}
