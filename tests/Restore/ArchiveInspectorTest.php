<?php
/**
 * ArchiveInspector tests — package validation and safe extraction.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Tests\Restore;

use Timevault\Core\EncryptionService;
use Timevault\Restore\ArchiveInspector;
use WP_UnitTestCase;
use ZipArchive;

/**
 * Adversarial coverage: bad checksum, zip-slip entries, missing manifest.
 */
final class ArchiveInspectorTest extends WP_UnitTestCase {

	private string $dir;

	private ArchiveInspector $inspector;

	public function set_up(): void {
		parent::set_up();
		$this->dir       = sys_get_temp_dir() . '/tv-inspector-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->dir );
		$this->inspector = new ArchiveInspector( new EncryptionService() );
	}

	public function tear_down(): void {
		$this->rrmdir( $this->dir );
		parent::tear_down();
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) glob( $dir . '/*' ) as $f ) {
			is_dir( $f ) ? $this->rrmdir( $f ) : unlink( $f );
		}
		rmdir( $dir );
	}

	private function make_zip( array $entries ): string {
		$path = $this->dir . '/pkg-' . wp_generate_password( 8, false ) . '.zip';
		$zip  = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		foreach ( $entries as $name => $content ) {
			$zip->addFromString( $name, $content );
		}
		$zip->close();

		return $path;
	}

	public function test_verify_checksum_rejects_mismatch(): void {
		$path = $this->dir . '/blob.bin';
		file_put_contents( $path, 'hello' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->assertTrue( $this->inspector->verify_checksum( $path, hash( 'sha256', 'hello' ) ) );
		$this->assertWPError( $this->inspector->verify_checksum( $path, hash( 'sha256', 'tampered' ) ) );
	}

	public function test_verify_checksum_requires_a_recorded_checksum(): void {
		$path = $this->dir . '/blob.bin';
		file_put_contents( $path, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->assertWPError( $this->inspector->verify_checksum( $path, '' ) );
	}

	public function test_inspect_rejects_zip_slip_entry(): void {
		$zip    = $this->make_zip(
			array(
				'files/../../../../evil.php' => '<?php echo "pwned";',
				'manifest.json'              => '{}',
			)
		);
		$result = $this->inspector->inspect( $zip );

		$this->assertWPError( $result );
		$this->assertSame( 'timevault_unsafe_entry', $result->get_error_code() );
	}

	public function test_inspect_rejects_missing_manifest(): void {
		$zip    = $this->make_zip( array( 'database.sql' => 'SELECT 1;' ) );
		$result = $this->inspector->inspect( $zip );

		$this->assertWPError( $result );
		$this->assertSame( 'timevault_restore_no_manifest', $result->get_error_code() );
	}

	public function test_inspect_rejects_non_json_manifest(): void {
		$zip    = $this->make_zip(
			array(
				'database.sql'  => 'SELECT 1;',
				'manifest.json' => 'O:8:"stdClass":0:{}', // Serialized object, not JSON.
			)
		);
		$result = $this->inspector->inspect( $zip );

		$this->assertWPError( $result );
		$this->assertSame( 'timevault_restore_bad_manifest', $result->get_error_code() );
	}

	public function test_inspect_accepts_valid_package(): void {
		$zip    = $this->make_zip(
			array(
				'database.sql'  => "CREATE TABLE x (id int);\n",
				'files/a.txt'   => 'hello',
				'manifest.json' => wp_json_encode( array( 'format' => 1 ) ),
			)
		);
		$result = $this->inspector->inspect( $zip );

		$this->assertIsArray( $result );
		$this->assertSame( 3, $result['entries'] );
		$this->assertSame( 1, $result['manifest']['format'] );
	}

	public function test_extract_rejects_zip_slip(): void {
		$zip    = $this->make_zip( array( 'files/../../evil' => 'x' ) );
		$result = $this->inspector->extract_to_staging( $zip, $this->dir . '/staging' );

		$this->assertWPError( $result );
	}

	public function test_extract_writes_only_validated_entries(): void {
		$zip     = $this->make_zip(
			array(
				'files/ok.txt'  => 'good',
				'manifest.json' => '{}',
			)
		);
		$staging = $this->dir . '/staging';
		$result  = $this->inspector->extract_to_staging( $zip, $staging );

		$this->assertTrue( $result );
		$this->assertFileExists( $staging . '/files/ok.txt' );
		$this->assertSame( 'good', file_get_contents( $staging . '/files/ok.txt' ) );
	}
}
