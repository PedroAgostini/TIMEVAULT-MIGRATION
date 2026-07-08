<?php
/**
 * SqlImporter tests — safe SQL restore.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Tests\Restore;

use Timevault\Restore\SqlImporter;
use WP_UnitTestCase;

/**
 * Covers the tokenizer, the whitelist/forbidden classifier, own-table
 * preservation and corrupted input. A scratch table isolates real execution
 * (DDL implicitly commits, so it is created and dropped explicitly).
 */
final class SqlImporterTest extends WP_UnitTestCase {

	private string $scratch;

	private string $dir;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		$this->scratch = $wpdb->prefix . 'tv_scratch';
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$this->scratch} (id INT PRIMARY KEY, val TEXT)" ); // phpcs:ignore WordPress.DB

		$this->dir = sys_get_temp_dir() . '/tv-sqlimporter';
		wp_mkdir_p( $this->dir );
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$this->scratch}" ); // phpcs:ignore WordPress.DB
		parent::tear_down();
	}

	private function write_sql( string $sql ): string {
		$path = $this->dir . '/dump-' . wp_generate_password( 8, false ) . '.sql';
		file_put_contents( $path, $sql ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return $path;
	}

	public function test_executes_inserts_and_counts(): void {
		$file = $this->write_sql(
			"INSERT INTO `{$this->scratch}` (`id`,`val`) VALUES (1,'a');\n" .
			"INSERT INTO `{$this->scratch}` (`id`,`val`) VALUES (2,'b');\n"
		);

		$result = ( new SqlImporter() )->import( $file );
		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['executed'] );
	}

	public function test_semicolon_inside_string_does_not_split(): void {
		global $wpdb;
		$file = $this->write_sql(
			"INSERT INTO `{$this->scratch}` (`id`,`val`) VALUES (1,'a; not; a; terminator');\n"
		);

		$result = ( new SqlImporter() )->import( $file );
		$this->assertSame( 1, $result['executed'] );
		$this->assertSame( 'a; not; a; terminator', $wpdb->get_var( "SELECT val FROM {$this->scratch} WHERE id=1" ) ); // phpcs:ignore WordPress.DB
	}

	public function test_forbidden_construct_aborts(): void {
		$file   = $this->write_sql( "SELECT * FROM users INTO OUTFILE '/tmp/x';\n" );
		$result = ( new SqlImporter() )->import( $file );

		$this->assertWPError( $result );
		$this->assertSame( 'timevault_restore_forbidden_sql', $result->get_error_code() );
	}

	public function test_load_file_inside_data_is_not_a_false_positive(): void {
		$file   = $this->write_sql(
			"INSERT INTO `{$this->scratch}` (`id`,`val`) VALUES (1,'contains LOAD_FILE and INTO OUTFILE literally');\n"
		);
		$result = ( new SqlImporter() )->import( $file );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['executed'] );
	}

	public function test_own_tables_are_skipped(): void {
		global $wpdb;
		$own  = $wpdb->prefix . 'timevault_backups';
		$file = $this->write_sql(
			"DROP TABLE IF EXISTS `{$own}`;\n" .
			"INSERT INTO `{$this->scratch}` (`id`,`val`) VALUES (7,'kept');\n"
		);

		$result = ( new SqlImporter() )->import( $file );
		$this->assertSame( 1, $result['executed'], 'Only the scratch insert should run.' );
		$this->assertGreaterThanOrEqual( 1, $result['skipped'], 'The own-table statement must be skipped.' );

		// The real backups table must still exist (never dropped by a restore).
		$this->assertSame( $own, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $own ) ) );
	}

	public function test_corrupted_statement_returns_error(): void {
		global $wpdb;
		$file = $this->write_sql( "INSERT INTO `{$this->scratch}` (`id`,`val`) VALUES (\n" ); // Unterminated / invalid.

		$wpdb->suppress_errors( true ); // The invalid statement is expected to fail at the DB.
		$result = ( new SqlImporter() )->import( $file );
		$wpdb->suppress_errors( false );

		$this->assertWPError( $result );
	}
}
