<?php
/**
 * Safe SQL dump importer.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Restore;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fgets -- Restore engine: statements come from a checksum-verified, entry-validated dump, are classified against a whitelist, and are executed one at a time via $wpdb->query(); streaming the file needs raw handles.

/**
 * Restores a plain-SQL dump WITHOUT eval, without multi-statement execution
 * and without PHP deserialization.
 *
 * Defenses:
 * - A hand-written tokenizer splits the dump into individual statements while
 *   respecting quoted strings (`\'` and `''`), backtick identifiers and both
 *   line and block comments - so a `;` inside a value never splits a statement.
 * - Every statement is classified: only an allow-list of DDL/DML is executed;
 *   dangerous constructs (INTO OUTFILE/DUMPFILE, LOAD DATA, LOAD_FILE, GRANT,
 *   CREATE/DROP USER, DROP DATABASE/SCHEMA) ABORT the whole restore.
 * - Statements are executed one at a time through $wpdb->query() - the driver
 *   is NOT put into multi-statement mode.
 * - Optional table-prefix rewrite touches only the target identifier of
 *   DDL/DML headers, never row data.
 */
final class SqlImporter {

	/**
	 * Leading keywords allowed to execute (uppercased, normalized spacing).
	 */
	private const ALLOWED = array( 'SET', 'DROP TABLE', 'CREATE TABLE', 'INSERT INTO', 'REPLACE INTO', 'ALTER TABLE', 'LOCK TABLES', 'UNLOCK TABLES' );

	/**
	 * Substrings whose presence in a statement aborts the restore immediately.
	 */
	private const FORBIDDEN = array( 'INTO OUTFILE', 'INTO DUMPFILE', 'LOAD DATA', 'LOAD_FILE', 'DROP DATABASE', 'DROP SCHEMA', 'CREATE USER', 'DROP USER', 'GRANT ', 'CREATE FUNCTION', 'CREATE TRIGGER', 'CREATE PROCEDURE', 'SET GLOBAL', '@@GLOBAL' );

	/**
	 * Cap on a single accumulated statement (32 MiB) - guards against a
	 * pathological unterminated statement exhausting memory.
	 */
	private const MAX_STATEMENT_BYTES = 33554432;

	/**
	 * Timevault's own operational tables (base names, any prefix). Statements
	 * targeting these are skipped during restore so the audit trail, the
	 * backup registry (including the just-made safety backup) and the running
	 * restore's own row survive the DB overwrite.
	 */
	private const OWN_TABLES = array( 'timevault_audit_log', 'timevault_backups', 'timevault_restores' );

	/**
	 * Imports a SQL file.
	 *
	 * @param string      $sql_file     Absolute path of the plain-SQL dump.
	 * @param string|null $from_prefix  Source table prefix (from the manifest).
	 * @param string|null $to_prefix    Destination table prefix (this site).
	 * @param bool        $preserve_own Skip statements targeting Timevault's own tables.
	 * @return array{executed: int, skipped: int}|\WP_Error
	 */
	public function import( string $sql_file, ?string $from_prefix = null, ?string $to_prefix = null, bool $preserve_own = true ): array|\WP_Error {
		global $wpdb;

		$handle = fopen( $sql_file, 'rb' );

		if ( false === $handle ) {
			return new \WP_Error( 'timevault_restore_sql_open', __( 'Could not open the SQL dump.', 'timevault' ) );
		}

		$rewrite = ( null !== $from_prefix && null !== $to_prefix && $from_prefix !== $to_prefix );

		$executed = 0;
		$skipped  = 0;

		// Wrap the whole import so a mid-way failure surfaces as one error;
		// FK checks are disabled during load and restored in the finally block.
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );

