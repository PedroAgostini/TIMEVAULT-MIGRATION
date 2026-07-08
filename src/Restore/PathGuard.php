<?php
/**
 * Path-traversal / zip-slip defense.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * Central defense against zip-slip and path traversal during restore.
 *
 * Two layers, both required:
 * 1. validate_entry_name(): rejects an archive entry name BEFORE extraction -
 *    absolute paths, drive letters, `..` segments, backslashes, NUL bytes,
 *    and anything that does not sit under an allowed top-level prefix.
 * 2. safe_target(): after joining the (already validated) relative name to a
 *    destination root, it canonicalizes and re-checks containment, so even a
 *    name that slipped through step 1 cannot escape the destination.
 *
 * Nothing here ever writes: it only decides whether a path is safe.
 */
final class PathGuard {

	/**
	 * Top-level archive prefixes a Timevault package may legitimately contain.
	 */
	public const ALLOWED_PREFIXES = array( 'files/', 'uploads/' );

	/**
	 * Bare entries (no prefix) that are allowed at the archive root.
	 */
	private const ALLOWED_ROOT_FILES = array( 'database.sql', 'manifest.json' );

	/**
	 * Validates an archive entry name before any extraction.
	 *
	 * @param string $name Raw entry name from the ZIP central directory.
	 * @return true|\WP_Error True when safe.
	 */
	public static function validate_entry_name( string $name ): bool|\WP_Error {
		$reject = static fn( string $why ): \WP_Error => new \WP_Error(
			'timevault_unsafe_entry',
			/* translators: 1: archive entry name, 2: reason. */
			sprintf( __( 'Rejected unsafe archive entry "%1$s": %2$s', 'timevault' ), $name, $why )
		);

		if ( '' === $name ) {
			return $reject( __( 'empty name', 'timevault' ) );
		}

		if ( str_contains( $name, "\0" ) ) {
			return $reject( __( 'NUL byte', 'timevault' ) );
		}

		if ( str_contains( $name, '\\' ) ) {
			return $reject( __( 'backslash', 'timevault' ) );
		}

		// Absolute paths (Unix `/...` or Windows `C:...`) must never be trusted.
		if ( str_starts_with( $name, '/' ) ) {
			return $reject( __( 'absolute path', 'timevault' ) );
		}

		if ( 1 === preg_match( '/^[A-Za-z]:/', $name ) ) {
			return $reject( __( 'drive letter', 'timevault' ) );
		}

		// Any `..` segment anywhere is a traversal attempt.
		foreach ( explode( '/', $name ) as $segment ) {
			if ( '..' === $segment ) {
				return $reject( __( 'parent-directory segment', 'timevault' ) );
			}
		}

		// A trailing slash denotes a directory entry - allowed structurally.
		$is_dir = str_ends_with( $name, '/' );

		if ( ! $is_dir && in_array( $name, self::ALLOWED_ROOT_FILES, true ) ) {
			return true;
		}

		foreach ( self::ALLOWED_PREFIXES as $prefix ) {
			if ( str_starts_with( $name, $prefix ) ) {
				return true;
			}
			if ( $is_dir && rtrim( $prefix, '/' ) . '/' === $name ) {
				return true; // The prefix directory itself.
			}
		}

		return $reject( __( 'outside allowed prefixes', 'timevault' ) );
	}

	/**
	 * Resolves a validated relative name against a destination root and
	 * guarantees the result stays inside that root (defense in depth).
	 *
	 * @param string $dest_root     Absolute destination root (must exist).
	 * @param string $relative_name Already-validated relative entry name.
	 * @return string|\WP_Error Absolute, contained target path.
	 */
	public static function safe_target( string $dest_root, string $relative_name ): string|\WP_Error {
		$root_real = realpath( $dest_root );

		if ( false === $root_real ) {
			return new \WP_Error( 'timevault_restore_no_dest', __( 'Restore destination does not exist.', 'timevault' ) );
		}

		$root_norm = trailingslashit( wp_normalize_path( $root_real ) );
		$target    = wp_normalize_path( $root_norm . ltrim( $relative_name, '/' ) );

		// Canonicalize the parent (the target file itself may not exist yet).
		$parent      = dirname( $target );
		$parent_real = realpath( $parent );

		if ( false !== $parent_real ) {
			$parent_norm = trailingslashit( wp_normalize_path( $parent_real ) );

			if ( ! str_starts_with( $parent_norm, $root_norm ) ) {
				return new \WP_Error( 'timevault_restore_escape', __( 'Archive entry resolves outside the destination.', 'timevault' ) );
			}
		} elseif ( ! str_starts_with( trailingslashit( $target ), $root_norm ) ) {
			// Parent not yet created: check the lexically-normalized target.
			return new \WP_Error( 'timevault_restore_escape', __( 'Archive entry resolves outside the destination.', 'timevault' ) );
		}

		return $target;
	}
}
