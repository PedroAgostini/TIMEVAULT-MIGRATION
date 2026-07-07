<?php
/**
 * Storage adapter contract (Strategy pattern).
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Common contract for all backup destinations (Local, S3, Google Drive, SFTP).
 *
 * Rules every adapter must follow:
 * - Credentials stored encrypted, never in plain text in the database (P3).
 * - Minimum-scope credentials (dedicated bucket/folder, never account-wide tokens).
 * - No external destination enabled by default — always explicit opt-in.
 * - region() feeds the LGPD Art. 33 record (international data transfer).
 */
interface StorageAdapterInterface {

	/**
	 * Stable adapter id, e.g. 'local', 's3', 'gdrive', 'sftp'.
	 */
	public function id(): string;

	/**
	 * Human-readable label for the admin UI.
	 */
	public function label(): string;

	/**
	 * Geographic region/location of the destination (e.g. 'sa-east-1', 'US'),
	 * or null when the data never leaves the site's own server.
	 */
	public function region(): ?string;

	/**
	 * Uploads a local file to the destination.
	 *
	 * @param string $local_path       Absolute path of the file to store.
	 * @param string $destination_name File name at the destination (no paths).
	 * @return string|\WP_Error Remote identifier on success.
	 */
	public function store( string $local_path, string $destination_name ): string|\WP_Error;

	/**
	 * Downloads a stored file back to a local path.
	 *
	 * @param string $remote_id  Identifier returned by store().
	 * @param string $local_path Absolute destination path.
	 * @return true|\WP_Error
	 */
	public function retrieve( string $remote_id, string $local_path ): bool|\WP_Error;

	/**
	 * Deletes a stored file (used by retention/expiration).
	 *
	 * @param string $remote_id Identifier returned by store().
	 * @return true|\WP_Error
	 */
	public function delete( string $remote_id ): bool|\WP_Error;

	/**
	 * Lists stored backups at the destination.
	 *
	 * @return array<int, array{name: string, size: int, modified: int}>|\WP_Error
	 */
	public function list_backups(): array|\WP_Error;
}
