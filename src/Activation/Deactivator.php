<?php
/**
 * Plugin deactivation.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Activation;

use Timevault\Core\AuditLog;
use Timevault\Queue\JobQueue;

defined( 'ABSPATH' ) || exit;

/**
 * Deactivation only stops scheduled work. Tables, capability, options and
 * backup archives are intentionally preserved - removing data is uninstall's
 * job, and even then only with explicit opt-in (this is a backup plugin;
 * losing backups by accident is the worst possible failure mode).
 */
final class Deactivator {

	/**
	 * Deactivation entry point.
	 */
	public static function deactivate(): void {
		( new JobQueue() )->unschedule_all();

		( new AuditLog() )->record( 'plugin_deactivated', array( 'version' => TIMEVAULT_VERSION ) );
	}
}
