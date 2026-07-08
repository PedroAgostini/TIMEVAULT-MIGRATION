<?php
/**
 * Automatic TIMEVAULT_ENCRYPTION_KEY installer.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Support;

use Timevault\Core\EncryptionService;

defined( 'ABSPATH' ) || exit;

/**
 * Generates and writes the encryption key to wp-config.php when possible.
 *
 * The key still lives only in configuration, never in the database. If the
 * config file is not writable, activation continues and the dashboard can
 * keep reporting that manual configuration is required.
 */
final class EncryptionKeyInstaller {

	public const STATUS_OPTION = 'timevault_key_install_status';

	/**
	 * Ensures TIMEVAULT_ENCRYPTION_KEY exists, generating it on activation when
	 * wp-config.php is writable.
	 *
	 * @return array{status:string,code?:string,message?:string}
	 */
	public static function ensure_configured(): array {
		if ( ( new EncryptionService() )->is_configured() ) {
			delete_option( self::STATUS_OPTION );

			return array( 'status' => 'already_configured' );
		}

		$config_path = self::locate_wp_config();

		if ( is_wp_error( $config_path ) ) {
			return self::remember_failure( $config_path );
		}

		$key = self::install_in_file( $config_path );

		if ( is_wp_error( $key ) ) {
			return self::remember_failure( $key );
		}

		if ( ! defined( EncryptionService::KEY_CONSTANT ) ) {
			define( 'TIMEVAULT_ENCRYPTION_KEY', $key );
		}

		update_option(
			self::STATUS_OPTION,
			array(
				'status'     => 'generated',
				'updated_at' => current_time( 'mysql', true ),
			),
			false
		);

		return array( 'status' => 'generated' );
	}

	/**
	 * Writes a generated key into a specific wp-config.php file.
	 *
	 * Public so tests can exercise the file editing logic without touching the
	 * real test site's configuration.
	 *
	 * @param string $config_path Absolute path to wp-config.php.
	 * @return string|\WP_Error Generated key on success.
	 */
	public static function install_in_file( string $config_path ): string|\WP_Error {
		if ( ! is_file( $config_path ) || ! is_readable( $config_path ) ) {
			return new \WP_Error( 'timevault_wp_config_missing', __( 'Could not read wp-config.php to install the Timevault encryption key.', 'timevault' ) );
		}

		if ( ! is_writable( $config_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Must know whether wp-config.php can be edited before attempting the install.
			return new \WP_Error( 'timevault_wp_config_not_writable', __( 'wp-config.php is not writable. Add TIMEVAULT_ENCRYPTION_KEY manually.', 'timevault' ) );
		}

		$contents = file_get_contents( $config_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Editing wp-config.php requires direct filesystem access.

		if ( false === $contents ) {
			return new \WP_Error( 'timevault_wp_config_read_failed', __( 'Could not read wp-config.php to install the Timevault encryption key.', 'timevault' ) );
		}

		if ( self::has_key_definition( $contents ) ) {
			return new \WP_Error( 'timevault_key_define_present', __( 'TIMEVAULT_ENCRYPTION_KEY already exists in wp-config.php but is not valid for Timevault.', 'timevault' ) );
		}

		$key     = EncryptionService::generate_key();
		$updated = self::insert_key_definition( $contents, $key );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$written = file_put_contents( $config_path, $updated, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Atomic-ish config update with exclusive lock.

		if ( false === $written ) {
			return new \WP_Error( 'timevault_wp_config_write_failed', __( 'Could not write TIMEVAULT_ENCRYPTION_KEY to wp-config.php.', 'timevault' ) );
		}

		return $key;
	}

	/**
	 * Inserts the define before WordPress boots wp-settings.php.
	 *
	 * @param string $contents Original wp-config.php contents.
	 * @param string $key      Base64-encoded 32-byte key.
	 * @return string|\WP_Error Updated contents.
	 */
	public static function insert_key_definition( string $contents, string $key ): string|\WP_Error {
		if ( self::has_key_definition( $contents ) ) {
			return new \WP_Error( 'timevault_key_define_present', __( 'TIMEVAULT_ENCRYPTION_KEY already exists in wp-config.php.', 'timevault' ) );
		}

		$newline = str_contains( $contents, "\r\n" ) ? "\r\n" : "\n";
		$snippet = $newline
			. '// Timevault backup encryption key. Generated automatically on plugin activation.' . $newline
			. "define( 'TIMEVAULT_ENCRYPTION_KEY', '" . addslashes( $key ) . "' );" . $newline;

		$pattern = '/(?=(?:require_once|require)\s*\(?\s*ABSPATH\s*\.\s*[\'"]wp-settings\.php[\'"]\s*\)?\s*;)/';

		if ( ! preg_match( $pattern, $contents ) ) {
			return new \WP_Error( 'timevault_wp_config_anchor_missing', __( 'Could not find the wp-settings.php loader in wp-config.php.', 'timevault' ) );
		}

		$updated = preg_replace( $pattern, $snippet, $contents, 1 );

		if ( ! is_string( $updated ) ) {
			return new \WP_Error( 'timevault_wp_config_write_failed', __( 'Could not prepare wp-config.php for the Timevault encryption key.', 'timevault' ) );
		}

		return $updated;
	}

	/**
	 * Locates wp-config.php in the standard WordPress locations.
	 *
	 * @return string|\WP_Error
	 */
	private static function locate_wp_config(): string|\WP_Error {
		$candidates = array(
			ABSPATH . 'wp-config.php',
			dirname( ABSPATH ) . '/wp-config.php',
		);

		foreach ( $candidates as $candidate ) {
			if ( is_file( $candidate ) ) {
				return $candidate;
			}
		}

		return new \WP_Error( 'timevault_wp_config_missing', __( 'Could not find wp-config.php to install the Timevault encryption key.', 'timevault' ) );
	}

	/**
	 * Checks whether wp-config.php already defines the Timevault key.
	 *
	 * @param string $contents wp-config.php contents.
	 */
	private static function has_key_definition( string $contents ): bool {
		return 1 === preg_match( '/define\s*\(\s*([\'"])' . EncryptionService::KEY_CONSTANT . '\1\s*,/i', $contents );
	}

	/**
	 * Stores a non-secret failure status for the dashboard and returns it.
	 *
	 * @param \WP_Error $error Failure details.
	 * @return array{status:string,code:string,message:string}
	 */
	private static function remember_failure( \WP_Error $error ): array {
		$status = array(
			'status'     => 'manual_required',
			'code'       => $error->get_error_code(),
			'message'    => $error->get_error_message(),
			'updated_at' => current_time( 'mysql', true ),
		);

		update_option( self::STATUS_OPTION, $status, false );

		return array(
			'status'  => 'manual_required',
			'code'    => $error->get_error_code(),
			'message' => $error->get_error_message(),
		);
	}
}
