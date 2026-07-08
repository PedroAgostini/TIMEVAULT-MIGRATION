<?php
/**
 * Backup orchestration.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Core;

use Timevault\Queue\JobQueue;
use Timevault\Storage\StorageAdapterInterface;
use Timevault\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates backups as an asynchronous step pipeline
 * (dump_db → package → finalize).
 *
 * Each step runs as its own Action Scheduler action - long work is never
 * executed synchronously inside a web request, and a step that dies never
 * leaves the pipeline half-applied (the registry row records the failure).
 *
 * Security decisions:
 * - No synchronous fallback: if the queue is unavailable, scheduling fails
 *   loudly instead of blocking a request for minutes.
 * - The checksum recorded in the registry is computed over the FINAL stored
 *   artifact (after encryption), so it can be verified before any restore
 *   without decrypting first.
 * - Every state change is written to the append-only AuditLog; audit context
 *   carries names/counts only, never secrets or credentials.
 * - Working files live inside the hardened backup directory and are removed
 *   on completion and on failure.
 */
final class BackupManager {

	/**
	 * Action Scheduler hook that executes pipeline steps.
	 */
	public const STEP_HOOK = 'timevault_backup_step';

	private const TYPES = array( 'full', 'db', 'export' );

	/**
	 * Constructor.
	 *
	 * @param BackupRepository                       $repository Backup registry.
	 * @param JobQueue                               $queue      Async job queue.
	 * @param AuditLog                               $audit      Append-only audit log.
	 * @param EncryptionService                      $encryption Encryption at rest.
	 * @param array<string, StorageAdapterInterface> $adapters   Available destinations, keyed by id ('local' always present; external ones only when explicitly enabled).
	 */
	public function __construct(
		private BackupRepository $repository,
		private JobQueue $queue,
		private AuditLog $audit,
		private EncryptionService $encryption,
		private array $adapters
	) {}

	/**
	 * Schedules a full backup (database + files).
	 *
	 * @param array<string, mixed> $options Options (files_scope: wp-content|full).
	 * @return string|\WP_Error Backup UUID.
	 */
	public function schedule_full_backup( array $options = array() ): string|\WP_Error {
		return $this->schedule( 'full', $options );
	}

	/**
	 * Schedules a database-only backup.
	 *
	 * @param array<string, mixed> $options Options.
	 * @return string|\WP_Error Backup UUID.
	 */
	public function schedule_database_backup( array $options = array() ): string|\WP_Error {
		return $this->schedule( 'db', $options );
	}

	/**
	 * Creates the registry row and dispatches the first pipeline step.
	 *
	 * @param string               $type    Backup type: full|db|export.
	 * @param array<string, mixed> $options Options stored in the row meta.
	 * @return string|\WP_Error Backup UUID.
	 */
	public function schedule( string $type, array $options = array() ): string|\WP_Error {
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new \WP_Error( 'timevault_invalid_type', __( 'Invalid backup type.', 'timevault' ) );
		}

		if ( ! $this->queue->is_available() ) {
			// Deliberately no synchronous fallback: long jobs must be async.
			return new \WP_Error( 'timevault_queue_unavailable', __( 'Action Scheduler is not available. Run "composer install" inside the plugin directory.', 'timevault' ) );
		}

		// Destination must be a registered adapter: 'local' always, external
		// ones only after explicit opt-in (enabled + credentials stored).
		$storage = (string) ( $options['storage'] ?? 'local' );

		if ( ! isset( $this->adapters[ $storage ] ) ) {
			return new \WP_Error( 'timevault_unknown_storage', __( 'Unknown or disabled storage destination.', 'timevault' ) );
		}

		unset( $options['storage'] );

		$uuid = $this->repository->create( $type, $storage, array( 'options' => $options ) );

		if ( is_wp_error( $uuid ) ) {
			return $uuid;
		}

		$this->audit->record(
			'backup_scheduled',
			array(
				'type'    => $type,
				'storage' => $storage,
			),
			'backup',
			$uuid
		);

		$dispatched = $this->queue->dispatch( self::STEP_HOOK, array( $uuid, 'dump_db' ) );

		if ( is_wp_error( $dispatched ) ) {
			$this->fail( $uuid, $dispatched->get_error_message() );

			return $dispatched;
		}

