<?php
/**
 * Import/restore engine - the highest-risk component of the plugin.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Core;

use Timevault\Queue\JobQueue;
use Timevault\Restore\ArchiveInspector;
use Timevault\Restore\PathGuard;
use Timevault\Restore\RestoreRepository;
use Timevault\Restore\SqlImporter;
use Timevault\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * Restores/migrates a site from a backup package. Every input is hostile.
 *
 * The restore runs as an asynchronous step pipeline
 * (safety_backup → validate → extract → restore_db → restore_files → finalize).
 *
 * Non-negotiable safeguards, in order:
 * - safety_backup: a full backup of the CURRENT state is taken to completion
 *   (synchronously) BEFORE anything is overwritten. If it fails, the restore
 *   aborts before touching the site.
 * - validate: the source artifact's SHA-256 is verified before its bytes are
 *   processed; it is then authenticated-decrypted; every ZIP entry name is
 *   validated (PathGuard) and the manifest is read as JSON (never unserialize).
 * - extract: entries are streamed to individually re-validated target paths
 *   inside a staging directory (no ZipArchive::extractTo on hostile names).
 * - restore_db: a whitelist-classified, tokenized SQL importer runs each
 *   statement through $wpdb->query() - never eval, never multi-statement.
 * - Double confirmation and rate limiting are enforced at the REST layer
 *   before schedule_restore() is ever reached; every attempt is audited.
 */
final class ImportManager {

	/**
	 * Action Scheduler hook that executes restore pipeline steps.
	 */
	public const STEP_HOOK = 'timevault_restore_step';

	/**
	 * Constructor.
	 *
	 * @param RestoreRepository                                         $restores   Restore registry.
	 * @param BackupRepository                                          $backups    Backup registry (source + safety).
	 * @param BackupManager                                             $backup_mgr Orchestrator (for the safety backup).
	 * @param JobQueue                                                  $queue      Async job queue.
	 * @param AuditLog                                                  $audit      Append-only audit log.
	 * @param ArchiveInspector                                          $inspector  Package validator/extractor.
	 * @param array<string, \Timevault\Storage\StorageAdapterInterface> $adapters Storage adapters.
	 */
	public function __construct(
		private RestoreRepository $restores,
		private BackupRepository $backups,
		private BackupManager $backup_mgr,
		private JobQueue $queue,
		private AuditLog $audit,
		private ArchiveInspector $inspector,
		private array $adapters
	) {}

	/**
	 * Validates a source backup WITHOUT touching the site (used by the REST
	 * "prepare" step to build the confirmation summary).
	 *
	 * @param string $backup_uuid Backup identifier from the registry.
	 * @return array<string, mixed>|\WP_Error Summary (type, manifest, entries) or error.
	 */
	public function validate_package( string $backup_uuid ): array|\WP_Error {
		$backup = $this->backups->get( $backup_uuid );

		if ( null === $backup || 'completed' !== (string) $backup['status'] ) {
			return new \WP_Error( 'timevault_restore_source_missing', __( 'Source backup not found or not completed.', 'timevault' ) );
		}

		$workdir = Paths::ensure_working_dir( 'inspect-' . $backup_uuid );

		try {
			$artifact = $this->fetch_artifact( $backup, $workdir );

			if ( is_wp_error( $artifact ) ) {
				return $artifact;
			}

			$check = $this->inspector->verify_checksum( $artifact, (string) $backup['checksum_sha256'] );

			if ( is_wp_error( $check ) ) {
				return $check;
			}

			$zip = $this->inspector->to_plaintext_zip( $artifact, (bool) $backup['is_encrypted'], $workdir . '/package.zip' );

			if ( is_wp_error( $zip ) ) {
				return $zip;
			}

			$inspection = $this->inspector->inspect( $zip );

			if ( is_wp_error( $inspection ) ) {
				return $inspection;
			}

			return array(
				'backup_uuid'  => $backup_uuid,
				'type'         => (string) $backup['type'],
				'entries'      => $inspection['entries'],
				'uncompressed' => $inspection['uncompressed'],
				'manifest'     => $inspection['manifest'],
			);
		} finally {
			Paths::delete_tree( $workdir );
		}
	}

