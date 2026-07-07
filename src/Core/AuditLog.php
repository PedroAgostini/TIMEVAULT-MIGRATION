<?php
/**
 * Append-only audit log.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Records who did what, when and to what (LGPD Art. 6, VI — accountability).
 *
 * Append-only by design: this class intentionally exposes NO update or delete
 * API, and no other plugin code may write to the table directly.
 */
final class AuditLog {

	/**
	 * Context keys whose values are never persisted (no secrets in logs).
	 */
	private const SENSITIVE_KEY_FRAGMENTS = array(
		'password',
		'passwd',
		'secret',
		'token',
		'key',
		'credential',
		'authorization',
		'auth',
	);

	/**
	 * Records an audit event.
	 *
	 * @param string               $action      Machine-readable action, e.g. 'backup_created'.
	 * @param array<string, mixed> $context     Extra data; sensitive keys are redacted before storage.
	 * @param string               $object_type Optional object type, e.g. 'backup'.
	 * @param string               $object_id   Optional object identifier, e.g. a backup UUID.
	 */
	public function record( string $action, array $context = array(), string $object_type = '', string $object_id = '' ): void {
		global $wpdb;

		$user = wp_get_current_user();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Append-only write to the dedicated audit table.
		$wpdb->insert(
			$wpdb->prefix . 'timevault_audit_log',
			array(
				'event_uuid'  => wp_generate_uuid4(),
				'user_id'     => (int) $user->ID,
				'user_login'  => (string) $user->user_login,
				'action'      => substr( $action, 0, 64 ),
				'object_type' => substr( $object_type, 0, 32 ),
				'object_id'   => substr( $object_id, 0, 64 ),
				'context'     => wp_json_encode( $this->redact( $context ) ),
				'ip_hash'     => $this->hash_ip(),
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Returns the most recent entries (read-only access for the dashboard).
	 *
	 * @param int $limit  Max rows (1–200).
	 * @param int $offset Pagination offset.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_entries( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$limit  = max( 1, min( 200, $limit ) );
		$offset = max( 0, $offset );
		$table  = $wpdb->prefix . 'timevault_audit_log';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Dedicated audit table; identifier from the trusted prefix; values prepared.
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ),
			ARRAY_A
		);
		// phpcs:enable
	}

	/**
	 * Replaces values of sensitive keys so secrets never reach the log.
	 *
	 * @param array<string, mixed> $context Raw context.
	 * @return array<string, mixed>
	 */
	private function redact( array $context ): array {
		$clean = array();

		foreach ( $context as $key => $value ) {
			$needle    = strtolower( (string) $key );
			$sensitive = false;

			foreach ( self::SENSITIVE_KEY_FRAGMENTS as $fragment ) {
				if ( str_contains( $needle, $fragment ) ) {
					$sensitive = true;
					break;
				}
			}

			if ( $sensitive ) {
				$clean[ $key ] = '[redacted]';
				continue;
			}

			$clean[ $key ] = is_array( $value ) ? $this->redact( $value ) : $value;
		}

		return $clean;
	}

	/**
	 * Salted hash of the client IP: enough to correlate events, without
	 * storing the raw address (LGPD data minimization).
	 */
	private function hash_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( '' === $ip ) {
			return '';
		}

		return hash( 'sha256', $ip . wp_salt( 'auth' ) );
	}
}
