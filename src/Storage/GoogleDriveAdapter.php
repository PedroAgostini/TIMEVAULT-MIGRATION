<?php
/**
 * Google Drive storage adapter.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Google Drive destination via service account.
 *
 * Security rules:
 * - Requires the Google API client (`composer require google/apiclient`) —
 *   loaded conditionally, never bundled for sites that don't use Drive.
 * - Scope is `drive.file` only (minimum privilege): the service account can
 *   see and touch ONLY files this plugin created — never the whole Drive.
 * - Uploads go to a dedicated folder (folder_id), resumable in 8 MiB chunks
 *   so large backups never load into memory.
 * - Service-account credentials are decrypted on demand and never logged.
 */
final class GoogleDriveAdapter implements StorageAdapterInterface {

	private const CHUNK_SIZE = 8388608; // 8 MiB.

	/**
	 * SDK client kept between service() and deferred upload calls.
	 *
	 * @var object|null
	 */
	private ?object $client = null;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $config   Destination config (folder_id, region, label).
	 * @param DestinationSettings  $settings Credential source (decrypts on demand).
	 */
	public function __construct(
		private array $config,
		private DestinationSettings $settings
	) {}

	/**
	 * Adapter id.
	 */
	public function id(): string {
		return 'gdrive';
	}

	/**
	 * Admin-facing label.
	 */
	public function label(): string {
		$label = (string) ( $this->config['label'] ?? '' );

		return ( '' !== $label ) ? $label : __( 'Google Drive', 'timevault' );
	}

	/**
	 * Declared data location — informational, recorded for LGPD Art. 33
	 * (Google Drive is an international transfer for Brazilian sites).
	 */
	public function region(): ?string {
		$region = (string) ( $this->config['region'] ?? '' );

		return ( '' !== $region ) ? $region : 'US';
	}

	/**
	 * Uploads a file to the dedicated folder (resumable, chunked).
	 *
	 * @param string $local_path       Absolute path of the file to store.
	 * @param string $destination_name File name at the destination.
	 * @return string|\WP_Error Drive file id on success.
	 */
	public function store( string $local_path, string $destination_name ): string|\WP_Error {
		$name = SafeName::validate( $destination_name );

		if ( is_wp_error( $name ) ) {
			return $name;
		}

		if ( ! is_readable( $local_path ) || ! is_file( $local_path ) ) {
			return new \WP_Error( 'timevault_storage_unreadable', __( 'Source file is not readable.', 'timevault' ) );
		}

		$drive = $this->service();

		if ( is_wp_error( $drive ) ) {
			return $drive;
		}

		try {
			$meta_class = '\\Google\\Service\\Drive\\DriveFile';
			$meta       = new $meta_class(
				array(
					'name'    => $name,
					'parents' => array( (string) $this->config['folder_id'] ),
				)
			);

			$this->client->setDefer( true );
			$request = $drive->files->create( $meta, array( 'fields' => 'id' ) );

			$media_class = '\\Google\\Http\\MediaFileUpload';
			$media       = new $media_class( $this->client, $request, 'application/octet-stream', null, true, self::CHUNK_SIZE );
			$media->setFileSize( (int) filesize( $local_path ) );

			// phpcs:disable WordPress.WP.AlternativeFunctions -- Chunked upload requires a raw handle.
			$handle = fopen( $local_path, 'rb' );

			if ( false === $handle ) {
				$this->client->setDefer( false );

				return new \WP_Error( 'timevault_storage_unreadable', __( 'Source file is not readable.', 'timevault' ) );
			}

			$status = false;

			while ( ! $status && ! feof( $handle ) ) {
				$chunk  = (string) fread( $handle, self::CHUNK_SIZE );
				$status = $media->nextChunk( $chunk );
			}

			fclose( $handle );
			// phpcs:enable
			$this->client->setDefer( false );

			if ( is_object( $status ) && isset( $status->id ) ) {
				return (string) $status->id;
			}

			return new \WP_Error( 'timevault_gdrive_error', __( 'Google Drive upload did not complete.', 'timevault' ) );
		} catch ( \Throwable $e ) {
			return $this->request_error( $e );
		}
	}

