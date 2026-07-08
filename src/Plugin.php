<?php
/**
 * Plugin bootstrap and lightweight service container.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault;

defined( 'ABSPATH' ) || exit;

/**
 * Central bootstrap: wires the layers together (Admin UI, REST API,
 * Core Engine, Storage Adapters, Job Queue) and exposes lazily built
 * shared services.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Shared audit log service.
	 *
	 * @var Core\AuditLog|null
	 */
	private ?Core\AuditLog $audit_log = null;

	/**
	 * Shared encryption service.
	 *
	 * @var Core\EncryptionService|null
	 */
	private ?Core\EncryptionService $encryption = null;

	/**
	 * Shared job queue (Action Scheduler wrapper).
	 *
	 * @var Queue\JobQueue|null
	 */
	private ?Queue\JobQueue $queue = null;

	/**
	 * Shared backup registry.
	 *
	 * @var Core\BackupRepository|null
	 */
	private ?Core\BackupRepository $backup_repository = null;

	/**
	 * Shared backup orchestrator.
	 *
	 * @var Core\BackupManager|null
	 */
	private ?Core\BackupManager $backup_manager = null;

	/**
	 * Shared export manager.
	 *
	 * @var Core\ExportManager|null
	 */
	private ?Core\ExportManager $export_manager = null;

	/**
	 * Shared destination settings (encrypted credentials).
	 *
	 * @var Storage\DestinationSettings|null
	 */
	private ?Storage\DestinationSettings $destination_settings = null;

	/**
	 * Shared restore registry.
	 *
	 * @var Restore\RestoreRepository|null
	 */
	private ?Restore\RestoreRepository $restore_repository = null;

	/**
	 * Shared import/restore orchestrator.
	 *
	 * @var Core\ImportManager|null
	 */
	private ?Core\ImportManager $import_manager = null;

	/**
	 * Shared privacy/LGPD service.
	 *
	 * @var Core\PrivacyService|null
	 */
	private ?Core\PrivacyService $privacy = null;

	/**
	 * Shared schedule manager (automatic backups + rotation).
	 *
	 * @var Core\ScheduleManager|null
	 */
	private ?Core\ScheduleManager $schedule = null;

	/**
	 * Retrieves the singleton instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Use Plugin::instance().
	 */
	private function __construct() {}

	/**
	 * Boots the plugin on `plugins_loaded`.
	 */
	public function boot(): void {
		load_plugin_textdomain( 'timevault', false, dirname( plugin_basename( TIMEVAULT_FILE ) ) . '/languages' );

		if ( is_admin() ) {
			Activation\Activator::maybe_upgrade();
		}

		( new Admin\AdminMenu( $this ) )->register();
		( new Rest\StatusController( $this ) )->register();
		( new Rest\BackupsController( $this ) )->register();
		( new Rest\DestinationsController( $this ) )->register();
		( new Rest\DownloadController( $this ) )->register();
		( new Rest\RestoreController( $this ) )->register();
		( new Rest\PrivacyController( $this ) )->register();
		( new Rest\ImportController( $this ) )->register();
		( new Rest\ScheduleController( $this ) )->register();

		// Action Scheduler executes pipeline steps through these hooks.
		add_action( Core\BackupManager::STEP_HOOK, array( $this->backups(), 'handle_step' ), 10, 2 );
		add_action( Core\ImportManager::STEP_HOOK, array( $this->imports(), 'handle_step' ), 10, 2 );
		add_action( Core\PrivacyService::RETENTION_HOOK, array( $this->privacy(), 'apply_retention_policy' ) );
		add_action( Core\ScheduleManager::HOOK, array( $this->schedule(), 'run' ) );
		add_action( 'timevault_backup_completed', array( $this->schedule(), 'on_backup_completed' ) );

		// Scheduling touches the Action Scheduler store, which only initializes
		// on `init` - registering it earlier (plugins_loaded) is "called too
		// early" and does not persist.
		add_action( 'init', array( $this, 'schedule_maintenance' ), 20 );
	}

	/**
	 * Ensures the recurring maintenance jobs match their config. Idempotent.
	 */
	public function schedule_maintenance(): void {
		$queue = $this->queue();

		if ( $queue->is_available() ) {
			$queue->schedule_recurring( DAY_IN_SECONDS, Core\PrivacyService::RETENTION_HOOK );
			$this->schedule()->ensure_scheduled();
		}
	}

	/**
	 * Append-only audit log (LGPD Art. 6, VI - accountability).
	 */
	public function audit_log(): Core\AuditLog {
		if ( null === $this->audit_log ) {
			$this->audit_log = new Core\AuditLog();
		}

		return $this->audit_log;
	}

	/**
	 * Encryption service. The key lives in wp-config.php, never in the database.
	 */
	public function encryption(): Core\EncryptionService {
		if ( null === $this->encryption ) {
			$this->encryption = new Core\EncryptionService();
		}

		return $this->encryption;
	}

	/**
	 * Job queue for long-running work (backup, export, restore).
	 */
	public function queue(): Queue\JobQueue {
		if ( null === $this->queue ) {
			$this->queue = new Queue\JobQueue();
		}

		return $this->queue;
	}

	/**
	 * Backup registry (timevault_backups table).
	 */
	public function backup_repository(): Core\BackupRepository {
		if ( null === $this->backup_repository ) {
			$this->backup_repository = new Core\BackupRepository();
		}

		return $this->backup_repository;
	}

	/**
	 * Backup orchestrator (async step pipeline).
	 */
	public function backups(): Core\BackupManager {
		if ( null === $this->backup_manager ) {
			$this->backup_manager = new Core\BackupManager(
				$this->backup_repository(),
				$this->queue(),
				$this->audit_log(),
				$this->encryption(),
				$this->storage_adapters()
			);
		}

		return $this->backup_manager;
	}

	/**
	 * External destination configuration (credentials encrypted at rest).
	 */
	public function destination_settings(): Storage\DestinationSettings {
		if ( null === $this->destination_settings ) {
			$this->destination_settings = new Storage\DestinationSettings( $this->encryption(), $this->audit_log() );
		}

		return $this->destination_settings;
	}

	/**
	 * Restore registry (timevault_restores table).
	 */
	public function restore_repository(): Restore\RestoreRepository {
		if ( null === $this->restore_repository ) {
			$this->restore_repository = new Restore\RestoreRepository();
		}

		return $this->restore_repository;
	}

	/**
	 * Import/restore orchestrator (highest-risk component).
	 */
	public function imports(): Core\ImportManager {
		if ( null === $this->import_manager ) {
			$this->import_manager = new Core\ImportManager(
				$this->restore_repository(),
				$this->backup_repository(),
				$this->backups(),
				$this->queue(),
				$this->audit_log(),
				new Restore\ArchiveInspector( $this->encryption() ),
				$this->storage_adapters()
			);
		}

		return $this->import_manager;
	}

	/**
	 * Privacy/LGPD service (anonymization policy, retention, processing record).
	 */
	public function privacy(): Core\PrivacyService {
		if ( null === $this->privacy ) {
			$this->privacy = new Core\PrivacyService(
				$this->backup_repository(),
				$this->audit_log(),
				$this->destination_settings(),
				$this->storage_adapters()
			);
		}

		return $this->privacy;
	}

	/**
	 * Schedule manager (automatic backups + rotation).
	 */
	public function schedule(): Core\ScheduleManager {
		if ( null === $this->schedule ) {
			$this->schedule = new Core\ScheduleManager(
				$this->backup_repository(),
				$this->backups(),
				$this->queue(),
				$this->audit_log(),
				$this->storage_adapters()
			);
		}

		return $this->schedule;
	}

	/**
	 * Selective export manager.
	 */
	public function exports(): Core\ExportManager {
		if ( null === $this->export_manager ) {
			$this->export_manager = new Core\ExportManager( $this->backups() );
		}

		return $this->export_manager;
	}

	/**
	 * Registered storage adapters, keyed by adapter id.
	 *
	 * Only the local adapter is always available. External destinations are
	 * registered ONLY when the site owner explicitly enabled them with
	 * encrypted credentials stored (opt-in, never active by default).
	 *
	 * @return array<string, Storage\StorageAdapterInterface>
	 */
	public function storage_adapters(): array {
		$adapters = array(
			'local' => new Storage\LocalAdapter(),
		);

		$settings = $this->destination_settings();

		foreach ( $settings->enabled() as $id => $config ) {
			if ( 's3' === $id ) {
				$adapters['s3'] = new Storage\S3Adapter( $config, $settings );
			} elseif ( 'gdrive' === $id ) {
				$adapters['gdrive'] = new Storage\GoogleDriveAdapter( $config, $settings );
			}
		}

		return $adapters;
	}
}
