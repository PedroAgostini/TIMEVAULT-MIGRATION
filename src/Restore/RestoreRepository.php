<?php
/**
 * Restore registry persistence.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD over the timevault_restores table. All writes go through
 * $wpdb->insert/update (prepared); reads use $wpdb->prepare.
 */
final class RestoreRepository {

	/**
	 * Columns update() may touch (whitelist).
	 */
	private const UPDATABLE = array( 'safety_backup_uuid', 'status', 'step', 'completed_at', 'meta' );

	/**
	 * Creates a pending restore record.
	 *
	 * @param string               $source_backup_uuid Backup being restored.
	 * @param array<string, mixed> $meta               Initial metadata.
	 * @return string|\WP_Error Restore UUID.
	 */
	public function create( string $source_backup_uuid, array $meta = array() ): string|\WP_Error {
		global $wpdb;

		$uuid = wp_generate_uuid4();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated plugin table.
		$result = $wpdb->insert(
			$this->table(),
			array(
				'restore_uuid'       => $uuid,
				'source_backup_uuid' => $source_backup_uuid,
				'status'             => 'pending',
				'step'               => '',
				'created_by'         => get_current_user_id(),
				'created_at'         => current_time( 'mysql', true ),
				'meta'               => (string) wp_json_encode( $meta ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'timevault_db_error', __( 'Could not create the restore record.', 'timevault' ) );
		}

		return $uuid;
	}

	/**
	 * Fetches a restore row by UUID (meta decoded).
	 *
	 * @param string $uuid Restore UUID.
	 * @return array<string, mixed>|null
	 */
	public function get( string $uuid ): ?array {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted prefix; value prepared.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE restore_uuid = %s", $uuid ), ARRAY_A );

		if ( null === $row ) {
			return null;
		}

		$meta        = json_decode( (string) ( $row['meta'] ?? '' ), true );
		$row['meta'] = is_array( $meta ) ? $meta : array();

		return $row;
	}

	/**
	 * Updates whitelisted fields.
	 *
	 * @param string               $uuid Restore UUID.
	 * @param array<string, mixed> $data Field => value.
	 */
	public function update( string $uuid, array $data ): bool {
		global $wpdb;

		$fields  = array();
		$formats = array();

		foreach ( $data as $key => $value ) {
			if ( ! in_array( $key, self::UPDATABLE, true ) ) {
				continue;
			}

			if ( 'meta' === $key && is_array( $value ) ) {
				$value = (string) wp_json_encode( $value );
			}

			$fields[ $key ] = $value;
			$formats[]      = '%s';
		}

		if ( array() === $fields ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated plugin table.
		return false !== $wpdb->update( $this->table(), $fields, array( 'restore_uuid' => $uuid ), $formats, array( '%s' ) );
	}

	/**
	 * Merges a patch into the row's meta JSON.
	 *
	 * @param string               $uuid  Restore UUID.
	 * @param array<string, mixed> $patch Meta keys to set.
	 */
	public function merge_meta( string $uuid, array $patch ): void {
		$row = $this->get( $uuid );

		if ( null === $row ) {
			return;
		}

		$this->update( $uuid, array( 'meta' => array_replace( (array) $row['meta'], $patch ) ) );
	}

	/**
	 * Lists restores, newest first.
	 *
	 * @param int $limit  Max rows (1–100).
	 * @param int $offset Pagination offset.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_restores( int $limit = 20, int $offset = 0 ): array {
		global $wpdb;

		$limit  = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );
		$table  = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted prefix; values prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A );

		return array_map(
			static function ( array $row ): array {
				$meta        = json_decode( (string) ( $row['meta'] ?? '' ), true );
				$row['meta'] = is_array( $meta ) ? $meta : array();

				return $row;
			},
			(array) $rows
		);
	}

	/**
	 * Fully qualified table name.
	 */
	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'timevault_restores';
	}
}
