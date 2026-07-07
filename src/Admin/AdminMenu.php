<?php
/**
 * Admin menu and placeholder dashboard.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Admin;

use Timevault\Core\EncryptionService;
use Timevault\Plugin;
use Timevault\Support\Capabilities;
use Timevault\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Timevault top-level menu, gated by the dedicated capability.
 * The real dashboard (status, history, progress) is built in P6 with the
 * agency design system.
 */
final class AdminMenu {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Service container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Hooks menu registration.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	/**
	 * Adds the top-level menu page.
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'Timevault — Migration & Backups', 'timevault' ),
			__( 'Timevault', 'timevault' ),
			Capabilities::MANAGE,
			'timevault',
			array( $this, 'render_dashboard' ),
			'dashicons-backup',
			75
		);
	}

	/**
	 * Placeholder dashboard: environment health checklist.
	 */
	public function render_dashboard(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to access Timevault.', 'timevault' ) );
		}

		$encryption_ok = $this->plugin->encryption()->is_configured();
		$queue_ok      = $this->plugin->queue()->is_available();
		$dir_ok        = Paths::is_hardened();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Timevault — Migration & Backups', 'timevault' ); ?></h1>

			<?php if ( ! $encryption_ok ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: 1: constant name, 2: file name. */
							esc_html__( 'Encryption key not configured. Define the constant %1$s in %2$s before creating backups. Backups are encrypted at rest and the key must never live in the database.', 'timevault' ),
							'<code>' . esc_html( EncryptionService::KEY_CONSTANT ) . '</code>',
							'<code>wp-config.php</code>'
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Environment health', 'timevault' ); ?></h2>
			<table class="widefat striped" style="max-width: 720px;">
				<tbody>
					<tr>
						<td><?php esc_html_e( 'Plugin version', 'timevault' ); ?></td>
						<td><code><?php echo esc_html( TIMEVAULT_VERSION ); ?></code></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Encryption key (wp-config.php)', 'timevault' ); ?></td>
						<td><?php echo $encryption_ok ? esc_html__( 'Configured', 'timevault' ) : esc_html__( 'Missing', 'timevault' ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Job queue (Action Scheduler)', 'timevault' ); ?></td>
						<td><?php echo $queue_ok ? esc_html__( 'Available', 'timevault' ) : esc_html__( 'Unavailable — run composer install', 'timevault' ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Backup directory hardening', 'timevault' ); ?></td>
						<td><?php echo $dir_ok ? esc_html__( 'Protected', 'timevault' ) : esc_html__( 'Not protected — reactivate the plugin', 'timevault' ); ?></td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Recent backups', 'timevault' ); ?></h2>
			<?php $recent = $this->plugin->backup_repository()->list_backups( 10 ); ?>
			<?php if ( array() === $recent ) : ?>
				<p><?php esc_html_e( 'No backups yet. Trigger one via POST /wp-json/timevault/v1/backups (the dashboard UI arrives in P6).', 'timevault' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width: 960px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'ID', 'timevault' ); ?></th>
							<th><?php esc_html_e( 'Type', 'timevault' ); ?></th>
							<th><?php esc_html_e( 'Status', 'timevault' ); ?></th>
							<th><?php esc_html_e( 'Size', 'timevault' ); ?></th>
							<th><?php esc_html_e( 'Encrypted', 'timevault' ); ?></th>
							<th><?php esc_html_e( 'Created (UTC)', 'timevault' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $recent as $backup ) : ?>
							<tr>
								<td><code><?php echo esc_html( substr( (string) $backup['backup_uuid'], 0, 8 ) ); ?></code></td>
								<td><?php echo esc_html( (string) $backup['type'] ); ?></td>
								<td><?php echo esc_html( (string) $backup['status'] ); ?></td>
								<td><?php echo esc_html( $backup['size_bytes'] ? size_format( (int) $backup['size_bytes'] ) : '—' ); ?></td>
								<td><?php echo $backup['is_encrypted'] ? esc_html__( 'Yes', 'timevault' ) : esc_html__( 'No', 'timevault' ); ?></td>
								<td><?php echo esc_html( (string) $backup['created_at'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<p style="margin-top: 1em;">
				<?php esc_html_e( 'Restore (P2) and the full dashboard (P6) arrive in the next phases.', 'timevault' ); ?>
			</p>
		</div>
		<?php
	}
}
