<?php
/**
 * Selective export.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Granular export: a selection of tables and/or the uploads directory,
 * delegated to the same audited, asynchronous pipeline as backups.
 *
 * Table names are shape-validated here and whitelist-validated against the
 * real SHOW TABLES output inside DatabaseDumper - a request can never reach
 * a query with an arbitrary identifier.
 */
final class ExportManager {

	/**
	 * Constructor.
	 *
	 * @param BackupManager $backups Pipeline orchestrator.
	 */
	public function __construct( private BackupManager $backups ) {}

	/**
	 * Schedules a selective export job.
	 *
	 * @param array<string, mixed> $selection Selection: tables (string[]), include_uploads (bool).
	 * @param array<string, mixed> $options   Extra options (all_tables: bool).
	 * @return string|\WP_Error Export UUID.
	 */
	public function schedule_export( array $selection, array $options = array() ): string|\WP_Error {
		$tables = array_values( array_filter( array_map( 'strval', (array) ( $selection['tables'] ?? array() ) ) ) );

		foreach ( $tables as $table ) {
			if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
				return new \WP_Error( 'timevault_invalid_table_name', __( 'Invalid table name in export selection.', 'timevault' ) );
			}
		}

		$include_uploads = ! empty( $selection['include_uploads'] );

		if ( array() === $tables && ! $include_uploads ) {
			return new \WP_Error( 'timevault_empty_selection', __( 'Select at least one table or the uploads directory.', 'timevault' ) );
		}

		return $this->backups->schedule(
			'export',
			array_replace(
				$options,
				array(
					'tables'          => $tables,
					'include_uploads' => $include_uploads,
					// Staging/dev exports pseudonymize personal data (LGPD, P5).
					'anonymize'       => ! empty( $selection['anonymize'] ),
				)
			)
		);
	}

	/**
	 * Tables available for export selection (UI helper).
	 *
	 * @param bool $all_tables Include tables outside the WP prefix.
	 * @return array<string>
	 */
	public function available_tables( bool $all_tables = false ): array {
		return ( new DatabaseDumper() )->base_tables( $all_tables );
	}
}