		return $uuid;
	}

	/**
	 * Action Scheduler callback: runs one pipeline step.
	 *
	 * @param string $uuid Backup UUID.
	 * @param string $step Step name.
	 */
	public function handle_step( string $uuid, string $step ): void {
		$row = $this->repository->get( $uuid );

		if ( null === $row || in_array( (string) $row['status'], array( 'completed', 'failed' ), true ) ) {
			return; // Stale or replayed action - a finished pipeline never re-runs.
		}

		if ( ! in_array( $step, array( 'dump_db', 'package', 'finalize' ), true ) ) {
			$this->fail( $uuid, 'Unknown backup step: ' . $step );

			return;
		}

		try {
			$next = match ( $step ) {
				'dump_db'  => $this->step_dump_db( $row ),
				'package'  => $this->step_package( $row ),
				'finalize' => $this->step_finalize( $row ),
			};

			if ( null !== $next ) {
				$this->next_step( $uuid, $next );
			}
		} catch ( \Throwable $e ) {
			$this->fail( $uuid, $e->getMessage() );
		}
	}

	/**
	 * Runs a backup to completion synchronously (no Action Scheduler).
	 *
	 * Used for the automatic safety backup taken before a restore, where the
	 * ordering "backup completes BEFORE anything is overwritten" must be
	 * guaranteed. Not for web requests - callers are already async jobs.
	 *
	 * @param string               $type    Backup type: full|db.
	 * @param array<string, mixed> $options Options (storage, files_scope).
	 * @return string|\WP_Error Completed backup UUID.
	 */
	public function run_now( string $type, array $options = array() ): string|\WP_Error {
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new \WP_Error( 'timevault_invalid_type', __( 'Invalid backup type.', 'timevault' ) );
		}

		$storage = (string) ( $options['storage'] ?? 'local' );

		if ( ! isset( $this->adapters[ $storage ] ) ) {
			return new \WP_Error( 'timevault_unknown_storage', __( 'Unknown or disabled storage destination.', 'timevault' ) );
		}

		unset( $options['storage'] );

		$uuid = $this->repository->create( $type, $storage, array( 'options' => $options ) );

		if ( is_wp_error( $uuid ) ) {
			return $uuid;
		}

		$this->audit->record(
			'backup_scheduled',
			array(
				'type'    => $type,
				'storage' => $storage,
				'sync'    => true,
			),
			'backup',
			$uuid
		);

		try {
			$step = 'dump_db';

			while ( null !== $step ) {
				$row  = $this->repository->get( $uuid );
				$step = match ( $step ) {
					'dump_db'  => $this->step_dump_db( (array) $row ),
					'package'  => $this->step_package( (array) $row ),
					'finalize' => $this->step_finalize( (array) $row ),
					default    => null,
				};
			}
		} catch ( \Throwable $e ) {
			$this->fail( $uuid, $e->getMessage() );

			return new \WP_Error( 'timevault_safety_backup_failed', $e->getMessage() );
		}

		return $uuid;
	}

	/**
	 * Step 1: dump the database into the working directory.
	 *
	 * @param array<string, mixed> $row Registry row.
	 * @return string Next step.
	 * @throws \RuntimeException On failure.
	 */
	private function step_dump_db( array $row ): string {
		$uuid = (string) $row['backup_uuid'];
		$this->repository->update( $uuid, array( 'status' => 'running' ) );

		$workdir = Paths::ensure_working_dir( $uuid );
		$options = (array) ( $row['meta']['options'] ?? array() );

		$only = null;

		if ( 'export' === $row['type'] ) {
			$only = array_map( 'strval', (array) ( $options['tables'] ?? array() ) );
		}

		if ( array() === $only ) {
			// Export with no table selection (e.g. uploads only): skip the dump.
			$this->repository->merge_meta(
				$uuid,
				array(
					'db' => array(
						'tables' => 0,
						'rows'   => 0,
					),
				)
			);
		} else {
			$dumper = new DatabaseDumper();

			// Staging/dev exports get personal data pseudonymized at the row
			// level before it is ever written to the dump (LGPD, P5).
			if ( ! empty( $options['anonymize'] ) ) {
				$dumper->set_row_transformer( ( new \Timevault\Privacy\Anonymizer() )->transformer() );
				$this->repository->merge_meta( $uuid, array( 'anonymized' => true ) );
			}

			$stats = $dumper->dump( $workdir . '/database.sql', $only, ! empty( $options['all_tables'] ) );
			$this->unwrap( $stats );
			$this->repository->merge_meta( $uuid, array( 'db' => $stats ) );
		}

		return 'package';
	}

	/**
	 * Step 2: package the dump (and files, per type) into a single ZIP with
	 * a JSON manifest.
	 *
	 * @param array<string, mixed> $row Registry row.
	 * @return string Next step.
	 * @throws \RuntimeException On failure.
	 */
	private function step_package( array $row ): string {
		$uuid    = (string) $row['backup_uuid'];
		$workdir = Paths::working_dir( $uuid );
		$options = (array) ( $row['meta']['options'] ?? array() );

		$named = array();
		$trees = array();

		if ( is_file( $workdir . '/database.sql' ) ) {
			$named['database.sql'] = $workdir . '/database.sql';
		}

		$files_scope = 'none';

		if ( 'full' === $row['type'] ) {
			$files_scope = ( 'full' === ( $options['files_scope'] ?? '' ) ) ? 'full' : 'wp-content';
			$root        = ( 'full' === $files_scope ) ? untrailingslashit( ABSPATH ) : WP_CONTENT_DIR;

			// Exclude the cache and EVERY timevault-* directory in wp-content -
			// not just the active one - so a relocated/orphaned backup store is
			// never swept into a new backup.
			$exclude = array_merge(
				array( WP_CONTENT_DIR . '/cache' ),
				(array) glob( WP_CONTENT_DIR . '/timevault-*', GLOB_ONLYDIR )
			);

			$trees['files'] = array(
				'root'          => $root,
				'exclude_paths' => $exclude,
			);
		}

		if ( 'export' === $row['type'] && ! empty( $options['include_uploads'] ) ) {
			$uploads = wp_get_upload_dir();

			$trees['uploads'] = array(
				'root'          => (string) $uploads['basedir'],
				'exclude_paths' => array( Paths::backup_dir() ),
			);
		}

		$manifest = $this->build_manifest( $row, $files_scope );
		$stats    = ( new FilePackager() )->package( $workdir . '/package.zip', $named, $trees, $manifest );
		$this->unwrap( $stats );

		$this->repository->merge_meta( $uuid, array( 'files' => $stats ) );

		return 'finalize';
	}

	/**
	 * Step 3: encrypt, checksum, store, complete.
	 *
	 * @param array<string, mixed> $row Registry row.
	 * @return null No further step.
	 * @throws \RuntimeException On failure.
	 */
	private function step_finalize( array $row ): ?string {
		$uuid     = (string) $row['backup_uuid'];
		$workdir  = Paths::working_dir( $uuid );
		$artifact = $workdir . '/package.zip';

		if ( ! is_file( $artifact ) ) {
			throw new \RuntimeException( 'Package artifact is missing.' );
		}

		$name      = sprintf( 'timevault-%s-%s-%s.zip', (string) $row['type'], gmdate( 'Ymd-His' ), substr( $uuid, 0, 8 ) );
		$encrypted = 0;

		if ( $this->encryption->is_configured() ) {
			$encrypted_path = $workdir . '/package.zip.enc';
			$this->unwrap( $this->encryption->encrypt_file( $artifact, $encrypted_path ) );
			wp_delete_file( $artifact ); // Plaintext never reaches final storage.

			$artifact  = $encrypted_path;
			$name     .= '.enc';
			$encrypted = 1;
		} else {
			// Allowed, but loudly recorded: encryption at rest is a release requirement.
			$this->audit->record( 'backup_unencrypted', array( 'reason' => 'encryption key not configured' ), 'backup', $uuid );
		}

		// Checksum of the FINAL artifact - verifiable before restore without decrypting.
		$checksum = hash_file( 'sha256', $artifact );

		if ( false === $checksum ) {
			throw new \RuntimeException( 'Could not compute the package checksum.' );
		}

		$size    = (int) filesize( $artifact );
		$adapter = $this->adapter_for( $row );
		$stored  = $adapter->store( $artifact, $name );
		$this->unwrap( $stored );

		$this->repository->update(
			$uuid,
			array(
				'status'          => 'completed',
				'file_name'       => $name,
				'size_bytes'      => $size,
				'checksum_sha256' => $checksum,
				'is_encrypted'    => $encrypted,
				'completed_at'    => current_time( 'mysql', true ),
			)
		);

		// Remote identifier (object key / Drive file id) kept in meta for retrieval.
		$this->repository->merge_meta( $uuid, array( 'remote_id' => (string) $stored ) );

		$this->audit->record(
			'backup_completed',
			array(
				'file_name'       => $name,
				'size_bytes'      => $size,
				'checksum_sha256' => $checksum,
				'encrypted'       => (bool) $encrypted,
				'storage'         => $adapter->id(),
				'storage_region'  => $adapter->region(), // LGPD Art. 33 traceability.
			),
			'backup',
			$uuid
		);

		Paths::delete_tree( $workdir );
		$this->notify( $uuid, true );

		/**
		 * Fires when a backup completes successfully. Used by the scheduler to
		 * rotate automatic backups.
		 *
		 * @param string $uuid Completed backup UUID.
		 */
		do_action( 'timevault_backup_completed', $uuid );

		return null;
	}

	/**
	 * Marks a backup as failed, audits, cleans up and notifies.
	 *
	 * @param string $uuid    Backup UUID.
	 * @param string $message Failure reason (truncated; must not contain secrets).
	 */
	private function fail( string $uuid, string $message ): void {
		$message = substr( $message, 0, 500 );

		$this->repository->update( $uuid, array( 'status' => 'failed' ) );
		$this->repository->merge_meta( $uuid, array( 'error' => $message ) );
		$this->audit->record( 'backup_failed', array( 'error' => $message ), 'backup', $uuid );

		Paths::delete_tree( Paths::working_dir( $uuid ) );
		$this->notify( $uuid, false, $message );
	}

	/**
	 * Dispatches the next pipeline step.
	 *
	 * @param string $uuid Backup UUID.
	 * @param string $step Next step name.
	 * @throws \RuntimeException When dispatch fails.
	 */
	private function next_step( string $uuid, string $step ): void {
		$this->unwrap( $this->queue->dispatch( self::STEP_HOOK, array( $uuid, $step ) ) );
	}

	/**
	 * Resolves the destination adapter recorded on the registry row.
	 *
	 * @param array<string, mixed> $row Registry row.
	 * @throws \RuntimeException When the destination is no longer available (e.g. disabled after scheduling).
	 */
	private function adapter_for( array $row ): StorageAdapterInterface {
		$id = (string) $row['storage'];

		if ( ! isset( $this->adapters[ $id ] ) ) {
			throw new \RuntimeException( 'Storage destination not available: ' . esc_html( $id ) );
		}

		return $this->adapters[ $id ];
	}

	/**
	 * Builds the JSON manifest embedded in the package.
	 *
	 * @param array<string, mixed> $row         Registry row.
	 * @param string               $files_scope Files scope: none|wp-content|full.
	 * @return array<string, mixed>
	 */
	private function build_manifest( array $row, string $files_scope ): array {
		global $wpdb;

		return array(
			'format'      => 1,
			'generator'   => 'timevault/' . TIMEVAULT_VERSION,
			'type'        => (string) $row['type'],
			'backup_uuid' => (string) $row['backup_uuid'],
			'created_at'  => gmdate( 'c' ),
			'site'        => array(
				'home_url'   => home_url(),
				'site_url'   => site_url(),
				'wp_version' => get_bloginfo( 'version' ),
				'db_prefix'  => $wpdb->base_prefix,
				'charset'    => get_bloginfo( 'charset' ),
			),
			'database'    => (array) ( $row['meta']['db'] ?? array(
				'tables' => 0,
				'rows'   => 0,
			) ),
			'files_scope' => $files_scope,
			'privacy'     => array(
				// True when this export had personal data pseudonymized (LGPD).
				'anonymized' => ! empty( $row['meta']['anonymized'] ),
			),
			'security'    => array(
				'wp_config_excluded' => true,
				'checksum_algorithm' => 'sha256',
				'serialization'      => 'json', // Manifest and meta are JSON only - never PHP serialize.
			),
		);
	}

	/**
	 * Optional e-mail notification (off unless the option holds a valid address).
	 *
	 * @param string $uuid    Backup UUID.
	 * @param bool   $success Outcome.
	 * @param string $detail  Failure detail, if any.
	 */
	private function notify( string $uuid, bool $success, string $detail = '' ): void {
		$to = (string) get_option( 'timevault_notify_email', '' );

		if ( '' === $to || ! is_email( $to ) ) {
			return;
		}

		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		$subject = $success
			/* translators: %s: site host name. */
			? sprintf( __( '[Timevault] Backup completed on %s', 'timevault' ), $host )
			/* translators: %s: site host name. */
			: sprintf( __( '[Timevault] Backup FAILED on %s', 'timevault' ), $host );

		$body = $success
			/* translators: %s: backup identifier. */
			? sprintf( __( 'Backup %s finished successfully.', 'timevault' ), $uuid )
			/* translators: 1: backup identifier, 2: error detail. */
			: sprintf( __( 'Backup %1$s failed: %2$s', 'timevault' ), $uuid, $detail );

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
