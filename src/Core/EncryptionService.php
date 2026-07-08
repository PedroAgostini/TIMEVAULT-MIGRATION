<?php
/**
 * Encryption at rest for backup archives.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Core;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Streaming cryptography requires raw file handles and byte-level I/O; WP_Filesystem has no streaming API.

/**
 * Authenticated streaming encryption for backup archives.
 *
 * Primary cipher: libsodium secretstream (XChaCha20-Poly1305) - per-chunk
 * authentication with native protection against truncation, reordering and
 * tampering. Fallback: OpenSSL AES-256-GCM in chunked mode, reproducing the
 * same guarantees manually (chunk index + final flag bound as AAD, per-file
 * key derived via HKDF, counter-based IVs that can never repeat).
 *
 * The key NEVER touches the database: it must be defined as a constant in
 * wp-config.php (or injected by a secret manager):
 *
 *     define( 'TIMEVAULT_ENCRYPTION_KEY', '<base64 de 32 bytes aleatórios>' );
 */
final class EncryptionService {

	public const KEY_CONSTANT = 'TIMEVAULT_ENCRYPTION_KEY';

	/**
	 * Key length in bytes (256-bit).
	 */
	public const KEY_BYTES = 32;

	private const MAGIC          = 'TVLT';
	private const FORMAT_VERSION = 1;
	private const METHOD_SODIUM  = 1;
	private const METHOD_OPENSSL = 2;

	/**
	 * Plaintext bytes per chunk (1 MiB) - bounds memory usage for large archives.
	 */
	private const CHUNK_SIZE = 1048576;

	/**
	 * Upper bound for a ciphertext chunk length read from disk; anything
	 * larger means a corrupted or hostile file and aborts decryption.
	 */
	private const MAX_CIPHER_CHUNK = self::CHUNK_SIZE + 1024;

	/**
	 * Whether a valid key is configured in wp-config.php.
	 */
	public function is_configured(): bool {
		return null !== $this->key();
	}

