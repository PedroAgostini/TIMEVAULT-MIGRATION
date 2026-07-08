<?php
/**
 * Anonymizer tests.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Tests\Privacy;

use Timevault\Privacy\Anonymizer;
use WP_UnitTestCase;

/**
 * Deterministic pseudonymization of known personal-data columns.
 */
final class AnonymizerTest extends WP_UnitTestCase {

	public function test_masks_user_columns(): void {
		global $wpdb;
		$t   = ( new Anonymizer() )->transformer();
		$row = $t(
			$wpdb->users,
			array(
				'ID'           => 5,
				'user_email'   => 'real@person.com',
				'display_name' => 'Real Name',
				'user_url'     => 'https://real.example',
			)
		);

		$this->assertStringContainsString( '@example.invalid', $row['user_email'] );
		$this->assertStringNotContainsString( 'real@person.com', $row['user_email'] );
		$this->assertNotSame( 'Real Name', $row['display_name'] );
		$this->assertStringNotContainsString( 'real.example', $row['user_url'] );
	}

	public function test_is_deterministic(): void {
		global $wpdb;
		$t = ( new Anonymizer() )->transformer();
		$a = $t( $wpdb->users, array( 'user_email' => 'same@x.com' ) );
		$b = $t( $wpdb->users, array( 'user_email' => 'same@x.com' ) );

		$this->assertSame( $a['user_email'], $b['user_email'] );
	}

	public function test_masks_known_usermeta_keys(): void {
		global $wpdb;
		$t   = ( new Anonymizer() )->transformer();
		$row = $t( $wpdb->usermeta, array( 'meta_key' => 'billing_phone', 'meta_value' => '+5511999998888' ) );

		$this->assertStringNotContainsString( '5511999998888', (string) $row['meta_value'] );
	}

	public function test_leaves_unknown_columns_untouched(): void {
		global $wpdb;
		$t   = ( new Anonymizer() )->transformer();
		$row = $t( $wpdb->posts, array( 'post_title' => 'Hello World', 'post_content' => 'body' ) );

		$this->assertSame( 'Hello World', $row['post_title'] );
		$this->assertSame( 'body', $row['post_content'] );
	}
}
