<?php
/**
 * Amazon S3 (and S3-compatible) storage adapter.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * S3 destination (AWS or compatible: MinIO, Cloudflare R2, DigitalOcean
 * Spaces via custom endpoint).
 *
 * Security rules:
 * - Requires the AWS SDK (`composer require aws/aws-sdk-php`) — loaded
 *   conditionally, never bundled for sites that don't use S3.
 * - Credentials come decrypted on demand from DestinationSettings and only
 *   live in memory for the duration of the call.
 * - Minimum scope by construction: all operations are confined to the
 *   configured bucket + prefix (dedicated folder). The IAM user should only
 *   have Get/Put/Delete/List on that prefix.
 * - Object keys derive from validated basenames only — no caller-controlled
 *   paths.
 */
final class S3Adapter implements StorageAdapterInterface {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $config   Destination config (bucket, prefix, region, endpoint, label).
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
		return 's3';
	}

	/**
	 * Admin-facing label.
	 */
	public function label(): string {
		$label = (string) ( $this->config['label'] ?? '' );

		return ( '' !== $label ) ? $label : __( 'Amazon S3 / compatible', 'timevault' );
	}

	/**
	 * Bucket region — recorded in the audit trail (LGPD Art. 33).
	 */
	public function region(): ?string {
		$region = (string) ( $this->config['region'] ?? '' );

		return ( '' !== $region ) ? $region : null;
	}

	/**
	 * Uploads a file to the bucket/prefix.
	 *
	 * @param string $local_path       Absolute path of the file to store.
	 * @param string $destination_name File name at the destination.
	 * @return string|\WP_Error Object key on success.
	 */
	public function store( string $local_path, string $destination_name ): string|\WP_Error {
		$name = SafeName::validate( $destination_name );

		if ( is_wp_error( $name ) ) {
			return $name;
		}

		if ( ! is_readable( $local_path ) || ! is_file( $local_path ) ) {
			return new \WP_Error( 'timevault_storage_unreadable', __( 'Source file is not readable.', 'timevault' ) );
		}

		$client = $this->client();

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$key = $this->object_key( $name );

		try {
			$client->putObject(
				array(
					'Bucket'     => (string) $this->config['bucket'],
					'Key'        => $key,
					'SourceFile' => $local_path,
				)
			);
		} catch ( \Throwable $e ) {
			return $this->request_error( $e );
		}

		return $key;
	}

	/**
	 * Downloads an object to a local path.
	 *
	 * @param string $remote_id  Object key returned by store().
	 * @param string $local_path Absolute destination path.
	 * @return true|\WP_Error
	 */
	public function retrieve( string $remote_id, string $local_path ): bool|\WP_Error {
		$key = $this->validate_key( $remote_id );

		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$client = $this->client();

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		try {
			$client->getObject(
				array(
					'Bucket' => (string) $this->config['bucket'],
					'Key'    => $key,
					'SaveAs' => $local_path,
				)
			);
		} catch ( \Throwable $e ) {
			return $this->request_error( $e );
		}

		return true;
	}

	/**
	 * Deletes an object (used by retention/expiration).
	 *
	 * @param string $remote_id Object key returned by store().
	 * @return true|\WP_Error
	 */
	public function delete( string $remote_id ): bool|\WP_Error {
		$key = $this->validate_key( $remote_id );

		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$client = $this->client();

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		try {
			$client->deleteObject(
				array(
					'Bucket' => (string) $this->config['bucket'],
					'Key'    => $key,
				)
			);
		} catch ( \Throwable $e ) {
			return $this->request_error( $e );
		}

		return true;
	}

	/**
	 * Lists backups under the configured prefix.
	 *
	 * @return array<int, array{name: string, size: int, modified: int}>|\WP_Error
	 */
	public function list_backups(): array|\WP_Error {
		$client = $this->client();

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$prefix = (string) ( $this->config['prefix'] ?? '' );

		try {
			$result = $client->listObjectsV2(
				array(
					'Bucket' => (string) $this->config['bucket'],
					'Prefix' => ( '' !== $prefix ) ? $prefix . '/' : '',
				)
			);
		} catch ( \Throwable $e ) {
			return $this->request_error( $e );
		}

		$items = array();

		foreach ( (array) ( $result['Contents'] ?? array() ) as $object ) {
			$key  = (string) $object['Key'];
			$name = ( '' !== $prefix ) ? substr( $key, strlen( $prefix ) + 1 ) : $key;

			if ( '' === $name ) {
				continue;
			}

			$items[] = array(
				'name'     => $name,
				'size'     => (int) ( $object['Size'] ?? 0 ),
				'modified' => isset( $object['LastModified'] ) ? (int) strtotime( (string) $object['LastModified'] ) : 0,
			);
		}

		return $items;
	}

	/**
	 * Builds the SDK client with on-demand decrypted credentials.
	 *
	 * @return \Aws\S3\S3Client|\WP_Error
	 */
	private function client(): object|\WP_Error {
		if ( ! class_exists( '\\Aws\\S3\\S3Client' ) ) {
			return new \WP_Error( 'timevault_sdk_missing', __( 'The AWS SDK is not installed. Run "composer require aws/aws-sdk-php" inside the plugin directory.', 'timevault' ) );
		}

		$credentials = $this->settings->credentials( 's3' );

		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		$args = array(
			'version'     => 'latest',
			'region'      => (string) ( $this->config['region'] ?? 'us-east-1' ),
			'credentials' => array(
				'key'    => (string) ( $credentials['access_key'] ?? '' ),
				'secret' => (string) ( $credentials['secret_key'] ?? '' ),
			),
		);

		$endpoint = (string) ( $this->config['endpoint'] ?? '' );

		if ( '' !== $endpoint ) {
			$args['endpoint']                = $endpoint;
			$args['use_path_style_endpoint'] = true; // MinIO/R2/Spaces compatibility.
		}

		$class = '\\Aws\\S3\\S3Client';

		return new $class( $args );
	}

	/**
	 * Object key for a validated file name (confined to the configured prefix).
	 *
	 * @param string $name Validated file name.
	 */
	private function object_key( string $name ): string {
		$prefix = (string) ( $this->config['prefix'] ?? '' );

		return ( '' !== $prefix ) ? $prefix . '/' . $name : $name;
	}

	/**
	 * Validates a stored object key: must be inside the configured prefix and
	 * carry a safe basename — rejects anything a tampered registry row could
	 * try to smuggle in.
	 *
	 * @param string $remote_id Object key.
	 * @return string|\WP_Error
	 */
	private function validate_key( string $remote_id ): string|\WP_Error {
		$name = SafeName::validate( basename( $remote_id ) );

		if ( is_wp_error( $name ) ) {
			return $name;
		}

		$expected = $this->object_key( $name );

		if ( $expected !== $remote_id ) {
			return new \WP_Error( 'timevault_storage_bad_name', __( 'Invalid backup file name.', 'timevault' ) );
		}

		return $expected;
	}

	/**
	 * Wraps SDK exceptions. AWS exception messages do not include the secret
	 * key, but we truncate defensively anyway — this string can reach logs.
	 *
	 * @param \Throwable $e SDK exception.
	 */
	private function request_error( \Throwable $e ): \WP_Error {
		return new \WP_Error( 'timevault_s3_error', substr( $e->getMessage(), 0, 300 ) );
	}
}
