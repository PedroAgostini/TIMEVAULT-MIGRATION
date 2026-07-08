<?php
/**
 * Admin menu and dashboard shell.
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
 * Registers the Timevault top-level menu (gated by the dedicated capability),
 * enqueues the dark amber/glass dashboard assets on its own screen only, and
 * renders the app shell that the JS hydrates from the REST API.
 */
final class AdminMenu {

	private const SLUG = 'timevault';

	/**
	 * Page hook suffix, kept so assets load only on our screen.
	 *
	 * @var string
	 */
	private string $hook = '';

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Service container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Hooks menu registration and asset enqueuing.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Adds the top-level menu page.
	 */
	public function add_menu(): void {
		$this->hook = (string) add_menu_page(
			__( 'Timevault Migration & Backups', 'timevault' ),
			__( 'Timevault', 'timevault' ),
			Capabilities::MANAGE,
			self::SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-backup',
			75
		);
	}

	/**
	 * Enqueues the dashboard CSS/JS only on the Timevault screen, and passes
	 * the REST root + nonce the app needs.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( $hook !== $this->hook ) {
			return;
		}

		wp_enqueue_style(
			'timevault-admin',
			TIMEVAULT_URL . 'assets/css/timevault-admin.css',
			array(),
			TIMEVAULT_VERSION
		);

		wp_enqueue_script(
			'timevault-admin',
			TIMEVAULT_URL . 'assets/js/timevault-admin.js',
			array( 'wp-i18n' ),
			TIMEVAULT_VERSION,
			true
		);

		wp_localize_script(
			'timevault-admin',
			'TimevaultConfig',
			array(
				'root'         => esc_url_raw( rest_url( 'timevault/v1' ) ),
				'rootFallback' => esc_url_raw( add_query_arg( 'rest_route', '/timevault/v1', site_url( '/' ) ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'logo'         => esc_url_raw( TIMEVAULT_URL . 'assets/images/TIMEVAULT-LOGO-2-cropped.webp' ),
				'encryptConst' => EncryptionService::KEY_CONSTANT,
				'siteHost'     => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			)
		);
	}

	/**
	 * Renders the app shell. JS hydrates it from the REST API; a static health
	 * checklist is shown as a no-JS / loading fallback.
	 */
	public function render_dashboard(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to access Timevault.', 'timevault' ) );
		}

		$encryption_ok = $this->plugin->encryption()->is_configured();
		$queue_ok      = $this->plugin->queue()->is_available();
		$dir_ok        = Paths::is_hardened();
		?>
		<div class="timevault-app" id="timevault-app">
			<noscript>
				<div class="tv-noscript">
					<?php esc_html_e( 'Timevault needs JavaScript enabled to run the dashboard.', 'timevault' ); ?>
				</div>
			</noscript>

			<div class="tv-boot" data-tv-boot>
				<div class="tv-boot__spinner" aria-hidden="true"></div>
				<p><?php esc_html_e( 'Loading Timevault…', 'timevault' ); ?></p>

				<ul class="tv-boot__health">
					<li><?php echo $encryption_ok ? '✓' : '⚠'; ?> <?php esc_html_e( 'Encryption key', 'timevault' ); ?></li>
					<li><?php echo $queue_ok ? '✓' : '⚠'; ?> <?php esc_html_e( 'Job queue', 'timevault' ); ?></li>
					<li><?php echo $dir_ok ? '✓' : '⚠'; ?> <?php esc_html_e( 'Backup directory', 'timevault' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}
}
