<?php
/**
 * External storage destination configuration.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Storage;

use Timevault\Core\AuditLog;
use Timevault\Core\EncryptionService;

defined( 'ABSPATH' ) || exit;

/**
 * Stores external destination configuration (option `timevault_destinations`,
 * autoload off).
 *
 * Security rules enforced here:
 * - Credentials are ALWAYS encrypted (EncryptionService) before touching the
 *   database. If no encryption key is configured, saving credentials is
 *   refused - there is no plaintext fallback.
 * - No destination is enabled by default; enabling requires credentials
 *   already stored (explicit opt-in, twice).
 * - Every configuration change is audited with the destination region
 *   (LGPD Art. 33 - international data transfer traceability). Credentials
 *   never reach the audit log.
 */
final class DestinationSettings {

	public const OPTION = 'timevault_destinations';

	private const IDS = array( 's3', 'gdrive' );

	/**
	 * Constructor.
	 *
	 * @param EncryptionService $encryption Credential encryption.
	 * @param AuditLog          $audit      Append-only audit log.
	 */
	public function __construct(
		private EncryptionService $encryption,
		private AuditLog $audit
	) {}

	/**
	 * All destination configs (credentials stay encrypted).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		$value = get_option( self::OPTION, array() );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * One destination config, or null when not configured.
	 *
	 * @param string $id Destination id.
	 * @return array<string, mixed>|null
	 */
	public function get( string $id ): ?array {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * Configs of destinations explicitly enabled by the site owner.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function enabled(): array {
		return array_filter( $this->all(), static fn( array $config ): bool => ! empty( $config['enabled'] ) );
	}

	/**
	 * Saves a destination configuration.
	 *
	 * @param string                    $id          Destination id (s3|gdrive).
	 * @param array<string, mixed>      $config      Non-secret settings.
	 * @param array<string, mixed>|null $credentials New credentials, or null to keep the stored ones.
	 * @return true|\WP_Error
	 */
	public function save( string $id, array $config, ?array $credentials = null ): bool|\WP_Error {
		if ( ! in_array( $id, self::IDS, true ) ) {
			return new \WP_Error( 'timevault_invalid_destination', __( 'Unknown storage destination.', 'timevault' ) );
		}

		$existing = $this->get( $id ) ?? array();

		$clean = array(
			'enabled'     => ! empty( $config['enabled'] ),
			'label'       => sanitize_text_field( (string) ( $config['label'] ?? '' ) ),
			'region'      => sanitize_text_field( (string) ( $config['region'] ?? '' ) ),
			'credentials' => (string) ( $existing['credentials'] ?? '' ),
		);

		if ( 's3' === $id ) {
			$clean['bucket']   = (string) preg_replace( '/[^a-z0-9.\-]/', '', strtolower( (string) ( $config['bucket'] ?? '' ) ) );
			$clean['prefix']   = trim( (string) preg_replace( '/[^A-Za-z0-9._\-\/]/', '', (string) ( $config['prefix'] ?? '' ) ), '/' );
			$clean['endpoint'] = esc_url_raw( (string) ( $config['endpoint'] ?? '' ) );

			if ( str_contains( $clean['prefix'], '..' ) ) {
				return new \WP_Error( 'timevault_invalid_destination', __( 'Invalid S3 prefix.', 'timevault' ) );
			}
		}

		if ( 'gdrive' === $id ) {
			$clean['folder_id'] = (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $config['folder_id'] ?? '' ) );
		}

		if ( null !== $credentials ) {
			if ( ! $this->encryption->is_configured() ) {
				// No plaintext fallback, ever: without a key we refuse to store secrets.
				return new \WP_Error( 'timevault_credentials_need_key', __( 'Define TIMEVAULT_ENCRYPTION_KEY in wp-config.php before saving storage credentials. They are never stored in plain text.', 'timevault' ) );
			}

			$blob = $this->encryption->encrypt_string( (string) wp_json_encode( $credentials ) );

			if ( is_wp_error( $blob ) ) {
				return $blob;
			}

			$clean['credentials'] = $blob;
		}

		if ( $clean['enabled'] && '' === $clean['credentials'] ) {
			return new \WP_Error( 'timevault_destination_no_credentials', __( 'Store credentials before enabling this destination.', 'timevault' ) );
		}

		$destinations        = $this->all();
		$destinations[ $id ] = $clean;
		update_option( self::OPTION, $destinations, false );

		$this->audit->record(
			'destination_configured',
			array(
				'destination'         => $id,
				'enabled'             => $clean['enabled'],
				'region'              => $clean['region'], // LGPD Art. 33: destination location on record.
				'credentials_changed' => ( null !== $credentials ),
			),
			'destination',
			$id
		);

		return true;
	}

	/**
	 * Removes a destination configuration (credentials included).
	 *
	 * @param string $id Destination id.
	 */
	public function delete( string $id ): void {
		$destinations = $this->all();

		if ( ! isset( $destinations[ $id ] ) ) {
			return;
		}

		unset( $destinations[ $id ] );
		update_option( self::OPTION, $destinations, false );

		$this->audit->record( 'destination_removed', array( 'destination' => $id ), 'destination', $id );
	}

	/**
	 * Decrypts and returns a destination's credentials.
	 *
	 * @param string $id Destination id.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function credentials( string $id ): array|\WP_Error {
		$config = $this->get( $id );
		$blob   = (string) ( $config['credentials'] ?? '' );

		if ( '' === $blob ) {
			return new \WP_Error( 'timevault_destination_no_credentials', __( 'No credentials stored for this destination.', 'timevault' ) );
		}

		$json = $this->encryption->decrypt_string( $blob );

		if ( is_wp_error( $json ) ) {
			return $json;
		}

		$data = json_decode( $json, true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'timevault_destination_corrupted', __( 'Stored credentials are corrupted.', 'timevault' ) );
		}

		return $data;
	}
}
