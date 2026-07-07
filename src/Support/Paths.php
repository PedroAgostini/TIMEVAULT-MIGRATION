<?php
/**
 * Backup directory resolution and hardening.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves and hardens the backup directory.
 *
 * Preferred setup: define TIMEVAULT_BACKUP_DIR in wp-config.php pointing
 * OUTSIDE the webroot. When the host does not allow that, we fall back to a
 * directory under wp-content with a random, non-enumerable suffix plus
 * .htaccess/web.config denial and an empty index.php.
 */
final class Paths {

	private const SUFFIX_OPTION = 'timevault_dir_suffix';

	/**
	 * Absolute path of the backup directory (without trailing slash).
	 */
	public static function backup_dir(): string {
		if ( defined( 'TIMEVAULT_BACKUP_DIR' ) && '' !== (string) TIMEVAULT_BACKUP_DIR ) {
			return untrailingslashit( (string) TIMEVAULT_BACKUP_DIR );
		}

		$suffix = get_option( self::SUFFIX_OPTION );

		if ( ! is_string( $suffix ) || '' === $suffix ) {
			// Random suffix so the directory name cannot be guessed/enumerated.
			$suffix = strtolower( wp_generate_password( 16, false, false ) );
			add_option( self::SUFFIX_OPTION, $suffix, '', false );
		}

		return WP_CONTENT_DIR . '/timevault-' . $suffix;
	}

	/**
	 * Creates the backup directory (if needed) and (re)applies hardening files.
	 *
	 * @return string Absolute path of the directory.
	 */
	public static function ensure_backup_dir(): string {
		$dir = self::backup_dir();

		if ( wp_mkdir_p( $dir ) ) {
			self::harden( $dir );
		}

		return $dir;
	}

	/**
	 * Whether the hardening files are in place.
	 */
	public static function is_hardened(): bool {
		$dir = self::backup_dir();

		return file_exists( $dir . '/.htaccess' ) && file_exists( $dir . '/index.php' );
	}

	/**
	 * Per-job working directory (inside the hardened backup directory).
	 *
	 * @param string $uuid Job UUID (sanitized to hex/dashes before use).
	 */
	public static function working_dir( string $uuid ): string {
		$safe = (string) preg_replace( '/[^a-f0-9\-]/', '', strtolower( $uuid ) );

		return self::backup_dir() . '/work/' . $safe;
	}

	/**
	 * Creates the working directory (hardening inherited from the parent).
	 *
	 * @param string $uuid Job UUID.
	 * @return string Absolute path.
	 */
	public static function ensure_working_dir( string $uuid ): string {
		self::ensure_backup_dir();

		$dir = self::working_dir( $uuid );
		wp_mkdir_p( $dir );

		return $dir;
	}

	/**
	 * Recursively deletes a directory — but ONLY inside the backup directory,
	 * and never the backup directory itself. Any path outside that boundary
	 * is silently refused (defense against a corrupted/hostile path ever
	 * reaching cleanup code).
	 *
	 * @param string $dir Directory to delete.
	 */
	public static function delete_tree( string $dir ): void {
		$root   = realpath( self::backup_dir() );
		$target = realpath( $dir );

		if ( false === $root || false === $target ) {
			return;
		}

		$root_prefix = trailingslashit( wp_normalize_path( $root ) );
		$target_norm = wp_normalize_path( $target );

		if ( untrailingslashit( $root_prefix ) === $target_norm || ! str_starts_with( $target_norm . '/', $root_prefix ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $target, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $file ) {
			if ( $file->isDir() && ! $file->isLink() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Cleanup inside our own hardened directory.
				rmdir( $file->getPathname() );
			} else {
				wp_delete_file( $file->getPathname() );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Cleanup inside our own hardened directory.
		rmdir( $target );
	}

	/**
	 * Writes deny-all rules for Apache/LiteSpeed (.htaccess), IIS (web.config)
	 * and an empty index.php against directory listing. Nginx cannot be
	 * configured from PHP — the README documents the required server block.
	 *
	 * @param string $dir Directory to harden.
	 */
	private static function harden( string $dir ): void {
		$htaccess = <<<'HTACCESS'
# Timevault — deny all direct web access to backup archives.
<IfModule mod_authz_core.c>
	Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
	Order deny,allow
	Deny from all
</IfModule>
Options -Indexes
HTACCESS;

		$web_config = <<<'WEBCONFIG'
<?xml version="1.0" encoding="UTF-8"?>
<!-- Timevault: deny all direct web access (requires IIS URL Authorization). -->
<configuration>
	<system.webServer>
		<security>
			<authorization>
				<remove users="*" roles="" verbs="" />
				<add accessType="Deny" users="*" />
			</authorization>
		</security>
	</system.webServer>
</configuration>
WEBCONFIG;

		$files = array(
			'.htaccess'  => $htaccess . "\n",
			'web.config' => $web_config . "\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
		);

		foreach ( $files as $name => $contents ) {
			$path = trailingslashit( $dir ) . $name;

			if ( ! file_exists( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Hardening files written during activation, before WP_Filesystem is relevant.
				file_put_contents( $path, $contents );
			}
		}
	}
}
