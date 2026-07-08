<?php
/**
 * Plugin activation: schema creation, capability grant, directory hardening.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Activation;

use Timevault\Core\AuditLog;
use Timevault\Support\Capabilities;
use Timevault\Support\EncryptionKeyInstaller;
use Timevault\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on activation and on schema upgrades.
 */
final class Activator {

	/**
	 * Bump whenever the dbDelta schema below changes.
	 */
	private const SCHEMA_VERSION = '2';

	private const SCHEMA_OPTION = 'timevault_schema_version';

	/**
	 * Activation entry point.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::activate_single_site();
				restore_current_blog();
			}

			return;
		}

		self::activate_single_site();
	}

	/**
	 * Re-runs schema creation after a plugin update without reactivation.
	 */
	public static function maybe_upgrade(): void {
		if ( self::SCHEMA_VERSION !== get_option( self::SCHEMA_OPTION ) ) {
			self::activate_single_site();
		}
	}

	/**
	 * Full setup for the current site.
	 */
	private static function activate_single_site(): void {
		self::create_tables();
		Capabilities::grant();
		$key_setup = EncryptionKeyInstaller::ensure_configured();
		Paths::ensure_backup_dir();

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );

		( new AuditLog() )->record(
			'plugin_activated',
			array(
				'version'          => TIMEVAULT_VERSION,
				'encryption_setup' => $key_setup['status'],
				'setup_code'       => $key_setup['code'] ?? '',
			)
		);
	}

	/**
	 * Creates the plugin tables via dbDelta (idempotent, prepared by core).
	 *
	 * Dedicated tables with the `timevault_` prefix keep operational data out
	 * of wp_options and allow proper indexing of the audit trail.
	 */
	private static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$audit_table     = $wpdb->prefix . 'timevault_audit_log';
		$backups_table   = $wpdb->prefix . 'timevault_backups';
		$restores_table  = $wpdb->prefix . 'timevault_restores';

		/*
		 * Audit log - append-only by application design: the AuditLog service
		 * exposes no update/delete API, and no plugin code may issue
		 * UPDATE/DELETE against this table (LGPD accountability, Art. 6, VI).
		 * `ip_hash` stores a salted SHA-256 of the IP, never the raw address
		 * (data minimization).
		 */
		dbDelta(
			"CREATE TABLE {$audit_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_uuid char(36) NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_login varchar(60) NOT NULL DEFAULT '',
				action varchar(64) NOT NULL,
				object_type varchar(32) NOT NULL DEFAULT '',
				object_id varchar(64) NOT NULL DEFAULT '',
				context longtext NULL,
				ip_hash char(64) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY action (action),
				KEY user_id (user_id),
				KEY created_at (created_at)
			) {$charset_collate};"
		);

		/*
		 * Backup registry - metadata only, never file contents. The SHA-256
		 * checksum recorded here is validated before any restore.
		 */
		dbDelta(
			"CREATE TABLE {$backups_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				backup_uuid char(36) NOT NULL,
				type varchar(20) NOT NULL DEFAULT 'full',
				status varchar(20) NOT NULL DEFAULT 'pending',
				storage varchar(32) NOT NULL DEFAULT 'local',
				file_name varchar(255) NOT NULL DEFAULT '',
				size_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
				checksum_sha256 char(64) NOT NULL DEFAULT '',
				is_encrypted tinyint(1) NOT NULL DEFAULT 0,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				completed_at datetime NULL,
				expires_at datetime NULL,
				meta longtext NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY backup_uuid (backup_uuid),
				KEY status (status),
				KEY created_at (created_at)
			) {$charset_collate};"
		);

		/*
		 * Restore registry - tracks each restore attempt (the most sensitive
		 * operation): which backup, the automatic safety backup taken before
		 * overwriting, current pipeline step, and per-step results in JSON.
		 */
		dbDelta(
			"CREATE TABLE {$restores_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				restore_uuid char(36) NOT NULL,
				source_backup_uuid char(36) NOT NULL DEFAULT '',
				safety_backup_uuid char(36) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'pending',
				step varchar(24) NOT NULL DEFAULT '',
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				completed_at datetime NULL,
				meta longtext NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY restore_uuid (restore_uuid),
				KEY status (status),
				KEY created_at (created_at)
			) {$charset_collate};"
		);
	}
}