	/**
	 * Generates a new base64-encoded 256-bit key for the site owner to paste
	 * into wp-config.php. Never persisted by the plugin.
	 */
	public static function generate_key(): string {
		return base64_encode( random_bytes( self::KEY_BYTES ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Key transport encoding, not obfuscation.
	}

	/**
	 * Encrypts a file (streaming, constant memory).
	 *
	 * @param string $source_path Plaintext file.
	 * @param string $target_path Destination for the ciphertext.
	 * @return true|\WP_Error
	 */
	public function encrypt_file( string $source_path, string $target_path ): bool|\WP_Error {
		$key = $this->key();

		if ( null === $key ) {
			return new \WP_Error( 'timevault_encryption_no_key', __( 'Encryption key is not configured in wp-config.php.', 'timevault' ) );
		}

		if ( ! $this->sodium_available() && ! function_exists( 'openssl_encrypt' ) ) {
			return new \WP_Error( 'timevault_encryption_no_backend', __( 'Neither libsodium nor OpenSSL is available on this server.', 'timevault' ) );
		}

		$in = fopen( $source_path, 'rb' );

		if ( false === $in ) {
			return new \WP_Error( 'timevault_encryption_read_failed', __( 'Could not open the source file for encryption.', 'timevault' ) );
		}

		$out = fopen( $target_path, 'wb' );

		if ( false === $out ) {
			fclose( $in );
			return new \WP_Error( 'timevault_encryption_write_failed', __( 'Could not open the target file for encryption.', 'timevault' ) );
		}

		$result = $this->sodium_available()
			? $this->encrypt_stream_sodium( $in, $out, $key )
			: $this->encrypt_stream_openssl( $in, $out, $key );

		fclose( $in );
		fclose( $out );

		if ( is_wp_error( $result ) ) {
			wp_delete_file( $target_path ); // Never leave a partial/unauthenticated artifact behind.
		}

		return $result;
	}

	/**
	 * Decrypts a file previously produced by encrypt_file().
	 *
	 * Fails closed: any authentication error, truncation or trailing garbage
	 * aborts and removes the partial plaintext.
	 *
	 * @param string $source_path Ciphertext file.
	 * @param string $target_path Destination for the plaintext.
	 * @return true|\WP_Error
	 */
	public function decrypt_file( string $source_path, string $target_path ): bool|\WP_Error {
		$key = $this->key();

		if ( null === $key ) {
			return new \WP_Error( 'timevault_encryption_no_key', __( 'Encryption key is not configured in wp-config.php.', 'timevault' ) );
		}

		$in = fopen( $source_path, 'rb' );

		if ( false === $in ) {
			return new \WP_Error( 'timevault_encryption_read_failed', __( 'Could not open the encrypted file.', 'timevault' ) );
		}

		$head = $this->read_exact( $in, 6 );

		if ( false === $head || self::MAGIC !== substr( $head, 0, 4 ) ) {
			fclose( $in );
			return new \WP_Error( 'timevault_encryption_bad_format', __( 'The file is not a Timevault encrypted archive.', 'timevault' ) );
		}

		if ( self::FORMAT_VERSION !== ord( $head[4] ) ) {
			fclose( $in );
			return new \WP_Error( 'timevault_encryption_bad_version', __( 'Unsupported encrypted archive version.', 'timevault' ) );
		}

		$method = ord( $head[5] );
		$out    = fopen( $target_path, 'wb' );

		if ( false === $out ) {
			fclose( $in );
			return new \WP_Error( 'timevault_encryption_write_failed', __( 'Could not open the target file for decryption.', 'timevault' ) );
		}

		if ( self::METHOD_SODIUM === $method ) {
			$result = $this->sodium_available()
				? $this->decrypt_stream_sodium( $in, $out, $key )
				: new \WP_Error( 'timevault_encryption_no_backend', __( 'This archive requires libsodium, which is not available on this server.', 'timevault' ) );
		} elseif ( self::METHOD_OPENSSL === $method ) {
			$result = $this->decrypt_stream_openssl( $in, $out, $key );
		} else {
			$result = new \WP_Error( 'timevault_encryption_bad_format', __( 'Unknown encryption method in archive header.', 'timevault' ) );
		}

		fclose( $in );
		fclose( $out );

		if ( is_wp_error( $result ) ) {
			wp_delete_file( $target_path ); // Fail closed: no partially decrypted output.
		}

		return $result;
	}

	/**
	 * Sodium secretstream encryption (XChaCha20-Poly1305).
	 *
	 * @param resource $in  Source handle.
	 * @param resource $out Target handle.
	 * @param string   $key Raw 32-byte key.
	 * @return true|\WP_Error
	 */
	private function encrypt_stream_sodium( $in, $out, string $key ): bool|\WP_Error {
		fwrite( $out, self::MAGIC . chr( self::FORMAT_VERSION ) . chr( self::METHOD_SODIUM ) );

		[ $state, $header ] = sodium_crypto_secretstream_xchacha20poly1305_init_push( $key );
		fwrite( $out, $header );

		$plain = fread( $in, self::CHUNK_SIZE );

		if ( false === $plain ) {
			return new \WP_Error( 'timevault_encryption_read_failed', __( 'Could not read the source file.', 'timevault' ) );
		}

		while ( true ) {
			$next  = fread( $in, self::CHUNK_SIZE );
			$final = ( false === $next || '' === $next );
			$tag   = $final
				? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
				: SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;

			$cipher = sodium_crypto_secretstream_xchacha20poly1305_push( $state, $plain, '', $tag );

			if ( false === fwrite( $out, pack( 'N', strlen( $cipher ) ) . $cipher ) ) {
				return new \WP_Error( 'timevault_encryption_write_failed', __( 'Could not write the encrypted file.', 'timevault' ) );
			}

			if ( $final ) {
				return true;
			}

			$plain = $next;
		}
	}

	/**
	 * Sodium secretstream decryption. The FINAL tag is mandatory: a stream
	 * that ends without it (truncation) or continues after it (appended
	 * garbage) is rejected.
	 *
	 * @param resource $in  Source handle (positioned after the 6-byte header).
	 * @param resource $out Target handle.
	 * @param string   $key Raw 32-byte key.
	 * @return true|\WP_Error
	 */
	private function decrypt_stream_sodium( $in, $out, string $key ): bool|\WP_Error {
		$header = $this->read_exact( $in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES );

		if ( false === $header ) {
			return $this->corrupted();
		}

		try {
			$state = sodium_crypto_secretstream_xchacha20poly1305_init_pull( $header, $key );
		} catch ( \SodiumException $e ) {
			unset( $e );
			return $this->corrupted();
		}

		while ( true ) {
			$len = $this->read_chunk_length( $in );

			if ( null === $len ) {
				return $this->corrupted(); // Stream ended before the FINAL tag: truncated.
			}

			$cipher = ( $len > 0 ) ? $this->read_exact( $in, $len ) : '';

			if ( false === $cipher ) {
				return $this->corrupted();
			}

			$result = sodium_crypto_secretstream_xchacha20poly1305_pull( $state, $cipher );

			if ( false === $result ) {
				return $this->tampered();
			}

			[ $plain, $tag ] = $result;

			if ( false === fwrite( $out, $plain ) ) {
				return new \WP_Error( 'timevault_encryption_write_failed', __( 'Could not write the decrypted file.', 'timevault' ) );
			}

			if ( SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL === $tag ) {
				return $this->at_clean_eof( $in ) ? true : $this->corrupted();
			}
		}
	}

	/**
	 * OpenSSL AES-256-GCM chunked encryption (fallback).
	 *
	 * Layout after the 6-byte header: salt(16) iv_base(8), then per chunk:
	 * len(4) final_flag(1) tag(16) ciphertext. The chunk index and final flag
	 * are bound as AAD so chunks cannot be reordered, dropped or truncated
	 * without failing authentication.
	 *
	 * @param resource $in  Source handle.
	 * @param resource $out Target handle.
	 * @param string   $key Raw 32-byte master key.
	 * @return true|\WP_Error
	 */
	private function encrypt_stream_openssl( $in, $out, string $key ): bool|\WP_Error {
		$salt     = random_bytes( 16 );
		$iv_base  = random_bytes( 8 );
		$file_key = hash_hkdf( 'sha256', $key, self::KEY_BYTES, 'timevault-file-v1', $salt );

		fwrite( $out, self::MAGIC . chr( self::FORMAT_VERSION ) . chr( self::METHOD_OPENSSL ) . $salt . $iv_base );

		$plain = fread( $in, self::CHUNK_SIZE );

		if ( false === $plain ) {
			return new \WP_Error( 'timevault_encryption_read_failed', __( 'Could not read the source file.', 'timevault' ) );
		}

		$index = 0;

		while ( true ) {
			$next  = fread( $in, self::CHUNK_SIZE );
			$final = ( false === $next || '' === $next );

			// Counter-based IV: unique per chunk under the per-file key by construction.
			$iv  = $iv_base . pack( 'N', $index );
			$aad = self::MAGIC . chr( self::METHOD_OPENSSL ) . pack( 'N', $index ) . chr( $final ? 1 : 0 );
			$tag = '';

			$cipher = openssl_encrypt( $plain, 'aes-256-gcm', $file_key, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16 );

			if ( false === $cipher ) {
				return new \WP_Error( 'timevault_encryption_failed', __( 'OpenSSL encryption failed.', 'timevault' ) );
			}

			if ( false === fwrite( $out, pack( 'N', strlen( $cipher ) ) . chr( $final ? 1 : 0 ) . $tag . $cipher ) ) {
				return new \WP_Error( 'timevault_encryption_write_failed', __( 'Could not write the encrypted file.', 'timevault' ) );
			}

			if ( $final ) {
				return true;
			}

			++$index;
			$plain = $next;
		}
	}

	/**
	 * OpenSSL AES-256-GCM chunked decryption.
	 *
	 * @param resource $in  Source handle (positioned after the 6-byte header).
	 * @param resource $out Target handle.
	 * @param string   $key Raw 32-byte master key.
	 * @return true|\WP_Error
	 */
	private function decrypt_stream_openssl( $in, $out, string $key ): bool|\WP_Error {
		$salt    = $this->read_exact( $in, 16 );
		$iv_base = $this->read_exact( $in, 8 );

		if ( false === $salt || false === $iv_base ) {
			return $this->corrupted();
		}

		$file_key = hash_hkdf( 'sha256', $key, self::KEY_BYTES, 'timevault-file-v1', $salt );
		$index    = 0;

		while ( true ) {
			$len = $this->read_chunk_length( $in );

			if ( null === $len ) {
				return $this->corrupted(); // Ended before a final-flagged chunk: truncated.
			}

			$flag_raw = $this->read_exact( $in, 1 );
			$tag      = $this->read_exact( $in, 16 );
			$cipher   = ( $len > 0 ) ? $this->read_exact( $in, $len ) : '';

			if ( false === $flag_raw || false === $tag || false === $cipher ) {
				return $this->corrupted();
			}

			$final = ( 1 === ord( $flag_raw ) );
			$iv    = $iv_base . pack( 'N', $index );
			$aad   = self::MAGIC . chr( self::METHOD_OPENSSL ) . pack( 'N', $index ) . chr( $final ? 1 : 0 );

			$plain = openssl_decrypt( $cipher, 'aes-256-gcm', $file_key, OPENSSL_RAW_DATA, $iv, $tag, $aad );

			if ( false === $plain ) {
				return $this->tampered();
			}

			if ( false === fwrite( $out, $plain ) ) {
				return new \WP_Error( 'timevault_encryption_write_failed', __( 'Could not write the decrypted file.', 'timevault' ) );
			}

			if ( $final ) {
				return $this->at_clean_eof( $in ) ? true : $this->corrupted();
			}

			++$index;
		}
	}

	/**
	 * Encrypts a short string (e.g. storage credentials) for storage in the
	 * options table. Returns an opaque base64 token. Same key policy as
	 * files: no key in wp-config.php, no encryption - callers must refuse
	 * to store plaintext instead of falling back.
	 *
	 * @param string $plaintext Secret to protect.
	 * @return string|\WP_Error
	 */
	public function encrypt_string( string $plaintext ): string|\WP_Error {
		$key = $this->key();

		if ( null === $key ) {
			return new \WP_Error( 'timevault_encryption_no_key', __( 'Encryption key is not configured in wp-config.php.', 'timevault' ) );
		}

		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Ciphertext transport encoding.
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

			return base64_encode( 'S' . $nonce . sodium_crypto_secretbox( $plaintext, $nonce, $key ) );
		}

		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv     = random_bytes( 12 );
			$tag    = '';
			$cipher = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'timevault-string-v1', 16 );

			if ( false === $cipher ) {
				return new \WP_Error( 'timevault_encryption_failed', __( 'OpenSSL encryption failed.', 'timevault' ) );
			}

			return base64_encode( 'O' . $iv . $tag . $cipher );
		}
		// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		return new \WP_Error( 'timevault_encryption_no_backend', __( 'Neither libsodium nor OpenSSL is available on this server.', 'timevault' ) );
	}

	/**
	 * Decrypts a string produced by encrypt_string(). Fails closed on any
	 * tampering or key mismatch.
	 *
	 * @param string $encoded Opaque token.
	 * @return string|\WP_Error
	 */
	public function decrypt_string( string $encoded ): string|\WP_Error {
		$key = $this->key();

		if ( null === $key ) {
			return new \WP_Error( 'timevault_encryption_no_key', __( 'Encryption key is not configured in wp-config.php.', 'timevault' ) );
		}

		$raw = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Ciphertext transport encoding.

		if ( false === $raw || strlen( $raw ) < 2 ) {
			return $this->corrupted();
		}

		$method = $raw[0];
		$body   = substr( $raw, 1 );

		if ( 'S' === $method && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			if ( strlen( $body ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return $this->corrupted();
			}

			$plain = sodium_crypto_secretbox_open(
				substr( $body, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				substr( $body, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				$key
			);

			return ( false === $plain ) ? $this->tampered() : $plain;
		}

		if ( 'O' === $method && function_exists( 'openssl_decrypt' ) ) {
			if ( strlen( $body ) < 28 ) {
				return $this->corrupted();
			}

			$plain = openssl_decrypt( substr( $body, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $body, 0, 12 ), substr( $body, 12, 16 ), 'timevault-string-v1' );

			return ( false === $plain ) ? $this->tampered() : $plain;
		}

		return $this->corrupted();
	}

	/**
	 * Reads a 4-byte big-endian chunk length with a sanity ceiling.
	 *
	 * @param resource $in Source handle.
	 * @return int|null Length, or null at clean EOF / on any anomaly.
	 */
	private function read_chunk_length( $in ): ?int {
		$raw = fread( $in, 4 );

		if ( false === $raw || '' === $raw || strlen( $raw ) < 4 ) {
			return null;
		}

		$len = (int) unpack( 'N', $raw )[1];

		return ( $len <= self::MAX_CIPHER_CHUNK ) ? $len : null;
	}

	/**
	 * Reads exactly $bytes from the stream, or false if it cannot.
	 *
	 * @param resource $in    Source handle.
	 * @param int      $bytes Byte count.
	 * @return string|false
	 */
	private function read_exact( $in, int $bytes ) {
		$data = '';
		$have = 0;

		while ( $have < $bytes ) {
			$part = fread( $in, $bytes - $have );

			if ( false === $part || '' === $part ) {
				return false;
			}

			$data .= $part;
			$have += strlen( $part );
		}

		return $data;
	}

	/**
	 * Whether the stream has no trailing data after the final chunk.
	 *
	 * @param resource $in Source handle.
	 */
	private function at_clean_eof( $in ): bool {
		$extra = fread( $in, 1 );

		return ( false === $extra || '' === $extra );
	}

	/**
	 * Shared corruption error.
	 */
	private function corrupted(): \WP_Error {
		return new \WP_Error( 'timevault_encryption_corrupted', __( 'The encrypted archive is corrupted or truncated.', 'timevault' ) );
	}

	/**
	 * Shared authentication-failure error.
	 */
	private function tampered(): \WP_Error {
		return new \WP_Error( 'timevault_encryption_tampered', __( 'Authentication failed: the archive was modified or the key is wrong.', 'timevault' ) );
	}

	/**
	 * Whether libsodium secretstream is available.
	 */
	private function sodium_available(): bool {
		return function_exists( 'sodium_crypto_secretstream_xchacha20poly1305_init_push' );
	}

	/**
	 * Decoded raw key, or null when missing/invalid.
	 */
	private function key(): ?string {
		if ( ! defined( self::KEY_CONSTANT ) ) {
			return null;
		}

		$raw = base64_decode( (string) constant( self::KEY_CONSTANT ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Key transport encoding.

		return ( false !== $raw && self::KEY_BYTES === strlen( $raw ) ) ? $raw : null;
	}
}
