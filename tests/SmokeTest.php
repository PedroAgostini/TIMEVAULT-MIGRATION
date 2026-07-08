<?php
/**
 * Environment smoke test.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Tests;

use WP_UnitTestCase;

/**
 * Verifies the plugin loaded and its tables exist in the test DB.
 */
final class SmokeTest extends WP_UnitTestCase {

	public function test_plugin_constants_defined(): void {
		$this->assertTrue( defined( 'TIMEVAULT_VERSION' ) );
		$this->assertTrue( class_exists( \Timevault\Plugin::class ) );
	}

	public function test_tables_exist(): void {
		global $wpdb;

		foreach ( array( 'timevault_audit_log', 'timevault_backups', 'timevault_restores' ) as $base ) {
			$table  = $wpdb->prefix . $base;
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$this->assertSame( $table, $exists, "Missing table {$base}" );
		}
	}

	public function test_encryption_key_configured(): void {
		$this->assertTrue( ( new \Timevault\Core\EncryptionService() )->is_configured() );
	}
}
