<?php
/**
 * Converts third-party backup packages into the internal Timevault package.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Core;

use Timevault\Restore\PathGuard;
use Timevault\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes known third-party backup shapes into:
 * - database.sql
 * - uploads/*
 * - files/*
 * - manifest.json
 *
 * The original archive is never restored directly. Everything is first copied
 * into a guarded staging tree and then re-packed with Timevault's own packager.
 */
final class ExternalPackageNormalizer {

	/**
	 * Maximum bytes copied from a single external entry (10 GiB).
	 */
	private const MAX_ENTRY_BYTES = 10737418240;

	/**
	 * All-in-One WP Migration (.wpress) fixed header size, in bytes.
	 *
	 * Layout: name(255) + size(14) + mtime(12) + path/prefix(4096) = 4377.
	 * It is NOT tar. Content bytes follow each header with no padding; the
	 * archive ends with an all-null header block.
	 */
	private const WPRESS_HEADER = 4377;

	/**
	 * Converts a supported external package into a Timevault plaintext ZIP.
	 *
	 * @param string $source_path   Uploaded file path.
	 * @param string $original_name Client supplied file name.
	 * @param string $workdir       Import working directory.
	 * @return array{path: string, source_format: string, db_prefix: string, files: int}|\WP_Error
	 */
	public function normalize( string $source_path, string $original_name, string $workdir ): array|\WP_Error {
		$staging = $workdir . '/external-staging';
		$target  = $workdir . '/normalized-package.zip';
		$ext     = strtolower( (string) pathinfo( $original_name, PATHINFO_EXTENSION ) );

		wp_mkdir_p( $staging );

		$result = 'wpress' === $ext
			? $this->extract_wpress( $source_path, $staging )
			: $this->extract_zip( $source_path, $staging );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$sql_path = $staging . '/database.sql';
		$trees    = array();

		if ( is_dir( $staging . '/files' ) ) {
			$trees['files'] = array( 'root' => $staging . '/files' );
		}

		if ( is_dir( $staging . '/uploads' ) ) {
			$trees['uploads'] = array( 'root' => $staging . '/uploads' );
		}

		$named_files = is_file( $sql_path ) ? array( 'database.sql' => $sql_path ) : array();

		if ( array() === $named_files && array() === $trees ) {
			return new \WP_Error(
				'timevault_import_external_empty',
				__( 'The external backup did not contain a recognizable database dump or wp-content files.', 'timevault' )
			);
		}

		$db_prefix = is_file( $sql_path ) ? $this->infer_db_prefix( $sql_path ) : '';
		$manifest  = array(
			'format'   => 1,
			'type'     => array() === $trees ? 'db' : 'full',
			'created'  => gmdate( 'c' ),
			'site'     => array(
				'home_url'  => '',
				'db_prefix' => $db_prefix,
			),
			'database' => array(
				'present' => is_file( $sql_path ),
			),
			'security' => array(
				'wp_config_excluded' => true,
				'serialization'      => 'json',
			),
			'external' => array(
				'source_format' => (string) $result['source_format'],
				'original_name' => sanitize_file_name( $original_name ),
			),
		);

		$stats = ( new FilePackager() )->package( $target, $named_files, $trees, $manifest );

		if ( is_wp_error( $stats ) ) {
			return $stats;
		}

		return array(
			'path'          => $target,
			'source_format' => (string) $result['source_format'],
			'db_prefix'     => $db_prefix,
			'files'         => (int) $stats['count'],
		);
	}

	/**
	 * Extracts a ZIP-based external package into a normalized staging tree.
	 *
	 * @param string $source_path Package path.
	 * @param string $staging     Staging directory.
	 * @return array{source_format: string}|\WP_Error
	 */
	private function extract_zip( string $source_path, string $staging ): array|\WP_Error {
		$zip = new \ZipArchive();

		if ( true !== $zip->open( $source_path ) ) {
			return new \WP_Error(
				'timevault_import_external_badzip',
				__( 'The external package is not a readable ZIP archive.', 'timevault' )
			);
		}

		$count          = $zip->numFiles; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native property.
		$source_format  = 'external_zip';
		$sql_written    = false;
		$mapped_entries = 0;

		for ( $i = 0; $i < $count; $i++ ) {
			$stat = $zip->statIndex( $i );

			if ( false === $stat ) {
				$zip->close();
				return new \WP_Error( 'timevault_import_external_entry', __( 'Unreadable entry in the external package.', 'timevault' ) );
			}

			$name = $this->clean_entry_name( (string) $stat['name'] );

			if ( null === $name || str_ends_with( $name, '/' ) ) {
				continue;
			}

			$mapped = $this->map_external_entry( $name, ! $sql_written, false );

			if ( null === $mapped ) {
				continue;
			}

			if ( 'database.sql' === $mapped ) {
				$sql_written = true;
			}

			$target = $this->safe_staging_target( $staging, $mapped );

			if ( is_wp_error( $target ) ) {
				$zip->close();
				return $target;
			}

			$copied = $this->copy_zip_entry( $zip, $i, $target, (int) $stat['size'] );

			if ( is_wp_error( $copied ) ) {
				$zip->close();
				return $copied;
			}

			++$mapped_entries;

			if ( str_contains( $name, 'wpvivid' ) ) {
				$source_format = 'wpvivid';
			}
		}

		$zip->close();

		if ( 0 === $mapped_entries ) {
			return new \WP_Error(
				'timevault_import_external_unknown',
				__( 'This ZIP does not look like a supported WordPress backup package.', 'timevault' )
			);
		}

		return array( 'source_format' => $source_format );
	}

