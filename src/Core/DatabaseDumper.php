<?php
/**
 * Database dump engine.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Core;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Dump engine: identifiers are validated against SHOW TABLES output and backtick-escaped; every VALUE is quoted through $wpdb->prepare().

/**
 * Streams a SQL dump of the site's tables to a file.
 *
 * Security decisions:
 * - Table names are never taken from user input directly: a requested
 *   selection is validated (whitelist) against the actual SHOW TABLES output
 *   before any query is built.
 * - Identifiers are backtick-escaped (backticks doubled); every VALUE is
 *   quoted through $wpdb->prepare( '%s' ) - no raw concatenation of data.
 * - Rows are read in batches (keyset pagination when a single integer
 *   primary key exists) so memory stays bounded on large tables.
 * - Output is plain SQL text only - no PHP serialization anywhere.
 */
final class DatabaseDumper {

	/**
	 * Rows fetched per query.
	 */
	private const BATCH = 1000;

	/**
	 * Rows per INSERT statement in the dump.
	 */
	private const ROWS_PER_INSERT = 100;

	/**
	 * Optional per-row transformer applied before serialization (anonymization).
	 *
	 * @var callable|null
	 */
	private $row_transformer = null;

	/**
	 * Sets a row transformer: (string $table, array $row) => array $row.
	 * Used by the PrivacyService to anonymize staging/dev exports at the
	 * structured-row level (never by rewriting SQL text).
	 *
	 * @param callable|null $transformer Transformer or null to clear.
	 */
	public function set_row_transformer( ?callable $transformer ): void {
		$this->row_transformer = $transformer;
	}

