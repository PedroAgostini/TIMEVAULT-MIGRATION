<?php
/**
 * Storage adapter tests.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Tests\Storage;

use Timevault\Core\AuditLog;
use Timevault\Core\EncryptionService;
use Timevault\Storage\DestinationSettings;
use Timevault\Storage\LocalAdapter;
use Timevault\Storage\S3Adapter;
use WP_UnitTestCase;

/**
 * LocalAdapter behavior + adversarial names, plus the conditional-SDK guard
 * of the external adapters (credential/SDK not available).
 */
final class LocalAdapterTest extends WP_UnitTestCase {

	private string $src;

	private LocalAdapter $adapter;

	public function set_up(): void {
		parent::set_up();
		$this->adapter = new LocalAdapter();
		$this->src     = sys_get_temp_dir() . '/tv-src-' . wp_generate_password( 8, false ) . '.zip';
		file_put_contents( $this->src, 'backup-bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	public function tear_down(): void {
		is_file( $this->src ) && unlink( $this->src );
		parent::tear_down();
	}

	public function test_store_retrieve_delete(): void {
		$name = 'timevault-db-20260101-000000-abcdef12.zip.enc';

		$stored = $this->adapter->store( $this->src, $name );
		$this->assertSame( $name, $stored );

		$out = sys_get_temp_dir() . '/tv-out-' . wp_generate_password( 8, false ) . '.zip';
		$this->assertTrue( $this->adapter->retrieve( $name, $out ) );
		$this->assertSame( 'backup-bytes', file_get_contents( $out ) );
		unlink( $out );

		$this->assertTrue( $this->adapter->delete( $name ) );
		$this->assertWPError( $this->adapter->retrieve( $name, $out ) );
	}

	/**
	 * @dataProvider bad_names
	 *
	 * @param string $name Hostile destination name.
	 */
	public function test_rejects_bad_names( string $name ): void {
		$this->assertWPError( $this->adapter->store( $this->src, $name ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function bad_names(): array {
		return array(
			'traversal'  => array( '../evil.zip' ),
			'subpath'    => array( 'sub/evil.zip' ),
			'htaccess'   => array( '.htaccess' ),
			'index'      => array( 'index.php' ),
		);
	}

	public function test_store_missing_source_fails(): void {
		$this->assertWPError( $this->adapter->store( $this->src . '.nope', 'x.zip' ) );
	}

	public function test_list_excludes_hardening_files(): void {
		$this->adapter->store( $this->src, 'timevault-db-20260101-000000-aaaaaaaa.zip.enc' );
		$names = array_column( $this->adapter->list_backups(), 'name' );

		$this->assertContains( 'timevault-db-20260101-000000-aaaaaaaa.zip.enc', $names );
		$this->assertNotContains( '.htaccess', $names );
		$this->assertNotContains( 'index.php', $names );
	}

	/**
	 * Credential/SDK unavailable: the S3 adapter must fail loudly, never crash.
	 * (aws/aws-sdk-php is intentionally not installed.)
	 */
	public function test_s3_adapter_without_sdk_returns_error(): void {
		$settings = new DestinationSettings( new EncryptionService(), new AuditLog() );
		$adapter  = new S3Adapter( array( 'bucket' => 'b', 'region' => 'us-east-1' ), $settings );

		$result = $adapter->store( $this->src, 'x.zip' );
		$this->assertWPError( $result );
		$this->assertSame( 'timevault_sdk_missing', $result->get_error_code() );
	}
}
