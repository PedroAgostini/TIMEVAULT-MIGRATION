<?php
/**
 * Import/restore engine (contract; implementation lands in P2).
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Core;

defined( 'ABSPATH' ) || exit;

/**
 * HIGHEST-RISK COMPONENT of the plugin. Every input is treated as hostile.
 *
 * P2 contract (non-negotiable):
 * - Validate EVERY zip entry before extraction (reject `../`, absolute paths
 *   and symlinks — zip-slip / path traversal).
 * - Validate the package SHA-256 checksum BEFORE any processing.
 * - Never unserialize untrusted PHP objects; JSON only.
 * - SQL restore through a safe parser + $wpdb->prepare; never eval or raw dump execution.
 * - Double confirmation in the UI + automatic safety backup of the current
 *   state before anything is overwritten.
 * - Rate limiting and audit logging of every restore attempt.
 */
final class ImportManager {

	/**
	 * Validates a backup package (checksum, structure, zip entries) without
	 * touching the site. Implemented in P2.
	 *
	 * @param string $package_path Absolute path of the uploaded/retrieved package.
	 * @return true|\WP_Error
	 */
	public function validate_package( string $package_path ): bool|\WP_Error {
		unset( $package_path );

		return new \WP_Error( 'timevault_not_implemented', __( 'Package validation is implemented in phase P2.', 'timevault' ) );
	}

	/**
	 * Enqueues a restore job for a previously validated package.
	 *
	 * @param string               $backup_uuid Backup identifier from the registry.
	 * @param array<string, mixed> $options     Restore options.
	 * @return string|\WP_Error Restore job UUID on success.
	 */
	public function schedule_restore( string $backup_uuid, array $options = array() ): string|\WP_Error {
		unset( $backup_uuid, $options );

		return new \WP_Error( 'timevault_not_implemented', __( 'Restore is implemented in phase P2.', 'timevault' ) );
	}
}