	/**
	 * Extracts an All-in-One WP Migration (.wpress) archive into a normalized
	 * staging tree.
	 *
	 * The format is a sequence of 4377-byte headers (see WPRESS_HEADER), each
	 * immediately followed by exactly `size` content bytes (no padding). The
	 * archive ends with an all-null header block.
	 *
	 * @param string $source_path Package path.
	 * @param string $staging     Staging directory.
	 * @return array{source_format: string}|\WP_Error
	 */
	private function extract_wpress( string $source_path, string $staging ): array|\WP_Error {
		// phpcs:disable WordPress.WP.AlternativeFunctions -- WPRESS parsing needs stream seeks and fixed-size binary reads.
		$handle = fopen( $source_path, 'rb' );

		if ( false === $handle ) {
			return new \WP_Error( 'timevault_import_wpress_open', __( 'Could not open the WPRESS archive.', 'timevault' ) );
		}

		$sql_written    = false;
		$mapped_entries = 0;

		try {
			while ( true ) {
				$header = $this->read_wpress_header( $handle );

				if ( null === $header ) {
					break; // Clean end of the archive.
				}

				if ( is_wp_error( $header ) ) {
					return $header;
				}

				if ( '' === trim( $header, "\0" ) ) {
					break; // All-null header: the end-of-archive marker.
				}

				$name = $this->clean_entry_name( $this->read_wpress_name( $header ) );
				$size = $this->read_wpress_size( $header );

				if ( is_wp_error( $size ) ) {
					return $size;
				}

				$mapped = null === $name ? null : $this->map_external_entry( $name, ! $sql_written, true );

				if ( null === $mapped ) {
					if ( $size > 0 && 0 !== fseek( $handle, (int) $size, SEEK_CUR ) ) {
						return new \WP_Error( 'timevault_import_wpress_seek', __( 'Could not read through the WPRESS archive.', 'timevault' ) );
					}
					continue;
				}

				if ( 'database.sql' === $mapped ) {
					$sql_written = true;
				}

				$target = $this->safe_staging_target( $staging, $mapped );

				if ( is_wp_error( $target ) ) {
					return $target;
				}

				$copied = $this->copy_stream_bytes( $handle, $target, (int) $size );

				if ( is_wp_error( $copied ) ) {
					return $copied;
				}

				++$mapped_entries;
			}
		} finally {
			fclose( $handle );
		}
		// phpcs:enable

		if ( 0 === $mapped_entries ) {
			return new \WP_Error(
				'timevault_import_wpress_unknown',
				__( 'The WPRESS archive did not contain recognizable WordPress content.', 'timevault' )
			);
		}

		return array( 'source_format' => 'all-in-one-wp-migration' );
	}

	/**
	 * Reads exactly one WPRESS header (4377 bytes).
	 *
	 * @param resource $handle Archive handle.
	 * @return string|\WP_Error|null Header bytes, error on a truncated header, or null at clean EOF.
	 */
	private function read_wpress_header( $handle ): string|\WP_Error|null {
		// phpcs:disable WordPress.WP.AlternativeFunctions -- Fixed-size binary read.
		$header = '';

		while ( strlen( $header ) < self::WPRESS_HEADER ) {
			$chunk = fread( $handle, self::WPRESS_HEADER - strlen( $header ) );

			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			$header .= $chunk;
		}
		// phpcs:enable

		if ( '' === $header ) {
			return null;
		}

		if ( self::WPRESS_HEADER !== strlen( $header ) ) {
			return new \WP_Error( 'timevault_import_wpress_header', __( 'The WPRESS archive has a truncated file header.', 'timevault' ) );
		}

		return $header;
	}