	/**
	 * Downloads a stored file to a local path (streamed).
	 *
	 * @param string $remote_id  Drive file id returned by store().
	 * @param string $local_path Absolute destination path.
	 * @return true|\WP_Error
	 */
	public function retrieve( string $remote_id, string $local_path ): bool|\WP_Error {
		$file_id = $this->validate_file_id( $remote_id );

		if ( is_wp_error( $file_id ) ) {
			return $file_id;
		}

		$drive = $this->service();

		if ( is_wp_error( $drive ) ) {
			return $drive;
		}

		try {
			$response = $drive->files->get( $file_id, array( 'alt' => 'media' ) );
			$body     = $response->getBody();

			// phpcs:disable WordPress.WP.AlternativeFunctions -- Streaming download requires a raw handle.
			$out = fopen( $local_path, 'wb' );

			if ( false === $out ) {
				return new \WP_Error( 'timevault_storage_write_failed', __( 'Could not write the downloaded file.', 'timevault' ) );
			}

			while ( ! $body->eof() ) {
				fwrite( $out, $body->read( self::CHUNK_SIZE ) );
			}

			fclose( $out );
			// phpcs:enable
		} catch ( \Throwable $e ) {
			return $this->request_error( $e );
		}

		return true;
	}

	/**
	 * Deletes a stored file (used by retention/expiration).
	 *
	 * @param string $remote_id Drive file id returned by store().
	 * @return true|\WP_Error
	 */
	public function delete( string $remote_id ): bool|\WP_Error {
		$file_id = $this->validate_file_id( $remote_id );

		if ( is_wp_error( $file_id ) ) {
			return $file_id;
		}

		$drive = $this->service();

		if ( is_wp_error( $drive ) ) {
			return $drive;
		}

		try {
			$drive->files->delete( $file_id );
		} catch ( \Throwable $e ) {
			return $this->request_error( $e );
		}

		return true;
	}

	/**
	 * Lists backups in the dedicated folder.
	 *
	 * @return array<int, array{name: string, size: int, modified: int}>|\WP_Error
	 */
	public function list_backups(): array|\WP_Error {
		$drive = $this->service();

		if ( is_wp_error( $drive ) ) {
			return $drive;
		}

		$folder_id = (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $this->config['folder_id'] ?? '' ) );

		try {
			$result = $drive->files->listFiles(
				array(
					'q'        => sprintf( "'%s' in parents and trashed = false", $folder_id ),
					'fields'   => 'files(id,name,size,modifiedTime)',
					'pageSize' => 100,
				)
			);
		} catch ( \Throwable $e ) {
			return $this->request_error( $e );
		}

		$items = array();

		foreach ( (array) $result->getFiles() as $file ) {
			$items[] = array(
				'name'     => (string) $file->getName(),
				'size'     => (int) $file->getSize(),
				'modified' => (int) strtotime( (string) $file->getModifiedTime() ),
			);
		}

		return $items;
	}

	/**
	 * Builds the Drive service with the minimum `drive.file` scope.
	 *
	 * @return object \Google\Service\Drive on success, \WP_Error otherwise (native
	 *                union with `object` is not allowed, callers use is_wp_error()).
	 */
	private function service(): object {
		if ( ! class_exists( '\\Google\\Client' ) ) {
			return new \WP_Error( 'timevault_sdk_missing', __( 'The Google API client is not installed. Run "composer require google/apiclient" inside the plugin directory.', 'timevault' ) );
		}

		$credentials = $this->settings->credentials( 'gdrive' );

		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		if ( '' === (string) ( $this->config['folder_id'] ?? '' ) ) {
			return new \WP_Error( 'timevault_invalid_destination', __( 'Google Drive destination has no dedicated folder configured.', 'timevault' ) );
		}

		try {
			$client_class = '\\Google\\Client';
			$client       = new $client_class();
			$client->setAuthConfig( $credentials ); // Service account JSON (decrypted in memory only).
			$client->setScopes( array( 'https://www.googleapis.com/auth/drive.file' ) ); // Minimum privilege: app-created files only.

			$this->client = $client;

			$service_class = '\\Google\\Service\\Drive';

			return new $service_class( $client );
		} catch ( \Throwable $e ) {
			return $this->request_error( $e );
		}
	}

	/**
	 * Validates a Drive file id from the registry.
	 *
	 * @param string $remote_id Candidate id.
	 * @return string|\WP_Error
	 */
	private function validate_file_id( string $remote_id ): string|\WP_Error {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_\-]{10,128}$/', $remote_id ) ) {
			return new \WP_Error( 'timevault_storage_bad_name', __( 'Invalid Google Drive file id.', 'timevault' ) );
		}

		return $remote_id;
	}

	/**
	 * Wraps SDK exceptions, truncated defensively (strings can reach logs).
	 *
	 * @param \Throwable $e SDK exception.
	 */
	private function request_error( \Throwable $e ): \WP_Error {
		return new \WP_Error( 'timevault_gdrive_error', substr( $e->getMessage(), 0, 300 ) );
	}
}
