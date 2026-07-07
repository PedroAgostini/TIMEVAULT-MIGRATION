<?php
/**
 * LGPD support service: anonymization wiring, retention, processing record.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Core;

use Timevault\Privacy\Anonymizer;
use Timevault\Storage\DestinationSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Technical support for LGPD compliance. NO hidden telemetry: this plugin
 * never sends anything off-site without explicit, configurable opt-in.
 *
 * Responsibilities:
 * - Anonymization of staging/dev exports (delegated to Anonymizer, applied at
 *   dump time by BackupManager) — this class owns the policy/reporting side.
 * - Configurable retention with automatic expiration of old backups
 *   (apply_retention_policy, driven by a recurring Action Scheduler job).
 * - A lightweight processing-activities record (what personal data the plugin
 *   touches, where it goes, how long it is kept) for the agency's compliance
 *   documentation.
 */
final class PrivacyService {

	/**
	 * Recurring Action Scheduler hook that runs the retention sweep.
	 */
	public const RETENTION_HOOK = 'timevault_retention_sweep';

	public const RETENTION_OPTION = 'timevault_retention';

	/**
	 * Constructor.
	 *
	 * @param BackupRepository                                          $backups  Backup registry.
	 * @param AuditLog                                                  $audit    Append-only audit log.
	 * @param DestinationSettings                                       $settings Storage destinations (for the record).
	 * @param array<string, \Timevault\Storage\StorageAdapterInterface> $adapters Storage adapters.
	 */
	public function __construct(
		private BackupRepository $backups,
		private AuditLog $audit,
		private DestinationSettings $settings,
		private array $adapters
	) {}

	/**
	 * Returns the retention policy (with defaults).
	 *
	 * @return array{enabled: bool, max_age_days: int, max_count: int, min_keep: int}
	 */
	public function get_retention_policy(): array {
		$stored = get_option( self::RETENTION_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'enabled'      => ! empty( $stored['enabled'] ),
			'max_age_days' => max( 0, (int) ( $stored['max_age_days'] ?? 0 ) ),
			'max_count'    => max( 0, (int) ( $stored['max_count'] ?? 0 ) ),
			// Safety floor: retention never deletes below this many backups.
			'min_keep'     => max( 1, (int) ( $stored['min_keep'] ?? 1 ) ),
		);
	}

	/**
	 * Saves the retention policy.
	 *
	 * @param array<string, mixed> $policy Policy fields.
	 */
	public function set_retention_policy( array $policy ): void {
		$clean = array(
			'enabled'      => ! empty( $policy['enabled'] ),
			'max_age_days' => max( 0, (int) ( $policy['max_age_days'] ?? 0 ) ),
			'max_count'    => max( 0, (int) ( $policy['max_count'] ?? 0 ) ),
			'min_keep'     => max( 1, (int) ( $policy['min_keep'] ?? 1 ) ),
		);

		update_option( self::RETENTION_OPTION, $clean, false );
		$this->audit->record( 'retention_policy_updated', $clean );
	}

	/**
	 * Applies the retention policy: expires (deletes the artifact of) backups
	 * beyond the age/count limits, never dropping below min_keep. Marks each
	 * expired row status='expired' but keeps the record for accountability.
	 *
	 * @return int Number of backups expired.
	 */
	public function apply_retention_policy(): int {
		$policy = $this->get_retention_policy();

		if ( ! $policy['enabled'] || ( 0 === $policy['max_age_days'] && 0 === $policy['max_count'] ) ) {
			return 0;
		}

		$completed = array_values(
			array_filter(
				$this->backups->list_backups( 100 ),
				static fn( array $b ): bool => 'completed' === (string) $b['status']
			)
		);

		if ( count( $completed ) <= $policy['min_keep'] ) {
			return 0;
		}

		$now     = time();
		$expired = 0;
		$index   = 0; // 0 = newest (list is newest-first).

		foreach ( $completed as $backup ) {
			++$index;

			// Never let the sweep drop below the safety floor.
			if ( count( $completed ) - $expired <= $policy['min_keep'] ) {
				break;
			}

			$too_old  = $policy['max_age_days'] > 0 && ( $now - (int) strtotime( (string) $backup['created_at'] ) ) > ( $policy['max_age_days'] * DAY_IN_SECONDS );
			$too_many = $policy['max_count'] > 0 && $index > $policy['max_count'];

			if ( $too_old || $too_many ) {
				if ( $this->expire_backup( $backup ) ) {
					++$expired;
				}
			}
		}

		if ( $expired > 0 ) {
			$this->audit->record( 'retention_sweep', array( 'expired' => $expired ) );
		}

		return $expired;
	}

