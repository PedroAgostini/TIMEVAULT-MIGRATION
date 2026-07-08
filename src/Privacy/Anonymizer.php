<?php
/**
 * Personal-data anonymization for staging/dev exports.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * Pseudonymizes personal data in a dump destined for staging/dev.
 *
 * Design: instead of rewriting SQL text (fragile), this exposes a row
 * transformer that the DatabaseDumper applies to each structured row before
 * it is serialized. Masking is DETERMINISTIC (HMAC-derived) so the same
 * source value always maps to the same masked value - joins, uniqueness and
 * referential integrity survive, but the real value never leaves the site.
 *
 * Only well-known WordPress core columns are touched; unknown tables/columns
 * pass through unchanged. The salt is per-site (wp_salt), so masked values
 * cannot be correlated back across sites either.
 */
final class Anonymizer {

	/**
	 * Per-table PII columns and their masking strategy, keyed by the table's
	 * base name (prefix-agnostic - matched as a suffix of the real table).
	 *
	 * @var array<string, array<string, string>>
	 */
	private const COLUMN_MAP = array(
		'users'    => array(
			'user_email'          => 'email',
			'user_url'            => 'url',
			'display_name'        => 'name',
			'user_activation_key' => 'clear',
		),
		'comments' => array(
			'comment_author'       => 'name',
			'comment_author_email' => 'email',
			'comment_author_url'   => 'url',
			'comment_author_IP'    => 'ip',
		),
	);

	/**
	 * Meta keys (in usermeta/postmeta) whose value holds personal data.
	 */
	private const META_KEYS = array(
		'first_name',
		'last_name',
		'nickname',
		'description',
		'billing_phone',
		'billing_email',
		'billing_address_1',
		'billing_address_2',
		'shipping_address_1',
		'shipping_address_2',
		'phone',
	);

	/**
	 * Returns a transformer closure: (string $table, array<string,mixed> $row) => array<string,mixed>.
	 *
	 * @return callable
	 */
	public function transformer(): callable {
		return function ( string $table, array $row ): array {
			$base = $this->base_name( $table );

			if ( isset( self::COLUMN_MAP[ $base ] ) ) {
				foreach ( self::COLUMN_MAP[ $base ] as $column => $strategy ) {
					if ( array_key_exists( $column, $row ) && null !== $row[ $column ] && '' !== $row[ $column ] ) {
						$row[ $column ] = $this->mask( (string) $row[ $column ], $strategy );
					}
				}
			}

			if ( str_ends_with( $base, 'usermeta' ) || str_ends_with( $base, 'postmeta' ) ) {
				$key = isset( $row['meta_key'] ) ? (string) $row['meta_key'] : '';

				// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Array key on a dump row, not a meta_query.
				if ( in_array( $key, self::META_KEYS, true ) && isset( $row['meta_value'] ) && '' !== (string) $row['meta_value'] ) {
					$strategy          = str_contains( $key, 'email' ) ? 'email' : ( str_contains( $key, 'phone' ) ? 'phone' : 'name' );
					$row['meta_value'] = $this->mask( (string) $row['meta_value'], $strategy );
				}
				// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			}

			return $row;
		};
	}

	/**
	 * Whether any known table is affected (for the processing record).
	 *
	 * @return array<int, string>
	 */
	public function affected_categories(): array {
		return array(
			__( 'User accounts (email, display name, URL)', 'timevault' ),
			__( 'Comment authors (name, email, URL, IP)', 'timevault' ),
			__( 'User/post meta (name, phone, address fields)', 'timevault' ),
		);
	}

	/**
	 * Masks a value deterministically according to a strategy.
	 *
	 * @param string $value    Original value.
	 * @param string $strategy One of: email|name|url|ip|phone|clear.
	 */
	private function mask( string $value, string $strategy ): string {
		if ( 'clear' === $strategy ) {
			return '';
		}

		$token = substr( hash_hmac( 'sha256', $value, wp_salt( 'nonce' ) ), 0, 12 );

		return match ( $strategy ) {
			'email' => 'user_' . $token . '@example.invalid',
			'name'  => 'Anon ' . strtoupper( substr( $token, 0, 6 ) ),
			'url'   => 'https://example.invalid/' . substr( $token, 0, 8 ),
			'ip'    => '0.0.0.0',
			'phone' => '+00000000' . substr( $token, 0, 4 ),
			default => $token,
		};
	}

	/**
	 * Reduces a full table name to a comparable base (drops the WP prefix).
	 *
	 * @param string $table Full table name.
	 */
	private function base_name( string $table ): string {
		global $wpdb;

		if ( str_starts_with( $table, $wpdb->base_prefix ) ) {
			$rest = substr( $table, strlen( $wpdb->base_prefix ) );

			// Multisite blog tables look like `2_comments`; drop the numeric segment.
			return (string) preg_replace( '/^[0-9]+_/', '', $rest );
		}

		return $table;
	}
}
