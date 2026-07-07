<?php
/**
 * LGPD support service (contract; implementation lands in P5).
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Technical support for LGPD compliance. No hidden telemetry: this plugin
 * never sends anything off-site without explicit, configurable opt-in.
 *
 * P5 contract:
 * - Optional anonymization of personal data (email, name, phone) in
 *   staging/dev exports.
 * - Configurable retention policy with automatic expiration of old backups.
 * - Lightweight processing-activities record (what personal data the plugin
 *   touches, where it goes, for how long) for the agency's compliance docs.
 * - Destination region registered per storage adapter (international data
 *   transfer, LGPD Art. 33).
 */
final class PrivacyService {

	/**
	 * Anonymizes personal data inside an export package. Implemented in P5.
	 *
	 * @param string $package_path Absolute path of the export package.
	 * @return true|\WP_Error
	 */
	public function anonymize_export( string $package_path ): bool|\WP_Error {
		unset( $package_path );

		return new \WP_Error( 'timevault_not_implemented', __( 'Anonymization is implemented in phase P5.', 'timevault' ) );
	}

	/**
	 * Applies the configured retention policy (expires old backups). Implemented in P5.
	 *
	 * @return int|\WP_Error Number of expired backups.
	 */
	public function apply_retention_policy(): int|\WP_Error {
		return new \WP_Error( 'timevault_not_implemented', __( 'Retention policy is implemented in phase P5.', 'timevault' ) );
	}

	/**
	 * Generates the processing-activities record. Implemented in P5.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function get_processing_record(): array|\WP_Error {
		return new \WP_Error( 'timevault_not_implemented', __( 'Processing record is implemented in phase P5.', 'timevault' ) );
	}
}
