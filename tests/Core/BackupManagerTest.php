<?php
/**
 * BackupManager tests — synchronous pipeline + integrity.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Tests\Core;

use Timevault\Plugin;
use Timevault\Restore\ArchiveInspector;
use Timevault\Core\EncryptionService;
use WP_UnitTestCase;

/**
 * Uses run_now() (no Action Scheduler) to exercise dump → package → encrypt →
 * checksum → store end-to-end, then verifies the artifact is a valid,
 * checksum-matching, decryptable package.
 */
final class BackupManagerTest extends WP_UnitTestCase {

	public function test_run_now_db_backup_completes_with_valid_artifact(): void {
		$plugin = Plugin::instance();
		$uuid   = $plugin->backups()->run_now( 'db' );

		$this->assertIsString( $uuid, is_wp_error( $uuid ) ? $uuid->get_error_message() : '' );

		$row = $plugin->backup_repository()->get( $uuid );
		$this->assertSame( 'completed', $row['status'] );
		$this->assertSame( 1, (int) $row['is_encrypted'] );
		$this->assertNotEmpty( $row['checksum_sha256'] );
		$this->assertNotEmpty( $row['file_name'] );

		// The stored artifact matches the recorded checksum and decrypts to a
		// valid ZIP containing the dump + JSON manifest.
		$dir  = \Timevault\Support\Paths::backup_dir();
		$path = $dir . '/' . $row['file_name'];
		$this->assertFileExists( $path );
		$this->assertSame( $row['checksum_sha256'], hash_file( 'sha256', $path ) );

		$inspector = new ArchiveInspector( new EncryptionService() );
		$plainzip  = $dir . '/rt-' . wp_generate_password( 6, false ) . '.zip';
		$this->assertTrue( $inspector->to_plaintext_zip( $path, true, $plainzip ) === $plainzip );

		$result = $inspector->inspect( $plainzip );
		$this->assertIsArray( $result );
		$this->assertTrue( (bool) $result['manifest']['security']['wp_config_excluded'] );
		$this->assertSame( 'json', $result['manifest']['security']['serialization'] );

		unlink( $plainzip );
		wp_delete_file( $path );
	}

	public function test_unknown_storage_is_rejected(): void {
		$uuid = Plugin::instance()->backups()->schedule( 'db', array( 'storage' => 'nonexistent' ) );
		$this->assertWPError( $uuid );
		$this->assertSame( 'timevault_unknown_storage', $uuid->get_error_code() );
	}

	public function test_invalid_type_is_rejected(): void {
		$uuid = Plugin::instance()->backups()->run_now( 'bogus' );
		$this->assertWPError( $uuid );
	}

	public function test_repository_delete_removes_row(): void {
		$plugin = Plugin::instance();
		$uuid   = $plugin->backups()->run_now( 'db' );
		$this->assertIsString( $uuid, is_wp_error( $uuid ) ? $uuid->get_error_message() : '' );

		$row  = $plugin->backup_repository()->get( $uuid );
		$path = \Timevault\Support\Paths::backup_dir() . '/' . $row['file_name'];
		$this->assertFileExists( $path );

		$this->assertTrue( $plugin->backup_repository()->delete( $uuid ) );
		$this->assertNull( $plugin->backup_repository()->get( $uuid ) );

		wp_delete_file( $path ); // The REST endpoint also removes the artifact; here we tidy up.
	}
}
