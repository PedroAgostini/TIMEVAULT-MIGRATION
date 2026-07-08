<?php
/**
 * ImportManager tests — non-destructive validation paths.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Tests\Core;

use Timevault\Plugin;
use Timevault\Support\Paths;
use WP_UnitTestCase;

/**
 * Covers validate_package (used by the double-confirm prepare step): happy
 * path, missing source, and a tampered artifact (checksum failure).
 *
 * The destructive full restore pipeline (restore_db issues DDL that
 * implicitly commits) is exercised by the runtime test on a real site, not
 * here, to keep the test database intact.
 */
final class ImportManagerTest extends WP_UnitTestCase {

	private function make_backup(): string {
		$uuid = Plugin::instance()->backups()->run_now( 'db' );
		$this->assertIsString( $uuid, is_wp_error( $uuid ) ? $uuid->get_error_message() : '' );

		return $uuid;
	}

	public function test_validate_package_happy_path(): void {
		$uuid    = $this->make_backup();
		$summary = Plugin::instance()->imports()->validate_package( $uuid );

		$this->assertIsArray( $summary, is_wp_error( $summary ) ? $summary->get_error_message() : '' );
		$this->assertSame( 'db', $summary['type'] );
		$this->assertArrayHasKey( 'manifest', $summary );
		$this->assertGreaterThan( 0, $summary['entries'] );
	}

	public function test_validate_package_unknown_backup(): void {
		$result = Plugin::instance()->imports()->validate_package( '00000000-0000-0000-0000-000000000000' );
		$this->assertWPError( $result );
		$this->assertSame( 'timevault_restore_source_missing', $result->get_error_code() );
	}

	public function test_validate_package_detects_tampered_artifact(): void {
		$uuid = $this->make_backup();
		$row  = Plugin::instance()->backup_repository()->get( $uuid );

		// Corrupt the stored artifact so its checksum no longer matches.
		$path = Paths::backup_dir() . '/' . $row['file_name'];
		file_put_contents( $path, file_get_contents( $path ) . 'GARBAGE' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$result = Plugin::instance()->imports()->validate_package( $uuid );
		$this->assertWPError( $result );
		$this->assertSame( 'timevault_restore_checksum', $result->get_error_code() );

		wp_delete_file( $path );
	}

	public function test_schedule_restore_rejects_unknown_backup(): void {
		$result = Plugin::instance()->imports()->schedule_restore( '00000000-0000-0000-0000-000000000000' );
		$this->assertWPError( $result );
	}
}