	/**
	 * Maps a third-party entry to a Timevault-safe relative entry.
	 *
	 * @param string $name                 Clean external entry name.
	 * @param bool   $allow_database       Whether a database dump has not yet been selected.
	 * @param bool   $wp_content_relative  True when the source stores paths relative to
	 *                                     wp-content (All-in-One WP Migration): then EVERY
	 *                                     entry is site content and is imported.
	 * @return string|null
	 */
	private function map_external_entry( string $name, bool $allow_database, bool $wp_content_relative ): ?string {
		$lower    = strtolower( $name );
		$basename = basename( $lower );

		if ( 'wp-config.php' === $basename ) {
			return null;
		}

		// Root-level metadata written by migration tools — not site content.
		if ( false === strpos( $name, '/' ) && in_array( $basename, array( 'package.json', 'multisite.json', 'blogs.json' ), true ) ) {
			return null;
		}

		if ( $allow_database && str_ends_with( $lower, '.sql' ) ) {
			if (
				in_array( $basename, array( 'database.sql', 'db.sql' ), true )
				|| str_contains( $lower, 'database' )
				|| str_contains( $lower, 'db_backup' )
				|| str_contains( $lower, 'wpvivid' )
			) {
				return 'database.sql';
			}
		}

		$wp_content_pos = strpos( $lower, 'wp-content/' );

		if ( false !== $wp_content_pos ) {
			return $this->map_wp_content_entry( substr( $name, $wp_content_pos + strlen( 'wp-content/' ) ) );
		}

		// A WPRESS archive stores everything relative to wp-content, so every
		// remaining entry is site content — import all of it.
		if ( $wp_content_relative ) {
			return $this->map_wp_content_entry( $name );
		}

		// A ZIP without a wp-content/ prefix might be a full-site export; only
		// take known content directories so wp-admin/wp-includes/root files and
		// secrets are not pulled in.
		foreach ( array( 'uploads/', 'plugins/', 'themes/', 'mu-plugins/', 'languages/' ) as $prefix ) {
			if ( str_starts_with( $lower, $prefix ) ) {
				return $this->map_wp_content_entry( $name );
			}
		}

		return null;
	}

	/**
	 * Maps a path relative to wp-content.
	 *
	 * @param string $inside Relative path.
	 * @return string|null
	 */
	private function map_wp_content_entry( string $inside ): ?string {
		$inside = ltrim( str_replace( '\\', '/', $inside ), '/' );
		$lower  = strtolower( $inside );

		if ( '' === $inside || 'wp-config.php' === basename( $lower ) ) {
			return null;
		}

		// Never re-import a backup tool's own archive folders (avoids nesting
		// backups-of-backups).
		foreach ( array( 'ai1wm-backups/', 'updraft/', 'timevault-' ) as $skip ) {
			if ( str_starts_with( $lower, $skip ) ) {
				return null;
			}
		}

		// Media goes to the uploads tree so restore places it in the uploads dir.
		if ( str_starts_with( $lower, 'uploads/' ) ) {
			return 'uploads/' . substr( $inside, strlen( 'uploads/' ) );
		}

		// Everything else under wp-content (plugins, themes, mu-plugins,
		// languages, and any custom folder or root file) is imported into
		// wp-content so the migration brings the whole site over.
		return 'files/' . $inside;
	}

	/**
	 * Cleans an archive entry and rejects obvious traversal.
	 *
	 * @param string $name Raw entry name.
	 * @return string|null
	 */
	private function clean_entry_name( string $name ): ?string {
		$name = str_replace( '\\', '/', trim( $name ) );
		$name = ltrim( $name, './' );

		if ( '' === $name || str_contains( $name, "\0" ) || str_starts_with( $name, '/' ) || 1 === preg_match( '/^[A-Za-z]:/', $name ) ) {
			return null;
		}

		foreach ( explode( '/', $name ) as $segment ) {
			if ( '..' === $segment ) {
				return null;
			}
		}

		return $name;
	}

	/**
	 * Validates a normalized staging target with the existing Timevault guard.
	 *
	 * @param string $staging Staging directory.
	 * @param string $mapped  Timevault relative entry.
	 * @return string|\WP_Error
	 */
	private function safe_staging_target( string $staging, string $mapped ): string|\WP_Error {
		$valid = PathGuard::validate_entry_name( $mapped );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		wp_mkdir_p( dirname( $staging . '/' . $mapped ) );

		return PathGuard::safe_target( $staging, $mapped );
	}

	/**
	 * Copies one ZIP entry to disk.
	 *
	 * @param \ZipArchive $zip    Open ZIP.
	 * @param int         $index  Entry index.
	 * @param string      $target Target path.
	 * @param int         $size   Expected uncompressed size.
	 * @return true|\WP_Error
	 */
	private function copy_zip_entry( \ZipArchive $zip, int $index, string $target, int $size ): bool|\WP_Error {
		// phpcs:disable WordPress.WP.AlternativeFunctions -- Streaming external archive entry.
		$stream = $zip->getStream( $zip->getNameIndex( $index ) );

		if ( ! is_resource( $stream ) ) {
			return new \WP_Error( 'timevault_import_external_read', __( 'Could not read an external package entry.', 'timevault' ) );
		}

		$result = $this->copy_stream_bytes( $stream, $target, $size );
		fclose( $stream );
		// phpcs:enable

		return $result;
	}

