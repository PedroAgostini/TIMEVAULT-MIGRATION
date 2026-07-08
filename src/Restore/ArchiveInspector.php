<?php
/**
 * Backup package inspection and safe extraction.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Restore;

use Timevault\Core\EncryptionService;

defined( 'ABSPATH' ) || exit;

/**
 * Opens a backup package as hostile input and decides whether it is safe to
 * restore, then extracts it entry-by-entry with full validation.
 *
 * Order of checks (fail closed at the first problem):
 * 1. SHA-256 checksum of the on-disk artifact matches the expected value -
 *    BEFORE anything else touches the bytes.
 * 2. Decrypt (if encrypted) to a staging file; authenticated decryption
 *    already rejects tampering/truncation.
 * 3. Open as ZIP; validate EVERY central-directory entry name (PathGuard)
 *    and reject compression bombs above a configurable ratio/size.
 * 4. Read manifest.json as JSON only - never unserialize().
 */
final class ArchiveInspector {

	/**
	 * Hard ceiling on total uncompressed size (10 GiB) - compression-bomb guard.
	 */
	private const MAX_TOTAL_UNCOMPRESSED = 10737418240;

	/**
	 * Maximum uncompressed:compressed ratio tolerated for a single entry.
	 */
	private const MAX_RATIO = 200;

	/**
	 * Constructor.
	 *
	 * @param EncryptionService $encryption Decryption service.
	 */
	public function __construct( private EncryptionService $encryption ) {}

	/**
	 * Verifies the artifact checksum before any processing.
	 *
	 * @param string $path              Absolute path of the stored artifact.
	 * @param string $expected_checksum Expected SHA-256 (hex).
	 * @return true|\WP_Error
	 */
	public function verify_checksum( string $path, string $expected_checksum ): bool|\WP_Error {
		if ( ! is_readable( $path ) || ! is_file( $path ) ) {
			return new \WP_Error( 'timevault_restore_missing', __( 'Backup artifact not found.', 'timevault' ) );
		}

		if ( '' === $expected_checksum ) {
			return new \WP_Error( 'timevault_restore_no_checksum', __( 'No checksum on record for this backup; refusing to restore.', 'timevault' ) );
		}

		$actual = hash_file( 'sha256', $path );

		if ( false === $actual || ! hash_equals( $expected_checksum, $actual ) ) {
			return new \WP_Error( 'timevault_restore_checksum', __( 'Backup failed checksum verification; it may be corrupted or tampered with.', 'timevault' ) );
		}

		return true;
	}

	/**
	 * Produces a plaintext ZIP path from a stored artifact: decrypts when the
	 * artifact is encrypted, otherwise returns the original path.
	 *
	 * @param string $artifact_path Absolute path of the stored artifact.
	 * @param bool   $is_encrypted  Whether the artifact is encrypted.
	 * @param string $staging_zip   Absolute path to write the plaintext ZIP to (when decrypting).
	 * @return string|\WP_Error Path of a plaintext ZIP.
	 */
	public function to_plaintext_zip( string $artifact_path, bool $is_encrypted, string $staging_zip ): string|\WP_Error {
		if ( ! $is_encrypted ) {
			return $artifact_path;
		}

		$result = $this->encryption->decrypt_file( $artifact_path, $staging_zip );

		return is_wp_error( $result ) ? $result : $staging_zip;
	}

	/**
	 * Validates every entry of a plaintext ZIP without extracting anything.
	 *
	 * @param string $zip_path Absolute path of a plaintext ZIP.
	 * @return array{entries: int, uncompressed: int, manifest: array<string, mixed>}|\WP_Error
	 */
	public function inspect( string $zip_path ): array|\WP_Error {
		$zip = new \ZipArchive();

		if ( true !== $zip->open( $zip_path ) ) {
			return new \WP_Error( 'timevault_restore_badzip', __( 'The package is not a valid ZIP archive.', 'timevault' ) );
		}

		$total = 0;
		$count = $zip->numFiles; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native ZipArchive property.

		for ( $i = 0; $i < $count; $i++ ) {
			$stat = $zip->statIndex( $i );

			if ( false === $stat ) {
				$zip->close();
				return new \WP_Error( 'timevault_restore_badentry', __( 'Unreadable entry in the package.', 'timevault' ) );
			}

			$name  = (string) $stat['name'];
			$valid = PathGuard::validate_entry_name( $name );

			if ( is_wp_error( $valid ) ) {
				$zip->close();
				return $valid;
			}

			$size   = (int) $stat['size'];
			$comp   = (int) $stat['comp_size'];
			$total += $size;

			if ( $total > self::MAX_TOTAL_UNCOMPRESSED ) {
				$zip->close();
				return new \WP_Error( 'timevault_restore_bomb', __( 'Package exceeds the maximum uncompressed size (possible zip bomb).', 'timevault' ) );
			}

			// Ratio guard, ignoring tiny entries where the ratio is meaningless.
			if ( $comp > 0 && $size > 65536 && ( $size / $comp ) > self::MAX_RATIO ) {
				$zip->close();
				return new \WP_Error( 'timevault_restore_bomb', __( 'Package has an abnormal compression ratio (possible zip bomb).', 'timevault' ) );
			}
		}

		$manifest = $this->read_manifest( $zip );
		$zip->close();

		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		return array(
			'entries'      => $count,
			'uncompressed' => $total,
			'manifest'     => $manifest,
		);
	}

