<?php
/**
 * Backup registry persistence.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Core;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD over the timevault_backups table. Metadata only — file contents never
 * touch the database. All writes go through $wpdb->insert/update (prepared);
 * reads use $wpdb->prepare.
 */
final class BackupRepository {

	/**
	 * Columns that update() is allowed to touch (whitelist).
	 */
	private const UPDATABLE = array( 'status', 'storage', 'file_name', 'size_bytes', 'checksum_sha256', 'is_encrypted', 'completed_at', 'expires_at', 'meta' );

	/**
	 * Creates a pending backup record.
	 *
	 * @param string               $type    Backup type: full|db|export.
	 * @param string               $storage Storage adapter id.
	 * @param array<string, mixed> $meta    Initial metadata (stored as JSON).
	 * @return string|\WP_Error Backup UUID.
	 */
	public function create( string $type, string $storage, array $meta = array() ): string|\WP_Error {
		global $wpdb;

		$uuid = wp_generate_uuid4();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated plugin table.
		$result = $wpdb->insert(
			$this->table(),
			array(
				'backup_uuid' => $uuid,
				'type'        => $type,
				'status'      => 'pending',
				'storage'     => $storage,
				'created_by'  => get_current_user_id(),
				'created_at'  => current_time( 'mysql', true ),
				'meta'        => (string) wp_json_encode( $meta ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'timevault_db_error', __( 'Could not create the backup record.', 'timevault' ) );
		}

		return $uuid;
	}

	/**
	 * Fetches a backup row by UUID (meta decoded from JSON).
	 *
	 * @param string $uuid Backup UUID.
	 * @return array<string, mixed>|null
	 */
	public function get( string $uuid ): ?array {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted prefix; value prepared.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE backup_uuid = %s", $uuid ), ARRAY_A );

		if ( null === $row ) {
			return null;
		}

		return $this->decode( $row );
	}

	/**
	 * Updates whitelisted fields of a backup row.
	 *
	 * @param string               $uuid Backup UUID.
	 * @param array<string, mixed> $data Field => value (non-whitelisted keys ignored).
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
			$formats[]      = in_array( $key, array( 'size_bytes', 'is_encrypted' ), true ) ? '%d' : '%s';
		}

		if ( array() === $fields ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated plugin table.
		return false !== $wpdb->update( $this->table(), $fields, array( 'backup_uuid' => $uuid ), $formats, array( '%s' ) );
	}

	/**
	 * Deletes a backup registry row.
	 *
	 * @param string $uuid Backup UUID.
	 */
	public function delete( string $uuid ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated plugin table.
		return false !== $wpdb->delete( $this->table(), array( 'backup_uuid' => $uuid ), array( '%s' ) );
	}

	/**
	 * Merges a patch into the row's meta JSON (patch wins on key conflict).
	 *
	 * @param string               $uuid  Backup UUID.
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
	 * Lists backups, newest first.
	 *
	 * @param int $limit  Max rows (1–100).
	 * @param int $offset Pagination offset.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_backups( int $limit = 20, int $offset = 0 ): array {
		global $wpdb;

		$limit  = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );
		$table  = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted prefix; values prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A );

		return array_map( array( $this, 'decode' ), (array) $rows );
	}

	/**
	 * Decodes JSON meta into an array.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private function decode( array $row ): array {
		$meta        = json_decode( (string) ( $row['meta'] ?? '' ), true );
		$row['meta'] = is_array( $meta ) ? $meta : array();

		return $row;
	}

	/**
	 * Fully qualified table name.
	 */
	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'timevault_backups';
	}
}
