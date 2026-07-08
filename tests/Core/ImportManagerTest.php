<?php
/**
 * ImportManager tests - non-destructive validation paths.
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

	public function test_import_uploaded_package_registers_backup(): void {
		// A real Timevault package to "upload": make one, then copy it aside.
		$src_uuid = $this->make_backup();
		$src      = Plugin::instance()->backup_repository()->get( $src_uuid );
		$file     = Paths::backup_dir() . '/' . $src['file_name'];

		$upload = sys_get_temp_dir() . '/tv-upload-' . wp_generate_password( 8, false ) . '.zip.enc';
		copy( $file, $upload );

		$new_uuid = Plugin::instance()->imports()->import_uploaded_package( $upload, 'timevault-db-x.zip.enc' );
		$this->assertIsString( $new_uuid, is_wp_error( $new_uuid ) ? $new_uuid->get_error_message() : '' );

		$row = Plugin::instance()->backup_repository()->get( $new_uuid );
		$this->assertSame( 'completed', $row['status'] );
		$this->assertSame( 1, (int) $row['is_encrypted'] );
		$this->assertTrue( ! empty( $row['meta']['imported'] ) );
		// The imported package is valid for the restore flow.
		$this->assertIsArray( Plugin::instance()->imports()->validate_package( $new_uuid ) );

		unlink( $upload );
		wp_delete_file( Paths::backup_dir() . '/' . $row['file_name'] );
	}

	public function test_import_rejects_garbage_and_registers_nothing(): void {
		$before = count( Plugin::instance()->backup_repository()->list_backups( 100 ) );

		$bad = sys_get_temp_dir() . '/tv-bad-' . wp_generate_password( 8, false ) . '.zip';
		file_put_contents( $bad, 'not a zip at all' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$result = Plugin::instance()->imports()->import_uploaded_package( $bad, 'bad.zip' );
		$this->assertWPError( $result );

		$after = count( Plugin::instance()->backup_repository()->list_backups( 100 ) );
		$this->assertSame( $before, $after, 'An invalid upload must not leave a registry row behind.' );

		unlink( $bad );
	}

	public function test_import_accepts_wpvivid_like_zip(): void {
		$upload = sys_get_temp_dir() . '/tv-wpvivid-' . wp_generate_password( 8, false ) . '.zip';
		$zip    = new \ZipArchive();
		$this->assertTrue( true === $zip->open( $upload, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) );
		$zip->addFromString( 'wpvivid_database.sql', "CREATE TABLE `wp_options` (`option_id` bigint);\n" );
		$zip->addFromString( 'wp-content/uploads/2026/hello.txt', 'hello' );
		$zip->addFromString( 'wp-content/plugins/sample/plugin.php', '<?php // plugin' );
		$zip->close();

		$uuid = Plugin::instance()->imports()->import_uploaded_package( $upload, 'wpvivid-backup.zip' );
		$this->assertIsString( $uuid, is_wp_error( $uuid ) ? $uuid->get_error_message() : '' );

		$row = Plugin::instance()->backup_repository()->get( $uuid );
		$this->assertSame( 'completed', $row['status'] );
		$this->assertSame( 'full', $row['type'] );
		$this->assertTrue( ! empty( $row['meta']['external'] ) );
		$this->assertSame( 'wpvivid', $row['meta']['manifest']['external']['source_format'] );
		$this->assertIsArray( Plugin::instance()->imports()->validate_package( $uuid ) );

		unlink( $upload );
		wp_delete_file( Paths::backup_dir() . '/' . $row['file_name'] );
	}

	public function test_import_accepts_basic_wpress_archive(): void {
		$upload = sys_get_temp_dir() . '/tv-ai1wm-' . wp_generate_password( 8, false ) . '.wpress';
		$this->write_wpress(
			$upload,
			array(
				'database.sql'              => "CREATE TABLE `wp_options` (`option_id` bigint);\n",
				'uploads/2026/photo.txt'    => 'image',
				'themes/example/style.css'  => 'body{}',
				'wp-config.php'             => 'secret',
			)
		);

		$uuid = Plugin::instance()->imports()->import_uploaded_package( $upload, 'site.wpress' );
		$this->assertIsString( $uuid, is_wp_error( $uuid ) ? $uuid->get_error_message() : '' );

		$row = Plugin::instance()->backup_repository()->get( $uuid );
		$this->assertSame( 'completed', $row['status'] );
		$this->assertSame( 'all-in-one-wp-migration', $row['meta']['manifest']['external']['source_format'] );
		$this->assertIsArray( Plugin::instance()->imports()->validate_package( $uuid ) );

		unlink( $upload );
		wp_delete_file( Paths::backup_dir() . '/' . $row['file_name'] );
	}

	/**
	 * Writes a minimal WPRESS v1-style archive for import tests.
	 *
	 * @param string               $path    Target path.
	 * @param array<string,string> $entries Entry => content.
	 */
	private function write_wpress( string $path, array $entries ): void {
		$handle = fopen( $path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$this->assertIsResource( $handle );

		foreach ( $entries as $name => $content ) {
			$header = str_repeat( "\0", 512 );
			$header = substr_replace( $header, substr( $name, 0, 100 ), 0, min( strlen( $name ), 100 ) );
			$size   = (string) strlen( $content );
			$header = substr_replace( $header, $size, 124, strlen( $size ) );

			fwrite( $handle, $header ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fwrite( $handle, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		}

		fwrite( $handle, str_repeat( "\0", 512 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}
}