	/**
	 * Imports an uploaded backup package (migration from another site): stores
	 * it in the hardened directory, validates it as hostile input (checksum,
	 * decrypt with THIS site's key, entry validation, JSON manifest) and
	 * registers it as a completed backup that can then be restored through the
	 * normal double-confirmation flow. Never auto-restores.
	 *
	 * @param string $tmp_path      Absolute path of the uploaded temp file.
	 * @param string $original_name Client-supplied file name (used only to detect encryption).
	 * @return string|\WP_Error New backup UUID.
	 * @throws \RuntimeException On validation failure - caught internally and returned as a WP_Error.
	 */
	public function import_uploaded_package( string $tmp_path, string $original_name ): string|\WP_Error {
		if ( ! is_readable( $tmp_path ) || ! is_file( $tmp_path ) ) {
			return new \WP_Error( 'timevault_import_bad_upload', __( 'Uploaded file is not readable.', 'timevault' ) );
		}

		$adapter = $this->adapters['local'] ?? null;

		if ( null === $adapter ) {
			return new \WP_Error( 'timevault_unknown_storage', __( 'Local storage is not available.', 'timevault' ) );
		}

		$encrypted     = str_ends_with( strtolower( $original_name ), '.enc' );
		$source_format = 'timevault';
		$import_source = $tmp_path;
		$workdir       = Paths::ensure_working_dir( 'import-upload-' . substr( wp_generate_uuid4(), 0, 8 ) );

		if ( ! $encrypted ) {
			$prepared = $this->prepare_plaintext_import_package( $tmp_path, $original_name, $workdir );

			if ( is_wp_error( $prepared ) ) {
				Paths::delete_tree( $workdir );

				return $prepared;
			}

			$import_source = $prepared['path'];
			$source_format = $prepared['source_format'];
		}

		$name = sprintf(
			'timevault-imported-%s-%s.zip%s',
			gmdate( 'Ymd-His' ),
			substr( wp_generate_uuid4(), 0, 8 ),
			$encrypted ? '.enc' : ''
		);

		// Store into the hardened directory under a controlled, safe name.
		$stored = $adapter->store( $import_source, $name );

		if ( is_wp_error( $stored ) ) {
			Paths::delete_tree( $workdir );

			return $stored;
		}

		$artifact = Paths::backup_dir() . '/' . $name;

		try {
			$checksum = hash_file( 'sha256', $artifact );

			if ( false === $checksum ) {
				throw new \RuntimeException( 'Could not read the uploaded package.' );
			}

			// Decrypt (this site's key) + validate every entry + read manifest.
			$zip = $this->inspector->to_plaintext_zip( $artifact, $encrypted, $workdir . '/package.zip' );
			$this->unwrap( $zip );

			$inspection = $this->inspector->inspect( (string) $zip );
			$this->unwrap( $inspection );

			$manifest = (array) $inspection['manifest'];
			$type     = isset( $manifest['type'] ) ? (string) $manifest['type'] : 'full';

			$uuid = $this->backups->create( $type, 'local', array( 'imported' => true ) );

			if ( is_wp_error( $uuid ) ) {
				throw new \RuntimeException( $uuid->get_error_message() );
			}

			$this->backups->update(
				$uuid,
				array(
					'status'          => 'completed',
					'file_name'       => $name,
					'size_bytes'      => (int) filesize( $artifact ),
					'checksum_sha256' => $checksum,
					'is_encrypted'    => $encrypted ? 1 : 0,
					'completed_at'    => current_time( 'mysql', true ),
				)
			);
			$this->backups->merge_meta(
				$uuid,
				array(
					'remote_id' => $name,
					'imported'  => true,
					'manifest'  => $manifest,
					'external'  => 'timevault' !== $source_format,
				)
			);

			$this->audit->record(
				'backup_imported',
				array(
					'file_name'   => $name,
					'type'        => $type,
					'encrypted'   => $encrypted,
					'source'      => $source_format,
					'source_site' => isset( $manifest['site']['home_url'] ) ? (string) $manifest['site']['home_url'] : '',
				),
				'backup',
				$uuid
			);

			return $uuid;
		} catch ( \Throwable $e ) {
			// Reject: never keep an unvalidated artifact in the registry/dir.
			$adapter->delete( $name );

			return new \WP_Error( 'timevault_import_invalid', esc_html( $e->getMessage() ) );
		} finally {
			Paths::delete_tree( $workdir );
		}
	}

