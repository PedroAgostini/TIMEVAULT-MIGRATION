<?php
/**
 * Plugin Name:       Timevault Migration & Backups
 * Description:       Private agency plugin for secure WordPress backup, export, import and migration. Privacy by design: no external network calls by default.
 * Version:           0.7.24
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            Pedro Agostini
 * Text Domain:       timevault
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * Update URI:        false
 *
 * @package Timevault
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'TIMEVAULT_VERSION', '0.7.24' );
define( 'TIMEVAULT_FILE', __FILE__ );
define( 'TIMEVAULT_DIR', plugin_dir_path( __FILE__ ) );
define( 'TIMEVAULT_URL', plugin_dir_url( __FILE__ ) );

/*
 * Composer autoloader (preferred), with a minimal PSR-4 fallback so the
 * plugin still boots on environments where `composer install` was not run.
 */
if ( file_exists( TIMEVAULT_DIR . 'vendor/autoload.php' ) ) {
	require_once TIMEVAULT_DIR . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class_name ): void {
			if ( ! str_starts_with( $class_name, 'Timevault\\' ) ) {
				return;
			}

			$relative = substr( $class_name, strlen( 'Timevault\\' ) );
			$path     = TIMEVAULT_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

			if ( is_readable( $path ) ) {
				require $path;
			}
		}
	);
}

/*
 * Action Scheduler must be loaded before `init` when bundled via Composer.
 * All long-running jobs (backup, export, restore) run through it - never
 * as synchronous blocking requests.
 */
$timevault_action_scheduler = TIMEVAULT_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
if ( file_exists( $timevault_action_scheduler ) ) {
	require_once $timevault_action_scheduler;
}
unset( $timevault_action_scheduler );

register_activation_hook( __FILE__, array( \Timevault\Activation\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Timevault\Activation\Deactivator::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		\Timevault\Plugin::instance()->boot();
	}
);
