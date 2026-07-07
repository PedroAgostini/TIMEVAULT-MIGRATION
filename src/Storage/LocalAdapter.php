<?php
/**
 * Local disk storage adapter.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Storage;

use Timevault\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * Stores backups in the hardened local directory (see Paths). The only
 * adapter enabled by default: data never leaves the server.
 */
final class LocalAdapter implements StorageAdapterInterface {

	/**
	 * Hardening files that must never be listed or touched as backups.
	 */
	private const RESERVED_FILES = array( '.htaccess', 'web.config', 'index.php' );

	/**
	 * Adapter id.
	 */
	public function id(): string {
		return 'local';
	}

	/**
	 * Admin-facing label.
	 */
	public function label(): string {
		return __( 'Local (server disk)', 'timevault' );
	}

	/**
	 * Local storage never leaves the site's server — no international
	 * transfer to record (LGPD Art. 33).
	 */
	public function region(): ?string {
		return null;
	}

	/**
	 * Copies a file into the hardened backup directory.
	 *
	 * @param string $local_path       Absolute path of the file to store.
	 * @param string $destination_name File name at the destination (no paths).
	 * @return string|\WP_Error Stored file name on success.
	 */
	public function store( string $local_path, string $destination_name ): string|\WP_Error {
		if ( ! is_readable( $local_path ) || ! is_file( $local_path ) ) {
			return new \WP_Error( 'timevault_storage_unreadable', __( 'Source file is not readable.', 'timevault' ) );
		}

		$name = $this->safe_name( $destination_name );

		if ( is_wp_error( $name ) ) {
			return $name;
		}

		$target = trailingslashit( Paths::ensure_backup_dir() ) . $name;

		if ( ! copy( $local_path, $target ) ) {
			return new \WP_Error( 'timevault_storage_write_failed', __( 'Could not write to the backup directory.', 'timevault' ) );
		}

		return $name;
	}

	/**
	 * Copies a stored backup to a local path.
	 *
	 * @param string $remote_id  File name returned by store().
	 * @param string $local_path Absolute destination path.
	 * @return true|\WP_Error
	 */
	public function retrieve( string $remote_id, string $local_path ): bool|\WP_Error {
		$name = $this->safe_name( $remote_id );

		if ( is_wp_error( $name ) ) {
			return $name;
		}

		$source = trailingslashit( Paths::backup_dir() ) . $name;

		if ( ! is_readable( $source ) || ! is_file( $source ) ) {
			return new \WP_Error( 'timevault_storage_not_found', __( 'Backup file not found.', 'timevault' ) );
		}

		if ( ! copy( $source, $local_path ) ) {
			return new \WP_Error( 'timevault_storage_read_failed', __( 'Could not copy the backup file.', 'timevault' ) );
		}

		return true;
	}

	/**
	 * Deletes a stored backup (retention/expiration).
	 *
	 * @param string $remote_id File name returned by store().
	 * @return true|\WP_Error
	 */
	public function delete( string $remote_id ): bool|\WP_Error {
		$name = $this->safe_name( $remote_id );

		if ( is_wp_error( $name ) ) {
			return $name;
		}

		$path = trailingslashit( Paths::backup_dir() ) . $name;

		if ( ! is_file( $path ) ) {
			return new \WP_Error( 'timevault_storage_not_found', __( 'Backup file not found.', 'timevault' ) );
		}

		wp_delete_file( $path );

		return true;
	}

	/**
	 * Lists backups in the local directory (hardening files excluded).
	 *
	 * @return array<int, array{name: string, size: int, modified: int}>
	 */
	public function list_backups(): array {
		$dir = Paths::backup_dir();

		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$entries = scandir( $dir );
		$items   = array();

		foreach ( ( false === $entries ? array() : $entries ) as $entry ) {
			if ( str_starts_with( $entry, '.' ) || in_array( $entry, self::RESERVED_FILES, true ) ) {
				continue;
			}

			$path = trailingslashit( $dir ) . $entry;

			if ( ! is_file( $path ) ) {
				continue;
			}

			$items[] = array(
				'name'     => $entry,
				'size'     => (int) filesize( $path ),
				'modified' => (int) filemtime( $path ),
			);
		}

		return $items;
	}

	/**
	 * Absolute path of a stored backup — local-only shortcut used by the
	 * download endpoint to stream without duplicating large files.
	 *
	 * @param string $remote_id File name returned by store().
	 * @return string|\WP_Error
	 */
	public function local_path( string $remote_id ): string|\WP_Error {
		$name = $this->safe_name( $remote_id );

		if ( is_wp_error( $name ) ) {
			return $name;
		}

		$path = trailingslashit( Paths::backup_dir() ) . $name;

		if ( ! is_file( $path ) ) {
			return new \WP_Error( 'timevault_storage_not_found', __( 'Backup file not found.', 'timevault' ) );
		}

		return $path;
	}

	/**
	 * Shared traversal defense (SafeName) plus local hardening-file guard.
	 *
	 * @param string $name Candidate file name.
	 * @return string|\WP_Error
	 */
	private function safe_name( string $name ): string|\WP_Error {
		$clean = SafeName::validate( $name );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		if ( in_array( $clean, self::RESERVED_FILES, true ) ) {
			return new \WP_Error( 'timevault_storage_bad_name', __( 'Invalid backup file name.', 'timevault' ) );
		}

		return $clean;
	}
}