	/**
	 * Validates a plaintext Timevault package or converts a supported external
	 * package into one.
	 *
	 * @param string $tmp_path      Upload temp path.
	 * @param string $original_name Client supplied file name.
	 * @param string $workdir       Import working directory.
	 * @return array{path: string, source_format: string}|\WP_Error
	 */
	private function prepare_plaintext_import_package( string $tmp_path, string $original_name, string $workdir ): array|\WP_Error {
		$inspection = $this->inspector->inspect( $tmp_path );

		if ( ! is_wp_error( $inspection ) ) {
			return array(
				'path'          => $tmp_path,
				'source_format' => 'timevault',
			);
		}

		$normalized = ( new ExternalPackageNormalizer() )->normalize( $tmp_path, $original_name, $workdir );

		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		return array(
			'path'          => (string) $normalized['path'],
			'source_format' => (string) $normalized['source_format'],
		);
	}

	/**
	 * Schedules a restore. MUST only be called after the REST layer has
	 * enforced double confirmation and rate limiting.
	 *
	 * @param string               $backup_uuid Source backup identifier.
	 * @param array<string, mixed> $options     Restore options (restore_files: bool, target_prefix: string).
	 * @return string|\WP_Error Restore job UUID.
	 */
	public function schedule_restore( string $backup_uuid, array $options = array() ): string|\WP_Error {
		if ( ! $this->queue->is_available() ) {
			return new \WP_Error( 'timevault_queue_unavailable', __( 'Action Scheduler is not available. Run "composer install" inside the plugin directory.', 'timevault' ) );
		}

		$backup = $this->backups->get( $backup_uuid );

		if ( null === $backup || 'completed' !== (string) $backup['status'] ) {
			return new \WP_Error( 'timevault_restore_source_missing', __( 'Source backup not found or not completed.', 'timevault' ) );
		}

		$uuid = $this->restores->create( $backup_uuid, array( 'options' => $options ) );

		if ( is_wp_error( $uuid ) ) {
			return $uuid;
		}

		$this->audit->record(
			'restore_scheduled',
			array(
				'source_backup' => $backup_uuid,
				'restore_files' => ! empty( $options['restore_files'] ),
			),
			'restore',
			$uuid
		);

		if ( ! empty( $options['manual_runner'] ) ) {
			return $uuid;
		}

		$dispatched = $this->queue->dispatch( self::STEP_HOOK, array( $uuid, 'safety_backup' ) );

		if ( is_wp_error( $dispatched ) ) {
			$this->fail( $uuid, $dispatched->get_error_message() );

			return $dispatched;
		}

		return $uuid;
	}

	/**
	 * Action Scheduler callback: runs one restore pipeline step.
	 *
	 * @param string $uuid Restore UUID.
	 * @param string $step Step name.
	 */
	public function handle_step( string $uuid, string $step ): void {
		$this->run_step( $uuid, $step, true );
	}

	/**
	 * Advances one restore step synchronously, used by the dashboard when the
	 * host does not process Action Scheduler jobs reliably.
	 *
	 * @param string $uuid Restore UUID.
	 * @return array<string, mixed>|\WP_Error Updated restore row.
	 */
	public function advance_restore( string $uuid ): array|\WP_Error {
		$row = $this->restores->get( $uuid );

		if ( null === $row ) {
			return new \WP_Error( 'timevault_not_found', __( 'Restore not found.', 'timevault' ) );
		}

		if ( in_array( (string) $row['status'], array( 'completed', 'failed' ), true ) ) {
			return $row;
		}

		$step = '' !== (string) $row['step'] ? (string) $row['step'] : 'safety_backup';
		$this->run_step( $uuid, $step, false );

		$updated = $this->restores->get( $uuid );

		return $updated ?? $row;
	}

	/**
	 * Runs a restore pipeline step.
	 *
	 * @param string $uuid          Restore UUID.
	 * @param string $step          Step to run.
	 * @param bool   $dispatch_next Whether to enqueue the next step with Action Scheduler.
	 */
	private function run_step( string $uuid, string $step, bool $dispatch_next ): void {
		$row = $this->restores->get( $uuid );

		if ( null === $row || in_array( (string) $row['status'], array( 'completed', 'failed' ), true ) ) {
			return; // Stale or replayed action.
		}

		$steps = array( 'safety_backup', 'validate', 'extract', 'restore_db', 'restore_files', 'finalize' );

		if ( ! in_array( $step, $steps, true ) ) {
			$this->fail( $uuid, 'Unknown restore step: ' . $step );

			return;
		}

		$this->restores->update(
			$uuid,
			array(
				'status' => 'running',
				'step'   => $step,
			)
		);

		try {
			$next = match ( $step ) {
				'safety_backup' => $this->step_safety_backup( $row ),
				'validate'      => $this->step_validate( $row ),
				'extract'       => $this->step_extract( $row ),
				'restore_db'    => $this->step_restore_db( $row ),
				'restore_files' => $this->step_restore_files( $row ),
				'finalize'      => $this->step_finalize( $row ),
			};

			if ( null !== $next ) {
				if ( $dispatch_next ) {
					$this->unwrap( $this->queue->dispatch( self::STEP_HOOK, array( $uuid, $next ) ) );
				} else {
					$this->restores->update(
						$uuid,
						array(
							'status' => 'pending',
							'step'   => $next,
						)
					);
				}
			}
		} catch ( \Throwable $e ) {
			$this->fail( $uuid, $e->getMessage() );
		}
	}

