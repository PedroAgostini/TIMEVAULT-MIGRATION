<?php
/**
 * ZIP packaging for backup artifacts.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the final backup package with ZipArchive.
 *
 * Security decisions:
 * - Symlinks are never followed or added: they can point outside the tree
 *   (our own zip-slip, in reverse) and cause traversal or loops.
 * - wp-config.php never enters a package, anywhere in the tree — secrets are
 *   excluded rather than shipped (the manifest records the exclusion).
 * - Excluded directories (the backup directory itself, caches) are pruned at
 *   iteration level, so backup archives are never re-packaged recursively.
 * - The manifest is JSON — never PHP serialization.
 */
final class FilePackager {

	/**
	 * Creates a ZIP package.
	 *
	 * @param string                                                     $zip_path    Absolute path of the .zip to create.
	 * @param array<string, string>                                      $named_files Map of archive entry => absolute file path.
	 * @param array<string, array{root: string, exclude_paths?: array}>  $trees       Map of archive prefix => tree spec.
	 * @param array<string, mixed>                                       $manifest    Manifest data; file stats are appended and it is embedded as manifest.json.
	 * @return array{count: int, bytes: int}|\WP_Error Package statistics.
	 */
	public function package( string $zip_path, array $named_files, array $trees, array $manifest ): array|\WP_Error {
		if ( ! class_exists( \ZipArchive::class ) ) {
			return new \WP_Error( 'timevault_zip_missing', __( 'The PHP zip extension is not available on this server.', 'timevault' ) );
		}

		$zip = new \ZipArchive();

		if ( true !== $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			return new \WP_Error( 'timevault_zip_open_failed', __( 'Could not create the backup package.', 'timevault' ) );
		}

		$count = 0;
		$bytes = 0;

		foreach ( $named_files as $entry => $path ) {
			if ( ! is_readable( $path ) || ! is_file( $path ) ) {
				$zip->close();
				return new \WP_Error( 'timevault_zip_add_failed', __( 'A package component is missing or unreadable.', 'timevault' ) );
			}

			if ( ! $zip->addFile( $path, $entry ) ) {
				$zip->close();
				return new \WP_Error( 'timevault_zip_add_failed', __( 'Could not add a file to the backup package.', 'timevault' ) );
			}

			++$count;
			$bytes += (int) filesize( $path );
		}

		foreach ( $trees as $prefix => $spec ) {
			$result = $this->add_tree( $zip, (string) $prefix, (string) $spec['root'], (array) ( $spec['exclude_paths'] ?? array() ), $count, $bytes );

			if ( is_wp_error( $result ) ) {
				$zip->close();
				return $result;
			}
		}

		$manifest['files'] = array(
			'count' => $count,
			'bytes' => $bytes,
		);

		$zip->addFromString( 'manifest.json', (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

		if ( ! $zip->close() ) {
			return new \WP_Error( 'timevault_zip_close_failed', __( 'Could not finalize the backup package (disk full?).', 'timevault' ) );
		}

		return array(
			'count' => $count,
			'bytes' => $bytes,
		);
	}

	/**
	 * Adds a directory tree under an archive prefix.
	 *
	 * @param \ZipArchive   $zip           Open archive.
	 * @param string        $prefix        Archive path prefix (e.g. 'files').
	 * @param string        $root          Absolute tree root.
	 * @param array<string> $exclude_paths Absolute paths to prune.
	 * @param int           $count         File counter (by reference).
	 * @param int           $bytes         Byte counter (by reference).
	 * @return true|\WP_Error
	 */
	private function add_tree( \ZipArchive $zip, string $prefix, string $root, array $exclude_paths, int &$count, int &$bytes ): bool|\WP_Error {
		$root_real = realpath( $root );

		if ( false === $root_real || ! is_dir( $root_real ) ) {
			return new \WP_Error( 'timevault_zip_tree_missing', __( 'A directory selected for backup does not exist.', 'timevault' ) );
		}

		$root_norm = trailingslashit( wp_normalize_path( $root_real ) );
		$excludes  = array();

		foreach ( $exclude_paths as $exclude ) {
			$real = realpath( $exclude );

			if ( false !== $real ) {
				$excludes[] = trailingslashit( wp_normalize_path( $real ) );
			}
		}

		$directory = new \RecursiveDirectoryIterator( $root_real, \FilesystemIterator::SKIP_DOTS );

		// Pruning at filter level: excluded directories (backup dir, caches)
		// are never even descended into.
		$filter = new \RecursiveCallbackFilterIterator(
			$directory,
			static function ( \SplFileInfo $file ) use ( $excludes ): bool {
				if ( $file->isLink() ) {
					return false; // Symlinks can escape the tree — never follow, never add.
				}

				if ( 'wp-config.php' === $file->getBasename() ) {
					return false; // Secrets never enter a package.
				}

				$path = trailingslashit( wp_normalize_path( $file->getPathname() ) );

				foreach ( $excludes as $exclude ) {
					if ( str_starts_with( $path, $exclude ) ) {
						return false;
					}
				}

				return true;
			}
		);

		$iterator = new \RecursiveIteratorIterator( $filter, \RecursiveIteratorIterator::LEAVES_ONLY );

		foreach ( $iterator as $file ) {
			/** @var \SplFileInfo $file */
			if ( ! $file->isFile() ) {
				continue;
			}

			$path_norm = wp_normalize_path( $file->getPathname() );
			$relative  = substr( $path_norm, strlen( $root_norm ) );

			if ( '' === $relative ) {
				continue;
			}

			if ( ! $zip->addFile( $file->getPathname(), $prefix . '/' . $relative ) ) {
				return new \WP_Error( 'timevault_zip_add_failed', __( 'Could not add a file to the backup package.', 'timevault' ) );
			}

			++$count;
			$bytes += (int) $file->getSize();
		}

		return true;
	}
}
