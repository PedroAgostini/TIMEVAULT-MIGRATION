<?php
/**
 * EncryptionService tests - authenticated round trip and tamper detection.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Tests\Core;

use Timevault\Core\EncryptionService;
use WP_UnitTestCase;

/**
 * Round trip, tampering, truncation and string helpers.
 */
final class EncryptionServiceTest extends WP_UnitTestCase {

	private string $dir;

	private EncryptionService $enc;

	public function set_up(): void {
		parent::set_up();
		$this->dir = sys_get_temp_dir() . '/tv-enc-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->dir );
		$this->enc = new EncryptionService();
	}

	public function tear_down(): void {
		foreach ( (array) glob( $this->dir . '/*' ) as $f ) {
			is_file( $f ) && unlink( $f );
		}
		rmdir( $this->dir );
		parent::tear_down();
	}

	public function test_is_configured(): void {
		$this->assertTrue( $this->enc->is_configured() );
	}

	public function test_round_trip_preserves_content(): void {
		$plain = $this->dir . '/plain.bin';
		$enc   = $this->dir . '/cipher.enc';
		$out   = $this->dir . '/decrypted.bin';
		$data  = random_bytes( 200000 ); // Spans multiple chunks.
		file_put_contents( $plain, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->assertTrue( $this->enc->encrypt_file( $plain, $enc ) );
		$this->assertNotSame( $data, file_get_contents( $enc ) );
		$this->assertTrue( $this->enc->decrypt_file( $enc, $out ) );
		$this->assertSame( $data, file_get_contents( $out ) );
	}

	public function test_tampered_ciphertext_fails_closed(): void {
		$plain = $this->dir . '/p.bin';
		$enc   = $this->dir . '/c.enc';
		$out   = $this->dir . '/o.bin';
		file_put_contents( $plain, 'sensitive payload' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->enc->encrypt_file( $plain, $enc );

		// Flip a byte in the ciphertext body.
		$bytes           = file_get_contents( $enc );
		$bytes[ strlen( $bytes ) - 5 ] = chr( ord( $bytes[ strlen( $bytes ) - 5 ] ) ^ 0xFF );
		file_put_contents( $enc, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$result = $this->enc->decrypt_file( $enc, $out );
		$this->assertWPError( $result );
		$this->assertFileDoesNotExist( $out, 'No partial plaintext must remain.' );
	}

	public function test_truncated_ciphertext_is_rejected(): void {
		$plain = $this->dir . '/p2.bin';
		$enc   = $this->dir . '/c2.enc';
		$out   = $this->dir . '/o2.bin';
		file_put_contents( $plain, str_repeat( 'A', 50000 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->enc->encrypt_file( $plain, $enc );

		$bytes = file_get_contents( $enc );
		file_put_contents( $enc, substr( $bytes, 0, (int) ( strlen( $bytes ) / 2 ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->assertWPError( $this->enc->decrypt_file( $enc, $out ) );
	}

	public function test_string_round_trip(): void {
		$secret = 'aws-secret-key-value';
		$token  = $this->enc->encrypt_string( $secret );

		$this->assertIsString( $token );
		$this->assertStringNotContainsString( $secret, $token );
		$this->assertSame( $secret, $this->enc->decrypt_string( $token ) );
	}

	public function test_string_tamper_fails(): void {
		$token = (string) $this->enc->encrypt_string( 'x' );
		$this->assertWPError( $this->enc->decrypt_string( $token . 'AA' ) );
	}
}