	/**
	 * Step 1: full backup of the current state, run to completion before any
	 * overwrite. This is the automatic safety net.
	 *
	 * @param array<string, mixed> $row Restore row.
	 * @return string Next step.
	 * @throws \RuntimeException On failure.
	 */
	private function step_safety_backup( array $row ): string {
		$uuid    = (string) $row['restore_uuid'];
		$options = (array) ( $row['meta']['options'] ?? array() );

		if ( ! empty( $options['skip_safety_backup'] ) ) {
			$this->audit->record( 'restore_safety_backup_skipped', array( 'reason' => 'user_opt_out' ), 'restore', $uuid );

			return 'validate';
		}

		if ( ! empty( $options['manual_runner'] ) ) {
			$safety = (string) ( $row['safety_backup_uuid'] ?? '' );

			if ( '' === $safety ) {
				$safety = $this->backup_mgr->start_manual( 'full', array( 'files_scope' => 'wp-content' ) );
				$this->unwrap( $safety );

				$this->restores->update( $uuid, array( 'safety_backup_uuid' => (string) $safety ) );

				return 'safety_backup';
			}

			$backup = $this->backup_mgr->advance_manual( $safety );
			$this->unwrap( $backup );

			if ( 'failed' === (string) $backup['status'] ) {
				$error = (string) ( $backup['meta']['error'] ?? __( 'The safety backup failed.', 'timevault' ) );
				throw new \RuntimeException( $error );
			}

			if ( 'completed' !== (string) $backup['status'] ) {
				return 'safety_backup';
			}

			$this->audit->record( 'restore_safety_backup', array( 'safety_backup' => $safety ), 'restore', $uuid );

			return 'validate';
		}

		$safety = $this->backup_mgr->run_now( 'full', array( 'files_scope' => 'wp-content' ) );

		$this->unwrap( $safety );

		$this->restores->update( $uuid, array( 'safety_backup_uuid' => (string) $safety ) );
		$this->audit->record( 'restore_safety_backup', array( 'safety_backup' => (string) $safety ), 'restore', $uuid );

		return 'validate';
	}

	/**
	 * Step 2: fetch source, verify checksum, decrypt, validate all entries.
	 *
	 * @param array<string, mixed> $row Restore row.
	 * @return string Next step.
	 * @throws \RuntimeException On failure.
	 */
	private function step_validate( array $row ): string {
		$uuid    = (string) $row['restore_uuid'];
		$backup  = $this->source_backup( $row );
		$workdir = Paths::ensure_working_dir( $uuid );

		$artifact = $this->fetch_artifact( $backup, $workdir );
		$this->unwrap( $artifact );

		$this->unwrap( $this->inspector->verify_checksum( (string) $artifact, (string) $backup['checksum_sha256'] ) );

		$package = $workdir . '/package.zip';
		$zip     = $this->inspector->to_plaintext_zip( (string) $artifact, (bool) $backup['is_encrypted'], $package );
		$this->unwrap( $zip );

		// to_plaintext_zip returns the artifact as-is for a non-encrypted
		// package (e.g. an imported .wpress). Copy it to the canonical
		// package.zip so step_extract (a separate job) always finds it.
		if ( (string) $zip !== $package && ! copy( (string) $zip, $package ) ) {
			throw new \RuntimeException( 'Could not stage the package for extraction.' );
		}

		$inspection = $this->inspector->inspect( $package );
		$this->unwrap( $inspection );

		$this->restores->merge_meta(
			$uuid,
			array(
				'manifest' => $inspection['manifest'],
				'entries'  => $inspection['entries'],
			)
		);

		return 'extract';
	}

