<?php
/**
 * Timevault uninstall.
 *
 * Conservative by default: tables, options and backup archives are only
 * removed when the site owner explicitly opted in via the
 * `timevault_delete_data_on_uninstall` option. Backup archives on disk are
 * NEVER deleted automatically — for a backup plugin, silently destroying
 * backups is the worst possible failure mode.
 *
 * @package Timevault
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// The dedicated capability always goes away with the plugin.
foreach ( wp_roles()->role_objects as $timevault_role ) {
	if ( $timevault_role->has_cap( 'manage_timevault' ) ) {
		$timevault_role->remove_cap( 'manage_timevault' );
	}
}

if ( ! (bool) get_option( 'timevault_delete_data_on_uninstall', false ) ) {
	return;
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uninstall cleanup; table names come from the trusted prefix.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}timevault_audit_log" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}timevault_backups" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}timevault_restores" );
// phpcs:enable

delete_option( 'timevault_schema_version' );
delete_option( 'timevault_dir_suffix' );
delete_option( 'timevault_delete_data_on_uninstall' );
