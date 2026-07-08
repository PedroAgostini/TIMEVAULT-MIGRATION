<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite and the plugin.
 *
 * @package Timevault
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Point the WP test suite at our config before it boots. wp-phpunit reads
// this as a CONSTANT (not an env var).
if ( ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );
}

$_tests_dir = getenv( 'WP_PHPUNIT__DIR' );

if ( false === $_tests_dir ) {
	$_tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
}

require_once $_tests_dir . '/includes/functions.php';

// Load Timevault (and its bundled Action Scheduler) as a mu-plugin-style boot.
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/timevault.php';
	}
);

// Ensure the plugin's tables exist in the test DB before tests run.
tests_add_filter(
	'setup_theme',
	static function (): void {
		\Timevault\Activation\Activator::activate();
	}
);

require $_tests_dir . '/includes/bootstrap.php';