	/**
	 * Step 3: safe extraction into a staging directory.
	 *
	 * @param array<string, mixed> $row Restore row.
	 * @return string Next step.
	 * @throws \RuntimeException On failure.
	 */
	private function step_extract( array $row ): string {
		$uuid    = (string) $row['restore_uuid'];
		$workdir = Paths::working_dir( $uuid );
		$staging = $workdir . '/extracted';

		$this->unwrap( $this->inspector->extract_to_staging( $workdir . '/package.zip', $staging ) );

		return 'restore_db';
	}

	/**
	 * Step 4: safe SQL import of the extracted dump.
	 *
	 * @param array<string, mixed> $row Restore row.
	 * @return string Next step.
	 * @throws \RuntimeException On failure.
	 */
	private function step_restore_db( array $row ): string {
		global $wpdb;

		$uuid     = (string) $row['restore_uuid'];
		$workdir  = Paths::working_dir( $uuid );
		$sql_file = $workdir . '/extracted/database.sql';

		if ( is_file( $sql_file ) ) {
			$manifest    = (array) ( $row['meta']['manifest'] ?? array() );
			$from_prefix = isset( $manifest['site']['db_prefix'] ) ? (string) $manifest['site']['db_prefix'] : null;
			$to_prefix   = $wpdb->base_prefix;

			// Snapshot Timevault's OWN bookkeeping before the dump overwrites
			// wp_options. Restoring an old dump would otherwise revert the
			// backup-directory suffix (relocating - and orphaning - every
			// backup, mid-restore), roll back the schema version, and possibly
			// deactivate the plugin, killing the pipeline. These few rows must
			// reflect reality, not the restored snapshot.
			$preserved = $this->snapshot_bookkeeping();
			$admin     = $this->snapshot_current_admin( (array) ( $row['meta']['options'] ?? array() ) );

			$stats = ( new SqlImporter() )->import( $sql_file, $from_prefix, $to_prefix );
			$this->unwrap( $stats );

			$this->restore_bookkeeping( $preserved );
			$this->preserve_current_admin( $admin, $uuid );

			$this->restores->merge_meta( $uuid, array( 'db' => $stats ) );
			$this->audit->record(
				'restore_db_applied',
				array(
					'statements' => $stats['executed'],
					'skipped'    => $stats['skipped'],
				),
				'restore',
				$uuid
			);
		}

		$options = (array) ( $row['meta']['options'] ?? array() );

		return ! empty( $options['restore_files'] ) ? 'restore_files' : 'finalize';
	}

	/**
	 * Step 5: copy extracted files into place (opt-in, containment-checked).
	 *
	 * @param array<string, mixed> $row Restore row.
	 * @return string Next step.
	 * @throws \RuntimeException On failure.
	 */
	private function step_restore_files( array $row ): string {
		$uuid    = (string) $row['restore_uuid'];
		$staging = Paths::working_dir( $uuid ) . '/extracted';

		$uploads = wp_get_upload_dir();
		$copied  = 0;

		// 'uploads/' → uploads basedir; 'files/' → wp-content. Each file lands
		// only on a PathGuard-contained target; secrets and our own dirs are skipped.
		$copied += $this->copy_tree( $staging . '/uploads', (string) $uploads['basedir'] );
		$copied += $this->copy_tree( $staging . '/files', WP_CONTENT_DIR );

		$this->restores->merge_meta( $uuid, array( 'files_copied' => $copied ) );
		$this->audit->record( 'restore_files_applied', array( 'files_copied' => $copied ), 'restore', $uuid );

		return 'finalize';
	}

	/**
	 * Step 6: cleanup, mark completed, audit, notify.
	 *
	 * @param array<string, mixed> $row Restore row.
	 * @return null No further step.
	 */
	private function step_finalize( array $row ): ?string {
		$uuid = (string) $row['restore_uuid'];

		Paths::delete_tree( Paths::working_dir( $uuid ) );
		$this->restores->update(
			$uuid,
			array(
				'status'       => 'completed',
				'step'         => 'finalize',
				'completed_at' => current_time( 'mysql', true ),
			)
		);
		$this->audit->record( 'restore_completed', array( 'safety_backup' => (string) $row['safety_backup_uuid'] ), 'restore', $uuid );
		$this->notify( $uuid, true );

		return null;
	}