	/**
	 * Copies a fixed number of bytes from a stream to a target file.
	 *
	 * @param resource $stream Source stream.
	 * @param string   $target Target path.
	 * @param int      $size   Bytes to copy.
	 * @return true|\WP_Error
	 */
	private function copy_stream_bytes( $stream, string $target, int $size ): bool|\WP_Error {
		// phpcs:disable WordPress.WP.AlternativeFunctions -- Streaming package entry bytes.
		if ( $size < 0 || $size > self::MAX_ENTRY_BYTES ) {
			return new \WP_Error( 'timevault_import_external_size', __( 'An external package entry is too large to import safely.', 'timevault' ) );
		}

		$out = fopen( $target, 'wb' );

		if ( false === $out ) {
			return new \WP_Error( 'timevault_import_external_write', __( 'Could not write a normalized package entry.', 'timevault' ) );
		}

		$remaining = $size;

		while ( $remaining > 0 ) {
			$chunk = fread( $stream, min( 1048576, $remaining ) );

			if ( false === $chunk || '' === $chunk ) {
				fclose( $out );
				return new \WP_Error( 'timevault_import_external_truncated', __( 'The external package ended unexpectedly.', 'timevault' ) );
			}

			$remaining -= strlen( $chunk );
			fwrite( $out, $chunk );
		}

		fclose( $out );
		// phpcs:enable

		return true;
	}

	/**
	 * Reads the full entry path from a WPRESS header.
	 *
	 * name is bytes 0..255; the directory prefix is bytes 281..4377. The full
	 * path is prefix + '/' + name (a prefix of '.' means the archive root).
	 *
	 * @param string $header 4377-byte header.
	 * @return string
	 */
	private function read_wpress_name( string $header ): string {
		$name   = rtrim( substr( $header, 0, 255 ), "\0" );
		$prefix = trim( rtrim( substr( $header, 281, 4096 ), "\0" ) );

		if ( '' === $prefix || '.' === $prefix ) {
			return $name;
		}

		return rtrim( $prefix, '/' ) . '/' . $name;
	}

	/**
	 * Reads the decimal size field from a WPRESS header (bytes 255..269).
	 *
	 * @param string $header 4377-byte header.
	 * @return int|\WP_Error
	 */
	private function read_wpress_size( string $header ): int|\WP_Error {
		$raw = trim( rtrim( substr( $header, 255, 14 ), "\0" ) );

		if ( '' === $raw ) {
			return 0;
		}

		if ( 1 !== preg_match( '/^\d+$/', $raw ) ) {
			return new \WP_Error( 'timevault_import_wpress_size', __( 'The WPRESS archive has an invalid entry size.', 'timevault' ) );
		}

		return (int) $raw;
	}

	/**
	 * Infers a WordPress table prefix from a SQL dump.
	 *
	 * @param string $sql_path SQL dump path.
	 * @return string
	 */
	private function infer_db_prefix( string $sql_path ): string {
		// phpcs:disable WordPress.WP.AlternativeFunctions -- Streaming SQL dump.
		$handle = fopen( $sql_path, 'rb' );

		if ( false === $handle ) {
			return '';
		}

		$suffixes = array( 'options', 'posts', 'postmeta', 'users', 'usermeta', 'terms', 'term_taxonomy', 'term_relationships', 'comments', 'commentmeta' );
		$seen     = array();
		$limit    = 0;

		while ( ! feof( $handle ) && $limit < 2097152 ) {
			$line = fgets( $handle );

			if ( false === $line ) {
				break;
			}

			$limit += strlen( $line );

			if ( 1 !== preg_match_all( '/`([^`]+)`/', $line, $matches ) && empty( $matches[1] ) ) {
				continue;
			}

			foreach ( $matches[1] as $table ) {
				foreach ( $suffixes as $suffix ) {
					if ( str_ends_with( $table, $suffix ) && strlen( $table ) > strlen( $suffix ) ) {
						$prefix          = substr( $table, 0, -strlen( $suffix ) );
						$seen[ $prefix ] = ( $seen[ $prefix ] ?? 0 ) + 1;
					}
				}
			}
		}

		fclose( $handle );
		// phpcs:enable

		if ( array() === $seen ) {
			return '';
		}

		arsort( $seen );

		return (string) array_key_first( $seen );
	}
}