	/**
	 * Generates the processing-activities record (LGPD accountability).
	 *
	 * @return array<string, mixed>
	 */
	public function get_processing_record(): array {
		$destinations = array(
			array(
				'id'       => 'local',
				'label'    => __( 'Local server disk', 'timevault' ),
				'region'   => null,
				'external' => false,
			),
		);

		foreach ( $this->settings->enabled() as $id => $config ) {
			$destinations[] = array(
				'id'       => $id,
				'label'    => (string) ( $config['label'] ?? $id ),
				'region'   => (string) ( $config['region'] ?? '' ),
				'external' => true, // Potential international data transfer (LGPD Art. 33).
			);
		}

		$policy = $this->get_retention_policy();

		return array(
			'generated_at'    => gmdate( 'c' ),
			'controller_note' => __( 'The legal basis and controller/operator roles are contractual between the agency and the client; this record documents only what the plugin technically does with personal data.', 'timevault' ),
			'personal_data'   => array(
				__( 'User accounts: email, login, display name, URL', 'timevault' ),
				__( 'User/post meta: name, phone, address fields', 'timevault' ),
				__( 'Comment authors: name, email, URL, IP address', 'timevault' ),
				__( 'Any personal data present in post/option content that is part of a backup', 'timevault' ),
			),
			'purposes'        => array(
				__( 'Disaster recovery (backup and restore of the site)', 'timevault' ),
				__( 'Site migration between environments', 'timevault' ),
				__( 'Staging/dev copies (with optional anonymization)', 'timevault' ),
			),
			'anonymization'   => array(
				'available'  => true,
				'categories' => ( new Anonymizer() )->affected_categories(),
				'note'       => __( 'Optional, opt-in per export; masking is deterministic and per-site.', 'timevault' ),
			),
			'destinations'    => $destinations,
			'retention'       => array(
				'enabled'      => $policy['enabled'],
				'max_age_days' => $policy['max_age_days'],
				'max_count'    => $policy['max_count'],
				'min_keep'     => $policy['min_keep'],
			),
			'security'        => array(
				'encryption_at_rest' => __( 'AES-256-GCM / XChaCha20-Poly1305; key in wp-config.php, never in the database', 'timevault' ),
				'access_control'     => __( 'Dedicated capability; signed short-lived download tokens; no public backup links', 'timevault' ),
				'audit_log'          => __( 'Append-only; IP addresses stored only as salted hashes', 'timevault' ),
			),
			'telemetry'       => array(
				'enabled' => false,
				'note'    => __( 'No telemetry. Nothing is sent off-site without explicit, configurable opt-in.', 'timevault' ),
			),
		);
	}

	/**
	 * Expires a single backup: deletes the stored artifact and marks the row.
	 *
	 * @param array<string, mixed> $backup Backup row.
	 */
	private function expire_backup( array $backup ): bool {
		$adapter = $this->adapters[ (string) $backup['storage'] ] ?? null;

		if ( null !== $adapter ) {
			$remote_id = (string) ( $backup['meta']['remote_id'] ?? $backup['file_name'] );

			if ( '' !== $remote_id ) {
				$adapter->delete( $remote_id ); // Best-effort; a missing file still expires the record.
			}
		}

		$uuid = (string) $backup['backup_uuid'];
		$this->backups->update(
			$uuid,
			array(
				'status'     => 'expired',
				'expires_at' => current_time( 'mysql', true ),
			)
		);
		$this->audit->record( 'backup_expired', array( 'file_name' => (string) $backup['file_name'] ), 'backup', $uuid );

		return true;
	}
}