	/**
	 * Captures the plugin's own operational state before a DB overwrite.
	 *
	 * @return array<string, mixed>
	 */
	private function snapshot_bookkeeping(): array {
		return array(
			'timevault_dir_suffix'     => get_option( 'timevault_dir_suffix' ),
			'timevault_schema_version' => get_option( 'timevault_schema_version' ),
			'siteurl'                  => get_option( 'siteurl' ),
			'home'                     => get_option( 'home' ),
			'active_plugins'           => get_option( 'active_plugins' ),
			'hostinger_options'        => $this->snapshot_hostinger_options(),
			'plugin_basename'          => plugin_basename( TIMEVAULT_FILE ),
		);
	}

	/**
	 * Re-asserts the plugin's own operational state after a DB overwrite, so
	 * the backup directory stays put, the schema stays current, and Timevault
	 * remains active to finish the pipeline. The object cache is flushed first
	 * because the raw SQL import bypassed it.
	 *
	 * @param array<string, mixed> $preserved Snapshot from snapshot_bookkeeping().
	 */
	private function restore_bookkeeping( array $preserved ): void {
		wp_cache_flush();

		if ( is_string( $preserved['timevault_dir_suffix'] ) && '' !== $preserved['timevault_dir_suffix'] ) {
			update_option( 'timevault_dir_suffix', $preserved['timevault_dir_suffix'], false );
		}

		if ( false !== $preserved['timevault_schema_version'] ) {
			update_option( 'timevault_schema_version', $preserved['timevault_schema_version'], false );
		}

		if ( is_string( $preserved['siteurl'] ) && '' !== $preserved['siteurl'] ) {
			update_option( 'siteurl', $preserved['siteurl'] );
		}

		if ( is_string( $preserved['home'] ) && '' !== $preserved['home'] ) {
			update_option( 'home', $preserved['home'] );
		}

		// Keep Timevault active regardless of what the restored snapshot said,
		// otherwise the next pipeline step would run without the plugin loaded.
		$active   = get_option( 'active_plugins' );
		$active   = is_array( $active ) ? $active : array();
		$basename = (string) $preserved['plugin_basename'];

		if ( ! in_array( $basename, $active, true ) ) {
			$active[] = $basename;
		}

		foreach ( $this->hostinger_active_plugins( (array) $preserved['active_plugins'] ) as $provider_plugin ) {
			if ( ! in_array( $provider_plugin, $active, true ) ) {
				$active[] = $provider_plugin;
			}
		}

		update_option( 'active_plugins', array_values( array_unique( $active ) ) );
		$this->restore_hostinger_options( (array) ( $preserved['hostinger_options'] ?? array() ) );
	}

	/**
	 * Captures Hostinger hPanel integration options before wp_options is replaced.
	 *
	 * The hPanel "Admin WordPress" button depends on Hostinger's local bridge.
	 * A migration should not import another site's hosting bridge nor erase the
	 * target site's bridge, so these operational rows are carried forward.
	 *
	 * @return array<string, array{value: mixed, autoload: string}>
	 */
	private function snapshot_hostinger_options(): array {
		global $wpdb;

		$patterns = array(
			$wpdb->esc_like( 'hostinger' ) . '%',
			$wpdb->esc_like( 'hts_' ) . '%',
		);
		$where    = array();
		$args     = array();

		foreach ( $patterns as $pattern ) {
			$where[] = 'option_name LIKE %s';
			$args[]  = $pattern;
		}

		$sql = "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE " . implode( ' OR ', $where );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Restore-time hosting bridge preservation.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$options = array();

		foreach ( $rows as $row ) {
			$name = (string) ( $row['option_name'] ?? '' );

			if ( '' === $name ) {
				continue;
			}

			$options[ $name ] = array(
				'value'    => maybe_unserialize( (string) ( $row['option_value'] ?? '' ) ),
				'autoload' => (string) ( $row['autoload'] ?? 'auto' ),
			);
		}

		return $options;
	}

	/**
	 * Restores Hostinger hPanel integration options after wp_options is replaced.
	 *
	 * @param array<string, array{value?: mixed, autoload?: string}> $options Snapshot from snapshot_hostinger_options().
	 */
	private function restore_hostinger_options( array $options ): void {
		foreach ( $options as $name => $row ) {
			if ( ! is_string( $name ) || '' === $name ) {
				continue;
			}

			update_option( $name, $row['value'] ?? '', (string) ( $row['autoload'] ?? 'auto' ) );
		}
	}