	/**
	 * Dumps tables to a SQL file.
	 *
	 * @param string             $target_path Absolute path of the .sql file to create.
	 * @param array<string>|null $only_tables Restrict to these tables (validated against the real list), or null for all.
	 * @param bool               $all_tables  Include tables outside the WP prefix (shared-database hosts: default off).
	 * @return array{tables: int, rows: int}|\WP_Error Dump statistics.
	 */
	public function dump( string $target_path, ?array $only_tables = null, bool $all_tables = false ): array|\WP_Error {
		$available = $this->base_tables( $all_tables );
		$tables    = $available;

		if ( null !== $only_tables ) {
			$unknown = array_diff( $only_tables, $available );

			if ( array() !== $unknown ) {
				return new \WP_Error(
					'timevault_unknown_table',
					sprintf(
						/* translators: %s: comma-separated table names. */
						__( 'Unknown or not allowed table(s): %s', 'timevault' ),
						implode( ', ', $unknown )
					)
				);
			}

			$tables = array_values( array_intersect( $available, $only_tables ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming dump requires a raw handle.
		$handle = fopen( $target_path, 'wb' );

		if ( false === $handle ) {
			return new \WP_Error( 'timevault_dump_write_failed', __( 'Could not create the database dump file.', 'timevault' ) );
		}

		$total_rows = 0;

		try {
			$this->write(
				$handle,
				"-- Timevault database dump\n"
				. '-- Generated: ' . gmdate( 'c' ) . "\n"
				. '-- Site: ' . home_url() . "\n"
				. '-- WordPress: ' . get_bloginfo( 'version' ) . "\n"
				. "-- Format: plain SQL statements only (no PHP serialization).\n\n"
				. "SET FOREIGN_KEY_CHECKS = 0;\n\n"
			);

			foreach ( $tables as $table ) {
				$total_rows += $this->dump_table( $handle, $table );
			}

			$this->write( $handle, "SET FOREIGN_KEY_CHECKS = 1;\n" );
		} catch ( \Throwable $e ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			wp_delete_file( $target_path ); // Never leave a partial dump behind.

			return new \WP_Error( 'timevault_dump_failed', $e->getMessage() );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return array(
			'tables' => count( $tables ),
			'rows'   => $total_rows,
		);
	}

	/**
	 * Lists the tables this site is allowed to dump.
	 *
	 * @param bool $all Include tables outside the WP base prefix.
	 * @return array<string>
	 */
	public function base_tables( bool $all = false ): array {
		global $wpdb;

		$rows   = $wpdb->get_results( 'SHOW FULL TABLES', ARRAY_N );
		$tables = array();

		foreach ( (array) $rows as $row ) {
			if ( isset( $row[1] ) && 'BASE TABLE' !== $row[1] ) {
				continue; // Skip views.
			}

			$name = (string) $row[0];

			if ( ! $all && ! str_starts_with( $name, $wpdb->base_prefix ) ) {
				continue; // Shared database: never touch other applications' tables by default.
			}

			$tables[] = $name;
		}

		return $tables;
	}

	/**
	 * Dumps one table (definition + data) and returns the row count.
	 *
	 * @param resource $handle Output handle.
	 * @param string   $table  Table name (already validated against SHOW TABLES).
	 * @return int Rows written.
	 * @throws \RuntimeException On read/write failure.
	 */
	private function dump_table( $handle, string $table ): int {
		global $wpdb;

		$qt     = $this->quote_identifier( $table );
		$create = $wpdb->get_row( "SHOW CREATE TABLE {$qt}", ARRAY_N );

		if ( ! isset( $create[1] ) ) {
			throw new \RuntimeException( 'Could not read the definition of table ' . esc_html( $table ) );
		}

		$this->write( $handle, "--\n-- Table: {$table}\n--\n\nDROP TABLE IF EXISTS {$qt};\n" . $create[1] . ";\n\n" );

		$columns = $wpdb->get_results( "SHOW COLUMNS FROM {$qt}", ARRAY_A );

		if ( array() === $columns || null === $columns ) {
			return 0;
		}

		$fields     = array_map( static fn( array $c ): string => (string) $c['Field'], $columns );
		$column_sql = implode( ', ', array_map( array( $this, 'quote_identifier' ), $fields ) );

		// Keyset pagination when there is a single integer primary key -
		// OFFSET degrades quadratically on large tables.
		$pk  = $this->integer_primary_key( $columns );
		$qpk = ( null !== $pk ) ? $this->quote_identifier( $pk ) : '';

		$rows_written = 0;
		$last_id      = null;
		$offset       = 0;

		while ( true ) {
			if ( null !== $pk ) {
				$rows = ( null === $last_id )
					? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$qt} ORDER BY {$qpk} ASC LIMIT %d", self::BATCH ), ARRAY_A )
					: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$qt} WHERE {$qpk} > %d ORDER BY {$qpk} ASC LIMIT %d", $last_id, self::BATCH ), ARRAY_A );
			} else {
				$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$qt} LIMIT %d OFFSET %d", self::BATCH, $offset ), ARRAY_A );
				$offset += self::BATCH;
			}

			$rows = (array) $rows;

			if ( array() === $rows ) {
				break;
			}

			foreach ( array_chunk( $rows, self::ROWS_PER_INSERT ) as $chunk ) {
				$tuples = array();

				foreach ( $chunk as $row ) {
					if ( null !== $this->row_transformer ) {
						// Anonymization happens on structured data, before serialization.
						$row = (array) ( $this->row_transformer )( $table, $row );
					}

					$tuples[] = $this->row_values( $row, $fields );
				}

				$this->write( $handle, "INSERT INTO {$qt} ({$column_sql}) VALUES\n" . implode( ",\n", $tuples ) . ";\n" );
			}

			$rows_written += count( $rows );

			if ( null !== $pk ) {
				$last    = end( $rows );
				$last_id = (int) $last[ $pk ];
			}

			if ( count( $rows ) < self::BATCH ) {
				break;
			}
		}

		$this->write( $handle, "\n" );

		return $rows_written;
	}

	/**
	 * Serializes one row as a SQL tuple, every value through $wpdb->prepare.
	 *
	 * @param array<string, mixed> $row    Row (ARRAY_A).
	 * @param array<string>        $fields Column order.
	 */
	private function row_values( array $row, array $fields ): string {
		global $wpdb;

		$values = array();

		foreach ( $fields as $field ) {
			$value = $row[ $field ] ?? null;

			// %s quoting is safe for every MySQL type (numbers are coerced on insert).
			$values[] = ( null === $value ) ? 'NULL' : $wpdb->prepare( '%s', $value );
		}

		return '(' . implode( ',', $values ) . ')';
	}

	/**
	 * Detects a single-column integer primary key.
	 *
	 * @param array<int, array<string, string>> $columns SHOW COLUMNS rows.
	 */
	private function integer_primary_key( array $columns ): ?string {
		$primary = array_values( array_filter( $columns, static fn( array $c ): bool => 'PRI' === ( $c['Key'] ?? '' ) ) );

		if ( 1 === count( $primary ) && preg_match( '/int/i', (string) $primary[0]['Type'] ) ) {
			return (string) $primary[0]['Field'];
		}

		return null;
	}

	/**
	 * Backtick-escapes an identifier (backticks doubled).
	 *
	 * @param string $identifier Table or column name.
	 */
	private function quote_identifier( string $identifier ): string {
		return '`' . str_replace( '`', '``', $identifier ) . '`';
	}

	/**
	 * Writes to the dump handle, throwing on failure (disk full, revoked handle).
	 *
	 * @param resource $handle Output handle.
	 * @param string   $data   Bytes to write.
	 * @throws \RuntimeException On write failure.
	 */
	private function write( $handle, string $data ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming dump.
		if ( false === fwrite( $handle, $data ) ) {
			throw new \RuntimeException( 'Could not write to the database dump file.' );
		}
	}
}
