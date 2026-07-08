<?php
/**
 * Scheduled (automatic) backups with rotation.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Core;

use Timevault\Queue\JobQueue;

defined( 'ABSPATH' ) || exit;

/**
 * Runs automatic backups on a chosen cadence (daily/weekly/monthly) and keeps
 * only the newest N automatic backups, deleting older ones so the host's
 * storage does not grow without bound.
 *
 * Rotation touches ONLY automatic backups (meta.options.auto) — manual
 * backups the operator created on purpose are never auto-deleted here.
 */
final class ScheduleManager {

	/**
	 * Recurring Action Scheduler hook that fires an automatic backup.
	 */
	public const HOOK = 'timevault_scheduled_backup';

	public const OPTION = 'timevault_schedule';

	private const FREQUENCIES = array( 'daily', 'weekly', 'monthly' );

	/**
	 * Constructor.
	 *
	 * @param BackupRepository                                          $backups Backup registry.
	 * @param BackupManager                                             $manager Backup orchestrator.
	 * @param JobQueue                                                  $queue   Async job queue.
	 * @param AuditLog                                                  $audit   Append-only audit log.
	 * @param array<string, \Timevault\Storage\StorageAdapterInterface> $adapters Storage adapters.
	 */
	public function __construct(
		private BackupRepository $backups,
		private BackupManager $manager,
		private JobQueue $queue,
		private AuditLog $audit,
		private array $adapters
	) {}

	/**
	 * Current schedule config (with defaults).
	 *
	 * @return array{enabled: bool, frequency: string, keep: int}
	 */
	public function get_schedule(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$frequency = (string) ( $stored['frequency'] ?? 'weekly' );

		return array(
			'enabled'   => ! empty( $stored['enabled'] ),
			'frequency' => in_array( $frequency, self::FREQUENCIES, true ) ? $frequency : 'weekly',
			'keep'      => max( 1, min( 60, (int) ( $stored['keep'] ?? 6 ) ) ),
		);
	}

	/**
	 * Saves the schedule config and (re)registers the recurring action.
	 *
	 * @param array<string, mixed> $data Config fields (enabled, frequency, keep).
	 * @return array{enabled: bool, frequency: string, keep: int}
	 */
	public function set_schedule( array $data ): array {
		$frequency = (string) ( $data['frequency'] ?? 'weekly' );

		$clean = array(
			'enabled'   => ! empty( $data['enabled'] ),
			'frequency' => in_array( $frequency, self::FREQUENCIES, true ) ? $frequency : 'weekly',
			'keep'      => max( 1, min( 60, (int) ( $data['keep'] ?? 6 ) ) ),
		);

		update_option( self::OPTION, $clean, false );
		$this->reschedule( $clean );
		$this->audit->record( 'schedule_updated', $clean );

		return $clean;
	}

	/**
	 * Unschedules any existing recurring backup and schedules a new one that
	 * matches the config. Called on every config change.
	 *
	 * @param array<string, mixed>|null $config Config, or null to load it.
	 */
	public function reschedule( ?array $config = null ): void {
		$config = $config ?? $this->get_schedule();

		$this->queue->unschedule_all( self::HOOK );

		if ( $config['enabled'] && $this->queue->is_available() ) {
			$this->queue->schedule_recurring( $this->interval( (string) $config['frequency'] ), self::HOOK );
		}
	}

	/**
	 * Light sync for `init`: ensures the recurring action exists when enabled
	 * and is gone when disabled, without churning it on every request.
	 */
	public function ensure_scheduled(): void {
		if ( ! $this->queue->is_available() || ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}

		$config    = $this->get_schedule();
		$scheduled = (bool) as_next_scheduled_action( self::HOOK, array(), JobQueue::GROUP );

		if ( $config['enabled'] && ! $scheduled ) {
			$this->queue->schedule_recurring( $this->interval( (string) $config['frequency'] ), self::HOOK );
		} elseif ( ! $config['enabled'] && $scheduled ) {
			$this->queue->unschedule_all( self::HOOK );
		}
	}

	/**
	 * Action Scheduler callback: starts one automatic full backup.
	 */
	public function run(): void {
		$config = $this->get_schedule();

		if ( ! $config['enabled'] ) {
			return;
		}

		$this->audit->record( 'scheduled_backup_run', array( 'frequency' => $config['frequency'] ) );

		// Marked auto:true so rotation can target only automatic backups.
		$this->manager->schedule(
			'full',
			array(
				'files_scope' => 'wp-content',
				'auto'        => true,
			)
		);
	}

	/**
	 * Fires after any backup completes (hooked to `timevault_backup_completed`).
	 * When the completed backup is an automatic one, rotate: keep the newest N,
	 * delete the rest (artifact + record).
	 *
	 * @param string $uuid Completed backup UUID.
	 */
	public function on_backup_completed( string $uuid ): void {
		$row = $this->backups->get( $uuid );

		if ( null === $row || empty( $row['meta']['options']['auto'] ) ) {
			return;
		}

		$this->rotate( $this->get_schedule()['keep'] );
	}

	/**
	 * Keeps the newest $keep completed automatic backups, deleting older ones.
	 *
	 * @param int $keep How many automatic backups to retain.
	 * @return int Number rotated out.
	 */
	public function rotate( int $keep ): int {
		$auto = array_values(
			array_filter(
				$this->backups->list_backups( 100 ),
				static fn( array $b ): bool => 'completed' === (string) $b['status'] && ! empty( $b['meta']['options']['auto'] )
			)
		);

		$rotated = 0;

		// list_backups is newest-first; everything past index (keep-1) is old.
		foreach ( array_slice( $auto, $keep ) as $old ) {
			if ( $this->delete_backup( $old ) ) {
				++$rotated;
			}
		}

		if ( $rotated > 0 ) {
			$this->audit->record(
				'scheduled_backup_rotated',
				array(
					'rotated' => $rotated,
					'kept'    => $keep,
				)
			);
		}

		return $rotated;
	}

	/**
	 * Deletes a backup's artifact and registry row.
	 *
	 * @param array<string, mixed> $backup Backup row.
	 */
	private function delete_backup( array $backup ): bool {
		$adapter = $this->adapters[ (string) $backup['storage'] ] ?? null;

		if ( null !== $adapter ) {
			$remote_id = (string) ( $backup['meta']['remote_id'] ?? $backup['file_name'] );

			if ( '' !== $remote_id ) {
				$adapter->delete( $remote_id );
			}
		}

		return $this->backups->delete( (string) $backup['backup_uuid'] );
	}

	/**
	 * Interval in seconds for a frequency.
	 *
	 * @param string $frequency daily|weekly|monthly.
	 */
	private function interval( string $frequency ): int {
		return match ( $frequency ) {
			'daily'   => DAY_IN_SECONDS,
			'monthly' => MONTH_IN_SECONDS,
			default   => WEEK_IN_SECONDS,
		};
	}
}