	/**
	 * Keeps only Hostinger plugins that were active before the restore.
	 *
	 * @param array<int, mixed> $active_plugins Original active_plugins option.
	 * @return array<int, string>
	 */
	private function hostinger_active_plugins( array $active_plugins ): array {
		$provider_plugins = array();

		foreach ( $active_plugins as $plugin ) {
			$plugin = is_string( $plugin ) ? $plugin : '';

			if ( '' === $plugin ) {
				continue;
			}

			if ( str_starts_with( $plugin, 'hostinger/' ) || str_starts_with( $plugin, 'hostinger-' ) ) {
				$provider_plugins[] = $plugin;
			}
		}

		return $provider_plugins;
	}

	/**
	 * Captures the current administrator before the users table is overwritten.
	 *
	 * @param array<string, mixed> $options Restore options.
	 * @return array<string, string>|null Admin snapshot.
	 */
	private function snapshot_current_admin( array $options ): ?array {
		if ( empty( $options['preserve_admin'] ) || ! function_exists( 'wp_get_current_user' ) ) {
			return null;
		}

		$user = wp_get_current_user();

		if ( ! $user || ! $user->exists() || ! user_can( $user, 'manage_options' ) ) {
			return null;
		}

		return array(
			'user_login'      => (string) $user->user_login,
			'user_pass'       => (string) $user->user_pass,
			'user_nicename'   => (string) $user->user_nicename,
			'user_email'      => (string) $user->user_email,
			'user_url'        => (string) $user->user_url,
			'user_registered' => (string) $user->user_registered,
			'display_name'    => (string) $user->display_name,
		);
	}

	/**
	 * Recreates the current admin in the imported database and issues a fresh
	 * auth cookie so wp-admin can continue without a manual login.
	 *
	 * @param array<string, string>|null $admin        Admin snapshot.
	 * @param string                     $restore_uuid Restore UUID.
	 */
	private function preserve_current_admin( ?array $admin, string $restore_uuid ): void {
		if ( null === $admin || '' === ( $admin['user_login'] ?? '' ) || '' === ( $admin['user_pass'] ?? '' ) ) {
			return;
		}

		global $wpdb;

		$login = (string) $admin['user_login'];
		$email = (string) $admin['user_email'];

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Restore-time admin preservation after DB replacement.
		$user_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_login = %s LIMIT 1", $login ) );

		if ( 0 === $user_id && '' !== $email ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Restore-time admin preservation after DB replacement.
			$user_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_email = %s LIMIT 1", $email ) );
		}

		$data = array(
			'user_pass'     => (string) $admin['user_pass'],
			'user_nicename' => (string) ( $admin['user_nicename'] ?: $login ),
			'user_email'    => $email,
			'user_url'      => (string) $admin['user_url'],
			'display_name'  => (string) ( $admin['display_name'] ?: $login ),
		);

		if ( $user_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Restore-time admin preservation after DB replacement.
			$wpdb->update( $wpdb->users, $data, array( 'ID' => $user_id ) );
		} else {
			$data['user_login']      = $login;
			$data['user_registered'] = (string) ( $admin['user_registered'] ?: current_time( 'mysql', true ) );
			$data['user_status']     = 0;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Restore-time admin preservation after DB replacement.
			$wpdb->insert( $wpdb->users, $data );
			$user_id = (int) $wpdb->insert_id;
		}

		if ( $user_id <= 0 ) {
			return;
		}

		update_user_meta( $user_id, $wpdb->base_prefix . 'capabilities', array( 'administrator' => true ) );
		update_user_meta( $user_id, $wpdb->base_prefix . 'user_level', 10 );
		clean_user_cache( $user_id );
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true, is_ssl() );

