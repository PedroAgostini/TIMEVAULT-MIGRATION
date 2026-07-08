<?php
/**
 * PathGuard tests — path-traversal / zip-slip defense.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Tests\Restore;

use Timevault\Restore\PathGuard;
use WP_UnitTestCase;

/**
 * Adversarial coverage for archive entry validation.
 */
final class PathGuardTest extends WP_UnitTestCase {

	/**
	 * @dataProvider malicious_names
	 *
	 * @param string $name Hostile entry name.
	 */
	public function test_rejects_malicious_entry_names( string $name ): void {
		$this->assertWPError( PathGuard::validate_entry_name( $name ), "Should reject: {$name}" );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function malicious_names(): array {
		return array(
			'parent traversal'      => array( 'files/../../evil.php' ),
			'leading traversal'     => array( '../wp-config.php' ),
			'absolute unix'         => array( '/etc/passwd' ),
			'windows drive'         => array( 'C:\\windows\\system32' ),
			'backslash'             => array( 'files\\..\\evil' ),
			'nul byte'              => array( "files/evil\0.php" ),
			'out of prefix'         => array( 'wp-config.php' ),
			'random top-level'      => array( 'secrets/key.pem' ),
			'empty'                 => array( '' ),
		);
	}

	/**
	 * @dataProvider safe_names
	 *
	 * @param string $name Legitimate entry name.
	 */
	public function test_accepts_safe_entry_names( string $name ): void {
		$this->assertTrue( PathGuard::validate_entry_name( $name ), "Should accept: {$name}" );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function safe_names(): array {
		return array(
			'database'    => array( 'database.sql' ),
			'manifest'    => array( 'manifest.json' ),
			'files tree'  => array( 'files/themes/x/style.css' ),
			'uploads'     => array( 'uploads/2026/07/pic.jpg' ),
			'files dir'   => array( 'files/' ),
		);
	}

	public function test_safe_target_stays_within_destination(): void {
		$root = sys_get_temp_dir() . '/tv-pathguard-' . wp_generate_password( 8, false );
		wp_mkdir_p( $root );

		$target = PathGuard::safe_target( $root, 'files/sub/x.txt' );
		$this->assertIsString( $target );
		// Compare against the canonicalized root (realpath) — on Windows the
		// temp dir may resolve to an 8.3 short path, which safe_target adopts.
		$this->assertStringStartsWith( trailingslashit( wp_normalize_path( (string) realpath( $root ) ) ), wp_normalize_path( (string) $target ) );

		rmdir( $root );
	}
}
