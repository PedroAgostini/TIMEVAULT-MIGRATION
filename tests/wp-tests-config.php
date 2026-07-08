<?php
/**
 * WordPress test-suite configuration for Timevault.
 *
 * Points the wp-phpunit bootstrap at a dedicated, disposable test database
 * (timevault_tests) and the local WordPress core. Never point DB_NAME at a
 * real site DB - the suite wipes it.
 *
 * @package Timevault
 */

// Local WordPress core (adjust if the install moves).
define( 'ABSPATH', getenv( 'WP_CORE_DIR' ) ?: 'C:/laragon/www/timevault-test/' );

// Disposable test database.
define( 'DB_NAME', getenv( 'WP_TESTS_DB_NAME' ) ?: 'timevault_tests' );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASS' ) ?: '' );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) ?: '127.0.0.1' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Required by the WP test suite.

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Timevault Tests' );
define( 'WP_PHP_BINARY', getenv( 'WP_PHP_BINARY' ) ?: 'php' );

define( 'WP_DEBUG', true );

// Encryption key so EncryptionService is configured during tests.
define( 'TIMEVAULT_ENCRYPTION_KEY', base64_encode( str_repeat( "\x01", 32 ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Deterministic test key.

// Keep test backups inside a temp dir, out of any real webroot.
define( 'TIMEVAULT_BACKUP_DIR', sys_get_temp_dir() . '/timevault-tests-store' );

if ( ! defined( 'WP_DEFAULT_THEME' ) ) {
	define( 'WP_DEFAULT_THEME', 'default' );
}