		$this->audit->record( 'restore_admin_preserved', array( 'user_login' => $login ), 'restore', $restore_uuid );
	}

	/**
	 * Copies a staging subtree into a destination with containment + exclusions.
	 *
	 * @param string $source_root Staging subtree (may not exist).
	 * @param string $dest_root   Destination root.
	 * @return int Files copied.
	 * @throws \RuntimeException On a containment violation.
	 */
	private function copy_tree( string $source_root, string $dest_root ): int {
		if ( ! is_dir( $source_root ) ) {
			return 0;
		}

		$backup_dir = wp_normalize_path( Paths::backup_dir() );
		$plugin_dir = wp_normalize_path( untrailingslashit( TIMEVAULT_DIR ) );
		$source_len = strlen( trailingslashit( wp_normalize_path( (string) realpath( $source_root ) ) ) );

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $source_root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		$copied = 0;

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || $file->isLink() ) {
				continue;
			}

			$relative = substr( wp_normalize_path( $file->getPathname() ), $source_len );

			if ( 'wp-config.php' === basename( $relative ) ) {
				continue; // Never restore secrets over the live config.
			}

			$target = PathGuard::safe_target( $dest_root, $relative );

			if ( is_wp_error( $target ) ) {
				throw new \RuntimeException( esc_html( $target->get_error_message() ) );
			}

			$target_norm = wp_normalize_path( $target );

			// Never overwrite our own backup directory or the running plugin.
			if ( str_starts_with( $target_norm, trailingslashit( $backup_dir ) ) || str_starts_with( $target_norm, trailingslashit( $plugin_dir ) ) ) {
				continue;
			}

			wp_mkdir_p( dirname( $target ) );

			if ( ! copy( $file->getPathname(), $target ) ) {
				throw new \RuntimeException( 'Could not restore a file into place.' );
			}

			++$copied;
		}

		return $copied;
	}

	/**
	 * Fetches a backup artifact from its storage adapter into a local path.
	 *
	 * @param array<string, mixed> $backup  Backup registry row.
	 * @param string               $workdir Working directory.
	 * @return string|\WP_Error Local artifact path.
	 */
	private function fetch_artifact( array $backup, string $workdir ): string|\WP_Error {
		$storage = (string) $backup['storage'];
		$adapter = $this->adapters[ $storage ] ?? null;

		if ( null === $adapter ) {
			return new \WP_Error( 'timevault_unknown_storage', __( 'Storage destination is no longer available.', 'timevault' ) );
		}

		$remote_id = (string) ( $backup['meta']['remote_id'] ?? $backup['file_name'] );
		$local     = $workdir . '/artifact.bin';

		$result = $adapter->retrieve( $remote_id, $local );

		return is_wp_error( $result ) ? $result : $local;
	}

	/**
	 * Loads the source backup row for a restore.
	 *
	 * @param array<string, mixed> $row Restore row.
	 * @return array<string, mixed>
	 * @throws \RuntimeException When the source backup is gone.
	 */
	private function source_backup( array $row ): array {
		$backup = $this->backups->get( (string) $row['source_backup_uuid'] );

		if ( null === $backup ) {
			throw new \RuntimeException( 'Source backup no longer exists.' );
		}

		return $backup;
	}

	/**
	 * Marks a restore as failed, audits, cleans up and notifies.
	 *
	 * @param string $uuid    Restore UUID.
	 * @param string $message Failure reason (truncated; no secrets).
	 */
	private function fail( string $uuid, string $message ): void {
		$message = substr( $message, 0, 500 );

		$this->restores->update( $uuid, array( 'status' => 'failed' ) );
		$this->restores->merge_meta( $uuid, array( 'error' => $message ) );
		$this->audit->record( 'restore_failed', array( 'error' => $message ), 'restore', $uuid );

		Paths::delete_tree( Paths::working_dir( $uuid ) );
		$this->notify( $uuid, false, $message );
	}

	/**
	 * Optional e-mail notification (off unless the option holds a valid address).
	 *
	 * @param string $uuid    Restore UUID.
	 * @param bool   $success Outcome.
	 * @param string $detail  Failure detail, if any.
	 */
	private function notify( string $uuid, bool $success, string $detail = '' ): void {
		$to = (string) get_option( 'timevault_notify_email', '' );

		if ( '' === $to || ! is_email( $to ) ) {
			return;
		}

		$host    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$subject = $success
			/* translators: %s: site host. */
			? sprintf( __( '[Timevault] Restore completed on %s', 'timevault' ), $host )
			/* translators: %s: site host. */
			: sprintf( __( '[Timevault] Restore FAILED on %s', 'timevault' ), $host );
		$body = $success
			/* translators: %s: restore id. */
			? sprintf( __( 'Restore %s finished successfully.', 'timevault' ), $uuid )
			/* translators: 1: restore id, 2: error. */
			: sprintf( __( 'Restore %1$s failed: %2$s', 'timevault' ), $uuid, $detail );

		wp_mail( $to, $subject, $body );
	}

	/**
	 * Converts a WP_Error into an exception (single failure path in steps).
	 *
	 * @param mixed $result Result to check.
	 * @return mixed The result, when not an error.
	 * @throws \RuntimeException When $result is a WP_Error.
	 */
	private function unwrap( mixed $result ): mixed {
		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( esc_html( $result->get_error_message() ) );
		}

		return $result;
	}
}