		try {
			foreach ( $this->statements( $handle ) as $parsed ) {
				if ( is_wp_error( $parsed ) ) {
					return $parsed;
				}

				// Classification runs on the string-literal-stripped "code" view
				// so a value like a post body containing "LOAD_FILE" can never be
				// mistaken for the SQL function of the same name.
				$forbidden = $this->forbidden_reason( $parsed['code'] );

				if ( null !== $forbidden ) {
					return new \WP_Error(
						'timevault_restore_forbidden_sql',
						/* translators: %s: the forbidden SQL construct. */
						sprintf( __( 'Restore aborted: dump contains a forbidden SQL construct (%s).', 'timevault' ), $forbidden )
					);
				}

				if ( ! $this->is_allowed( $parsed['code'] ) ) {
					++$skipped; // Unknown-but-not-dangerous (e.g. a foreign tool's pragma): skip, don't abort.
					continue;
				}

				if ( $preserve_own && $this->targets_own_table( $parsed['code'] ) ) {
					++$skipped; // Never let a restore clobber Timevault's own operational tables.
					continue;
				}

				$statement = $rewrite
					? $this->rewrite_prefix( $parsed['sql'], (string) $from_prefix, (string) $to_prefix )
					: $parsed['sql'];

				$result = $wpdb->query( $statement );

				if ( false === $result ) {
					return new \WP_Error(
						'timevault_restore_sql_failed',
						/* translators: %s: database error message. */
						sprintf( __( 'A restore statement failed: %s', 'timevault' ), $wpdb->last_error )
					);
				}

				++$executed;
			}
		} finally {
			$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );
			fclose( $handle );
		}

		return array(
			'executed' => $executed,
			'skipped'  => $skipped,
		);
	}

	/**
	 * Streams statements from the dump handle (generator).
	 *
	 * A character-level state machine tracks quoting/commenting so that a
	 * terminator `;` inside a string or comment does not end a statement. For
	 * each statement it yields both the full SQL (`sql`, executed verbatim) and
	 * a "code" view (`code`) with string-literal CONTENTS removed, so that
	 * classification cannot be fooled by data that merely contains SQL keywords.
	 *
	 * @param resource $handle Readable dump handle.
	 * @return \Generator<array{sql: string, code: string}|\WP_Error>
	 */
	private function statements( $handle ): \Generator {
		$buffer    = '';       // Full statement text (executed).
		$code      = '';       // Statement with string-literal contents stripped.
		$in_string = false;
		$quote     = '';
		$in_line   = false;    // -- or # comment.
		$in_block  = false;    // /* */ comment.
		$in_ident  = false;    // backtick identifier.

		while ( ! feof( $handle ) ) {
			$line = fgets( $handle, 65536 );

			if ( false === $line ) {
				break;
			}

			$length = strlen( $line );

			for ( $i = 0; $i < $length; $i++ ) {
				$char = $line[ $i ];
				$next = ( $i + 1 < $length ) ? $line[ $i + 1 ] : '';

				// End-of-line closes a line comment.
				if ( $in_line ) {
					if ( "\n" === $char ) {
						$in_line = false;
						$buffer .= $char;
						$code   .= ' ';
					}
					continue;
				}

				if ( $in_block ) {
					if ( '*' === $char && '/' === $next ) {
						$in_block = false;
						++$i;
					}
					continue;
				}

				if ( $in_string ) {
					$buffer .= $char; // Literal contents go to the full SQL only.

					if ( '\\' === $char && '' !== $next ) {
						$buffer .= $next; // Escaped char (e.g. \' \\) - consume both.
						++$i;
						continue;
					}

					if ( $char === $quote ) {
						// Doubled quote ('') is an escaped quote, not a terminator.
						if ( $next === $quote ) {
							$buffer .= $next;
							++$i;
							continue;
						}
						$in_string = false;
						$quote     = '';
						$code     .= $char; // Closing quote → code sees an empty literal.
					}
					continue;
				}

				if ( $in_ident ) {
					$buffer .= $char;
					$code   .= $char; // Identifiers matter for classification.

					if ( '`' === $char ) {
						$in_ident = false;
					}
					continue;
				}

				// Not inside any string/comment/identifier: detect openers.
				if ( '-' === $char && '-' === $next ) {
					$in_line = true;
					++$i;
					continue;
				}

				if ( '#' === $char ) {
					$in_line = true;
					continue;
				}

				if ( '/' === $char && '*' === $next ) {
					$in_block = true;
					++$i;
					continue;
				}

				if ( "'" === $char || '"' === $char ) {
					$in_string = true;
					$quote     = $char;
					$buffer   .= $char;
					$code     .= $char; // Opening quote (contents omitted from code).
					continue;
				}

				if ( '`' === $char ) {
					$in_ident = true;
					$buffer  .= $char;
					$code    .= $char;
					continue;
				}

				if ( ';' === $char ) {
					$sql       = trim( $buffer );
					$code_trim = trim( $code );
					$buffer    = '';
					$code      = '';

					if ( '' !== $sql ) {
						yield array(
							'sql'  => $sql,
							'code' => $code_trim,
						);
					}
					continue;
				}

				$buffer .= $char;
				$code   .= $char;
			}

			if ( strlen( $buffer ) > self::MAX_STATEMENT_BYTES ) {
				yield new \WP_Error( 'timevault_restore_sql_toolong', __( 'A single SQL statement exceeded the size limit; aborting.', 'timevault' ) );
				return;
			}
		}

		$sql = trim( $buffer );

		if ( '' !== $sql && ! $in_string && ! $in_block ) {
			yield array(
				'sql'  => $sql,
				'code' => trim( $code ),
			);
		}
	}

	/**
	 * Normalized leading form of a statement for classification.
	 *
	 * @param string $statement Trimmed statement.
	 */
	private function normalized_head( string $statement ): string {
		// Strip a leading version-gated comment wrapper: /*!40101 SET ... */.
		$statement = preg_replace( '#^/\*![0-9]*\s*#', '', $statement ) ?? $statement;
		$head      = strtoupper( substr( ltrim( $statement ), 0, 32 ) );

		return (string) preg_replace( '/\s+/', ' ', $head );
	}

	/**
	 * Whether a statement matches the execution allow-list.
	 *
	 * @param string $statement Trimmed statement.
	 */
	private function is_allowed( string $statement ): bool {
		$head = $this->normalized_head( $statement );

		foreach ( self::ALLOWED as $allowed ) {
			if ( str_starts_with( $head, $allowed ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a statement targets one of Timevault's own operational tables
	 * (prefix-agnostic: matches the base name after any prefix).
	 *
	 * @param string $code Statement code view (string literals stripped).
	 */
	private function targets_own_table( string $code ): bool {
		if ( 1 !== preg_match( '/^\s*(?:DROP TABLE(?: IF EXISTS)?|CREATE TABLE(?: IF NOT EXISTS)?|INSERT INTO|REPLACE INTO|ALTER TABLE|TRUNCATE(?: TABLE)?|LOCK TABLES)\s+`([^`]+)`/i', $code, $m ) ) {
			return false;
		}

		foreach ( self::OWN_TABLES as $base ) {
			if ( str_ends_with( $m[1], $base ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the forbidden construct found in a statement, or null.
	 *
	 * @param string $statement Trimmed statement.
	 */
	private function forbidden_reason( string $statement ): ?string {
		$upper = strtoupper( $statement );

		foreach ( self::FORBIDDEN as $needle ) {
			if ( str_contains( $upper, $needle ) ) {
				return trim( $needle );
			}
		}

		return null;
	}

	/**
	 * Rewrites the table prefix on a DDL/DML target identifier only.
	 *
	 * Matches the first backtick-quoted identifier after the statement's
	 * leading keyword and, if it starts with $from, swaps in $to. Row values
	 * are never touched.
	 *
	 * @param string $statement Trimmed statement.
	 * @param string $from      Source prefix.
	 * @param string $to        Destination prefix.
	 */
	private function rewrite_prefix( string $statement, string $from, string $to ): string {
		$pattern = '/^(\s*(?:DROP TABLE(?: IF EXISTS)?|CREATE TABLE(?: IF NOT EXISTS)?|INSERT INTO|REPLACE INTO|ALTER TABLE|LOCK TABLES)\s+`)' . preg_quote( $from, '/' ) . '/i';

		return (string) preg_replace_callback(
			$pattern,
			static fn( array $m ): string => $m[1] . $to,
			$statement,
			1
		);
	}
}
