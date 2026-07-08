<?php
/**
 * EncryptionKeyInstaller tests.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Tests\Support;

use Timevault\Core\EncryptionService;
use Timevault\Support\EncryptionKeyInstaller;
use WP_UnitTestCase;

/**
 * Covers safe wp-config.php insertion without touching the real test config.
 */
final class EncryptionKeyInstallerTest extends WP_UnitTestCase {

	private string $dir;

	public function set_up(): void {
		parent::set_up();
		$this->dir = sys_get_temp_dir() . '/tv-config-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->dir );
	}

	public function tear_down(): void {
		foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
			is_file( $file ) && unlink( $file );
		}

		rmdir( $this->dir );
		parent::tear_down();
	}

	public function test_install_in_file_writes_valid_key_before_wp_settings(): void {
		$config = $this->dir . '/wp-config.php';

		file_put_contents(
			$config,
			"<?php\n"
			. "define( 'DB_NAME', 'wordpress' );\n"
			. "require_once ABSPATH . 'wp-settings.php';\n"
		);

		$key = EncryptionKeyInstaller::install_in_file( $config );

		$this->assertIsString( $key );
		$this->assertSame( EncryptionService::KEY_BYTES, strlen( (string) base64_decode( $key, true ) ) );

		$contents = (string) file_get_contents( $config );

		$this->assertStringContainsString( "define( 'TIMEVAULT_ENCRYPTION_KEY', '{$key}' );", $contents );
		$this->assertLessThan(
			strpos( $contents, "require_once ABSPATH . 'wp-settings.php';" ),
			strpos( $contents, "define( 'TIMEVAULT_ENCRYPTION_KEY'" )
		);
	}

	public function test_install_in_file_refuses_existing_definition(): void {
		$config = $this->dir . '/wp-config.php';

		file_put_contents(
			$config,
			"<?php\n"
			. "define( 'TIMEVAULT_ENCRYPTION_KEY', 'already-here' );\n"
			. "require_once ABSPATH . 'wp-settings.php';\n"
		);

		$result = EncryptionKeyInstaller::install_in_file( $config );

		$this->assertWPError( $result );
		$this->assertSame( 'timevault_key_define_present', $result->get_error_code() );
	}

	public function test_insert_key_definition_requires_wp_settings_anchor(): void {
		$result = EncryptionKeyInstaller::insert_key_definition( "<?php\n", EncryptionService::generate_key() );

		$this->assertWPError( $result );
		$this->assertSame( 'timevault_wp_config_anchor_missing', $result->get_error_code() );
	}
}