	/**
	 * Safely extracts validated entries into a staging directory. Each entry
	 * is re-validated (name + resolved target containment) at write time.
	 *
	 * @param string $zip_path    Absolute path of a plaintext ZIP.
	 * @param string $staging_dir Absolute staging directory (created if needed).
	 * @return true|\WP_Error
	 */
	public function extract_to_staging( string $zip_path, string $staging_dir ): bool|\WP_Error {
		wp_mkdir_p( $staging_dir );

		$zip = new \ZipArchive();

		if ( true !== $zip->open( $zip_path ) ) {
			return new \WP_Error( 'timevault_restore_badzip', __( 'The package is not a valid ZIP archive.', 'timevault' ) );
		}

		$count = $zip->numFiles; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native ZipArchive property.

		for ( $i = 0; $i < $count; $i++ ) {
			$name  = (string) $zip->getNameIndex( $i );
			$valid = PathGuard::validate_entry_name( $name );

			if ( is_wp_error( $valid ) ) {
				$zip->close();
				return $valid;
			}

			if ( str_ends_with( $name, '/' ) ) {
				$dir = PathGuard::safe_target( $staging_dir, $name );

				if ( is_wp_error( $dir ) ) {
					$zip->close();
					return $dir;
				}

				wp_mkdir_p( $dir );
				continue;
			}

			$target = PathGuard::safe_target( $staging_dir, $name );

			if ( is_wp_error( $target ) ) {
				$zip->close();
				return $target;
			}

			wp_mkdir_p( dirname( $target ) );

			// Stream the entry out instead of ZipArchive::extractTo, so the
			// validated $target is the ONLY path we ever write to.
			$written = $this->stream_entry( $zip, $i, $target );

			if ( is_wp_error( $written ) ) {
				$zip->close();
				return $written;
			}
		}

		$zip->close();

		return true;
	}

	/**
	 * Streams one ZIP entry to a validated target path.
	 *
	 * @param \ZipArchive $zip    Open archive.
	 * @param int         $index  Entry index.
	 * @param string      $target Validated absolute target path.
	 * @return true|\WP_Error
	 */
	private function stream_entry( \ZipArchive $zip, int $index, string $target ): bool|\WP_Error {
		// phpcs:disable WordPress.WP.AlternativeFunctions -- Streaming extraction to a pre-validated path; WP_Filesystem cannot stream a zip entry.
		$in = $zip->getStream( $zip->getNameIndex( $index ) );

		if ( ! is_resource( $in ) ) {
			return new \WP_Error( 'timevault_restore_entry_read', __( 'Could not read a package entry.', 'timevault' ) );
		}

		$out = fopen( $target, 'wb' );

		if ( false === $out ) {
			fclose( $in );
			return new \WP_Error( 'timevault_restore_entry_write', __( 'Could not write an extracted file.', 'timevault' ) );
		}

		while ( ! feof( $in ) ) {
			$chunk = fread( $in, 1048576 );

			if ( false === $chunk ) {
				break;
			}

			fwrite( $out, $chunk );
		}

		fclose( $in );
		fclose( $out );
		// phpcs:enable

		return true;
	}

	/**
	 * Reads and decodes manifest.json (JSON only - never unserialize()).
	 *
	 * @param \ZipArchive $zip Open archive.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function read_manifest( \ZipArchive $zip ): array|\WP_Error {
		$raw = $zip->getFromName( 'manifest.json' );

		if ( false === $raw ) {
			return new \WP_Error( 'timevault_restore_no_manifest', __( 'Package has no manifest.json; refusing to restore.', 'timevault' ) );
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'timevault_restore_bad_manifest', __( 'Package manifest is not valid JSON.', 'timevault' ) );
		}

		return $data;
	}
}
