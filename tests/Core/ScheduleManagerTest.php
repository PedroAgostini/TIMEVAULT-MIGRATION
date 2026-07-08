<?php
/**
 * ScheduleManager tests — automatic backup config and rotation.
 *
 * @package Timevault
 */

declare( strict_types=1 );

namespace Timevault\Tests\Core;

use Timevault\Plugin;
use Timevault\Support\Paths;
use WP_UnitTestCase;

/**
 * Rotation keeps only the newest N automatic backups and never touches manual
 * ones. Config round-trips through the option.
 */
final class ScheduleManagerTest extends WP_UnitTestCase {

	public function tear_down(): void {
		delete_option( \Timevault\Core\ScheduleManager::OPTION );
		parent::tear_down();
	}

	private function make_auto( bool $auto ): string {
		$uuid = Plugin::instance()->backups()->run_now( 'db', $auto ? array( 'auto' => true ) : array() );
		$this->assertIsString( $uuid, is_wp_error( $uuid ) ? $uuid->get_error_message() : '' );

		return $uuid;
	}

	private function cleanup( array $uuids ): void {
		foreach ( $uuids as $uuid ) {
			$row = Plugin::instance()->backup_repository()->get( $uuid );
			if ( null !== $row && '' !== (string) $row['file_name'] ) {
				wp_delete_file( Paths::backup_dir() . '/' . $row['file_name'] );
			}
		}
	}

	public function test_config_round_trips(): void {
		$saved = Plugin::instance()->schedule()->set_schedule( array( 'enabled' => true, 'frequency' => 'monthly', 'keep' => 4 ) );
		$this->assertTrue( $saved['enabled'] );
		$this->assertSame( 'monthly', $saved['frequency'] );
		$this->assertSame( 4, $saved['keep'] );
		$this->assertSame( $saved, Plugin::instance()->schedule()->get_schedule() );
	}

	public function test_invalid_frequency_falls_back(): void {
		$saved = Plugin::instance()->schedule()->set_schedule( array( 'enabled' => true, 'frequency' => 'hourly', 'keep' => 6 ) );
		$this->assertSame( 'weekly', $saved['frequency'] );
	}

	public function test_rotate_keeps_newest_automatic_only(): void {
		$uuids = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$uuids[] = $this->make_auto( true );
		}

		$rotated = Plugin::instance()->schedule()->rotate( 3 );
		$this->assertSame( 2, $rotated );

		$remaining = array_filter(
			Plugin::instance()->backup_repository()->list_backups( 100 ),
			static fn( array $b ): bool => 'completed' === (string) $b['status'] && ! empty( $b['meta']['options']['auto'] )
		);
		$this->assertCount( 3, $remaining );

		$this->cleanup( $uuids );
	}

	public function test_rotate_never_touches_manual_backups(): void {
		$manual = array( $this->make_auto( false ), $this->make_auto( false ) );
		$auto   = array( $this->make_auto( true ), $this->make_auto( true ), $this->make_auto( true ) );

		Plugin::instance()->schedule()->rotate( 1 );

		foreach ( $manual as $uuid ) {
			$this->assertNotNull( Plugin::instance()->backup_repository()->get( $uuid ), 'Manual backups must survive rotation.' );
		}
		$autos = array_filter(
			Plugin::instance()->backup_repository()->list_backups( 100 ),
			static fn( array $b ): bool => 'completed' === (string) $b['status'] && ! empty( $b['meta']['options']['auto'] )
		);
		$this->assertCount( 1, $autos );

		$this->cleanup( array_merge( $manual, $auto ) );
	}
}
