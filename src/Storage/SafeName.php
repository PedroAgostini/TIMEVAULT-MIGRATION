<?php
/**
 * Shared backup file-name validation.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Path-traversal defense shared by every storage adapter: a backup file name
 * must be a plain, already-sanitized basename. Anything else is rejected —
 * adapters never "fix" a suspicious name silently.
 */
final class SafeName {

	/**
	 * Validates a backup file name.
	 *
	 * @param string $name Candidate file name.
	 * @return string|\WP_Error The validated name.
	 */
	public static function validate( string $name ): string|\WP_Error {
		$clean = sanitize_file_name( basename( $name ) );

		if ( '' === $clean || $clean !== $name ) {
			return new \WP_Error( 'timevault_storage_bad_name', __( 'Invalid backup file name.', 'timevault' ) );
		}

		return $clean;
	}
}
